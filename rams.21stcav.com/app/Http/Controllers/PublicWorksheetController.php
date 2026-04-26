<?php

namespace App\Http\Controllers;

use App\Models\Worksheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
