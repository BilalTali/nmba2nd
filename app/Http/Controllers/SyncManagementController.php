<?php

namespace App\Http\Controllers;

use App\Console\Kernel as ConsoleKernel;
use App\Jobs\SyncBatchJob;
use App\Models\Event;
use App\Traits\SharedCacheTrait;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * SyncManagementController — all admin sync write-operations.
 *
 * Extracted from EventController (GAP-6 refactor).
 * Responsibilities:
 *   - toggleSyncStatus()    — manually flip pending ↔ synced
 *   - retrySync()           — reset + re-dispatch via SyncBatchJob (GAP-2 fix)
 *   - toggleAutoSync()      — pause / resume global auto-sync flag
 *   - forceSync()           — clear all locks + immediately dispatch pending batches (GAP-3 fix)
 *   - resetFailedSyncs()    — bulk reset failed_permanently events to pending
 *   - runQueueWorkerManually() — run queue:work inline for up to 25 jobs
 *   - clearQueueManually()  — artisan queue:clear
 *   - purgeSyncedMedia()    — delete photo files for synced events
 *   - runQueueWorkerInBackground() — shared helper: exec() + web cron loopback
 */
class SyncManagementController extends Controller
{
    use SharedCacheTrait;

    // ── Toggle sync status (pending ↔ synced) ────────────────────────────────

    public function toggleSyncStatus(Event $event): RedirectResponse
    {
        if ($event->sync_status === 'synced' || $event->sync_status === 'failed_permanently') {
            $event->update([
                'sync_status'     => 'pending',
                'sync_attempts'   => -1, // manual lockout — scheduler will not auto-dispatch
                'last_attempt_at' => null,
                'last_error_log'  => null,
            ]);
            $message = "Event #{$event->id} manually set to Pending (locked from auto-sync).";
        } else {
            $event->update([
                'sync_status'     => 'synced',
                'sync_attempts'   => 0,
                'last_attempt_at' => now(),
                'last_error_log'  => null,
            ]);
            $message = "Event #{$event->id} manually marked as Synced.";
        }

        Cache::forget('dashboard_metrics_counts');
        return redirect()->route('dashboard')->with('success', $message);
    }

    // ── Retry a single event — GAP-2 fix ─────────────────────────────────────
    // Previously dispatched the legacy SyncEventJob (bypasses session slots,
    // cookies, CookieJar, circuit breaker). Now dispatches SyncBatchJob on
    // slot 0 (the dedicated manual-retry slot).

    public function retrySync(Event $event): RedirectResponse
    {
        $event->update([
            'sync_status'     => 'pending',
            'sync_attempts'   => 0,
            'last_attempt_at' => null,
            'last_error_log'  => null,
        ]);

        // Clear circuit breaker so the job can proceed immediately.
        Cache::forget('sre_circuit_breaker_portal_down');
        Cache::forget("sre_sync_dispatch_lock_{$event->id}");

        // GAP-2 FIX: Use SyncBatchJob (slot 0) instead of legacy SyncEventJob.
        // SyncBatchJob reuses the cookie jar, respects the circuit breaker, and
        // follows the same auth flow as the scheduler's parallel batch workers.
        dispatch(new SyncBatchJob([$event->id], 0));

        $this->runQueueWorkerInBackground();
        Cache::forget('dashboard_metrics_counts');

        return redirect()->route('dashboard')
            ->with('success', "Event #{$event->id} reset and queued for retry via SyncBatchJob slot 0.");
    }

    // ── Pause / resume global auto-sync ──────────────────────────────────────

    public function toggleAutoSync(): RedirectResponse
    {
        $isPaused = $this->getSharedValue('auto_sync_paused', false);
        $this->setSharedValue('auto_sync_paused', !$isPaused, 86400 * 365);

        $message = !$isPaused
            ? 'Automatic synchronization PAUSED — queue will hold pending items.'
            : 'Automatic synchronization RESUMED — syncing will execute on next sweep.';

        return redirect()->route('dashboard')->with('success', $message);
    }

    // ── Force Sync — GAP-3 fix ────────────────────────────────────────────────
    // Previously called ensurePendingEventsAreQueued() which was a blank stub.
    // Now calls Kernel::dispatchPendingBatches() directly — same logic the
    // 1-minute cron uses, executed immediately on button press.

    public function forceSync(Request $request): RedirectResponse
    {
        $key = 'force-sync-limit:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::attempts($key) >= 5) {
            RateLimiter::clear($key);
        } else {
            RateLimiter::hit($key, 60);
        }

