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

        // Run queue:work database --max-time=15 --tries=10 --timeout=30 --stop-when-empty
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $input  = new \Symfony\Component\Console\Input\StringInput('queue:work database --max-time=15 --tries=10 --timeout=30 --stop-when-empty');
        $exitCode   = $kernel->handle($input, $output);
        $outputText = $output->fetch();

        file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Async Worker Exit Code: ' . $exitCode . PHP_EOL . $outputText . PHP_EOL, FILE_APPEND);

        // Check if there are still jobs in the queue, and if the portal is alive.
        // If so, spawn another async worker loopback!
        $remainingJobs = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', time())
            ->count();

        if ($remainingJobs > 0) {
            $liveWindowAge = readPortalLiveWindowAge();
            $stillAlive    = ($liveWindowAge !== null && $liveWindowAge < 15);
            if ($stillAlive && !\Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down')) {
                $host     = $_SERVER['HTTP_HOST'] ?? 'nmbabudgam.in';
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $selfUrl  = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken) . '&run_worker=true';
                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Spawning replacement worker: {$remainingJobs} jobs remain." . PHP_EOL, FILE_APPEND);
                fireAsync($selfUrl);
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
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
    // Portal is dead — immediately forget the live window and the local alive state
    // so that the dashboard updates to Offline status instantly without cached delay.
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

                // Clear the default queue
                \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'default')->delete();

                // Reset all events currently marked 'syncing' back to 'pending'
                \App\Models\Event::where('sync_status', 'syncing')
                    ->update([
                        'sync_status' => 'pending',
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

                \Illuminate\Support\Facades\Cache::put('sre_site_was_offline', true, 7200);

                file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Queue sweep and dispatch locks cleared successfully.' . PHP_EOL, FILE_APPEND);
            }
        } catch (\Throwable $e) {
            file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] Cron: Failed to sweep queue on offline transition: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
        setSharedValue('sre_last_portal_was_offline', true, 7200);
    }

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
    \Illuminate\Support\Facades\Cache::put('sre_portal_is_alive', true, 90);
    forgetSharedValue('sre_circuit_breaker_portal_down');
    setSharedValue('sre_portal_is_alive', true, 90);

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
        ->whereNull('reserved_at')
        ->where('available_at', '<=', time())
        ->count();

    $maxSlots = (int) config('services.sync.max_slots', 8);
    $workersToSpawn = min($maxSlots, $pendingJobs);

    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] Scheduler finished. Pending jobs: {$pendingJobs}. Max slots: {$maxSlots}. Spawning {$workersToSpawn} parallel workers." . PHP_EOL, FILE_APPEND);

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
