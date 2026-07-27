<?php

namespace App\Repositories;

use App\Models\ProductTaxonomy;

/**
 * Phase 260727-wt1 Plan 01 — single access point for all catalogue reads /
 * writes. Every WorksheetClassifier query and every LearnedTaxonomyWriter
 * call goes through this repo. The classifier itself remains db-agnostic
 * (Plan 02 will inject this repo behind the WORKSHEET_TAXONOMY_DB kill
 * switch).
 *
 * Design principles:
 *  - Deterministic ordering (id ASC by default) so re-runs of the same
 *    query against the same data return the same row. Callers must not
 *    depend on insertion order beyond that.
 *  - Lookups are case-insensitive: SKU + manufacturer are upper-cased /
 *    lower-cased before comparison to match WorksheetClassifier semantics.
 *  - Soft-deleted rows are excluded automatically (SoftDeletes on the
 *    model). Plan 05's admin resource opts in with withTrashed() where
 *    it needs the historical view.
 *  - No `Cache::remember` here — first-cut correctness > premature
 *    optimisation. If Plan 02 shadow parity reveals per-request N+1s,
 *    cache lands in a follow-up.
 *
 * Bound as a singleton in AppServiceProvider so the classifier + writer
 * share the same instance per request.
 */
class ProductTaxonomyRepository
{
    /**
     * Tier 1 — exact SKU pattern match.
     *
     * The catalogue stores SKU patterns verbatim (usually upper-case). We
     * upper-case the incoming SKU before comparison so 'gsm4230px' hits
     * 'GSM4230PX'. Returns the FIRST matching row (deterministic id ASC).
     */
    public function findByExactSku(string $sku): ?ProductTaxonomy
    {
        $needle = trim($sku);
        if ($needle === '') {
            return null;
        }

        return ProductTaxonomy::query()
            ->whereRaw('UPPER(sku_pattern) = ?', [strtoupper($needle)])
            ->orderBy('id')
            ->first();
    }

    /**
     * Tier 2 — manufacturer + description keyword pair.
     *
     * Both sides are matched case-insensitively via LOWER(). The
     * description_pattern is compared as a substring — the stored pattern
     * must appear anywhere in $description for a hit. If either side is
     * empty or nothing matches, returns null.
     */
    public function findByManufacturerAndKeyword(string $manufacturer, string $description): ?ProductTaxonomy
    {
        $mfg = strtolower(trim($manufacturer));
        $desc = strtolower(trim($description));
        if ($mfg === '' || $desc === '') {
            return null;
        }

        return ProductTaxonomy::query()
            ->whereNotNull('manufacturer')
            ->whereNotNull('description_pattern')
            ->whereRaw('LOWER(manufacturer) = ?', [$mfg])
            ->whereRaw('? LIKE CONCAT(\'%\', LOWER(description_pattern), \'%\')', [$desc])
            ->orderBy('id')
            ->first();
    }

    /**
     * Tier 3 — description keyword only (no manufacturer bound).
     *
     * Matches rows where manufacturer IS NULL and sku_pattern IS NULL and
     * the row's description_pattern appears as a substring in $description.
     * These rows are the last-resort keyword heuristic ported from
     * config('worksheet_taxonomy.keyword_rules').
     */
    public function findByKeywordOnly(string $description): ?ProductTaxonomy
    {
        $desc = strtolower(trim($description));
        if ($desc === '') {
            return null;
        }

        return ProductTaxonomy::query()
            ->whereNull('manufacturer')
            ->whereNull('sku_pattern')
            ->whereNotNull('description_pattern')
            ->whereRaw('? LIKE CONCAT(\'%\', LOWER(description_pattern), \'%\')', [$desc])
            ->orderBy('id')
            ->first();
    }

    /**
     * Plan 04's write path (stubbed here for Plan 01 completeness).
     *
     * Persists a learned or admin row. Callers are responsible for supplying
     * source + provenance fields (learned_from_package_id, created_by,
     * promoted_by, promoted_at). This method does no idempotency check —
     * the seeder handles seed idempotency, and the LearnedTaxonomyWriter
     * (Plan 04) will run its own duplicate-guard before calling in.
     *
     * @param array<string, mixed> $data Fillable attributes for ProductTaxonomy.
     */
    public function learn(array $data): ProductTaxonomy
    {
        return ProductTaxonomy::create($data);
    }
}
