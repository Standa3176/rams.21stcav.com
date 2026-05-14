<?php

namespace App\Services\Drawings;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 — Port-to-port cable router.
 *
 * Reads each `cable_schedule_items` row on the project and emits ONE mxGraph
 * edge descriptor per cable (plus an optional ⚠ glyph cell when port FKs are
 * missing or the stencil lacks <constraint> elements). The orchestrator
 * (DrawIoBuilderService Plan 23-05) serialises these into the final mxGraph
 * XML alongside Plan 02's device cells.
 *
 * Implements (per CONTEXT + RESEARCH + DISCOVERY-OQ-4):
 *   DRAW-43: port-to-port routing via exitPortId / entryPortId attributes
 *            referencing the stencil's <constraint name="..."> element
 *   DRAW-44: signal-type colour from config('cables.signal_type_colours')
 *            — single source of truth locked by Phase 22; DO NOT MODIFY here
 *   DRAW-45: cable_id literal label rendered via the edge cell `value`
 *            attribute (mxGraph default midpoint placement)
 *   D-07:    NULL-FK fallback ladder
 *              - both device_ids NULL  → skip (v1.3 surface handles per Phase 22 D-10)
 *              - either port_id NULL   → coordinate-style edge + ⚠ glyph
 *              - both port_ids present → port-to-port (preferred)
 *   OQ-4:    Path B — Tier 1.5 stencils silently fall back to coordinate-
 *            style regardless of FK presence because their mxgraph_xml
 *            lacks <constraint> elements (94.8% of currently-seeded
 *            engineer-curated stencils per 23-DISCOVERY-OQ-4 disposition)
 *
 * Eager-load discipline (Phase 22 D-10):
 *   loadMissing at the CALL SITE INSIDE emitCables. NEVER add $with to
 *   CableScheduleItem — would force LEFT JOINs on every legacy NULL-FK row
 *   across v1.3 read paths (XLSX export, bound-PDF, schematic generator).
 *
 * Pure read function: NO Eloquent writes (D-LOCK-5/6 determinism).
 *   Same project state → same edge descriptor array, twice in a row, forever.
 *   No `now()`, no random, no DB writes anywhere in this class.
 *
 * Generic naming (Phase 21 D-09 carry-forward): class is `CableRouter`, NOT
 * `RamsCableRouter` — SCC merge readiness.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-07 / D-09 / D-10
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 3 (port-to-port edge)
 * @see .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md Path B
 */
class CableRouter
{
    /** Base edge style fragment — strokeColor/fontColor get spliced in per signal type. */
    private const EDGE_STYLE_TEMPLATE = 'edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeWidth=2;fontSize=10;';

    /** ⚠ glyph style — orange high-contrast warning per CONTEXT specifics line 215. */
    private const WARN_STYLE = 'text;html=1;align=center;verticalAlign=middle;fontSize=12;fontColor=#E67E22;';

    /**
     * D-07 source-side heuristic — cards in these categories project from the
     * right edge by default; everything else projects from the left edge.
     * Match against the device cell's 'category' field when the orchestrator
     * supplies it; falls back to left-edge if unknown.
     */
    private const SOURCE_LIKE_CATEGORIES = [
        'videobar', 'byod', 'mic', 'desk-mic', 'ceiling-mic', 'paging-station', 'call-station',
    ];

    /** Defensive cap — log a warning when a single project produces > 200 descriptors. */
    private const LARGE_EDGE_COUNT_THRESHOLD = 200;

