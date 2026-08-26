# Phase 27 — Deferred Items

## From Plan 27-06 (GATE-09 engineer-row extension) — CLOSED by Plan 27-07

**GATE-09 could not see `material_handling.large_items` on two production
paths Plan 27-06 did not touch. Both are now CLOSED.**

1. **CLOSED — `RamsController::updateAndDownload()`** ("Save Review" / "Save
   & Download" on the RAMS review screen,
   `app/Http/Controllers/RamsController.php`). This action builds
   `$generatedData` from `$rams->generated_data`, mirrors several
   reviewed-data sub-keys onto it (`site_emergency` explicitly, with a
   comment explaining exactly why), then calls
   `RamsComplianceUpgradeService::upgrade($generatedData)` directly. It
   built `$reviewedData['material_handling']` but never mirrored it onto
   `$generatedData` the way `site_emergency` is mirrored — so
   `enforceDisplayLiftGate()`'s engineer-row loop (Plan 27-06 Task 2) saw an
   empty array on this path and could not fire, even though the engineer
   just typed a non-conforming team size into the form on that exact
   request.

   **Fix (Plan 27-07, Task 1):** mirrored `material_handling` onto
   `$generatedData` immediately before the `upgrade()` call, in the same
   block as and directly after the existing `site_emergency` mirror. A
   thrown `RamsGenerationException` from that call now redirects back to
   the review screen with the message in the session error bag, instead of
   escaping as an unhandled 500.
   **Commit:** `32c6a2c` (feat(27-07): mirror material_handling into
   generatedData before upgrade())
   **Proof:** `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php` (4
   tests) drives the real `POST /rams/{rams}/update-and-download` route.
   Non-vacuity confirmed: reverting the mirror line made
   `test_four_persons_is_blocked_on_save_review_with_item_name_in_error`
   fail (`Session is missing expected key [error]`); restoring made it pass
   again — see `27-07-SUMMARY.md`'s Non-Vacuity Proof section.

2. **CLOSED — the PDF template read engineer rows directly from
   `reviewed_data`, bypassing `generated_data`/`upgrade()`/GATE-09
   entirely.** `resources/views/pdf/rams.blade.php` read
   `$rams->reviewed_data['material_handling']` directly — a second,
   independent read of the same engineer-typed field that never passed
   through `RamsComplianceUpgradeService::upgrade()` at all.

   **Fix (Plan 27-07, Task 2, per 2026-08-26 user decision):** re-pointed
   the template at `$rams->generated_data['material_handling']` (the gated
   output — the same source `DocxBuilderService::buildMaterialHandling()`
   reads) with a deliberate, explicitly-commented `reviewed_data` fallback
   used only when the gated key is absent.
   **Commit:** `d679511` (fix(27-07): re-point the live PDF
   material-handling read at generated_data)
   **Proof:** `tests/Feature/Rams/DisplayLiftPdfSourceTest.php` (3 tests)
   proves precedence (generated_data wins when both present and differ),
   the historical-document fallback, and no-error rendering when neither
   key is present. Non-vacuity confirmed: reverting the source-preference
   change made
   `test_pdf_renders_generated_data_rows_not_reviewed_data_rows_when_both_present`
   fail (`UNGATED Samsung 98` was present when it should not have been);
   restoring made it pass again — see `27-07-SUMMARY.md`'s Non-Vacuity
   Proof section.

## Remaining accepted exception (NOT a gap — by design)

**The `reviewed_data` fallback in `resources/views/pdf/rams.blade.php` is a
single remaining, deliberately accepted, ungated read.** RAMS documents
generated before Plan 27-07 have no `generated_data['material_handling']`
key at all. Without the fallback, every such historical document would lose
its §6.7 Material Handling table on next PDF render. Those documents are
already issued and cannot be retro-fixed (Phase 26 established the same
principle: denormalised storage means reseeding never retro-changes an
issued RAMS). New generations always populate the gated key on all three
entry points (`RamsBuilderService::runFromReview()`/`runPipeline()` — Plan
27-06 — and `RamsController::updateAndDownload()` — Plan 27-07), so this
fallback is only reachable for documents that predate this phase. This is
recorded here, not tracked as an open item, and requires no follow-up plan.
