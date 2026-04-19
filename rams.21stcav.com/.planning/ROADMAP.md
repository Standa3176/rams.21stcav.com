# Roadmap: RAMS Platform — AV Operations System

## Milestones

- ✅ **v1.0 RAMS MVP** — Phases 01–07 (shipped 2026-04-12)
- 📋 **v1.1 Operations Dashboard & Notifications** — Phases 08–11 (planned)
- 📋 **v1.2 Installation Programme & Field Management** — Phases 12–16 (planned)
- 📋 **v1.3 Technical Drawings & Schematics** — Phases 17–20 (planned)
- 📋 **v1.4 Client Portal & Project Visibility** — Phases 21–24 (planned)
- 📋 **v1.5 Financial & Proposal Engine** — Phases 25–28 (planned)
- 📋 **v1.6 Service & Inventory** — Phases 29–32 (planned)

## Phases

<details>
<summary>✅ v1.0 RAMS MVP (Phases 01–07) — SHIPPED 2026-04-12</summary>

- [x] Phase 01: Project Layer & Data Foundation (6/6 plans) — completed 2026-04-10
- [x] Phase 02: QuoteWerks SQL Import (3/3 plans) — completed 2026-04-10
- [x] Phase 03: Survey Data Integration (4/4 plans) — completed 2026-04-11
- [x] Phase 04: Document Generators — Worksheets, O&M, Cable Schedules (4/4 plans) — completed 2026-04-11
- [x] Phase 05: Project Content Pack — Single AI Call Scope Generation (4/4 plans) — completed 2026-04-11
- [x] Phase 06: RAMS Document Quality (2/2 plans) — completed 2026-04-12
- [x] Phase 07: Dynamic Site Survey AI-Generated Room Questions (6/6 plans) — completed 2026-04-12

Full archive: [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md)

</details>

### v1.1 Operations Dashboard & Notifications
*"See everything, miss nothing"*

- [x] Phase 08: Enterprise Dashboard — Real-time project status, health indicators, overdue/blocked alerts across all active projects (completed 2026-04-19)
- [ ] Phase 09: Email Notifications — Generation complete, survey submitted, review needed triggers
- [ ] Phase 10: Document Quality Scores — Confidence indicators and data completeness per room/equipment
- [ ] Phase 11: Bitrix24 Integration — OAuth connection, project sync, document links, task updates

### v1.2 Installation Programme & Field Management
*"Quote to field in one platform"*

- [x] Phase 12: Install Task Generation — Auto-generate task list (room × equipment) from ProjectDataService; install_programmes + install_tasks models; WORK-05/06 worksheet enhancements (completed 2026-04-13)
- [x] Phase 13: Task Assignment & Scheduling — Engineer assignment, planned dates, week-view calendar; conditional Gantt (frappe-gantt) only when project duration > 4 days (completed 2026-04-14)
- [ ] Phase 14: Mobile Field View — Responsive task checklist, status toggle, per-task photo capture (HEIC protection), clock in/out; online-only
- [ ] Phase 15: Time Tracking — Actual labour hours per project/category; heartbeat-guarded sessions; UTC storage; actuals-only (no budget comparison in v1.2)
- [ ] Phase 16: Commissioning Checklist — Per-equipment AVIXA-category sign-off; per-item AJAX saves; client signature (creagia/laravel-sign-pad, DPI-corrected); snagging PDF; programme completion → project state advance

## Phase Details

