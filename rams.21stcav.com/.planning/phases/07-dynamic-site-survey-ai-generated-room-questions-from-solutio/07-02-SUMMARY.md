---
phase: 07-dynamic-site-survey
plan: "02"
subsystem: data-layer
tags: [migration, eloquent-model, ai-prompt, tdd, wave-1]
dependency_graph:
  requires:
    - 07-01 (test contracts)
  provides:
    - site_survey_room_questions DB table
    - SiteSurveyRoomQuestion Eloquent model
    - questions() HasMany relationship on SiteSurveyRoom
    - SurveyQuestionsPrompt AI prompt class
  affects:
    - Plan 03 (GenerateSurveyQuestionsJob — depends on model and prompt)
    - Plan 04 (answer endpoint — depends on model)
    - Plan 05 (completion gate — depends on questions() relationship)
    - Plan 06 (UI — depends on questions relationship and model)
tech_stack:
  added: []
  patterns:
    - Migration with enum column and cascadeOnDelete foreign key
    - Eloquent model fillable + casts + BelongsTo relationship
    - HasMany relationship ordered by sort_order
    - BasePrompt extension with build() using storedContext merge pattern
key_files:
  created:
    - database/migrations/2026_04_12_000001_create_site_survey_room_questions_table.php
    - app/Models/SiteSurveyRoomQuestion.php
    - app/Core/AI/Prompts/SurveyQuestionsPrompt.php
  modified:
    - app/Models/SiteSurveyRoom.php
decisions:
  - systemMessage() says "pre-install site verification checks" to satisfy the test asserting 'pre-install' (case-insensitive) — plan draft omitted that word; corrected at implementation time
  - build() uses (array) cast for checklist_lines and equipment to handle both string and array inputs — test passes a string for checklist_lines
metrics:
  duration_minutes: 20
  completed_date: "2026-04-12"
  tasks_completed: 2
  tasks_total: 2
  files_created: 3
  files_modified: 1
---

# Phase 07 Plan 02: Wave 1 — Data Layer Summary

**One-liner:** DB migration for site_survey_room_questions, SiteSurveyRoomQuestion model with BelongsTo, questions() HasMany on SiteSurveyRoom, and SurveyQuestionsPrompt extending BasePrompt with maxTokens=1024 and British English pre-install checks.

## What Was Built

**Task 1 — Migration + Model + Relationship:**
- `2026_04_12_000001_create_site_survey_room_questions_table.php` — creates `site_survey_room_questions` table with id, site_survey_room_id (FK with cascadeOnDelete), question (text), sort_order (smallint, default 0), answer (enum: yes/no/other, nullable), other_text (text, nullable), timestamps, and an index on site_survey_room_id
- `SiteSurveyRoomQuestion.php` — Eloquent model with all 5 fillable fields, sort_order cast to integer, room() BelongsTo relationship
- `SiteSurveyRoom.php` (modified) — added `questions(): HasMany` returning SiteSurveyRoomQuestion ordered by sort_order

**Task 2 — AI Prompt:**
- `SurveyQuestionsPrompt.php` — extends BasePrompt, systemMessage() specifies "pre-install site verification checks" and British English, maxTokens() returns 1024, temperature() returns 0.2, build() composes prompt from 6 context keys with graceful fallbacks for missing optional fields

## Test Results

All 9 Plan 02 tests GREEN:
- `SurveyRoomQuestionModelTest` — 4/4 PASS (fillable, cast, BelongsTo, inline create)
- `SurveyQuestionsPromptTest` — 5/5 PASS (systemMessage x2, maxTokens, temperature, build output)

## Deviations from Plan

**1. [Rule 1 - Bug] system message adjusted to include "pre-install"**
- **Found during:** Task 2 — reading the test contract before implementing
- **Issue:** Plan's draft system message ("preparing a site survey") did not contain "pre-install"; test `test_system_message_contains_pre_install` asserts case-insensitive match for "pre-install"
- **Fix:** Changed system message to "generating pre-install site verification checks" — preserves intent, passes test
- **Files modified:** `app/Core/AI/Prompts/SurveyQuestionsPrompt.php`
- **Commit:** eb2ac1c

## Known Stubs

None — no UI rendering, no data flowing to views. All outputs are DB records and strings.

## Threat Flags

No new threat surface beyond what is documented in the plan's threat model. No new routes, auth paths, or file access patterns introduced.

## Commits

| Task | Hash | Message |
|------|------|---------|
| 1 | 7316ded | feat(07-02): migration, SiteSurveyRoomQuestion model, and questions() relationship |
| 2 | eb2ac1c | feat(07-02): SurveyQuestionsPrompt — pre-install check question generation |

## Self-Check: PASSED

All files confirmed to exist:
- `database/migrations/2026_04_12_000001_create_site_survey_room_questions_table.php` — FOUND
- `app/Models/SiteSurveyRoomQuestion.php` — FOUND
- `app/Core/AI/Prompts/SurveyQuestionsPrompt.php` — FOUND
- `app/Models/SiteSurveyRoom.php` (modified) — FOUND (contains questions() HasMany)
Both commits confirmed in git log: 7316ded, eb2ac1c
