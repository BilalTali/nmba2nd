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
                
                // If it wasn't already marked offline, perform a clean sweep of the queue
                if (!Cache::get('sre_site_was_offline', false)) {
                    Log::channel('sync')->info('Scheduler: Portal transitioned to OFFLINE. Sweeping queue and resetting event statuses.');
                    try {
                        \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'default')->delete();
                        Event::where('sync_status', 'syncing')
                            ->update([
                                'sync_status' => 'pending',
                                'last_attempt_at' => now(),
                            ]);
                        Event::where('sync_status', 'pending')
                            ->chunk(100, function ($pendingEvents) {
                                foreach ($pendingEvents as $pe) {
                                    Cache::forget("sre_sync_dispatch_lock_{$pe->id}");
                                }
                            });

                        // Clear WithoutOverlapping slot locks to allow fresh start
                        for ($i = 0; $i < 8; $i++) {
                            Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
                            $this->forgetSlotCrossLock($i);
                        }
                    } catch (\Throwable $e) {
                        Log::channel('sync')->warning('Scheduler: offline sweep failed: ' . $e->getMessage());
                    }
                }

                Cache::put('sre_site_was_offline', true, 7200);
                $this->setSharedValue('sre_last_portal_was_offline', true, 7200);
                return;
            }

            // ── Guard 3: Queue flood protection ───────────────────────────
            try {
                $readyJobs = \Illuminate\Support\Facades\DB::table('jobs')
                    ->where('queue', 'default')
                    ->where(function ($query) {
                        $query->whereNull('reserved_at')
                              ->orWhere('reserved_at', '<=', now()->getTimestamp() - 900);
                    })
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
            if (Cache::get('sre_site_was_offline', false)) {
                Cache::forget('sre_site_was_offline');
                $this->forgetSharedValue('sre_last_portal_was_offline');
                Log::channel('sync')->info('Scheduler: portal recovery detected — clearing dispatch locks, slot locks and resetting delayed jobs.');

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

                // 2. Clear WithoutOverlapping slot locks to enable immediate parallel execution
                try {
                    for ($i = 0; $i < 8; $i++) {
                        Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
                        $this->forgetSlotCrossLock($i);
                    }
                } catch (\Throwable $e) {
                    Log::channel('sync')->warning('Scheduler recovery: could not clear slot locks: ' . $e->getMessage());
                }

                // 3. Reset all delayed queue jobs to immediately available
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
     * Each slot processes up to 20 events per sweep.
     * Max throughput: 8 slots × 20 events = 160 events per scheduler sweep.
     *
     * Returns the number of batch jobs dispatched.
     */
    public function dispatchPendingBatches(): int
    {
        try {
            $maxSlots = app(PortalHealthService::class)->getRecommendedSlotLimit();
        } catch (\Throwable $e) {
            $maxSlots = (int) config('services.sync.max_slots', 8);
        }
        if (!$maxSlots) {
            $maxSlots = (int) config('services.sync.max_slots', 8);
        }

        $neededCount = $maxSlots * 20; // Maximum events we could possibly dispatch (up to 20 per slot)
        $dispatchable = collect();
        $chunkSize = 500;
        $offset = 0;
        $maxSearch = 5000;

        while ($dispatchable->count() < $neededCount && $offset < $maxSearch) {
            // BN-6 FIX: Select only the columns required for dispatch-eligibility
            // checks. Avoids loading 30+ columns × 1,000 rows = unnecessary memory.
            $candidates = Event::select(['id', 'sync_status', 'sync_attempts', 'last_attempt_at', 'updated_at', 'created_at'])
                ->where(function ($q) {
                    $q->where(function ($query) {
                        $query->where('sync_status', 'pending')
                              ->where(function ($inner) {
                                  // THROUGHPUT FIX: reduced 5min → 2min.
                                  // Dispatch lock (6min) + transient lock (90s) already prevent
                                  // double-processing; 5min was unnecessarily starving events.
                                  $inner->whereNull('last_attempt_at')
                                        ->orWhere('last_attempt_at', '<', now()->subMinutes(2));
                              });
                    })
                    ->orWhere(function ($query) {
                        $query->where('sync_status', 'syncing')
                              ->where('updated_at', '<', now()->subMinutes(10));
                    });
                })
                ->whereBetween('sync_attempts', [0, 9])
                ->orderBy('created_at', 'asc')
                ->offset($offset)
                ->limit($chunkSize)
                ->get();

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $event) {
                if (!Cache::has("sre_sync_dispatch_lock_{$event->id}")) {
                    $dispatchable->push($event);
                    if ($dispatchable->count() >= $neededCount) {
                        break;
                    }
                }
            }

            if ($candidates->count() < $chunkSize) {
                break;
            }

            $offset += $chunkSize;
        }

        if ($dispatchable->isEmpty()) {
            return 0;
        }

        Log::channel('sync')->info('Scheduler sweep started — dispatching parallel batch jobs.', [
            'candidate_count' => $dispatchable->count(),
        ]);

        $isCtet = str_contains(config('app.url', ''), 'ctetmonktest');
        $slotOrder = [];
        if ($isCtet) {
            for ($i = $maxSlots - 1; $i >= 0; $i--) {
                $slotOrder[] = $i;
            }
        } else {
            for ($i = 0; $i < $maxSlots; $i++) {
                $slotOrder[] = $i;
            }
        }

        $batchIndex = 0;
        $dispatched = 0;

        // THROUGHPUT FIX: chunk(20) — SyncBatchJob processes up to 20 events per slot.
        // 8 slots × 20 events = 160 events per scheduler sweep (was 40 with chunk(5)).
        // SyncBatchJob has a 35s wall-clock guard that stops processing if it runs long;
        // jitter was also reduced so a 20-event batch completes in ~60-70s comfortably.
        foreach ($dispatchable->chunk(20) as $batch) {
            if ($batchIndex >= $maxSlots) {
                break;
            }

            $assignedSlot = null;
            while ($batchIndex < $maxSlots) {
                $slotToCheck = $slotOrder[$batchIndex];

                // Skip if this slot already has a live batch (WithoutOverlapping lock or cross-site slot lock)
                $slotLockKey = "laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$slotToCheck}";
                $isLocalLocked = Cache::has($slotLockKey);
                $isCrossLocked = $this->isSlotCrossLocked($slotToCheck);

                if (!$isLocalLocked && !$isCrossLocked) {
                    $assignedSlot = $slotToCheck;
                    $batchIndex++;
                    break;
                }

                $reason = $isLocalLocked ? 'busy locally' : 'busy cross-site';
                Log::channel('sync')->info("Scheduler: slot {$slotToCheck} is {$reason} — skipping.", [
                    'slot' => $slotToCheck,
                ]);
                $batchIndex++;
            }

            if ($assignedSlot === null) {
                break;
            }

            $batchIds = $batch->pluck('id')->toArray();

            dispatch(new SyncBatchJob($batchIds, $assignedSlot));

            // Dispatch lock: 6 min, prevents duplicate-selection of same events.
            // Previously 10 min — reduced so events don't stay locked if a worker dies early.
            foreach ($batchIds as $id) {
                Cache::put("sre_sync_dispatch_lock_{$id}", true, now()->addMinutes(6));
            }

            Log::channel('sync')->info("Scheduler dispatched SyncBatchJob.", [
                'slot'        => $assignedSlot,
                'event_count' => count($batchIds),
            ]);

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
