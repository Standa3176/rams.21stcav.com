<?php

namespace Tests\Feature\Notifications;

use App\Jobs\BuildCableScheduleJob;
use App\Jobs\BuildRamsDocumentJob;
use App\Mail\DocumentGenerationFailedMail;
use App\Models\CableSchedule;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature test: Build*Job::failed() admin failure alerts
 * (NOTF-04a, NOTF-04b, NOTF-04c).
 */
class DocumentGenerationFailedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_hook_sends_DocumentGenerationFailedMail_to_all_admins(): void
    {
        Mail::fake();

        $adminOne = User::factory()->create(['email' => 'admin1@example.test', 'role' => 'admin']);
        $adminTwo = User::factory()->create(['email' => 'admin2@example.test', 'role' => 'admin']);

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

        Mail::assertQueued(DocumentGenerationFailedMail::class, 2);
        Mail::assertQueued(DocumentGenerationFailedMail::class, fn ($m) => $m->hasTo($adminOne->email));
        Mail::assertQueued(DocumentGenerationFailedMail::class, fn ($m) => $m->hasTo($adminTwo->email));
    }

    public function test_failed_hook_does_not_double_send_when_failed_email_sent_at_set(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);

        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'              => $owner->id,
            'project_id'           => $project->id,
            'status'               => RamsDocument::STATUS_FAILED,
            'failed_email_sent_at' => now()->subHour(),
        ]);

        (new BuildRamsDocumentJob($rams->id))->failed(new \Exception('boom'));

        Mail::assertNotQueued(DocumentGenerationFailedMail::class);
    }

    public function test_failed_email_body_truncates_error_message_to_500_chars(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);

        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        // The failed() hook overwrites record->error_message with the exception
        // message FIRST, then dispatches the alert — so the 600-char string must
        // live on the exception. (Matches real $tries=2 exhaustion path where
        // Laravel hands the fatal exception into failed().)
        $rams = RamsDocument::factory()->create([
            'user_id'              => $owner->id,
            'project_id'           => $project->id,
            'status'               => RamsDocument::STATUS_GENERATING,
            'failed_email_sent_at' => null,
        ]);

        $longError = str_repeat('A', 600);
        (new BuildRamsDocumentJob($rams->id))->failed(new \Exception($longError));

        Mail::assertQueued(DocumentGenerationFailedMail::class, 1);
        Mail::assertQueued(DocumentGenerationFailedMail::class, function ($mail) {
            // NOTF-04c: caller truncates error_message to ≤500 chars.
            return is_string($mail->errorMessage)
                && strlen($mail->errorMessage) === 500;
        });
    }

    public function test_cable_failed_hook_falls_back_to_exception_message_when_error_message_null(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);

        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $cable   = CableSchedule::factory()->create([
            'user_id'              => $owner->id,
            'project_id'           => $project->id,
            'status'               => CableSchedule::STATUS_GENERATING,
            'error_message'        => null,
            'failed_email_sent_at' => null,
        ]);

        (new BuildCableScheduleJob($cable->id))->failed(new \Exception('cable boom'));

        Mail::assertQueued(DocumentGenerationFailedMail::class, function ($mail) {
            return str_contains((string) $mail->errorMessage, 'cable boom');
        });
    }
}
