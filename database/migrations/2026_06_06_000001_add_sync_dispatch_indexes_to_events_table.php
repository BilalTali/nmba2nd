<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance migration: add composite covering index for the scheduler's
 * primary dispatch query in Kernel::dispatchPendingBatches().
 *
 * The query pattern is:
 *   WHERE (sync_status = 'pending' AND last_attempt_at < now-5min)
 *      OR (sync_status = 'syncing' AND updated_at < now-10min)
 *   AND sync_attempts BETWEEN 0 AND 9
 *   ORDER BY created_at ASC
 *   LIMIT 1000
 *
 * Without this index MySQL performs a full-table scan on every scheduler tick
 * (every 60 seconds). With 100K+ rows this becomes the single biggest DB
 * bottleneck in the system.
 *
 * Index 1 — sync_dispatch_main:
 *   Covers the primary pending path: status + attempts + last_attempt_at.
 *   MySQL uses it for the WHERE and the ORDER BY when filtering pending rows.
 *
 * Index 2 — sync_dispatch_zombie:
 *   Covers the zombie-recovery path: status + updated_at.
 *   Allows instant lookup of stuck-syncing events without a full scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Primary dispatch path: pending events eligible for re-dispatch
            // Composite: (sync_status, sync_attempts, last_attempt_at, created_at)
            // — sync_status + sync_attempts eliminates most rows up front
            // — last_attempt_at further filters the eligibility window
            // — created_at covers the ORDER BY so MySQL sorts without a filesort
            $table->index(
                ['sync_status', 'sync_attempts', 'last_attempt_at', 'created_at'],
                'idx_events_sync_dispatch_main'
            );

            // Zombie recovery path: syncing events stuck > 10 minutes
            $table->index(
                ['sync_status', 'updated_at'],
                'idx_events_sync_dispatch_zombie'
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_sync_dispatch_main');
            $table->dropIndex('idx_events_sync_dispatch_zombie');
        });
    }
};
