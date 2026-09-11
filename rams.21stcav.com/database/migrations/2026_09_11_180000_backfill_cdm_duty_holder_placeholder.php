<?php

use App\Services\Rams\RamsComplianceUpgradeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 29-05 Task 1 — backfill the RULE-07 CDM duty-holder placeholder
 * (`'[To be confirmed]'`) already persisted on `rams_documents` rows created
 * before Plan 29-03's `addCdmDutyHolders()` restatement. Mirrors the Phase
 * 28-07 migration's shape verbatim (see class docblock references below):
 * chunked scan, dual-column (reviewed_data + generated_data) independent
 * patching, per-surface changed-counters, auditable `echo` summary,
 * documented no-op `down()`.
 *
 * ── WHY THIS EXISTS — production counts ─────────────────────────────────
 * Measured 2026-09-11 on rams.21stcav.com (29-01-SUMMARY.md / 29-MEASUREMENT.md):
 * 46 of 54 `RamsDocument` rows (85%) carry the CDM `'[To be confirmed]'`
 * placeholder in `reviewed_data['cdm']` or `generated_data['cdm_duty_holders']`.
 * Plan 29-03's code fix only reaches a document on its next full `upgrade()`
 * pass — a "completed" document may never regenerate again, so only a
 * backfill reaches it (D-02).
 *
 * ── THE TWO SHAPES ────────────────────────────────────────────────────────
 * `generated_data['cdm_duty_holders']` — a KEYED array (RamsComplianceUpgradeService
 * ::addCdmDutyHolders()). Only `principal_designer`/`principal_contractor` are
 * ever the bare literal `'[To be confirmed]'` string under the restated
 * RULE-07 — `project_manager`/`site_supervisor` legitimately carry a
 * data-dependent placeholder reflecting genuinely missing project data and
 * are NOT touched by this migration (matches GATE-11's own scope, Plan
 * 29-03).
 *
 * `reviewed_data['cdm']` — a LIST of `{role, name}` rows (RamsController.php
 * :504-509, confirmed by 29-RESEARCH.md Finding 5). A row's free-text `name`
 * field may contain the substring `'To be confirmed'` if an engineer left it
 * at a seeded default. Matched by role (case-insensitively against
 * `'Principal Designer'`/`'Principal Contractor'`) — rows for any other role
 * (Client, Contractor, Sub-contractor, etc.) are left untouched even if they
 * happen to contain the substring, since only PD/PC are RULE-07's
 * settled-position fields.
 *
 * ── VALUE-EQUALITY / SUBSTRING GUARD (D-02) ─────────────────────────────
 * `generated_data['cdm_duty_holders']['principal_designer'|'principal_contractor']`
 * is patched only when the CURRENT value is EXACTLY the bare literal
 * `'[To be confirmed]'` — never a row an engineer has typed a real name
 * into, and never a substring match (a real name that happens to contain
 * the phrase would not equal the bare literal and is left untouched).
 *
 * `reviewed_data['cdm'][*]['name']` is patched only when the row's `role`
 * matches PD/PC AND its `name` field contains the substring `'To be
 * confirmed'` — the review form captures free text, so an exact-literal
 * guard would miss legitimate seeded-default variants; the role scope
 * (PD/PC only) is what keeps this from ever touching an engineer's real
 * typed content for any other role.
 *
 * Both columns are checked and patched independently per row, mirroring the
 * 28-07 precedent's per-column independence. Running `up()` twice produces
 * zero additional row updates on the second run — the guards above make this
 * naturally idempotent, no separate "already migrated" marker needed.
 *
 * ── REVERSIBILITY ────────────────────────────────────────────────────────
 * `down()` is a deliberate no-op, for the same reason as the Phase 28-07
 * precedent this migration's shape copies: neither guard above can
 * distinguish a row THIS migration changed from a row that already carried
 * the corrected value, or from a row an engineer independently corrected by
 * hand through the review form after this migration ran. A `down()` that
 * reverted the restated CDM wording back to the bare placeholder would
 * destroy genuine post-migration correctness rather than restore a prior
 * state — worse than leaving the data alone. Documented explicitly rather
 * than silently omitted.
 *
 * @see database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php
 * @see app/Services/Rams/RamsComplianceUpgradeService.php (DEFAULT_PRINCIPAL_DESIGNER_NOTE / DEFAULT_PRINCIPAL_CONTRACTOR_NOTE)
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-MEASUREMENT.md
 */
