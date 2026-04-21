<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature tests for the Phase 15 programme:close-stale-sessions Artisan command.
 *
 * Covers:
 *   - Registration:       discoverable via Artisan::all()
 *   - Stale close:        clocked_out_at = last_heartbeat_at, closure_reason set
 *   - Fresh skip:         entry within threshold stays open
 *   - Summary line:       exit 0 + "Closed N stale time entries" output
 *   - --minutes option:   custom threshold overrides the 120-min default
 *   - NULL heartbeat:     fallback to clocked_in_at + 1 min (D-11)
 *   - Invalid option:     --minutes=0 returns FAILURE
 *   - Scheduler wiring:   hourly cron + withoutOverlapping in routes/console.php
 *
 * @see \App\Console\Commands\CloseStaleSessionsCommand
 * @see \App\Services\TimeEntryService::closeStaleSessions
 */
class CloseStaleSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_registered_in_artisan_list(): void
    {
        $this->assertArrayHasKey('programme:close-stale-sessions', Artisan::all());
    }

    public function test_command_closes_stale_session(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => Carbon::parse('2026-04-21 05:00:00'),
            'last_heartbeat_at' => Carbon::parse('2026-04-21 09:00:00'),
        ]);

        $this->artisan('programme:close-stale-sessions')
            ->assertExitCode(0);

        $entry->refresh();
        $this->assertNotNull($entry->clocked_out_at);
        $this->assertTrue($entry->clocked_out_at->equalTo(Carbon::parse('2026-04-21 09:00:00')));
        $this->assertSame(TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE, $entry->closure_reason);
    }

    public function test_command_does_not_close_fresh_session(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => Carbon::parse('2026-04-21 11:00:00'),
            'last_heartbeat_at' => Carbon::parse('2026-04-21 11:30:00'),
        ]);

        $this->artisan('programme:close-stale-sessions')->assertExitCode(0);

        $entry->refresh();
        $this->assertNull($entry->clocked_out_at);
        $this->assertNull($entry->closure_reason);
    }

    public function test_command_exits_zero_with_summary(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => Carbon::parse('2026-04-21 05:00:00'),
            'last_heartbeat_at' => Carbon::parse('2026-04-21 06:00:00'),
        ]);

        $this->artisan('programme:close-stale-sessions')
            ->expectsOutputToContain('Closed 1 stale time entries')
            ->assertExitCode(0);
    }

    public function test_command_respects_minutes_option(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => Carbon::parse('2026-04-21 10:00:00'),
            'last_heartbeat_at' => Carbon::parse('2026-04-21 11:00:00'),  // 1h old
        ]);

        // Default 120 min threshold — entry stays open
        $this->artisan('programme:close-stale-sessions')->assertExitCode(0);
        $entry->refresh();
        $this->assertNull($entry->clocked_out_at);

        // Custom 45 min threshold — entry now stale, gets closed
        $this->artisan('programme:close-stale-sessions --minutes=45')->assertExitCode(0);
        $entry->refresh();
        $this->assertNotNull($entry->clocked_out_at);
        $this->assertSame(TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE, $entry->closure_reason);
    }

    public function test_command_handles_null_heartbeat_via_fallback(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => Carbon::parse('2026-04-21 05:00:00'),
            'last_heartbeat_at' => null,
        ]);

        $this->artisan('programme:close-stale-sessions')->assertExitCode(0);
        $entry->refresh();
        $this->assertNotNull($entry->clocked_out_at);
        $this->assertTrue($entry->clocked_out_at->equalTo(Carbon::parse('2026-04-21 05:01:00')));
    }

    public function test_invalid_minutes_option_returns_failure(): void
    {
        $this->artisan('programme:close-stale-sessions --minutes=0')
            ->assertExitCode(1);
    }

    public function test_scheduler_registration_hourly_without_overlap(): void
    {
        $schedule = app(Schedule::class);
        $matches = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'programme:close-stale-sessions'))
            ->values()
            ->all();

        $this->assertCount(1, $matches, 'Expected exactly one schedule entry for programme:close-stale-sessions');
        $event = $matches[0];
        $this->assertSame('0 * * * *', $event->expression, 'Expected hourly cron expression');
        $this->assertTrue($event->withoutOverlapping, 'Expected withoutOverlapping=true');
    }
}