### Phase 08: Enterprise Dashboard
**Goal**: Transform the existing closure-based dashboard into a `DashboardController`-powered operational command centre — showing all active projects with per-project health indicators (green/amber/red derived from document completion state), overdue stage alerts (based on existing milestone timestamps), blocked alerts (e.g. engineering with no approved RAMS), a status summary strip, status-filter tabs, and an install programme task-completion widget for projects in the installing/commissioning stage.
**Depends on**: Phase 01 (Project model + lifecycle timestamps), Phase 02 (RAMS/ProjectPackage data), Phase 12 (install_programmes + install_tasks — gracefully absent if no programme exists)
**Requirements**: DASH-01, DASH-01a, DASH-01b, DASH-01c, DASH-01d, DASH-01e, DASH-01f, DASH-01g, DASH-01h
**Success Criteria** (what must be TRUE):
  1. `/dashboard` is served by `DashboardController@index` — the route closure is removed from `routes/web.php`
  2. Dashboard shows all non-archived projects (not capped at 6), each with a health badge (green/amber/red)
  3. A project in `engineering` status with no approved RAMS document shows a red health badge
  4. A project whose current-stage milestone timestamp is >14 days ago shows an overdue indicator
  5. Clicking a status chip in the summary strip filters the project grid to that stage (Alpine.js, no page reload)
  6. A project in `installing` status with an active install programme shows task completion % alongside its health badge
  7. `ProjectHealthService::assess(Project $project): ProjectHealth` exists and is unit-testable in isolation
**Plans**: 2 plans

Plans:
- [x] 08-01-PLAN.md — DashboardController + ProjectHealthService + ProjectHealth DTO + unit/feature tests (Wave 1)
- [x] 08-02-PLAN.md — Enhanced dashboard.blade.php: health grid, status filter strip, Alpine.js filter, install programme widget (Wave 2)

### Phase 09: Email Notifications
**Goal**: Add trigger-based system email notifications for AV-operations events — document generation completed (RAMS, O&M, Worksheet, Cable), document generation failed, RAMS review needed, and inherit the existing survey-submitted email. Wire each trigger into the relevant `Build*Job` success/`failed()` hook or status-transition path; queue mailables via `ShouldQueue`; configure Postmark transport with `rams@21stcav.com` sender for production. Per-user opt-out, in-app notifications, and multi-channel (Slack/Bitrix) are explicitly out of scope.
**Depends on**: Phase 04 (BuildOmManualJob, BuildWorksheetDocxJob, cable schedule generator hooks), Phase 06 (RAMS pipeline status transitions for review-needed and completion), Phase 03 (existing SurveyService send path)
**Requirements**: NOTF-01, NOTF-01a, NOTF-01b, NOTF-01c, NOTF-01d, NOTF-01e, NOTF-01f, NOTF-02, NOTF-02a, NOTF-03, NOTF-03a, NOTF-03b, NOTF-04, NOTF-04a, NOTF-04b, NOTF-04c, NOTF-05, NOTF-05a, NOTF-05b, NOTF-05c, NOTF-05d, NOTF-05e, NOTF-05f, NOTF-05g, NOTF-05h
**Success Criteria** (what must be TRUE):
  1. When a `RamsDocument` transitions to `STATUS_COMPLETED` via `BuildRamsDocumentJob`, the project owner receives an email with the DOCX attached and `completion_email_sent_at` is set on the model row
  2. The same completion-notification pattern fires for O&M Manuals, Worksheets, and Cable Schedules — each model has its own `completion_email_sent_at` column and idempotency guard
  3. When a `Build*Job` exhausts retries and lands in `STATUS_FAILED`, every `User::where('role', 'admin')` recipient receives a failure alert exactly once (`failed_email_sent_at` guard) — note: codebase uses `users.role = 'admin'`, not an `is_admin` boolean
  4. When `ExtractRamsDraftJob` finishes and `RamsDocument.status` becomes `awaiting_review`, the project owner (or admin fallback) receives a review-needed email
  5. The existing survey-submitted send path in `SurveyService::submitPublic()` continues to work unchanged after Phase 09 ships (no regression)
  6. With `MAIL_MAILER=postmark` and a valid `POSTMARK_API_KEY` (the env var name Laravel 12 + `config/services.php` already use), all mailables dispatch via the database queue (`implements ShouldQueue`) and arrive at the recipient
  7. When `RAMS_NOTIFICATION_BCC` env var is non-empty, every system email BCC's that address; when empty, no BCC is added
  8. A mail send failure (caught `Throwable` → `Log::warning`) never rolls back the underlying document-generation job or aborts a status transition
  9. Feature tests using `Mail::fake()` assert each trigger fires the correct mailable to the correct recipient with the correct attachment / no-attachment shape
