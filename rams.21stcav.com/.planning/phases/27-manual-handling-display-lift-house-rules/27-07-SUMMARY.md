---
phase: 27-manual-handling-display-lift-house-rules
plan: 07
subsystem: rams
tags: [rams, display-lift, manual-handling, gate-09, pdf, phpunit]

# Dependency graph
requires:
  - phase: 27-manual-handling-display-lift-house-rules (Plan 27-06)
    provides: "enforceDisplayLiftGate()'s engineer-row loop, and the two RamsBuilderService mirrors (runFromReview()/runPipeline()) that first made it reachable; deferred-items.md recording the two gaps this plan closes"
provides:
  - "GATE-09 now fires on the Save Review request (RamsController::updateAndDownload()) via a one-line material_handling mirror before upgrade(), matching the existing site_emergency mirror"
  - "A thrown RamsGenerationException on the Save Review path redirects back with the message in the session error bag instead of an unhandled 500"
  - "The live PDF (resources/views/pdf/rams.blade.php) now reads material_handling from generated_data (the gated output), with a deliberate, commented reviewed_data fallback for documents generated before this phase"
  - "GATE-09 now covers all three RAMS-generation entry points plus the live PDF render — deferred-items.md's two gaps are CLOSED"
affects: [27-05-live-deploy]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Mirror-before-upgrade(): any reviewed_data sub-key a Tier-1 gate needs to see must be copied onto the generatedData array immediately before RamsComplianceUpgradeService::upgrade() is called — the site_emergency mirror was the precedent, material_handling is now a second instance of the same pattern in the same method"
    - "Gated-source-preferred, ungated-fallback read: a render template resolves a field from the gated generated_data key first, falling back to the raw reviewed_data key ONLY when the gated key is entirely absent — explicitly commented as an accepted, scoped exception (historical documents only), not a silent bypass"

key-files:
  created:
    - tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php
    - tests/Feature/Rams/DisplayLiftPdfSourceTest.php
  modified:
    - app/Http/Controllers/RamsController.php
    - resources/views/pdf/rams.blade.php
    - .planning/phases/27-manual-handling-display-lift-house-rules/deferred-items.md

key-decisions:
  - "A RamsGenerationException thrown by upgrade() on the Save Review path is now caught and converted to `back()->withInput()->with('error', $e->getMessage())` — nothing is persisted at that point (the DB transaction and render happen later in the method), so there is nothing to roll back; this differs from the existing catch-all around DB::transaction() (which handles render/DB failures after data IS about to be persisted) but reuses the same redirect-with-error convention."
  - "The PDF template's reviewed_data fallback is guarded on the generated_data value being a non-empty array (`is_array() && !empty()`), not merely `array_key_exists()`. In practice the two are equivalent for this codebase: every current write path either omits the key (historical documents) or populates the full three-key material_handling shape (has_large_items/large_items/handling_notes) via the mirror — so a present-but-empty generated_data['material_handling'] never occurs in a way that would produce a different outcome under either guard."

requirements-completed: [GATE-09]

# Metrics
duration: 55min
completed: 2026-08-26
---

# Phase 27 Plan 07: GATE-09 Last-Mile Bypass Closure Summary

**Mirrored `material_handling` into `generatedData` before `RamsComplianceUpgradeService::upgrade()` in `RamsController::updateAndDownload()` (the "Save Review" route engineers actually use), and re-pointed the live PDF's material-handling read at the gated `generated_data` source with a deliberate, commented `reviewed_data` fallback for pre-phase documents — closing the last two GATE-09 bypass paths recorded in Plan 27-06's `deferred-items.md`.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-08-26T14:20:00Z (approx.)
- **Completed:** 2026-08-26T15:15:00Z (approx.)
- **Tasks:** 3 completed
- **Files modified:** 2 production files, 2 new test files, 1 planning doc

