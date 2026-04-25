---
phase: 16-commissioning-checklist-signoff
plan: "03"
subsystem: commissioning
tags: [wave-2, http-endpoints, blade-views, photo-upload, immutability-guard, atomic-fail]
dependency_graph:
  requires:
    - Plan 16-01 red test scaffold (ItemStatusPatchTest, ItemNotesPatchTest, ItemPhotoUploadTest, ImmutabilityAfterSignoffTest, OwnershipGuardTest, ZeroItemsTest view half)
    - Plan 16-02 CommissioningItem + CommissioningSignoff models + CommissioningSignoffException + InstallProgramme::commissioningItems/commissioningSignoff relationships
    - Phase 14 HeicImageConverter (writeAsJpeg signature) + TaskPhotoController / TaskStatusController patterns
  provides:
    - CommissioningController with show() checklist action (INST-05c entry point)
    - CommissioningItemController with 6 per-item endpoints (4 mutations + photo.show + fail-with-evidence)
    - CommissioningPhotoService (HEIC→JPEG wrapper for commissioning evidence under commissioning-evidence/{project_id}/{item_id}/{uuid}.jpg)
    - 3 Blade views (show, _item-row, _commissioning-fail-sheet) with Alpine factory
    - 6 route registrations clustered at routes/web.php for Plan 04 append
    - 4 Form Requests (status, notes, photo, fail-with-evidence) with mimetypes + 20MB cap + enum validation
  affects:
    - Plan 16-04 can append its finalise / resync / state-transition routes in the same cluster
    - Plan 16-05 signature factory can bind to the data-role="signoff-sheet-slot" placeholder + commissioning:open-signoff-sheet custom event hook
tech_stack:
  added: []
  patterns:
    - "Manual Validator::make() in controller for multipart photo POST endpoints (storePhoto, failWithEvidence) — Phase 14 precedent so plain form POSTs return 422 JSON instead of 302 redirect."
    - "assertMutable() + authoriseEdit() pair invoked at the start of every mutating action — INST-05i + T-16-03 defence-in-depth."
    - "Shared in-place AJAX response: handleStatusResponse() in Alpine updates data-item-id row attributes directly (W-08) — preserves INST-05c AJAX UX without full reload."
    - "Atomic fail endpoint wraps photo store + notes + status transition + audit columns in a single DB::transaction with orphan-photo cleanup on throw (W-12)."
    - "authoriseEdit() extends the Phase 14 canonical abort_if with programme-scope engineer check — engineer doesn't need to be assigned to the specific install_task that spawned the item, assignment to any task on the same programme grants access."
key_files:
  created:
    - app/Http/Controllers/CommissioningController.php
    - app/Http/Controllers/CommissioningItemController.php
    - app/Http/Requests/UpdateCommissioningItemStatusRequest.php
    - app/Http/Requests/UpdateCommissioningItemNotesRequest.php
    - app/Http/Requests/UploadCommissioningItemPhotoRequest.php
    - app/Http/Requests/FailCommissioningItemWithEvidenceRequest.php
    - app/Services/CommissioningPhotoService.php
    - resources/views/commissioning/show.blade.php
    - resources/views/commissioning/_item-row.blade.php
    - resources/views/commissioning/_commissioning-fail-sheet.blade.php
  modified:
    - routes/web.php (6 routes added in a commissioning cluster before the document-edit group — Plan 04 appends its finalise / resync routes on top)
decisions:
  - "storePhoto and failWithEvidence use Validator::make() rather than the matching FormRequest classes. Rationale: plain form POSTs (no Accept: application/json header) trigger FormRequest's default 302-redirect behaviour on validation failure. The tests (ItemPhotoUploadTest::test_upload_unsupported_mime_returns_422 + test_upload_oversize_returns_422) use plain post() so they expected a 422 JSON body. Phase 14 TaskPhotoController solved this by validating in-controller; we mirror that precedent exactly. The FormRequest classes (Upload, Fail) are retained because they document the contract and can be swapped back when the consumers move to Accept: application/json everywhere."
  - "HeicImageConverter exposes writeAsJpeg(UploadedFile, string) — the plan's action snippet called convertToAbsolutePath() (a name that doesn't exist on Phase 14's shipped API). Rule 3 blocking-issue fix: used the real method. Behaviour is identical (HEIC transcode + JPEG/PNG/WebP passthrough, mkdir -p internal, fail-loud if imagick missing)."
  - "destroyPhoto() return type widened to Response|JsonResponse (from Response) so the 422 immutability-after-signoff path can return a JSON body. Other pattern would be to throw CommissioningSignoffException and let a global handler translate — but this plan's brief keeps the translation local to each action, matching updateStatus/updateNotes/storePhoto."
  - "Route cluster placement: all six Phase 16 routes land together before the document-edit group in routes/web.php. This groups the commissioning surface for readability and gives Plan 04 a clean append point (finalise + resync + state-transition routes will slot directly after the fail-with-evidence line)."
  - "Alpine factory dispatches a `commissioning:open-signoff-sheet` CustomEvent when Complete Commissioning is clicked rather than wiring the sheet inline — this keeps the Plan 03 Blade shell working standalone (event dies silently) while giving Plan 05 a clean listener hook when the signature sheet lands."
  - "In-memory orphan cleanup in failWithEvidence catch block: when DB::transaction aborts after the photo was written but before the model save committed, the catch sets $item->evidence_photo_path = $uploadedPath to make CommissioningPhotoService::delete() find the orphan; then nullifies the in-memory property so the thrown exception bubbles with a clean model state for the global handler."
