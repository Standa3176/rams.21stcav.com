<?php

namespace App\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 Plan 05 — XTEN-AV-style mxGraph builder for the draw.io embed.
 *
 * Public contract (preserved from Phase 21 P03):
 *   public function build(Project $project): string;
 *
 * Internals (Phase 23 rewire — per CONTEXT D-05 spike route preservation):
 *   1. ZoneGrouper           — derive sub-room zones per D-01/D-02/D-04
 *   2. XtenAvLayoutEngine    — emit zone container + device cells (DRAW-42 + DRAW-46)
 *   3. CableRouter           — emit port-to-port edges (DRAW-43/44/45) + ⚠ glyphs (D-07)
 *   4. SheetPaginator        — classify into 1–5 sheets (DRAW-47 + D-06 thresholds)
 *   5. TitleBlockRenderer    — 8 title-block fields per sheet (DRAW-48 / D-08)
 *   6. SheetBorderRenderer   — single dashed border per sheet (DRAW-49)
 *   → Serialise each sheet as <diagram><mxGraphModel>…</mxGraphModel></diagram>
 *   → Wrap all <diagram> elements in <mxfile> (draw.io multi-page format)
 *
 * The Phase 21 P03 shallow stencil-role const tables and the canonical
 * Teams Room cable-chain inference have all been removed — the phase-23
 * marker from Plan 21-03 is resolved by this class.
 *
 * Backwards-compat (preserved):
 *   - Empty project (no latestPackage OR no hardware devices) emits the
 *     legacy single `<mxGraphModel>` empty-graph shape — NO `<mxfile>`
 *     wrapper. Phase 21 P03's `test_empty_package_emits_valid_empty_graph`
 *     stays green.
 *   - The DrawIoSpikeBuilderService shim continues to delegate to this class
 *     unchanged (Phase 21 D-08).
 *   - DrawIoSpikeController constructor signature unchanged.
 *
 * Constraints:
 *   - NO AI usage (D-LOCK-5)
 *   - NO Eloquent writes (D-LOCK-5/6 — cache-miss writes happen inside
 *     `Project::devicesWithStencils()`, not here)
 *   - Deterministic: same Project state → same XML bytes (test harness
 *     uses Carbon::setTestNow to freeze the title-block date field)
 *   - All user-supplied strings are XML-escaped by the helpers before
 *     reaching this orchestrator — DO NOT re-escape during serialise
 *
 * @see app/Services/Drawings/ZoneGrouper.php (Plan 23-02)
 * @see app/Services/Drawings/XtenAvLayoutEngine.php (Plan 23-02)
 * @see app/Services/Drawings/CableRouter.php (Plan 23-03)
 * @see app/Services/Drawings/SheetPaginator.php (Plan 23-04)
 * @see app/Services/Drawings/TitleBlockRenderer.php (Plan 23-04)
 * @see app/Services/Drawings/SheetBorderRenderer.php (Plan 23-04)
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-05, D-06, D-07, D-08)
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-08 spike route preservation)
 */
class DrawIoBuilderService
{
    /**
     * Pitfall 4 — log a warning when XML approaches the 5 MB postMessage cap
     * enforced by DrawIoSpikeController::saveXml validation. At 4.5 MB the
     * builder warns ops; users still get the render but should consider
     * splitting the project into multiple drawings.
     */
    private const LARGE_XML_THRESHOLD_BYTES = 4_500_000;

    /**
     * Phase 23 dependency injection — Laravel auto-resolves all 8 helpers
     * via the container (no explicit AppServiceProvider bindings needed).
     *
     * Note: $stencilCache is no longer called directly by this class — the
     * cache-miss → Tier 1 auto-create side-effect lives inside
     * Project::devicesWithStencils() (Phase 21 D-07). The dependency is
     * declared here to make the contract explicit AND keep the container
     * wiring honest if a future refactor moves the call site back into the
     * builder.
     */
    public function __construct(
        private readonly DeviceStencilCacheService $stencilCache,
        private readonly ManufacturerLogoResolver  $logos,
        private readonly ZoneGrouper               $zones,
        private readonly XtenAvLayoutEngine        $layout,
        private readonly CableRouter               $cables,
        private readonly SheetPaginator            $paginator,
        private readonly TitleBlockRenderer        $titleBlock,
        private readonly SheetBorderRenderer       $sheetBorder,
    ) {}

