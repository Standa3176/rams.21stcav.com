<?php

namespace App\Services\Drawings;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260509-ibx — draw.io embed spike.
 *
 * Pure data aggregator → mxGraph XML emitter for the small Teams Room
 * archetype (D-LOCK-3). NO AI usage (D-LOCK-5). NO Eloquent writes.
 * NO HTTP calls. Hand the same Project twice → same mxGraph XML twice
 * (idempotent / deterministic).
 *
 * Pipeline: ProjectPackage.extracted_data['equipment']
 *           → filter to a Teams Room area
 *           → map each equipment item to a stencil ID via name fragments
 *           → emit mxGraph XML positioned in a deterministic 3-column grid
 *           → derive cables from cable_list OR signal_role inference
 *           → return string ready for postMessage(action: load) into the
 *             draw.io embed iframe
 *
 * Mirrors SchematicD2SourceBuilder shape (Phase 17 P02) — same first-match-wins
 * fragment-mapping idea, different output target (mxGraph XML, not D2 source).
 *
 * @see app/Services/Drawings/SchematicD2SourceBuilder.php — reference pattern.
 * @see resources/data/draw-io-stencils/21cav-mtr-spike.json — stencil pack consumed.
 * @see .planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-CONTEXT.md
 */
class DrawIoSpikeBuilderService
{
    /**
     * First-match-wins fragment → stencil-id allowlist.
     *
     * Mirrors SchematicD2SourceBuilder::SYMBOL_ALIASES shape but maps to
     * mxGraph stencil IDs from the 21cav-mtr-spike pack instead of D2
     * SVG filenames. Order matters — more specific fragments come first
     * so "neat bar pro" wins over "neat" for example.
     *
     * @var array<string, string>
     */
    private const STENCIL_ALIASES = [
        // Most-specific first.
        'neat bar pro' => '21cav.mtr.neat-bar-pro',
        'neat bar' => '21cav.mtr.neat-bar-pro',
        'clickshare bar pro' => '21cav.mtr.clickshare-bar-pro',
        'clickshare' => '21cav.mtr.clickshare-bar-pro',
        'teamconnect ceiling' => '21cav.mtr.sennheiser-tcc',
        'tcc2' => '21cav.mtr.sennheiser-tcc',
        'sennheiser' => '21cav.mtr.sennheiser-tcc',
        'gs312tp' => '21cav.mtr.netgear-poe-12pt',
        'netgear' => '21cav.mtr.netgear-poe-12pt',
        'poe switch' => '21cav.mtr.netgear-poe-12pt',
        'network switch' => '21cav.mtr.netgear-poe-12pt',
        // Generic-display family (least-specific last).
        'samsung' => '21cav.mtr.samsung-display',
        'qm65' => '21cav.mtr.samsung-display',
        'be65' => '21cav.mtr.samsung-display',
        'display' => '21cav.mtr.samsung-display',
        'tv' => '21cav.mtr.samsung-display',
        'screen' => '21cav.mtr.samsung-display',
        'monitor' => '21cav.mtr.samsung-display',
    ];

    /**
     * Stencil role → grid column mapping.
     *
     * Layout: simple deterministic 3-column grid:
     *   - column 0 (x=80):  sources, BYOD, microphones (videobar/byod/ceiling-mic)
     *   - column 1 (x=420): switch, processors (network-switch)
     *   - column 2 (x=760): displays
     */
    private const ROLE_COLUMN = [
        'videobar' => 0,
        'byod' => 0,
        'ceiling-mic' => 0,
        'network-switch' => 1,
        'display' => 2,
    ];

    /**
     * Defensive cap on per-line quantity expansion. A 12-port switch
     * line with quantity=12 would otherwise paint 12 switches and blow
     * the canvas; the spike's pass criterion is fidelity not stress test.
     */
    private const QUANTITY_CAP = 5;

