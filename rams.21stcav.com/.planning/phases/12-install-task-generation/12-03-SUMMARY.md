---
phase: 12-install-task-generation
plan: "03"
subsystem: worksheet-generation
tags: [worksheet, pre-install, docx, work-05, work-06]
dependency_graph:
  requires:
    - Phase 07 SiteSurveyRoomQuestion model (answer enum, other_text field)
    - Phase 07 SiteSurveyRoom.questions() hasMany relationship
    - v1.0 Phase 04 worksheets.generate-from-project route (WORK-06)
  provides:
    - pre_install_answers per room in WorksheetGeneratorService generateContent()
    - Section E "Pre-Install Check Answers" in each room's DOCX output
  affects:
    - WorksheetGeneratorService — extended return shape with pre_install_answers key
    - WorksheetDocxService — new section E per room, new buildPreInstallAnswersTable() method
tech_stack:
  added: []
  patterns:
    - siteSurveys()->latest()->first() pattern for project-scoped survey lookup
    - lowercase-trim keying for case-insensitive room name matching
    - whereNotNull('answer') collection filter for answered-only questions
key_files:
  created: []
  modified:
    - app/Services/WorksheetGeneratorService.php
    - app/Services/WorksheetDocxService.php
decisions:
  - "Use strtolower(trim()) on both survey room names and quote room names for matching — avoids fragile exact-match failures due to casing or whitespace differences"
  - "Only include answered questions (whereNotNull('answer')) — unanswered AI-generated questions are not useful in the DOCX output"
  - "Use latest site survey only (->latest()->first()) — consistent with how ProjectDataService resolves survey data"
  - "WORK-06 required no new code — route and dashboard button confirmed present from v1.0 Phase 04; documented in code comment"
metrics:
  duration_minutes: 8
  completed_date: "2026-04-13"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 2
---

# Phase 12 Plan 03: Worksheet Pre-Install Answers (WORK-05 + WORK-06) Summary

**One-liner:** Pre-install check answers from SiteSurveyRoomQuestion flow into Worksheet DOCX as section E per room, with case-insensitive room name matching and empty-state fallback.

## What Was Built

**Task 1 — WorksheetGeneratorService data layer (WORK-05)**

Before the `buildRooms()` call in `generateContent()`, the service now:
1. Fetches the latest site survey for the project via `$project->siteSurveys()->latest()->first()`
2. Loads all rooms with their questions via `rooms()->with('questions')->get()`
3. Filters to answered questions only via `->whereNotNull('answer')`
4. Keys the answers map by `strtolower(trim($room_name))` for case-insensitive matching
5. Passes the map as `$preInstallAnswers` (5th arg) to `buildRooms()`
6. In the rooms loop, looks up `$preInstallAnswers[strtolower(trim($roomName))] ?? []` and adds as `pre_install_answers` key

**Task 2 — WorksheetDocxService render layer (WORK-05) + WORK-06 confirmation**

In `buildRoom()`, after section D (Power & Network), a new section E is rendered:
- `addSectionHeading($section, 'E. Pre-Install Check Answers')`
- `buildPreInstallAnswersTable($section, $room['pre_install_answers'] ?? [])`

`buildPreInstallAnswersTable()` renders:
- Empty state: italic grey "No pre-install checks recorded." paragraph
- Non-empty: two-column table (Question | Answer) with alternating row shading
- Answer formatting: `ucfirst()` for yes/no; "Other: {other_text}" when answer = 'other'

WORK-06 confirmed: `worksheets.generate-from-project` POST route exists in `routes/web.php` line 256, and `ProjectController::show()` includes `generate_route => route('worksheets.generate-from-project', $project)` in `$linkedRecords`. No new code needed — documented in code comment above `buildPreInstallAnswersTable()`.

## Commits

| Task | Commit | Message |
|------|--------|---------|
| Task 1 | 403a3dc | feat(12-03): add pre-install answers to WorksheetGeneratorService (WORK-05 data layer) |
| Task 2 | 7730de9 | feat(12-03): add section E pre-install check answers to WorksheetDocxService (WORK-05 render layer) |

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None. Pre-install answers are fully wired from SiteSurveyRoomQuestion through generateContent() into buildRoom() DOCX output. Rooms with no survey answers render the "No pre-install checks recorded." fallback rather than a blank section.

## Threat Flags

None. Trust boundary analysis per plan threat model:
- T-12-09: Answer values are engineer-entered enum (yes/no/other) + free text — no HTML injection risk in PhpWord text rendering. Accepted.
- T-12-10: `$project->siteSurveys()` hasMany constrains query to project's own surveys via project_id FK — no cross-project disclosure. Mitigated by query design.
- T-12-11: AV pre-install questions typically 5-10 per room — no pagination needed. Accepted.

## Self-Check: PASSED

- [x] `app/Services/WorksheetGeneratorService.php` exists and modified
- [x] `app/Services/WorksheetDocxService.php` exists and modified
- [x] Commit 403a3dc exists (`git log` confirmed)
- [x] Commit 7730de9 exists (`git log` confirmed)
- [x] Both files pass `php -l` syntax check
- [x] `grep -c "pre_install_answers" WorksheetGeneratorService.php` = 3
- [x] `grep -c "buildPreInstallAnswersTable" WorksheetDocxService.php` = 2
- [x] `php artisan route:list --name=worksheets.generate` confirms WORK-06 route