    /**
     * Build the multi-sheet mxGraph XML for a project's devices.
     *
     * Empty / no-package case returns the legacy single `<mxGraphModel>` shape
     * (no `<mxfile>` wrapper) so Phase 21 P03 backwards-compat is preserved.
     */
    public function build(Project $project): string
    {
        $package = $project->latestPackage;
        if ($package === null) {
            Log::info('DrawIoBuilderService: no latest package — emitting empty graph', [
                'project_id' => $project->id,
            ]);

            return $this->emitEmptyGraph();
        }

        $lines = $project->devicesWithStencils();
        if ($lines === []) {
            return $this->emitEmptyGraph();
        }

        // ── 1. Zone-group (D-01/D-02/D-04 + OQ-1 Path B — Plan 23-02) ──
        $zoned = $this->zones->assign($lines);

        // ── 2. Layout: zone containers + device cells (DRAW-42, DRAW-46) ──
        $deviceCells = $this->layout->placeDevices($zoned);

        // ── 3. Enrich device cells with device_id + category for CableRouter ──
        $enrichedDeviceCells = $this->enrichDeviceCellsWithDeviceIds($project, $deviceCells);

        // ── 4. Cables: port-to-port edges (DRAW-43/44/45 — Plan 23-03) ──
        $cableCells = $this->cables->emitCables($project, $enrichedDeviceCells);

        // ── 5. Paginate (DRAW-47 — Plan 23-04) ──
        $sheets = $this->paginator->classify($project);

        // ── 6. Resolve drawing for revision lookup (D-08) ──
        // Latest non-superseded schematic drawing — TitleBlockRenderer falls
        // back to 'R0' when null.
        $drawing = $project->drawings()
            ->where('kind', ProjectDrawing::KIND_SCHEMATIC)
            ->where('status', '!=', ProjectDrawing::STATUS_SUPERSEDED)
            ->latest('updated_at')
            ->first();

        // ── 7. Serialise each sheet → <diagram> → wrap all in <mxfile> ──
        $diagrams = [];
        foreach ($sheets as $sheet) {
            $sheetCells = $this->composeSheet(
                $sheet,
                $enrichedDeviceCells,
                $cableCells,
                $project,
                $drawing,
            );
            $diagrams[] = $this->emitDiagram($sheet, $sheetCells);
        }

        $xml = $this->emitMxFile($diagrams);

        if (strlen($xml) > self::LARGE_XML_THRESHOLD_BYTES) {
            Log::warning('DrawIoBuilderService: large XML payload approaching 5 MB postMessage cap', [
                'project_id'  => $project->id,
                'sheet_count' => count($sheets),
                'byte_count'  => strlen($xml),
            ]);
        }

        return $xml;
    }

    /**
     * Splice `device_id` (Phase 4 Devices table) and `category` onto each
     * device cell descriptor so CableRouter can map cable_schedule_items.
     * (source|dest)_device_id back to a device cell. Matches by part_number.
     *
     * No-op for non-device descriptors (zones flow through unchanged).
     *
     * @param  array<int, array<string, mixed>>  $deviceCells
     * @return array<int, array<string, mixed>>
     */
    private function enrichDeviceCellsWithDeviceIds(Project $project, array $deviceCells): array
    {
        // Build a part_number → first-available device_id map. Phase 23
        // fixtures use Device::create with part_no = stencil part_number;
        // legacy projects that pre-date Phase 4 just won't match (and the
        // CableRouter skips cables whose device_ids aren't on the sheet).
        $project->loadMissing('devices');
        $byPartNumber = [];
        foreach ($project->devices as $device) {
            $key = strtolower((string) ($device->part_no ?? ''));
            if ($key === '' || isset($byPartNumber[$key])) {
                continue;
            }
            $byPartNumber[$key] = $device->id;
        }

        $enriched = [];
        foreach ($deviceCells as $cell) {
            if (($cell['kind'] ?? '') !== 'device') {
                $enriched[] = $cell;
                continue;
            }
            $partKey = strtolower((string) ($cell['part_number'] ?? ''));
            $deviceId = $byPartNumber[$partKey] ?? null;

            $stencil = $cell['stencil'] ?? null;
            $category = '';
            if (is_object($stencil)) {
                $category = (string) ($stencil->category ?? $stencil->source_category ?? '');
            }

            $enriched[] = $cell + [
                'device_id' => $deviceId,
                'category'  => $category,
            ];
        }

        return $enriched;
    }

