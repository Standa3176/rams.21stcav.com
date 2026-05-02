<?php

namespace App\Services\Drawings;

use App\Models\ProjectDrawing;
use RuntimeException;

/**
 * Phase 18 Plan 03 — synchronous custom Blade SVG rack renderer.
 *
 * Consumes {@see ProjectDrawing::$source_data} (rack_meta + rack_items) and
 * emits a complete SVG document with:
 *   - U-numbered side rail (1 at the BOTTOM, heightU at the top — AVIXA
 *     PDU-low / patches-high convention).
 *   - Equipment rectangles at correct U-positions, sized by u_height (in U).
 *   - A totals footer with weight / current / BTU / U-utilisation. Asterisks
 *     and "(n/m known)" ratios surface when metric data is partial (DRAW-12,
 *     CONTEXT.md Gray Area D — "honest about data quality").
 *   - A "U-height unknown" warning region listing devices whose u_height is
 *     null (CRIT-06: never silent 1U guess — surface it).
 *
 * Synchronous by design: the engineer clicks "Save Rack" in the Plan 18-03
 * editor and this service runs in-band on the controller, completing in well
 * under a second for a full 42U rack with 30 items
 * (Warning 8 fix — asserted by RackElevationRenderServiceTest::test_render_completes_within_one_second_for_full_rack).
 *
 * Falls back through {@see DeviceCatalogService::lookupByPartNo()} when
 * source_data omits per-item metric values — but never silent-guesses U-height
 * (CRIT-06 honoured throughout).
 *
 * @see app/Services/Drawings/DeviceCatalogService.php
 * @see resources/data/device-port-catalog.json
 * @see CRIT-06 in .planning/research/PITFALLS.md
 */
class RackElevationRenderService
{
    // ── Layout constants (px) ─────────────────────────────────────────────
    private const U_HEIGHT_PX = 24;            // 1U vertical pixel height

    private const RACK_WIDTH_PX = 380;         // visual width of the rack frame

    private const RAIL_LABEL_WIDTH_PX = 28;    // left rail "U number" column width

    private const FOOTER_HEIGHT_PX = 110;      // totals footer height below the rack

    private const HEADER_HEIGHT_PX = 32;       // rack-label + height header above

    private const PADDING_PX = 16;             // overall canvas padding

    private const FONT_FAMILY = "'Helvetica Neue', Arial, sans-serif";

    public function __construct(private readonly DeviceCatalogService $catalog) {}

