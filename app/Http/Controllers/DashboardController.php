<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SystemTelemetry;
use App\Http\Controllers\SyncManagementController;
use App\Services\PortalHealthService;
use App\Traits\SharedCacheTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * DashboardController — read-only telemetry and log views.
 *
 * Extracted from EventController (GAP-6 refactor).
 * Responsibilities:
 *   - Render the admin dashboard (Inertia)
 *   - Serve the /events/check-portal JSON health endpoint (polled every 15s)
 *   - Render the sync log and audit log admin pages
 *   - Record + retrieve system telemetry
 */
class DashboardController extends Controller
{
    use SharedCacheTrait;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getBlocks(): array
    {
        return \App\Models\Block::orderBy('name')->pluck('name', 'id')->toArray();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(): Response
    {
        $autoSyncPaused           = $this->getSharedValue('auto_sync_paused', false);
        $portalCredentialsInvalid = $this->getSharedValue('portal_credentials_invalid', false);

        // Cache dashboard counts for 30 seconds to completely eliminate DB pressure under heavy polling.
        $cachedMetrics = Cache::remember('dashboard_metrics_counts', 30, function () {
            $counts = Event::selectRaw('sync_status, COUNT(*) as total')
                ->groupBy('sync_status')
                ->pluck('total', 'sync_status');

            $transient = Event::where('sync_attempts', '>', 0)
                ->where('sync_status', 'pending')
                ->count();

            return [
                'counts'    => $counts->toArray(),
                'transient' => $transient,
            ];
        });

        $counts  = collect($cachedMetrics['counts']);
        $metrics = [
            'total'       => $counts->sum(),
            'pending'     => (int) ($counts->get('pending', 0)),
            'syncing'     => (int) ($counts->get('syncing', 0)),
            'synced'      => (int) ($counts->get('synced', 0)),
            'failed_perm' => (int) ($counts->get('failed_permanently', 0)),
            'transient'   => (int) $cachedMetrics['transient'],
        ];

        // Self-healing Watchdog: if Hostinger cron fails, visiting the dashboard silently wakes up the worker.
        if (!Cache::has('sre_dashboard_cron_watchdog') && !Cache::get('sre_circuit_breaker_portal_down', false) && ($metrics['pending'] > 0)) {
            Cache::put('sre_dashboard_cron_watchdog', true, 10);
            try {
                $host        = $_SERVER['HTTP_HOST'] ?? '';
                $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

                if (!$isLocalhost) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $cronUrl  = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode(config('services.cron.token', ''));

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
                // Silent watchdog — never crash the dashboard load
            }
        }

        $cachedHeavyQueries = Cache::remember('dashboard_heavy_queries', 30, function () use ($counts) {
            $recentEvents   = Event::orderBy('created_at', 'desc')->limit(20)->get();
            $recentFailures = Event::whereNotNull('last_error_log')->orderBy('last_attempt_at', 'desc')->limit(10)->get();

            $statusData = array_values(array_filter([
                ['name' => 'Synced',  'value' => (int) $counts->get('synced', 0),             'fill' => '#10b981'],
                ['name' => 'Pending', 'value' => (int) $counts->get('pending', 0),            'fill' => '#f59e0b'],
                ['name' => 'Failed',  'value' => (int) $counts->get('failed_permanently', 0), 'fill' => '#f43f5e'],
                ['name' => 'Syncing', 'value' => (int) $counts->get('syncing', 0),            'fill' => '#3b82f6'],
            ], fn($item) => $item['value'] > 0));

            $blocks        = $this->getBlocks();
            $eventsByBlock = Event::selectRaw('block_id, COUNT(*) as count')
                ->groupBy('block_id')
                ->get()
                ->map(fn($item) => [
                    'name'  => $blocks[$item->block_id] ?? 'Unknown',
                    'count' => $item->count,
                ])
                ->sortByDesc('count')
                ->values();

            $eventsOverTimeRaw = Event::where('created_at', '>=', now()->subDays(30)->startOfDay())
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->pluck('count', 'date');

            $eventsOverTime = collect();
            for ($i = 30; $i >= 0; $i--) {
                $carbonDate = now()->subDays($i);
                $eventsOverTime->push([
                    'date'  => $carbonDate->format('M d'),
                    'count' => $eventsOverTimeRaw->get($carbonDate->format('Y-m-d'), 0),
                ]);
            }

            return [
                'recentEvents'   => $recentEvents,
                'recentFailures' => $recentFailures,
                'statusData'     => $statusData,
                'eventsByBlock'  => $eventsByBlock,
                'eventsOverTime' => $eventsOverTime,
            ];
        });

        $envFile     = base_path('.env');
        $envContent  = file_exists($envFile) ? file_get_contents($envFile) : '';
        preg_match('/^PORTAL_URL=(.*)$/m', $envContent, $urlMatch);
        preg_match('/^PORTAL_EMAIL=(.*)$/m', $envContent, $emailMatch);
        preg_match('/^PORTAL_PASSWORD=(.*)$/m', $envContent, $passwordMatch);

        return Inertia::render('Events/Dashboard', [
            'metrics'                 => $metrics,
            'recentEvents'            => $cachedHeavyQueries['recentEvents'],
            'recentFailures'          => $cachedHeavyQueries['recentFailures'],
            'autoSyncPaused'          => $autoSyncPaused,
            'portalCredentialsInvalid' => $portalCredentialsInvalid,
            'statusData'              => $cachedHeavyQueries['statusData'],
            'eventsByBlock'           => $cachedHeavyQueries['eventsByBlock'],
            'eventsOverTime'          => $cachedHeavyQueries['eventsOverTime'],
            'telemetryData'           => $this->getTelemetryHistory(),
            'portalConfig'            => [
                'portal_url'     => trim($urlMatch[1]    ?? config('services.portal.url', '')),
                'admin_id'       => trim($emailMatch[1]  ?? config('services.portal.email', '')),
                'admin_password' => trim($passwordMatch[1] ?? config('services.portal.password', ''), '"'),
            ],
        ]);
    }

    // ── Portal Health (polled every 15s by Dashboard.jsx) ─────────────────────

    public function checkPortalHealth(PortalHealthService $healthService): JsonResponse
    {
        $wasOfflinePreviously = $this->getSharedValue('sre_last_portal_was_offline', false);

        $isOnline = $this->getSharedValue('sre_portal_is_alive', false) === true
            && $this->getSharedValue('sre_circuit_breaker_portal_down') !== true;

        if (!$isOnline && $this->getSharedValue('sre_circuit_breaker_portal_down') !== true) {
            $liveWindow = $this->readPortalLiveWindow();
            // 450s = 3× aliveTtl (150s). Covers up to 3 missed cron cycles before showing
            // as offline. The live_window file is re-written on every successful cron probe.
            if ($liveWindow !== null && (time() - $liveWindow) < 450) {
                // Aligned to PortalHealthService::$aliveTtl (150s).
                $this->setSharedValue('sre_portal_is_alive', true, 150);
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
                $isOnline = true;
                Log::channel('sync')->info('Dashboard health check: portal_live_window hit — restoring online state.', [
                    'window_age_seconds' => time() - $liveWindow,
                ]);
            }
        }

        // DEGRADED STATE: portal login responds but event submissions are failing (522/524).
        // sre_portal_is_degraded is set by PortalHealthService::tripCircuitBreaker() with a
        // 120s TTL — much longer than the 8s circuit breaker — so the dashboard reliably
        // sees this state across multiple 15s health polls.
        // It is cleared by SyncBatchJob when at least one event syncs successfully.
        $isDegraded = $isOnline && $this->getSharedValue('sre_portal_is_degraded') === true;

        $isPaused           = $this->getSharedValue('auto_sync_paused', false);
        $credentialsInvalid = $this->getSharedValue('portal_credentials_invalid', false);

        $cachedHealth = Cache::get('dashboard_metrics_counts');
        $pendingCount = $cachedHealth
            ? (int) ($cachedHealth['counts']['pending'] ?? 0)
            : Event::where('sync_status', 'pending')->count();

        // Telemetry: reflect true state — healthy=0.1s, degraded=2.0s, offline=60s
        $responseTime = $isOnline ? ($isDegraded ? 2.0 : 0.1) : 60.0;
        $this->recordTelemetry($pendingCount, $responseTime, $isOnline);
        $telemetry = $this->getTelemetryHistory();

        if (!$isOnline) {
            $this->setSharedValue('sre_last_portal_was_offline', true, 7200);
            return response()->json([
                'status'                     => 'offline',
                'pending_count'              => $pendingCount,
                'triggered_sync'             => false,
                'auto_sync_paused'           => $isPaused,
                'portal_credentials_invalid' => $credentialsInvalid,
                'telemetry'                  => $telemetry,
            ]);
        }

        $triggeredSync       = false;
        $recoveredFromOutage = false;

        if ($wasOfflinePreviously) {
            $this->forgetSharedValue('sre_last_portal_was_offline');
            $recoveredFromOutage = true;
            Log::channel('sync')->info('Portal back online — outage recovery triggered.', ['pending_count' => $pendingCount]);

            try {
                Event::where('sync_status', 'pending')
                    ->where('sync_attempts', '!=', -1)
                    ->chunk(100, function ($pendingEvents) {
                        foreach ($pendingEvents as $pe) {
                            Cache::forget("sre_sync_dispatch_lock_{$pe->id}");
                        }
                    });
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not clear dispatch locks on portal recovery: ' . $e->getMessage());
            }

            try {
                for ($i = 0; $i < 8; $i++) {
                    Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not clear slot locks on portal recovery: ' . $e->getMessage());
            }

            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    DB::table('jobs')
                        ->where('available_at', '>', time())
                        ->update(['available_at' => time(), 'reserved_at' => null]);
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not reset delayed jobs on portal recovery: ' . $e->getMessage());
            }

            Cache::forget('dashboard_metrics_counts');
        }

        if (!$isPaused) {
            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    if (DB::table('jobs')->where('available_at', '>', time())->exists()) {
                        $triggeredSync = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not check delayed jobs in checkPortalHealth: ' . $e->getMessage());
            }

            $pendingActiveCount = Event::where('sync_status', 'pending')
                ->where('sync_attempts', '!=', -1)
                ->count();

            if ($pendingActiveCount > 0) {
                $triggeredSync = true;
            }

            if ($triggeredSync || $recoveredFromOutage) {
                app(SyncManagementController::class)->runQueueWorkerInBackground();
            }
        }

        return response()->json([
            'status'                     => $isDegraded ? 'degraded' : 'online',
            'pending_count'              => $pendingCount,
            'triggered_sync'             => $triggeredSync,
            'auto_sync_paused'           => $isPaused,
            'portal_credentials_invalid' => $credentialsInvalid,
            'recovered_from_outage'      => $recoveredFromOutage,
            'telemetry'                  => $telemetry,
        ]);
    }

    // ── Log Views ─────────────────────────────────────────────────────────────

    public function viewSyncLogs(): Response
    {
        $logPath    = storage_path('logs/sync-' . now(config('app.timezone'))->format('Y-m-d') . '.log');
        $parsedLogs = [];

        if (!file_exists($logPath)) {
            $files = glob(storage_path('logs/sync-*.log'));
            if (!empty($files)) {
                $logPath = end($files);
            }
        }

        if (file_exists($logPath)) {
            $lines = new \SplFixedArray(300);
            $index = 0;
            $count = 0;

            $handle = @fopen($logPath, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    if (preg_match('/^\[(.*?)\] (.*?)\.(.*?): (.*)$/', $line)) {
                        $lines[$index] = $line;
                        $index         = ($index + 1) % 300;
                        $count++;
                    }
                }
                fclose($handle);
            }

            $totalToTake = min($count, 300);
            $startIndex  = $count > 300 ? $index : 0;
            $lastLines   = [];
            for ($i = 0; $i < $totalToTake; $i++) {
                $lastLines[] = $lines[($startIndex + $i) % 300];
            }

            foreach ($lastLines as $line) {
                if (preg_match('/^\[(.*?)\] (.*?)\.(.*?): (.*)$/', $line, $matches)) {
                    $context = null;
                    $rest    = $matches[4];
                    if (preg_match('/^(.*?) (\{.*\})$/', $rest, $jsonMatches)) {
                        $rest    = trim($jsonMatches[1]);
                        $context = json_decode($jsonMatches[2], true);
                    }
                    $parsedLogs[] = [
                        'timestamp' => $matches[1],
                        'level'     => strtoupper($matches[3]),
                        'message'   => $rest,
                        'context'   => $context,
                        'raw'       => $line,
                    ];
                }
            }
        }

        return Inertia::render('Admin/Logs/Sync', [
            'logs' => array_reverse($parsedLogs),
        ]);
    }