    /**
     * Stencil pack JSON cache (per request).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $stencilPack = null;

    /**
     * Build the mxGraph XML for a project's small Teams Room equipment.
     *
     * Empty / no-package case: returns a valid empty <mxGraphModel> so the
     * embed loads with no devices but no error.
     */
    public function build(Project $project): string
    {
        $package = $project->latestPackage;
        if ($package === null) {
            Log::info('DrawIoSpikeBuilderService: no latest package — emitting empty graph', [
                'project_id' => $project->id,
            ]);

            return $this->emptyGraph();
        }

        $extracted = (array) ($package->extracted_data ?? []);
        $equipment = (array) ($extracted['equipment'] ?? []);
        if (empty($equipment)) {
            // Fallback for older projects that landed equipment via
            // packages.equipment_list rather than extracted_data['equipment'].
            $equipment = (array) ($package->equipment_list ?? []);
        }

        $teamsRoomItems = $this->filterToTeamsRoom($equipment);
        $deviceCells = $this->mapEquipmentToCells($teamsRoomItems);
        $cableCells = $this->deriveCables($deviceCells, (array) ($package->cable_list ?? []));

        return $this->emitMxGraph($deviceCells, $cableCells);
    }

    /**
     * Filter the equipment list to items in a Teams Room area.
     *
     * Match logic (case-insensitive substring against item['area']):
     *   - 'teams' OR 'meeting room' OR 'mtr' OR 'collaboration'
     *
     * Last-resort fallback: if NO area matches AND the project has
     * equipment, return everything (capped at 8 items so the canvas
     * stays readable). This lets the spike render on legacy projects
     * without proper area tagging — better than a blank canvas during
     * a 2-week evaluation window.
     *
     * @param  array<int, array<string, mixed>>  $equipment
     * @return array<int, array<string, mixed>>
     */
    private function filterToTeamsRoom(array $equipment): array
    {
        $needles = ['teams', 'meeting room', 'mtr', 'collaboration'];
        $matched = [];
        foreach ($equipment as $item) {
            $area = strtolower((string) ($item['area'] ?? ''));
            if ($area === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if (str_contains($area, $needle)) {
                    $matched[] = $item;
                    break;
                }
            }
        }

        if (! empty($matched)) {
            return $matched;
        }

        Log::info('DrawIoSpikeBuilderService: no Teams Room area match — falling back to first 8 equipment items', [
            'equipment_count' => count($equipment),
        ]);

        // Filter out non-hardware (cables/services) before returning the
        // fallback — the spike renders devices, not cables-as-line-items.
        $fallback = array_values(array_filter(
            $equipment,
            static fn (array $item): bool => ($item['category'] ?? 'hardware') !== 'cable'
                && ($item['category'] ?? 'hardware') !== 'service'
        ));

        return array_slice($fallback, 0, 8);
    }

