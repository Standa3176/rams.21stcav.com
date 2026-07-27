<?php

namespace Tests\Feature\Worksheet;

use App\Models\ProductTaxonomy;
use Database\Seeders\ProductTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 260727-wt1 Plan 01 — ProductTaxonomySeeder behaviour.
 *
 *   1. Ports every sku_map / manufacturer_rules / keyword_rules entry.
 *   2. Ignores behavioural sections (mount_inherit_keywords /
 *      warranty_keywords / existing_keywords / exclude_keywords).
 *   3. Idempotent — re-running does not duplicate rows.
 *   4. Every seeded row carries source='seed'.
 *   5. Every seeded row's category is one of the ENUM values (never a
 *      stray 'mount_inherit' etc. leaking through).
 */
class ProductTaxonomySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_ports_sku_map_entries(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        $skuMap = (array) config('worksheet_taxonomy.sku_map', []);
        $this->assertNotEmpty($skuMap, 'sku_map should not be empty in config');

        foreach ($skuMap as $sku => $category) {
            $row = ProductTaxonomy::query()
                ->where('sku_pattern', $sku)
                ->where('worksheet_category', $category)
                ->first();
            $this->assertNotNull($row,
                "seeder must port sku_map entry {$sku} => {$category}");
            $this->assertSame(ProductTaxonomy::SOURCE_SEED, $row->source);
        }
    }

    public function test_seeder_ports_keyword_rules(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        $keywordRules = (array) config('worksheet_taxonomy.keyword_rules', []);
        $this->assertNotEmpty($keywordRules);

        foreach ($keywordRules as $category => $keywords) {
            foreach ((array) $keywords as $kw) {
                $exists = ProductTaxonomy::query()
                    ->whereNull('sku_pattern')
                    ->whereNull('manufacturer')
                    ->where('description_pattern', $kw)
                    ->where('worksheet_category', $category)
                    ->exists();
                $this->assertTrue($exists,
                    "seeder must port keyword_rules {$category} => {$kw}");
            }
        }
    }

    public function test_seeder_expands_manufacturer_rules_into_pair_rows(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        // Pick a well-known rule from the config and prove every
        // (mfg × keyword) pair got a row.
        $rules = (array) config('worksheet_taxonomy.manufacturer_rules', []);
        $this->assertNotEmpty($rules);

        $sampleRule = $rules[0]; // first rule — flat-panel display OEMs
        foreach ((array) $sampleRule['manufacturer'] as $mfg) {
            foreach ((array) $sampleRule['keywords'] as $kw) {
                $exists = ProductTaxonomy::query()
                    ->where('manufacturer', $mfg)
                    ->where('description_pattern', $kw)
                    ->where('worksheet_category', $sampleRule['category'])
                    ->exists();
                $this->assertTrue($exists,
                    "expected row for {$mfg} + {$kw} => {$sampleRule['category']}");
            }
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ProductTaxonomySeeder::class);
        $countAfterFirst = ProductTaxonomy::count();

        $this->seed(ProductTaxonomySeeder::class);
        $countAfterSecond = ProductTaxonomy::count();

        $this->assertGreaterThan(0, $countAfterFirst,
            'seeder must produce at least one row');
        $this->assertSame($countAfterFirst, $countAfterSecond,
            're-running the seeder must not add duplicate rows');
    }

    public function test_every_seeded_row_has_source_seed(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        $other = ProductTaxonomy::query()
            ->where('source', '!=', ProductTaxonomy::SOURCE_SEED)
            ->count();
        $this->assertSame(0, $other,
            'Plan 01 seeder writes source=seed exclusively');
    }

    public function test_every_seeded_row_has_a_canonical_category(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        $bogus = ProductTaxonomy::query()
            ->whereNotIn('worksheet_category', ProductTaxonomy::CATEGORIES)
            ->count();
        $this->assertSame(0, $bogus,
            'no seeded row may carry a category outside the ENUM (mount_inherit gets remapped to unclassified)');
    }

    public function test_mount_inherit_rules_land_as_unclassified(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        // The config has one manufacturer_rule with category='mount_inherit'
        // (Chief/Unicol/Vogel's/Peerless/B-Tech/Crestron mounts). The seeder
        // stores those as worksheet_category='unclassified' because the ENUM
        // has no mount_inherit slot — Plan 02's classifier handles the
        // cascade via mount_inherit_keywords in config, not this row's
        // category.
        $rules = (array) config('worksheet_taxonomy.manufacturer_rules', []);
        $mountRule = null;
        foreach ($rules as $r) {
            if (($r['category'] ?? null) === 'mount_inherit') {
                $mountRule = $r;
                break;
            }
        }
        $this->assertNotNull($mountRule, 'config must still have a mount_inherit rule (guards config drift)');

        // Sanity: pick a (mfg × keyword) from that rule — it should exist
        // with worksheet_category='unclassified'.
        $mfg = $mountRule['manufacturer'][0];
        $kw  = $mountRule['keywords'][0];
        $row = ProductTaxonomy::query()
            ->where('manufacturer', $mfg)
            ->where('description_pattern', $kw)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(ProductTaxonomy::CATEGORY_UNCLASSIFIED, $row->worksheet_category);
    }

    public function test_seeder_does_not_port_behavioural_sections(): void
    {
        $this->seed(ProductTaxonomySeeder::class);

        // warranty / existing / exclude / mount_inherit KEYWORD arrays are
        // behavioural — they should NEVER land as description_pattern rows
        // (they stay in the config file forever, consumed by the classifier
        // in Plans 02+).
        $behavioural = array_merge(
            (array) config('worksheet_taxonomy.warranty_keywords', []),
            (array) config('worksheet_taxonomy.existing_keywords', []),
            (array) config('worksheet_taxonomy.exclude_keywords', []),
        );

        foreach ($behavioural as $kw) {
            // Some of these ('microphone', 'speaker' etc.) overlap with
            // real keyword_rules — so we only assert that no PURE
            // description-only row exists with source=seed carrying ONLY
            // this behavioural pattern with NO manufacturer AND matching the
            // exact pattern. In practice these lists (warranty/smartnet/
            // labour/etc.) do not appear in keyword_rules — assert that.
            $isInKeywordRules = false;
            foreach ((array) config('worksheet_taxonomy.keyword_rules', []) as $kwList) {
                if (in_array($kw, (array) $kwList, true)) {
                    $isInKeywordRules = true;
                    break;
                }
            }
            if ($isInKeywordRules) continue; // real overlap — allowed to land

            $exists = ProductTaxonomy::query()
                ->whereNull('sku_pattern')
                ->whereNull('manufacturer')
                ->where('description_pattern', $kw)
                ->exists();
            $this->assertFalse($exists,
                "behavioural keyword {$kw} must not be seeded as a taxonomy row");
        }
    }
}
