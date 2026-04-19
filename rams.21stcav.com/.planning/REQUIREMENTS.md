# Requirements: v1.1 + v1.2

---

## v1.1 — Operations Dashboard & Notifications
**Milestone goal:** Give PMs real-time visibility across all active projects — health indicators, overdue/blocked alerts, email notifications, and quality scores.
**Phases:** 08–11
**Defined:** 2026-04-14

---

### DASH-01 — Enterprise Dashboard

**Goal:** Transform the basic closure-driven dashboard into a real-time project health command centre showing all active projects, health indicators, overdue stage alerts, and install programme progress.

- [x] **DASH-01a**: Dashboard route uses `DashboardController@index` — closure removed from `routes/web.php`; controller lives at `app/Http/Controllers/DashboardController.php`
- [x] **DASH-01b**: All non-archived projects returned in controller and rendered in a health grid — not capped at 6; uses `Project::with(['owner', 'ramsDocuments', 'siteSurveys', 'installProgrammes'])->whereNotIn('status', ['archived'])->get()`
- [x] **DASH-01c**: `ProjectHealthService::assess(Project $project): ProjectHealth` returns a value object with `status` (green/amber/red), `reason` (string), and `overdue` (bool)
- [x] **DASH-01d**: Health derivation rules (applied in priority order — first match wins):
  - **Red**: RAMS document for this project has `status = failed`; OR project is in `engineering` with no RAMS document at `approved` or beyond; OR project is in `survey_pending` with no submitted `SiteSurvey` record and `survey_started_at` is >14 days ago
  - **Amber**: project has been in its current stage for >7 days; OR RAMS is `awaiting_review` (needs engineer action); OR project is in `engineering` with RAMS in `uploaded`/`awaiting_review` (blocked in pipeline)
  - **Green**: all document states normal for the current project lifecycle stage
- [x] **DASH-01e**: Overdue indicator: derived from existing milestone timestamp columns (`survey_started_at`, `engineering_started_at`, `installation_started_at`, `commissioning_started_at`, `handover_started_at`) — a project is overdue when `now() - stage_start > 14 days`; no new column required
- [x] **DASH-01f**: Status summary strip: count of non-archived projects per lifecycle stage (quote_imported, survey_pending, engineering, installing, commissioning, handover, completed) shown as clickable chips using `Project::STATUS_LABELS` and `Project::STATUS_COLOURS`
- [x] **DASH-01g**: Status filter: clicking a stage chip filters the health grid to show only projects in that stage — implemented with Alpine.js `x-show` on each row, no page reload, URL hash updated for bookmarkability
- [x] **DASH-01h**: Install programme widget: for projects in `installing` or `commissioning` status, show active install programme task completion % (`complete_tasks / total_tasks * 100`) alongside health badge; gracefully hidden (no widget shown) when no active `install_programme` record exists

---

### NOTF-01 — Document generation completion notifications

**Goal:** Project owner is notified by email when any of the four document types (RAMS, O&M Manual, Worksheet, Cable Schedule) finishes generating, so they don't need to refresh the dashboard or poll the queue.

- [ ] **NOTF-01a**: A `RamsDocumentReadyMail` (or shared `DocumentReadyMail` polymorphic over the four types) implements `Illuminate\Contracts\Queue\ShouldQueue` and is dispatched from `BuildRamsDocumentJob` immediately after the model status flips to `STATUS_COMPLETED`
- [ ] **NOTF-01b**: The same dispatch hook exists in `BuildOmManualJob` (status → `final` or `STATUS_FINAL`), the worksheet generator job, and the cable-schedule generator job — each fires its own typed mailable so the subject line and template can vary per document type
- [ ] **NOTF-01c**: Each notifiable model gains a `completion_email_sent_at` timestamp column via migration: `rams_documents.completion_email_sent_at`, `om_manuals.completion_email_sent_at`, `worksheets.completion_email_sent_at`, `cable_schedules.completion_email_sent_at` — `RamsDocument.email_sent_at` (existing, owned by manual `RamsController@email`) is NOT reused
- [ ] **NOTF-01d**: Send-once guard: each dispatch path checks `$model->completion_email_sent_at === null` before sending; the timestamp is set in the same `update()` call as the dispatch so a job retry cannot double-send
- [ ] **NOTF-01e**: Manual regenerations do not fire a fresh "regenerated" email — only the standard completion email when the new model row reaches the completed state. The old superseded row stays silent (its `completion_email_sent_at` was already set when its own job completed)
- [ ] **NOTF-01f**: Each completion email includes the generated artifact as an attachment (resolved via `DocumentArtifactStorage::readPath()`, gracefully omitted when the file is missing — same pattern as `RamsDocumentMail::attachments()`); subject line format: `[{project_ref}] {DocType} ready — {project_name}` (e.g., `[21CQ30017] RAMS ready — Acme Boardroom Refresh`)

---

### NOTF-02 — Site survey submission notification (inherited from v1.0)

