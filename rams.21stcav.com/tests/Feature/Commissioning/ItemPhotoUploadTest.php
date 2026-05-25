<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * INST-05d — per-item evidence photo upload. Mirrors Phase 14
 * InstallTaskPhotoUploadTest verbatim; HEIC conversion + size cap + mime
 * validation + ownership guard.
 *
 * Red until Plan 03 ships CommissioningItemController::storePhoto + route.
 */
class ItemPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_jpeg_succeeds(): void
    {
        Storage::fake('local');
        [$user, $item] = $this->scaffoldItem();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'ev.jpg',
            'image/jpeg',
            null,
            true,
        );

        $response = $this->actingAs($user)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file]);

        $response->assertCreated();
        $this->assertNotNull($item->fresh()->evidence_photo_path);
    }

    public function test_upload_heic_converts_to_jpeg(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded; HEIC conversion cannot be verified.');
        }

        Storage::fake('local');
        [$user, $item] = $this->scaffoldItem();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.heic'),
            'IMG_0002.heic',
            'image/heic',
            null,
            true,
        );

        $response = $this->actingAs($user)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file]);

        $response->assertCreated();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh->evidence_photo_path);
        $this->assertStringEndsWith('.jpg', $fresh->evidence_photo_path);
    }

    public function test_upload_unsupported_mime_returns_422(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $file = UploadedFile::fake()->create('bad.txt', 10, 'text/plain');

        $this->actingAs($user)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file])
            ->assertStatus(422);
    }

    public function test_upload_oversize_returns_422(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $file = UploadedFile::fake()->create('huge.jpg', 21 * 1024, 'image/jpeg'); // 21MB > 20MB cap

        $this->actingAs($user)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file])
            ->assertStatus(422);
    }

    public function test_any_authenticated_user_can_upload_photo(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-assigned user may upload
        // commissioning evidence photos — the same relaxation applied across the
        // field-ops cluster. (Was the owner/assigned-engineer 403 model pre-260525-s8b.)
        Storage::fake('local');
        [, $item] = $this->scaffoldItem();
        $stranger = User::factory()->create();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'shared-evidence.jpg',
            'image/jpeg',
            null,
            true,
        );

        $this->actingAs($stranger)
            ->post("/commissioning-items/{$item->id}/photo", ['photo' => $file])
            ->assertCreated();

        $this->assertNotNull($item->fresh()->evidence_photo_path);
    }

    public function test_delete_photo_clears_column_and_removes_file(): void
    {
        Storage::fake('local');
        [$user, $item] = $this->scaffoldItem();

        // Prime an existing photo
        Storage::disk('local')->put('commissioning-evidence/existing.jpg', 'x');
        $item->update(['evidence_photo_path' => 'commissioning-evidence/existing.jpg']);

        $response = $this->actingAs($user)
            ->delete("/commissioning-items/{$item->id}/photo");

        $response->assertNoContent();
        $this->assertNull($item->fresh()->evidence_photo_path);
        Storage::disk('local')->assertMissing('commissioning-evidence/existing.jpg');
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
