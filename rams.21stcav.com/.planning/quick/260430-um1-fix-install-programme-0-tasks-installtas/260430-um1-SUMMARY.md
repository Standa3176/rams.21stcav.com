---
phase: quick-260430-um1
plan: 01
subsystem: install-programme
tags: [install-programme, install-task, worksheet-parity, room-distribution, project-data]

requires:
  - phase: phase-12-install-programme-v1.2
    provides: InstallTaskGeneratorService skeleton and synchronous generate() pipeline
provides:
  - InstallTask generation works for QuoteWerks-imported projects whose rooms come from ProjectDataService::resolveRooms() with empty equipment arrays
  - Two-strategy room/equipment distribution (area-tag grouping + flat location-match) ported into InstallTaskGeneratorService as private helpers
  - NON_PHYSICAL_ROOMS guard prevents pseudo-rooms (Professional Services, Cabling, Licencing, etc.) from becoming InstallTask rows
affects: [install-programme, worksheet-generator, project-data-service]

tech-stack:
  added: []
  patterns:
    - "Mirror duplication: critical helper logic copied verbatim from another service when a shared trait would slow stabilisation. Promote to App\\Services\\Rooms\\RoomEquipmentDistributor only when a 3rd caller appears."

key-files:
  created: []
  modified:
    - app/Services/InstallTaskGeneratorService.php
    - tests/Unit/InstallTaskGeneratorServiceTest.php

key-decisions:
  - "Duplicate WorksheetGeneratorService's resolveAndDistributeRooms / buildRoomsFromAreaTags / recoverRoomsFromEquipment as private methods rather than extracting a shared trait. Two callers do not yet justify the abstraction; documented in DUPLICATION NOTE block."
  - "Filter NON_PHYSICAL_ROOMS inside recoverRoomsFromEquipment too — the worksheet path got away without it because phantom-room cleanup runs later in buildRooms(); InstallTaskGeneratorService has no equivalent post-pass, so the guard had to live at the recovery point."
  - "Use $this->filterHardware() (not WorksheetGeneratorService's filterItems()) inside the distributor — InstallTaskGeneratorService has no WorksheetClassifier dependency, and filterHardware's category+keyword exclusion is sufficient for install-task purposes."

patterns-established:
  - "DUPLICATION NOTE comment block — when a service deliberately mirrors logic from another, the doc-block names the source, the reason, and the conditions for promoting to a shared abstraction."

requirements-completed: [QUICK-260430-UM1]

duration: 18min
completed: 2026-04-30
---

# Phase quick-260430-um1: Fix install programme producing zero tasks Summary

**InstallTaskGeneratorService now distributes equipment from `_raw_equipment` (Strategy 1: area tags) or `equipment` (Strategy 2: flat location-match) into rooms before task generation, mirroring WorksheetGeneratorService.**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-04-30 (local, untimed)
- **Completed:** 2026-04-30
- **Tasks:** 2 (TDD task + verification checkpoint)
- **Files modified:** 2

## Accomplishments
- All 3 local projects (1, 2, 3) now produce > 0 InstallTasks after regeneration (previously: 0 across the board).
- 4 new unit tests cover both distribution strategies and the non-physical-room guard.
- All 16 InstallTaskGeneratorServiceTest tests pass; all 121 Worksheet tests still pass; WorksheetGeneratorService.php is untouched (zero-line diff).

## Root Cause (1 paragraph)

`InstallTaskGeneratorService::generate()` iterated `$data['rooms']` directly and read `$room['equipment']`, but `ProjectDataService::resolveRooms()` returns rooms as bare names with `equipment => []`. The actual equipment lives at `$data['equipment']` (filtered) and `$data['_raw_equipment']` (raw, with `area`/`location` tags). For every QuoteWerks-imported project, the foreach loop saw empty equipment arrays and never called `InstallTask::create()`. The bug shipped silently in Phase 12 because development was done against survey-enriched projects where rooms had pre-populated equipment.

## Task Commits

Each task was committed atomically:

1. **Task 1 (RED): add failing tests for area-tag and flat-equipment distribution** — `c5f4ab1` (test)
2. **Task 1 (GREEN): port two-strategy room/equipment distribution into InstallTaskGeneratorService** — `c479364` (feat)
3. **Task 2: verification against local projects 1, 2, 3** — no commit (one-off script ran from gitignored `storage/app/private/tmp/`, deleted after PASS)

## Files Created/Modified

- `app/Services/InstallTaskGeneratorService.php` (+245, -5)
  - Added `NON_PHYSICAL_ROOMS` constant
  - Refactored `generate()` to call new `resolveAndDistributeRooms($data)` before the foreach
  - Added private `resolveAndDistributeRooms()`, `buildRoomsFromAreaTags()`, `recoverRoomsFromEquipment()`
  - Added DUPLICATION NOTE comment block citing the worksheet source
  - Added pseudo-room guard inside `recoverRoomsFromEquipment()` (Rule 1 fix)
