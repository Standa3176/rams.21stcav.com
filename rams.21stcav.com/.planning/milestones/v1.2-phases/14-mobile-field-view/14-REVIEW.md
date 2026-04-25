---
phase: 14-mobile-field-view
reviewed: 2026-04-20T12:00:00Z
depth: standard
files_reviewed: 32
files_reviewed_list:
  - rams.21stcav.com/app/Exceptions/ClockInBlockedException.php
  - rams.21stcav.com/app/Http/Controllers/InstallProgrammeController.php
  - rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php
  - rams.21stcav.com/app/Http/Controllers/TaskStatusController.php
  - rams.21stcav.com/app/Http/Controllers/TimeEntryController.php
  - rams.21stcav.com/app/Models/InstallTask.php
  - rams.21stcav.com/app/Models/InstallTaskPhoto.php
  - rams.21stcav.com/app/Models/TimeEntry.php
  - rams.21stcav.com/app/Providers/AppServiceProvider.php
  - rams.21stcav.com/app/Services/HeicImageConverter.php
  - rams.21stcav.com/app/Services/TaskPhotoService.php
  - rams.21stcav.com/app/Services/TimeEntryService.php
  - rams.21stcav.com/composer.json
  - rams.21stcav.com/database/factories/InstallProgrammeFactory.php
  - rams.21stcav.com/database/factories/InstallTaskFactory.php
  - rams.21stcav.com/database/factories/InstallTaskPhotoFactory.php
  - rams.21stcav.com/database/factories/TimeEntryFactory.php
  - rams.21stcav.com/database/migrations/2026_04_20_000001_create_install_task_photos_table.php
  - rams.21stcav.com/database/migrations/2026_04_20_000002_create_time_entries_table.php
  - rams.21stcav.com/database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php
  - rams.21stcav.com/resources/views/components/install-task/photo-upload.blade.php
  - rams.21stcav.com/resources/views/install-programmes/_field-room.blade.php
  - rams.21stcav.com/resources/views/install-programmes/_field-sheet.blade.php
  - rams.21stcav.com/resources/views/install-programmes/_field-task-row.blade.php
  - rams.21stcav.com/resources/views/install-programmes/field.blade.php
  - rams.21stcav.com/routes/web.php
  - rams.21stcav.com/tests/Feature/FieldView/FieldPageTest.php
  - rams.21stcav.com/tests/Feature/FieldView/FieldViewResponsivenessTest.php
  - rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskNotesTest.php
  - rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php
  - rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php
  - rams.21stcav.com/tests/Feature/TimeEntries/TimeEntryTest.php
  - rams.21stcav.com/tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php
  - rams.21stcav.com/tests/Unit/Migrations/TimeEntriesSchemaTest.php
  - rams.21stcav.com/tests/Unit/Services/HeicImageConverterTest.php
findings:
  critical: 0
  warning: 5
  info: 10
  total: 15
status: issues_found
---

# Phase 14: Code Review Report

**Reviewed:** 2026-04-20T12:00:00Z
**Depth:** standard
**Files Reviewed:** 32 (includes composer.json context file)
**Status:** issues_found (advisory, non-blocking)

## Summary

Overall, Phase 14 delivers a solid mobile field view with strong security fundamentals: thin controllers delegating to services (matches project convention), UUID-based filenames preventing path traversal, server-side MIME detection (never trusts client), ownership guards on every mutation endpoint, CSRF coverage on all AJAX paths, and a race-safe one-open-entry guard using DB transaction + `lockForUpdate`. Blade output is consistently escaped and Alpine state is populated via `@js()` where it matters. Tests exercise the core positive and negative paths (403 for unrelated users, 422 for invalid states, traversal-sanitisation fixture, double-clock-in rejection).

The issues below are largely defence-in-depth, UX edge cases, and coverage gaps. **No critical vulnerabilities or data-loss bugs were found.** The clock-in guard, photo upload pipeline (including HEIC fail-loud behaviour), and status state machine all look correct. The scope-all engineer → photo 403 mismatch (WR-01) is the most visible user-facing issue and should probably be fixed before wide rollout, but it is a UX bug rather than a security hole.

