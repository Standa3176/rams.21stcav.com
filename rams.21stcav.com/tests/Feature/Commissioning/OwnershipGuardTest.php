<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-16-03 — ownership guard on every commissioning endpoint. Engineers
 * assigned to project A cannot mutate project B's items.
 *
 * Red until Plan 03 ships authoriseEdit + authoriseView guards.
 */
class OwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stranger_gets_403_on_checklist_view(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $project = Project::factory()->create(['user_id' => $owner->id]);
        InstallProgramme::factory()->create(['project_id' => $project->id]);

        $this->actingAs($stranger)
            ->get("/projects/{$project->id}/commissioning")
            ->assertForbidden();
    }

    public function test_stranger_gets_403_on_status_patch(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        $item = CommissioningItem::factory()->create(['install_programme_id' => $programme->id]);

        $this->actingAs($stranger)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ])
            ->assertForbidden();
    }

    public function test_engineer_assigned_to_project_a_cannot_patch_project_b_item(): void
    {
        // Two projects / two engineers
        $engineerA = User::factory()->create();
        $engineerB = User::factory()->create();

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $projectA = Project::factory()->create(['user_id' => $ownerA->id]);
        $projectB = Project::factory()->create(['user_id' => $ownerB->id]);

        $programmeA = InstallProgramme::factory()->create(['project_id' => $projectA->id]);
        $programmeB = InstallProgramme::factory()->create(['project_id' => $projectB->id]);

        // Wire engineer A to a task in programme A only
        \App\Models\InstallTask::factory()->create([
            'install_programme_id' => $programmeA->id,
            'assigned_to'          => $engineerA->id,
        ]);

        $itemB = CommissioningItem::factory()->create([
            'install_programme_id' => $programmeB->id,
        ]);

        $this->actingAs($engineerA)
            ->patchJson("/commissioning-items/{$itemB->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ])
            ->assertForbidden();
    }
}
