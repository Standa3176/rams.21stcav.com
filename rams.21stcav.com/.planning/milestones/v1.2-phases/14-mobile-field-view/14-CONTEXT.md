# Phase 14: Mobile Field View - Context

**Gathered:** 2026-04-19
**Status:** Ready for planning

<domain>
## Phase Boundary

A mobile-responsive field page at `/projects/{project}/programme` where engineers execute their assigned install work from their phone: view tasks grouped by room, tap to advance task status, capture per-task photos (HEIC silently converted), leave notes, and clock in/out of the project. Online-only (per INST-03h).

**In scope this phase:** mobile-first Blade/Tailwind/Alpine UI, `install_task_photos` table + model, per-task photo upload with HEIC→JPEG conversion, AJAX status/notes saves, task scope filtering (engineer vs all), minimal `time_entries` scaffold sufficient to satisfy success criterion 5 (clock in/out button works end-to-end).

**Out of scope this phase:** Phase 15's full INST-04 schema (category, heartbeat loop, `programme:close-stale-sessions` command, budget comparison), Phase 16's INST-05 commissioning checklist/signatures/snagging PDF, offline/service-worker behaviour, push notifications.

</domain>

<decisions>
## Implementation Decisions

### Layout & Navigation
- **D-01** Room-sectioned scrollable list: each room is a collapsible section containing its tasks, room-level `N of M complete` counter on the section header. All rooms expanded by default; engineer can collapse any room they're not in.
- **D-02** Default task scope for engineers = `assigned_to = auth()->id()`. Admins (`User::isAdmin()`) and project owners (`project.user_id === auth()->id()`) see all programme tasks by default. Engineers get a toggle to "Show all" for situational awareness.
- **D-03** Sticky top bar above the task list shows project name + current clock-in status + clock in/out button. Persistent across scroll.
- **D-04** Programme-wide progress shown as a linear progress bar + `X of Y tasks complete` text near the top of the page (under the sticky bar).

### Task Status Interaction
- **D-05** Primary interaction = tap-to-advance the task row through `pending → in_progress → complete`. Tapping a completed task does nothing on the main path (no accidental un-complete).
- **D-06** `blocked` and `skipped` statuses live behind an overflow (`⋮`) icon per task row. Setting either opens a bottom-sheet and **requires a reason note** before saving.
- **D-07** Regression from `complete → in_progress` is allowed via the overflow menu only. Every status change is persisted with an audit-log row (`status_changed_at` + `status_changed_by` minimum).
- **D-08** Visual confirmation on save = inline row state change (colour + icon) + brief checkmark pulse over the row. No toast. All saves are AJAX, no page reload (INST-03c).

### Photo Capture Flow
- **D-09** Multiple photos per task via a new `install_task_photos` table modelled exactly on `site_survey_photos` (columns: `id`, `install_task_id`, `filename`, `original_name`, `mime_type` default `image/jpeg`, `caption`, `sort_order`, timestamps). UI shows thumbnails as a horizontal scrolling strip below the task row.
- **D-10** Photos are **optional** — UI encourages capture but does not block `complete`. Phase 16 (commissioning) will handle stricter evidence requirements via its own `commissioning_items.evidence_photo_path`.
- **D-11** HEIC → JPEG conversion via `intervention/image` with the **Imagick** driver, **synchronous** inside the upload request. Fails loudly if the PHP Imagick extension is missing (HTTP 500 with a clear message in the log — **never silent fallback**, per PROJECT.md data-integrity rule). The converter service is reusable by Phase 16 for INST-05d.
- **D-12** Photos support optional captions. Caption input is inline under each thumbnail and saves on blur via AJAX (mirrors `site_survey_photos.caption`).

### Claude's Discretion
The user did not pick the "Clock in/out scope" gray area, and did not ask for deep-dives on notes input, empty state, or file-size limits. Claude will decide the following during planning — flag here so the user can overrule before research:

