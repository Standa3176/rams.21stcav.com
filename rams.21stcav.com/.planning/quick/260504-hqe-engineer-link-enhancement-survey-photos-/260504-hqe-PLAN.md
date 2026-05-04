---
quick_id: 260504-hqe
mode: quick
type: execute
wave: 1
depends_on: []
autonomous: true
files_modified:
  - database/migrations/{TIMESTAMP}_add_pre_install_confirmations_to_worksheets_table.php
  - app/Models/Worksheet.php
  - app/Http/Controllers/PublicWorksheetController.php
  - routes/web.php
  - resources/views/worksheets/public-show.blade.php

must_haves:
  truths:
    - "Engineer opens /worksheet/{token}; for any room that has a matching SiteSurveyRoom with photos, the Survey Reference drawer shows a thumbnail strip of those survey photos"
    - "Each thumbnail is clickable; clicking opens the full-size photo via a token-gated proxy route — no leaked direct storage URL"
    - "A photo from a different project's survey CANNOT be served through a leaked token (cross-project guard returns 403/404)"
    - "Engineer sees a per-room 'I have reviewed the survey' checkbox + Mark Reviewed button at the bottom of the Survey Reference drawer when the room is not yet reviewed"
    - "Submitting the per-room review form persists `pre_install_confirmations[$roomName] = {reviewed_at, reviewed_by}` on the worksheet and the page reloads showing a green ✓ Reviewed badge"
    - "When a room has a survey AND is not yet reviewed, the page-level Sign-Off button is visually disabled with a tooltip 'Review the survey for all rooms first'"
    - "When a room has NO survey OR has been reviewed, the Sign-Off button is unaffected — engineer can sign off normally"
    - "Legacy worksheets (pre_install_confirmations === null) render the drawer + photos but do NOT show the soft-block on Sign-Off (backward compatible)"
    - "Forged room name in the markSurveyReviewed POST is rejected with 422"
  artifacts:
    - path: "database/migrations/{TIMESTAMP}_add_pre_install_confirmations_to_worksheets_table.php"
      provides: "Schema migration adding nullable JSON column with default null + drop in down()"
    - path: "app/Models/Worksheet.php"
      provides: "Adds pre_install_confirmations to fillable + array cast"
      contains: "pre_install_confirmations"
    - path: "app/Http/Controllers/PublicWorksheetController.php"
      provides: "serveSurveyPhoto + markSurveyReviewed methods, both token-gated, with cross-project + room-name-inclusion validation"
      contains: "serveSurveyPhoto"
    - path: "routes/web.php"
      provides: "Two new routes inside the existing public-worksheet.* block — survey-photos.serve (GET, throttle:120,1) + survey-reviewed (POST, throttle:60,1)"
      contains: "public-worksheet.survey-photos.serve"
    - path: "resources/views/worksheets/public-show.blade.php"
      provides: "Survey-photos thumb strip at top of Survey Reference drawer + per-room review checkbox/badge at bottom of drawer + soft-disabled Sign-Off button when unreviewed rooms exist"
      contains: "public-worksheet.survey-photos.serve"
  key_links:
    - from: "resources/views/worksheets/public-show.blade.php (drawer body)"
      to: "route('public-worksheet.survey-photos.serve', ['token', 'photo'])"
      via: "anchor href + img src on each thumbnail"
      pattern: "public-worksheet\\.survey-photos\\.serve"
    - from: "resources/views/worksheets/public-show.blade.php (per-room form)"
      to: "POST public-worksheet.survey-reviewed"
      via: "standard form post (full-page reload)"
      pattern: "public-worksheet\\.survey-reviewed"
    - from: "PublicWorksheetController::serveSurveyPhoto"
      to: "$photo->room->survey->project_id check against $worksheet->project_id"
      via: "abort_unless cross-project guard"
      pattern: "project_id"
    - from: "PublicWorksheetController::markSurveyReviewed"
      to: "$worksheet->generated_data['rooms'][*]['name'] inclusion list"
      via: "Rule::in(...) validator built from generated_data room names"
      pattern: "Rule::in"
    - from: "Worksheet::$casts"
      to: "pre_install_confirmations stored as array"
      via: "Eloquent JSON cast"
      pattern: "pre_install_confirmations.*array"
---

