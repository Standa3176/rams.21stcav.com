<?php

use App\Models\WorksheetAdditionalKit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46.4 Plan 01 Task 2 — the first field in RAMS where extra kit can land.
 *
 * The user, verbatim: "For each room add addition kit field where an engineer
 * work engineer qty and part desc then add another and another so additional
 * items are separated out so they can be presented to the office admin as a
 * clean list rather than free text."
 *
 * Measured 2026-09-26: a grep for `additional kit|extra kit|materials
 * used|kit used` across the whole app returns NOTHING. Today extra kit lands
 * in `worksheet_signoffs.comments` — a max:5000 textarea — or in a phone call.
 *
 * ── ROOM SCOPING IS A STRING, ON PURPOSE ────────────────────────────────────
 *
 * `room_name` is a plain string, exactly as `worksheet_photos.room_name` and
 * `device_label_photos.room_name` already are. RAMS rooms are JSON inside
 * `worksheets.generated_data['rooms']`, not rows — there is nothing to point a
 * foreign key AT. SCC has real `PmvVisitRoom` rows with an FK, which is why
 * its room-rename endpoint is safe and a RAMS equivalent would not be:
 * renaming a room here orphans everything keyed to the old string. THIS PHASE
 * ADDS NO RENAME, AND MUST NOT.
 *
 * ── THE D-08 MARK RULING ────────────────────────────────────────────────────
 *
 * "Engineer can add items, modify items and mark items for deletion (with
 * reason)." — the user, verbatim.
 *
 * A KIT ROW IS NEVER HARD DELETED. A marked row is NOT deleted, NOT
 * soft-deleted, and does NOT leave the office's list. `marked_for_deletion_at`
 * + `deletion_reason` together are an INSTRUCTION to the office — *the
 * engineer says this should come off, because X* — and the office decides.
 * That is an audit trail, which is the whole point of a list that gets
 * reconciled against a quote.
 *
 * `deletion_reason` is nullable in the SCHEMA only because NULL is what an
 * UNMARKED row holds. The endpoint in plan 05 REQUIRES it whenever the mark is
 * set: the two move together, and a blank reason is a row the office cannot
 * action.
 *
 * There is deliberately no `unmark` and no `restore` — D-08 gives the engineer
 * add, modify and mark, and does not give them a way back. If an engineer
 * marks a row by mistake, today the office fixes it. Recorded as a known gap
 * in 46.4-01-SUMMARY.md rather than solved by inventing an endpoint the user
 * did not ask for.
 *
 * ── THE D-08 AMENDMENT RULING, AND THE ALTERNATIVE REJECTED ─────────────────
 *
 * An amendment is an APPEND-ONLY JSON TRAIL ON THE ROW (`amendments`), not a
 * second table. Each entry is:
 *
 *     {at, actor, changes: {field: {from, to}}}
 *
 * Chosen because: it is one migration and no new model; it is only ever read
 * per-row, on one office screen; and a JSON blob carrying structured history
 * is already load-bearing on this exact page — `worksheets
 * .pre_install_confirmations`, namespaced `survey_review.{room}` /
 * `room_complete.{room}`.
 *
 * REJECTED: a `worksheet_additional_kit_events` table. It is the correct shape
 * the moment amendments are queried ACROSS rows.
 *
 * ⚠️ THE TRIGGER, BY NAME: if Phase 47 or Phase 49 needs a cross-row amendment
 * query — an office report of "everything amended this week", or any filter
 * that is not scoped to a single kit row — PROMOTE `amendments` TO A
 * `worksheet_additional_kit_events` TABLE. Do not write a JSON search.
 *
 * ── THE ACTOR STAMPS ────────────────────────────────────────────────────────
 *
 * `created_by_actor` and `marked_by_actor`, and the `actor` key inside every
 * `amendments` entry, hold the `ip:…|actor:<sha256 slice>` stamp. THEY ARE
 * NEVER RENDERED. Same rule that governs `device_label_photos.captured_by`,
 * which once leaked a UUID token fragment and needed
 * `2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php` to
 * null every legacy value. All three are off `$fillable` on the model.
 *
 * ── NO UNIT COLUMN (D-10) ───────────────────────────────────────────────────
 *
 * A unit field (each / metres / boxes, for cable and trunking) was put to the
 * user and DECLINED: "3.no." qty and part description only. Do not add one —
 * not as a nullable placeholder, not "for later".
 *
 * @see app/Models/WorksheetAdditionalKit.php
 * @see tests/Feature/Worksheets/WorksheetAdditionalKitModelTest.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-06, D-08, D-09, D-10)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_additional_kit', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('worksheet_id')->constrained()->cascadeOnDelete();

            // A string, not an FK. See the docblock — rooms are JSON.
            $table->string('room_name', 200);

            // D-02: the engineer is RESOLVED FROM THE VISIT, never typed.
            // Nullable is the ruled fallback — a worksheet with no visit, or a
            // visit with no allocation, still records the row. A null means
            // "no engineer was allocated to this visit"; because there is no
            // free-text engineer field anywhere in this phase, a null can
            // never become a spelling variant.
            $table->foreignId('labour_resource_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('qty');
            $table->string('part_description', 500);

            // Provenance. Defaulted rather than nullable: every row has one,
            // and the default is the only writer this phase introduces. Off
            // $fillable so a request body cannot claim to be the office.
            $table->string('created_via', 32)->default(WorksheetAdditionalKit::CREATED_VIA_ENGINEER_LINK);
            $table->string('created_by_actor', 80)->nullable();

            // D-09: "own" means added via the engineer link AND NOT YET
            // RECONCILED by the office. Per-person ownership is not
            // achievable — every engineer on a worksheet shares one token and
            // an `actor:<sha256 slice>` cannot separate two people holding the
            // same link. The user accepted that.
            $table->timestamp('reconciled_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            // ── D-08: the mark. Never a delete. ──────────────────────────────
            $table->timestamp('marked_for_deletion_at')->nullable();
            $table->string('deletion_reason', 500)->nullable();
            $table->string('marked_by_actor', 80)->nullable();

            // ── D-08: the amendment trail. Append-only. ──────────────────────
            $table->json('amendments')->nullable();
            $table->timestamp('amended_at')->nullable();

            $table->timestamps();

            // Every engineer-side read is "this worksheet's kit in this room".
            $table->index(['worksheet_id', 'room_name']);

            // The office filters on the mark, so it rides its own composite.
            $table->index(['worksheet_id', 'marked_for_deletion_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_additional_kit');
    }
};
