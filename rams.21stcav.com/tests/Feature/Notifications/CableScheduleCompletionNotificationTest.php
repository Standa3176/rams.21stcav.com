<?php

namespace Tests\Feature\Notifications;

use App\Jobs\BuildCableScheduleJob;
use App\Mail\CableScheduleReadyMail;
use App\Models\CableSchedule;
use App\Models\Project;
use App\Models\User;
use App\Services\CableScheduleGeneratorService;
use App\Services\CableScheduleXlsxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Feature test: BuildCableScheduleJob completion email dispatch (NOTF-01b Cable variant).
 *
 * Fires on STATUS_DRAFT (not STATUS_FINAL — RESEARCH Pitfall 4).
 * Attachment lookup uses source_filename (CableSchedule has no filename column).
 */
class CableScheduleCompletionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_CableScheduleReadyMail_when_status_flips_to_draft(): void
    {
        Mail::fake();

        $owner    = User::factory()->create(['email' => 'owner@example.test']);
        $project  = Project::factory()->create(['user_id' => $owner->id]);
        $schedule = CableSchedule::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => CableSchedule::STATUS_GENERATING,
            'completion_email_sent_at' => null,
        ]);

        $generator = Mockery::mock(CableScheduleGeneratorService::class);
        $generator->shouldReceive('generate')->once()->andReturn(0);
        $xlsx = Mockery::mock(CableScheduleXlsxService::class);
        $xlsx->shouldReceive('build')->zeroOrMoreTimes()->andReturn('/tmp/fake-cable.xlsx');

        (new BuildCableScheduleJob($schedule->id))->handle($generator, $xlsx);

        Mail::assertQueued(CableScheduleReadyMail::class, function ($mail) use ($owner, $schedule) {
            return $mail->hasTo($owner->email) && $mail->schedule->id === $schedule->id;
        });

        $schedule->refresh();
        $this->assertEquals(CableSchedule::STATUS_DRAFT, $schedule->status);
        $this->assertNotNull($schedule->completion_email_sent_at);
    }

    public function test_attachment_uses_source_filename_not_filename(): void
    {
        Mail::fake();

        $owner    = User::factory()->create(['email' => 'owner@example.test']);
        $project  = Project::factory()->create(['user_id' => $owner->id]);
        $schedule = CableSchedule::factory()->create([
            'user_id'                  => $owner->id,
            'project_id'               => $project->id,
            'status'                   => CableSchedule::STATUS_GENERATING,
            'completion_email_sent_at' => null,
        ]);

        $generator = Mockery::mock(CableScheduleGeneratorService::class);
        $generator->shouldReceive('generate')->once()->andReturn(0);
        $xlsx = Mockery::mock(CableScheduleXlsxService::class);
        $xlsx->shouldReceive('build')->zeroOrMoreTimes()->andReturn('/tmp/fake-cable.xlsx');

        (new BuildCableScheduleJob($schedule->id))->handle($generator, $xlsx);

        // The dispatched mailable must carry the schedule whose `source_filename`
        // is populated (CableSchedule has NO `filename` column — only
        // `source_filename`). Asserting the column has been stamped by the job
        // (XLSX path stamps it via CableScheduleXlsxService::build; CSV
        // fallback stamps it via BuildCableScheduleJob::buildCsvFallback).
        // RESEARCH "CableSchedule Asymmetry" — NOTF-01b cable variant.
        Mail::assertQueued(CableScheduleReadyMail::class, function ($mail) {
            return $mail->schedule instanceof CableSchedule
                && is_string($mail->schedule->source_filename)
                && $mail->schedule->source_filename !== '';
        });
    }
}
