<?php

namespace Tests\Feature\Rams;

use App\Jobs\ExtractRamsDraftJob;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature smoke test: RAMS creation via the quote-upload path (POST /rams/upload).
 *
 * The upload controller:
 *   1. Resolves or creates a Project
 *   2. Creates a placeholder RamsDocument (status = uploaded, linked to project)
 *   3. Dispatches ExtractRamsDraftJob for Phase A extraction
 *   4. Redirects to the resolved project's show page
 *
 * No generation happens at upload time — that is deferred until the user
 * reviews and approves the extracted data.
 *
 * Bus::fake() is used so the job is captured without executing, letting us
 * assert the record state immediately after the HTTP response.
 */
class QuoteUploadRamsCreationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf');
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_quote_upload_creates_record_and_redirects_to_project(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $this->fakePdf(),
            'project_ref'  => 'SMOKE-001',
            'client_name'  => 'Smoke Test Client',
            'site_address' => '1 Smoke Test Street',
        ]);

        // Upload resolves/creates a project and redirects there.
        $project = Project::where('ref', 'SMOKE-001')->firstOrFail();
        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('rams_documents', 1);
    }

    public function test_quote_upload_sets_status_uploaded(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $this->fakePdf(),
            'project_ref'  => 'UPLOAD-STATUS-001',
            'project_name' => 'Upload Status Test',
        ]);

        $record = RamsDocument::where('project_ref', 'UPLOAD-STATUS-001')->first();

        $this->assertNotNull($record);
        $this->assertSame(RamsDocument::STATUS_UPLOADED, $record->status);
    }

    public function test_quote_upload_populates_filename_with_relative_path(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $this->fakePdf(),
            'project_ref' => 'UPLOAD-FILENAME-001',
        ]);

        $record = RamsDocument::where('project_ref', 'UPLOAD-FILENAME-001')->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record->filename, 'filename must not be null after upload.');
        $this->assertNotEmpty($record->filename, 'filename must not be empty after upload.');

        // filename is a relative storage path — use Storage::path() to resolve to absolute
        $this->assertStringContainsString('rams/uploads', $record->filename);
        $this->assertStringEndsWith('.pdf', $record->filename);
    }

    public function test_quote_upload_dispatches_extract_draft_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $this->fakePdf(),
            'project_ref' => 'UPLOAD-JOB-001',
        ]);

        $record = RamsDocument::where('project_ref', 'UPLOAD-JOB-001')->first();

        $this->assertNotNull($record);

        Bus::assertDispatched(ExtractRamsDraftJob::class, function ($job) use ($record) {
            return $job->ramsDocumentId === $record->id;
        });
    }

    public function test_quote_upload_creates_rams_with_project_id(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $this->fakePdf(),
            'project_ref'  => 'UPLOAD-PROJ-001',
            'client_name'  => 'Project Link Client',
            'site_address' => '1 Project Link Street',
        ]);

        $record = RamsDocument::where('project_ref', 'UPLOAD-PROJ-001')->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record->project_id, 'RAMS document must have project_id after upload.');
        $this->assertGreaterThan(0, $record->project_id);
    }

    public function test_quote_upload_persists_record_with_correct_user(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $this->fakePdf(),
            'project_ref'  => 'UPLOAD-USER-001',
            'project_name' => 'Upload User Test',
            'client_name'  => 'Test Client Ltd',
        ]);

        $this->assertDatabaseHas('rams_documents', [
            'user_id'     => $user->id,
            'project_ref' => 'UPLOAD-USER-001',
            'client_name' => 'Test Client Ltd',
            'status'      => RamsDocument::STATUS_UPLOADED,
        ]);
    }

    public function test_quote_upload_extracted_data_is_null_until_job_runs(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $this->fakePdf(),
            'project_ref' => 'UPLOAD-NULL-001',
        ]);

        $record = RamsDocument::where('project_ref', 'UPLOAD-NULL-001')->first();

        $this->assertNotNull($record);
        $this->assertNull($record->extracted_data, 'extracted_data must be null until ExtractRamsDraftJob runs.');
        $this->assertNull($record->reviewed_data,  'reviewed_data must be null until user reviews.');
        $this->assertNull($record->generated_data, 'generated_data must be null until Phase B completes.');
    }

    public function test_upload_fails_gracefully_when_no_pdf_supplied(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('rams.upload.store'), [
            'client_name' => 'Test Client',
        ]);

        $response->assertSessionHasErrors(['quote_pdf']);
        $this->assertDatabaseCount('rams_documents', 0);
    }

    public function test_unauthenticated_upload_is_redirected_to_login(): void
    {
        $response = $this->post(route('rams.upload.store'), [
            'quote_pdf' => $this->fakePdf(),
        ]);

        $response->assertRedirectToRoute('login');
    }

    public function test_uploaded_pdf_is_stored_on_disk(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $this->fakePdf(),
            'project_ref' => 'UPLOAD-DISK-001',
        ]);

        // Verify at least one PDF was written under rams/uploads/
        $files = Storage::disk('local')->files('rams/uploads');
        $this->assertNotEmpty($files, 'No PDF file was stored in rams/uploads/.');

        $pdfs = array_filter($files, fn ($f) => str_ends_with($f, '.pdf'));
        $this->assertNotEmpty($pdfs, 'No .pdf file found in rams/uploads/.');
    }

    public function test_quote_upload_creates_project_quote_version_record(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $this->fakePdf(),
            'project_ref'  => 'UPLOAD-VER-001',
            'client_name'  => 'Version Client',
            'site_address' => '1 Version Street',
        ]);

        $project = Project::where('ref', 'UPLOAD-VER-001')->firstOrFail();

        $this->assertDatabaseHas('project_quotes', [
            'project_id'      => $project->id,
            'version_number'  => 1,
            'uploaded_by'     => $user->id,
            'quote_reference' => 'UPLOAD-VER-001',
        ]);
    }
}
