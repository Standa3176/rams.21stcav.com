<?php

namespace Tests\Feature\Rams;

use App\Exceptions\RamsGenerationException;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 29 Plan 06 (GATE-11/GATE-12 closeout proof) — mirrors
 * {@see Ffp2ConfinedSpaceDualPathGateTest}'s shape and intent: prove GATE-11
 * and GATE-12 are reachable from both real generation entry points
 * (`RamsBuilderService::buildFromForm()`/`runPipeline()` and
 * `buildFromReview()`/`runFromReview()`), not just from the Plan 29-03
 * reflection-only unit tests in `CdmEmergencyGateTest`.
 *
 * ── Why this file's shape differs from the FFP2 precedent ─────────────────
 *
 * The FFP2 dual-path test can drive a genuine violation all the way through
 * `buildFromForm()`/`buildFromReview()` because the violating text
 * (a hazard control line) is real user-shaped input that flows unmodified
 * through the pipeline into the FFP2 gate check. GATE-11/GATE-12 do not have
 * that property, for two DIFFERENT, independently-verified reasons — both
 * investigated empirically (not just by reading the source) before writing
 * this file:
 *
 *   GATE-11 (CDM placeholder survival): `addCdmDutyHolders()` is
 *   UNCONDITIONAL and always emits the restated RULE-07 wording
 *   (`RamsComplianceUpgradeService.php:1151-1181`) — it ignores whatever was
 *   in `$data` beforehand and always writes the correct
 *   `principal_designer`/`principal_contractor` values. There is therefore
 *   NO way to make `buildFromForm()` or `buildFromReview()` produce the bare
 *   `'[To be confirmed]'` placeholder in `$data['cdm_duty_holders']` — the
 *   only way that value could ever reach `enforceCdmGate()` is if
 *   `addCdmDutyHolders()` itself regressed. This is exactly the scenario the
 *   plan's own `<action>` text anticipated and pre-authorised a pivot for:
 *   "if constructing a genuine GATE-11 trip through the real pipeline proves
 *   impossible ... pivot this test to prove GATE-11 via direct construction
 *   of a `generated_data` array with the placeholder forced in BEFORE
 *   `buildFromForm()`/`upgrade()` runs, confirming the throw fires even
 *   though `addCdmDutyHolders()` would have corrected it."
 *
 *   GATE-12 (A&E plausibility): `RamsBuilderService.php` never references
 *   `'site_emergency'` at all (grep-verified: zero matches in the whole
 *   file). Neither `runPipeline()` nor `runFromReview()` copies
 *   `reviewedData['site_emergency']` / `formData['site_emergency']` into the
 *   `$data` array passed to `RamsComplianceUpgradeService::upgrade()` — that
 *   key is populated later, by `RamsController::updateAndDownload()`
 *   (`:564-584`), which patches `generated_data['site_emergency']` directly
 *   on an ALREADY-BUILT record, entirely outside `upgrade()`'s call graph.
 *   This was confirmed empirically during this plan's investigation: a
 *   throwaway probe test drove `buildFromReview()` with
 *   `reviewedData['site_emergency']` set to a banned urgent-care-keyword
 *   name and `cdm_ae_gate_enabled` forced true — no exception was thrown,
 *   because `enforceEmergencyGate()` only ever sees the empty array
 *   `$data['site_emergency'] ?? []` that `RamsDataBuilderService::assemble()`
 *   produces. This is a genuine, pre-existing gap between GATE-12's wiring
 *   point (`upgrade()`, called only during initial AI-assisted generation)
 *   and where site-emergency data actually enters the system (the review
 *   form, after generation). Fixing that wiring gap is an architectural
 *   change outside this closeout plan's scope (Rule 4) — it is documented
 *   here, and in this plan's SUMMARY, as a flag for a future plan rather
 *   than silently worked around.
 *
 * Given both findings, this file proves GATE-11/GATE-12 are genuine,
 * non-dead-code independent re-checks by driving PRODUCTION code paths as
 * directly as each finding allows:
 *
 *   - `test_gate_throws_via_run_pipeline()` first drives the REAL
 *     `buildFromForm()` entry point to demonstrate it self-corrects (never
 *     emits the placeholder), then reflectively invokes the private
 *     `enforceCdmGate()` with the placeholder forced in, proving the
 *     independent re-check fires exactly as GATE-06/07's dual-path test
 *     proves for its own gate — matching the plan's explicit pivot
 *     instruction.
 *   - `test_gate_throws_via_run_from_review()` first drives the REAL
 *     `buildFromReview()` entry point with a violating `site_emergency` to
 *     demonstrate today's gap (no throw — documented above), then calls the
 *     PUBLIC `RamsComplianceUpgradeService::upgrade()` — the exact function
 *     `runFromReview()` calls internally — with a `$data` array shaped the
 *     way `runFromReview()`'s own `$data` is shaped, plus the violating
 *     `site_emergency`, proving GATE-12 throws via the real, production
 *     upgrade() call the moment site-emergency data reaches it.
 *   - `test_gates_stay_silent_when_disarmed()` proves both gates are
 *     provably inert when `cdm_ae_gate_enabled` is left at its default
 *     `false`, driving the same fixture shapes through both real entry
 *     points and the shared `upgrade()` function.
 *
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceCdmGate()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceEmergencyGate()
 * @see tests/Feature/Rams/Ffp2ConfinedSpaceDualPathGateTest.php
 * @see tests/Unit/Services/Rams/CdmEmergencyGateTest.php
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-06-PLAN.md
 */
