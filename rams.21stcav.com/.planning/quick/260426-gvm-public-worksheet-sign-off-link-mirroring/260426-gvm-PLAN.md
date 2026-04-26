---
phase: 260426-gvm-quick
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php
  - database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php
  - app/Models/Worksheet.php
  - app/Models/WorksheetSignoff.php
  - app/Http/Controllers/PublicWorksheetController.php
  - app/Http/Controllers/WorksheetController.php
  - routes/web.php
  - resources/views/worksheets/public-show.blade.php
  - resources/views/worksheets/show.blade.php
  - app/Services/WorksheetDocxService.php
  - tests/Feature/Worksheet/PublicWorksheetSignoffTest.php
autonomous: true
requirements:
  - QUICK-260426-gvm

must_haves:
  truths:
    - "Each worksheet has a unique UUID access_token generated on creation"
    - "GET /worksheet/{token} returns 200 for a valid token and renders a read-only worksheet view"
    - "GET /worksheet/{token} returns 404 for an unknown token"
    - "POST /worksheet/{token}/sign with a valid name + signature image stores a worksheet_signoffs row, sets signed_at, and records signed_with_comments + comments when supplied"
    - "Re-submitting POST /worksheet/{token}/sign after a prior signoff appends a new row (does not overwrite the existing latest signoff)"
    - "Once at least one signoff exists, the public show view displays a 'Signed by {name} on {date}' banner with comments rendered if present"
    - "After regeneration, the worksheet DOCX 'ENGINEER SIGN-OFF' section embeds the latest signoff signature image (PNG bytes) and comment block"
    - "POST /worksheet/{token}/sign is rate-limited via the throttle:10,1 middleware"
    - "Authenticated worksheet show page exposes the public 'Client Link' URL with copy-to-clipboard so the PM can share it"
    - "The existing /worksheets/{worksheet} admin show route, download route, and DOCX pipeline continue to work unchanged"
  artifacts:
    - path: "database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php"
      provides: "access_token UUID column with unique index on worksheets"
      contains: "Schema::table('worksheets'"
    - path: "database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php"
      provides: "worksheet_signoffs table — append-only, FK to worksheets, signature_png_base64 + client_name + signed_with_comments + comments + signed_at"
      contains: "Schema::create('worksheet_signoffs'"
    - path: "app/Models/Worksheet.php"
      provides: "boot() hook generating access_token UUID, signoffs() hasMany, latestSignoff(), isSigned(), publicUrl() helpers"
      contains: "static::creating"
    - path: "app/Models/WorksheetSignoff.php"
      provides: "WorksheetSignoff Eloquent model with worksheet() belongsTo, signed_at cast, signature data-uri accessor"
      contains: "class WorksheetSignoff"
    - path: "app/Http/Controllers/PublicWorksheetController.php"
      provides: "public show() and sign() methods, UUID-token gate via resolveWorksheet()"
      contains: "class PublicWorksheetController"
    - path: "resources/views/worksheets/public-show.blade.php"
      provides: "Mobile-friendly read-only worksheet view with single signature pad, name field, comments textarea, signed_with_comments checkbox, post-sign banner"
      contains: "@csrf"
    - path: "tests/Feature/Worksheet/PublicWorksheetSignoffTest.php"
      provides: "Feature tests covering token gate, sign happy path, append-on-resubmit, throttle, DOCX signature embedding"
      contains: "class PublicWorksheetSignoffTest"
  key_links:
    - from: "routes/web.php"
      to: "App\\Http\\Controllers\\PublicWorksheetController"
      via: "/worksheet/{token} (GET) and /worksheet/{token}/sign (POST throttle:10,1)"
      pattern: "PublicWorksheetController"
    - from: "app/Models/Worksheet.php"
      to: "worksheet_signoffs table"
      via: "hasMany(WorksheetSignoff::class) ordered by signed_at desc"
      pattern: "hasMany\\(WorksheetSignoff"
    - from: "app/Services/WorksheetDocxService.php"
      to: "WorksheetSignoff::signature_png_base64"
      via: "$worksheet->latestSignoff() read inside addEngineerSignOff() to embed image bytes"
      pattern: "latestSignoff"
    - from: "resources/views/worksheets/show.blade.php"
      to: "Worksheet::publicUrl()"
      via: "Client Link card with copy-to-clipboard button"
      pattern: "publicUrl"
