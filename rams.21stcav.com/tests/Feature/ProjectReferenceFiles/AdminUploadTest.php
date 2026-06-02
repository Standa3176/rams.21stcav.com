<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin (authed) upload endpoint coverage for quick task 260601-r4c.
 */
class AdminUploadTest extends TestCase
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

    public function test_happy_upload_pdf_redirects_with_flash_and_persists_row_and_file(): void
    {
        $pdf = $this->fakePdfFile('plan.pdf');

        $response = $this->actingAs($this->user)
            ->from(route('projects.show', $this->project))
            ->post(route('projects.reference-files.store', $this->project), [
                'file'  => $pdf,
                'label' => 'Floor plan',
            ]);

        $response->assertRedirect(route('projects.show', $this->project));
        $response->assertSessionHas('success');

        $this->assertSame(1, ProjectReferenceFile::where('project_id', $this->project->id)->count());
        $row = ProjectReferenceFile::where('project_id', $this->project->id)->first();
        $this->assertSame('plan.pdf', $row->original_filename);
        $this->assertSame('Floor plan', $row->label);

        // File on disk
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $row->stored_path);
        $this->assertFileExists($abs);
    }

    public function test_svg_upload_rejected_with_no_row_and_no_file(): void
    {
        $svg = $this->fakeUpload('logo.svg', '<svg></svg>', 'image/svg+xml');

        $response = $this->actingAs($this->user)
            ->from(route('projects.show', $this->project))
            ->post(route('projects.reference-files.store', $this->project), [
                'file' => $svg,
            ]);

        $response->assertRedirect(route('projects.show', $this->project));
        $response->assertSessionHasErrors(['file']);

        $this->assertSame(0, ProjectReferenceFile::where('project_id', $this->project->id)->count());
    }

    public function test_oversize_upload_rejected(): void
    {
        // 25 MB
        $big = UploadedFile::fake()->create('huge.pdf', 25 * 1024, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->from(route('projects.show', $this->project))
            ->post(route('projects.reference-files.store', $this->project), [
                'file' => $big,
            ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertSame(0, ProjectReferenceFile::where('project_id', $this->project->id)->count());
    }

    public function test_unauthenticated_upload_redirects_to_login(): void
    {
        $pdf = $this->fakePdfFile('plan.pdf');

        $response = $this->post(route('projects.reference-files.store', $this->project), [
            'file' => $pdf,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, ProjectReferenceFile::where('project_id', $this->project->id)->count());
    }

    public function test_missing_file_returns_validation_error(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('projects.show', $this->project))
            ->post(route('projects.reference-files.store', $this->project), [
                'label' => 'no file',
            ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertSame(0, ProjectReferenceFile::where('project_id', $this->project->id)->count());
    }

    public function test_label_persisted_when_supplied(): void
    {
        $pdf = $this->fakePdfFile('docs.pdf');

        $this->actingAs($this->user)
            ->post(route('projects.reference-files.store', $this->project), [
                'file'  => $pdf,
                'label' => 'Cable schedule v3',
            ]);

        $row = ProjectReferenceFile::where('project_id', $this->project->id)->first();
        $this->assertSame('Cable schedule v3', $row->label);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function fakePdfFile(string $name): UploadedFile
    {
        return $this->fakeUpload($name, "%PDF-1.4\nbody\n%%EOF\n", 'application/pdf');
    }

    private function fakeUpload(string $name, string $bytes, string $clientMime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adup');
        file_put_contents($tmp, $bytes);
        return new UploadedFile($tmp, $name, $clientMime, null, true);
    }
}
