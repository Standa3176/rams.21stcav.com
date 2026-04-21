<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 15-01 Task 3 — TimeEntry and User model extensions (unit-level only).
 *
 * Feature-level tests for the HTTP flow live in tests/Feature/TimeEntries/ and
 * remain green as part of the no-regression check. This file focuses on:
 *   - The new CATEGORY_* constants and the CATEGORIES array
 *   - The CLOSURE_REASON_STALE_AUTO_CLOSE constant
 *   - TimeEntry::audits() hasMany relation to TimeEntryAudit
 *   - User::timeEntries() hasMany relation
 *   - User::timeEntryAudits() hasMany via edited_by_user_id
 */
class TimeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_constant_has_four_values(): void
    {
        $this->assertCount(4, TimeEntry::CATEGORIES);
        $this->assertContains(TimeEntry::CATEGORY_INSTALLATION, TimeEntry::CATEGORIES);
        $this->assertContains(TimeEntry::CATEGORY_COMMISSIONING, TimeEntry::CATEGORIES);
        $this->assertContains(TimeEntry::CATEGORY_TESTING, TimeEntry::CATEGORIES);
        $this->assertContains(TimeEntry::CATEGORY_OTHER, TimeEntry::CATEGORIES);
    }

    public function test_category_constants_have_lowercase_values(): void
    {
        // D-01 + D-19: DB values stored lowercase, UI title-cases
        $this->assertSame('installation', TimeEntry::CATEGORY_INSTALLATION);
        $this->assertSame('commissioning', TimeEntry::CATEGORY_COMMISSIONING);
        $this->assertSame('testing', TimeEntry::CATEGORY_TESTING);
        $this->assertSame('other', TimeEntry::CATEGORY_OTHER);
    }

    public function test_closure_reason_stale_auto_close_constant(): void
    {
        $this->assertSame('stale_auto_close', TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE);
    }

    public function test_entry_has_audits_relation(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
        ]);

        TimeEntryAudit::create([
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $user->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
            'old_value'         => 'installation',
            'new_value'         => 'testing',
            'edited_at'         => now(),
        ]);
        TimeEntryAudit::create([
            'time_entry_id'     => $entry->id,
            'edited_by_user_id' => $user->id,
            'field'             => TimeEntryAudit::FIELD_NOTES,
            'old_value'         => null,
            'new_value'         => 'Corrected after review',
            'edited_at'         => now()->addSecond(),
        ]);

        $this->assertCount(2, $entry->audits);
    }

    public function test_user_has_time_entries_relation(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        TimeEntry::factory()->count(2)->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
        ]);

        $this->assertCount(2, $user->timeEntries);
    }

    public function test_user_has_time_entry_audits_relation_by_editor(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $entryA = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
        ]);
        $entryB = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
        ]);

        TimeEntryAudit::create([
            'time_entry_id'     => $entryA->id,
            'edited_by_user_id' => $editor->id,
            'field'             => TimeEntryAudit::FIELD_CATEGORY,
            'old_value'         => 'installation',
            'new_value'         => 'commissioning',
            'edited_at'         => now(),
        ]);
        TimeEntryAudit::create([
            'time_entry_id'     => $entryB->id,
            'edited_by_user_id' => $editor->id,
            'field'             => TimeEntryAudit::FIELD_NOTES,
            'old_value'         => null,
            'new_value'         => 'Added missing note',
            'edited_at'         => now()->addSecond(),
        ]);

        $this->assertCount(2, $editor->timeEntryAudits);
    }

    public function test_phase_15_fillable_includes_new_columns(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $entry = TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now(),
            'clocked_out_at' => now()->addHour(),
            'notes'          => 'Finished first fix',
            'closure_reason' => null,
        ]);

        $this->assertSame('installation', $entry->fresh()->category);
        $this->assertSame('Finished first fix', $entry->fresh()->notes);
    }
}
