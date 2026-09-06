<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 Plan 06 (GATE-06/GATE-07) — coverage for the "Save Review" path.
 *
 * `RamsController::updateAndDownload()` seeds `$generatedData` from the
 * document's EXISTING `generated_data` and only merges specific keys
 * (`project`, `site_emergency`, `material_handling`) from the request —
 * `hazards` is never touched by this request, so a pre-Phase-28 document's
 * stale `generated_data['hazards'][*]['controls']` survives untouched into
 * the array `RamsComplianceUpgradeService::upgrade()` is called against.
 * This is exactly the path Plan 28-01's tier-1 auto-correction (which lives
 * inside `RamsBuilderService::reviewedToRisk()`, never called by this
 * controller method) cannot reach — this gate is the only thing that
 * catches it here.
 *
 * Drives the real route (`POST /rams/{rams}/update-and-download`) with a
 * realistic form payload — not the controller method directly — so this
 * test fails if routing, middleware, or validation regress the fix.
 *
 * @see App\Http\Controllers\RamsController::updateAndDownload()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate()
 * @see .planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-06-PLAN.md
 */
class Ffp2ConfinedSpaceSaveReviewGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(User $user, array $hazards): RamsDocument
    {
        return RamsDocument::create([
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
                'hazards' => $hazards,
            ],
            'reviewed_data' => [],
            'status'        => RamsDocument::STATUS_FOR_REVIEW,
            'filename'      => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function minimalPayload(): array
    {
        return [
            'project_name' => 'Test Project',
            'project_ref'  => 'TEST-001',
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Test Street',
        ];
    }

    // ── Blocked: a stale, pre-Phase-28 FFP2 control line never conforms ────

    public function test_stale_ffp2_control_line_is_blocked_on_save_review(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            [
                'hazard'   => 'Dust from Drilling and Cutting',
                'controls' => ['Dust mask (FFP2) worn when accessing ceiling voids.'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->minimalPayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Dust mask (FFP2) worn when accessing ceiling voids.', session('error'));
        $this->assertStringContainsString('GATE-06/RULE-01', session('error'));

        $rams->refresh();
        // Nothing persisted as if the save succeeded.
        $this->assertSame([], $rams->reviewed_data);
    }

    // ── Blocked: an unresolved "Confined Space" hazard NAME never conforms ──

    public function test_stale_confined_space_hazard_name_is_blocked_on_save_review(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            [
                'hazard'   => 'Confined Space',
                'controls' => ['Ventilation confirmed before entry.'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->minimalPayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Confined Space', session('error'));
        $this->assertStringContainsString('GATE-07/RULE-06', session('error'));

        $rams->refresh();
        $this->assertSame([], $rams->reviewed_data);
    }

    // ── Allowed: a clean hazard proceeds normally ───────────────────────────

    public function test_clean_hazard_saves_and_proceeds_normally(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            [
                'hazard'   => 'Restricted access and ceiling void working',
                'controls' => ['Confirm ventilation and safe access before entering ceiling voids, comms rooms or enclosures. These are not classified as confined spaces, but access is restricted and is treated as a controlled activity.'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->minimalPayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
    }

    // ── Kill-switch: RAMS_PPE_CEILING_ELECTRICAL_GATE=false is a genuine rollback here too ──

    public function test_kill_switch_allows_stale_ffp2_control_line_to_save_when_gate_disabled(): void
    {
        config(['rams_tier1.ffp2_confined_space_gate_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            [
                'hazard'   => 'Dust from Drilling and Cutting',
                'controls' => ['Dust mask (FFP2) worn when accessing ceiling voids.'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), $this->minimalPayload());

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
    }
}