metrics:
  duration_minutes: 10
  completed_date: 2026-04-22
  tasks_executed: 2
  commits: 2
  targeted_tests_green: 21
  targeted_tests_skipped: 1
  files_created: 10
  files_modified: 1
---

# Phase 16 Plan 03: Commissioning Checklist View + Per-item AJAX Endpoints Summary

Wave 2 Plan A — engineer-facing checklist page plus the five mutating AJAX endpoints (status, notes, photo upload, photo delete, atomic fail-with-evidence) plus the B-03 photo-show stream route. Every mutation respects INST-05i immutability and D-14 photo-on-fail.

## Red → Green Delta

Plan 16-03 baseline (pre-Task 1): `ItemStatusPatchTest | ItemNotesPatchTest | ItemPhotoUploadTest | ImmutabilityAfterSignoffTest | OwnershipGuardTest` — 23 failing, 1 skipped.

After Task 1 (controller + views + show route): OwnershipGuardTest[stranger view] + ZeroItemsTest[checklist view empty state] green; 21 still red.

After Task 2 (CommissioningItemController + Form Requests + photo service): **all 21 plan-scoped tests green, 1 environmental imagick skip**.

| Test class | Tests | Status |
|---|---|---|
| `OwnershipGuardTest` | 3 | green |
| `ItemStatusPatchTest` | 6 | green |
| `ItemNotesPatchTest` | 3 | green |
| `ItemPhotoUploadTest` | 5 + 1 skip | 5 green, 1 environmental imagick skip |
| `ImmutabilityAfterSignoffTest` | 4 | green |
| `ZeroItemsTest::test_checklist_view_shows_empty_state_when_no_items` | 1 of 2 | green (finalise half remains red — Plan 04 surface) |
| **Total** | **22** | **21 green + 1 skip** |

Full-filter baseline for the phase: 55 passed, 32 failed, 1 skipped (was 33 passed after Plan 02). The 22-test jump matches the Plan 03 contract exactly.

## Routes Added (6)

All six land inside the `auth` middleware group in a clustered block before the document-edit routes:

| Method | URI | Controller action | Name |
|---|---|---|---|
| GET | `projects/{project}/commissioning` | `CommissioningController::show` | `commissioning.show` |
| PATCH | `commissioning-items/{item}/status` | `CommissioningItemController::updateStatus` | `commissioning-items.status` |
| PATCH | `commissioning-items/{item}/notes` | `CommissioningItemController::updateNotes` | `commissioning-items.notes` |
| POST | `commissioning-items/{item}/photo` | `CommissioningItemController::storePhoto` | `commissioning-items.photo.store` |
| DELETE | `commissioning-items/{item}/photo` | `CommissioningItemController::destroyPhoto` | `commissioning-items.photo.destroy` |
| GET | `commissioning-items/{item}/photo` | `CommissioningItemController::show` | `commissioning-items.photo.show` |
| POST | `commissioning-items/{item}/fail-with-evidence` | `CommissioningItemController::failWithEvidence` | `commissioning-items.fail-with-evidence` |

