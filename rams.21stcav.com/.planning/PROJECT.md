# RAMS Platform — AV Operations System

## What This Is

An internal operations platform for 21st Century AV Ltd that manages the full lifecycle of AV installation projects. Starting from a quoted job, it flows through site survey, engineering review, and generates all compliance and technical documents (RAMS, Worksheets, O&M Manuals, Cable Schedules) from a single unified dataset. No duplicated data, no AI guessing — every output is driven by structured project data.

## Core Value

One dataset powers every document. Engineers capture real-world data, quotes provide equipment scope, and all outputs are generated with zero guesswork from that shared truth.

## Requirements

### Validated

- ✓ QuoteWerks PDF upload, text extraction, and structured parsing — existing
- ✓ Two-phase extraction pipeline (extracted_data → reviewed_data) — existing
- ✓ AI-generated method statements (structured JSON, Claude/OpenAI) — existing
- ✓ RAMS DOCX generation from reviewed data — existing
- ✓ Queue-based async document generation with status tracking — existing
- ✓ Site survey system with room-by-room input and photo upload — existing
- ✓ Public survey access via UUID token (no login required) — existing
- ✓ Equipment classification and hazard template resolution — existing
- ✓ AI response caching and usage tracking — existing
- ✓ Role-based access (admin/user) with policy-based authorization — existing

### Active

#### Project Layer
- [ ] **PROJ-01**: New `projects` table as single source of truth (name, client, site address, quote reference with versioning)
- [ ] **PROJ-02**: All systems linked via `project_id` (RAMS, surveys, worksheets, O&M, cable schedules)
- [ ] **PROJ-03**: Project dashboard showing all related records and lifecycle state
- [ ] **PROJ-04**: Project lifecycle state machine (quote_imported → survey_pending → engineering → installing → commissioning → handover → completed → archived)

#### Unified Data Model
- [ ] **DATA-01**: `ProjectDataService` that merges extracted_data, reviewed_data, and survey_data into a normalized dataset
- [ ] **DATA-02**: Canonical data structure: `{ project, equipment, rooms, activities, risks, survey_data }`
- [ ] **DATA-03**: All generators consume from `ProjectDataService` — no direct data access
- [ ] **DATA-04**: Data confidence tracking per record (`data_source: pdf|quotewerks`, `confidence: 0.0-1.0`)

#### QuoteWerks SQL Import
- [ ] **QWSQL-01**: `QuoteWerksImportService` connecting to remote MS SQL (read-only, direct connection via VPN)
- [ ] **QWSQL-02**: Pull header data, line items, and room/group structure from QuoteWerks database
- [ ] **QWSQL-03**: Map SQL data into identical `extracted_data` structure as PDF import
- [ ] **QWSQL-04**: Dual input system — both PDF and SQL imports produce identical output format
- [ ] **QWSQL-05**: SQL connection configured via `.env` with no frontend exposure

#### Site Survey Enhancements
- [ ] **SURV-01**: External users (clients/subcontractors) can fill surveys via token link
- [ ] **SURV-02**: Per-room data capture: displays, audio systems, cable routes, power/network, mounting constraints, access limitations
- [ ] **SURV-03**: Global survey data: site risks, H&S notes, constraints
- [ ] **SURV-04**: Draft save and submission with timestamps
- [ ] **SURV-05**: Survey data feeds into `ProjectDataService` for all generators

#### Document Generators
- [ ] **RAMS-01**: Refine existing RAMS generator to consume from `ProjectDataService` (survey data + structured equipment/activities)
- [ ] **WORK-01**: Worksheet generator — room-by-room install steps, equipment lists, cable routes, constraints (DOCX format)
- [ ] **WORK-02**: Worksheets derived entirely from structured project + survey data (no AI guessing)
- [ ] **OM-01**: O&M Manual generator — equipment schedules, system descriptions, maintenance guidance, asset register (DOCX format)
- [ ] **OM-02**: O&M content is equipment-driven, no generic filler, only installed systems
- [ ] **CABLE-01**: Cable Schedule generator — cable type, from/to, length, route notes (XLSX format)
- [ ] **CABLE-02**: Cable data derived from equipment relationships and survey inputs

### Out of Scope

- Mobile native app — web-based mobile survey is sufficient for now
- Real-time collaboration — single-user editing per survey/review session
- Client portal — external users only access surveys via token links
- Automated quote generation — system consumes quotes, doesn't create them
- Integration with project management tools (MS Project, etc.) — not needed yet
- Multi-tenancy — single-company system for 21st Century AV

## Context

- **Existing codebase**: Laravel 12, PHP 8.2+, MySQL, Blade/Tailwind/Alpine.js
- **AI stack**: Claude (default) + OpenAI via AIManager abstraction, structured JSON only
- **Document generation**: PHPWord (DOCX), DomPDF/mPDF (PDF), needs PhpSpreadsheet for XLSX
- **Current state**: RAMS pipeline fully functional, site survey system has significant infrastructure, O&M and cable schedule services partially scaffolded
- **Key model**: `ProjectPackage` currently serves as the parsed quote container — will work alongside new `Project` model
- **QuoteWerks SQL**: Remote MS SQL database accessible via VPN/direct connection, read-only access
- **External survey users**: Clients and subcontractors receive UUID token links, no authentication required

## Constraints

- **AI usage**: AI is ONLY allowed for formatting and method statement structuring — never for inventing scope, equipment, or design
- **Data integrity**: All document content must trace back to quote data, survey data, or reviewed inputs
- **Existing pipeline**: Must not break existing RAMS pipeline, extracted/reviewed/generated data flow, or queue-based generation
- **Architecture**: Laravel service-based, thin controllers, shared data services, safe migrations, queue-compatible
- **SQL security**: QuoteWerks SQL connection is read-only, .env configured, no frontend exposure
- **Output formats**: RAMS/Worksheets/O&M as DOCX, Cable Schedules as XLSX

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| New `projects` table separate from `project_packages` | Projects are the top-level entity; packages are one input source | — Pending |
| Direct SQL connection to QuoteWerks (not API) | VPN available, simpler than building intermediary service | — Pending |
| Token-only access for external survey users | Simplicity; no account management overhead for one-off surveys | — Pending |
| Cable schedules as XLSX, everything else DOCX | Engineers need cable data in spreadsheet format for field use | — Pending |
| ProjectDataService as single data merge point | Prevents each generator from independently resolving data, ensures consistency | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-09 after initialization*
