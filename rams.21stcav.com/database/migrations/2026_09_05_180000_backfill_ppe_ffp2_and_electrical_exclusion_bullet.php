<?php

use App\Models\HazardTemplate;
use App\Services\Rams\ControlTextRuleViolations;
use App\Services\Rams\LegacyHazardNameFoldMap;
use App\Services\Rams\PpeVocabularyFoldMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 28-07 Task 2 — backfill four already-persisted RULE-01/RULE-09/RULE-06
 * surfaces on `rams_documents` that do not self-heal (or self-heal only on a
 * full regeneration that a "completed" document may never receive again):
 *
 *   (a) `ppe` / `ppe_matrix[*]['ppe']` FFP2        -> fold via PpeVocabularyFoldMap (Plan 28-03)
 *   (b) hazard `controls`/`control_measures` FFP2  -> replace with current library text,
 *                                                      classified via ControlTextRuleViolations
 *                                                      (Plan 27-08's tier-1 shape)
 *   (c) hazard NAME "Confined Spaces"               -> "Restricted access and ceiling void working"
 *   (d) `exclusions` (already isset, RULE-09)        -> append the electrical-boundary bullet
 *
 * Runs over BOTH `reviewed_data` and `generated_data` — 28-07-MEASUREMENT.md
 * found the 99 stale hazard-name occurrences span both columns, and the Save
 * Review path (`RamsController::updateAndDownload()`) reads/writes
 * `generated_data` directly, bypassing `reviewedToRisk()`'s tier-1
 * correction and the fold map entirely. Patching `reviewed_data` alone would
 * leave Save Review broken for any document already reviewed once.
 *
 * ── WHY THIS EXISTS — production counts ─────────────────────────────────
 * Measured 2026-09-06 on rams.21stcav.com, logged in as `stcav`, read-only
 * (28-07-MEASUREMENT.md):
 *
 *   | Surface                        | Docs    | Self-heals?                |
 *   |---------------------------------|--------:|------------------------------|
 *   | FFP2 in `ppe` / `ppe_matrix`    | 54 / 54 | never                       |
 *   | FFP2 in hazard `controls[]`     | 36      | only on full regeneration  |
 *   | Hazard NAME "Confined Spaces"   | 52 (99) | only on full regeneration  |
 *   | `exclusions` already isset      | 32      | never                       |
 *
 * Every document currently in production (54/54, 100%) carries at least the
 * PPE defect, which is why this migration is unconditional rather than
 * gated behind a further re-measurement.
 *
 * ── DELIBERATE NARROWING ─────────────────────────────────────────────────
 * Surface (b)'s library-controls lookup mirrors only the FIRST TWO tiers of
 * `HazardLibraryService::fuzzyMatch()` — the `LegacyHazardNameFoldMap` fold,
 * then an exact case-insensitive name match against global
 * `hazard_templates` rows. The substring and shared-significant-word fuzzy
 * tiers are deliberately NOT reimplemented here: a batch script guessing a
 * fuzzy hazard-name match against live safety documents is a materially
 * riskier action than the interactive review-form flow those tiers exist
 * for. A hazard whose name neither folds nor exactly matches is left
 * untouched — fail closed, matching `ControlTextRuleViolations`' own
 * "prefer the false negative" philosophy (T-27-08-01).
 *
 * Surface (b) only acts on the `ffp2` violation key. The measured
 * production count for an AFFIRMATIVE confined-space claim in control TEXT
 * is 0 (28-07-MEASUREMENT.md Pass 2) — this migration does not invent
 * behaviour for an unmeasured case. That surface remains covered by Plan
 * 28-01's detector on the hazard's own next full regeneration.
 *
 * Surface (c) is a deterministic single-string replace only. Exactly one
 * distinct legacy string exists in production (28-07-MEASUREMENT.md Pass
 * 3) — no fuzzy matching, no invented target. No other legacy hazard name
 * is renamed by this migration (28-02-PLAN.md's no-title-backfill decision
 * stands for every OTHER legacy name).
 *
 * ── REVERSIBILITY ────────────────────────────────────────────────────────
 * `down()` is a deliberate no-op, for the same reason as the Plan 27-08
 * precedent this migration's shape copies: none of the four checks above
 * can distinguish a row THIS migration changed from a row that already
 * carried the corrected value (an engineer could, in principle, have
 * corrected any of these four surfaces by hand through the review form
 * between this migration running and any later rollback). A `down()` that
 * reverted FFP3 back to FFP2, stripped the RULE-09 bullet, or re-inserted
 * the legacy "Confined Spaces" name would destroy genuine post-migration
 * correctness rather than restore a prior state — worse than leaving the
 * data alone. Documented explicitly rather than silently omitted.
 */
return new class extends Migration
{
    private const LEGACY_CONFINED_SPACE_NAME = 'Confined Spaces';

    private const CANONICAL_CONFINED_SPACE_NAME = 'Restricted access and ceiling void working';

    /**
     * Copied verbatim from RamsDisplayPatchService.php's `exclusions`
     * default (Plan 28-05, RULE-09) — do not re-paraphrase.
     */
    private const RULE_09_BULLET = 'Electrical scope terminates at the existing socket outlet or client data outlet — no alteration to the fixed electrical installation and no live working under any circumstances.';

    public function up(): void
    {
        $documentsTouched      = 0;
        $ppeDocsTouched        = 0;
        $controlsDocsTouched   = 0;
        $nameDocsTouched       = 0;
        $exclusionsDocsTouched = 0;

        // Preloaded once — a small, fixed-size global hazard library, not
        // re-queried per row/per chunk. Keyed by lowercase/trimmed name so
        // lookups mirror HazardLibraryService::fuzzyMatch()'s tier-1 (fold)
        // + tier-2 (exact) steps without touching the DB inside the loop.
        $libraryControlsByName = HazardTemplate::query()
            ->where('is_global', true)
            ->get(['name', 'controls'])
            ->reduce(function (array $carry, HazardTemplate $tpl) {
                $carry[strtolower(trim((string) $tpl->name))] = array_map('strval', (array) $tpl->controls);

                return $carry;
            }, []);

        // Chunked — this runs against production.
        DB::table('rams_documents')
            ->select('id', 'reviewed_data', 'generated_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (
                &$documentsTouched,
                &$ppeDocsTouched,
                &$controlsDocsTouched,
                &$nameDocsTouched,
                &$exclusionsDocsTouched,
                $libraryControlsByName,
            ) {
                foreach ($rows as $row) {
                    $update = [];
                    $rowTouchedPpe = false;
                    $rowTouchedControls = false;
                    $rowTouchedName = false;
                    $rowTouchedExclusions = false;

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

                        $columnChanged = false;

                        [$data, $ppeChanged] = self::fixPpe($data);
                        $columnChanged = $columnChanged || $ppeChanged;
                        $rowTouchedPpe = $rowTouchedPpe || $ppeChanged;

                        [$data, $controlsChanged, $nameChanged] = self::fixHazards($data, $libraryControlsByName);
                        $columnChanged = $columnChanged || $controlsChanged || $nameChanged;
                        $rowTouchedControls = $rowTouchedControls || $controlsChanged;
                        $rowTouchedName = $rowTouchedName || $nameChanged;

                        [$data, $exclusionsChanged] = self::fixExclusions($data);
                        $columnChanged = $columnChanged || $exclusionsChanged;
                        $rowTouchedExclusions = $rowTouchedExclusions || $exclusionsChanged;

                        if ($columnChanged) {
                            $update[$column] = json_encode($data);
                        }
                    }

                    if ($update === []) {
                        continue;
                    }

                    DB::table('rams_documents')->where('id', $row->id)->update($update);

                    $documentsTouched++;
                    $ppeDocsTouched        += $rowTouchedPpe ? 1 : 0;
                    $controlsDocsTouched   += $rowTouchedControls ? 1 : 0;
                    $nameDocsTouched       += $rowTouchedName ? 1 : 0;
                    $exclusionsDocsTouched += $rowTouchedExclusions ? 1 : 0;
                }
            });

        // Auditable output for the production run.
        echo sprintf(
            "backfill_ppe_ffp2_and_electrical_exclusion: %d document(s) touched — "
            . "%d ppe/ppe_matrix fold, %d hazard-controls FFP2 replace, "
            . "%d hazard-name Confined-Spaces replace, %d exclusions bullet append\n",
            $documentsTouched,
            $ppeDocsTouched,
            $controlsDocsTouched,
            $nameDocsTouched,
            $exclusionsDocsTouched,
        );
    }

    public function down(): void
    {
        // Deliberate no-op — see class docblock "REVERSIBILITY".
    }

    /**
     * Surface (a) — fold every element of `ppe` and every
     * `ppe_matrix[*]['ppe']` through PpeVocabularyFoldMap. A string replace
     * is naturally idempotent; no existing-value guard needed.
     *
     * @return array{0: array, 1: bool}
     */
    private static function fixPpe(array $data): array
    {
        $changed = false;

        if (isset($data['ppe']) && is_array($data['ppe'])) {
            $original = array_map('strval', $data['ppe']);
            $folded   = PpeVocabularyFoldMap::canonicalAll($original);

            if ($folded !== $original) {
                $data['ppe'] = $folded;
                $changed = true;
            }
        }

        if (isset($data['ppe_matrix']) && is_array($data['ppe_matrix'])) {
            foreach ($data['ppe_matrix'] as $i => $row) {
                if (! is_array($row) || ! isset($row['ppe']) || ! is_array($row['ppe'])) {
                    continue;
                }

                $original = array_map('strval', $row['ppe']);
                $folded   = PpeVocabularyFoldMap::canonicalAll($original);

                if ($folded !== $original) {
                    $data['ppe_matrix'][$i]['ppe'] = $folded;
                    $changed = true;
                }
            }
        }

        return [$data, $changed];
    }

    /**
     * Surfaces (b) and (c) — per hazard row: deterministic legacy-name
     * replace, then FFP2-controls replace via the current library text.
     * Independently counted, per this plan's acceptance criteria.
     *
     * @param  array<string, array<int, string>>  $libraryControlsByName
     * @return array{0: array, 1: bool, 2: bool}
     */
    private static function fixHazards(array $data, array $libraryControlsByName): array
    {
        $controlsChanged = false;
        $namesChanged = false;

        if (! isset($data['hazards']) || ! is_array($data['hazards'])) {
            return [$data, $controlsChanged, $namesChanged];
        }

        foreach ($data['hazards'] as $i => $hazard) {
            if (! is_array($hazard)) {
                continue;
            }

            $name = (string) ($hazard['hazard'] ?? '');

            // (c) — deterministic single-string replace. Exactly one
            // distinct legacy string exists in production
            // (28-07-MEASUREMENT.md Pass 3) — no fuzzy matching, no
            // invented target.
            if (trim($name) === self::LEGACY_CONFINED_SPACE_NAME) {
                $data['hazards'][$i]['hazard'] = self::CANONICAL_CONFINED_SPACE_NAME;
                $name = self::CANONICAL_CONFINED_SPACE_NAME;
                $namesChanged = true;
            }

            // (b) — reviewed_data uses 'control_measures'; generated_data
            // (reviewedToRisk()'s output shape) uses 'controls'. Check
            // whichever key this row actually carries.
            $controlsField = null;
            if (array_key_exists('controls', $hazard) && is_array($hazard['controls'])) {
                $controlsField = 'controls';
            } elseif (array_key_exists('control_measures', $hazard) && is_array($hazard['control_measures'])) {
                $controlsField = 'control_measures';
            }

            if ($controlsField === null) {
                continue;
            }

            $controls = array_map('strval', $hazard[$controlsField]);

            // Classification only — never a second, independently-written
            // FFP2 phrase list (ControlTextRuleViolations is the single
            // choke point; see its own class docblock).
            $violations = ControlTextRuleViolations::detectAll($controls);

            if (! in_array('ffp2', $violations, true)) {
                continue;
            }

            // Fold-then-exact lookup only — see class docblock "DELIBERATE
            // NARROWING". A miss leaves this hazard's controls untouched.
            $lookupName = LegacyHazardNameFoldMap::canonicalName($name) ?? $name;
            $libraryControls = $libraryControlsByName[strtolower(trim($lookupName))] ?? null;

            if ($libraryControls === null || $libraryControls === $controls) {
                continue;
            }

            $data['hazards'][$i][$controlsField] = $libraryControls;
            $controlsChanged = true;
        }

        return [$data, $controlsChanged, $namesChanged];
    }

    /**
     * Surface (d) — append RULE-09's electrical-boundary bullet to every
     * already-`isset` `exclusions` array that doesn't already carry it.
     * Never touches a document whose `exclusions` key is unset — that
     * document still gets the bullet for free from
     * RamsDisplayPatchService's `!isset` seed on its next render.
     *
     * @return array{0: array, 1: bool}
     */
    private static function fixExclusions(array $data): array
    {
        if (! isset($data['exclusions']) || ! is_array($data['exclusions'])) {
            return [$data, false];
        }

        if (in_array(self::RULE_09_BULLET, $data['exclusions'], true)) {
            return [$data, false];
        }

        $data['exclusions'][] = self::RULE_09_BULLET;

        return [$data, true];
    }
};
