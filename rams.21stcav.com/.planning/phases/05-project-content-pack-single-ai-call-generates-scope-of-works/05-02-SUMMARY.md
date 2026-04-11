---
phase: 05-project-content-pack
plan: 02
subsystem: data-pipeline
tags: [normalisation, validation, controller, job, content-pack]
dependency_graph:
  requires: [05-01]
  provides: [description-persisted-through-pipeline, works_overview-persisted-through-pipeline, auto-content-pack-on-extract]
  affects: [RamsReviewDataService, RamsReviewValidatorService, ProjectPackageReviewController, ExtractQuoteJob]
tech_stack:
  added: []
  patterns: [best-effort-try-catch, defensive-string-casting, normalise-schema-extension]
key_files:
  created: []
  modified:
    - app/Services/RamsReviewDataService.php
    - app/Services/RamsReviewValidatorService.php
    - app/Http/Controllers/ProjectPackageReviewController.php
    - app/Jobs/ExtractQuoteJob.php
decisions:
  - generateContentPack() uses app() resolver for RoomOverviewSummaryService to avoid constructor injection changes in a queued job
  - sourceDescription extracted from current_description form value first, then backfills from saved overviews — matches existing pattern for sourceOverview
  - works_overview max:2000 in validator (exec summary is short); room description max:10000 (prose paragraph, same as overview/summary)
metrics:
  duration: 20m
  completed: 2026-04-11
  tasks: 3
  files_modified: 4
---

# Phase 05 Plan 02: Wire description and works_overview Through Data Persistence Layer — Summary

**One-liner:** Extended normalisation, validation, controller show/save/expand, and ExtractQuoteJob to persist `description` per room and `works_overview` project-level through every data round-trip, and auto-generate the content pack on quote extraction.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Extend RamsReviewDataService normalise() and normaliseRoomOverviews() for new fields | 30666cf | app/Services/RamsReviewDataService.php |
| 2 | Update controller show(), parseReviewPayload(), and generateSurveyRooms() for new fields | 3ff3034 | app/Http/Controllers/ProjectPackageReviewController.php |
| 3 | Add validation rules and auto-generation in ExtractQuoteJob | b5ee1c0 | app/Services/RamsReviewValidatorService.php, app/Jobs/ExtractQuoteJob.php |

## What Was Built

### Task 1 — RamsReviewDataService

- `normalise()` return array now includes `works_overview` (string, default `''`) after `scope_of_works`
- `normaliseRoomOverviews()` per-entry now includes `description` (string, default `''`) after `summary`
- `load()` merge block backfills `description` from `extracted_data` when the `reviewed_data` description is empty — same pattern as existing `overview` and `summary` backfill

### Task 2 — ProjectPackageReviewController

- `show()` room_overviews `array_map` closure now returns `description` per room from saved overviews
- `parseReviewPayload()` trims and captures `works_overview` from the form POST (after `scope_of_works`)
- `parseReviewPayload()` room overviews loop captures `description` per room entry
- `generateSurveyRooms()` validation rules include `current_description` (nullable string)
- `generateSurveyRooms()` extracts `$sourceDescription` from `current_description` form value, with fallback lookup from saved overviews (same pattern as `$sourceOverview`)
- `generateSurveyRooms()` room expansion loop copies `$sourceDescription` to each new numbered room entry

### Task 3 — RamsReviewValidatorService + ExtractQuoteJob

- `RamsReviewValidatorService`: two new rules — `room_overviews.*.description` (nullable, string, max:10000) and `works_overview` (nullable, string, max:2000)
- `ExtractQuoteJob::handle()`: calls `$this->generateContentPack($extracted)` after `DB::transaction()` closes and before the final `Log::info`
- `ExtractQuoteJob::generateContentPack()`: private method that:
  1. Calls `RoomOverviewSummaryService::summarize()` on room_overviews to get AI-generated summaries and descriptions
  2. Builds room lines and calls `ScopeOfWorksPrompt` via `AIManager::run()` to get `scope_of_works` + `works_overview`
  3. Fetches fresh `extracted_data`, merges room_overviews + scope fields, and persists via `$this->package->update()`
  4. Entire body wrapped in `try/catch(\Throwable)` — AI failure logs a warning and extraction completes normally

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — this plan wires persistence only. No UI changes yet (that is Plan 03). The `description` and `works_overview` values are now stored in `extracted_data` and survive save/reload cycles, but no Blade template renders them yet.

## Threat Flags

No new network endpoints, auth paths, or file access patterns introduced. All threat model mitigations applied as planned:

| Flag | File | Description |
|------|------|-------------|
| T-05-02-01 mitigated | ProjectPackageReviewController.php | works_overview captured with trim((string)(...)) and validated max:2000 |
| T-05-02-02 mitigated | ProjectPackageReviewController.php | room description captured with trim((string)(...)) per room, validated max:10000 |
| T-05-02-03 accepted | ExtractQuoteJob.php | AI calls wrapped in try/catch(\Throwable) — failure logs warning only |
| T-05-02-04 accepted | ExtractQuoteJob.php | Logs contain package_id only, no PII or AI response content |

## Self-Check: PASSED

- app/Services/RamsReviewDataService.php — modified, committed at 30666cf
- app/Http/Controllers/ProjectPackageReviewController.php — modified, committed at 3ff3034
- app/Services/RamsReviewValidatorService.php — modified, committed at b5ee1c0
- app/Jobs/ExtractQuoteJob.php — modified, committed at b5ee1c0
- All four files lint clean with `php -l`
- `works_overview` present in RamsReviewDataService normalise() and RamsReviewValidatorService rules
- `description` present in RamsReviewDataService normaliseRoomOverviews() and load() merge block (4 occurrences)
- `generateContentPack` present at both call site (line 165) and definition (line 180) in ExtractQuoteJob
- 2 `catch (\Throwable` blocks in ExtractQuoteJob (existing AI standardisation + new content pack)
