# Feature Landscape: v1.2 Installation Programme & Field Management

**Domain:** AV installation delivery — task management, field operations, time tracking, commissioning
**Researched:** 2026-04-13
**Confidence:** HIGH for table stakes and AV commissioning patterns (AVIXA standards, Jetbuilt verified); MEDIUM for complexity estimates (based on stack knowledge, no experimental build yet)
**Platform context:** Adding onto existing RAMS platform that already has project lifecycle, site survey per-room data, equipment lists per room (reviewed_data), and document generators. The existing data model is the foundation for all v1.2 features.

---

## Context: What Already Exists (Do Not Re-Research)

- Project lifecycle state machine: `quote_imported` → `survey_pending` → `engineering` → `installing` → `commissioning` → `handover` → `completed` → `archived`
- Site survey with per-room data, pre-install questions per room (AI-generated), photo upload
- `reviewed_data` with room_overviews and equipment list per room (canonical source of truth)
- `ProjectDataService` 4-tier data merge (reviewed > survey > sql > extracted)
- Queue-based async document generation
- User model with auth (Breeze), role-based (admin/user)
- Existing generators: RAMS, Worksheets, O&M Manuals, Cable Schedules

---

## Table Stakes

Features that AV field management platforms (Jetbuilt Install, XTEN-AV X-Pro, D-Tools SI) all provide and that engineers treat as baseline expectations. Missing = product feels incomplete.

| Feature | Why Expected | Complexity | Dependency on Existing Data |
|---------|--------------|------------|-----------------------------|
| Auto-generate task list from equipment | Engineers should not create tasks manually — Jetbuilt generates tasks from labor line items; XTEN-AV links tasks to equipment items. Manual task creation per-project is unacceptable overhead | Medium | `reviewed_data` room_overviews + equipment list from ProjectDataService |
| Task checklist — mark items complete | Core field UX; every field tool has this; replacing pen-and-paper checklists. One-tap complete | Low | Generated task records (new `install_tasks` table) |
| Task assignment to named engineer | Jetbuilt and XTEN-AV both allow assigning tasks to specific users; engineers need to know what is theirs | Low | Existing `users` table; FK on task record |
| Clock in / clock out per project | All AV PM tools (Jetbuilt, XTEN-AV, ClockShark) have this; required for budget vs actual labor tracking. Jetbuilt implements per-project per-labor-category | Low–Medium | Project ID + user auth; new `time_entries` table |
| Budget vs actual labor hours | Quoted hours vs logged hours is the accountability loop; managers flag overruns. Jetbuilt alerts when logged hours exceed quoted project hours | Medium | Time entries + quoted labor hours (currently in equipment labor data or estimated install time per item) |
| Mobile-responsive field view | Engineers are on site with phones, not desktops. If it's not usable on a phone it won't be used at all | Medium | Task list + clock in/out; Tailwind responsive Blade |
| Photo capture on task completion | Evidence of installation work; Jetbuilt explicitly supports photo comments on tasks; required for QA | Low–Medium | Task records; photo stored in `storage/app/private/` via standard file upload |
| Commissioning checklist — per-room, per-equipment | AVIXA standard; every AV commissioning workflow includes per-equipment functional verification before handover | Medium–High | Equipment list per room from `reviewed_data`; new `commission_items` table |
| Client digital signature at commissioning handover | UK standard handover requirement; commissioning reports need client or site representative signature to close a project | Medium | Commissioning checklist completion; HTML canvas signature pad |
| Worksheet enhancements (pre-install answers + dashboard trigger) | Pre-install answers are already captured per room in the survey; showing them in the worksheet closes the loop. Dashboard trigger is just a UX convenience | Low | Existing `survey_data` pre-install answers; existing WorksheetGeneratorService |

---

## Differentiators