**Plans**: 6 plans

Plans:
- [ ] 09-01-PLAN.md — Migrations (8 email-timestamp columns + cable_schedules.error_message) + config/rams.php notifications.bcc + .env.example placeholders (Wave 1)
- [ ] 09-02-PLAN.md — composer require symfony/postmark-mailer + symfony/http-client + NotificationRecipientResolver service + unit tests (Wave 1)
- [ ] 09-03-PLAN.md — 4 typed *ReadyMail mailables (RAMS / O&M / Worksheet / Cable) implementing ShouldQueue with DocumentArtifactStorage attachments + Blade templates (Wave 2)
- [ ] 09-04-PLAN.md — RamsReviewNeededMail + DocumentGenerationFailedMail (polymorphic) + Blade templates + SurveyService refactor to use NotificationRecipientResolver (NOTF-02a, fixes 2 latent bugs) (Wave 2)
- [ ] 09-05-PLAN.md — Wire all 5 jobs (Build*Job + ExtractRamsDraftJob) with completion / failure / review email dispatch + 9 feature tests (Mail::fake) + idempotency + BCC tests + extend PublicSurveyControllerTest (Wave 3)
- [ ] 09-06-PLAN.md — POSTMARK-OPS-CHECKLIST.md (DNS, Postmark dashboard, production .env) + human-verify checkpoint for cutover decision (Wave 4)

### Phase 12: Install Task Generation
**Goal**: Auto-generate a structured install task list from `ProjectDataService`, persisted as `install_programmes` + `install_tasks` records. Engineers confirm the generated list before it becomes active. Also deliver WORK-05/06 worksheet enhancements (pre-install answers + dashboard trigger).
**Depends on**: Nothing (first phase of v1.2; ProjectDataService from v1.0 is the data source)
**Requirements**: INST-01, INST-01a, INST-01b, INST-01c, INST-01d, INST-01e, INST-01f, INST-01g, INST-01h, WORK-05, WORK-06
**Success Criteria** (what must be TRUE):
  1. `php artisan tinker` can call `InstallTaskGeneratorService::generate($programme)` and create task records grouped by room
  2. `install_programmes` and `install_tasks` tables exist with all columns defined in REQUIREMENTS.md
  3. Generating tasks for a project with 3 rooms and 5 equipment items produces ≥ 3 task records (one per room × equipment combination)
  4. A confirm gate UI exists: generated tasks are shown for PM review before programme is activated
  5. Worksheet DOCX for a project with pre-install answers includes those answers in the generated file
  6. Worksheet generation button appears on project dashboard and dispatches the job
**Plans**: 3 plans

Plans:
- [x] 12-01-PLAN.md — Migrations (install_programmes, install_tasks) + InstallProgramme/InstallTask models + Project relationships
- [x] 12-02-PLAN.md — InstallTaskGeneratorService + InstallProgrammeService + Controller + Routes + Review UI
- [x] 12-03-PLAN.md — WorksheetDocxService section E (WORK-05: pre-install answers) + WORK-06 verification

### Phase 13: Task Assignment & Scheduling
**Goal**: Engineers can be assigned to tasks and dates set; programme is viewable as a week-grouped table. For projects spanning > 4 days, an interactive Gantt timeline (frappe-gantt) is shown.
**Depends on**: Phase 12
**Requirements**: INST-02, INST-02a, INST-02b, INST-02c, INST-02d, INST-02e, INST-02f, INST-02g
**Success Criteria** (what must be TRUE):
  1. `install_tasks.assigned_to` column (existing FK to users.id) satisfies INST-02a; `planned_start_date` + `planned_end_date` date columns added to install_tasks
  2. Bulk assignment routes assign all tasks in a room or entire programme to a selected engineer in one action
  3. Week-view calendar groups tasks by planned week; each task shows assigned engineer name colour-coded by user ID modulo 8
  4. When programme `planned_end_date - planned_start_date > 4 days`, the Gantt view renders via frappe-gantt
  5. When project duration ≤ 4 days, Gantt is not shown; week-table is shown instead
  6. Field engineers see only their assigned tasks on the schedule page; PM role (project owner / admin) sees all tasks
