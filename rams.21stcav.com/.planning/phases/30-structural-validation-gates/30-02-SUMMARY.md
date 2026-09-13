---
phase: 30-structural-validation-gates
plan: 02
subsystem: rams
tags: [laravel, php, data-reachability, validation-gates, rams-compliance]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 01
    provides: StructuralGateVocabulary::flattenAreas()/flattenClientResponsibilities(), the three disarmed gate flags, compliance_warnings channel
provides:
  - client_responsibilities_expanded mirrored from reviewed_data onto the pipeline array at all three real generation entry points
  - areas_for_gate (gate-private area list) mirrored from reviewed_data['room_overviews'] at the same three entry points
  - StructuralGatesDualPathTest — non-vacuity proof, run and recorded, that each of the three mirrors is load-bearing
affects: [30-03, 30-06, 30-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Mirror reviewed_data onto the pipeline array immediately before upgrade(), at every entry point, with a comment naming the coverage gap it closes (S4, Plan 27-07 material_handling idiom) — applied here to two new keys"
    - "Gate-private mirror key (areas_for_gate) instead of the semantically obvious room_overviews, specifically to avoid waking a dormant AI code path"
    - "Non-vacuity proof recorded in the test docblock, and actually executed (delete-one-line, watch it fail, restore, confirm git diff empty) rather than merely described"

key-files:
  created:
    - tests/Feature/Rams/StructuralGatesDualPathTest.php
  modified:
    - app/Http/Controllers/RamsController.php
    - app/Services/RamsBuilderService.php

key-decisions:
  - "Mirrored under a gate-private areas_for_gate key, never $data['room_overviews'] — per RESEARCH Finding 3/Assumption A2, setting room_overviews would wake the long-dormant ensurePerRoomBullets() AI path, a behaviour change outside this phase's scope and against CLAUDE.md's minimal-diff posture"
  - "runPipeline() sources both mirrors from $record->reviewed_data (not $formData), because runPipeline() has no formData equivalent of room_overviews/client_responsibilities_expanded — those are review-screen-only inputs written by ExtractQuoteJob.php:257 before the build"
  - "A form-only initial build correctly yields areas_for_gate === [] — asserted as a passing, non-error case, not treated as a bug (per plan interfaces note)"
  - "The DOCX-rebuild/downloadPdf()/rams:refresh-compliance sites (4/5/6) are NOT re-mirrored themselves — they inherit the two new keys only by persistence (reading $rams->generated_data verbatim), proven explicitly for site 5 in the new test"

patterns-established:
  - "The three coordinated mirror sites for a phase's data-reachability gap are documented together in one feature test's docblock, per entry point, including which upgrade() call sites are live vs dormant vs inherit-by-persistence — following CdmEmergencyDualPathGateTest's house convention"

requirements-completed: [GATE-01, GATE-02]

duration: ~40min
completed: 2026-09-13
---

# Phase 30 Plan 02: Structural Gate Data-Reachability Mirrors Summary

**Mirrored `client_responsibilities_expanded` and a gate-private `areas_for_gate` area list onto the pipeline array at all three real RAMS generation entry points, immediately before `RamsComplianceUpgradeService::upgrade()`, and proved with an executed (not just described) non-vacuity check that GATE-01 and GATE-02 would otherwise report clean on every real document.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-09-13
- **Tasks:** 2
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments

- Added two coordinated mirrors — `client_responsibilities_expanded` and `areas_for_gate` — at all three sites named in the plan's interfaces block: `RamsController.php` (Save Review, immediately before the `updateAndDownload()` `upgrade()` call), `RamsBuilderService::runFromReview()`, and `RamsBuilderService::runPipeline()`. Each site carries a comment naming the coverage gap it closes, matching the Plan 27-07 `material_handling` idiom (S4).
- Confirmed and used the exact per-path area sources named in the plan: `$reviewedData['room_overviews']` on the controller and `runFromReview()` paths, and `$record->reviewed_data['room_overviews']` on `runPipeline()` (which has no `$formData` equivalent).
- `areas_for_gate` is written as a flat list of trimmed, non-empty room-name strings — directly consumable by Plan 30-01's `StructuralGateVocabulary::flattenAreas()`, which reads `areas_for_gate` first, falling back to `$data['rooms']`.
- Created `tests/Feature/Rams/StructuralGatesDualPathTest.php` (6 tests) proving: the Save Review HTTP route mirrors both keys into persisted `generated_data`; `runFromReview()` does the same; `runPipeline()` mirrors `areas_for_gate` non-empty when the record's `reviewed_data['room_overviews']` was already populated (quote-extracted case) and correctly empty-but-present on a form-only build; a document with zero `room_overviews` stays legal end-to-end via the real HTTP route; and `downloadPdf()` (site 5) inherits the mirrors only by persistence, not by re-deriving them itself.
- **Actually ran** the delete-one-line-watch-it-fail non-vacuity procedure for all three mirror sites (not merely described it): each deletion was confirmed to fail its named test, each restoration was confirmed via `git diff` returning empty for the file, and the full Rams suite (780 tests) was re-confirmed green after final restoration. Results are recorded verbatim in the test file's docblock.
- Full Rams test suite: 774 passed before Task 2, 780 passed after (774 baseline + 6 new), 0 failed, both runs read directly from PowerShell output.

## Task Commits

Each task was committed atomically:

1. **Task 1: Mirror client_responsibilities_expanded and the area list at all three entry points** - `dd7ebd8` (feat)
2. **Task 2: StructuralGatesDualPathTest — prove the mirrors, per entry point** - `213276c` (test)

## Files Created/Modified

- `app/Http/Controllers/RamsController.php` - `client_responsibilities_expanded` + `areas_for_gate` mirrors added immediately before the Save Review `upgrade()` call (`:595-611`)
- `app/Services/RamsBuilderService.php` - symmetric mirrors added in `runFromReview()` (`:296-309`, sourced from `$reviewedData`) and `runPipeline()` (`:958-975`, sourced from `$record->reviewed_data` — the only entry point with no `$formData` equivalent)
- `tests/Feature/Rams/StructuralGatesDualPathTest.php` - new, 6 tests proving reachability at all three entry points plus the inherit-by-persistence property on the downloadPdf() site

## Decisions Made

- Gate-private `areas_for_gate` key chosen over `$data['room_overviews']` specifically to keep `ensurePerRoomBullets()` dormant — a deliberate, documented tradeoff named in both the plan and the mirror comments
- `runPipeline()`'s mirrors read from `$record->reviewed_data`, not `$formData`, because `ExtractQuoteJob.php` writes `room_overviews`/`client_responsibilities_expanded` into `reviewed_data` before the build runs on quote-extracted jobs, and `buildFromForm()`'s manual-creation path never populates a `$formData` equivalent of either key
- A form-only initial build's `areas_for_gate === []` is treated as correct, asserted output — not smoothed over or skipped

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test assertion mismatched a real (and correct) controller default**
- **Found during:** Task 2, first test run
- **Issue:** `test_zero_room_overviews_yields_areas_for_gate_empty_and_no_error_on_save_review` initially asserted `client_responsibilities_expanded === []` on the Save Review path, but `RamsController.php:522-536` always constructs the four fixed buckets (`network_readiness`/`licences`/`access`/`power_validation`) from the request regardless of whether any `client_resp_*` field was submitted — so the mirrored value is never a bare `[]` on this path, only "all buckets not required, no free text, no additional rows."
- **Fix:** Rewrote the assertion to check that content shape (`required === false`, `notes === ''` per bucket, `additional === []`) instead of array identity with `[]`. No production code changed — this was a test-authoring correction, not a mirror defect.
- **Files modified:** `tests/Feature/Rams/StructuralGatesDualPathTest.php`
- **Verification:** `php artisan test --filter=StructuralGatesDualPathTest` — 6 passed (was 5 passed, 1 failed before the fix)
- **Committed in:** `213276c` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 Rule-1 test-assertion bug, no production code affected)
**Impact on plan:** None — the mirror implementation was correct from the first run; only the new test's own assertion needed correcting.

