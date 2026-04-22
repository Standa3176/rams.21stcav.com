<?php

namespace App\Http\Controllers;

use App\Exceptions\CommissioningSignoffException;
use App\Http\Requests\UpdateCommissioningItemNotesRequest;
use App\Http\Requests\UpdateCommissioningItemStatusRequest;
use App\Models\CommissioningItem;
use App\Services\CommissioningPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CommissioningItemController — per-item AJAX endpoints (INST-05c / INST-05d).
 *
 * Every mutating action calls assertMutable() first (INST-05i enforcement)
 * and authoriseEdit() which mirrors the canonical Phase 14 ownership guard
 * with the engineer-assigned-to-programme extension.
 *
 * Endpoints
 *   PATCH /commissioning-items/{item}/status              → updateStatus
 *   PATCH /commissioning-items/{item}/notes               → updateNotes
 *   POST  /commissioning-items/{item}/photo               → storePhoto
 *   DELETE /commissioning-items/{item}/photo              → destroyPhoto
 *   GET   /commissioning-items/{item}/photo               → show (B-03 stream)
 *   POST  /commissioning-items/{item}/fail-with-evidence  → failWithEvidence (W-12)
 *
 * D-14 photo-on-fail guard: updateStatus refuses status=fail without both a
 * non-empty note AND an existing evidence_photo_path. For the two-step
 * (photo-first, then status) flow this keeps a pending item from turning
 * fail without both pieces of evidence. The W-12 atomic endpoint
 * (failWithEvidence) lets the client post everything together.
 *
 * @see CommissioningPhotoService — delegated photo storage
 * @see CommissioningSignoffException — thrown by assertMutable()
 */
class CommissioningItemController extends Controller
{
    public function __construct(
        private readonly CommissioningPhotoService $photoService,
    ) {}

    // =========================================================================
    // STATUS PATCH (INST-05c + D-14 + INST-05i audit fields)
    // =========================================================================

