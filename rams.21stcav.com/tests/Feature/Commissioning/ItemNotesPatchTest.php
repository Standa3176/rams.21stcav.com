<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-05c — PATCH /commissioning-items/{item}/notes persistence + max length.
 * Red until Plan 03 ships the controller + route.
 */
class ItemNotesPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_notes_persists(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/notes", [
                'notes' => 'adjusted levels',
            ])
            ->assertOk();

        $this->assertSame('adjusted levels', $item->fresh()->notes);
    }

    public function test_patch_notes_max_2000_chars(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/notes", [
                'notes' => str_repeat('x', 2001),
            ])
            ->assertStatus(422);
    }

    public function test_patch_notes_null_clears(): void
    {
        [$user, $item] = $this->scaffoldItem(['notes' => 'pre-existing']);

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/notes", [
                'notes' => null,
            ])
            ->assertOk();

        $this->assertNull($item->fresh()->notes);
    }

    /**
     * @return array{0: User, 1: CommissioningItem}
     */
    private function scaffoldItem(array $overrides = []): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);
        $item = CommissioningItem::factory()->create(array_merge([
            'install_programme_id' => $programme->id,
        ], $overrides));

        return [$user, $item];
    }
}
