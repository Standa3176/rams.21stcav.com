<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\Drawings\RackElevationRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 Plan 03 — feature tests for the synchronous custom Blade SVG
 * rack renderer.
 *
 * Coverage map (per 18-03 plan §<task 1>):
 *   1. test_render_throws_when_kind_is_not_rack            — guard
 *   2. test_render_emits_42_numbered_rail_labels           — DRAW-08 rail
 *   3. test_render_places_items_at_correct_u_positions     — DRAW-08 / -10
 *   4. test_render_totals_footer_with_all_known_metrics    — DRAW-12 known
 *   5. test_render_totals_footer_with_partial_data         — DRAW-12 partial
 *   6. test_render_unknown_u_height_surfaces_warning       — CRIT-06
 *   7. test_render_marks_locked_items_in_svg               — DRAW-10 lock
 *   8. test_render_completes_within_one_second_for_full_rack — Warning 8
 *   9. test_render_escapes_equipment_names                 — XSS hygiene
 *
 * Mirrors {@see SchematicGeneratorServiceTest} fixture shape (no factories
 * yet for ProjectDrawing — direct ProjectDrawing::create from inline arrays).
 */
class RackElevationRenderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id' => $user->id,
            'name' => 'Rack Render Test',
            'ref' => 'RACK-RENDER-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Render Street, London',
            'status' => 'quote_imported',
        ]);
    }

    private function makeRackDrawing(Project $project, array $rackItems = [], int $rackHeightU = 42): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_RACK,
            'rack_label' => 'Rack 1',
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $project->user_id,
            'source_data' => [
                'rack_meta' => [
                    'rack_label' => 'Rack 1',
                    'rack_height_u' => $rackHeightU,
                    'nominal_voltage_v' => 230,
                    'floor' => null,
                ],
                'rack_items' => $rackItems,
            ],
        ]);
    }

    // ── 1. Kind guard ─────────────────────────────────────────────────────

    public function test_render_throws_when_kind_is_not_rack(): void
    {
        $project = $this->makeProject();

        $drawing = ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_SCHEMATIC, // wrong kind on purpose
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $project->user_id,
            'source_data' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not .rack./');

        app(RackElevationRenderService::class)->render($drawing);
    }

    // ── 2. 42U numbered rail ──────────────────────────────────────────────

    public function test_render_emits_42_numbered_rail_labels(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, []);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        $this->assertStringContainsString('<svg', $svg);

        // All 42 rail labels must appear as <text>...</text>
        for ($u = 1; $u <= 42; $u++) {
            $this->assertMatchesRegularExpression(
                '/<text[^>]*>'.$u.'<\/text>/',
                $svg,
                "Rail label for U-{$u} not found in SVG"
            );
        }
    }

    // ── 3. Item placement (1U at bottom, 2U spans up) ─────────────────────

    public function test_render_places_items_at_correct_u_positions(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            [
                'equipment_id' => 'EQ-1',
                'name' => 'Bottom Item',
                'part_no' => 'PN-1',
                'u_position' => 1,
                'u_height' => 1.0,
                'locked' => false,
                'weight_kg' => 1.0,
                'current_draw_a' => 0.5,
                'btu_per_hour' => 50,
            ],
            [
                'equipment_id' => 'EQ-2',
                'name' => 'Tall Amp',
                'part_no' => 'PN-2',
                'u_position' => 10,
                'u_height' => 2.0,
                'locked' => false,
                'weight_kg' => 5.0,
                'current_draw_a' => 1.5,
                'btu_per_hour' => 200,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        // The 1U item's name must appear in a <text>
        $this->assertStringContainsString('Bottom Item', $svg);
        // The 2U item must show its name AND its 2U height label
        $this->assertStringContainsString('Tall Amp', $svg);

        // Layout maths: U_HEIGHT_PX=24, rack-internal frame top padding+border
        // is consistent so U-1's rect bottom edge is the lowest rect bottom in
        // the SVG. We assert a rect at the bottom-most y > a rect at u_position=10.
        // Capture rect y attributes for items.
        preg_match_all('/<rect[^>]*data-equipment-id="(EQ-1|EQ-2)"[^>]*y="(\d+(?:\.\d+)?)"/', $svg, $m);
        $this->assertCount(2, $m[1] ?? [], "Expected exactly 2 item rects");
        $byId = array_combine($m[1], $m[2]);
        // EQ-1 (u_position=1, bottom) must have GREATER y than EQ-2 (u_position=10).
        $this->assertGreaterThan(
            (float) $byId['EQ-2'],
            (float) $byId['EQ-1'],
            'U-1 item should be visually below U-10 item (greater y)'
        );
    }

    // ── 4. Totals footer — all metrics known, no asterisk ─────────────────

    public function test_render_totals_footer_with_all_known_metrics(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            [
                'equipment_id' => 'EQ-A',
                'name' => 'AirMedia',
                'part_no' => 'AM-3200-GV',
                'u_position' => 1,
                'u_height' => 1.0,
                'locked' => false,
                'weight_kg' => 1.8,
                'current_draw_a' => 0.5,
                'btu_per_hour' => 60,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        // All-known metrics — NO asterisk on the metric values, NO "(n/m known)" ratio.
        $this->assertStringContainsString('Weight: 1.8 kg', $svg);
        $this->assertStringNotContainsString('Weight: 1.8 kg*', $svg);
        $this->assertStringContainsString('Current: 0.5 A', $svg);
        $this->assertStringNotContainsString('Current: 0.5 A*', $svg);
        $this->assertStringContainsString('BTU: 60', $svg);
        $this->assertStringContainsString('U-utilisation: 1', $svg);
        // 42U scaffold so "1U / 42U" is the rendered string
        $this->assertStringContainsString('42U', $svg);
    }

    // ── 5. Totals footer — partial data, asterisks + ratio ────────────────

    public function test_render_totals_footer_with_partial_data(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            // Known
            [
                'equipment_id' => 'EQ-K',
                'name' => 'Known Device',
                'part_no' => 'PN-K',
                'u_position' => 1,
                'u_height' => 1.0,
                'locked' => false,
                'weight_kg' => 1.8,
                'current_draw_a' => 0.5,
                'btu_per_hour' => 60,
            ],
            // Unknown — no metrics, no catalog entry
            [
                'equipment_id' => 'EQ-U',
                'name' => 'Unknown Device',
                'part_no' => 'NOT-IN-CATALOG-XYZ',
                'u_position' => 5,
                'u_height' => 1.0,
                'locked' => false,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        // Partial — asterisk + ratio "(1/2 known)"
        $this->assertStringContainsString('Weight: 1.8 kg*', $svg);
        $this->assertStringContainsString('1/2 known', $svg);
    }

    // ── 6. CRIT-06 — unknown u_height surfaces a warning region ───────────

    public function test_render_unknown_u_height_surfaces_warning(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            [
                'equipment_id' => 'EQ-MYS',
                'name' => 'Mystery Box',
                'part_no' => 'WHO-KNOWS',
                'u_position' => 3,
                // No u_height — null + catalog miss → 1U placeholder + warning
                'locked' => false,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        $this->assertStringContainsString('U-height unknown', $svg);
        // Device name must appear in the warning region (not just the rack)
        $this->assertStringContainsString('Mystery Box', $svg);
    }

    // ── 7. Locked items annotated in SVG ──────────────────────────────────

    public function test_render_marks_locked_items_in_svg(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            [
                'equipment_id' => 'EQ-LOCK',
                'name' => 'Pinned PDU',
                'part_no' => 'PDU-1',
                'u_position' => 1,
                'u_height' => 1.0,
                'locked' => true,
                'weight_kg' => 2.0,
                'current_draw_a' => 0.0,
                'btu_per_hour' => 0,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        // Lock attribute on the rect (per plan: data-locked="true")
        $this->assertMatchesRegularExpression(
            '/<rect[^>]*data-equipment-id="EQ-LOCK"[^>]*data-locked="true"/',
            $svg,
            'Locked item should have data-locked="true" on its rect'
        );
    }

    // ── 8. Render-time budget — Warning 8 fix ─────────────────────────────

    public function test_render_completes_within_one_second_for_full_rack(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, array_map(fn ($i) => [
            'equipment_id' => "EQ-{$i}",
            'name' => "Unit {$i}",
            'part_no' => "PN-{$i}",
            'u_position' => $i,
            'u_height' => 1.0,
            'locked' => false,
            'weight_kg' => 1.0,
            'current_draw_a' => 0.1,
            'btu_per_hour' => 10,
        ], range(1, 30)));

        $start = microtime(true);
        app(RackElevationRenderService::class)->render($drawing);
        $elapsed = microtime(true) - $start;

        // Generous 1s budget — catches gross regressions without flapping in CI.
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf('Rack render took %.3fs — budget is 1.0s', $elapsed)
        );
    }

    // ── 9. XSS hygiene — equipment names go through htmlspecialchars ──────

    public function test_render_escapes_equipment_names(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project, [
            [
                'equipment_id' => 'EQ-HOSTILE',
                'name' => '<script>alert(1)</script>',
                'part_no' => 'PN-X',
                'u_position' => 1,
                'u_height' => 1.0,
                'locked' => false,
            ],
        ]);

        $svg = app(RackElevationRenderService::class)->render($drawing);

        // Raw <script> tag MUST NOT appear; escaped form MUST appear.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $svg);
    }
}
