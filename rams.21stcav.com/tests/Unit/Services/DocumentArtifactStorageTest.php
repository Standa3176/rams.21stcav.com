<?php

namespace Tests\Unit\Services;

use App\Services\DocumentArtifactStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Contract tests for DocumentArtifactStorage — the H-07 canonical path helper.
 *
 * Uses Storage::fake('documents') to isolate the new unified disk. The legacy
 * fallback path is tested by writing a synthetic file to the real storage_path
 * and cleaning it up in tearDown, because Storage::fake() does not intercept
 * raw storage_path() reads.
 */
class DocumentArtifactStorageTest extends TestCase
{
    private DocumentArtifactStorage $svc;
    private array $legacyCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentArtifactStorage::DISK);
        $this->svc = new DocumentArtifactStorage();
    }

    protected function tearDown(): void
    {
        foreach ($this->legacyCleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
            $dir = dirname($path);
            // Only rmdir if empty AND we created it (best-effort).
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function test_write_path_lands_in_documents_disk_subdirectory(): void
    {
        $path = $this->svc->writePath(DocumentArtifactStorage::TYPE_RAMS, 'test.docx');

        $this->assertStringContainsString('documents', $path);
        $this->assertStringContainsString(DocumentArtifactStorage::TYPE_RAMS, $path);
        $this->assertStringEndsWith('test.docx', $path);
    }

    public function test_read_path_returns_null_when_file_missing_in_both_locations(): void
    {
        $this->assertNull(
            $this->svc->readPath(DocumentArtifactStorage::TYPE_WORKSHEET, 'does-not-exist.docx')
        );
    }

    public function test_read_path_prefers_new_location_over_legacy(): void
    {
        // Write to the new disk
        Storage::disk(DocumentArtifactStorage::DISK)
            ->put(DocumentArtifactStorage::TYPE_OM . '/preferred.docx', 'NEW');

        $found = $this->svc->readPath(DocumentArtifactStorage::TYPE_OM, 'preferred.docx');

        $this->assertNotNull($found);
        $this->assertSame('NEW', file_get_contents($found));
        // The resolved path must be under the faked `documents` disk root.
        $this->assertStringContainsString('documents', $found);
    }

    public function test_read_path_falls_back_to_legacy_location(): void
    {
        $legacyPath = storage_path('app/rams/legacy.docx');
        @mkdir(dirname($legacyPath), 0777, true);
        file_put_contents($legacyPath, 'LEGACY');
        $this->legacyCleanup[] = $legacyPath;

        $found = $this->svc->readPath(DocumentArtifactStorage::TYPE_RAMS, 'legacy.docx');

        $this->assertNotNull($found);
        $this->assertSame('LEGACY', file_get_contents($found));
        // Normalise path separators so the assertion works on Windows and POSIX.
        $this->assertStringContainsString('app/rams', str_replace('\\', '/', $found));
    }

    public function test_exists_returns_true_when_only_legacy_file_present(): void
    {
        $legacyPath = storage_path('app/private/cable-schedules/legacy.xlsx');
        @mkdir(dirname($legacyPath), 0777, true);
        file_put_contents($legacyPath, 'x');
        $this->legacyCleanup[] = $legacyPath;

        $this->assertTrue(
            $this->svc->exists(DocumentArtifactStorage::TYPE_CABLE, 'legacy.xlsx')
        );
    }

    public function test_delete_removes_both_new_and_legacy_copies(): void
    {
        // New location
        Storage::disk(DocumentArtifactStorage::DISK)
            ->put(DocumentArtifactStorage::TYPE_WORKSHEET . '/both.docx', 'NEW');

        // Legacy location
        $legacyPath = storage_path('app/private/worksheets/both.docx');
        @mkdir(dirname($legacyPath), 0777, true);
        file_put_contents($legacyPath, 'LEGACY');
        $this->legacyCleanup[] = $legacyPath;

        $this->svc->delete(DocumentArtifactStorage::TYPE_WORKSHEET, 'both.docx');

        $this->assertFalse(
            Storage::disk(DocumentArtifactStorage::DISK)
                ->exists(DocumentArtifactStorage::TYPE_WORKSHEET . '/both.docx')
        );
        $this->assertFalse(is_file($legacyPath));
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->writePath('not-a-real-type', 'x.docx');
    }

    public function test_types_returns_the_full_registry(): void
    {
        // Historical name was "test_types_returns_all_four" — kept getting
        // stale as the registry grew (4 -> 5 with TYPE_SNAGGING, now 8 with
        // TYPE_DRAWING / TYPE_SURVEY / TYPE_REFERENCE) because only the
        // fixed list was updated, not the method name. Renamed so it no
        // longer lies about the count.
        //
        // IMPORTANT: whenever a new TYPE_* constant is added to
        // DocumentArtifactStorage, it MUST be appended here in registration
        // order. The dedicated snagging-specific test below
        // (test_types_array_includes_snagging) remains the authoritative
        // "must contain" guard for that one type.
        $this->assertSame(
            [
                DocumentArtifactStorage::TYPE_RAMS,
                DocumentArtifactStorage::TYPE_OM,
                DocumentArtifactStorage::TYPE_WORKSHEET,
                DocumentArtifactStorage::TYPE_CABLE,
                DocumentArtifactStorage::TYPE_SNAGGING,
                DocumentArtifactStorage::TYPE_DRAWING,
                DocumentArtifactStorage::TYPE_SURVEY,
                DocumentArtifactStorage::TYPE_REFERENCE,
            ],
            $this->svc->types()
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Phase 16 — TYPE_SNAGGING extension (INST-05g + H-07 convention)
    //
    // These three tests are red until Plan 16-02 adds the TYPE_SNAGGING
    // constant + types() entry AND keeps TYPE_SNAGGING OUT of LEGACY_ROOTS
    // (no pre-H-07 snagging history exists; a fake legacy path would
    // mask real missing-file bugs — B-02 guard).
    // ────────────────────────────────────────────────────────────────────

    public function test_type_snagging_writes_and_reads(): void
    {
        $this->assertSame(
            'snagging',
            DocumentArtifactStorage::TYPE_SNAGGING,
            'TYPE_SNAGGING constant must equal "snagging" per H-07 convention.',
        );

        $filename = 'snagging_programme_1_20260422_120000_final.pdf';
        $writePath = $this->svc->writePath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);

        file_put_contents($writePath, 'PDF-BYTES');

        $readPath = $this->svc->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);

        $this->assertNotNull($readPath);
        $this->assertSame('PDF-BYTES', file_get_contents($readPath));
        $this->assertStringContainsString('snagging', str_replace('\\', '/', $readPath));
    }

    public function test_types_array_includes_snagging(): void
    {
        $this->assertContains(
            DocumentArtifactStorage::TYPE_SNAGGING,
            $this->svc->types(),
            'types() must include TYPE_SNAGGING after Plan 16-02.',
        );
    }

    public function test_type_snagging_read_path_returns_null_without_legacy_fallback(): void
    {
        // B-02 — TYPE_SNAGGING is NOT in LEGACY_ROOTS. Even if we write a
        // "legacy"-style file to storage/app/private/snagging/, readPath must
        // still return null — there is no legacy fallback path for snagging.
        $legacyStyle = storage_path('app/private/snagging/' . 'should-not-be-found.pdf');
        @mkdir(dirname($legacyStyle), 0777, true);
        file_put_contents($legacyStyle, 'LEGACY-NOT-ACCEPTED');
        $this->legacyCleanup[] = $legacyStyle;

        $found = $this->svc->readPath(DocumentArtifactStorage::TYPE_SNAGGING, 'should-not-be-found.pdf');

        $this->assertNull(
            $found,
            'TYPE_SNAGGING must NOT have a legacy fallback — readPath must return null when file missing from documents disk.',
        );
    }
}
