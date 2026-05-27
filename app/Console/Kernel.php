<?php

namespace App\Console;

use App\Jobs\SyncBatchJob;
use App\Jobs\SyncEventJob;
use App\Models\Event;
use App\Services\PortalHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function (PortalHealthService $healthService) {

            // --- Guard 0: Auto-sync paused check ---
            if (Cache::get('auto_sync_paused', false) === true) {
                Log::channel('sync')->info('Scheduler skipped — auto-sync is paused globally.');
                return;
            }

            // --- Guard 1: Circuit breaker check ---
            if (Cache::get('sre_circuit_breaker_portal_down') === true) {
                Log::channel('sync')->info('Scheduler skipped — circuit breaker is active.');
                // Track offline state so checkPortalHealth / next scheduler run can detect recovery.
                Cache::put('sre_last_portal_was_offline', true, now()->addHours(2));
                return;
            }

            // --- Guard 2: Queue flood protection ---
            try {
                $readyJobs = \Illuminate\Support\Facades\DB::table('jobs')
                    ->where('queue', 'default')
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->getTimestamp())
                    ->count();
                if ($readyJobs > 100) {
                    Log::channel('sync')->warning('Scheduler skipped — ready queue backlog exceeds 100 entries.', ['ready_jobs' => $readyJobs]);
                    return;
                }
            } catch (\Exception $e) {
                Log::channel('sync')->warning('Queue size check failed.', ['error' => $e->getMessage()]);
            }

            // --- Guard 3: Portal health pre-flight probe ---
            if (!$healthService->isAlive()) {
                Log::channel('sync')->warning('Scheduler halted — portal health probe failed. Circuit breaker tripped.');
                // Track offline state so checkPortalHealth / next scheduler run can detect recovery.
                Cache::put('sre_last_portal_was_offline', true, now()->addHours(2));
                return;
            }

            // --- Portal Recovery Detection ---
            // Portal is alive. If it was previously offline (outage), all pending events will have:
            //   1. Per-event dispatch locks set for hours (exponential backoff from handleTransientFailure).
            //   2. Queue jobs with far-future available_at (from $this->release($delaySeconds)).
            // Both must be cleared immediately so this scheduler run can re-dispatch them.
            if (Cache::get('sre_last_portal_was_offline', false)) {
                Cache::forget('sre_last_portal_was_offline');
                Log::channel('sync')->info('Scheduler: portal recovery detected — clearing dispatch locks and resetting delayed jobs.');

                // 1. Clear per-event dispatch locks.
                try {
                    Event::where('sync_status', 'pending')
                        ->where('sync_attempts', '!=', -1)
                        ->chunk(100, function ($recoverableEvents) {
                            foreach ($recoverableEvents as $re) {
                                Cache::forget("sre_sync_dispatch_lock_{$re->id}");
                            }
                        });
                } catch (\Throwable $e) {
                    Log::channel('sync')->warning('Scheduler recovery: could not clear dispatch locks: ' . $e->getMessage());
                }

                // 2. Reset all delayed queue jobs to immediately available.
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                        $resetCount = \Illuminate\Support\Facades\DB::table('jobs')
                            ->where('available_at', '>', time())
                            ->update(['available_at' => time(), 'reserved_at' => null]);
                        Log::channel('sync')->info('Scheduler recovery: delayed jobs reset to immediate.', ['reset_count' => $resetCount]);
                    }
                } catch (\Throwable $e) {
                    Log::channel('sync')->warning('Scheduler recovery: could not reset delayed jobs: ' . $e->getMessage());
                }
            }

            // --- Query: Fetch pending + zombie records (up to 100 = 5 slots × 20 each) ---
            // Uses balanced parenthesized grouping to enforce correct boolean OR precedence.
            // The outer orWhere clause is the SRE Time-Based Deadlock Breaker that recovers
            // jobs frozen in 'syncing' state for over 10 minutes (worker crash recovery).
            $events = Event::where(function ($q) {
                $q->where(function ($query) {
                    $query->where('sync_status', 'pending')
                          ->where(function ($inner) {
                              $inner->whereNull('last_attempt_at')
                                    ->orWhere('last_attempt_at', '<', now()->subMinutes(5));
                          });
                })
                ->orWhere(function ($query) {
                    $query->where('sync_status', 'syncing')
                          ->where('updated_at', '<', now()->subMinutes(10));
                });
            })
            ->whereBetween('sync_attempts', [0, 9])
            ->orderBy('created_at', 'asc')
            ->limit(160) // 8 parallel slots × 20 events each
            ->get();

            if ($events->isEmpty()) {
                return;
            }

            // Filter out events that are under a dispatch lock (backoff cooldown).
            $dispatchable = $events->filter(function ($event) {
                return !Cache::has("sre_sync_dispatch_lock_{$event->id}");
            });

            if ($dispatchable->isEmpty()) {
                Log::channel('sync')->info('Scheduler sweep: all candidates are under dispatch locks. Skipping.');
                return;
            }

            Log::channel('sync')->info('Scheduler sweep started — dispatching parallel batch jobs.', [
                'candidate_count' => $dispatchable->count(),
            ]);

            // Chunk events into up to 5 batches of 20 and dispatch one SyncBatchJob per slot.
            // Each slot gets an isolated portal session (its own cookie jar + transmission lock).
            $slotIndex = 0;
            foreach ($dispatchable->chunk(20) as $batch) {
                if ($slotIndex >= 8) {
                    break; // Maximum of 8 concurrent portal sessions
                }

                $batchIds = $batch->pluck('id')->toArray();

                // Check if this slot already has a batch running (WithoutOverlapping check).
                // If it does, skip this slot to avoid clobbering the active session.
                $slotLockKey = "laravel-queue-overlap:App\Jobs\SyncBatchJob:sync_batch_slot_{$slotIndex}";
                if (Cache::has($slotLockKey)) {
                    Log::channel('sync')->info("Scheduler: slot {$slotIndex} is busy — skipping.", [
                        'slot' => $slotIndex,
                    ]);
                    $slotIndex++;
                    continue;
                }

                dispatch(new SyncBatchJob($batchIds, $slotIndex));

                Log::channel('sync')->info("Scheduler dispatched SyncBatchJob.", [
                    'slot'        => $slotIndex,
                    'event_count' => count($batchIds),
                    'event_ids'   => $batchIds,
                ]);

                $slotIndex++;
            }

        })->everyMinute()->name('nmba_sync_orchestration_sweep')->withoutOverlapping();

        // FIX-OPS-01: Alert if events are stuck in pending for over 30 minutes.
        // Runs every 15 minutes. Sends email to ADMIN_EMAIL and writes to sync-health.log.
        $schedule->command('sync:health-check')
            ->everyFifteenMinutes()
            ->name('nmba_sync_health_check')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sync-health-scheduler.log'));

        // FIX-SEC-02: Weekly portal credential validation.
        // Tests actual authentication and writes to credential-checks.log.
        $schedule->command('portal:check-credentials', ['--quiet-on-success'])
            ->weekly()
            ->name('nmba_portal_credential_check')
            ->appendOutputTo(storage_path('logs/credential-checks-scheduler.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