Phase 15 will extend time entries with heartbeat/category/close-stale-sessions; this review did not evaluate anything deferred to that phase.

## Warnings

### WR-01: Scope=all field view shows photo thumbnails that 403 for non-assigned engineers

**File:** `rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php:195-202`
**Also:** `rams.21stcav.com/resources/views/install-programmes/_field-task-row.blade.php:119`

**Issue:** `InstallProgrammeController::field()` gates access with "is assigned to at least one task on the programme" (line 267-269). An engineer with scope=all then sees every task row in the programme, and every task row includes `<x-install-task.photo-upload>` which renders existing photos as `<img src="/install-task-photos/{id}">`. But `TaskPhotoController::authoriseTaskMutation()` requires the user be the task's `assigned_to` (not just any assigned engineer on the programme). Result: an engineer viewing scope=all sees broken thumbnails (403) for any photo on a task they are not personally assigned to. Same logic blocks them from opening the lightbox, uploading, or deleting — but the upload UI and caption input are still rendered, giving false affordance.

The mismatch is between the page-level gate ("any task assigned on programme") and the photo-level gate ("this specific task assigned to me"). Either tighten `_field-task-row` to hide the photo component when the task isn't personally assigned (and hide "Add photo" button), or broaden the photo ownership guard to "any task assigned on the same programme".

**Fix:**
```php
// TaskPhotoController::authoriseTaskMutation — option A (broaden view, keep mutation strict)
private function authoriseTaskView(InstallTask $task): void
{
    $user = auth()->user();
    $project = $task->programme->project;
    $isOwnerOrAdmin = $project->user_id === $user->id || $user->isAdmin();
    if ($isOwnerOrAdmin) return;

    // Any engineer assigned on this programme may VIEW any photo on the programme.
    $hasAnyAssigned = InstallTask::where('install_programme_id', $task->install_programme_id)
        ->where('assigned_to', $user->id)
        ->exists();
    abort_if(! $hasAnyAssigned, 403);
}
// …and call authoriseTaskView() in show(), authoriseTaskMutation() in store/update/destroy.
```
Alternative (Option B): in `_field-task-row.blade.php`, wrap `<x-install-task.photo-upload>` in `@if ($isOwnerOrAdmin || $task->assigned_to === auth()->id())` so scope=all engineers get a read-only row for non-assigned tasks without any photo UI at all.

---

### WR-02: Blade `@js`/attribute interpolation bug — room names with apostrophes break Alpine bindings

**File:** `rams.21stcav.com/resources/views/install-programmes/_field-room.blade.php:17`

**Issue:** The aria-label is rendered inside an Alpine attribute binding:
```blade
:aria-label="open ? 'Collapse {{ $roomName }}' : 'Expand {{ $roomName }}'"
```
Blade's `{{ }}` HTML-encodes, so `$roomName = "Alice's Office"` becomes `Alice&#039;s Office` in the emitted attribute. The browser then HTML-decodes the attribute value before Alpine's JS-expression parser sees it → the literal becomes `'Collapse Alice's Office'`, which is a JavaScript syntax error (unterminated string). Alpine will swallow the error silently and the button gets no aria-label. It is also theoretically an XSS vector: a hostile `room_name` with `'+alert(1)+'` embedded would become executable JS inside the Alpine expression. In practice `room_name` comes from quote/survey data so hostile payloads are unlikely, but defence-in-depth matters here.

The correct pattern (used elsewhere in this phase, e.g. `_field-task-row.blade.php:36`) is to pre-serialise the value with `@js()` and concatenate in JS:
```blade
:aria-label="(open ? 'Collapse ' : 'Expand ') + @js($roomName)"
```

**Fix:**
```blade
<button type="button"
        ...
        :aria-label="(open ? 'Collapse ' : 'Expand ') + @js($roomName)"
        :aria-expanded="open.toString()"
        @click="open = !open">
```

