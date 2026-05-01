---
phase: 17-system-schematics-shared-foundations
plan: 03
subsystem: drawings-render-ui-handover
tags: [drawings, schematic, render-ui, om-handover, pdf-smoke-test, draw-05, draw-06, draw-26, draw-27, blocker-3, warning-6, warning-8, warning-9]

requires:
  - phase: 17-01
    provides: PdfRenderService::fromBladeAsPng (Warning 8 seam), DrawingService.generateInitial (Warning 9), ProjectDrawing model + status helpers, ProjectDrawingController shell, projects.drawings.{index,show,regenerate} routes, DocumentEditAdapterRegistry::for('drawing') with set_status op
  - phase: 17-02
    provides: BuildSchematicJob with Plan 17-03 thumbnail-render insertion-point comment marker, real generator wired (placeholder removed), pdf.drawings.schematic Blade view, AV symbol pack, signal-type colour map
provides:
  - DrawingExportRendererService — single PDF/SVG/PNG entrypoint; PNG path delegates to PdfRenderService::fromBladeAsPng (Warning 8 — no inline Browsershot import)
  - DrawingExportRendererService::ensurePngForHandover — idempotent per-version PNG cache for DRAW-26 O&M handover
  - BuildSchematicJob now writes thumbnail_png_path after generator success and before completion email send (Warning 6 disjoint region)
  - ProjectDrawingController — index returns Blade view, show returns Blade view (replaces Plan 01 JSON stub), createSchematic uses createForProject + generateInitial (Warning 9), updateStatus routes through DrawingEditAdapter::set_status, download per-format with policy + format whitelist
  - resources/views/projects/drawings/{index,show,_status-pill,_regenerate-confirm-modal}.blade.php — Drawings list + preview pages with Alpine modal + status pill partial
  - OmManualDocxService::build appends Drawings section via fresh $drawingsSection = $phpWord->addSection(...) (Blocker 3 fix) — DRAW-26 PNG embed, one drawing per page
  - pdf:smoke-test --drawings flag — renders real schematic when available, falls back to in-memory placeholder fixture otherwise
  - resources/views/projects/show.blade.php — additive "Drawings" header link with current-revision count badge (existing tabs untouched)
  - 3 new routes: projects.drawings.{create-schematic, download, update-status}
affects: [phase-18-rack-elevations, phase-19-floor-plans, phase-20-drawing-export-om]

tech-stack:
  added: []
  patterns:
    - "PNG export delegation — DrawingExportRendererService::renderPng calls PdfRenderService::fromBladeAsPng, never instantiates Browsershot directly. Phase 20's CRIT-03 hardening flows in via PdfRenderService and renderPng picks it up automatically."
    - "Per-version handover PNG cache — drawings/handover-png/drawing-{id}-v{version}.png. ensurePngForHandover() reads-then-writes so repeated O&M generation calls reuse the cached file."
    - "PhpWord section-per-major-block discipline — Drawings section opens its own $drawingsSection rather than reusing $s from a prior section (Blocker 3)."
    - "createSchematic = createForProject + generateInitial (NOT regenerate). First version is R0 (version=1) with no archived predecessor (Warning 9)."
    - "UI-driven status flips route through DrawingEditAdapter::set_status — same allow-list (draft/for_review/approved) as chat-driven edits."
    - "Lock-on-edit modal as DRAW-05 UX scaffolding only — Alpine x-data modal with hasUserEdits-aware copy. Functional Konva editor lands in Phase 19."

key-files:
  created:
    - "app/Services/Drawings/DrawingExportRendererService.php"
    - "resources/views/projects/drawings/index.blade.php"
    - "resources/views/projects/drawings/show.blade.php"
    - "resources/views/projects/drawings/_status-pill.blade.php"
    - "resources/views/projects/drawings/_regenerate-confirm-modal.blade.php"
    - ".planning/phases/17-system-schematics-shared-foundations/deferred-items.md"
  modified:
    - "app/Jobs/BuildSchematicJob.php"
    - "app/Http/Controllers/ProjectDrawingController.php"
    - "app/Console/Commands/PdfSmokeTestCommand.php"
    - "app/Services/OmManualDocxService.php"
    - "resources/views/projects/show.blade.php"
    - "routes/web.php"

