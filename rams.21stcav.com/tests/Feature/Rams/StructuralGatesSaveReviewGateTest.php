<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 30 Plan 03, Task 3 — armed-gate HTTP surfacing proof for GATE-01
 * (enforceOrphanControlGate()) and GATE-02 (enforceAreaCoverageGate()) on
 * the real "Save Review" route (`POST /rams/{rams}/update-and-download`).
 *
 * Modeled directly on `DisplayLiftSaveReviewGateTest` — the exact analog
 * `30-PATTERNS.md` maps for this surface — reusing its `makeRams(User
 * $user)` and realistic-payload-helper shape so these tests fail if
 * routing, middleware or validation regress the fix, not just the gate
 * logic in isolation.
 *
 * This is deliberately distinct from `StructuralGatesDualPathTest` (Plan
 * 30-02), which POSTs the same route with the gates DISARMED to prove the
 * `client_responsibilities_expanded`/`areas_for_gate` mirrors are
 * populated. That file proves the mirror; this file proves an ARMED gate's
 * `RamsGenerationException` is caught and surfaced as a friendly redirect,
 * not an unhandled 500 — replicating the GATE-06/GATE-09 precedent
 * REQUIREMENTS.md:66 records ("reachable and caught with a friendly
 * redirect on all 3 HTTP-reachable upgrade() call sites").
 *
 * Only the Save Review site (`RamsController.php:598-611` mirrors,
 * `:621-624` catch) is driven here — its catch block explicitly
 * `return back()->withInput()->with('error', $e->getMessage())`. The other
 * two catch sites do NOT behave alike: the DOCX-rebuild-on-download catch
 * (`~:701`) catches the broader `\Throwable`, logs, sets `$rebuildError`
 * and CONTINUES rather than redirecting — a different surfacing shape this
 * file does not assert against.
 *
 * @see App\Http\Controllers\RamsController::updateAndDownload()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceOrphanControlGate()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceAreaCoverageGate()
 * @see tests\Feature\Rams\DisplayLiftSaveReviewGateTest.php
 * @see tests\Feature\Rams\StructuralGatesDualPathTest.php
 * @see .planning/phases/30-structural-validation-gates/30-03-PLAN.md
 */
class StructuralGatesSaveReviewGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(User $user, array $overrides = []): RamsDocument
    {
        return RamsDocument::create(array_merge([
            'user_id'        => $user->id,
            'project_ref'    => 'TEST-001',
            'project_name'   => 'Test Project',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '123 Test Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => [],
            'generated_data' => [
                'project' => [
                    'name'         => 'Test Project',
                    'ref'          => 'TEST-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
            ],
            'reviewed_data' => [],
            'status'        => RamsDocument::STATUS_FOR_REVIEW,
            'filename'      => null,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function basePayload(): array
    {
        return [
            'project_name'  => 'Test Project',
            'project_ref'   => 'TEST-001',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '123 Test Street',
        ];
    }

    // ── GATE-01: armed orphan control blocks the save ───────────────────────

    public function test_gate01_armed_blocks_save_review_with_orphan_control_message(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            'generated_data' => [
                'project' => [
                    'name'         => 'Test Project',
                    'ref'          => 'TEST-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
                'method_statement' => [
                    'phases' => [
                        [
                            'title' => 'Site Survey',
                            'steps' => ['Review the asbestos register before any drilling works begin.'],
                        ],
                    ],
                ],
                'hazards' => [],
                'client_responsibilities' => [],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->basePayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('asbestos register', session('error'));
        $this->assertStringContainsString('GATE-01', session('error'));

        // Nothing persisted — the throw precedes the persist
        // (RamsController.php ~:623), so the record's generated_data must
        // still be the pre-request seed, not upgrade()'s output.
        $rams->refresh();
        $this->assertArrayNotHasKey('compliance_warnings', $rams->generated_data);
    }

    public function test_gate01_disarmed_allows_the_same_violating_payload_through(): void
    {
        config(['rams_tier1.structural_gates_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            'generated_data' => [
                'project' => [
                    'name'         => 'Test Project',
                    'ref'          => 'TEST-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
                'method_statement' => [
                    'phases' => [
                        [
                            'title' => 'Site Survey',
                            'steps' => ['Review the asbestos register before any drilling works begin.'],
                        ],
                    ],
                ],
                'hazards' => [],
                'client_responsibilities' => [],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->basePayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        // upgrade() ran to completion (disarmed gate never threw) — the
        // unconditional compliance_warnings channel is present.
        $this->assertArrayHasKey('compliance_warnings', $rams->generated_data);
    }

    // ── GATE-02: armed area-coverage gap blocks the save ────────────────────

    public function test_gate02_armed_blocks_save_review_with_area_coverage_message(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            'generated_data' => [
                'project' => [
                    'name'         => 'Test Project',
                    'ref'          => 'TEST-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
                'method_statement' => [
                    'phases' => [
                        ['title' => 'Install Displays', 'steps' => ['Mount the display in Reception.']],
                    ],
                ],
                'hazards' => [],
                'client_responsibilities' => [],
            ],
            // The Save Review controller reads room_overviews from
            // reviewed_data (never the POST payload) — pre-seed it exactly
            // as StructuralGatesDualPathTest does, so areas_for_gate mirrors
            // a real, uncovered area name.
            'reviewed_data' => [
                'room_overviews' => [
                    ['room' => 'Boardroom 2', 'overview' => 'Secondary meeting space.', 'works_summary' => '- Install display'],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->basePayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Boardroom 2', session('error'));
        $this->assertStringContainsString('GATE-02', session('error'));

        $rams->refresh();
        $this->assertArrayNotHasKey('compliance_warnings', $rams->generated_data);
    }

    public function test_gate02_disarmed_allows_the_same_violating_payload_through(): void
    {
        config(['rams_tier1.structural_gates_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            'generated_data' => [
                'project' => [
                    'name'         => 'Test Project',
                    'ref'          => 'TEST-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
                'method_statement' => [
                    'phases' => [
                        ['title' => 'Install Displays', 'steps' => ['Mount the display in Reception.']],
                    ],
                ],
                'hazards' => [],
                'client_responsibilities' => [],
            ],
            'reviewed_data' => [
                'room_overviews' => [
                    ['room' => 'Boardroom 2', 'overview' => 'Secondary meeting space.', 'works_summary' => '- Install display'],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->basePayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        $this->assertSame(['Boardroom 2'], $rams->generated_data['areas_for_gate'] ?? null);
    }
}
