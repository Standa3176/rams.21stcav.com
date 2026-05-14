<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — XTEN-AV-style layout engine (DRAW-42 + DRAW-46).
 *
 * Takes {@see ZoneGrouper}::assign() output (zone name → device lines) and
 * emits a flat ordered list of mxCell descriptors. The orchestrator
 * ({@see DrawIoBuilderService} — Plan 23-05 rewire) serialises these into
 * the final mxGraph XML.
 *
 * Descriptor shape (zone first, then its devices):
 *   [
 *     'kind'   => 'zone',
 *     'id'     => 'zone-rack',
 *     'value'  => 'RACK',           // XML-escaped
 *     'style'  => 'rounded=0;dashed=1;dashPattern=5 5;fillColor=none;...',
 *     'parent' => '1',
 *     'x' => 60, 'y' => 60, 'w' => 500, 'h' => 320,
 *   ]
 *
 *   [
 *     'kind'        => 'device',
 *     'id'          => 'dev-rack-0',
 *     'value'       => 'Neat Bar Pro',                       // XML-escaped
 *     'style'       => 'shape=stencil(<base64>);verticalLabelPosition=top;...',
 *     'parent'      => 'zone-rack',
 *     'x' => 20, 'y' => 44, 'w' => 220, 'h' => 140,           // relative to zone
 *     'part_number' => 'NEAT-BAR-PRO',                        // carried for Plan 23-03 CableRouter
 *     'stencil'     => DeviceStencil|object,                  // carried for downstream
 *   ]
 *
 * Layout strategy: within each zone, devices flow column-major. Default
 * cell size = 220×140 (matches {@see DeviceStencil}::default_width/height).
 * Gap: 30 px horizontal, 20 px vertical. Zone container bounds = union of
 * children + 20 px padding, with the zone title in the top-left (24 px
 * reserved for the title strip).
 *
 * Per CONTEXT D-04 (Tier 1 + Tier 2 both render), DRAW-42, DRAW-46.
 *
 * Pure read function:
 *   - NO Eloquent writes / Eloquent reads (D-LOCK-5/6 determinism)
 *   - NO AI calls (D-LOCK-5)
 *   - NO `now()` / random / DB / config-time-of-day reads
 *   - same input → same output, twice in a row, forever
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-04)
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md (Examples 1 + 5)
 */
class XtenAvLayoutEngine
{
    /**
     * Dashed-bordered group container style — DRAW-46 visual contract per
     * 23-RESEARCH.md Example 5. Title placed top-left inside the box.
     */
    private const ZONE_STYLE = 'rounded=0;dashed=1;dashPattern=5 5;fillColor=none;strokeColor=#888888;strokeWidth=1;fontSize=10;fontColor=#666666;verticalAlign=top;align=left;spacingTop=4;spacingLeft=8;';

    /**
     * Device-card style fragments. The base64-encoded mxgraph_xml gets
     * spliced between PREFIX and SUFFIX. Mirrors the Phase 21 P03 pattern
     * from {@see DrawIoBuilderService::emitMxGraph()} so the same draw.io
     * renderer rules apply.
     */
    private const DEVICE_STYLE_PREFIX = 'shape=stencil(';
    private const DEVICE_STYLE_SUFFIX = ');whiteSpace=wrap;html=1;verticalLabelPosition=top;verticalAlign=bottom;fontSize=10;fontColor=#333333;';

    // Layout geometry — all integer pixels, all deterministic.
    private const COLUMN_GAP         = 30;
    private const ROW_GAP            = 20;
    private const ZONE_PADDING       = 20;
    private const ZONE_TITLE_HEIGHT  = 24;   // top strip reserved for zone label
    private const ZONE_X_START       = 60;
    private const ZONE_Y_START       = 60;
    private const ZONE_SPACING       = 40;   // horizontal gap between zones
    private const MAX_COLS_PER_ZONE  = 4;    // wraps to a new row after 4 devices
    private const DEFAULT_DEVICE_W   = 220;  // fallback when stencil has no default_width
    private const DEFAULT_DEVICE_H   = 140;  // fallback when stencil has no default_height