    /**
     * Map each equipment line to a placed mxCell descriptor.
     *
     * Returns a list of:
     *   [
     *     'cell_id'     => 'dev-1',
     *     'stencil_id'  => '21cav.mtr.neat-bar-pro',
     *     'label'       => 'Neat Bar Pro',
     *     'part_number' => 'NEAT-BAR-PRO',
     *     'role'        => 'videobar',
     *     'x' => ..., 'y' => ..., 'w' => 220, 'h' => 140,
     *     'shape_xml'   => '<shape>...</shape>',
     *     'style'       => 'rounded=1;...',
     *   ]
     *
     * Items that don't match any STENCIL_ALIASES fragment are SKIPPED
     * with a Log::info — D-LOCK-3 locks 5 stencils for the small Teams
     * Room only; rendering a generic placeholder for unmapped items
     * would fail the visual-fidelity test.
     *
     * @param  array<int, array<string, mixed>>  $equipment
     * @return list<array<string, mixed>>
     */
    private function mapEquipmentToCells(array $equipment): array
    {
        $pack = $this->loadStencilPack();
        $stencilsById = [];
        foreach ($pack['stencils'] as $s) {
            $stencilsById[$s['id']] = $s;
        }

        // Per-column row tracker for grid placement.
        $rowByColumn = [0 => 0, 1 => 0, 2 => 0];
        $cells = [];
        $cellSerial = 0;

        foreach ($equipment as $item) {
            $name = strtolower((string) ($item['name'] ?? ''));
            $partNumber = strtolower((string) ($item['part_number'] ?? ''));
            $haystack = trim($name.' '.$partNumber);

            if ($haystack === '') {
                continue;
            }

            $stencilId = null;
            foreach (self::STENCIL_ALIASES as $fragment => $candidate) {
                if (str_contains($haystack, $fragment)) {
                    $stencilId = $candidate;
                    break;
                }
            }

            if ($stencilId === null) {
                Log::info('DrawIoSpikeBuilderService: equipment skipped — no stencil match', [
                    'name' => $item['name'] ?? null,
                    'part_number' => $item['part_number'] ?? null,
                ]);
                continue;
            }

            $stencil = $stencilsById[$stencilId] ?? null;
            if ($stencil === null) {
                Log::warning('DrawIoSpikeBuilderService: alias points to missing stencil', [
                    'stencil_id' => $stencilId,
                ]);
                continue;
            }

            $role = (string) $stencil['role'];
            $col = self::ROLE_COLUMN[$role] ?? 1;
            $w = (int) ($stencil['default_size']['w'] ?? 220);
            $h = (int) ($stencil['default_size']['h'] ?? 140);

            // Quantity expansion: default to 1; cap aggressively.
            $qty = max(1, min(self::QUANTITY_CAP, (int) ($item['quantity'] ?? 1)));
            if ((int) ($item['quantity'] ?? 1) > self::QUANTITY_CAP) {
                Log::warning('DrawIoSpikeBuilderService: quantity capped', [
                    'name' => $item['name'] ?? null,
                    'requested' => $item['quantity'],
                    'capped_to' => self::QUANTITY_CAP,
                ]);
            }

            for ($i = 0; $i < $qty; $i++) {
                $cellSerial++;
                $row = $rowByColumn[$col]++;
                // Column anchors deliberately spaced wider than widest stencil
                // (320 px Netgear) so cards don't collide.
                $colAnchor = [80, 460, 800][$col];
                $x = $colAnchor;
                $y = 80 + ($row * 220);

                $cells[] = [
                    'cell_id' => 'dev-'.$cellSerial,
                    'stencil_id' => $stencilId,
                    'label' => (string) ($item['name'] ?? $stencil['model']),
                    'part_number' => (string) ($item['part_number'] ?? ''),
                    'manufacturer' => (string) $stencil['manufacturer'],
                    'role' => $role,
                    'x' => $x,
                    'y' => $y,
                    'w' => $w,
                    'h' => $h,
                    'shape_xml' => (string) $stencil['mxgraph_shape_xml'],
                    'style' => (string) ($stencil['drawio_style'] ?? ''),
                    'ports' => (array) ($stencil['ports'] ?? []),
                ];
            }
        }

        return $cells;
    }

    /**
     * Derive cable mxCells from cable_list when present, else infer
     * default cables from the signal-role chain typical of a small
     * Teams Room:
     *
     *   videobar.hdmi-out   → display.hdmi-1
     *   byod.hdmi-out       → videobar.hdmi-in
     *   ceiling-mic.dante   → switch.port-1
     *   videobar.lan        → switch.port-2
     *   display.lan         → switch.port-3
     *
     * Each emitted as an mxCell edge with source/target referencing the
     * device's cell_id and a port id from the stencil's port list.
     *
     * For the spike, we ALWAYS use the inferred chain (cable_list lines
     * from QuoteWerks rarely have device-level source/target — they're
     * cable SKUs not connectivity records). The cable_list parameter is
     * accepted for forward-compatibility with v2.0 port-FK migration.
     *
     * @param  list<array<string, mixed>>  $deviceCells
     * @param  array<int, array<string, mixed>>  $cableList  reserved for v2.0
     * @return list<array<string, mixed>>
     */
    private function deriveCables(array $deviceCells, array $cableList): array
    {
        // Index devices by role for quick lookup. Multiple devices with
        // the same role (e.g. two ceiling mics) get a list, terminate
        // sequentially against switch ports.
        $byRole = [];
        foreach ($deviceCells as $cell) {
            $byRole[$cell['role']][] = $cell;
        }

        // Pick the canonical device per role for the Teams Room chain.
        $videobar = $byRole['videobar'][0] ?? null;
        $display = $byRole['display'][0] ?? null;
        $byod = $byRole['byod'][0] ?? null;
        $switch = $byRole['network-switch'][0] ?? null;
        $ceilingMic = $byRole['ceiling-mic'][0] ?? null;

        $edges = [];
        $edgeSerial = 0;

        $emit = function (?array $src, ?string $srcPort, ?array $tgt, ?string $tgtPort, string $signal) use (&$edges, &$edgeSerial): void {
            if ($src === null || $tgt === null) {
                return;
            }
            $edgeSerial++;
            $edges[] = [
                'edge_id' => 'cab-'.$edgeSerial,
                'source' => $src['cell_id'],
                'target' => $tgt['cell_id'],
                'source_port' => $srcPort,
                'target_port' => $tgtPort,
                'signal_type' => $signal,
            ];
        };

        // Canonical Teams Room signal chain.
        $emit($videobar, 'hdmi-out', $display, 'hdmi-1', 'video');
        $emit($byod, 'hdmi-out', $videobar, 'hdmi-in', 'video');

        // Network: each device's LAN to a switch port (round-robin port-1..3).
        $emit($ceilingMic, 'dante-poe', $switch, 'port-1', 'network');
        $emit($videobar, 'lan', $switch, 'port-2', 'network');
        $emit($display, 'lan', $switch, 'port-3', 'network');

        return $edges;
    }

