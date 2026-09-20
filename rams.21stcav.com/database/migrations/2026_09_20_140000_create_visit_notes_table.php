<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46 Plan 07 Task 1 — `visit_notes`, the office's annotation of a return.
 *
 * 46-CONTEXT.md D-02, verbatim:
 *
 *   "Add an office note — the PM annotates the return without changing what
 *   the engineer said. The engineer's record stays intact; the office view
 *   sits alongside it. Do NOT let an office note overwrite or edit
 *   engineer-captured data."
 *
 * ── WHY `site_surveys.office_review_notes` WAS **NOT** REUSED ───────────────
 *
 * That column exists (quick task 260508-v7g, "Office review surface") and
 * reusing it would have been one line of code. It was rejected for three
 * reasons, all of them the decision rather than a preference:
 *
 *   1. IT IS SINGLE-VALUED AND OVERWRITABLE. The second note destroys the
 *      first. An annotation surface whose history can be silently erased is
 *      not an annotation surface.
 *   2. IT CARRIES NO AUTHOR AND NO TIMESTAMP. "Who said that, and when" is
 *      unanswerable, which is exactly the accountability the cockpit's other
 *      office acts (accept, send back) were built to record.
 *   3. IT LIVES ON THE ENGINEER'S RECORD. Writing office text inside
 *      engineer-captured data is the shape D-02 forbids in its own words.
 *
 * And a fourth, practical: a `worksheet` has no equivalent column at all, so
 * reusing the survey's would mean first-fix and install visits could not be
 * annotated. Do not "consolidate" these two later — they are different things.
 *
 * ── APPEND-ONLY ─────────────────────────────────────────────────────────────
 *
 * `created_at` ONLY, no `updated_at` — the same shape `project_activity_logs`
 * uses, and for the same reason: a note that can be edited is a note that can
 * be made to say something it did not say. `VisitNote::UPDATED_AT` is null and
 * `CockpitOfficeNoteAndSnagTest::test_a_visit_note_has_no_update_path_and_no_delete_path_anywhere_in_app()`
 * greps `app/` so the absence stays an absence.
 *
 * ── FK dispositions, which differ on purpose (the `snags` rule, applied) ────
 *
 *   - `project_id` CASCADES: a note has no meaning without its project.
 *   - `visit_id` NULLS: `visits` has no softDeletes, so a deleted visit is a
 *     HARD delete. What the office observed does not stop having been observed
 *     because the visit row went.
 *   - `user_id` NULLS: the note outlives the account, and reads "System"
 *     exactly as the activity feed does.
 *
 * @see app/Models/VisitNote.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-02)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('visit_id')
                ->nullable()
                ->constrained('visits')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The office's words. Stored raw, escaped at render — `{{ }}`
            // only, never `{!! !!}` (T-46-07-05).
            $table->text('body');

            // APPEND-ONLY: created_at and nothing else.
            $table->timestamp('created_at')->nullable();

            // The two reads: one visit's notes, and a project's notes.
            $table->index('visit_id');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_notes');
    }
};
