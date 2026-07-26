<?php

namespace Tests\Feature\Cable;

use App\Jobs\BuildCableScheduleJob;
use App\Models\CableSchedule;
use App\Models\Project;
use App\Models\User;
use App\Services\CableScheduleGeneratorService;
use App\Services\CableScheduleXlsxService;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 2 — cable schedule error_message.
 *
 * When BuildCableScheduleJob's inner catch fires (any exception during
 * generation), the exception message must land on
 * cable_schedules.error_message so the edit / index UI can surface it via
 * the "See why" pattern used by RAMS.
 */
class CableScheduleErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    // ── Handle-path catch stamps error_message ────────────────────────────

    public function test_build_job_catch_stamps_error_message_on_schedule(): void
    {
        $user     = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Failing project',
            'status'       => CableSchedule::STATUS_GENERATING,
        ]);

        $generator = Mockery::mock(CableScheduleGeneratorService::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Boom — synthetic generator failure'));

        $xlsx = Mockery::mock(CableScheduleXlsxService::class);

        $job = new BuildCableScheduleJob($schedule->id);

        try {
            $job->handle($generator, $xlsx);
            $this->fail('Expected RuntimeException to bubble up from handle()');
        } catch (\RuntimeException $e) {
            // Expected — job re-throws for retry semantics.
        }

        $schedule->refresh();
        $this->assertSame(CableSchedule::STATUS_FAILED, $schedule->status);
        $this->assertNotNull($schedule->error_message);
        $this->assertStringContainsString('Boom', $schedule->error_message);
    }

    // ── failed() hook also stamps error_message on retry-exhaustion ────────

    public function test_failed_hook_stamps_error_message_after_retry_exhaustion(): void
    {
        $user     = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Retry-exhausted project',
            'status'       => CableSchedule::STATUS_GENERATING,
        ]);

        $job = new BuildCableScheduleJob($schedule->id);
        $job->failed(new \RuntimeException('Retry exhausted — final failure marker'));

        $schedule->refresh();
        $this->assertSame(CableSchedule::STATUS_FAILED, $schedule->status);
        $this->assertNotNull($schedule->error_message);
        $this->assertStringContainsString('Retry exhausted', $schedule->error_message);
    }

    // ── Edit view surfaces the error message ───────────────────────────────

    public function test_edit_view_surfaces_error_message_when_failed(): void
    {
        $user     = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Broken schedule',
            'status'        => CableSchedule::STATUS_FAILED,
            'error_message' => 'Missing quote data — cannot infer cable routes',
        ]);

        $response = $this->actingAs($user)
            ->get(route('cable-schedules.edit', $schedule))
            ->assertOk();

        $response->assertSee('Generation failed', false);
        $response->assertSee('See why', false);
        $response->assertSee('Missing quote data — cannot infer cable routes', false);
    }

    // ── Index view surfaces the failed pill + "See why" ────────────────────

    public function test_index_view_shows_failed_pill_and_see_why(): void
    {
        $user     = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $user->id]);
        CableSchedule::create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Failed CS Index',
            'status'        => CableSchedule::STATUS_FAILED,
            'error_message' => 'Truncated at boundary — details surface after click',
        ]);

        $response = $this->actingAs($user)
            ->get(route('cable-schedules.index'))
            ->assertOk();

        $response->assertSee('Failed', false);
        $response->assertSee('See why', false);
        $response->assertSee('Truncated at boundary', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
