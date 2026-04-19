<?php

namespace Tests\Feature\Notifications;

use App\Jobs\ExtractRamsDraftJob;
use App\Mail\RamsReviewNeededMail;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature test: ExtractRamsDraftJob review-needed email dispatch
 * (NOTF-03a, NOTF-03b, NOTF-03c).
 *
 * Tests the dispatch seam directly via dispatchReviewNeededEmail() to avoid
 * OCR latency; end-to-end PDF flow is exercised by
 * tests/Feature/Rams/ReviewWorkflowTest.php (existing) where applicable.
 */
class RamsReviewNeededNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_RamsReviewNeededMail_when_extract_completes(): void
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

        (new ExtractRamsDraftJob($rams->id))->dispatchReviewNeededEmail($rams);

        Mail::assertQueued(RamsReviewNeededMail::class, function ($mail) use ($owner, $rams) {
            return $mail->hasTo($owner->email) && $mail->rams->id === $rams->id;
        });

        $rams->refresh();
        $this->assertNotNull($rams->review_needed_email_sent_at);
    }

    public function test_review_email_subject_contains_project_ref_and_for_review_text(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                     => $owner->id,
            'project_id'                  => $project->id,
            'project_ref'                 => '21CQ30017',
            'status'                      => RamsDocument::STATUS_AWAITING_REVIEW,
            'review_needed_email_sent_at' => null,
        ]);

        (new ExtractRamsDraftJob($rams->id))->dispatchReviewNeededEmail($rams);

        Mail::assertQueued(RamsReviewNeededMail::class, function ($mail) {
            $subject = $mail->envelope()->subject;
            return preg_match('/\[21CQ30017\] RAMS ready for review/', $subject) === 1;
        });
    }

    public function test_does_not_resend_when_review_needed_email_sent_at_already_set(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $rams    = RamsDocument::factory()->create([
            'user_id'                     => $owner->id,
            'project_id'                  => $project->id,
            'status'                      => RamsDocument::STATUS_AWAITING_REVIEW,
            'review_needed_email_sent_at' => now()->subHour(),
        ]);

        (new ExtractRamsDraftJob($rams->id))->dispatchReviewNeededEmail($rams);

        // NOTF-03c idempotency lock — a $tries=2 retry must NOT re-fire.
        Mail::assertNotQueued(RamsReviewNeededMail::class);
    }
}
