<?php

namespace Tests\Unit\Services;

use App\Services\RamsReviewDataService;
use Tests\TestCase;

/**
 * Phase 22.1 Plan 03 Task 2 — schema-trim tests for RamsReviewDataService.
 *
 * Locks the canonical room_overviews[] shape to exactly 4 keys:
 *   - room
 *   - overview
 *   - works_summary
 *   - solution_type_id
 *
 * The legacy keys `summary`, `description`, and `scope` are dropped from
 * the normaliser output per CONTEXT.md D-01 / D-07 / D-08.
 * The one-off `summary → works_summary` migration is owned by
 * rams:backfill-room-overview-summary (Plan 03 Task 1) — by the time this
 * normaliser is read in production, every record will have its
 * works_summary populated where the legacy summary held content.
 *
 * The load-time backfill (RamsReviewDataService::load(), lines ~56-61
 * before Plan 03) is removed for the same reason — the artisan handles it
 * once and for all.
 *
 * @see app/Services/RamsReviewDataService.php
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-01 / D-07 / D-08
 */
class RamsReviewDataServiceTest extends TestCase
{
    private RamsReviewDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RamsReviewDataService();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DATA-02 / D-01 / D-07 / D-08: room_overviews shape is exactly 4 keys
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseRoomOverviews_output_contains_only_canonical_keys(): void
    {
        $raw = [
            [
                'room'             => 'Boardroom',
                'overview'         => 'PM prose narrative.',
                'works_summary'    => '- Install display',
                'summary'          => 'LEGACY summary text',
                'description'      => 'LEGACY AI prose',
                'scope'            => 'LEGACY scope text',
                'solution_type_id' => 5,
            ],
        ];

        $out = $this->service->normalise(['room_overviews' => $raw])['room_overviews'];

        $this->assertCount(1, $out);
        $expectedKeys = ['room', 'overview', 'works_summary', 'solution_type_id'];
        $this->assertSame(
            $expectedKeys,
            array_keys($out[0]),
            'Phase 22.1 D-02: room_overviews schema must contain ONLY ' . implode(',', $expectedKeys),
        );
        $this->assertArrayNotHasKey('summary',     $out[0]);
        $this->assertArrayNotHasKey('description', $out[0]);
        $this->assertArrayNotHasKey('scope',       $out[0]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The two remaining narrative fields are preserved verbatim
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseRoomOverviews_preserves_overview_and_works_summary_verbatim(): void
    {
        $raw = [
            [
                'room'          => 'Cinnamon',
                'overview'      => "PM-typed prose paragraph.\nWith a second line.",
                'works_summary' => "- Bullet one\n- Bullet two",
            ],
        ];

        $out = $this->service->normalise(['room_overviews' => $raw])['room_overviews'];

        $this->assertSame("PM-typed prose paragraph.\nWith a second line.", $out[0]['overview']);
        $this->assertSame("- Bullet one\n- Bullet two", $out[0]['works_summary']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // solution_type_id handling unchanged
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseRoomOverviews_solution_type_id_int_or_null(): void
    {
        $raw = [
            ['room' => 'A', 'overview' => '', 'works_summary' => '', 'solution_type_id' => 7],
            ['room' => 'B', 'overview' => '', 'works_summary' => '', 'solution_type_id' => 0],
            ['room' => 'C', 'overview' => '', 'works_summary' => '', 'solution_type_id' => null],
            ['room' => 'D', 'overview' => '', 'works_summary' => ''],
        ];

        $out = $this->service->normalise(['room_overviews' => $raw])['room_overviews'];

        $this->assertSame(7, $out[0]['solution_type_id']);
        $this->assertNull($out[1]['solution_type_id'], '0 collapses to null per legacy semantics');
        $this->assertNull($out[2]['solution_type_id']);
        $this->assertNull($out[3]['solution_type_id'], 'missing key collapses to null');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Empty / non-array inputs still safe
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseRoomOverviews_handles_empty_and_invalid_inputs(): void
    {
        $emptyOut = $this->service->normalise(['room_overviews' => []])['room_overviews'];
        $this->assertSame([], $emptyOut);

        $missingOut = $this->service->normalise([])['room_overviews'];
        $this->assertSame([], $missingOut);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-09 (Phase 22.1 Plan 05): reviewed_data.project.overview is dropped
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseProject_does_not_emit_overview_key(): void
    {
        $out = $this->service->normalise([
            'project' => ['overview' => 'raw quote prose that no consumer reads'],
        ])['project'];

        $this->assertArrayNotHasKey('overview', $out,
            'Phase 22.1 D-09: reviewed_data.project.overview is dropped — no consumer reads it.');
    }

    public function test_normaliseProject_output_has_exactly_canonical_keys(): void
    {
        $out = $this->service->normalise(['project' => []])['project'];

        // Phase 22.1 D-09: project shape is exactly 11 keys — `overview` removed.
        $expected = [
            'project_name', 'quote_ref', 'client_name', 'site_name',
            'site_address', 'site_contact', 'prepared_by',
            'project_manager', 'lead_engineer', 'additional_engineers', 'programmer',
        ];
        $this->assertSame($expected, array_keys($out),
            'Phase 22.1 D-09: project array must contain exactly the 11 canonical keys (overview removed).');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 26 Plan 05 (HAZ-04): normaliseHazards() extended numeric-score schema
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normaliseHazards_round_trips_numeric_scores_and_markers_unchanged(): void
    {
        $raw = [
            [
                'activity_key'       => 'display_installation',
                'hazard'             => 'Working at Height',
                'pre_likelihood'     => 3,
                'pre_severity'       => 4,
                'post_likelihood'    => 1,
                'post_severity'      => 4,
                'score_reviewed'     => true,
                'needs_confirmation' => false,
                'control_measures'   => ['Use podium steps'],
            ],
        ];

        $out = $this->service->normalise(['hazards' => $raw])['hazards'];

        $this->assertCount(1, $out);
        $this->assertSame(3, $out[0]['pre_likelihood']);
        $this->assertSame(4, $out[0]['pre_severity']);
        $this->assertSame(1, $out[0]['post_likelihood']);
        $this->assertSame(4, $out[0]['post_severity']);
        $this->assertTrue($out[0]['score_reviewed']);
        $this->assertFalse($out[0]['needs_confirmation']);
        $this->assertSame(['Use podium steps'], $out[0]['control_measures']);
    }

    public function test_normaliseHazards_legacy_risk_only_row_falls_back_to_bucket_convention(): void
    {
        $raw = [
            [
                'hazard' => 'Working at Height',
                'risk'   => 'High',
            ],
        ];

        $out = $this->service->normalise(['hazards' => $raw])['hazards'];

        // High => [4,4] initial, matching RamsBuilderService::riskLevelsFromString(),
        // residual one lower per reviewedToRisk()'s existing max(1, preL-1) pattern.
        $this->assertSame(4, $out[0]['pre_likelihood']);
        $this->assertSame(4, $out[0]['pre_severity']);
        $this->assertSame(3, $out[0]['post_likelihood']);
        $this->assertSame(3, $out[0]['post_severity']);
        $this->assertFalse($out[0]['score_reviewed']);
        $this->assertFalse($out[0]['needs_confirmation']);
    }

    public function test_normaliseHazards_clamps_out_of_range_and_falls_back_on_non_numeric(): void
    {
        $raw = [
            [
                'hazard'         => 'Working at Height',
                'pre_likelihood' => 9,
            ],
            [
                'hazard'         => 'Manual Handling',
                'pre_likelihood' => 'abc',
                'risk'           => 'Low',
            ],
        ];

        $out = $this->service->normalise(['hazards' => $raw])['hazards'];

        $this->assertSame(5, $out[0]['pre_likelihood'], 'out-of-range numeric clamps to 5');
        $this->assertSame(2, $out[1]['pre_likelihood'], 'non-numeric falls back to legacy-bucket default (Low => 2)');
    }

    public function test_normaliseHazards_output_does_not_contain_legacy_risk_key(): void
    {
        $out = $this->service->normalise(['hazards' => [['hazard' => 'Test', 'risk' => 'Medium']]])['hazards'];

        $this->assertArrayNotHasKey(
            'risk',
            $out[0],
            'Phase 26 HAZ-04: risk is fully superseded by the 4 numeric fields + score_reviewed',
        );
    }
}
