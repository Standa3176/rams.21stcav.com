---
phase: 26-hazard-library-structural-inversion
plan: 08
subsystem: rams-hazard-generation
tags: [php, laravel, hazard-library, risk-assessment, tdd, fold-mapping, regression-guard]

# Dependency graph
requires:
  - phase: 26-hazard-library-structural-inversion (Plan 04)
    provides: HazardIncludeWhenResolver wired into RiskTemplateResolverService and runPipeline()
  - phase: 26-hazard-library-structural-inversion (Plan 07)
    provides: tiered resolution wired into runFromReview() via RiskTemplateResolverService::tieredRowsNotAlreadyPresent()
provides:
  - "App\\Services\\Rams\\LegacyHazardNameFoldMap — single 16-entry, documented-provenance legacy-name -> canonical-library-name map"
  - "HazardLibraryService::fuzzyMatch() folds a seed through the map as its first step, shared by every caller of resolveFromSeeds()"
  - "RamsBuilderService::reviewedToRisk() renames matched reviewed rows to their template's canonical name, replaces controls unconditionally on a genuine rename, gates score precedence on score_reviewed (absent treated as false), escalates needs_confirmation on a folded confirm-tier match, and runs a same-batch dedup pass before the tiered merge"
  - "Test coverage built from the REAL 7-name legacy vocabulary observed on live (21CQ30960 / RAMS 97), not only a clean synthetic fixture"
affects: [rams-generation, rams-review-screen, hazard-library-phase-27-28]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Single fold choke point: LegacyHazardNameFoldMap is consumed ONLY inside HazardLibraryService::fuzzyMatch(), as the first statement — every caller of resolveFromSeeds() (explicit-picks path AND reviewed-data path) folds identically, with no second implementation anywhere"
    - "Rename vs case-only-match distinction: $renamed = strtolower(trim($tpl->name)) !== strtolower(trim($name)) — a genuine rename replaces controls unconditionally; a case-only casing fix (e.g. 'Working at Height' -> 'Working at height') displays under the library's exact casing but leaves engineer-authored controls untouched (gap-fill only)"
    - "Score precedence gated on score_reviewed, absent-is-false: true keeps the existing gap-fill-only behaviour (engineer values win, only genuinely null slots filled); false OR ABSENT sets all four score fields unconditionally from the matched template — this is what restores HAZ-03's residual score against stale pre-26-05 reviewed_data"
    - "Same-batch dedup runs AFTER the per-row rename/score logic but BEFORE the existing tiered merge — two different legacy names in one reviewed_data payload that fold onto the same canonical target collapse to the first occurrence, guaranteeing the downstream tieredRowsNotAlreadyPresent() dedup (which is exact-match-only) always receives already-canonical names"

key-files:
  created:
    - app/Services/Rams/LegacyHazardNameFoldMap.php
    - tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php
    - tests/Unit/Services/HazardLibraryServiceTest.php
  modified:
    - app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php
    - database/seeders/HazardTemplateSeeder.php
    - app/Services/RamsBuilderService.php
    - tests/Unit/Services/RamsBuilderServiceTest.php
    - tests/Feature/Rams/ReviewedHazardTieringTest.php
    - tests/Feature/Rams/RiskTemplateResolverServiceTest.php
    - .planning/REQUIREMENTS.md

key-decisions:
  - "Task 3's fixture repair required updating ALL 5 reviewedByName lookup keys in the pre-existing test to their canonical folded casing (e.g. 'Working at Height' -> 'Working at height'), not only the 2 the plan's own behavior text named (Electrical, Slips/Trips/Falls). Task 2's mechanism sets finalName = tpl->name whenever ANY match resolves (case-only or genuine rename alike), so a case-only match also changes the row's displayed hazard string — the plan's Task 3 prose was imprecise on this point; implemented per Task 2's actual, already-tested mechanism."
  - "Two existing drilling-gated negative assertions (in the always/confirm-tier test and the no-drilling-language test) had to drop 'Slips, trips and falls' / 'Noise and vibration' respectively from their 'must NOT appear' lists, because those names are now legitimately present via the FOLD (Slips, Trips & Falls (Same Level) -> Slips, trips and falls; Noise and Vibration -> Noise and vibration) rather than via a false-positive drilling-signal or always-tier match. The remaining assertions in both tests still fully prove their original intent (fail-safe default / merge-not-replace)."
  - "LegacyHazardNameFoldMap's provenance docblock originally referenced the deleted MANDATORY_KEYWORDS constant and mandatoryBaseline() method by name for git-archaeology documentation purposes — this tripped HazardInjectionPathsRemovedGuardTest's structural string scan (a Plan 26-04 regression guard forbidding those exact identifiers anywhere in app/ or tests/). Reworded to describe the retired machinery without using its forbidden literal names; guard now passes clean. [Rule 1 — self-introduced regression, fixed before commit.]"

