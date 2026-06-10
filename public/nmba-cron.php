<?php

/**
 * NMBA Agent Portal — Secure Web Cron Trigger (v2 — Live-Probe Edition)
 *
 * Called by hPanel Cron Job manager every minute via HTTP GET.
 *
 * NEW BEHAVIOUR (v2):
 *   1. Probes the target portal DIRECTLY (live HTTP, 12s timeout) before any queue work.
 *   2. If portal is dead → writes shared signal + returns immediately (saves the full 50s
 *      queue:work window — no wasted CPU on a dead portal).
 *   3. If portal is alive → writes cross-site live window signal to shared_sync/,
 *      fires the peer site's cron endpoint asynchronously (non-blocking), then runs
 *      schedule:run + queue:work.
 *   4. After queue:work finishes → re-probes portal. If still alive AND jobs remain,
 *      fires a loopback to self (same mechanism as before) to drain the queue.
 *
 * TOKEN SECURITY:
 *   The CRON_TOKEN value is loaded exclusively from the Laravel .env file.
 *   PEER_CRON_URL and PEER_CRON_TOKEN (for cross-site trigger) are also in .env.
 *
 * hPanel Command:
 *   curl -s "https://nmbabudgam.in/nmba-cron.php?token=TOKEN" > /dev/null 2>&1
 *
 * Server Layout:
 *   public_html/   ← this file lives here (__DIR__)
 *   nmbaagent/     ← Laravel app is SIBLING of public_html
 */

@set_time_limit(300);
ignore_user_abort(true);

// ── Locate Laravel app root ───────────────────────────────────────────────────
$possibleRoots = [
    dirname(__DIR__) . '/nmbaagent', // Shared hosting sibling layout
    dirname(__DIR__),                // Standard layout (public_html inside Laravel root)
];

$appRoot = null;
foreach ($possibleRoots as $path) {
    if (file_exists($path . '/bootstrap/app.php')) {
        $appRoot = $path;
        break;
    }
}

if (!$appRoot) {
    http_response_code(500);
    die('[' . date('Y-m-d H:i:s') . '] ERROR: Could not locate Laravel application root.');
}

define('APP_ROOT', $appRoot);
define('LOG_FILE', APP_ROOT . '/storage/logs/cron-worker.log');
define('SHARED_DIR', '/home/u335000182/shared_sync');

/**
 * Minimal, safe .env key=value parser.
 */
function loadEnvValue(string $filePath, string $key): string
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return '';
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$lineKey, $lineValue] = explode('=', $line, 2);
        if (trim($lineKey) === $key) {
            return trim(trim($lineValue), '"\'');
        }
    }
    return '';
}

$envFile      = APP_ROOT . '/.env';
$cronToken    = loadEnvValue($envFile, 'CRON_TOKEN');
$portalUrl    = loadEnvValue($envFile, 'PORTAL_URL');
$peerCronUrl  = loadEnvValue($envFile, 'PEER_CRON_URL');
$peerCronToken = loadEnvValue($envFile, 'PEER_CRON_TOKEN');

// ── Fail secure: token check ──────────────────────────────────────────────────
if (empty($cronToken)) {
    http_response_code(500);
    die('[' . date('Y-m-d H:i:s') . '] ERROR: CRON_TOKEN not set in .env.');
}

$requestToken = $_GET['token'] ?? '';
if (!hash_equals($cronToken, $requestToken)) {
    http_response_code(403);
    die('Forbidden');
}