    /**
     * Emit one mxGraph edge descriptor per cable_schedule_item on the project.
     *
     * Output descriptor shapes:
     *
     *   Edge:
     *     [
     *       'kind'   => 'edge',
     *       'id'     => 'cab-{item->id}',
     *       'value'  => '{xml-escaped cable_id}',
     *       'style'  => '...strokeColor=#XXXXXX;fontColor=#XXXXXX;exitPortId=...;entryPortId=...;',
     *       'source' => 'dev-{zone}-{idx}',  // from $deviceCells lookup
     *       'target' => 'dev-{zone}-{idx}',
     *       'signal' => 'video'|'audio'|...,
     *       'colour' => '#2980B9',
     *     ]
     *
     *   Warn glyph (D-07 / OQ-4 fallback only):
     *     [
     *       'kind'   => 'warn',
     *       'id'     => 'cab-{item->id}-warn',
     *       'value'  => '⚠',
     *       'style'  => self::WARN_STYLE,
     *       'parent' => '1',
     *       'x' => int, 'y' => int, 'w' => 20, 'h' => 20,
     *     ]
     *
     * @param  array<int, array{kind: string, id: string, device_id?: int|null, stencil?: object, category?: string, x?: int, y?: int, w?: int, h?: int}>  $deviceCells
     * @return array<int, array<string, mixed>>
     */
    public function emitCables(Project $project, array $deviceCells): array
    {
        // CALL-SITE eager-load — Phase 22 D-10 guard. Never add $with to the
        // model — eager-loading port relations class-wide would force 4 LEFT
        // JOINs on every legacy NULL-FK row across v1.3 read paths.
        $project->loadMissing([
            'cableSchedules.items.sourcePort',
            'cableSchedules.items.destPort',
            'cableSchedules.items.sourceDevice',
            'cableSchedules.items.destDevice',
        ]);

        // Build device_id → cell descriptor index for O(1) lookup.
        $byDeviceId = [];
        foreach ($deviceCells as $cell) {
            if (($cell['kind'] ?? '') !== 'device' || empty($cell['device_id'])) {
                continue;
            }
            $byDeviceId[$cell['device_id']] = $cell;
        }

        $cells = [];
        $items = $project->cableSchedules->flatMap(fn ($s) => $s->items);

        foreach ($items as $item) {
            // ── D-07 leg 1 — both device_ids NULL → skip (v1.3 surface handles) ──
            if ($item->source_device_id === null && $item->dest_device_id === null) {
                continue;
            }

            // ── Step 2 — both sides must be on the current sheet's device set ──
            $src = $byDeviceId[$item->source_device_id] ?? null;
            $dst = $byDeviceId[$item->dest_device_id] ?? null;
            if ($src === null || $dst === null) {
                Log::warning('CableRouter: skipping cable, device not on sheet', [
                    'cable_id'         => $item->cable_id,
                    'source_device_id' => $item->source_device_id,
                    'dest_device_id'   => $item->dest_device_id,
                    'project_id'       => $project->id,
                ]);
                continue;
            }

            // ── Step 3 — resolve signal_type (source preferred; dest fallback; 'unknown' default) ──
            $signal = (string) (
                $item->sourcePort?->signal_type
                ?? $item->destPort?->signal_type
                ?? 'unknown'
            );
            $colour = (string) (
                config('cables.signal_type_colours.' . $signal)
                ?? config('cables.signal_type_colours.unknown')
                ?? '#000000'
            );

            // ── Step 4 — decide port attachment style ──
            // OQ-4 Path B: if EITHER stencil lacks <constraint> elements in its
            // mxgraph_xml, the named-port route is impossible — even when the
            // FK is populated, the stencil shape can't terminate the cable at
            // a named port. Fall back to coordinate-style + ⚠ glyph in that
            // case (94.8% of currently-seeded stencils per the OQ-4 discovery).
            $srcHasConstraints = $this->stencilHasConstraints($src['stencil'] ?? null);
            $dstHasConstraints = $this->stencilHasConstraints($dst['stencil'] ?? null);

            $bothPortsPresent = $item->source_port_id !== null && $item->dest_port_id !== null;
            $canUseNamedPorts = $bothPortsPresent && $srcHasConstraints && $dstHasConstraints;

            if ($canUseNamedPorts) {
                $portStyle = $this->portToPortStyle($item);
                $needsWarnGlyph = false;
            } else {
                $portStyle = $this->deviceEdgeStyle($src, $dst);
                $needsWarnGlyph = true;
            }

            $edgeStyle = self::EDGE_STYLE_TEMPLATE
                . "strokeColor={$colour};fontColor={$colour};"
                . $portStyle;

            $cells[] = [
                'kind'    => 'edge',
                'id'      => 'cab-' . $item->id,
                'value'   => $this->xml((string) ($item->cable_id ?? '')),
                'style'   => $edgeStyle,
                'source'  => $src['id'],
                'target'  => $dst['id'],
                'signal'  => $signal,
                'colour'  => $colour,
            ];

            if ($needsWarnGlyph) {
                // Midpoint between source + dest device centres — gives the
                // engineer a clear "needs disambiguation" anchor on the visual.
                $midX = (int) (((((int) ($src['x'] ?? 0)) + ((int) ($src['w'] ?? 0)) / 2)
                              + (((int) ($dst['x'] ?? 0)) + ((int) ($dst['w'] ?? 0)) / 2)) / 2);
                $midY = (int) (((((int) ($src['y'] ?? 0)) + ((int) ($src['h'] ?? 0)) / 2)
                              + (((int) ($dst['y'] ?? 0)) + ((int) ($dst['h'] ?? 0)) / 2)) / 2);
                $cells[] = [
                    'kind'   => 'warn',
                    'id'     => 'cab-' . $item->id . '-warn',
                    'value'  => '⚠',
                    'style'  => self::WARN_STYLE,
                    'parent' => '1',
                    'x'      => $midX - 10,
                    'y'      => $midY - 10,
                    'w'      => 20,
                    'h'      => 20,
                ];
            }
        }

        if (count($cells) > self::LARGE_EDGE_COUNT_THRESHOLD) {
            Log::warning('CableRouter: large edge count — visual review recommended', [
                'count'      => count($cells),
                'project_id' => $project->id,
            ]);
        }

        return $cells;
    }

