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
 * BCC application tests (NOTF-05d). Locks that every notification call site
 * honours `config('rams.notifications.bcc')` and NO Bcc header is set when the
 * config key is empty.
 */
class BccTest extends TestCase
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

    public function test_completion_email_includes_bcc_when_RAMS_NOTIFICATION_BCC_set(): void
    {
        Mail::fake();
        config(['rams.notifications.bcc' => 'audit@21stcav.com']);

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

        Mail::assertQueued(RamsReadyMail::class, fn ($mail) => $mail->hasBcc('audit@21stcav.com'));
    }

    public function test_completion_email_omits_bcc_when_RAMS_NOTIFICATION_BCC_empty(): void
    {
        Mail::fake();
        config(['rams.notifications.bcc' => null]);

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

        Mail::assertQueued(RamsReadyMail::class, function ($mail) {
            // No BCC configured → the bcc property must be empty / null.
            return empty($mail->bcc);
        });
    }

    public function test_failure_email_includes_bcc_when_RAMS_NOTIFICATION_BCC_set(): void
    {
        Mail::fake();
        config(['rams.notifications.bcc' => 'audit@21stcav.com']);

        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);

        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'              => $owner->id,
            'project_id'           => $project->id,
            'status'               => RamsDocument::STATUS_GENERATING,
            'failed_email_sent_at' => null,
        ]);

        (new BuildRamsDocumentJob($rams->id))->failed(new \Exception('x'));

        Mail::assertQueued(DocumentGenerationFailedMail::class, fn ($mail) => $mail->hasBcc('audit@21stcav.com'));
    }

    public function test_review_needed_email_includes_bcc_when_RAMS_NOTIFICATION_BCC_set(): void
    {
        Mail::fake();
        config(['rams.notifications.bcc' => 'audit@21stcav.com']);

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                     => $owner->id,
            'project_id'                  => $project->id,
            'status'                      => RamsDocument::STATUS_AWAITING_REVIEW,
            'review_needed_email_sent_at' => null,
        ]);

        (new ExtractRamsDraftJob($rams->id))->dispatchReviewNeededEmail($rams);

        Mail::assertQueued(RamsReviewNeededMail::class, fn ($mail) => $mail->hasBcc('audit@21stcav.com'));
    }
}
