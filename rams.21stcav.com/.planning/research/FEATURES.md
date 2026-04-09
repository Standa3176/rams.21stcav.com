# Feature Landscape

**Domain:** AV installation operations platform (single-company, field-to-handover lifecycle)
**Researched:** 2026-04-09
**Confidence:** HIGH — grounded in codebase archaeology + AV/field-service domain knowledge. Web search unavailable; findings derived from what the system already does, what operators in this sector require, and standard field service / document generation norms.

---

## Context: What Already Exists

The platform already has a solid foundation. Research was conducted by reading the codebase directly. The following is already built and working:

- RAMS pipeline end-to-end (PDF extract → AI method statement → DOCX generation)
- Project model with 8-state lifecycle state machine
- Site survey system with per-room data capture and photo upload
- Public token-based survey access (UUID links, no login required)
- ProjectPackage as quote container (extracted_data + equipment_list + cable_list)
- Cable schedule XLSX generation (with PhpSpreadsheet, column schema: Cable ID / From / To / Type / Cores / Length / Notes)
- O&M manual service scaffolded (two-pass: extract from PDF → AI generate content)
- AI caching and usage tracking
- Projects index with status filter tabs and search

The gap this milestone closes: **none of these documents share a data source**. Each generator reads its own upstream independently. There is no `ProjectDataService`, no merged canonical dataset, and no direct QuoteWerks SQL path.

---

## Table Stakes

Features users (field engineers and project managers at an AV installation company) expect. Missing = product feels incomplete or untrustworthy.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Project dashboard showing all related records | Engineers need one place to see RAMS, survey, O&M, cable schedule status at a glance | Low | Structure exists; cards for each document type need status indicators |
| Lifecycle state machine with explicit transitions | PM must know where a job is; no ambiguity about "is the survey done?" | Low | Already built in Project model; UI transition button exists |
| Quote import that produces a reviewed equipment list | Every document downstream starts here; without it there's nothing to generate | Medium | PDF path exists; SQL path (QuoteWerks) is the gap |
| Site survey with per-room data capture | Field engineers capture real conditions; dimensions, ceiling type, cable routes, constraints | Medium | Already built (SiteSurveyRoom has 40+ fields); gap is feeding this into generators |
| Survey completion progress tracking per room | Engineers doing multi-room surveys need to know which rooms are done | Low | Already built (is_completed / completed_at on SiteSurveyRoom) |
| RAMS document generation from project data | UK regulatory requirement for construction/installation work; cannot be missing | High | Already works; gap is migrating it to consume ProjectDataService |
| Worksheet generation per room | Field engineers on-site need room-by-room install steps and equipment lists | Medium | Currently missing; depends on unified data model |
| O&M manual generation from installed equipment | Client handover document; any professional AV company delivers one | High | Service scaffolded but uses AI to generate from PDF, not structured project data |
| Cable schedule as XLSX | Engineers use spreadsheets in the field; DOCX cable schedules are not field-usable | Low | Already built; gap is populating from structured data not AI PDF scan |
| Document download access from project page | Generated docs must be reachable in one click from the project | Low | Partially built; project show page has RAMS section; O&M/cable need parity |
| Quote version history | Quotes get revised; engineers must know which version drove which document | Low | Already built (ProjectQuote model, version_number, history table in show.blade) |
| Data confidence / source tracking | Engineers must know if a field came from PDF, QuoteWerks SQL, or manual review | Low | Planned (DATA-04); critical for trust in generated documents |
| Structured review step before generation | "AI generated, engineer reviewed" — prevents garbage-in-garbage-out documents | Medium | Already built for RAMS (review form); must apply to all generators |
| External survey access via token (no login) | Clients or subcontractors fill surveys; they don't have accounts | Low | Already built; expiry logic exists |
| Activity log per project | Audit trail — who did what and when | Low | Already built (ProjectActivityLog) |

---

## Differentiators