- `tests/Unit/InstallTaskGeneratorServiceTest.php` (+165)
  - Added private `resolvedDataWith()` helper accepting rooms + equipment + raw_equipment
  - 4 new tests:
    - `generate_distributes_flat_equipment_by_area_tag` (Strategy 1, with "no area" item routing to General)
    - `generate_filters_non_physical_area_tag_under_strategy_one` (Strategy 1, Professional Services drop)
    - `generate_distributes_flat_equipment_by_location_match` (Strategy 2, location → room name match + General fallback)
    - `generate_filters_non_physical_room_under_strategy_two` (Strategy 2, pseudo-room never appears as room_name)

## Test Results

| Suite | Pre-fix | Post-fix |
|-------|---------|----------|
| `InstallTaskGeneratorServiceTest` | 12 passing, 4 missing | 16 passing (4 new) |
| `--filter=Worksheet` | 121 passing | 121 passing (regression guard) |
| `git diff --stat app/Services/WorksheetGeneratorService.php` | n/a | empty |

## Local Verification Output (verbatim)

```
Project 1 (21CQ30069-02-OPS - Volkswagen National Learning Centre): 3 tasks across rooms — Restaurant Display(3)
Project 2 (21CQ29531-05-OPS - Tilda Meeting Rooms): 43 tasks across rooms — OREGANO(9), CINNAMON(17), SAFFRON(15), ROOM BOOKING PANELS(2)
Project 3 (21CQ29437-11-OPS - Western Digital UK Limited): 31 tasks across rooms — VC Room (22) - Primary Left(7), Conference room (23) - Secondary (Right(3), Comm's Room (21) - Rack Equipment(5), Breakout Area(11), Comms Room (Next to Breakout/Townhall Area(5)
```

PASS criteria met:
- All three projects produce > 0 InstallTasks (previously 0 for all).
- No NON_PHYSICAL_ROOMS member (Professional Services, Cabling, Licencing, Consumables, Services, Options, Delivery, Carriage, Support Services) appears as a `room_name`.
- Each room has ≥ 1 task.

Note on Project 2's "ROOM BOOKING PANELS" row: that's a real area tag from the source quote — same output as WorksheetGeneratorService produces for that project. Not a regression; not in the pseudo-room list.

## Decisions Made

- **Duplicate, don't extract.** WorksheetGeneratorService still owns the canonical distribution logic. A shared `App\Services\Rooms\RoomEquipmentDistributor` is deferred until a 3rd caller appears. Documented in a DUPLICATION NOTE comment block in the service.
- **Use `filterHardware()` (not `filterItems()`).** InstallTaskGeneratorService doesn't have access to WorksheetClassifier and doesn't need its tier-3 classification — category+keyword exclusion is enough.
- **Filter pseudo-rooms in `recoverRoomsFromEquipment()` too.** The worksheet path got away without this guard because phantom-room cleanup runs later in `buildRooms()`. InstallTaskGeneratorService has no equivalent post-pass, so the guard had to live at the recovery point.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Pseudo-room leaked through `recoverRoomsFromEquipment()`**
- **Found during:** Task 1 (GREEN run after porting all three helpers)
- **Issue:** The 4th regression test (`generate_filters_non_physical_room_under_strategy_two`) failed with `1 task` instead of `0`. Trace: when all resolved rooms are pseudo-rooms and get filtered, the strategy-2 branch falls through to `recoverRoomsFromEquipment()`. That helper grouped equipment by `location` without filtering `NON_PHYSICAL_ROOMS`, so a `location = 'Professional Services'` line item was reborn as a recovered room.
- **Fix:** Added a `NON_PHYSICAL_ROOMS` guard inside the recovery loop. The verbatim copy from WorksheetGeneratorService didn't need this because that pipeline filters pseudo rooms in `buildRooms()` later via `classifyItems()` returning all-empty buckets.
- **Files modified:** app/Services/InstallTaskGeneratorService.php
- **Verification:** test now passes; all 16 InstallTaskGeneratorServiceTest tests green; all 121 Worksheet tests still green.
- **Committed in:** `c479364` (Task 1 GREEN — fix landed in the same commit as the helper port)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Necessary fix — without it, the non-physical-filter guarantee would have a hole. No scope creep; the change is one extra `in_array()` check inside the recovery helper.

## Issues Encountered

None beyond the deviation above.

## Files Queued for Live Upload

Per the local-edit-then-upload workflow, the user should upload these to the live host:

```
app/Services/InstallTaskGeneratorService.php
tests/Unit/InstallTaskGeneratorServiceTest.php
```

After upload, regenerate the install programme on the Integra Building project (21CQ30246-06-OPS) and verify > 0 tasks. No deployment automation in this plan.

## Deferred Follow-ups

- Extract `App\Services\Rooms\RoomEquipmentDistributor` (shared between WorksheetGeneratorService and InstallTaskGeneratorService) when a 3rd caller appears. Until then, the duplication is intentional and documented.

## Self-Check: PASSED

- Files exist:
  - FOUND: app/Services/InstallTaskGeneratorService.php
  - FOUND: tests/Unit/InstallTaskGeneratorServiceTest.php
- Commits exist:
  - FOUND: c5f4ab1 (test RED)
  - FOUND: c479364 (feat GREEN)
- WorksheetGeneratorService.php diff: empty (verified via `git diff --stat`)
- All InstallTaskGeneratorServiceTest tests passing: 16/16
- Worksheet regression suite passing: 121/121

---
*Phase: quick-260430-um1*
*Completed: 2026-04-30*
