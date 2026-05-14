<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\SheetBorderRenderer;
use Tests\TestCase;

/**
 * Phase 23 Plan 04 — DRAW-49 SheetBorderRenderer.
 *
 * Single dashed-border mxCell per sheet at page bounds with insets per
 * config('drawings.page_dimensions.border_inset'). Deterministic — same
 * config → same descriptor, byte-identical across calls.
 *
 * @see app/Services/Drawings/SheetBorderRenderer.php
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 7
 */
class SheetBorderRendererTest extends TestCase
{
    public function test_emits_one_border_cell(): void
    {
        $cells = app(SheetBorderRenderer::class)->render();

        $this->assertCount(1, $cells);
        $this->assertSame('border', $cells[0]['kind']);
    }

    public function test_border_geometry_inset_from_page_bounds(): void
    {
        config()->set('drawings.page_dimensions', [
            'width'         => 1600,
            'height'        => 1000,
            'border_inset'  => 20,
            'title_block_y' => 940,
        ]);

        $cells = app(SheetBorderRenderer::class)->render();

        $this->assertSame(20, $cells[0]['x']);
        $this->assertSame(20, $cells[0]['y']);
        $this->assertSame(1560, $cells[0]['w']); // 1600 - 2*20
        $this->assertSame(960, $cells[0]['h']);  // 1000 - 2*20
    }

    public function test_border_style_is_dashed(): void
    {
        $cells = app(SheetBorderRenderer::class)->render();

        $this->assertStringContainsString('dashed=1', $cells[0]['style']);
        $this->assertStringContainsString('fillColor=none', $cells[0]['style']);
        $this->assertStringContainsString('strokeColor=#1B7A7A', $cells[0]['style']);
    }

    public function test_render_is_deterministic(): void
    {
        // Same config → byte-identical descriptor on every call.
        $a = app(SheetBorderRenderer::class)->render();
        $b = app(SheetBorderRenderer::class)->render();

        $this->assertSame($a, $b);
    }
}
