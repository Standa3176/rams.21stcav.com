<?php

namespace Tests\Feature\Rams;

use App\Exceptions\RamsGenerationException;
use App\Models\HazardTemplate;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 28 Plan 06 (GATE-06/GATE-07), Revision 1 — closes plan-checker
 * Blocker 2. The plan's original draft declined a dedicated dual-path test
 * on the assertion that `enforceFfp2AndConfinedSpaceGate()` "scans a generic
 * already-assembled array structure identical regardless of entry point" —
 * that is an assertion, not evidence, and it is the same category of
 * assumption that closed Phase 26 prematurely TWICE (27-VERIFICATION.md
 * Blocker 1). This file proves it instead, driving BOTH real generation
 * entry points directly (not via the Save Review HTTP route, which is
 * already covered by {@see Ffp2ConfinedSpaceSaveReviewGateTest}):
 *
 *   - test_gate_throws_via_run_pipeline() drives
 *     RamsBuilderService::buildFromForm() (`runPipeline()`).
 *   - test_gate_throws_via_run_from_review() drives
 *     RamsBuilderService::buildFromReview() (`runFromReview()`).
 *
 * ── Why the runPipeline() fixture needs a local HazardTemplate ────────────
 *
 * `buildFromForm()`'s `formData['hazards']` is a flat array of hazard NAMES
 * only (`resources/views/rams/create.blade.php`, `name="hazards[]"`), and
 * `RiskTemplateResolverService::buildHazards()` always sources `controls`
 * from the resolved `HazardTemplate` — never from form input. The seeded
 * 18-hazard library is provably FFP2-clean (Plan 28-01's corpus test), so a
 * name-only fixture can never carry a violating control line through this
 * path. Mirroring `DisplayLiftDualPathTest`'s own explicit workaround for
 * the analogous problem, this file creates a local, non-global
 * (`is_global = false`) `HazardTemplate` owned by the fixture's user, whose
 * `controls` include the violating FFP2 sentence, then references that
 * template's exact name in the form fixture — `HazardLibraryService::
 * resolveFromSeeds()`'s exact-match tier resolves it deterministically.
 *
 * ── Why the runFromReview() fixture uses a NAME violation, not a control
 * violation ────────────────────────────────────────────────────────────────
 *
 * Using a hazard named 'Confined Space' (which resolves to no library
 * template — `LegacyHazardNameFoldMap` only folds the exact plural
 * 'confined spaces') makes this one test do double duty: it proves both the
 * dual-path wiring AND Blocker 1's name-field check on the
 * regenerate-from-review path, which is the most common real-world path an
 * engineer uses to correct a document.
 *
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate()
 * @see .planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-06-PLAN.md
 */
class Ffp2ConfinedSpaceDualPathGateTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClaudeResponse(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['phases' => [
                ['title' => 'Phase 1: Pre-works', 'steps' => ['Site induction', 'PPE check']],
            ]])]],
            'stop_reason' => 'end_turn',
        ], 200)]);
    }

    // ── runPipeline() (buildFromForm()) ──────────────────────────────────────

    public function test_gate_throws_via_run_pipeline(): void
    {
        $this->fakeClaudeResponse();

        $user = User::factory()->create();

        // A local, non-global template — the ONLY way a violating control
        // line can reach this path, since formData['hazards'] carries names
        // only (see class docblock).
        $template = HazardTemplate::create([
            'user_id'         => $user->id,
            'name'            => 'FFP2 Dual Path Test Hazard',
            'description'     => null,
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'post_likelihood' => 1,
            'post_severity'   => 2,
            'controls'        => ['Dust mask (FFP2) worn when accessing ceiling voids.'],
            'is_global'       => false,
        ]);

        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-PIPE-001',
            'project_name'   => 'Dual Path Gate Test (Manual)',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-pipe-001.docx',
            'form_data'      => [
                'source'            => 'manual_form',
                'project_ref'       => 'DUAL-PIPE-001',
                'project_name'      => 'Dual Path Gate Test (Manual)',
                'client_name'       => 'Acme Ltd',
                'site_address'      => '1 Test Street',
                'works_description' => 'Supply and installation of AV systems throughout the premises.',
                'hazards'           => [$template->name],
                'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
                'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
            ],
            'generated_data' => [],
        ]);

        try {
            app(RamsBuilderService::class)->buildFromForm($rams->form_data, $rams);
            $this->fail('Expected RamsGenerationException was not thrown via buildFromForm()/runPipeline().');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('FFP2', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
    }

    // ── runFromReview() (buildFromReview()) ─────────────────────────────────

    public function test_gate_throws_via_run_from_review(): void
    {
        $this->fakeClaudeResponse();

        $user = User::factory()->create();

        $reviewedData = [
            'project' => [
                'project_name' => 'Dual Path Gate Test',
                'quote_ref'    => 'DUAL-RFR-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'  => [],
            'activities' => [],
            'hazards'    => [
                [
                    'hazard'           => 'Confined Space',
                    'control_measures' => ['Ventilation confirmed before entry.'],
                ],
            ],
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
        ];

        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-RFR-001',
            'project_name'   => 'Dual Path Gate Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-rfr-001.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);

        try {
            app(RamsBuilderService::class)->buildFromReview($rams->reviewed_data, [], $rams);
            $this->fail('Expected RamsGenerationException was not thrown via buildFromReview()/runFromReview().');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('Confined Space', $e->getMessage());
            $this->assertStringContainsString('GATE-07/RULE-06', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
    }
}
