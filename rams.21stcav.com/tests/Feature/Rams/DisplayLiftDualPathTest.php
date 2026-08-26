<?php

namespace Tests\Feature\Rams;

use App\Exceptions\RamsGenerationException;
use App\Jobs\BuildRamsDocumentJob;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Phase 27 Plan 03 (GATE-09) — proves the gate fires/passes IDENTICALLY via
 * both real generation entry points: RamsBuilderService::runFromReview()
 * (:284) and ::runPipeline() (:881, via buildFromForm()).
 *
 * ── Why the "violating" tests mock DisplayLiftPolicy ─────────────────────
 *
 * DisplayLiftPolicy::forSize() and ::violatesPolicy() are independent
 * implementations that share only their numeric band constants (D-03) — by
 * construction, forSize()'s output can NEVER disagree with violatesPolicy()
 * for the same inputs (verified exhaustively while implementing this plan:
 * every branch of forSize() returns a (min_persons, inches) pair that
 * violatesPolicy() necessarily accepts). This means
 * RamsComplianceUpgradeService::deriveMaterialHandling() — the SOLE producer
 * of `material_handling_derived.items`, always resolving team size via
 * `DisplayLiftPolicy::forSize()` — can never emit an item that trips the
 * gate through ordinary keyword-scanned business data. This is a deliberate,
 * positive correctness property, not a test gap: it is exactly D-03's single-
 * source-of-truth design working as intended.
 *
 * Proving the gate genuinely FIRES therefore requires the violating
 * (min_persons, inches) pair to originate somewhere other than forSize()'s
 * own output. Short of temporarily editing DisplayLiftPolicy.php itself
 * (out of this plan's scope — RamsComplianceUpgradeService.php and
 * config/rams_tier1.php are the only production files this plan touches),
 * the only way to reach that state through the REAL entry points is an
 * isolated-process `alias:` mock of DisplayLiftPolicy standing in for
 * forSize()/violatesPolicy() for the duration of one test method.
 * `#[RunInSeparateProcess]` is required because `HazardTemplateSeeder` (and
 * several other suites) reference `DisplayLiftPolicy` directly, so the real
 * class is normally already autoloaded by the time this test runs —
 * Mockery's alias mock can only intercept a class name that has not yet
 * been loaded in its process. Empirically confirmed: seeding
 * `HazardTemplateSeeder` BEFORE registering the alias mock breaks the mock
 * ("class already exists") because the seeder's own `standardHazards()`
 * calls `DisplayLiftPolicy::genericBandSummary()` / `::wallMountRemovalStatement()` —
 * so the mock is always registered first, then the seeder runs against it.
 *
 * The two conforming-fixture tests below use the REAL, unmocked
 * DisplayLiftPolicy — only the two violating-fixture tests use the
 * isolated-process mock.
 *
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceDisplayLiftGate()
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-03-PLAN.md
 */
class DisplayLiftDualPathTest extends TestCase
{
    use RefreshDatabase;

