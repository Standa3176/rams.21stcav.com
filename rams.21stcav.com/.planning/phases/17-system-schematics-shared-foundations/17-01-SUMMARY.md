---
phase: 17-system-schematics-shared-foundations
plan: 01
subsystem: drawings-foundations
tags: [drawings, schematic, project_drawings, browsershot, document-edits, draw-24, draw-25, draw-30]

requires:
  - phase: 16-snagging-and-handover
    provides: DocumentArtifactStorage::TYPE_SNAGGING precedent (post-H-07 type without LEGACY_ROOTS) — TYPE_DRAWING follows the same shape
  - phase: 12-install-programme
    provides: InstallProgrammeService.archive-prior + regenerate pattern — DrawingService.regenerate mirrors it with the superseded_by_id self-FK layered on top
  - phase: 09-email-notifications
    provides: NotificationRecipientResolver + Build*Job idempotent completion email pattern — BuildSchematicJob copies BuildOmManualJob verbatim
provides:
  - project_drawings table with kind discriminator (schematic / rack / floor_plan), status state machine, version + superseded_by_id self-FK
  - ProjectDrawing model with KIND_* and STATUS_* constants + helpers (statusLabel, kindLabel, revisionLabel, isSuperseded, hasUserEdits)
  - ProjectDrawingPolicy (owner-or-admin, mirrors RamsDocumentPolicy)
  - Device.signal_role column + isSource/isDestination/isProcessor/hasUnknownSignalRole classifiers (CRIT-05)
  - DocumentArtifactStorage::TYPE_DRAWING constant
  - PdfRenderService::fromBlade waitForJs option + new fromBladeAsPng method (Browsershot construction lives in one place)
  - DrawingService (createForProject / generateInitial / regenerate / archivePrior) + DrawingDataResolverService (read-only ProjectDataService reshape)
  - DrawingEditAdapter scaffolding (DRAW-30) registered in DocumentEditAdapterRegistry + DocumentEditParsingPromptFactory drawingSnapshot
  - BuildSchematicJob skeleton with full handle() + failed() bodies (idempotent completion + admin failure alerts)
  - DrawingReadyMail single mailable with kind-discriminated subjects + drawing-ready Blade view
  - ProjectDrawingController shell (index + show + regenerate) + 3 named routes wired into auth group
  - Project::drawings() HasMany relation + Gate::policy registration
affects: [phase-17-02-schematic-generator, phase-17-03-render-ui-handover, phase-18-rack-elevations, phase-19-floor-plans, phase-20-drawing-export-om]

tech-stack:
  added: []
  patterns:
    - "Single project_drawings table + kind discriminator (mirrors H-07 collapse to one DocumentArtifactStorage)"
    - "DrawingService.regenerate inside DB::transaction; job dispatch AFTER commit so queue worker never sees a phantom row"
    - "Idempotent completion_email_sent_at + failed_email_sent_at set BEFORE send (D-14)"
    - "Single *ReadyMail with kind discriminator (subject branches on $drawing->kind)"
    - "Layout-only chat ops with fixed allow-list (set_status / set_revision_note / add_layout_hint) — AI cannot invent equipment or cables"
    - "Browsershot construction centralised in PdfRenderService — fromBlade + fromBladeAsPng both go through the same chrome path / no-sandbox / chromium-args, so Phase 20 hardening lands in one place"

key-files:
  created:
    - "database/migrations/2026_05_01_000001_create_project_drawings_table.php"
    - "database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php"
    - "app/Models/ProjectDrawing.php"
    - "app/Policies/ProjectDrawingPolicy.php"
    - "app/Services/Drawings/DrawingService.php"
    - "app/Services/Drawings/DrawingDataResolverService.php"
    - "app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php"
    - "app/Jobs/BuildSchematicJob.php"
    - "app/Mail/DrawingReadyMail.php"
    - "resources/views/emails/drawing-ready.blade.php"
    - "app/Http/Controllers/ProjectDrawingController.php"
  modified:
    - "app/Models/Device.php"
    - "app/Models/Project.php"
    - "app/Services/DocumentArtifactStorage.php"
    - "app/Services/PdfRenderService.php"
    - "app/Services/DocumentEdits/DocumentEditAdapterRegistry.php"
    - "app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php"
    - "app/Providers/AppServiceProvider.php"
    - "routes/web.php"

key-decisions:
  - "Single project_drawings table with kind discriminator over three near-identical models (mirrors H-07)"
  - "TYPE_DRAWING is a single constant; sub-kind lives in filename convention drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}"
  - "DrawingService.generateInitial separated from regenerate — first version doesn't archive a prior that doesn't exist (Warning 9 fix)"
  - "PdfRenderService.fromBladeAsPng added at Phase 17 so Plan 03 / Phase 20 reuse the central Browsershot construction (Warning 8)"
  - "DrawingEditAdapter is scaffolding-only at Phase 17; functional schematic chat lands in Phase 19 alongside the Konva editor (CONTEXT.md GAP-4)"
  - "BuildSchematicJob handle() body has explicit Plan 17-01 placeholder markers so Plan 02 has a clean replacement target"

