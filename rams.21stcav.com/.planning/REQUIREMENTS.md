# Requirements: v1.2 — Installation Programme & Field Management

**Milestone goal:** Transform the platform from document generator into a live installation delivery system — auto-generated task lists from project data, engineer assignment, mobile-responsive field view, time tracking, and commissioning sign-off.

**Phases:** 12–16 (continues from v1.0 Phases 01–07)
**Defined:** 2026-04-13
**Research:** `.planning/research/SUMMARY.md` (synthesised 2026-04-13)

---

## Scoping Decisions (recorded 2026-04-13)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Scheduling UI | Date fields + week-view table; interactive Gantt only when project duration > 4 days | Gantt is overkill for ≤4-day installs; conditional Gantt via frappe-gantt for longer projects |
| Offline mode | Online only — no PWA/service worker | AV install sites (offices, venues, data centres) always have WiFi |
| Time tracking budget | Actuals only — no budget comparison in v1.2 | Budget source (QuoteWerks labour vs manual) not yet decided; track actual hours now |
| Field devices | Mixed iOS + Android | HEIC photo protection mandatory; per-item AJAX saves required; canvas DPI scaling for signatures |

---

## Requirements

### INST-01 — Install Task Generation

**Goal:** Auto-generate a structured task list for a project from `ProjectDataService` — no manual task creation.