    /** Absolute paths of DOCX files written during the test run (cleaned up in tearDown). */
    private array $generatedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakeClaudeResponse(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['phases' => [
                ['title' => 'Phase 1: Pre-works', 'steps' => ['Site induction', 'PPE check']],
            ]])]],
            'stop_reason' => 'end_turn',
        ], 200)]);
    }

    private function baseReviewedData(array $overrides = []): array
    {
        return array_merge([
            'project' => [
                'project_name' => 'Display Lift Dual Path Test',
                'quote_ref'    => 'DLD-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'              => [],
            'activities'             => [],
            'hazards'                => [],
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
        ], $overrides);
    }

    private function makeReviewedRams(User $user, array $reviewedData, string $ref): RamsDocument
    {
        return RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => $ref,
            'project_name'   => 'Display Lift Dual Path Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-' . $ref . '.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);
    }

    private function makeManualFormRams(array $formDataOverrides, string $ref): RamsDocument
    {
        return RamsDocument::create([
            'user_id'        => User::factory()->create()->id,
            'project_ref'    => $ref,
            'project_name'   => 'Display Lift Dual Path Test (Manual)',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-' . $ref . '.docx',
            'form_data'      => array_merge([
                'source'            => 'manual_form',
                'project_ref'       => $ref,
                'project_name'      => 'Display Lift Dual Path Test (Manual)',
                'client_name'       => 'Acme Ltd',
                'site_address'      => '1 Test Street',
                'works_description' => 'Supply and installation of AV systems throughout the premises.',
                'hazards'           => ['Electrocution', 'Manual Handling'],
                'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
                'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
            ], $formDataOverrides),
            'generated_data' => [],
        ]);
    }

    /** Runs the SAME job the "Generate RAMS" / regenerate button dispatches. */
    private function regenerate(RamsDocument $rams): RamsDocument
    {
        (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));

        $fresh = $rams->fresh();

        $candidate = $this->writtenDocxPath($fresh);
        if ($candidate !== null) {
            $this->generatedFiles[] = $candidate;
        }

        return $fresh;
    }

    /**
     * The real renderer (DocxBuilderService) writes new artifacts under the
     * unified `documents` disk (H-07) via DocumentArtifactStorage, not the
     * legacy storage/app/rams/ root — resolve through the same single
     * source of truth every reader uses.
     */
    private function writtenDocxPath(RamsDocument $record): ?string
    {
        return app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_RAMS, (string) $record->filename);
    }

    private function mockDisplayLiftPolicyAlwaysViolates(): void
    {
        \Mockery::mock('alias:App\Services\Rams\DisplayLiftPolicy')
            ->shouldReceive('forSize')->andReturn(['min_persons' => 2, 'sentence' => 'Team lift — stub sentence.'])
            ->shouldReceive('violatesPolicy')->andReturn(true)
            ->shouldReceive('wallMountRemovalStatement')->andReturn('Stub wall-mount removal statement.')
            ->shouldReceive('genericBandSummary')->andReturn('Stub generic band summary.');
    }

    // ── Conforming fixtures (REAL DisplayLiftPolicy, no mock) ────────────────

    public function test_conforming_display_item_generates_successfully_via_run_from_review(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        $rams = $this->makeReviewedRams($user, $this->baseReviewedData([
            // 32" is well under the 55" single-operative ceiling — RULE-02
            // compliant, must not trip GATE-09.
            'new_install_items' => [
                ['item_name' => '32 inch display', 'qty' => 1],
            ],
        ]), 'DLD-CONFORM-RFR');

        $rebuilt = $this->regenerate($rams);

        $this->assertNotSame(
            RamsDocument::STATUS_FAILED,
            $rebuilt->status,
            'Conforming 1-operative-under-55" display must not fail generation: ' . ($rebuilt->error_message ?? ''),
        );
        $this->assertNotNull($this->writtenDocxPath($rebuilt), 'Expected DOCX artifact was not written via DocxBuilderService::build().');

        $items = (array) ($rebuilt->generated_data['material_handling_derived']['items'] ?? []);
        $this->assertNotEmpty($items, 'The 32" display must have been detected as a manual-handling item.');
        $this->assertSame(1, $items[0]['min_persons'] ?? null);
    }

    public function test_conforming_display_item_generates_successfully_via_run_pipeline(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $rams = $this->makeManualFormRams([
            'new_install_items' => [
                ['item_name' => '32 inch display', 'qty' => 1],
            ],
        ], 'DLD-CONFORM-PIPE');

        $rebuilt = $this->regenerate($rams);

        $this->assertNotSame(
            RamsDocument::STATUS_FAILED,
            $rebuilt->status,
            'Conforming 1-operative-under-55" display must not fail generation: ' . ($rebuilt->error_message ?? ''),
        );
        $this->assertNotNull($this->writtenDocxPath($rebuilt), 'Expected DOCX artifact was not written via DocxBuilderService::build().');

        $items = (array) ($rebuilt->generated_data['material_handling_derived']['items'] ?? []);
        $this->assertNotEmpty($items, 'The 32" display must have been detected as a manual-handling item.');
        $this->assertSame(1, $items[0]['min_persons'] ?? null);
    }

    // ── Violating fixtures (isolated-process alias mock of DisplayLiftPolicy) ─
    //
    // Plan 27-06 note: these 2 tests cover the DERIVED-items branch only
    // (material_handling_derived.items, produced by deriveMaterialHandling()
    // via DisplayLiftPolicy::forSize()) — which remains conformant-by-
    // construction and therefore still needs this mock to force a violation.
    // The engineer-typed branch (material_handling.large_items) this mock
    // does NOT cover is proven unmocked, on real production-shaped data, by
    // test_gate_fires_on_engineer_row_via_run_from_review/_run_pipeline below
    // — THAT is Plan 27-06's actual coverage-gap closure.

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_violating_display_item_fails_generation_via_run_from_review(): void
    {
        $this->mockDisplayLiftPolicyAlwaysViolates();

        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        $rams = $this->makeReviewedRams($user, $this->baseReviewedData([
            'new_install_items' => [
                ['item_name' => '98 inch display', 'qty' => 1],
            ],
        ]), 'DLD-VIOLATE-RFR');

        try {
            (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));
            $this->fail('Expected RamsGenerationException was not thrown via runFromReview().');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('display-lift house rules', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringContainsString('display-lift house rules', $fresh->error_message);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_violating_display_item_fails_generation_via_run_pipeline(): void
    {
        $this->mockDisplayLiftPolicyAlwaysViolates();

        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $rams = $this->makeManualFormRams([
            'new_install_items' => [
                ['item_name' => '98 inch display', 'qty' => 1],
            ],
        ], 'DLD-VIOLATE-PIPE');

        try {
            (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));
            $this->fail('Expected RamsGenerationException was not thrown via runPipeline().');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('display-lift house rules', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringContainsString('display-lift house rules', $fresh->error_message);
    }

    // ── Plan 27-06 — engineer-typed rows (REAL DisplayLiftPolicy, no mock) ──
    //
    // This is the actual coverage-gap closure: material_handling_derived
    // items can never trip the gate (see the class docblock above), but
    // material_handling.large_items is free-text an engineer types on the
    // RAMS review screen — no DisplayLiftPolicy::forSize() involved at all —
    // so a genuinely violating (min_persons, inches) pair reaches
    // enforceDisplayLiftGate() through ordinary business data. No mock of
    // any kind is used in either test below.

    public function test_gate_fires_on_engineer_row_via_run_from_review(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $user = User::factory()->create();
        $rams = $this->makeReviewedRams($user, $this->baseReviewedData([
            'material_handling' => [
                'has_large_items' => true,
                'large_items'     => [
                    ['item' => 'Samsung 98" display', 'handling_method' => 'Team lift — minimum 4 persons', 'weight_kg' => ''],
                ],
                'handling_notes' => '',
            ],
        ]), 'DLD-ENG-RFR');

        try {
            (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));
            $this->fail('Expected RamsGenerationException was not thrown via runFromReview() for an engineer-typed row.');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('Samsung 98" display', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringContainsString('Samsung 98" display', $fresh->error_message);
    }

    public function test_gate_fires_on_engineer_row_via_run_pipeline(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->fakeClaudeResponse();

        $rams = $this->makeManualFormRams([
            'material_handling' => [
                'has_large_items' => true,
                'large_items'     => [
                    ['item' => 'Samsung 98" display', 'handling_method' => 'Team lift — minimum 4 persons', 'weight_kg' => ''],
                ],
                'handling_notes' => '',
            ],
        ], 'DLD-ENG-PIPE');

        try {
            (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));
            $this->fail('Expected RamsGenerationException was not thrown via runPipeline() for an engineer-typed row.');
        } catch (RamsGenerationException $e) {
            $this->assertStringContainsString('Samsung 98" display', $e->getMessage());
        }

        $fresh = $rams->fresh();
        $this->assertSame(RamsDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringContainsString('Samsung 98" display', $fresh->error_message);
    }
}
