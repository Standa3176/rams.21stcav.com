---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 05
subsystem: rams
tags: [php, laravel, phpunit, exclusions, hazards, ceiling-load, electrical-boundary]

# Dependency graph
requires:
  - phase: 28-02
    provides: "Hazard #7 renamed 'Restricted access and ceiling void working' — this plan's regression test decouples from the exact name and searches controls by substring instead, per plan instruction, but the rename is the live title referenced in this summary"
provides:
  - "RULE-09's electrical scope boundary sentence, unconditionally seeded into reviewed_data['exclusions'] for every never-reviewed RamsDocument, reaching both the live DOCX render path (buildLegacy) and the review-form GET pre-fill"
  - "A regression-locked proof (CeilingLoadStatementRegressionTest) that RULE-10's ceiling-load statement fires end-to-end for a ceiling-mounted job against the real seeded hazard library, closing the Wave-0 gap 28-VALIDATION.md identified"
affects: ["28-07 (backfill migration — the only route that reaches RULE-09's bullet for already-reviewed documents)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Reused DocxBuilderPdfParityTest's build()+ZipArchive+document.xml pattern to prove a reviewed_data change reaches the live rendered output, rather than asserting only against the in-memory patched array"
    - "Reused HazardIncludeWhenResolverTest's real-vs-hand-built-fixture distinction — this plan's new test explicitly seeds the REAL HazardTemplateSeeder library instead of duplicating the unit-level hand-built Collection approach"

key-files:
  created:
    - tests/Feature/Rams/ElectricalScopeBoundaryExclusionTest.php
    - tests/Feature/Rams/CeilingLoadStatementRegressionTest.php
  modified:
    - app/Services/Rams/RamsDisplayPatchService.php
    - tests/Feature/Rams/PatchRamsForDisplayTest.php

key-decisions:
  - "D-07 honoured exactly as scoped: shipped only the one settled electrical-boundary sentence RULE-09 names in REQUIREMENTS.md. Explicitly did NOT add BS 7671/lock-off/test-dead wording, a live-working PPE row ban, or a 'first-fix power' -> 'first-fix AV signal/data/ELV cabling' rename — see Deviations/Deferrals below."
  - "RULE-10 required zero new code. Research Q1 already verified empirically that signal:ceiling_void_access fires for ceiling-mounted (non-void-entry) jobs via the ceiling_works activity match, and the seeded hazard already carries the correct ceiling-load sentence. This plan's only job was to lock that finding into a regression test — confirmed no mechanism was invented."

requirements-completed: [RULE-10]  # RULE-09 deliberately left partially covered — see Known Stubs / Judgement below

# Metrics
duration: ~40min
completed: 2026-09-06
---

# Phase 28 Plan 05: Ceiling Load Statement Lock-In + Electrical Scope Boundary Summary

**Added RULE-09's electrical scope boundary sentence as a 6th bullet in `RamsDisplayPatchService`'s unconditional default exclusions array (reaching every never-reviewed document's DOCX output), and added an end-to-end regression test proving RULE-10's ceiling-load statement already fires for ceiling-mounted equipment against the real seeded hazard library — no new trigger mechanism, per research's Q1 finding.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-09-06
- **Tasks:** 2 planned (both `type="auto"`)
- **Files modified:** 4 (2 created, 2 modified — 1 of the modifications is a Rule-1 regression fix not in the original plan's `files_modified` list)

## Accomplishments

- Added RULE-09's electrical scope boundary sentence as a 6th element inside `RamsDisplayPatchService.php`'s existing `if (! isset($rd['exclusions']))` block (`:387-396`) — no new conditional branch, matching D-06's "unconditional statement, no AI decision" constraint exactly.
- Created `ElectricalScopeBoundaryExclusionTest` with two tests: one proving `patch()` seeds the bullet into `reviewed_data['exclusions']` for a document whose exclusions key was never set, and one proving the bullet reaches the actual rendered DOCX `word/document.xml` via `DocxBuilderService::build()` — the live `buildLegacy()` path (`config('rams.unified_composer')` false), following `DocxBuilderPdfParityTest`'s render-and-unzip pattern rather than asserting only against the in-memory array.
- Created `CeilingLoadStatementRegressionTest`, seeding the real `HazardTemplateSeeder` library (not a hand-built fixture), classifying a synthetic "Ceiling mounted projector" item via the real `EquipmentClassifierService`, resolving it through the real `HazardIncludeWhenResolver` against the real seeded `HazardTemplate::where('is_global', true)` rows, and asserting a matched hazard's `controls` array contains the substring "structural soffit" — locking in research Q1's tinker-session finding as a durable regression test.
- Deliberately did NOT filter matched hazards by name before searching controls, per the plan's explicit instruction — decouples this test from Plan 28-02's rename landing order.
- **[Rule 1 - Bug] Fixed a direct regression the exclusions-array change caused:** `PatchRamsForDisplayTest::test_reviewed_data_defaults_populate_when_not_already_set` hardcoded `assertCount(5, $rd['exclusions'])` against the pre-Plan-28-05 default array. Updated to `assertCount(6, ...)` and added an explicit assertion for the new boundary sentence, so the pre-existing test still proves what it always proved (defaults populate correctly) without silently losing coverage of the count.
- Ran the full `--filter=Rams` suite: 625 passed, 1 pre-existing failure (2463 assertions) — the failure is `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`, confirmed identical to the plan's documented pre-existing failure (`deferred-items.md`, logged by Plan 28-02), unrelated to this plan's changes, left untouched.

## Task Commits

1. **Task 1: RULE-09 — electrical scope boundary bullet + reachability test** - `4c8d287` (feat)
2. **Task 2: RULE-10 — end-to-end regression lock for ceiling-load statement** - `2fe13d3` (test)
3. **Regression fix (Rule 1): update hardcoded exclusions count** - `51b73d9` (fix)

**Plan metadata:** pending (this commit)

## Files Created/Modified

- `app/Services/Rams/RamsDisplayPatchService.php` - added RULE-09's 6th default exclusions bullet
- `tests/Feature/Rams/ElectricalScopeBoundaryExclusionTest.php` - new, proves patch() seed + live DOCX render reachability
- `tests/Feature/Rams/CeilingLoadStatementRegressionTest.php` - new, end-to-end lock for RULE-10 against the real seeded library
- `tests/Feature/Rams/PatchRamsForDisplayTest.php` - Rule-1 fix, updated hardcoded exclusions count from 5 to 6

## Decisions Made

See `key-decisions` in frontmatter. Summarized: D-07's scope boundary was honoured exactly — one sentence shipped, four further `house-rules.md` positions explicitly deferred (see below). RULE-10 needed no new derivation logic; the plan's entire job for that requirement was proving an already-correct mechanism with a durable test.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed a pre-existing test's hardcoded exclusions count broken by this plan's own change**

- **Found during:** Task 1 verification (`--filter=RamsDisplayPatch` requested by the plan's verification step, which matched zero test classes since no class is literally named `RamsDisplayPatch*` — the closest related coverage is `PatchRamsForDisplayTest`, `PatchServiceMarkerTest`, and `DocxBuilderPdfParityTest`, all of which were run directly instead).
- **Issue:** `PatchRamsForDisplayTest::test_reviewed_data_defaults_populate_when_not_already_set` asserted `assertCount(5, $rd['exclusions'])` against the canonical default list. Adding RULE-09's 6th bullet broke this pre-existing, in-scope assertion — a direct, unavoidable consequence of this task's own edit to the exact array the test inspects.
- **Fix:** Updated the assertion to `assertCount(6, ...)` and added an explicit `assertContains()` for the new boundary sentence, preserving the test's original intent (proving the default-seed mechanism) while keeping it accurate.
- **Files modified:** `tests/Feature/Rams/PatchRamsForDisplayTest.php`
- **Commit:** `51b73d9`

### Explicit D-07 Deferrals (not bugs — intentional scope boundary, stated per Phase 27 RULE-12 precedent)

This plan ships **only** the one sentence RULE-09 names in `REQUIREMENTS.md`. The following four `house-rules.md` §"Electrical scope boundary" positions named in D-07 are **explicitly deferred**, not silently dropped:

1. **BS 7671 / lock-off / test-dead wording** for plugging equipment into an existing socket (should be reserved for genuine fixed-installation work by the client's competent electrician) — deferred because there is an **open, unresolved user decision on this wording carried in project memory**; settling it is a checkpoint question, not something this plan's minimum route required or was authorised to decide.
2. **A ban on any live-working PPE row** (e.g. insulated gloves "for live working," which contradicts "no live working") — deferred because no such row exists anywhere in the current app; there is no named defect to fix, only a check against text that doesn't exist.
3. **"First-fix power" / "power cabling" → "first-fix AV signal/data/ELV cabling" rename** — deferred for the same reason: the string "first-fix power" does not appear in any file this phase touches.
4. **Hardwired-supply isolation by the client's authorised person with site lock-off** — not addressed; out of this plan's one-sentence scope.

These are recorded here explicitly rather than narrowed silently, per the Phase 27 RULE-12 precedent this plan's own objective cites.

## Issues Encountered

None beyond the deviation and deferrals documented above. The plan's specified verification command `php artisan test --filter=RamsDisplayPatch` matched no test classes (no file is literally named `RamsDisplayPatch*`); the closest existing coverage (`PatchRamsForDisplayTest`, `PatchServiceMarkerTest`, `DocxBuilderPdfParityTest`, `RamsDocumentComposerTest`) was run directly instead and is reported in Verification Evidence below.

## User Setup Required

None - no external service configuration required.

## Verification Evidence

### This plan's two new tests
```
$ php artisan test --filter='ElectricalScopeBoundaryExclusionTest|CeilingLoadStatementRegressionTest'
PASS  Tests\Feature\Rams\CeilingLoadStatementRegressionTest
✓ ceiling mounted equipment produces the ceiling load control line end to end

PASS  Tests\Feature\Rams\ElectricalScopeBoundaryExclusionTest
✓ patch seeds the electrical boundary bullet for a never reviewed document
✓ docx render path includes the electrical boundary sentence for a never reviewed document

Tests: 3 passed (12 assertions)
```

### Regression check on the edited file's direct related tests (in lieu of the plan's non-matching `--filter=RamsDisplayPatch`)
```
$ php artisan test tests/Feature/Rams/Composer/PatchServiceMarkerTest.php tests/Feature/Rams/PatchRamsForDisplayTest.php tests/Feature/Rams/DocxBuilderPdfParityTest.php tests/Feature/Rams/Composer/RamsDocumentComposerTest.php
Tests: 41 passed, 1 failed (323 assertions)   <- before the Rule-1 fix
```
After the Rule-1 fix:
```
$ php artisan test tests/Feature/Rams/PatchRamsForDisplayTest.php
PASS  Tests\Feature\Rams\PatchRamsForDisplayTest
Tests: 7 passed (34 assertions)
```

### Full Rams suite (no new failures beyond the documented pre-existing one)
```
$ php artisan test --filter=Rams
Tests: 2 deprecated, 1 failed, 625 passed (2463 assertions)
```
The 1 failure is `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` — matches the plan's documented "Known pre-existing failure" exactly, confirmed identical, left alone.

## Next Phase Readiness

- **RULE-10 is genuinely complete.** No new mechanism was added or needed; a durable end-to-end regression test now locks in that a ceiling-mounted (non-void-entry) job's hazard register states the ceiling-load position against the real seeded library, closing the Wave-0 gap `28-VALIDATION.md` identified.
- **RULE-09 is only partially delivered — do not mark it complete.** The boundary sentence is unconditional and reaches both DOCX render paths (`buildLegacy` confirmed live; `DocxBuilderServiceV2` not separately re-verified this plan but shares the same `patch()` call site per research Q2) and the review-form GET pre-fill, but **only for documents whose `reviewed_data['exclusions']` key has never been set.** Any document that has already been through one Save Review — even a no-op one — is permanently excluded from this new default, because `RamsController::updateAndDownload()` persists the exclusions array unconditionally on every save, making the key permanently `isset` from that point forward. **This gap does not self-heal and this plan does not close it — Plan 28-07's backfill migration is the remediation for already-reviewed documents.**
- D-07's four deferred positions (BS 7671/lock-off wording, live-working PPE row ban, "first-fix power" rename, hardwired-supply isolation) remain open per the explicit deferrals above. The BS 7671 wording specifically still has an unresolved user decision pending in project memory.
- No blockers for Plan 28-07.

## Self-Check: PASSED

- FOUND: app/Services/Rams/RamsDisplayPatchService.php (6th exclusions bullet present)
- FOUND: tests/Feature/Rams/ElectricalScopeBoundaryExclusionTest.php
- FOUND: tests/Feature/Rams/CeilingLoadStatementRegressionTest.php
- FOUND: tests/Feature/Rams/PatchRamsForDisplayTest.php (count updated to 6)
- FOUND commit 4c8d287
- FOUND commit 2fe13d3
- FOUND commit 51b73d9
- Verification run: `php artisan test --filter='ElectricalScopeBoundaryExclusionTest|CeilingLoadStatementRegressionTest'` -> 3 passed (12 assertions), exit 0
- Verification run: `php artisan test --filter=Rams` -> 625 passed / 1 pre-existing failure (2463 assertions), no new failures

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