<objective>
Engineer link `/worksheet/{token}` ONLY — add survey photos + per-room "I have reviewed the survey" confirmation gate. The engineer arrives on site with a tablet, opens the worksheet, expands a room, sees the existing 260504-dh8 Survey Reference drawer; the drawer NOW also shows the survey photos for that room as 80×80 clickable thumbnails AND a per-room "I have reviewed the survey" form. Once each room with a survey is reviewed, the page-level Sign-Off button unlocks. Pure additive — DOCX worksheet, admin show page, RAMS, OM Manual all OUT of scope.

Purpose: Engineers need to cross-check survey findings BEFORE signing off the worksheet — currently they have the survey reference text but not the photos that would prove what the surveyor saw. Without an explicit "reviewed" checkpoint, engineers can blast through sign-off without ever opening the drawer.

Output: One new column (`worksheets.pre_install_confirmations` JSON nullable), two new public routes (`public-worksheet.survey-photos.serve` + `public-worksheet.survey-reviewed`), an extension of the existing Survey Reference drawer with photo thumbs + review form, and a soft-disabled Sign-Off button when unreviewed rooms exist.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md
@.planning/quick/260504-dh8-survey-worksheet-output-usability-site-c/260504-dh8-SUMMARY.md
@resources/views/worksheets/public-show.blade.php
@app/Http/Controllers/PublicWorksheetController.php
@app/Http/Controllers/PublicSurveyController.php
@app/Models/SiteSurveyRoom.php
@app/Models/SiteSurveyPhoto.php
@app/Models/Worksheet.php
@routes/web.php
@database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php

<interfaces>
<!-- Pre-loaded contracts the executor needs. No codebase exploration required. -->

From app/Models/SiteSurveyPhoto.php:
```php
class SiteSurveyPhoto extends Model {
    protected $fillable = ['site_survey_room_id', 'filename', 'original_name', 'mime_type', 'category', 'caption', 'sort_order'];
    public function room(): BelongsTo;             // → SiteSurveyRoom
    public function storagePath(): string;         // 'survey-photos/uuid.jpg' or 'projects/.../uuid.jpg'
    public function absolutePath(): string;        // Storage::disk('local')->path(...)
}
```

From app/Models/SiteSurveyRoom.php:
```php
class SiteSurveyRoom extends Model {
    public function survey(): BelongsTo;           // → SiteSurvey (has ->project_id)
    public function photos(): HasMany;             // ordered by sort_order
}
```

From app/Models/Worksheet.php:
```php
protected $fillable = [
    'user_id', 'project_id', 'project_name', 'project_ref', 'client_name',
    'site_address', 'status', 'error_message', 'generated_data', 'filename',
    'access_token', 'completion_email_sent_at', 'failed_email_sent_at',
];
protected function casts(): array {
    return [
        'generated_data'           => 'array',
        'completion_email_sent_at' => 'datetime',
        'failed_email_sent_at'     => 'datetime',
    ];
}
```

From app/Http/Controllers/PublicWorksheetController.php (existing methods):
```php
private function resolveWorksheet(string $token): Worksheet;  // 404s on miss
public function servePhoto(string $token, int $photoId);      // pattern to mirror
```

From app/Http/Controllers/PublicSurveyController.php line 531 (servePhoto pattern):
```php
public function servePhoto(string $token, SiteSurveyPhoto $photo): \Symfony\Component\HttpFoundation\Response {
    $survey = $this->resolveSurvey($token);
    abort_unless($photo->room->site_survey_id === $survey->id, 403);
    $path = Storage::disk('local')->path($photo->storagePath());
    abort_unless(file_exists($path), 404);
    return response()->file($path, [
        'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
        'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
    ]);
}
```

From routes/web.php (existing public-worksheet block at lines 90–112):
```php
Route::get('worksheet/{token}', [PublicWorksheetController::class, 'show'])->name('public-worksheet.show');
Route::post('worksheet/{token}/sign', [PublicWorksheetController::class, 'sign'])->name('public-worksheet.sign')->middleware('throttle:10,1');
Route::post('worksheet/{token}/rooms/{room_name}/photos', [PublicWorksheetController::class, 'uploadPhoto'])->name('public-worksheet.photos.upload')->middleware('throttle:30,1')->where('room_name', '.*');
Route::get('worksheet/{token}/photos/{photo}', [PublicWorksheetController::class, 'servePhoto'])->name('public-worksheet.photos.serve');
Route::delete('worksheet/{token}/photos/{photo}', [PublicWorksheetController::class, 'deletePhoto'])->name('public-worksheet.photos.delete')->middleware('throttle:30,1');
```