- **Clock in/out backend wiring.** Ship a **minimal** `time_entries` table in Phase 14 with only the columns the UI needs: `id`, `project_id`, `user_id`, `clocked_in_at`, `clocked_out_at` (nullable), `last_heartbeat_at` (nullable — defined from day one per REQUIREMENTS.md "Technical Constraints" row), `created_at`, `updated_at`. **No `category` column yet** — Phase 15 adds it via a non-destructive migration. This unblocks success criterion 5 ("Clock in/out controls appear on the field page") without stepping on Phase 15's INST-04a scope. Guard (INST-04g): only one open entry per user per project at a time; second clock-in rejected with a 422.
- **Notes input pattern.** Inline auto-expanding textarea per task, saved on blur via AJAX. Mirrors the caption pattern.
- **Empty state.** When an engineer has zero assigned tasks, show "No tasks assigned to you yet." with a "Show all programme tasks" link.
- **Photo thumbnail layout.** Horizontal scrolling strip, 80×80 thumbnails below the task row. Tap opens a lightbox.
- **Max photo size.** 20 MB per upload (covers typical iOS photo sizes; rejected politely above that).
- **Storage path.** `storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg` on the default `local` disk, matching the survey pattern.

### Folded Todos
None — no matching pending todos.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements & Roadmap
- `.planning/REQUIREMENTS.md` §INST-03 — Full INST-03a–h spec (route, grouping, status toggle, photo capture, HEIC protection, notes, progress, online-only)
- `.planning/REQUIREMENTS.md` §INST-05d — Photo HEIC protection reused in Phase 16 (converter built here is the one Phase 16 calls)
- `.planning/REQUIREMENTS.md` "Technical Constraints" table — `last_heartbeat_at` required day-one; HEIC conversion must be server-side; `ProjectDataService` rules
- `.planning/ROADMAP.md` "Phase 14: Mobile Field View" — Goal, `Depends on: Phase 12`, success criteria 1–5

### Related Phase Artifacts
- `.planning/phases/12-install-task-generation/12-01-PLAN.md` — Migrations for `install_programmes` / `install_tasks`, model structure
- `.planning/phases/12-install-task-generation/12-01-SUMMARY.md` — What actually shipped on the install_tasks schema
- `.planning/phases/13-task-assignment-scheduling/13-01-PLAN.md` — Task assignment service + controller pattern
- `.planning/phases/13-task-assignment-scheduling/13-02-PLAN.md` — Schedule view, route structure, Alpine panel approach

### Codebase Reuse Targets
- `app/Models/InstallTask.php:1-130` — Existing `status` / `notes` / `assigned_to` / `started_at` / `completed_at` fields; add `photos()` HasMany relation
- `database/migrations/*_create_install_tasks_table.php` — Authoritative status enum values: `pending | in_progress | complete | blocked | skipped`
- `database/migrations/2026_03_14_000031_create_site_survey_photos_table.php` — Exact pattern to mirror for `install_task_photos` migration
- `app/Core/Modules/Survey/SurveyService.php:451-480` — UUID-naming + `Storage::disk('local')` photo upload flow
- `resources/views/components/survey/photo-upload.blade.php` — Alpine `capture="environment"` camera input + upload handler
- `resources/views/public-survey/show.blade.php:1801-1970` — `fetch()` + manual CSRF meta tag AJAX pattern (the app does not use Axios for form saves)
- `app/Http/Controllers/InstallProgrammeController.php` — Ownership check pattern: `abort_if($project->user_id !== auth()->id() && ! auth()->user()->isAdmin(), 403)`; `schedule()` action as shape reference
- `app/Models/User.php:45-47` — `isAdmin()`; roles in the codebase are only `admin` and `user`
- `app/Models/InstallProgramme.php` — Programme lifecycle (`draft | active | complete | archived`) for context on when field view is reachable
- `composer.json` — Currently NO image library installed; Phase 14 adds `intervention/image` + requires `ext-imagick`

### Planning Context
- `.planning/PROJECT.md` — "AI usage" and "Data integrity" constraints; in particular the non-negotiable that processing failures surface loudly rather than silently degrading

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `SurveyService::uploadPhoto` pattern (UUID storage, `site_survey_photos` row insertion) — copy shape into a new `TaskPhotoService::upload()`
- `resources/views/components/survey/photo-upload.blade.php` Alpine component — fork into `resources/views/components/install-task/photo-upload.blade.php` (or make the existing one parameterised)
- `InstallProgrammeController` ownership check pattern — reuse verbatim
- `User::isAdmin()` — sole role-based gate; no need to invent an "engineer" role