requirements-completed: [HAZ-03]

# Metrics
duration: ~65min
completed: 2026-08-24
---

# Phase 26 Plan 08: Hazard Library Structural Inversion — Gap Closure Round 2 Summary

**Closed the round-2 live-verification gaps: `LegacyHazardNameFoldMap` is now the single choke point every hazard-name resolution path folds legacy vocabulary through (killing the "Confined Spaces" client-facing mislabel and the 3 duplicate-pair collisions), and `reviewedToRisk()`'s score precedence is gated on `score_reviewed` so the library's residual `1×4` for Working at Height now wins over stale pre-26-05 reviewed data — proven against the REAL 7-name legacy vocabulary observed on live (21CQ30960 / RAMS 97), not a clean synthetic fixture.**

## Performance

- **Duration:** ~65 min
- **Completed:** 2026-08-24
- **Tasks:** 3/3 completed
- **Files modified:** 10 (3 new, 7 modified, including REQUIREMENTS.md)

## Accomplishments

- **HAZ-02 mechanism-level fix landed** (requirement stays OPEN pending live re-verification — see Requirements Gate below): D-02's fold mapping (previously only prose in `26-01-PLAN.md` plus an implicit consequence of the seeder's output) is now `App\Services\Rams\LegacyHazardNameFoldMap` — a 16-entry map with documented git provenance for every entry, consumed by `HazardLibraryService::fuzzyMatch()` as the very first step. Both callers of `resolveFromSeeds()` — the explicit-picks path (`RiskTemplateResolverService::buildHazards()`) and the reviewed-data path (`RamsBuilderService::reviewedToRisk()`) — fold identically through this one choke point.
- `reviewedToRisk()` now renames a matched reviewed row to its resolved template's exact canonical name (`$finalName = (string) $tpl->name`), replacing controls unconditionally on a genuine rename (e.g. "Electrical Hazards" -> "Electrical" picks up the library's own control text) but leaving engineer-authored controls untouched on a case-only casing fix ("Working at Height" -> "Working at height" keeps the row's own controls, gap-filled only).
- **HAZ-03 closed and provable by automated test** (requirements gate satisfied): score precedence in `reviewedToRisk()` is now gated on `score_reviewed` — `true` preserves the existing gap-fill-only behaviour (engineer values win); `false` OR the key entirely ABSENT sets all four score fields unconditionally from the matched library template. Proven with a fixture that has no `score_reviewed` key at all (not merely `false`), restoring Working at Height's residual `1×4` against a fixture carrying the exact stale `3×3 -> 2×2` GATE-05 signature from live evidence.
- A folded reviewed pick that lands on a confirm-tier hazard (e.g. "Working in Occupied Premises" -> "Occupied premises") is escalated to `needs_confirmation = true` regardless of the source row never having set it — never downgraded, only ever escalated.
- A same-batch dedup pass runs before the existing tiered merge, collapsing two different legacy names in one `reviewed_data` payload that fold onto the same canonical target (e.g. both "Confined Spaces" and "Cable Installation in Ceiling Voids" folding onto "Restricted access and ceiling voids") into a single row.
- **Test coverage rebuilt from the REAL 7-name legacy vocabulary** observed on live (21CQ30960 / RAMS 97) — the exact fixture gap the round-2 verification identified as having let both round 1 and round 2 through. `ReviewedHazardTieringTest::test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores` regenerates all 7 real legacy names (Working at Height, Manual Handling, Electrical Hazards, Slips/Trips & Falls, Noise and Vibration, Working in Occupied Premises, Confined Spaces) with no `score_reviewed` key present, and proves: zero duplicate names, zero "Confined Spaces" rows in output, "Restricted access and ceiling voids" carries the library's exact "not classified as confined spaces" control text, "Working at height" renders the named `1×4` HAZ-03 proof, "Occupied premises" carries `needs_confirmation=true`, and "Electrical" carries the library's own control text.
- The `Electrical`-absence question from round 2 (26-VERIFICATION.md's open item) is settled at the mechanism level: a new test proves an "Electrical Hazards" reviewed row, when present, is never silently dropped — it survives, renamed to "Electrical", carrying the library's controls. **This does not resolve whether project 92's `reviewed_data` genuinely lacked an electrical entry at the time of the round-2 regeneration — that remains unexplained pending live re-verification, per this plan's own `<investigation>` constraint.**

## Task Commits

Each task was committed atomically:

1. **Task 1: LegacyHazardNameFoldMap — the shared source of truth D-02 never had** - `71703c7` (feat)
2. **Task 2: reviewedToRisk() — rename on match, replace controls on rename, gate scores on score_reviewed** - `05e5fc7` (fix)
3. **Task 3: Real legacy vocabulary coverage — the fixture gap that let both rounds through** - `744990f` (test, includes a Rule-1 self-fix of a regression this plan's own docblock introduced)

_Note: each task followed RED -> GREEN TDD — failing tests were written and confirmed failing before the corresponding implementation, matching this plan's `tdd="true"` task type._

## Files Created/Modified

- `app/Services/Rams/LegacyHazardNameFoldMap.php` (new) - 16-entry legacy-name -> canonical-library-name map with documented git provenance for every entry (D-02's 6 + the old-13-template's 7 + the retired always-on hazard-keyword fallback's 3); `canonicalName()` / `all()` public API.
- `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` - `fuzzyMatch()` folds the seed through `LegacyHazardNameFoldMap::canonicalName()` as its first statement, before the existing 3-tier match.
- `database/seeders/HazardTemplateSeeder.php` - docblock now points at the executable map instead of only describing the fold as a removal consequence; `standardHazards()`/`run()` untouched.
- `app/Services/RamsBuilderService.php` - `reviewedToRisk()`'s per-row closure rewritten: rename-on-match, controls-replace-on-genuine-rename-only, score precedence gated on `score_reviewed`, confirm-tier escalation, plus a post-map same-batch dedup pass before the existing tiered merge call site (unmoved).
- `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php` (new) - 4 tests, no DB: case-insensitive/trim matching, unmapped name passthrough, empty-string guard, map-output never re-introduces "Confined Spaces".
- `tests/Unit/Services/HazardLibraryServiceTest.php` (new) - 4 tests, seeded DB: map/seeder drift guard, fold reaches a real template through the full `resolveFromSeeds()`/`fuzzyMatch()` chain (both a Group 3 name and a Group 1 D-02 name), unmapped name untouched.
- `tests/Unit/Services/RamsBuilderServiceTest.php` - 6 new/updated tests covering rename+controls-replace, case-only-match+controls-preserved, score_reviewed=true/false/absent precedence, confirm-tier escalation, same-batch collision collapse; 3 pre-existing tests explicitly left byte-identical.
- `tests/Feature/Rams/ReviewedHazardTieringTest.php` - `makeReviewedHazards()` fixture gains `score_reviewed => true` on all 5 rows (repairs 3 existing tests' now-outdated count/name-key/controls assertions); new `makeUnreviewedLegacyHazards()` fixture (the real 7-name live vocabulary, no `score_reviewed` key) backs 2 new tests proving fold+dedup+score-restoration and idempotency at feature-test depth (real seeded DB, real `BuildRamsDocumentJob`).
- `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` - new test proving the fold reaches the explicit-picks generation path too, not only `reviewedToRisk()`.
- `.planning/REQUIREMENTS.md` - HAZ-03 marked `[x]` complete with closure narrative; HAZ-02 stays `[ ]` with a note that the code fix landed in this plan and awaits live re-verification; traceability table updated for both.

## Decisions Made

- See `key-decisions` in frontmatter for the three substantive implementation decisions (Task 3's full-5-key lookup rename, the two negative-assertion drops, and the self-fix of the guard-test regression).
- Kept the plan's exact fold-map provenance structure (3 groups, git-show-traced) rather than re-deriving the mapping from first principles — this preserves the auditability the plan explicitly asked for ("a future reader does not have to re-run the same git show archaeology").

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug, self-introduced] LegacyHazardNameFoldMap's provenance docblock tripped HazardInjectionPathsRemovedGuardTest**
- **Found during:** Task 3's full-suite run (this plan's own final check)
- **Issue:** The docblock I wrote for Task 1 documented Group 3's provenance by naming the deleted `MANDATORY_KEYWORDS` constant and `mandatoryBaseline()` method (for git-archaeology traceability). `HazardInjectionPathsRemovedGuardTest` (a Plan 26-04 structural regression guard) does a literal substring scan of every `*.php` file under `app/` and `tests/` for those exact forbidden strings, and failed.
- **Fix:** Reworded the two docblock/comment occurrences to describe the retired machinery ("the retired always-on hazard-keyword fallback names") without using its forbidden literal identifier names. The git-show command reference (which is not a forbidden string) is unchanged, so the archaeology trail is still fully reproducible.
- **Files modified:** `app/Services/Rams/LegacyHazardNameFoldMap.php`
- **Verification:** `php artisan test --filter=HazardInjectionPathsRemovedGuardTest` passes; full suite re-run confirms zero regressions.
- **Committed in:** `744990f` (Task 3 commit — bundled since both surfaced from the same full-suite run)

**2. [Rule 1 - Test correctness] Two pre-existing test assertions had to drop a name from their negative "must NOT appear" list**
- **Found during:** Task 3, writing/repairing `ReviewedHazardTieringTest`
- **Issue:** `test_reviewed_rams_regenerates_with_always_and_confirm_tier_hazards_merged_on_top()`'s negative drilling-gated check and `test_no_drilling_language_yields_none_of_the_drilling_gated_hazards()`'s equivalent check both included `'Slips, trips and falls'` / `'Noise and vibration'` respectively — names that are now LEGITIMATELY present in the register via the fold (not via a false-positive signal match), because the shared fixture's reviewed rows fold onto those exact canonical names.
- **Fix:** Removed the now-legitimately-present name from each negative list, with an inline comment explaining why. The remaining checks in both tests still fully prove their original intent.
- **Files modified:** `tests/Feature/Rams/ReviewedHazardTieringTest.php`
- **Verification:** Both tests pass; the removed names are separately proven present-via-fold (not via a false positive) by the new Task 3 tests.
- **Committed in:** `744990f` (Task 3 commit)

---

**Total deviations:** 2 auto-fixed (both Rule 1 — self-surfaced test/regression corrections found during this plan's own TDD cycle, not scope creep).
**Impact on plan:** None on scope or intent — both fixes were necessary for the plan's own stated success criteria (zero new suite regressions, guard test intact) to hold true.

## Issues Encountered

None beyond the two auto-fixed items above — both surfaced and were resolved within Task 3's own RED/GREEN cycle before commit.

## User Setup Required

None - no external service configuration required. Pure service-layer + test changes; no migration, no deploy (deploy and live re-verification remain a human/operator step per the Requirements Gate below).

## Requirements Gate (per this plan's explicit instructions)

- **HAZ-03: marked `[x]` complete.** Working at Height provably renders `3x4 -> 1x4` under the new score-precedence rule in an automated test (`ReviewedHazardTieringTest::test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores`, using a fixture with the real legacy vocabulary and no `score_reviewed` key present at all).
- **HAZ-02: deliberately left `[ ]` open.** It has been closed prematurely twice (2026-08-23 implicitly, then again by Plan 26-07's partial fix). The code-level fold+dedup mechanism is now genuinely correct and covered by tests using the real 7-name live vocabulary, but per this plan's explicit gate, HAZ-02 may only flip to complete after the human operator re-verifies live against real project data (21CQ30960 / RAMS 97 regenerated a third time) — automated-test evidence alone is not sufficient this time.

## Next Phase Readiness

- Both round-2 gaps are code-fixed and test-covered at the mechanism level. `26-VERIFICATION.md` round-3 (if run) should regenerate 21CQ30960 a third time and confirm: zero duplicate hazard names, no row titled "Confined Spaces", Working at Height at `1×4`, and record whether an electrical-related row appears (per this plan's `<investigation>`, its continued absence would indicate project 92's data history, not a code defect, and should NOT re-open HAZ-02 a third time without new evidence of a mechanism failure).
- `26-VERIFICATION.md` round-1/round-2 Outstanding items unaffected by this plan remain open: RAMS 96/97 are still test artefacts on production for project 92 (supersede or delete when convenient); rollback proof (`RAMS_HAZARD_LIBRARY_TIERING=false`) still not run against this plan's specific changes; review-screen UI (`/rams/{id}/quote-review`) still not visually confirmed on live.
- No blockers for subsequent phases. Phase 27/28 (house-rule text edits, including RULE-06's "Restricted access and ceiling void working" title correction) can proceed against a hazard register whose name-folding is now genuinely single-choke-point and tested against real production vocabulary.

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*

## Self-Check: PASSED

All 9 created/modified files confirmed present on disk; all 3 task commits (`71703c7`, `05e5fc7`, `744990f`) confirmed present in git history.
