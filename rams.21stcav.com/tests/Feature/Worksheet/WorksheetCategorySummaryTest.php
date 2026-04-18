<?php

namespace Tests\Feature\Worksheet;

use App\Services\Worksheet\WorksheetClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-level check for the Pass B dynamic category summary.
 *
 * Uses the classifier directly to simulate room output, then asserts the
 * summary phrasing is derived from the distinct categories present and
 * uses canonical taxonomy order (Display → VC → Audio → Control → Rack → Network).
 */
class WorksheetCategorySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_category_returns_bare_label(): void
    {
        $summary = $this->summaryFor([
            ['manufacturer' => 'Samsung', 'name' => 'Samsung QM75B Display'],
        ]);
        $this->assertSame('Display', $summary);
    }

    public function test_two_categories_joined_with_and(): void
    {
        $summary = $this->summaryFor([
            ['manufacturer' => 'Samsung',  'name' => 'Samsung QM75B Display'],
            ['manufacturer' => 'Logitech', 'name' => 'Logitech Rally Bar'],
        ]);
        $this->assertSame('Display and Video Conferencing', $summary);
    }

    public function test_three_plus_categories_use_comma_and_final_and(): void
    {
        $summary = $this->summaryFor([
            ['manufacturer' => 'Samsung',  'name' => 'Samsung QM75B Display'],
            ['manufacturer' => 'Logitech', 'name' => 'Logitech Rally Bar'],
            ['manufacturer' => 'Shure',    'name' => 'Shure MXA920 Ceiling Array Microphone'],
            ['manufacturer' => 'Netgear',  'name' => 'Netgear M4250 PoE Switch'],
        ]);
        $this->assertSame('Display, Video Conferencing, Audio and Network', $summary);
    }

    public function test_summary_follows_canonical_order_regardless_of_input_order(): void
    {
        // Provide items in reverse canonical order; expect canonical order out.
        $summary = $this->summaryFor([
            ['manufacturer' => 'Netgear',  'name' => 'Managed PoE Switch'],
            ['manufacturer' => 'Crestron', 'name' => 'Crestron Touch Panel'],
            ['manufacturer' => 'Samsung',  'name' => 'Samsung 75" Display'],
        ]);
        // Display < Control & Automation < Network
        $this->assertSame('Display, Control & Automation and Network', $summary);
    }

    public function test_unclassified_items_excluded_from_summary(): void
    {
        $summary = $this->summaryFor([
            ['manufacturer' => 'Samsung', 'name' => 'Samsung Display'],
            ['name' => 'Widget Thing 42'], // tier5 unclassified, should NOT appear
        ]);
        $this->assertSame('Display', $summary);
    }

    /**
     * Mirrors WorksheetGeneratorService::groupByCanonicalCategory +
     * buildCategorySummary without needing to spin the full generator.
     */
    private function summaryFor(array $items): string
    {
        $taxonomy = config('worksheet_taxonomy');
        $labels   = $taxonomy['categories'];
        $order    = $taxonomy['category_order'];

        $classifier = app(WorksheetClassifier::class);
        $result     = $classifier->classifyRoom($items);

        $bucket = [];
        foreach ($result['items'] as $i) {
            $cat = $i['_classification']['category'];
            if (isset($labels[$cat])) {
                $bucket[$cat] = true;
            }
        }

        $presentLabels = [];
        foreach ($order as $k) {
            if (isset($bucket[$k])) $presentLabels[] = $labels[$k];
        }

        return match (count($presentLabels)) {
            0       => '',
            1       => $presentLabels[0],
            2       => $presentLabels[0] . ' and ' . $presentLabels[1],
            default => (function () use ($presentLabels) {
                $last = array_pop($presentLabels);
                return implode(', ', $presentLabels) . ' and ' . $last;
            })(),
        };
    }
}