Features that set this platform apart from generic field service tools. Not universally expected, but deliver real competitive value for 21st Century AV specifically.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Single unified data model feeding all generators | One correction (e.g. a room name change) propagates to RAMS, worksheet, O&M, and cable schedule simultaneously | High | The core DATA-01 to DATA-04 work; no commercial AV tool does this well |
| QuoteWerks SQL import (not just PDF) | Eliminates re-keying of quote data; SQL gives structured room/group/line-item data that PDF parsing approximates | Medium | Direct read-only SQL connection via VPN; maps to same extracted_data structure as PDF |
| AI-structured method statements (not AI-invented scope) | AI formats and phrases, but scope comes only from quote and survey data; eliminates invented content risk | High | Already built; constraint must be preserved as new generators are added |
| Cable schedule derived from equipment relationships | Cable schedule built from equipment-to-equipment relationships and survey cable routes, not guessed from a PDF | High | Requires equipment relationship data in unified model; currently cable service uses AI on PDF |
| Room-as-first-class-citizen data model | Survey captures room dimensions, ceiling type, mount constraints — this feeds worksheet constraints automatically | Medium | Room data already rich; gap is wiring it into worksheet and cable generators |
| Data source and confidence annotation per field | Engineers can see "this came from QuoteWerks SQL (high confidence)" vs "OCR extracted (low confidence)" | Low | Small implementation; high trust payoff |
| Token-based survey delegation | Subcontractors or clients fill parts of the survey with no account — submitted data flows directly into project | Low | Already built; differentiates from tools that require everyone to have logins |
| Draft-save with timestamps on surveys | Field engineers get interrupted; save-and-resume is expected but uncommon in form-based tools | Low | Status field + submitted_at timestamps already exist |
| Worksheet output as DOCX (not just PDF) | Engineers can edit worksheets on-site if plans change; editable format preferred over read-only PDF | Low | PHPWord already in stack; follows RAMS pattern |
| O&M content driven by installed equipment only | No boilerplate filler — every section maps to an actual system in the room | High | Requires structured equipment data from unified model; current implementation uses AI on PDF |

---

## Anti-Features

Features to deliberately NOT build for this milestone (and why).

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Mobile native app | Platform is single-company; responsive web is sufficient for survey forms on tablets | Keep site survey as a mobile-responsive web form; focus build effort on data quality |
| Real-time multi-user collaboration on surveys or reviews | Adds significant complexity (websockets, conflict resolution, locking); one engineer per survey is the real-world pattern | Single-user editing per session is correct; lock a survey during active edit if needed |
| Client portal (beyond survey token links) | Clients don't need to browse project history; they receive deliverables at handover | Deliver documents by export/email; token links for surveys only |
| Automated RAMS or worksheet content invention via AI | Regulatory and liability risk; if AI invents a step that isn't done, it creates a safety incident | AI is only allowed for formatting and phrasing structured data that was sourced from quote or survey |
| In-app DOCX/XLSX editor | Editing documents inside the web app is a product unto itself | Generate, download, edit locally; re-generate if source data changes |
| Bi-directional QuoteWerks sync (write back) | Write access to the live QuoteWerks database creates data integrity risk | Read-only SQL connection; never write to QuoteWerks from this platform |
| Multi-tenancy / multi-company | Single-company platform; multi-tenancy adds auth complexity with no current benefit | Single-tenant design throughout; revisit only if scope changes |
| Project management / scheduling (Gantt, resource allocation) | Separate discipline; tools like MS Project exist for this | Lifecycle state machine is sufficient; don't try to replace project scheduling software |
| Email notification system | Adds infrastructure complexity (queues, mail driver config, bounce handling) | Out of scope for this milestone; deferred |
| Full-text search across document content | Elasticsearch-level problem; not needed at current scale | Search project name/client/ref is sufficient (already built in projects index) |
| Asset register as a managed entity separate from equipment list | AV companies use the O&M manual asset register; a separate asset database is overkill at this scale | Asset register is a section within the O&M output, not a standalone module |

---

## Feature Dependencies

