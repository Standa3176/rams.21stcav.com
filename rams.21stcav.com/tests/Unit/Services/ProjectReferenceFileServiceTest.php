<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\ProjectReferenceFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit coverage for ProjectReferenceFileService (quick task 260601-r4c).
 *
 * Locks: finfo-based MIME allowlist, extension allowlist + deny-list,
 * octet-stream-only-for-DWG/DXF rule, 20 MB cap, filename sanitiser
 * (path traversal + null byte + truncation), idempotent delete().
 */
class ProjectReferenceFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectReferenceFileService $service;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a fresh test-only storage root so we don't litter the dev
        // documents disk with test bytes.
        $testRoot = storage_path('framework/testing/documents-' . uniqid());
        if (! is_dir($testRoot)) {
            mkdir($testRoot, 0775, true);
        }
        config(['filesystems.disks.documents' => [
            'driver' => 'local',
            'root'   => $testRoot,
            'throw'  => false,
        ]]);

        $this->service = app(ProjectReferenceFileService::class);
        $this->user    = User::factory()->create();
        $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of the per-test documents root.
        $root = config('filesystems.disks.documents.root');
        if (is_string($root) && is_dir($root)) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    // ─── Happy paths ───────────────────────────────────────────────────────

    public function test_happy_path_pdf_stores_row_and_file(): void
    {
        // Real-PDF magic header so finfo sniffs application/pdf.
        $pdfBytes = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\nblah\n%%EOF\n";
        $file     = $this->makeUploadedFileWithContent('plan.pdf', $pdfBytes, 'application/pdf');

        $row = $this->service->store($file, $this->project, $this->user, 'Site plan');

        $this->assertNotNull($row->id);
        $this->assertSame('Site plan', $row->label);
        $this->assertSame('plan.pdf', $row->original_filename);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertGreaterThan(0, $row->size_bytes);
        $this->assertSame($this->user->id, $row->uploaded_by_user_id);
        $this->assertStringStartsWith($this->project->id . '/', $row->stored_path);
        // File actually on disk:
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $row->stored_path);
        $this->assertNotNull($abs);
        $this->assertFileExists($abs);
    }

    public function test_happy_path_png_stores(): void
    {
        // UploadedFile::fake()->image() produces a real PNG so finfo sniffs image/png.
        $file = UploadedFile::fake()->image('shot.png', 50, 50);

        $row = $this->service->store($file, $this->project, $this->user, null);

        $this->assertSame('shot.png', $row->original_filename);
        $this->assertStringStartsWith('image/', $row->mime_type);
    }

    public function test_happy_path_dwg_with_octet_stream_accepted(): void
    {
        // DWG binary header (AC1027 = AutoCAD 2013). finfo sniffs this as
        // application/octet-stream, which is explicitly accepted because
        // the extension is dwg.
        $dwgBytes = "AC1027\x00\x00\x00\x00" . str_repeat("\x00", 200);
        $file     = $this->makeUploadedFileWithContent('drawing.dwg', $dwgBytes, 'application/acad');

        $row = $this->service->store($file, $this->project, $this->user, null);
        $this->assertSame('drawing.dwg', $row->original_filename);
    }

    // ─── Rejects ───────────────────────────────────────────────────────────

    public function test_octet_stream_with_zip_extension_rejected(): void
    {
        // Extension allowlist rejects zip first — even if octet-stream MIME passed.
        $file = $this->makeUploadedFileWithContent('archive.zip', "PK\x03\x04junk", 'application/zip');

        $this->expectException(ValidationException::class);
        $this->service->store($file, $this->project, $this->user, null);
    }

    public function test_svg_rejected_by_deny_extension(): void
    {
        // SVG is in DENY_EXTENSIONS — must reject even though image/svg+xml
        // might be allowed somewhere via image/* matchers in other systems.
        $file = $this->makeUploadedFileWithContent('logo.svg', '<svg></svg>', 'image/svg+xml');

        $this->expectException(ValidationException::class);
        $this->service->store($file, $this->project, $this->user, null);
    }

    public function test_html_rejected(): void
    {
        $file = $this->makeUploadedFileWithContent('page.html', '<html></html>', 'text/html');

        $this->expectException(ValidationException::class);
        $this->service->store($file, $this->project, $this->user, null);
    }

    public function test_js_rejected(): void
    {
        $file = $this->makeUploadedFileWithContent('evil.js', 'alert(1)', 'application/javascript');

        $this->expectException(ValidationException::class);
        $this->service->store($file, $this->project, $this->user, null);
    }

    public function test_oversize_pdf_rejected(): void
    {
        // UploadedFile::fake()->create with sizeInKilobytes=25000 = ~25 MB.
        $file = UploadedFile::fake()->create('big.pdf', 25 * 1024, 'application/pdf');

        try {
            $this->service->store($file, $this->project, $this->user, null);
            $this->fail('Expected ValidationException for oversize file.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('20 MB', json_encode($e->errors()));
        }
    }

    // ─── Filename sanitisation ────────────────────────────────────────────

    public function test_path_traversal_filename_sanitised(): void
    {
        $pdfBytes = "%PDF-1.4\nbody\n%%EOF\n";
        $file     = $this->makeUploadedFileWithContent('../../etc/passwd.pdf', $pdfBytes, 'application/pdf');

        $row = $this->service->store($file, $this->project, $this->user, null);

        $this->assertStringNotContainsString('..', $row->stored_path);
        $this->assertStringNotContainsString('/etc/', $row->stored_path);
        // Stored path must still be a project-nested ulid basename
        $this->assertStringStartsWith($this->project->id . '/', $row->stored_path);
    }

    public function test_null_byte_filename_sanitised(): void
    {
        $pdfBytes = "%PDF-1.4\nbody\n%%EOF\n";
        $file     = $this->makeUploadedFileWithContent("evil\x00.pdf", $pdfBytes, 'application/pdf');

        $row = $this->service->store($file, $this->project, $this->user, null);

        $this->assertStringNotContainsString("\x00", $row->stored_path);
    }

    public function test_long_filename_truncated(): void
    {
        $pdfBytes  = "%PDF-1.4\nbody\n%%EOF\n";
        $longBase  = str_repeat('x', 300);
        $file      = $this->makeUploadedFileWithContent($longBase . '.pdf', $pdfBytes, 'application/pdf');

        $row = $this->service->store($file, $this->project, $this->user, null);

        // stored_path = "{project_id}/{ulid}-{sanitised basename up to 100}.pdf"
        // ulid is 26 chars + dash + max 100 chars + ".pdf" = ~131 plus prefix.
        $basename = basename($row->stored_path);
        $this->assertLessThanOrEqual(140, strlen($basename));
        $this->assertStringEndsWith('.pdf', $basename);
    }

    public function test_sanitiseFilename_is_idempotent(): void
    {
        $svc = $this->service;
        $a = $svc->sanitiseFilename('../../etc/passwd.pdf');
        $b = $svc->sanitiseFilename($a);
        $this->assertSame($a, $b);
    }

    // ─── delete() ──────────────────────────────────────────────────────────

    public function test_delete_removes_row_and_file_and_is_idempotent(): void
    {
        $pdfBytes = "%PDF-1.4\nbody\n%%EOF\n";
        $file     = $this->makeUploadedFileWithContent('todelete.pdf', $pdfBytes, 'application/pdf');

        $row = $this->service->store($file, $this->project, $this->user, null);
        $abs = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $row->stored_path);
        $this->assertFileExists($abs);

        $this->service->delete($row);

        $this->assertNull($row->fresh());
        $this->assertFileDoesNotExist($abs);

        // Second call must not throw.
        $this->service->delete($row);
        $this->assertTrue(true);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Build an UploadedFile whose underlying temp file actually contains
     * the bytes we want — necessary because finfo sniffs the bytes, not
     * the client-supplied MIME parameter. UploadedFile::fake()->create()
     * only writes zero-padding which doesn't trigger format detection.
     */
    private function makeUploadedFileWithContent(string $name, string $bytes, string $clientMime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'prf');
        file_put_contents($tmp, $bytes);

        // test=true means the constructor skips the is_uploaded_file()
        // check, allowing the file to be ->move()d in test context.
        return new UploadedFile($tmp, $name, $clientMime, null, true);
    }
}
