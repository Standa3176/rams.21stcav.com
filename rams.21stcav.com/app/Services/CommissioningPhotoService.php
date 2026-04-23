<?php

namespace App\Services;

use App\Models\CommissioningItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CommissioningPhotoService — orchestrates evidence-photo upload for
 * commissioning items (INST-05d).
 *
 * Mirrors the TaskPhotoService shape from Phase 14:
 *   - Delegates HEIC→JPEG conversion (+ passthrough for JPEG/PNG/WebP) to
 *     HeicImageConverter::writeAsJpeg(), which fails loudly if HEIC support
 *     is missing on the host (D-11).
 *   - UUID filenames, never client-supplied, scoped under
 *     commissioning-evidence/{project_id}/{item_id}/{uuid}.jpg on the
 *     private `local` disk.
 *   - Caller is responsible for ownership authorisation. This service trusts
 *     the $item argument is already authorised.
 *
 * The service returns the relative path string rather than writing to the
 * model directly — the caller (controller) is in charge of the Eloquent
 * save so the DB write and file write can be wrapped in one transaction
 * for the W-12 atomic-fail path.
 *
 * @see HeicImageConverter — single dependency for format conversion
 * @see CommissioningItemController — primary consumer
 */
class CommissioningPhotoService
{
    private const BASE_DIR = 'commissioning-evidence';

    public function __construct(
        private readonly HeicImageConverter $converter,
    ) {}

    // =========================================================================
    // STORE
    // =========================================================================

    /**
     * Store an evidence photo for the given commissioning item. Returns the
     * relative path that should be persisted to commissioning_items.evidence_photo_path.
     *
     * Replaces any prior photo on the item (INST-05a — single photo per item).
     */
    public function store(CommissioningItem $item, UploadedFile $file): string
    {
        $item->loadMissing('programme');
        $projectId = (int) $item->programme->project_id;
        $itemId    = (int) $item->id;
        $uuid      = (string) Str::uuid();

        // Always write as .jpg. HeicImageConverter transcodes HEIC → JPEG and
        // passes JPEG/PNG/WebP through untouched; the uniform .jpg extension
        // matches the Phase 14 convention and simplifies the show() route.
        $relative = sprintf('%s/%d/%d/%s.jpg', self::BASE_DIR, $projectId, $itemId, $uuid);
        $absolute = Storage::disk('local')->path($relative);

        // HeicImageConverter::writeAsJpeg() handles mkdir -p internally, so we
        // don't need to pre-create the directory here.
        $this->converter->writeAsJpeg($file, $absolute);

        Log::info('CommissioningPhotoService: photo stored', [
            'item_id'    => $itemId,
            'project_id' => $projectId,
            'path'       => $relative,
            'mime_in'    => $file->getMimeType(),
            'size_bytes' => @filesize($absolute) ?: 0,
        ]);

        // WR-02 — INST-05a single-photo-per-item. Defer the destructive
        // filesystem unlink until AFTER the caller's DB transaction commits.
        // Previously this ran inline, so if the controller's $item->save()
        // threw inside DB::transaction the DB rollback left the column
        // pointing at the old path but the old file was already gone —
        // permanent data loss.
        //
        // DB::afterCommit queues the closure on the ACTIVE transaction if
        // one is open, else runs it immediately. That matches both the
        // atomic failWithEvidence path (transaction wrapper) and the plain
        // storePhoto path (no transaction).
        $old = $item->evidence_photo_path;
        if ($old && $old !== $relative) {
            $oldAbs = Storage::disk('local')->path($old);
            $itemIdForLog = $itemId;
            DB::afterCommit(function () use ($oldAbs, $old, $itemIdForLog) {
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                    Log::info('CommissioningPhotoService: old photo replaced', [
                        'item_id' => $itemIdForLog,
                        'old'     => $old,
                    ]);
                }
            });
        }

        return $relative;
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    /**
     * Remove the stored evidence-photo file (idempotent). Does NOT null the
     * column on the model — the caller is responsible for that so the DB and
     * filesystem can be updated in a single controller-scoped transaction.
     */
    public function delete(CommissioningItem $item): void
    {
        $path = $item->evidence_photo_path;
        if (! $path) {
            return;
        }

        $abs = Storage::disk('local')->path($path);
        if (is_file($abs)) {
            @unlink($abs);
        }

        Log::info('CommissioningPhotoService: photo deleted', [
            'item_id' => $item->id,
            'path'    => $path,
        ]);
    }
}
