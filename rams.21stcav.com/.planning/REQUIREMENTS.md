# Requirements: RAMS Platform — AV Operations System

**Defined:** 2026-04-09
**Core Value:** One dataset powers every document. Engineers capture real-world data, quotes provide equipment scope, and all outputs are generated with zero guesswork from that shared truth.

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Project Layer

- [ ] **PROJ-01**: User can create a project with name, client, site address, and quote reference
- [ ] **PROJ-02**: All system records (RAMS, surveys, worksheets, O&M, cable schedules) are linked via project_id
- [ ] **PROJ-03**: User can view a project dashboard showing all related records with status indicators
- [ ] **PROJ-04**: Project follows lifecycle state machine (quote_imported → survey_pending → engineering → installing → commissioning → handover → completed → archived)
- [ ] **PROJ-05**: Quote references support versioning (ABC123-01, -02)

### Unified Data Model

- [ ] **DATA-01**: ProjectDataService merges extracted_data, reviewed_data, and survey_data into a single canonical dataset
- [ ] **DATA-02**: Canonical data structure follows the contract: { project, equipment, rooms, activities, risks, survey_data }
- [ ] **DATA-03**: All document generators consume exclusively from ProjectDataService — no direct data access
- [ ] **DATA-04**: Every data field carries source annotation (data_source: pdf | quotewerks | manual, confidence: 0.0-1.0)
- [ ] **DATA-05**: Merge priority is enforced: reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults

### QuoteWerks SQL Import

- [ ] **QWSQL-01**: MS SQL driver verified and configured on production server (hard blocker)
- [ ] **QWSQL-02**: QuoteWerksImportService connects to remote MS SQL via read-only direct connection
- [ ] **QWSQL-03**: SQL import pulls header data, line items, and room/group structure from QuoteWerks database
- [ ] **QWSQL-04**: SQL import produces identical extracted_data structure as PDF import
- [ ] **QWSQL-05**: Dual input system — user can import via PDF upload or SQL quote reference lookup
- [ ] **QWSQL-06**: SQL connection configured via .env with no frontend exposure of credentials
- [ ] **QWSQL-07**: Health check artisan command (quotewerks:ping) verifies connectivity

### Survey Integration

- [ ] **SURV-01**: Survey data is wired into ProjectDataService and available to all generators
- [ ] **SURV-02**: Per-room data captures: displays, audio systems, cable routes, power/network, mounting constraints, access limitations
- [ ] **SURV-03**: Global survey data captures: site risks, H&S notes, constraints
- [ ] **SURV-04**: External users (clients/subcontractors) can fill surveys via UUID token link without login
- [ ] **SURV-05**: Survey supports draft save and final submission with timestamps

### RAMS Generator

- [ ] **RAMS-01**: RAMS generator consumes from ProjectDataService (survey data + structured equipment/activities)
- [ ] **RAMS-02**: Existing RAMS pipeline (BuildRamsDocumentJob → RamsBuilderService) continues to work during migration
- [ ] **RAMS-03**: AI is used ONLY for method statement structuring — never for inventing scope or equipment

### Worksheet Generator

- [ ] **WORK-01**: User can generate a worksheet document (DOCX) from project data
- [ ] **WORK-02**: Worksheet contains room-by-room install steps, equipment lists, cable routes, and key constraints
- [ ] **WORK-03**: Worksheet content is derived entirely from structured project + survey data (no AI guessing)
- [ ] **WORK-04**: Worksheet generation uses queue-based async processing with status tracking

### O&M Manual Generator

- [ ] **OM-01**: User can generate an O&M manual (DOCX) from project data
- [ ] **OM-02**: O&M contains equipment schedules, system descriptions, maintenance guidance, and asset register
- [ ] **OM-03**: O&M content is equipment-driven — no generic filler text, only installed systems included
- [ ] **OM-04**: O&M generation uses queue-based async processing with status tracking

### Cable Schedule Generator

- [ ] **CABLE-01**: User can generate a cable schedule (XLSX) from project data
- [ ] **CABLE-02**: Cable schedule contains cable type, from/to, length (estimated or survey-derived), and route notes
- [ ] **CABLE-03**: Cable data is derived from equipment relationships and survey inputs
- [ ] **CABLE-04**: Cable schedule generation uses queue-based async processing with status tracking

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Notifications

- **NOTF-01**: User receives email when document generation completes
- **NOTF-02**: User receives email when external survey is submitted
- **NOTF-03**: PM receives weekly project status digest

### Enhanced Confidence Tracking

- **CONF-01**: Dashboard showing data confidence scores across all projects
- **CONF-02**: Ability to filter projects by confidence level
- **CONF-03**: Confidence improvement suggestions (e.g., "survey data would improve confidence for Room 3")

### Survey Management

- **SURVM-01**: Admin can set expiry dates on survey token links
- **SURVM-02**: Admin can revoke survey access
- **SURVM-03**: Admin can view survey completion analytics

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Mobile native app | Responsive web is sufficient for survey forms on tablets |
| Real-time collaboration | Single-user editing per session matches real-world workflow |
| Client portal | Clients receive deliverables at handover; token links for surveys only |
| AI-invented scope/equipment/design | Regulatory and liability risk; AI formats only, never invents |
| In-app DOCX/XLSX editor | Generate, download, edit locally; re-generate if source data changes |
| Bi-directional QuoteWerks sync | Read-only SQL; never write to QuoteWerks from this platform |
| Multi-tenancy | Single-company platform for 21st Century AV |
| Project scheduling (Gantt) | Lifecycle state machine is sufficient; don't replace MS Project |
| Email notification system | Adds infrastructure complexity; deferred to v2 |
| Full-text search across documents | Not needed at current scale; project name/client/ref search exists |
| Asset register as standalone module | Asset register is a section within O&M output, not a separate entity |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| (Populated during roadmap creation) | | |

**Coverage:**
- v1 requirements: 32 total
- Mapped to phases: 0
- Unmapped: 32

---
*Requirements defined: 2026-04-09*
*Last updated: 2026-04-09 after initial definition*