return new class extends Migration
{
    /**
     * The bare literal placeholder GATE-11 exists to catch. Exact-equality
     * guard target for `generated_data['cdm_duty_holders']`.
     */
    private const RAW_PLACEHOLDER = '[To be confirmed]';

    /**
     * Substring guard target for `reviewed_data['cdm'][*]['name']` free
     * text — matches RamsComplianceUpgradeService's own
     * `project_manager`/`site_supervisor` placeholder phrase without the
     * surrounding brackets, since an engineer's free-text field may embed
     * it inside a longer seeded-default sentence.
     */
    private const NAME_SUBSTRING = 'To be confirmed';

    private const PD_ROLE = 'principal designer';

    private const PC_ROLE = 'principal contractor';

    public function up(): void
    {
        $documentsTouched        = 0;
        $generatedDataDocsTouched = 0;
        $reviewedDataDocsTouched  = 0;

        // Chunked — this runs against production.
        DB::table('rams_documents')
            ->select('id', 'reviewed_data', 'generated_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (
                &$documentsTouched,
                &$generatedDataDocsTouched,
                &$reviewedDataDocsTouched,
            ) {
                foreach ($rows as $row) {
                    $update = [];
                    $rowTouchedGenerated = false;
                    $rowTouchedReviewed  = false;

                    foreach (['reviewed_data', 'generated_data'] as $column) {
                        if (empty($row->$column)) {
                            continue;
                        }

                        $data = is_array($row->$column)
                            ? $row->$column
                            : json_decode((string) $row->$column, true);

                        if (! is_array($data)) {
                            continue;
                        }

                        [$data, $generatedChanged, $reviewedChanged] = self::fixCdmPlaceholder($data);

                        if ($generatedChanged || $reviewedChanged) {
                            $update[$column] = json_encode($data);
                        }

                        $rowTouchedGenerated = $rowTouchedGenerated || $generatedChanged;
                        $rowTouchedReviewed  = $rowTouchedReviewed || $reviewedChanged;
                    }

                    if ($update === []) {
                        continue;
                    }

                    DB::table('rams_documents')->where('id', $row->id)->update($update);

                    $documentsTouched++;
                    $generatedDataDocsTouched += $rowTouchedGenerated ? 1 : 0;
                    $reviewedDataDocsTouched  += $rowTouchedReviewed ? 1 : 0;
                }
            });

        // Auditable output for the production run.
        echo sprintf(
            "backfill_cdm_duty_holder_placeholder: %d document(s) touched — "
            . "%d generated_data.cdm_duty_holders PD/PC replace, "
            . "%d reviewed_data.cdm PD/PC row replace\n",
            $documentsTouched,
            $generatedDataDocsTouched,
            $reviewedDataDocsTouched,
        );
    }

    public function down(): void
    {
        // Deliberate no-op — see class docblock "REVERSIBILITY".
    }

    /**
     * Patches both CDM shapes within one JSON column's decoded array.
     * Independently counted per this plan's acceptance criteria.
     *
     * @return array{0: array, 1: bool, 2: bool} [$data, $generatedChanged, $reviewedChanged]
     */
    private static function fixCdmPlaceholder(array $data): array
    {
        $generatedChanged = false;
        $reviewedChanged  = false;

        // generated_data['cdm_duty_holders'] — keyed shape, exact-literal guard.
        if (isset($data['cdm_duty_holders']) && is_array($data['cdm_duty_holders'])) {
            foreach ([
                'principal_designer'   => RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE,
                'principal_contractor' => RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
            ] as $field => $replacement) {
                if (($data['cdm_duty_holders'][$field] ?? null) === self::RAW_PLACEHOLDER) {
                    $data['cdm_duty_holders'][$field] = $replacement;
                    $generatedChanged = true;
                }
            }
        }

        // reviewed_data['cdm'] — list-of-rows shape, substring guard scoped
        // to PD/PC roles only (case-insensitive role match).
        if (isset($data['cdm']) && is_array($data['cdm'])) {
            foreach ($data['cdm'] as $i => $cdmRow) {
                if (! is_array($cdmRow)) {
                    continue;
                }

                $role = strtolower(trim((string) ($cdmRow['role'] ?? '')));
                $name = (string) ($cdmRow['name'] ?? '');

                if ($role === self::PD_ROLE && str_contains($name, self::NAME_SUBSTRING)) {
                    $data['cdm'][$i]['name'] = RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE;
                    $reviewedChanged = true;
                } elseif ($role === self::PC_ROLE && str_contains($name, self::NAME_SUBSTRING)) {
                    $data['cdm'][$i]['name'] = RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE;
                    $reviewedChanged = true;
                }
            }
        }

        return [$data, $generatedChanged, $reviewedChanged];
    }
};
