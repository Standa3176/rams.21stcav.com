<?php

namespace App\Services\Drawings;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Phase 21 Plan 03 — DB-backed mxGraph builder for the draw.io embed.
 *
 * Generalises the spike's hand-coded `DrawIoSpikeBuilderService` to read
 * stencils from the new `device_stencils` table (Plan 21-01 schema, Plan
 * 21-02 seed pack). Every hardware part_number on a project produces a
 * stencil cell — curated devices render with full port detail; uncatalogued
 * devices render as Tier 1 placeholders auto-created by `DeviceStencilCacheService`
 * on first read (`Project::devicesWithStencils()` does the cache miss → auto
 * insert side-effect).
 *
 * Contract:
 *   build(Project $project): string  // returns mxGraphModel XML
 *
 * Layout heuristic — INTENTIONALLY SHALLOW (Nit 9 fix):
 *   Scoped to manufacturer-logo placement + a coarse 4-column grid for
 *   visual recognisability. Lines whose stencil's part_number matches one
 *   of the 5 spike-promoted slugs (neat-bar-pro, samsung-qm65c-t,
 *   clickshare-bar-pro, sennheiser-tcc2, netgear-gs312tp) use the role
 *   from STENCIL_ROLES. Everything else lands in a 4th "other" column on
 *   the right. NO port-composition heuristic dispatch (network-switch
 *   column inference, audio-in dominance for mic column, etc.) — that's
 *   Phase 23.
 *
 *   // TODO(phase-23): replace this 4-column shallow heuristic with a
 *   // proper category-metadata-driven layout engine. The current logic is
 *   // sized to "stop the spike admin route from regressing visually" —
 *   // NOT to be the long-term layout strategy. See
 *   // .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md
 *   // deferred section ("Full role-inference engine — Phase 23").
 *
 * Cable derivation: preserves the spike's canonical Teams Room chain
 * (videobar → display, byod → videobar, ceiling-mic / videobar / display
 * → switch). Phase 22 replaces this with port-level FK routing once
 * `cable_schedule_items.source_port_id` / `dest_port_id` are migrated.
 *
 * NO AI usage. NO Eloquent writes (the cache miss → Tier 1 auto-create
 * side-effect happens inside Project::devicesWithStencils, NOT here).
 * Hand the same Project twice → same mxGraph XML twice (deterministic).
 *
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see app/Services/Drawings/ManufacturerLogoResolver.php
 * @see app/Models/Project.php — devicesWithStencils() accessor (Plan 21-01)
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php — backwards-compat shim
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-06, D-08, Nit 9)
 */
class DrawIoBuilderService
{
    /**
     * Spike-promoted slug → role lookup. SHALLOW per Nit 9 — used only for
     * the 4-column layout grid, NOT for port-composition heuristics.
     *
     * Phase 23 replaces this with `device_stencils.metadata.role` plus a
     * proper layout engine driven by port-composition rules.
     *
     * @var array<string, string>
     */
    private const STENCIL_ROLES = [
        'neat-bar-pro' => 'videobar',
        'clickshare-bar-pro' => 'byod',
        'sennheiser-tcc2' => 'ceiling-mic',
        'netgear-gs312tp' => 'network-switch',
        'samsung-qm65c-t' => 'display',
    ];

    /**
     * Role → grid column mapping. Same shape as the spike's ROLE_COLUMN
     * constant; "other" column added to absorb non-spike stencils per
     * Nit 9.
     *
     * Layout: deterministic 4-column grid:
     *   - column 0 (x=80):   sources, BYOD, microphones (videobar/byod/ceiling-mic)
     *   - column 1 (x=460):  switch, processors (network-switch)
     *   - column 2 (x=800):  displays
     *   - column 3 (x=1140): everything else (Tier 1 placeholders, uncatalogued)
     */
    private const ROLE_COLUMN = [
        'videobar' => 0,
        'byod' => 0,
        'ceiling-mic' => 0,
        'network-switch' => 1,
        'display' => 2,
        'other' => 3,
    ];

    private const COLUMN_ANCHORS = [80, 460, 800, 1140];

    /**
     * Defensive cap on per-line quantity expansion. Mirrors the spike's
     * QUANTITY_CAP — a 12-port switch line with quantity=12 would
     * otherwise paint 12 switches and blow the canvas.
     */
    private const QUANTITY_CAP = 5;

    /**
     * Note: $cache is constructor-injected even though build() doesn't call
     * it directly — the cache miss → Tier 1 auto-create side-effect lives
     * inside Project::devicesWithStencils() (Plan 21-01). Declaring the
     * dependency here makes the contract explicit (this builder depends
     * on the cache contract being honoured by the model layer) AND keeps
     * the container wiring honest if a future refactor moves the call
     * site back into the builder.
     */
    public function __construct(
        private readonly DeviceStencilCacheService $cache,
        private readonly ManufacturerLogoResolver $logos,
    ) {}

    /**
     * Build the mxGraph XML for a project's devices.
     *
     * Empty / no-package case: returns a valid empty <mxGraphModel> so the
     * embed loads with no devices but no error.
     */
    public function build(Project $project): string
    {
        $package = $project->latestPackage;
        if ($package === null) {
            Log::info('DrawIoBuilderService: no latest package — emitting empty graph', [
                'project_id' => $project->id,
            ]);

            return $this->emptyGraph();
        }

        $lines = $project->devicesWithStencils();

        if ($lines === []) {
            return $this->emptyGraph();
        }

        $deviceCells = $this->mapLinesToCells($lines);
        $cableCells = $this->deriveCables($deviceCells, (array) ($package->cable_list ?? []));

        return $this->emitMxGraph($deviceCells, $cableCells);
    }

