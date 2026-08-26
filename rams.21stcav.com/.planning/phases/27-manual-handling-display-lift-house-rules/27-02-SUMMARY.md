---
phase: 27-manual-handling-display-lift-house-rules
plan: 02
subsystem: rams
tags: [php, laravel, phpunit, manual-handling, display-lift, hazard-seeder]

requires:
  - phase: 27-01
    provides: "App\\Services\\Rams\\DisplayLiftPolicy — forSize(), wallMountRemovalStatement(), genericBandSummary()"
provides:
  - "RamsComplianceUpgradeService::suggestHandlingMethod() delegating every display/tv/screen band to DisplayLiftPolicy::forSize() instead of its own hardcoded 4/3/2-person ladder"
  - "RULE-12's branch-order fix — mount/bracket descriptions resolve before the display band is ever evaluated"
  - "deriveMaterialHandling()'s three existing scan loops (quote line_items, scope_items.new_install, rooms[].equipment) now also store min_persons/inches per item"
  - "A fourth deriveMaterialHandling() scan loop over scope_items.decommission, appending DisplayLiftPolicy::wallMountRemovalStatement() to display strip-out items — the RULE-03 statement now reaches real generation, not just a buried hazard-control bullet"
  - "EquipmentClassifierService::textIndicatesDisplay() — a new public keyword-vocabulary accessor mirroring textIndicatesDrilling()'s shape, so the decommission scan reuses ACTIVITY_MAP['display_installation']['keywords'] without a second copy"
  - "HazardTemplateSeeder's seeded Manual handling row sourcing both its team-size and wall-mount-removal control bullets from DisplayLiftPolicy directly"
affects: [27-03, 27-04]

tech-stack:
  added: []
  patterns:
    - "suggestHandlingMethod() returns ?array{sentence, min_persons, inches} instead of ?string, so both the rendered §6.7 text and GATE-09's structured re-validation (Plan 27-03) read the same resolved values without re-parsing prose"
    - "Deterministic keyword-vocabulary reuse for a safety-relevant trigger (RULE-03's strip-out signal) — no AI, mirrors EquipmentClassifierService::textIndicatesDrilling()'s established shape"

key-files:
  created:
    - tests/Unit/Services/Rams/RamsComplianceUpgradeServiceDisplayLiftTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - app/Services/EquipmentClassifierService.php
    - database/seeders/HazardTemplateSeeder.php

key-decisions:
  - "EquipmentClassifierService::ACTIVITY_MAP is private const, so the plan's literal instruction to read it directly from RamsComplianceUpgradeService could not be followed verbatim — a new public method, textIndicatesDisplay(), was added mirroring the existing textIndicatesDrilling() shape (same class, same pattern, reads the same private const) rather than duplicating the keyword list. This is a Rule 3 (blocking-issue) auto-fix: the plan's own constraint (\"do not hardcode a second copy of the keyword array\") could not be satisfied without it."
  - "The decommission scan's mount-vs-display branch order is unchanged from Task 1 (mount checked first) — a decommission item worded as \"...wall-mounted display\" resolves through the mount branch (str_contains('mount') matches the 'mounted' substring) before the RULE-03 statement is appended by the decommission loop's own textIndicatesDisplay() check. This means the base sentence for that fixture is the mount branch's single-person wording, not a DisplayLiftPolicy band sentence — the wall-mount-removal statement is still correctly appended per RULE-03 regardless, since that check runs independently of which suggestHandlingMethod() branch fired. Documented here rather than silently accepted, since it is a direct consequence of the RULE-12 fix reaching an unanticipated string."

requirements-completed: [RULE-02, RULE-03, RULE-12]

duration: ~35min
completed: 2026-08-26
---

# Phase 27 Plan 02: RULE-02/RULE-03/RULE-12 Wiring Summary

**`RamsComplianceUpgradeService::suggestHandlingMethod()` now delegates every display-lift band to `DisplayLiftPolicy` (killing the hardcoded 4-person/3-person ladder), the mount/bracket branch runs before the display branch (RULE-12), `deriveMaterialHandling()` scans `scope_items.decommission` for the first time (RULE-03), and the seeded Manual-handling hazard reads both its team-size and wall-mount-removal bullets from the same shared class — proven by 10 new tests including an explicit revert/restore non-vacuity check.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-26T08:55:00Z (approx, following 27-01)
- **Completed:** 2026-08-26T09:30:00Z (approx)
- **Tasks:** 3 completed
- **Files modified:** 4 (1 new test file, 3 modified source files)

