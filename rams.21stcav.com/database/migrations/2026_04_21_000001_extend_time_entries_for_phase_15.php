<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 extension of the Phase 14 time_entries table.
 *
 * Additive columns (per CLAUDE.md safe-migration rule — no column renames,
 * no existing-column type changes):
 *   - category        (string, nullable) — INST-04a; enum values enforced at
 *                     the service layer via TimeEntry::CATEGORIES. SQLite + MySQL
 *                     parity beats DB CHECK constraints here.
 *   - notes           (text, nullable)   — INST-04e D-06; optional clock-out note,
 *                     ≤500 chars enforced in the Plan 15-02 FormRequest.
 *   - closure_reason  (string, nullable) — D-12; null = manual clock-out,
 *                     'stale_auto_close' written by the scheduled command (Plan 15-03).
 *
 * Also adds an index on (project_id, clocked_in_at) to speed up the dashboard
 * widget totals query ("SUM(minutes) GROUP BY category WHERE project_id = ?").
 *
 * Phase 14 rows backfill to NULL across all three columns, which Plan 15-02
 * will treat as 'other' at read-time rather than bulk-mutating history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('category', 32)
                  ->nullable()
                  ->after('user_id');

            $table->text('notes')
                  ->nullable()
                  ->after('last_heartbeat_at');

            $table->string('closure_reason', 32)
                  ->nullable()
                  ->after('notes');

            $table->index(
                ['project_id', 'clocked_in_at'],
                'time_entries_project_clocked_in_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_project_clocked_in_index');
            $table->dropColumn(['category', 'notes', 'closure_reason']);
        });
    }
};
