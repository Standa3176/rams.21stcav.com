<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DocumentArtifactStorage;
use App\Services\ProjectReferenceFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Public worksheet download endpoint coverage for quick task 260601-r4c.
 *
 * Includes the LOAD-BEARING SECURITY TEST: the cross-tenant 403 guard
 * (T-r4c-01). A leaked Project-A worksheet token must NOT be usable to
 * enumerate Project-B's reference files.
 */
class PublicWorksheetDownloadTest extends TestCase
{
    use RefreshDatabase;

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
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.documents.root');
        if (is_string($root) && is_dir($root)) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    public function test_happy_path_serves_pdf_inline(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $file      = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheet->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline; filename=', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_happy_path_serves_dwg_as_attachment(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $file      = $this->seedFile($project, $user, 'drawing.dwg', 'application/acad', "AC1027\x00" . str_repeat("\x00", 200));

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheet->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(200);
        $this->assertStringStartsWith('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * LOAD-BEARING SECURITY TEST (T-r4c-01).
     *
     * A leaked Project-A worksheet token used to look up a file_id that
     * belongs to Project B must return 403 — BEFORE any storage I/O.
     */
    public function test_cross_tenant_guard_returns_403(): void
    {
        $user       = User::factory()->create();
        $projectA   = Project::factory()->create(['user_id' => $user->id]);
        $worksheetA = Worksheet::factory()->create(['project_id' => $projectA->id]);

        $projectB = Project::factory()->create(['user_id' => $user->id]);
        $fileB    = $this->seedFile($projectB, $user, 'b-secret.pdf', 'application/pdf');

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheetA->access_token,
            'file'  => $fileB->id,
        ]));

        $response->assertStatus(403);
    }

    public function test_unknown_token_returns_404(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $file    = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => 'not-a-real-token',
            'file'  => $file->id,
        ]));

        $response->assertStatus(404);
    }

    public function test_unknown_file_id_returns_404(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheet->access_token,
            'file'  => 999999,
        ]));

        $response->assertStatus(404);
    }

    public function test_missing_file_on_disk_returns_404(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $file      = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        // Delete the file from disk but leave the row
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $file->stored_path);
        @unlink($abs);

        $response = $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheet->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(404);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function seedFile(Project $project, User $user, string $name, string $clientMime, ?string $bytes = null): ProjectReferenceFile
    {
        $bytes ??= "%PDF-1.4\nbody\n%%EOF\n";
        $tmp = tempnam(sys_get_temp_dir(), 'pwd');
        file_put_contents($tmp, $bytes);
        $upload = new UploadedFile($tmp, $name, $clientMime, null, true);

        return app(ProjectReferenceFileService::class)->store($upload, $project, $user, null);
    }
}
