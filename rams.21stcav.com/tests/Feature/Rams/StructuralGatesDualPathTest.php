<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 30 Plan 02 — non-vacuity proof for the `client_responsibilities_expanded`
 * and `areas_for_gate` mirrors added immediately before every
 * {@see RamsComplianceUpgradeService::upgrade()} call. Without these mirrors,
 * GATE-01's client-responsibility half and GATE-02 (both shipped in 30-01/30-03+)
 * would report clean on every real document — a gate that cannot see its
 * subject is worse than no gate at all (30-RESEARCH.md Finding 1).
 *
 * ── Which of the six `upgrade()` call sites this file proves live ──────────
 *
 *   1. `RamsBuilderService::runFromReview()` (`:297`)      — LIVE, this request.
 *   2. `RamsBuilderService::runPipeline()`   (`:942`)      — LIVE, this request
 *      (client_responsibilities_expanded always present, defaulting to `[]`;
 *      areas_for_gate present-and-non-empty ONLY when `$record->reviewed_data
 *      ['room_overviews']` was already populated before the build — the
 *      quote-extracted case. On a form-only initial build no quote was
 *      extracted, so `reviewed_data` is empty and `areas_for_gate === []` is
 *      the CORRECT answer, not a bug — see `test_run_pipeline_form_only_...`.)
 *   3. `RamsController::updateAndDownload()` (`:598-611`)  — LIVE, this request
 *      (the path an engineer actually uses to Save Review).
 *   4. `RamsController` DOCX rebuild-on-download (`:701`)  — inherits the
 *      mirrors ONLY BY PERSISTENCE: it passes `$rams->generated_data`
 *      verbatim, so the mirrors are only present if THIS document was last
 *      written (Save Review / a build) after Phase 30 shipped.
 *   5. `RamsController::downloadPdf()` (`:857`)            — same
 *      inherit-by-persistence property as site 4; proved explicitly below
 *      (`test_download_pdf_site_inherits_mirrors_only_by_persistence`).
 *   6. `RamsRefreshComplianceCommand` (`:185`)              — same
 *      inherit-by-persistence property as sites 4/5; not separately driven
 *      here (out of this file's HTTP/builder scope), but the property is
 *      identical: it also passes persisted `generated_data` verbatim.
 *
 * ── The known coshh_baseline asymmetry (Finding 1, unrelated to this file's
 *    mirrors but recorded per house convention) ─────────────────────────────
 *
 *   `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` — which sets
 *   `$data['coshh_baseline']` — runs AFTER `upgrade()` on both builder paths
 *   (`RamsBuilderService.php:297` then `:302`; `:942` then `:947`), so
 *   `coshh_baseline` is absent from the array `upgrade()` sees on sites 1-2
 *   and present on sites 3-6 (persisted from a previous build). This file's
 *   own mirrors do not share that asymmetry — both `client_responsibilities_
 *   expanded` and `areas_for_gate` are written immediately before `upgrade()`
 *   on ALL THREE real generation entry points (sites 1-3), which is exactly
 *   what closes 30-RESEARCH.md Finding 1.
 *
 * ── The mirror-liveness / inherit-by-persistence subtlety (the phase's most
 *    dangerous property) ─────────────────────────────────────────────────────
 *
 *   Sites 4, 5 and 6 do NOT run the mirror logic themselves — they pass
 *   `$rams->generated_data` straight into `upgrade()`. So on a LEGACY
 *   document (last written before Phase 30 shipped, or written by a code
 *   path that predates this plan), `generated_data` carries neither key,
 *   `areas_for_gate` resolves to `[]`, and GATE-02 reports clean even though
 *   the document may have areas with no method step. Likewise a legacy
 *   document whose only asbestos client-responsibility lives in
 *   `client_responsibilities_expanded` makes GATE-01's client-responsibility
 *   half blind — a FALSE POSITIVE (reports clean when it should not) that,
 *   if GATE-01 later throws on the hazard-side match alone, surfaces as an
 *   unexplained rejection. The remedy is operational, not code: Plan 30-05's
 *   arming runbook requires a corpus regeneration (e.g. via
 *   `php artisan rams:refresh-compliance`, which itself is site 6 and would
 *   populate the mirrors going forward) BEFORE `RAMS_STRUCTURAL_GATES` is
 *   flipped. `test_download_pdf_site_inherits_mirrors_only_by_persistence()`
 *   proves this property explicitly on site 5.
 *
 * ── Non-vacuity proof, actually run (2026-09-13) ────────────────────────────
 *
 *   Procedure per mirror site, mirroring `CdmEmergencyGateTest.php:18-45`'s
 *   house convention:
 *     1. Note the test(s) this site's mirror is proven by.
 *     2. Delete that site's `areas_for_gate` mirror line (or comment it out).
 *     3. Run `php artisan test --filter=StructuralGatesDualPathTest`.
 *     4. Confirm the named test FAILS (not errors for an unrelated reason).
 *     5. Restore the deleted line via `git checkout -- <file>` (or manual
 *        re-type) and confirm `git diff` is empty for that file.
 *
 *   Results, run via PowerShell/Herd (per CLAUDE.md — `php` is not on the
 *   Bash tool's PATH and a Bash `php ... | tail` exits 0 having run nothing):
 *
 *     Site 1 (RamsController.php `areas_for_gate` mirror, `:610-612`):
 *       deleted → `test_client_responsibilities_and_areas_reach_upgrade_via_save_review`
 *       FAILED (assertion on non-empty `areas_for_gate` in persisted
 *       generated_data failed, actual `[]`). Restored → `git diff` for the
 *       file empty, full re-run green.
 *
 *     Site 2 (RamsBuilderService.php `runFromReview()` `areas_for_gate`
 *       mirror, `:296-298`): deleted →
 *       `test_client_responsibilities_and_areas_reach_upgrade_via_run_from_review`
 *       FAILED (same non-empty assertion failed). Restored → `git diff`
 *       empty, full re-run green.
 *
 *     Site 3 (RamsBuilderService.php `runPipeline()` `areas_for_gate`
 *       mirror, `:891-893`): deleted →
 *       `test_run_pipeline_areas_for_gate_nonempty_when_quote_extracted`
 *       FAILED (assertion on non-empty `areas_for_gate` failed, actual `[]`).
 *       Restored → `git diff` empty, full re-run green (774+ Rams suite also
 *       re-confirmed green after restoration).
 *
 * @see App\Http\Controllers\RamsController::updateAndDownload()
 * @see App\Services\RamsBuilderService::runFromReview()
 * @see App\Services\RamsBuilderService::runPipeline()
 * @see App\Services\Rams\StructuralGateVocabulary::flattenAreas()
 * @see App\Services\Rams\StructuralGateVocabulary::flattenClientResponsibilities()
 * @see tests\Feature\Rams\CdmEmergencyDualPathGateTest.php
 * @see tests\Feature\Rams\DisplayLiftSaveReviewGateTest.php
 * @see .planning/phases/30-structural-validation-gates/30-02-PLAN.md
 */
class StructuralGatesDualPathTest extends TestCase
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

    private function makeRams(User $user, array $overrides = []): RamsDocument
    {
        return RamsDocument::create(array_merge([
            'user_id'        => $user->id,
            'project_ref'    => 'SGD-001',
            'project_name'   => 'Structural Gates Dual Path Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => [],
            'generated_data' => [
                'project' => [
                    'name'         => 'Structural Gates Dual Path Test',
                    'ref'          => 'SGD-001',
                    'client'       => 'Acme Ltd',
                    'site_address' => '1 Test Street',
                ],
            ],
            'reviewed_data' => [],
            'status'        => RamsDocument::STATUS_FOR_REVIEW,
            'filename'      => null,
        ], $overrides));
    }

    private function populatedRoomOverviews(): array
    {
        return [
            ['room' => 'Main Boardroom', 'overview' => 'Primary meeting space.', 'works_summary' => '- Install display'],
            ['room' => 'AV Rack Room', 'overview' => 'Equipment rack location.', 'works_summary' => '- Install rack'],
        ];
    }

    private function populatedClientResponsibilitiesExpanded(): array
    {
        return [
            'network_readiness' => ['required' => true, 'notes' => 'Client must confirm network readiness before install.'],
            'licences'          => ['required' => false, 'notes' => ''],
            'access'            => ['required' => false, 'notes' => ''],
            'power_validation'  => ['required' => false, 'notes' => ''],
            'additional'        => [
                ['item' => 'Review the asbestos register', 'notes' => 'Site pre-2000 construction.'],
            ],
        ];
    }

    // ── Site 3: RamsController::updateAndDownload() (Save Review) ──────────

    public function test_client_responsibilities_and_areas_reach_upgrade_via_save_review(): void
    {
        $user = User::factory()->create();
        // Save Review's controller action reads $rams->reviewed_data and only
        // ADDS new sub-keys onto it — it never receives room_overviews via
        // the POST payload (that is written exclusively by the project
        // package review screens). Pre-seed it here so it is present on the
        // reviewed_data the controller reads at request time, exactly as it
        // would be for a document that already went through project package
        // review before this Save Review request.
        $rams = $this->makeRams($user, [
            'reviewed_data' => [
                'room_overviews' => $this->populatedRoomOverviews(),
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), [
                'project_name'  => 'Structural Gates Dual Path Test',
                'project_ref'   => 'SGD-001',
                'client_name'   => 'Acme Ltd',
                'site_address'  => '1 Test Street',
                'client_resp_network_readiness_required' => true,
                'client_resp_network_readiness_notes'    => 'Client must confirm network readiness before install.',
                'client_resp_additional' => [
                    ['item' => 'Review the asbestos register', 'notes' => 'Site pre-2000 construction.'],
                ],
            ]);

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();

        $expanded = $rams->generated_data['client_responsibilities_expanded'] ?? null;
        $this->assertIsArray($expanded);
        $this->assertNotSame([], $expanded);
        $this->assertTrue($expanded['network_readiness']['required'] ?? false);

        $areas = $rams->generated_data['areas_for_gate'] ?? null;
        $this->assertSame(['Main Boardroom', 'AV Rack Room'], $areas);
    }

    // ── Site 1: RamsBuilderService::runFromReview() ─────────────────────────

    public function test_client_responsibilities_and_areas_reach_upgrade_via_run_from_review(): void
    {
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        $reviewedData = [
            'project' => [
                'project_name' => 'Structural Gates Dual Path Test (Review)',
                'quote_ref'    => 'SGD-002',
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
            'room_overviews'         => $this->populatedRoomOverviews(),
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
            'client_responsibilities_expanded' => $this->populatedClientResponsibilitiesExpanded(),
        ];

        $rams = RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'SGD-002',
            'project_name'   => 'Structural Gates Dual Path Test (Review)',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-sgd-002.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);

        app(RamsBuilderService::class)->buildFromReview($rams->reviewed_data, [], $rams);

        $rams->refresh();

        $expanded = $rams->generated_data['client_responsibilities_expanded'] ?? null;
        $this->assertIsArray($expanded);
        $this->assertNotSame([], $expanded);

        $areas = $rams->generated_data['areas_for_gate'] ?? null;
        $this->assertSame(['Main Boardroom', 'AV Rack Room'], $areas);
    }

    // ── Site 2: RamsBuilderService::runPipeline() (quote-extracted case) ────

    public function test_run_pipeline_areas_for_gate_nonempty_when_quote_extracted(): void
    {
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        $rams = $this->makeRams($user, [
            // Simulates ExtractQuoteJob.php:257 having already written
            // room_overviews into reviewed_data BEFORE the pipeline build
            // runs — the quote-extracted case named in the plan interfaces.
            'reviewed_data' => [
                'room_overviews' => $this->populatedRoomOverviews(),
                'client_responsibilities_expanded' => $this->populatedClientResponsibilitiesExpanded(),
            ],
        ]);

        app(RamsBuilderService::class)->buildFromForm([
            'client_name'       => 'Acme Ltd',
            'site_address'      => '1 Test Street',
            'project_ref'       => 'SGD-001',
            'project_name'      => 'Structural Gates Dual Path Test',
            'works_description' => 'Supply and installation of AV systems throughout the premises.',
            'hazards'           => [],
            'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
            'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
        ], $rams);

        $rams->refresh();

        $expanded = $rams->generated_data['client_responsibilities_expanded'] ?? null;
        $this->assertIsArray($expanded);
        $this->assertNotSame([], $expanded);

        $areas = $rams->generated_data['areas_for_gate'] ?? null;
        $this->assertSame(['Main Boardroom', 'AV Rack Room'], $areas);
    }

    // ── Site 2: RamsBuilderService::runPipeline() (form-only case) ──────────

    public function test_run_pipeline_form_only_yields_areas_for_gate_empty_and_no_error(): void
    {
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        // Form-only initial build: no quote was ever extracted, so
        // reviewed_data is legitimately empty. areas_for_gate === [] is the
        // CORRECT answer here (30-02-PLAN.md interfaces note), not a bug —
        // erroring or requiring non-empty on this path would reject every
        // manual/form-only RAMS.
        $rams = $this->makeRams($user, ['reviewed_data' => []]);

        app(RamsBuilderService::class)->buildFromForm([
            'client_name'       => 'Acme Ltd',
            'site_address'      => '1 Test Street',
            'project_ref'       => 'SGD-001',
            'project_name'      => 'Structural Gates Dual Path Test',
            'works_description' => 'Supply and installation of AV systems throughout the premises.',
            'hazards'           => [],
            'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
            'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
        ], $rams);

        $rams->refresh();

        $this->assertNotSame(RamsDocument::STATUS_FAILED, $rams->status);
        // Key PRESENCE asserted in both cases; non-emptiness only in the
        // quote-extracted case (test above).
        $this->assertArrayHasKey('client_responsibilities_expanded', $rams->generated_data);
        $this->assertArrayHasKey('areas_for_gate', $rams->generated_data);
        $this->assertSame([], $rams->generated_data['areas_for_gate']);
    }

    // ── Zero-area document stays legal end-to-end (Save Review path) ───────

    public function test_zero_room_overviews_yields_areas_for_gate_empty_and_no_error_on_save_review(): void
    {
        $user = User::factory()->create();
        // No room_overviews at all — the manual/form-only RAMS path must
        // stay legal; a gate that errors on legitimately-empty data would
        // reject correct output (ROADMAP criterion 4).
        $rams = $this->makeRams($user, ['reviewed_data' => []]);

        $response = $this->actingAs($user)
            ->from(route('rams.review', $rams))
            ->post(route('rams.update-and-download', $rams), [
                'project_name'  => 'Structural Gates Dual Path Test',
                'project_ref'   => 'SGD-001',
                'client_name'   => 'Acme Ltd',
                'site_address'  => '1 Test Street',
            ]);

        $response->assertRedirect(route('rams.review', $rams));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $rams->refresh();
        $this->assertArrayHasKey('areas_for_gate', $rams->generated_data);
        $this->assertSame([], $rams->generated_data['areas_for_gate']);
        // Note: RamsController::updateAndDownload() always CONSTRUCTS the
        // four fixed client_responsibilities_expanded buckets from the
        // request (RamsController.php:522-536), even when none of the
        // client_resp_* fields were submitted — so the mirrored value on
        // this path is never a bare `[]`, only "all buckets not required,
        // no free text, no additional rows". Assert that content shape
        // rather than array identity with `[]`.
        $expanded = $rams->generated_data['client_responsibilities_expanded'] ?? null;
        $this->assertIsArray($expanded);
        foreach (['network_readiness', 'licences', 'access', 'power_validation'] as $bucket) {
            $this->assertFalse($expanded[$bucket]['required'] ?? true);
            $this->assertSame('', $expanded[$bucket]['notes'] ?? null);
        }
        $this->assertSame([], $expanded['additional'] ?? null);
    }

    // ── Site 5: downloadPdf() — inherit-by-persistence, not by re-mirroring ─

    public function test_download_pdf_site_inherits_mirrors_only_by_persistence(): void
    {
        $user = User::factory()->create();

        // A LEGACY document: generated_data was persisted by a pre-Phase-30
        // code path (or simply never went through a mirror site), so it
        // carries neither client_responsibilities_expanded nor
        // areas_for_gate — even though reviewed_data DOES carry a populated
        // room_overviews and client_responsibilities_expanded. downloadPdf()
        // (site 5, RamsController.php:857) passes $rams->generated_data
        // straight into upgrade() with no re-mirroring step of its own, so
        // the gate is blind on this document until the next Save Review or
        // build re-runs a mirror site.
        $legacyGeneratedData = [
            'project' => [
                'name'         => 'Legacy Document',
                'ref'          => 'SGD-LEGACY',
                'client'       => 'Acme Ltd',
                'site_address' => '1 Test Street',
            ],
            // Deliberately absent: client_responsibilities_expanded, areas_for_gate.
        ];

        $rams = $this->makeRams($user, [
            'project_ref'    => 'SGD-LEGACY',
            'generated_data' => $legacyGeneratedData,
            'reviewed_data'  => [
                'room_overviews' => $this->populatedRoomOverviews(),
                'client_responsibilities_expanded' => $this->populatedClientResponsibilitiesExpanded(),
            ],
        ]);

        // This is exactly what downloadPdf() does at RamsController.php:875-877.
        $upgraded = RamsComplianceUpgradeService::upgrade($rams->generated_data);

        $this->assertArrayNotHasKey('client_responsibilities_expanded', $legacyGeneratedData);
        $this->assertSame([], $upgraded['areas_for_gate'] ?? [], 'A legacy document with no mirror-written generated_data must not retroactively see reviewed_data through downloadPdf() — inherit-by-persistence, not by re-derivation.');
        $this->assertArrayNotHasKey(
            'client_responsibilities_expanded',
            $upgraded,
            'downloadPdf() never mirrors client_responsibilities_expanded itself — a legacy document stays blind until the next Save Review/build persists it.',
        );

        // Contrast: once the document HAS been through a mirror site (e.g.
        // Save Review) and its generated_data is persisted with the mirrors,
        // downloadPdf() correctly sees them on every subsequent call, because
        // it reads the persisted array verbatim.
        $rams->update(['generated_data' => array_merge($legacyGeneratedData, [
            'client_responsibilities_expanded' => $this->populatedClientResponsibilitiesExpanded(),
            'areas_for_gate' => ['Main Boardroom', 'AV Rack Room'],
        ])]);
        $rams->refresh();

        $upgradedAfterMirror = RamsComplianceUpgradeService::upgrade($rams->generated_data);
        $this->assertNotSame([], $upgradedAfterMirror['areas_for_gate'] ?? []);
        $this->assertNotSame([], $upgradedAfterMirror['client_responsibilities_expanded'] ?? []);
    }
}
