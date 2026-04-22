<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * INST-05i — every mutating endpoint must return 422 after a signoff exists.
 * Error class = CommissioningSignoffException::itemsImmutable.
 *
 * Red until Plan 03 ships assertMutable() on every controller action.
 */
class ImmutabilityAfterSignoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_patch_after_signoff_returns_422(): void
    {
        [$user, $item] = $this->scaffoldSignedOffItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ])
            ->assertStatus(422);
    }

    public function test_notes_patch_after_signoff_returns_422(): void
    {
        [$user, $item] = $this->scaffoldSignedOffItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/notes", [
                'notes' => 'cannot edit',
            ])
            ->assertStatus(422);
    }

    public function test_photo_upload_after_signoff_returns_422(): void
    {
        Storage::fake('local');
        [$user, $item] = $this->scaffoldSignedOffItem();

        $file = UploadedFile::fake()->image('nope.jpg');

        $this->actingAs($user)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file])
            ->assertStatus(422);
    }

    public function test_photo_delete_after_signoff_returns_422(): void
    {
        [$user, $item] = $this->scaffoldSignedOffItem([
            'evidence_photo_path' => 'commissioning-evidence/already.jpg',
        ]);

        $this->actingAs($user)
            ->delete("/commissioning-items/{$item->id}/photo")
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: CommissioningItem}
     */
    private function scaffoldSignedOffItem(array $itemOverrides = []): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_COMMISSIONING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_COMPLETE,
        ]);
        $item = CommissioningItem::factory()->create(array_merge([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ], $itemOverrides));

        CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        return [$user, $item];
    }
}