**Goal:** Project owner is notified when an external surveyor submits the public site-survey form. **Already implemented** in `SurveyService::submitPublic()` ([app/Core/Modules/Survey/SurveyService.php:403](../../app/Core/Modules/Survey/SurveyService.php#L403)) using `SurveySubmittedMail`. Phase 09 inherits this path and ensures it continues to work; template polish is allowed but the call site stays put.

- [ ] **NOTF-02a**: After Phase 09 ships, the existing survey-submitted send path still passes its current feature tests (no regression) and uses the same recipient-resolution rule as the new triggers (project owner with admin fallback) — if the rule is extracted to a `NotificationRecipientResolver` service, `SurveyService` is refactored to use it for consistency

---

### NOTF-03 — RAMS review-needed notification

**Goal:** Engineer/PM is notified when a RAMS document reaches `awaiting_review` status (i.e., `ExtractRamsDraftJob` has produced extracted data and the document is ready for human review), so review work is not silently sitting in the queue.

- [ ] **NOTF-03a**: A `RamsReviewNeededMail` (`implements ShouldQueue`) is dispatched when `ExtractRamsDraftJob` finishes successfully and updates `RamsDocument.status` to `STATUS_AWAITING_REVIEW` — dispatched from the job, not from a model observer (so test mocking is straightforward)
- [ ] **NOTF-03c**: Idempotency guard via `rams_documents.review_needed_email_sent_at` (nullable timestamp added in plan 09-01); the dispatch path checks `if ($record->review_needed_email_sent_at === null) { ... }` and sets the timestamp BEFORE send (same pattern as completion mail per NOTF-01d). Without this column, ExtractRamsDraftJob `$tries=2` retries would silently re-fire the review email
- [ ] **NOTF-03b**: Recipient = project owner (with admin fallback when `project.user_id` is null or orphaned). Email body contains a link to the RAMS review URL (`route('rams.review', $rams)`) and a brief summary (project ref, project name, time the document entered the review queue). No 7-day reminder in v1.1 — that scheduled-command extension is deferred to a v1.1 quick task

---

### NOTF-04 — Document generation failure alert

**Goal:** Admins (the operations team) are notified when a `Build*Job` exhausts its retries and the model status lands on `failed`, so failed jobs do not silently rot in the queue.

- [ ] **NOTF-04a**: A `DocumentGenerationFailedMail` (`implements ShouldQueue`) is dispatched from each `Build*Job::failed()` lifecycle hook (Laravel calls this hook after the final retry exhaustion). Recipients = `User::where('role', 'admin')->get()` (the codebase uses `users.role`, not an `is_admin` boolean — see NOTF-05b); the project owner is NOT CC'd (operational issue, not their concern)
- [ ] **NOTF-04b**: Each notifiable model gains a `failed_email_sent_at` timestamp column; the dispatch path sets it inside the same `update()` call to prevent duplicate alerts when `failed()` re-fires across separate retry attempts
- [ ] **NOTF-04c**: Failure email body includes: project ref, project name, document type, the value of `error_message` (truncated to 500 chars), and a link to the document detail page so an admin can drill into the full stack trace via the existing UI

---

### NOTF-05 — Notification recipients, transport, and operational guarantees

**Goal:** All notification triggers share consistent recipient-resolution, transport, and failure-handling rules. No per-user opt-out, no per-project subscriber list, no in-app channel — all deferred.

- [ ] **NOTF-05a**: Default recipient resolution = `Project::user()` (project owner) with fallback to `User::where('is_admin', true)->first()` when the owner is null or the user record is missing. Implemented as a single `App\Services\NotificationRecipientResolver::resolveProjectRecipient(Project $project): ?User` so the rule is not duplicated across mailable call sites
- [ ] **NOTF-05b**: Failure-alert recipients = all admins (`User::where('role', 'admin')->get()` — the codebase uses a `users.role` enum, not an `is_admin` boolean). No `config/rams.php` admin override list; the role-based query is the single source of truth
- [ ] **NOTF-05c**: No `project_notification_recipients` table or per-project subscriber UI in v1.1 — project owner is the sole project-level recipient (deferred)
- [ ] **NOTF-05d**: Configurable global BCC for audit. New env var `RAMS_NOTIFICATION_BCC` (default empty in dev/test, `ops@21stcav.com` in production); when non-empty, every system email applies `->bcc(config('rams.notifications.bcc'))`. `config/rams.php` exposes `notifications.bcc => env('RAMS_NOTIFICATION_BCC')`
- [ ] **NOTF-05e**: All system mailables are `Illuminate\Mail\Mailable` subclasses (no `Illuminate\Notifications\Notification` framework migration in v1.1 — multi-channel deferred to Phase 11+ when there is a real second channel)
- [ ] **NOTF-05f**: All system mailables `implements ShouldQueue` so they dispatch through the existing `database` queue. Dev environment with `MAIL_MAILER=log` writes the rendered message to `storage/logs/laravel.log` for visual verification
- [ ] **NOTF-05g**: Production transport = Postmark. `MAIL_MAILER=postmark` + `POSTMARK_API_KEY` in production `.env` (the env var name `config/services.php:18` already reads — Laravel 12 / Symfony Postmark transport convention; `POSTMARK_TOKEN` is incorrect). Requires `composer require symfony/postmark-mailer symfony/http-client` (not currently installed). From address = `rams@21stcav.com`, From name = `RAMS Platform`. SPF / DKIM (Postmark-issued selector) / DMARC records on `21stcav.com` are operational prerequisites (DNS work tracked as a planning checklist item, not a code task)
- [ ] **NOTF-05h**: Every send wrapped in `try { Mail::to(...)->send(...); } catch (\Throwable $e) { Log::warning('NotificationService: ...', [...]); }` — same defensive pattern as `SurveyService::submitPublic()`. Mail failure must never roll back the underlying document-generation job, status transition, or survey submission. No bounce/complaint webhook ingestion in v1.1 (Postmark dashboard is the operability surface; webhook ingestion deferred)

---

## v1.2 — Installation Programme & Field Management
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