    /**
     * Compose the ordered cell list for one sheet.
     *
     * Rules:
     *   - system_overview sheet → ALL borders + zones + devices + edges
     *   - sub-sheet (signal_filter set) → filter edges to that signal type;
     *     filter device cells to the union of devices touched by surviving
     *     edges; keep only zones that still have at least one surviving
     *     device child.
     *   - Border first (renders behind content), title block last (renders
     *     on top — mxGraph z-order = document order).
     *
     * @param  array{key: string, sheet_number: string, title: string, signal_filter: ?string}  $sheet
     * @param  array<int, array<string, mixed>>  $deviceCells
     * @param  array<int, array<string, mixed>>  $cableCells
     * @return array<int, array<string, mixed>>
     */
    private function composeSheet(
        array $sheet,
        array $deviceCells,
        array $cableCells,
        Project $project,
        ?ProjectDrawing $drawing,
    ): array {
        $cells = [];

        // ── Border (renders behind content) ──
        foreach ($this->sheetBorder->render() as $border) {
            $cells[] = $border;
        }

        if ($sheet['signal_filter'] !== null) {
            $signal = $sheet['signal_filter'];

            // Filter edges to this signal (warn glyphs always carry through
            // since they're paired with their parent edge).
            $filteredEdges = array_values(array_filter(
                $cableCells,
                fn ($c) => ($c['kind'] ?? '') === 'warn'
                    || (($c['kind'] ?? '') === 'edge' && ($c['signal'] ?? null) === $signal),
            ));

            // Union of device-cell ids touched by surviving edges.
            $touchedIds = [];
            foreach ($filteredEdges as $edge) {
                if (($edge['kind'] ?? '') !== 'edge') {
                    continue;
                }
                if (! empty($edge['source'])) {
                    $touchedIds[$edge['source']] = true;
                }
                if (! empty($edge['target'])) {
                    $touchedIds[$edge['target']] = true;
                }
            }

            // Pass 1 — keep zones (provisionally) + device cells whose id is touched.
            $survivingDevicesAndZones = array_values(array_filter(
                $deviceCells,
                function (array $c) use ($touchedIds): bool {
                    $kind = (string) ($c['kind'] ?? '');
                    if ($kind === 'zone') {
                        // Provisional — pass 2 drops zones that have no children.
                        return true;
                    }

                    return $kind === 'device' && isset($touchedIds[$c['id']]);
                },
            ));

            // Pass 2 — drop zones with zero surviving device children.
            $survivingDevicesAndZones = array_values(array_filter(
                $survivingDevicesAndZones,
                function (array $c) use ($survivingDevicesAndZones): bool {
                    if (($c['kind'] ?? '') !== 'zone') {
                        return true;
                    }
                    foreach ($survivingDevicesAndZones as $other) {
                        if (($other['kind'] ?? '') === 'device'
                            && (($other['parent'] ?? '') === $c['id'])
                        ) {
                            return true;
                        }
                    }

                    return false;
                },
            ));

            foreach ($survivingDevicesAndZones as $c) {
                $cells[] = $c;
            }
            foreach ($filteredEdges as $c) {
                $cells[] = $c;
            }
        } else {
            // System overview — everything.
            foreach ($deviceCells as $c) {
                $cells[] = $c;
            }
            foreach ($cableCells as $c) {
                $cells[] = $c;
            }
        }

        // ── Title block (renders above content visually) ──
        foreach ($this->titleBlock->render($sheet, $project, $drawing) as $c) {
            $cells[] = $c;
        }

        return $cells;
    }

