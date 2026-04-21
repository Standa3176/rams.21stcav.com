<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 15-01 Task 2 — TimeEntryAudit model unit tests.
 *
 * Covers:
 *   - belongsTo(TimeEntry) via time_entry_id
 *   - belongsTo(User) editor via edited_by_user_id (non-default FK)
 *   - FIELDS constant covers exactly the two retro-editable fields (D-04, D-07)
 */
class TimeEntryAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_belongs_to_time_entry(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
        ]);

        $audit = TimeEntryAudit::create([
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $user->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
            'old_value'         => 'installation',
            'new_value'         => 'testing',
            'edited_at'         => now(),
        ]);

        $this->assertTrue($audit->timeEntry->is($entry));
    }

    public function test_audit_belongs_to_editor(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
        ]);

        $audit = TimeEntryAudit::create([
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $editor->id,
            'field'             => TimeEntryAudit::FIELD_NOTES,
            'old_value'         => null,
            'new_value'         => 'Corrected post-hoc',
            'edited_at'         => now(),
        ]);

        $this->assertTrue($audit->editor->is($editor));
    }

    public function test_field_constant_covers_both_retro_editable_fields(): void
    {
        $this->assertSame(
            ['category', 'notes'],
            TimeEntryAudit::FIELDS,
        );
    }
}
