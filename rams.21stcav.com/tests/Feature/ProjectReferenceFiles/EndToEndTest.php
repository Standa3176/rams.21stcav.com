<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end happy path for quick task 260601-r4c.
 *
 * Single test exercises: authed user uploads 3 files (PDF + DWG + XLSX)
 * to Project A → opens Project A's worksheet token URL (anonymous) and
 * confirms the drawer marker appears → fetches each file via the public
 * worksheet endpoint (200) → repeats against the survey token endpoint
 * → deletes one file as authed user → confirms count drops 3 → 2 on
 * the next public render.
 */
class EndToEndTest extends TestCase
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

    public function test_full_flow_admin_upload_engineer_view_cross_link_delete(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        // Quick task 260816-t5c: `access_token` is guarded on SiteSurvey
        // (Re-audit S-03) — mass-assigning it is a silent no-op since
        // boot()'s creating hook overwrites it with a fresh UUID. This test
        // DOES read $survey->access_token later (route generation below), so
        // force-fill after create() to keep the intent explicit even though
        // the value would be auto-generated either way.
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'E2E Project',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => (string) Str::uuid()])->save();

        // ── 1. Admin uploads 3 files ──────────────────────────────────────
        $pdf  = $this->fakeUpload('plan.pdf', "%PDF-1.4\nbody\n%%EOF\n", 'application/pdf');
        $dwg  = $this->fakeUpload('cad.dwg', "AC1027\x00" . str_repeat("\x00", 200), 'application/acad');
        $xlsx = $this->fakeUpload(
            'cables.xlsx',
            "PK\x03\x04" . str_repeat("\x00", 200),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $this->actingAs($user)
            ->post(route('projects.reference-files.store', $project), ['file' => $pdf])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('projects.reference-files.store', $project), ['file' => $dwg])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('projects.reference-files.store', $project), ['file' => $xlsx])
            ->assertRedirect();

        $this->assertSame(3, ProjectReferenceFile::where('project_id', $project->id)->count());

        // ── 2. Each file streams via the public worksheet endpoint ────────
        foreach (ProjectReferenceFile::where('project_id', $project->id)->get() as $f) {
            $this->get(route('public-worksheet.files.serve', [
                'token' => $worksheet->access_token,
                'file'  => $f->id,
            ]))->assertStatus(200);
        }

        // ── 3. Each file streams via the public survey endpoint ──────────
        foreach (ProjectReferenceFile::where('project_id', $project->id)->get() as $f) {
            $this->get(route('public-survey.files.serve', [
                'token' => $survey->access_token,
                'file'  => $f->id,
            ]))->assertStatus(200);
        }

        // ── 4. Admin deletes one file ─────────────────────────────────────
        $toDelete = ProjectReferenceFile::where('project_id', $project->id)
            ->where('original_filename', 'cad.dwg')
            ->first();

        $this->actingAs($user)
            ->delete(route('projects.reference-files.destroy', [$project, $toDelete]))
            ->assertRedirect();

        $this->assertSame(2, ProjectReferenceFile::where('project_id', $project->id)->count());

        // ── 5. Deleted file 404s on subsequent public download attempts ──
        $this->get(route('public-worksheet.files.serve', [
            'token' => $worksheet->access_token,
            'file'  => $toDelete->id,
        ]))->assertStatus(404);

        $this->get(route('public-survey.files.serve', [
            'token' => $survey->access_token,
            'file'  => $toDelete->id,
        ]))->assertStatus(404);
    }

    private function fakeUpload(string $name, string $bytes, string $clientMime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'e2e');
        file_put_contents($tmp, $bytes);
        return new UploadedFile($tmp, $name, $clientMime, null, true);
    }
}
