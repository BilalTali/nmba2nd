<?php

namespace App\Jobs;

use App\Exceptions\AuthenticationSyncException;
use App\Exceptions\PermanentSyncException;
use App\Exceptions\TransientSyncException;
use App\Models\Event;
use App\Models\EventSyncLog;
use App\Services\HttpPortalSyncService;
use App\Services\PortalHealthService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use App\Traits\SharedCacheTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SyncBatchJob — Parallel Portal Session Worker
 *
 * This job represents one "browser" in the 5-parallel-session sync architecture.
 * Each instance:
 *   1. Owns a dedicated session slot (0–4) with its own cookie jar and lock
 *   2. Logs into the government portal ONCE via ensureAuthenticated()
 *   3. Processes up to 20 events sequentially within that single session
 *   4. Handles mid-batch session expiry by re-authenticating automatically
 *
 * Dispatched by the Kernel scheduler sweep, which picks 100 pending events,
 * chunks them into 5 batches, and dispatches one SyncBatchJob per slot.
 *
 * Throughput: 5 slots × 20 events = 100 events per scheduler sweep.
 */
class SyncBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SharedCacheTrait;

    /**
     * 10-minute timeout — covers 20 events × ~30s each with overhead.
     */
    public int $timeout = 600;

    /**
     * Queue-level retry attempts before Laravel marks the batch job as failed.
     */
    public int $tries = 10;

    /**
     * Maximum exceptions before Laravel marks the job as failed.
     */
    public int $maxExceptions = 10;

    /**
     * @param array $eventIds    IDs of up to 20 events this batch should process.
     * @param int   $sessionSlot Slot index 0–4. Governs the isolated cookie jar
     *                           and per-slot transmission lock in HttpPortalSyncService.
     */
    public function __construct(
        protected array $eventIds,
        protected int $sessionSlot
    ) {
        $this->connection = 'database';
        $this->queue      = 'default';
        $this->sessionSlot = max(0, min(7, $sessionSlot));
    }

    /**
     * WithoutOverlapping keyed on the slot — ensures that if a previous batch
     * for slot 2 is still running, a new scheduler sweep does NOT dispatch
     * another batch on slot 2 (which would create a session conflict).
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("sync_batch_slot_{$this->sessionSlot}"))
                ->dontRelease()
                ->expireAfter(600)
        ];
    }

    public function handle(): void
    {
        Log::channel('sync')->info("SyncBatchJob started.", [
            'slot'        => $this->sessionSlot,
            'event_count' => count($this->eventIds),
            'event_ids'   => $this->eventIds,
        ]);

        // ── Guard: Circuit breaker check ──────────────────────────────────────
        if ($this->getSharedValue('sre_circuit_breaker_portal_down') === true) {
            Log::channel('sync')->info("SyncBatchJob slot {$this->sessionSlot}: Circuit breaker is active. Silently deleting job so scheduler can re-dispatch later.", [
                'slot' => $this->sessionSlot,
            ]);
            $this->delete();
            return;
        }

        // ── Step 1: Establish portal session (one login for the whole batch) ──
        $syncService = new HttpPortalSyncService($this->sessionSlot);

        try {
            $syncService->ensureAuthenticated();
        } catch (AuthenticationSyncException $e) {
            $this->handleAuthFailureForBatch($e->getMessage());
            return;
        } catch (TransientSyncException $e) {
            Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Login failed (transient). Releasing batch.", [
                'slot'  => $this->sessionSlot,
                'error' => $e->getMessage(),
            ]);
            $this->release(300);
            return;
        } catch (Exception $e) {
            Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Unexpected error during login. Releasing batch.", [
                'slot'  => $this->sessionSlot,
                'error' => $e->getMessage(),
            ]);
            $this->release(300);
            return;
        }

        // ── Step 2: Process each event in the batch ───────────────────────────
        $successCount = 0;
        $failureCount = 0;

        foreach ($this->eventIds as $eventId) {
            // Guard: Stop mid-batch if circuit breaker tripped
            if (Cache::get('sre_circuit_breaker_portal_down') === true) {
                Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Circuit breaker tripped mid-batch. Halting remaining events.");
                break;
            }

            $event = Event::find($eventId);

            // Skip if event no longer exists or is already synced
            if (!$event || $event->sync_status === 'synced' || $event->sync_status === 'failed_permanently') {
                continue;
            }

            // Skip events that have exceeded their retry budget
            if ($event->sync_attempts >= 9) {
                Log::channel('sync')->info("SyncBatchJob slot {$this->sessionSlot}: Event {$eventId} exceeded attempt budget — resetting.", [
                    'slot'          => $this->sessionSlot,
                    'event_id'      => $eventId,
                    'sync_attempts' => $event->sync_attempts,
                ]);
                $event->update([
                    'sync_attempts'   => 0,
                    'sync_status'     => 'pending',
                    'last_attempt_at' => now(),
                ]);
                Cache::forget("sre_sync_dispatch_lock_{$eventId}");
                continue;
            }

            // Atomic CAS claim: pending or stuck syncing → syncing (prevents double processing)
            $claimed = Event::where('id', $eventId)
                ->where(function ($query) {
                    $query->where('sync_status', 'pending')
                          ->orWhere(function ($q) {
                              $q->where('sync_status', 'syncing')
                                ->where('updated_at', '<', now()->subMinutes(10));
                          });
                })
                ->update([
                    'sync_status'     => 'syncing',
                    'sync_attempts'   => DB::raw('sync_attempts + 1'),
                    'last_attempt_at' => now(),
                ]);

            if ($claimed === 0) {
                // Another worker already claimed this event
                Log::channel('sync')->info("SyncBatchJob slot {$this->sessionSlot}: CAS claim rejected for event {$eventId} — already claimed.", [
                    'slot'     => $this->sessionSlot,
                    'event_id' => $eventId,
                ]);
                continue;
            }

            $event->refresh();
            $startTime = microtime(true);

            try {
                $success    = $syncService->sync($event);
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($success) {
                    $storedPaths = $event->photo_paths;
                    $event->markSynced();

                    // Clear dispatch lock on success
                    Cache::forget("sre_sync_dispatch_lock_{$eventId}");
                    Cache::forget('sre_consecutive_auth_failures');

                    // Audit log
                    $this->writeSyncLog($event, 'success', null, null);

                    // Post-sync media management: move files to 'events/synced/'
                    $newPaths = [];
                    foreach ($storedPaths as $path) {
                        if (str_contains($path, 'events/synced/')) {
                            $newPaths[] = $path;
                            continue;
                        }
                        if (Storage::disk('public')->exists($path)) {
                            $newPath = str_replace('events/', 'events/synced/', $path);
                            Storage::disk('public')->move($path, $newPath);
                            $newPaths[] = $newPath;
                        }
                    }
                    if (!empty($newPaths)) {
                        $event->photo_paths = $newPaths;
                        $event->save();
                    }

                    Log::channel('sync')->info("SyncBatchJob slot {$this->sessionSlot}: Event synced successfully.", [
                        'slot'          => $this->sessionSlot,
                        'event_id'      => $eventId,
                        'duration_ms'   => $durationMs,
                        'sync_attempts' => $event->sync_attempts,
                    ]);

                    $successCount++;

                } else {
                    $this->writeSyncLog($event, 'failure', null, 'Sync service returned false — portal did not confirm submission.');
                    $event->markFailed('Sync service returned false — portal did not confirm submission.');
                    $failureCount++;
                }

            } catch (AuthenticationSyncException $e) {
                // Mid-batch auth failure — pause the whole batch
                $this->writeSyncLog($event, 'failure', null, $e->getMessage());
                $event->markFailed($e->getMessage());
                $this->handleAuthFailureForBatch($e->getMessage());
                return; // Stop processing the rest of the batch
            } catch (PermanentSyncException $e) {
                $this->writeSyncLog($event, 'permanent_failure', null, $e->getMessage());
                $event->markFailedPermanently($e->getMessage());
                Cache::forget("sre_sync_dispatch_lock_{$eventId}");

                Log::channel('sync')->error("SyncBatchJob slot {$this->sessionSlot}: Permanent failure for event {$eventId}.", [
                    'slot'     => $this->sessionSlot,
                    'event_id' => $eventId,
                    'reason'   => mb_substr($e->getMessage(), 0, 500),
                ]);
                $failureCount++;

            } catch (TransientSyncException $e) {
                $this->writeSyncLog($event, 'failure', null, $e->getMessage());

                // Set rest time to exactly 1 minute (60 seconds)
                $delaySeconds = 60;

                $event->markFailed($e->getMessage());
                Cache::put("sre_sync_dispatch_lock_{$eventId}", true, $delaySeconds + 30);

                Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Transient failure for event {$eventId}. Backoff set.", [
                    'slot'         => $this->sessionSlot,
                    'event_id'     => $eventId,
                    'backoff_secs' => $delaySeconds,
                    'reason'       => mb_substr($e->getMessage(), 0, 300),
                ]);
                $failureCount++;

            } catch (Exception $e) {
                $this->writeSyncLog($event, 'failure', null, 'Unexpected: ' . $e->getMessage());
                $event->markFailed('Unexpected exception: ' . $e->getMessage());

                Log::channel('sync')->error("SyncBatchJob slot {$this->sessionSlot}: Unexpected exception for event {$eventId}.", [
                    'slot'     => $this->sessionSlot,
                    'event_id' => $eventId,
                    'error'    => $e->getMessage(),
                ]);
                $failureCount++;
            }
        }

        Log::channel('sync')->info("SyncBatchJob slot {$this->sessionSlot}: Batch complete.", [
            'slot'         => $this->sessionSlot,
            'total_events' => count($this->eventIds),
            'succeeded'    => $successCount,
            'failed'       => $failureCount,
        ]);
    }

    /**
     * Handle a credential failure that should pause the entire sync system.
     * Mirrors the same pausing logic from SyncEventJob::handleAuthFailure().
     */
    protected function handleAuthFailureForBatch(string $errorMessage): void
    {
        $failures = (int) $this->getSharedValue('sre_consecutive_auth_failures', 0) + 1;
        $this->setSharedValue('sre_consecutive_auth_failures', $failures, now()->addDays(1));

        if ($failures >= 3) {
            $this->setSharedValue('auto_sync_paused', true, 86400 * 365);
            $this->setSharedValue('portal_credentials_invalid', true, 86400 * 365);

            Log::channel('sync')->error("SyncBatchJob slot {$this->sessionSlot}: AUTH FAILURE THRESHOLD REACHED. Auto-sync paused.", [
                'slot'                 => $this->sessionSlot,
                'consecutive_failures' => $failures,
                'reason'               => mb_substr($errorMessage, 0, 500),
            ]);
        } else {
            Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Auth failure below threshold — will retry.", [
                'slot'                 => $this->sessionSlot,
                'consecutive_failures' => $failures,
            ]);
            $this->release(300); // retry batch in 5 minutes
        }
    }

    /**
     * Write one row to event_sync_logs for audit trail.
     */
    protected function writeSyncLog(
        Event   $event,
        string  $outcome,
        ?int    $httpStatusCode,
        ?string $responseSnippet
    ): void {
        try {
            EventSyncLog::create([
                'event_id'             => $event->id,
                'attempted_at'         => now(),
                'attempt_number'       => $event->sync_attempts,
                'http_status_code'     => $httpStatusCode,
                'api_response_snippet' => $responseSnippet
                    ? mb_substr($responseSnippet, 0, 500)
                    : null,
                'outcome'              => $outcome,
                'worker_pid'           => getmypid() ?: null,
            ]);
        } catch (Exception $e) {
            // Audit logging must never break the job itself
            Log::channel('sync')->warning("SyncBatchJob slot {$this->sessionSlot}: Failed to write sync log.", [
                'slot'     => $this->sessionSlot,
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