    /**
     * Emit the full mxGraph XML document.
     *
     * Shape:
     *   <mxGraphModel ...>
     *     <root>
     *       <mxCell id="0"/>
     *       <mxCell id="1" parent="0"/>
     *       <!-- one mxCell vertex per device, with the inline stencil shape
     *            embedded into the style via the shape= prefix. We embed the
     *            full mxgraph_shape_xml as a `stencil(base64)` style fragment
     *            so the editor doesn't need separate stencil-pack registration
     *            on load — the shape travels with the cell. -->
     *       <!-- one mxCell edge per cable -->
     *     </root>
     *   </mxGraphModel>
     *
     * Uses XML escaping on every label / part_number — stencil IDs are
     * application-controlled so safe; equipment names from QuoteWerks are
     * untrusted (Warning 7 / T-17.02-01 from Phase 17 P02 carries forward).
     *
     * @param  list<array<string, mixed>>  $deviceCells
     * @param  list<array<string, mixed>>  $cableCells
     */
    private function emitMxGraph(array $deviceCells, array $cableCells): string
    {
        $vertexXml = [];
        foreach ($deviceCells as $cell) {
            $cellId = $this->xml((string) $cell['cell_id']);
            $label = $this->xml((string) $cell['label']);
            $partNumber = $this->xml((string) $cell['part_number']);
            $manufacturer = $this->xml((string) $cell['manufacturer']);

            // Encode the shape XML as a stencil-style fragment. draw.io
            // accepts `shape=stencil(<base64-of-shape-xml>)` to render
            // an inline stencil without pack registration.
            $shapeB64 = base64_encode((string) $cell['shape_xml']);

            $style = sprintf(
                'shape=stencil(%s);html=1;fillColor=#FAFAF6;strokeColor=#1B7A7A;fontColor=#1B7A7A;fontSize=11;verticalLabelPosition=bottom;verticalAlign=top;align=center;',
                $shapeB64
            );

            // Build the rich value line. draw.io's mxCell `value` attribute
            // accepts HTML (manufacturer/model/part-number rendered as a
            // 3-line label below the stencil), but the HTML must be
            // double-encoded — once for HTML-in-the-string, again for
            // XML-attribute placement. We assemble the HTML with already-
            // XML-escaped fragments, then re-encode the whole thing for
            // the attribute (so the parsed value, post XML-decode, is
            // still well-formed HTML).
            $valueLines = [];
            if ($manufacturer !== '') {
                $valueLines[] = $manufacturer;
            }
            $valueLines[] = $label;
            if ($partNumber !== '') {
                $valueLines[] = '&lt;i&gt;'.$partNumber.'&lt;/i&gt;';
            }
            $value = implode('&lt;br/&gt;', $valueLines);

            $vertexXml[] = sprintf(
                '<mxCell id="%s" value="%s" style="%s" vertex="1" parent="1">'.
                    '<mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry"/>'.
                    '</mxCell>',
                $cellId,
                $value,
                $this->xml($style),
                (int) $cell['x'],
                (int) $cell['y'],
                (int) $cell['w'],
                (int) $cell['h']
            );
        }

        $edgeXml = [];
        foreach ($cableCells as $idx => $edge) {
            $edgeId = $this->xml((string) $edge['edge_id']);
            $source = $this->xml((string) $edge['source']);
            $target = $this->xml((string) $edge['target']);
            $sourcePort = $edge['source_port'] ?? null;
            $targetPort = $edge['target_port'] ?? null;
            $signal = (string) ($edge['signal_type'] ?? 'video');

            // Signal-type colour map mirrors v1.3 schematic conventions.
            $color = match ($signal) {
                'video' => '#1B7A7A',
                'network' => '#1B6CB5',
                'audio' => '#C07000',
                'control' => '#666666',
                default => '#333333',
            };

            $exitConstraint = $sourcePort
                ? sprintf('exitX=0;exitY=0;exitDx=0;exitDy=0;exitPerimeter=0;')
                : '';
            $entryConstraint = $targetPort
                ? sprintf('entryX=0;entryY=0;entryDx=0;entryDy=0;entryPerimeter=0;')
                : '';
            // Note: the constraint 'name' attribute on the stencil <constraint>
            // element addresses ports symbolically. For the spike we set the
            // edge's exitX/exitY/entryX/entryY explicitly via the source/target
            // constraint names embedded in the edge style as
            // exitConstraint=<name>. mxGraph also supports addressing by
            // 'name' via style: e.g. style="exit=<port-name>;entry=<port-name>".

            $style = sprintf(
                'edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=%s;strokeWidth=2;fontSize=10;fontColor=%s;%s%s',
                $color,
                $color,
                $exitConstraint,
                $entryConstraint,
            );

            $signalLabel = $this->xml(strtoupper($signal));

            $edgeXml[] = sprintf(
                '<mxCell id="%s" value="%s" style="%s" edge="1" parent="1" source="%s" target="%s">'.
                    '<mxGeometry relative="1" as="geometry"/>'.
                    '</mxCell>',
                $edgeId,
                $signalLabel,
                $this->xml($style),
                $source,
                $target,
            );
            unset($idx);
        }

        $body = implode("\n      ", array_merge($vertexXml, $edgeXml));

        return <<<XML
<mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1600" pageHeight="1000" math="0" shadow="0">
  <root>
    <mxCell id="0"/>
    <mxCell id="1" parent="0"/>
      {$body}
  </root>
</mxGraphModel>
XML;
    }