---

### WR-03: Octet-stream fallback + allowed extension = bypass of `mimetypes` validation

**File:** `rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php:79-92`

**Issue:** The `mimetypes:` validation rule accepts `application/octet-stream` (intentional — iOS Safari HEIC Pitfall 3). The secondary defence at lines 79-92 only invokes `mime_content_type()` content-sniffing when the file extension is NOT in the allowed list (`jpg|jpeg|png|webp|heic|heif`). So a file named `payload.jpg` whose real contents are, say, `application/x-php` or `text/html` can pass if Laravel's `mimetypes` detector returns `application/octet-stream` for the content (which can happen for arbitrary binary data, short HTML snippets, or obfuscated payloads).

Because the downstream store does NOT execute the file (it writes to storage/app/private and serves via response()->file with the detected mime), the practical exploit surface is narrow — the worst case is storing an HTML file that a signed-in user later downloads via the show route. `response()->file` sends `Content-Disposition: inline` with the original filename; the Content-Type from `mime_type` column is set from a post-upload `mime_content_type()` call so HTML would be served as `text/html` inline, giving a stored-XSS vector against whichever user views the photo.

Mitigation: always run the content-sniff, not only when the extension is unknown. Assert `$finfo` starts with `image/`.

**Fix:**
```php
// Always content-sniff, regardless of extension
$original = $request->file('photo');
$finfo = @mime_content_type($original->getRealPath()) ?: '';
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/webp',
    'image/heic', 'image/heif',
    'image/heic-sequence', 'image/heif-sequence',
];
// Allow octet-stream only if extension sniff agrees it's an image type we handle
if (! str_starts_with($finfo, 'image/')) {
    $ext = strtolower($original->getClientOriginalExtension());
    $heicLike = in_array($ext, ['heic', 'heif'], true)
        && in_array($finfo, ['application/octet-stream', ''], true);
    abort_unless($heicLike, 422, 'Only photos (JPG, PNG, WEBP, HEIC) are allowed.');
}
```

---

### WR-04: Shared-device privacy leak — photo responses cached by URL, not by user

**File:** `rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php:181-185`

**Issue:** `show()` sets `Cache-Control: private, max-age=3600`. `private` keeps the image out of shared CDN caches but still permits the user-agent's own disk cache to retain it for an hour. On a site-provided tablet passed between engineers (realistic field scenario — "here, log in to check your tasks"), the first user fetches `/install-task-photos/42`, logs out, the second user logs in, the browser serves the cached response from disk without round-tripping — the second user sees the first user's photo regardless of auth. Disk cache is keyed by URL, not by cookie.

Since photos can contain client site details (racks, cable runs, whiteboards with network diagrams), this is meaningful privacy/confidentiality exposure. Route thumbnails via `no-store` or `no-cache, must-revalidate`. If you want fast thumbnail loads for the same session, use `private, max-age=0, must-revalidate` so the browser always pings the server and the 304/403 gate runs.

**Fix:**
```php
return response()->file($path, [
    'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
    'Content-Disposition' => 'inline; filename="'.$photo->original_name.'"',
    'Cache-Control'       => 'private, no-cache, must-revalidate',
    'Pragma'              => 'no-cache',
]);
```

---

### WR-05: Orphaned files on DB failure after upload

**File:** `rams.21stcav.com/app/Services/TaskPhotoService.php:68-85`

**Issue:** `store()` writes the file to disk via `$this->converter->writeAsJpeg()` first, then calls `InstallTaskPhoto::create()`. If the DB insert fails (constraint violation, connection drop, disk-full on the DB side), the file stays on disk with no row referencing it. Over time this accumulates as storage bloat and a forensic nightmare ("whose photo is this?"). Low probability but trivially fixed by wrapping in a transaction and using `register_shutdown_function` / `try/finally` to unlink on failure.

