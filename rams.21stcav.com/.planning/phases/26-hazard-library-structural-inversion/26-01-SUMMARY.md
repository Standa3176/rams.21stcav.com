---
phase: 26-hazard-library-structural-inversion
plan: 01
subsystem: database
tags: [laravel, migration, seeder, hazard_templates, rams]

# Dependency graph
requires:
  - phase: null
    provides: null
provides:
  - "hazard_templates.include_when column (nullable text) — the tiered
    always/signal:<key>/confirm:<key>/null condition string"
  - "18-hazard global library seeded from the 21cav-rams skill's
    hazard-library.md, replacing the old 13-hazard baseline"
  - "HazardTemplate::$fillable extended with include_when (seeder write path
    only — verified NOT reachable through HazardTemplateController mass
    assignment)"
  - "Regression test proving seeder idempotency, is_global=false row safety,
    and the T-26-01 mass-assignment mitigation end-to-end via a real HTTP
    POST"
affects: [26-02, 26-03, 26-04, 26-05, 26-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guarded add-column migration (Schema::hasColumn) — no try/catch
      wrapper, that pattern is reserved for Schema::create"
    - "Seeder upsert-by-name loop preserved verbatim; orphan-row cleanup
      appended as a strictly is_global=true-scoped delete, never a truncate"

key-files:
  created:
    - database/migrations/2026_08_23_160000_add_include_when_to_hazard_templates.php
    - tests/Feature/HazardTemplates/HazardTemplateSeederIncludeWhenTest.php
  modified:
    - database/seeders/HazardTemplateSeeder.php
    - app/Models/HazardTemplate.php
    - app/Http/Controllers/HazardTemplateController.php

key-decisions:
  - "include_when values follow the locked convention: 'always' (4 tier-1
    hazards), 'signal:<key>' (9 tier-2), 'confirm:<key>' (5 tier-3, human-
    confirmation-only, never AI-evaluated per CLAUDE.md's AI-usage
    constraint)"
  - "Orphan-row cleanup scoped to ->where('is_global', true)->whereNotIn(...)
    so it can never reach an is_global=false (user-created) row, satisfying
    D-03's no-truncate/no-user-row-touch guarantee"
  - "Rule 1 auto-fix: HazardTemplateController::store()/update() now use
    $data['description'] ?? null instead of unconditional array access,
    fixing a pre-existing undefined-array-key 500 error hit by the plan's
    own mandated test payload (description omitted, which is valid since
    the field is validated as nullable)"

patterns-established:
  - "Tiered include_when vocabulary (always / signal:<key> / confirm:<key> /
    null) is now the source-of-truth convention Plan 26-02's resolver must
    consume unchanged"

requirements-completed: [HAZ-01, HAZ-03]

# Metrics
duration: ~30min
completed: 2026-08-24
---

# Phase 26 Plan 01: Hazard Library Structural Inversion — Seed Data Foundation Summary