Features that go beyond what generic field service tools offer and that specifically leverage the existing RAMS platform data.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Tasks auto-generated from `reviewed_data` (not manual templates) | Platform already has structured per-room, per-equipment data — no other tool has this pre-wired into the generation; Jetbuilt uses labor templates that engineers must configure; this system derives tasks from actual reviewed scope, room by room | High | Other tools generate from templates; this generates from real project data. Task name = room + equipment + action type (install / cable / commission / test) |
| Pre-install survey answers surfaced in the field view | Engineers answered specific pre-install questions per room during the site survey (AI-generated questions, reviewed answers); showing those answers in the field checklist prevents re-discovering issues already captured before the job. No other field tool has this because no other tool has the upstream survey data. | Medium | `survey_data` pre-install_questions already stored; WORK-05 also surfaces these in the Worksheet DOCX |
| Commissioning checklist auto-populated from equipment list | No manual entry — system knows exactly what was installed per room from `reviewed_data`; commissioning items generated from real project data mirrors how the O&M generator works | High | Depends on `reviewed_data` room_overviews with equipment per room; same data source as O&M generator |
| Project state machine advances automatically at commissioning sign-off | When commissioning checklist is fully signed and client signature captured, project transitions to `handover` state; no manual state update needed | Low | Existing state machine + commissioning completion event |
| Time entries linked to room/phase category | Logging hours against "Boardroom — installation" vs "Reception — cabling" gives per-room labor tracking, not just project total; useful for post-project analysis of install efficiency per room type | Medium | Extends clock in/out with category selector; room list comes from project rooms |
| Snagging list from incomplete commissioning items | Outstanding items at commissioning sign-off form a UK-standard snagging list; UK term (vs US "punch list") appropriate for 21st Century AV's market | Low–Medium | Flagged commissioning items; simple filtered list output |
| Worksheet generation triggered from project dashboard (WORK-06) | Engineering office can regenerate worksheets without navigating away from the project dashboard; reduces friction for the common "re-generate after scope change" workflow | Low | Existing `GenerateWorksheetJob`; new AJAX trigger from project show page |

---

## Anti-Features

Features to explicitly NOT build in v1.2, with rationale.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Full Gantt chart with drag-and-drop dependencies | Gantt complexity is a product of its own; building one correctly (dependencies, critical path, resource levelling) takes weeks. 21st Century AV jobs are typically 1–5 day installs, not multi-month construction programmes. Jetbuilt offers Gantt but it is one of their most complained-about features for AV installers | Use date fields on tasks + a simple calendar view (tasks grouped by date); this covers 95% of scheduling needs |
| Native mobile app (iOS/Android) | Explicitly out of scope in PROJECT.md. Mobile-responsive web is sufficient for commercial AV install sites | Mobile-responsive Blade views with Tailwind; camera input via `<input type="file" accept="image/*" capture="environment">` — works natively on iOS and Android |
| Offline mode / PWA sync | Laravel PWA with service worker offline support is technically possible but adds significant complexity: task completion events, photo uploads, and clock in/out all need offline queue + conflict resolution logic. Commercial AV install sites (offices, data centres, hospitality venues) have WiFi as a matter of course | Keep pages lightweight and fast-loading; assume connectivity. Note if offline is needed in a specific project type in the future |
| GPS geofencing clock-in enforcement | Workyard/ClockShark-style GPS location lock adds UK GDPR complexity (continuous location processing of employees); commercial AV engineers work in office buildings where GPS accuracy is poor anyway | Trust engineer self-reporting; admin can review anomalies in the time log |
| Real-time multi-engineer live task collaboration | PROJECT.md explicitly rules out multi-user collaboration; adds WebSocket or Pusher complexity disproportionate to a single-company team of known size | Refresh on page load; last-write-wins is acceptable for task completion; timestamps show who completed what |
| AI-generated commissioning pass/fail judgement | Core constraint in CLAUDE.md and PROJECT.md — AI never invents scope, design, or technical judgement. Pass/fail is the engineer's judgement on site, evidenced by the photo they attach | Engineers tick items manually; photo is the evidence; AI is not involved in commissioning |
| Payroll integration / export | Financial system is out of scope in PROJECT.md; accounts team handles payroll externally | Export time log as CSV if needed — do not build native payroll connection |
| Client portal access to live task progress | This is explicitly v1.4 CLIENT PORTAL scope (PORT-03). Building it now couples two milestones and adds auth complexity before the portal model exists | Log that commissioning sign-off occurred; the client portal in v1.4 surfaces this history |
| Per-equipment estimated install hours from manufacturer data | Manufacturers don't publish standard install times for AV equipment; any database of "hours per item" would be manual maintenance overhead. Jetbuilt uses user-configured labor templates for this | Use a flat estimated-hours field on the generated task that can be manually adjusted; derive actual from time tracking |

---

## Feature Dependencies

Task and commissioning features have a clear dependency ordering based on what data each feature needs:

```
ProjectDataService (reviewed_data)
    └── room_overviews + equipment list per room
            │
            ├── INST-01: Auto-generate task list (new install_tasks table)
            │       │
            │       ├── INST-02: Task assignment to engineer (users FK)
            │       │       └── Task notification (optional — v1.1 NOTF scope)
            │       │
            │       └── INST-03: Mobile field view (checklist + photo capture)
            │               │
            │               └── INST-04: Clock in/out (time_entries table)
            │                       └── Budget vs actual comparison (quoted hours vs logged)
            │
            └── INST-05: Commissioning checklist (commission_items table)
                    │
                    ├── Per-equipment pass/fail + photo evidence
                    │
                    └── Client digital signature
                            └── Project state → handover (auto-transition)

SiteSurvey (pre_install_questions per room)
    └── WORK-05: Pre-install answers in Worksheet DOCX

Project Dashboard
    └── WORK-06: Trigger worksheet generation from dashboard UI
```