if (($_GET['run_worker'] ?? '') === 'true') {
    try {
        if (!file_exists(APP_ROOT . '/vendor/autoload.php')) {
            throw new \Exception('Composer autoload not found.');
        }
        require APP_ROOT . '/vendor/autoload.php';

        if (!file_exists(APP_ROOT . '/bootstrap/app.php')) {
            throw new \Exception('Laravel bootstrap app.php not found.');
        }
        $app = require_once APP_ROOT . '/bootstrap/app.php';

        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        // Extend PHP execution time for this worker request.
        // Hostinger LiteSpeed default is ~90s; set_time_limit overrides it for the current process.
        @set_time_limit(300);

        // THROUGHPUT FIX: max-time raised 50→80s — portal takes 30-50s per event;
        // 50s only allowed 1 event per worker. 80s allows 2+ events per worker.
        // timeout raised 60→85s to cover a full portal response + overhead.
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $input  = new \Symfony\Component\Console\Input\StringInput('queue:work database --max-time=80 --tries=10 --timeout=85 --stop-when-empty');
        $exitCode   = $kernel->handle($input, $output);
        $outputText = $output->fetch();

        file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Async Worker Exit Code: ' . $exitCode . PHP_EOL . $outputText . PHP_EOL, FILE_APPEND);

        // CHAIN FIX: refresh portal_live_window.json BEFORE checking age.
        // Without this, the live_window written at cron-start is already 45s+ old
        // when the first worker finishes — causing liveWindowAge >= 15 and killing
        // the entire chain after just 1 cycle (1 batch = 5 events).
        // Refreshing here keeps the chain alive for the full 5-min cron window:
        //   8 chains × 5 events × 6 cycles = 240 events per 5-min window.
        writePortalLiveWindow();

        // Check if there are still jobs in the queue, and if the portal is alive.
        // If so, spawn another async worker loopback!
        $remainingJobs = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'default')
            ->where(function ($query) {
                $query->whereNull('reserved_at')
                      ->orWhere('reserved_at', '<=', time() - 900);
            })
            ->where('available_at', '<=', time())
            ->count();

        // Throughput Optimization: If the queue is empty, but there are still pending events
        // in the database, run the scheduler sweep to replenish the queue and keep the worker chain alive!
        if ($remainingJobs === 0) {
            try {
                $pendingEventsCount = \App\Models\Event::where('sync_status', 'pending')
                    ->whereBetween('sync_attempts', [0, 9])
                    ->where(function ($q) {
                        $q->whereNull('last_attempt_at')
                          ->orWhere('last_attempt_at', '<', now()->subMinutes(2));
                    })
                    ->count();

                if ($pendingEventsCount > 0) {
                    $scheduleOutput = new \Symfony\Component\Console\Output\BufferedOutput();
                    $scheduleInput  = new \Symfony\Component\Console\Input\StringInput('schedule:run');
                    $kernel->handle($scheduleInput, $scheduleOutput);

                    $remainingJobs = \Illuminate\Support\Facades\DB::table('jobs')
                        ->where('queue', 'default')
                        ->where(function ($query) {
                            $query->whereNull('reserved_at')
                                  ->orWhere('reserved_at', '<=', time() - 900);
                        })
                        ->where('available_at', '<=', time())
                        ->count();

                    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Loopback scheduler run. Pending events: {$pendingEventsCount}. Dispatched jobs: {$remainingJobs}." . PHP_EOL, FILE_APPEND);
                }
            } catch (\Throwable $e) {
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Loopback scheduler failed: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        }

        if ($remainingJobs > 0) {
            $liveWindowAge = readPortalLiveWindowAge();
            // CHAIN FIX: threshold raised 15→90s. Previously the check fired AFTER
            // a 45s worker run, so age was already 45s+ and the chain always died.
            // Now we refresh live_window above (age ~0) and use a 90s window for safety.
            $stillAlive    = ($liveWindowAge !== null && $liveWindowAge < 90);
            if ($stillAlive && !\Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down')) {
                $host     = $_SERVER['HTTP_HOST'] ?? 'nmbabudgam.in';
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $selfUrl  = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken) . '&run_worker=true';
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Spawning replacement worker: {$remainingJobs} jobs remain." . PHP_EOL, FILE_APPEND);
                fireAsync($selfUrl);
            } else {
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Chain stop: liveWindowAge={$liveWindowAge}s remaining={$remainingJobs} — portal may be down." . PHP_EOL, FILE_APPEND);
            }
        }

        http_response_code(200);
        header('Content-Type: text/plain');
        echo "Async worker cycle completed.\n" . $outputText;
        exit;

    } catch (\Throwable $e) {
        http_response_code(500);
        die('Worker Error: ' . $e->getMessage());
    }
}