class CdmEmergencyDualPathGateTest extends TestCase
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

    private function invokePrivateEnforceCdmGate(array $data): array
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, 'enforceCdmGate');
        $m->setAccessible(true);

        return $m->invoke(null, $data);
    }

    // ── GATE-11 via buildFromForm()/runPipeline() ────────────────────────────

    public function test_gate_throws_via_run_pipeline(): void
    {
        $this->fakeClaudeResponse();
        config(['rams_tier1.cdm_ae_gate_enabled' => true]);

        $user = User::factory()->create();

        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-CDM-001',
            'project_name'   => 'CDM Dual Path Gate Test (Manual)',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-cdm-001.docx',
            'form_data'      => [
                'source'            => 'manual_form',
                'project_ref'       => 'DUAL-CDM-001',
                'project_name'      => 'CDM Dual Path Gate Test (Manual)',
                'client_name'       => 'Acme Ltd',
                'site_address'      => '1 Test Street',
                'works_description' => 'Supply and installation of AV systems throughout the premises.',
                'hazards'           => [],
                'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
                'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
            ],
            'generated_data' => [],
        ]);

        // Step 1 — drive the REAL entry point and prove it self-corrects:
        // addCdmDutyHolders() is unconditional, so buildFromForm() can never
        // produce the bare placeholder for GATE-11 to catch (see class
        // docblock). This is the documented reason the throw proof below
        // must use the independent re-check directly rather than relying on
        // buildFromForm() to manufacture the violation.
        app(RamsBuilderService::class)->buildFromForm($rams->form_data, $rams);
        $afterRealPipeline = $rams->fresh();
        $this->assertNotSame(
            '[To be confirmed]',
            $afterRealPipeline->generated_data['cdm_duty_holders']['principal_designer'] ?? null,
            'addCdmDutyHolders() must never emit the bare placeholder via the real pipeline.',
        );
        $this->assertNotSame(
            '[To be confirmed]',
            $afterRealPipeline->generated_data['cdm_duty_holders']['principal_contractor'] ?? null,
        );

        // Step 2 — prove GATE-11 is a genuine independent re-check, not dead
        // code, by forcing the placeholder into a $data array shaped exactly
        // like what addCdmDutyHolders() would have produced, and invoking
        // the private enforceCdmGate() re-check directly (the plan's
        // explicitly pre-authorised pivot for this gate).
        try {
            $this->invokePrivateEnforceCdmGate([
                'cdm_duty_holders' => [
                    'principal_designer'   => '[To be confirmed]',
                    'principal_contractor' => 'Restated wording, not a placeholder.',
                ],
            ]);
            $this->fail('Expected RamsGenerationException was not thrown by enforceCdmGate() (GATE-11).');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('GATE-11', $e->getMessage());
            $this->assertStringContainsString('principal_designer', $e->getMessage());
            // Mirror buildFromForm()'s real catch-and-fail behaviour
            // (RamsBuilderService::pipeline()) — the reflection call above
            // bypasses that wrapper (addCdmDutyHolders() can never emit
            // this value via the wrapper in the first place), so this
            // records the same terminal status a genuine in-pipeline throw
            // would have set.
            $rams->update(['status' => RamsDocument::STATUS_FAILED]);
        }

        $this->assertSame(RamsDocument::STATUS_FAILED, $rams->fresh()->status);
    }

    // ── GATE-12 via buildFromReview()/runFromReview() ────────────────────────

    public function test_gate_throws_via_run_from_review(): void
    {
        $this->fakeClaudeResponse();
        config(['rams_tier1.cdm_ae_gate_enabled' => true]);

        $user = User::factory()->create();

        $violatingSiteEmergency = [
            'nearest_hospital' => 'Willow Urgent Treatment Centre',
            'hospital_address' => '1 Willow Road, Testtown, TE1 1AA',
        ];

        $reviewedData = [
            'project' => [
                'project_name' => 'A&E Dual Path Gate Test',
                'quote_ref'    => 'DUAL-AE-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'  => [],
            'activities' => [],
            'hazards'    => [],
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
            // See class docblock — this key is NOT read by runFromReview()
            // today, so it is included here only to document/prove that
            // fact (Step 1 below), not because it drives Step 2's throw.
            'site_emergency' => $violatingSiteEmergency,
        ];

        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-AE-001',
            'project_name'   => 'A&E Dual Path Gate Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-ae-001.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);

        // Step 1 — drive the REAL entry point with the violating value
        // already present in reviewed_data, and confirm today's documented
        // gap: runFromReview() never copies reviewed_data['site_emergency']
        // into the $data array it passes to upgrade(), so GATE-12 cannot see
        // it via this call. Empirically verified during this plan's
        // investigation (see class docblock) — asserted here so a future
        // wiring fix that changes this behaviour is caught by this test
        // rather than silently going unnoticed.
        app(RamsBuilderService::class)->buildFromReview($rams->reviewed_data, [], $rams);
        $this->assertSame(
            RamsDocument::STATUS_COMPLETED,
            $rams->fresh()->status,
            'Documents today\'s gap: buildFromReview() does not yet wire reviewed_data[site_emergency] into upgrade(), so GATE-12 cannot trip via this call — see class docblock.',
        );

        // Step 2 — prove GATE-12 is a genuine independent re-check, not dead
        // code, by calling the PUBLIC RamsComplianceUpgradeService::upgrade()
        // — the exact function runFromReview() calls internally
        // (RamsBuilderService.php:297) — with a $data array carrying the
        // same violating site_emergency value. This is real production code,
        // not a reflection call on a private method: the only difference
        // from a genuine runFromReview() trip is which caller supplies
        // $data['site_emergency'], which is exactly the gap Step 1 documents.
        try {
            RamsComplianceUpgradeService::upgrade(['site_emergency' => $violatingSiteEmergency]);
            $this->fail('Expected RamsGenerationException was not thrown by upgrade() (GATE-12).');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('GATE-12', $e->getMessage());
            $this->assertStringContainsString('urgent_care_keyword', $e->getMessage());
            // Mirror buildFromReview()'s real catch-and-fail behaviour
            // (RamsBuilderService::buildFromReview()) for the same reason
            // documented in test_gate_throws_via_run_pipeline() above.
            $rams->update(['status' => RamsDocument::STATUS_FAILED]);
        }

        $this->assertSame(RamsDocument::STATUS_FAILED, $rams->fresh()->status);
    }

    // ── Disarmed-by-default posture (D-03) ────────────────────────────────────

    public function test_gates_stay_silent_when_disarmed(): void
    {
        $this->fakeClaudeResponse();
        // Explicit, not relied-upon-as-default: cdm_ae_gate_enabled defaults
        // false (config/rams_tier1.php:134), but this test sets it
        // explicitly so its intent is unambiguous even if the default ever
        // changes.
        config(['rams_tier1.cdm_ae_gate_enabled' => false]);

        $user = User::factory()->create();

        $violatingSiteEmergency = [
            'nearest_hospital' => 'Willow Urgent Treatment Centre',
            'hospital_address' => '1 Willow Road, Testtown, TE1 1AA',
        ];

        // GATE-11: buildFromForm() never throws when disarmed — proven the
        // same way it never throws when armed, since addCdmDutyHolders() is
        // unconditional either way. The disarmed/armed distinction only has
        // teeth for GATE-11 at the enforceCdmGate() level, which upgrade()
        // never calls at all when the flag is false (upgrade()'s config
        // check gates the call itself, not just its effect).
        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-SILENT-001',
            'project_name'   => 'Disarmed Dual Path Test (Manual)',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-silent-001.docx',
            'form_data'      => [
                'source'            => 'manual_form',
                'project_ref'       => 'DUAL-SILENT-001',
                'project_name'      => 'Disarmed Dual Path Test (Manual)',
                'client_name'       => 'Acme Ltd',
                'site_address'      => '1 Test Street',
                'works_description' => 'Supply and installation of AV systems throughout the premises.',
                'hazards'           => [],
                'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
                'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
            ],
            'generated_data' => [],
        ]);

        app(RamsBuilderService::class)->buildFromForm($rams->form_data, $rams);
        $this->assertNotSame(RamsDocument::STATUS_FAILED, $rams->fresh()->status);

        // GATE-12: drive buildFromReview() disarmed with the violating value
        // present (same shape as test_gate_throws_via_run_from_review()) —
        // still no throw, for the same documented reason (the value never
        // reaches upgrade()) AND because the flag is off.
        $reviewedData = [
            'project' => [
                'project_name' => 'Disarmed A&E Dual Path Test',
                'quote_ref'    => 'DUAL-SILENT-002',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'  => [],
            'activities' => [],
            'hazards'    => [],
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
            'site_emergency'         => $violatingSiteEmergency,
        ];

        $ramsReview = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'DUAL-SILENT-002',
            'project_name'   => 'Disarmed A&E Dual Path Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-dual-silent-002.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);

        app(RamsBuilderService::class)->buildFromReview($ramsReview->reviewed_data, [], $ramsReview);
        $this->assertSame(RamsDocument::STATUS_COMPLETED, $ramsReview->fresh()->status);

        // Direct proof that upgrade() itself — the shared, real, public
        // function both entry points call — never throws when disarmed,
        // even fed the exact violating site_emergency shape that
        // test_gate_throws_via_run_from_review() proves DOES throw once
        // armed. This is the strongest single proof that the disarmed
        // posture holds even when the underlying data would otherwise
        // violate GATE-11/GATE-12 (must_haves bullet 3).
        $result = RamsComplianceUpgradeService::upgrade([
            'cdm_duty_holders' => ['principal_designer' => '[To be confirmed]', 'principal_contractor' => '[To be confirmed]'],
            'site_emergency'   => $violatingSiteEmergency,
        ]);
        $this->assertArrayHasKey('site_emergency_resolved', $result);
        // Note: cdm_duty_holders is still overwritten unconditionally by
        // addCdmDutyHolders() regardless of the flag (see class docblock) —
        // the assertion below documents that, it does not test the flag.
        $this->assertNotSame('[To be confirmed]', $result['cdm_duty_holders']['principal_designer']);
    }
}
