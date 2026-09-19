<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 45 Plan 04 Task 1 — give `visits.install_record_id` its real FK.
 *
 * Plan 45-02 created the column unconstrained
 * (`2026_09_19_140000_create_visits_table.php`) because `install_records` did
 * not exist yet. This migration attaches the constraint now that it does.
 *
 * NULLABLE, with `nullOnDelete()`. Per the D-06 refinement recorded in
 * `45-CONTEXT.md:85-90`, a visit's MANDATORY parent is the project, not the
 * install record: a backfilled survey visit routinely predates any install
 * programme — and therefore any install record — existing at all.
 *
 * @see database/migrations/2026_09_19_140000_create_visits_table.php
 * @see app/Models/Visit.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreign('install_record_id')
                  ->references('id')
                  ->on('install_records')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['install_record_id']);
        });
    }
};
