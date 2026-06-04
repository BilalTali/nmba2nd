<?php

/**
 * NMBA Sync — Full Worker Bootstrap
 * ─────────────────────────────────
 * Run once with:  php run_jobs.php
 *
 * This script will:
 *   1. Stop any existing queue workers / scheduler
 *   2. Reset circuit breaker & portal offline flags
 *   3. Start 8 parallel queue:work processes in the background
 *   4. Start schedule:work in the background
 *   5. Dispatch all currently pending events into the queue
 */

require __DIR__ . '/vendor/autoload.php';
$app    = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Event;
use App\Jobs\SyncEventJob;

// ── CONFIG ────────────────────────────────────────────────────────
define('NUM_WORKERS', 8);
define('PHP_BIN',    PHP_BINARY);
define('ARTISAN',    __DIR__ . '/artisan');
define('LOG_DIR',    __DIR__ . '/storage/logs');

// ── HELPERS ───────────────────────────────────────────────────────
function line(string $msg = ''): void  { echo $msg . PHP_EOL; }
function ok(string $msg): void         { echo "  \e[1;32m✓ {$msg}\e[0m" . PHP_EOL; }
function info(string $msg): void       { echo "\e[1;34m▶ {$msg}\e[0m" . PHP_EOL; }
function warn(string $msg): void       { echo "  \e[1;33m⚠ {$msg}\e[0m" . PHP_EOL; }
function banner(string $msg): void {
    $pad = str_repeat('═', 52);
    line("\e[1;32m╔{$pad}╗\e[0m");
    line("\e[1;32m║  {$msg}" . str_repeat(' ', 50 - strlen($msg)) . "║\e[0m");
    line("\e[1;32m╚{$pad}╝\e[0m");
}

// ── BANNER ────────────────────────────────────────────────────────
line();
banner('NMBA Sync — Full Worker Bootstrap');
line();

// ── STEP 1: Stop any existing workers & scheduler ─────────────────
info('Step 1/5 — Stopping any existing queue workers & scheduler...');
exec("pkill -f 'artisan queue:work' 2>/dev/null");
exec("pkill -f 'artisan schedule:work' 2>/dev/null");
sleep(1); // Allow OS to reap processes
ok('Existing workers stopped.');
line();

// ── STEP 2: Reset circuit breaker & portal offline flags ──────────
info('Step 2/5 — Resetting circuit breaker & portal flags...');
Cache::forget('sre_circuit_breaker_portal_down');
Cache::forget('sre_last_portal_was_offline');
Cache::forget('auto_sync_paused');
Cache::forget('sre_consecutive_auth_failures');
Cache::forget('portal_credentials_invalid');
ok('Circuit breaker reset — syncing unblocked.');
line();

// ── STEP 3: Start 8 queue workers in background ───────────────────
info('Step 3/5 — Starting ' . NUM_WORKERS . ' queue workers in background...');
for ($i = 0; $i < NUM_WORKERS; $i++) {
    $logFile = LOG_DIR . "/queue-worker-{$i}.log";
    $cmd = sprintf(
        '%s %s queue:work --queue=default --tries=3 --timeout=120 --sleep=3 >> %s 2>&1 &',
        PHP_BIN, ARTISAN, $logFile
    );
    exec($cmd);
    ok("Worker {$i} started  →  storage/logs/queue-worker-{$i}.log");
}
line();

// ── STEP 4: Start scheduler in background ─────────────────────────
info('Step 4/5 — Starting scheduler (schedule:work) in background...');
$schedulerLog = LOG_DIR . '/scheduler.log';
$cmd = sprintf('%s %s schedule:work >> %s 2>&1 &', PHP_BIN, ARTISAN, $schedulerLog);
exec($cmd);
ok('Scheduler started  →  storage/logs/scheduler.log');
line();

// ── STEP 5: Clear old queue entries and reset dispatch locks ───────
info('Step 5/5 — Clearing old queue jobs and resetting dispatch locks...');
try {
    if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
        $cleared = DB::table('jobs')->where('queue', 'default')->delete();
        ok("Cleared {$cleared} old/stuck jobs from the database queue.");
    }
} catch (Exception $e) {
    warn('Could not clear jobs table: ' . $e->getMessage());
}

/** @var \Illuminate\Database\Eloquent\Collection<int, Event> $events */
$events = Event::where('sync_status', 'pending')->get();
$count  = $events->count();

if ($count === 0) {
    warn('No pending events found — queue is already empty.');
} else {
    foreach ($events as $event) {
        Cache::forget("manual_override_{$event->id}");
        Cache::forget("sre_sync_dispatch_lock_{$event->id}");
    }
    ok("Reset dispatch locks for {$count} pending events.");
}
line();

// ── SUMMARY ───────────────────────────────────────────────────────
banner('All systems running!');
line();
line('  Queue Workers  : ' . NUM_WORKERS . ' workers running in background');
line('  Scheduler      : Running (sweeps every minute via schedule:work)');
line('  Events Pending : ' . $count . ' events ready for scheduler orchestration');
line();
line('  Logs:');
line('    Workers    → storage/logs/queue-worker-[0-7].log');
line('    Scheduler  → storage/logs/scheduler.log');
line('    Sync       → storage/logs/sync.log');
line('    Health     → storage/logs/sync-health-scheduler.log');
line();
line("  \e[1;33mTip: tail -f storage/logs/sync.log   (watch live sync progress)\e[0m");
line("  \e[1;33mTip: php artisan queue:size           (check remaining queue depth)\e[0m");
line();
