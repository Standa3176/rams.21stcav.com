# RAMS Platform — AV Operations System

## What This Is

An internal operations platform for 21st Century AV Ltd that manages the full lifecycle of AV installation projects. Starting from a quoted job (PDF or QuoteWerks SQL), it flows through site survey, AI-assisted engineering review, and generates all compliance and technical documents (RAMS, Worksheets, O&M Manuals, Cable Schedules) from a single unified dataset. No duplicated data, no AI guessing — every output is driven by structured project data.

## Core Value

One dataset powers every document. Engineers capture real-world data, quotes provide equipment scope, and all outputs are generated with zero guesswork from that shared truth.

## Requirements

### Validated (v1.0)

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
- ✓ **PROJ-01–05**: `projects` table as top-level entity with lifecycle state machine, quote reference versioning, client/site linking — v1.0
- ✓ **DATA-01–03, DATA-05**: `ProjectDataService` 4-tier canonical merge (reviewed_data > survey_data > quotewerks_sql > extracted_data) powering all generators — v1.0
- ✓ **QWSQL-01–07**: QuoteWerks SQL import pipeline — named DB connection, QuoteWerksRepository, import service, health check command — v1.0
- ✓ **SURV-01–05**: Survey data integrated into ProjectDataService, global site data captured, submission timestamps, external token access — v1.0
- ✓ **WORK-01–04**: Worksheet DOCX generator, room-by-room install steps, queue-based — v1.0
- ✓ **OM-01–04**: O&M Manual DOCX generator, equipment-driven, queue-based — v1.0
- ✓ **CABLE-01–04**: Cable Schedule XLSX generator, queue-based — v1.0
- ✓ **RAMS-01–03**: RAMS generator consuming ProjectDataService via content pack, AI for method statements only — v1.0
- ✓ AI content pack — single Claude call generates room scope narratives for RAMS enrichment — v1.0
- ✓ Dynamic AI-generated pre-install check questions per survey room (Phase 07) — v1.0

## Current Milestone: v1.2 Installation Programme & Field Management

**Goal:** Transform the platform from document generator into a live installation delivery system — auto-generated task lists from project data, engineer assignment, mobile-responsive field view, time tracking, and commissioning sign-off.

**Target features:**
- Auto-generate install task lists (room × equipment driven)
- Task assignment to engineers with calendar view
- Mobile-responsive field view — checklist, clock in/out, photo capture
- Time tracking — budget vs actual per project/category
- Commissioning checklist — per-equipment sign-off, photo evidence, client signature
- Worksheet enhancements — pre-install answers included, triggered from dashboard

---

### Active

#### v1.1 — Operations Dashboard & Notifications (Phases 08–09; SHIPPED 2026-04-25)

*Originally scoped as Phases 08–11; only 08 and 09 were executed. Phases 10 (Bitrix24/CRM push) and 11 (multi-channel notifications + quality scoring) were never built and are deferred. See `milestones/v1.1-ROADMAP.md`.*

**Shipped:**
- [x] **DASH-01**: Real-time project status dashboard with health indicators across all active projects *(validated in Phase 08 — red/amber/green priority rules, overdue indicator, status-summary chips, install-programme widget, Alpine.js client filter)*
- [x] **NOTF-01**: Email notification when document generation completes *(validated in Phase 09 — 4 typed *ReadyMail × idempotency timestamps × DocumentArtifactStorage attachments)*
- [x] **NOTF-02**: Email notification when external survey is submitted *(validated in Phase 09 — existing path refactored to NotificationRecipientResolver, latent is_admin / Project::with('user') bugs fixed)*
- [x] **NOTF-03**: RAMS review-needed email dispatched after extraction *(added during v1.1 — RamsReviewNeededMail wired into ExtractRamsDraftJob)*
- [x] **NOTF-04**: Document generation failure alert to admins *(added during v1.1 — DocumentGenerationFailedMail from each Build*Job::failed())*
- [x] **NOTF-05**: Notification transport, recipients, and operational guarantees *(added during v1.1 — NotificationRecipientResolver, role-based admin lookup, Postmark transport packaged, RAMS_NOTIFICATION_BCC, ShouldQueue + try/catch defensive pattern)*