    /**
     * Preferred port-to-port style. Uses exitPortId / entryPortId referencing
     * the stencil's <constraint name="..."> elements (DRAW-43 happy path).
     *
     * Only reachable when BOTH stencils carry <constraint> elements AND both
     * port FKs are populated — caller has already gated.
     */
    private function portToPortStyle(\App\Models\CableScheduleItem $item): string
    {
        $srcPortId = (string) ($item->sourcePort?->port_id ?? '');
        $dstPortId = (string) ($item->destPort?->port_id ?? '');

        return 'exitPortId=' . $srcPortId . ';'
             . 'entryPortId=' . $dstPortId . ';';
    }

    /**
     * D-07 coordinate-style fallback — projects from the source device's
     * right edge if its category is "source-like" (videobar / byod / mic),
     * else from the left edge. Dest defaults to left-edge entry.
     *
     * Per OQ-4 Path B disposition, this is also the path Tier 1.5 stencils
     * take even when their port FKs are populated — the stencil shape has
     * no <constraint> to anchor a named port to.
     *
     * @param  array<string, mixed>  $src
     * @param  array<string, mixed>  $dst
     */
    private function deviceEdgeStyle(array $src, array $dst): string
    {
        $srcCategory = strtolower((string) ($src['category'] ?? ($src['stencil']->source_category ?? '')));
        $srcSide = in_array($srcCategory, self::SOURCE_LIKE_CATEGORIES, true) ? 'right' : 'left';
        $dstSide = 'left'; // dest defaults to left edge per D-07 heuristic

        $srcCoord = $this->sideToCoord($srcSide);
        $dstCoord = $this->sideToCoord($dstSide);

        return "exitX={$srcCoord['x']};exitY={$srcCoord['y']};exitDx=0;exitDy=0;exitPerimeter=0;"
             . "entryX={$dstCoord['x']};entryY={$dstCoord['y']};entryDx=0;entryDy=0;entryPerimeter=0;";
    }

    /**
     * OQ-4 Path B detection — does this stencil declare any <constraint>
     * elements in its mxgraph_xml? If not, the named-port route is impossible
     * regardless of FK presence (the stencil shape has nothing to anchor
     * exitPortId / entryPortId to).
     *
     * Returns false defensively when the stencil object is null or carries no
     * mxgraph_xml — both signal "can't terminate at a named port here".
     */
    private function stencilHasConstraints(?object $stencil): bool
    {
        if ($stencil === null) {
            return false;
        }
        $xml = (string) ($stencil->mxgraph_xml ?? '');

        return $xml !== '' && str_contains($xml, '<constraint');
    }

    /**
     * Side enum → mxGraph (exitX, exitY) coordinate pair. Returns floats in
     * the 0..1 range as mxGraph parent-relative coordinates.
     *
     * @return array{x: float, y: float}
     */
    private function sideToCoord(string $side): array
    {
        return match (strtolower($side)) {
            'right'  => ['x' => 1,   'y' => 0.5],
            'left'   => ['x' => 0,   'y' => 0.5],
            'top'    => ['x' => 0.5, 'y' => 0],
            'bottom' => ['x' => 0.5, 'y' => 1],
            default  => ['x' => 0.5, 'y' => 0.5],
        };
    }

    /**
     * XSS-safe XML escape — mirrors {@see DrawIoBuilderService::xml()} +
     * {@see XtenAvLayoutEngine::xml()} exactly. T-23-03-A1 cable_id mitigation:
     * engineer-typed cable IDs flow into mxCell value attributes; every one
     * passes through here before interpolation.
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
