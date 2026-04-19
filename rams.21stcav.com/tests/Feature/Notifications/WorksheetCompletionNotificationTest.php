<?php

namespace Tests\Feature\Notifications;

use App\Jobs\BuildWorksheetJob;
use App\Mail\WorksheetReadyMail;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\WorksheetDocxService;
use App\Services\WorksheetGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Feature test: BuildWorksheetJob completion email dispatch (NOTF-01b Worksheet variant).
 *
 * Fires on STATUS_DRAFT (not STATUS_FINAL — RESEARCH Pitfall 4).
 */
class WorksheetCompletionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function substantiveGeneratedData(): array
    {
        return [
            'rooms' => [
                [
                    'name'                 => 'Board Room',
                    'equipment'            => [['item' => 'Display']],
                    'install_steps'        => ['Mount display'],
                    'pre_install_answers'  => [],
                ],
            ],
        ];
    }

    public function test_sends_WorksheetReadyMail_when_status_flips_to_draft(): void
    {
        Mail::fake();

        $owner     = User::factory()->create(['email' => 'owner@example.test']);
        $project   = Project::factory()->create(['user_id' => $owner->id]);
        $worksheet = Worksheet::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => Worksheet::STATUS_GENERATING,
            'completion_email_sent_at' => null,
        ]);

        $generator = Mockery::mock(WorksheetGeneratorService::class);
        $generator->shouldReceive('generateContent')->once()->andReturn($this->substantiveGeneratedData());
        $docx = Mockery::mock(WorksheetDocxService::class);
        $docx->shouldReceive('build')->once()->andReturnNull();

        (new BuildWorksheetJob($worksheet->id))->handle($generator, $docx);

        Mail::assertQueued(WorksheetReadyMail::class, function ($mail) use ($owner, $worksheet) {
            return $mail->hasTo($owner->email) && $mail->worksheet->id === $worksheet->id;
        });

        $worksheet->refresh();
        $this->assertEquals(Worksheet::STATUS_DRAFT, $worksheet->status);
        $this->assertNotNull($worksheet->completion_email_sent_at);
    }

    public function test_does_not_send_when_already_sent(): void
    {
        Mail::fake();

        $owner     = User::factory()->create(['email' => 'owner@example.test']);
        $project   = Project::factory()->create(['user_id' => $owner->id]);
        $worksheet = Worksheet::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => Worksheet::STATUS_GENERATING,
            'completion_email_sent_at' => now()->subHour(),
        ]);

        $generator = Mockery::mock(WorksheetGeneratorService::class);
        $generator->shouldReceive('generateContent')->once()->andReturn($this->substantiveGeneratedData());
        $docx = Mockery::mock(WorksheetDocxService::class);
        $docx->shouldReceive('build')->once()->andReturnNull();

        (new BuildWorksheetJob($worksheet->id))->handle($generator, $docx);

        Mail::assertNotQueued(WorksheetReadyMail::class);
    }
}