**Deferred to a future milestone (originally scoped as Phases 10/11, never built):**
- [ ] **DASH-02**: Overdue survey, generation pending, and blocked project alerts *(partially covered by DASH-01 overdue indicator; remaining work is dedicated alert UI)*
- [ ] **DASH-03**: Admin view of AI usage, token consumption, and generation costs
- [ ] **QUAL-01**: RAMS document quality score shown to engineer before download
- [ ] **QUAL-02**: Data confidence indicators per room/equipment (surface DATA-04 partial implementation)
- [ ] **BIT-01**: OAuth 2.0 connection to Bitrix24 workspace
- [ ] **BIT-02**: Project creation in Bitrix24 on RAMS project creation
- [ ] **BIT-03**: Document links pushed to Bitrix24 deal/task on generation
- [ ] **BIT-04**: Survey submission triggers Bitrix24 task update

**Operational debt (post-ship):**
- [ ] **NOTF-05g**: Production Postmark cutover — DNS records (SPF/DKIM/DMARC), `POSTMARK_API_KEY` in production `.env`, sender signature verification, first-send confirmation. Runbook at `milestones/v1.1-phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`

#### v1.2 — Installation Programme & Field Management (Phases 12–16; SHIPPED 2026-04-25)
- [x] **INST-01**: Auto-generate install task lists from project data (room x equipment driven) *(validated in Phase 12 — InstallTaskGeneratorService reads from ProjectDataService, PM confirm gate, re-generation archives prior programme)*
- [x] **INST-02**: Task assignment to engineers with calendar view and Gantt timeline *(validated in Phase 13 — bulk assignment, conditional Gantt at >4 days, engineer-only filter)*
- [x] **INST-03**: Responsive mobile field view — task checklist, clock in/out, photo capture *(validated in Phase 14 — `/projects/{project}/programme`, HEIC server-side conversion, AJAX status/notes saves, online-only)*
- [x] **INST-04**: Time tracking per project/category with heartbeat-guarded sessions and dashboard actual hours *(validated in Phase 15 — actuals-only, budget comparison deferred)*
- [x] **INST-05**: Commissioning checklist — per-equipment sign-off with photo evidence and client signature *(validated in Phase 16 — INST-05a through INST-05i all satisfied, iOS Retina DPI signature capture human-verified)*
- [x] **WORK-05**: Worksheet includes pre-install check question answers per room *(validated in Phase 12 — Section E renders SiteSurveyRoomQuestion answers via WorksheetDocxService)*
- [x] **WORK-06**: Worksheet generation triggered from project dashboard *(validated in Phase 12 — `worksheets.generate-from-project` route + ProjectController button)*

**Outstanding human UAT (deployment-gated, not implementation gaps):**
- [ ] Phase 13: Gantt rendering + slide-over click + role-based filter — frappe-gantt is bundled but browser-based confirmation pending
- [ ] Phase 14: iOS HEIC end-to-end upload — blocked on deploying app to a web-accessible environment for real-iPhone test

#### v1.3 — Technical Drawings & Schematics (Phases 17–20)
- [ ] **DRAW-01**: AI-generated system schematics from equipment and cable schedule data
- [ ] **DRAW-02**: Rack elevation generation from equipment lists with U-height and ventilation data
- [ ] **DRAW-03**: Floor plan upload with auto-placed equipment per room
- [ ] **DRAW-04**: Drawing export — PDF immediate, DWG for CAD tools (AutoCAD/Vectorworks)

#### v1.4 — Client Portal & Project Visibility (Phases 21–24)
- [ ] **PORT-01**: Branded client portal per project/site with secure access
- [ ] **PORT-02**: Client document access — RAMS, O&M, drawings, certificates
- [ ] **PORT-03**: Live survey and installation completion progress visible to client
- [ ] **PORT-04**: Client notification on project milestones and document availability

#### v1.5 — Financial & Proposal Engine (Phases 25–28)
- [ ] **FIN-01**: Multiplier-based pricing engine (HW value x multiplier, min/max), admin+sales accessible
- [ ] **FIN-02**: Proposal generator — new client + renewal flows, PDF/DOCX branded output
- [ ] **FIN-03**: Project budget tracking — cost monitoring, margin alerts, forecast vs actual
- [ ] **FIN-04**: Renewal workflow — auto-populate from existing contract hardware, year-on-year escalation

#### v1.6 — Service & Inventory (Phases 29–32)
- [ ] **SVC-01**: Asset registry — installed equipment as live assets with QR codes
- [ ] **SVC-02**: Service tickets — contract search, room/asset select, auto-fill, callback scheduling
- [ ] **SVC-03**: PMV checklists — per-equipment-type maintenance checks with fault diagnosis and sign-off
- [ ] **SVC-04**: AI troubleshooting — QR scan triggers AI-guided device-specific troubleshooting

### Out of Scope