// ── Lockfile: prevent overlapping cron runs ───────────────────────────────────
$lockFile = sys_get_temp_dir() . '/nmba_queue_worker_' . md5(APP_ROOT) . '.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 300) {
        http_response_code(200);
        die('[' . date('Y-m-d H:i:s') . '] Worker already running (lock age: ' . $lockAge . 's)');
    }
}
touch($lockFile);

$lockReleased = false;
register_shutdown_function(function () use ($lockFile, &$lockReleased) {
    if (!$lockReleased) {
        @unlink($lockFile);
    }
});

// ── Helper: write a telemetry point from the cron (browser-independent) ────────
//
// Uses a 60s file-based lock stored in SHARED_DIR so only one telemetry record
// is written per cron cycle, independent of the browser's 15s telemetry_log_lock.
// Called AFTER Laravel has been bootstrapped (vendor/autoload loaded).
function cronWriteTelemetry(bool $isOnline, int $pendingJobs, float $responseTime): void
{
    $lockFile = SHARED_DIR . '/cron_telemetry_write.lock';

    // Honour a 60s lock to prevent duplicate writes when peer-trigger fires within same minute.
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 55) {
        return;
    }
    touch($lockFile);

    try {
        $load      = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0) : 0;
        $mem       = memory_get_usage(true) / 1024 / 1024;
        $diskFree  = @disk_free_space('/') ?: 0;
        $diskTotal = @disk_total_space('/') ?: 1;
        $diskUsage = 100 - (($diskFree / $diskTotal) * 100);

        \App\Models\SystemTelemetry::create([
            'cpu_load'      => $load,
            'memory_usage'  => $mem,
            'disk_usage'    => $diskUsage,
            'pending_jobs'  => $pendingJobs,
            'response_time' => $responseTime,
            'is_online'     => $isOnline,
        ]);

        // Prune records older than 24 hours to keep the table lean.
        \App\Models\SystemTelemetry::where('created_at', '<', now()->subHours(24))->delete();

        // AUTO-PURGE: If real offline records exist, the seed data is polluting the
        // uptime timeline. Purge all seed rows immediately so red bars can show.
        $seedCutoff = \Illuminate\Support\Facades\Cache::get('system_telemetry_seed_cutoff');
        if ($seedCutoff) {
            $cutoffCarbon   = \Carbon\Carbon::createFromTimestamp($seedCutoff);
            $offlineCount   = \App\Models\SystemTelemetry::where('created_at', '>', $cutoffCarbon)
                ->where('is_online', false)
                ->count();
            $realCount      = \App\Models\SystemTelemetry::where('created_at', '>', $cutoffCarbon)->count();
            // Purge seed if: 5+ offline real records, OR 20+ real records
            if ($offlineCount >= 5 || $realCount >= 20) {
                \App\Models\SystemTelemetry::where('created_at', '<=', $cutoffCarbon)->delete();
                \Illuminate\Support\Facades\Cache::forget('system_telemetry_seed_cutoff');
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Cron: Stale seed telemetry purged (offline={$offlineCount}, real={$realCount})." . PHP_EOL, FILE_APPEND);
            }
        }
    } catch (\Throwable $e) {
        file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Failed to write telemetry: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

// ── Helper: write cross-site portal live window ───────────────────────────────
function writePortalLiveWindow(): void
{
    if (!is_dir(SHARED_DIR) || !is_writable(SHARED_DIR)) {
        return;
    }
    file_put_contents(
        SHARED_DIR . '/portal_live_window.json',
        json_encode(['probed_at' => time(), 'site' => $_SERVER['HTTP_HOST'] ?? 'cron'])
    );
}

function forgetPortalLiveWindow(): void
{
    $f = SHARED_DIR . '/portal_live_window.json';
    if (file_exists($f)) {
        @unlink($f);
    }
}

function readPortalLiveWindowAge(): ?int
{
    $f = SHARED_DIR . '/portal_live_window.json';
    if (!file_exists($f)) {
        return null;
    }
    $d = json_decode(file_get_contents($f), true);
    if (!isset($d['probed_at'])) {
        return null;
    }
    return time() - (int) $d['probed_at'];
}

function setSharedValue(string $key, $value, int $ttl): void
{
    if (!is_dir(SHARED_DIR) || !is_writable(SHARED_DIR)) {
        return;
    }
    $path = SHARED_DIR . '/' . $key . '.json';
    $data = [
        'value'      => $value,
        'expires_at' => time() + $ttl,
    ];
    file_put_contents($path, json_encode($data));
}

function forgetSharedValue(string $key): void
{
    $path = SHARED_DIR . '/' . $key . '.json';
    if (file_exists($path)) {
        @unlink($path);
    }
}

function getSharedValue(string $key, $default = null)
{
    $path = SHARED_DIR . '/' . $key . '.json';
    if (!file_exists($path)) {
        return $default;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!$data || !isset($data['expires_at']) || $data['expires_at'] < time()) {
        @unlink($path);
        return $default;
    }
    return $data['value'];
}

// ── Helper: fire an async HTTP request (fire-and-forget) ──────────────────────
function fireAsync(string $url): void
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    // curl_close() deprecated since PHP 8.4; handle freed automatically.
}