    /**
     * Render the rack drawing as a complete SVG document.
     *
     * @throws RuntimeException When the drawing's kind is not 'rack' or
     *                          rack_height_u is out of range (1..99).
     */
    public function render(ProjectDrawing $drawing): string
    {
        if ($drawing->kind !== ProjectDrawing::KIND_RACK) {
            throw new RuntimeException(
                "RackElevationRenderService::render: kind '{$drawing->kind}' is not 'rack'"
            );
        }

        $source = (array) ($drawing->source_data ?? []);
        $meta = (array) ($source['rack_meta'] ?? []);
        $items = (array) ($source['rack_items'] ?? []);
        $heightU = (int) ($meta['rack_height_u'] ?? 42);

        if ($heightU < 1 || $heightU > 99) {
            throw new RuntimeException(
                "RackElevationRenderService::render: invalid rack_height_u={$heightU}"
            );
        }

        $rackInnerHeight = $heightU * self::U_HEIGHT_PX;

        $totalWidth = self::PADDING_PX * 2 + self::RAIL_LABEL_WIDTH_PX + self::RACK_WIDTH_PX;
        $totalHeight = self::PADDING_PX * 2
            + self::HEADER_HEIGHT_PX
            + $rackInnerHeight
            + self::FOOTER_HEIGHT_PX;

        $rackTop = self::PADDING_PX + self::HEADER_HEIGHT_PX;
        $rackLeft = self::PADDING_PX + self::RAIL_LABEL_WIDTH_PX;

        $unknownDevices = [];
        $totals = [
            'weight_kg' => 0.0,
            'current_a' => 0.0,
            'btu_per_hour' => 0,
            'weight_known' => 0,
            'current_known' => 0,
            'btu_known' => 0,
            'used_u' => 0.0,
        ];

        // Body order: header → rail → items → footer. Items and totals in one
        // pass over rack_items.
        $header = $this->renderHeader($meta, $totalWidth);
        $rail = $this->renderRail($heightU, $rackTop, $rackLeft, $rackInnerHeight);
        $items_svg = $this->renderItems(
            $items,
            $heightU,
            $rackTop,
            $rackLeft,
            $rackInnerHeight,
            $unknownDevices,
            $totals,
        );
        $frame = $this->renderFrame($rackLeft, $rackTop, $rackInnerHeight);
        $footer = $this->renderFooter(
            $totals,
            count($items),
            $heightU,
            $unknownDevices,
            $rackTop + $rackInnerHeight + 12,
        );

        // Compose. Note: <style> font-family on text/tspan globally so PDF
        // render via Browsershot has a font Chrome definitely has metrics for
        // (mirrors the Phase 17 schematic Blade's fallback chain).
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
            .'<style>text { font-family: %s; font-size: 11px; fill: #1f2937; }</style>'
            .'%s%s%s%s%s'
            .'</svg>',
            $totalWidth,
            $totalHeight,
            $totalWidth,
            $totalHeight,
            self::FONT_FAMILY,
            $header,
            $frame,
            $rail,
            $items_svg,
            $footer,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function renderHeader(array $meta, int $totalWidth): string
    {
        $label = $this->escape((string) ($meta['rack_label'] ?? 'Rack'));
        $height = (int) ($meta['rack_height_u'] ?? 42);
        $voltage = (int) ($meta['nominal_voltage_v'] ?? 230);

        return sprintf(
            '<text x="%d" y="%d" font-weight="600" font-size="13px">%s — %dU · %dV</text>',
            self::PADDING_PX,
            self::PADDING_PX + 16,
            $label,
            $height,
            $voltage,
        );
    }

    private function renderFrame(int $rackLeft, int $rackTop, int $rackInnerHeight): string
    {
        return sprintf(
            '<rect class="rack-frame" x="%d" y="%d" width="%d" height="%d" '
            .'fill="#f3f4f6" stroke="#374151" stroke-width="2" rx="4"/>',
            $rackLeft,
            $rackTop,
            self::RACK_WIDTH_PX,
            $rackInnerHeight,
        );
    }

    /**
     * U-numbered side rail. U-1 at the BOTTOM, heightU at the top (AVIXA
     * convention). Each U-tick gets a horizontal hairline + a centred number.
     */
    private function renderRail(int $heightU, int $rackTop, int $rackLeft, int $rackInnerHeight): string
    {
        $svg = '';

        // Per-U horizontal hairline INSIDE the rack frame.
        for ($u = 1; $u <= $heightU; $u++) {
            // y for the BOTTOM of this U-row (1 at bottom)
            $yBottom = $rackTop + $rackInnerHeight - ($u - 1) * self::U_HEIGHT_PX;
            // Number sits centred VERTICALLY within the U-cell
            $yLabel = $yBottom - (self::U_HEIGHT_PX / 2) + 4;

            $svg .= sprintf(
                '<text x="%d" y="%.1f" text-anchor="middle" fill="#475569" font-size="9px">%d</text>',
                $rackLeft - (self::RAIL_LABEL_WIDTH_PX / 2),
                $yLabel,
                $u,
            );

            // Hairline along the TOP of each U-row (skip very top)
            if ($u < $heightU) {
                $yLine = $rackTop + $rackInnerHeight - $u * self::U_HEIGHT_PX;
                $svg .= sprintf(
                    '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="2 2"/>',
                    $rackLeft,
                    $yLine,
                    $rackLeft + self::RACK_WIDTH_PX,
                    $yLine,
                );
            }
        }

        return $svg;
    }

    /**
     * Equipment rects + per-item metrics. Mutates $unknownDevices (for the
     * CRIT-06 warning) and $totals (for the footer asterisks/ratios).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $unknownDevices
     * @param  array<string, mixed>  $totals
     */
    private function renderItems(
        array $items,
        int $heightU,
        int $rackTop,
        int $rackLeft,
        int $rackInnerHeight,
        array &$unknownDevices,
        array &$totals,
    ): string {
        $svg = '';

        foreach ($items as $item) {
            $partNo = (string) ($item['part_no'] ?? '');
            $catalogRow = $this->catalog->lookupByPartNo($partNo);

            // u_height: prefer per-item override, then catalog, then NULL → 1U
            // PLACEHOLDER + warning (CRIT-06: never silent 1U guess; surface it).
            $rawU = $item['u_height'] ?? $catalogRow['u_height'] ?? null;
            if ($rawU === null) {
                $unknownDevices[] = (string) ($item['name'] ?? $partNo ?: 'Unknown');
                $uHeight = 1.0; // PLACEHOLDER for layout — the warning row carries the truth.
            } else {
                $uHeight = (float) $rawU;
            }

            $weight = $this->resolveNumeric($item, $catalogRow, 'weight_kg');
            $current = $this->resolveNumeric($item, $catalogRow, 'current_draw_a');
            $btu = $this->resolveNumeric($item, $catalogRow, 'btu_per_hour');

            if ($weight !== null) {
                $totals['weight_kg'] += (float) $weight;
                $totals['weight_known']++;
            }
            if ($current !== null) {
                $totals['current_a'] += (float) $current;
                $totals['current_known']++;
            }
            if ($btu !== null) {
                $totals['btu_per_hour'] += (int) $btu;
                $totals['btu_known']++;
            }
            $totals['used_u'] += $uHeight;

            $uPosition = (int) ($item['u_position'] ?? 1);
            // Rect TOP: walk up from the rack BOTTOM by (u_position + height - 1) U
            $rectY = $rackTop + $rackInnerHeight - ($uPosition + $uHeight - 1) * self::U_HEIGHT_PX;
            $rectHeight = $uHeight * self::U_HEIGHT_PX;

            $name = $this->escape((string) ($item['name'] ?? $partNo));
            $equipmentId = $this->escape((string) ($item['equipment_id'] ?? $partNo));
            $locked = ! empty($item['locked']);

            // Rect — colour-coded for locked vs unlocked
            $svg .= sprintf(
                '<rect data-equipment-id="%s"%s x="%d" y="%.1f" width="%d" height="%.1f" '
                .'fill="%s" stroke="#475569" stroke-width="1"/>',
                $equipmentId,
                $locked ? ' data-locked="true"' : '',
                $rackLeft + 2,
                $rectY,
                self::RACK_WIDTH_PX - 4,
                $rectHeight,
                $locked ? '#fef3c7' : '#ffffff',
            );

            // Label — centred vertically in the rect, indented 8px from rect left.
            $labelY = $rectY + ($rectHeight / 2) + 4;
            $svg .= sprintf(
                '<text x="%d" y="%.1f" font-size="11px">%s · %sU%s · U-%d</text>',
                $rackLeft + 8,
                $labelY,
                $name,
                $rawU === null ? '?' : $this->formatNumber($uHeight),
                $locked ? ' (locked)' : '',
                $uPosition,
            );
        }

        return $svg;
    }

    /**
     * Footer — totals line + U-utilisation + (when present) the
     * "U-height unknown:" warning row that lists every device with no
     * u_height resolved (CRIT-06).
     */
    private function renderFooter(
        array $totals,
        int $totalItems,
        int $heightU,
        array $unknownDevices,
        float $startY,
    ): string {
        $svg = '';

        $weightLine = $this->formatMetricLine(
            'Weight',
            $this->formatNumber($totals['weight_kg']),
            'kg',
            $totals['weight_known'],
            $totalItems,
        );
        $currentLine = $this->formatMetricLine(
            'Current',
            $this->formatNumber($totals['current_a']),
            'A',
            $totals['current_known'],
            $totalItems,
        );
        $btuLine = $this->formatMetricLine(
            'BTU',
            (string) (int) $totals['btu_per_hour'],
            '',
            $totals['btu_known'],
            $totalItems,
        );

        $usedU = $this->formatNumber($totals['used_u']);
        $utilLine = sprintf('U-utilisation: %sU / %dU', $usedU, $heightU);

        $y = $startY + 16;
        $svg .= sprintf(
            '<text x="%d" y="%.1f" font-weight="600">Totals</text>',
            self::PADDING_PX,
            $y,
        );

        foreach ([$weightLine, $currentLine, $btuLine, $utilLine] as $line) {
            $y += 14;
            $svg .= sprintf(
                '<text x="%d" y="%.1f">%s</text>',
                self::PADDING_PX,
                $y,
                $this->escape($line),
            );
        }

        if (! empty($unknownDevices)) {
            $y += 16;
            $names = $this->escape(implode(', ', $unknownDevices));
            $svg .= sprintf(
                '<text x="%d" y="%.1f" fill="#b45309" font-weight="600">'
                .'U-height unknown: %s'
                .'<title>%s</title>'
                .'</text>',
                self::PADDING_PX,
                $y,
                $names,
                $names,
            );
        }

        return $svg;
    }

    /**
     * Build a "Weight: 1.8 kg* (1/2 known)" or "Weight: 1.8 kg" line.
     */
    private function formatMetricLine(string $label, string $value, string $unit, int $known, int $totalItems): string
    {
        $unitSpace = $unit !== '' ? ' '.$unit : '';

        if ($known >= $totalItems || $totalItems === 0) {
            // All known (or no items at all) — no asterisk, no ratio
            return sprintf('%s: %s%s', $label, $value, $unitSpace);
        }

        // Partial — asterisk + ratio
        return sprintf('%s: %s%s* (%d/%d known)', $label, $value, $unitSpace, $known, $totalItems);
    }

    /**
     * Resolve a numeric metric (weight_kg / current_draw_a / btu_per_hour) by
     * preferring the item's own value, then the catalog row, then null.
     */
    private function resolveNumeric(array $item, ?array $catalogRow, string $key): float|int|null
    {
        if (array_key_exists($key, $item) && $item[$key] !== null) {
            return is_int($item[$key]) ? (int) $item[$key] : (float) $item[$key];
        }
        if ($catalogRow !== null && array_key_exists($key, $catalogRow) && $catalogRow[$key] !== null) {
            return is_int($catalogRow[$key]) ? (int) $catalogRow[$key] : (float) $catalogRow[$key];
        }

        return null;
    }

    /**
     * Format a float with up to one decimal place, dropping trailing zero.
     * 1.8 → "1.8"; 1.0 → "1"; 60 → "60".
     */
    private function formatNumber(float|int $n): string
    {
        if ((float) $n === (float) (int) $n) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }

    /**
     * htmlspecialchars wrapper for SVG-safe text. ENT_XML1 keeps quote
     * attribute escaping correct; ENT_QUOTES escapes both ' and " for
     * defense-in-depth on attribute values.
     */
    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