**Ported the 21cav-rams skill's full 18-hazard library into `hazard_templates` with a new tiered `include_when` column, retiring the old 13-hazard baseline via a scoped orphan-row cleanup that never touches user-created rows.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-08-24T10:20:00Z (approx, first Read of plan files)
- **Completed:** 2026-08-24T11:50:00Z
- **Tasks:** 3/3 completed
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments
- `hazard_templates` gained a guarded, reversible `include_when` nullable text column.
- `HazardTemplateSeeder` rewritten to emit all 18 hazard-library.md hazards verbatim (names, full control lists, typical initial/residual L×S scores), each tagged with its tier's `include_when` value.
- Working at height seeded with residual `1×4` (not the old baseline's `2×3`) — the checkable HAZ-03 proof.
- Orphan-row cleanup removes the 6 D-02-superseded old global hazard names without ever touching `is_global=false` rows or truncating.
- Local SQLite DB migrated and seeded: `hazard_templates` now has exactly 18 `is_global=true` rows, 0 with a null `include_when`, 0 `is_global=false` rows affected (none pre-existed).
- New regression test (`HazardTemplateSeederIncludeWhenTest`, 3 tests / 7 assertions) proves row count, re-seed idempotency + user-row safety, and the T-26-01 mass-assignment mitigation through a real HTTP POST to `hazard-templates.store`.

## Task Commits

1. **Task 1: Migration — add include_when column, extend HazardTemplate::$fillable** - `90dffca` (feat)
2. **Task 2: Rewrite HazardTemplateSeeder — the 18-hazard library + orphan-row cleanup** - `10f26f0` (feat)
3. **Task 3: [BLOCKING] Migrate + seed locally, verify row state, document live deploy** - `8084ddb` (test)

_Task 3's commit also carries the Rule 1 controller fix required to make its own mandated test pass (see Deviations below)._

## Files Created/Modified
- `database/migrations/2026_08_23_160000_add_include_when_to_hazard_templates.php` - Adds `include_when` nullable text column after `controls`, `Schema::hasColumn`-guarded, reversible.
- `database/seeders/HazardTemplateSeeder.php` - `standardHazards()` now returns the 18 hazard-library.md hazards with `include_when`; `run()` appends a scoped orphan-row delete after the existing upsert loop.
- `app/Models/HazardTemplate.php` - `include_when` added to `$fillable` (no cast — plain nullable string).
- `app/Http/Controllers/HazardTemplateController.php` - `description` now defaults to `null` via `?? null` in both `store()` and `update()` payloads (Rule 1 fix, unrelated to `include_when` mass-assignment exclusion, which remains untouched).
- `tests/Feature/HazardTemplates/HazardTemplateSeederIncludeWhenTest.php` - 3 feature tests + live-deploy-sequence docblock.

## Decisions Made
- Followed the plan's locked `include_when` tier vocabulary and per-hazard key assignments exactly (4 always / 9 signal:<key> / 5 confirm:<key>).
- Orphan-row cleanup implemented as a single `whereNotIn('name', $newNames)` delete scoped by `where('is_global', true)`, matching the plan's exact prescribed shape — not a truncate, cannot reach user rows by construction.
- Did not touch `HazardTemplateController`'s validated/mass-assignment surface for `include_when` — verified via Task 3's test 3 that the existing literal-array construction already excludes it (T-26-01 mitigation intact, no new code needed for that part).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed undefined-array-key 500 on HazardTemplateController::store()/update() when `description` is omitted**
- **Found during:** Task 3 (writing the plan-mandated spoofed-`include_when` POST test)
- **Issue:** `validateTemplate()` validates `description` as `nullable`, meaning `$request->validate()` omits the key entirely from `$data` when the field is absent from the request body. Both `store()` and `update()` did unconditional `$data['description']` array access, throwing an `ErrorException: Undefined array key "description"` (500) whenever a caller omits the field — exactly the payload shape the plan's own Task 3 example specifies (no `description` key).
- **Fix:** Changed both call sites to `$data['description'] ?? null`.
- **Files modified:** `app/Http/Controllers/HazardTemplateController.php`
- **Verification:** `HazardTemplateSeederIncludeWhenTest::test_store_route_drops_spoofed_include_when_from_mass_assignment` now passes (previously errored with a 500 before the row could even be inspected). No other test in the repo exercises `hazard-templates.store`/`update`, so this is a pure bug fix with no observed blast radius.
- **Committed in:** `8084ddb` (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Necessary to make the plan's own mandated Task 3 test pass. No scope creep — single two-line fix, no new behaviour beyond making an already-nullable field actually optional.

## Issues Encountered
- `php` was not on `PATH` in the execution shell; resolved by prepending `/c/Users/sonny.tanda/.config/herd/bin/php84` for all `php`/`php artisan` invocations in this session.
- Debugging the Task 3 test failure required two throwaway debug edits (a `dump()`/`getSession()` attempt that hung the process, then `withoutExceptionHandling()`) to surface the real 500 error underneath what first looked like a silent validation failure. Both were reverted before the final commit; only the clean 3-test file was committed.
- Ran the project's full test suite in the background to check for wider regressions; it did not complete within a reasonable window (large PDF/DOCX-generation suite) and was terminated. This plan's actual verification requirement (`php artisan test --filter=HazardTemplateSeederIncludeWhenTest`) passed cleanly, and a targeted grep confirmed no other test file references `HazardTemplateController`, `hazard-templates.store/update`, or `HazardTemplateSeeder`/`standardHazards`, so the changed surface has no other test consumers to regress.

## User Setup Required
None - no external service configuration required. Live deploy sequence (deploy as `stcav`, `git pull`, `php artisan migrate --force`, `php artisan db:seed --class=HazardTemplateSeeder --force`) is documented as a docblock on the new test file for the Plan 26-06 human checkpoint; not executed in this plan per the phase boundary (live deploy is out of scope here).

## Next Phase Readiness
- `hazard_templates` now holds the full 18-hazard library with correct `include_when` tier tags and the HAZ-03 residual-score fix — the data foundation Plan 26-02 (include-when resolver) reads from.
- The `include_when` vocabulary (`always` / `signal:<key>` / `confirm:<key>` / `null`) is locked and consumable as-is by the resolver; no further schema changes anticipated for it.
- Local DB is migrated and seeded — downstream plans' automated tests against `hazard_templates` will see the 18-row library, not a stale/empty table.
- Live deploy (rams.21stcav.com) has NOT been touched by this plan — remains on the old 13-hazard baseline until Plan 26-06's checkpoint runs the documented deploy sequence.

## Self-Check: PASSED

All created files verified present on disk; all three task commits (`90dffca`, `10f26f0`, `8084ddb`) verified present in git log.

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*