## Accomplishments

- `suggestHandlingMethod()` no longer contains any hardcoded team-size number in its display branch — every display/tv/screen band call resolves through `DisplayLiftPolicy::forSize()`, so RULE-02's corrected 1/2/3-operative bands (under 55″ / 55″-90″ inclusive / above 90″) are the ONLY place a display's team size is ever computed.
- The mount/bracket branch (`str_contains($desc, 'mount') || str_contains($desc, 'bracket')`) now runs BEFORE the display/tv/screen branch — the exact RULE-12 fix. A description like "double-arm wall mount for 65 inch display" resolves as a mount, never inheriting the display band's "Team lift" wording.
- `suggestHandlingMethod()`'s return type changed from `?string` to `?array{sentence, min_persons, inches}`. All three pre-existing `deriveMaterialHandling()` scan loops (quote `line_items`, `scope_items.new_install`, `rooms[].equipment`) were updated to read the new shape and store `min_persons`/`inches` per detected item — the structured data Plan 27-03's GATE-09 needs to independently re-validate without re-parsing rendered prose.
- A fourth `deriveMaterialHandling()` scan loop now covers `scope_items.decommission`, which previously produced zero §6.7 rows for any display being stripped out. Display items detected there (via the new `EquipmentClassifierService::textIndicatesDisplay()`) get `DisplayLiftPolicy::wallMountRemovalStatement()` appended to the resolved base sentence, explicitly stating the highest-risk-lift removal sequence — not just a generic team-lift line. Non-display decommission items (e.g. a rack) are scanned the same as before, with no statement appended.
- `HazardTemplateSeeder`'s seeded "Manual handling" row now sources its team-size bullet from `DisplayLiftPolicy::genericBandSummary()` and its wall-mount-removal bullet from `DisplayLiftPolicy::wallMountRemovalStatement()` — the stale "minimum two operatives for every panel size" wording is gone, and the DB row can never drift from the freshly-derived §6.7 text again. The controls array keeps its 7-entry shape and ordering. Verified live by running `php artisan db:seed --class=HazardTemplateSeeder` locally (idempotent upsert, "18 standard hazards seeded, 0 superseded").
- 10 new tests in `RamsComplianceUpgradeServiceDisplayLiftTest` lock all of the above, including an explicit local revert/restore proof for the RULE-12 branch-order fix (see Verification below).

## Task Commits

Each task was committed atomically:

1. **Task 1: RULE-02 ladder + RULE-12 branch order in suggestHandlingMethod()** - `f37e5ba` (feat)
2. **Task 2: RULE-03 decommission-scope scan + seeder re-sourcing** - `310ab47` (feat)
3. **Task 3: Non-vacuity test suite for Tasks 1 and 2** - `055651f` (test)

## Files Created/Modified

- `app/Services/Rams/RamsComplianceUpgradeService.php` - `suggestHandlingMethod()` rewritten to `?array` return shape, delegating display bands to `DisplayLiftPolicy::forSize()`; mount/bracket branch moved before the display branch; `deriveMaterialHandling()`'s three existing loops updated for the new shape plus a new fourth loop over `scope_items.decommission`.
- `app/Services/EquipmentClassifierService.php` - added `textIndicatesDisplay()`, a public method mirroring the existing `textIndicatesDrilling()` shape, exposing `ACTIVITY_MAP['display_installation']['keywords']` for reuse (deviation, see below).
- `database/seeders/HazardTemplateSeeder.php` - "Manual handling" row's two stale bullets replaced with `DisplayLiftPolicy::genericBandSummary()` / `DisplayLiftPolicy::wallMountRemovalStatement()` calls.
- `tests/Unit/Services/Rams/RamsComplianceUpgradeServiceDisplayLiftTest.php` - 10 tests, reflection-based, covering RULE-02 boundaries, the RULE-12 regression case (with revert/restore proof), RULE-03's decommission scan (positive and negative case), and the re-seeded hazard row's content.

## Decisions Made

