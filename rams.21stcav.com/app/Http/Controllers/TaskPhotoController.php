<?php

namespace App\Http\Controllers;

use App\Models\InstallTask;
use App\Models\InstallTaskPhoto;
use App\Services\TaskPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * TaskPhotoController — photo lifecycle for install tasks.
 *
 *   - store():   POST   /install-tasks/{task}/photos
 *   - update():  PATCH  /install-task-photos/{photo}    — caption only (D-12)
 *   - destroy(): DELETE /install-task-photos/{photo}    — inline lightbox confirm
 *   - show():    GET    /install-task-photos/{photo}    — private file stream
 *
 * Validation:
 *   - Uses `mimetypes` (not `mimes`) per RESEARCH.md Pitfall 2 — Laravel's
 *     `image` / `mimes` rules don't reliably include HEIC; mimetypes is explicit.
 *   - Accepts `application/octet-stream` as a fallback because iOS Safari
 *     occasionally reports HEIC uploads that way (Pitfall 3); HeicImageConverter
 *     sniffs the real MIME server-side.
 *
 * @see TaskPhotoService — all file work delegated here
 * @see HeicImageConverter — invoked transitively
 */
class TaskPhotoController extends Controller
{
    public function __construct(
        private readonly TaskPhotoService $photos,
    ) {}

    // =========================================================================
    // UPLOAD
    // =========================================================================

    /**
     * POST /install-tasks/{task}/photos
     * Returns 201 with { id, filename, original_name, caption, mime_type, url }.
     *
     * @param  Request     $request
     * @param  InstallTask $task
     * @return JsonResponse
     */
    public function store(Request $request, InstallTask $task): JsonResponse
    {
        $task->load('programme.project');
        $this->authoriseTaskMutation($task);

        // Manually validate rather than $request->validate() — a raw form POST
        // without Accept:json would otherwise receive a 302 redirect back with
        // the errors in the session instead of a 422 JSON body. This endpoint
        // is always consumed by fetch() / Axios, so return JSON on validation
        // failure regardless of the client's Accept header.
        $validator = Validator::make($request->all(), [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,image/heic-sequence,image/heif-sequence,application/octet-stream',
                'max:20480', // 20 MB in KB
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        // Secondary defence against the octet-stream fallback being used as a
        // smuggling vector: require the file extension to be one of the image
        // types we handle, OR let a content-sniff decide.
        $original = $request->file('photo');
        $ext = strtolower($original->getClientOriginalExtension());
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
        if (! in_array($ext, $allowedExts, true)) {
            // Fall back to content-sniff; if that also fails we 422
            $finfo = @mime_content_type($original->getRealPath());
            $allowedMimes = [
                'image/jpeg', 'image/png', 'image/webp',
                'image/heic', 'image/heif',
                'image/heic-sequence', 'image/heif-sequence',
            ];
            abort_unless(in_array($finfo, $allowedMimes, true), 422,
                'Only photos (JPG, PNG, WEBP, HEIC) are allowed.');
        }

        $photo = $this->photos->store($task, $original);

        Log::info('TaskPhotoController: photo stored', [
            'task_id'  => $task->id,
            'photo_id' => $photo->id,
            'user_id'  => auth()->id(),
        ]);

        return response()->json([
            'id'            => $photo->id,
            'filename'      => $photo->filename,
            'original_name' => $photo->original_name,
            'caption'       => $photo->caption,
            'mime_type'     => $photo->mime_type,
            'url'           => route('install-task-photos.show', $photo),
        ], 201);
    }

    // =========================================================================
    // CAPTION UPDATE (D-12)
    // =========================================================================

    /**
     * PATCH /install-task-photos/{photo}
     * Body: { caption: string|null, max 200 chars }
     *
     * @param  Request          $request
     * @param  InstallTaskPhoto $photo
     * @return JsonResponse
     */
    public function update(Request $request, InstallTaskPhoto $photo): JsonResponse
    {
        $photo->load('task.programme.project');
        $this->authoriseTaskMutation($photo->task);

        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:200'],
        ]);

        $photo = $this->photos->updateCaption($photo, $validated['caption'] ?? null);

        return response()->json([
            'id'      => $photo->id,
            'caption' => $photo->caption,
        ]);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    /**
     * DELETE /install-task-photos/{photo}
     * Returns 204 No Content.
     *
     * @param  InstallTaskPhoto $photo
     * @return Response
     */
    public function destroy(InstallTaskPhoto $photo): Response
    {
        $photo->load('task.programme.project');
        $this->authoriseTaskMutation($photo->task);

        $this->photos->delete($photo);

        return response()->noContent(); // 204
    }

    // =========================================================================
    // PRIVATE FILE SERVE (Pitfall 5 — do NOT use Storage::url() for local disk)
    // =========================================================================

    /**
     * GET /install-task-photos/{photo}
     * Streams the file bytes with Content-Type from mime_type.
     *
     * @param  InstallTaskPhoto $photo
     * @return BinaryFileResponse
     */
    public function show(InstallTaskPhoto $photo): BinaryFileResponse
    {
        $photo->load('task.programme.project');
        $this->authoriseTaskMutation($photo->task);

        $path = $photo->absolutePath();
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
            'Content-Disposition' => 'inline; filename="'.$photo->original_name.'"',
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    // =========================================================================
    // Ownership guard — mirrors TaskStatusController
    // =========================================================================

    /**
     * Canonical ownership guard: project owner, admin, or assigned engineer may mutate.
     */
    private function authoriseTaskMutation(InstallTask $task): void
    {
        $user = auth()->user();
        $isOwnerOrAdmin = $task->programme->project->user_id === $user->id || $user->isAdmin();
        $isAssigned = $task->assigned_to === $user->id;

        abort_if(! $isOwnerOrAdmin && ! $isAssigned, 403);
    }
}