From resources/views/worksheets/public-show.blade.php existing $efByRoom lookup (lines 442–495):
```blade
@php
    $efByRoom = [];
    if ($worksheet->project_id && class_exists(\App\Models\SiteSurvey::class)) {
        $survey = \App\Models\SiteSurvey::with('rooms')
            ->where('project_id', $worksheet->project_id)
            ->latest('id')
            ->first();
        if ($survey) {
            foreach ($survey->rooms as $r) {
                $key = strtolower(trim((string) ($r->room_name ?? '')));
                if ($key === '') continue;
                $efByRoom[$key] = [...];
            }
        }
    }
@endphp
```
The Task 3 view edit MUST extend this existing block (replace `with('rooms')` with `with('rooms.photos')` and add a parallel `$photosByRoom` map keyed by lowercase room_name → Collection of SiteSurveyPhoto). Do NOT introduce a second SiteSurvey lookup.

From resources/views/worksheets/public-show.blade.php existing teal Survey Reference drawer:
- Drawer is rendered per room around line 643–782, gated by `@if($hasEF)`.
- Drawer body uses inline styles only (NO new CSS classes per the 260504-dh8 precedent).
- The teal accent colour used is `#178A95`.
- Existing `class="actions"` is the bullet list helper, `class="room-drawer"` / `class="room-drawer-body"` are the drawer chrome.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Schema migration + Worksheet model fillable/cast</name>
  <files>database/migrations/{TIMESTAMP}_add_pre_install_confirmations_to_worksheets_table.php, app/Models/Worksheet.php</files>
  <action>
**1a) Create the migration file** at `database/migrations/{TIMESTAMP}_add_pre_install_confirmations_to_worksheets_table.php` — use a timestamp later than `2026_05_03_120000` (the most recent migration). Concretely use `2026_05_04_120000`. Mirror the structure of `2026_04_26_000001_add_access_token_to_worksheets_table.php` (anonymous class returning Migration; `up()` with `Schema::table('worksheets', fn ($t) => $t->json('pre_install_confirmations')->nullable()->after('access_token'))`; `down()` drops the column).

The `up()` method MUST NOT default the column — leave it nullable with no default; null means "no rooms reviewed yet" and is the expected state for legacy worksheets. NO backfill is needed (legacy rows correctly stay null).

The `down()` method MUST drop the column inside a `Schema::table('worksheets', ...)` block; mirror the existing dropColumn pattern.

**1b) Extend `app/Models/Worksheet.php`:**
- Add `'pre_install_confirmations'` to the `$fillable` array — place it immediately after `'access_token'` to keep the order consistent with the migration column order.
- Inside `casts()` at lines 76–83, add `'pre_install_confirmations' => 'array'` — place it immediately after `'generated_data' => 'array'`.

NO other changes to the Worksheet model. Do NOT add helper methods like `isRoomReviewed()` — the controller + view handle the lookup directly via the array cast.

Run the migration locally to verify it applies cleanly: `php artisan migrate`. Verify the column exists with: `php artisan tinker --execute="dump(Schema::hasColumn('worksheets', 'pre_install_confirmations'));"` (expect `true`).
  </action>
  <verify>
    <automated>php artisan migrate &amp;&amp; php artisan tinker --execute="echo (Schema::hasColumn('worksheets','pre_install_confirmations')?'OK':'FAIL').PHP_EOL;"</automated>
  </verify>
  <done>Column `worksheets.pre_install_confirmations` (JSON, nullable, default null) exists. `Worksheet::$fillable` lists it. `Worksheet::casts()` returns `'array'` for it. `down()` cleanly drops the column. Legacy rows have NULL — backward-compatible.</done>
</task>

<task type="auto">
  <name>Task 2: Controller methods + routes (serveSurveyPhoto + markSurveyReviewed)</name>
  <files>app/Http/Controllers/PublicWorksheetController.php, routes/web.php</files>
  <action>
**2a) Add two new methods to `app/Http/Controllers/PublicWorksheetController.php`:**

Insert these two methods immediately AFTER the existing `servePhoto()` method (around line 144, between `servePhoto` and the `// ─── Sign ───` divider). Keep the existing method dividers intact and add a new `// ─── Survey reference (photos + per-room review) ──────────────────────` divider before the new pair.

