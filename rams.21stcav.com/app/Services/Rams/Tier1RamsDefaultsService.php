<?php

namespace App\Services\Rams;

/**
 * Tier 1 RAMS Defaults Service.
 *
 * Fallback layer that injects industry-standard AV baseline safety content
 * into the RAMS $data array whenever the corresponding reviewed / generated
 * value is empty. Engineer-supplied values ALWAYS win.
 *
 * This is the safety-net that stops a blank / skeleton RAMS from ever going
 * out the door. When an engineer skips the review form or fills in only the
 * mandatory fields, the resulting PDF still meets tier-1 UK CDM 2015 /
 * HSG 65 / AVIXA F502.01 competence expectations for AV install works.
 *
 * Wired into App\Services\RamsBuilderService at TWO injection points
 * (D-01):
 *   1. runFromReview() — immediately after RamsComplianceUpgradeService::upgrade($data)
 *   2. runPipeline()   — immediately after RamsComplianceUpgradeService::upgrade($data)
 *
 * Both paths funnel into $record->update(['generated_data' => $data, ...])
 * so the defaults land once and persist for both PDF + DOCX pipelines.
 *
 * Config:
 *   config/rams_tier1.php  (safety-critical warning header at top)
 *
 * Master kill-switch:
 *   env('RAMS_TIER1_DEFAULTS', true)
 *   When false, injectDefaultsIntoRamsData() returns $data unchanged.
 *
 * Non-clobbering behaviour:
 *   - standards_references  → set only when empty / missing on $data
 *   - coshh_baseline        → ALWAYS set (new key, doesn't collide with
 *                              engineer-added $data['coshh'] which stays
 *                              independent).
 *
 * Phase 26 (Hazard Library Structural Inversion): this service no longer
 * touches $data['hazards'] at all. Hazard population is handled entirely,
 * upstream of this method, by RiskTemplateResolverService and the tiered
 * include-when resolver it delegates to, evaluating each hazard_templates
 * row's include_when condition. An empty $data['hazards'] array reaching
 * this method now stays empty.
 *
 * @see config/rams_tier1.php
 * @see app/Services/RamsBuilderService.php
 * @see tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php
 */
class Tier1RamsDefaultsService
{
    /**
     * Inject tier-1 safety-critical defaults into a RAMS $data array.
     *
     * Behaviour (fallback-only, engineer wins):
     *   - If `config('rams_tier1.enabled')` is false → return $data unchanged.
     *   - If `$data['standards_references']` is empty or unset → set from config.
     *   - Always set `$data['coshh_baseline']` from config (non-clobbering,
     *     the existing `$data['coshh']` engineer-additions key stays
     *     independent).
     *
     * $data['hazards'] is never touched here (Phase 26) — see class docblock.
     *
     * @param  array  $data  RAMS data bag (typically $record->generated_data).
     * @return array  Mutated data bag with tier-1 defaults folded in.
     */
    public function injectDefaultsIntoRamsData(array $data): array
    {
        if (! (bool) config('rams_tier1.enabled', true)) {
            return $data;
        }

        // ── Standards & Guidance references (Section 3 table) ────────────
        if (empty($data['standards_references']) || ! is_array($data['standards_references'])) {
            $data['standards_references'] = (array) config('rams_tier1.standards_references', []);
        }

        // ── COSHH baseline inventory (COSHH table, new key) ──────────────
        // Non-clobbering: this is a new key that doesn't collide with the
        // existing $data['coshh'] engineer-additions bucket. The PDF renders
        // the baseline table AND (if present) the engineer additions below.
        $data['coshh_baseline'] = (array) config('rams_tier1.coshh_products', []);

        return $data;
    }
}
