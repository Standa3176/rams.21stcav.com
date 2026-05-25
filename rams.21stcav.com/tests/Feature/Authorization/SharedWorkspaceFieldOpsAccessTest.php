<?php

namespace Tests\Feature\Authorization;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260525-s8b — shared-workspace authorization regression for the
 * field-ops cluster + the RAMS upload flow (the surface 260525-pyu missed).
 *
 * The intended model for the 3-person company: ANY authenticated user
 * (role=user, non-owner, non-assigned-engineer) can fully use commissioning,
 * the install programme field/schedule views, task mutations, and time entries.
 *
 * Two negative controls prove we did NOT over-relax:
 *   - #6: the strict owner-only heartbeat liveness guard is PRESERVED (a peer
 *     cannot keep another user's open clock-session alive — integrity, not
 *     access control).
 *   - #7: guests are still bounced to login (the auth middleware is intact).
 *
 * "owner" = user 1 (sonny in production); "staff" = a second role=user user
 * with NO assignment to any task (zack / alison in production).
 */
class SharedWorkspaceFieldOpsAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Owner of every resource under test = "user 1" (sonny in production). */
    private User $owner;

    /** Non-admin, non-owner, non-assigned actor = "zack / alison". */
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->staff = User::factory()->create(['role' => 'user']);
    }

    private function makeProject(): Project
    {
        return Project::factory()->create([
            'user_id' => $this->owner->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
    }

    private function makeActiveProgramme(Project $project): InstallProgramme
    {
        return InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);
    }

    // ── 1. non-assigned user can view the commissioning checklist ────────────

    public function test_non_assigned_user_can_view_commissioning_checklist(): void
    {
        $project = $this->makeProject();
        $this->makeActiveProgramme($project);

        $this->actingAs($this->staff)
            ->get(route('commissioning.show', $project))
            ->assertOk();
    }

    // ── 2. non-assigned user sees the field page AND ALL tasks ───────────────

    public function test_non_assigned_user_can_view_install_field_page_and_sees_all_tasks(): void
    {
        $project = $this->makeProject();
        $programme = $this->makeActiveProgramme($project);

        // A task assigned to a THIRD user — proves the listing is un-scoped.
        $otherEngineer = User::factory()->create(['role' => 'user']);
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'assigned_to'          => $otherEngineer->id,
            'title'                => 'Task owned by someone else entirely',
        ]);

        $this->actingAs($this->staff)
            ->get(route('install-programmes.field', $project))
            ->assertOk()
            ->assertSee('Task owned by someone else entirely');
    }

    // ── 3. non-assigned user can patch task status ───────────────────────────

    public function test_non_assigned_user_can_patch_task_status(): void
    {
        $project = $this->makeProject();
        $programme = $this->makeActiveProgramme($project);
        $task = InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => InstallTask::STATUS_IN_PROGRESS,
            'started_at'           => now(),
        ]);

        $this->actingAs($this->staff)
            ->patchJson(route('install-tasks.status', $task), [
                'status' => InstallTask::STATUS_COMPLETE,
            ])
            ->assertOk();

        $this->assertDatabaseHas('install_tasks', [
            'id'     => $task->id,
            'status' => InstallTask::STATUS_COMPLETE,
        ]);
    }

    // ── 4. non-assigned user can clock in AND retro-edit a time entry ────────

    public function test_non_assigned_user_can_clock_in_and_retro_edit_time_entry(): void
    {
        $project = $this->makeProject();

        // Clock in on the owner's project.
        $this->actingAs($this->staff)
            ->postJson(route('time-entries.start', $project), [
                'category' => TimeEntry::CATEGORY_INSTALLATION,
            ])
            ->assertOk();

        // Retro-edit a separately-created CLOSED entry owned by user 1.
        $closed = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $this->owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $this->actingAs($this->staff)
            ->patchJson(route('time-entries.update', $closed), [
                'field' => TimeEntryAudit::FIELD_CATEGORY,
                'value' => TimeEntry::CATEGORY_TESTING,
            ])
            ->assertOk();

        // The audit row records the staff member as editor (accountability kept).
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $closed->id,
            'edited_by_user_id' => $this->staff->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
        ]);
    }

    // ── 5. THE pyu-MISSED SURFACE: non-owner reaches RAMS upload status ──────

    public function test_non_owner_can_reach_rams_upload_processing_status(): void
    {
        $project = $this->makeProject();
        $rams = RamsDocument::factory()->create([
            'user_id'    => $this->owner->id,
            'project_id' => $project->id,
            'status'     => RamsDocument::STATUS_UPLOADED,
        ]);

        // checkReady JSON poll — must clear the authorization gate (200, not 403).
        $check = $this->actingAs($this->staff)->get(route('rams.check-ready', $rams));
        $check->assertOk();
        $this->assertNotSame(403, $check->getStatusCode(), 'Non-owner must NOT receive 403 on RAMS checkReady.');

        // processing waiting page — NOT 403 (200 view while uploaded, or a
        // redirect once ready/failed — both acceptable; only 403 is the bug).
        $processing = $this->actingAs($this->staff)->get(route('rams.processing', $rams));
        $this->assertNotSame(403, $processing->getStatusCode(), 'Non-owner must NOT receive 403 on RAMS processing.');
    }

    // ── 6. NEGATIVE CONTROL: heartbeat is STILL owner-only (preserved guard) ─

    public function test_heartbeat_still_owner_only(): void
    {
        $project = $this->makeProject();

        // user 1 has an OPEN time entry.
        $open = TimeEntry::factory()->create([
            'project_id'     => $project->id,
            'user_id'        => $this->owner->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_out_at' => null,
        ]);

        // A peer must NOT keep another user's session alive (integrity guard).
        $this->actingAs($this->staff)
            ->postJson(route('time-entries.heartbeat', $open))
            ->assertForbidden();
    }

    // ── 7. NEGATIVE CONTROL: guest is bounced to login (auth intact) ─────────

    public function test_guest_is_redirected_to_login(): void
    {
        $project = $this->makeProject();
        $this->makeActiveProgramme($project);

        $this->get(route('install-programmes.field', $project))
            ->assertRedirect(route('login'));
    }
}