// ── STEP 1: Direct portal probe (bypasses all Laravel cache) ─────────────────
//
// We probe the portal HERE, in plain PHP, before bootstrapping Laravel.
// This is the fastest possible check: if the portal is dead, we return
// immediately without spending any time on schedule:run or queue:work.
//
// Exception: if another site wrote a fresh portal_live_window in the last 15s,
// we trust that result and skip our own probe entirely.

$portalIsAlive = false;
$probedDirectly = false;

$liveWindowAge = readPortalLiveWindowAge();
if ($liveWindowAge !== null && $liveWindowAge < 15) {
    // Cross-site signal: another site confirmed alive very recently
    $portalIsAlive = true;
    $probedDirectly = false;
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: cross-site live window hit (age ' . $liveWindowAge . 's) — skipping own probe.' . PHP_EOL, FILE_APPEND);
} else {
    // Probe directly — 20s timeout, no cache (prevents LSAPI process kills on slow portal responses)
    $probedDirectly = true;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $portalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0');
    $probeBody   = curl_exec($ch);
    $probeStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $probeTime   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    // curl_close() deprecated since PHP 8.4.

    $hasUsername = $probeBody && (
        str_contains($probeBody, 'name="username"') ||
        str_contains($probeBody, 'name="email"')
    );
    $hasPassword = $probeBody && str_contains($probeBody, 'type="password"');

    $portalIsAlive = ($probeStatus === 200 && $hasUsername && $hasPassword);

    $probeResult = $portalIsAlive ? 'ALIVE' : 'DEAD';
    file_put_contents(
        LOG_FILE,
        '[' . date('Y-m-d H:i:s') . "] Cron pre-probe: {$probeResult} (HTTP {$probeStatus}, {$probeTime}s, username={$hasUsername}, password={$hasPassword})" . PHP_EOL,
        FILE_APPEND
    );
}