(7 entries — the plan's frontmatter said "6 routes under auth middleware (4 mutations + photo show + fail-with-evidence)" but miscounted the GET checklist show; actual total is 7 new routes.)

## Controllers + Services + Requests — Public API

```php
class CommissioningController {
    public function show(Request $request, Project $project): View;
}

class CommissioningItemController {
    public function updateStatus(UpdateCommissioningItemStatusRequest $r, CommissioningItem $i): JsonResponse;
    public function failWithEvidence(Request $r, CommissioningItem $i): JsonResponse;  // W-12 atomic
    public function updateNotes(UpdateCommissioningItemNotesRequest $r, CommissioningItem $i): JsonResponse;
    public function storePhoto(Request $r, CommissioningItem $i): JsonResponse;  // 201
    public function destroyPhoto(CommissioningItem $i): Response|JsonResponse;  // 204 | 422
    public function show(CommissioningItem $i): BinaryFileResponse;  // B-03 stream
}

class CommissioningPhotoService {
    public function __construct(private readonly HeicImageConverter $converter);
    public function store(CommissioningItem $item, UploadedFile $file): string;  // returns relative path
    public function delete(CommissioningItem $item): void;  // idempotent; does not null the column
}
```

Form Requests:
- `UpdateCommissioningItemStatusRequest` — `status` required+in:pending,pass,fail,na; `note` nullable max 2000
- `UpdateCommissioningItemNotesRequest` — `notes` nullable max 2000
- `UploadCommissioningItemPhotoRequest` — `photo` required file mimetypes+max 20480 (retained for future JSON-accepting clients; Task 2 endpoint uses Validator::make instead)
- `FailCommissioningItemWithEvidenceRequest` — same photo rules + `note` required min 1 max 2000 (retained; endpoint uses Validator::make)

## Blade Views Created (3)

- `resources/views/commissioning/show.blade.php` — mobile-first `x-data="commissioningPage(...)"` factory with CSRF meta + fetch-based PATCH/POST. Sticky header, per-room grouping, D-13 empty state, D-14 fail bottom-sheet trigger, Complete Commissioning button with custom-event dispatch for Plan 05.
- `resources/views/commissioning/_item-row.blade.php` — `data-item-id`/`data-status` partial for each commissioning item with pass/fail/na buttons (disabled when locked).
- `resources/views/commissioning/_commissioning-fail-sheet.blade.php` — D-14 bottom sheet wiring photo + note + `confirmFailWithNoteAndPhoto()` → `/commissioning-items/{id}/fail-with-evidence`.

## Security Posture (Threat Model Alignment)

| Threat ID | Mitigation delivered |
|---|---|
| T-16-01 (post-signoff tampering) | `assertMutable()` invoked before any write on updateStatus, failWithEvidence, updateNotes, storePhoto, destroyPhoto (5 call-sites — verified via `grep -c "assertMutable(\$item)"`). All return 422 with CommissioningSignoffException::itemsImmutable message. |
| T-16-02 (malicious photo upload) | mimetypes + 20MB cap in Validator; HeicImageConverter fails loudly on malformed HEIC or missing imagick (D-11). `application/octet-stream` accepted for iOS HEIC quirk then content-sniffed server-side. |
| T-16-02b (photo path disclosure) | Stored under private `local` disk with UUID filenames, project-scoped subdir. Only accessible via `show()` which runs the same `authoriseEdit` guard; sets `Cache-Control: private, no-cache, no-store` to prevent tablet cross-user leakage (T-14-21 precedent). |
| T-16-03 (cross-tenant access) | `authoriseEdit()` guards every action — owner/admin/assigned-engineer-on-programme; OwnershipGuardTest confirms engineer-A cannot PATCH project-B items. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] HeicImageConverter API name**

- **Found during:** Task 2 (CommissioningPhotoService authoring).
- **Issue:** The plan's action snippet called `$this->converter->convertToAbsolutePath($file, $destinationAbsPath)`, but Phase 14's `App\Services\HeicImageConverter` exposes `writeAsJpeg(UploadedFile $file, string $destinationAbsPath): void` — the name in the plan doesn't exist.
- **Fix:** Used `writeAsJpeg()`. Behaviour is identical (HEIC→JPEG transcode, JPEG/PNG/WebP passthrough, mkdir -p, D-11 fail-loud on missing imagick). Signature note added to the service docblock.
- **Files modified:** `app/Services/CommissioningPhotoService.php`.
- **Commit:** `a156e28` (rolled into Task 2).

**2. [Rule 1 — Bug] FormRequest 302-redirect on plain multipart POST**

- **Found during:** Task 2 test run — ItemPhotoUploadTest::test_upload_unsupported_mime_returns_422 and test_upload_oversize_returns_422.
- **Issue:** Tests use plain `$this->actingAs(...)->post(...)`, not `postJson(...)`. A FormRequest validation failure on such requests returns a 302 redirect with session errors (standard Laravel web-form behaviour), not the 422 JSON body the tests expect. This endpoint is consumed exclusively by `fetch()` multipart uploads from the Alpine factory.
- **Fix:** Replaced `UploadCommissioningItemPhotoRequest` binding on `storePhoto` and `FailCommissioningItemWithEvidenceRequest` binding on `failWithEvidence` with manual `Validator::make()` calls returning 422 JSON unconditionally. Matches the Phase 14 `TaskPhotoController::store` precedent verbatim. The Form Request classes remain in place because they document the contract and can be re-bound once consumers standardise on Accept: application/json.
- **Files modified:** `app/Http/Controllers/CommissioningItemController.php`.
- **Commit:** `a156e28` (rolled into Task 2).