- [ ] **INST-01a**: `InstallTaskGeneratorService` reads canonical `ProjectDataset` from `ProjectDataService::resolve()` — never reads `reviewed_data` or `extracted_data` directly
- [ ] **INST-01b**: Tasks are generated per room × equipment item: one task per hardware item per room (e.g. "Install Samsung 75" display in Boardroom")
- [ ] **INST-01c**: Task records are persisted to `install_tasks` table (not a document snapshot) with fields: `id`, `install_programme_id`, `room_name` (denormalised string), `equipment_name`, `quantity`, `category`, `status` (pending / in_progress / complete), `sort_order`, `notes`, `created_at`, `updated_at`
- [ ] **INST-01d**: `install_programmes` table groups tasks per project: `id`, `project_id`, `generated_at`, `status` (draft / active / complete), `planned_start_date`, `planned_end_date`, `created_at`, `updated_at`
- [ ] **INST-01e**: Human confirm gate before programme becomes active — generated tasks are shown for review, PM confirms or deletes/edits individual tasks before activation
- [ ] **INST-01f**: Re-generation is allowed (new programme version); old programme archived when new one activated
- [ ] **INST-01g**: Task generation is synchronous (no queue job) — fast enough for inline response; < 1 second for any real-world project size
- [ ] **INST-01h**: `Project` model gains `installProgrammes()` hasMany and `activeInstallProgramme()` relationships
- [ ] **WORK-05**: Worksheet DOCX includes pre-install check question answers per room (from `SurveyQuestionAnswer` joined to `SiteSurveyRoom`)
- [ ] **WORK-06**: Worksheet generation can be triggered from the project dashboard (button alongside RAMS/O&M/cable schedule triggers)

---

### INST-02 — Task Assignment & Scheduling

**Goal:** Assign tasks to engineers and set planned dates; view schedule as week-view calendar.

- [ ] **INST-02a**: `install_tasks.assigned_user_id` FK to `users` — nullable (unassigned tasks allowed)
- [ ] **INST-02b**: Bulk assignment: assign all tasks in a room, all tasks of a category, or all tasks in a programme to a user in one action
- [ ] **INST-02c**: Per-task planned start date (`planned_start_date`) and planned end date (`planned_end_date`) — nullable, date-only (not datetime)
- [ ] **INST-02d**: Week-view calendar: tasks grouped by week, colour-coded by assigned engineer — Tailwind table layout, no JS library required
- [ ] **INST-02e**: Conditional Gantt: when `planned_end_date - planned_start_date > 4 days`, show interactive Gantt timeline (frappe-gantt); otherwise show week-view table
- [ ] **INST-02f**: Gantt bars represent tasks; click to open task detail panel; no drag-to-reschedule in v1.2 (read-only timeline)
- [ ] **INST-02g**: Engineers see only their assigned tasks on the field view; PMs see all tasks

---

### INST-03 — Mobile Field View

**Goal:** Responsive mobile-first page where field engineers see their assigned tasks, mark progress, and capture photos.

- [ ] **INST-03a**: `/projects/{project}/programme` route — mobile-responsive Blade + Tailwind layout following existing public survey patterns
- [ ] **INST-03b**: Task list grouped by room; sorted by `sort_order`; filtered to authenticated engineer's assigned tasks by default; PM can see all
- [ ] **INST-03c**: Task status toggle: tap to advance `pending → in_progress → complete`; AJAX save (no page reload); visual confirmation on success
- [ ] **INST-03d**: Photo capture per task: `<input type="file" accept="image/*" capture="environment">` — same pattern as existing survey photo upload
- [ ] **INST-03e**: iOS HEIC protection: server-side conversion to JPEG using GD before storage; applies to all task photo uploads
- [ ] **INST-03f**: Per-task notes input (free text, optional); saved via AJAX
- [ ] **INST-03g**: Room-level progress indicator: `N of M tasks complete` per room; overall programme progress bar
- [ ] **INST-03h**: Online only — no service worker, no offline caching

---

### INST-04 — Time Tracking

**Goal:** Engineers clock in and out against a project; actual hours logged per category.

- [ ] **INST-04a**: `time_entries` table: `id`, `project_id`, `user_id`, `category` (installation / commissioning / testing / other), `clocked_in_at`, `clocked_out_at` (nullable), `last_heartbeat_at`, `notes`, `created_at`, `updated_at`
- [ ] **INST-04b**: Clock in: creates `time_entry` row with `clocked_in_at = now(UTC)`, `clocked_out_at = null`
- [ ] **INST-04c**: Clock out: sets `clocked_out_at = now(UTC)` on open entry
- [ ] **INST-04d**: Heartbeat: mobile page sends heartbeat every 60 seconds; `last_heartbeat_at` updated server-side; no JS library required (Axios interval)
- [ ] **INST-04e**: Stale session recovery: scheduled job (`php artisan programme:close-stale-sessions`) auto-closes entries where `last_heartbeat_at` is older than 2 hours; logs a warning
- [ ] **INST-04f**: Timezone: all storage in UTC; display in Europe/London (BST/GMT aware) — `Carbon::setTimezone('Europe/London')` for display only, never for storage
- [ ] **INST-04g**: Guard: only one open time entry per user per project at a time; clock in rejected if open entry exists
- [ ] **INST-04h**: Actual hours summary: per-project total and per-category breakdown shown on project dashboard
- [ ] **INST-04i**: v1.2 tracks actuals only — no budget comparison (deferred until labour source is decided)

---

### INST-05 — Commissioning Checklist & Client Sign-off

**Goal:** Per-equipment commissioning checklist with photo evidence and client signature; triggers project state transition.

- [ ] **INST-05a**: `commissioning_items` table: `id`, `install_programme_id`, `equipment_name`, `room_name`, `category` (power / display / audio / vtc / control / network / cabling), `status` (pending / pass / fail / na), `evidence_photo_path`, `notes`, `signed_off_by`, `signed_off_at`, `created_at`, `updated_at`
- [ ] **INST-05b**: Commissioning items generated from programme equipment list — one item per equipment × AVIXA category where applicable
- [ ] **INST-05c**: Per-item AJAX save: each status update, photo upload, or note is saved immediately as a separate AJAX request — never a single full-form POST
- [ ] **INST-05d**: Photo evidence upload per item: same HEIC protection as INST-03e
- [ ] **INST-05e**: AVIXA checklist categories: Power On / Display Quality / Audio Level / VTC Connectivity / Control System / Network / Cabling — applied per equipment type (not every category applies to every item)
- [ ] **INST-05f**: Client signature: `creagia/laravel-sign-pad` package; canvas with explicit `devicePixelRatio` scaling (prevents Retina/iOS DPI corruption); signature stored as base64 PNG
- [ ] **INST-05g**: Commissioning completion: all items pass/fail/na → "Complete Commissioning" button unlocked; generates PDF snagging report (uses existing DomPDF pipeline, embeds signature image)
- [ ] **INST-05h**: Programme completion auto-advances `Project.status` from `STATUS_INSTALLING` to `STATUS_COMMISSIONING` via existing state machine — guard confirms valid transition before advancing
- [ ] **INST-05i**: Audit trail: `commissioning_items.signed_off_by` (engineer name), `signed_off_at` (UTC timestamp); immutable once signed

---

## Technical Constraints (from research)

| Constraint | Source | Applies to |
|------------|--------|------------|
| Task generation reads only from `ProjectDataService::resolve()` — never `extracted_data` directly | Core data integrity constraint | INST-01 |
| Room names denormalised as strings in `install_tasks.room_name` — not FK to `site_survey_rooms` | ProjectDataService resolves rooms from reviewed_data; IDs may not match survey IDs | INST-01 |
| All datetimes stored UTC; displayed in Europe/London | Timezone pitfall documented in research | INST-04 |
| `last_heartbeat_at` column required from day one — not retrofittable after time_entries contains data | Schema design | INST-04 |
| iOS HEIC server-side conversion mandatory — not client-side | HEIC is a silent failure (upload succeeds; GD render fails later) | INST-03, INST-05 |
| Canvas signature with `devicePixelRatio` scaling — documented library issue on iOS Retina | signature_pad GitHub issues #71, #153, #200, #362 | INST-05 |
| Per-item AJAX saves for commissioning — no single-form POST | Spotty signal in plant rooms / basements | INST-05 |
| Existing `BuildRamsDocumentJob` / RAMS pipeline untouched | v1.2 adds no new models to existing pipeline | All phases |

---

## Dependencies

```
Phase 12 (INST-01: Task generation + models)
  └── Phase 13 (INST-02: Assignment + scheduling) — needs install_tasks table
  └── Phase 14 (INST-03: Mobile field view)       — needs install_tasks table
        └── Phase 16 (INST-05: Commissioning)     — reuses mobile UX patterns

Phase 12 (INST-01)
  └── Phase 15 (INST-04: Time tracking)           — needs project_id from established programme

Phases 13 and 14 can be built in parallel after Phase 12.
```

---

## Out of Scope (v1.2)

| Item | Reason |
|------|--------|
| Offline PWA / service worker | AV sites have WiFi; complexity not justified |
| Drag-and-drop Gantt rescheduling | Anti-feature for ≤4-day installs; read-only Gantt view only |
| Budget vs actual comparison | Labour budget source not yet decided; actuals-only in v1.2 |
| Push notifications to engineers | Deferred to v1.1 NOTF scope |
| Native mobile app | Responsive web is sufficient |
| Asset registry as separate module | Part of v1.6 |
| Client portal for commissioning reports | Part of v1.4 |

---

## Open Questions

| Question | Impact | Owner |
|----------|--------|-------|
| Where do budgeted hours come from? (QuoteWerks labour lines vs manual entry) | INST-04 budget comparison — deferred to post-v1.2 quick task | PM decision |
| Should programme completion notify PM? | NOTF scope overlap with v1.1 — keep manual for now | Product |
| What triggers v1.1 delivery? | v1.1 (DASH, NOTF, BIT) is still "next" in roadmap — does user want v1.1 after v1.2 or continue skipping? | User |
