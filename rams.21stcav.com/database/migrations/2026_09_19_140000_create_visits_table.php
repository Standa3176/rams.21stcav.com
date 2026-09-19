<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 45 Plan 02 Task 1 — the `visits` table: one typed record per trip to
 * site, the spine of the whole v4.0 cockpit milestone.
 *
 * `project_id` is the MANDATORY parent, NOT the install record (45-CONTEXT.md
 * D-06, refinement added 2026-09-19 after research). A backfilled *survey*
 * visit routinely predates any install programme existing at all, so the
 * project is the only parent every visit is guaranteed to have.
 * `install_record_id` is therefore nullable, and carries NO `constrained()`
 * here — Plan 45-04 creates `install_records` and adds that FK.
 *
 * ── Why `source_id` is deliberately NOT a foreign key ────────────────────────
 *
 * D-04: "a visit survives the record it wraps being superseded or
 * soft-deleted. The trip to site happened; superseding the paperwork does not
 * un-happen it."
 *
 * Every FK in this schema is `cascadeOnDelete` or `nullOnDelete`. A cascade FK
 * would hard-delete the visit when a survey is force-deleted; `nullOnDelete`
 * would silently detach it. Either outcome un-happens the trip to site, which
 * D-04 forbids. So the wrapped record is identified by a
 * `(source_type, source_id)` pair with a unique composite index and no
 * `constrained()` at all.
 *
 * This deviates from this repo's general "real FKs everywhere" habit
 * DELIBERATELY. It is stated here, at the definition site, so a later reviewer
 * does not "fix" it and quietly break D-04. See also
 * `45-RESEARCH.md` Pitfall 3.
 *
 * The unique index on `(source_type, source_id)` is what makes the 45-05
 * backfill idempotent at the DATABASE level — a concurrent re-run cannot mint
 * a second visit for the same source row, which an application-side
 * `->exists()` check alone would not guarantee.
 *
 * `title` / `summary` are denormalised on purpose, so a visit row still
 * renders after its source is force-deleted. Both wrapped models already
 * follow that same habit (`SiteSurvey.php:18-21`, `Worksheet.php:44-47`).
 *
 * No `softDeletes()`: this phase has no delete path, and the trait's global
 * scope is one more thing the backfill's idempotency guard would have to
 * reason about.
 *
 * @see app/Models/Visit.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-01..D-04, D-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            // The mandatory parent (D-06 refinement).
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // Nullable link to the durable install record. No constrained() —
            // Plan 45-04 creates `install_records` and owns the FK.
            $table->unsignedBigInteger('install_record_id')->nullable()->index();

            $table->string('type', 32)->index();
            $table->string('status', 24)->default('completed')->index();
            $table->date('scheduled_date')->nullable()->index();
            $table->json('labour_resource_ids')->nullable();

            // The wrapped record — deliberately not a FK (see docblock).
            $table->string('source_type', 24)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // D-02's explicit, queryable "this was inferred" marker.
            $table->boolean('is_backfilled')->default(false)->index();

            // Denormalised so the row still renders standalone.
            $table->string('title', 200)->nullable();
            $table->text('summary')->nullable();

            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'visits_source_unique');
            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
