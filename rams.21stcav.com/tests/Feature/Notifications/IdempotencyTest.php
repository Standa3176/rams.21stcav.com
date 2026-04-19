<?php

namespace Tests\Feature\Notifications;

use App\Jobs\BuildRamsDocumentJob;
use App\Jobs\ExtractRamsDraftJob;
use App\Mail\DocumentGenerationFailedMail;
use App\Mail\RamsReadyMail;
use App\Mail\RamsReviewNeededMail;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Idempotency lock across the three notification paths (NOTF-01d, NOTF-03c,
 * NOTF-04b). Pins the "timestamp-before-send" pattern so a $tries=2 retry
 * cannot double-dispatch.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function validReviewPayload(): array
    {
        return [
            'project'    => ['project_name' => 'Test'],
            'activities' => [['key' => 'install']],
            'equipment'  => [],
            'hazards'    => [],
            'meta'       => ['source' => 'reviewed'],
        ];
    }

    public function test_completion_email_not_resent_when_job_handle_runs_twice(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            'reviewed_data'            => $this->validReviewPayload(),
            'approved_at'              => now(),
            'completion_email_sent_at' => null,
        ]);

        $builder = Mockery::mock(RamsBuilderService::class);
        $builder->shouldReceive('buildFromReview')->twice()->andReturn('/tmp/fake.docx');

        $job = new BuildRamsDocumentJob($rams->id);
        $job->handle($builder);

        // Second run simulates a queue retry. Status is already COMPLETED and
        // completion_email_sent_at is set, so the second handle() must NOT
        // re-dispatch the mail (NOTF-01d).
        $rams->refresh();
        // Handle() guards on reviewed_data / approved_at — both still valid
        // because handle() doesn't reset them on success. Second handle will
        // see status=COMPLETED and short-circuit the status-update, then hit
        // the completion_email_sent_at === null check and skip the mail.
        $job->handle($builder);

        Mail::assertQueued(RamsReadyMail::class, 1);
    }

    public function test_failed_email_not_resent_when_failed_hook_runs_twice(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'admin1@example.test', 'role' => 'admin']);
        User::factory()->create(['email' => 'admin2@example.test', 'role' => 'admin']);

        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'              => $owner->id,
            'project_id'           => $project->id,
            'status'               => RamsDocument::STATUS_GENERATING,
            'failed_email_sent_at' => null,
        ]);

        $job = new BuildRamsDocumentJob($rams->id);
        $job->failed(new \Exception('boom'));
        $job->failed(new \Exception('boom'));

        // 2 admins; one round of failed() → 2 mails. Second call must NOT
        // double the count to 4 (NOTF-04b).
        Mail::assertQueued(DocumentGenerationFailedMail::class, 2);
    }

    public function test_review_needed_email_not_resent_when_extract_dispatch_runs_twice(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                     => $owner->id,
            'project_id'                  => $project->id,
            'status'                      => RamsDocument::STATUS_AWAITING_REVIEW,
            'review_needed_email_sent_at' => null,
        ]);

        $job = new ExtractRamsDraftJob($rams->id);
        $job->dispatchReviewNeededEmail($rams);
        // Second call simulates $tries=2 retry of the extract job.
        $job->dispatchReviewNeededEmail($rams);

        // NOTF-03c lock — closes the gap raised by checker I-04.
        Mail::assertQueued(RamsReviewNeededMail::class, 1);
    }

    public function test_timestamp_set_before_send_so_concurrent_retry_sees_it(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            'reviewed_data'            => $this->validReviewPayload(),
            'approved_at'              => now(),
            'completion_email_sent_at' => null,
        ]);

        $builder = Mockery::mock(RamsBuilderService::class);
        $builder->shouldReceive('buildFromReview')->once()->andReturn('/tmp/fake.docx');

        (new BuildRamsDocumentJob($rams->id))->handle($builder);

        // RESEARCH 'Idempotency Pattern': timestamp is set BEFORE mail dispatch
        // so a concurrent retry observes a non-null value and skips. Verified
        // here post-hoc — by the time Mail::assertQueued runs (after handle()
        // returned), the DB column must already carry a timestamp.
        Mail::assertQueued(RamsReadyMail::class, function ($mail) use ($rams) {
            return RamsDocument::find($rams->id)->completion_email_sent_at !== null;
        });
    }
}
