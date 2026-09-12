<?php

use App\Services\Rams\RamsComplianceUpgradeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 29-10 Task 2 — backfill the `contractor_note` key into
 * `generated_data['cdm_duty_holders']` for `rams_documents` rows persisted
 * before that key existed in {@see RamsComplianceUpgradeService::addCdmDutyHolders()}'s
 * output shape.
 *
 * ── WHY THIS EXISTS — the decision this plan is required to make (29-UAT.md
 *    Gap 3) ──────────────────────────────────────────────────────────────
 * The 46 production rows the 2026-09-11 backfill
 * (`2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php`) already
 * patched were patched BEFORE `contractor_note` existed as a key at all in
 * `addCdmDutyHolders()`'s output shape — that migration only ever touched
 * `principal_designer` / `principal_contractor` (and the mirrored
 * `reviewed_data['cdm']` rows). Those 46 rows' persisted
 * `generated_data['cdm_duty_holders']` array therefore has NO
 * `contractor_note` key whatsoever, not even an empty one.
 *
 * `RamsComplianceUpgradeService::upgrade()` running fresh on any real
 * regeneration always rebuilds `cdm_duty_holders` from scratch via
 * `addCdmDutyHolders()`, so it naturally reaches every document on its NEXT
 * regeneration and needs no backfill for that case. But a COMPLETED
 * document that never regenerates again would never pick up the RULE-07
 * sentence without a backfill — mirroring exactly the reasoning D-02 gave
 * for the original CDM placeholder backfill. Therefore this backfill IS
 * warranted, not optional, and is written now so it is one `.env`-adjacent
 * step away from running, per the phase's own "make the call explicit"
 * instruction.
 *
 * This migration is NOT run against production by this plan (out of
 * scope) — Plan 29-14 or a human owns running it after the render fix
 * (Plan 29-11/29-12) ships.
 *
 * ── GUARD DISCIPLINE ─────────────────────────────────────────────────────
 * A row is only patched when `generated_data['cdm_duty_holders']` exists,
 * is a non-empty array, AND does NOT already have a `contractor_note` key
 * — checked with `array_key_exists()`, never `empty()`. An engineer could
 * plausibly have intentionally cleared `contractor_note` to an empty
 * string through the review form; `array_key_exists()` leaves that row
 * alone (the key IS present, its value is simply empty), matching the
 * exact-guard discipline of the 2026-09-11 analog migration. Only a row
 * with the key entirely ABSENT is treated as needing the backfill.
 *
 * `reviewed_data['cdm']` is NOT touched by this migration — that shape is
 * a list of `{role, name}` rows with no room for a contractor-wide note;
 * `contractor_note` only exists on the `generated_data['cdm_duty_holders']`
 * keyed shape.
 *
 * Running `up()` twice produces zero additional row updates on the second
 * run — the `array_key_exists()` guard makes this naturally idempotent, no
 * separate "already migrated" marker needed.
 *
 * ── REVERSIBILITY ────────────────────────────────────────────────────────
 * `down()` is a deliberate no-op, for the same reason as the 2026-09-11
 * precedent this migration's shape mirrors: the guard above cannot
 * distinguish a row THIS migration changed from a row an engineer
 * independently set `contractor_note` on by hand through the review form
 * after this migration ran. A `down()` that stripped the key back out
 * would destroy genuine post-migration or hand-authored data rather than
 * restore a prior state — worse than leaving the data alone. Documented
 * explicitly rather than silently omitted.
 *
 * @see database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
 * @see app/Services/Rams/RamsComplianceUpgradeService.php (DEFAULT_CONTRACTOR_NOTE)
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-UAT.md
 */
return new class extends Migration
{
    public function up(): void
    {
        $documentsTouched = 0;

        // Chunked — this runs against production (when a human/Plan 29-14
        // decides to run it; this migration is not invoked by this plan).
        DB::table('rams_documents')
            ->select('id', 'generated_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$documentsTouched) {
                foreach ($rows as $row) {
                    if (empty($row->generated_data)) {
                        continue;
                    }

                    $data = is_array($row->generated_data)
                        ? $row->generated_data
                        : json_decode((string) $row->generated_data, true);

                    if (! is_array($data)) {
                        continue;
                    }

                    $cdm = $data['cdm_duty_holders'] ?? null;

                    if (! is_array($cdm) || $cdm === []) {
                        continue;
                    }

                    if (array_key_exists('contractor_note', $cdm)) {
                        continue;
                    }

                    $data['cdm_duty_holders']['contractor_note'] = RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE;

                    DB::table('rams_documents')
                        ->where('id', $row->id)
                        ->update(['generated_data' => json_encode($data)]);

                    $documentsTouched++;
                }
            });

        // Auditable output for the production run.
        echo sprintf(
            "backfill_cdm_contractor_note: %d document(s) touched — "
            . "generated_data.cdm_duty_holders.contractor_note added where absent\n",
            $documentsTouched,
        );
    }

    public function down(): void
    {
        // Deliberate no-op — see class docblock "REVERSIBILITY".
    }
};