## Accomplishments
- `RamsController::updateAndDownload()` now mirrors `reviewed_data['material_handling']` onto `$generatedData` immediately after the existing `site_emergency` mirror and before the `upgrade()` call, so `enforceDisplayLiftGate()`'s engineer-row loop sees exactly what the engineer just typed on the Save Review request — the route an engineer actually uses.
- A `RamsGenerationException` thrown by that `upgrade()` call now redirects back to the review screen with the exception's message (which names the offending item) in the session error bag, instead of escaping as an unhandled 500.
- `resources/views/pdf/rams.blade.php` no longer reads `reviewed_data['material_handling']` unconditionally. It now prefers `generated_data['material_handling']` — the same gated source `DocxBuilderService::buildMaterialHandling()` reads — falling back to `reviewed_data` only when the gated key is entirely absent (the pre-Plan-27-07 shape), so historical documents keep rendering §6.7/§6.5 Material Handling.
- `DisplayLiftSaveReviewGateTest` (4 tests) drives the real `POST /rams/{rams}/update-and-download` route: 4-persons is blocked with the item name in the error bag and nothing persisted; 3-persons and an unparseable handling method both save normally; the `RAMS_DISPLAY_LIFT_GATE=false` kill-switch is proven a genuine rollback on this path too.
- `DisplayLiftPdfSourceTest` (3 tests) proves precedence (generated_data rows win over differing reviewed_data rows when both are present), the historical-document fallback (reviewed_data renders when generated_data's key is absent), and error-free rendering when neither key is present.
- `deferred-items.md` rewritten: both Plan 27-06 gaps marked CLOSED with commit references; the `reviewed_data` PDF fallback recorded as the single remaining accepted exception, not an open item.

## Task Commits

Each task was committed atomically:

1. **Task 1: mirror material_handling in updateAndDownload()** - `32c6a2c` (feat)
2. **Task 2: re-point the live PDF at the gated source** - `d679511` (fix)
3. **Task 3: coverage-completeness proof + deferred-items.md closure** - `5265c31` (test)

**Plan metadata:** (this commit, docs: complete plan)

## Files Created/Modified
- `app/Http/Controllers/RamsController.php` - Added `$generatedData['material_handling'] = $reviewedData['material_handling'] ?? [];` directly after the existing `site_emergency` mirror and before `RamsComplianceUpgradeService::upgrade($generatedData)`, with a comment citing Plan 27-07 and deferred-items.md item 1. Wrapped the `upgrade()` call in a `try`/`catch (\App\Exceptions\RamsGenerationException $e)` that redirects back with the message.
- `resources/views/pdf/rams.blade.php` - Replaced the unconditional `$matHandling = $rams->reviewed_data['material_handling'] ?? [];` with a generated-data-preferred, reviewed-data-fallback resolution, with a multi-line comment explaining the fallback is deliberate, scoped to historical documents, and why. `$mhItems`/`$mhNotes` derivation is unchanged.
- `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php` - New: 4 tests driving the real Save Review route (block on 4-persons with item name in error; save on 3-persons; save on unparseable handling method; kill-switch rollback).
- `tests/Feature/Rams/DisplayLiftPdfSourceTest.php` - New: 3 tests proving generated_data precedence, the historical-document reviewed_data fallback, and no-error rendering when neither key is present.
- `.planning/phases/27-manual-handling-display-lift-house-rules/deferred-items.md` - Rewritten: both gaps marked CLOSED with commit hashes and proof references; the reviewed_data PDF fallback recorded as the single remaining accepted exception.

## Decisions Made
See `key-decisions` in frontmatter above. Most consequential: the Save Review path's `upgrade()` call needed its own exception handling (distinct from the existing `DB::transaction()` catch-all further down the method) because it runs *before* any DB write in this method — there is nothing to roll back, only a redirect-with-error to produce.

## Deviations from Plan

None - plan executed exactly as written. Both tasks matched their `<action>`/`<behavior>` blocks precisely; no Rule 1-4 auto-fixes were needed.

## Issues Encountered

**Test-authoring mistake caught by the RED phase itself.** While first writing `DisplayLiftPdfSourceTest`, the `renderWith()` helper was called with the raw `material_handling` sub-array (e.g. `['has_large_items' => true, 'large_items' => [...]]`) directly as the `reviewedData`/`generatedData` argument, instead of wrapping it under a `material_handling` key inside the full `reviewed_data`/`generated_data` array. Running the test against the UNFIXED template (the deliberate RED step required by this plan's `<critical_constraints>` #1) surfaced this immediately: both the precedence test AND the historical-fallback test failed with "item not found," when only the precedence test should have failed pre-fix. Inspecting the stub via a debug dump showed `reviewed_data` was set to the material_handling array itself rather than `['material_handling' => ...]`. Fixed by having `ramsStub()` accept the `material_handling` sub-array (or `null`) and wrap it under the correct key. Re-ran RED: exactly one test failed (the precedence test), as expected — confirming the test bug, not a template bug, was the initial cause.

## User Setup Required

None - no external service configuration required. Reuses the existing `RAMS_DISPLAY_LIFT_GATE` kill-switch (default `true`); no new flag introduced.

## Non-Vacuity Proof (per-plan requirement)

**Task 1 (material_handling mirror in `updateAndDownload()`):**
1. Commented out the mirror line (`$generatedData['material_handling'] = $reviewedData['material_handling'] ?? [];`) in `RamsController.php`.
2. Re-ran `php artisan test --filter=DisplayLiftSaveReviewGateTest`.
3. **Result:** 1 of 4 tests failed as expected — `test_four_persons_is_blocked_on_save_review_with_item_name_in_error` failed with `Session is missing expected key [error]. Failed asserting that false is true.` The other 3 tests (3-persons save, unparseable-method save, kill-switch) still passed, since they don't depend on the gate firing.
4. Restored the mirror line exactly (`git diff` on the file showed no content difference after restoration, only a line-ending normalisation warning); re-ran: all 4 tests passed again.

**Task 2 (PDF source-preference change):**
1. Temporarily reverted `$matHandling`'s resolution to the original unconditional `$rams->reviewed_data['material_handling'] ?? [];`.
2. Re-ran `php artisan test --filter=DisplayLiftPdfSourceTest`.
3. **Result:** 1 of 3 tests failed as expected — `test_pdf_renders_generated_data_rows_not_reviewed_data_rows_when_both_present` failed with "Not to contain: UNGATED Samsung 98" (the ungated reviewed_data row rendered instead of the gated generated_data row). The other 2 tests (historical fallback, no-error rendering) still passed, since the pre-fix code already read reviewed_data unconditionally.
4. Restored the generated-data-preferred resolution exactly (`git diff` on the file showed no content difference); re-ran: all 3 tests passed again.

**Combined proof:** `php artisan test --filter="DisplayLiftSaveReviewGateTest|DisplayLiftPdfSourceTest|DisplayLiftGateEngineerRowsTest|DisplayLiftDualPathTest|DisplayLiftGateTest|DisplayLiftPolicySourceGuardTest"` — 47 tests, 99 assertions, all pass (both before non-vacuity reverts and after restoration).

## Verification

- Combined six-filter command: `php artisan test --filter="DisplayLiftSaveReviewGateTest|DisplayLiftPdfSourceTest|DisplayLiftGateEngineerRowsTest|DisplayLiftDualPathTest|DisplayLiftGateTest|DisplayLiftPolicySourceGuardTest"` — 47 tests, 99 assertions, all pass. Confirms GATE-09 fires on the Save Review route (the path an engineer actually uses), the PDF renders from the gated `generated_data` source for new documents and falls back correctly for historical ones, and no regression on Plan 27-03's derived-items branch or Plan 27-06's `RamsBuilderService` dual-path/engineer-row coverage.
- Full suite: `php artisan test` — **2376 passed**, 1 failed, 6 skipped (9422 assertions), duration 509.01s (~8.5 min). The 1 failure is `Tests\Feature\Queue\QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan` — the exact documented pre-existing memory-limit flake from this plan's `<known_context>` ("Failed asserting that 1 is identical to 0" at the same assertion line as previously recorded). `BoundPdfDownloadTest` did NOT fail in this run (consistent with its documented order-dependent nature — no action needed). Pass count is exactly 7 higher than Plan 27-06's baseline of 2369 (4 new `DisplayLiftSaveReviewGateTest` tests + 3 new `DisplayLiftPdfSourceTest` tests = 7), confirming **no new failures beyond the one documented pre-existing flake.**
- Related PDF/DOCX regression suites re-checked directly (not just via the full-suite run, given this plan touches a render path): `Tier1SiteEmergencyFormAndRenderTest`, `EquipmentScheduleFallbackTest`, `DocxBuilderPdfParityTest`, `RamsRenderRegressionTest` (byte-identical-across-two-renders, 3 fixtures), `RamsSection70HeadingTest`, `Tier1BaselineHazardsRenderTest`, `Tier1CoshhTableRenderTest`, `Tier1PdfStructuralPolishTest`, `MethodStatementComposerMaterialHandlingTest`, `RamsPdfRoomOverviewsTest`, `RamsPdfScopeTest`, `MethodStatementSectionDtoTest` — all pass. The `#[Group('snapshot')]`-excluded `PdfSnapshotTest` (golden-file comparison for the legacy `pdf.rams` blade, including a "Tilda" fixture) was also run explicitly via `vendor/bin/phpunit --group=snapshot --filter=PdfSnapshotTest` — all 3 pass, confirming the golden output is unaffected (the Tilda fixture predates GATE-09's material_handling mirrors and has no populated `generated_data['material_handling']`, so it exercises the fallback path without producing a diff).

## Next Phase Readiness
- GATE-09 now covers all three RAMS-generation entry points (`RamsBuilderService::runFromReview()`/`runPipeline()` from Plan 27-06, and `RamsController::updateAndDownload()` from this plan) plus the live PDF render — the phase-level "the live PDF is gated" claim from Plan 27-06's Next Phase Readiness note is now fully true, not partially true.
- `deferred-items.md` no longer lists any open gaps for GATE-09. The one remaining item recorded there (the PDF's `reviewed_data` fallback for historical documents) is explicitly an accepted, by-design exception, not a follow-up task.
- No further follow-up plan is required for GATE-09 coverage. Ready for 27-05 (live deploy) to include this plan's two commits in its next release cut.

---
*Phase: 27-manual-handling-display-lift-house-rules*
*Completed: 2026-08-26*

## Self-Check: PASSED

All created/modified files confirmed on disk; all 3 task commit hashes (32c6a2c, d679511, 5265c31) confirmed in `git log --oneline --all`.