- AI-invented scope/equipment/design — regulatory and liability risk; AI formats only
- In-app DOCX/XLSX editor — generate, download, edit locally; re-generate if source changes
- Bi-directional QuoteWerks sync — read-only SQL, never write back
- Multi-tenancy — single-company platform for 21st Century AV
- Full-text search across documents — project name/client/ref search is sufficient at current scale
- Native mobile app — responsive web sufficient; mobile field view (v1.2) covers field use
- Real-time multi-user collaboration — single-user editing per session matches workflow
- Payment processing — invoicing/payments handled externally by accounts team

## Context

- **Shipped v1.0:** 7 phases, 29 plans — full RAMS pipeline from quote import through document generation
- **Shipped v1.1:** 2 phases (08, 09), 9 plans — real-time project health dashboard + 6 queued email mailables with idempotency-first dispatch (Phases 10/11 deferred — Bitrix24 + multi-channel + quality scoring rolled to a future milestone)
- **Shipped v1.2:** 5 phases (12–16), 21 plans, 281 commits in dev range — full installation delivery loop from auto-generated task list → mobile field view → time tracking → commissioning sign-off with snagging PDF
- **Codebase:** Laravel 12, PHP 8.2+, MySQL, Blade/Tailwind/Alpine.js, ~580+ commits across v1.0/v1.1/v1.2
- **AI stack:** Claude (default) + OpenAI via AIManager abstraction — structured JSON only, cached
- **Document generation:** PHPWord (DOCX worksheets/RAMS/O&M), PhpSpreadsheet (XLSX cable schedules), DomPDF/mPDF (PDF), `creagia/laravel-sign-pad` (client signature)
- **Notification stack (v1.1):** 6 `ShouldQueue` mailables, Postmark transport packaged, `NotificationRecipientResolver` single source of truth, `RAMS_NOTIFICATION_BCC` global BCC, idempotency timestamps set BEFORE send
- **Field stack (v1.2):** `/projects/{project}/programme` mobile route, `HeicImageConverter::writeAsJpeg()` server-side HEIC conversion, `TimeEntryService` with 60s heartbeat + 2-hour stale-close cron, `commissioning_items` per AVIXA category with DPI-correct signature canvas, snagging PDF via `DocumentArtifactStorage::TYPE_SNAGGING`
- **Current state post-v1.2:** Document generators + survey system + dashboard + notifications + full installation/commissioning loop all live; Postmark production cutover gated by runbook
- **Tech debt:** DATA-04 confidence scoring partial; Phase 07 RED test stubs not greened; Phase 09 REVIEW WR-01..WR-04 polish; Phase 15 REVIEW WR-01 (TOCTOU race in stale-close) + WR-02 + IN-01..IN-06 cosmetics; VALIDATION.md `wave_0_complete: false` for phases 08, 09, 12, 13, 15
- **Outstanding human UAT (deployment-gated):** Phase 13 Gantt browser confirmation, Phase 14 iOS HEIC end-to-end (both blocked on deploying to a web-accessible environment)
- **Next milestone focus:** v1.3 Technical Drawings & Schematics (Phases 17–20) — AI-generated system schematics, rack elevations, floor plans, DWG/PDF export

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
| `projects` table separate from `project_packages` | Projects are top-level entity; packages are one input source | ✓ Good — clean separation in use |
| ProjectDataService 4-tier merge (reviewed > survey > sql > extracted > defaults) | Single canonical data point prevents each generator resolving independently | ✓ Good — all generators use it |
| Direct SQL connection to QuoteWerks (not API) | VPN available, simpler than building intermediary service | ✓ Good — working pipeline |
| Token-only access for external survey users | Simplicity; no account management overhead for one-off surveys | ✓ Good — UUID tokens working |
| Cable schedules as XLSX, everything else DOCX | Engineers need cable data in spreadsheet format for field use | ✓ Good |
| AI content pack via single Claude call per project | Avoids per-room AI calls; generates all room narratives in one structured response | ✓ Good — fast and cost-efficient |
| AI questions dispatched async via Job on survey create | Non-blocking; survey creation succeeds even if AI call fails | ✓ Good — silent failure pattern working |
| SurveyQuestionsPrompt temperature 0.2 | Consistent, practical questions — not creative responses | ✓ Good |
| Bitrix24 integration | CRM/task management link for project delivery visibility | — Planned for v1.1 |

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
*Last updated: 2026-04-25 after v1.2 (Installation Programme & Field Management) milestone archive — 5 phases shipped covering install task generation, scheduling, mobile field view, time tracking, and commissioning sign-off. Three milestones live (v1.0, v1.1, v1.2); v1.3 Technical Drawings & Schematics is next planned.*