**3. [Rule 1 — Bug] destroyPhoto return type**

- **Found during:** Task 2 test run — ImmutabilityAfterSignoffTest::test_photo_delete_after_signoff_returns_422.
- **Issue:** Initial signature declared `destroyPhoto(): Response` but the method returns `JsonResponse` via `response()->json()` when the assertMutable guard catches CommissioningSignoffException::itemsImmutable. PHP's strict return-type contract refused the type mismatch, surfacing as a TypeError.
- **Fix:** Widened return type to `Response|JsonResponse`. `response()->noContent()` continues to return Symfony Response (204); the error path returns JsonResponse (422). Both satisfy the union.
- **Files modified:** `app/Http/Controllers/CommissioningItemController.php`.
- **Commit:** `a156e28` (rolled into Task 2).

No Rule 2 (missing critical functionality) or Rule 4 (architectural) events. All other plan content executed exactly as written.

## Authentication Gates

None. No external provider credentials required for this plan.

## Plan 04 Surface — Remains Red by Design

The following test classes remain red and are Plan 04's/Plan 05's to turn green:
- `SignoffFinaliseTest`, `SignoffTransactionTest`, `SignoffRaceTest`, `SnaggingPdfGenerationTest`, `StateTransitionTest`, `CommissioningPdfServiceTest`, `CommissioningServiceTest` — Plan 04 finalise flow
- `ZeroItemsTest::test_empty_programme_signoff_succeeds` — Plan 04 finalise half (view half green)
- `SignoffSheetViewTest`, `ResyncDiffTest` — Plan 05 sign-off sheet + re-sync UI

Verified via `php artisan test --filter=Commissioning` — 55 passed, 32 failed, 1 skipped (up from 33/54/1 after Plan 02).

## Known Stubs

One intentional placeholder tracked for Plan 05 wire-up:

| File | Line | Reason |
|---|---|---|
| `resources/views/commissioning/show.blade.php` | `data-role="signoff-sheet-slot"` + `openSignoffSheet()` dispatches `commissioning:open-signoff-sheet` CustomEvent | The sign-off signature sheet implementation belongs to Plan 05. The Plan 03 Blade shell provides the empty slot and event hook so Plan 05 has a clean integration point. Click does not die silently — the custom-event is dispatched; Plan 05 adds the listener. |

No production-code stubs. No hardcoded empty responses. All endpoint methods return authoritative data.

## Threat Flags

None. The plan's `<threat_model>` already enumerated the surface this plan touches; no new unplanned surface was introduced. Files added introduce no new network endpoints outside the plan's register (6 routes) and no new file-access patterns outside `commissioning-evidence/…` which is the plan's declared T-16-02b scope.

## Commits

| # | Hash | Message |
|---|---|---|
| Task 1 | `9421026` | `feat(16-03): add CommissioningController::show + checklist Blade views + route` |
| Task 2 | `a156e28` | `feat(16-03): add CommissioningItemController + form requests + photo service` |

## Self-Check: PASSED

Verified against `success_criteria`:

- [x] All 2 tasks from 16-03-PLAN.md executed.
- [x] Each task committed individually (2 commits: `9421026`, `a156e28`).
- [x] Plan-scoped tests green: OwnershipGuardTest (3/3), ItemStatusPatchTest (6/6), ItemNotesPatchTest (3/3), ItemPhotoUploadTest (5/5 + 1 environmental skip), ImmutabilityAfterSignoffTest (4/4). Total **21 green + 1 skip**.
- [x] ZeroItemsTest view half green (`test_checklist_view_shows_empty_state_when_no_items`); finalise half stays red (Plan 04).
- [x] Plan 04 surface stays red (SignoffFinaliseTest, SignoffTransactionTest, SnaggingPdfGenerationTest, StateTransitionTest, SignoffRaceTest — all red as expected).
- [x] SUMMARY.md created at `.planning/phases/16-commissioning-checklist-signoff/16-03-SUMMARY.md` (this file).
- [x] All 10 key files present on disk (5 app/ + 4 Form Requests + 1 service + 3 Blade views).
- [x] routes/web.php contains all 7 new route registrations (grep `commissioning-items.` returns 6 + `commissioning.show` returns 1).

Self-check commands:

```
$ git log --oneline -3
a156e28 feat(16-03): add CommissioningItemController + form requests + photo service
9421026 feat(16-03): add CommissioningController::show + checklist Blade views + route
b4fb1c2 docs(state): record phase 16 planning complete

$ php artisan test --filter='OwnershipGuardTest|ItemStatusPatchTest|ItemNotesPatchTest|ItemPhotoUploadTest|ImmutabilityAfterSignoffTest'
Tests: 1 skipped, 21 passed (39 assertions)
```