Method A — `serveSurveyPhoto(string $token, \App\Models\SiteSurveyPhoto $photo)`:
- Resolve worksheet via existing `$this->resolveWorksheet($token)`.
- Cross-project guard: `abort_unless($photo->room?->survey?->project_id === $worksheet->project_id, 403);` — a leaked token must NOT be able to serve photos from a survey on a different project. The `?->` chain handles defensively-broken records (orphan photo with no room, room with no survey, survey with no project_id) — they all yield null and trigger 403.
- Mirror the file-serving body from PublicSurveyController::servePhoto (line 531): `$path = \Illuminate\Support\Facades\Storage::disk('local')->path($photo->storagePath());`, `abort_unless(file_exists($path), 404);`, `return response()->file($path, ['Content-Type' => $photo->mime_type ?? 'image/jpeg', 'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"']);`.
- Use route-model binding (type-hint `SiteSurveyPhoto $photo`) — Laravel resolves the {photo} segment to a model automatically. Add `use App\Models\SiteSurveyPhoto;` at the top of the file alongside the existing `use` statements.
- Return type: `\Symfony\Component\HttpFoundation\Response`.
- PHPDoc comment: `GET /worksheet/{token}/survey-photos/{photo}` — Stream a SiteSurveyPhoto belonging to the same project as this worksheet. Cross-project guard prevents a leaked token from serving photos from a different project's survey.

Method B — `markSurveyReviewed(\Illuminate\Http\Request $request, string $token, string $roomName)`:
- Resolve worksheet via existing `$this->resolveWorksheet($token)`.
- Build the inclusion list of valid room names from the worksheet's generated_data:
  ```php
  $validRoomNames = collect((array) ($worksheet->generated_data['rooms'] ?? []))
      ->pluck('name')
      ->filter()
      ->values()
      ->all();
  abort_if(empty($validRoomNames), 422, 'Worksheet has no rooms — cannot mark a room reviewed.');
  ```
- Validate the {roomName} URL segment via `\Illuminate\Validation\Rule::in($validRoomNames)`. Because Laravel passes the URL segment as a string, do this manually:
  ```php
  if (! in_array($roomName, $validRoomNames, true)) {
      abort(422, 'Unknown room name.');
  }
  ```
  This is the forged-room-name guard called out in the constraints.
- Update the JSON column:
  ```php
  $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
  $now = now();
  $confirmations[$roomName] = [
      'reviewed_at' => $now->toIso8601String(),
      'reviewed_by' => substr($token, 0, 8),
  ];
  $worksheet->pre_install_confirmations = $confirmations;
  $worksheet->save();
  ```
- Return a redirect back to the worksheet show page with a flash message — full-page reload pattern (per constraint "Pick the simpler full-form-post path for reliability"):
  ```php
  return redirect()
      ->route('public-worksheet.show', ['token' => $token])
      ->with('success', "Survey reviewed for: {$roomName}");
  ```
- Return type: `\Illuminate\Http\RedirectResponse`.
- PHPDoc: `POST /worksheet/{token}/rooms/{roomName}/survey-reviewed` — Record that an engineer has reviewed the site-survey reference for a specific room. Validates roomName against the worksheet's own generated_data rooms list — forged names rejected with 422. Updates worksheet.pre_install_confirmations JSON (array-keyed by roomName).

Do NOT modify any existing controller method bodies. Do NOT touch the existing `servePhoto`, `uploadPhoto`, `deletePhoto`, `sign`, `uploadLabelPhoto`, `confirmLabelPhoto`, `deleteLabelPhoto`, or `resolveWorksheet` methods.

**2b) Add two new routes to `routes/web.php` inside the existing public-worksheet block (lines 90–112).**

Insert these AFTER the `Route::delete('worksheet/{token}/photos/{photo}', ...)` line (around line 102) and BEFORE the `// Device label photo capture` comment (around line 104). Group them with a comment divider:

```php
// Survey-reference photos + per-room review-confirmation gate (engineer cross-checks
// survey findings before sign-off). Cross-project access prevented in the controller.
Route::get('worksheet/{token}/survey-photos/{photo}', [PublicWorksheetController::class, 'serveSurveyPhoto'])
    ->name('public-worksheet.survey-photos.serve')->middleware('throttle:120,1');
Route::post('worksheet/{token}/rooms/{roomName}/survey-reviewed', [PublicWorksheetController::class, 'markSurveyReviewed'])
    ->name('public-worksheet.survey-reviewed')->middleware('throttle:60,1')
    ->where('roomName', '.*');
```

