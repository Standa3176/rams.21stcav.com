<?php

namespace Tests\Feature\Rams;

use App\Jobs\BuildRamsDocumentJob;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature smoke test: manual RAMS creation via POST /rams.
 *
 * Covers manual RAMS create flow:
 *   RamsFormRequest validation → RamsController::store()
 *     → queue dispatch (BuildRamsDocumentJob)
 *     → redirect to rams.review
 *
 * BuildRamsDocumentJob execution itself is covered separately in workflow tests.
 */
class ManualRamsCreationTest extends TestCase
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

    private function fakeClaudeResponse(array $phases): void
    {
        Http::fake(['*' => Http::response([
            'content'     => [['type' => 'text', 'text' => json_encode(['phases' => $phases])]],
            'stop_reason' => 'end_turn',
        ], 200)]);
    }

    private function validFormPayload(array $overrides = []): array
    {
        return array_merge([
            'project_ref'       => 'TEST-2025-001',
            'project_name'      => 'Feature Test Project',
            'client_name'       => 'Acme Corp',
            'site_address'      => '1 Feature Lane, London, EC1A 1AA',
            'works_description' => 'Supply and installation of AV systems throughout the premises.',
            'hazards'           => ['Electrocution', 'Manual Handling'],
            'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
            'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
        ], $overrides);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_store_dispatches_generation_job_instead_of_running_builder_inline(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        // Controller should queue the job and never call builder inline.
        $this->mock(RamsBuilderService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('buildFromForm');
        });

        $response = $this->actingAs($user)
            ->post(route('rams.store'), $this->validFormPayload([
                'project_ref' => 'QUEUE-STORE-001',
            ]));

        $record = RamsDocument::where('project_ref', 'QUEUE-STORE-001')->first();

        $this->assertNotNull($record, 'RamsDocument was not created.');
        $this->assertSame('manual_form', $record->form_data['source'] ?? null);
        $response->assertRedirectToRoute('rams.review', $record);

        Bus::assertDispatched(
            BuildRamsDocumentJob::class,
            fn (BuildRamsDocumentJob $job): bool => $job->ramsDocumentId === $record->id
        );
    }

    public function test_authenticated_user_can_create_rams_from_form(): void
    {
        $user = User::factory()->create();

        $this->fakeClaudeResponse([
            ['title' => 'Phase 1: Planning', 'steps' => ['Review RAMS', 'Conduct induction']],
            ['title' => 'Phase 2: Installation', 'steps' => ['Mount brackets', 'Hang screens']],
        ]);

        $response = $this->actingAs($user)
            ->post(route('rams.store'), $this->validFormPayload());

        // Grab the generated file path before assertions so we can clean it up
        $record = RamsDocument::latest()->first();
        if ($record && $record->filename && $record->filename !== 'pending-' . substr($record->filename, 8)) {
            $candidate = storage_path('app/rams/' . $record->filename);
            if (file_exists($candidate)) {
                $this->generatedFiles[] = $candidate;
            }
        }

        $response->assertRedirectToRoute('rams.review', $record);
        $response->assertSessionHas('success');
    }

    public function test_rams_document_record_is_persisted_in_database(): void
    {
        $user = User::factory()->create();

        $this->fakeClaudeResponse([
            ['title' => 'Phase 1: Pre-Works', 'steps' => ['Review RAMS', 'Site induction', 'PPE check']],
        ]);

        $this->actingAs($user)->post(route('rams.store'), $this->validFormPayload([
            'project_ref'  => 'DB-PERSIST-001',
            'project_name' => 'DB Persistence Test',
            'client_name'  => 'DB Test Client',
        ]));

        $this->assertDatabaseHas('rams_documents', [
            'user_id'      => $user->id,
            'project_ref'  => 'DB-PERSIST-001',
            'client_name'  => 'DB Test Client',
        ]);

        // Clean up generated file
        $record = RamsDocument::where('project_ref', 'DB-PERSIST-001')->first();
        if ($record) {
            $candidate = storage_path('app/rams/' . $record->filename);
            if (file_exists($candidate)) {
                $this->generatedFiles[] = $candidate;
            }
        }
    }

    public function test_rams_status_is_for_review_after_successful_generation(): void
    {
        $user = User::factory()->create();

        $this->fakeClaudeResponse([
            ['title' => 'Phase 1: Planning', 'steps' => ['Step one', 'Step two', 'Step three']],
        ]);

        $this->actingAs($user)->post(route('rams.store'), $this->validFormPayload([
            'project_ref' => 'STATUS-001',
        ]));

        $record = RamsDocument::where('project_ref', 'STATUS-001')->first();

        $this->assertNotNull($record, 'RamsDocument was not created.');
        $this->assertSame(RamsDocument::STATUS_FOR_REVIEW, $record->status);

        $candidate = storage_path('app/rams/' . $record->filename);
        if (file_exists($candidate)) {
            $this->generatedFiles[] = $candidate;
        }
    }

    public function test_pipeline_completes_with_ai_fallback_when_claude_returns_500(): void
    {
        $user = User::factory()->create();

        // AI unavailable — MethodStatementService should use static fallback
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $response = $this->actingAs($user)->post(route('rams.store'), $this->validFormPayload([
            'project_ref' => 'FALLBACK-001',
        ]));

        $record = RamsDocument::where('project_ref', 'FALLBACK-001')->first();

        $this->assertNotNull($record, 'RamsDocument not created when AI was unavailable.');
        $this->assertSame(RamsDocument::STATUS_FOR_REVIEW, $record->status);
        $response->assertRedirectToRoute('rams.review', $record);

        $candidate = storage_path('app/rams/' . $record->filename);
        if (file_exists($candidate)) {
            $this->generatedFiles[] = $candidate;
        }
    }

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $response = $this->post(route('rams.store'), $this->validFormPayload());

        $response->assertRedirectToRoute('login');
    }

    public function test_validation_fails_when_required_fields_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('rams.store'), [
            // Deliberately omitting required fields
            'project_ref' => 'INVALID-001',
        ]);

        $response->assertSessionHasErrors(['project_name', 'client_name', 'site_address', 'works_description']);
        $this->assertDatabaseMissing('rams_documents', ['project_ref' => 'INVALID-001']);
    }
}
