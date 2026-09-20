<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46 Plan 01 Task 1 — the visit lifecycle, as the smallest honest schema.
 *
 * Seven columns, and the rule that picked them: A COLUMN FOR EVERY ACT A HUMAN
 * PERFORMED, AND A DERIVATION FOR EVERYTHING THE ENGINEER'S OWN RECORD ALREADY
 * KNOWS. Sending, accepting and sending back are acts performed by a PM inside
 * this app against no other record, so they are stored here. Everything else in
 * the lifecycle is read at render time from the record it is true of.
 *
 * ── Why EVERY column is nullable, with no default ────────────────────────────
 *
 * There are 24 backfilled visits on the live server, reconstructed by Phase 45
 * from surveys and signed worksheets. Nobody measured when those trips were
 * "sent" or who "accepted" them, because nobody did either — the acts never
 * happened. A default, or a back-filling `up()`, would write an assertion about
 * history that no one can evidence, and the cockpit's `1 visit · reconstructed`
 * at-rest disclosure would start claiming things Phase 45 was careful not to.
 * NULL is the only honest value for an act that was never performed.
 *
 * After this migration a pre-existing row still reads `status = 'completed'`,
 * `is_backfilled = 1`, and NULL in all seven new columns.
 *
 * ── Why there is deliberately NO `returned_at` and NO `scope_locked_at` ──────
 *
 * A return is already recorded, by the engineer, on their own record —
 * `SiteSurvey.submitted_at`, or the latest `WorksheetSignoff`. A second copy on
 * `visits` would go stale the moment a worksheet is re-signed after remedials,
 * and then the cockpit and the engineer link would disagree about whether the
 * work came back with no way to tell which is true. This is the same reasoning
 * `Visit::isSuperseded()`'s docblock already makes, applied to the same shape
 * of fact, and the same reasoning behind 46-CONTEXT.md D-01's carry-forward
 * reading the survey live rather than copying it.
 *
 * `scope_locked_at` is the same mistake one step further out: the lock is not
 * an independent fact at all, it is a CONSEQUENCE of a return
 * (ROADMAP v4.0 criterion 3 — "scope locks once a return arrives"). Storing a
 * consequence lets it contradict its own cause.
 *
 * Both are DERIVED on the model: `Visit::returnedAt()` and `Visit::isLocked()`.
 * `VisitLifecycleTest::test_the_stored_status_vocabulary_did_not_grow()` guards
 * the other half of this — the stored `status` vocabulary stays
 * `planned` / `completed`, so no reconstructed row is ever rewritten.
 *
 * If you are here because a `returned_at` column would be convenient: that is
 * the argument this paragraph exists to refuse. Argue against it in a commit
 * message, do not fill a gap that was dug on purpose.
 *
 * ── What `rooms_in_scope` does and does NOT do (D-05, requirement VL-12) ─────
 *
 * `rooms_in_scope` CAPTURES which rooms a visit covers, and Plan 46-05 renders
 * it on the engineer's link so an engineer arriving on site knows their scope.
 *
 * It does NOT scope the RAMS or the worksheet GENERATORS. Both
 * (`RamsController::generateFromProject()`, `WorksheetController::generateFromProject()`)
 * take a `Project` and nothing else, and the RAMS is authored by an AI pipeline
 * driven by the whole quote — nobody has decided how a room-scoped RAMS should
 * be written. That half of ROADMAP v4.0 criterion 2 is the open gap **VL-12,
 * NOT DELIVERED** in `.planning/REQUIREMENTS.md`, and it needs a user decision
 * and a phase of its own. Do not infer from this column that the generators
 * were re-scoped.
 *
 * ── The two foreign keys ─────────────────────────────────────────────────────
 *
 * `accepted_by_user_id` and `created_by_user_id` are real FKs to `users` with
 * `nullOnDelete()`: deleting a staff login must never delete delivery history,
 * and "who accepted this" must read NULL rather than silently become somebody
 * else. Note this is a different posture from `source_id`, which is
 * deliberately NOT an FK (see the create-table migration's docblock, D-04) —
 * the difference is that a user is not the thing the visit evidences.
 *
 * No existing column, default, index or the `visits_source_unique` constraint
 * is touched. This migration only ADDS.
 *
 * @see app/Models/Visit.php
 * @see database/migrations/2026_09_19_140000_create_visits_table.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-01, D-05, D-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // ── Acts a PM performs, recorded because nothing else records them ──
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_back_at')->nullable();

            // Why it went back, in the PM's own words.
            $table->text('send_back_reason')->nullable();

            // ── Who (T-46-01-02: an accept with no actor is repudiable) ────────
            $table->foreignId('accepted_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ── D-05: captured and rendered on the engineer link; NOT fed to
            //    the RAMS/worksheet generators. See VL-12 above. ───────────────
            $table->json('rooms_in_scope')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Drop the constrained FKs FIRST — sqlite and MySQL differ in how
            // they tolerate dropping a column that still carries a constraint,
            // and this repo's suite runs on sqlite `:memory:`.
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');

            $table->dropColumn([
                'sent_at',
                'accepted_at',
                'sent_back_at',
                'send_back_reason',
                'rooms_in_scope',
            ]);
        });
    }
};
