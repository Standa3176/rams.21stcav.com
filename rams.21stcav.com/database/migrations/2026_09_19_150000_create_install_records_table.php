<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 45 Plan 04 Task 1 — `install_records`: the DURABLE half of the D-06
 * split.
 *
 * `InstallProgrammeService::createForProject()` archives the existing
 * programme and creates a brand-new `install_programmes` row on every
 * regenerate, so anything filed under a programme is orphaned the first time
 * a PM rebuilds the task list. `install_records` is the row that does NOT
 * change: one per project, never archived, never replaced. A visit filed
 * against the record survives any number of regenerates.
 *
 * ── What this migration deliberately does NOT do ─────────────────────────────
 *
 * 1. It does NOT move `install_tasks`. They stay on `install_programmes`.
 *    Re-pointing them would drag the live `commissioning_items.install_task_id`
 *    FK with them.
 * 2. It does NOT touch the UNIQUE constraint on
 *    `commissioning_signoffs.install_programme_id`. That key currently means
 *    "one signoff per generation"; re-parenting signoffs to the durable record
 *    would silently change it to mean "one per project", which
 *    `SignoffRaceTest` and `SignoffTransactionTest` assert against.
 *
 * Both are Phase 51's work — see `.planning/ROADMAP.md:295-297, 313`. Neither
 * is achievable behaviour-preservingly inside Phase 45, and ROADMAP criterion
 * 4 (with the flag off the app behaves exactly as it does today) is a hard
 * gate on this plan. The split is therefore purely ADDITIVE.
 *
 * `project_id` is nullable with `nullOnDelete()`, matching
 * `install_programmes.project_id`'s existing rule
 * (`2026_04_14_000001_create_install_programmes_table.php:28-32`) — a
 * programme legally survives its project with a NULL `project_id`, and the
 * durable record tolerates exactly the same.
 *
 * No `softDeletes()`: the record is durable by definition. Nothing archives
 * it, nothing deletes it, and `archiveExisting()` operates on programmes only.
 *
 * @see app/Models/InstallRecord.php
 * @see app/Services/InstallProgrammeService.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('install_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                  ->nullable()
                  ->constrained('projects')
                  ->nullOnDelete();

            $table->timestamps();

            // "One durable record per project" as a DATABASE fact, not a
            // convention. InstallProgrammeService::createForProject()'s
            // resolve-or-create relies on this under concurrency.
            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_records');
    }
};
