
### Phase 1: Project Layer & Data Foundation

**Goal:** Engineers can create and manage projects as top-level entities, and ProjectDataService exists with a defined canonical contract ready for all generators to consume
**Requirements**: PROJ-01, PROJ-02, PROJ-03, PROJ-04, PROJ-05, DATA-01, DATA-02, DATA-04, DATA-05
**Depends on:** —
**Plans:** 6 plans

Plans:
- [x] 01-01: Project model, migration, and controller scaffolding
- [x] 01-02: Project dashboard (show page, linked records card, lifecycle bar)
- [x] 01-03: ProjectDataService — canonical merge contract
- [x] 01-04: Auto-advance hooks (quote import → survey_pending, survey submit → engineering)
- [x] 01-05: Project index — search, client filter, status tabs
- [x] 01-06: Backfill project_id on existing module tables

### Phase 2: QuoteWerks SQL Import

**Goal:** Users can import quote data directly from the QuoteWerks SQL database as an alternative to PDF upload, producing identical extracted_data output
**Requirements**: QWSQL-01, QWSQL-02, QWSQL-03, QWSQL-04, QWSQL-05, QWSQL-06, QWSQL-07
**Depends on:** Phase 1
**Plans:** 3 plans

Plans:
- [x] 02-01: QuoteWerks connection foundation — ping + schema artisan commands
- [x] 02-02: QuoteWerksRepository and QuoteWerksImportService
- [x] 02-03: Dual-tab import UI (PDF upload | QuoteWerks lookup)

### Phase 3: Survey Data Integration

**Goal:** Survey data is fully wired into ProjectDataService so all generators receive enriched, merged project data including per-room survey fields and global site conditions
**Requirements**: SURV-01, SURV-02, SURV-03, SURV-04, SURV-05
**Depends on:** Phase 1
**Plans:** 4 plans

Plans:
- [x] 03-01: Schema extension — site_surveys global fields and superseded_at migration
- [x] 03-02: Real mergeSurveyRooms and resolveSurveyMeta implementations (fuzzy matching, relational load)
- [x] 03-03: One-survey-per-project enforcement with supersede mechanism
- [x] 03-04: Global survey fields wired through public form, confirmation page, and internal show view

### Phase 4: Document Generators

**Goal:** Users can generate Worksheets (DOCX), O&M Manuals (DOCX), and Cable Schedules (XLSX) from ProjectDataService canonical data, with queue-based async processing and status tracking
**Requirements**: WORK-01, WORK-02, WORK-03, WORK-04, OM-01, OM-02, OM-03, OM-04, CABLE-01, CABLE-02, CABLE-03, CABLE-04
**Depends on:** Phase 1, Phase 3
**Plans:** 1/4 plans executed

Plans:
- [x] 04-01-PLAN.md � Worksheet generator (model, migration, AI prompt, DOCX service, job, controller, views)
- [x] 04-02-PLAN.md � O&M Manual refactor (replace Pass 1 with ProjectDataService feed)
- [ ] 04-03-PLAN.md � Cable Schedule refactor (fix model fillable, CableScheduleGeneratorService, BuildCableScheduleJob)
- [ ] 04-04-PLAN.md � Project show page � wire all three generate buttons, status polling, remove Phase 4 guard
