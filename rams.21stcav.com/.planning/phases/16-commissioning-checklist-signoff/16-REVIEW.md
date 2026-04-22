---
phase: 16-commissioning-checklist-signoff
reviewed: 2026-04-22T00:00:00Z
depth: standard
files_reviewed: 29
files_reviewed_list:
  - app/Exceptions/CommissioningSignoffException.php
  - app/Http/Controllers/CommissioningController.php
  - app/Http/Controllers/CommissioningItemController.php
  - app/Http/Controllers/CommissioningResyncController.php
  - app/Http/Controllers/CommissioningSignoffController.php
  - app/Http/Requests/FailCommissioningItemWithEvidenceRequest.php
  - app/Http/Requests/FinaliseCommissioningSignoffRequest.php
  - app/Http/Requests/UpdateCommissioningItemNotesRequest.php
  - app/Http/Requests/UpdateCommissioningItemStatusRequest.php
  - app/Http/Requests/UploadCommissioningItemPhotoRequest.php
  - app/Models/CommissioningItem.php
  - app/Models/CommissioningSignoff.php
  - app/Observers/InstallTaskObserver.php
  - app/Services/CommissioningItemGenerator.php
  - app/Services/CommissioningPdfService.php
  - app/Services/CommissioningPhotoService.php
  - app/Services/CommissioningService.php
  - app/Services/CommissioningSyncService.php
  - app/Services/DocumentArtifactStorage.php
  - config/commissioning.php
  - database/migrations/2026_04_22_000001_create_commissioning_items_table.php
  - database/migrations/2026_04_22_000002_create_commissioning_signoffs_table.php
  - resources/views/commissioning/_commissioning-fail-sheet.blade.php
  - resources/views/commissioning/_commissioning-signoff-sheet.blade.php
  - resources/views/commissioning/_item-row.blade.php
  - resources/views/commissioning/_resync-diff.blade.php
  - resources/views/commissioning/show.blade.php
  - resources/views/pdf/commissioning-snagging.blade.php
  - routes/web.php (cross-referenced for route names)
findings:
  critical: 0
  warning: 4
  info: 6
  total: 10
status: issues_found
---

# Phase 16: Code Review Report

**Reviewed:** 2026-04-22T00:00:00Z
**Depth:** standard
**Files Reviewed:** 29
**Status:** issues_found

## Summary

Phase 16 delivers the commissioning checklist, per-item status / photo / notes mutation, D-14 atomic fail-with-evidence, D-04 re-sync, and the D-16 preview → sign → finalise transaction. The overall shape is strong: service boundaries are clean, the finalise transaction uses `lockForUpdate` + a DB-level UNIQUE constraint to serialise the race, INST-05i immutability is enforced in both the HTTP layer and the re-sync service, PNG bytes are validated before storage, and Blade output is consistently escaped. Photo paths are server-generated (no traversal surface), and DomPDF runs with `isRemoteEnabled=false` + `isPhpEnabled=false`.

The review did surface one functional bug (preview PDF URL 404s because the download route only serves finalised signoffs — no preview branch exists), a transactional data-loss edge case on the W-12 fail-with-evidence rollback path, a missing upper bound on signature payload size, and a pair of consistency issues in the fail-path notes handling.

## Warnings

### WR-01: Preview snagging PDF URL cannot be served — `downloadSnagging` has no preview branch

**File:** `app/Http/Controllers/CommissioningSignoffController.php:53-67, 100-115`
**Issue:** `preview()` generates `snagging_programme_{id}_{ts}_preview.pdf` via `CommissioningPdfService::buildPreview()` and returns a URL built with `route('commissioning.snagging.show', ['programme' => $programme, 'v' => 'preview', 'file' => $filename])`. However, the `downloadSnagging` handler bound to `commissioning.snagging.show` ignores the `v` / `file` query-string parameters entirely — it loads `$programme->commissioningSignoff` and `abort_if($signoff === null, 404, ...)`. Before finalise there is no signoff row, so the iframe at step 1 of the signoff sheet will always receive a 404 and the "Generating preview…" → error path in the Alpine factory will trigger (`previewError` is only surfaced if the initial POST fails, not if the iframe src 404s). Net effect: engineers cannot actually preview the snagging PDF before asking the client to sign — the core D-10 "review before sign" journey is broken.
**Fix:** Either (a) add a preview branch to `downloadSnagging` that, when `request('v') === 'preview'`, validates the `file` query-param matches the `snagging_*_preview.pdf` naming convention and streams it from `DocumentArtifactStorage::TYPE_SNAGGING` (with the same ownership guard), or (b) introduce a dedicated `commissioning.snagging.preview` route + controller action:

