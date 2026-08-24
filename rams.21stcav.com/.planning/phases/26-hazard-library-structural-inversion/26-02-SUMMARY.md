---
phase: 26-hazard-library-structural-inversion
plan: 02
subsystem: rams
tags: [laravel, hazard-library, include-when, rams, tdd, unit-test]

# Dependency graph
requires:
  - phase: 26-01
    provides: "18-hazard global library seeded with the include_when tier vocabulary (always / signal:<key> / confirm:<key> / null)"
provides:
  - "HazardIncludeWhenResolver::resolve(Collection $library, array $signals): Collection — standalone, unit-tested tiered include-when evaluator (D-05 tiers 1/2/3, D-06 always-flag fallback, D-04 null-exclusion)"
  - "8 passing unit tests proving every tier's boundary behaviour with no DB dependency"
affects: [26-04, 26-05, 26-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fixed-vocabulary keyword/tag matching (no open-ended regex) — mirrors RiskTemplateResolverService::PPE_ACTIVITY_MAP/ACCESS_EQUIPMENT_MAP discipline"
    - "Transient, unsaved dynamic model attributes (needs_confirmation/match_tier/pre_ticked) attached via Eloquent __set without ->save() — mirrors HazardLibraryService::resolveFromSeeds()'s unmatched-seed wrapping pattern"

key-files:
  created:
    - app/Services/Rams/HazardIncludeWhenResolver.php
    - tests/Unit/Services/Rams/HazardIncludeWhenResolverTest.php
  modified: []

key-decisions:
  - "No AI/LLM call anywhere in this resolver — tier 3 (confirm:<key>) always returns the hazard flagged for human confirmation; a keyword hit only sets a transient pre_ticked ordering hint, never auto-confirms or excludes. This is the 2026-08-23 D-05 correction, made binding by CLAUDE.md's AI-usage constraint."
  - "Test fixtures use two separate small collections (alwaysAndSignalFixture: 4 always + 9 signal + 1 null row; confirmFixture: the 5 confirm rows) rather than one shared 18-row library, so tier-1's 'returns exactly the 4 always rows' assertion isn't muddied by tier-3's unconditional-inclusion behaviour, which is orthogonal to what that specific test proves."
  - "Unrecognised/malformed include_when strings fail closed (treated as null/manual-only) rather than throwing or silently matching — defensive default not explicitly required by the plan but consistent with D-04's manual-only default."

patterns-established:
  - "Single-pass map()->filter()->values() evaluation (not a separate filter+map) — one evaluate() call per row decides both inclusion and decoration together, avoiding duplicated tier-matching logic between a filter callback and a map callback."

requirements-completed: []

# Metrics
duration: ~45min
completed: 2026-08-24
---

# Phase 26 Plan 02: Hazard Library Structural Inversion — Include-When Resolver Summary

**Standalone `HazardIncludeWhenResolver` service implementing D-05's three-tier include-when evaluation (always / deterministic signal-match / always-confirm), proven correct by 8 unit tests with zero DB dependency and zero AI calls.**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-08-24T (session start, first file reads)
- **Completed:** 2026-08-24T (this summary)
- **Tasks:** 2/2 completed
- **Files modified:** 2 (both created)

## Accomplishments
- `HazardIncludeWhenResolver::resolve()` correctly implements all three D-05 tiers plus D-04's null-exclusion and D-06's always-flag fallback, matching the 2026-08-23 tier-3 correction exactly: `confirm:<key>` rows are always returned with `needs_confirmation=true` on every job, never auto-confirmed, never excluded by a keyword hit.
- Tier-2 (`signal:<key>`) evaluation supports three independent match paths per key — activity-key intersection, narrative keyword scan, and a flat drilling-required flag list — matching against exactly the seeded key vocabulary from Plan 26-01 (`mounting_above_reach`, `display_mount_or_rack`, `mains_connection`, `drilling_or_percussive`, `ceiling_void_access`, `first_fix_cabling`, `any_penetration`, `any_drilling`, `strip_out_or_decommission`).
- Tier-3 pre-tick keyword vocabulary covers all 5 confirm keys seeded by Plan 26-01 (`occupied_premises`, `asbestos`, `vehicle_plant`, `lone_working`, `road_risk`) as an ordering-only hint, never affecting inclusion or confirmation state.
- 8 unit tests (52 assertions) cover every tier boundary, including the critical tier-2-vs-tier-3 distinction: a tier-2 miss is proven genuinely absent (by name, not just a false flag), while a tier-3 row is proven always present and always flagged regardless of keyword match.
- No AI/LLM import or call exists anywhere in the file — verified by design (no `App\Core\AI\Prompts` import, no call into `HazardLibraryService::resolveFromSeeds()` or any fuzzy-match path).
- Not wired into the RAMS pipeline — confirmed no other file references `HazardIncludeWhenResolver` yet; Plan 26-04 owns that integration.

## Task Commits

1. **Task 1: HazardIncludeWhenResolver — tier 1/2/3 evaluation** - `50048fe` (feat)
2. **Task 2: Unit tests — full tier + D-06 coverage** - `70ad97d` (test)

## Files Created/Modified
- `app/Services/Rams/HazardIncludeWhenResolver.php` - Tiered resolver: `resolve(Collection $library, array $signals): Collection`. Three private const keyword/activity maps (`TIER2_ACTIVITY_SIGNALS`, `TIER2_KEYWORD_SIGNALS`, `TIER3_KEYWORD_PRECHECK`) plus a flat `TIER2_DRILLING_SIGNALS` list. Single `evaluate()` method per row decides inclusion and decoration together; unmatched/null rows return `null` and are filtered out.
- `tests/Unit/Services/Rams/HazardIncludeWhenResolverTest.php` - 8 test methods, no `RefreshDatabase`, unsaved `HazardTemplate` fixtures built via `collect([new HazardTemplate([...]), ...])`.

## Decisions Made
- Implemented `resolve()` as a single `map()->filter()->values()` pass with one `evaluate()` method per row (rather than a separate `filter()` callback followed by a `map()` callback, as the plan's action text sketched) — this avoids computing tier-2/tier-3 match logic twice per row and keeps the inclusion decision and the decoration (`needs_confirmation`/`match_tier`/`pre_ticked`) provably consistent with each other, since they're now produced by the same code path instead of two independently-maintained ones.
- Split the test fixtures into `alwaysAndSignalFixture()` (tiers 1+2, no tier-3 rows) and `confirmFixture()` (tier 3 only) rather than one shared 18-row library. Rationale: tier 3 is unconditionally returned on every job regardless of signals, so any fixture containing both always-rows and confirm-rows would make a literal "returns exactly the 4 always rows" assertion (Test 1) false for reasons unrelated to what that test is proving. This is a test-organisation choice only — the resolver's behaviour when given a mixed 18-row library (as Plan 26-04 will actually pass it) is unaffected and implicitly proven correct by tiers being evaluated independently per-row.
- Unknown/malformed `include_when` values (anything not `null`, `'always'`, `'signal:*'`, or `'confirm:*'`) fail closed to excluded, matching D-04's "manual-only by default" spirit for any value the tiered vocabulary doesn't recognise. Not explicitly required by the plan's behaviour list, but a defensive default consistent with the surrounding rules; no test asserts this specific branch since it's not part of the plan's 8 required behaviors.

## Deviations from Plan

None in the code — plan executed exactly as written. The two decisions above are implementation-detail choices within the plan's stated design freedom ("Return `$library->filter(...)->map(...)` ... " was described as the shape, not a mandated literal structure), not deviations from any `<truths>`, `<behavior>`, or `<action>` requirement.

**Process correction:** the plan's frontmatter lists `requirements: [HAZ-02]`, and the standard executor state-update step ran `requirements mark-complete HAZ-02`, which briefly checked HAZ-02 off in `REQUIREMENTS.md`. Reverted before committing: HAZ-02's own requirement text — "a hazard is included only when the job meets it... register starts empty and is added to" — describes end-to-end pipeline behaviour, and this plan's `environment_facts` are explicit that "it does not wire anything into the RAMS pipeline — plan 26-04 does that." Marking it complete here would misrepresent project state (the live RAMS pipeline still injects the old fixed baseline until 26-04 lands). `REQUIREMENTS.md` now reads HAZ-02 as `[ ]` Pending with a note crediting this plan's logic and pointing at 26-04 for the wiring that actually satisfies the requirement.

## Issues Encountered
- `php` is not on `PATH` in the execution shell for this repo (same issue noted in Plan 26-01's summary) — resolved by prepending `/c/Users/sonny.tanda/.config/herd/bin/php84` to `PATH` for every `php artisan` invocation in this session.
- Ran the full `Rams` feature/unit test namespace (`php artisan test --filter=Rams`) as a broader regression check beyond the plan's mandated scoped test — 461 tests passed, 0 failures, confirming this plan's new standalone file introduced no regressions anywhere in the existing RAMS test surface (expected, since it isn't wired into any pipeline yet).

## User Setup Required
None - no external service configuration required. Pure logic + unit tests, no migration, no seeder, no deploy.

## Next Phase Readiness
- `HazardIncludeWhenResolver` is a complete, tested, standalone unit ready for Plan 26-04 to wire into `RiskTemplateResolverService` (or `RamsBuilderService::reviewedToRisk()`, per `26-PATTERNS.md`'s analysis of the actual live call site).
- The resolver's `resolve()` output shape (Collection of `HazardTemplate` instances carrying `needs_confirmation`/`match_tier`/`pre_ticked`) is the contract Plan 26-04 must adapt to `RiskTemplateResolverService::buildHazards()`'s existing row shape (`id`, `hazard`, `persons_at_risk`, `pre_likelihood`, `pre_severity`, `controls`, `post_likelihood`, `post_severity`) — not yet done, correctly deferred to 26-04 per this plan's environment_facts.
- No blockers. The tier vocabulary consumed here (`always`/`signal:<key>`/`confirm:<key>`/`null`) matches Plan 26-01's seeded data exactly — verified by cross-referencing every `include_when` value in `database/seeders/HazardTemplateSeeder.php` against this resolver's constant maps; all 9 signal keys and all 5 confirm keys have a corresponding matcher entry.

## Self-Check: PASSED

- `app/Services/Rams/HazardIncludeWhenResolver.php` — FOUND
- `tests/Unit/Services/Rams/HazardIncludeWhenResolverTest.php` — FOUND
- Commit `50048fe` — FOUND in `git log`
- Commit `70ad97d` — FOUND in `git log`
- `php artisan test --filter=HazardIncludeWhenResolverTest` — 8 passed, 52 assertions, 0 failures

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*
