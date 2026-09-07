<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 28 follow-up (RULE-01 / GATE-06) — clears the residual FFP2 control
 * lines that `2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet`
 * deliberately declined to touch.
 *
 * ── Why a second migration was needed ──────────────────────────────────────
 * The first backfill resolves replacement control text through the current
 * hazard library, keyed by lowercased hazard name. Production diagnosis
 * (2026-09-07) found the residual FFP2 lines sit under three LEGACY hazard
 * names that are not in `hazard_templates` at all:
 *
 *   31x  "Dust from Drilling & Cutting"                                   (note the ampersand)
 *   14x  "Working in Ceiling Voids"
 *    3x  "Dust generation from drilling and cutting — respiratory and eye hazard"
 *
 * The lookup missed, so the first migration failed closed and left them
 * alone. **That was correct behaviour, not a bug** — it refused to guess
 * replacement text for a hazard it could not identify. Post-run scan:
 * `ppe_ffp2=0 controls_ffp2=34 hazard_NAME_confined=0`.
 *
 * ── Why this migration is safe without the library ─────────────────────────
 * The residual lines are not engineer prose. There are exactly TWO distinct
 * strings across all 48 occurrences, and both are the app's OWN hardcoded
 * output from `RamsComplianceUpgradeService` as it stood before Plan 28-04
 * corrected it in source. So the replacement is not a judgement call — it is
 * whatever that source line says today:
 *
 *   RamsComplianceUpgradeService.php:760  'Dust mask (FFP3) worn when accessing ceiling voids'
 *   RamsComplianceUpgradeService.php:809  'FFP3 dust mask and safety glasses worn during all drilling and cutting'
 *
 * This migration therefore does a LITERAL, exact-match replace of those two
 * strings and nothing else. No library resolution, no fuzzy matching, no
 * substring rewriting of surrounding text. A control line that is not one of
 * these two exact strings is left untouched — the same fail-closed posture as
 * the first migration, and as `ControlTextRuleViolations` itself.
 *
 * All residual occurrences were found in `generated_data` only, but both
 * columns are processed for safety and so a re-run is provably a no-op.
 *
 * ── down() ────────────────────────────────────────────────────────────────
 * Deliberately NOT reversible. Reversing would reintroduce FFP2 into live
 * safety documents, which is the defect RULE-01 exists to eliminate, and the
 * original per-document text is not recoverable from here. `down()` throws
 * rather than silently doing nothing, so a rollback attempt is loud.
 */
return new class extends Migration
{
    /**
     * Exact stored string => exact replacement. Both replacements are copied
     * verbatim from the post-Plan-28-04 source lines cited in the docblock,
     * so migrated data matches byte-for-byte what the code now generates.
     */
    private const REPLACEMENTS = [
        'FFP2 dust mask and safety glasses worn during all drilling and cutting'
            => 'FFP3 dust mask and safety glasses worn during all drilling and cutting',
        'Dust mask (FFP2) worn when accessing ceiling voids'
            => 'Dust mask (FFP3) worn when accessing ceiling voids',
    ];

    public function up(): void
    {
        $documentsTouched = 0;
        $linesReplaced = 0;

        // Chunked — this runs against production.
        DB::table('rams_documents')
            ->select('id', 'reviewed_data', 'generated_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$documentsTouched, &$linesReplaced) {
                foreach ($rows as $row) {
                    $update = [];
                    $rowTouched = false;

                    foreach (['reviewed_data', 'generated_data'] as $column) {
                        if (empty($row->$column)) {
                            continue;
                        }

                        $data = json_decode((string) $row->$column, true);
                        if (! is_array($data) || empty($data['hazards']) || ! is_array($data['hazards'])) {
                            continue;
                        }

                        $columnChanged = false;

                        foreach ($data['hazards'] as $hIndex => $hazard) {
                            if (! is_array($hazard)) {
                                continue;
                            }

                            // Mirror the first migration's field-name handling:
                            // reviewedToRisk()'s shape uses 'controls'; some
                            // older payloads use 'control_measures'.
                            foreach (['controls', 'control_measures'] as $field) {
                                if (! array_key_exists($field, $hazard) || ! is_array($hazard[$field])) {
                                    continue;
                                }

                                foreach ($hazard[$field] as $cIndex => $control) {
                                    if (! is_string($control)) {
                                        continue;
                                    }

                                    $trimmed = trim($control);

                                    // Exact match only. Anything else is left
                                    // alone — fail closed, never guess.
                                    if (! array_key_exists($trimmed, self::REPLACEMENTS)) {
                                        continue;
                                    }

                                    $data['hazards'][$hIndex][$field][$cIndex] = self::REPLACEMENTS[$trimmed];
                                    $columnChanged = true;
                                    $linesReplaced++;
                                }
                            }
                        }

                        if ($columnChanged) {
                            $update[$column] = json_encode($data);
                            $rowTouched = true;
                        }
                    }

                    if ($update !== []) {
                        DB::table('rams_documents')->where('id', $row->id)->update($update);
                    }

                    $documentsTouched += $rowTouched ? 1 : 0;
                }
            });

        echo sprintf(
            "backfill_residual_ffp2_control_lines: %d document(s) touched, %d control line(s) replaced\n",
            $documentsTouched,
            $linesReplaced,
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Irreversible: reversing would reintroduce FFP2 into live safety documents '
            . '(RULE-01/GATE-06). The original per-document text is not recoverable from here. '
            . 'To disable the gate instead, set RAMS_PPE_CEILING_ELECTRICAL_GATE=false and run '
            . 'php artisan config:clear.'
        );
    }
};