**Plans**: 2 plans

Plans:
- [x] 13-01-PLAN.md — Migration (per-task planned dates) + TaskAssignmentService + TaskAssignmentController + 3 assignment routes
- [x] 13-02-PLAN.md — frappe-gantt install + schedule.blade.php (week-view + conditional Gantt + Alpine panel) + schedule() action + INST-02g filter

### Phase 14: Mobile Field View
**Goal**: Mobile-responsive field page where engineers tick tasks complete, capture per-task photos, and clock in/out. HEIC photos are silently converted server-side.
**Depends on**: Phase 12
**Requirements**: INST-03, INST-03a, INST-03b, INST-03c, INST-03d, INST-03e, INST-03f, INST-03g, INST-03h
**Success Criteria** (what must be TRUE):
  1. `/projects/{project}/programme` route renders on a 375px viewport without horizontal scroll
  2. Tapping a task status updates it via AJAX with no page reload; success shown visually
  3. Uploading a HEIC photo from iOS is stored as JPEG in `storage/app/private/task-photos/`
  4. Room-level progress counter updates when tasks are completed
  5. Clock in/out controls appear on the field page
**Plans**: TBD

### Phase 15: Time Tracking
**Goal**: Engineers clock in/out per project with category selection. Open sessions are protected by server heartbeat; stale sessions auto-closed by scheduled command. Actual hours visible on project dashboard.
**Depends on**: Phase 12
**Requirements**: INST-04, INST-04a, INST-04b, INST-04c, INST-04d, INST-04e, INST-04f, INST-04g, INST-04h, INST-04i
**Success Criteria** (what must be TRUE):
  1. `time_entries` table has columns: `id`, `project_id`, `user_id`, `category`, `clocked_in_at`, `clocked_out_at`, `last_heartbeat_at`, `notes`
  2. Clock in creates a row with `clocked_out_at = null`; second clock-in is rejected with an error
  3. `php artisan programme:close-stale-sessions` closes entries where `last_heartbeat_at` is older than 2 hours
  4. All `clocked_in_at`/`clocked_out_at` values are stored as UTC in database
  5. Project dashboard shows total actual hours and per-category breakdown
**Plans**: TBD

### Phase 16: Commissioning Checklist & Sign-off
**Goal**: Per-equipment commissioning checklist with AVIXA categories, per-item photo evidence, and client digital signature. Completing the checklist generates a snagging PDF and advances project to Commissioning state.
**Depends on**: Phase 14
**Requirements**: INST-05, INST-05a, INST-05b, INST-05c, INST-05d, INST-05e, INST-05f, INST-05g, INST-05h, INST-05i
**Success Criteria** (what must be TRUE):
  1. `commissioning_items` table has all columns from REQUIREMENTS.md
  2. Each item status update is saved via a separate AJAX request (no full-form POST)
  3. Uploading a HEIC photo for a commissioning item stores it as JPEG
  4. Client signature canvas renders at correct DPI on iOS Retina (devicePixelRatio scaling applied)
  5. "Complete Commissioning" button is disabled until all items are pass/fail/na
  6. Generating the snagging PDF produces a downloadable file embedding the signature image
  7. On programme completion, `Project.status` advances to `STATUS_COMMISSIONING` via state machine
**Plans**: TBD

---

### v1.3 Technical Drawings & Schematics
*"AI-powered visuals from the same dataset"*

- [ ] Phase 17: System Schematics — Auto-generate signal flow diagrams from equipment and cable schedule data
- [ ] Phase 18: Rack Elevations — Generate rack layouts from equipment lists with U-height and ventilation data
- [ ] Phase 19: Floor Plans — Upload building layout, auto-place equipment per room with logical positioning
- [ ] Phase 20: Drawing Export — PDF immediate download, DWG export for CAD tools (AutoCAD/Vectorworks)

### v1.4 Client Portal & Project Visibility
*"Clients see what they need, when they need it"*