    public function viewAuditLogs(): SymfonyResponse
    {
        $files = glob(storage_path('audit/hash-audit-*.log'));
        if (empty($files)) {
            return response('No audit logs found. Run deploy.sh or the audit:rehash-events command.', 200, ['Content-Type' => 'text/plain']);
        }
        return response(file_get_contents(end($files)), 200, ['Content-Type' => 'text/plain']);
    }

    // ── Telemetry ─────────────────────────────────────────────────────────────

    public function recordTelemetry(int $pendingCount, float $responseTime, bool $isOnline): void
    {
        // 60s lock: one browser-poll telemetry record per minute.
        // The cron writes its own record via cronWriteTelemetry() using a separate
        // file-based lock, so browser and cron never block each other.
        $lockKey = 'telemetry_log_lock';
        if (!Cache::has($lockKey)) {
            Cache::put($lockKey, true, 60);

            $load       = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0) : 0;
            $mem        = memory_get_usage(true) / 1024 / 1024;
            $diskFree   = @disk_free_space('/') ?: 0;
            $diskTotal  = @disk_total_space('/') ?: 1;
            $diskUsage  = 100 - (($diskFree / $diskTotal) * 100);

            SystemTelemetry::create([
                'cpu_load'      => $load,
                'memory_usage'  => $mem,
                'disk_usage'    => $diskUsage,
                'pending_jobs'  => $pendingCount,
                'response_time' => $responseTime,
                'is_online'     => $isOnline,
            ]);

            SystemTelemetry::where('created_at', '<', now()->subHours(24))->delete();
        }
    }

    public function getTelemetryHistory(): \Illuminate\Support\Collection
    {
        if (SystemTelemetry::count() <= 1) {
            // Seed placeholder data so the uptime chart is not empty on first load.
            // ALL seed points are online — no fake outages — so seed data never
            // artificially lowers the uptime percentage.
            $now = now();
            Cache::put('system_telemetry_seed_cutoff', $now->timestamp, 48 * 3600);
            for ($i = 288; $i >= 0; $i--) {
                $time      = (clone $now)->subMinutes($i * 5);
                $load      = 1.0 + (sin($i / 10) * 0.4) + (rand(0, 100) / 200.0);
                $mem       = 45.0 + (cos($i / 10) * 3.0) + (rand(0, 100) / 50.0);
                $diskFree  = @disk_free_space('/') ?: 0;
                $diskTotal = @disk_total_space('/') ?: 1;
                $diskUsage = 100 - (($diskFree / $diskTotal) * 100);

                [$isOnline, $pending, $latency] = $this->telemetrySeedPoint($i);

                SystemTelemetry::create([
                    'created_at'    => $time,
                    'cpu_load'      => $load,
                    'memory_usage'  => $mem,
                    'disk_usage'    => $diskUsage,
                    'pending_jobs'  => $pending,
                    'response_time' => $latency,
                    'is_online'     => $isOnline,
                ]);
            }
        } else {
            // Auto-purge stale seed records once enough real telemetry has accumulated.
            // Seed cutoff is stored as a Unix timestamp in cache at seed time.
            // Once 20+ real records exist after the seed cutoff, the fake seed rows are
            // deleted — ensuring historical uptime reflects only live probe data.
            $seedCutoff = Cache::get('system_telemetry_seed_cutoff');
            if ($seedCutoff) {
                $cutoffCarbon = \Carbon\Carbon::createFromTimestamp($seedCutoff);
                $realCount    = SystemTelemetry::where('created_at', '>', $cutoffCarbon)->count();
                // Also purge immediately when any real offline records exist:
                // seed data (all is_online=true) mixed with offline records causes
                // every 30-min bucket to appear as 'degraded' (orange) instead of
                // 'offline' (red) because ratio > 0 even though portal was down.
                $offlineCount = SystemTelemetry::where('created_at', '>', $cutoffCarbon)
                    ->where('is_online', false)
                    ->count();
                if ($realCount >= 20 || $offlineCount >= 5) {
                    SystemTelemetry::where('created_at', '<=', $cutoffCarbon)->delete();
                    Cache::forget('system_telemetry_seed_cutoff');
                    Log::channel('sync')->info('Telemetry: stale seed data auto-purged.', [
                        'real_records_present'    => $realCount,
                        'offline_records_present' => $offlineCount,
                        'seed_cutoff'             => $cutoffCarbon->toDateTimeString(),
                    ]);
                }
            }
        }

        return SystemTelemetry::where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->get()
            ->reverse()
            ->values()
            ->map(fn($t) => [
                'time'      => $t->created_at->setTimezone('Asia/Kolkata')->format('H:i'),
                'timestamp' => $t->created_at->timestamp,
                'cpu'       => round($t->cpu_load, 2),
                'memory'    => round($t->memory_usage, 1),
                'disk'      => round($t->disk_usage, 1),
                'pending'   => $t->pending_jobs,
                'latency'   => round($t->response_time * 1000, 0),
                'is_online' => (bool) $t->is_online,
            ]);
    }

    /** Generate placeholder telemetry seed data — all online, no fake outages. */
    private function telemetrySeedPoint(int $i): array
    {
        // All seed data is placeholder only — never insert fake offline periods.
        // Real outage history accumulates from live probes (every 15s from checkPortalHealth).
        // Keeping seed at 100% online ensures the uptime baseline starts at 100%
        // and drops only as real offline events occur.
        return [true, 0, 0.12 + rand(0, 80) / 1000.0];
    }

    /**
     * Get date-wise sync report for last N hours for the local portal only.
     */
    public function getSyncReport(\Illuminate\Http\Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 12);
        if ($hours <= 0) {
            return response()->json(['error' => 'Hours must be a positive integer.'], 400);
        }

        $localDb = config('database.connections.mysql.database');
        $localName = ($localDb === 'u335000182_nmbabudgam') ? 'nmbabudgam.in' : 'ctetmonktest.fun';

        try {
            $localResults = DB::select("
                SELECT event_date, COUNT(*) AS total
                FROM events
                WHERE sync_status = 'synced' AND synced_at >= NOW() - INTERVAL :hours HOUR
                GROUP BY event_date
                ORDER BY event_date
            ", ['hours' => $hours]);
        } catch (\Exception $e) {
            return response()->json(['error' => "Failed to query database: " . $e->getMessage()], 500);
        }

        $report = [];
        foreach ($localResults as $row) {
            $report[] = [
                'date' => $row->event_date,
                'total' => (int) $row->total,
            ];
        }

        return response()->json([
            'hours' => $hours,
            'portal_name' => $localName,
            'report' => $report,
        ]);
    }
}
