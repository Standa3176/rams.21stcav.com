---
quick_id: 260602-rcd
type: execute
title: Engineer Report — enhance existing View page (no duplication) + PDF
status: complete
completed_at: 2026-06-02
tasks_completed: 4
tasks_total: 4
commits:
  - 704297e: feat(quick-260602-rcd) Task 1 — EngineerActivityService + Worksheet::hasEngineerActivity + 12 unit tests
  - 8a1d321: feat(quick-260602-rcd) Task 2 — enhance worksheets/show with engineer activity sections
  - bab0439: feat(quick-260602-rcd) Task 3 — Engineer Report PDF endpoint + Blade
  - 03ad3f4: feat(quick-260602-rcd) Task 4 — projects.show button + feature tests
files_created:
  - app/Services/EngineerActivityService.php
  - tests/Unit/Services/EngineerActivityServiceTest.php
  - tests/Unit/Models/WorksheetHasEngineerActivityTest.php
  - tests/Feature/Worksheets/EngineerReportPdfTest.php
  - resources/views/pdf/engineer-report.blade.php
files_modified:
  - app/Models/Worksheet.php
  - app/Http/Controllers/WorksheetController.php
  - resources/views/worksheets/show.blade.php
  - resources/views/projects/show.blade.php
  - routes/web.php
tests_added: 21 (12 unit + 9 feature)
tests_assertions_added: 48
canary_rams_render: 3/3 GREEN (D-12 byte-equivalence preserved)
deviations: 1 (minor — Rule 3, photo URL routing)
---

# Quick Task 260602-rcd — Engineer Report (re-scoped) Summary

Enhance the existing `worksheets/show.blade.php` admin view with 3 small
additions and add a PDF endpoint that renders the same content for print.
NOT a separate page (user pivoted away from the duplicating original plan).

## What shipped

### Task 1 — Service + accessor + unit tests (commit `704297e`)

- **New `App\Services\EngineerActivityService`** with one public method
  `buildReportContext(Worksheet $worksheet): array` returning the canonical
  4-key dict `[rooms, outstanding_items, signoffs, summary]`. Per-room
  entries have a locked 5-key shape
  `[name, survey_reviewed_at, room_completed_at, completed_photos, label_photos]`.
  Summary block is 4 keys: `[photo_count, label_count, signoff_count, has_activity]`.
  Photos are pre-fetched once + grouped by case-insensitive trimmed room
  name (matches `Worksheet::photoCountsByRoom()` normalisation) so the view
  and PDF never disagree on a room match.
- **New `Worksheet::hasEngineerActivity(): bool`** — true if ANY of:
  completed-work photos, equipment label photos, sign-offs, OR any
  `survey_review.reviewed_at` tick across any room. Drives the PDF
  endpoint's 404-no-content gate AND the disabled-button state on both
  the worksheets/show header and projects/show worksheet row.
- **Outstanding items flattening** — every `signed_with_comments` sign-off's
  `comments` text is split on newlines; each non-empty line becomes one
  outstanding-items entry. Clean sign-offs (no comments) are ignored.
- **12 unit tests / 23 assertions** lock the shape contract + truth table
  (`EngineerActivityServiceTest` covers top-level key lock, summary key
  lock, per-room key lock, case-insensitive grouping, label-photo
  grouping, line-by-line flattening, has_activity mirror;
  `WorksheetHasEngineerActivityTest` covers no-activity, completed photo,
  signoff, survey-reviewed tick, device-label-photo).

### Task 2 — Enhance worksheets/show (commit `8a1d321`)

- **Engineer Report PDF button** added to page-header actions next to
  Download / Regenerate. Renders as `<a target="_blank">` with the
  PDF route when `hasEngineerActivity` is true, otherwise as a disabled
  `<button>` with `title="No engineer activity yet"`.
- **Outstanding Items (n) card** inserted right after the Sign-Off Status
  section. Gold left-bar callout (matches site-survey/client-report
  office-note-callout chrome). Hidden when empty so clean projects don't
  carry an "Outstanding Items (0)" header.
- **Completed-Work Photos (n) sub-section** inserted inside each room
  body just before the Equipment Labels Captured block. Reuses the same
  `openPhotoLightbox` cycler as the labels section, so the interaction
  is identical. Photos served via `route('public-worksheet.photos.serve')`
  (there's no admin-only photo-serve endpoint, and the worksheet's
  `access_token` is already printed on the page in the Client Sign-Off
  Link card — the public route is the canonical photo URL).
- **`WorksheetController::show`** eager-loads `[project, signoffs, photos]`
  and injects `$context = app(EngineerActivityService::class)->buildReportContext($worksheet)`.

### Task 3 — PDF endpoint + Blade (commit `bab0439`)

- **`WorksheetController::engineerReportPdf`** streams via
  `PdfRenderService::fromBlade('pdf.engineer-report', ...)` (Browsershot
  per the 260427-qvr standard). Filename:
  `engineer-report-{project_ref|ws-id}-{YYYYMMDD}.pdf`. Authorisation
  `abort_unless(auth()->check(), 403)` (shared workspace per 260525-pyu/s8b).
  404 when `! hasEngineerActivity()`.
