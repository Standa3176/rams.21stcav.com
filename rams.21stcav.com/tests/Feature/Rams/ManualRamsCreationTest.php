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

    /**
     * RENAMED AND RE-EXPECTED BY QUICK TASK 260928-dq2, BY NAME AND WITH THE
     * REASON — never deleted to make a red test green.
     *
     * WAS `test_rams_status_is_for_review_after_successful_generation()`,
     * asserting `STATUS_FOR_REVIEW`. That assertion PINNED A BUG.
     * `BuildRamsDocumentJob` set FOR_REVIEW on the manual-form path only, so a
     * manual-form RAMS could never reach `completed` however well the build
     * went, and the completion notification twelve lines below that branch —
     * gated on `status === STATUS_COMPLETED` — has never fired for this path
     * at all. The user saw the first half: *"once a rams is generate it
     * defaults to review rams eventhough they have been created"*.
     *
     * Review is NOT lost by completing it: `rams.store` still redirects to
     * `rams.review`, which IS the edit form, and
     * `test_successful_creation_redirects_to_review_page()` in this file still
     * proves that.
     */
    public function test_rams_status_is_completed_after_successful_generation(): void
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
        $this->assertSame(RamsDocument::STATUS_COMPLETED, $record->status);

        $candidate = storage_path('app/rams/' . $record->filename);
        if (file_exists($candidate)) {
            $this->generatedFiles[] = $candidate;
        }
    }

    /**
     * THE SIDE EFFECT OF THE STATUS FIX, NAMED AND ASSERTED (260928-dq2).
     *
     * A status fix that quietly starts sending email is a surprise, so the mail
     * is pinned rather than left to be discovered on live. The notification is
     * NOTF-01 / Phase 09, it is gated on `STATUS_COMPLETED`, and it is
     * idempotent through `completion_email_sent_at` — so this asserts BOTH the
     * mailable and the stamp that stops a retry sending it twice.
     */
    public function test_completing_a_manual_form_rams_sends_the_completion_notification(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        // The resolver falls back to the first admin when the document has no
        // project owner, and a manual-form RAMS has no project at all — so an
        // admin is what makes this path have a recipient.
        User::factory()->create(['role' => 'admin', 'email' => 'office@example.test']);

        $user = User::factory()->create();

        $this->fakeClaudeResponse([
            ['title' => 'Phase 1: Planning', 'steps' => ['Step one', 'Step two', 'Step three']],
        ]);

        $this->actingAs($user)->post(route('rams.store'), $this->validFormPayload([
            'project_ref' => 'NOTIFY-001',
        ]));

        $record = RamsDocument::where('project_ref', 'NOTIFY-001')->first();

        $this->assertNotNull($record, 'RamsDocument was not created.');
        $this->assertSame(RamsDocument::STATUS_COMPLETED, $record->status);

        // NOTE the second argument is Laravel's CALLBACK/address parameter, not
        // a failure message — so the reason is asserted separately below rather
        // than smuggled in here, where it would read as an email address.
        //
        // THE REASON: the completion notification is gated on STATUS_COMPLETED,
        // so while the manual-form path set FOR_REVIEW it sent nothing at all.
        // Fixing the status starts sending mail on a path that sent none, and
        // that consequence is pinned rather than discovered on live.
        //
        // `assertQueued`, not `assertSent`: `RamsReadyMail implements
        // ShouldQueue` (its own docblock says so, plan 09-05), so `Mail::to()
        // ->send()` hands it to the queue rather than the transport. Measured,
        // not assumed — `assertSent` was red here first.
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\RamsReadyMail::class);

        $this->assertNotNull(
            $record->completion_email_sent_at,
            'The idempotency stamp was not set, so a job retry would email twice.'
        );

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
        // RE-EXPECTED BY 260928-dq2, same reason as the status test above: the
        // fallback build SUCCEEDS, so its terminal status is `completed`. The
        // redirect to the review/edit page below is unchanged.
        $this->assertSame(RamsDocument::STATUS_COMPLETED, $record->status);
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
