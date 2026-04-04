<?php

namespace Tests\Feature\Rams;

use App\Jobs\BuildRamsDocumentJob;
use App\Jobs\ExtractRamsDraftJob;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use App\Services\RamsExtractionDraftBuilderService;
use App\Services\QuoteTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the RAMS review workflow.
 *
 * Tests cover:
 *   - Phase A: extraction job saves extracted_data and sets awaiting_review
 *   - Review page loads with normalised data structure
 *   - Review update persists reviewed_data
 *   - Approve validates required fields
 *   - Approve sets approved_at, approved_by, status
 *   - Phase B generation job refuses without reviewed_data
 *   - Phase B generation job uses reviewed_data, not extracted_data
 */
class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeRecord(User $user, array $overrides = []): RamsDocument
    {
        return RamsDocument::create(array_merge([
            'user_id'        => $user->id,
            'project_ref'    => 'TEST-001',
            'project_name'   => 'Test Project',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '123 Test Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => ['stored_pdf_path' => '/tmp/test.pdf', 'source' => 'quote_upload'],
            'extracted_data' => null,
            'reviewed_data'  => null,
            'generated_data' => null,
            'filename'       => null,
            'status'         => RamsDocument::STATUS_UPLOADED,
        ], $overrides));
    }

    /** Minimal valid review payload matching canonical schema. */
    private function validReviewPayload(): array
    {
        return [
            'project' => [
                'project_name' => 'Test Project',
                'quote_ref'    => 'TEST-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '123 Test Street, London',
                'prepared_by'  => 'J. Smith',
            ],
            'equipment' => [
                ['quantity' => 2, 'name' => '55" Samsung Display'],
                ['quantity' => 1, 'name' => 'Logitech Rally Bar'],
            ],
            'activities' => [
                ['key' => 'display_installation', 'label' => 'Display & Screen Installation'],
                ['key' => 'video_conferencing',   'label' => 'Video Conferencing Installation'],
            ],
            'hazards' => [
                [
                    'activity_key'     => 'display_installation',
                    'hazard'           => 'Working at Height',
                    'risk'             => 'Medium',
                    'control_measures' => ['Use podium steps', 'Two-person team'],
                ],
            ],
            'ppe'                    => ['Safety Boots (steel toe cap)', 'Hi-Visibility Vest'],
            'access'                 => ['ladders' => true, 'tower' => false, 'scissor_lift' => false, 'out_of_hours' => false, 'live_environment' => false],
            'method_statement_notes' => 'Works during school hours only.',
            'meta'                   => ['parser_confidence' => 0.75, 'source' => 'extracted'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Extraction phase stores extracted_data and sets awaiting_review
    // ─────────────────────────────────────────────────────────────────────────

    public function test_extraction_phase_saves_extracted_data_and_sets_awaiting_review(): void
    {
        Storage::fake('local');

        $user   = $this->makeUser();
        $record = $this->makeRecord($user);

        // Create a fake PDF file so the job's file-exists guard passes.
        Storage::disk('local')->put('rams/uploads/test.pdf', '%PDF-1.4 fake content');
        $fakePath = Storage::disk('local')->path('rams/uploads/test.pdf');

        $record->update([
            'filename'  => $fakePath,
            'form_data' => ['stored_pdf_path' => $fakePath, 'source' => 'quote_upload'],
        ]);

        // Mock the text extractor to return deterministic text.
        $this->mock(QuoteTextExtractorService::class, function ($mock) {
            $mock->shouldReceive('extract')
                 ->once()
                 ->andReturn('Client: Acme Ltd Site: London Quote: TEST-001 2x 55" Samsung Display 1x Logitech Rally Bar mount bracket');
        });

        // Run the job synchronously.
        (new ExtractRamsDraftJob($record->id))->handle(
            app(QuoteTextExtractorService::class),
            app(RamsExtractionDraftBuilderService::class),
        );

        $record->refresh();

        $this->assertEquals(RamsDocument::STATUS_AWAITING_REVIEW, $record->status);
        $this->assertNotNull($record->extracted_data);
        $this->assertArrayHasKey('project',    $record->extracted_data);
        $this->assertArrayHasKey('equipment',  $record->extracted_data);
        $this->assertArrayHasKey('activities', $record->extracted_data);
        $this->assertArrayHasKey('hazards',    $record->extracted_data);
        $this->assertArrayHasKey('ppe',        $record->extracted_data);
        $this->assertArrayHasKey('access',     $record->extracted_data);
        $this->assertArrayHasKey('meta',       $record->extracted_data);
        $this->assertEquals('extracted', $record->extracted_data['meta']['source']);
    }

    public function test_extraction_phase_fails_safely_when_pdf_missing(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'form_data' => ['stored_pdf_path' => '/nonexistent/path/file.pdf'],
        ]);

        $job = new ExtractRamsDraftJob($record->id);

        // Calling fail() on a non-queued job throws; we catch it here.
        try {
            $job->handle(
                app(QuoteTextExtractorService::class),
                app(RamsExtractionDraftBuilderService::class),
            );
        } catch (\Throwable) {
            // Expected — the job calls $this->fail() which throws
        }

        $record->refresh();
        $this->assertEquals(RamsDocument::STATUS_FAILED, $record->status);
        $this->assertNull($record->extracted_data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Review page loads with normalised structure
    // ─────────────────────────────────────────────────────────────────────────

    public function test_review_page_loads_with_normalised_structure(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'extracted_data' => $this->validReviewPayload(),
        ]);

        $response = $this->actingAs($user)->get(route('rams.quote-review.show', $record));

        $response->assertStatus(200);
        $response->assertViewIs('rams.quote-review');
        $response->assertViewHas('rams');
        $response->assertViewHas('reviewPayload');

        $payload = $response->viewData('reviewPayload');

        // Verify top-level keys exist.
        $this->assertArrayHasKey('project',                $payload);
        $this->assertArrayHasKey('equipment',              $payload);
        $this->assertArrayHasKey('activities',             $payload);
        $this->assertArrayHasKey('hazards',                $payload);
        $this->assertArrayHasKey('ppe',                    $payload);
        $this->assertArrayHasKey('access',                 $payload);
        $this->assertArrayHasKey('method_statement_notes', $payload);
        $this->assertArrayHasKey('meta',                   $payload);

        // Verify project sub-keys.
        $this->assertArrayHasKey('project_name', $payload['project']);
        $this->assertArrayHasKey('quote_ref',    $payload['project']);
        $this->assertArrayHasKey('site_address', $payload['project']);

        // Verify access boolean keys.
        $this->assertArrayHasKey('ladders',          $payload['access']);
        $this->assertArrayHasKey('tower',            $payload['access']);
        $this->assertArrayHasKey('scissor_lift',     $payload['access']);
        $this->assertArrayHasKey('out_of_hours',     $payload['access']);
        $this->assertArrayHasKey('live_environment', $payload['access']);
    }

    public function test_review_page_prioritises_reviewed_data_over_extracted_data(): void
    {
        $user = $this->makeUser();

        $extracted = $this->validReviewPayload();
        $reviewed  = $this->validReviewPayload();
        $reviewed['project']['project_name'] = 'REVIEWED Project Name';

        $record = $this->makeRecord($user, [
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'extracted_data' => $extracted,
            'reviewed_data'  => $reviewed,
        ]);

        $response = $this->actingAs($user)->get(route('rams.quote-review.show', $record));

        $response->assertStatus(200);
        $payload = $response->viewData('reviewPayload');
        $this->assertEquals('REVIEWED Project Name', $payload['project']['project_name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Review update persists reviewed_data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_review_update_persists_reviewed_data(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'extracted_data' => $this->validReviewPayload(),
        ]);

        $postData = $this->buildFormPost($this->validReviewPayload());
        $postData['project']['project_name'] = 'Updated Project Name';

        $response = $this->actingAs($user)->post(
            route('rams.quote-review.update', $record),
            $postData,
        );

        $response->assertRedirect(route('rams.quote-review.show', $record));

        $record->refresh();
        $this->assertNotNull($record->reviewed_data);
        $this->assertEquals('Updated Project Name', $record->reviewed_data['project']['project_name']);
        // Status should remain awaiting_review — update does not change it.
        $this->assertEquals(RamsDocument::STATUS_AWAITING_REVIEW, $record->status);
    }

    public function test_review_update_blocked_when_already_completed(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($user)->post(
            route('rams.quote-review.update', $record),
            $this->buildFormPost($this->validReviewPayload()),
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Approve validates required fields
    // ─────────────────────────────────────────────────────────────────────────

    public function test_approve_requires_project_name(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);

        $payload                            = $this->buildFormPost($this->validReviewPayload());
        $payload['project']['project_name'] = '';

        $response = $this->actingAs($user)->post(route('rams.approve', $record), $payload);

        $response->assertSessionHasErrors('project.project_name');
    }

    public function test_approve_requires_at_least_one_equipment_row(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);

        $payload              = $this->buildFormPost($this->validReviewPayload());
        $payload['equipment'] = [];

        $response = $this->actingAs($user)->post(route('rams.approve', $record), $payload);

        $response->assertSessionHasErrors('equipment');
    }

    public function test_approve_requires_at_least_one_activity(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);

        $payload               = $this->buildFormPost($this->validReviewPayload());
        $payload['activities'] = [];

        $response = $this->actingAs($user)->post(route('rams.approve', $record), $payload);

        $response->assertSessionHasErrors('activities');
    }

    public function test_approve_requires_at_least_one_ppe_item(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);

        $payload        = $this->buildFormPost($this->validReviewPayload());
        $payload['ppe'] = [];

        $response = $this->actingAs($user)->post(route('rams.approve', $record), $payload);

        $response->assertSessionHasErrors('ppe');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Approve sets approved_at, approved_by, status, dispatches job
    // ─────────────────────────────────────────────────────────────────────────

    public function test_approve_sets_approved_metadata_and_dispatches_generation_job(): void
    {
        Bus::fake();

        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);

        $response = $this->actingAs($user)->post(
            route('rams.approve', $record),
            $this->buildFormPost($this->validReviewPayload()),
        );

        $response->assertRedirect(route('rams.index'));
        $response->assertSessionHas('success');

        $record->refresh();

        $this->assertEquals(RamsDocument::STATUS_APPROVED_FOR_GENERATION, $record->status);
        $this->assertNotNull($record->approved_at);
        $this->assertEquals($user->id, $record->approved_by);
        $this->assertNotNull($record->reviewed_data);
        $this->assertEquals('reviewed', $record->reviewed_data['meta']['source']);

        Bus::assertDispatched(BuildRamsDocumentJob::class, function ($job) use ($record) {
            return $job->ramsDocumentId === $record->id;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Generation job refuses to run without reviewed_data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generation_job_refuses_without_reviewed_data(): void
    {
        $user   = $this->makeUser();
        $record = $this->makeRecord($user, [
            'status'        => RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            'reviewed_data' => null,
        ]);

        $job = new BuildRamsDocumentJob($record->id);

        try {
            $job->handle(app(RamsBuilderService::class));
        } catch (\Throwable) {
            // Expected
        }

        $record->refresh();
        $this->assertEquals(RamsDocument::STATUS_FAILED, $record->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Generation job uses reviewed_data, not extracted_data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generation_job_uses_reviewed_data_not_extracted_data(): void
    {
        Queue::fake();

        $user = $this->makeUser();

        $extractedData         = $this->validReviewPayload();
        $extractedData['project']['project_name'] = 'EXTRACTED Name — should not appear';

        $reviewedData         = $this->validReviewPayload();
        $reviewedData['project']['project_name'] = 'REVIEWED Name — should be used';
        $reviewedData['meta']['source'] = 'reviewed';

        $record = $this->makeRecord($user, [
            'status'         => RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            'extracted_data' => $extractedData,
            'reviewed_data'  => $reviewedData,
        ]);

        // Mock the builder so we can verify it receives reviewed_data.
        $builderMock = $this->mock(RamsBuilderService::class);
        $builderMock->shouldReceive('buildFromReview')
                    ->once()
                    ->withArgs(function ($passedReviewData, $passedFormData, $passedRecord) use ($reviewedData, $record) {
                        // Verify that the project_name comes from reviewed_data, not extracted_data.
                        return $passedReviewData['project']['project_name'] === 'REVIEWED Name — should be used'
                            && $passedRecord->id === $record->id;
                    })
                    ->andReturn('/tmp/fake.docx');

        $job = new BuildRamsDocumentJob($record->id);
        $job->handle($builderMock);

        $record->refresh();
        $this->assertEquals(RamsDocument::STATUS_COMPLETED, $record->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a canonical review payload into a flat form POST array.
     * control_measures arrays are joined with newlines (as the textarea format expects).
     */
    private function buildFormPost(array $payload): array
    {
        $post = $payload;

        // Convert control_measures arrays to newline-separated strings.
        foreach ($post['hazards'] ?? [] as $i => $h) {
            if (is_array($h['control_measures'] ?? null)) {
                $post['hazards'][$i]['control_measures'] = implode("\n", $h['control_measures']);
            }
        }

        return $post;
    }
}