**Fix:**
```php
// In TaskPhotoService::store()
$this->converter->writeAsJpeg($file, $absolutePath);
try {
    $finalMime = @mime_content_type($absolutePath) ?: 'image/jpeg';
    $originalName = $this->sanitiseOriginalName($file->getClientOriginalName());
    $photo = InstallTaskPhoto::create([
        'install_task_id' => $task->id,
        'filename'        => $relativePath,
        'original_name'   => $originalName,
        'mime_type'       => $finalMime,
        'caption'         => null,
        'sort_order'      => ($task->photos()->max('sort_order') ?? 0) + 1,
    ]);
} catch (\Throwable $e) {
    @unlink($absolutePath); // prevent orphan
    throw $e;
}
```

---

## Info

### IN-01: No rate limiting on status / notes / photo-mutation endpoints

**File:** `rams.21stcav.com/routes/web.php:299-318`

**Issue:** `install-tasks.status` (PATCH), `install-tasks.notes` (PATCH), and the photo `update`/`destroy`/`show` routes have no `throttle:` middleware. `install-task-photos.store` has `throttle:60,1`, and `time-entries.start/stop` have `throttle:30,1`. A malicious (or broken) client could flood notes saves (5 KB each) or status PATCHes. Recommend `throttle:120,1` on notes/status and `throttle:60,1` on photo update/destroy for parity with the store endpoint.

**Fix:** Add `->middleware('throttle:120,1')` to status and notes routes; `->middleware('throttle:60,1')` to photo update/destroy.

---

### IN-02: Counter calculation is eventually-consistent under concurrent writes

**File:** `rams.21stcav.com/app/Http/Controllers/TaskStatusController.php:151-171`

**Issue:** `counters()` issues four separate COUNT queries after the task update, outside any transaction. Two engineers on the same room marking tasks complete simultaneously will each see their own update but may miss the other's. The displayed counter in each browser pulses to a stale value briefly before the next PATCH arrives. Not a correctness bug (eventual consistency is fine for progress counters), but note that the room counter on the requester's UI is authoritative-for-them-only until the page is reloaded. Acceptable for Phase 14 scope.

**Fix:** None required for Phase 14. If needed in Phase 16 ("team progress dashboard"), add a broadcast channel or Livewire poll for shared refresh.

---

### IN-03: `started_at` never set when status jumps pending → complete

**File:** `rams.21stcav.com/app/Http/Controllers/TaskStatusController.php:68-70`

**Issue:** `'started_at' => $task->started_at ?? ($next === STATUS_IN_PROGRESS ? now() : null)`. If a user taps from pending → complete directly (possible via the Alpine `advance()` chain only through two taps, but directly reachable via API), `started_at` stays null while `completed_at` is set. Reports joining task durations from `completed_at - started_at` will show null/negative values. Arguably intentional (it was never in progress), but it's inconsistent: `completed_at` is always set on completion, but `started_at` is not always set on start-or-completion.

**Fix (optional):**
```php
'started_at' => $task->started_at
    ?? (in_array($next, [STATUS_IN_PROGRESS, STATUS_COMPLETE], true) ? now() : null),
```

---

### IN-04: `completed_at` silently cleared when transitioning complete → blocked

**File:** `rams.21stcav.com/app/Http/Controllers/TaskStatusController.php:70`

**Issue:** `'completed_at' => $next === STATUS_COMPLETE ? now() : null`. A complete → blocked transition will wipe the original `completed_at`, losing the "this was done at 3pm on Tuesday" data if someone later discovers a problem and re-marks blocked. The status_changed_at audit row captures the transition but the audit trail UI is deferred. Consider keeping `completed_at` untouched when transitioning away from complete (only nulling it on reopen → in_progress, which is the only legitimate "this wasn't actually done" path).

**Fix (optional):**
```php
// Only clear completed_at on explicit reopen to in_progress
'completed_at' => $next === STATUS_COMPLETE
    ? now()
    : ($task->status === STATUS_COMPLETE && $next === STATUS_IN_PROGRESS
        ? null
        : $task->completed_at),
```

---

### IN-05: SQLite `lockForUpdate()` is a no-op — guard relies on transaction serialisation

