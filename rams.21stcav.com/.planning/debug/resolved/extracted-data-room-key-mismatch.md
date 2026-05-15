---
status: awaiting_human_verify
trigger: "Project package 124 (Light Forms Ltd 21CQ30451-01-OPS) renders 'No spaces detected' on the review form even though the underlying quote has a clear Boardroom room. extracted_data has room_summaries but controller expects room_overviews."
created: 2026-05-14T19:50:00Z
updated: 2026-05-14T20:25:00Z
---

## Current Focus

hypothesis: CONFIRMED — QuoteExtractorService (Claude PDF-vision) writes per-room data under `room_summaries`. ProjectPackageReviewController::show() never consults that key. The QuoteImportService::reimport path used by package 124 (revision=2) skips the room_summaries → room_overviews normalisation that ExtractQuoteJob does.
test: Defensive seed in ProjectPackageReviewController::show() — build $summaryByRoom from $raw['room_summaries'] and extend the first-load room-name list + initial overview value with it.
expecting: Section 2 renders Boardroom row with AI summary as the seed overview; existing curated-mode behaviour unchanged; room_summaries readers (PDF, DOCX, quote-import review) untouched.
next_action: User UAT — visit /project-packages/124/review and confirm Boardroom appears.

## Symptoms

expected: Review form at /project-packages/124/review shows "Boardroom" in Section 2 with OVERVIEW prose.
actual: Section 2 shows "No spaces detected. Click + Add Space to add one manually."; Equipment list shows 3 items without zone/area.
errors: None.
reproduction:
  - Visit /project-packages/124/review
  - $p = ProjectPackage::find(124); extracted_data has `room_summaries` not `room_overviews`; line_items lack `area`.
  - PDF itself contains clean OVERVIEWTITLESTART/END for Boardroom.
started: Package 124 created 2026-05-14 19:29:37, after parser fixes 3e936b0/2c7d1fa landed at 18:58. Parser fixes are unrelated.

## Eliminated

- hypothesis: ExtractQuoteJob is the producer of the bad data shape
  evidence: ExtractQuoteJob uses QuoteExtractionPrompt (just standardises equipment names; does not emit room_summaries) and mergeParsedQuoteData() at app/Jobs/ExtractQuoteJob.php:297-319 explicitly maps parser room_overviews into the AI output OR scaffolds from ai['rooms']. ExtractQuoteJob always persists room_overviews; never room_summaries-without-room_overviews.
  timestamp: 2026-05-14T19:55:00Z

## Evidence

- timestamp: 2026-05-14T19:53:00Z
  checked: Grep app/ for `room_summaries` literal.
  found: Only producer is app/Services/QuoteExtractorService.php (Claude PDF-vision extractor). Readers: resources/views/quote-import/review.blade.php, resources/views/pdf/rams.blade.php, app/Services/WordDocumentService.php, app/Services/RamsDataBuilderService.php (writer). The project-package review controller never reads room_summaries.
  implication: room_summaries is a real shape that other code relies on — must NOT delete/rename. Fix must be additive: surface room_summaries to the controller too.

- timestamp: 2026-05-14T19:54:00Z
  checked: Read app/Http/Controllers/ProjectPackageReviewController.php show() lines 52-247.
  found: Line 193 gate `if (! empty($raw['room_overviews']))` is the only path that surfaces saved rooms. First-load branch (209-223) derives room names only from (a) hardware equipment areas and (b) `$raw['rooms']`. Neither is populated by QuoteExtractorService. `room_summaries` is never consulted.
  implication: When the AI-vision path runs (QuoteImportService::import / ::reimport), the data has Boardroom inside `room_summaries[0]['room']` but the controller doesn't look there → empty room list.

- timestamp: 2026-05-14T19:55:00Z
  checked: Read app/Core/Modules/QuoteImport/QuoteImportService.php extract() lines 405-416 and import()/reimport() flow.
  found: Both import() (line 56-156) and reimport() (line 201-240) call this->extract() → QuoteExtractorService::extractFromPath() → returns Claude AI shape with `room_summaries` (not `room_overviews`). The mapping at lines 411-413 only renames line_items→equipment_list, cable_hints→[], qw_number→quote_reference. NO room_summaries → room_overviews map.
  implication: Package 124 with revision=2 most likely came through reimport() — user clicked "Re-extract Quote" on an existing package. That path lands extracted_data with room_summaries but no room_overviews.

- timestamp: 2026-05-14T19:56:00Z
  checked: All readers of room_summaries.
  found: 4 readers (quote-import/review.blade.php, pdf/rams.blade.php, WordDocumentService.php, RamsDataBuilderService.php) all read the shape `[{room, summary}, ...]`. None of them tolerate a missing key — they each check `! empty($quote['room_summaries'])` first.
  implication: Safe to add — not replace — the room_overviews scaffolding in the controller. Existing room_summaries readers stay intact.

## Resolution

root_cause: ProjectPackageReviewController::show() first-load branch derives room names only from equipment areas and `$raw['rooms']`. The Claude PDF-vision extractor (QuoteExtractorService) writes room data to `room_summaries` (shape `[{room, summary}, ...]`) and the controller never consults that key, so the review form shows "No spaces detected" even when room data is present. QuoteImportService::import and ::reimport do NOT map room_summaries → room_overviews before persisting (only ExtractQuoteJob does). Package 124 has revision=2 → went through reimport().
fix: In ProjectPackageReviewController::show(), build $summaryByRoom from $raw['room_summaries'] before the curated-vs-first-load decision. In first-load mode, append summaryByRoom keys to $allRoomNames. In the projection step, seed the per-row `overview` from $summaryByRoom[$roomName] when no saved row exists. Same excludedAreaWords filter applied so AI hallucinations like "Summary" / "Cabling" don't slip through.
verification: |
  - php -l clean on app/Http/Controllers/ProjectPackageReviewController.php
  - 6 new regression tests pass (tests/Feature/ProjectPackages/ReviewRoomSummariesSeedTest.php) covering:
    * single room seed renders + empty-state suppressed
    * multi-room seed renders all rooms
    * curated mode (room_overviews present) is NOT polluted by room_summaries
    * empty summary still surfaces the room name
    * excludedAreaWords filter applies (Cabling/General skipped)
    * end-to-end save round-trip — saved row becomes canonical, room_summaries preserved
  - All 13 tests/Feature/ProjectPackages tests pass (no regression in zone-dropdown suite).
  - All 9 Phase23InvariantGuardTest + 8 V13SurfacesUntouchedTest pass — no v1.3 / Phase 23 surface touched.
  - All 73 RAMS + RamsReviewDataService tests pass.
files_changed:
  - app/Http/Controllers/ProjectPackageReviewController.php (added $summaryByRoom build + first-load extension + projection seed; ~25 LOC)
  - tests/Feature/ProjectPackages/ReviewRoomSummariesSeedTest.php (new file, 6 tests)
  - .planning/notes/2026-05-14-extracted-data-room-key-mismatch.md (sidebar-fix note)