**Hard dependency order:**
1. `INST-01` must be delivered first — task list is the foundation for assignment, field view, and time tracking
2. `INST-02` (assignment) and `INST-03` (field view) can be built together in the same phase
3. `INST-04` (time tracking) can be added to the field view in the same phase as INST-02/03 or the next
4. `INST-05` (commissioning) is independent of INST-03 but benefits from the same mobile-responsive patterns; build after field view is stable
5. `WORK-05` and `WORK-06` are low-complexity and can be batched with any phase as filler work

---

## AV-Specific Workflow: What Engineers Actually Need On Site

Based on AVIXA commissioning standards, Jetbuilt field technician workflow, and SafetyCulture AV commissioning template analysis:

### Pre-Install Phase (before arriving on site)
- See which rooms/tasks are assigned to them for today's visit
- Review pre-install check answers from the site survey per room (confirmed power positions, network drops, ceiling type, constraints flagged)
- Access generated worksheets for the project

### Installation Phase (on site)
- Tick off tasks as completed — single tap, not a multi-field form
- Attach a photo where evidence is required (bracket placement, cable route, rack build, wallplate fit)
- Clock in at start of day / start of task; clock out at end or when switching tasks
- Add a short text note against a task if conditions differ from what was scoped (feeds snagging list)

### Commissioning Phase (end of install)
- Work through per-room, per-equipment functional verification checklist
- AVIXA-standard AV commissioning items per equipment category:
  - **All equipment**: Power on/off confirmed, no fault LEDs
  - **Displays / Projectors**: Correct input selected, correct resolution, no image artefacts
  - **Audio**: Signal routing confirmed, levels calibrated, no hum or distortion
  - **Video Conferencing**: Far-end connection confirmed, PTZ camera functional, echo cancellation active
  - **Control Systems**: All button functions operate as programmed, GUI matches room requirements
  - **Wallplates / Input panels**: All inputs switching correctly, labelled correctly
  - **Networking**: Device on correct VLAN, IP reserved or DHCP lease noted
  - **Cable management**: All cables dressed and labelled per cable schedule
  - **Client training**: Completed (checkbox + engineer note on what was covered)
- Photo evidence attached to equipment items with noted anomalies
- Client or site representative signs on screen (canvas signature pad)
- Any items not passed become the snagging list

### Handover
- All items checked and signed → system generates commissioning record (date, items signed off, engineer name, client signature stored)
- Project state transitions to `handover`
- Outstanding items visible as snagging list for follow-up visit

---

## Mobile UX Constraints

| Constraint | Impact | Mitigation |
|-----------|--------|------------|
| Small screen — engineers primarily use phones, not tablets | Checklist items must be large tap targets (44px+ per Apple HIG / WCAG 2.5.5); avoid dense tables | Tailwind `p-4`, `text-lg` on interactive rows; full-width rows not columns |
| Gloves or dirty hands on site | Touch accuracy is reduced; buttons must be large; avoid gestures that require precision | Simple tap-to-toggle checkboxes; no drag-to-reorder; avoid swipe actions |
| High-brightness environments (warehouses, outdoor runs) | High contrast needed; faint grey text washes out in sunlight | Test at high display brightness; prefer Tailwind `text-gray-900` on `white` backgrounds; avoid mid-grey interactive states |
| Camera input for photo evidence | Native `<input type="file" accept="image/*" capture="environment">` handles camera on both iOS and Android without JavaScript camera libraries; works in mobile Safari and Chrome | No third-party camera JS library needed; store via standard multipart form upload to `storage/app/private/` |
| Canvas signature on mobile | Signature pad canvas needs correct devicePixelRatio scaling for sharp rendering on high-DPI screens; canvas must resize on orientation change | Use `creagia/laravel-sign-pad` package — Laravel-native, Eloquent model integration, Alpine.js compatible; handle canvas resize on `orientationchange` event |
| Network connectivity | Commercial AV install sites (offices, data centres, hospitality venues) have WiFi as standard; assume connectivity; no offline mode needed | Keep requests lightweight; use `fetch()` / Axios for task completion actions rather than full-page reloads; show loading state |

---

## Complexity Ratings Summary