```php
// Option B — dedicated preview route
Route::get('install-programmes/{programme}/snagging/preview/{file}',
    [CommissioningSignoffController::class, 'streamPreview'])
    ->where('file', 'snagging_programme_\\d+_\\d{8}_\\d{6}_preview\\.pdf')
    ->name('commissioning.snagging.preview');

public function streamPreview(InstallProgramme $programme, string $file): BinaryFileResponse
{
    $this->authorise($programme);
    // Defence-in-depth: ensure file belongs to this programme before serving.
    abort_unless(str_starts_with($file, "snagging_programme_{$programme->id}_"), 404);
    $path = $this->artifacts->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $file);
    abort_if($path === null, 404);
    return response()->file($path, [
        'Content-Type'  => 'application/pdf',
        'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
    ]);
}
```

Then update `preview()` to build the URL against the new route name.

### WR-02: `failWithEvidence` rollback path permanently destroys the previously attached evidence photo

**File:** `app/Http/Controllers/CommissioningItemController.php:178-208`
**Issue:** Inside the `DB::transaction`, `photoService->store(...)` is called first; internally (see `CommissioningPhotoService::store()` lines 80-90) it `@unlink()`s the old `evidence_photo_path` file from disk as soon as the new one is written. If `$item->save()` then throws inside the transaction, the DB rollback restores the old `evidence_photo_path` column value on the model state in memory (the model row on disk never changed), but the catch block only deletes the NEW file — the OLD file has already been wiped out-of-band. The next page load finds a stale DB path pointing to a file that no longer exists, and the engineer has silently lost their prior evidence.
**Fix:** Defer the old-file cleanup until after the controller transaction commits. Two options:

```php
// Option A — return the old path from store() and let the caller clean up on commit
public function store(CommissioningItem $item, UploadedFile $file): array
{
    // ... existing code, but REMOVE the @unlink block ...
    return ['new' => $relative, 'old' => $item->evidence_photo_path];
}

// In the controller:
DB::transaction(function () use (...) {
    $paths = $this->photoService->store($item, $file);
    // ... mutate + save $item ...
    return $paths;
});
// After commit — safe to drop the old file
if ($paths['old'] && $paths['old'] !== $paths['new']) {
    @unlink(Storage::disk('local')->path($paths['old']));
}
```

Option B — copy-on-write naming so `store()` never touches the old file; a nightly sweeper reaps orphans. Either way the critical invariant is: no destructive filesystem mutation inside a DB transaction that might roll back.

### WR-03: Signature PNG payload has no upper-bound — DoS / log-spam risk

**File:** `app/Http/Requests/FinaliseCommissioningSignoffRequest.php:39-44`
**Issue:** The `signature_png_base64` rule is `required|string|min:100|regex:...` with no `max:`. `longText` in MySQL accepts up to 4GB. A malicious client (even an authenticated engineer) could POST a several-megabyte base64 body. The service then `base64_decode()`s the whole thing into memory, runs `preg_replace` over it, and stores it in `signature_png_base64` for every snagging PDF render (DomPDF inlines the data URI). Realistic iPad Retina signatures are 30-60 KB per the migration docblock, so a 2 MB cap is comfortably above the real ceiling and still protects the host from single-request memory blow-up and bloated PDF sizes.
**Fix:** Add an explicit max on the FormRequest and reject oversized payloads loudly:

```php
'signature_png_base64' => [
    'required',
    'string',
    'min:100',
    'max:2097152',   // 2 MB of base64 — ~1.5 MB decoded PNG, well above iPad signature reality
    'regex:#^(data:image/png;base64,)?[A-Za-z0-9+/=\s]+$#',
],
```

Consider mirroring the same cap in `CommissioningService::assertValidPngBase64` as belt-and-braces if the payload ever arrives from a non-FormRequest path later.

### WR-04: Notes handling diverges between the two fail paths — data loss through the atomic endpoint

**File:** `app/Http/Controllers/CommissioningItemController.php:76-91` (two-step path appends)
**File:** `app/Http/Controllers/CommissioningItemController.php:184` (atomic path overwrites)
**Issue:** `updateStatus()`, when called with `status=fail`, preserves any pre-existing `$item->notes` and appends the fail reason with a `\n\n[Fail reason] ` separator (W-10 — intentional behaviour per the docblock). `failWithEvidence()`, however, does `$item->notes = $note;` — overwriting any notes the engineer had already typed into the free-text notes box before tapping Fail. Because the Alpine factory routes ALL fail clicks through the fail-sheet → `/fail-with-evidence` endpoint (see `patchStatus` in `show.blade.php` line 221-231 — the direct-PATCH fail branch is now dead code at the UI layer), engineers in practice lose any pre-typed notes on every fail.
**Fix:** Make the two paths consistent. Either apply the same append logic inside `failWithEvidence`:

```php
$existing = trim((string) $item->notes);
$item->notes = $existing === ''
    ? $note
    : $existing . "\n\n[Fail reason] " . $note;
```

