<?php

namespace App\Http\Controllers;

use App\Mail\WorksheetSignedMail;
use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\SiteSurveyPhoto;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use App\Services\DeviceLabelPhotoService;
use App\Services\NotificationRecipientResolver;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
 *  - Worksheets do NOT expire by default. Audit M-05 (2026-05-17) added an
 *    optional `access_token_expires_at` — null = never expires (default),
 *    set-and-past = 410 Gone. The admin "Revoke link" action regenerates
 *    the UUID so any leaked copy of the old URL becomes inert immediately.
 *  - Sign-off is APPEND-ONLY: a fresh submission inserts a new
 *    worksheet_signoffs row even when one already exists for this worksheet.
 *    `Worksheet::latestSignoff()` resolves the most-recent acceptance for
 *    display + DOCX embedding.
 *  - THE PAGE REMAINS VISIBLE AFTER SIGN-OFF, BUT CAPTURE IS CLOSED (Phase
 *    46.4, D-07). Until 2026-09-26 this paragraph claimed the opposite: that
 *    the page stayed OPEN after sign-off for further notes / photo capture via
 *    the admin pipeline. Half of that is now false, and the superseded sentence
 *    is deliberately not reproduced here — a docblock must not contain a
 *    findable claim the code contradicts, even as a quotation. The user,
 *    verbatim: *"client cannot chage anything as they are signing to confirm
 *    work is complete."*
 *    Once a worksheet_signoffs row exists, every CAPTURE endpoint on this
 *    controller refuses with 422 (see App\Support\Worksheets\WorksheetCaptureLock)
 *    — photo uploads, photo labels, photo deletes, serial-label capture. There
 *    is no login and no second URL, so the app cannot tell an engineer from a
 *    client by identity; it tells them apart by STATE, and the refusal lives on
 *    the SERVER because the token is the only credential and a hidden button
 *    was never a permission.
 *    Still true, and deliberately NOT locked: the page RENDERS (a signed
 *    worksheet is a record its signer must be able to read), photos and
 *    reference files still serve, the engineer's room-complete / survey-reviewed
 *    status confirmations still write, and RE-SIGNING STILL WORKS — it produces
 *    a snag-list audit trail, which is why POST /sign carries no lock.
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
        // 260602-mlt — `project.latestPackage` eager-load powers the header
        // "Site contact: {name} · {tel-link}" line so the @php block in
        // public-show.blade.php doesn't trigger an N+1 per request.
        $worksheet->load('signoffs', 'photos', 'project.referenceFiles', 'project.latestPackage');

        return view('worksheets.public-show', [
            'worksheet'      => $worksheet,
            'token'          => $token,
            'latestSignoff'  => $worksheet->latestSignoff(),
            'photoCounts'    => $worksheet->photoCountsByRoom(),
            // Plan 46-05 — the office send-back banner. This page is signed by
            // the CLIENT, so the office's wording about its own engineer is
            // DELIBERATELY withheld (threat T-46-05-02): the client learns the
            // visit is not finished, not what the office thinks of the return.
            // Do not "fix" the missing reason by passing it through.
            'sendBack'       => \App\Support\Visits\VisitReworkState::forWorksheet($worksheet),
        ]);
    }

    // ─── Photo upload / serve ────────────────────────────────────────────────

    /**
     * POST /worksheet/{token}/photos
     *
     * Accept a photo upload from the public worksheet link and persist it
     * scoped to a specific room. Engineers must capture photos per room
     * before requesting client sign-off.
     *
     * room_name travels in the FormData body (not the URL path) so room
     * names containing '/', '?', '#' or other reserved chars don't 404
     * against the web server's encoded-slash rejection. See routes/web.php
     * for the rationale.
     */
    public function uploadPhoto(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // D-07 — BEFORE VALIDATION, on purpose. A locked caller must not learn
        // which field was malformed; and a queued row draining in late must be
        // refused before its bytes reach the disk.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

        $request->validate([
            'room_name' => ['required', 'string', 'max:200'],
            'photo'     => ['required', 'file', 'image', 'max:10240'],
            'caption'   => ['nullable', 'string', 'max:200'],
            // Phase 46.4 (D-03) — the tray the photo came from. Validated
            // against the MODEL'S OWN constant, never a literal list here, so
            // the vocabulary cannot drift between the two files.
            //
            // `sometimes` — NOT `nullable` — and the distinction is load
            // bearing. An ABSENT bucket is a compatibility case that must
            // work: a pre-46.4 client posts no bucket at all, and so does a
            // photo that has been sitting in a browser's IndexedDB queue since
            // before this deploy — its `fields` bag has no bucket key and it
            // may drain days later. Those uploads land in `completion`, the
            // same statement the migration's backfill made about every legacy
            // row. A bucket that is PRESENT but empty is a different thing: a
            // client that meant to say something and said nothing, which is
            // rejected. (`nullable` would conflate the two, because the global
            // ConvertEmptyStringsToNull middleware turns `bucket=` into null
            // before the validator ever sees it.)
            'bucket'    => ['sometimes', 'string', \Illuminate\Validation\Rule::in(\App\Models\WorksheetPhoto::BUCKETS)],
        ]);

        $roomName = $request->input('room_name');
        $bucket   = $request->input('bucket') ?: \App\Models\WorksheetPhoto::BUCKET_COMPLETION;

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

        // Phase 46.4 (D-03) — sort order is computed WITHIN the room AND the
        // bucket, so the Start and Completion trays number independently. Room
        // only would make a start photo captured after three completion photos
        // sort as #4 in a tray showing one item.
        $sortOrder = ($worksheet->photos()
            ->where('room_name', $roomName)
            ->where('bucket', $bucket)
            ->max('sort_order') ?? 0) + 1;
        $photo = $worksheet->photos()->create([
            'room_name'     => $roomName,
            'bucket'        => $bucket,
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
            'bucket'   => $photo->bucket,
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

        // D-07 — before the row is even resolved. This endpoint unlinks the FILE
        // before the row, so a guard placed any later could leave an orphan.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

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
        //
        // Audit M-06 (2026-07): reviewed_by used to be substr($token, 0, 8) —
        // 32 bits of a URL-bearing auth secret leaked into a persisted audit
        // log. Replaced with the request IP + an SHA-256 hash of the token
        // for actor correlation without leaking the token itself. The hash
        // lets a DB reader confirm "same actor signed both rooms" without
        // exposing bytes an attacker could use to guess the URL.
        $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
        $now = now();
        $confirmations['survey_review'][$roomName] = [
            'reviewed_at' => $now->toIso8601String(),
            'reviewed_by' => 'ip:' . ($request->ip() ?: 'unknown')
                            . '|actor:' . substr(hash('sha256', $token), 0, 12),
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

        // Audit M-06 (2026-07): see markSurveyReviewed above for rationale.
        // completed_by carries IP + SHA-256 hash prefix of the token instead
        // of the token itself — actor correlation without the leak.
        $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
        $now = now();
        $confirmations['room_complete'][$roomName] = [
            'completed_at' => $now->toIso8601String(),
            'completed_by' => 'ip:' . ($request->ip() ?: 'unknown')
                              . '|actor:' . substr(hash('sha256', $token), 0, 12),
        ];
        $worksheet->pre_install_confirmations = $confirmations;
        $worksheet->save();

        return redirect()
            ->route('public-worksheet.show', ['token' => $token])
            ->with('success', "Room marked complete: {$roomName}");
    }

    // ─── Additional kit (46.4-05 — D-06 / D-08 / D-02 / D-10) ────────────────

    /**
     * Rendered in place of an engineer's name when `labour_resource_id` is null.
     *
     * D-02's fallback, in words: no visit, an empty allocation, or a resource
     * that has since been deleted all reduce to "nobody was named", and the row
     * is still recordable. Because THERE IS NO FREE-TEXT ENGINEER FIELD ANYWHERE
     * IN THIS PHASE, a null can only ever mean that — it can never become a
     * spelling variant of somebody's name.
     */
    public const UNASSIGNED_ENGINEER = 'Unassigned — no engineer allocated to this visit';

    /**
     * POST /worksheet/{token}/additional-kit
     *
     * Record one row of extra kit an engineer used on site, scoped to a room.
     * D-06: real rows, one per item, repeatable — not a `max:5000` comments
     * textarea and not a phone call to the office.
     *
     * `room_name` travels in the body (not the path) for the same documented
     * reason as the photo routes — see routes/web.php.
     *
     * ⚠️ EVERY AUDIT COLUMN IS SET SERVER-SIDE AND NONE IS EVER READ FROM THE
     * REQUEST. The public token is the only credential on this link; a body that
     * could set `created_via` is a body that could claim to be the office.
     * `WorksheetAdditionalKit` keeps all eight off `$fillable` for that reason
     * and this method assigns the two it owns explicitly.
     */
    public function addAdditionalKit(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // D-07 — FIRST, BEFORE VALIDATION. A locked caller must not learn which
        // field was malformed, and a row queued on a phone must be refused
        // before it lands.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

        $data = $request->validate([
            'room_name'          => ['required', 'string', 'max:200'],
            'labour_resource_id' => ['nullable', 'integer'],
            // D-10: qty and part_description, AND NOTHING ELSE. A unit field
            // (each / metres / boxes) was put to the user and declined — "3.no."
            // Do not add one, not even as a nullable placeholder.
            'qty'                => ['required', 'integer', 'min:1', 'max:999'],
            'part_description'   => ['required', 'string', 'max:500'],
        ]);

        $roomName = $this->assertRoomNameIsOnTheWorksheet($worksheet, $data['room_name']);

        $engineers    = \App\Support\Worksheets\AllocatedEngineers::forWorksheet($worksheet);
        $engineerId   = $this->assertEngineerIsAllocated($engineers, $data['labour_resource_id'] ?? null);

        // sort_order runs WITHIN the room, matching SCC's addCheck precedent
        // (max + 1 scoped to the room, never a worksheet-wide sequence).
        $nextSort = (int) WorksheetAdditionalKit::query()
            ->where('worksheet_id', $worksheet->id)
            ->where('room_name', $roomName)
            ->max('sort_order');

        $row = new WorksheetAdditionalKit();
        $row->fill([
            'worksheet_id'       => $worksheet->id,
            'room_name'          => $roomName,
            'labour_resource_id' => $engineerId,
            'qty'                => (int) $data['qty'],
            'part_description'   => $data['part_description'],
            'sort_order'         => $nextSort + 1,
        ]);
        // ⚠️ SERVER-FORCED, never mass-assigned. Both columns are off $fillable.
        $row->forceFill([
            'created_via'      => WorksheetAdditionalKit::CREATED_VIA_ENGINEER_LINK,
            'created_by_actor' => $this->actorStamp($request, $token),
        ])->save();

        // ⚠️ THE RESPONSE CARRIES NO ACTOR STAMP. created_by_actor holds
        // `ip:…|actor:<sha256 slice>` and is NEVER rendered or returned — same
        // rule as device_label_photos.captured_by, which once leaked a token
        // fragment and needed a migration to null every legacy value.
        return response()->json([
            'id'               => $row->id,
            'room_name'        => $row->room_name,
            'qty'              => $row->qty,
            'part_description' => $row->part_description,
            'engineer_name'    => $this->engineerName($engineers, $row->labour_resource_id),
        ], 201);
    }

    /**
     * POST /worksheet/{token}/additional-kit/{row}
     *
     * Correct a row — qty, part description, or which allocated engineer (D-08).
     *
     * ⚠️ THIS IS AN AMENDMENT, NOT AN OVERWRITE. Each request appends exactly
     * ONE entry to the append-only `amendments` trail, carrying only the fields
     * that actually moved. A request that changes nothing is refused 422 rather
     * than appending an empty entry: a trail of `{from: 3, to: 3}` rows is noise
     * the office stops reading, and a trail nobody reads is not an audit trail.
     *
     * No `room_name` is accepted — a row cannot change rooms (see routes/web.php).
     */
    public function modifyAdditionalKit(Request $request, string $token, int $rowId): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // D-07 — first, before validation.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

        $row = $this->resolveAdditionalKitRow($worksheet, $rowId);
        $this->assertRowIsOpen($row);

        // `sometimes` on all three: the drawer posts what it holds, and an
        // ABSENT key must be distinguishable from an explicit null (which means
        // "unassign the engineer", a real edit).
        $data = $request->validate([
            'qty'                => ['sometimes', 'required', 'integer', 'min:1', 'max:999'],
            'part_description'   => ['sometimes', 'required', 'string', 'max:500'],
            'labour_resource_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $engineers = \App\Support\Worksheets\AllocatedEngineers::forWorksheet($worksheet);

        // ── Diff BEFORE saving. The trail is built from what actually moved ──
        $changes = [];

        if (array_key_exists('qty', $data) && (int) $data['qty'] !== (int) $row->qty) {
            $changes['qty'] = ['from' => (int) $row->qty, 'to' => (int) $data['qty']];
        }

        if (array_key_exists('part_description', $data)
            && (string) $data['part_description'] !== (string) $row->part_description) {
            $changes['part_description'] = [
                'from' => (string) $row->part_description,
                'to'   => (string) $data['part_description'],
            ];
        }

        if (array_key_exists('labour_resource_id', $data)) {
            $posted = $this->assertEngineerIsAllocated($engineers, $data['labour_resource_id']);

            if ($posted !== $row->labour_resource_id) {
                $changes['labour_resource_id'] = ['from' => $row->labour_resource_id, 'to' => $posted];
            }
        }

        // ⚠️ AN EMPTY AMENDMENT IS WORSE THAN NO AMENDMENT.
        abort_if($changes === [], 422, 'Nothing changed.');

        foreach ($changes as $field => $pair) {
            $row->{$field} = $pair['to'];
        }

        // `amendments` and `amended_at` are off $fillable by design — append to
        // the existing list and assign explicitly. Never REPLACE the trail.
        $trail   = $row->amendments;
        $trail[] = [
            'at'      => now()->toIso8601String(),
            'actor'   => $this->actorStamp($request, $token),
            'changes' => $changes,
        ];

        $row->forceFill([
            'amendments' => $trail,
            'amended_at' => now(),
        ])->save();

        // ⚠️ NO ACTOR STAMP IN THE RESPONSE — not created_by_actor, not
        // marked_by_actor, and not the amendment's own `actor`.
        return response()->json([
            'id'               => $row->id,
            'room_name'        => $row->room_name,
            'qty'              => $row->qty,
            'part_description' => $row->part_description,
            'engineer_name'    => $this->engineerName($engineers, $row->labour_resource_id),
            'amended'          => true,
        ]);
    }

    /**
     * POST /worksheet/{token}/additional-kit/{row}/mark-deleted
     *
     * Flag a row for the office to take off, WITH A REASON (D-08). The user:
     * *"mark items for deletion (with reason)."*
     *
     * ⚠️ THE ROW STAYS. This method sets three columns and removes nothing.
     * `Worksheet::additionalKit()` still returns the row, on purpose, and the
     * office's table shows it as marked rather than finding it gone — that is
     * what makes the list reconcilable. There is no hard delete anywhere in this
     * phase and no unmark; if an engineer marks a row by mistake, the office
     * fixes it (recorded as a known gap in 46.4-01-SUMMARY.md).
     *
     * The reason is REQUIRED and must be at least 3 non-whitespace characters:
     * a blank reason is a row the office cannot action, and `x` is not an
     * explanation.
     */
    public function markAdditionalKitForDeletion(Request $request, string $token, int $rowId): \Illuminate\Http\JsonResponse
    {
        $worksheet = $this->resolveWorksheet($token);

        // D-07 — first, before validation.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

        $row = $this->resolveAdditionalKitRow($worksheet, $rowId);
        // Marking an ALREADY-MARKED row is refused here, which is what keeps the
        // first explanation and its timestamp intact. A second mark must not
        // rewrite the first engineer's reason.
        $this->assertRowIsOpen($row);

        // Trim before validating so a whitespace-only reason fails `required`
        // rather than being stored as "   ". TrimStrings already does this for
        // ordinary form posts; doing it here means a JSON caller gets the same
        // rule, and the rule is then visible at the point it matters.
        $reason = $request->input('deletion_reason');
        $request->merge([
            'deletion_reason' => is_string($reason) ? trim($reason) : $reason,
        ]);

        $data = $request->validate([
            'deletion_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        // All three columns are off $fillable — assigned explicitly, never from
        // the request beyond the validated reason itself.
        $row->forceFill([
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => $data['deletion_reason'],
            'marked_by_actor'        => $this->actorStamp($request, $token),
        ])->save();

        // ⚠️ NO ACTOR STAMP IN THE RESPONSE. The reason is engineer free text
        // and is returned raw JSON-encoded here; the PAGE escapes it on render
        // (Blade `{{ }}` server-side, `_esc()` for a JS-grafted row).
        return response()->json([
            'id'              => $row->id,
            'marked'          => true,
            'deletion_reason' => $row->deletion_reason,
        ]);
    }

    // ─── Additional-kit helpers ──────────────────────────────────────────────

    /**
     * The forged-room-name inclusion list.
     *
     * ⚠️ THIS IS A DELIBERATE COPY of the guard in `markRoomComplete` and
     * `markSurveyReviewed`, and it is NOT a candidate for extraction into one
     * shared helper. Three call sites that are free to differ are better than
     * three that are forced to move together: the two status endpoints carry
     * their own wording, their own HTTP idiom (redirect vs JSON) and their own
     * M-06 history, and a future change to one of them must not silently change
     * the kit endpoint's behaviour on a page with no login on it.
     */
    private function assertRoomNameIsOnTheWorksheet(Worksheet $worksheet, string $roomName): string
    {
        $validRoomNames = collect((array) ($worksheet->generated_data['rooms'] ?? []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        abort_if(empty($validRoomNames), 422,
            'Worksheet has no rooms — cannot record additional kit.');

        if (! in_array($roomName, $validRoomNames, true)) {
            abort(422, 'Unknown room name.');
        }

        return $roomName;
    }

    /**
     * D-02 — the engineer is PICKED, never typed, and the pick is verified
     * server-side against the visit's own allocation.
     *
     * ⚠️ THE REFUSAL IS GENERIC ON PURPOSE. Naming the rejected resource would
     * turn a public token into a staff-directory lookup oracle: post an id,
     * read a name back. Null is always accepted (D-02's fallback).
     *
     * @param array<int, array{id: int, name: string}> $engineers
     */
    private function assertEngineerIsAllocated(array $engineers, mixed $posted): ?int
    {
        if ($posted === null || $posted === '') {
            return null;
        }

        $id = (int) $posted;

        foreach ($engineers as $engineer) {
            if ((int) $engineer['id'] === $id) {
                return $id;
            }
        }

        abort(422, 'That engineer is not allocated to this visit.');
    }

    /**
     * ONE definition of "the engineer link may still touch this row", shared by
     * modify and mark so the two can never drift. Plan 03's office screen
     * branches on plan 01's matching `isMarked()` / `isOpen()` helpers.
     *
     * The two refusals carry DISTINCT messages so the engineer reads *why*
     * rather than a generic "no" — a refusal they cannot interpret becomes a
     * phone call to the office.
     */
    private function assertRowIsOpen(WorksheetAdditionalKit $row): void
    {
        abort_if(
            $row->isMarked(),
            422,
            'This row is already marked for deletion — the office will action it. Its original reason stays on the record.',
        );

        abort_if(
            $row->reconciled_at !== null,
            422,
            'The office has already reconciled this row — it can no longer be changed here.',
        );
    }

    /**
     * Resolve a kit row that belongs to THIS worksheet. 404 on a row from
     * another worksheet even with a valid token — the same cross-tenant shape as
     * `resolveDevice` and the label-photo lookups.
     */
    private function resolveAdditionalKitRow(Worksheet $worksheet, int $rowId): WorksheetAdditionalKit
    {
        $row = WorksheetAdditionalKit::query()
            ->where('id', $rowId)
            ->where('worksheet_id', $worksheet->id)
            ->first();

        abort_if($row === null, 404, 'Kit row not found on this worksheet.');

        return $row;
    }

    /**
     * Name-only projection (LR-04). The name comes from `AllocatedEngineers`'
     * plain `['id','name']` arrays — never from the `labourResource` relation,
     * which carries an email and a phone.
     *
     * @param array<int, array{id: int, name: string}> $engineers
     */
    private function engineerName(array $engineers, ?int $labourResourceId): string
    {
        if ($labourResourceId === null) {
            return self::UNASSIGNED_ENGINEER;
        }

        foreach ($engineers as $engineer) {
            if ((int) $engineer['id'] === $labourResourceId) {
                return (string) $engineer['name'];
            }
        }

        // An id whose resource has since been deleted, or one no longer on the
        // visit. The row keeps its id; the page says nobody is named rather
        // than inventing a name or throwing at an engineer on site.
        return self::UNASSIGNED_ENGINEER;
    }

    /**
     * Audit M-06's stamp, verbatim in shape from `markRoomComplete` /
     * `markSurveyReviewed`: the request IP plus a 12-hex slice of
     * `sha256($token)`.
     *
     * ⚠️ NEVER `substr($token, 0, 8)`. That leaked 32 bits of a URL-bearing auth
     * secret into a persisted audit column and needed a migration to undo. The
     * hash lets a DB reader confirm "same actor touched both rows" without
     * exposing bytes an attacker could use to guess the URL.
     *
     * ⚠️ THE RETURN VALUE IS STORED AND NEVER RENDERED — not by this
     * controller's responses, not by the engineer page, not by the office table.
     */
    private function actorStamp(Request $request, string $token): string
    {
        return 'ip:' . ($request->ip() ?: 'unknown')
            . '|actor:' . substr(hash('sha256', $token), 0, 12);
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
            'happy_with_work'      => ['nullable', 'boolean'],  // 260504-q19 — UX gate, NOT persisted
            'signed_with_comments' => ['nullable', 'boolean'],
            'comments'             => ['nullable', 'string', 'max:5000'],
        ]);

        // 260504-q19 — at least one of the two checkboxes must be ticked.
        // happy_with_work is a UX-only flag (not persisted) — a sign-off without
        // outstanding items is implied by signed_with_comments=0. We still require
        // the engineer to make an explicit choice on the form.
        $happy        = filter_var($data['happy_with_work'] ?? false, FILTER_VALIDATE_BOOL);
        $withComments = filter_var($data['signed_with_comments'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $happy && ! $withComments) {
            return back()
                ->withErrors(['happy_with_work' => 'Please confirm you are happy with the work or list outstanding items before signing.'])
                ->withInput();
        }

        // Conditional rule: when the "outstanding items" checkbox is on,
        // the comments textarea must hold non-whitespace text.
        if ($withComments && trim((string) ($data['comments'] ?? '')) === '') {
            return back()
                ->withErrors(['comments' => 'Please list the outstanding items in the comments box.'])
                ->withInput();
        }

        // Strip the data:image/png;base64, prefix so DB stores raw base64
        // (matches CommissioningSignoff convention).
        $b64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $data['signature_image']);

        $signoff = $worksheet->signoffs()->create([
            'client_name'          => $data['client_name'],
            'signature_png_base64' => $b64,
            'signed_with_comments' => $withComments,
            'comments'             => $data['comments'] ?? null,
            'signed_at'            => now(),
            'ip_address'           => $request->ip(),
            'user_agent'           => substr((string) $request->userAgent(), 0, 500),
        ]);

        // ── Office notification (quick task 260726-fx4 Task 3) ──────────────
        // Mirrors SurveyService::submitPublic — resolve project owner via
        // NotificationRecipientResolver, send WorksheetSignedMail inline,
        // then forceFill signed_notification_sent_at only on successful send.
        // A mailer failure logs a warning and leaves the timestamp null so
        // the show-view can display "Office not notified" and a future
        // retry (admin re-trigger) can fire the mail again.
        if ($worksheet->project_id) {
            try {
                $resolver  = app(NotificationRecipientResolver::class);
                $recipient = $resolver->resolveProjectRecipient($worksheet->project);
                if ($recipient?->email) {
                    Mail::to($recipient->email)->send(new WorksheetSignedMail($worksheet, $signoff));

                    // forceFill() bypasses $fillable — signed_notification_sent_at
                    // is intentionally guarded on the model (see Worksheet.php
                    // Mass-assignment safety block) so a form payload can't
                    // spoof "notification sent". Only writers on this exact
                    // code path may stamp it.
                    $worksheet->forceFill([
                        'signed_notification_sent_at' => now(),
                    ])->save();
                }
            } catch (\Throwable $e) {
                Log::warning('PublicWorksheetController: failed to send worksheet signed email', [
                    'worksheet_id' => $worksheet->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

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

        // D-07 — before validation AND before the Device::firstOrCreate below.
        // A guard any later would mint an asset-register row for a worksheet the
        // client has already signed.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

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

        // Audit CR-01 (2026-07-08): M-06 sibling — was writing the first 8
        // hex chars of the worksheet UUID token verbatim to
        // device_label_photos.captured_by. Same fix that shipped for
        // markSurveyReviewed / markRoomComplete.
        $photo = $service->capture(
            project:    $worksheet->project,
            file:       $request->file('photo'),
            device:     $device,
            worksheet:  $worksheet,
            roomName:   $data['room_name'],
            capturedBy: 'ip:' . ($request->ip() ?: 'unknown')
                       . '|actor:' . substr(hash('sha256', $token), 0, 12),
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

        // D-07 — confirming a label WRITES the serial / MAC / model onto the
        // Device row in the asset register. That is capture, not status.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

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

        // D-07 — deleting the photographic evidence of a serial after the client
        // has signed is the exact tampering this lock exists to stop.
        if (WorksheetCaptureLock::isLocked($worksheet)) {
            return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
        }

        $photo = DeviceLabelPhoto::where('id', $photoId)
            ->where('worksheet_id', $worksheet->id)
            ->firstOrFail();

        $service->delete($photo);

        return response()->json(['ok' => true]);
    }

    // ─── Engineer reference files (quick task 260601-r4c) ────────────────────

    /**
     * GET /worksheet/{token}/files/{file}
     *
     * Stream a project-level engineer reference file (uploaded site plan,
     * CAD drawing, cable schedule, method statement, etc.) attached to the
     * same project as the worksheet identified by $token.
     *
     * **CROSS-TENANT GUARD** (T-r4c-01) — `abort_unless($file->project_id
     * === $worksheet->project_id, 403)` runs BEFORE any storage I/O. A
     * leaked Project-A worksheet token MUST NOT be usable to enumerate
     * Project-B's reference files; the project_id-mismatch check tested
     * explicitly in PublicWorksheetDownloadTest.
     */
    public function downloadReferenceFile(
        string $token,
        \App\Models\ProjectReferenceFile $file,
    ): \Symfony\Component\HttpFoundation\Response {
        $worksheet = $this->resolveWorksheet($token);

        abort_unless($file->project_id === $worksheet->project_id, 403);

        return app(\App\Services\ProjectReferenceFileService::class)
            ->streamResponse($file);
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

        // Audit M-05 (2026-07) — expired-token gate. 410 Gone is semantically
        // right here: the URL was valid, the resource still exists on the
        // admin side, but this specific token is retired. Fresh links can be
        // re-issued from the admin worksheet page.
        abort_if(
            $worksheet->isTokenExpired(),
            410,
            'This worksheet link has expired. Please contact the project manager for a new link.',
        );

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
