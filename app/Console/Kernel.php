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

use App\Traits\SharedCacheTrait;

class Kernel extends ConsoleKernel
{
    use SharedCacheTrait;

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function (PortalHealthService $healthService) {

            // ── Guard 0: Auto-sync paused ─────────────────────────────────
            if ($this->getSharedValue('auto_sync_paused', false) === true) {
                Log::channel('sync')->info('Scheduler skipped — auto-sync is paused globally.');
                return;
            }

            // ── Guard 1: Portal liveness check ────────────────────────────
            //
            // CHANGED from previous behaviour:
            // Before: if circuit_breaker set → hard return (wastes up to 60s of potential uptime).
            // Now:    circuit_breaker TTL is only 8s, so by the time the scheduler fires again
            //         it will already have expired. We still respect it on the current tick,
            //         BUT first we check the cross-site portal_live_window — if another site
            //         on this server confirmed the portal alive in the last 20s, we override
            //         the local circuit breaker and proceed to sync immediately.
            $circuitBreakerActive = $this->getSharedValue('sre_circuit_breaker_portal_down') === true;

            if ($circuitBreakerActive) {
                // Check cross-site signal: maybe the other deployment found it alive
                $liveWindowAt = $this->readPortalLiveWindow();
                $liveWindowFresh = $liveWindowAt !== null && (time() - $liveWindowAt) < 20;

                if (!$liveWindowFresh) {
                    Log::channel('sync')->info('Scheduler skipped — circuit breaker active, no cross-site live signal.');
                    $this->setSharedValue('sre_last_portal_was_offline', true, 7200);
                    return;
                }

                // Cross-site confirmed alive — clear local breaker and proceed
                Log::channel('sync')->info('Scheduler: cross-site live window overriding local circuit breaker — proceeding with sync.', [
                    'live_window_age_s' => time() - $liveWindowAt,
                ]);
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            }

            // ── Guard 2: Live portal probe ────────────────────────────────
            //
            // isAlive() now:
            //   - Checks portal_live_window first (cross-site signal, 20s TTL)
            //   - Checks local sre_portal_is_alive (10s TTL)
            //   - Falls through to live HTTP probe (12s timeout, not 90s)
            //   - On success: writes portal_live_window + sets sre_portal_is_alive(10s)
            //   - On failure: trips circuit breaker (8s TTL, not 60s)
            if (!$healthService->isAlive()) {
                Log::channel('sync')->warning('Scheduler halted — portal health probe failed. Circuit breaker tripped (8s cooldown).');
                $this->setSharedValue('sre_last_portal_was_offline', true, 7200);
                return;
            }

            // ── Guard 3: Queue flood protection ───────────────────────────
            try {
                $readyJobs = \Illuminate\Support\Facades\DB::table('jobs')
                    ->where('queue', 'default')
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->getTimestamp())
                    ->count();
                if ($readyJobs > 100) {
                    Log::channel('sync')->warning('Scheduler skipped — ready queue backlog exceeds 100 entries.', ['ready_jobs' => $readyJobs]);

                    if (app()->environment('local')) {
                        Log::channel('sync')->info('Local Environment: Auto-recovery triggered. Clearing stale queue and rebooting workers...');
                        \Illuminate\Support\Facades\Artisan::call('queue:clear', ['--force' => true]);
                        $runJobsPath = base_path('run_jobs.php');
                        $cmd = 'php ' . escapeshellarg($runJobsPath) . ' > /dev/null 2>&1 &';
                        exec($cmd);
                        Log::channel('sync')->info('Local Environment: Stale jobs cleared and run_jobs.php executed in background successfully.');
                    }

                    return;
                }
            } catch (\Exception $e) {
                Log::channel('sync')->warning('Queue size check failed.', ['error' => $e->getMessage()]);
            }

            // ── Portal Recovery Detection ─────────────────────────────────
            // Portal is alive. If it was previously offline, clear all frozen state:
            //   1. Per-event dispatch locks (set for hours by exponential backoff)
            //   2. Queue jobs with far-future available_at (from release($delaySeconds))
            if ($this->getSharedValue('sre_last_portal_was_offline', false)) {
                $this->forgetSharedValue('sre_last_portal_was_offline');
                Log::channel('sync')->info('Scheduler: portal recovery detected — clearing dispatch locks and resetting delayed jobs.');

                // 1. Clear per-event dispatch locks
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

                // 2. Reset all delayed queue jobs to immediately available
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

            // ── Dispatch: Fetch and batch all dispatchable events ─────────
            $this->dispatchPendingBatches();

        })->everyMinute()->name('nmba_sync_orchestration_sweep')->withoutOverlapping(2);

        // FIX-OPS-01: Alert if events are stuck in pending for over 30 minutes.
        $schedule->command('sync:health-check')
            ->everyFifteenMinutes()
            ->name('nmba_sync_health_check')
            ->withoutOverlapping(15)
            ->appendOutputTo(storage_path('logs/sync-health-scheduler.log'));

        // FIX-SEC-02: Weekly portal credential validation.
        $schedule->command('portal:check-credentials', ['--quiet-on-success'])
            ->weekly()
            ->name('nmba_portal_credential_check')
            ->appendOutputTo(storage_path('logs/credential-checks-scheduler.log'));
    }

    /**
     * Fetch all dispatchable pending events and fire SyncBatchJobs for each slot.
     * Extracted to a method so the cron script can also call it directly
     * without going through the full scheduler guard chain.
     *
     * Returns the number of batch jobs dispatched.
     */
    protected function dispatchPendingBatches(): int
    {
        // Query pending + zombie (stuck in syncing > 10 min) events
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
        ->limit(1000)
        ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        // Filter out events still under a dispatch lock (backoff cooldown)
        $dispatchable = $events->filter(function ($event) {
            return !Cache::has("sre_sync_dispatch_lock_{$event->id}");
        });

        if ($dispatchable->isEmpty()) {
            Log::channel('sync')->info('Scheduler sweep: all candidates are under dispatch locks. Skipping.');
            return 0;
        }

        Log::channel('sync')->info('Scheduler sweep started — dispatching parallel batch jobs.', [
            'candidate_count' => $dispatchable->count(),
        ]);

        $maxSlots  = (int) env('SYNC_MAX_SLOTS', 2);
        $slotIndex = 0;
        $dispatched = 0;

        foreach ($dispatchable->chunk(20) as $batch) {
            if ($slotIndex >= $maxSlots) {
                break;
            }

            $batchIds = $batch->pluck('id')->toArray();

            // Skip if this slot already has a live batch (WithoutOverlapping lock)
            $slotLockKey = "laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$slotIndex}";
            if (Cache::has($slotLockKey)) {
                Log::channel('sync')->info("Scheduler: slot {$slotIndex} is busy — skipping.", [
                    'slot' => $slotIndex,
                ]);
                $slotIndex++;
                continue;
            }

            dispatch(new SyncBatchJob($batchIds, $slotIndex));

            // Dispatch lock: 10 min, prevents duplicate-selection of same events
            foreach ($batchIds as $id) {
                Cache::put("sre_sync_dispatch_lock_{$id}", true, now()->addMinutes(10));
            }

            Log::channel('sync')->info("Scheduler dispatched SyncBatchJob.", [
                'slot'        => $slotIndex,
                'event_count' => count($batchIds),
            ]);

            $slotIndex++;
            $dispatched++;
        }

        return $dispatched;
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
