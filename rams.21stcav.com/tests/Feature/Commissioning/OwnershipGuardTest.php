<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared workspace (260525-s8b) — every commissioning endpoint is open to any
 * authenticated user. The 3-person team shares all field-ops surfaces, so a
 * non-owner, non-assigned user can view the checklist and patch any project's
 * items. (Was the T-16-03 owner/assigned-engineer 403 model pre-260525-s8b.)
 */
class OwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_checklist(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $project = Project::factory()->create(['user_id' => $owner->id]);
        InstallProgramme::factory()->create(['project_id' => $project->id]);

        $this->actingAs($stranger)
            ->get("/projects/{$project->id}/commissioning")
            ->assertOk();
    }

    public function test_any_authenticated_user_can_patch_status(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        $item = CommissioningItem::factory()->create(['install_programme_id' => $programme->id]);

        $response = $this->actingAs($stranger)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'status',
            'counters' => [
                'programme' => ['complete', 'total', 'unlocked'],
            ],
        ]);
    }

    public function test_any_authenticated_user_can_patch_any_project_item(): void
    {
        // Two projects / two engineers — proves a user with NO relationship to
        // project B can still patch project B's item under the shared model.
        $engineerA = User::factory()->create();

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
            ->assertOk();
    }
}