The `->where('roomName', '.*')` is REQUIRED — room names contain spaces (e.g. "Boardroom Floor 1") which Laravel rejects by default. Mirror the existing `where('room_name', '.*')` pattern at line 98.

Do NOT modify any existing route registrations. Do NOT add the routes outside the public-worksheet block. Do NOT wrap in `Route::middleware(...)` — the throttle is per-route.

Verify routes load: `php artisan route:list --name=public-worksheet.survey` — expect 2 lines (survey-photos.serve + survey-reviewed).
  </action>
  <verify>
    <automated>php -l app/Http/Controllers/PublicWorksheetController.php &amp;&amp; php -l routes/web.php &amp;&amp; php artisan route:list --name=public-worksheet.survey 2>&amp;1 | grep -E "survey-photos\.serve|survey-reviewed" | wc -l | xargs -I{} test {} = 2 &amp;&amp; echo OK</automated>
  </verify>
  <done>`PublicWorksheetController` has new `serveSurveyPhoto` + `markSurveyReviewed` methods with cross-project + room-name-inclusion guards. Two new named routes registered (`public-worksheet.survey-photos.serve` GET throttle:120,1, `public-worksheet.survey-reviewed` POST throttle:60,1 + where('roomName','.*')). PHP lints clean. Existing routes/methods untouched.</done>
</task>

<task type="auto">
  <name>Task 3: View — extend Survey Reference drawer with photos strip + review form, soft-gate Sign-Off button</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
This is the biggest task — three coordinated edits to the same view file.

**3a) Extend the existing per-page `@php` lookup block (lines 442–495) to ALSO load room photos:**

Currently line 455 reads:
```php
$survey = \App\Models\SiteSurvey::with('rooms')
```
Change to:
```php
$survey = \App\Models\SiteSurvey::with(['rooms', 'rooms.photos'])
```

Inside the existing `foreach ($survey->rooms as $r)` loop (line 478), in addition to the existing `$efByRoom[$key] = [...]` assignment, add a parallel:
```php
$photosByRoom[$key] = $r->photos ?? collect();
```

Initialise `$photosByRoom = [];` immediately next to the existing `$efByRoom = [];` line (around line 452).

ALSO inside the same outer `if ($worksheet->project_id && class_exists(...))` block, AFTER the rooms loop, build the page-level "all rooms with surveys" set used by the Sign-Off gate (so a room WITHOUT a matching SiteSurveyRoom never blocks sign-off):
```php
$roomsRequiringReview = array_keys($efByRoom); // lowercase trimmed room-name keys that DO have a survey row
```
Initialise `$roomsRequiringReview = [];` next to `$efByRoom = [];`.

**3b) Per-room — extend the existing teal Survey Reference drawer body** (drawer body opens at line 649 `<div class="room-drawer-body">`).

INSERT TWO NEW BLOCKS:

Block A — Survey Photos strip — placed at the **TOP of the drawer body**, immediately after the opening `<div class="room-drawer-body">` (line 649) and BEFORE the existing `{{-- Mounting heights --}}` comment (line 651). Use this exact structure:

```blade
{{-- ── Survey photos for this room (260504-hqe) ──
     Token-gated proxy serves SiteSurveyPhoto rows linked to the same project.
     If the room has no survey photos, render the muted "no photos" line instead. --}}
@php $surveyPhotos = $photosByRoom[$efKey] ?? collect(); @endphp
<div style="margin-bottom:.85rem;">
    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.4rem;">Survey photos</div>
    @if($surveyPhotos->isEmpty())
        <div class="muted" style="font-size:.8rem;">No survey photos for this room.</div>
    @else
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
            @foreach($surveyPhotos as $sp)
                <a href="{{ route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => $sp->id]) }}"
                   target="_blank"
                   style="display:inline-block;width:80px;height:80px;border-radius:6px;overflow:hidden;background:#F3F4F6;">
                    <img src="{{ route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => $sp->id]) }}"
                         alt="{{ $sp->caption ?? '' }}"
                         loading="lazy"
                         style="width:100%;height:100%;object-fit:cover;">
                </a>
            @endforeach
        </div>
    @endif
</div>
```

