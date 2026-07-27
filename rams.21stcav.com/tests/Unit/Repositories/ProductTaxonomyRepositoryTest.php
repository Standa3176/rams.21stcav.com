<?php

namespace Tests\Unit\Repositories;

use App\Models\ProductTaxonomy;
use App\Repositories\ProductTaxonomyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 260727-wt1 Plan 01 — ProductTaxonomyRepository finder + writer
 * behaviour.
 *
 * Plan 02's classifier depends on these three finders returning the right
 * seeded row for the right shape of input and null for everything else —
 * this suite locks that contract in.
 */
class ProductTaxonomyRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ProductTaxonomyRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(ProductTaxonomyRepository::class);

        // Fixtures — three shapes of catalogue row, one per finder.
        ProductTaxonomy::create([
            'sku_pattern'        => 'GSM4230PX',
            'worksheet_category' => ProductTaxonomy::CATEGORY_NETWORK,
            'source'             => ProductTaxonomy::SOURCE_SEED,
        ]);
        ProductTaxonomy::create([
            'manufacturer'        => 'shure',
            'description_pattern' => 'mxa',
            'worksheet_category'  => ProductTaxonomy::CATEGORY_AUDIO,
            'source'              => ProductTaxonomy::SOURCE_SEED,
        ]);
        ProductTaxonomy::create([
            'description_pattern' => 'videowall',
            'worksheet_category'  => ProductTaxonomy::CATEGORY_DISPLAY,
            'source'              => ProductTaxonomy::SOURCE_SEED,
        ]);
    }

    public function test_singleton_binding_returns_shared_instance(): void
    {
        $a = app(ProductTaxonomyRepository::class);
        $b = app(ProductTaxonomyRepository::class);
        $this->assertSame($a, $b, 'repository must be bound as a singleton');
    }

    // ── findByExactSku ────────────────────────────────────────────────────────

    public function test_find_by_exact_sku_hits_on_exact_case(): void
    {
        $row = $this->repo->findByExactSku('GSM4230PX');
        $this->assertNotNull($row);
        $this->assertSame(ProductTaxonomy::CATEGORY_NETWORK, $row->worksheet_category);
    }

    public function test_find_by_exact_sku_is_case_insensitive(): void
    {
        $row = $this->repo->findByExactSku('gsm4230px');
        $this->assertNotNull($row);
        $this->assertSame(ProductTaxonomy::CATEGORY_NETWORK, $row->worksheet_category);
    }

    public function test_find_by_exact_sku_returns_null_for_unknown(): void
    {
        $this->assertNull($this->repo->findByExactSku('DOES-NOT-EXIST-999'));
    }

    public function test_find_by_exact_sku_returns_null_for_empty_input(): void
    {
        $this->assertNull($this->repo->findByExactSku(''));
        $this->assertNull($this->repo->findByExactSku('   '));
    }

    // ── findByManufacturerAndKeyword ─────────────────────────────────────────

    public function test_find_by_manufacturer_and_keyword_hits_on_substring(): void
    {
        $row = $this->repo->findByManufacturerAndKeyword('Shure', 'Shure MXA710 ceiling array microphone');
        $this->assertNotNull($row);
        $this->assertSame(ProductTaxonomy::CATEGORY_AUDIO, $row->worksheet_category);
    }

    public function test_find_by_manufacturer_and_keyword_requires_both_sides(): void
    {
        // Manufacturer matches but keyword absent from description → null.
        $this->assertNull($this->repo->findByManufacturerAndKeyword('Shure', 'random text no keyword'));
        // Empty inputs.
        $this->assertNull($this->repo->findByManufacturerAndKeyword('', 'mxa'));
        $this->assertNull($this->repo->findByManufacturerAndKeyword('Shure', ''));
    }

    public function test_find_by_manufacturer_and_keyword_never_returns_keyword_only_row(): void
    {
        // The 'videowall' keyword-only row must NOT be returned by the
        // mfg+keyword finder even if the description contains it.
        $this->assertNull(
            $this->repo->findByManufacturerAndKeyword('SomeMfg', 'huge videowall installation'),
        );
    }

    // ── findByKeywordOnly ─────────────────────────────────────────────────────

    public function test_find_by_keyword_only_hits_on_substring(): void
    {
        $row = $this->repo->findByKeywordOnly('Samsung 4x4 videowall panel');
        $this->assertNotNull($row);
        $this->assertSame(ProductTaxonomy::CATEGORY_DISPLAY, $row->worksheet_category);
    }

    public function test_find_by_keyword_only_returns_null_for_unknown(): void
    {
        $this->assertNull($this->repo->findByKeywordOnly('a completely unrecognised widget'));
    }

    public function test_find_by_keyword_only_ignores_manufacturer_scoped_rows(): void
    {
        // 'mxa' is only stored under a manufacturer-scoped row — the
        // keyword-only finder must NOT return it.
        $this->assertNull($this->repo->findByKeywordOnly('some mxa device'));
    }

    public function test_find_by_keyword_only_returns_null_for_empty_input(): void
    {
        $this->assertNull($this->repo->findByKeywordOnly(''));
        $this->assertNull($this->repo->findByKeywordOnly('   '));
    }

    // ── learn ────────────────────────────────────────────────────────────────

    public function test_learn_persists_row(): void
    {
        $before = ProductTaxonomy::count();
        $row = $this->repo->learn([
            'sku_pattern'        => 'NOVEL-SKU-42',
            'worksheet_category' => ProductTaxonomy::CATEGORY_CONTROL,
            'source'             => ProductTaxonomy::SOURCE_LEARNED,
        ]);

        $this->assertNotNull($row->id);
        $this->assertSame($before + 1, ProductTaxonomy::count());
        $this->assertSame(ProductTaxonomy::SOURCE_LEARNED, $row->fresh()->source);
    }

    // ── Soft-delete + finder interaction ─────────────────────────────────────

    public function test_finders_ignore_soft_deleted_rows(): void
    {
        ProductTaxonomy::where('sku_pattern', 'GSM4230PX')->delete();
        $this->assertNull($this->repo->findByExactSku('GSM4230PX'),
            'soft-deleted rows must not surface via the finders (Plan 05 promote/delete flow depends on this)');
    }
}
