<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Services\Drawings\DrawIoBuilderService;
use Carbon\Carbon;
use Database\Seeders\DeviceStencilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 05 — end-to-end orchestration tests for the rewired
 * {@see DrawIoBuilderService}.
 *
 * Asserts:
 *   - Empty project (no package, no devices) → legacy single `<mxGraphModel>`
 *     shape (no `<mxfile>` wrapper). Preserves Phase 21 P03 backwards-compat.
 *   - Non-empty project → `<mxfile>` wrapper with one `<diagram>` per sheet.
 *   - System overview sheet always emits; sub-sheets gated by D-06 BOTH-AND
 *     threshold OR engineer force_sheets metadata override.
 *   - D-07 NULL-FK fallback emits the ⚠ warn glyph in the output.
 *   - Each emitted sheet carries a dashed page border + 8-field title block.
 *   - Cable colour styles come from `config('cables.signal_type_colours')`.
 *   - Determinism (D-LOCK-5/6) — same project → byte-identical XML.
 *   - XSS payload in Project.name is xml-escaped before interpolation
 *     (T-23-05-A1 mitigation).
 *
 * Carbon::setTestNow freezes the title-block `Date` field so determinism
 * tests stay green across days.
 *
 * @see app/Services/Drawings/DrawIoBuilderService.php
 * @see tests/Fixtures/Drawings/Phase23FixtureFactory.php
 * @see .planning/phases/23-xten-av-style-renderer/23-05-drawio-builder-orchestrator-rewire-PLAN.md
 */
class DrawIoBuilderServiceMultiSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // D-LOCK-5/6 — freeze time so the title block's Date field renders
        // identically across two consecutive calls + across CI runs.
        Carbon::setTestNow('2026-05-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_project_emits_legacy_single_mxgraphmodel(): void
    {
        $project = Project::factory()->create();

        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertStringContainsString('<mxGraphModel', $xml,
            'Empty project must still emit a valid empty mxGraphModel (Phase 21 P03 backwards-compat)');
        $this->assertStringNotContainsString('<mxfile', $xml,
            'Empty project must NOT be wrapped in <mxfile> — legacy single-page shape');
        $this->assertStringNotContainsString('vertex="1"', $xml,
            'Empty project must emit zero vertex cells');
    }

    public function test_small_mtr_fixture_emits_single_sheet_inside_mxfile(): void
    {
        $project = Phase23FixtureFactory::smallMtr();

        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertStringContainsString('<mxfile', $xml,
            'Non-empty project MUST be wrapped in <mxfile> for draw.io multi-page format');
        $this->assertStringContainsString('<diagram', $xml,
            'Each sheet MUST be wrapped in a <diagram> element');

        // smallMtr has 6 cables: 1 HDMI (video), 1 USB, 3 LAN (network), 1 XLR
        // (audio). At most NETWORK could approach threshold (3 cables on
        // 4 devices) but cables=3 < min_cables=5 → only system_overview.
        $sheetCount = substr_count($xml, '<diagram ');
        $this->assertSame(1, $sheetCount,
            "Small MTR fixture should emit ONLY system_overview (no sub-sheets above threshold); got {$sheetCount} sheets");
    }

    public function test_paging_system_fixture_emits_multiple_sheets(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();

        // pagingSystem fixture's cables write port_id=null on every row
        // (Tier 1.5 stencils carry no DevicePort rows per the fixture's
        // simple-cable helper). Without ports, SheetPaginator's threshold
        // check can't read signal_type from cables. Use the D-06 tinker
        // override path (`metadata.force_sheets`) to force the sub-sheets.
        $project->forceFill([
            'metadata' => ['force_sheets' => ['audio', 'network']],
        ])->save();

        $xml = app(DrawIoBuilderService::class)->build($project->fresh());

        $this->assertStringContainsString('<mxfile', $xml);
        $this->assertGreaterThanOrEqual(2, substr_count($xml, '<diagram '),
            'Paging system fixture with force_sheets=[audio,network] MUST emit >= 2 sheets');
    }

    public function test_legacy_null_fk_fixture_renders_with_warning_glyphs(): void
    {
        $project = Phase23FixtureFactory::legacyNullFk();

        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertStringContainsString('<mxfile', $xml);
        // D-07 ⚠ glyph emitted for at least one cable (legacy fixture has
        // 3 populated cables with NULL port FKs and 3 fully NULL-FK rows;
        // the 3 populated-but-null-port rows route via D-07 fallback +
        // ⚠ glyph; the 3 fully-NULL rows skip).
        $this->assertStringContainsString('⚠', $xml,
            'Legacy NULL-FK fixture MUST emit at least one ⚠ warn glyph (D-07 fallback)');
    }

    public function test_each_sheet_has_dashed_border_and_title_block(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $project->forceFill([
            'metadata' => ['force_sheets' => ['audio', 'network']],
        ])->save();

        $xml = app(DrawIoBuilderService::class)->build($project->fresh());

        $sheetCount = substr_count($xml, '<diagram ');
        $this->assertGreaterThanOrEqual(2, $sheetCount, 'Need at least 2 sheets to assert per-sheet chrome');

        // One dashed border per sheet (SheetBorderRenderer style fingerprint).
        $borderHits = substr_count($xml, 'dashPattern=8 4');
        $this->assertGreaterThanOrEqual($sheetCount, $borderHits,
            "Expected >= {$sheetCount} dashed border cells (one per sheet); got {$borderHits}");

        // 8 title-block fields per sheet — tb-{key}-{i} id prefix.
        $tbCount = substr_count($xml, 'id="tb-');
        $this->assertGreaterThanOrEqual(8 * $sheetCount, $tbCount,
            "Expected >= ".(8 * $sheetCount)." title-block field cells; got {$tbCount}");
    }

    public function test_signal_colours_match_config_cables(): void
    {
        $project = Phase23FixtureFactory::smallMtr();

        $xml = app(DrawIoBuilderService::class)->build($project);

        $networkColour = (string) config('cables.signal_type_colours.network');
        $this->assertNotEmpty($networkColour, 'config(cables.signal_type_colours.network) must be set');

        // SmallMTR has 3 LAN cables — the renderer's edge style should
        // splice the network colour as strokeColor + fontColor on at least
        // one edge cell.
        if (str_contains($xml, 'signal=network') === false) {
            // Edge cells don't expose signal= as an attribute; assert by colour.
            $this->assertStringContainsString("strokeColor={$networkColour}", $xml,
                'Network-signal cable strokeColor must match config(cables.signal_type_colours.network)');
        }
    }

    public function test_determinism_across_calls(): void
    {
        $project = Phase23FixtureFactory::smallMtr();

        $a = app(DrawIoBuilderService::class)->build($project);
        $b = app(DrawIoBuilderService::class)->build($project->fresh());

        $this->assertSame($a, $b,
            'D-LOCK-5/6 determinism contract — same project state → byte-identical XML');
    }

    public function test_xss_payload_in_project_name_is_escaped(): void
    {
        // Use the fixture so the title block actually renders (empty
        // projects skip the title block via the legacy empty-graph path).
        $project = Phase23FixtureFactory::smallMtr();
        $project->forceFill(['name' => '<script>alert(1)</script>'])->save();

        $xml = app(DrawIoBuilderService::class)->build($project->fresh());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $xml,
            'Raw <script> payload must NOT appear unescaped in the rendered XML');
        $this->assertStringContainsString('&lt;script&gt;', $xml,
            'Project.name XSS payload must be htmlspecialchars-escaped before interpolation (T-23-05-A1)');
    }
}