Defensive: `$photosByRoom[$efKey] ?? collect()` — if survey exists but room has zero photos, $surveyPhotos is empty and the muted line renders. If survey is missing entirely, $efKey isn't in $photosByRoom and same fallback fires. Drawer never crashes.

Block B — Review confirmation footer — placed at the **BOTTOM of the drawer body**, AFTER the `{{-- Floor box info --}}` block (which ends at line 778) and BEFORE the `</div>` that closes `room-drawer-body` (line 780). Use this exact structure:

```blade
{{-- ── Review confirmation gate (260504-hqe) ──
     Soft-block: when the engineer has not yet ticked "I have reviewed",
     this room is flagged in $unreviewedRooms (page-level set computed below)
     and the page-level Sign-Off button is visually disabled.
     Once submitted, the row is stamped {reviewed_at, reviewed_by} and a green
     badge replaces the form. Gate is visual-only — the server does NOT block
     sign-off if a room is unreviewed. --}}
@php
    $confirmations = (array) ($worksheet->pre_install_confirmations ?? []);
    $thisRoomReview = $confirmations[$room['name']] ?? null;
@endphp
<div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #E5E7EB;">
    @if($thisRoomReview)
        @php
            $rTime = $thisRoomReview['reviewed_at'] ?? null;
            $rBy   = $thisRoomReview['reviewed_by'] ?? '';
            try { $rDisplay = $rTime ? \Carbon\Carbon::parse($rTime)->format('d M Y H:i') : ''; }
            catch (\Throwable $e) { $rDisplay = (string) $rTime; }
        @endphp
        <div style="display:inline-block;padding:.4rem .75rem;border-radius:9999px;background:#DCFCE7;color:#166534;font-size:.8rem;font-weight:600;">
            ✓ Reviewed by {{ $rBy }} at {{ $rDisplay }}
        </div>
    @else
        <form method="POST"
              action="{{ route('public-worksheet.survey-reviewed', ['token' => $token, 'roomName' => $room['name']]) }}"
              style="display:flex;flex-wrap:wrap;align-items:center;gap:.65rem;">
            @csrf
            <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:#374151;">
                <input type="checkbox" required style="width:1rem;height:1rem;">
                I have reviewed the survey for this room
            </label>
            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.78rem;">Mark Reviewed</button>
        </form>
    @endif
</div>
```

The `required` on the checkbox prevents an accidental empty submit (browser-level, no JS). The form posts via standard form-submit → controller → redirect → page reload → green badge appears.

**3c) Per-room sign-off-gate accumulator + page-level Sign-Off button soft-disable**

Before the `@foreach($rooms as $idx => $room)` loop (around line 585), add a fresh `@php` block to build the unreviewed-rooms set ONCE:

```blade
@php
    // ── Sign-off gate (260504-hqe) — collect rooms that have a survey but
    //    have NOT yet been reviewed. The set drives the soft-disable on the
    //    page-level Sign-Off button. Visual-only gate — never blocks server.
    $confirmations    = (array) ($worksheet->pre_install_confirmations ?? []);
    $unreviewedRooms  = [];
    foreach ($rooms as $r) {
        $rName = (string) ($r['name'] ?? '');
        if ($rName === '') continue;
        $rKey  = strtolower(trim($rName));
        $hasSurveyForThisRoom = in_array($rKey, $roomsRequiringReview, true);
        if ($hasSurveyForThisRoom && empty($confirmations[$rName])) {
            $unreviewedRooms[] = $rName;
        }
    }
    $signOffBlocked = ! empty($unreviewedRooms);
@endphp
```

Then locate the existing Sign-Off `<form>` submit button (around line 962–onwards — the form posts to `public-worksheet.sign`). Find the submit `<button type="submit">` that submits the sign-off form (currently somewhere after line 988). Add `@disabled($signOffBlocked)` to that button (Laravel @disabled directive — emits `disabled` attribute when true). Add `title="{{ $signOffBlocked ? 'Review the survey for all rooms first: ' . implode(', ', $unreviewedRooms) : '' }}"` for the tooltip.

ALSO add a visible warning banner ABOVE the sign-off form (inside the `<div class="card">` that opens around line 954, right after the `<div class="card-title">Client Sign-Off</div>`):

