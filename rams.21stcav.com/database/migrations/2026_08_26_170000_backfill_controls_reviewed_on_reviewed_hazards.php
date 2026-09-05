<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 27-08 Task 4 — backfill `controls_reviewed = true` on every hazard row
 * inside every existing `rams_documents.reviewed_data['hazards']`.
 *
 * WHY THIS EXISTS
 *
 * Plan 27-08 gives hazard control text the same provenance marker that
 * HAZ-03 gave scores. `reviewedToRisk()`'s new precedence is:
 *
 *   1. controls violate a known house rule  -> replace from the library
 *   2. controls_reviewed !== true           -> replace from the library
 *   3. otherwise                            -> the engineer's text stands
 *
 * Absent === false, mirroring `score_reviewed`. Every document written before
 * this migration lacks the key, so without a backfill tier 2 would treat all
 * of them as never-reviewed and replace their controls wholesale on the next
 * regeneration — including site-specific wording an engineer typed by hand.
 * That is silent data loss on a live safety document.
 *
 * Backfilling to `true` prevents that. It does NOT weaken the fix that
 * motivated Plan 27-08: the live defect found on 21CQ30960 ("items over 20 kg",
 * "screens and equipment over 40\" — minimum two persons") is corrected by
 * tier 1, which fires on a rule violation regardless of this marker. So legacy
 * documents still get the safety correction; they just keep their clean,
 * deliberate customisations.
 *
 * User decision, 2026-08-26. The rejected alternative was no backfill —
 * guarantees no stale library text survives anywhere, at the cost of
 * discarding engineer controls irreversibly, per document, on regeneration.
 *
 * REVERSIBILITY
 *
 * `down()` is a deliberate no-op. This migration cannot distinguish a row it
 * added the key to from one that already carried `controls_reviewed => true`,
 * so removing the key on rollback would destroy genuine markers set through
 * the review form after this ran. Leaving the key in place is harmless: it
 * means "an engineer reviewed these controls", which is the safe reading for
 * pre-existing data either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $documentsTouched = 0;
        $hazardRowsTouched = 0;

        // Chunked — this runs against production.
        DB::table('rams_documents')
            ->select('id', 'reviewed_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$documentsTouched, &$hazardRowsTouched) {
                foreach ($rows as $row) {
                    if (empty($row->reviewed_data)) {
                        continue;
                    }

                    $data = is_array($row->reviewed_data)
                        ? $row->reviewed_data
                        : json_decode((string) $row->reviewed_data, true);

                    if (! is_array($data) || ! isset($data['hazards']) || ! is_array($data['hazards'])) {
                        continue;
                    }

                    $changed = 0;

                    foreach ($data['hazards'] as $i => $hazard) {
                        if (! is_array($hazard)) {
                            continue;
                        }

                        // Never overwrite an explicit value — including an
                        // explicit false, which means "the library should win
                        // here" and is a deliberate state.
                        if (array_key_exists('controls_reviewed', $hazard)) {
                            continue;
                        }

                        $data['hazards'][$i]['controls_reviewed'] = true;
                        $changed++;
                    }

                    if ($changed === 0) {
                        continue;
                    }

                    DB::table('rams_documents')
                        ->where('id', $row->id)
                        ->update(['reviewed_data' => json_encode($data)]);

                    $documentsTouched++;
                    $hazardRowsTouched += $changed;
                }
            });

        // Auditable output for the production run.
        echo sprintf(
            "backfill_controls_reviewed: %d document(s), %d hazard row(s) marked controls_reviewed=true\n",
            $documentsTouched,
            $hazardRowsTouched,
        );
    }

    public function down(): void
    {
        // Deliberate no-op — see the class docblock. Removing the key would
        // destroy markers legitimately set through the review form after this
        // migration ran, which is worse than leaving it.
    }
};
