<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\ProjectReferenceFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin DELETE endpoint coverage for quick task 260601-r4c.
 *
 * scopeBindings() in routes/web.php means a {reference_file} from another
 * project must 404 against the controller — proven explicitly here.
 */
class AdminDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $testRoot = storage_path('framework/testing/documents-' . uniqid());
        if (! is_dir($testRoot)) {
            mkdir($testRoot, 0775, true);
        }
        config(['filesystems.disks.documents' => [
            'driver' => 'local',
            'root'   => $testRoot,
            'throw'  => false,
        ]]);

        $this->user    = User::factory()->create();
        $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.documents.root');
        if (is_string($root) && is_dir($root)) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    public function test_happy_delete_removes_row_and_file_with_redirect(): void
    {
        $row = $this->seedFile('todelete.pdf');
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $row->stored_path);
        $this->assertFileExists($abs);

        $response = $this->actingAs($this->user)
            ->from(route('projects.show', $this->project))
            ->delete(route('projects.reference-files.destroy', [$this->project, $row]));

        $response->assertRedirect(route('projects.show', $this->project));
        $response->assertSessionHas('success');

        $this->assertNull(ProjectReferenceFile::find($row->id));
        $this->assertFileDoesNotExist($abs);
    }

    public function test_delete_with_missing_disk_file_still_removes_row(): void
    {
        $row = $this->seedFile('stale.pdf');
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $row->stored_path);

        // Pre-delete the on-disk file to simulate manual cleanup / drift.
        @unlink($abs);
        $this->assertFileDoesNotExist($abs);

        $response = $this->actingAs($this->user)
            ->delete(route('projects.reference-files.destroy', [$this->project, $row]));

        $response->assertRedirect();
        $this->assertNull(ProjectReferenceFile::find($row->id));
    }

    public function test_cross_project_file_id_returns_404(): void
    {
        // File belongs to projectB; URL claims it belongs to $this->project.
        $projectB = Project::factory()->create();
        $rowB     = $this->seedFile('belongs-to-b.pdf', $projectB);

        $response = $this->actingAs($this->user)
            ->delete(url(
                "/projects/{$this->project->id}/reference-files/{$rowB->id}"
            ));

        $response->assertStatus(404);

        // Row must still be present — destroy was never called.
        $this->assertNotNull(ProjectReferenceFile::find($rowB->id));
    }

    public function test_unauthenticated_delete_redirects_to_login(): void
    {
        $row = $this->seedFile('any.pdf');

        $response = $this->delete(route('projects.reference-files.destroy', [$this->project, $row]));

        $response->assertRedirect(route('login'));
        $this->assertNotNull(ProjectReferenceFile::find($row->id));
    }

    public function test_user_can_delete_file_uploaded_by_another_user(): void
    {
        $otherUser = User::factory()->create();
        $row = $this->seedFile('not-mine.pdf', $this->project, $otherUser);

        $response = $this->actingAs($this->user)
            ->delete(route('projects.reference-files.destroy', [$this->project, $row]));

        $response->assertRedirect();
        $this->assertNull(ProjectReferenceFile::find($row->id));
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function seedFile(string $name, ?Project $project = null, ?User $user = null): ProjectReferenceFile
    {
        $project ??= $this->project;
        $user    ??= $this->user;

        $tmp = tempnam(sys_get_temp_dir(), 'addel');
        file_put_contents($tmp, "%PDF-1.4\nbody\n%%EOF\n");
        $upload = new UploadedFile($tmp, $name, 'application/pdf', null, true);

        return app(ProjectReferenceFileService::class)
            ->store($upload, $project, $user, null);
    }
}