    /**
     * Map enriched lines (from Project::devicesWithStencils()) to placed
     * mxCell descriptors via the SHALLOW 4-column grid heuristic.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function mapLinesToCells(array $lines): array
    {
        $rowByColumn = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        $cells = [];
        $cellSerial = 0;

        foreach ($lines as $line) {
            $stencil = $line['stencil'] ?? null;
            if ($stencil === null) {
                Log::info('DrawIoBuilderService: line skipped — null stencil', [
                    'part_number' => $line['part_number'] ?? null,
                ]);

                continue;
            }

            // Shallow role inference per Nit 9 — spike-slug lookup ONLY.
            $role = self::STENCIL_ROLES[$stencil->part_number] ?? 'other';
            $col = self::ROLE_COLUMN[$role] ?? 3;
            $w = (int) ($stencil->default_width ?? 220);
            $h = (int) ($stencil->default_height ?? 140);

            $qty = max(1, min(self::QUANTITY_CAP, (int) ($line['quantity'] ?? 1)));
            $requested = (int) ($line['quantity'] ?? 1);
            if ($requested > self::QUANTITY_CAP) {
                Log::warning('DrawIoBuilderService: quantity capped', [
                    'part_number' => $stencil->part_number,
                    'requested' => $requested,
                    'capped_to' => self::QUANTITY_CAP,
                ]);
            }

            for ($i = 0; $i < $qty; $i++) {
                $cellSerial++;
                $row = $rowByColumn[$col]++;
                $colAnchor = self::COLUMN_ANCHORS[$col];
                $x = $colAnchor;
                $y = 80 + ($row * 220);

                $manufacturer = (string) ($stencil->manufacturer ?? ($line['manufacturer'] ?? ''));
                $logoSvg = $this->logos->resolveSvg($manufacturer);

                $cells[] = [
                    'cell_id' => 'dev-'.$cellSerial,
                    'stencil_id' => 'db.'.$stencil->id,
                    'label' => (string) ($line['name'] ?? $stencil->display_name ?? $stencil->part_number),
                    'part_number' => (string) ($line['part_number'] ?? $stencil->part_number),
                    'manufacturer' => $manufacturer,
                    'manufacturer_logo' => $logoSvg, // null when unmatched (graceful degrade per Nit 9)
                    'role' => $role,
                    'x' => $x,
                    'y' => $y,
                    'w' => $w,
                    'h' => $h,
                    'shape_xml' => (string) $stencil->mxgraph_xml,
                ];
            }
        }

        return $cells;
    }

    /**
     * Derive cable mxCells from the canonical Teams Room chain.
     *
     * For Plan 21-03 we preserve the spike's deriveCables logic VERBATIM —
     * the cable_list parameter is accepted for forward-compatibility with
     * Phase 22's port-FK migration (which will replace this with proper
     * `source_port_id` / `dest_port_id` lookup). Today QuoteWerks cable
     * lines rarely carry device-level source/target so the inferred chain
     * is the only useful default.
     *
     * @param  list<array<string, mixed>>  $deviceCells
     * @param  array<int, array<string, mixed>>  $cableList  reserved for Phase 22
     * @return list<array<string, mixed>>
     */
    private function deriveCables(array $deviceCells, array $cableList): array
    {
        unset($cableList); // Reserved for Phase 22 port-FK rewrite.

        $byRole = [];
        foreach ($deviceCells as $cell) {
            $byRole[$cell['role']][] = $cell;
        }

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

        // Canonical Teams Room signal chain (verbatim from spike).
        $emit($videobar, 'hdmi-out', $display, 'hdmi-1', 'video');
        $emit($byod, 'hdmi-out', $videobar, 'hdmi-in', 'video');
        $emit($ceilingMic, 'dante-poe', $switch, 'port-1', 'network');
        $emit($videobar, 'lan', $switch, 'port-2', 'network');
        $emit($display, 'lan', $switch, 'port-3', 'network');

        return $edges;
    }

    /**
     * Emit the full mxGraph XML document.
     *
     * Mirrors DrawIoSpikeBuilderService::emitMxGraph verbatim — same
     * shape=stencil(base64) embedding, same htmlspecialchars escape on
     * every interpolated user value (Warning 7 / T-21.03-01 carries
     * forward).
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

            // Build the rich value line. Same double-encoding as the spike
            // (HTML inside an XML attribute means HTML entities → XML
            // entities then re-encoded for attribute placement).
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
        foreach ($cableCells as $edge) {
            $edgeId = $this->xml((string) $edge['edge_id']);
            $source = $this->xml((string) $edge['source']);
            $target = $this->xml((string) $edge['target']);
            $sourcePort = $edge['source_port'] ?? null;
            $targetPort = $edge['target_port'] ?? null;
            $signal = (string) ($edge['signal_type'] ?? 'video');

            $color = match ($signal) {
                'video' => '#1B7A7A',
                'network' => '#1B6CB5',
                'audio' => '#C07000',
                'control' => '#666666',
                default => '#333333',
            };

            $exitConstraint = $sourcePort
                ? 'exitX=0;exitY=0;exitDx=0;exitDy=0;exitPerimeter=0;'
                : '';
            $entryConstraint = $targetPort
                ? 'entryX=0;entryY=0;entryDx=0;entryDy=0;entryPerimeter=0;'
                : '';

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
     * Empty graph — used when project has no package or no devices.
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
     * Escape user-supplied data before XML interpolation. Mirrors Phase 17
     * P02 Warning 7 fix — equipment names from QuoteWerks are untrusted
     * (T-21.03-01 mitigation).
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
