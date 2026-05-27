#!/bin/bash

# ==============================================================================
# NMBA Agent Portal — Immortal Background Queue Worker Launcher
# ==============================================================================
#
# Starts 8 parallel Laravel queue workers that NEVER STOP.
# Each worker runs in a tight loop — if PHP exits for any reason
# (memory limit, MySQL drop, crash), the loop restarts it instantly.
#
# Key flags:
#   --sleep=3        : poll DB every 3s when queue is empty (no busy-spin)
#   --timeout=120    : max 120s per job before SIGKILL (portal can be slow)
#   --tries=3        : retry failed jobs up to 3x before marking failed_permanently
#   --memory=512     : allow up to 512MB RAM before PHP gracefully exits (loop restarts)
#   NO --max-jobs    : workers NEVER self-terminate after N jobs
#
# Usage:
#   ./start_workers.sh           # start (kills any existing workers first)
#
# To stop all workers:
#   pkill -f "artisan queue:work"
#
# Monitor logs:
#   tail -f storage/logs/worker_loop_1.log
# ==============================================================================

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

echo "=== NMBA Agent Immortal Worker Launcher ==="
echo "Stopping any existing workers..."
pkill -f "artisan queue:work" || true
pkill -f "start_workers" || true
sleep 1

echo "Clearing any stale overlapping locks from the cache..."
php artisan tinker --execute="for (\$i=0; \$i<8; \$i++) { Cache::forget(\"laravel-queue-overlap:App\Jobs\SyncBatchJob:sync_batch_slot_\$i\"); Cache::forget(\"laravel_unique_job:sync_batch_slot_\$i\"); }"

echo "Launching 8 immortal parallel queue workers..."
for i in {1..8}; do
    (
        while true; do
            TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
            echo "[$TIMESTAMP] [Worker $i] Starting queue worker..."
            php artisan queue:work database \
                --sleep=3 \
                --timeout=120 \
                --tries=3 \
                --memory=512
            EXIT_CODE=$?
            TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
            echo "[$TIMESTAMP] [Worker $i] Worker exited (code=$EXIT_CODE). Restarting in 2 seconds..."
            sleep 2
        done
    ) >> "$APP_DIR/storage/logs/worker_loop_$i.log" 2>&1 &
    echo "✓ Worker $i launched (Log: storage/logs/worker_loop_$i.log)"
done

echo ""
echo "=== All 8 immortal workers running in background! ==="
echo "Workers will auto-restart if they ever exit for any reason."
echo ""
echo "Monitor:  tail -f storage/logs/worker_loop_1.log"
echo "Stop all: pkill -f 'artisan queue:work'"
