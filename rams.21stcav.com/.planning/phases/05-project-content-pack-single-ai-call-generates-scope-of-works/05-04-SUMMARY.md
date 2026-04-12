---
phase: 05-project-content-pack
plan: 04
subsystem: downstream-consumers
tags: [method-statement, om-manual, scope-of-works, room-description, prompt-enrichment]
dependency_graph:
  requires: [05-02]
  provides: [scope-of-works-wired-to-method-statement, om-manual-uses-content-pack]
  affects: [MethodStatementService, OmManualGeneratorService, OmManualPrompt]
tech_stack:
  added: []
  patterns: [first-preference-guard, room-enrichment-merge, conditional-prompt-block]
key_files:
  created: []
  modified:
    - app/Services/MethodStatementService.php
    - app/Core/Modules/OMManual/OmManualGeneratorService.php
    - app/Core/AI/Prompts/OmManualPrompt.php
decisions:
  - Project model uses packages() relationship (not projectPackages()); used packages()->whereNotNull('project_id')->latest()->first() for linked package lookup
  - scopeBlock uses conditional interpolation so prompt is unchanged when scope_of_works is empty — zero regression risk for existing O&Ms
metrics:
  duration: 15m
  completed: 2026-04-11
  tasks: 3
  files_modified: 3
---

# Phase 05 Plan 04: Wire Content Pack Into Downstream Consumers — Summary

**One-liner:** Wired scope_of_works as highest-priority input to MethodStatementService::buildScope() and enriched OmManualGeneratorService context + OmManualPrompt with scope_of_works and per-room description from the content pack.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Update MethodStatementService::buildScope() to prefer scope_of_works | c28d4d2 | app/Services/MethodStatementService.php |
| 2 | Enrich OmManualGeneratorService context with scope_of_works and room descriptions | 96ce8b9 | app/Core/Modules/OMManual/OmManualGeneratorService.php |
| 3 | Update OmManualPrompt::forContent() to render scope_of_works and room descriptions | 596053a | app/Core/AI/Prompts/OmManualPrompt.php |

## What Was Built

### Task 1 — MethodStatementService

- Added `scope_of_works` guard at the very top of `buildScope()`, before the existing `tasks` check
- Returns the scope_of_works string immediately when non-empty (highest priority in fallback chain)
- Updated PHPDoc to document the full 5-priority fallback chain (scope_of_works → tasks → classifier summary → equipment summary → generic fallback)

### Task 2 — OmManualGeneratorService

- `buildContextFromProjectData()` now loads `descriptionsByRoom` from the most recent linked `ProjectPackage` via `$project->packages()->whereNotNull('project_id')->latest()->first()`
- Merges per-room `description` into each room entry in the rooms array (empty string when no match)
- Loads `scope_of_works` from the same package's `extracted_data` and adds it as a top-level key in the return array
- `buildContentContext()` new-shape branch now passes `scope_of_works` through from `extracted_data` (with `trim((string)(...))` defensive cast)

### Task 3 — OmManualPrompt

- `buildContentPrompt()` extracts `scope_of_works` from context and builds a conditional `$scopeBlock`
- `$scopeBlock` renders as a `PROJECT SCOPE` block (with heading and dashes) when non-empty; resolves to empty string otherwise — no prompt change for existing O&Ms without content pack
- `$scopeBlock` inserted between PROJECT DETAILS and INSTALLED EQUIPMENT sections in the heredoc
- Added instruction 3: "Where a room `description` field is provided in the equipment data, use it to ground the system description and operating procedure for that room"
- Renumbered existing instructions 3 and 4 to 4 and 5
- Updated PHPDoc for `buildContentPrompt()` to document `scope_of_works` and room `description` context keys

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Used correct Project relationship name**
- **Found during:** Task 2
- **Issue:** Plan referenced `$project->projectPackages()` but the `Project` model only defines `packages()` (a `HasMany` to `ProjectPackage`)
- **Fix:** Used `$project->packages()->whereNotNull('project_id')->latest()->first()` instead
- **Files modified:** app/Core/Modules/OMManual/OmManualGeneratorService.php
- **Commit:** 96ce8b9

## Known Stubs

None — this plan wires pre-generated content into consumers. No UI changes. All three files pass `php -l` cleanly.

## Threat Flags

No new network endpoints, auth paths, or file access patterns introduced. All threat model dispositions accepted as planned:

| Threat ID | Disposition | Note |
|-----------|-------------|------|
| T-05-04-01 | accepted | scope_of_works in method statement — reviewer-approved internal data |
| T-05-04-02 | accepted | packages() lookup uses project_id FK — no cross-project exposure |
| T-05-04-03 | accepted | scope_of_works interpolated into AI prompt — same pattern as existing $notes |
| T-05-04-04 | accepted | Single additional query per O&M generation — negligible overhead |

## Self-Check: PASSED

- app/Services/MethodStatementService.php — modified, committed at c28d4d2
- app/Core/Modules/OMManual/OmManualGeneratorService.php — modified, committed at 96ce8b9
- app/Core/AI/Prompts/OmManualPrompt.php — modified, committed at 596053a
- All three files lint clean with `php -l`
- `scope_of_works` present in MethodStatementService (3 lines: PHPDoc × 2, guard variable)
- `scope_of_works` present in OmManualGeneratorService (4 lines: variable assignment, return key in buildContextFromProjectData, return key in buildContentContext, comment)
- `PROJECT SCOPE` present in OmManualPrompt (2 lines: PHPDoc and scopeBlock string)
- `scopeBlock` present in OmManualPrompt (conditional assignment and heredoc interpolation)
- `descriptionsByRoom` present in OmManualGeneratorService (enrichment map variable and array_map closure)
- `description` field merge present in OmManualGeneratorService room enrichment block
- All three commits verified in git log
