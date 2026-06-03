---
quick_id: 260602-rcd
type: execute
wave: 1
depends_on: []
title: Engineer Report — enhance existing View page (no duplication)
scope: Fill the 3 actual gaps on worksheets/show + add a PDF endpoint of the same content
tasks: 4
---

# Plan — Quick Task 260602-rcd (re-scoped)

## Original scope vs pivot

The original plan built a NEW Engineer Report page. **The user noticed the existing `worksheets.show` (View) page already covers ~70% of it** — sign-off status, latest signoff card, and per-room Equipment Labels Captured with photo lightbox (lines 366-394 of `worksheets/show.blade.php`).

**Real gaps to fill** (confirmed by grep):
1. **Completed-work photos per room** — `WorksheetPhoto` rows. Engineer uploads via public link; never surfaced on the admin View.
2. **Outstanding-items aggregate** — flat list of every snag flagged across all signoffs.
3. **"Download as PDF" button** — currently only DOCX of the worksheet itself.

Plus wiring:
4. **Engineer Report PDF route** — same content as enhanced View, optimised for print, on-demand via `PdfRenderService::fromBlade`.
5. **Engineer Report button on `projects.show` worksheet rows** — always visible, greyed when no engineer activity (per user-locked decision).

No new dedicated View page. No duplication.

## Tasks

### Task 1 — Service + model accessor + unit tests
- New `App\Services\EngineerActivityService::buildReportContext(Worksheet $worksheet): array` — single source of truth feeding both the enhanced View and the PDF. Shape: `['rooms' => [['name','survey_reviewed_at','room_completed_at','completed_photos','label_photos'], ...], 'outstanding_items' => [string,...], 'signoffs' => [WorksheetSignoff,...], 'summary' => ['photo_count','label_count','signoff_count','has_activity']]`.
- New `Worksheet::hasEngineerActivity(): bool` accessor (placed after `staleSince()` ~line 287). True if photos OR labels OR signoffs OR any survey_reviewed_at exist.
- Unit tests: service shape correctness; `hasEngineerActivity` truth table (4 cases).

**Files:**
- `app/Services/EngineerActivityService.php` (new)
- `app/Models/Worksheet.php` (modify — add `hasEngineerActivity()`)
- `tests/Unit/Services/EngineerActivityServiceTest.php` (new)
- `tests/Unit/Models/WorksheetHasEngineerActivityTest.php` (new)

### Task 2 — Enhance worksheets/show.blade.php (the gaps)
- At top, after Sign-Off Status: new **"Outstanding Items ({count})"** card listing every snag from every signoff. Hide when empty.
- Inside each room (alongside the existing Equipment Labels Captured block at lines 383-394): new **"Completed-Work Photos ({count})"** sub-section using the same thumbnail-grid + lightbox pattern. Reuse `openPhotoLightbox` JS.
- New **"📄 Engineer Report PDF"** button in page-header actions (next to Download DOCX / Regenerate). Disabled with tooltip "No engineer activity yet" when `hasEngineerActivity` is false.
- `WorksheetController::show` adds eager-loads + injects `$context = app(EngineerActivityService::class)->buildReportContext($worksheet)`.

**Files:**
- `resources/views/worksheets/show.blade.php` (modify — 3 small inserts)
- `app/Http/Controllers/WorksheetController.php` (modify — `show()` eager-load + context)

### Task 3 — PDF endpoint + PDF Blade
- New `WorksheetController::engineerReportPdf(Worksheet $worksheet): Response` — authed (`abort_unless(auth()->check(), 403)`), `abort_if(! $worksheet->hasEngineerActivity(), 404)`.
- Calls `PdfRenderService::fromBlade('pdf.engineer-report', $context)` then `response()->streamDownload()` with sanitised filename `engineer-report-{project_ref|worksheet_id}-{YYYYMMDD}.pdf`.
- New `resources/views/pdf/engineer-report.blade.php` — print-optimised, A4 portrait. MIRROR `resources/views/pdf/site-survey/client-report.blade.php` for structure (per-room iteration + photo grid via `PdfImageEmbedder::dataUri`) and `pdf/mini-om.blade.php` for chrome (21CAV teal + gold). Photos inlined as base64 (Browsershot 5.x rejects file://). Outstanding-items aggregate at top; signoffs at bottom.
- New route: `GET worksheets/{worksheet}/engineer-report.pdf` → `worksheets.engineer-report-pdf`. Throttle: 30/min. Inserted BEFORE the `worksheets.show` wildcard.

**Files:**
- `app/Http/Controllers/WorksheetController.php` (modify — add method)
- `routes/web.php` (modify — add route)
- `resources/views/pdf/engineer-report.blade.php` (new)

### Task 4 — Project show button + feature tests
- `resources/views/projects/show.blade.php` worksheet-row actions (~line 1188): add small **"📄 Engineer Report"** button alongside View / AI Chat. `btn btn-outline btn-sm` (project convention — NOT `bg-brand-teal` per 260601-r4c). Always visible. When `$worksheet->hasEngineerActivity()` is false → render as `<button disabled title="No engineer activity yet">`. When true → `<a href="{worksheets.engineer-report-pdf}" target="_blank">`.
- Feature tests:
  1. PDF endpoint returns non-empty `application/pdf` when activity exists (skip-when-Browsershot-unavailable pattern).
  2. PDF endpoint 404s when worksheet has no engineer activity.
  3. View page renders Completed-Work Photos section when worksheet has photos.
  4. View page renders Outstanding Items aggregate when signoffs have items.
  5. Authorization: unauthenticated request to PDF endpoint 302s to login.
  6. Project show: Engineer Report button is disabled when no activity, enabled when activity exists.

**Files:**
- `resources/views/projects/show.blade.php` (modify — button)
- `tests/Feature/Worksheets/EngineerReportPdfTest.php` (new)

## Constraints

- Shared-workspace authorization per 260525-pyu/s8b — `abort_unless(auth()->check(), 403)`, no new admin gate.
- Use `PdfRenderService::fromBlade()` (Browsershot, current standard per 260427-qvr).
- Use ONLY defined Tailwind utilities or the existing `.btn btn-teal/outline/sm` custom classes — NO `bg-brand-teal` (undefined per 260601-r4c).
- Photos in PDF via `PdfImageEmbedder::dataUri()` (base64) — same pattern as `pdf/site-survey/client-report.blade.php`.
- Reuse existing `openPhotoLightbox` JS for completed-work photos (don't reimplement).
- RamsRenderRegression D-12 byte-equivalence stays green (no touches to document rendering).
- Mobile-first View enhancements.

## Out of scope

- No separate "Engineer Report" HTML view page (user's pivot — enhanced View covers it).
- No per-engineer filtering / cross-project activity feed.
- No new database table or migration.
- No touches to public engineer link, worksheet generator, RAMS rendering, or AI/parser code.

## Verification gates

- Pest filter `EngineerReport|EngineerActivity|WorksheetHasEngineerActivity` returns all-green.
- RamsRenderRegression D-12 canary 3/3 GREEN (verify before + after).
- Manual visual: open worksheets/show for a worksheet with photos+labels+signoffs → see all 3 sections.
- Manual visual: open the PDF endpoint → renders cleanly with photos inline.
