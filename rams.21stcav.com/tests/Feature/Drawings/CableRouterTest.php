<?php

namespace Tests\Feature\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Services\Drawings\CableRouter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 23 Plan 03 — DRAW-43 (port-to-port edges via exitPortId/entryPortId)
 *                  + DRAW-44 (signal-type colour from config('cables.signal_type_colours'))
 *                  + DRAW-45 (cable_id literal value at edge midpoint)
 *                  + D-07     (NULL-FK fallback ladder)
 *                  + OQ-4     (Path B — Tier 1.5 stencils silently fall back to
 *                              coordinate-style + ⚠ glyph regardless of FK presence
 *                              because their mxgraph_xml lacks <constraint> elements)
 *
 * The router is a PURE READ helper — every test asserts zero DB writes via
 * before/after row counts. Deterministic: same input → same output, twice.
 *
 * @see app/Services/Drawings/CableRouter.php
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-07, D-10)
 * @see .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md (Path B)
 */
class CableRouterTest extends TestCase
{
    use RefreshDatabase;

    private CableRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        // Determinism guard — pin time for the entire test run per critical_invariants #9.
        Carbon::setTestNow('2026-05-14 12:00:00');
        $this->router = app(CableRouter::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Build a project with one CableSchedule + one CableScheduleItem.
     * The item is fully port-FK-populated unless the caller overrides.
     *
     * Both stencils are Tier 2 (curated, carry <constraint> elements) so the
     * default fixture exercises the named-port edge path (OQ-4 Path A).
     *
     * Returns ['project' => Project, 'deviceCells' => array<int, array>] —
     * deviceCells mirrors XtenAvLayoutEngine output shape with an extra
     * 'device_id' key the orchestrator (Plan 23-05) will inject so the
     * router can resolve $item->source_device_id back to a cell id.
     */
    private function makeProjectWithCables(array $itemOverrides = [], array $opts = []): array
    {
        $tier15Source = (bool) ($opts['tier15_source'] ?? false);
        $tier15Dest   = (bool) ($opts['tier15_dest'] ?? false);

        $project = Project::factory()->create();

        $sourceStencil = DeviceStencil::create([
            'part_number'  => 'src-stencil-1',
            'manufacturer' => 'Acme',
            'model'        => 'Source-1',
            'mxgraph_xml'  => $tier15Source
                ? '<shape><background><rect x="0" y="0" w="220" h="140"/></background></shape>' // NO <constraint> = Tier 1.5
                : '<shape><connections><constraint name="hdmi-out-1" x="1" y="0.5" perimeter="0"/></connections></shape>',
            'source'         => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'default_width'  => 220,
            'default_height' => 140,
        ]);

        $destStencil = DeviceStencil::create([
            'part_number'  => 'dst-stencil-1',
            'manufacturer' => 'Acme',
            'model'        => 'Dest-1',
            'mxgraph_xml'  => $tier15Dest
                ? '<shape><background><rect x="0" y="0" w="220" h="140"/></background></shape>'
                : '<shape><connections><constraint name="hdmi-in" x="0" y="0.5" perimeter="0"/></connections></shape>',
            'source'         => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'default_width'  => 220,
            'default_height' => 140,
        ]);

        $sourcePort = DevicePort::create([
            'device_stencil_id' => $sourceStencil->id,
            'port_id'           => 'hdmi-out-1',
            'label'             => 'HDMI OUT 1',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_OUT,
            'sort_order'        => 1,
        ]);

        $destPort = DevicePort::create([
            'device_stencil_id' => $destStencil->id,
            'port_id'           => 'hdmi-in',
            'label'             => 'HDMI IN',
            'side'              => DevicePort::SIDE_LEFT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_IN,
            'sort_order'        => 1,
        ]);

        $sourceDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Acme Source 1',
            'manufacturer' => 'Acme',
            'model'        => 'Source-1',
            'part_no'      => 'src-stencil-1',
            'qty'          => 1,
        ]);