Or drop the append logic from `updateStatus` (since the UI never reaches it for fails) and document that `notes` is authoritative from the fail-sheet. The append branch is the better user-experience choice since engineers demonstrably type notes first, then flip status.

## Info

### IN-01: `PATCH /commissioning-items/{item}/notes` has no server-side error surface

**File:** `resources/views/commissioning/show.blade.php:313-324` (client)
**Issue:** `patchNotes()` in the Alpine factory fires the PATCH and does not await / check the response. If the server rejects (INST-05i immutability, validation failure, 500), the UI shows no feedback and the engineer believes the note saved. Minor because the notes input is not on-screen in the current row UI (only preview display), but as soon as the inline-edit follow-up lands this will surface as a silent data loss.
**Fix:** Mirror `handleStatusResponse()` — inspect `res.ok`, show an inline error under the notes input on failure, and roll the on-screen preview back to the prior value.

### IN-02: `CommissioningPdfService` renders HTML that includes DB timestamp formatting into PDF — acceptable but worth verifying

**File:** `resources/views/pdf/commissioning-snagging.blade.php:34, 123`
**Issue:** `now()->setTimezone('Europe/London')->format(...)` and `$signoff->signed_at->setTimezone(...)` are hard-coded to Europe/London. If the system is ever deployed to a client with a different locale, the snagging PDF will lie about the sign-off time to tablets in other zones. The rest of the codebase uses the same convention (Phase 14 precedent), so this is consistent — just note that the timezone is a codebase-wide assumption.
**Fix:** Extract to `config('app.display_timezone', 'Europe/London')` so a single edit can re-scope multi-tenant deployments later. Cosmetic for now.

### IN-03: `CommissioningItemController::updateStatus` has two `auth()->user()` calls — minor duplication

**File:** `app/Http/Controllers/CommissioningItemController.php:101, 116`
**Issue:** Every status mutation resolves `auth()->user()` twice (once for `$item->signed_off_by = auth()->user()->name`, once implicitly through `auth()->id()` in the log call). The helper runs its own middleware resolution each time. Style-only; functional behaviour is correct.
**Fix:** Cache once at the top of the method: `$user = auth()->user(); ... $item->signed_off_by = $user->name; ... Log::info(..., ['user_id' => $user->id]);`.

### IN-04: `CommissioningService::finalise` PDF orphan on rollback

**File:** `app/Services/CommissioningService.php:107-109`
**Issue:** `buildFinal()` is called inside `DB::transaction` and does `file_put_contents(...)` before the `snagging_pdf_path` column update. If a later step (e.g. the `$project->update(...)` or `$programme->update(...)`) throws, the transaction rolls back but the PDF file is already on disk. The docblock acknowledges this as acceptable, but an explicit cleanup in the outer `catch` would keep `storage/app/documents/snagging/` tidy:

```php
$finalFilename = null;
try {
    return DB::transaction(function () use (...) use (&$finalFilename) {
        // ... existing body ...
        $finalFilename = $this->pdfService->buildFinal($programme, $signoff);
        // ...
    });
} catch (\Throwable $e) {
    if ($finalFilename !== null) {
        $this->artifacts->delete(DocumentArtifactStorage::TYPE_SNAGGING, $finalFilename);
    }
    throw $e;
}
```

**Fix:** Optional — implement the orphan-cleanup wrapper above, or add an `artisan commissioning:prune-orphans` command as a longer-term hygiene task.

### IN-05: `CommissioningResyncController::resync` does not lock the programme during diff

**File:** `app/Services/CommissioningSyncService.php:52-111`
**Issue:** The resync transaction reads `withTrashed()` items and writes back without `lockForUpdate()`. A concurrent fail-with-evidence PATCH hitting `$item->save()` mid-resync could set `$item->status = fail` on a row that this service is about to soft-delete (because the source task no longer matches), or vice-versa. Practical impact is low (resync is only triggered by a deliberate engineer tap; concurrent engineer activity on the same programme is rare) but defence-in-depth would be `InstallProgramme::lockForUpdate()` at the top of the resync transaction to serialise against the finalise path.
**Fix:** Add the same `lockForUpdate` pattern used by `CommissioningService::finalise`:

```php
DB::transaction(function () use ($programme, ...) {
    InstallProgramme::where('id', $programme->id)->lockForUpdate()->firstOrFail();
    // ... existing diff body ...
});
```

### IN-06: Dead `preview_url` query-string parameters

**File:** `app/Http/Controllers/CommissioningSignoffController.php:60-65`
**Issue:** `['v' => 'preview', 'file' => $filename]` are appended to the route URL but never read by the controller (see WR-01). Even after WR-01 is fixed, `v=preview` will be redundant if a dedicated preview route is introduced. Clean these up once the preview endpoint lands.
**Fix:** Remove unused query parameters from the `route()` call once WR-01 has been resolved.

---

_Reviewed: 2026-04-22T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
