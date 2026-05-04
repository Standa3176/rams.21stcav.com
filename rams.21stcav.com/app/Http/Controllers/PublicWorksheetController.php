<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\SiteSurveyPhoto;
use App\Models\Worksheet;
use App\Services\DeviceLabelPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * PublicWorksheetController — no authentication required.
 *
 * All access is gated by a UUID `access_token` embedded in the URL. The token
 * is generated automatically when a Worksheet is created (see Worksheet::boot).
 *
 * Routes (defined outside the auth middleware group in routes/web.php):
 *   GET  /worksheet/{token}        — read-only worksheet view + sign pad
 *   POST /worksheet/{token}/sign   — record a client sign-off (throttle:10,1)
 *
 * Behaviour notes:
 *  - Worksheets do NOT expire (locked design — unlike site surveys which can
 *    have an expires_at). PMs can revoke a link by regenerating the worksheet.
 *  - Sign-off is APPEND-ONLY: a fresh submission inserts a new
 *    worksheet_signoffs row even when one already exists for this worksheet.
 *    `Worksheet::latestSignoff()` resolves the most-recent acceptance for
 *    display + DOCX embedding.
 *  - The page remains active after sign-off so engineers can continue updating
 *    notes / photos via the admin pipeline. Re-signing produces a snag-list
 *    audit trail.
 */
class PublicWorksheetController extends Controller
{
    // ─── Show ────────────────────────────────────────────────────────────────

    /**
     * GET /worksheet/{token}
     *
     * Render the read-only worksheet view with a single signature pad at the
     * bottom of the page. 404 on unknown token.
     */
    public function show(string $token): View
    {
        $worksheet = $this->resolveWorksheet($token);
        $worksheet->load('signoffs', 'photos');

        return view('worksheets.public-show', [
            'worksheet'      => $worksheet,
            'token'          => $token,
            'latestSignoff'  => $worksheet->latestSignoff(),
            'photoCounts'    => $worksheet->photoCountsByRoom(),
        ]);
    }

    // ─── Photo upload / serve ────────────────────────────────────────────────

