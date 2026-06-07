<?php

namespace App\Console\Commands;

use App\Models\SystemTelemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * TelemetryResetCommand
 *
 * Wipes all rows in the system_telemetry table and clears the seed-cutoff
 * cache key so that getTelemetryHistory() can re-seed cleanly (all-online
 * placeholder) on next load — or simply accumulate fresh live probe data.
 *
 * Usage:
 *   php artisan telemetry:reset
 *
 * When to run:
 *   After deploying the seed-fix that changed telemetrySeedPoint() from
 *   fake-outage mode to all-online. Existing production rows with
 *   is_online=false from the old fake seed are deleted by this command.
 *   The uptime chart will rebuild from real probe data within minutes.
 */
class TelemetryResetCommand extends Command
{
    protected $signature = 'telemetry:reset
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Clear all system_telemetry rows (removes stale seed data with fake offline periods). Fresh data rebuilds automatically from live probes.';

    public function handle(): int
    {
        $count = SystemTelemetry::count();

        if ($count === 0) {
            $this->info('system_telemetry table is already empty — nothing to do.');
            return self::SUCCESS;
        }

        $this->warn("This will delete {$count} telemetry record(s).");
        $this->line('The uptime chart will rebuild automatically from live probe data within ~5 minutes.');

        if (!$this->option('force') && !$this->confirm('Proceed?', true)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        SystemTelemetry::query()->delete();

        // Clear the seed cutoff key so the auto-purge logic doesn't run
        // on stale references to rows that no longer exist.
        Cache::forget('system_telemetry_seed_cutoff');

        $this->info("✔ Deleted {$count} telemetry records.");
        $this->line('The dashboard will re-seed with all-online placeholder data on next load,');
        $this->line('then replace it with real probe data as the cron accumulates records.');

        return self::SUCCESS;
    }
}
