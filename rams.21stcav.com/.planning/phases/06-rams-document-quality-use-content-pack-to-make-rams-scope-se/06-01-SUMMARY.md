---
phase: 06-rams-document-quality
plan: 01
subsystem: service-layer
tags: [rams, method-statement, worksheet, content-pack, ai-prompts, skip-guard]
dependency_graph:
  requires: [05]
  provides: [rams-skip-guard, method-statement-enrichment, worksheet-enrichment]
  affects: [RamsBuilderService, MethodStatementService, MethodStatementPrompt, WorksheetGeneratorService, WorksheetPrompt]
tech_stack:
  added: []
  patterns: [tdd-red-green, skip-guard, context-enrichment, case-insensitive-lookup]
key_files:
  created:
    - tests/Unit/Services/RamsBuilderServiceTest.php
    - tests/Unit/Services/MethodStatementServiceTest.php
    - tests/Unit/Services/MethodStatementPromptTest.php
    - tests/Unit/Services/WorksheetGeneratorServiceTest.php
    - tests/Unit/Services/WorksheetPromptTest.php
  modified:
    - app/Services/RamsBuilderService.php
    - app/Services/MethodStatementService.php
    - app/Core/AI/Prompts/MethodStatementPrompt.php
    - app/Services/WorksheetGeneratorService.php
    - app/Core/AI/Prompts/WorksheetPrompt.php
decisions:
  - "Skip summarize() when all room summaries populated: allSummariesPopulated check uses is_array() guard per T-06-01 threat model"
  - "Non-array room entries treated as empty summary — trigger summarize() rather than skip"
  - "reviewedToParsed() extended with scope_of_works and works_overview for downstream use by MethodStatementService"
  - "buildRoomDescriptions() helper: newline-delimited Room:prose, omits entries with blank room or description"
  - "scope_of_works NOT added as separate prompt key in MethodStatementPrompt — buildScope() already carries it via scope_summary (avoids double injection per RESEARCH Pitfall 5)"
  - "WorksheetGeneratorService uses Path B (direct package reviewed_data access) — avoids modifying ProjectDataService"
  - "Room description lookup uses strtolower+trim for case-insensitive matching per RESEARCH Pitfall 3"
metrics:
  duration: 90m
  completed: 2026-04-12
  tasks: 3
  files: 10
---

# Phase 06 Plan 01: Service Enrichment — RAMS Skip Guard, Method Statement and Worksheet Prompts

**One-liner:** Wired Phase 5 content pack fields into service layer and AI prompts — skip guard eliminates redundant summarize() AI calls when room summaries already reviewed; works_overview and per-room description prose injected into MethodStatementPrompt and WorksheetPrompt for project-specific generation.

## What Was Built

### Task 1: RamsBuilderService skip-guard (D-02)

Replaced the unconditional `$this->roomOverviewSummary->summarize()` call with an `allSummariesPopulated` check. When all rooms in `reviewed_data['room_overviews']` already have a non-empty `summary` field (from Phase 5 content pack or prior review), the AI summarize call is skipped entirely. The DB update is also skipped in that path (no needless writes). In the regenerate path, behaviour is unchanged.

Non-array room entries are treated as "empty summary" to prevent a skip on corrupt data (T-06-01 threat mitigation: `is_array($r)` guard in the check).

`reviewedToParsed()` was extended to include `scope_of_works` and `works_overview` in the returned parsedQuote array — these are needed by MethodStatementService for the prompt context.

### Task 2: MethodStatementService + Prompt enrichment (D-03)

`generate()` now passes two additional context keys to the AI prompt:
- `works_overview` — project-level 2-3 sentence executive summary from content pack
- `room_descriptions` — newline-delimited "Room: prose" entries built by `buildRoomDescriptions()`

`buildRoomDescriptions()` filters `room_overviews[].description` — skips non-array entries and entries with blank room name or blank description.

`MethodStatementPrompt::build()` adds two optional lines (`worksOverviewLine`, `roomDescriptionsLine`) after the existing `roomSummaryLine`. Both are omitted when the context values are empty — keeping the prompt compact for projects without content pack data.

Phase 4 instruction updated to reference room descriptions explicitly.

`scope_of_works` was NOT added as a separate prompt key — `buildScope()` already returns it as `scope_summary` when populated (RESEARCH Pitfall 5 avoidance).

### Task 3: WorksheetGeneratorService + Prompt enrichment (D-04)

`generateContent()` now loads room description and works_overview from `$project->latestPackage->reviewed_data` after resolving ProjectDataService data. Room names are normalised to lowercase+trimmed for case-insensitive lookup (RESEARCH Pitfall 3 fix).

`buildRooms()` signature extended with `$roomDescriptions` and `$worksOverview` parameters. Each `$roomForPrompt` receives `description` (looked up by lowercase room name) and `works_overview`.

`WorksheetPrompt::build()` adds two optional labelled blocks between the SITE SURVEY DATA section and INSTRUCTIONS:
- `ROOM DESCRIPTION (use for context only):` — when room['description'] is non-empty
- `PROJECT OVERVIEW (use for context only):` — when room['works_overview'] is non-empty

The INSTRUCTIONS constraint "Base steps ONLY on the equipment and survey data provided above — do not invent items" is unchanged.

## Test Results

### Targeted suite (31 tests)
- `RamsBuilderServiceTest`: 7 passing
- `MethodStatementServiceTest`: 6 passing
- `MethodStatementPromptTest`: 6 passing
- `WorksheetGeneratorServiceTest`: 5 passing
- `WorksheetPromptTest`: 7 passing

### Full suite
- 261 passing, 33 failing (same 33 pre-existing failures as baseline — no regressions)

## Commits

| Task | Type | Hash    | Description |
|------|------|---------|-------------|
| 1 RED  | test | f0e728b | Add failing tests for RamsBuilderService skip-guard |
| 1 GREEN | feat | 62305bb | RamsBuilderService skip-guard implementation |
| 2 RED  | test | b682791 | Add failing tests for MethodStatementService and Prompt |
| 2 GREEN | feat | 1bbd90e | MethodStatementService and Prompt enrichment |
| 3 RED  | test | b07ccca | Add failing tests for WorksheetGeneratorService and Prompt |
| 3 GREEN | feat | 87e8664 | WorksheetGeneratorService and Prompt enrichment |

## Deviations from Plan

None — plan executed exactly as written. The `is_array()` guard in the allSummariesPopulated check was specified by the plan (T-06-01 threat mitigation) and is implemented as designed.

## Known Stubs

None. All content pack fields flow from `reviewed_data` (Phase 5 populated). When fields are absent, all new code degrades gracefully to empty string — no invented content, no crashes.

## Threat Flags

None. All new code consumes internal `reviewed_data` fields — no new network endpoints, no new auth paths, no new file access, no schema changes.

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| app/Services/RamsBuilderService.php | FOUND |
| app/Services/MethodStatementService.php | FOUND |
| app/Core/AI/Prompts/MethodStatementPrompt.php | FOUND |
| app/Services/WorksheetGeneratorService.php | FOUND |
| app/Core/AI/Prompts/WorksheetPrompt.php | FOUND |
| tests/Unit/Services/RamsBuilderServiceTest.php | FOUND |
| Commit 62305bb (RamsBuilderService skip-guard) | FOUND |
| Commit 1bbd90e (MethodStatement enrichment) | FOUND |
| Commit 87e8664 (Worksheet enrichment) | FOUND |
| Full suite: 33 pre-existing failures, 0 regressions | CONFIRMED |
