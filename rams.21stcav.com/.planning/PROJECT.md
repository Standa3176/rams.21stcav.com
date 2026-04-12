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

### Active

#### Enterprise Dashboard
- [ ] **DASH-01**: Real-time project status dashboard with health indicators across all active projects
- [ ] **DASH-02**: Overdue survey, generation pending, and blocked project alerts
- [ ] **DASH-03**: Admin view of AI usage, token consumption, and generation costs

#### Document Quality & Notifications
- [ ] **NOTF-01**: Email notification when document generation completes
- [ ] **NOTF-02**: Email notification when external survey is submitted
- [ ] **QUAL-01**: RAMS document quality score shown to engineer before download
- [ ] **QUAL-02**: Data confidence indicators per room/equipment (surface DATA-04 partial implementation)

#### Worksheet Generator Enhancements
- [ ] **WORK-05**: Worksheet includes pre-install check question answers per room
- [ ] **WORK-06**: Worksheet generation triggered from project dashboard (not just manual API)

#### Installation & Programme
- [ ] **PROG-01**: Installation task list generated from project data (room-by-room, equipment-driven)
- [ ] **PROG-01**: Programme of works view showing install sequence

#### Bitrix24 Integration
- [ ] **BIT-01**: OAuth 2.0 connection to Bitrix24 workspace
- [ ] **BIT-02**: Project creation in Bitrix24 on RAMS project creation
- [ ] **BIT-03**: Document links pushed to Bitrix24 deal/task on generation
- [ ] **BIT-04**: Survey submission triggers Bitrix24 task update

### Out of Scope

- Mobile native app — responsive web sufficient for survey forms on tablets
- Real-time collaboration — single-user editing per session matches workflow
- Client portal — external users access surveys via token links only
- AI-invented scope/equipment/design — regulatory and liability risk; AI formats only
- In-app DOCX/XLSX editor — generate, download, edit locally; re-generate if source changes
- Bi-directional QuoteWerks sync — read-only SQL, never write back
- Multi-tenancy — single-company platform for 21st Century AV
- Project scheduling (Gantt) — lifecycle state machine is sufficient
- Full-text search across documents — project name/client/ref search is sufficient at current scale

## Context

- **Shipped v1.0:** 7 phases, 29 plans — full RAMS pipeline from quote import through document generation
- **Codebase:** Laravel 12, PHP 8.2+, MySQL, Blade/Tailwind/Alpine.js, ~212 commits
- **AI stack:** Claude (default) + OpenAI via AIManager abstraction — structured JSON only, cached
- **Document generation:** PHPWord (DOCX worksheets/RAMS/O&M), PhpSpreadsheet (XLSX cable schedules), DomPDF/mPDF (PDF)
- **Current state post-v1.0:** All core document generators shipped, site survey system fully operational with AI pre-install questions, QuoteWerks SQL import working, content pack enriching RAMS quality
- **Tech debt:** DATA-04 confidence scoring (per-field source annotation) partially implemented — designed in ProjectDataService but no UI surface yet; Phase 07 RED test stubs not greened
- **Next milestone focus:** Enterprise-grade dashboard, notifications, Bitrix24 integration, installation programme

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
*Last updated: 2026-04-12 after v1.0 milestone*
