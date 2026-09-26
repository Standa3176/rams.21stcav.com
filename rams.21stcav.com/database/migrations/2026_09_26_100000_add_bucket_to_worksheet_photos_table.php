<?php

use App\Models\WorksheetPhoto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46.4 Plan 01 Task 1 — room photos gain a START / COMPLETION bucket.
 *
 * The user, verbatim (46.4-CONTEXT.md D-03): "Worksheet will need rooms start
 * and completion pics." Today `worksheet_photos` has no bucket at all.
 *
 * ── THE D-03 RULING ON HISTORY, AND WHY ─────────────────────────────────────
 *
 * Every row that existed before this migration is stamped `completion`, by the
 * EXPLICIT UPDATE below and NOT by a column default.
 *
 * The relabel is deliberate and it is correct. The tray these photos were
 * captured through is titled, verbatim:
 *
 *     📷 Photos of completed work
 *
 * (`resources/views/worksheets/public-show.blade.php:938`). Calling them
 * `completion` restates the label the engineer was already reading when they
 * took them. Calling them `start`, or leaving them NULL, would invent a
 * distinction nobody ever made.
 *
 * ⚠️ THAT TRAY TITLE IS NOW LOAD-BEARING. A later plan in this phase is
 * FORBIDDEN from changing it, because this ruling rests on it.
 *
 * THE COUNTER-ARGUMENT, RECORDED AND REJECTED: a legacy row is arguably
 * "unbucketed" rather than completion, and a third `unknown` bucket would say
 * so honestly. Rejected because an `unknown` bucket would have to be rendered
 * somewhere forever, and the office would have to learn what it means — a
 * permanent cost to preserve a distinction the old UI never offered.
 *
 * Checkpoint step 5 of plan 46.4-07 puts this ruling back in front of the
 * user. If they disagree it is far cheaper to change now than in a month.
 *
 * ── WHY THE COLUMN ARRIVES NULLABLE AND IS THEN TIGHTENED ───────────────────
 *
 * A column added `NOT NULL DEFAULT 'completion'` fills every existing row with
 * the default, on both MySQL and SQLite. A backfill written underneath it
 * would be a no-op that READS like a decision — invisible in a schema diff and
 * decided by nobody. So the column arrives nullable, the backfill is the thing
 * that actually stamps history, and only then is the column tightened to NOT
 * NULL DEFAULT 'completion' for everything written from here on.
 *
 * `WorksheetPhotoBucketDefaultTest` holds both halves: the round trip through
 * a genuine pre-migration row, and the shape of this file.
 *
 * `down()` drops the index and the column. It does NOT attempt to restore a
 * distinction that never existed — there is nothing to restore.
 *
 * @see app/Models/WorksheetPhoto.php
 * @see tests/Feature/Worksheets/WorksheetPhotoBucketDefaultTest.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-03)
 */
return new class extends Migration
{
    /**
     * Named explicitly: the default Laravel name for a three-column index
     * would be `worksheet_photos_worksheet_id_room_name_bucket_index`, which
     * is 52 characters and fine, but naming it here means `down()` drops
     * exactly the index `up()` created, on every driver, forever.
     */
    private const INDEX = 'worksheet_photos_worksheet_id_room_name_bucket_index';

    public function up(): void
    {
        if (! Schema::hasColumn('worksheet_photos', 'bucket')) {
            Schema::table('worksheet_photos', function (Blueprint $table): void {
                // NULLABLE ON PURPOSE. See the docblock — this is what makes
                // the backfill below load-bearing rather than decorative.
                $table->string('bucket', 20)->nullable()->after('room_name');
            });
        }

        // ── THE BACKFILL. This statement is the D-03 ruling, executed. ──────
        //
        // Not the column default. Not a comment. A real UPDATE over every row
        // that predates this migration.
        DB::table('worksheet_photos')
            ->whereNull('bucket')
            ->update(['bucket' => WorksheetPhoto::BUCKET_COMPLETION]);

        // Only now is it safe to forbid NULL: there are none left.
        Schema::table('worksheet_photos', function (Blueprint $table): void {
            $table->string('bucket', 20)
                ->nullable(false)
                ->default(WorksheetPhoto::BUCKET_COMPLETION)
                ->change();
        });

        // Created AFTER the ->change(), deliberately: on SQLite a column
        // change rebuilds the table, and an index created before it would be
        // rebuilt along with it. Creating it last removes the question.
        if (! $this->indexExists()) {
            Schema::table('worksheet_photos', function (Blueprint $table): void {
                // Every read in plans 02 and 03 is "this room's photos in this
                // bucket", so the bucket rides the existing composite rather
                // than getting an index of its own.
                $table->index(['worksheet_id', 'room_name', 'bucket'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('worksheet_photos', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('worksheet_photos', 'bucket')) {
            Schema::table('worksheet_photos', function (Blueprint $table): void {
                $table->dropColumn('bucket');
            });
        }
    }

    private function indexExists(): bool
    {
        foreach (Schema::getIndexes('worksheet_photos') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