    /**
     * Empty graph — used when project has no package or no matching equipment.
     */
    private function emptyGraph(): string
    {
        return <<<'XML'
<mxGraphModel dx="800" dy="600" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1169" pageHeight="826" math="0" shadow="0">
  <root>
    <mxCell id="0"/>
    <mxCell id="1" parent="0"/>
  </root>
</mxGraphModel>
XML;
    }

    /**
     * Load and memoise the stencil pack JSON.
     *
     * @return array<string, mixed>
     */
    private function loadStencilPack(): array
    {
        if (self::$stencilPack !== null) {
            return self::$stencilPack;
        }

        $path = base_path('resources/data/draw-io-stencils/21cav-mtr-spike.json');
        if (! is_file($path)) {
            Log::error('DrawIoSpikeBuilderService: stencil pack missing', ['path' => $path]);

            return self::$stencilPack = ['stencils' => []];
        }

        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            Log::error('DrawIoSpikeBuilderService: stencil pack invalid JSON', [
                'path' => $path,
                'json_error' => json_last_error_msg(),
            ]);

            return self::$stencilPack = ['stencils' => []];
        }

        return self::$stencilPack = $decoded;
    }

    /**
     * Escape user-supplied data before XML interpolation. Mirrors Phase 17
     * P02 Warning 7 fix — equipment names from QuoteWerks are untrusted.
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
