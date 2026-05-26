<?php

/**
 * NMBA Agent Portal — Secure Web Cron Trigger
 *
 * This endpoint is called by the Hostinger hPanel Cron Job manager
 * every 5 minutes via an HTTP GET request. It processes up to 10
 * pending queue jobs per invocation using the database driver in-memory.
 *
 * TOKEN SECURITY:
 *   The CRON_TOKEN value is loaded exclusively from the Laravel .env
 *   file — it is never stored in source code. To set up:
 *     1. Generate a new token: openssl rand -hex 32
 *     2. Add CRON_TOKEN=<generated_value> to your .env file
 *     3. Update the hPanel cron URL to use the new token value
 *   See README.md > "Deployment Secrets" for full instructions.
 *
 * hPanel Command (template — replace TOKEN with actual value from .env):
 *   curl -s "https://nmbabudgam.in/nmba-cron.php?token=TOKEN" > /dev/null 2>&1
 *
 * THROUGHPUT:
 *   --max-jobs=10 processes up to 10 jobs per 5-minute cycle (~120/hr burst).
 *   A second hPanel cron entry offset by 2 minutes doubles burst capacity to ~240/hr.
 *   See DEPLOYMENT.md for full throughput math and second-cron setup instructions.
 *
 * Server Layout:
 *   public_html/       <-- this file lives here (__DIR__)
 *   nmbaagent/         <-- Laravel app is SIBLING of public_html, NOT child
 */

// Increase maximum execution time to 240 seconds to prevent early timeout
@set_time_limit(240);

// Prevent the webserver from killing the script when the loopback curl disconnects
ignore_user_abort(true);

// ── Load CRON_TOKEN securely from the Laravel .env file ─────────
$possibleRoots = [
    dirname(__DIR__) . '/nmbaagent', // Shared hosting sibling
    dirname(__DIR__),                // Local/standard (public_html is inside Laravel root)
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

/**
 * Minimal, safe .env key=value parser.
 * Reads only what it needs — does NOT eval or include the file.
 */
function loadEnvValue(string $filePath, string $key): string
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return '';
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and lines that don't start with KEY=
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$lineKey, $lineValue] = explode('=', $line, 2);
        if (trim($lineKey) === $key) {
            // Strip surrounding quotes if present
            return trim(trim($lineValue), '"\'');
        }
    }
    return '';
}

$envFile   = APP_ROOT . '/.env';
$cronToken = loadEnvValue($envFile, 'CRON_TOKEN');

// ── Fail secure: reject if token is not configured in .env ───────
if (empty($cronToken)) {
    http_response_code(500);
    die('[' . date('Y-m-d H:i:s') . '] ERROR: CRON_TOKEN is not set in .env. Cron execution blocked.');
}

// ── Security: reject requests without the correct token ─────────
$requestToken = $_GET['token'] ?? '';
if (!hash_equals($cronToken, $requestToken)) {
    http_response_code(403);
    die('Forbidden');
}

// ── Guard: prevent overlapping cron runs using a lockfile ────────
$lockFile = sys_get_temp_dir() . '/nmba_queue_worker.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 300) { // Lock expires after 5 minutes
        http_response_code(200);
        die('[' . date('Y-m-d H:i:s') . '] Worker already running (lock age: ' . $lockAge . 's)');
    }
}
touch($lockFile);

$lockReleased = false;
register_shutdown_function(function() use ($lockFile, &$lockReleased) {
    if (!$lockReleased) {
        @unlink($lockFile);
    }
});

try {
    // ── Bootstrap Laravel Internally ─────────────────────────────────
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

    // Check if circuit breaker is active before running queue worker
    if (\Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down') === true) {
        // @unlink($lockFile); // Handled by shutdown function
        http_response_code(200);
        header('Content-Type: text/plain');
        die('[' . date('Y-m-d H:i:s') . '] Circuit breaker active. Queue worker execution deferred.' . PHP_EOL);
    }

    // First, run Laravel schedule:run internally to process scheduled tasks and dispatch batch jobs
    $scheduleOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    $scheduleInput = new \Symfony\Component\Console\Input\StringInput('schedule:run');
    $kernel->handle($scheduleInput, $scheduleOutput);
    $scheduleText = $scheduleOutput->fetch();

    if (!empty(trim($scheduleText))) {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] Scheduler Output:' . PHP_EOL . $scheduleText . PHP_EOL;
        file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    }

    // Capture output of the Artisan command run
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    // Process as many jobs as possible for 50 seconds, then exit cleanly to allow loopback
    $input = new \Symfony\Component\Console\Input\StringInput('queue:work database --max-time=50 --tries=10 --timeout=110 --stop-when-empty');

    // Run the queue worker command internally in the current PHP process SAPI context
    $exitCode = $kernel->handle($input, $output);
    $outputText = $output->fetch();

    // Log the output to cron-worker.log
    $logEntry = '[' . date('Y-m-d H:i:s') . '] Exit Code: ' . $exitCode . PHP_EOL . $outputText . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);

    // Release lock before checking for remaining jobs and loopbacking
    $lockReleased = true;
    @unlink($lockFile);

    // Check if there are still ready jobs in the queue
    try {
        $remainingJobs = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', time())
            ->count();

        $host = $_SERVER['HTTP_HOST'] ?? 'nmbabudgam.in';
        $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

        if ($remainingJobs > 0 && !$isLocalhost && \Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down') !== true) {
            // Trigger next batch asynchronously via loopback call
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $selfUrl = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken);

            $logEntry = '[' . date('Y-m-d H:i:s') . "] Spawning next batch async loopback: {$remainingJobs} jobs remaining.\n";
            file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);

            // Trigger curl asynchronously with 1-second timeout
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $selfUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (\Throwable $dbEx) {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] Loopback trigger failed: ' . $dbEx->getMessage() . PHP_EOL;
        file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    }

    // Respond with confirmation
    http_response_code(200);
    header('Content-Type: text/plain');
    echo '[' . date('Y-m-d H:i:s') . '] Queue worker cycle completed (max-jobs=10).' . PHP_EOL;
    echo $outputText;

} catch (\Throwable $e) {
    // @unlink($lockFile); // Handled by shutdown function
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERROR bootstrapping or executing queue: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
}