---

<objective>
Add a public, no-auth client sign-off link to each worksheet — mirroring the existing site-survey UUID-token pattern — so clients can review the worksheet and electronically sign acceptance from any device.

Purpose: Replace the current paper / "engineer's clipboard" hand-off model with a shareable URL the PM can email to the client. Single-signature acceptance with an optional "outstanding items / comments" textarea covers both clean and snag-list scenarios.

Output:
- New `access_token` column on `worksheets` (UUID-gated public route, mirrors site-survey).
- New `worksheet_signoffs` table — append-only audit log (matches the `commissioning_signoffs` precedent).
- New `PublicWorksheetController` exposing `GET /worksheet/{token}` and `POST /worksheet/{token}/sign`.
- New mobile-friendly `worksheets/public-show.blade.php` rendering rooms read-only with a single signature pad at the bottom.
- DOCX builder embeds the latest signature + comments inside the existing `ENGINEER SIGN-OFF` section so regenerated DOCX always reflects the latest acceptance state.
- Authenticated worksheet show page surfaces the "Client Link" URL with copy-to-clipboard for the PM.
- Feature tests covering token gate, signoff persistence, append-on-resubmit, throttle, and DOCX embedding.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Models/SiteSurvey.php
@app/Http/Controllers/PublicSurveyController.php
@app/Http/Controllers/WorksheetController.php
@app/Models/Worksheet.php
@app/Models/CommissioningSignoff.php
@database/migrations/2026_04_05_100000_add_token_fields_to_site_surveys_table.php
@database/migrations/2026_04_22_000002_create_commissioning_signoffs_table.php
@routes/web.php
@app/Services/WorksheetDocxService.php
@resources/views/worksheets/show.blade.php

<interfaces>
<!-- Pre-extracted contracts. Executor must use these directly — no codebase exploration needed. -->

From app/Models/SiteSurvey.php (the EXACT pattern to mirror in Worksheet model):
```php
use Illuminate\Support\Str;

protected static function boot(): void
{
    parent::boot();
    static::creating(function (SiteSurvey $survey): void {
        if (empty($survey->access_token)) {
            $survey->access_token = (string) Str::uuid();
        }
    });
}

public function publicUrl(): string
{
    return route('survey.show', ['token' => $this->access_token]);
}
```

From app/Http/Controllers/PublicSurveyController.php (the EXACT resolver pattern to mirror):
```php
private function resolveSurvey(string $token): SiteSurvey
{
    $survey = SiteSurvey::where('access_token', $token)->first();
    abort_if($survey === null, 404, 'Survey not found. Please check your link.');
    abort_if($survey->isTokenExpired(), 410, 'This survey link has expired. ...');
    return $survey;
}
```

From app/Models/CommissioningSignoff.php (the signature column convention to copy):
```php
// Storage column: signature_png_base64 (longText)  — base64 PNG, NO data: prefix.
// Accessor: getSignatureDataUriAttribute()         — adds 'data:image/png;base64,' prefix for <img src>.
// Cast:    'signed_at' => 'datetime'
```

From app/Models/Worksheet.php (current shape — extend, do not break):
```php
public const STATUS_PENDING    = 'pending';
public const STATUS_GENERATING = 'generating';
public const STATUS_DRAFT      = 'draft';
public const STATUS_FINAL      = 'final';
public const STATUS_FAILED     = 'failed';

protected $fillable = [
    'user_id', 'project_id', 'project_name', 'project_ref',
    'client_name', 'site_address', 'status', 'error_message',
    'generated_data', 'filename',
    'completion_email_sent_at', 'failed_email_sent_at',
];
// MUST add 'access_token' to $fillable.
```

