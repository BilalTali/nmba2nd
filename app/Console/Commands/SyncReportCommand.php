<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SyncReportCommand
 *
 * Provides a date-wise breakdown of successfully synced events in the last N hours for the local portal only.
 *
 * Usage:
 *   php artisan sync:report {hours=12}
 */
class SyncReportCommand extends Command
{
    protected $signature = 'sync:report
                            {hours=12 : The number of hours to report performance for}';

    protected $description = 'Get date-wise list of events synced locally in the last N hours';

    public function handle(): int
    {
        $hours = (int) $this->argument('hours');
        if ($hours <= 0) {
            $this->error('The hours argument must be a positive integer.');
            return self::FAILURE;
        }

        $localDb = config('database.connections.mysql.database');
        $localName = ($localDb === 'u335000182_nmbabudgam') ? 'nmbabudgam.in' : 'ctetmonktest.fun';

        $cutoff = now()->subHours($hours)->toDateTimeString();
        $this->info("Fetching performance metrics for local portal ($localName)...");
        try {
            $results = DB::select("
                SELECT event_date, COUNT(*) AS total
                FROM events
                WHERE sync_status = 'synced' AND synced_at >= :cutoff
                GROUP BY event_date
                ORDER BY event_date
            ", ['cutoff' => $cutoff]);
        } catch (\Exception $e) {
            $this->error("Failed to query database: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("==========================================================");
        $this->line("       LOCAL SYNC PERFORMANCE REPORT (LAST {$hours} HOURS) ");
        $this->line("==========================================================");

        $headers = [
            'Event Date',
            'Synced Events'
        ];

        $rows = [];
        $grandTotal = 0;

        foreach ($results as $row) {
            $rows[] = [
                $row->event_date,
                number_format($row->total),
            ];
            $grandTotal += $row->total;
        }

        if (empty($rows)) {
            $this->warn("No events synced on this portal in the last {$hours} hours.");
            return self::SUCCESS;
        }

        $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
        $rows[] = [
            'TOTAL',
            number_format($grandTotal),
        ];

        $this->table($headers, $rows);
        $this->newLine();

        return self::SUCCESS;
    }
}