patterns-established:
  - "DocumentArtifactStorage post-H-07 type registration (TYPE_DRAWING joins TYPE_SNAGGING — neither has a LEGACY_ROOTS entry)"
  - "Drawing regenerate flow: replicate row → bump version → archive prior (transactional) → dispatch job after commit"
  - "PdfRenderService extension contract: pass-through `waitForJs` option works on BOTH fromBlade (PDF) and fromBladeAsPng (PNG)"

requirements-completed:
  - DRAW-24
  - DRAW-25
  - "DRAW-30 (scaffolding only — functional schematic chat lands in Phase 19)"

duration: 12min
completed: 2026-05-01
---

# Phase 17 Plan 01: Foundations Summary

**Shared drawings infrastructure (table, model, policy, storage type, render extensions, job + mail skeleton, edit-adapter scaffolding) landed so Phases 17-02/03 and 18/19/20 are pure additions — never re-architects.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-05-01T15:35:11Z
- **Completed:** 2026-05-01T15:47:00Z (approx)
- **Tasks:** 3 of 3 complete
- **Files created:** 11
- **Files modified:** 8

## Accomplishments

- **Schema landed:** `project_drawings` table (with the full column set Phases 18/19 will use, even where stubbed) + `devices.signal_role` for CRIT-05 protection. Both migrations applied cleanly via `php artisan migrate`.
- **Render extensions in one place:** `PdfRenderService::fromBlade` accepts a `waitForJs` option (default false — every existing call site byte-for-byte identical) and a brand-new `fromBladeAsPng()` method shares the same Browsershot construction. Plan 03's PNG renderer and Phase 20's hardening (CRIT-03 chrome flags) both ride on this single seam.
- **DrawingService method matrix:** `createForProject` / `generateInitial` / `regenerate` / `archivePrior` — `generateInitial` splits cleanly from `regenerate` so the first version never archives a non-existent predecessor (Warning 9 fix locked into the plan).
- **Edit-adapter scaffolding:** `DrawingEditAdapter` registered in `DocumentEditAdapterRegistry` (`'drawing' => DrawingEditAdapter::class`) and the parser factory (`drawingSnapshot` exposes only project_ref / kind / status / version / has_canvas_state — no equipment lists, no PII). Layout-only operation enum (`set_status` / `set_revision_note` / `add_layout_hint`) — AI MAY NEVER add equipment, cables, or rooms.
- **Routes wired:** `projects.drawings.{index, show, regenerate}` registered in the authenticated group. `php artisan route:list --name=drawings` shows the three routes.

## Task Commits

Each task was committed atomically with hooks (Pint formatting fixes applied per commit):

1. **Task 1: Migrations + Device classification** — `91fbffd` (feat)
2. **Task 2: Model + Policy + Storage + PdfRenderService extension (waitForJs + fromBladeAsPng)** — `e4cdfc3` (feat)
3. **Task 3: Drawing services + Job + Mail + EditAdapter + Routes + Controller shell** — `5da6d47` (feat)

## DrawingService Method Matrix

