---
phase: 26-hazard-library-structural-inversion
plan: 04
subsystem: rams
tags: [laravel, hazard-library, include-when, rams, tdd, risk-resolver]

# Dependency graph
requires:
  - phase: 26-01
    provides: "18-hazard global library seeded with the include_when tier vocabulary (always / signal:<key> / confirm:<key> / null)"
  - phase: 26-02
    provides: "HazardIncludeWhenResolver::resolve(Collection $library, array $signals): Collection — standalone, unit-tested tiered evaluator"
  - phase: 26-03
    provides: "Removal of all four render/build-time fixed-baseline re-injection paths + the hazard_tiering_enabled kill-switch"
provides:
  - "Path #5 fully removed: HazardLibraryService's MANDATORY_KEYWORDS / mandatoryBaseline() / mergeWithMandatory() dead code deleted"
  - "RiskTemplateResolverService wired to HazardIncludeWhenResolver — buildHazards()/resolveHazards() merge explicit engineer picks with tiered matches, gated by rams_tier1.hazard_tiering_enabled"
  - "Both upstream call sites (RamsExtractionDraftBuilderService::build(), RamsBuilderService::runPipeline()) build and forward a scope_narrative signal string"
  - "extracted_data['hazards'] rows carry numeric pre/post likelihood+severity, needs_confirmation, and score_reviewed instead of a Low/Medium/High risk label"
  - "HazardInjectionPathsRemovedGuardTest — structural guard proving zero references to the deleted machinery anywhere in app/ or tests/"
