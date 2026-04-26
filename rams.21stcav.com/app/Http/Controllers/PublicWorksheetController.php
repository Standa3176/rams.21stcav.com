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
        $worksheet->load('signoffs');

        return view('worksheets.public-show', [
            'worksheet'     => $worksheet,
            'token'         => $token,
            'latestSignoff' => $worksheet->latestSignoff(),
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
