<?php

namespace Database\Seeders;

use App\Models\ProductTaxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Phase 260727-wt1 Plan 01 — port config/worksheet_taxonomy.php rows into
 * the DB catalogue.
 *
 * Ported:
 *   - sku_map              → one row per SKU with sku_pattern set.
 *   - manufacturer_rules   → row per (manufacturer × keyword) pair. A rule
 *                            with N mfgs and M keywords expands to N * M
 *                            rows. Rules whose category is 'mount_inherit'
 *                            are ALSO ported so Plan 02 can preserve the
 *                            "mount → parent inherit" behaviour; they land
 *                            with worksheet_category = 'unclassified'
 *                            (the ENUM has no 'mount_inherit' slot — this
 *                            keeps the DB shape clean; Plan 02's classifier
 *                            still applies the inherit cascade based on
 *                            keyword-only match, not on this row's category).
 *   - keyword_rules        → one row per keyword with only
 *                            description_pattern set.
 *
 * NOT ported (behavioural — stay in config forever):
 *   - mount_inherit_keywords
 *   - warranty_keywords
 *   - existing_keywords
 *   - exclude_keywords
 *
 * Idempotency:
 *   updateOrCreate on the natural key (sku_pattern, manufacturer,
 *   description_pattern, worksheet_category). Re-running is a no-op after
 *   the first run — the same input config always produces the same rows.
 *
 * NOT registered in DatabaseSeeder yet — Plan 05 will wire it into the
 * standard seed flow once the kill switch is flipped in prod. For now,
 * invoke via `php artisan db:seed --class=Database\\Seeders\\ProductTaxonomySeeder`.
 */
class ProductTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $taxonomy = (array) config('worksheet_taxonomy', []);
        if ($taxonomy === []) {
            Log::warning('ProductTaxonomySeeder: config/worksheet_taxonomy is empty — nothing to seed.');
            return;
        }

        $seeded = 0;
        $seeded += $this->portSkuMap((array) ($taxonomy['sku_map'] ?? []));
        $seeded += $this->portManufacturerRules((array) ($taxonomy['manufacturer_rules'] ?? []));
        $seeded += $this->portKeywordRules((array) ($taxonomy['keyword_rules'] ?? []));

        Log::info('ProductTaxonomySeeder: catalogue populated', [
            'rows_touched'  => $seeded,
            'total_seed'    => ProductTaxonomy::query()->where('source', ProductTaxonomy::SOURCE_SEED)->count(),
        ]);
    }

    /**
     * sku_map: [ 'SKU-123' => 'audio', ... ] → one row per pair.
     */
    private function portSkuMap(array $skuMap): int
    {
        $touched = 0;
        foreach ($skuMap as $sku => $category) {
            $sku = trim((string) $sku);
            $category = trim((string) $category);
            if ($sku === '' || ! $this->isCanonicalCategory($category)) {
                continue;
            }

            ProductTaxonomy::query()->updateOrCreate(
                [
                    'sku_pattern'         => $sku,
                    'manufacturer'        => null,
                    'description_pattern' => null,
                    'worksheet_category'  => $category,
                ],
                [
                    'source' => ProductTaxonomy::SOURCE_SEED,
                ],
            );
            $touched++;
        }
        return $touched;
    }

    /**
     * manufacturer_rules: [ ['manufacturer'=>[...], 'keywords'=>[...], 'category'=>'...'], ... ]
     * Flatten to one row per (manufacturer × keyword). Rules with no
     * keywords expand to one row per manufacturer with description_pattern
     * left NULL (Tier 2 manufacturer-only match).
     *
     * mount_inherit rules land with worksheet_category = 'unclassified' — the
     * ENUM has no 'mount_inherit' slot and adding one would leak an internal
     * classifier concept into the catalogue schema. Plan 02's classifier keeps
     * the mount-inherit cascade driven by mount_inherit_keywords in config,
     * not by these rows' category.
     */
    private function portManufacturerRules(array $rules): int
    {
        $touched = 0;
        foreach ($rules as $rule) {
            $manufacturers = (array) ($rule['manufacturer'] ?? []);
            $keywords      = (array) ($rule['keywords']     ?? []);
            $category      = trim((string) ($rule['category'] ?? ''));

            if ($manufacturers === [] || $category === '') {
                continue;
            }

            $storedCategory = $this->isCanonicalCategory($category)
                ? $category
                : ProductTaxonomy::CATEGORY_UNCLASSIFIED;

            if ($keywords === []) {
                // Manufacturer-only rule → one row per mfg, desc NULL.
                foreach ($manufacturers as $mfg) {
                    $mfg = trim((string) $mfg);
                    if ($mfg === '') continue;
                    ProductTaxonomy::query()->updateOrCreate(
                        [
                            'sku_pattern'         => null,
                            'manufacturer'        => $mfg,
                            'description_pattern' => null,
                            'worksheet_category'  => $storedCategory,
                        ],
                        [
                            'source' => ProductTaxonomy::SOURCE_SEED,
                        ],
                    );
                    $touched++;
                }
                continue;
            }

            foreach ($manufacturers as $mfg) {
                $mfg = trim((string) $mfg);
                if ($mfg === '') continue;
                foreach ($keywords as $kw) {
                    $kw = trim((string) $kw);
                    if ($kw === '') continue;
                    ProductTaxonomy::query()->updateOrCreate(
                        [
                            'sku_pattern'         => null,
                            'manufacturer'        => $mfg,
                            'description_pattern' => $kw,
                            'worksheet_category'  => $storedCategory,
                        ],
                        [
                            'source' => ProductTaxonomy::SOURCE_SEED,
                        ],
                    );
                    $touched++;
                }
            }
        }
        return $touched;
    }

    /**
     * keyword_rules: [ 'display' => ['videowall', ...], ... ]
     * → one row per keyword, description_pattern only. Tier 3 heuristic.
     */
    private function portKeywordRules(array $rules): int
    {
        $touched = 0;
        foreach ($rules as $category => $keywords) {
            $category = trim((string) $category);
            if (! $this->isCanonicalCategory($category)) {
                continue;
            }
            foreach ((array) $keywords as $kw) {
                $kw = trim((string) $kw);
                if ($kw === '') continue;
                ProductTaxonomy::query()->updateOrCreate(
                    [
                        'sku_pattern'         => null,
                        'manufacturer'        => null,
                        'description_pattern' => $kw,
                        'worksheet_category'  => $category,
                    ],
                    [
                        'source' => ProductTaxonomy::SOURCE_SEED,
                    ],
                );
                $touched++;
            }
        }
        return $touched;
    }

    private function isCanonicalCategory(string $category): bool
    {
        return in_array($category, ProductTaxonomy::CATEGORIES, true);
    }
}
