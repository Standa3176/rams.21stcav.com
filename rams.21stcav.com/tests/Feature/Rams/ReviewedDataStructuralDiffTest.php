<?php

namespace Tests\Feature\Rams;

use App\Services\RamsReviewDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22.1 D-13: structural JSON diff between legacy and migrated
 * reviewed_data shape.
 *
 * Complementary to the Wave-1 RamsRenderRegressionTest (byte-equivalence of
 * rendered output). This test asserts the DATA SHAPE invariants directly:
 * the deprecated keys are absent from the normaliser output; the canonical
 * keys remain present and unchanged.
 *
 * Fixture mimics a pre-Phase-22.1 reviewed_data record — every legacy key
 * is populated so any future revert to old behaviour materially trips at
 * least one assertion in this file.
 *
 * Decision map:
 *   - D-09 → project.overview        (dropped from project normaliser)
 *   - D-07 → room_overviews[*].summary
 *   - D-01 → room_overviews[*].description
 *   - D-08 → room_overviews[*].scope
 *
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-13
 * @see app/Services/RamsReviewDataService.php (the normaliser under test)
 */
class ReviewedDataStructuralDiffTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fixture mimicking a pre-Phase-22.1 reviewed_data record — has all the
     * legacy keys populated, including project.overview and per-room
     * summary/description/scope. Used by every test method below.
     */
    private function legacyReviewedDataFixture(): array
    {
        return [
            'project' => [
                'project_name'         => 'Acme HQ Refit',
                'quote_ref'            => 'Q-2026-001',
                'client_name'          => 'Acme Co',
                'site_name'            => 'HQ',
                'site_address'         => '1 Test Street, London',
                'site_contact'         => 'PM Smith',
                'prepared_by'          => 'Engineer Brown',
                'project_manager'      => 'PM Smith',
                'lead_engineer'        => 'Engineer Brown',
                'additional_engineers' => 'Engineer Green',
                'programmer'           => 'Programmer Black',
                'overview'             => 'LEGACY: raw quote prose. D-09 drops this key.',
            ],
            'scope_of_works'         => 'AV installation across Boardroom and Cinnamon meeting rooms.',
            'works_overview'         => 'Two-room AV: display + audio + control.',
            'method_statement_notes' => 'PM instructions: coordinate with site security for after-hours access.',
            'room_overviews' => [
                [
                    'room'             => 'Boardroom',
                    'overview'         => 'PM prose narrative for Boardroom.',
                    'works_summary'    => "- Install 98\" display\n- Deploy Crestron Flex",
                    'summary'          => "- LEGACY: identical to works_summary. D-07 drops.",
                    'description'      => "LEGACY: AI prose paragraph. D-01 drops.",
                    'scope'            => "LEGACY: never written. D-08 drops.",
                    'solution_type_id' => null,
                ],
                [
                    'room'             => 'Cinnamon',
                    'overview'         => 'PM prose narrative for Cinnamon.',
                    'works_summary'    => "- Install ceiling mics\n- Configure Q-SYS",
                    'summary'          => '',
                    'description'      => '',
                    'scope'            => '',
                    'solution_type_id' => null,
                ],
            ],
            // Other keys retained verbatim (activities, hazards, ppe, access,
            // equipment, programme, site_logistics, meta) — minimal placeholders.
            'activities'     => [['key' => 'mount', 'label' => 'Mount display']],
            'hazards'        => [['activity_key' => 'mount', 'hazard' => 'Falls from height', 'risk' => 'Medium', 'control_measures' => []]],
            'ppe'            => ['Hard hat'],
            'access'         => ['ladders' => true, 'tower' => false, 'scissor_lift' => false, 'out_of_hours' => false, 'live_environment' => false],
            'equipment'      => [['quantity' => 1, 'part_number' => 'X-1', 'name' => 'Test', 'area' => 'Boardroom', 'category' => 'display']],
            'meta'           => ['parser_confidence' => 1.0, 'source' => 'reviewed'],
            'programme'      => [],
            'site_logistics' => [],
        ];
    }

    // ── Project-level dropped keys (D-09) ────────────────────────────────────

    public function test_normaliseProject_drops_overview_key_d09(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $out = $svc->normalise($legacy);

        $this->assertArrayNotHasKey('overview', $out['project'],
            'D-09: reviewed_data.project.overview must be dropped by the normaliser. '
            . 'Legacy quote prose ("' . substr($legacy['project']['overview'], 0, 30) . '...") '
            . 'must not survive normalisation.');
    }

    public function test_normaliseProject_canonical_keys_are_exactly_eleven_d09(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $out = $svc->normalise($legacy)['project'];

        $expected = [
            'project_name', 'quote_ref', 'client_name', 'site_name',
            'site_address', 'site_contact', 'prepared_by',
            'project_manager', 'lead_engineer', 'additional_engineers', 'programmer',
        ];
        $this->assertSame($expected, array_keys($out),
            'D-09: project schema must contain exactly 11 canonical keys (no overview).');

        // Explicit completeness assertion — the legacy "overview" key is the
        // only one the cleanup removes, and the project schema is closed
        // (no untracked extras). Belt-and-braces guard for D-09.
        $this->assertArrayNotHasKey('overview', $out,
            'D-09 completeness: the project sub-array must not contain overview alongside the canonical 11 keys.');
        $this->assertCount(11, $out,
            'D-09: project sub-array must contain exactly 11 entries.');
    }

    // ── Per-room dropped keys (D-01 / D-07 / D-08) ──────────────────────────

    public function test_normaliseRoomOverviews_drops_summary_description_scope_d07_d01_d08(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $rooms = $svc->normalise($legacy)['room_overviews'];

        $this->assertCount(2, $rooms);
        foreach ($rooms as $idx => $row) {
            $this->assertArrayNotHasKey('summary',     $row, "Row {$idx}: D-07 drops legacy summary.");
            $this->assertArrayNotHasKey('description', $row, "Row {$idx}: D-01 drops legacy description.");
            $this->assertArrayNotHasKey('scope',       $row, "Row {$idx}: D-08 drops legacy scope.");
        }
    }

    public function test_normaliseRoomOverviews_canonical_keys_are_exactly_four(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $rooms = $svc->normalise($legacy)['room_overviews'];

        $expected = ['room', 'overview', 'works_summary', 'solution_type_id'];
        foreach ($rooms as $idx => $row) {
            $this->assertSame($expected, array_keys($row),
                "Row {$idx}: per-room schema must contain exactly 4 canonical keys.");
        }
    }

    // ── Retained keys preserved verbatim ────────────────────────────────────

    public function test_retained_keys_preserve_legacy_values_verbatim(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $out = $svc->normalise($legacy);

        // Project — 11 canonical fields preserved verbatim
        $this->assertSame('Acme HQ Refit', $out['project']['project_name']);
        $this->assertSame('Q-2026-001',    $out['project']['quote_ref']);
        $this->assertSame('Acme Co',       $out['project']['client_name']);
        $this->assertSame('HQ',            $out['project']['site_name']);
        $this->assertSame('PM Smith',      $out['project']['project_manager']);

        // Top-level scope keys preserved verbatim
        $this->assertSame('AV installation across Boardroom and Cinnamon meeting rooms.', $out['scope_of_works']);
        $this->assertSame('Two-room AV: display + audio + control.', $out['works_overview']);
        $this->assertSame($legacy['method_statement_notes'], $out['method_statement_notes']);

        // Per-room canonical fields preserved verbatim
        $this->assertSame('Boardroom', $out['room_overviews'][0]['room']);
        $this->assertSame('PM prose narrative for Boardroom.', $out['room_overviews'][0]['overview']);
        $this->assertStringContainsString("Install 98\" display", $out['room_overviews'][0]['works_summary']);

        $this->assertSame('Cinnamon', $out['room_overviews'][1]['room']);
        $this->assertSame('PM prose narrative for Cinnamon.', $out['room_overviews'][1]['overview']);
        $this->assertStringContainsString('Q-SYS', $out['room_overviews'][1]['works_summary']);
    }

    public function test_retained_top_level_arrays_pass_through_normaliser(): void
    {
        $legacy = $this->legacyReviewedDataFixture();
        $svc = app(RamsReviewDataService::class);
        $out = $svc->normalise($legacy);

        // Spot-check the other retained top-level keys round-trip through
        // their own normalisers without dropping data — this guards against a
        // future regression where someone "tidies" the top-level shape and
        // accidentally drops a key.
        $this->assertSame([['key' => 'mount', 'label' => 'Mount display']], $out['activities']);
        $this->assertSame(['Hard hat'], $out['ppe']);
        $this->assertTrue($out['access']['ladders']);
        $this->assertFalse($out['access']['tower']);

        $this->assertCount(1, $out['equipment']);
        $this->assertSame('X-1', $out['equipment'][0]['part_number']);

        // Meta + programme + site_logistics survive normalisation.
        $this->assertSame('reviewed', $out['meta']['source']);
        $this->assertIsArray($out['programme']);
        $this->assertIsArray($out['site_logistics']);
    }

    // ── Write-side guards (Plan 07 — closure for 22.1-VERIFICATION.md gaps) ──
    //
    // The 6 READ-side methods above exercise RamsReviewDataService::normalise()
    // only. The verifier (2026-05-13) found that the WRITE path
    // (ProjectPackageReviewController::parseReviewPayload + $package->update)
    // was still re-introducing legacy keys on every save, undoing the backfill
    // artisan's migration for any record the PM touched. These two methods
    // close that gap.

    public function test_write_side_parseReviewPayload_drops_legacy_summary_description_keys(): void
    {
        // Simulate a stale-client POST that includes all 6 legacy keys per row.
        // After Phase 22.1 closure, parseReviewPayload must silently drop the
        // `summary` and `description` keys, persisting exactly the 4 canonical
        // keys.
        $hostilePayload = [
            'room_overviews' => [
                [
                    'room'             => 'Boardroom',
                    'overview'         => 'PM prose.',
                    'works_summary'    => "- Install display",
                    'summary'          => 'LEGACY — stale client posted this.',
                    'description'      => 'LEGACY — dead textarea reposted.',
                    'solution_type_id' => 1,
                ],
                [
                    'room'             => 'Cinnamon',
                    'overview'         => '',
                    'works_summary'    => '',
                    'summary'          => 'LEGACY',
                    'description'      => 'LEGACY',
                    'solution_type_id' => 0,
                ],
            ],
        ];

        // Invoke the private parseReviewPayload via reflection — this is the
        // exact code path a real form POST traverses. parseReviewPayload's
        // signature is `parseReviewPayload(Request $request): array` and its
        // first line is `$request->except([...])`, so the reflection call
        // MUST construct a real Request from the raw payload before invoking
        // — passing a raw array throws TypeError.
        $controller = app(\App\Http\Controllers\ProjectPackageReviewController::class);
        $reflection = new \ReflectionMethod($controller, 'parseReviewPayload');
        $reflection->setAccessible(true);
        $request = \Illuminate\Http\Request::create('/dummy', 'POST', $hostilePayload);
        $parsed  = $reflection->invoke($controller, $request);

        $this->assertArrayHasKey('room_overviews', $parsed,
            'parseReviewPayload must always return a room_overviews key.');
        $this->assertCount(2, $parsed['room_overviews']);

        $expectedKeys = ['room', 'overview', 'works_summary', 'solution_type_id'];
        sort($expectedKeys);

        foreach ($parsed['room_overviews'] as $idx => $row) {
            $actualKeys = array_keys($row);
            sort($actualKeys);
            $this->assertSame($expectedKeys, $actualKeys,
                "Row {$idx}: parseReviewPayload must persist exactly the 4 canonical keys. "
                . "Found: " . implode(', ', $actualKeys) . '. '
                . "Phase 22.1 closure (Plan 07) — see 22.1-VERIFICATION.md gaps.");
            $this->assertArrayNotHasKey('summary',     $row, "Row {$idx}: legacy summary key leaked through parseReviewPayload.");
            $this->assertArrayNotHasKey('description', $row, "Row {$idx}: legacy description key leaked through parseReviewPayload.");
        }
    }

    public function test_write_side_package_update_round_trips_canonical_shape_unchanged(): void
    {
        // The JSON-column cast must not re-introduce or strip keys. A payload
        // with exactly the 4 canonical keys must round-trip unchanged through
        // $package->update(...) + $package->fresh().
        $user = \App\Models\User::factory()->create();
        $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);
        $package = \App\Models\ProjectPackage::create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'extracted_data' => [],
        ]);

        $canonicalPayload = [
            'room_overviews' => [
                [
                    'room'             => 'Boardroom',
                    'overview'         => 'PM prose.',
                    'works_summary'    => "- Install display",
                    'solution_type_id' => 1,
                ],
            ],
        ];

        $package->update(['extracted_data' => $canonicalPayload]);
        $persisted = $package->fresh()->extracted_data;

        $this->assertIsArray($persisted);
        $this->assertArrayHasKey('room_overviews', $persisted);
        $this->assertCount(1, $persisted['room_overviews']);

        $row = $persisted['room_overviews'][0];
        $expectedKeys = ['room', 'overview', 'works_summary', 'solution_type_id'];
        sort($expectedKeys);
        $actualKeys = array_keys($row);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys,
            'Round-trip through $package->update + fresh() must preserve exactly the 4 canonical keys. '
            . 'Phase 22.1 closure (Plan 07).');

        // Sanity — the values survive the cast intact.
        $this->assertSame('Boardroom', $row['room']);
        $this->assertSame("- Install display", $row['works_summary']);
        $this->assertSame(1, $row['solution_type_id']);
    }
}
