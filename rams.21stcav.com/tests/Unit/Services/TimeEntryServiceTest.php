<?php

namespace Tests\Unit\Services;

use App\Exceptions\TimeEntryEditException;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit coverage for Phase 15 Plan 15-02 extensions to TimeEntryService:
 * start(+category), stop(+note), recordHeartbeat, editEntry,
 * summaryForProject, closeStaleSessions.
 */
class TimeEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimeEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimeEntryService();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // start(+category)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_start_requires_valid_category(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->start($project, $user, 'bogus');
    }

    public function test_start_persists_category(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = $this->service->start($project, $user, TimeEntry::CATEGORY_INSTALLATION);

        $this->assertSame(TimeEntry::CATEGORY_INSTALLATION, $entry->category);
        $this->assertDatabaseHas('time_entries', [
            'id'       => $entry->id,
            'category' => TimeEntry::CATEGORY_INSTALLATION,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // stop(+note)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stop_with_note_persists_note(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->service->start($project, $user, TimeEntry::CATEGORY_INSTALLATION);
        $closed = $this->service->stop($project, $user, 'Finished rack build');

        $this->assertSame('Finished rack build', $closed->notes);
        $this->assertNotNull($closed->clocked_out_at);
        $this->assertNull($closed->closure_reason);
    }

    public function test_stop_without_note_persists_null(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->service->start($project, $user, TimeEntry::CATEGORY_OTHER);
        $closed = $this->service->stop($project, $user);

        $this->assertNull($closed->notes);
    }

    public function test_stop_with_oversize_note_throws(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->service->start($project, $user, TimeEntry::CATEGORY_OTHER);

        $this->expectException(InvalidArgumentException::class);
        $this->service->stop($project, $user, str_repeat('a', 501));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recordHeartbeat
    // ─────────────────────────────────────────────────────────────────────────

    public function test_heartbeat_updates_last_heartbeat_at(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $this->service->recordHeartbeat($entry, $user);

        $fresh = $entry->fresh();
        $this->assertLessThanOrEqual(
            2,
            (int) $fresh->last_heartbeat_at->diffInSeconds(now()),
        );
    }

    public function test_heartbeat_rejects_foreign_user(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $owner->id]);

        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->service->recordHeartbeat($entry, $stranger);
    }

    public function test_heartbeat_rejects_closed_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(TimeEntryEditException::class);
        $this->service->recordHeartbeat($entry, $user);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // editEntry
    // ─────────────────────────────────────────────────────────────────────────

    public function test_edit_entry_by_owner_writes_audit_row(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $fresh = $this->service->editEntry(
            $entry,
            $user,
            TimeEntryAudit::FIELD_CATEGORY,
            TimeEntry::CATEGORY_TESTING,
        );

        $this->assertSame(TimeEntry::CATEGORY_TESTING, $fresh->category);
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $user->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
            'old_value'         => TimeEntry::CATEGORY_INSTALLATION,
            'new_value'         => TimeEntry::CATEGORY_TESTING,
        ]);
        $this->assertSame(1, TimeEntryAudit::count());
    }

    public function test_edit_entry_by_admin_allowed(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->service->editEntry(
            $entry,
            $admin,
            TimeEntryAudit::FIELD_NOTES,
            'ops fix',
        );

        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $admin->id,
            'field'             => TimeEntryAudit::FIELD_NOTES,
            'new_value'         => 'ops fix',
        ]);
    }

    public function test_edit_entry_by_any_user_succeeds_and_writes_audit(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-admin user may retro-edit
        // any entry; the audit row records them as editor (accountability preserved).
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $owner->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $fresh = $this->service->editEntry(
            $entry,
            $stranger,
            TimeEntryAudit::FIELD_CATEGORY,
            TimeEntry::CATEGORY_TESTING,
        );

        $this->assertSame(TimeEntry::CATEGORY_TESTING, $fresh->category);
        $this->assertSame(1, TimeEntryAudit::count());
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $stranger->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
            'old_value'         => TimeEntry::CATEGORY_INSTALLATION,
            'new_value'         => TimeEntry::CATEGORY_TESTING,
        ]);
    }

    public function test_edit_entry_rejects_open_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(TimeEntryEditException::class);
        $this->service->editEntry(
            $entry,
            $user,
            TimeEntryAudit::FIELD_CATEGORY,
            TimeEntry::CATEGORY_TESTING,
        );
    }

    public function test_edit_entry_rejects_invalid_field(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(TimeEntryEditException::class);
        $this->service->editEntry($entry, $user, 'user_id', '99');
    }

    public function test_edit_entry_rejects_invalid_category(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(TimeEntryEditException::class);
        $this->service->editEntry(
            $entry,
            $user,
            TimeEntryAudit::FIELD_CATEGORY,
            'bogus',
        );
    }

    public function test_edit_entry_rejects_oversize_note(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->expectException(TimeEntryEditException::class);
        $this->service->editEntry(
            $entry,
            $user,
            TimeEntryAudit::FIELD_NOTES,
            str_repeat('a', 501),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // summaryForProject
    // ─────────────────────────────────────────────────────────────────────────

    public function test_summary_for_project_totals_by_category(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        TimeEntry::factory()->create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subMinutes(30),
            'clocked_out_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subMinutes(60),
            'clocked_out_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_TESTING,
            'clocked_in_at'  => now()->subMinutes(15),
            'clocked_out_at' => now(),
        ]);

        $summary = $this->service->summaryForProject($project);

        $this->assertSame(105, $summary['total_minutes']);
        $this->assertSame(90, $summary['per_category'][TimeEntry::CATEGORY_INSTALLATION]);
        $this->assertSame(0, $summary['per_category'][TimeEntry::CATEGORY_COMMISSIONING]);
        $this->assertSame(15, $summary['per_category'][TimeEntry::CATEGORY_TESTING]);
        $this->assertSame(0, $summary['per_category'][TimeEntry::CATEGORY_OTHER]);
    }

    public function test_summary_excludes_open_entries(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // OPEN entry — must NOT be counted
        TimeEntry::factory()->create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subMinutes(30),
            'clocked_out_at' => null,
        ]);

        $summary = $this->service->summaryForProject($project);

        $this->assertSame(0, $summary['total_minutes']);
    }

    public function test_summary_returns_all_four_keys_even_empty(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $summary = $this->service->summaryForProject($project);

        $this->assertSame(0, $summary['total_minutes']);
        foreach (TimeEntry::CATEGORIES as $cat) {
            $this->assertArrayHasKey($cat, $summary['per_category']);
            $this->assertSame(0, $summary['per_category'][$cat]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // closeStaleSessions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_close_stale_sessions_closes_session_past_cutoff(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $heartbeatAt = now()->subHours(3);
        $entry = TimeEntry::factory()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => now()->subHours(4),
            'clocked_out_at'    => null,
            'last_heartbeat_at' => $heartbeatAt,
        ]);

        $closed = $this->service->closeStaleSessions(120);

        $this->assertSame(1, $closed);
        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->clocked_out_at);
        $this->assertSame(
            $heartbeatAt->toIso8601String(),
            $fresh->clocked_out_at->toIso8601String(),
        );
        $this->assertSame(
            TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE,
            $fresh->closure_reason,
        );
    }

    public function test_close_stale_sessions_uses_fallback_when_last_heartbeat_null(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $clockedIn = now()->subHours(3);
        $entry = TimeEntry::factory()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => $clockedIn,
            'clocked_out_at'    => null,
            'last_heartbeat_at' => null,
        ]);

        $closed = $this->service->closeStaleSessions(120);

        $this->assertSame(1, $closed);
        $fresh = $entry->fresh();
        $this->assertSame(
            $clockedIn->copy()->addMinute()->toIso8601String(),
            $fresh->clocked_out_at->toIso8601String(),
        );
    }

    public function test_close_stale_sessions_skips_recent(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        TimeEntry::factory()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => now()->subHour(),
            'clocked_out_at'    => null,
            'last_heartbeat_at' => now()->subMinutes(30),
        ]);

        $closed = $this->service->closeStaleSessions(120);

        $this->assertSame(0, $closed);
    }

    public function test_close_stale_sessions_skips_already_closed(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        TimeEntry::factory()->closed()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'     => now()->subHours(4),
            'clocked_out_at'    => now()->subHours(3),
            'last_heartbeat_at' => now()->subHours(3),
        ]);

        $closed = $this->service->closeStaleSessions(120);

        $this->assertSame(0, $closed);
    }
}