**File:** `rams.21stcav.com/app/Services/TimeEntryService.php:41-46`

**Issue:** Documented in the comment. On SQLite (test env) `lockForUpdate` is a no-op, so the race-safety guarantee is only tested under MySQL (prod). `test_double_clock_in_rejected` passes on SQLite because the two requests run sequentially, not because the lock works. Suggest adding a CI job or a dedicated MySQL feature test that uses Laravel's `DB::transaction` with parallel pthreads/processes to actually exercise concurrent start attempts. Not blocking for Phase 14.

**Fix (optional):** Add MySQL-gated test in Phase 15.

---

### IN-06: Test coverage gap — no test for `application/octet-stream` HEIC upload (Pitfall 3)

**File:** `rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php`

**Issue:** RESEARCH.md Pitfall 3 explicitly calls out iOS Safari reporting `application/octet-stream` for HEIC. No test covers this path. `test_heic_converts_to_jpeg` uses `image/heic` MIME literal, which is the easy case. Recommend a variant that passes `'application/octet-stream'` with a `.heic` extension to prove the detectMime fallback ladder works end-to-end.

**Fix:**
```php
public function test_heic_via_octet_stream_upload_converts(): void
{
    if (! extension_loaded('imagick')) $this->markTestSkipped('no imagick');
    Storage::fake('local');
    [$user, $task] = $this->scaffold();
    $file = new UploadedFile(
        base_path('tests/Fixtures/sample.heic'),
        'IMG_0001.HEIC',
        'application/octet-stream', // iOS Safari path
        null, true,
    );
    $response = $this->actingAs($user)->post("/install-tasks/{$task->id}/photos", ['photo' => $file]);
    $response->assertCreated();
    $this->assertSame('image/jpeg', InstallTaskPhoto::first()->mime_type);
}
```

---

### IN-07: Test coverage gap — no test for admin photo access on non-owned project

**File:** `rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php`

**Issue:** All `InstallTasks/*Test` scaffolds act as the project owner. There's no test that proves an admin (distinct from owner) can upload/view/delete photos. Since the admin path is a separate branch in `authoriseTaskMutation`, it's worth asserting. The field view test has admin coverage but mutations do not.

**Fix:** Add `test_admin_can_upload_and_view_photo` following the `test_admin_can_view_field_page` pattern.

---

### IN-08: Test coverage gap — scope=all shows task but photo endpoint 403s

**File:** `rams.21stcav.com/tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php`

**Issue:** Related to WR-01. Adding a test that an engineer viewing scope=all can SEE the task row for a non-assigned task but cannot fetch its photo would codify the current (inconsistent) behaviour and make the fix a green-field test update rather than a surprise regression.

---

### IN-09: `activeInstallProgramme` relation accessed as method and property inconsistently

**File:** `rams.21stcav.com/app/Http/Controllers/InstallProgrammeController.php:259` vs `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php:119`

**Issue:** `InstallProgrammeController::field()` calls `$project->activeInstallProgramme()->with([...])->first()` (method form — explicit relation query). `TimeEntryController::authoriseProjectAccess()` uses `$project->activeInstallProgramme` (property form — cached relation). Both work but the property form triggers a second query if the relation isn't eager-loaded. Minor: consider a shared helper on Project or consistent use pattern.

**Fix (optional):** Standardise on one form or add `->loadMissing('activeInstallProgramme')` in TimeEntryController.

---

### IN-10: Alpine factory duplication — `csrf()` redeclared in component and field blade

**File:** `rams.21stcav.com/resources/views/install-programmes/field.blade.php:185` and `rams.21stcav.com/resources/views/components/install-task/photo-upload.blade.php:32`

**Issue:** The photo-upload component defines `csrf()` as an Alpine method; the parent page defines `csrf()` as a top-level JS function. Both work (different scopes), but the pattern is inconsistent. Minor — if photo-upload is ever used on a non-field page the local definition keeps it self-contained. Acceptable.

---

_Reviewed: 2026-04-20T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