    /**
     * POST /worksheet/{token}/rooms/{room_name}/photos
     *
     * Accept a photo upload from the public worksheet link and persist it
     * scoped to a specific room. Engineers must capture photos per room
     * before requesting client sign-off.
     */
    public function uploadPhoto(Request $request, string $token, string $roomName): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        $request->validate([
            'photo'   => ['required', 'file', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('photo');
        $extension = match ($file->getMimeType()) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'image/heic',
            'image/heif'      => 'heic',
            default           => 'jpg',
        };
        $basename = \Illuminate\Support\Str::uuid() . '.' . $extension;
        $directory = "worksheet-photos/{$worksheet->id}";
        $storedPath = "{$directory}/{$basename}";
        \Illuminate\Support\Facades\Storage::disk('local')->putFileAs($directory, $file, $basename);

        $sortOrder = ($worksheet->photos()->where('room_name', $roomName)->max('sort_order') ?? 0) + 1;
        $photo = $worksheet->photos()->create([
            'room_name'     => $roomName,
            'filename'      => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType() ?? 'image/jpeg',
            'caption'       => $request->input('caption'),
            'sort_order'    => $sortOrder,
        ]);

        return response()->json([
            'id'       => $photo->id,
            'filename' => $photo->filename,
            'caption'  => $photo->caption,
            'url'      => route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $photo->id]),
        ]);
    }

    /**
     * DELETE /worksheet/{token}/photos/{photo}
     *
     * Remove a photo from a room. Token + ownership double-checked so a
     * leaked URL can't blow away photos on a different worksheet.
     */
    public function deletePhoto(string $token, int $photoId): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);
        $photo     = $worksheet->photos()->where('id', $photoId)->firstOrFail();

        \Illuminate\Support\Facades\Storage::disk('local')->delete($photo->storagePath());
        $photo->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /worksheet/{token}/photos/{photo}
     *
     * Stream a photo file. Token-gated and verified to belong to the
     * matching worksheet to prevent cross-worksheet enumeration.
     */
    public function servePhoto(string $token, int $photoId): \Symfony\Component\HttpFoundation\Response
    {
        $worksheet = $this->resolveWorksheet($token);
        $photo     = $worksheet->photos()->where('id', $photoId)->firstOrFail();

        $path = $photo->absolutePath();
        abort_unless(file_exists($path), 404);

        return response()->file($path, [
            'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
        ]);
    }

    // ─── Survey reference (photos + per-room review) ──────────────────────────

    /**
     * GET /worksheet/{token}/survey-photos/{photo}
     *
     * Stream a SiteSurveyPhoto belonging to the same project as this worksheet.
     * Cross-project guard prevents a leaked token from serving photos that live
     * on a different project's survey — `$photo->room?->survey?->project_id`
     * must match `$worksheet->project_id`. The defensive `?->` chain causes any
     * orphaned record (photo with no room, room with no survey, survey with no
     * project_id) to evaluate to `null` and trip the guard with a 403.
     */
    public function serveSurveyPhoto(string $token, SiteSurveyPhoto $photo): \Symfony\Component\HttpFoundation\Response
    {
        $worksheet = $this->resolveWorksheet($token);

        abort_unless(
            $photo->room?->survey?->project_id === $worksheet->project_id,
            403
        );

        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($photo->storagePath());
        abort_unless(file_exists($path), 404);

        return response()->file($path, [
            'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
        ]);
    }

    /**
     * POST /worksheet/{token}/rooms/{roomName}/survey-reviewed
     *
     * Record that an engineer has reviewed the site-survey reference for a
     * specific room. Validates `roomName` against the worksheet's own
     * `generated_data['rooms'][*]['name']` inclusion list — forged names are
     * rejected with 422 so a leaked token cannot inject arbitrary keys into
     * the JSON column. Updates `worksheet.pre_install_confirmations` (260504-iy4
     * namespaced shape: survey_review.{roomName}) and redirects back to the show
     * page with a flash success message (full-page reload pattern).
     */
    public function markSurveyReviewed(Request $request, string $token, string $roomName): RedirectResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // Build inclusion list of valid room names from the worksheet's own
        // generated_data — anything outside this list is a forged name.
        $validRoomNames = collect((array) ($worksheet->generated_data['rooms'] ?? []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        abort_if(empty($validRoomNames), 422,
            'Worksheet has no rooms — cannot mark a room reviewed.');

        if (! in_array($roomName, $validRoomNames, true)) {
            abort(422, 'Unknown room name.');
        }

        // 260504-iy4 — H4: namespaced JSON shape. survey_review.{room} = {reviewed_at, reviewed_by}.
        // room_complete is a sibling namespace handled by markRoomComplete (see Task 2).
        $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
        $now = now();
        $confirmations['survey_review'][$roomName] = [
            'reviewed_at' => $now->toIso8601String(),
            'reviewed_by' => substr($token, 0, 8),
        ];
        $worksheet->pre_install_confirmations = $confirmations;
        $worksheet->save();

        return redirect()
            ->route('public-worksheet.show', ['token' => $token])
            ->with('success', "Survey reviewed for: {$roomName}");
    }

    /**
     * POST /worksheet/{token}/rooms/{roomName}/complete
     *
     * Record that an engineer has marked a room "complete" — the room body
     * auto-collapses on next render and a green ✓ Complete pill appears on the
     * <summary> row.
     *
     * Server-side validation: forged room names rejected (422), mirrors
     * markSurveyReviewed exactly. NO server-side enforcement of the gate (photos +
     * survey-reviewed) — engineers may be on flaky networks and partial state must
     * not block them. Frontend disables the button until the soft gate is
     * satisfied; if the engineer hits the endpoint directly, the write succeeds.
     *
     * Writes pre_install_confirmations['room_complete'][$roomName] = {completed_at, completed_by}.
     */
    public function markRoomComplete(Request $request, string $token, string $roomName): RedirectResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // Forged-room-name guard — mirrors markSurveyReviewed.
        $validRoomNames = collect((array) ($worksheet->generated_data['rooms'] ?? []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        abort_if(empty($validRoomNames), 422,
            'Worksheet has no rooms — cannot mark a room complete.');

        if (! in_array($roomName, $validRoomNames, true)) {
            abort(422, 'Unknown room name.');
        }

        $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
        $now = now();
        $confirmations['room_complete'][$roomName] = [
            'completed_at' => $now->toIso8601String(),
            'completed_by' => substr($token, 0, 8),
        ];
        $worksheet->pre_install_confirmations = $confirmations;
        $worksheet->save();

        return redirect()
            ->route('public-worksheet.show', ['token' => $token])
            ->with('success', "Room marked complete: {$roomName}");
    }

    // ─── Sign ────────────────────────────────────────────────────────────────

    /**
     * POST /worksheet/{token}/sign
     *
     * Accept a sign-off submission, persist a new worksheet_signoffs row, and
     * redirect back to the show page with a success flash. Throttled to
     * 10 requests / minute (see routes/web.php).
     */
    public function sign(Request $request, string $token): RedirectResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        $data = $request->validate([
            'client_name'          => ['required', 'string', 'max:200'],
            'signature_image'      => ['required', 'string'],   // data:image/png;base64,...
            'signed_with_comments' => ['nullable', 'boolean'],
            'comments'             => ['nullable', 'string', 'max:5000'],
        ]);

        // Conditional rule: when the "signed with comments" checkbox is on,
        // the comments textarea must hold non-whitespace text.
        $withComments = filter_var($data['signed_with_comments'] ?? false, FILTER_VALIDATE_BOOL);
        if ($withComments && trim((string) ($data['comments'] ?? '')) === '') {
            return back()
                ->withErrors(['comments' => 'Comments are required when "signed with comments" is ticked.'])
                ->withInput();
        }

        // Strip the data:image/png;base64, prefix so DB stores raw base64
        // (matches CommissioningSignoff convention).
        $b64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $data['signature_image']);

        $worksheet->signoffs()->create([
            'client_name'          => $data['client_name'],
            'signature_png_base64' => $b64,
            'signed_with_comments' => $withComments,
            'comments'             => $data['comments'] ?? null,
            'signed_at'            => now(),
            'ip_address'           => $request->ip(),
            'user_agent'           => substr((string) $request->userAgent(), 0, 500),
        ]);

        return redirect()
            ->route('public-worksheet.show', ['token' => $token])
            ->with('success', 'Thank you — your sign-off has been recorded.');
    }

    // ─── Device label photo capture (engineer-facing) ───────────────────────

    /**
     * POST /worksheet/{token}/label-photo
     *
     * Engineer captures a photo of an equipment label. The server finds or
     * creates the matching Device row by (project_id, room_name, description),
     * stores the photo, runs AI vision OCR, and returns the extracted fields
     * for engineer confirmation.
     */
    public function uploadLabelPhoto(
        Request $request,
        DeviceLabelPhotoService $service,
        string $token,
    ): \Illuminate\Http\JsonResponse {
        $worksheet = $this->resolveWorksheet($token);

        $data = $request->validate([
            'photo'            => ['required', 'file', 'image', 'max:10240'],
            'room_name'        => ['required', 'string', 'max:200'],
            'item_description' => ['required', 'string', 'max:300'],
            'item_part_number' => ['nullable', 'string', 'max:120'],
            'item_qty'         => ['nullable', 'integer', 'min:1'],
        ]);

        abort_if($worksheet->project_id === null, 422,
            'Worksheet has no project — cannot register devices.');

        // Find-or-create the Device row this label belongs to.
        $device = Device::firstOrCreate(
            [
                'project_id'  => $worksheet->project_id,
                'room_name'   => $data['room_name'],
                'description' => $data['item_description'],
            ],
            [
                'part_no' => $data['item_part_number'] ?? null,
                'qty'     => $data['item_qty'] ?? 1,
            ]
        );

        $photo = $service->capture(
            project:    $worksheet->project,
            file:       $request->file('photo'),
            device:     $device,
            worksheet:  $worksheet,
            roomName:   $data['room_name'],
            capturedBy: substr($token, 0, 8),
        );

        return response()->json([
            'id'           => $photo->id,
            'device_id'    => $device->id,
            'photo_url'    => Storage::url($photo->photo_path),
            'ai_extracted' => $photo->ai_extracted,
            'confirmed'    => $photo->confirmed,
        ]);
    }

    /**
     * POST /worksheet/{token}/label-photos/{photo}/confirm
     *
     * Engineer reviews/edits the AI-extracted values and confirms. Writes
     * the final part / serial / MAC / model / manufacturer onto the linked
     * Device row.
     */
    public function confirmLabelPhoto(
        Request $request,
        DeviceLabelPhotoService $service,
        string $token,
        int $photoId,
    ): \Illuminate\Http\JsonResponse {
        $worksheet = $this->resolveWorksheet($token);

        $photo = DeviceLabelPhoto::where('id', $photoId)
            ->where('worksheet_id', $worksheet->id)
            ->firstOrFail();

        $fields = $request->validate([
            'part_number'   => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'mac_address'   => ['nullable', 'string', 'max:60'],
            'model'         => ['nullable', 'string', 'max:120'],
            'manufacturer'  => ['nullable', 'string', 'max:120'],
        ]);

        $photo = $service->confirm($photo, $fields);

        return response()->json([
            'ok'        => true,
            'confirmed' => $photo->confirmed,
            'device'    => $photo->device?->only([
                'id', 'part_no', 'serial_number', 'mac_address', 'model', 'manufacturer',
            ]),
        ]);
    }

    /**
     * DELETE /worksheet/{token}/label-photos/{photo}
     */
    public function deleteLabelPhoto(
        DeviceLabelPhotoService $service,
        string $token,
        int $photoId,
    ): \Illuminate\Http\JsonResponse {
        $worksheet = $this->resolveWorksheet($token);

        $photo = DeviceLabelPhoto::where('id', $photoId)
            ->where('worksheet_id', $worksheet->id)
            ->firstOrFail();

        $service->delete($photo);

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Resolve a Worksheet by its access token, 404'ing on miss. Worksheets do
     * not expire — the token is valid for the life of the worksheet record.
     */
    private function resolveWorksheet(string $token): Worksheet
    {
        $worksheet = Worksheet::where('access_token', $token)->first();

        abort_if($worksheet === null, 404, 'Worksheet not found. Please check your link.');

        return $worksheet;
    }

    /**
     * Resolve a Device that belongs to the worksheet's project. 404 prevents
     * cross-project enumeration via a leaked token.
     */
    private function resolveDevice(Worksheet $worksheet, int $deviceId): Device
    {
        $device = Device::where('id', $deviceId)
            ->where('project_id', $worksheet->project_id)
            ->first();

        abort_if($device === null, 404, 'Device not found on this project.');

        return $device;
    }
}