affects: [26-05, 26-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Explicit-pick-first merge: resolveHazards() always resolves explicit names via HazardLibraryService::resolveFromSeeds() first, then (when tiering is enabled) appends tiered matches deduplicated by id (library rows) or lower-cased name (unmatched pseudo-object seeds) — explicit picks are never dropped or overridden by a tiered match"
    - "Scope-narrative construction pattern (works_summary + works_description + equipment descriptions, array_filter + implode + trim) duplicated identically at both call sites rather than factored into a shared helper, matching the plan's explicit per-call-site action text"

key-files:
  created:
    - tests/Feature/Rams/RiskTemplateResolverServiceTest.php
    - tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php
  modified:
    - app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php
    - app/Services/RiskTemplateResolverService.php
    - app/Services/RamsExtractionDraftBuilderService.php
    - app/Services/RamsBuilderService.php

key-decisions:
  - "fuzzyMatch() preserved unchanged in HazardLibraryService — confirmed NOT a tier-3 AI plug-in point (2026-08-23 CONTEXT.md correction); it remains the deterministic string-matching mechanism resolveFromSeeds() uses for explicit engineer picks."
  - "resolveByIds()/resolveFromSeeds() lost their includeMandatory parameter entirely rather than being repurposed — with mandatoryBaseline()/mergeWithMandatory() deleted, empty-input now returns an empty Collection, not a fallback baseline."
  - "resolveHazards() always resolves explicit names first (unconditionally, regardless of the tiering flag), then conditionally layers tiered matches on top only when config('rams_tier1.hazard_tiering_enabled', true) is true — this is what makes the flag-off path degrade cleanly to manual-only with zero auto-population."
  - "Corrected an inaccuracy in the plan's own Task 2 <behavior> Test 1 (and its mirror in must_haves.truths): a blank signal set does NOT resolve to 'exactly the 4 always-tier hazards' — it resolves to those 4 PLUS the 5 always-confirm tier-3 hazards (9 total), because HazardIncludeWhenResolver (Plan 26-02, not modified by this plan) unconditionally surfaces confirm:<key> hazards on every job per CONTEXT.md's binding 2026-08-23 tier-3 correction ('up to 5 confirmation rows per job... accepted cost, not a defect to engineer away'). Verified end-to-end through RamsExtractionDraftBuilderService::build() with a no-signal extraction: output is 4 rows with needs_confirmation=false and 5 with needs_confirmation=true, never the old baseline."

patterns-established:
  - "Any future call site wanting hazard resolution must pass a scope_narrative (6th resolve() argument) to get tier-2 keyword matching; omitting it silently degrades tier-2 keyword-based matches to activity/drilling-only matches, which is safe but less precise."

requirements-completed: [HAZ-02]

# Metrics
duration: ~1h10min
completed: 2026-08-24
---

# Phase 26 Plan 04: Hazard Library Structural Inversion — Wire the Tiered Resolver (Keystone) Summary

**Deleted the last unconditional hazard-injection path (`HazardLibraryService`'s mandatory-baseline machinery), wired `RiskTemplateResolverService` to the tiered `HazardIncludeWhenResolver` built in Plan 26-02, and forwarded a real scope-narrative signal from both upstream call sites — a fresh quote-import RAMS now starts from the 4 always-tier hazards (plus the 5 hazards that always require human confirmation) and is genuinely added to by matched job signals, never a padded fixed baseline.**

## Performance

- **Duration:** ~1h10min
- **Started:** 2026-08-24T (session start, first Read of plan files)
- **Completed:** 2026-08-24T (this summary)
- **Tasks:** 3/3 completed
- **Files modified:** 6 (2 created, 4 modified)

## Accomplishments
- `HazardLibraryService::MANDATORY_KEYWORDS`, `mandatoryBaseline()`, `mergeWithMandatory()` deleted entirely. `resolveByIds()`/`resolveFromSeeds()` simplified to 2-parameter signatures (no `includeMandatory` flag); `fuzzyMatch()` preserved unchanged, confirmed as the engineer-pick matcher, not a tier-3 AI plug-in.
- `RiskTemplateResolverService` now injects `HazardIncludeWhenResolver`, accepts a 6th `scopeNarrative` parameter through `resolve()` → `buildHazards()` → `resolveHazards()`, and merges explicit engineer picks (via `HazardLibraryService::resolveFromSeeds()`) with tiered matches, deduplicated by `id`/lower-cased `name`. `config('rams_tier1.hazard_tiering_enabled')` off degrades cleanly to explicit-picks-only.
- Both live call sites — `RamsExtractionDraftBuilderService::build()` (quote-import auto-seed) and `RamsBuilderService::runPipeline()` (manual create form + regenerate) — build an identical scope-narrative expression from `works_summary` + `works_description` + equipment descriptions and forward it as the resolver's 6th argument.
- `RamsExtractionDraftBuilderService::buildHazards()` stopped downgrading numeric scores into a Low/Medium/High label; extraction-time hazard rows now carry `pre_likelihood`, `pre_severity`, `post_likelihood`, `post_severity`, `needs_confirmation`, and `score_reviewed` (unused `riskLabel()` removed).
- `RamsBuilderService::reviewedToRisk()`'s `resolveFromSeeds()` call dropped its trailing `false` argument to match the simplified signature (per the plan's explicit scope note — no other logic in that method touched, Plan 26-05 owns its HAZ-04 extension).
- New `HazardInjectionPathsRemovedGuardTest` proves zero references to `MANDATORY_KEYWORDS`, `mandatoryBaseline`, `mergeWithMandatory`, or `rams_tier1.baseline_hazards` remain anywhere in `app/` or `tests/`.
- Verified end-to-end (not just unit-level) via a throwaway manual check (removed before commit) that `RamsExtractionDraftBuilderService::build()` with genuinely blank scope text produces exactly the 4 always-tier + 5 always-confirm hazards, and that ceiling-mounted-display scope text correctly pulls in "Working at height", "Manual handling", and "Restricted access and ceiling voids".
- Full `--filter=Rams` namespace: 466 passed, 0 failed (was 461 before this plan). Full suite: 2251 passed, 1 failed — the 1 failure is `QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan`, a documented pre-existing environment-memory-limit flake unrelated to this plan's changes (its own test comment explains the cause).

## Task Commits

1. **Task 1: HazardLibraryService — remove mandatory-baseline dead code** - `28039e9` (refactor)
2. **Task 2 RED: failing tests for tiered RiskTemplateResolverService** - `182e9d6` (test)
2. **Task 2 GREEN: wire HazardIncludeWhenResolver into RiskTemplateResolverService** - `ce5fdc9` (feat)
3. **Task 3: wire call sites — scope narrative, numeric passthrough, structural guard test** - `bd440ab` (feat)

## Files Created/Modified
- `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` - Deleted `MANDATORY_KEYWORDS`, `mandatoryBaseline()`, `mergeWithMandatory()`; `resolveByIds()`/`resolveFromSeeds()` simplified to 2-parameter signatures; `fuzzyMatch()` untouched; class docblock updated.
- `app/Services/RiskTemplateResolverService.php` - Constructor now injects `HazardIncludeWhenResolver`; `resolve()` gained a 6th `scopeNarrative` parameter; `buildHazards()`/`resolveHazards()` rewritten to merge explicit picks with tiered matches, gated by `hazard_tiering_enabled`; output rows gained `needs_confirmation`.
- `app/Services/RamsExtractionDraftBuilderService.php` - `build()` constructs and forwards `scopeNarrative`; `buildHazards()` emits numeric scores + `needs_confirmation` + `score_reviewed` instead of a risk label; `riskLabel()` removed.
- `app/Services/RamsBuilderService.php` - `runPipeline()` constructs and forwards the same `scopeNarrative` expression; `reviewedToRisk()`'s `resolveFromSeeds()` call dropped its trailing `false` argument.
- `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` - 4 feature tests (RefreshDatabase + seeded `HazardTemplateSeeder`) covering blank-signal resolution, ceiling-activity tier-2 matching, explicit-pick merge, and the flag-off reversibility guarantee.
- `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php` - Structural substring-scan guard modeled on `DeadPathRemovalGuardTest`.

## Decisions Made
- Followed the plan's exact Task 1 removal targets and signature simplifications verbatim.
- Implemented `resolveHazards()` so explicit picks are ALWAYS resolved (even when tiering is disabled) and tiered matches are layered on top only when the flag is enabled — this is what makes disabling the flag degrade cleanly to "explicit picks only, zero auto-population" without any special-case branching for the explicit-names path.
- Did not touch `app/Services/RamsReviewDataService.php::normaliseHazards()` — its 4-key whitelist (`activity_key`/`hazard`/`risk`/`control_measures`) will silently drop the new `pre_likelihood`/`needs_confirmation`/`score_reviewed` keys when the review screen loads `extracted_data`. This is explicitly out of this plan's scope (PATTERNS.md flags it as an "implied touch-point" for HAZ-04, which is Plan 26-05's job, not 26-04's) — the register-population contract (HAZ-02) is satisfied regardless of what the review UI currently displays.

## Deviations from Plan

### Auto-fixed Issues

**1. [Plan-text correction, not a code bug] Task 2 Test 1 and the frontmatter `must_haves.truths` bullet both understate the blank-signal-set result**
- **Found during:** Task 2 GREEN phase, first test run against the real `HazardIncludeWhenResolver`
- **Issue:** The plan's Task 2 `<behavior>` block states Test 1 "returns exactly the 4 always-tier hazard rows" for a blank signal set, and the plan's frontmatter `must_haves.truths` makes the same claim ("produces a hazards draft containing only the 4 always-tier hazards"). Both statements conflict with `HazardIncludeWhenResolver`'s actual, correct, already-tested behavior from Plan 26-02 (not modified by this plan): `confirm:<key>` tier-3 hazards are unconditionally returned with `needs_confirmation=true` on *every* job, regardless of signals — this is CONTEXT.md's binding 2026-08-23 tier-3 correction ("up to 5 confirmation rows per job. That is the accepted cost, not a defect to engineer away. Do not reintroduce an AI call to reduce it.").
- **Fix:** Wrote the test to assert the actual, correct, locked contract: a blank signal set resolves to the 4 always-tier hazards (`needs_confirmation=false`) PLUS the 5 always-confirm tier-3 hazards (`needs_confirmation=true`) — 9 rows total. Verified this is not a regression to the old baseline by asserting the confirm-tier names are the 5 seeded `confirm:<key>` hazard names (Occupied premises, Asbestos-containing materials, Vehicle and plant movement, Lone and small-team working, Occupational road risk), not any of the old fixed-11 baseline titles. Also verified end-to-end through `RamsExtractionDraftBuilderService::build()` with a genuinely blank quote text — same 4+5 split.
- **Files modified:** `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` (test written correctly from the start, not patched after a false-positive commit)
- **Verification:** All 4 `RiskTemplateResolverServiceTest` tests pass; the same 4+5 split was independently confirmed via a throwaway manual `build()` invocation (removed before commit, not part of the test suite).
- **Committed in:** `ce5fdc9` (commit message documents the same reasoning)

---

**Total deviations:** 1 (plan-text imprecision, not a code defect — the implementation followed the correct, binding, already-locked design from Plan 26-02/CONTEXT.md rather than the plan's own imprecise restatement of it).
**Impact on plan:** None on scope or correctness — the actual delivered behavior is the one CONTEXT.md's tier-3 correction explicitly mandates and the one Plan 26-02's tests already lock in. No code in `HazardIncludeWhenResolver` was touched or needed to be.

## Issues Encountered
- `php` is not on `PATH` in this repo's execution shell — resolved by prepending `/c/Users/sonny.tanda/.config/herd/bin/php84` to `PATH` for every `php artisan` invocation, consistent with Plans 26-01/26-02/26-03's summaries.
- Ran the full test suite (`php artisan test`, no filter) beyond the plan's mandated `--filter=Hazard`/`--filter=Rams` scope, as a final regression check given this is the phase's keystone plan. Found exactly 1 failure: `QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan`, whose own inline comment documents it as a known environment-memory-limit artifact unrelated to hazard/RAMS code — not chased, per the environment_facts' explicit "do not chase" instruction for pre-existing unrelated failures.

## User Setup Required
None - no external service configuration required. Pure service-layer logic + tests; no migration, no deploy (Plan 26-06 owns live deploy).

## Next Phase Readiness
- **HAZ-02 is now genuinely satisfied end-to-end.** A fresh quote-import RAMS register starts from the 4 always-tier + 5 always-confirm hazards (never a full/old baseline) and grows via matched tier-2 signals when the job's captured scope contains them — verified through both `RamsExtractionDraftBuilderService::build()` (auto-seed) and `RamsBuilderService::runPipeline()` (manual form + regenerate), and marked complete in `REQUIREMENTS.md`.
- The register's `needs_confirmation` flag is now present in the data at both call sites, ready for Plan 26-05 to surface visually on the review screen (`resources/views/rams/quote-review.blade.php`) and to extend `RamsReviewDataService::normaliseHazards()`'s 4-key whitelist so the new numeric-score keys survive the review round-trip — neither of which this plan touches, per its stated scope boundary.
- `HazardIncludeWhenResolver`, `HazardLibraryService::fuzzyMatch()`, and the seeded 18-hazard library (Plans 26-01/26-02) are all consumed unchanged — no further changes to those files were needed or made.
- No blockers for Plan 26-05 or 26-06.

## Self-Check: PASSED

- `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` — FOUND, mandatory-baseline machinery absent, `fuzzyMatch()` present
- `app/Services/RiskTemplateResolverService.php` — FOUND, `HazardIncludeWhenResolver` injected and consumed
- `app/Services/RamsExtractionDraftBuilderService.php` — FOUND, `scopeNarrative` built and forwarded, `riskLabel()` absent
- `app/Services/RamsBuilderService.php` — FOUND, `scopeNarrative` built and forwarded, `resolveFromSeeds()` call has 2 args
- `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` — FOUND, 4 tests passing
- `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php` — FOUND, 1 test passing
- Commit `28039e9` — FOUND in `git log`
- Commit `182e9d6` — FOUND in `git log`
- Commit `ce5fdc9` — FOUND in `git log`
- Commit `bd440ab` — FOUND in `git log`
- `php artisan test --filter=Rams` — 466 passed, 0 failed
- `php artisan test` (full suite) — 2251 passed, 1 failed (pre-existing, documented, unrelated)

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*