```blade
@if($signOffBlocked)
    <div style="margin-bottom:.85rem;padding:.7rem .9rem;border-radius:6px;background:#FEF3C7;color:#92400E;font-size:.85rem;">
        ⚠ Review the survey reference for these rooms before signing off:
        <strong>{{ implode(', ', $unreviewedRooms) }}</strong>.
        Open each room above, expand <em>📋 Survey Reference</em>, and tap <em>Mark Reviewed</em>.
    </div>
@endif
```

If the engineer's project has no survey at all (`$roomsRequiringReview === []`), `$unreviewedRooms` stays empty and `$signOffBlocked` is false — the banner doesn't render and the Sign-Off button is fully enabled. **This is the legacy-worksheet backward-compat path.**

**3d) Defensive room-name lookup — already handled by the existing 260504-dh8 pattern.** The existing `$efByRoom` lookup uses `strtolower(trim(...))` keys (line 479). The new `$photosByRoom` we add MUST use the same key — we already specified `$photosByRoom[$key] = $r->photos` inside the SAME loop, so it inherits the same key. The new `$roomsRequiringReview = array_keys($efByRoom)` and the foreach in 3c also use `strtolower(trim($rName))` for matching. Consistent throughout — case-insensitive + trim-tolerant.

**Constraints reminder:**
- Do NOT add new CSS classes — use inline styles only (per the 260504-dh8 precedent).
- Do NOT modify the existing Site Logistics drawer (lines 528–578).
- Do NOT modify the photo tray (lines 919–948) or any drawer except the teal Survey Reference drawer.
- Do NOT modify the Kit List drawer, AV Works drawer, Install Steps drawer, or sign-off form fields — only the SUBMIT BUTTON gets `@disabled` + `title`, and a warning banner is INSERTED above the form.
- Do NOT introduce JS / fetch / AJAX — pure server-side form post + page reload.

Run `php artisan view:cache` to confirm Blade compiles clean.
  </action>
  <verify>
    <automated>php artisan view:cache 2>&amp;1 | tee /tmp/view-cache.log; grep -qE "(error|Exception|ERROR)" /tmp/view-cache.log &amp;&amp; { echo "VIEW CACHE FAILED"; exit 1; } || echo OK</automated>
  </verify>
  <done>
- `public-show.blade.php` compiles clean via `php artisan view:cache`.
- Survey Reference drawer body now opens with a Survey Photos strip (clickable thumbs OR muted "No survey photos for this room") and closes with a review form OR green ✓ Reviewed badge depending on state.
- A `$signOffBlocked` flag is computed once before the rooms loop; the page-level Sign-Off submit button gets `@disabled($signOffBlocked)` + tooltip; a warning banner renders above the sign-off form when blocked.
- Backward compat: a worksheet whose project has no survey shows zero new visual changes to the sign-off form.
- No new CSS classes added; only inline styles.
  </done>
</task>

</tasks>

<verification>

## Manual smoke tests (no automated test suite per the spec)

After the 3 tasks land, perform these checks against a local dev DB:

### Smoke 1 — Legacy worksheet (project with no SiteSurvey)
- Open `/worksheet/{token}` for a worksheet whose project has no SiteSurvey row.
- Survey Reference drawer is hidden (existing `@if($hasEF)` already gates it — 260504-dh8 behaviour preserved).
- Sign-Off button is **enabled**, no warning banner above it.
- Sign-off flow works exactly as before.

### Smoke 2 — Worksheet with survey, no rooms reviewed yet
- Open `/worksheet/{token}` for a worksheet whose project has a recent SiteSurvey with engineer-feedback data on at least one room AND survey photos on that room.
- Expand the room → Survey Reference drawer opens.
- Top of drawer shows Survey Photos as 80×80 thumbnails. Click one — opens the full-size photo in a new tab (URL is `/worksheet/{token}/survey-photos/{photoId}`).
- Bottom of drawer shows the "I have reviewed the survey for this room" checkbox + Mark Reviewed button.
- A yellow warning banner appears above the page-level Sign-Off form listing this room.
- The page-level Sign-Off submit button is visually disabled with tooltip.

### Smoke 3 — Submit per-room review
- Tick the checkbox + click Mark Reviewed → page reloads with success flash.
- The drawer footer now shows a green ✓ Reviewed by {token_prefix} at {timestamp} badge.
- If only one room had a survey, the warning banner disappears and the Sign-Off button is enabled.
- Inspect the DB row: `SELECT pre_install_confirmations FROM worksheets WHERE id = ?` — JSON contains `{"<RoomName>": {"reviewed_at": "...", "reviewed_by": "abc12345"}}`.

