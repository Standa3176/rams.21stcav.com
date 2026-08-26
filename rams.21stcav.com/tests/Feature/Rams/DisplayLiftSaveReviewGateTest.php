<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27 Plan 07, Task 1 — GATE-09 coverage-gap closure for the "Save
 * Review" path.
 *
 * `RamsController::updateAndDownload()` builds `$generatedData` and calls
 * `RamsComplianceUpgradeService::upgrade()` directly, but historically never
 * mirrored `reviewed_data['material_handling']` onto `$generatedData` first
 * (unlike the existing `site_emergency` mirror) — so
 * `enforceDisplayLiftGate()`'s engineer-row loop always saw an empty array
 * on this exact request, even though this is the route an engineer actually
 * uses to save a non-conforming team size.
 *
 * Drives the real route (`POST /rams/{rams}/update-and-download`) with a
 * realistic form payload — not the controller method directly — so these
 * tests fail if routing, middleware, or validation regress the fix.
 *
 * @see App\Http\Controllers\RamsController::updateAndDownload()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceDisplayLiftGate()
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-07-PLAN.md
 */
class DisplayLiftSaveReviewGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(User $user): RamsDocument
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
            ],
            'reviewed_data' => [],
            'status'        => RamsDocument::STATUS_FOR_REVIEW,
            'filename'      => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadWithHandling(string $handlingMethod, string $item = 'Samsung 98" display'): array
    {
        return [
            'project_name'  => 'Test Project',
            'project_ref'   => 'TEST-001',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '123 Test Street',
            'material_handling_items' => [
                [
                    'item'            => $item,
                    'handling_method' => $handlingMethod,
                ],
            ],
        ];
    }

    // ── Blocked: 4-persons team size never conforms ────────────────────────

    public function test_four_persons_is_blocked_on_save_review_with_item_name_in_error(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(
                route('rams.update-and-download', $rams),
                $this->payloadWithHandling('Team lift — minimum 4 persons'),
            );

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Samsung 98" display', session('error'));

        $rams->refresh();
        // Nothing persisted as if the save succeeded — reviewed_data / status
        // must not reflect a completed save.
        $this->assertSame([], $rams->reviewed_data);
    }

    // ── Allowed: 3-persons conforms at 98" ──────────────────────────────────

    public function test_three_persons_saves_and_proceeds_normally(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(
                route('rams.update-and-download', $rams),
                $this->payloadWithHandling('Team lift — minimum 3 persons'),
            );

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        $this->assertSame(
            'Samsung 98" display',
            $rams->reviewed_data['material_handling']['large_items'][0]['item'] ?? null,
        );
    }

    // ── Allowed: no parseable team size is never blocked ────────────────────

    public function test_unparseable_handling_method_saves_and_proceeds_normally(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(
                route('rams.update-and-download', $rams),
                $this->payloadWithHandling('Use a trolley'),
            );

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        $this->assertSame(
            'Samsung 98" display',
            $rams->reviewed_data['material_handling']['large_items'][0]['item'] ?? null,
        );
    }

    // ── Kill-switch: RAMS_DISPLAY_LIFT_GATE=false is a genuine rollback here too ──

    public function test_kill_switch_allows_four_persons_to_save_when_gate_disabled(): void
    {
        config(['rams_tier1.display_lift_gate_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(
                route('rams.update-and-download', $rams),
                $this->payloadWithHandling('Team lift — minimum 4 persons'),
            );

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        $this->assertSame(
            'Samsung 98" display',
            $rams->reviewed_data['material_handling']['large_items'][0]['item'] ?? null,
        );
    }
}
