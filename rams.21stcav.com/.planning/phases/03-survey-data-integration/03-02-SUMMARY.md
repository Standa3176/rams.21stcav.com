---
phase: 03-survey-data-integration
plan: "02"
subsystem: data-service
tags: [survey, fuzzy-matching, eloquent, unit-tests, projectdataservice]
dependency_graph:
  requires: [03-01]
  provides: [ProjectDataService.mergeSurveyRooms, ProjectDataService.normalizeRooms, ProjectDataService.roomSimilarity, ProjectDataService.resolveSurveyMeta-6keys, superseded_at-filter]
  affects: [app/Core/Modules/Projects/ProjectDataService.php, tests/Unit/ProjectDataServiceTest.php]
tech_stack:
  added: []
  patterns: [similar_text-fuzzy-matching, loadMissing-n1-prevention, eloquent-collection-whereNull, tdd-red-green]
key_files:
  created: []
  modified:
    - app/Core/Modules/Projects/ProjectDataService.php
    - tests/Unit/ProjectDataServiceTest.php
decisions:
  - "Used similar_text() over levenshtein() per RESEARCH.md recommendation — more idiomatic PHP for space-variation room names"
  - "Fuzzy threshold set at 0.70 matching D-01 starting point"
  - "loadMissing('rooms') called once in mergeSurveyRooms() to prevent N+1; resolveSurveyMeta() accesses the cached relation"
  - "PHPDoc comments reference room_data/items_to_remove only to explain what was replaced/excluded — no live code references"
  - "Test stub uses anonymous class with loadMissing() no-op for Eloquent-compatible testing without DB"
metrics:
  duration: "~25 minutes"
  completed: "2026-04-10"
  tasks_completed: 2
  tasks_total: 2
---

# Phase 3 Plan 02: ProjectDataService Survey Integration Summary

**One-liner:** Replaced two Phase 1 stubs with real Eloquent-relational fuzzy merge and 6-key survey_meta, extending test suite to 13 passing tests.

## What Was Built

### Task 1: ProjectDataService — three targeted changes

**CHANGE 1 — resolve() superseded_at filter**

Added `->whereNull('superseded_at')` to both the DB query path and the in-memory Eloquent Collection path in `resolve()`. The Collection supports `whereNull()` natively in Laravel 12, so both paths filter correctly.

**CHANGE 2 — mergeSurveyRooms() full replacement**

The Phase 1 stub read `$survey->room_data` (a JSON field that does not exist — Eloquent silently returns null). The new implementation:

- Calls `$survey->loadMissing('rooms')` once before the loop (prevents N+1; subsequent `$survey->rooms` access uses the cached relation)
- Calls `$this->normalizeRooms($survey->rooms)` to get the D-10 field-set
- Fuzzy-matches each quote room against survey rooms using `$this->roomSimilarity()` at a 0.70 threshold
- Merges matched survey rooms into the quote room at `confidence: 0.95`, `data_source: 'survey'`
- Appends unmatched survey rooms as orphan entries with `quote_room_matched: false`

**CHANGE 3 — resolveSurveyMeta() 6-key shape**

Replaced the 3-key stub (which also read `$survey->room_data`) with the D-05 canonical 7-key shape:

```
has_survey, submitted_at, site_risks, access_constraints, h_and_s_notes, general_notes, rooms
```

The existing `$submittedAt` resolution logic (handles Carbon objects and plain strings) was retained unchanged.

**New private helpers added:**

| Method | Purpose |
|---|---|
| `normalizeRooms(iterable)` | Extracts D-10 fields from SiteSurveyRoom records; annotates with `data_source: survey`, `confidence: 0.95`; excludes `items_to_remove`, `items_to_retain`, `existing_condition` |
| `roomSimilarity(string, string)` | Wraps `similar_text()` to return 0.0–1.0 float for room name comparison |

### Task 2: ProjectDataServiceTest — stub update and 5 new tests

**Stub update (test 8):** Replaced `$survey->room_data = []` with `makeSurveyStub()` which exposes `rooms = collect([])`, the four global text fields, and a `loadMissing()` no-op.

**New test helpers:**
- `makeSurveyStub(string $status)` — anonymous class with `loadMissing()` no-op, `rooms` Collection, and all Phase 3 fields
- `makeSurveyRoomStub(string $roomName)` — stdClass with all D-10 fields set to null

**Five new test methods:**

| # | Test | What it verifies |
|---|---|---|
| 9 | `test_matched_survey_room_inherits_survey_fields_at_confidence_95` | Name match >= 0.70 → `data_source: survey`, `confidence: 0.95` |
| 10 | `test_orphan_survey_room_appended_with_quote_room_matched_false` | No matching quote room → appended with `quote_room_matched: false` |
| 11 | `test_below_threshold_survey_room_leaves_quote_room_unchanged` | Low similarity → quote room `data_source` not overwritten |
| 12 | `test_resolve_survey_meta_has_all_six_keys` | `survey_meta` has all 7 keys including `site_risks`, `access_constraints`, `h_and_s_notes` |
| 13 | `test_superseded_survey_excluded_from_resolve` | `superseded_at` set → `meta.has_survey: false` |

**Result: 13 tests, 0 failures, 53 assertions.**

## Verification

```
php artisan test tests/Unit/ProjectDataServiceTest.php
# 13 passed (53 assertions)
```

Acceptance criteria checks:
- `function roomSimilarity(` — line 401
- `function normalizeRooms(` — line 333
- `similar_text(` — line 403 (live code)
- `loadMissing('rooms')` — line 275
- `whereNull('superseded_at')` — lines 52, 56
- `site_risks` — line 212
- `access_constraints` — line 213
- `h_and_s_notes` — line 214
- `room_data` — PHPDoc comment only (no live code reference)
- `items_to_remove` — PHPDoc comment only (explains exclusion)

## Deviations from Plan

None — plan executed exactly as written.

The plan noted that `room_data` and `items_to_remove` should not appear in the code. Both appear only in PHPDoc comments explaining what was replaced/excluded. The live implementation contains no references to these phantom fields.

## Known Stubs

None — all survey data now flows from real Eloquent relations. `normalizeRooms()` reads actual SiteSurveyRoom properties.

## Threat Flags

None — all threat mitigations from plan threat_model are implemented:
- T-03-03: `mergeSurveyRooms()` now uses `loadMissing('rooms')` — phantom `room_data` field eliminated
- T-03-04: `whereNull('superseded_at')` added to both query paths in `resolve()`
- T-03-05: `loadMissing('rooms')` called once in `mergeSurveyRooms()`; `resolveSurveyMeta()` accesses the already-cached relation

## Self-Check

- `app/Core/Modules/Projects/ProjectDataService.php` exists: FOUND
- `tests/Unit/ProjectDataServiceTest.php` exists: FOUND
- Commit 9682e03 (Task 1): FOUND
- Commit 303e83e (Task 2): FOUND
- 13 tests, 0 failures: PASSED
