<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 28 Plan 03 (research Q4 gap closure) — end-to-end proof that a
 * `reviewed_data['ppe']` array already containing the literal string
 * "Dust Mask (FFP2)" renders as "Dust Mask (FFP3)" on the next full
 * regeneration, via the REAL `RamsBuilderService::buildFromReview()` entry
 * point (mirrors what `BuildRamsDocumentJob::handle()` calls), not just at
 * the `PpeVocabularyFoldMap` unit level.
 *
 * AI call: `MethodStatementGeneratorService` makes the only AI call in this
 * pipeline stage — faked via `Http::fake()` (mirrors
 * `DisplayLiftDualPathTest::fakeClaudeResponse()`), so this test is
 * deterministic and makes no real network call.
 *
 * @see app/Services/Rams/PpeVocabularyFoldMap.php
 * @see app/Services/RamsBuilderService.php::buildFromReview()
 * @see tests/Feature/Rams/DisplayLiftDualPathTest.php (fixture/fake shape copied from here)
 */
class PpeFfp2RenderRegressionTest extends TestCase
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

    private function baseReviewedData(array $overrides = []): array
    {
        return array_merge([
            'project' => [
                'project_name' => 'PPE FFP2 Render Regression Test',
                'quote_ref'    => 'PPE-FFP2-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'              => [],
            'activities'             => [],
            'hazards'                => [],
            'ppe'                    => ['Dust Mask (FFP2)', 'Safety Boots (steel toe cap)'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
        ], $overrides);
    }

    private function makeRams(User $user, array $reviewedData): RamsDocument
    {
        return RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'PPE-FFP2-001',
            'project_name'   => 'PPE FFP2 Render Regression Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => [],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
            'filename'       => null,
        ]);
    }

    public function test_stored_ffp2_ppe_string_renders_as_ffp3_via_build_from_review(): void
    {
        $this->fakeClaudeResponse();

        $user   = User::factory()->create();
        $record = $this->makeRams($user, $this->baseReviewedData());

        app(RamsBuilderService::class)->buildFromReview($record->reviewed_data, [], $record);

        $ppe = $record->fresh()->generated_data['ppe'] ?? [];

        $this->assertContains('Dust Mask (FFP3)', $ppe);
        $this->assertNotContains('Dust Mask (FFP2)', $ppe);
    }
}