key-decisions:
  - "DrawingExportRendererService delegates PNG to PdfRenderService::fromBladeAsPng — no Browsershot import (Warning 8 paid off; Phase 20 CRIT-03 hardening lands in one place)"
  - "ensurePngForHandover caches under drawings/handover-png/drawing-{id}-v{version}.png at 1280px wide (slightly smaller than full to keep DOCX size manageable)"
  - "thumbnail render in BuildSchematicJob is non-fatal — SVG remains the primary artifact, a missing thumbnail won't block the completion email"
  - "createSchematic uses generateInitial (NOT regenerate) — first version is R0 with NO phantom archived sibling (Warning 9)"
  - "Drawings link added to project show header actions slot rather than as a new tab — minimises disruption to the existing rich tab structure (additive only)"
  - "status select on show page omits superseded/generating/ready/failed — those are pipeline-controlled, only draft/for_review/approved are user-facing (DRAW-25 UX decision)"
  - "Lock-on-edit modal is DRAW-05 SCAFFOLDING ONLY — Phase 17 ships UX, Phase 19 ships functional editor"

patterns-established:
  - "Per-format download endpoint shape — projects/{project}/drawings/{drawing}/download/{format} with format whitelist (pdf|svg|png) enforced via Route::where regex AND in_array() guard. Phase 18 (rack PDF/SVG) and Phase 19 (floor plan PDF/SVG/PNG) reuse the same DrawingExportRendererService entrypoint by extending bladeViewFor()."
  - "DRAW-26 O&M PNG-embed pattern — fetch ready+non-superseded drawings, ensurePngForHandover per drawing, addImage with width=500/height=null/wrappingStyle=square/alignment=Jc::CENTER, addPageBreak between drawings."
  - "pdf:smoke-test --drawings extension shape — branch on the option, prefer real fixture, fall back to in-memory ProjectDrawing with hard-coded generated_svg. Phase 20 CRIT-04 chrome-version-drift extension can layer chrome-version reporting on top."

requirements-completed:
  - DRAW-05 (UX scaffolding only — full editor in Phase 19)
  - DRAW-06
  - DRAW-26
  - DRAW-27

duration: ~11min
completed: 2026-05-01
---

# Phase 17 Plan 03: Render UI + O&M Handover Summary

**Closed the Phase 17 user journey: click Generate Schematic → schematic appears → preview → download (PDF / SVG / PNG) → embed in O&M handover. DrawingExportRendererService rides on PdfRenderService::fromBladeAsPng (Warning 8 paid off). createSchematic uses generateInitial so first version is R0 not R1-with-phantom-sibling (Warning 9). OmManualDocxService Drawings section opens a fresh `$drawingsSection` rather than reusing `$s` (Blocker 3 fix).**

## Performance

- **Duration:** ~11 min
- **Started:** 2026-05-01T17:27:28Z
- **Completed:** 2026-05-01T17:38:16Z
- **Tasks:** 3 of 3 complete
- **Files created:** 6
- **Files modified:** 6

## Routes Added

| # | Method | URI                                                       | Name                                  |
|---|--------|-----------------------------------------------------------|----------------------------------------|
| 1 | POST   | projects/{project}/drawings/create-schematic              | projects.drawings.create-schematic     |
| 2 | GET    | projects/{project}/drawings/{drawing}/download/{format}    | projects.drawings.download             |
| 3 | PUT    | projects/{project}/drawings/{drawing}/status               | projects.drawings.update-status        |

`projects.drawings.{index, show, regenerate}` already landed in Plan 17-01 — total drawings route count is now 6.

## Task Commits

Each task committed atomically with hooks:

1. **Task 1: DrawingExportRendererService + Job wiring + downloads + smoke test** — `d21b53e` (feat)
2. **Task 2: Drawings index + show views + status controls + project page link** — `0a7f41b` (feat)
3. **Task 3: O&M Manual handover wiring (DRAW-26 — Blocker 3 fix)** — `bfb3785` (feat)

## Critical Constraint Verification

| Constraint     | Check                                                                                          | Result |
|----------------|------------------------------------------------------------------------------------------------|--------|
| Warning 8      | `! grep -q "use Spatie.Browsershot" app/Services/Drawings/DrawingExportRendererService.php`    | PASS   |
| Blocker 3 (a)  | `grep -q "drawingsSection = .phpWord->addSection" app/Services/OmManualDocxService.php`        | PASS   |
| Blocker 3 (b)  | `! grep -q "\\\$section->" app/Services/OmManualDocxService.php`                              | PASS   |
| Warning 9      | `grep -q "generateInitial" app/Http/Controllers/ProjectDrawingController.php`                  | PASS   |
| Warning 9 (b)  | `regenerate(` only appears inside the regenerate() action, NOT inside createSchematic()        | PASS   |
| Warning 6      | `grep -nc "DrawingReadyMail\|completion_email_sent_at" app/Jobs/BuildSchematicJob.php` ≥ 2     | PASS (returned 6) |
| Routes         | `php artisan route:list --name=drawings` lists 6 routes (index/show/regenerate/download/create-schematic/update-status) | PASS |
| Lint           | `php -l` clean on all 7 modified/created PHP files                                             | PASS   |
| view:cache     | `php artisan view:cache` compiles all Blade views without error                                | PASS   |

