<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance migration: add a MySQL generated virtual column for the first
 * element of the `event_category` JSON array, then index it.
 *
 * Problem (GAP-5):
 *   `whereJsonContains('event_category', $cat)` in the portal Blade view,
 *   PDF export, and CSV export forces a full-table JSON scan on every request.
 *   At 22K+ rows this adds ~200ms per page load. At 100K+ rows it becomes
 *   the slowest query in the system.
 *
 * Solution:
 *   - Add a VIRTUAL generated column `event_category_first` that extracts
 *     the first element from the JSON array using MySQL's JSON_UNQUOTE().
 *   - Add a standard B-tree index on that generated column.
 *   - Existing queries do NOT automatically use this index. The follow-up
 *     code change in EventController/web.php should switch:
 *       ->whereJsonContains('event_category', $val)
 *     to:
 *       ->where('event_category_first', $val)
 *     for single-category filter queries to benefit from the index.
 *
 * Compatibility:
 *   - Requires MySQL 5.7.6+ or MariaDB 5.3+ (JSON_UNQUOTE, JSON_EXTRACT).
 *   - VIRTUAL columns are computed on-read, zero storage overhead.
 *   - The migration is safe to run on live tables — no row lock escalation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Use raw SQL: Blueprint does not natively support GENERATED columns.
        DB::statement("
            ALTER TABLE events
            ADD COLUMN event_category_first VARCHAR(120)
                GENERATED ALWAYS AS (
                    JSON_UNQUOTE(JSON_EXTRACT(event_category, '$[0]'))
                ) VIRTUAL
        ");

        Schema::table('events', function (Blueprint $table) {
            $table->index('event_category_first', 'idx_events_category_first');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_category_first');
        });

        DB::statement("ALTER TABLE events DROP COLUMN event_category_first");
    }
};