        try {
            // Reset all delayed job timers so the running daemon picks them up immediately.
            try {
                if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
                    DB::table('jobs')->update(['available_at' => time(), 'reserved_at' => null]);
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('forceSync: could not reset delayed jobs — ' . $e->getMessage());
            }

            // Clear all lock signals.
            $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            $this->setSharedValue('sre_portal_is_alive', true, 90); // Aligned to PortalHealthService::$aliveTtl (was 300s)
            $this->forgetSharedValue('auto_sync_paused');
            $this->forgetSharedValue('sre_consecutive_auth_failures');
            $this->forgetSharedValue('portal_credentials_invalid');

            // Clear per-event dispatch locks for all pending events.
            Event::where('sync_status', 'pending')
                ->chunk(100, function ($pendingEvents) {
                    foreach ($pendingEvents as $event) {
                        Cache::forget("manual_override_{$event->id}");
                        Cache::forget("sre_sync_dispatch_lock_{$event->id}");
                    }
                });

            // Clear orphaned queue jobs.
            try {
                if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
                    DB::table('jobs')->where('queue', 'default')->delete();
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('forceSync: could not clear queue — ' . $e->getMessage());
            }

            // GAP-3 FIX: Actually dispatch pending batches immediately instead of
            // calling the blank stub ensurePendingEventsAreQueued().
            $dispatched = app(ConsoleKernel::class)->dispatchPendingBatches();

            $this->runQueueWorkerInBackground();
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', "Force sync triggered — {$dispatched} batch job(s) dispatched immediately.");
        } catch (Exception $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Force sync error: ' . $e->getMessage()]);
        }
    }

    // ── Reset all failed_permanently events to pending ────────────────────────

    public function resetFailedSyncs(): RedirectResponse
    {
        try {
            $updatedCount = Event::where('sync_status', 'failed_permanently')
                ->orWhere('sync_attempts', -1)
                ->update([
                    'sync_status'     => 'pending',
                    'sync_attempts'   => 0,
                    'last_attempt_at' => null,
                    'last_error_log'  => null,
                ]);

            $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            $this->forgetSharedValue('auto_sync_paused');
            $this->forgetSharedValue('sre_consecutive_auth_failures');
            $this->forgetSharedValue('portal_credentials_invalid');

            for ($i = 0; $i < 8; $i++) {
                Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
            }

            Cache::forget('dashboard_metrics_counts');
            $this->runQueueWorkerInBackground();

            return redirect()->route('dashboard')
                ->with('success', "Successfully reset {$updatedCount} failed or quarantined events back to pending. The background sync daemon will process them shortly.");
        } catch (Exception $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Reset failed: ' . $e->getMessage()]);
        }
    }

    // ── Run queue worker inline (up to 25 jobs) ───────────────────────────────

    public function runQueueWorkerManually(): RedirectResponse
    {
        Cache::forget('sre_circuit_breaker_portal_down');
        $start = microtime(true);

        try {
            Artisan::call('queue:work', [
                'connection'      => 'database',
                '--queue'         => 'default',
                '--max-jobs'      => 25,
                '--stop-when-empty' => true,
                '--tries'         => 1,
                '--timeout'       => 30,
            ]);

            $duration = round(microtime(true) - $start, 2);
            $output   = Artisan::output();
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', "Queue worker completed in {$duration}s. " . ($output ?: 'No jobs in queue.'));
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Queue worker error: ' . $e->getMessage()]);
        }
    }

    // ── Clear entire queue ────────────────────────────────────────────────────

    public function clearQueueManually(): RedirectResponse
    {
        try {
            Artisan::call('queue:clear', [
                'connection' => 'database',
                '--queue'    => 'default',
                '--force'    => true,
            ]);

            $output = Artisan::output();
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', 'Queue cleared. ' . ($output ?: 'All pending jobs removed.'));
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Queue clear error: ' . $e->getMessage()]);
        }
    }

    // ── Purge photo files for synced events ───────────────────────────────────

    public function purgeSyncedMedia(): RedirectResponse
    {
        $events       = Event::where('sync_status', 'synced')->whereNotNull('photo_paths')->get();
        $deletedCount = 0;

        foreach ($events as $event) {
            $paths = $event->photo_paths;
            if (is_array($paths)) {
                foreach ($paths as $path) {
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                        $deletedCount++;
                    }
                }
            }
            $event->update(['photo_paths' => []]);
        }

        Cache::forget('dashboard_metrics_counts');
        Log::channel('sync')->info('Admin purged synced media.', ['files_deleted' => $deletedCount, 'admin_id' => auth()->id()]);

        return back()->with('success', "Purged {$deletedCount} media files from synced events.");
    }

    // ── Shared: trigger background queue worker ───────────────────────────────
    // Called by DashboardController::checkPortalHealth() and all write actions
    // that need to wake the worker after state changes.

    public function runQueueWorkerInBackground(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        $host        = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || php_sapi_name() === 'cli';

        if ($isLocalhost) {
            return;
        }

        $spawnLockKey = 'sre_background_worker_spawn_lock';
        if (Cache::has($spawnLockKey)) {
            return;
        }
        Cache::put($spawnLockKey, true, 60);

        // Try exec() first (most reliable when available).
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('exec')) {
                $phpBinary = PHP_BINARY;
                if (preg_match('/php-fpm[0-9.]*$/i', $phpBinary)) {
                    $phpBinary = preg_replace('/php-fpm[0-9.]*$/i', 'php', $phpBinary);
                } elseif (preg_match('/php-cgi[0-9.]*$/i', $phpBinary)) {
                    $phpBinary = preg_replace('/php-cgi[0-9.]*$/i', 'php', $phpBinary);
                }
                if (!file_exists($phpBinary) || !is_executable($phpBinary)) {
                    $phpBinary = 'php';
                }
                $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg(base_path('artisan')) . ' queue:work database --max-jobs=25 --tries=10 --timeout=110';
                exec($cmd . ' > /dev/null 2>&1 &');
                Log::channel('sync')->info('Background queue worker started via exec().');
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('exec() queue worker failed: ' . $e->getMessage());
        }

        // Always also fire the web cron loopback (works on Hostinger shared hosting).
        try {
            $cronToken = config('services.cron.token');
            if ($cronToken && !$isLocalhost) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $cronUrl  = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $cronUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                $ch = null;
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Loopback cron trigger failed: ' . $e->getMessage());
        }

        // Bulletproof shutdown fallback: 5 inline jobs after response is sent.
        if (!$isLocalhost) {
            register_shutdown_function(function () {
                try {
                    if (Cache::get('sre_circuit_breaker_portal_down') !== true) {
                        Artisan::call('queue:work', [
                            'connection'        => 'database',
                            '--max-jobs'        => 5,
                            '--stop-when-empty' => true,
                            '--timeout'         => 110,
                            '--quiet'           => true,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Silent — never crash the shutdown sequence
                }
            });
        }
    }
}