### Smoke 4 — Cross-project photo block
- Identify a SiteSurveyPhoto on a DIFFERENT project than the current worksheet's project.
- Hit `/worksheet/{thisWorksheetToken}/survey-photos/{otherProjectsPhotoId}` directly.
- Server returns 403 (cross-project guard fires).

### Smoke 5 — Forged room name block
- POST to `/worksheet/{token}/rooms/Atlantis/survey-reviewed` (room not in worksheet's generated_data).
- Server returns 422 — JSON column unchanged.

### Smoke 6 — Photo with broken room/survey link
- Manually corrupt a SiteSurveyPhoto in the DB (set site_survey_room_id to NULL or a deleted row).
- Hit the survey-photos.serve route for that photo via a valid token.
- Server returns 403 (the `?->` chain in the cross-project guard yields null → guard fires).

### Schema migration sanity
- `php artisan migrate:rollback` removes the column cleanly.
- `php artisan migrate` re-applies cleanly.

## File footprint audit

```
$ git diff --stat HEAD~3 HEAD -- {target paths}
```
Expect EXACTLY 5 files changed:
- `database/migrations/2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php` (new)
- `app/Models/Worksheet.php`
- `app/Http/Controllers/PublicWorksheetController.php`
- `routes/web.php`
- `resources/views/worksheets/public-show.blade.php`

## Forbidden-paths audit

```
$ git diff --stat HEAD~3 HEAD -- app/Services/ resources/views/site-survey/ \
    resources/views/pdf/ resources/views/worksheets/show.blade.php \
    app/Models/SiteSurvey.php app/Models/SiteSurveyRoom.php \
    app/Models/SiteSurveyPhoto.php app/Http/Controllers/PublicSurveyController.php
```
Expect EMPTY — only the engineer link is in scope; site-survey models, services, PDF templates, admin views, and authenticated worksheet show page must NOT be touched.

</verification>

<success_criteria>

Quick task 260504-hqe is complete when ALL of the following hold:

1. Migration `2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php` exists, applies cleanly, and rolls back cleanly.
2. `Worksheet::$fillable` lists `pre_install_confirmations`; `Worksheet::casts()` casts it to `array`.
3. `PublicWorksheetController::serveSurveyPhoto` and `PublicWorksheetController::markSurveyReviewed` exist; both PHPDoc'd; both gated by token + per-method-specific guard (cross-project for photos, room-name-inclusion for review).
4. Two new named routes registered: `public-worksheet.survey-photos.serve` (GET, throttle:120,1) and `public-worksheet.survey-reviewed` (POST, throttle:60,1, where roomName .*).
5. View `public-show.blade.php` compiles via `php artisan view:cache` — no Blade errors.
6. Survey photos render as 80×80 thumbnails at top of the existing teal Survey Reference drawer (when survey + photos exist).
7. Per-room review form OR green ✓ Reviewed badge renders at the bottom of the drawer.
8. Page-level Sign-Off button is soft-disabled (visual only — `@disabled` + tooltip + warning banner) when any room in the worksheet has an unreviewed survey.
9. Legacy worksheets with NULL `pre_install_confirmations` AND no SiteSurvey row render zero visual change to the sign-off section.
10. File footprint exactly 5 files; forbidden-paths diff is empty.
11. PHP lints clean on all modified PHP files (`php -l ...`).
12. The 6 smoke tests above pass on a local dev DB.

</success_criteria>

<output>
After completion, create `.planning/quick/260504-hqe-engineer-link-enhancement-survey-photos-/260504-hqe-SUMMARY.md` with:
- frontmatter: quick_id, mode=quick, type=summary, status=complete, completed_at, duration_minutes, commits[], files_modified[], file_count, line_delta, deviations[]
- "What changed" section with one bullet per modified file (concise, factual)
- "File footprint audit" with the `git diff --stat` outputs proving the 5-file footprint and empty forbidden-paths
- "Render smoke tests" with the 6 manual smoke results
- "Files to upload to live" — list of all 5 modified files
- "Commands to run on live" — `php artisan migrate` (CRITICAL — schema change), then `php artisan view:clear`, then `php artisan route:clear`
- "Deviations from plan" — list any deviations OR "None — plan executed exactly as written"
- "Self-Check: PASSED" — confirm files exist + commits exist + 5-file footprint
</output>
