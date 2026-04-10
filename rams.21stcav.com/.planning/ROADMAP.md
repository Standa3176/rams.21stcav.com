# Roadmap: RAMS Platform — AV Operations System

## Overview

The milestone transforms a working-but-siloed RAMS pipeline into a unified platform where one dataset powers every document. The build order is determined by dependency: the data layer must exist before generators can consume it, the live RAMS pipeline migrates last. QuoteWerks SQL import runs in parallel with the data layer foundation. Worksheets, O&M, and Cable Schedule generators are proven before the live RAMS pipeline is touched.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Project Layer & Data Foundation** - Introduce the `projects` table and define `ProjectDataService` as the canonical data merge contract
- [ ] **Phase 2: QuoteWerks SQL Import** - Verify MS SQL connectivity and build the import service that produces identical output to PDF import
- [ ] **Phase 3: Survey Data Integration** - Wire existing survey infrastructure into `ProjectDataService` so all generators can consume survey data
- [ ] **Phase 4: New Document Generators** - Build Worksheet, O&M, and Cable Schedule generators consuming from `ProjectDataService`
- [ ] **Phase 5: RAMS Pipeline Migration** - Migrate the live RAMS pipeline to consume `ProjectDataService` (last by design)

## Phase Details

### Phase 1: Project Layer & Data Foundation
**Goal**: Engineers can create and manage projects as top-level entities, and `ProjectDataService` exists with a defined canonical contract ready for all generators to consume
**Depends on**: Nothing (first phase)
**Requirements**: PROJ-01, PROJ-02, PROJ-03, PROJ-04, PROJ-05, DATA-01, DATA-02, DATA-04, DATA-05
**Success Criteria** (what must be TRUE):
  1. User can create a project with name, client, site address, and quote reference (with version suffix support)
  2. User can view a project dashboard that lists all linked records (RAMS, surveys, worksheets, O&M, cable schedules) with their status
  3. Project lifecycle state transitions correctly (quote_imported → survey_pending → engineering → ... → archived) and the current state is visible
  4. `ProjectDataService::resolve(project)` returns a canonical dataset structured as `{ project, equipment, rooms, activities, risks, survey_data }` with merge priority enforced (reviewed_data wins)
  5. Every field in the merged dataset carries a `data_source` annotation (pdf | quotewerks | manual) and a `confidence` score
**Plans**: 6 plans
Plans:
- [x] 01-01-PLAN.md — Schema migrations, model update, controller sharing, project creation form
- [x] 01-02-PLAN.md — Project dashboard UI: Linked Records card, client filter on listing
- [x] 01-03-PLAN.md — ProjectDataService (TDD): canonical data merge with per-field annotation
- [x] 01-04-PLAN.md — Auto-advance lifecycle hooks and Project Data tab
- [x] 01-05-PLAN.md — GAP: Controller shared visibility, authorization relaxation, quote_reference form + validation, similar-project warning, search fix
- [x] 01-06-PLAN.md — GAP: importFromData() convenience method on QuoteImportService; all 6 feature tests passing
**UI hint**: yes

### Phase 2: QuoteWerks SQL Import
**Goal**: The platform can import quote data directly from the QuoteWerks SQL database, producing output structurally identical to the existing PDF import path
**Depends on**: Phase 1
**Requirements**: QWSQL-01, QWSQL-02, QWSQL-03, QWSQL-04, QWSQL-05, QWSQL-06, QWSQL-07
**Success Criteria** (what must be TRUE):
  1. `php artisan quotewerks:ping` succeeds on the production server, confirming MS SQL driver and VPN connectivity are both working
  2. User can look up a quote by reference number and import it — the resulting `extracted_data` structure is byte-for-byte compatible with a PDF import of the same quote
  3. User can choose between PDF upload and SQL quote reference lookup on the import screen — both paths land in the same review workflow
  4. QuoteWerks SQL credentials are never exposed in the frontend or application logs
**Plans**: 3 plans
Plans:
- [x] 02-01-PLAN.md — Named DB connection config, .env.example, quotewerks:ping and quotewerks:schema commands
- [ ] 02-02-PLAN.md — QuoteWerksRepository (TDD) + QuoteWerksImportService (TDD): SQL query layer and extracted_data mapping
- [ ] 02-03-PLAN.md — QuoteWerksImportController, QuoteWerksLookupRequest, dual-tab import UI

### Phase 3: Survey Data Integration
**Goal**: Survey data captured by engineers, clients, or subcontractors is fully wired into `ProjectDataService` and available to all document generators without any generator needing to query the survey tables directly
**Depends on**: Phase 1
**Requirements**: SURV-01, SURV-02, SURV-03, SURV-04, SURV-05
**Success Criteria** (what must be TRUE):
  1. External user (client or subcontractor) can open a UUID token link, fill in per-room and global site data, save a draft, and submit — without creating an account
  2. Per-room survey captures displays, audio systems, cable routes, power/network details, mounting constraints, and access limitations
  3. Global survey section captures site risks, H&S notes, and site-wide constraints
  4. `ProjectDataService::resolve(project)` includes submitted survey data merged at the correct priority tier (above quotewerks_sql, below reviewed_data)
**Plans**: TBD
**UI hint**: yes

### Phase 4: New Document Generators
**Goal**: Engineers can generate Worksheets, O&M Manuals, and Cable Schedules from a project — all derived exclusively from structured project and survey data, with no AI-invented content
**Depends on**: Phase 3
**Requirements**: DATA-03, WORK-01, WORK-02, WORK-03, WORK-04, OM-01, OM-02, OM-03, OM-04, CABLE-01, CABLE-02, CABLE-03, CABLE-04
**Success Criteria** (what must be TRUE):
  1. User can trigger Worksheet generation and download a DOCX file containing room-by-room install steps, equipment lists, cable routes, and constraints — all traceable to project or survey data
  2. User can trigger O&M Manual generation and download a DOCX file containing equipment schedules, system descriptions, maintenance guidance, and an asset register — only installed systems appear, no generic filler
  3. User can trigger Cable Schedule generation and download an XLSX file containing cable type, from/to endpoints, estimated or survey-derived length, and route notes
  4. All three generators process via the queue with visible status tracking (pending → processing → complete / failed)
  5. Each generator consumes exclusively from `ProjectDataService` — no direct queries to survey, extracted_data, or reviewed_data tables from within the generator
**Plans**: TBD
**UI hint**: yes

### Phase 5: RAMS Pipeline Migration
**Goal**: The existing live RAMS pipeline is migrated to consume from `ProjectDataService`, completing the unified data layer — every document type now draws from the same source of truth
**Depends on**: Phase 4
**Requirements**: RAMS-01, RAMS-02, RAMS-03
**Success Criteria** (what must be TRUE):
  1. Existing RAMS documents continue to generate correctly after migration — no regressions in output quality or structure
  2. RAMS generator reads exclusively from `ProjectDataService` rather than directly accessing extracted_data or reviewed_data
  3. AI usage within RAMS is limited to method statement structuring — scope, equipment lists, and activities come from structured data only, never from AI inference
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

Note: Phase 2 (QuoteWerks SQL) has no dependency on Phase 3 (Survey Integration) — both depend only on Phase 1. They can be planned and executed independently after Phase 1 completes.

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Project Layer & Data Foundation | 6/6 | Complete | 2026-04-10 |
| 2. QuoteWerks SQL Import | 1/3 | In Progress|  |
| 3. Survey Data Integration | 0/TBD | Not started | - |
| 4. New Document Generators | 0/TBD | Not started | - |
| 5. RAMS Pipeline Migration | 0/TBD | Not started | - |