    /**
     * Serialise one sheet's cells into a <diagram><mxGraphModel>…</mxGraphModel></diagram>.
     *
     * @param  array{key: string, sheet_number: string, title: string, signal_filter: ?string}  $sheet
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function emitDiagram(array $sheet, array $cells): string
    {
        $page = (array) config('drawings.page_dimensions', []);
        $w = (int) ($page['width']  ?? 1600);
        $h = (int) ($page['height'] ?? 1000);

        $body = '<root><mxCell id="0"/><mxCell id="1" parent="0"/>';
        foreach ($cells as $cell) {
            $body .= $this->serialiseCell($cell);
        }
        $body .= '</root>';

        return sprintf(
            '<diagram name="%s" id="sheet-%s"><mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="%d" pageHeight="%d" math="0" shadow="0">%s</mxGraphModel></diagram>',
            $this->xml((string) ($sheet['title'] ?? '')),
            $this->xml((string) ($sheet['key'] ?? '')),
            $w, $h, $body,
        );
    }

    /**
     * Wrap N <diagram> elements in <mxfile> per draw.io multi-page format
     * (23-RESEARCH.md Example 4 — host/agent/version attributes match the
     * spike's expected wrapper shape).
     *
     * @param  array<int, string>  $diagrams
     */
    private function emitMxFile(array $diagrams): string
    {
        return '<mxfile host="app.diagrams.net" agent="21cav-rams-renderer/v23" version="29.7.12">'
            . implode('', $diagrams)
            . '</mxfile>';
    }

    /**
     * Serialise one cell descriptor into mxGraph XML.
     *
     * CRITICAL: the cell's `value` and `style` strings are ALREADY xml-escaped
     * by the helper that emitted the descriptor (ZoneGrouper / XtenAvLayoutEngine /
     * CableRouter / TitleBlockRenderer all run htmlspecialchars before they
     * place a string in the value attribute). The orchestrator MUST NOT
     * re-escape — that would double-encode every user string and break the
     * visual contract.
     *
     * @param  array<string, mixed>  $cell
     */
    private function serialiseCell(array $cell): string
    {
        $kind = (string) ($cell['kind'] ?? '');

        if ($kind === 'edge') {
            return sprintf(
                '<mxCell id="%s" value="%s" style="%s" edge="1" parent="1" source="%s" target="%s"><mxGeometry relative="1" as="geometry"/></mxCell>',
                (string) $cell['id'],
                (string) ($cell['value'] ?? ''),
                $this->xml((string) ($cell['style'] ?? '')),
                (string) ($cell['source'] ?? ''),
                (string) ($cell['target'] ?? ''),
            );
        }

        // vertex cells (zone / device / warn / border / title-block-field)
        return sprintf(
            '<mxCell id="%s" value="%s" style="%s" vertex="1" parent="%s"><mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry"/></mxCell>',
            (string) $cell['id'],
            (string) ($cell['value'] ?? ''),
            $this->xml((string) ($cell['style'] ?? '')),
            (string) ($cell['parent'] ?? '1'),
            (int) ($cell['x'] ?? 0),
            (int) ($cell['y'] ?? 0),
            (int) ($cell['w'] ?? 80),
            (int) ($cell['h'] ?? 40),
        );
    }

    /**
     * Empty-project backwards-compat shape — single `<mxGraphModel>` with no
     * `<mxfile>` wrapper. Mirrors Phase 21 P03's emptyGraph() so
     * `test_empty_package_emits_valid_empty_graph` stays green after the
     * Plan 05 rewire.
     */
    private function emitEmptyGraph(): string
    {
        $page = (array) config('drawings.page_dimensions', []);
        $w = (int) ($page['width']  ?? 1600);
        $h = (int) ($page['height'] ?? 1000);

        return sprintf(
            '<mxGraphModel dx="1200" dy="800" pageWidth="%d" pageHeight="%d"><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel>',
            $w, $h,
        );
    }

    /**
     * XSS-safe XML escape — used ONLY for orchestrator-owned strings (sheet
     * title, sheet key, style strings). User-controlled value attributes are
     * already escaped upstream by the per-cell emitter helpers.
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