## DrawingExportRendererService API

```php
// PDF — delegates to PdfRenderService::fromBlade
public function renderPdf(ProjectDrawing $drawing): string

// SVG — writes generated_svg directly (no Browsershot involved)
public function renderSvg(ProjectDrawing $drawing): string

// PNG — delegates to PdfRenderService::fromBladeAsPng (Warning 8)
public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string

// Idempotent per-version PNG cache for O&M handover (DRAW-26)
// Returns null when drawing is not ready (handover gracefully skips it)
public function ensurePngForHandover(ProjectDrawing $drawing): ?string
```

Filename convention (per ARCHITECTURE.md §6.1):
- One-shot: `drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}`
- Handover cache: `drawings/handover-png/drawing-{id}-v{version}.png`

`renderPdf/renderSvg/renderPng` throw `RuntimeException` when status is not ready (cannot render an in-progress drawing). `ensurePngForHandover` returns `null` for not-ready drawings so the O&M Word doc gracefully skips them.

## Phase 19 Plug-in Points

Marked with explicit TODO comments so Phase 19 implementers see exactly where to plug in:

1. **`resources/views/projects/drawings/show.blade.php`** — comment block beneath the status form references `document-edit-drawer` for DRAW-30 chat scaffolding hookup. The `DrawingEditAdapter` is already registered in `DocumentEditAdapterRegistry`, so wiring is one `@include` away.
2. **`DrawingExportRendererService::bladeViewFor()`** — `KIND_RACK` and `KIND_FLOOR_PLAN` arms throw `RuntimeException` with explicit phase pointers. Phase 18 swaps the rack arm to `'pdf.drawings.rack'`; Phase 19 swaps the floor-plan arm to `'pdf.drawings.floor-plan'`. The PNG path is automatic — same Blade view fed to `fromBladeAsPng`.
3. **Lock-on-edit modal (`_regenerate-confirm-modal.blade.php`)** — Phase 19 enriches the modal copy when the Konva editor lands; the Alpine `hasUserEdits` arm already differentiates between edited and pristine drawings, so the prose can deepen without a structural rewrite.

## Phase 20 Hand-off Notes

- **CRIT-03 chrome-flag hardening** lands in `PdfRenderService` (the central Browsershot construction). `DrawingExportRendererService::renderPng` rides on `fromBladeAsPng` so picks up the hardening automatically — Warning 8 paid off.
- **CRIT-04 chrome-version-drift** extends `pdf:smoke-test --drawings` (delivered in this plan) with a chrome-version reporter. The smoke command's `renderDrawingSmoke()` body is a clean grafting point.
- **MOD-10 versioned filenames** — partial coverage already in this plan via the `drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}` pattern. Phase 20 adds the `regen_recommended` flag on the drawings table to mark stale O&M attachments.
- **DXF stretch / ZIP bundle** — both fold into the renderer service: a new `renderDxf()` method (DXF format) and a new `renderBundle()` method (ZIP of all formats). Existing `renderPdf/renderSvg/renderPng` remain untouched.
- **Dedicated drawings queue** — flip the `BuildSchematicJob` queue connection in `config/queue.php`. The job is already `ShouldQueue`-shaped; only the configuration changes.

## Phase 18 Pointers

Rack elevations are a pure addition on top of Phase 17 foundations:

- `TYPE_DRAWING` storage type — already in place.
- `ProjectDrawing` model with `KIND_RACK` constant — already in place.
- `BuildSchematicJob` shape (idempotent completion, `failed()` admin alert, `DrawingReadyMail`) — copy-paste-and-rename to `BuildRackJob` in Phase 18.
- `DrawingEditAdapter` — Phase 18 enriches the `set_status` op with rack-specific operations (e.g., `swap_rack_position`).
- `DrawingExportRendererService::bladeViewFor` — Phase 18 swaps the `KIND_RACK` throw for `'pdf.drawings.rack'`. Three lines of code.

