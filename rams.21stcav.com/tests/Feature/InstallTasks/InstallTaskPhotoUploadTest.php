<?php

namespace Tests\Feature\InstallTasks;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\InstallTaskPhoto;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * INST-03d (upload) + INST-03e (HEIC→JPEG) + D-11 (fail loudly) + D-12 (caption).
 */
class InstallTaskPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_jpeg_upload_stores_file_and_creates_row(): void
    {
        Storage::fake('local');
        [$user, $task] = $this->scaffold();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'site.jpg',
            'image/jpeg',
            null,
            true, // test mode
        );

        $response = $this->actingAs($user)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'filename', 'original_name', 'url']);
        $this->assertDatabaseCount('install_task_photos', 1);

        $photo = InstallTaskPhoto::first();
        Storage::disk('local')->assertExists($photo->filename);
    }

    public function test_heic_converts_to_jpeg(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded; HEIC conversion cannot be verified.');
        }

        Storage::fake('local');
        [$user, $task] = $this->scaffold();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.heic'),
            'IMG_0001.heic',
            'image/heic',
            null,
            true,
        );

        $response = $this->actingAs($user)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertCreated();

        $photo = InstallTaskPhoto::first();
        $this->assertNotNull($photo);
        $this->assertSame('image/jpeg', $photo->mime_type, 'mime_type must flip to image/jpeg');
        $this->assertStringEndsWith('.jpg', $photo->filename, 'filename must end in .jpg after conversion');
        Storage::disk('local')->assertExists($photo->filename);
    }

    public function test_oversized_upload_is_rejected(): void
    {
        [$user, $task] = $this->scaffold();

        $file = UploadedFile::fake()->create('big.jpg', 25 * 1024, 'image/jpeg'); // 25 MB

        $response = $this->actingAs($user)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertStatus(422);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        [$user, $task] = $this->scaffold();

        $file = UploadedFile::fake()->create('bad.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertStatus(422);
    }

    public function test_caption_update(): void
    {
        // D-12 caption saved on blur via PATCH
        [$user, $task, $photo] = $this->setupWithPhoto();

        $response = $this->actingAs($user)->patchJson(
            "/install-task-photos/{$photo->id}",
            ['caption' => 'Rack after tidy'],
        );

        $response->assertOk();
        $this->assertSame('Rack after tidy', $photo->fresh()->caption);
    }

    public function test_unrelated_user_cannot_upload(): void
    {
        [, $task] = $this->scaffold();
        $stranger = User::factory()->create();

        $file = UploadedFile::fake()->image('sneaky.jpg');

        $response = $this->actingAs($stranger)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertForbidden();
    }

    public function test_unrelated_user_cannot_view_photo(): void
    {
        [, , $photo] = $this->setupWithPhoto();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->get("/install-task-photos/{$photo->id}");

        $response->assertForbidden();
    }

    public function test_original_filename_with_traversal_is_sanitised(): void
    {
        Storage::fake('local');
        [$user, $task] = $this->scaffold();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            '../../etc/passwd.jpg',
            'image/jpeg',
            null,
            true,
        );

        $response = $this->actingAs($user)->post(
            "/install-tasks/{$task->id}/photos",
            ['photo' => $file],
        );

        $response->assertCreated();
        $photo = InstallTaskPhoto::first();
        // Stored filename must NOT contain the traversal sequence
        $this->assertStringNotContainsString('..', $photo->filename);
        $this->assertStringNotContainsString('/etc/', $photo->filename);
    }

    private function scaffold(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        $task = InstallTask::factory()->create(['install_programme_id' => $programme->id]);

        return [$owner, $task];
    }

    private function setupWithPhoto(): array
    {
        [$user, $task] = $this->scaffold();
        $photo = InstallTaskPhoto::factory()->create(['install_task_id' => $task->id]);

        return [$user, $task, $photo];
    }
}