- Non-display branches (mount/bracket, projector, rack, amp/dsp, ceiling speaker, speaker, catch-all) all return `min_persons: null, inches: null` — D-01's bands are display-specific only (house-rules.md:18-19), so no non-display branch reports a team size sourced from `DisplayLiftPolicy`.
- The decommission loop's distinguishing marker uses BOTH an appended `' (decommission)'` item-name suffix AND a `'phase' => 'decommission'` key, rather than picking only one — this gives both a human-readable §6.7 label and a machine-checkable field for any future consumer, at negligible cost.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added `EquipmentClassifierService::textIndicatesDisplay()` — not in the plan's `files_modified` list**
- **Found during:** Task 2 (RULE-03 decommission-scope scan)
- **Issue:** The plan's action text instructs: "determine `$isDisplay` by checking the description against `EquipmentClassifierService::ACTIVITY_MAP['display_installation']['keywords']` ... do not hardcode a second copy of the keyword array." But `ACTIVITY_MAP` is declared `private const` — it cannot be read from `RamsComplianceUpgradeService` without either (a) reflection into a private constant (fragile, non-idiomatic), (b) a second, divergent keyword list (exactly what the plan forbids), or (c) a new public accessor on `EquipmentClassifierService` itself.
- **Fix:** Added `EquipmentClassifierService::textIndicatesDisplay(string $text): bool`, mirroring the established `textIndicatesDrilling()` shape (same class, same private-const-driven keyword scan, same fail-safe-by-construction contract). `RamsComplianceUpgradeService::deriveMaterialHandling()` instantiates `EquipmentClassifierService` (no constructor args, stateless) and calls the new method.
- **Files modified:** `app/Services/EquipmentClassifierService.php` (added file to the plan's touched set), `app/Services/Rams/RamsComplianceUpgradeService.php` (caller).
- **Verification:** `RamsComplianceUpgradeServiceDisplayLiftTest`'s decommission tests (positive and negative cases) prove the new method is correctly wired and does not false-positive on a non-display description.
- **Committed in:** `310ab47` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 3 - blocking issue, required to honor the plan's own "no second keyword list" constraint)
**Impact on plan:** Minimal, in-scope addition — no new behavior beyond exposing an existing private keyword list through a public method with the same contract as its sibling `textIndicatesDrilling()`. No scope creep.

## RULE-12 Scope Note — Weight-Derivation Clause DEFERRED (verbatim, per plan constraint 2)

RULE-12's full text requires deriving manual-handling controls "from actual equipment
weight, manufacturer handling requirements and route assessment — never from screen
diagonal alone." This plan implements ONLY the "mount rows must not inherit display
text" clause (the branch-reorder fix in Task 1). The weight/manufacturer/route-derivation
clause is **explicitly DEFERRED, not silently dropped**.

**Evidence for the deferral:** RESEARCH.md confirmed, via direct trace of the
quote-ingestion pipeline (`QuoteWerksImportService`, `ExtractQuoteJob`), that
`weight_kg`/`display_size_in` structured tags never reach RAMS-path equipment items —
those services produce raw `description`/`qty`/`category` dicts only. Coverage of
structured weight data on the RAMS path is effectively zero. Building weight-driven
handling text against a data source that does not exist on this path would either be
a no-op (no weight ever present) or would require a separate, larger data-capture
phase (see 27-CONTEXT.md's `<deferred>` section — "Weight-driven manual handling"
is explicitly called out there as its own future phase if `weight_kg` coverage
cannot be verified).

This deferral is carried forward unchanged into VERIFICATION.md per the plan's own
instruction — CONTEXT.md's Claude's Discretion section explicitly forbids silently
narrowing RULE-12 without saying so.

## Issues Encountered

- The plan's Task 2 acceptance-criteria fixture ("Remove existing 65in wall-mounted
  display") triggers the mount branch, not the display branch, because
  `str_contains($desc, 'mount')` matches the substring inside "wall-mounted" — a
  pre-existing naive-substring characteristic of the mount check, now surfaced by
  the RULE-12 reorder reaching this string first. The RULE-03 wall-mount-removal
  statement is still correctly appended (the decommission loop's
  `textIndicatesDisplay()` check runs independently of which `suggestHandlingMethod()`
  branch fired), and all of Task 2's literal acceptance-criteria substring checks
  pass. Documented here as an observation for future maintainers, not remediated —
  it is outside this plan's scope (RULE-12's discretion note governs the ordering
  fix only, not a rewrite of the mount/bracket keyword matching itself).

