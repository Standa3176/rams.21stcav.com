<?php

namespace Tests\Feature\TimeEntries;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /time-entries/{entry} feature coverage — Plan 15-02 (INST-04b/c retro-edit).
 *
 * Owner OR admin may edit a CLOSED entry's category or notes. Every successful
 * edit writes a TimeEntryAudit row (T-15-02-02 / T-15-02-04 mitigation).
 */
class TimeEntryEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_edit_category_on_closed_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_CATEGORY,
            'value' => TimeEntry::CATEGORY_TESTING,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('time_entries', [
            'id'       => $entry->id,
            'category' => TimeEntry::CATEGORY_TESTING,
        ]);
        $this->assertSame(1, TimeEntryAudit::count());
    }

    public function test_owner_can_edit_notes_on_closed_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_NOTES,
            'value' => 'retro note',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $user->id,
            'field'             => TimeEntryAudit::FIELD_NOTES,
            'new_value'         => 'retro note',
        ]);
    }

    public function test_admin_can_edit_any_entry(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($admin)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_CATEGORY,
            'value' => TimeEntry::CATEGORY_OTHER,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $admin->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
        ]);
    }

    public function test_any_authenticated_user_can_edit_any_entry(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-admin user may retro-edit
        // any entry. The append-only audit row records them as editor — accountability
        // is preserved by the trail, not by an ownership gate.
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $owner->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($stranger)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_CATEGORY,
            'value' => TimeEntry::CATEGORY_TESTING,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('time_entries', [
            'id'       => $entry->id,
            'category' => TimeEntry::CATEGORY_TESTING,
        ]);
        $this->assertSame(1, TimeEntryAudit::count());
        $this->assertDatabaseHas('time_entry_audits', [
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $stranger->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
        ]);
    }

    public function test_cannot_edit_open_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_CATEGORY,
            'value' => TimeEntry::CATEGORY_TESTING,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_edit_invalid_field(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => 'project_id',
            'value' => '99',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_edit_category_to_invalid_value(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_CATEGORY,
            'value' => 'rubbish',
        ]);

        $response->assertStatus(422);
    }

    public function test_note_over_500_rejected(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->patchJson("/time-entries/{$entry->id}", [
            'field' => TimeEntryAudit::FIELD_NOTES,
            'value' => str_repeat('a', 501),
        ]);

        $response->assertStatus(422);
    }
}
