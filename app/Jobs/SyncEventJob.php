<?php

namespace App\Jobs;

use App\Exceptions\PermanentSyncException;
use App\Exceptions\TransientSyncException;
use App\Models\Event;
use App\Models\EventSyncLog;
use App\Services\Contracts\PortalSyncInterface;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum seconds this job may run before the queue worker kills it.
     */
    public int $timeout = 180;

    /**
     * Maximum queue-level retry attempts before Laravel marks the job as failed.
     */
    public int $tries = 10;

    /**
     * Maximum exceptions allowed before Laravel marks the job as failed.
     */
    public int $maxExceptions = 10;

    public function __construct(protected Event $event)
    {
        $this->connection = 'database';
        $this->queue = 'default';
    }

    /**
     * WithoutOverlapping: prevents parallel workers from processing the same event ID.
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->event->id)];
    }

    public function handle(PortalSyncInterface $syncService): void
    {
        // Pre-flight guard: synchronous queue driver breaks async constraints.
        if (config('queue.default') === 'sync') {
            Log::channel('sync')->warning(
                'Synchronous queue driver is active for NMBA background sync operation. Execution bypasses standard database backgrounding.'
            );
        }

        // Always refresh from DB to get the latest state — model may be stale from dispatch time.
        $this->event->refresh();

        // Pre-flight circuit breaker check: skip processing if the circuit breaker is active.
        if (\Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down') === true) {
            Log::channel('sync')->info('Deferred Sync: Circuit breaker is active. Silently deleting job so scheduler can re-dispatch later.', [
                'event_id' => $this->event->id,
            ]);
            $this->delete();
            return;
        }

        // Cycle-reset: when attempts reach 9, reset the counter and return the event
        // to 'pending' status, then delete this job. The scheduler's next 5-minute sweep
        // will re-select it and dispatch a fresh job.
        // This avoids an infinite hot-loop in the queue while keeping the event alive for retry.
        if ($this->event->sync_attempts >= 9) {
            Log::channel('sync')->info('Attempt cycle complete — resetting counter and returning to pending pool.', [
                'event_id'      => $this->event->id,
                'sync_attempts' => $this->event->sync_attempts,
            ]);
            $this->event->update([
                'sync_attempts'   => 0,
                'sync_status'     => 'pending',
                'last_attempt_at' => now(),
            ]);
            // Clear dispatch lock so the scheduler can re-select this event next sweep.
            \Illuminate\Support\Facades\Cache::forget("sre_sync_dispatch_lock_{$this->event->id}");
            $this->delete();
            return;
        }

        // Atomic Compare-And-Set (CAS) claim: atomically transition pending → syncing
        // and increment attempt counter simultaneously. If zero rows updated, another worker
        // claimed this record — exit immediately to prevent double processing.
        $updated = Event::where('id', $this->event->id)
            ->where('sync_status', 'pending')
            ->update([
                'sync_status'    => 'syncing',
                'sync_attempts'  => DB::raw('sync_attempts + 1'),
                'last_attempt_at'=> now(),
            ]);

        if ($updated === 0) {
            Log::channel('sync')->info('CAS claim rejected — record already claimed by another worker.', [
                'event_id' => $this->event->id,
            ]);
            return;
        }

        $this->event->refresh();
        $startTime = microtime(true);

        try {
            $success = $syncService->sync($this->event);

            $durationMs  = (int) round((microtime(true) - $startTime) * 1000);
            $storedPaths = $this->event->photo_paths;

            if ($success) {
                $this->event->markSynced();

                // Clear any pending dispatch cache lock
                \Illuminate\Support\Facades\Cache::forget("sre_sync_dispatch_lock_{$this->event->id}");

                // Reset consecutive auth failures counter on success
                \Illuminate\Support\Facades\Cache::forget('sre_consecutive_auth_failures');

                // Audit log: record successful sync attempt
                $this->writeSyncLog('success', null, null);

                // Post-sync media management: move files to 'events/synced' folder so they can be deleted later via frontend
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
                
                // Update the event with the new photo paths
                if (!empty($newPaths)) {
                    $this->event->photo_paths = $newPaths;
                    $this->event->save();
                }

                Log::channel('sync')->info('Event synchronized successfully. Local media purged.', [
                    'event_id'    => $this->event->id,
                    'unique_hash' => $this->event->unique_hash,
                    'sync_status' => 'synced',
                    'sync_attempts' => $this->event->sync_attempts,
                    'duration_ms' => $durationMs,
                ]);
            } else {
                // Audit log: record failed attempt before throwing
                $this->writeSyncLog('failure', null, 'Sync service returned false — portal did not confirm submission.');
                throw new TransientSyncException(
                    'Sync service returned false — portal did not confirm submission.'
                );
            }

        } catch (TransientSyncException $e) {
            $this->handleTransientFailure($e->getMessage());
        } catch (\App\Exceptions\AuthenticationSyncException $e) {
            $this->handleAuthFailure($e->getMessage());
        } catch (PermanentSyncException $e) {
            $this->handlePermanentFailure($e->getMessage());
        } catch (Exception $e) {
            Log::channel('sync')->error('Unexpected exception during sync execution: ' . $e->getMessage(), [
                'event_id'        => $this->event->id,
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
                'trace'           => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            $this->handleTransientFailure('Unexpected exception [' . get_class($e) . ']: ' . $e->getMessage());
        }
    }

    protected function handleAuthFailure(string $errorMessage): void
    {
        // Audit log: record auth failure
        $this->writeSyncLog('failure', null, $errorMessage);

        // Sanity check: if the portal is actually offline or structurally degraded,
        // do not pause auto-sync globally. Instead, handle this as a transient failure.
        try {
            $healthService = app(\App\Services\PortalHealthService::class);
            if (!$healthService->isAlive(true)) {
                Log::channel('sync')->warning('Auth failure but health check indicates portal is offline or structurally degraded — treating as transient.', [
                    'event_id' => $this->event->id,
                    'reason'   => mb_substr($errorMessage, 0, 200),
                ]);
                $this->handleTransientFailure('Portal check failed during auth error: ' . $errorMessage);
                return;
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Error running health check during auth failure: ' . $e->getMessage());
        }

        // Increment consecutive auth failures counter
        $failures = (int) \Illuminate\Support\Facades\Cache::get('sre_consecutive_auth_failures', 0) + 1;
        \Illuminate\Support\Facades\Cache::put('sre_consecutive_auth_failures', $failures, now()->addDays(1));

        if ($failures >= 3) {
            $this->event->markFailed($errorMessage);
            // Reset sync status back to pending instead of keeping it in transient retry loop
            Event::where('id', $this->event->id)->update(['sync_status' => 'pending']);

            // Clear dispatch lock since auto-sync is paused
            \Illuminate\Support\Facades\Cache::forget("sre_sync_dispatch_lock_{$this->event->id}");

            // Pause auto-sync globally
            \Illuminate\Support\Facades\Cache::put('auto_sync_paused', true);
            \Illuminate\Support\Facades\Cache::put('portal_credentials_invalid', true);

            Log::channel('sync')->error('AUTH FAILURE THRESHOLD REACHED: Event set to pending. Auto-sync paused.', [
                'event_id'             => $this->event->id,
                'consecutive_failures' => $failures,
                'reason'               => mb_substr($errorMessage, 0, 500),
            ]);

            $this->delete();
        } else {
            Log::channel('sync')->warning('Auth failure encountered but below threshold — treating as transient.', [
                'event_id'             => $this->event->id,
                'consecutive_failures' => $failures,
                'reason'               => mb_substr($errorMessage, 0, 200),
            ]);
            $this->handleTransientFailure('Auth failure (attempt ' . $failures . ' of 3): ' . $errorMessage);
        }
    }

    /**
     * Handle a retriable failure with exponential backoff + jitter.
     * Resets sync_status to 'pending' so the scheduler can re-select the record.
     */
    protected function handleTransientFailure(string $errorMessage): void
    {
        // Audit log: record transient failure before computing backoff
        $this->writeSyncLog('failure', null, $errorMessage);

        $this->event->refresh();
        $attempts = $this->event->sync_attempts;

        // Set rest time to exactly 1 minute (60 seconds)
        $delaySeconds = 60;

        $this->event->markFailed($errorMessage);

        // Update dispatch lock to align with the backoff delay (plus 30s buffer)
        \Illuminate\Support\Facades\Cache::put("sre_sync_dispatch_lock_{$this->event->id}", true, $delaySeconds + 30);

        Log::channel('sync')->warning('Transient sync failure. Job released with 60-second backoff.', [
            'event_id'      => $this->event->id,
            'unique_hash'   => $this->event->unique_hash,
            'sync_status'   => 'pending',
            'sync_attempts' => $attempts,
            'backoff_secs'  => $delaySeconds,
            'reason'        => mb_substr($errorMessage, 0, 500),
        ]);

        $this->release($delaySeconds);
    }

    /**
     * Handle an unrecoverable failure. Dead-letters the record and deletes the job.
     */
    protected function handlePermanentFailure(string $errorMessage): void
    {
        // Audit log: record permanent failure
        $this->writeSyncLog('permanent_failure', null, $errorMessage);

        $this->event->markFailedPermanently($errorMessage);

        // Clear dispatch lock
        \Illuminate\Support\Facades\Cache::forget("sre_sync_dispatch_lock_{$this->event->id}");

        Log::channel('sync')->error('PERMANENT FAILURE: Event dead-lettered.', [
            'event_id'      => $this->event->id,
            'unique_hash'   => $this->event->unique_hash,
            'sync_status'   => 'failed_permanently',
            'sync_attempts' => $this->event->sync_attempts,
            'reason'        => mb_substr($errorMessage, 0, 500),
        ]);

        $this->delete();
    }

    /**
     * FIX-OPS-02: Write one row to event_sync_logs after every API call attempt.
     *
     * @param string $outcome  'success' | 'failure' | 'permanent_failure'
     * @param int|null $httpStatusCode  Portal HTTP response code (null if connection failed)
     * @param string|null $responseSnippet  First 500 chars of response body
     */
    protected function writeSyncLog(
        string $outcome,
        ?int $httpStatusCode,
        ?string $responseSnippet
    ): void {
        try {
            EventSyncLog::create([
                'event_id'             => $this->event->id,
                'attempted_at'         => now(),
                'attempt_number'       => $this->event->sync_attempts,
                'http_status_code'     => $httpStatusCode,
                'api_response_snippet' => $responseSnippet
                    ? mb_substr($responseSnippet, 0, 500)
                    : null,
                'outcome'              => $outcome,
                'worker_pid'           => getmypid() ?: null,
            ]);
        } catch (Exception $e) {
            // Audit logging must never break the job itself
            Log::channel('sync')->warning('Failed to write sync log entry.', [
                'event_id' => $this->event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
