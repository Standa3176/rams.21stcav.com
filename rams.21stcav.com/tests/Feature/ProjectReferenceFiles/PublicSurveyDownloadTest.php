<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\ProjectReferenceFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public survey download endpoint coverage for quick task 260601-r4c.
 *
 * Includes the LOAD-BEARING SECURITY TEST: the cross-tenant 403 guard
 * (T-r4c-02). Same shape as PublicWorksheetDownloadTest against the
 * survey token path.
 */
class PublicSurveyDownloadTest extends TestCase
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
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($user, $project);
        $file    = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        $response = $this->get(route('public-survey.files.serve', [
            'token' => $survey->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline; filename=', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_happy_path_serves_dwg_as_attachment(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($user, $project);
        $file    = $this->seedFile($project, $user, 'drawing.dwg', 'application/acad', "AC1027\x00" . str_repeat("\x00", 200));

        $response = $this->get(route('public-survey.files.serve', [
            'token' => $survey->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(200);
        $this->assertStringStartsWith('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * LOAD-BEARING SECURITY TEST (T-r4c-02).
     */
    public function test_cross_tenant_guard_returns_403(): void
    {
        $user     = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $surveyA  = $this->makeSurvey($user, $projectA);

        $projectB = Project::factory()->create(['user_id' => $user->id]);
        $fileB    = $this->seedFile($projectB, $user, 'b-secret.pdf', 'application/pdf');

        $response = $this->get(route('public-survey.files.serve', [
            'token' => $surveyA->access_token,
            'file'  => $fileB->id,
        ]));

        $response->assertStatus(403);
    }

    public function test_unknown_token_returns_404(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $file    = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        $response = $this->get(route('public-survey.files.serve', [
            'token' => 'not-a-real-token',
            'file'  => $file->id,
        ]));

        $response->assertStatus(404);
    }

    public function test_unknown_file_id_returns_404(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($user, $project);

        $response = $this->get(route('public-survey.files.serve', [
            'token' => $survey->access_token,
            'file'  => 999999,
        ]));

        $response->assertStatus(404);
    }

    public function test_missing_file_on_disk_returns_404(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($user, $project);
        $file    = $this->seedFile($project, $user, 'plan.pdf', 'application/pdf');

        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $file->stored_path);
        @unlink($abs);

        $response = $this->get(route('public-survey.files.serve', [
            'token' => $survey->access_token,
            'file'  => $file->id,
        ]));

        $response->assertStatus(404);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function makeSurvey(User $user, Project $project): SiteSurvey
    {
        // Quick task 260816-t5c: `access_token` is guarded on SiteSurvey
        // (Re-audit S-03) — mass-assigning it is a silent no-op since
        // boot()'s creating hook overwrites it with a fresh UUID. Every
        // caller of this helper reads ->access_token back for route
        // generation, so force-fill after create() to keep the intent
        // explicit even though the value would be auto-generated either way.
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Test Project',
            'status'       => 'draft',
        ]);

        $survey->forceFill(['access_token' => (string) Str::uuid()])->save();

        return $survey;
    }

    private function seedFile(Project $project, User $user, string $name, string $clientMime, ?string $bytes = null): ProjectReferenceFile
    {
        $bytes ??= "%PDF-1.4\nbody\n%%EOF\n";
        $tmp = tempnam(sys_get_temp_dir(), 'psd');
        file_put_contents($tmp, $bytes);
        $upload = new UploadedFile($tmp, $name, $clientMime, null, true);

        return app(ProjectReferenceFileService::class)->store($upload, $project, $user, null);
    }
}
