<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SyncReportCommand
 *
 * Provides a date-wise breakdown of successfully synced events in the last N hours.
 * Connects to both the local and peer databases to show a comparative view.
 *
 * Usage:
 *   php artisan sync:report {hours=12}
 */
class SyncReportCommand extends Command
{
    protected $signature = 'sync:report
                            {hours=12 : The number of hours to report performance for}';

    protected $description = 'Get date-wise list of events synced in the last N hours for both portals';

    public function handle(): int
    {
        $hours = (int) $this->argument('hours');
        if ($hours <= 0) {
            $this->error('The hours argument must be a positive integer.');
            return self::FAILURE;
        }

        $localDb = config('database.connections.mysql.database');
        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $password = config('database.connections.mysql.password');

        $peerDb = null;
        if ($localDb === 'u335000182_nmbabudgam') {
            $peerDb = 'u335000182_database';
        } elseif ($localDb === 'u335000182_database') {
            $peerDb = 'u335000182_nmbabudgam';
        }

        $localName = ($localDb === 'u335000182_nmbabudgam') ? 'nmbabudgam.in' : 'ctetmonktest.fun';
        $peerName = ($peerDb === 'u335000182_database') ? 'ctetmonktest.fun' : 'nmbabudgam.in';

        // 1. Fetch Local Portal Data
        $this->info("Fetching performance metrics for local portal ($localName)...");
        try {
            $localResults = DB::select("
                SELECT event_date, COUNT(*) AS total
                FROM events
                WHERE sync_status = 'synced' AND synced_at >= NOW() - INTERVAL :hours HOUR
                GROUP BY event_date
                ORDER BY event_date
            ", ['hours' => $hours]);
        } catch (\Exception $e) {
            $this->error("Failed to query local database: " . $e->getMessage());
            return self::FAILURE;
        }

        // 2. Fetch Peer Portal Data
        $peerResults = [];
        $peerConnected = false;

        if ($peerDb) {
            $this->info("Connecting to peer portal database ($peerName)...");
            try {
                $peerPdo = new \PDO("mysql:host={$host};port={$port};dbname={$peerDb};charset=utf8mb4", $peerDb, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);

                $stmt = $peerPdo->prepare("
                    SELECT event_date, COUNT(*) AS total
                    FROM events
                    WHERE sync_status = 'synced' AND synced_at >= NOW() - INTERVAL :hours HOUR
                    GROUP BY event_date
                    ORDER BY event_date
                ");
                $stmt->execute(['hours' => $hours]);
                $peerResults = $stmt->fetchAll();
                $peerConnected = true;
            } catch (\Exception $e) {
                $this->warn("Unable to connect/query peer database: " . $e->getMessage());
            }
        } else {
            $this->warn("No known peer database mapping for local database '$localDb'. Peer columns will be blank.");
        }

        // 3. Consolidate Results by event_date
        $report = [];
        foreach ($localResults as $row) {
            $date = $row->event_date;
            $report[$date] = [
                'date' => $date,
                'local' => (int) $row->total,
                'peer' => 0,
                'total' => (int) $row->total,
            ];
        }

        foreach ($peerResults as $row) {
            $date = $row['event_date'];
            if (!isset($report[$date])) {
                $report[$date] = [
                    'date' => $date,
                    'local' => 0,
                    'peer' => (int) $row['total'],
                    'total' => (int) $row['total'],
                ];
            } else {
                $report[$date]['peer'] = (int) $row['total'];
                $report[$date]['total'] += (int) $row['total'];
            }
        }

        ksort($report);

        // 4. Output Comparison Table
        $this->newLine();
        $this->line("==========================================================");
        $this->line("       SYNC PERFORMANCE REPORT (LAST {$hours} HOURS)       ");
        $this->line("==========================================================");

        $headers = [
            'Event Date',
            "Local ($localName)",
            "Peer (" . ($peerConnected ? $peerName : 'Unreachable') . ")",
            'Total Syncs'
        ];

        $rows = [];
        $totalLocal = 0;
        $totalPeer = 0;
        $totalCombined = 0;

        foreach ($report as $r) {
            $rows[] = [
                $r['date'],
                number_format($r['local']),
                number_format($r['peer']),
                number_format($r['total']),
            ];
            $totalLocal += $r['local'];
            $totalPeer += $r['peer'];
            $totalCombined += $r['total'];
        }

        if (empty($rows)) {
            $this->warn("No events synced on either portal in the last {$hours} hours.");
            return self::SUCCESS;
        }

        // Add separator and summary row
        $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
        $rows[] = [
            'TOTAL',
            number_format($totalLocal),
            number_format($totalPeer),
            number_format($totalCombined),
        ];

        $this->table($headers, $rows);
        $this->newLine();

        return self::SUCCESS;
    }
}