From routes/web.php (lines 41-63 — add the worksheet block IMMEDIATELY AFTER the existing public survey block, OUTSIDE the `auth` middleware group):
```php
// Existing public survey block ends at line 63. Add new block at line ~64:
Route::get ('worksheet/{token}',          [PublicWorksheetController::class, 'show']) ->name('public-worksheet.show');
Route::post('worksheet/{token}/sign',     [PublicWorksheetController::class, 'sign']) ->name('public-worksheet.sign')->middleware('throttle:10,1');
```

From app/Services/WorksheetDocxService.php (lines 355-371 — the exact section to mutate):
```php
// ── Engineer Notes / Snags / Sign-Off ────────────────────────────────
$this->heading($section, 'ENGINEER SIGN-OFF');
$soTable = $section->addTable([...]);
$soFields = [
    ['Engineer Name', ''],
    ['Date', ''],
    ['Snags / Notes', ''],
    ['Engineer Signature', ''],
    ['Client Name', ''],
    ['Client Signature', ''],
];
// EXTEND: when $worksheet->latestSignoff() exists, replace the empty
// 'Client Name' / 'Client Signature' / 'Snags / Notes' values with the
// signoff data and embed the signature PNG via $section->addImage()
// using a tmp file path written from base64_decode($signoff->signature_png_base64).
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: DB schema + models (access_token + worksheet_signoffs)</name>
  <files>
    database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php,
    database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php,
    app/Models/Worksheet.php,
    app/Models/WorksheetSignoff.php
  </files>
  <behavior>
    - A freshly-created Worksheet has a non-null UUID `access_token` (unique constraint enforced).
    - Worksheet::publicUrl() returns the route('public-worksheet.show', ['token' => $worksheet->access_token]).
    - Worksheet::isSigned() returns false when no signoffs exist, true after one signoff is created.
    - Worksheet::latestSignoff() returns the most-recently-signed_at signoff (orderBy signed_at desc, first).
    - Creating a second WorksheetSignoff for the same worksheet succeeds — append-only, NO unique constraint on worksheet_id.
    - WorksheetSignoff::signed_at is cast to a Carbon instance.
    - WorksheetSignoff exposes a `signature_data_uri` accessor returning `'data:image/png;base64,' . $this->signature_png_base64`.
  </behavior>
  <action>
    1. Create migration `2026_04_26_000001_add_access_token_to_worksheets_table.php`:
       - `Schema::table('worksheets', fn ($t) => $t->uuid('access_token')->nullable()->unique()->after('filename'));`
       - down(): drop the column.
       - Run `php artisan migrate` against the dev DB so the next steps can use it.

    2. Create migration `2026_04_26_000002_create_worksheet_signoffs_table.php` (mirrors the commissioning_signoffs precedent BUT without the unique programme_id constraint — D-LOCKED: append-only):
       ```php
       Schema::create('worksheet_signoffs', function (Blueprint $t) {
           $t->id();
           $t->foreignId('worksheet_id')->constrained('worksheets')->cascadeOnDelete();
           $t->string('client_name', 200);
           $t->longText('signature_png_base64');           // raw base64, NO data: prefix
           $t->boolean('signed_with_comments')->default(false);
           $t->text('comments')->nullable();
           $t->timestamp('signed_at');
           $t->ipAddress('ip_address')->nullable();         // audit trail (best-effort, no PII enforcement)
           $t->string('user_agent', 500)->nullable();
           $t->timestamps();                                // standard timestamps; NO softDeletes — append-only
           $t->index('worksheet_id');
           $t->index('signed_at');
       });
       ```

    3. Create `app/Models/WorksheetSignoff.php`:
       - extends Model, no SoftDeletes, no HasFactory needed (tests can ::create directly).
       - `$fillable = ['worksheet_id','client_name','signature_png_base64','signed_with_comments','comments','signed_at','ip_address','user_agent']`.
       - `$casts = ['signed_at' => 'datetime', 'signed_with_comments' => 'boolean']`.
       - `worksheet()` belongsTo Worksheet.
       - `getSignatureDataUriAttribute(): string` returns `'data:image/png;base64,' . $this->signature_png_base64` (mirrors CommissioningSignoff convention exactly).

    4. Extend `app/Models/Worksheet.php`:
       - Add `'access_token'` to `$fillable`.
       - Add `use Illuminate\Support\Str;` import.
       - Add `boot()` (same shape as SiteSurvey::boot — see <interfaces>): generate UUID on creating when access_token is empty.
       - Add `signoffs()` hasMany WorksheetSignoff ordered by `signed_at desc`.
       - Add `latestSignoff(): ?WorksheetSignoff` returning `$this->signoffs()->first()`.
       - Add `isSigned(): bool` returning `$this->signoffs()->exists()`.
       - Add `publicUrl(): string` returning `route('public-worksheet.show', ['token' => $this->access_token])`.

    Use the SiteSurvey + CommissioningSignoff patterns shown in <interfaces> verbatim — do NOT invent new conventions. Note the route name `public-worksheet.show` is registered in Task 2 — Laravel resolves it lazily at call time, so the model can reference it before the route file is updated.
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Claude Projects/Rams2/rams.21stcav.com" &amp;&amp; php artisan migrate &amp;&amp; php artisan tinker --execute="\$w = App\Models\Worksheet::factory()->create(); echo \$w-&gt;access_token; echo PHP_EOL; var_dump((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$/', \$w-&gt;access_token));" 2&gt;&amp;1 | tail -5</automated>
  </verify>
  <done>
    Migrations run cleanly; freshly-created Worksheet has a UUID access_token; WorksheetSignoff model + relationships/helpers exist on Worksheet (`signoffs`, `latestSignoff`, `isSigned`, `publicUrl`).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Public controller + routes + read-only sign-off view + admin link</name>
  <files>
    app/Http/Controllers/PublicWorksheetController.php,
    routes/web.php,
    resources/views/worksheets/public-show.blade.php,
    resources/views/worksheets/show.blade.php,
    tests/Feature/Worksheet/PublicWorksheetSignoffTest.php
  </files>
  <behavior>
    - GET /worksheet/{token} with valid token → 200 + view contains the worksheet's project name and rooms.
    - GET /worksheet/{token} with unknown token → 404.
    - GET /worksheet/{token} when no signoffs exist → view shows the signature pad form (NOT readonly).
    - GET /worksheet/{token} when at least one signoff exists → view shows banner "Signed by {client_name} on {dd Mon yyyy}" plus comments block when present, AND the form remains accessible for a fresh signoff round.
    - POST /worksheet/{token}/sign with valid {client_name, signature_image (base64 data URL), comments?, signed_with_comments?} → creates a worksheet_signoffs row, sets signed_at = now(), strips the `data:image/png;base64,` prefix before persisting, redirects to the same show page with a success flash.
    - POST /worksheet/{token}/sign with missing client_name → 422 validation error.
    - POST /worksheet/{token}/sign with missing signature_image → 422 validation error.
    - POST /worksheet/{token}/sign with `signed_with_comments=1` AND empty comments → 422 (when checkbox is on, comments must be supplied).
    - Submitting twice for the same worksheet appends a second row — total `worksheet_signoffs` count for that worksheet === 2, and `latestSignoff()` returns the most-recent row.
    - The route `public-worksheet.sign` carries `throttle:10,1` middleware (assert via Route::getRoutes() inspection in the test).
    - The authenticated worksheet show page contains `route('public-worksheet.show', ...)` URL plus a copy-to-clipboard control.
  </behavior>
  <action>
    1. Add to `routes/web.php` IMMEDIATELY AFTER the existing `Public Survey Routes` block (line ~63, BEFORE `Route::middleware('auth')->group(...)`). Add a use-import for `App\Http\Controllers\PublicWorksheetController` near the other use-statements. New routes:
       ```php
       /*
       |---------------------------------------------------------------------
       | Public Worksheet Sign-Off Routes — no authentication required
       | UUID access token gates the link (mirrors site-survey pattern).
       |---------------------------------------------------------------------
       */
       Route::get ('worksheet/{token}',      [PublicWorksheetController::class, 'show']) ->name('public-worksheet.show');
       Route::post('worksheet/{token}/sign', [PublicWorksheetController::class, 'sign']) ->name('public-worksheet.sign')->middleware('throttle:10,1');
       ```

    2. Create `app/Http/Controllers/PublicWorksheetController.php` (mirror PublicSurveyController structurally — see <interfaces>):
       - Class extends Controller.
       - `private function resolveWorksheet(string $token): Worksheet` — looks up by access_token, abort_if(null, 404). NO expiry check (worksheets do not expire — locked design).
       - `show(string $token): View` — loads worksheet with `signoffs` (orderBy signed_at desc), returns `view('worksheets.public-show', ['worksheet' => $worksheet, 'token' => $token, 'latestSignoff' => $worksheet->latestSignoff()])`.
       - `sign(Request $request, string $token): RedirectResponse`:
         ```php
         $worksheet = $this->resolveWorksheet($token);

         $data = $request->validate([
             'client_name'          => ['required', 'string', 'max:200'],
             'signature_image'      => ['required', 'string'],   // data:image/png;base64,...
             'signed_with_comments' => ['nullable', 'boolean'],
             'comments'             => ['nullable', 'string', 'max:5000'],
         ]);

         // Conditional rule: when signed_with_comments is truthy, comments must be non-empty.
         if (filter_var($data['signed_with_comments'] ?? false, FILTER_VALIDATE_BOOL) && trim((string) ($data['comments'] ?? '')) === '') {
             return back()->withErrors(['comments' => 'Comments are required when "signed with comments" is ticked.'])->withInput();
         }

         // Strip the data:image/png;base64, prefix so DB stores raw base64 (matches CommissioningSignoff convention).
         $b64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $data['signature_image']);

         $worksheet->signoffs()->create([
             'client_name'          => $data['client_name'],
             'signature_png_base64' => $b64,
             'signed_with_comments' => filter_var($data['signed_with_comments'] ?? false, FILTER_VALIDATE_BOOL),
             'comments'             => $data['comments'] ?? null,
             'signed_at'            => now(),
             'ip_address'           => $request->ip(),
             'user_agent'           => substr((string) $request->userAgent(), 0, 500),
         ]);

         return redirect()->route('public-worksheet.show', ['token' => $token])
             ->with('success', 'Thank you — your sign-off has been recorded.');
         ```
       - PHPDoc block at top — describe routes, the no-auth/UUID gating, the append-only behaviour, and the "worksheet stays editable post-signature" semantics. Mirror the PublicSurveyController class docblock style.

    3. Create `resources/views/worksheets/public-show.blade.php` — standalone HTML (NOT extending `layouts.app`, mirror `resources/views/public-survey/show.blade.php`'s self-contained mobile-first structure):
       - `<!DOCTYPE html>` + viewport meta + csrf-token meta.
       - Inline `<style>` block: header bar (#0B3C45 teal), max-width 860px container, single-column responsive cards. Reuse the same colour tokens / layout idiom as public-survey/show.blade.php.
       - Header: "Worksheet — {{ $worksheet->project_name }}" + client_name + project_ref + site_address.
       - If `$latestSignoff`: a green banner card at top — "Signed by {{ $latestSignoff->client_name }} on {{ $latestSignoff->signed_at->format('d M Y H:i') }}" + comments block when present. The banner includes the line "This worksheet remains accessible — engineers may continue to update notes and photos." (locked design).
       - Per-room cards (read-only) iterating `$worksheet->generated_data['rooms']`: render Equipment, Install Steps, Cable Routes, Power & Network — copy the field display logic VERBATIM from `resources/views/worksheets/show.blade.php` lines ~187-302 (the executor must read that file and copy the rendering block, not invent new shapes). The room cards are read-only — no input fields, no edit affordances.
       - Sign-off card at bottom (single card, ALWAYS visible — supports re-signoff rounds):
         * `<form method="POST" action="{{ route('public-worksheet.sign', ['token' => $token]) }}">`
         * `@csrf`
         * `<input name="client_name" required>`
         * Signature pad — use a plain HTML5 `<canvas>` + tiny inline JS that captures pointer/touch events and on-submit copies the canvas `toDataURL('image/png')` value into a hidden `<input type="hidden" name="signature_image">`. DO NOT pull in a new npm package — the canvas-based pad is ~40 lines of vanilla JS and matches how the project keeps front-end footprint small.
         * Provide a "Clear" button that resets the canvas + the hidden input.
         * `<textarea name="comments" maxlength="5000">` with a `<input type="checkbox" name="signed_with_comments" value="1">` + label "I am signing with the outstanding items / comments above".
         * Submit button labelled "Sign &amp; Submit" — disabled until the canvas has been drawn on (track via a JS `dirty` flag).
         * Show validation errors via `@if($errors->any()) ... @endif` block above the form.
         * Success flash via `@if(session('success'))` banner at top.
       - All copy/labels must be consumer-facing (no internal jargon).

    4. Extend `resources/views/worksheets/show.blade.php` (admin/PM page) — append a "Client Link" card immediately after the existing Status bar (around line 165, before the room accordion @if(empty($rooms)) block):
       ```blade
       <div class="card card-sm" style="margin-bottom:1.25rem;" x-data="{ url: '{{ $worksheet->publicUrl() }}', copied: false }">
           <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:.4rem;">Client Sign-Off Link</div>
           <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
               <input type="text" :value="url" readonly
                      style="flex:1;min-width:260px;font-size:.82rem;padding:.45rem .65rem;border:1px solid var(--border);border-radius:6px;background:#fafbfc;"
                      @click="$event.target.select()">
               <button type="button" class="btn-outline btn-sm"
                       @click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 1500);"
                       x-text="copied ? '✓ Copied' : 'Copy'"></button>
               <a :href="url" target="_blank" class="btn-outline btn-sm">Open ↗</a>
           </div>
           @if($worksheet->isSigned())
               @php $sig = $worksheet->latestSignoff(); @endphp
               <div style="margin-top:.5rem;font-size:.78rem;color:#065F46;">
                   ✓ Signed by {{ $sig->client_name }} on {{ $sig->signed_at->format('d M Y H:i') }}
                   @if($sig->signed_with_comments) <span style="color:#92400E;font-weight:600;">(signed with comments)</span>@endif
               </div>
           @endif
       </div>
       ```

    5. Create `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php` with `RefreshDatabase` and the following 6 tests (all named `test_*` so PHPUnit discovers them):
       - `test_show_returns_200_with_valid_token_and_renders_project_name`
       - `test_show_returns_404_with_unknown_token`
       - `test_sign_persists_worksheet_signoff_with_correct_fields_including_stripped_base64`
       - `test_sign_with_missing_signature_or_name_returns_422`
       - `test_sign_with_signed_with_comments_true_but_empty_comments_returns_validation_error`
       - `test_resubmit_appends_a_second_signoff_and_does_not_overwrite_the_first`
       - `test_sign_route_is_throttled_to_10_per_minute` (assert via `Route::getRoutes()->getByName('public-worksheet.sign')->middleware()` contains `'throttle:10,1'`)
       Helpers: `makeWorksheet(): Worksheet` that creates a User, Project, and Worksheet with a known UUID + minimal `generated_data['rooms']` payload. Use `$worksheet->refresh()` after sign to reload signoffs.
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Claude Projects/Rams2/rams.21stcav.com" &amp;&amp; php artisan test --filter=PublicWorksheetSignoffTest 2&gt;&amp;1 | tail -25</automated>
  </verify>
  <done>
    All 6 PublicWorksheetSignoffTest cases pass. Manual smoke: visiting /worksheet/{access_token} of a real worksheet renders the public read-only view with a signature pad; signing redirects with the success banner and the admin show page now displays the green "Signed by..." line under the Client Link card.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: DOCX signature embed + DOCX-content test</name>
  <files>
    app/Services/WorksheetDocxService.php,
    tests/Feature/Worksheet/PublicWorksheetSignoffTest.php
  </files>
  <behavior>
    - When a Worksheet has at least one signoff, regenerating its DOCX produces a file whose `ENGINEER SIGN-OFF` section contains the latest signoff's `client_name`, `signed_at` (formatted), `comments` (when present), and an embedded PNG image whose bytes match `base64_decode($latestSignoff->signature_png_base64)`.
    - When a Worksheet has NO signoffs, the existing empty 6-row sign-off table is preserved exactly as it is today (regression guard — Task 3 must not break currently-generated worksheets).
  </behavior>
  <action>
    1. In `app/Services/WorksheetDocxService.php`, locate the `addEngineerSignOff()` method (the section starting at line ~355 with `$this->heading($section, 'ENGINEER SIGN-OFF');`). Refactor it to branch on `$worksheet->latestSignoff()`:
       - Inject the `Worksheet $worksheet` model into the call chain. Trace upward — find where this method is called and pass `$worksheet` through. If the existing method signature only takes `$section` + `array $data`, extend it to `addEngineerSignOff(Section $section, Worksheet $worksheet, array $data): void` and update the caller (likely `buildSection()` or similar).
       - When `$worksheet->latestSignoff()` is null → keep the current 6-row empty table verbatim (no behaviour change for unsigned worksheets — regression guard).
       - When `$worksheet->latestSignoff()` exists, render the table with these rows instead:
         ```
         | Client Name        | {client_name}                                |
         | Signed At          | {signed_at->format('d M Y H:i')}             |
         | Signed With Notes  | {signed_with_comments ? 'Yes' : 'No'}        |
         | Outstanding Items  | {comments ?? '—'}                            |
         | Client Signature   | <embedded PNG image>                         |
         ```
       - To embed the image: write the decoded PNG bytes to a tmp file via `tempnam(sys_get_temp_dir(), 'wsig_') . '.png'`, then `$row->addCell(6000)->addImage($tmpPath, ['width' => 200, 'height' => 80])`. Register the tmp path for cleanup in a finally block on the calling method (the Job will delete it after `save()`).

    2. Add ONE more test to `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php`:
       - `test_docx_regeneration_after_signoff_embeds_signature_png_bytes`:
         * Create a Worksheet with a minimal `generated_data['rooms']` payload (one room).
         * Insert a WorksheetSignoff with a known 1×1 PNG (`base64_encode(file_get_contents(__DIR__.'/../../fixtures/1x1.png'))` — generate that fixture in the test setup if it does not exist using `imagepng(imagecreate(1,1), ...)`).
         * Resolve the WorksheetDocxService from the container, call its build method directly to write the DOCX to a `Storage::fake('documents')` disk.
         * Read the DOCX bytes, unzip in-memory (DOCX is a zip), and assert that the `word/media/` directory contains a PNG file whose bytes equal the original PNG bytes (or, if exact-match is fragile due to PhpWord re-encoding, assert the decoded base64 substring is present in the document XML or that the media directory contains at least one PNG).
         * ALSO assert the rendered document XML (`word/document.xml`) contains the client_name string.
       - The regression guard (no-signoff path produces the empty 6-row table) is implicitly covered by existing WorksheetCategorySummaryTest / WorksheetPreInstallKeyingTest passing post-change. Run them as part of verify.
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Claude Projects/Rams2/rams.21stcav.com" &amp;&amp; php artisan test --filter='PublicWorksheetSignoffTest|WorksheetCategorySummaryTest|WorksheetPreInstallKeyingTest' 2&gt;&amp;1 | tail -30</automated>
  </verify>
  <done>
    The new DOCX-embedding test plus all pre-existing Worksheet feature tests pass. Manual smoke: download a regenerated DOCX of a signed worksheet — the ENGINEER SIGN-OFF section shows the client name, signed-at timestamp, the signed-with-comments flag, the comment text, and the rendered signature image instead of the blank 6-row table.
  </done>
</task>

</tasks>

<verification>
- `php artisan migrate` runs cleanly; both new migrations roll back cleanly via `php artisan migrate:rollback --step=2`.
- `php artisan test --filter=PublicWorksheetSignoffTest` — all 7 tests pass.
- `php artisan test --filter=Worksheet` — all pre-existing worksheet tests still pass (regression guard).
- Manual smoke (single browser run):
  1. Generate a worksheet via the existing PM workflow.
  2. On the worksheet show page, copy the Client Link.
  3. Open the link in an incognito window — confirm the read-only view renders + signature pad works on touch + mouse.
  4. Sign with a name + drawn signature + a comment + tick "signed with comments" — submit.
  5. Confirm redirect with success flash + banner now reads "Signed by ... on ...".
  6. Refresh the admin show page — confirm the "Client Link" card now shows the green ✓ Signed line.
  7. Click "Retry Generation" — when status returns to draft, download the DOCX and verify the ENGINEER SIGN-OFF section contains the embedded signature image + client name + comment text.
  8. Submit a second sign-off via the same public link — confirm a new worksheet_signoffs row appears (`SELECT count(*) FROM worksheet_signoffs WHERE worksheet_id = X` returns 2) and the banner now reflects the latest one.
- Authenticated `/worksheets/{id}` route, DOCX download, retry-generation, destroy — all unchanged in behaviour.
</verification>

<success_criteria>
- [ ] Worksheets gain a UUID `access_token` auto-generated on creation.
- [ ] `worksheet_signoffs` table exists with the locked schema (worksheet_id FK, client_name, signature_png_base64, signed_with_comments, comments, signed_at, ip_address, user_agent, timestamps; NO unique constraint, NO softDeletes).
- [ ] Public route `GET /worksheet/{token}` renders the read-only worksheet view; 404 on unknown token.
- [ ] Public route `POST /worksheet/{token}/sign` carries `throttle:10,1`; happy path persists a signoff and redirects with success flash; missing fields → 422; signed_with_comments=1 + empty comments → 422.
- [ ] Re-submitting appends a new row — the previous signoff is preserved (locked behaviour).
- [ ] Authenticated worksheet show page exposes the Client Link with copy-to-clipboard + open-in-new-tab.
- [ ] DOCX regeneration embeds the latest signature image and comment block in the ENGINEER SIGN-OFF section; unsigned worksheets retain the original empty 6-row table layout.
- [ ] All 7 PublicWorksheetSignoffTest cases pass; pre-existing Worksheet feature tests still pass.
- [ ] No changes to existing `/worksheets/{worksheet}` admin routes, the BuildWorksheetJob, or the WorksheetGeneratorService data shape.
</success_criteria>

<output>
After completion, create `.planning/quick/260426-gvm-public-worksheet-sign-off-link-mirroring/260426-gvm-SUMMARY.md` summarising: migrations applied, files created, files modified, test count + names, any deviations from the plan and why, and the manual smoke-test result.
</output>