| Method               | Caller                       | Archives prior? | Dispatches BuildSchematicJob? | Wrapped in DB::transaction? |
| -------------------- | ---------------------------- | --------------- | ----------------------------- | --------------------------- |
| `createForProject()` | Plan 03 controller create    | no              | no                            | no                          |
| `generateInitial()`  | Plan 03 controller create (next call after createForProject) | no              | yes                           | no (single update + dispatch) |
| `regenerate()`       | `ProjectDrawingController::regenerate` (Plan 17-01) | yes             | yes (after commit)            | yes (replicate + supersede inside txn) |
| `archivePrior()`     | internal (called inside `regenerate()`'s txn) | n/a (this IS the archive step) | no                | called from inside the parent txn |

## New PdfRenderService API

`PdfRenderService::fromBladeAsPng(string $view, array $data, ?string $writeToPath = null, array $options = []): string`

- Same Browsershot construction as `fromBlade` — chrome path, no-sandbox, `--disable-dev-shm-usage`, `--disable-setuid-sandbox`.
- Options: `waitForJs` (bool), `widthPx` (int, default 1920), `heightPx` (int, default `widthPx * 0.707`).
- Plan 03 Task 1 (`DrawingExportRendererService::renderPng`), Phase 19 Konva PNG export, and Phase 20 thumbnails ALL go through this method so future hardening (CRIT-03 chrome flags, dedicated queue) lands in one place.

## Files Created/Modified

### Created (11)

- `database/migrations/2026_05_01_000001_create_project_drawings_table.php` — table schema (DRAW-24 + DRAW-25 + access_token forward-compat)
- `database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php` — `signal_role` nullable column after `part_no`
- `app/Models/ProjectDrawing.php` — Eloquent model with KIND_*/STATUS_* constants + helpers
- `app/Policies/ProjectDrawingPolicy.php` — view/update/delete (owner-or-admin)
- `app/Services/Drawings/DrawingService.php` — createForProject / generateInitial / regenerate / archivePrior
- `app/Services/Drawings/DrawingDataResolverService.php` — adjacencyForProject shape (Plan 02 fills body) + Phase 18/19 throws
- `app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php` — DRAW-30 scaffolding (layout-only ops)
- `app/Jobs/BuildSchematicJob.php` — full handle() + failed() bodies (placeholder SVG; Plan 02 replaces the marked block)
- `app/Mail/DrawingReadyMail.php` — single mailable, kind-discriminated subjects
- `resources/views/emails/drawing-ready.blade.php` — completion email body
- `app/Http/Controllers/ProjectDrawingController.php` — index / show (JSON) / regenerate

### Modified (8)

- `app/Models/Device.php` — ROLE_* constants + isSource/isDestination/isProcessor/hasUnknownSignalRole helpers
- `app/Models/Project.php` — `drawings()` HasMany relation
- `app/Services/DocumentArtifactStorage.php` — TYPE_DRAWING constant added to types() (no LEGACY_ROOTS entry)
- `app/Services/PdfRenderService.php` — fromBlade waitForJs option + new fromBladeAsPng method
- `app/Services/DocumentEdits/DocumentEditAdapterRegistry.php` — `'drawing' => DrawingEditAdapter::class` in DEFAULT_MAP
- `app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php` — drawing snapshot arm + private `drawingSnapshot` method
- `app/Providers/AppServiceProvider.php` — `Gate::policy(ProjectDrawing::class, ProjectDrawingPolicy::class)`
- `routes/web.php` — three routes wired in authenticated group, ProjectDrawingController imported

## Decisions Made

All key decisions were locked in `17-CONTEXT.md` and the canonical research files; the executor implemented them verbatim. No new decisions arose during execution. Notable plan-locked choices reaffirmed by the codebase:

- Devices table uses `vlan` and `port` columns (added by `2026_04_28_152000_add_vlan_port_to_devices_table.php`) — already in `Device::$fillable`, so no impact on the Phase 17 `signal_role` migration which simply uses `->after('part_no')` per the planner-verified column position.

## Deviations from Plan

None — plan executed exactly as written. Pint applied stylistic formatting fixes (PSR-12 base) on every commit; those changes were intentional and idempotent (no semantic change). The reformatted lines in Project.php / AppServiceProvider.php / DocumentEditParsingPromptFactory.php / etc. were untouched logic — just spacing/quote alignment normalised by the linter.

## Next Steps

- **Plan 17-02 (Schematic Generator):** D2 CLI install, AV symbol pack, `SchematicGeneratorService` consuming `DrawingDataResolverService::adjacencyForProject()`, `BuildSchematicJob::handle()` body replacement (the Plan 17-01 placeholder block has explicit `// ── Plan 17-01 placeholder body ──` markers for clean replacement).
- **Plan 17-03 (Render UI + Handover):** index Blade view, status state machine UI for DRAW-25, per-format download endpoints (PDF / SVG / PNG), `DrawingExportRendererService` riding on `PdfRenderService::fromBladeAsPng`.
- **Phases 18 / 19 / 20:** rack elevations + floor plans (Konva) + drawing export & O&M wiring — all land as pure additions on this foundation.

## Self-Check: PASSED

- `git log --oneline | grep -E "91fbffd|e4cdfc3|5da6d47"` — three task commits present.
- `Schema::hasTable('project_drawings')` — PASS
- `Schema::hasColumn('devices', 'signal_role')` — PASS
- `Schema::hasColumn('devices', 'part_no')` — PASS (verified BEFORE the new migration ran)
- `in_array('drawings', DocumentArtifactStorage->types(), true)` — PASS
- `method_exists(PdfRenderService, 'fromBladeAsPng')` — PASS (Warning 8)
- `method_exists(DrawingService, 'generateInitial')` — PASS (Warning 9)
- `method_exists(DrawingDataResolverService, 'adjacencyForProject')` — PASS (Warning 5)
- `app(DocumentEditAdapterRegistry)->for('drawing')->documentType()` returns `drawing` — PASS
- `php artisan route:list --name=drawings` — 3 routes (index / show / regenerate)
- `php -l` — clean on all 11 new + 8 modified files
