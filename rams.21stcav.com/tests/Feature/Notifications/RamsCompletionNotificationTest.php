<?php

namespace Tests\Feature\Notifications;

use App\Jobs\BuildRamsDocumentJob;
use App\Mail\RamsReadyMail;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Feature test: BuildRamsDocumentJob completion email dispatch (NOTF-01a, NOTF-01d, NOTF-01f).
 *
 * The RamsBuilderService is mocked so the job handle() short-circuits the
 * heavy DOCX-build path and we focus the assertion on the email-dispatch
 * block added by plan 09-05 Task 1.
 */
class RamsCompletionNotificationTest extends TestCase
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

    public function test_sends_RamsReadyMail_to_project_owner_when_status_completes(): void
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

        // Mock builder so handle() doesn't actually build a DOCX.
        $builder = Mockery::mock(RamsBuilderService::class);
        $builder->shouldReceive('buildFromReview')->once()->andReturn('/tmp/fake.docx');

        (new BuildRamsDocumentJob($rams->id))->handle($builder);

        Mail::assertQueued(RamsReadyMail::class, function ($mail) use ($owner, $rams) {
            return $mail->hasTo($owner->email)
                && $mail->rams->id === $rams->id;
        });

        $rams->refresh();
        $this->assertNotNull($rams->completion_email_sent_at);
        $this->assertEquals(RamsDocument::STATUS_COMPLETED, $rams->status);
    }

    public function test_does_not_send_when_completion_email_sent_at_already_set(): void
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
            'completion_email_sent_at' => now()->subHour(),
        ]);

        $builder = Mockery::mock(RamsBuilderService::class);
        $builder->shouldReceive('buildFromReview')->once()->andReturn('/tmp/fake.docx');

        (new BuildRamsDocumentJob($rams->id))->handle($builder);

        Mail::assertNotQueued(RamsReadyMail::class);
    }

    public function test_falls_back_to_admin_when_project_has_no_owner(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'email' => 'admin@example.test',
            'role'  => 'admin',
        ]);
        // Owner exists but has empty email — triggers fallback.
        $ownerNoEmail = User::factory()->create(['email' => '']);
        $project = Project::factory()->create(['user_id' => $ownerNoEmail->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                  => $ownerNoEmail->id,
            'project_id'               => $project->id,
            'status'                   => RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            'reviewed_data'            => $this->validReviewPayload(),
            'approved_at'              => now(),
            'completion_email_sent_at' => null,
        ]);

        $builder = Mockery::mock(RamsBuilderService::class);
        $builder->shouldReceive('buildFromReview')->once()->andReturn('/tmp/fake.docx');

        (new BuildRamsDocumentJob($rams->id))->handle($builder);

        Mail::assertQueued(RamsReadyMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email);
        });
    }
}
