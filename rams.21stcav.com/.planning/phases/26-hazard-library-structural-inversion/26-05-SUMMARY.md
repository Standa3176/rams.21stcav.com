---
phase: 26-hazard-library-structural-inversion
plan: 05
subsystem: rams
tags: [laravel, hazard-library, rams-review, tdd, blade, haz-04]

# Dependency graph
requires:
  - phase: 26-04
    provides: "Tiered resolver wired live; extracted_data['hazards'] rows already carry numeric pre/post likelihood+severity, needs_confirmation, and score_reviewed instead of a Low/Medium/High label"
provides:
  - "RamsReviewDataService::normaliseHazards() extended schema: pre_likelihood/pre_severity/post_likelihood/post_severity/score_reviewed/needs_confirmation, with a legacy risk-string bucket fallback for old rows"
  - "RamsBuilderService::reviewedToRisk() honours an already-numeric row score over a library re-lookup, and carries score_reviewed/needs_confirmation into generated_data['hazards'][*]"
  - "quote-review.blade.php: 4 editable numeric L×S inputs per hazard row (replacing the Low/Medium/High select), a client-side score_reviewed flip-on-edit marker, and a visible needs-confirmation badge + row highlight"
  - "RamsReviewController::parseReviewPayload() hazards block now delegates to RamsReviewDataService::normalise() instead of running its own narrower ad hoc parser, so Save/Approve POST no longer silently drops the new fields"