```
QuoteWerks SQL import (QWSQL-01..05)
  └── maps to extracted_data (same as PDF import)
         └── ProjectDataService (DATA-01..04)
                ├── RAMS generator (RAMS-01)  [already exists, needs refactor]
                ├── Worksheet generator (WORK-01..02)  [new]
                ├── O&M Manual generator (OM-01..02)   [partial, needs refactor]
                └── Cable Schedule generator (CABLE-01..02)  [partial, needs refactor]

Site Survey enhancements (SURV-01..05)
  └── survey_data flows into ProjectDataService
         ├── Worksheet (room-by-room constraints, cable routes)
         ├── O&M (room descriptions, installed equipment per room)
         └── Cable Schedule (cable routes from survey)

Project layer (PROJ-01..04)
  └── project_id links all records
         └── Project dashboard (PROJ-03) reads from all linked models

Data confidence tracking (DATA-04)
  └── requires ProjectDataService to be built first
```

**Hard dependency order:**
1. Project layer (PROJ-01..04) must exist before anything is linked to it — already largely done
2. ProjectDataService (DATA-01..04) must be built before any generator can be refactored to consume it
3. Survey enhancements (SURV-01..05) should be delivered alongside or before generator refactoring, so survey data is available when generators are tested
4. QuoteWerks SQL import (QWSQL-01..05) can be developed in parallel with ProjectDataService since it maps to an existing interface
5. Document generators (RAMS-01, WORK-01..02, OM-01..02, CABLE-01..02) are last — they consume the unified model

---

## MVP Recommendation

For this milestone, the minimum viable set that delivers the core value proposition ("one dataset, every document"):

**Must ship:**
1. `ProjectDataService` — the merger/normaliser; everything else depends on this
2. QuoteWerks SQL import — structured data beats PDF parsing every time; this is the data quality foundation
3. Worksheet generator — most immediately useful to field engineers; room-by-room, no AI invention
4. Cable Schedule refactor — move from AI-on-PDF to structured data; highest accuracy gain for least effort
5. O&M Manual refactor — consume ProjectDataService + survey data instead of AI-on-PDF; remove the boilerplate-generation risk
6. Survey data flowing into ProjectDataService — surveys are already rich; wiring them in is the missing link

**Defer:**
- Data confidence UI (DATA-04 display layer): implement the data structure, show the annotation in the review screen, but don't invest in a full confidence dashboard
- Survey token expiry management UI: the expiry logic exists; a management screen can wait
- Project dashboard document status cards: functional linking takes priority over polished status indicators

---

## Phase-Specific Feature Notes

| Phase Topic | Feature Behaviour Expected | Common Mistake |
|-------------|---------------------------|----------------|
| ProjectDataService | Must be deterministic — same input always produces same output; no AI calls inside it | Letting each generator call AI independently to fill gaps rather than making data collection explicit |
| QuoteWerks SQL | Map to extracted_data exactly, including all keys the existing review form expects | Assuming SQL data is "better" and skipping the review step — review is a safety gate, always required |
| Worksheet generation | Room-ordered, one section per room, equipment list + install steps + constraints; derived from survey + equipment data | Generating generic steps not tied to specific rooms or equipment from the quote |
| O&M Manual | Sections: system overview, room-by-room equipment, operating procedures per system type, maintenance schedule, warranty summary, fault-finding guide | Using AI to invent maintenance intervals that aren't tied to specific manufacturer equipment |
| Cable Schedule | Columns: Cable ID, From Location, To Location, Cable Type, Cores, Length (m), Route Notes — already implemented in XlsxService; gap is populating from structured data | Adding a cable to the schedule that wasn't in the equipment relationships or survey |
| Site Survey enhancements | External user form must be mobile-friendly; progress tracking (N of M rooms complete) already exists and must be maintained | Breaking the is_completed / completed_at tracking when adding new room fields |

---

## Sources

- Codebase direct analysis: `app/Models/`, `app/Services/`, `resources/views/` (HIGH confidence)
- `PROJECT.md` requirements specification (HIGH confidence)
- AV installation industry standard deliverables (RAMS, O&M, Cable Schedules, Worksheets) — domain knowledge (MEDIUM confidence; web search unavailable to verify against current industry body publications)
- UK CDM 2015 / H&S regulatory context for RAMS requirements — domain knowledge (MEDIUM confidence)
