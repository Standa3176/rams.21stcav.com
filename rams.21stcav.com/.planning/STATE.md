# Project State

**Last updated:** 2026-04-11

## Current Phase

Phase 4: Document Generators — 04-03 complete (cable schedule generator pipeline)

**Last session:** Completed 04-03-PLAN.md — CableSchedule model fix, CableScheduleGeneratorService, BuildCableScheduleJob, generateFromProject/status/download controller methods, 3 new routes.

## Completed Phases

| Phase | Name | Status |
|-------|------|--------|
| 01 | Project Layer & Data Foundation | Complete (gaps resolved 2026-04-11) |
| 02 | QuoteWerks SQL Import | Complete |
| 03 | Survey Data Integration | Complete |

## Quick Tasks Completed

| ID | Description | Date |
|----|-------------|------|
| 260411-9ov | Fix Phase 1 gaps — confirm all 7 gaps resolved, fix auto-advance backward regression | 2026-04-11 |

## Accumulated Context

### Roadmap Evolution

- Phase 1 added: Project Layer & Data Foundation
- Phase 2 added: QuoteWerks SQL Import
- Phase 3 added: Survey Data Integration
- Phase 4 added: Document generators — Worksheet, O&M Manual, and Cable Schedule generated from ProjectDataService canonical data

### Key Decisions (Cross-Phase)

- AI is only used for method statement structuring — never invents scope, equipment, or design
- All document content traces to quote data, survey data, or reviewed inputs
- Merge priority: reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults
- One survey per project (enforced via superseded_at)
- ProjectDataService is the single canonical data source for all generators
- Cable type inference is deterministic keyword matching — no AI (04-03)
- CableScheduleXlsxService::build() updates filename but not status — BuildCableScheduleJob sets STATUS_DRAFT explicitly after build() (04-03)
- Route ordering: literal segments (generate-from-project) registered before Route::resource wildcard to prevent capture (04-03)