| Feature | Complexity | Risk | Notes |
|---------|------------|------|-------|
| INST-01: Auto-generate task list | Medium | Low | Data is in reviewed_data; needs new `install_tasks` table + generator service following existing generator pattern |
| INST-02: Task assignment + date | Low | Low | Simple FK to users + date field; notification is optional |
| INST-03: Mobile field view (checklist + photo) | Medium | Low | Blade + Tailwind responsive; file upload pattern already established in survey photo upload |
| INST-04: Time tracking (clock in/out, budget vs actual) | Medium | Medium | Needs correct event modelling (open clock event per user per project); budget hours require a canonical source (quoted install hours from equipment data) |
| INST-05: Commissioning checklist + client signature | High | Medium | Most complex: per-equipment checklist schema, photo per item, signature capture, record generation, state transition trigger |
| WORK-05: Pre-install answers in worksheet | Low | Low | Existing data, existing generator; add a section to the DOCX template |
| WORK-06: Worksheet trigger from dashboard | Low | Low | Existing `GenerateWorksheetJob`; new AJAX call from project show page |

---

## MVP Recommendation for v1.2

Deliver in this order:

1. **INST-01 — Auto-generate task list** — everything else depends on this; derive tasks from `reviewed_data` room × equipment matrix
2. **INST-02 — Task assignment + date field** — minimal: assign to user, set date; no Gantt
3. **INST-03 — Mobile field view** — checklist tick-off + photo capture; the core field UX
4. **INST-04 — Time tracking** — clock in/out per project; budget vs actual display
5. **WORK-05 + WORK-06** — low-complexity worksheet enhancements; batch with INST-03 or INST-04 phase
6. **INST-05 — Commissioning checklist + signature** — build last; most complex; requires stable task foundation and mobile patterns established in INST-03

**Defer from v1.2:**
- Full snagging/punch list formatted output document — flagged commissioning items in the checklist UI are sufficient for v1.2; a formal PDF/DOCX snagging report can be a v1.3 output
- Notification when tasks are assigned to an engineer — the v1.1 NOTF scope owns this; don't duplicate

---

## Sources

- [Jetbuilt — Creating an Install Task List Based on Line Item Labor](https://help.jetbuilt.com/en/articles/6063128-creating-an-install-task-list-based-on-line-item-labor) — MEDIUM confidence (help article; task generation model verified)
- [Jetbuilt — Creating and Viewing Install Tasks](https://help.jetbuilt.com/en/articles/2286590-creating-and-viewing-install-tasks) — MEDIUM confidence (photo comments on tasks, assignment, notification confirmed)
- [Jetbuilt — Time Tracking for Install Users](https://jetbuilt.com/press/jetbuilt-unveils-time-tracking-for-install-users/) — HIGH confidence (official press release; clock in/out, project category, budget vs actual confirmed)
- [XTEN-AV — AV Project Resource Management Software](https://xtenav.com/av-project-resource-management-software/) — MEDIUM confidence (marketing; clock in/out and task assignment confirmed as features)
- [AVIXA Audiovisual Systems Commissioning Tests Checklist — SafetyCulture](https://public-library.safetyculture.io/products/avixa-audiovisual-systems-commissioning-tests-checklist) — HIGH confidence (AVIXA is the AV industry standards body; checklist categories are authoritative)
- [SafetyCulture — In-House AV System Commissioning Checklist](https://safetyculture.com/library/telecommunications-and-media/020icommissionchecklist-v1-0-nvodexgyhzqfnmhr) — HIGH confidence (industry standard template; equipment categories and sign-off flow confirmed)
- [Acentech — AV Commissioning (2025)](https://lab.acentech.com/2025/02/28/av-commissioning/) — HIGH confidence (AV consulting firm; punch list in commissioning context confirmed)
- [SnagBricks — Snag Lists vs Punch Lists](https://snagbricks.com/snag-lists-vs-punch-lists-practical-checklist.html) — MEDIUM confidence (UK vs US terminology confirmed)
- [creagia/laravel-sign-pad — GitHub](https://github.com/creagia/laravel-sign-pad) — HIGH confidence (active Laravel package; Eloquent model integration confirmed)
- [Salfade — Signature Pad with Alpine.js](https://salfade.com/tutorials/signature-pad-with-alpinejs) — MEDIUM confidence (Alpine.js integration pattern confirmed)
- [FieldPoint — Mobile Checklists for Field Service Management](https://fieldpoint.net/mobile-checklists/) — MEDIUM confidence (field service industry; mobile-first and mandatory checklist patterns)
- [Software Advice — Field Service Management Software Features 2026](https://www.softwareadvice.com/resources/field-service-management-software-features-users-vs-buyers/) — MEDIUM confidence (industry survey data on what field users actually value)