        $destDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Acme Dest 1',
            'manufacturer' => 'Acme',
            'model'        => 'Dest-1',
            'part_no'      => 'dst-stencil-1',
            'qty'          => 1,
        ]);

        $schedule = CableSchedule::create([
            'user_id'         => $project->user_id,
            'project_id'      => $project->id,
            'project_ref'     => $project->quote_reference ?? 'Q-FIX-000',
            'project_name'    => $project->name,
            'client_name'     => $project->client_name,
            'source_filename' => 'fixture-' . $project->id . '.xlsx',
            'status'          => CableSchedule::STATUS_DRAFT,
        ]);

        $defaults = [
            'cable_schedule_id' => $schedule->id,
            'source_device_id'  => $sourceDevice->id,
            'source_port_id'    => $sourcePort->id,
            'dest_device_id'    => $destDevice->id,
            'dest_port_id'      => $destPort->id,
            'cable_id'          => 'VID-1000',
            'cable_type'        => 'HDMI',
            'from_location'     => 'Acme Source-1 (HDMI OUT 1)',
            'to_location'       => 'Acme Dest-1 (HDMI IN)',
            'sort_order'        => 1,
        ];

        CableScheduleItem::create(array_merge($defaults, $itemOverrides));

        // Mirror XtenAvLayoutEngine output for the two devices, plus the
        // device_id key the Plan 05 orchestrator will splice in so CableRouter
        // can map FKs back to cell ids.
        $deviceCells = [
            [
                'kind'        => 'device',
                'id'          => 'dev-rack-0',
                'device_id'   => $sourceDevice->id,
                'part_number' => 'src-stencil-1',
                'stencil'     => $sourceStencil,
                'category'    => 'videobar', // source-like → projects from right edge in D-07 fallback
                'x' => 80, 'y' => 80, 'w' => 220, 'h' => 140,
            ],
            [
                'kind'        => 'device',
                'id'          => 'dev-wall-0',
                'device_id'   => $destDevice->id,
                'part_number' => 'dst-stencil-1',
                'stencil'     => $destStencil,
                'category'    => 'display',
                'x' => 500, 'y' => 80, 'w' => 220, 'h' => 140,
            ],
        ];

        return ['project' => $project->fresh(), 'deviceCells' => $deviceCells];
    }

    // ── DRAW-43 happy path — exitPortId/entryPortId on curated stencils ──────

    public function test_port_to_port_edge_uses_exit_port_id(): void
    {
        $f = $this->makeProjectWithCables();
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge, 'router should emit an edge for the curated/port-FK happy path');
        $this->assertStringContainsString('exitPortId=hdmi-out-1', $edge['style']);
        $this->assertStringContainsString('entryPortId=hdmi-in', $edge['style']);
    }

    // ── DRAW-45 cable_id rendered as edge value ──────────────────────────────

    public function test_edge_value_is_cable_id(): void
    {
        $f = $this->makeProjectWithCables(['cable_id' => 'LAN-1004']);
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertSame('LAN-1004', $edge['value']);
    }

    // ── DRAW-44 signal-type colour comes from config('cables.signal_type_colours') ──

    public function test_cable_colour_from_config_signal_type_colours(): void
    {
        // video → '#2980B9' from config/cables.php (Phase 22 locked single source of truth)
        $f = $this->makeProjectWithCables();
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $expected = config('cables.signal_type_colours.video');
        $this->assertStringContainsString("strokeColor={$expected}", $edge['style']);
        $this->assertStringContainsString("fontColor={$expected}", $edge['style']);
    }

    public function test_unknown_signal_type_falls_back_to_unknown_colour(): void
    {
        // Set every port's signal_type to a key not in config — should fall to
        // 'unknown' (#000000) per signal_type_colours. UPDATE the ports AFTER
        // the fixture builds them, then re-fresh the project so loadMissing
        // picks up the mutated values.
        $f = $this->makeProjectWithCables();
        DB::table('device_ports')->update(['signal_type' => 'made-up-signal']);
        $project = $f['project']->fresh();
        $edges = $this->router->emitCables($project, $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $expected = config('cables.signal_type_colours.unknown');
        $this->assertStringContainsString("strokeColor={$expected}", $edge['style']);
    }

    // ── D-07 NULL-FK fallback ladder ─────────────────────────────────────────

    public function test_null_fk_renders_with_warning_glyph(): void
    {
        // Both device IDs present, both port IDs NULL → D-07 device-edge fallback + ⚠.
        $f = $this->makeProjectWithCables([
            'source_port_id' => null,
            'dest_port_id'   => null,
            'cable_id'       => 'WARN-001',
        ]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('exitPortId', $edge['style']);
        $this->assertStringContainsString('exitX=', $edge['style']); // coordinate-style fallback

        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn, 'NULL-FK fallback must emit a ⚠ glyph cell');
        $this->assertStringContainsString('⚠', $warn['value']);
    }

    public function test_source_port_null_dest_port_present_falls_back(): void
    {
        // Only source port NULL — dest port still set. The router still falls
        // back to coordinate-style because we can't terminate one end at a
        // named port without anchoring the other side.
        $f = $this->makeProjectWithCables(['source_port_id' => null]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('exitPortId', $edge['style']);

        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn);
    }

    public function test_dest_port_null_source_port_present_falls_back(): void
    {
        $f = $this->makeProjectWithCables(['dest_port_id' => null]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('entryPortId', $edge['style']);

        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn);
    }

    public function test_double_null_fk_cable_is_skipped(): void
    {
        // Both device_ids AND both port_ids NULL → pure legacy text row, skip
        // entirely (v1.3 surface handles per Phase 22 D-10).
        $f = $this->makeProjectWithCables([
            'source_device_id' => null,
            'source_port_id'   => null,
            'dest_device_id'   => null,
            'dest_port_id'     => null,
        ]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);
        $this->assertSame([], $cells);
    }

    // ── OQ-4 Path B — Tier 1.5 stencils silently fall back ──────────────────

    public function test_tier15_source_stencil_falls_back_to_coordinate_style(): void
    {
        // Source stencil has NO <constraint> in mxgraph_xml. Even though both
        // port FKs are populated, the router MUST drop exitPortId because
        // the stencil shape can't terminate the cable at a named port.
        $f = $this->makeProjectWithCables([], ['tier15_source' => true]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('exitPortId', $edge['style'],
            'OQ-4 Path B: Tier 1.5 source stencil cannot anchor exitPortId');
        $this->assertStringContainsString('exitX=', $edge['style']);

        // OQ-4 Path B: warn glyph at the cable junction so engineers see the
        // "needs curation" signal in the visual.
        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn);
    }

    public function test_tier15_dest_stencil_falls_back_to_coordinate_style(): void
    {
        $f = $this->makeProjectWithCables([], ['tier15_dest' => true]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('entryPortId', $edge['style'],
            'OQ-4 Path B: Tier 1.5 dest stencil cannot anchor entryPortId');
        $this->assertStringContainsString('entryX=', $edge['style']);
    }

    public function test_both_tier15_stencils_fall_back(): void
    {
        // Both ends Tier 1.5 — pure coordinate-style edge + ⚠ glyph.
        $f = $this->makeProjectWithCables([], ['tier15_source' => true, 'tier15_dest' => true]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('exitPortId', $edge['style']);
        $this->assertStringNotContainsString('entryPortId', $edge['style']);

        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn);
    }

    // ── Security — XML escaping of cable_id (T-23-03-A1) ────────────────────

    public function test_cable_id_xss_escaped(): void
    {
        $f = $this->makeProjectWithCables(['cable_id' => '<script>alert(1)</script>']);
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertStringNotContainsString('<script>', $edge['value']);
        $this->assertStringContainsString('&lt;script&gt;', $edge['value']);
    }

    // ── Eager-loading (Pitfall 9 — N+1 guard) ───────────────────────────────

    public function test_eager_loading_keeps_query_count_bounded(): void
    {
        $f = $this->makeProjectWithCables();
        DB::enableQueryLog();
        $this->router->emitCables($f['project'], $f['deviceCells']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // With loadMissing at the call site, total queries should be ≤ 10
        // (1 cableSchedules + 1 items + sourcePort + destPort + sourceDevice
        //  + destDevice batched).
        $this->assertLessThan(10, count($queries), 'Eager-loading must prevent N+1 per Pitfall 9');
    }

    // ── D-LOCK-5/6 — pure read function ──────────────────────────────────────

    public function test_router_does_not_write_to_database(): void
    {
        $f = $this->makeProjectWithCables();
        $tables = ['cable_schedule_items', 'device_ports', 'device_stencils', 'devices', 'projects'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->count();
        }

        $this->router->emitCables($f['project'], $f['deviceCells']);

        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->count(), "router wrote to {$t} — D-LOCK-5/6 violated");
        }
    }

    // ── Determinism — same input → same descriptor list ─────────────────────

    public function test_emits_stable_descriptors_across_calls(): void
    {
        $f = $this->makeProjectWithCables();
        $a = $this->router->emitCables($f['project'], $f['deviceCells']);
        $b = $this->router->emitCables($f['project'], $f['deviceCells']);

        $this->assertSame(array_column($a, 'id'), array_column($b, 'id'));
        $this->assertSame(array_column($a, 'style'), array_column($b, 'style'));
    }

    // ── Skip when device_id missing from cells map (FK pointed at deleted device) ──

    public function test_skips_cable_when_device_id_not_in_cells_map(): void
    {
        $f = $this->makeProjectWithCables();
        // Remove the source device from the cells list — simulates a Plan 05
        // page that doesn't render that zone's devices on this sheet.
        $deviceCells = array_filter($f['deviceCells'], fn ($c) => $c['id'] !== 'dev-rack-0');
        $cells = $this->router->emitCables($f['project'], array_values($deviceCells));
        $this->assertSame([], $cells);
    }
}