### Established Patterns
- Blade + Tailwind + Alpine.js, server-rendered, no SPA
- Fetch-based AJAX with manual CSRF meta header (`X-CSRF-TOKEN` from `<meta name="csrf-token">`); not Axios for form/save traffic
- UUID-named files on private `storage/app/private/` disk via `Storage::disk('local')`
- Status enum stored as `varchar` with PHP constants on the model class (e.g. `InstallTask::STATUS_COMPLETE`)
- JSON response shape for upload endpoints: `{ id, filename, original_name, caption, url }`
- Thin controllers → dedicated service classes (`app/Services/` flat namespace or `app/Core/Modules/{Module}/`)

### Integration Points
- `routes/web.php` — add `/projects/{project}/programme` (GET view), plus status/photo/notes/clock PATCH+POST endpoints
- Extend `InstallProgrammeController` with a `field()` action (or create `app/Http/Controllers/FieldController.php`) — planner to decide based on file size
- Add `InstallTask::photos()` HasMany → new `InstallTaskPhoto` model
- New `app/Services/HeicImageConverter.php` wrapping `intervention/image` with Imagick; inject into photo upload services so Phase 16 can reuse
- New `app/Models/TimeEntry.php` (minimal schema — Phase 15 expands)
- `composer require intervention/image` + document Imagick requirement in `docs/deployment` or README

### Gaps the Scout Surfaced
- No `photo_path` on `install_tasks` yet — we are adding a dedicated `install_task_photos` table rather than a column
- No image library installed at all — Imagick PHP extension must be installed on the server (document this)
- No existing HEIC handling anywhere — `site_survey_photos` currently accepts raw uploads with only MIME validation; consider a follow-up (deferred) to backfill survey photos through the same converter

</code_context>

<specifics>
## Specific Ideas

- **Layout mirrors site survey UX** — engineers already know the room-by-room scrollable list from Phase 07. Same mental model, no retraining.
- **Tap-to-advance** — matches the "fast common path" philosophy established in Phase 07 survey answering. Overflow/long-press covers the edge cases.
- **HEIC conversion must fail loudly** — user explicitly selected the "fails loudly if extension missing" option. Reinforces the PROJECT.md data-integrity rule: no silent degradation when an input cannot be processed.
- **No toast notifications** — visual confirmation is inline to the task row. Keeps the UI calm on a small screen when engineers tap many tasks in a row.

</specifics>

<deferred>
## Deferred Ideas

- **Offline / service worker / localStorage queue** — INST-03h locks this phase to online-only. Revisit only if field-signal issues surface after launch.
- **Time-tracking categories, heartbeat, stale-session command** — Phase 15 (INST-04b–h). Phase 14 ships only the minimum `time_entries` schema to make clock in/out work end-to-end.
- **Budget vs actual hours** — Phase 15's INST-04i explicitly defers this until labour-source decision; noted here so Phase 14's dashboard surface doesn't try to pre-empt it.
- **Commissioning evidence photos, client signature, snagging PDF** — Phase 16 (INST-05). Phase 16 reuses the HEIC converter built here.
- **Per-task-type `requires_photo` flag** — not in INST-03; Phase 16 may need it for commissioning sign-off. Revisit there.
- **Task-status audit trail UI** — saves persist `status_changed_at/by`, but we aren't building a history view on the field page. Could surface on the schedule page later if compliance requires.
- **Push notifications on task reassignment** — v1.1 NOTF scope is email only; mobile push is not on any current roadmap.
- **Backfill HEIC conversion for existing `site_survey_photos`** — worth a follow-up todo since survey currently accepts raw HEIC uploads. Not in Phase 14 scope.

### Reviewed Todos (not folded)
None — no pending todos surfaced.

</deferred>

---

*Phase: 14-mobile-field-view*
*Context gathered: 2026-04-19*