    /**
     * Place every device line into a flat ordered descriptor list with
     * zone containers preceding their child devices.
     *
     * @param  array<string, array<int, array{part_number: string, name?: string, stencil: object}>>  $zonedLines
     * @return array<int, array<string, mixed>>
     */
    public function placeDevices(array $zonedLines): array
    {
        if ($zonedLines === []) {
            return [];
        }

        $cells = [];
        $zoneIndex = 0;
        $zoneX = self::ZONE_X_START;

        foreach ($zonedLines as $zoneName => $lines) {
            $zoneSlug   = $this->slug((string) $zoneName, $zoneIndex);
            $zoneCellId = 'zone-' . $zoneSlug;

            // Build device cell descriptors with coordinates relative to the
            // zone container. mxGraph parent-relative geometry means the
            // device x/y are local to the zone box.
            $deviceCells = $this->buildDeviceCells($lines, $zoneSlug, $zoneCellId);

            // Zone bounding box = union of children + padding. Title strip
            // already accounted for in the device y-offset.
            [$zoneW, $zoneH] = $this->boundsOf($deviceCells);

            // Emit zone container FIRST so consumers serialising in order
            // see the parent before its children. mxGraph allows either
            // order but our spec (Plan 23-02 acceptance) requires this.
            $cells[] = [
                'kind'   => 'zone',
                'id'     => $zoneCellId,
                'value'  => $this->xml((string) $zoneName),
                'style'  => self::ZONE_STYLE,
                'parent' => '1',
                'x'      => $zoneX,
                'y'      => self::ZONE_Y_START,
                'w'      => $zoneW,
                'h'      => $zoneH,
            ];

            foreach ($deviceCells as $dc) {
                $cells[] = $dc;
            }

            $zoneX += $zoneW + self::ZONE_SPACING;
            $zoneIndex++;
        }

        return $cells;
    }

    /**
     * Build device-cell descriptors for one zone, with column-major flow
     * and the parent attribute pre-wired to the zone container id.
     *
     * @param  array<int, array{part_number: string, name?: string, stencil: object}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function buildDeviceCells(array $lines, string $zoneSlug, string $zoneCellId): array
    {
        $cells = [];
        $deviceIndex = 0;

        foreach ($lines as $line) {
            /** @var object $stencil */
            $stencil = $line['stencil'];

            $col = $deviceIndex % self::MAX_COLS_PER_ZONE;
            $row = intdiv($deviceIndex, self::MAX_COLS_PER_ZONE);

            $w = (int) ($stencil->default_width  ?? self::DEFAULT_DEVICE_W);
            $h = (int) ($stencil->default_height ?? self::DEFAULT_DEVICE_H);

            // Label resolution: explicit name → display_name → part_number.
            // Whatever falls out is XML-escaped before interpolation
            // (T-23-02-A2 — device names are upstream-untrusted QuoteWerks data).
            $rawLabel = (string) ($line['name'] ?? '');
            if ($rawLabel === '') {
                $rawLabel = (string) ($stencil->display_name ?? '');
            }
            if ($rawLabel === '') {
                $rawLabel = (string) ($stencil->part_number ?? '');
            }

            $cells[] = [
                'kind'        => 'device',
                'id'          => 'dev-' . $zoneSlug . '-' . $deviceIndex,
                'value'       => $this->xml($rawLabel),
                'style'       => self::DEVICE_STYLE_PREFIX
                    . base64_encode((string) ($stencil->mxgraph_xml ?? ''))
                    . self::DEVICE_STYLE_SUFFIX,
                'parent'      => $zoneCellId,
                'x'           => self::ZONE_PADDING + $col * ($w + self::COLUMN_GAP),
                'y'           => self::ZONE_PADDING + self::ZONE_TITLE_HEIGHT + $row * ($h + self::ROW_GAP),
                'w'           => $w,
                'h'           => $h,
                'part_number' => (string) ($line['part_number'] ?? ''),
                'stencil'     => $stencil,
            ];

            $deviceIndex++;
        }

        return $cells;
    }

    /**
     * Compute the zone container's width + height as the union of every
     * child cell's right/bottom edge plus one padding step. Returns
     * [width, height] in integer pixels.
     *
     * Zone with zero children still gets a non-zero footprint so an empty
     * group renders as a visible (if small) dashed box rather than vanishing.
     *
     * @param  array<int, array<string, mixed>>  $deviceCells
     * @return array{0: int, 1: int}
     */
    private function boundsOf(array $deviceCells): array
    {
        if ($deviceCells === []) {
            // Minimal visible footprint for an empty zone.
            return [
                self::ZONE_PADDING * 2 + self::DEFAULT_DEVICE_W,
                self::ZONE_PADDING * 2 + self::ZONE_TITLE_HEIGHT + self::DEFAULT_DEVICE_H,
            ];
        }

        $maxX = 0;
        $maxY = 0;
        foreach ($deviceCells as $dc) {
            $maxX = max($maxX, ((int) $dc['x']) + ((int) $dc['w']));
            $maxY = max($maxY, ((int) $dc['y']) + ((int) $dc['h']));
        }

        return [$maxX + self::ZONE_PADDING, $maxY + self::ZONE_PADDING];
    }

    /**
     * Slug a zone name into a stable ID component. Falls back to the
     * zone index for unicode-heavy strings (or strings consisting only
     * of non-ASCII / punctuation, e.g. an XSS-probe key) so IDs stay
     * deterministic and never collide with empty strings.
     */
    private function slug(string $zoneName, int $index): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($zoneName));
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : 'unnamed-' . $index;
    }

    /**
     * XSS-safe XML escape — mirrors {@see DrawIoBuilderService::xml()} exactly
     * (T-23-02-A1 zone-label mitigation + T-23-02-A2 device-name mitigation
     * per CONTEXT Pitfall 8). Every user-supplied string that becomes an
     * `mxCell value="..."` attribute MUST pass through here.
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
