<?php

namespace Tests\Unit\Models;

use App\Models\ProductTaxonomy;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 260727-wt1 Plan 01 — ProductTaxonomy model construction, casts,
 * enums, soft-deletes, relationships.
 *
 * Behavioural tests for the classifier + writer live in later plans; this
 * suite only proves the model itself hangs together.
 */
class ProductTaxonomyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_persist_minimal_seed_row(): void
    {
        $row = ProductTaxonomy::create([
            'sku_pattern'        => 'AM-3200-GV',
            'worksheet_category' => ProductTaxonomy::CATEGORY_CONTROL,
            'source'             => ProductTaxonomy::SOURCE_SEED,
        ]);

        $this->assertNotNull($row->id);
        $this->assertSame('AM-3200-GV', $row->sku_pattern);
        $this->assertSame('control', $row->worksheet_category);
        $this->assertSame('seed', $row->source);
        $this->assertNull($row->manufacturer);
        $this->assertNull($row->description_pattern);
        $this->assertNull($row->deleted_at);
    }

    public function test_promoted_at_casts_to_datetime(): void
    {
        $row = ProductTaxonomy::create([
            'worksheet_category' => ProductTaxonomy::CATEGORY_AUDIO,
            'source'             => ProductTaxonomy::SOURCE_ADMIN,
            'promoted_at'        => '2026-07-27 10:15:00',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->promoted_at);
        $this->assertSame('2026-07-27 10:15:00', $row->promoted_at->format('Y-m-d H:i:s'));
    }

    public function test_soft_delete_hides_row_from_default_queries(): void
    {
        $row = ProductTaxonomy::create([
            'description_pattern' => 'test-widget',
            'worksheet_category'  => ProductTaxonomy::CATEGORY_NETWORK,
        ]);

        $row->delete();

        $this->assertNotNull($row->fresh()->deleted_at ?? null,
            'delete should be soft (Plan 05 depends on this)');
        $this->assertSame(0, ProductTaxonomy::count(),
            'soft-deleted rows must not surface in default queries');
        $this->assertSame(1, ProductTaxonomy::withTrashed()->count());
    }

    public function test_relationships_wire_up_correctly(): void
    {
        $creator  = User::factory()->create();
        $promoter = User::factory()->create();
        $project  = Project::create([
            'user_id'     => $creator->id,
            'name'        => 'Test Project',
            'ref'         => 'TP-001',
            'client_name' => 'Test Client',
            'site_address' => '1 Test Rd',
            'status'      => 'quote_imported',
        ]);
        $package  = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $creator->id,
            'quote_filename' => 'test.pdf',
            'status'         => ProjectPackage::STATUS_PENDING,
        ]);

        $row = ProductTaxonomy::create([
            'sku_pattern'             => 'NOVEL-SKU-1',
            'worksheet_category'      => ProductTaxonomy::CATEGORY_DISPLAY,
            'source'                  => ProductTaxonomy::SOURCE_LEARNED,
            'learned_from_package_id' => $package->id,
            'created_by'              => $creator->id,
            'promoted_by'             => $promoter->id,
            'promoted_at'             => now(),
        ]);

        $this->assertTrue($row->creator->is($creator));
        $this->assertTrue($row->promoter->is($promoter));
        $this->assertTrue($row->learnedFromPackage->is($package));
    }

    public function test_category_constants_match_migration_enum(): void
    {
        // Every constant on the model must be a valid ENUM value. If someone
        // adds a 7th category to the model but forgets the migration, this
        // test lands the row and the ENUM check constraint (or MySQL strict
        // mode) throws — catching the schema drift.
        foreach (ProductTaxonomy::CATEGORIES as $cat) {
            $row = ProductTaxonomy::create([
                'description_pattern' => 'probe-' . $cat,
                'worksheet_category'  => $cat,
            ]);
            $this->assertSame($cat, $row->fresh()->worksheet_category);
        }
    }

    public function test_source_constants_match_migration_enum(): void
    {
        foreach (ProductTaxonomy::SOURCES as $source) {
            $row = ProductTaxonomy::create([
                'description_pattern' => 'probe-source-' . $source,
                'worksheet_category'  => ProductTaxonomy::CATEGORY_UNCLASSIFIED,
                'source'              => $source,
            ]);
            $this->assertSame($source, $row->fresh()->source);
        }
    }
}