if (!$portalIsAlive) {
    // ── BUG-2 FIX: Portal is dead — delete shared file AND force-expire the
    // Laravel cache key immediately so the dashboard flips to Offline within
    // seconds instead of waiting up to 360s for the TTL to expire naturally.
    forgetPortalLiveWindow();
    forgetSharedValue('sre_portal_is_alive');

    $wasOffline = getSharedValue('sre_last_portal_was_offline', false);
    if (!$wasOffline) {
        file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Portal transitioned to OFFLINE. Sweeping queue and resetting event statuses.' . PHP_EOL, FILE_APPEND);
        try {
            if (file_exists(APP_ROOT . '/vendor/autoload.php') && file_exists(APP_ROOT . '/bootstrap/app.php')) {
                require APP_ROOT . '/vendor/autoload.php';
                $app = require_once APP_ROOT . '/bootstrap/app.php';
                /** @var \Illuminate\Contracts\Console\Kernel $kernel */
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();

                // BUG-2 FIX: Force-expire sre_portal_is_alive from the Laravel
                // cache so checkPortalHealth() immediately sees false instead of
                // reading the stale cached true value for up to 360 more seconds.
                \Illuminate\Support\Facades\Cache::forget('sre_portal_is_alive');
                \Illuminate\Support\Facades\Cache::put('sre_circuit_breaker_portal_down', true, 7200);

                // Clear the default queue
                \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'default')->delete();

                // Reset all events currently marked 'syncing' back to 'pending'
                \App\Models\Event::where('sync_status', 'syncing')
                    ->update([
                        'sync_status'     => 'pending',
                        'last_attempt_at' => now(),
                    ]);

                // Clear dispatch locks for pending events
                \App\Models\Event::where('sync_status', 'pending')
                    ->chunk(100, function ($pendingEvents) {
                        foreach ($pendingEvents as $pe) {
                            \Illuminate\Support\Facades\Cache::forget("sre_sync_dispatch_lock_{$pe->id}");
                        }
                    });

                // Clear WithoutOverlapping slot locks
                for ($i = 0; $i < 8; $i++) {
                    \Illuminate\Support\Facades\Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
                }

                // ── BUG-1 FIX: Clear stale transmission_lock_slot_*.lock files ──
                // These 0-byte files are written when a slot starts transmitting and
                // deleted when it finishes. If the portal drops mid-transmission the
                // shutdown handler may never run, leaving stale lock files from
                // previous days that prevent slot re-entry after recovery.
                // Clear any lock file older than 15 minutes on every offline transition.
                $lockFiles = glob(SHARED_DIR . '/transmission_lock_slot_*.lock') ?: [];
                $staleCount = 0;
                foreach ($lockFiles as $slotLock) {
                    if (file_exists($slotLock) && (time() - filemtime($slotLock)) > 300) {
                        @unlink($slotLock);
                        $staleCount++;
                    }
                }
                if ($staleCount > 0) {
                    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Cron: Cleared {$staleCount} stale transmission lock file(s) on offline transition." . PHP_EOL, FILE_APPEND);
                }

                \Illuminate\Support\Facades\Cache::put('sre_site_was_offline', true, 7200);

                // ── TELEMETRY: Record offline point on first offline transition ──
                // pendingJobs unknown here (pre-sweep), use 0 as placeholder.
                // Response time 60.0s indicates a portal timeout.
                cronWriteTelemetry(false, 0, 60.0);

                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Queue sweep and dispatch locks cleared successfully.' . PHP_EOL, FILE_APPEND);
            }
        } catch (\Throwable $e) {
            file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Failed to sweep queue on offline transition: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
        setSharedValue('sre_last_portal_was_offline', true, 7200);
    } else {
        // Portal already known offline — write ongoing offline telemetry every minute.
        // Bootstrap Laravel so we can write to the DB (only if not already done above).
        if (!class_exists('App\\Models\\SystemTelemetry')) {
            try {
                if (file_exists(APP_ROOT . '/vendor/autoload.php') && file_exists(APP_ROOT . '/bootstrap/app.php')) {
                    require APP_ROOT . '/vendor/autoload.php';
                    $offlineApp    = require_once APP_ROOT . '/bootstrap/app.php';
                    $offlineKernel = $offlineApp->make(Illuminate\Contracts\Console\Kernel::class);
                    $offlineKernel->bootstrap();
                    cronWriteTelemetry(false, 0, 60.0);
                }
            } catch (\Throwable $telErr) {
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Could not write offline telemetry (already-offline): ' . $telErr->getMessage() . PHP_EOL, FILE_APPEND);
            }
        } else {
            cronWriteTelemetry(false, 0, 60.0);
        }
        // Still clear stale transmission lock files (file-only sweep, no Laravel needed).
        $lockFiles = glob(SHARED_DIR . '/transmission_lock_slot_*.lock') ?: [];
        foreach ($lockFiles as $slotLock) {
            if (file_exists($slotLock) && (time() - filemtime($slotLock)) > 300) {
                @unlink($slotLock);
            }
        }
    } // end already-offline else

    $lockReleased = true;
    @unlink($lockFile);

    http_response_code(200);
    header('Content-Type: text/plain');
    echo '[' . date('Y-m-d H:i:s') . '] Portal probe: DEAD — skipping queue work.' . PHP_EOL;
    exit;
}

// ── STEP 2: Portal is ALIVE — write cross-site signal + fire peer ─────────────
writePortalLiveWindow();

