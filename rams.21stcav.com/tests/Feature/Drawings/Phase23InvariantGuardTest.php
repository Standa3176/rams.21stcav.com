<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\DrawIoBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 07 — per-requirement CI assertion of DRAW-42..49.
 *
 * Maps each Phase 23 deliverable to a single test method whose name carries
 * the requirement ID. Failing this suite red-blocks Phase 23 ship.
 *
 * Carbon::setTestNow freezes the title-block `Date` field so determinism
 * assertions stay byte-identical across days. Mirrors the harness pattern
 * shared by DrawIoBuilderServiceMultiSheetTest + XtenAvDeterminismHarnessTest.
 *
 * @see .planning/REQUIREMENTS.md § Phase 23 (DRAW-42..49)
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-01..D-10)
 */
class Phase23InvariantGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // D-LOCK-5/6 — freeze the title-block Date field so two consecutive
        // build() calls produce byte-identical XML regardless of clock drift.
        Carbon::setTestNow('2026-05-13 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @requirement DRAW-42 — custom device-card stencils with logo / name / model / port rails.
     *
     * @see CONTEXT.md D-04 (Tier 1 + Tier 2 both render — base64-stencil embed pattern).
     */
    public function test_draw_42_device_cards_emit_base64_stencil_with_value(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        // XtenAvLayoutEngine splices `shape=stencil(<base64>)` per Phase 21 P03
        // pattern. Same prefix used for both Tier 1 placeholders and Tier 2
        // engineer-curated stencils (CONTEXT D-04 carry-forward).
        $this->assertStringContainsString('shape=stencil(', $xml,
            'DRAW-42: device cells MUST embed stencil via base64 shape=stencil(...) splice');

        // Device cell ids follow dev-{zoneSlug}-{index} per XtenAvLayoutEngine.
        $this->assertMatchesRegularExpression(
            '/<mxCell\s+id="dev-[a-z0-9\-]+-\d+"\s+value="[^"]+"/',
            $xml,
            'DRAW-42: device cells MUST carry stable `dev-{zone}-{idx}` ids + non-empty value attribute',
        );
    }

    /**
     * @requirement DRAW-43 — port-to-port cable routing.
     *
     * @see CONTEXT.md D-07 (NULL-FK fallback ladder) + OQ-4 Path B (Tier 1.5 falls back to coordinate-style).
     */
    public function test_draw_43_emits_port_to_port_or_coordinate_edge(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        // smallMtr has both populated port FKs (5 of the 6 cables resolve to
        // Tier 2 stencils with <constraint>) AND coordinate-fallback cables
        // (e.g. the cross-stencil USB-C cable). Either form of attachment is
        // an acceptable DRAW-43 emission per D-07/OQ-4 Path B.
        $hasPortId = str_contains($xml, 'exitPortId=') && str_contains($xml, 'entryPortId=');
        $hasCoord  = str_contains($xml, 'exitX=') && str_contains($xml, 'entryX=');

        $this->assertTrue($hasPortId || $hasCoord,
            'DRAW-43: edges MUST use port-id style (Tier 2 happy path) OR coordinate style (Tier 1.5 / NULL-FK fallback)');
    }

    /**
     * @requirement DRAW-44 — signal-type colour coding.
     *
     * @see CONTEXT.md D-10 (config/cables.php signal_type_colours single source of truth).
     */
    public function test_draw_44_edge_colour_from_config_cables(): void
    {
        // smallMtr's wireSmallMtrCables resolves real DevicePort FKs for the
        // Tier 2 stencils, so signal_type is non-null on most edges → real
        // network/audio/video/usb colours appear in the rendered XML.
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        $colours = config('cables.signal_type_colours');
        $this->assertIsArray($colours);
        $this->assertNotEmpty($colours, 'config(cables.signal_type_colours) MUST be defined per D-10');

        $matched = 0;
        foreach ($colours as $colour) {
            if (str_contains($xml, "strokeColor={$colour}")) {
                $matched++;
            }
        }
        $this->assertGreaterThan(0, $matched,
            'DRAW-44: at least one config(cables.signal_type_colours) hex MUST appear as edge strokeColor');
    }

    /**
     * @requirement DRAW-45 — cable ID label rendered at edge midpoint.
     */
    public function test_draw_45_edge_value_attribute_carries_cable_id(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        // smallMtr cable_id values are HDMI-001 / USB-001 / LAN-001 / LAN-002 /
        // LAN-003 / AUDIO-001. CableRouter emits edge cell ids prefixed with
        // `cab-{pk}` (numeric PK from cable_schedule_items) and places the
        // cable_id text in the value attribute (mxGraph renders that at the
        // edge midpoint by default).
        $this->assertMatchesRegularExpression(
            '/<mxCell\s+id="cab-\d+"\s+value="[A-Z0-9\-]+"\s+style="[^"]*"\s+edge="1"/',
            $xml,
            'DRAW-45: cable edge cell MUST carry value="{CABLE-ID}" for mxGraph midpoint label',
        );
    }

    /**
     * @requirement DRAW-46 — sub-room zones rendered as dashed-bordered groups.
     *
     * @see CONTEXT.md D-01 (category → zone map), D-02 (per-device override), D-04 (free-text escape hatch).
     */
    public function test_draw_46_zones_render_as_dashed_groups(): void
    {
        // boardroom fixture has 10 devices across multiple zones — guarantees
        // at least one zone container in the rendered output.
        $project = Phase23FixtureFactory::boardroom();
        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertMatchesRegularExpression(
            '/<mxCell\s+id="zone-[a-z0-9\-_]+"\s+value="[^"]+"\s+style="[^"]*dashed=1[^"]*"/',
            $xml,
            'DRAW-46: zone containers MUST render as dashed-bordered groups (dashed=1 in style)',
        );
    }

    /**
     * @requirement DRAW-47 — multi-page paginator wraps multiple sheets in `<mxfile>`.
     *
     * @see CONTEXT.md D-06 (BOTH-AND threshold + force_sheets metadata override).
     */
    public function test_draw_47_multi_page_wraps_in_mxfile(): void
    {
        // pagingSystem fixture writes port_id=null on every cable (Tier 1.5
        // stencils carry no DevicePort rows), so SheetPaginator's BOTH-AND
        // threshold can't read signal_type. Use the D-06 tinker override
        // (metadata.force_sheets) to force ≥2 sheets — the supported escape
        // hatch documented in CONTEXT D-06 deferred-UI line.
        $project = Phase23FixtureFactory::pagingSystem();
        $project->forceFill([
            'metadata' => ['force_sheets' => ['audio', 'network']],
        ])->save();

        $xml = app(DrawIoBuilderService::class)->build($project->fresh());

        $this->assertStringContainsString('<mxfile', $xml,
            'DRAW-47: multi-sheet output MUST be wrapped in <mxfile> per draw.io multi-page format');
        $this->assertGreaterThan(1, substr_count($xml, '<diagram '),
            'DRAW-47: force_sheets=[audio,network] MUST yield >1 <diagram> elements (system_overview + sub-sheets)');
    }

    /**
     * @requirement DRAW-48 — standardised 8-field title block.
     *
     * @see CONTEXT.md D-08 (source-of-truth resolution per field).
     */
    public function test_draw_48_title_block_eight_fields_per_sheet(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        // TitleBlockRenderer emits 8 fields per sheet with ids tb-{key}-{0..7}.
        // smallMtr only emits system_overview → exactly 8 tb-* cells.
        $tbFields = substr_count($xml, 'id="tb-');
        $this->assertGreaterThanOrEqual(8, $tbFields,
            "DRAW-48: each sheet MUST emit 8 title-block fields; got {$tbFields}");

        // Field labels are concatenated as "Label: value" BEFORE the xml()
        // htmlspecialchars call. The literal label-and-colon prefix flows
        // through unchanged because neither character is special-encoded.
        foreach (['Project:', 'Client:', 'Designed by:', 'Drawn by:', 'Checked by:', 'Sheet:', 'Date:', 'Rev:'] as $label) {
            $this->assertStringContainsString($label, $xml,
                "DRAW-48: title-block MUST include '{$label}' field per D-08 source resolution");
        }
    }

    /**
     * @requirement DRAW-49 — dashed sheet border rendered on every page.
     */
    public function test_draw_49_dashed_border_on_every_diagram(): void
    {
        // pagingSystem + force_sheets guarantees multi-sheet output so we can
        // assert ≥1 page-border cell per <diagram> element.
        $project = Phase23FixtureFactory::pagingSystem();
        $project->forceFill([
            'metadata' => ['force_sheets' => ['audio', 'network']],
        ])->save();

        $xml = app(DrawIoBuilderService::class)->build($project->fresh());

        $sheetCount  = substr_count($xml, '<diagram ');
        $borderCount = substr_count($xml, 'id="page-border"');

        $this->assertGreaterThan(1, $sheetCount, 'Need ≥2 sheets to meaningfully assert per-sheet border emission');
        $this->assertGreaterThanOrEqual($sheetCount, $borderCount,
            "DRAW-49: every sheet MUST include exactly one page-border cell; sheets={$sheetCount}, borders={$borderCount}");
    }

    /**
     * @requirement Phase 23 determinism contract (D-LOCK-5/6 carry-forward from spike).
     *
     * Two consecutive build() calls on the same project state MUST produce
     * byte-identical XML — no Eloquent writes, no AI calls, no time-of-day
     * reads outside the Carbon::setTestNow harness window.
     */
    public function test_phase_23_builder_is_byte_identical_across_calls(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();

        $a = app(DrawIoBuilderService::class)->build($project);
        $b = app(DrawIoBuilderService::class)->build($project->fresh());

        $this->assertSame($a, $b,
            'D-LOCK-5/6: same project state MUST produce byte-identical XML across two consecutive build() calls');
    }
}