affects: [26-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-field independent fallback: each of the 4 numeric score fields clamps to 1-5 independently when present/numeric, or falls back individually to the matching legacy risk-bucket value — not an all-or-nothing 'all 4 present or all 4 bucket' switch. This is a superset of the plan's literal 'when ALL FOUR are absent/non-numeric' wording and satisfies both the all-absent legacy case and any partial/mixed input safely."
    - "Gap-fill, not override: reviewedToRisk()'s library lookup now only fills a null score slot (or empty controls) — it never overwrites a numeric value the row itself already carries, whether that value arrived from an earlier library match or was typed by an engineer."
    - "One schema gate for both load and save: RamsReviewController::parseReviewPayload() now calls the SAME RamsReviewDataService::normalise() the review screen's GET/show() path uses, instead of maintaining a second, narrower field whitelist — load and save can no longer drift out of sync on the hazard row shape."

key-files:
  created:
    - tests/Feature/Rams/HazardScoreEditableDefaultTest.php
  modified:
    - app/Services/RamsReviewDataService.php
    - app/Services/RamsBuilderService.php
    - resources/views/rams/quote-review.blade.php
    - app/Http/Controllers/RamsReviewController.php
    - tests/Unit/Services/RamsReviewDataServiceTest.php
    - tests/Unit/Services/RamsBuilderServiceTest.php

key-decisions:
  - "normaliseHazards()'s bucket fallback is applied PER FIELD, not as an all-4-or-nothing switch: each of pre_likelihood/pre_severity/post_likelihood/post_severity independently uses its clamped numeric value when present, or falls back to the matching legacy-bucket value when absent/non-numeric. The plan's action text described 'when ALL FOUR are absent/non-numeric, derive from the bucket' — per-field fallback is a safe superset of that (produces the exact same result when all 4 are genuinely absent, e.g. Test 2, while also degrading sensibly for a genuinely mixed row, e.g. Test 3's pre_likelihood='abc' with the other three fields absent)."
  - "Added a hidden needs_confirmation input to both the static row and the JS row template, even though the plan's action text only specified a visible badge. Without it, RamsReviewController::parseReviewPayload()'s (fixed) hazards block would receive no needs_confirmation POST value on Save, defaulting every row to false — silently clearing the D-06 confirmation flag on the very first 'Save Review' click, before the engineer ever explicitly confirmed anything. This is Rule 1 (bug fix): the flag is resolver-set and not meant to be user-editable, so round-tripping it via a hidden field (not a checkbox) keeps it out of the engineer's direct control while preventing accidental data loss."
  - "Fixed RamsReviewController::parseReviewPayload()'s hazards block (Rule 3 — blocking issue, file not in the plan's files_modified list) to delegate to RamsReviewDataService::normalise() instead of its own separate, narrower ad hoc parser. Discovered while verifying the full round trip: update()/approve() build 'reviewed_data' directly from parseReviewPayload()'s output — completely independently of RamsReviewDataService::normaliseHazards() — and that method was still whitelisting only activity_key/hazard/risk/control_measures. Without this fix, every numeric score field and both markers submitted by the new form would have been silently discarded on every Save/Approve, which is precisely the failure mode HAZ-04 exists to prevent ('Do not let the app apply the typical scores silently') — except worse, since it would have discarded the ENGINEER'S OWN reviewed values too, not just an unreviewed default."

patterns-established:
  - "Any future hazard-row field addition must be added in exactly ONE place — RamsReviewDataService::normaliseHazards()'s schema — because both the GET/show() load path and the POST/update()+approve() save path now share it. Do not resurrect a separate ad hoc field whitelist in a controller."

requirements-completed: [HAZ-04, HAZ-02]

# Metrics
duration: ~1h40min
completed: 2026-08-24
---

# Phase 26 Plan 05: Hazard Library Structural Inversion — Editable Numeric Hazard Scores (HAZ-04) Summary

**Closed the last HAZ-04 gap: `quote-review.blade.php`'s hazard row is now 4 editable numeric L×S inputs (not a Low/Medium/High select) with a client-side "reviewed" marker and a visible needs-confirmation badge, threaded end-to-end through `RamsReviewDataService::normaliseHazards()`, `RamsBuilderService::reviewedToRisk()`, and — found and fixed along the way — `RamsReviewController`'s previously-independent Save/Approve parser, which would otherwise have silently discarded every new field on the very first save.**

## Performance

- **Duration:** ~1h40min
- **Started:** 2026-08-24T (session start, first file reads)
- **Completed:** 2026-08-24T (this summary)
- **Tasks:** 3/3 completed
- **Files modified:** 6 (1 created, 5 modified)

## Accomplishments
- `RamsReviewDataService::normaliseHazards()` now emits `pre_likelihood`/`pre_severity`/`post_likelihood`/`post_severity` (each independently clamped `max(1, min(5, (int) $value))` when present/numeric, else per-field fallback to a High/Medium/Low legacy bucket keyed off the old `risk` string), plus `score_reviewed` and `needs_confirmation` booleans. The `risk` key itself is fully dropped from the output schema.
- `RamsBuilderService::reviewedToRisk()` now treats an already-numeric `pre_likelihood`/`pre_severity`/`post_likelihood`/`post_severity` on the reviewed_data row as authoritative — the hazard-library re-lookup only fills a genuinely missing score slot (or empty controls), never overwrites a value the row already carries. `score_reviewed` and `needs_confirmation` now survive from `reviewed_data['hazards'][*]` into `generated_data['hazards'][*]` unchanged, and legacy risk-only rows still resolve via the pre-existing library-lookup-then-risk-string fallback chain, unmodified.
- `quote-review.blade.php`'s static hazard row and the JS `hazardRowTemplate()` (kept in exact sync) both replace the single `<select name="hazards[i][risk]">` with 4 `<input type="number" min="1" max="5">` fields, a hidden `score_reviewed` input that flips from `"0"` to `"1"` via a new `markHazardReviewed()` handler the moment any of the 4 score inputs is edited, and — for resolver-flagged rows — a `.badge-needs-confirmation` span plus a `.hazard-needs-confirmation` row highlight. Manually-added rows (via "+ Add Row") default `score_reviewed="1"` (a typed row is inherently reviewed) and never carry the confirmation badge.
- **Deviation found and fixed:** `RamsReviewController::parseReviewPayload()` runs its own hazard-row shaping on every Save (`update()`) and Approve (`approve()`) POST, building `reviewed_data` directly — entirely independent of `RamsReviewDataService::normaliseHazards()`. It was still whitelisting only `activity_key`/`hazard`/`risk`/`control_measures`, which would have silently discarded every new field (including the engineer's own typed scores) on the very first save. Fixed by delegating the hazards block to `RamsReviewDataService::normalise()` — the same schema gate the GET path already uses — so load and save can never drift apart again.
- Added a hidden `needs_confirmation` field to both the static row and JS template so a plain "Save Review" click (with no field edits) does not silently clear the D-06 confirmation flag before an engineer has had a chance to act on it.
- New `tests/Feature/Rams/HazardScoreEditableDefaultTest.php` proves the review screen renders numeric inputs (not a select) and shows the confirmation badge/row-highlight for exactly the flagged row.
- `--filter=Rams`: 475 passed, 0 failed (was 466 before this plan — 9 new tests: 4 `RamsReviewDataServiceTest`, 3 `RamsBuilderServiceTest`, 2 `HazardScoreEditableDefaultTest`). Full suite: 2260 passed, 1 failed — the same documented pre-existing `QueueRecoverCommandTest` memory-limit flake noted in 26-04's environment_facts, not chased.

## Task Commits

1. **Task 1 RED: failing tests for normaliseHazards() extended schema** - `56e7c8a` (test)
1. **Task 1 GREEN: normaliseHazards() extended numeric-score schema** - `45f4260` (feat)
2. **Task 2 RED: failing tests for reviewedToRisk() score precedence + markers** - `4a04ca4` (test)
2. **Task 2 GREEN: reviewedToRisk() honours engineer-entered scores, carries markers** - `28ac91e` (feat)
3. **Task 3: quote-review.blade.php editable numeric scores + badge (+ RamsReviewController fix)** - `9efda43` (feat)

## Files Created/Modified
- `app/Services/RamsReviewDataService.php` - `normaliseHazards()` rewritten: 4 clamped numeric fields + `score_reviewed`/`needs_confirmation`, per-field legacy-bucket fallback, `risk` dropped from output.
- `app/Services/RamsBuilderService.php` - `reviewedToRisk()`: row-numeric scores win over library re-lookup (gap-fill only); `score_reviewed`/`needs_confirmation` read from the row and written into the generated row.
- `resources/views/rams/quote-review.blade.php` - Hazard table header/row/JS-template: 4 numeric inputs replace the risk select; hidden `score_reviewed` (flip-on-edit via `markHazardReviewed()`) and `needs_confirmation` (round-trip only) inputs; `.badge-needs-confirmation` + `.hazard-needs-confirmation` CSS; `.col-risk` replaced with `.col-score`.
- `app/Http/Controllers/RamsReviewController.php` - `parseReviewPayload()`'s hazards block now builds a raw per-row array (including the new fields) and delegates clamping/fallback to `RamsReviewDataService::normalise()`, instead of its own narrower whitelist.
- `tests/Unit/Services/RamsReviewDataServiceTest.php` - 4 new tests: round-trip unchanged, legacy bucket fallback, clamp + non-numeric fallback, `risk` key absent from output.
- `tests/Unit/Services/RamsBuilderServiceTest.php` - New `makeServiceWithHazardLibrary()`/`invokeReviewedToRisk()` helpers + 3 new tests: row wins over library match, markers survive, legacy row unchanged.
- `tests/Feature/Rams/HazardScoreEditableDefaultTest.php` (new) - 2 feature tests: numeric inputs present (no select), confirmation badge/highlight present only on the flagged row.

## Decisions Made
- Implemented the legacy-bucket fallback per-field rather than as a single all-4-fields-or-nothing switch (see key-decisions above) — a safe superset of the plan's literal wording that also handles a genuinely mixed row sensibly.
- Added the hidden `needs_confirmation` round-trip field (not specified by the plan's action text, which only asked for a visible badge) to prevent a Rule-1-class data-loss bug: without it, the flag would silently disappear on the first Save.
- Fixed `RamsReviewController::parseReviewPayload()` (Rule 3 — not in the plan's `files_modified` list) because without it the entire feature would be non-functional on the actual persistence path; only the GET/show() path would have worked.
- Did not touch `RamsReviewValidatorService.php`. Its `'hazards.*.risk' => ['nullable', ...]` rule is now harmless (the key is simply absent from the payload). Clamping to 1-5 already happens inside `RamsReviewDataService::normalise()`, which now runs BEFORE validation in the controller's `parseReviewPayload()`, so the security boundary (T-26-06) is satisfied without adding new validator rules — keeping the diff minimal per this plan's scope.
- Left `resources/views/rams/quote-review.blade2903.php` (a pre-existing dated backup file, per this repo's own documented "Legacy/backup files: numeric date suffix" convention) and `resources/views/project-packages/review.blade.php` / `app/Services/ProjectPackageRamsReviewService.php` / `app/Http/Controllers/ProjectPackageReviewController.php` (a separate, unrelated Project Package review subsystem that also has its own `risk` select) untouched — both are out of this plan's stated scope (`resources/views/rams/quote-review.blade.php` only).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue, file not in plan's files_modified] `RamsReviewController::parseReviewPayload()` ran an independent, narrower hazard-row parser that would have silently discarded every new HAZ-04 field on Save/Approve**
- **Found during:** Task 3, while tracing the full save round trip after finishing the blade edits (before writing the feature test)
- **Issue:** `update()` and `approve()` both build `$payload = $this->parseReviewPayload($request)` and persist it DIRECTLY as `reviewed_data` — completely bypassing `RamsReviewDataService::normalise()`/`normaliseHazards()`. `parseReviewPayload()`'s own hazards block only ever whitelisted `activity_key`/`hazard`/`risk`/`control_measures`. Left as-is, submitting the new form would have silently dropped `pre_likelihood`/`pre_severity`/`post_likelihood`/`post_severity`/`score_reviewed`/`needs_confirmation` on every save — the exact "app applies typical scores silently" failure HAZ-04 exists to prevent, except worse (it would have discarded the engineer's OWN typed values, not just an unreviewed default).
- **Fix:** `parseReviewPayload()`'s hazards block now builds a raw per-row array (still handling the textarea-to-array `control_measures` conversion, which is request-shape-specific and stays here) and calls `$this->reviewDataService->normalise(['hazards' => $hazardsRaw])['hazards']` to get the same clamped/defaulted output the GET path already produces — one schema gate, not two.
- **Files modified:** `app/Http/Controllers/RamsReviewController.php`
- **Verification:** `tests/Feature/Rams/ReviewWorkflowTest.php` (14 tests, all still passing — covers save/approve/regenerate flows) plus the new `HazardScoreEditableDefaultTest.php`; full `--filter=Rams` 475/475 passed.
- **Committed in:** `9efda43` (bundled with the blade changes it makes functional, documented in the commit message)

---

**Total deviations:** 1 (a blocking-issue fix outside the plan's stated `files_modified` list, required for the plan's own deliverable to work end-to-end on the real Save/Approve path, not just the read-only GET/show() path).
**Impact on plan:** None on scope or the plan's intent — the fix is exactly what HAZ-04 already demanded ("must not reach `generated_data` as a committed score without the un-reviewed marker travelling with it"), just applied to a second code path the plan's `<interfaces>` section didn't call out.

## Issues Encountered
- `php` is not on `PATH` in this repo's execution shell (same as Plans 26-01 through 26-04) — resolved by prepending `/c/Users/sonny.tanda/.config/herd/bin/php84` to `PATH` for every `php artisan` invocation.
- First draft of the two `RamsBuilderServiceTest` new tests asserted `$out[0][...]` instead of `$out['hazards'][0][...]` — `reviewedToRisk()` returns `['hazards' => [...], 'ppe' => [...], 'access_equipment' => [...]]`, not a flat hazard-row array. Caught immediately by the RED run (`Undefined array key 0`), fixed before the GREEN implementation was written — not a deviation, a normal RED-phase test-authoring correction.
- First draft of the confirmation-badge feature-test assertion (`substr_count($html, 'badge-needs-confirmation') === 1`) failed because the CSS rule definition in the `<style>` block also contains that substring, making the true count 2. Fixed by asserting the exact `<span class="badge badge-warning badge-needs-confirmation">` markup instead — same normal test-authoring correction, not a deviation.
- Ran the full test suite (`php artisan test`, no filter) beyond the plan's mandated `--filter=HazardScoreEditableDefaultTest` scope, matching Plan 26-04's precedent as this phase's final plan. Found exactly the same 1 pre-existing `QueueRecoverCommandTest` memory-limit failure documented in this plan's `environment_facts` — not chased.

## User Setup Required
None - no external service configuration required. Pure service-layer + Blade + controller logic and tests; no migration, no deploy (Plan 26-06 owns live deploy).

## Next Phase Readiness
- **HAZ-04 is now fully satisfied end-to-end**, on both the read (GET/show) and write (POST/update+approve) paths: typical scores are visibly pre-filled as real editable numbers, never silently committed without `score_reviewed` travelling alongside them, and `needs_confirmation`-flagged hazards are visually distinguishable and no longer silently lost on save.
- **Phase 26 is now feature-complete** at the code level: HAZ-01 through HAZ-04 are all satisfied (HAZ-02/HAZ-04 marked complete by this plan's requirements-completed; HAZ-01/HAZ-03 were already complete per `REQUIREMENTS.md`). Plan 26-06 owns the live deploy and the manual spot-check against job 21CQ30960 (VW Blakelands) called out in `26-CONTEXT.md`'s `<specifics>`.
- No blockers for Plan 26-06. Note for the deploy plan: this plan's `RamsReviewController` fix means the live save/approve flow will now persist the new hazard fields — worth a quick manual save-and-reload smoke test on the live review screen post-deploy, in addition to the planned generate-and-spot-check.

## Self-Check: PASSED

- `app/Services/RamsReviewDataService.php` — FOUND, `normaliseHazards()` emits the extended schema, `risk` absent from output
- `app/Services/RamsBuilderService.php` — FOUND, `reviewedToRisk()` gap-fills only, markers carried through
- `resources/views/rams/quote-review.blade.php` — FOUND, numeric inputs present, `<select name="hazards` absent, badge/highlight markup present
- `app/Http/Controllers/RamsReviewController.php` — FOUND, `parseReviewPayload()` delegates to `RamsReviewDataService::normalise()`
- `tests/Unit/Services/RamsReviewDataServiceTest.php` — FOUND, 4 new tests passing
- `tests/Unit/Services/RamsBuilderServiceTest.php` — FOUND, 3 new tests passing
- `tests/Feature/Rams/HazardScoreEditableDefaultTest.php` — FOUND, 2 tests passing
- Commit `56e7c8a` — FOUND in `git log`
- Commit `45f4260` — FOUND in `git log`
- Commit `4a04ca4` — FOUND in `git log`
- Commit `28ac91e` — FOUND in `git log`
- Commit `9efda43` — FOUND in `git log`
- `php artisan test --filter=RamsReviewDataService` — 10 passed, 0 failed
- `php artisan test --filter=RamsBuilderServiceTest` — 10 passed, 0 failed
- `php artisan test --filter=HazardScoreEditableDefaultTest` — 2 passed, 0 failed
- `php artisan test --filter=Rams` — 475 passed, 0 failed
- `php artisan test` (full suite) — 2260 passed, 1 failed (pre-existing, documented, unrelated `QueueRecoverCommandTest`)

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*