## Non-Vacuity Proof — Result

Run 2026-09-13 via PowerShell/Herd (per CLAUDE.md — `php` is not on the Bash tool's PATH):

| Mirror site | Line(s) | Test that must fail | Result |
|---|---|---|---|
| `RamsController.php` `areas_for_gate` mirror | `:609-612` | `test_client_responsibilities_and_areas_reach_upgrade_via_save_review` | **FAILED as expected** (`areas_for_gate` came back `[]` instead of the two seeded room names). Restored; `git diff` empty; suite green. |
| `RamsBuilderService::runFromReview()` `areas_for_gate` mirror | `:307-310` | `test_client_responsibilities_and_areas_reach_upgrade_via_run_from_review` | **FAILED as expected**, same signature. Restored; `git diff` empty; suite green. |
| `RamsBuilderService::runPipeline()` `areas_for_gate` mirror | `:972-975` | `test_run_pipeline_areas_for_gate_nonempty_when_quote_extracted` | **FAILED as expected**, same signature. Restored; `git diff` empty; full Rams suite (780 tests) re-confirmed green. |

Full procedure and evidence recorded verbatim in `tests/Feature/Rams/StructuralGatesDualPathTest.php`'s class docblock.

## Issues Encountered

None beyond the test-assertion correction documented above.

## User Setup Required

None — no external service configuration, no new env vars, no migration.

## Next Phase Readiness

- Plan 30-03 (GATE-01/GATE-02 gate bodies) can now build directly on `client_responsibilities_expanded` and `areas_for_gate` being reliably present on the array `upgrade()` receives at all three real generation entry points, and on `StructuralGateVocabulary::flattenAreas()`/`flattenClientResponsibilities()` (Plan 30-01) already knowing how to read them.
- The inherit-by-persistence property on sites 4/5/6 (documented and proven for site 5 here) is now a recorded, testable fact for Plan 30-05's arming runbook — a corpus regeneration (e.g. `php artisan rams:refresh-compliance`) remains a precondition for those three sites to see live mirror data on legacy documents.
- No blockers. All 780 Rams tests green.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-13*

## Self-Check: PASSED

Verified on disk: `app/Http/Controllers/RamsController.php` and `app/Services/RamsBuilderService.php` both carry the `areas_for_gate`/`client_responsibilities_expanded` mirrors (confirmed via `grep -n areas_for_gate`); `tests/Feature/Rams/StructuralGatesDualPathTest.php` exists with 6 tests, all passing. Both commit hashes (`dd7ebd8`, `213276c`) confirmed present in `git log --oneline -5`.
