---
phase: 05-project-content-pack
plan: 01
subsystem: ai-prompts
tags: [ai, prompts, room-summary, scope-of-works, json-schema]
dependency_graph:
  requires: []
  provides: [description-field-per-room, works-overview-field]
  affects: [RoomOverviewSummaryService, downstream-content-pack-plans]
tech_stack:
  added: []
  patterns: [extended-json-schema, defensive-ai-response-parsing]
key_files:
  created: []
  modified:
    - app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php
    - app/Core/AI/Prompts/ScopeOfWorksPrompt.php
    - app/Services/RoomOverviewSummaryService.php
decisions:
  - description field added as single-line JSON string with British English prose instruction to match existing summary pattern
  - Guard relaxed from if (room !== '' && summary !== '') to if (room !== '') so rooms with no AI summary still get description stored
  - maxTokens increased conservatively (1200→2000, 600→900) to accommodate new fields without over-spending
metrics:
  duration: 15m
  completed: 2026-04-11
  tasks: 3
  files_modified: 3
---

# Phase 05 Plan 01: Extend AI Prompt Contracts with description and works_overview Fields — Summary

**One-liner:** Extended RoomOverviewSummaryPrompt and ScopeOfWorksPrompt JSON schemas with `description` and `works_overview` fields respectively, and wired RoomOverviewSummaryService to extract and return `description` per room.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Extend RoomOverviewSummaryPrompt to return description per room | 9cba34d | app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php |
| 2 | Extend ScopeOfWorksPrompt to also return works_overview | 0072cb1 | app/Core/AI/Prompts/ScopeOfWorksPrompt.php |
| 3 | Update RoomOverviewSummaryService::summarize() to extract and return description | 521b4da | app/Services/RoomOverviewSummaryService.php |

## What Was Built

### Task 1 — RoomOverviewSummaryPrompt
- `description` field added to the JSON schema returned by the prompt (alongside `summary`)
- `systemMessage()` extended with instructions for a 2–4 sentence British English prose paragraph per room
- `maxTokens()` increased from 1200 to 2000
- `build()` heredoc updated: schema block now includes `description`, example includes a fully written `$exampleDescription` string
- CRITICAL JSON RULE added for `description` (single-line, no actual newlines in string)

### Task 2 — ScopeOfWorksPrompt
- `works_overview` field added to the JSON response schema alongside `scope_of_works`
- `systemMessage()` extended with 3 new sentences describing the executive summary purpose
- `maxTokens()` increased from 600 to 900
- `build()` JSON template updated to include `works_overview`, requirements block adds works_overview spec

### Task 3 — RoomOverviewSummaryService
- Guard in the AI response loop relaxed from `$room !== '' && $summary !== ''` to `$room !== ''` so rooms with an empty summary still have their `description` stored
- `$summaries` map now stores both `summary` and `description` per room name key
- Main `array_map` path sets `$r['description']` from AI response (or `''` if missing)
- Exception catch fallback path sets `$r['description'] = ''`
- Empty-overview early-return path sets `$r['description'] = ''`
- `summarize()` method signature unchanged

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — this plan extends prompt contracts and service extraction only. No UI or persistence changes. `description` and `works_overview` values flow to downstream plans (05-02 onwards) for storage and display.

## Threat Flags

No new network endpoints, auth paths, file access patterns, or schema changes introduced. AI response parsing uses existing defensive `?? ''` pattern as required by T-05-01-01 and T-05-01-02.

## Self-Check: PASSED

- app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php — modified, committed at 9cba34d
- app/Core/AI/Prompts/ScopeOfWorksPrompt.php — modified, committed at 0072cb1
- app/Services/RoomOverviewSummaryService.php — modified, committed at 521b4da
- All three files lint clean with `php -l`
- `return 2000` present in RoomOverviewSummaryPrompt
- `return 900` present in ScopeOfWorksPrompt
- 5 occurrences of `'description'` in RoomOverviewSummaryService
