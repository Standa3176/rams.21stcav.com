<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 260822-esf Plan 01 Task 1 — schema foundation for the project
 * deliverables selection model (260822-CONTEXT.md D-01, D-03, D-04).
 *
 *   - project_deliverables: one row per (project_id, deliverable_key), a real
 *     INDEXED `state` column (required / not_required / not_yet_decided,
 *     D-01's three-state model). `Project.metadata` (JSON) is explicitly
 *     disqualified as the store — the Phase 24 D-03 migration docblock
 *     already established that "metadata only ever holds the LAST edit, not
 *     full history", and D-12's health filter / D-13's amber-grace-period
 *     query both need to be indexable, which a JSON extract cannot be on
 *     MariaDB. Nine canonical deliverable_key values per D-04; see
 *     App\Models\ProjectDeliverable::ALL_KEYS for the single source of truth.
 *
 *   - project_deliverable_audits: dedicated append-only audit trail (D-03 —
 *     every flag change records who, when, and an optional why). Mirrors
 *     device_stencil_audits (Phase 24 D-03) but ADDS a nullable `reason` text
 *     column, which device_stencil_audits confirmedly lacks — this table's
 *     rationale (D-03's "why") requires it explicitly, so it is not omitted
 *     here as it was there.
 *
 * @see app/Models/ProjectDeliverable.php
 * @see app/Models/ProjectDeliverableAudit.php
 * @see app/Services/ProjectDeliverablesService.php
 * @see .planning/phases/260822-esf-project-deliverables-selection/260822-CONTEXT.md (D-01, D-03, D-04)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();
            $table->string('deliverable_key', 30);
            // state: required / not_required / not_yet_decided (D-01). See
            // ProjectDeliverable::STATE_* constants.
            $table->string('state', 20)->default('not_yet_decided')->index();
            $table->timestamps();

            $table->unique(['project_id', 'deliverable_key']);
        });

        Schema::create('project_deliverable_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_deliverable_id')
                ->constrained('project_deliverables')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            // action: manual_change / auto_flip / import_default / backfill
            // (see ProjectDeliverableAudit::ACTION_* constants).
            $table->string('action', 30);
            // D-03's "why" — free text, optional. The device_stencil_audits
            // precedent lacks this column; it is required here.
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Audits table first — it holds the FK onto project_deliverables.
        Schema::dropIfExists('project_deliverable_audits');
        Schema::dropIfExists('project_deliverables');
    }
};
