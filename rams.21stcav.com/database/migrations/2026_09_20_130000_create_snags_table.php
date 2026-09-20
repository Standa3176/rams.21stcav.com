<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46 Plan 02 Task 1 — the MINIMAL `snags` table.
 *
 * 46-CONTEXT.md D-03 is the whole point of this table's shape: "raising a snag
 * here creates a minimal snag record linked to the visit it came from. Parts,
 * the three outcomes, and linked follow-up snags are Phase 47. Phase 46 must
 * not build the snag lifecycle — it provides the entry point Phase 47 builds
 * out."
 *
 * Nine columns, and deliberately not a tenth. The absence is asserted as data
 * by `SnagTest::test_the_snags_table_carries_no_phase_47_column()`.
 *
 * ── Why `visit_id` is NULLABLE even though Phase 46 always sets it ──────────
 *
 * Phase 47 criterion 1: "a snag may exist with no visit attached", and
 * criterion 5: "a snag may sit with the client or a third party and never have
 * a visit at all". A NOT NULL column would have to be ALTERed to allow that,
 * which on sqlite means a table rebuild. This is the one place the schema
 * anticipates Phase 47, and it does so by being LESS strict — never by adding
 * a column nobody writes.
 *
 * ── Why `visit_id` is `nullOnDelete` and `project_id` is `cascadeOnDelete` ──
 *
 * These two FKs differ on purpose, and the difference is the D-04 property
 * applied to a snag:
 *
 *   - `project_id` CASCADES. A snag has no meaning without its project; if the
 *     project is gone there is nothing to snag.
 *   - `visit_id` NULLS. What was reported does not stop having been reported
 *     because the visit record went. `visits` has no `softDeletes()`, so a
 *     deleted visit is a HARD delete — a cascade here would destroy a real
 *     finding, and that is exactly the "un-happening" D-04 forbids. Nulling
 *     keeps the snag and loses only the pointer.
 *
 * Contrast with `visits.source_id`, which carries NO foreign key at all
 * because a visit must survive with its pointer INTACT. A snag has no
 * denormalised copy of its visit to fall back on and does not need one, so a
 * real FK that nulls is both honest and cheap here. Stated at the definition
 * site so a later reviewer does not "harmonise" the two.
 *
 * ── Derived over stored ─────────────────────────────────────────────────────
 *
 * No `raised_at` column: `created_at` already is the moment it was raised.
 * No `is_open` flag: that is `status === 'open'`. Same doctrine as
 * `Visit::isSuperseded()` and 46-01's refusal to add `returned_at`.
 *
 * @see app/Models/Snag.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-02, D-03)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snags', function (Blueprint $table) {
            $table->id();

            // A snag has no meaning without its project.
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // The visit it came from. Nullable for Phase 47 (see docblock);
            // nullOnDelete so the snag outlives the visit record.
            $table->foreignId('visit_id')
                ->nullable()
                ->constrained('visits')
                ->nullOnDelete();

            // What the PM saw. Free text — stored raw, escaped at render
            // (T-46-02-01; Plan 46-05 renders through `{{ }}` only).
            $table->string('title', 200);
            $table->text('detail')->nullable();

            // Where on site. A plain string, not a room FK: a snag is often
            // raised about somewhere the room list does not yet name.
            $table->string('room_name', 200)->nullable();

            // Who raised it. Nulls rather than cascades — the report outlives
            // the account of the person who filed it.
            $table->foreignId('raised_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // One value in Phase 46. Phase 47 adds fixed / not fixed /
            // deferred here — see Snag::STATUSES.
            $table->string('status', 24)->default('open');

            $table->timestamps();

            // The two reads this table will get: a project's open snags, and
            // the snags raised on one visit (Phase 47: one visit may resolve
            // several).
            $table->index(['project_id', 'status']);
            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snags');
    }
};