## Deviations from Plan

None — plan executed exactly as written. Three minor implementation notes:

1. **Modal form action** — built dynamically in JavaScript via `:action="`{{ url(...) }}/${drawingId}/regenerate`"` rather than relying on Alpine to compose route helpers (which it can't from JS scope). Functionally identical to the plan; just clearer.
2. **Status pill colour scheme** — added inline `<style>` in index.blade.php / show.blade.php to provide concrete `.badge-grey/yellow/green/teal/blue/red` rules. The model's `statusBadgeClass()` returns these class names; matching CSS is needed for them to render. (Existing site-survey pages use a similar inline-style pattern.)
3. **Drawings link placement** — added to the project show header `<x-slot name="actions">` rather than as a new tab. Minimises diff against the rich existing tab structure (8 tabs already there); engineers find the link in the same row as "Edit Project Data".

Pint applied stylistic formatting (PSR-12 base) on every commit; reformatted lines were untouched logic — just spacing/quote alignment normalised.

## Deferred Issues

One pre-existing failing test discovered during verification, NOT caused by Plan 17-03:

- `OmManualProjectLinkageTest::test_project_show_page_displays_om_manuals_section_for_project` — asserts literal `'O&amp;M Manuals'` but the show page tab uses `'O&M'` (verified failing both before and after Plan 17-03 changes via `git stash` rollback). Logged in `deferred-items.md` for separate `/gsd-quick` follow-up.

## Threat Model — Verification

| Threat ID | Mitigation | Verified by |
|-----------|------------|-------------|
| T-17.03-01 (download EoP) | `authorize('view', $drawing)` + project_id match check + format whitelist (pdf/svg/png only) | Code review |
| T-17.03-02 (status tampering) | DrawingEditAdapter restricts `set_status` to draft/for_review/approved; CSRF on form | Code review |
| T-17.03-03 (cross-project disclosure) | `if ($drawing->project_id !== $project->id) abort(404)` guard in download/show/updateStatus | Code review |
| T-17.03-04 (Blade view selection tampering) | `bladeViewFor()` matches kind against KIND_* constants only; unknown kinds throw | Code review |
| T-17.03-05 (Browsershot PNG render) | renderPng delegates to PdfRenderService::fromBladeAsPng — same noSandbox + chromium-args. CHROME_PATH from env. | Warning 8 grep check (PASS) |
| T-17.03-06 (renderPng DoS) | Phase 17 thumbnails are 400px-wide (small). Phase 20 CRIT-03 lands the dedicated queue + memory probe. | Code review |
| T-17.03-07 (OmManualDocxService addImage path) | PNG path resolved via ensurePngForHandover → DocumentArtifactStorage::readPath. is_file() guard. | Code review |
| T-17.03-08 (archived versions in handover) | `whereNull('superseded_by_id')` filter applied in OmManualDocxService AND drawings index controller | Code review |
| T-17.03-09 (BuildSchematicJob co-edit) | `depends_on: ["17-01", "17-02"]` enforced sequential commit; mail dispatch grep returns 6 hits post-Plan-03 (≥2 required) | Warning 6 grep check (PASS) |
| T-17.03-10 (createSchematic UX bug) | createSchematic calls `generateInitial` (NOT `regenerate`); regenerate only appears inside the regenerate action | Warning 9 grep check (PASS) |

## Self-Check: PASSED

- `git log --oneline | grep -E "d21b53e|0a7f41b|bfb3785"` — three task commits present.
- `app/Services/Drawings/DrawingExportRendererService.php` — exists, no Browsershot import.
- `app/Services/OmManualDocxService.php` — Drawings section uses `$drawingsSection` (no `$section->` references).
- `app/Http/Controllers/ProjectDrawingController.php` — `generateInitial` called in `createSchematic`; `regenerate` only inside regenerate() action.
- `app/Jobs/BuildSchematicJob.php` — thumbnail block + mail dispatch both present (DrawingReadyMail/completion_email_sent_at grep returns 6).
- `php artisan route:list --name=drawings` — 6 routes (index, show, regenerate, download, create-schematic, update-status).
- `php artisan view:cache` — compiles all 4 new Blade views without error.
- `php -l` — clean on all 7 modified/created PHP files.
- `php artisan list | grep pdf:smoke-test` — command registered (signature includes `--drawings` flag).
- Pre-existing OmManualProjectLinkageTest failure logged in deferred-items.md (NOT caused by Plan 17-03; verified via git stash rollback).
