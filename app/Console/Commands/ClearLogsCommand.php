<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ClearLogsCommand
 *
 * Dynamically truncates all files ending in .log under the storage/logs/
 * directory to 0 bytes. This prevents disk space exhaustion due to large
 * scheduler or worker log files.
 *
 * Usage:
 *   php artisan logs:clear
 */
class ClearLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Truncate all log files in storage/logs directory to reclaim disk space';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logPath = storage_path('logs');
        if (!File::isDirectory($logPath)) {
            $this->error("Logs directory does not exist at {$logPath}");
            return self::FAILURE;
        }

        // Get all files in storage/logs
        $files = File::files($logPath);
        $clearedCount = 0;
        $totalBytesFreed = 0;

        foreach ($files as $file) {
            $fileName = $file->getFilename();
            // Skip hidden files (like .gitignore)
            if (str_starts_with($fileName, '.')) {
                continue;
            }

            // Target files ending in .log
            if ($file->getExtension() === 'log') {
                $filePath = $file->getRealPath();
                $originalSize = $file->getSize();

                if ($originalSize > 0) {
                    // Open in read/write mode and truncate to 0
                    $handle = fopen($filePath, 'r+');
                    if ($handle !== false) {
                        ftruncate($handle, 0);
                        fclose($handle);
                        $clearedCount++;
                        $totalBytesFreed += $originalSize;
                        $this->info("Truncated {$fileName} (freed " . number_format($originalSize) . " bytes)");
                    } else {
                        $this->error("Failed to open {$fileName} for truncating.");
                    }
                }
            }
        }

        $formattedBytes = number_format($totalBytesFreed / (1024 * 1024), 2);
        $this->info("Successfully cleared {$clearedCount} log files. Freed {$formattedBytes} MB.");

        return self::SUCCESS;
    }
}
