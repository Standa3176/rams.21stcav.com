<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 45 Plan 04 Task 1 — link the regenerable half to the durable half.
 *
 * ── The column is NULLABLE, and that is mandatory ────────────────────────────
 *
 * `InstallProgrammeFactory` (`database/factories/InstallProgrammeFactory.php`)
 * does not set `install_record_id`, and three further factories chain off
 * `InstallProgramme::factory()` — `InstallTaskFactory`,
 * `CommissioningItemFactory` and `CommissioningSignoffFactory`. A NOT NULL
 * column would fail every test that builds a programme directly or
 * transitively through any of them, cascading across the entire programme,
 * install-task and commissioning suites. Nullable is not a convenience here;
 * it is the behaviour-preservation requirement.
 *
 * Existing rows are linked by the `install-records:backfill` command
 * (Plan 45-04 Task 3), NOT by this migration. A migration that wrote rows
 * would be an un-reviewable, un-dry-runnable data change against live
 * production programmes; the command is idempotent and dry-run by default.
 *
 * `nullOnDelete()` mirrors the table's existing FK posture. No existing FK,
 * unique constraint, cascade rule, index or `softDeletes()` on this table is
 * altered — this migration only ADDS a column.
 *
 * @see database/migrations/2026_09_19_150000_create_install_records_table.php
 * @see app/Console/Commands/BackfillInstallRecordsCommand.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_programmes', function (Blueprint $table) {
            $table->foreignId('install_record_id')
                  ->nullable()
                  ->after('project_id')
                  ->constrained('install_records')
                  ->nullOnDelete();

            $table->index('install_record_id');
        });
    }

    public function down(): void
    {
        Schema::table('install_programmes', function (Blueprint $table) {
            $table->dropIndex(['install_record_id']);
            $table->dropConstrainedForeignId('install_record_id');
        });
    }
};