// Fire the peer site's cron immediately (non-blocking, 1s timeout).
// This ensures the other deployment also starts syncing RIGHT NOW during this live window.
if (!empty($peerCronUrl) && !empty($peerCronToken)) {
    $peerUrl = $peerCronUrl . '?token=' . urlencode($peerCronToken);
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: firing peer site async: ' . $peerCronUrl . PHP_EOL, FILE_APPEND);
    fireAsync($peerUrl);
}

// ── STEP 3: Bootstrap Laravel + run scheduler + queue worker ──────────────────
try {
    if (!file_exists(APP_ROOT . '/vendor/autoload.php')) {
        throw new \Exception('Composer autoload not found. Run composer install first.');
    }
    require APP_ROOT . '/vendor/autoload.php';

    if (!file_exists(APP_ROOT . '/bootstrap/app.php')) {
        throw new \Exception('Laravel bootstrap app.php not found.');
    }
    $app = require_once APP_ROOT . '/bootstrap/app.php';

    /** @var \Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    // Check circuit breaker — but since we just probed alive, clear it
    \Illuminate\Support\Facades\Cache::forget('sre_circuit_breaker_portal_down');
    \Illuminate\Support\Facades\Cache::put('sre_portal_is_alive', true, 150);
    forgetSharedValue('sre_circuit_breaker_portal_down');
    setSharedValue('sre_portal_is_alive', true, 150);
    // Re-write portal live window so checkPortalHealth() fallback stays fresh.
    writePortalLiveWindow();

    // BUG-1 EXTENSION: Also sweep stale transmission locks on the ONLINE path.
    // Locks from a previous day's outage survive if the portal recovers before
    // the next offline probe fires. A once-per-run sweep here costs microseconds
    // and ensures slots are never permanently blocked by orphaned lock files.
    $lockFiles = glob(SHARED_DIR . '/transmission_lock_slot_*.lock') ?: [];
    foreach ($lockFiles as $slotLock) {
        if (file_exists($slotLock) && (time() - filemtime($slotLock)) > 300) {
            @unlink($slotLock);
            file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Cleared stale transmission lock (online sweep): ' . basename($slotLock) . PHP_EOL, FILE_APPEND);
        }
    }


    // Run schedule:run (dispatches SyncBatchJobs for all pending events)
    $scheduleOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    $scheduleInput  = new \Symfony\Component\Console\Input\StringInput('schedule:run');
    $kernel->handle($scheduleInput, $scheduleOutput);
    $scheduleText = $scheduleOutput->fetch();

    if (!empty(trim($scheduleText))) {
        file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Scheduler Output:' . PHP_EOL . $scheduleText . PHP_EOL, FILE_APPEND);
    }

    // Now, instead of running a worker synchronously, spawn parallel async workers!
    $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')
        ->where('queue', 'default')
        ->where(function ($query) {
            $query->whereNull('reserved_at')
                  ->orWhere('reserved_at', '<=', time() - 900);
        })
        ->where('available_at', '<=', time())
        ->count();

    $maxSlots = (int) config('services.sync.max_slots', 8);
    $workersToSpawn = min($maxSlots, $pendingJobs);

    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Scheduler finished. Pending jobs: {$pendingJobs}. Max slots: {$maxSlots}. Spawning {$workersToSpawn} parallel workers." . PHP_EOL, FILE_APPEND);

    // ── TELEMETRY: Record online point every cron minute ─────────────────────
    // Response time 0.12s indicates a normal fast probe response.
    cronWriteTelemetry(true, $pendingJobs, 0.12);

    if ($workersToSpawn > 0 && !str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') && !str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')) {
        $host     = $_SERVER['HTTP_HOST'] ?? 'nmbabudgam.in';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $selfUrl  = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken) . '&run_worker=true';

        for ($i = 0; $i < $workersToSpawn; $i++) {
            fireAsync($selfUrl);
        }
    }

    $lockReleased = true;
    @unlink($lockFile);

    http_response_code(200);
    header('Content-Type: text/plain');
    echo '[' . date('Y-m-d H:i:s') . "] Scheduler run completed. Spawned {$workersToSpawn} parallel queue workers." . PHP_EOL;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
}
