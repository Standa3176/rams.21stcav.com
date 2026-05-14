<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — Sheet border renderer (DRAW-49).
 *
 * Emits a single dashed-border mxCell that wraps the page bounds. One per
 * sheet inside the `<mxfile>` wrapper. Geometry insets per
 * config('drawings.page_dimensions.border_inset'); style mirrors brand
 * teal (#1B7A7A) at 1.5px stroke per the XTEN-AV reference image.
 *
 * Pure deterministic function — no input dependencies, NO Eloquent writes,
 * NO AI calls. Same config → byte-identical descriptor on every call.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 7
 */
class SheetBorderRenderer
{
    /**
     * mxGraph style for the dashed page border. Brand teal stroke at 1.5px
     * with 8/4 dash pattern matches the reference image visual contract.
     */
    private const BORDER_STYLE = 'rounded=0;dashed=1;dashPattern=8 4;fillColor=none;strokeColor=#1B7A7A;strokeWidth=1.5;';

    /**
     * Render the dashed border for one sheet.
     *
     * Returns a single-element array so the orchestrator can splice it into
     * a flat per-sheet descriptor list alongside title-block fields, zone
     * groups, device cells, and edge cells without special-casing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function render(): array
    {
        $page = (array) config('drawings.page_dimensions', []);
        $width  = (int) ($page['width']  ?? 1600);
        $height = (int) ($page['height'] ?? 1000);
        $inset  = (int) ($page['border_inset'] ?? 20);

        return [
            [
                'kind'   => 'border',
                'id'     => 'page-border',
                'value'  => '',
                'style'  => self::BORDER_STYLE,
                'parent' => '1',
                'x'      => $inset,
                'y'      => $inset,
                'w'      => $width  - 2 * $inset,
                'h'      => $height - 2 * $inset,
            ],
        ];
    }
}