- [ ] Phase 21: Client Portal — Branded project status page per client/site with secure access
- [ ] Phase 22: Document Access — Clients download RAMS, O&M, drawings and certificates from portal
- [ ] Phase 23: Survey & Installation Progress — Live completion percentages per room visible to client
- [ ] Phase 24: Notification & Communication — Client receives updates on project milestones and document availability

### v1.5 Financial & Proposal Engine
*"From pricing rules to signed proposal"*

- [ ] Phase 25: Pricing Engine — Multiplier-based config (HW value x multiplier with min/max), admin+sales accessible
- [ ] Phase 26: Proposal Generator — New client + renewal flows, PDF/DOCX branded output
- [ ] Phase 27: Budget Tracking — Project cost monitoring, margin alerts, forecast vs actual
- [ ] Phase 28: Renewal Workflow — Auto-populate from existing contract hardware, year-on-year escalation

### v1.6 Service & Inventory
*"Post-install lifecycle"*

- [ ] Phase 29: Asset Registry — Track installed equipment as live assets with QR codes per item
- [ ] Phase 30: Service Tickets — Contract search, room/asset select, auto-fill site/contact, callback scheduling
- [ ] Phase 31: PMV Checklists — Per-equipment-type maintenance checks with fault diagnosis and sign-off
- [ ] Phase 32: AI Troubleshooting — QR scan triggers AI-guided device-specific troubleshooting workflow

## Progress

| Phase | Milestone | Plans | Status | Completed |
|-------|-----------|-------|--------|-----------|
| 01. Project Layer & Data Foundation | v1.0 | 6/6 | Complete | 2026-04-10 |
| 02. QuoteWerks SQL Import | v1.0 | 3/3 | Complete | 2026-04-10 |
| 03. Survey Data Integration | v1.0 | 4/4 | Complete | 2026-04-11 |
| 04. Document Generators | v1.0 | 4/4 | Complete | 2026-04-11 |
| 05. Project Content Pack | v1.0 | 4/4 | Complete | 2026-04-11 |
| 06. RAMS Document Quality | v1.0 | 2/2 | Complete | 2026-04-12 |
| 07. Dynamic Survey AI Questions | v1.0 | 6/6 | Complete | 2026-04-12 |
| 08. Enterprise Dashboard | v1.1 | 2/2 | Complete    | 2026-04-19 |
| 09. Email Notifications | v1.1 | — | Planned | — |
| 10. Document Quality Scores | v1.1 | — | Planned | — |
| 11. Bitrix24 Integration | v1.1 | — | Planned | — |
| 12. Install Task Generation + Worksheet Enhancements | v1.2 | 3/3 | Complete   | 2026-04-13 |
| 13. Task Assignment & Scheduling | v1.2 | 3/3 | Complete   | 2026-04-14 |
| 14. Mobile Field View & Time Tracking | v1.2 | — | Planned | — |
| 15. Time Tracking | v1.2 | — | Planned | — |
| 16. Commissioning Checklist & Sign-off | v1.2 | — | Planned | — |
| 17. System Schematics | v1.3 | — | Planned | — |
| 18. Rack Elevations | v1.3 | — | Planned | — |
| 19. Floor Plans | v1.3 | — | Planned | — |
| 20. Drawing Export | v1.3 | — | Planned | — |
| 21. Client Portal | v1.4 | — | Planned | — |
| 22. Document Access | v1.4 | — | Planned | — |
| 23. Survey & Installation Progress | v1.4 | — | Planned | — |
| 24. Notification & Communication | v1.4 | — | Planned | — |
| 25. Pricing Engine | v1.5 | — | Planned | — |
| 26. Proposal Generator | v1.5 | — | Planned | — |
| 27. Budget Tracking | v1.5 | — | Planned | — |
| 28. Renewal Workflow | v1.5 | — | Planned | — |
| 29. Asset Registry | v1.6 | — | Planned | — |
| 30. Service Tickets | v1.6 | — | Planned | — |
| 31. PMV Checklists | v1.6 | — | Planned | — |
| 32. AI Troubleshooting | v1.6 | — | Planned | — |
