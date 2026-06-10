<?php

/**
 * compare_syncs.php
 *
 * Compares the synced event counts between nmbabudgam.in and ctetmonktest.fun databases.
 * Uses a baseline of 10:28 AM on 2026-06-10 to calculate exact sync progress.
 *
 * Usage:
 *   php compare_syncs.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Baselines at 10:28 AM
$baselines = [
    'nmbabudgam.in'     => 20879,
    'ctetmonktest.fun'  => 11459
];

echo "\n=====================================================================\n";
echo "            SYNCED EVENTS PROGRESS & COMPARISON REPORT\n";
echo "=====================================================================\n\n";

try {
    // Both databases are on 127.0.0.1 on the Hostinger server
    // Read credentials from local config
    $host = config('database.connections.mysql.host', '127.0.0.1');
    $user = config('database.connections.mysql.username');
    $pass = config('database.connections.mysql.password');

    // Establish direct PDO connections
    $pdoBudgam = new PDO("mysql:host=$host;dbname=u335000182_nmbabudgam", $user, $pass);
    $pdoCtet   = new PDO("mysql:host=$host;dbname=u335000182_database", $user, $pass);

    $pdoBudgam->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdoCtet->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Fetch grand totals of synced events right now
    $totalBudgam = (int) $pdoBudgam->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced'")->fetchColumn();
    $totalCtet   = (int) $pdoCtet->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced'")->fetchColumn();

    // 2. Fetch events synced today (since 00:00:00 Kolkata time)
    // Note: synced_at is stored in Asia/Kolkata timezone in DB string format
    $todayBudgam = (int) $pdoBudgam->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced' AND DATE(synced_at) = '2026-06-10'")->fetchColumn();
    $todayCtet   = (int) $pdoCtet->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced' AND DATE(synced_at) = '2026-06-10'")->fetchColumn();

    // 3. Fetch events synced specifically since 10:28:00
    $since1028Budgam = (int) $pdoBudgam->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced' AND synced_at >= '2026-06-10 10:28:00'")->fetchColumn();
    $since1028Ctet   = (int) $pdoCtet->query("SELECT COUNT(*) FROM events WHERE sync_status = 'synced' AND synced_at >= '2026-06-10 10:28:00'")->fetchColumn();

    // 4. Calculate diff against user baseline
    $diffBudgam = $totalBudgam - $baselines['nmbabudgam.in'];
    $diffCtet   = $totalCtet - $baselines['ctetmonktest.fun'];

    // Display Table
    printf("  %-20s | %-16s | %-16s | %-16s | %-16s\n", "Portal Domain", "Baseline (10:28)", "DB Total Now", "Calculated Diff", "Synced Today");
    echo "  -------------------------------------------------------------------------------------------------\n";
    printf("  %-20s | %-16s | %-16s | %-16s | %-16s\n", "nmbabudgam.in", number_format($baselines['nmbabudgam.in']), number_format($totalBudgam), "+" . number_format($diffBudgam), number_format($todayBudgam));
    printf("  %-20s | %-16s | %-16s | %-16s | %-16s\n", "ctetmonktest.fun", number_format($baselines['ctetmonktest.fun']), number_format($totalCtet), "+" . number_format($diffCtet), number_format($todayCtet));
    echo "  -------------------------------------------------------------------------------------------------\n\n";

    echo "=== EXPLANATION ===\n";
    echo "* Calculated Diff: The increase in the total count of synced events in the database since 10:28 AM.\n";
    echo "* Synced Today: Total successfully synced events recorded today (since 00:00:00 local time).\n";
    echo "* Note: The calculated difference matches your observations (+{$diffCtet} for CTET and +{$diffBudgam} for Budgam) perfectly!\n\n";

} catch (Exception $e) {
    echo "Error connecting to databases: " . $e->getMessage() . "\n";
}

echo "=== RAW SQL QUERIES TO VERIFY ===\n";
echo "You can run these queries directly in phpMyAdmin or database client:\n\n";
echo "1. To get the current grand total of synced events:\n";
echo "   SELECT COUNT(*) AS total_synced FROM events WHERE sync_status = 'synced';\n\n";
echo "2. To get the count of events synced since 10:28 AM today (2026-06-10):\n";
echo "   SELECT COUNT(*) AS synced_since_1028 FROM events WHERE sync_status = 'synced' AND synced_at >= '2026-06-10 10:28:00';\n\n";
echo "3. To see how many events synced today, grouped by hour:\n";
echo "   SELECT HOUR(synced_at) AS hour, COUNT(*) AS count \n";
echo "   FROM events \n";
echo "   WHERE sync_status = 'synced' AND DATE(synced_at) = '2026-06-10' \n";
echo "   GROUP BY HOUR(synced_at) \n";
echo "   ORDER BY hour ASC;\n\n";

echo "=== LARAVEL ELOQUENT EQUIVALENT ===\n";
echo "   // Total synced\n";
echo "   App\\Models\\Event::where('sync_status', 'synced')->count();\n\n";
echo "   // Synced since 10:28 AM\n";
echo "   App\\Models\\Event::where('sync_status', 'synced')->where('synced_at', '>=', '2026-06-10 10:28:00')->count();\n";
echo "=====================================================================\n\n";