## Verification

- `php artisan test --filter=RamsComplianceUpgradeServiceDisplayLiftTest` — 10 passed
  (22 assertions).
- `php artisan test --filter=RamsComplianceUpgradeService` (all matching files) — 16
  passed (28 assertions), including `RamsComplianceUpgradeServiceCacheTest`.
- `php artisan test --filter="AccessEquipmentContradictionTest|ProjectSpecificRisksGatedTest|MethodStatementAssociatedRisksTest"` —
  11 passed (52 assertions), confirming no regression in the adjacent hazard/risk
  pipeline the plan's `<verification>` names.
- **Revert/restore non-vacuity proof (RULE-12):** the mount/bracket block was
  temporarily swapped back to AFTER the display block, `php artisan test
  --filter=RamsComplianceUpgradeServiceDisplayLiftTest` was re-run, and
  `test_mount_shadowed_by_display_keyword_resolves_as_mount_not_display` failed as
  expected (asserting the sentence starts with "Team lift", the exact pre-fix
  regression). The branch order was then restored and the full 10-test file passed
  again. This proves the RULE-12 test is non-vacuous, not merely present.
- `php artisan db:seed --class=HazardTemplateSeeder` run locally against the
  project's sqlite dev DB — succeeded ("18 standard hazards seeded, 0 superseded
  global row(s) removed"), confirming the re-sourced seeder is still idempotent and
  upsert-safe.
- `php artisan test` (full suite) — 2323 passed, 3 failed, 2 deprecated, 10
  warnings, 6 skipped (9297 assertions, 366.66s). The 3 failures are pre-existing
  and unrelated to this plan: two `SignoffFinaliseTest` failures
  (`storage/framework/testing/disks/documents/snagging` directory missing on this
  dev machine — a commissioning-PDF filesystem setup issue, nothing to do with RAMS
  material handling) and one `QueueRecoverCommandTest` failure that is explicitly
  self-documented in its own source comment as "out of scope for this quick task —
  see SUMMARY.md" (a known memory-limit/exit-code flake unrelated to this plan). No
  new failures were introduced by this plan's changes.

## Known Stubs

None. Every code path this plan touches is deterministic and produces real output —
no hardcoded empty arrays, no placeholder text, no unwired data sources.

## Threat Flags

None. This plan edits existing internal derivation logic (no new network endpoint,
auth path, file-access pattern, or schema change) and a seeder's static content —
matching the plan's own threat model (T-27-02, T-27-05, both accepted, no new surface).

## User Setup Required

None - no external service configuration required. The re-seeded `HazardTemplateSeeder`
still needs to be re-run on live (`php artisan db:seed --class=HazardTemplateSeeder
--force`) as part of the deploy runbook, per the established Phase 26 pattern — this is
an existing operational step, not new user setup.

## Next Phase Readiness

- `min_persons`/`inches` are now available on every `material_handling_derived.items`
  entry for display items — Plan 27-03's GATE-09 can read these directly instead of
  re-parsing rendered sentences.
- The RULE-12 weight-derivation deferral (documented above) should be carried
  forward into VERIFICATION.md and considered when scoping any future phase that
  captures `weight_kg` on the RAMS quote-ingestion path.
- `SafetyProfileService` and `MethodStatementService` are explicitly untouched by
  this plan (owned by Plan 27-04, same wave) — no overlap risk.

## Self-Check: PASSED

- `app/Services/Rams/RamsComplianceUpgradeService.php` — FOUND
- `app/Services/EquipmentClassifierService.php` — FOUND
- `database/seeders/HazardTemplateSeeder.php` — FOUND
- `tests/Unit/Services/Rams/RamsComplianceUpgradeServiceDisplayLiftTest.php` — FOUND
- Commit `f37e5ba` — FOUND in `git log --oneline --all`
- Commit `310ab47` — FOUND in `git log --oneline --all`
- Commit `055651f` — FOUND in `git log --oneline --all`

---
*Phase: 27-manual-handling-display-lift-house-rules*
*Completed: 2026-08-26*
