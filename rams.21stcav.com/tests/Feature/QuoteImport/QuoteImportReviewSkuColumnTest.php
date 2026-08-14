<?php

namespace Tests\Feature\QuoteImport;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260814-o8o — regression test for the quote-import review SKU
 * column.
 *
 * review.blade.php:160 read $item['sku'] with no fallback, but the
 * QuoteWerks and PDF-parser import paths never write 'sku' — they write
 * part_number / part_no (see QuoteWerksImportService.php:150-151). 'sku' is
 * the Claude-vision extraction path's key only. Every QuoteWerks-imported
 * line item therefore rendered '—' in the SKU column even though the part
 * number was stored correctly in extracted_data.
 *
 * This test asserts the SKU cell's rendered contents for all three shapes
 * the line-item array can take, matching the fallback chain used elsewhere
 * in the codebase (part_number ?? part_no ?? sku), e.g.
 * ProjectPackageReviewController.php:107.
 */
class QuoteImportReviewSkuColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_sku_column_falls_back_across_all_line_item_shapes(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'line_items' => [
                    // QuoteWerks-shaped: part_number only, no sku key at all.
                    [
                        'part_number' => 'QW-PART-001',
                        'description' => 'QuoteWerks line item',
                        'qty'         => 1,
                        'unit_price'  => 10,
                        'total_price' => 10,
                    ],
                    // Claude-vision-shaped: sku only, no part_number/part_no.
                    [
                        'sku'         => 'VISION-SKU-002',
                        'description' => 'Vision-extracted line item',
                        'qty'         => 2,
                        'unit_price'  => 20,
                        'total_price' => 40,
                    ],
                    // Neither key present.
                    [
                        'description' => 'No part number at all',
                        'qty'         => 3,
                        'unit_price'  => 30,
                        'total_price' => 90,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('quote-import.review', $package));

        $response->assertOk();
        $response->assertSeeText('QW-PART-001');
        $response->assertSeeText('VISION-SKU-002');
        $response->assertSeeText('No part number at all');
        $response->assertSeeText('—');
    }
}
