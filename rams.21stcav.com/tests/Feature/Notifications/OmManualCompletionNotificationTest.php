<?php

namespace Tests\Feature\Notifications;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Jobs\BuildOmManualJob;
use App\Mail\OmManualReadyMail;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use App\Services\OmManualDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Feature test: BuildOmManualJob completion email dispatch (NOTF-01b O&M variant).
 *
 * Fires on STATUS_DRAFT (not STATUS_FINAL — RESEARCH Pitfall 4).
 */
class OmManualCompletionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_OmManualReadyMail_when_status_flips_to_draft(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $manual  = OmManual::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => OmManual::STATUS_GENERATING,
            'completion_email_sent_at' => null,
        ]);

        // Mock collaborators so handle() doesn't run AI / DOCX.
        $generator = Mockery::mock(OmManualGeneratorService::class);
        $generator->shouldReceive('generateContent')->once()->andReturn(['sections' => []]);
        $docx = Mockery::mock(OmManualDocxService::class);
        $docx->shouldReceive('build')->once()->andReturn('/tmp/fake-om.docx');

        (new BuildOmManualJob($manual->id))->handle($generator, $docx);

        Mail::assertQueued(OmManualReadyMail::class, function ($mail) use ($owner, $manual) {
            return $mail->hasTo($owner->email) && $mail->manual->id === $manual->id;
        });

        $manual->refresh();
        $this->assertEquals(OmManual::STATUS_DRAFT, $manual->status);
        $this->assertNotNull($manual->completion_email_sent_at);
    }

    public function test_does_not_send_when_already_sent(): void
    {
        Mail::fake();

        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $manual  = OmManual::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => OmManual::STATUS_GENERATING,
            'completion_email_sent_at' => now()->subHour(),
        ]);

        $generator = Mockery::mock(OmManualGeneratorService::class);
        $generator->shouldReceive('generateContent')->once()->andReturn(['sections' => []]);
        $docx = Mockery::mock(OmManualDocxService::class);
        $docx->shouldReceive('build')->once()->andReturn('/tmp/fake-om.docx');

        (new BuildOmManualJob($manual->id))->handle($generator, $docx);

        Mail::assertNotQueued(OmManualReadyMail::class);
    }
}