    /**
     * PATCH /commissioning-items/{item}/status
     *
     * Body: { status: pending|pass|fail|na, note?: string }
     * Response 200: { id, status, notes, signed_off_by, signed_off_at,
     *                 counters: { programme: { complete, total, unlocked } } }
     */
    public function updateStatus(UpdateCommissioningItemStatusRequest $request, CommissioningItem $item): JsonResponse
    {
        $this->authoriseEdit($item);

        try {
            $this->assertMutable($item);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $request->string('status')->toString();
        $note   = $request->input('note');

        // D-14 — fail requires photo + note server-side. The FormRequest
        // doesn't know about $item state so this guard runs here rather than
        // in rules().
        if ($status === CommissioningItem::STATUS_FAIL) {
            if (! is_string($note) || trim($note) === '') {
                return response()->json(['message' => 'A fail status requires a note.'], 422);
            }
            if (! $item->evidence_photo_path) {
                return response()->json(['message' => 'A fail status requires an evidence photo.'], 422);
            }

            // W-10: append the fail reason rather than overwriting. Engineers
            // often pre-type context notes before hitting Fail; clobbering
            // them loses that context. Separator uses a distinct marker so
            // the audit trail is readable.
            $item->notes = trim((string) $item->notes) === ''
                ? $note
                : $item->notes . "\n\n[Fail reason] " . $note;
        }

        $item->status = $status;

        $terminalStatuses = [
            CommissioningItem::STATUS_PASS,
            CommissioningItem::STATUS_FAIL,
            CommissioningItem::STATUS_NA,
        ];
        if (in_array($status, $terminalStatuses, true)) {
            $item->signed_off_by = auth()->user()->name;
            $item->signed_off_at = now();
        } else {
            // Reset-to-pending clears the audit columns so a subsequent
            // re-sign records a genuine new timestamp rather than echoing
            // the stale one.
            $item->signed_off_by = null;
            $item->signed_off_at = null;
        }

        $item->save();

        Log::info('CommissioningItemController: status updated', [
            'item_id' => $item->id,
            'status'  => $status,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'id'            => $item->id,
            'status'        => $item->status,
            'notes'         => $item->notes,
            'signed_off_by' => $item->signed_off_by,
            'signed_off_at' => optional($item->signed_off_at)->toIso8601String(),
            'counters'      => $this->programmeCounters($item),
        ]);
    }

    // =========================================================================
    // FAIL WITH EVIDENCE (W-12 atomic path)
    // =========================================================================

    /**
     * POST /commissioning-items/{item}/fail-with-evidence
     *
     * Multipart body: photo (file) + note (string). Writes the photo + sets
     * notes + transitions status=fail + records audit columns in a single
     * DB::transaction so an interrupted client request never leaves an
     * orphan photo on a still-pending item.
     */
    public function failWithEvidence(
        Request $request,
        CommissioningItem $item,
    ): JsonResponse {
        $this->authoriseEdit($item);

        try {
            $this->assertMutable($item);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Same manual-validator pattern as storePhoto(): the Alpine factory
        // posts multipart/form-data, so a FormRequest redirect on failure
        // would break the fetch() consumer. Validate + return 422 JSON
        // unconditionally.
        $validator = Validator::make($request->all(), [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/octet-stream',
                'max:20480',
            ],
            'note'  => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        $note = $request->string('note')->toString();
        if (trim($note) === '') {
            return response()->json(['message' => 'A fail status requires a note.'], 422);
        }

        $uploadedPath = null;
        try {
            $uploadedPath = DB::transaction(function () use ($request, $item, $note) {
                $path = $this->photoService->store($item, $request->file('photo'));

                $item->evidence_photo_path = $path;
                $item->notes               = $note;
                $item->status              = CommissioningItem::STATUS_FAIL;
                $item->signed_off_by       = auth()->user()->name;
                $item->signed_off_at       = now();
                $item->save();

                return $path;
            });
        } catch (\Throwable $e) {
            // Best-effort cleanup — if store() wrote the file but the DB save
            // failed inside the transaction, drop the orphan now. The model
            // is not reloaded from DB, so we set evidence_photo_path on the
            // in-memory instance so delete() finds the orphan file.
            if ($uploadedPath !== null) {
                $item->evidence_photo_path = $uploadedPath;
                $this->photoService->delete($item);
                $item->evidence_photo_path = null;
            }
            Log::error('CommissioningItemController: failWithEvidence failed', [
                'item_id' => $item->id,
                'user_id' => auth()->id(),
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('CommissioningItemController: item failed with evidence', [
            'item_id' => $item->id,
            'user_id' => auth()->id(),
            'path'    => $uploadedPath,
        ]);

        return response()->json([
            'id'                  => $item->id,
            'status'              => $item->status,
            'notes'               => $item->notes,
            'evidence_photo_path' => $item->evidence_photo_path,
            'photo_url'           => route('commissioning-items.photo.show', $item),
            'signed_off_by'       => $item->signed_off_by,
            'signed_off_at'       => optional($item->signed_off_at)->toIso8601String(),
            'counters'            => $this->programmeCounters($item),
        ]);
    }

    // =========================================================================
    // NOTES PATCH (INST-05c)
    // =========================================================================

    /**
     * PATCH /commissioning-items/{item}/notes
     *
     * Body: { notes: string|null, max 2000 chars }
     * Response 200: { id, notes }
     */
    public function updateNotes(UpdateCommissioningItemNotesRequest $request, CommissioningItem $item): JsonResponse
    {
        $this->authoriseEdit($item);

        try {
            $this->assertMutable($item);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $item->notes = $request->input('notes');
        $item->save();

        return response()->json([
            'id'    => $item->id,
            'notes' => $item->notes,
        ]);
    }

    // =========================================================================
    // PHOTO UPLOAD (INST-05d)
    // =========================================================================

    /**
     * POST /commissioning-items/{item}/photo
     *
     * Multipart body: photo (file). Returns 201 with { id, evidence_photo_path, url }.
     *
     * Uses manual Validator rather than a FormRequest because this endpoint
     * is always consumed by fetch()/Axios from the Alpine factory, and a
     * plain form POST without Accept:json would otherwise receive a 302
     * redirect with session errors instead of a 422 JSON body. Mirrors the
     * Phase 14 TaskPhotoController::store pattern exactly.
     */
    public function storePhoto(Request $request, CommissioningItem $item): JsonResponse
    {
        $this->authoriseEdit($item);

        try {
            $this->assertMutable($item);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $validator = Validator::make($request->all(), [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/octet-stream',
                'max:20480',   // 20 MB
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        $path = $this->photoService->store($item, $request->file('photo'));

        $item->evidence_photo_path = $path;
        $item->save();

        return response()->json([
            'id'                  => $item->id,
            'evidence_photo_path' => $path,
            'url'                 => route('commissioning-items.photo.show', $item),
        ], 201);
    }

    // =========================================================================
    // PHOTO DELETE
    // =========================================================================

    /**
     * DELETE /commissioning-items/{item}/photo
     *
     * Returns 204 No Content.
     */
    public function destroyPhoto(CommissioningItem $item): Response|JsonResponse
    {
        $this->authoriseEdit($item);

        try {
            $this->assertMutable($item);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->photoService->delete($item);
        $item->evidence_photo_path = null;
        $item->save();

        return response()->noContent();
    }

    // =========================================================================
    // PHOTO SHOW (B-03 stream)
    // =========================================================================

    /**
     * GET /commissioning-items/{item}/photo
     *
     * Streams the stored evidence JPEG. Ownership-guarded (same rule as the
     * mutating endpoints). Always served as image/jpeg because every stored
     * photo has been transcoded / passed through to a .jpg extension by
     * CommissioningPhotoService.
     *
     * Returns 404 when evidence_photo_path is null or the file is missing
     * on disk (B-03 — no legacy fallback for this type yet).
     */
    public function show(CommissioningItem $item): BinaryFileResponse
    {
        $this->authoriseEdit($item);

        abort_if($item->evidence_photo_path === null, 404, 'No evidence photo on this item.');

        $absolute = Storage::disk('local')->path($item->evidence_photo_path);
        abort_unless(is_file($absolute), 404, 'Evidence photo file is missing on disk.');

        return response()->file($absolute, [
            'Content-Type'  => 'image/jpeg',
            // Tablets shared across engineers: revalidate per request so the
            // auth guard gets hit every time and cached bytes don't leak
            // across sessions. Mirrors the Phase 14 TaskPhotoController
            // Cache-Control policy.
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Canonical ownership guard — project owner, admin, or engineer assigned
     * to at least one task on the item's programme. Mirrors Phase 14's
     * TaskStatusController::authoriseTaskMutation with one extension: the
     * engineer doesn't need to be assigned to the specific task that spawned
     * this commissioning item — assignment to any task on the same programme
     * grants access (commissioning is a programme-level concern).
     */
    private function authoriseEdit(CommissioningItem $item): void
    {
        $item->loadMissing('programme.project');
        $user = auth()->user();

        $isOwnerOrAdmin = $item->programme->project->user_id === $user->id
            || $user->isAdmin();

        $isAssignedEngineer = $item->programme->tasks()
            ->where('assigned_to', $user->id)
            ->exists();

        abort_if(! $isOwnerOrAdmin && ! $isAssignedEngineer, 403);
    }

    /**
     * INST-05i enforcement — throws CommissioningSignoffException::itemsImmutable
     * if a signoff has been recorded against this item's programme. Caller
     * catches the exception and returns a 422 JSON response with the message.
     */
    private function assertMutable(CommissioningItem $item): void
    {
        if ($item->programme->commissioningSignoff()->exists()) {
            throw CommissioningSignoffException::itemsImmutable($item->id);
        }
    }

    /**
     * Compute programme-level counters for the response payload. Drives the
     * UI's Complete Commissioning button unlocked state (D-13 zero-items +
     * all-non-pending unlocked).
     *
     * @return array{programme: array{complete: int, total: int, unlocked: bool}}
     */
    private function programmeCounters(CommissioningItem $item): array
    {
        $programme = $item->programme;
        $total     = $programme->commissioningItems()->count();
        $complete  = $programme->commissioningItems()->whereIn('status', [
            CommissioningItem::STATUS_PASS,
            CommissioningItem::STATUS_FAIL,
            CommissioningItem::STATUS_NA,
        ])->count();

        return [
            'programme' => [
                'complete' => $complete,
                'total'    => $total,
                'unlocked' => $total === 0 || $complete === $total,
            ],
        ];
    }
}