- **New route** `GET worksheets/{worksheet}/engineer-report.pdf` →
  `worksheets.engineer-report-pdf`, throttle `30,1`. Registered BEFORE
  the `worksheets/{worksheet}` wildcard so the dotted `.pdf` literal
  segment isn't consumed by model-binding.
- **New Blade `pdf/engineer-report.blade.php`** mirrors
  `pdf/site-survey/client-report.blade.php` for structure (per-room
  iteration + photo grid + meta-table) and `pdf/mini-om.blade.php`
  for chrome (21CAV teal `#1B7A7A` + gold `#C07000`,
  `.cover-accent-bar` linear-gradient, `.outstanding-callout` with
  gold left bar). Cover meta + summary pills + outstanding-items callout
  + per-room blocks (completed photos / label photos / label-data
  table) + sign-offs block with signature data-URI. **All photos
  base64-inlined via `App\Support\PdfImageEmbedder::dataUri()`** — same
  pattern as `pdf/site-survey/client-report.blade.php` (Browsershot 5.x
  rejects `file://`).

### Task 4 — projects.show button + feature tests (commit `03ad3f4`)

- **projects.show worksheets table** gets a new `📄 Engineer Report` button
  next to View / AI Chat. Uses the project's defined classes
  `btn btn-outline btn-sm` — **NOT** `bg-brand-teal` (undefined; per
  260601-r4c hotfix lesson). Rendered as `<a target="_blank">` when
  `hasEngineerActivity()` is true; disabled `<button>` with title tooltip
  when false. Always visible.
- **`EngineerReportPdfTest`** — 9 feature tests / 25 assertions covering:
  1. PDF returns `application/pdf` + non-empty body when activity exists
  2. PDF 404s when no engineer activity
  3. View renders Completed-Work Photos section when photos exist
  4. View does NOT render Completed-Work Photos section when no photos
  5. View renders Outstanding Items aggregate when signoffs have snags
  6. View omits Outstanding Items when clean
  7. Unauthenticated PDF request 302s to login
  8. projects.show renders DISABLED Engineer Report button + tooltip when no activity
  9. projects.show renders ENABLED Engineer Report link with PDF URL when activity exists
  PdfRenderService bound to an in-process fake that ALSO renders the
  actual Blade view (`view($view, $data)->render()`) so any Blade syntax
  errors surface in the test rather than getting swallowed by the fake.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking-routing fix] Photo URL routing for Completed-Work Photos**
- **Found during:** Task 2
- **Issue:** Plan implied `\Storage::disk('local')->url(...)` for `WorksheetPhoto::$filename` URLs, but `WorksheetPhoto` files live in the local (non-public) disk and are served via the token-gated `public-worksheet.photos.serve` route — there is no admin-only photo-serve endpoint. Using `Storage::disk('local')->url()` would have produced unloadable URLs (no public symlink for that path).
- **Fix:** Generate URLs via `route('public-worksheet.photos.serve', ['token' => $worksheet->access_token, 'photo' => $cp->id])`. The token is already printed on the same page in the Client Sign-Off Link card (visible to admins), so reusing the public serve route is safe.
- **Files modified:** `resources/views/worksheets/show.blade.php`
- **Commit:** `8a1d321`

No other deviations. Plan executed as written. No new database tables, migrations, or schema changes. No touches to public engineer link, worksheet generator, RAMS rendering, or AI/parser code.

## Verification gates

| Gate | Result |
|------|--------|
| Plan-filter union (`EngineerReport\|EngineerActivity\|WorksheetHasEngineerActivity`) | 21 passed / 48 assertions GREEN |
| RamsRenderRegression D-12 canary | 3 passed / 9 assertions GREEN (unchanged from baseline) |
| Targeted regression bundle (5 test files + canary) | 34 passed / 87 assertions GREEN |
| Full `--filter=Worksheet` suite | 163 passed + 2 failed / 380 assertions — **2 failures are pre-existing in `PublicWorksheetSignoffTest`** (verified by running baseline before Task 1 — same line:257 assertion, unchanged) |
| Route registered | `worksheets.engineer-report-pdf` confirmed via `php artisan route:list` |

## Self-Check: PASSED

All files referenced in this summary verified:
- app/Services/EngineerActivityService.php → exists
- app/Models/Worksheet.php → modified (hasEngineerActivity accessor present)
- app/Http/Controllers/WorksheetController.php → modified (engineerReportPdf + show context)
- resources/views/worksheets/show.blade.php → modified (3 inserts)
- resources/views/projects/show.blade.php → modified (Engineer Report button)
- routes/web.php → modified (engineer-report-pdf route registered)
- resources/views/pdf/engineer-report.blade.php → exists
- tests/Unit/Services/EngineerActivityServiceTest.php → exists, 7 tests
- tests/Unit/Models/WorksheetHasEngineerActivityTest.php → exists, 5 tests
- tests/Feature/Worksheets/EngineerReportPdfTest.php → exists, 9 tests

All 4 commits verified in `git log --oneline`:
- 704297e → present
- 8a1d321 → present
- bab0439 → present
- 03ad3f4 → present
