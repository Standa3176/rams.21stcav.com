---
phase: 18-rack-elevations
plan: 18-03-rack-editor
subsystem: drawings/rack-elevations
tags: [phase18, drawings, racks, rack-editor, sortable, blade-svg, render]
dependency-graph:
  requires:
    - "Phase 17 ProjectDrawing model + KIND_RACK + STATUS_DRAFT/READY/FAILED"
    - "Phase 17 PdfRenderService::fromBlade + fromBladeAsPng (extended for kind=rack)"
    - "Phase 17 DrawingExportRendererService PDF/SVG/PNG endpoints"
    - "Plan 18-01 Device.u_height + is_rack_mounted columns (CRIT-06 nullable-first)"
    - "Plan 18-01 DeviceCatalogService::lookupByPartNo (case-insensitive trimmed)"
    - "Plan 18-01 DrawingDataResolverService::rackStackForProject (locked palette shape)"
    - "Plan 18-01 ProjectDrawingController::picker + createRack (rack scaffold)"
  provides:
    - "RackElevationRenderService::render(ProjectDrawing): string — synchronous custom Blade SVG renderer (~340 LOC)"
    - "ProjectDrawingController::editRack + saveRackCanvas + flipRackMountedFlag (controller actions)"
    - "App\\Policies\\ProjectPolicy (owner-OR-admin via Project::user_id + isAdmin())"
    - "resources/views/projects/drawings/rack-edit.blade.php (palette + 42U scaffold + Save UI)"
    - "resources/views/pdf/drawings/rack.blade.php (landscape A4 PDF Blade view)"
    - "resources/js/rack-editor.js (Alpine x-data + Sortable.js + AJAX save + cursor-walk lock-aware reorder)"
    - "Sortable.js dependency + dedicated Vite entry (out of global Alpine bundle)"
    - "3 new routes: projects.drawings.edit + .rack-canvas + .flip-rack-mounted (throttled 60/min)"
  affects:
    - "DrawingExportRendererService::bladeViewFor — rack arm now returns 'pdf.drawings.rack' (no longer throws)"
    - "show.blade.php — Edit Rack button added next to Download buttons (existing line-55 SVG render branch UNCHANGED, Warning 9)"
    - "AppServiceProvider::boot — Gate::policy(Project::class, ProjectPolicy::class) registered"
tech-stack:
  added:
    - "sortablejs ^1.15.7 (rack DnD reorder)"
  patterns:
    - "Custom Blade SVG renderer (no D2, no Konva) — list-shaped fits string concat better than graph engines"
    - "Synchronous render on save — no BuildRackElevationJob queue dispatch"
    - "Cursor-walk lock-aware reorder algorithm in client JS, asserted by server-side round-trip test"
    - "Kind-agnostic SVG render branch in show.blade.php — single source of truth, lit up by status flip from saveRackCanvas"
key-files:
  created:
    - "app/Services/Drawings/RackElevationRenderService.php"
    - "app/Policies/ProjectPolicy.php"
    - "resources/views/pdf/drawings/rack.blade.php"
    - "resources/views/projects/drawings/rack-edit.blade.php"
    - "resources/js/rack-editor.js"
    - "tests/Feature/Drawings/RackElevationRenderServiceTest.php"
    - "tests/Feature/Drawings/RackEditorEndpointsTest.php"
  modified:
    - "app/Services/Drawings/DrawingExportRendererService.php"
    - "app/Http/Controllers/ProjectDrawingController.php"
    - "app/Providers/AppServiceProvider.php"
    - "routes/web.php"
    - "resources/views/projects/drawings/show.blade.php"
    - "vite.config.js"
    - "package.json"
    - "package-lock.json"
decisions:
  - "Synchronous render (no BuildRackElevationJob) — RackElevationRenderService runs in-band on saveRackCanvas. Asserted by test_render_completes_within_one_second_for_full_rack (1s budget for 30-item full 42U rack — actual measured 0.06s on dev)."
  - "CRIT-06 enforced — items whose u_height resolves to null (no override + catalog miss) render with a 1U layout placeholder AND surface a 'U-height unknown:' warning region listing every affected device name. Verified by test_render_unknown_u_height_surfaces_warning."
  - "DRAW-12 totals footer with asterisks + ratios — partial-data totals render 'Weight: 1.8 kg* (1/2 known)' style; all-known totals drop the asterisk. tooltip <title> on the warning row carries the full device list."
  - "flipRackMountedFlag authorises against ProjectPolicy::update (owner-OR-admin on the Project), NOT against any specific Drawing. Endpoint reachable BEFORE the engineer creates their first rack drawing — Blocker 2 fix from checker iteration 2. Regression test test_flip_rack_mounted_works_before_any_rack_drawing_exists locks it."
  - "show.blade.php Edit Rack button placed next to Download/Regenerate; the existing kind-agnostic SVG render branch (now line 66, unchanged condition) handles rack SVG display once status flips to ready. No second render branch added — Warning 9 fix."
  - "Sortable.js loaded ONLY via the rack-editor.js Vite entry. Build outputs assets/rack-editor-*.js as a 39 kB / 14 kB-gzipped chunk separate from the 94 kB Alpine app bundle — kept off /dashboard, /projects, etc."
  - "Locked-item cursor walk lives in JS (rack-editor.js onRackReorder); server faithfully persists what the client sends. The contract is asserted by test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it (Warning 7 fix)."
  - "App\\Policies\\ProjectPolicy added in this plan — Phase 17 didn't ship one despite the plan's reuse note. Owner-OR-admin via Project::user_id; mirrors ProjectController's inline ownership checks. Registered in AppServiceProvider::boot alongside the other three policies."
metrics:
  duration: "~12 min"
  tasks: 3
  files_created: 7
  files_modified: 8
  tests_added: 20
  test_assertions_added: 99
  completed: "2026-05-02"
---

# Phase 18 Plan 03: Rack Editor + Synchronous Render + Drag-Into-U-Slots Summary

**One-liner:** Lands the synchronous custom Blade SVG `RackElevationRenderService` (~340 LOC, 1s for full 42U/30-items), the Alpine + Sortable.js drag-into-U-slots editor with cursor-walk lock-aware reorder, the AJAX save endpoint that persists + renders + flips status to ready in one round-trip, and the PDF/SVG/PNG export pipeline lit up for `kind=rack` — closing out Phase 18 with DRAW-07/08/09(partial)/10/11/12/13 covered.

## Outcome

Engineers now have a complete rack-build workflow:

1. **Pick** — `+ Create Drawing` → Rack on the project drawings index (Plan 18-01) creates a 42U scaffold drawing in `STATUS_DRAFT`, redirects to its show page.
2. **Edit** — On the show page (kind=rack), an **Edit Rack** button (this plan) loads `/projects/{p}/drawings/{r}/edit` with a palette on the left (rack-mounted equipment FIRST, other equipment SECOND/greyed) and a 42U U-numbered scaffold on the right.
3. **Drag** — Sortable.js DnD: drag from palette → rack column. Drag-reorder within the column. Per-item lock toggle. The cursor walks bottom-up over the reordered DOM, locked items keep their U-position verbatim, unlocked items get cursor-assigned U-positions with the cursor jumping over locked ranges.
4. **Save** — Click `Save Rack` → AJAX POST → server validates (typed allow-list) → persists to `source_data.rack_items` → runs `RackElevationRenderService::render` synchronously → writes `generated_svg` → flips `status` to READY → page reloads → SVG visible via the existing kind-agnostic line-66 render branch on `show.blade.php`.
5. **Download** — `DrawingExportRendererService` PDF/SVG/PNG endpoints all light up automatically — `bladeViewFor` rack arm now returns `'pdf.drawings.rack'` (landscape A4 + title block + embedded SVG).

## RackElevationRenderService API + measured render time

| Method | Returns | Notes |
|--------|---------|-------|
| `render(ProjectDrawing $drawing): string` | Complete `<svg>` document | Throws RuntimeException for kind != rack OR rack_height_u out of range (1..99). |

**Measured render time** for a full 42U rack with 30 items: **~0.06s** on dev (PHP 8.4.19, Windows). Plan budget was <1s; actual is 16x under budget. Asserted by `test_render_completes_within_one_second_for_full_rack`.

**Layout constants** (`private const`):

| Constant | Value | Purpose |
|----------|-------|---------|
| `U_HEIGHT_PX` | 24 | 1U vertical pixel height |
| `RACK_WIDTH_PX` | 380 | Visual width of the rack frame |
| `RAIL_LABEL_WIDTH_PX` | 28 | Left rail "U number" column width |
| `FOOTER_HEIGHT_PX` | 110 | Totals footer height below the rack |
| `HEADER_HEIGHT_PX` | 32 | Rack-label + height header above |
| `PADDING_PX` | 16 | Overall canvas padding |
| `FONT_FAMILY` | `'Helvetica Neue', Arial, sans-serif` | SVG `<style>` font |

**Render composition** (single pass over `rack_items`):
1. `renderHeader` — rack label + heightU + voltage in the top-left corner.
2. `renderFrame` — outer rack rectangle.
3. `renderRail` — heightU numbered ticks on the left, 1 at the BOTTOM.
4. `renderItems` — per-item `<rect>` + `<text>` label; mutates `$unknownDevices` (CRIT-06 warning fed) + `$totals` (footer asterisks/ratios fed).
5. `renderFooter` — totals lines + U-utilisation + warning row (when present).

**Critical resolver chain** for each metric (weight_kg / current_draw_a / btu_per_hour / u_height):
1. Per-item override on `rack_items[i]`.
2. `DeviceCatalogService::lookupByPartNo(part_no)` (case-insensitive trimmed).
3. `null` — surfaced as asterisk + ratio in the footer; for `u_height` ALSO triggers the "U-height unknown" warning region (CRIT-06).

## PDF Blade view location + landscape A4 setup

**Path:** `resources/views/pdf/drawings/rack.blade.php`

```blade
@page { size: A4 landscape; margin: 12mm; }
```

Mirrors `pdf/drawings/schematic.blade.php`:
- `page-break-inside: avoid` keeps the rack + title block atomic.
- SVG `<text>` font forced to Arial / Liberation Sans / DejaVu Sans (the Browsershot font-fallback trap from Phase 17 deployment runbook).
- Title block partial: `pdf.drawings._title-block` (shared with schematic).
- `{!! $drawing->generated_svg !!}` is safe — every SVG `<text>` content went through `htmlspecialchars()` before SVG emission (T-18.03-02).

## Routes added + throttle middleware

| Route Name | Method | URI | Throttle | Purpose |
|------------|--------|-----|----------|---------|
| `projects.drawings.edit` | GET | `/projects/{project}/drawings/{drawing}/edit` | (none) | Rack editor page (only kind=rack — guard 404s otherwise). |
| `projects.drawings.rack-canvas` | POST | `/projects/{project}/drawings/{drawing}/rack-canvas` | `60/min` | AJAX save — validates JSON, runs render, flips status to ready/failed. |
| `projects.drawings.flip-rack-mounted` | POST | `/projects/{project}/drawings/flip-rack-mounted` | `60/min` | Flip Device.is_rack_mounted from palette OR project-package review. **Project-scoped — NO `{drawing}` segment.** Authorises against `ProjectPolicy::update` so it works pre-rack (Blocker 2 fix). |

`flip-rack-mounted` is intentionally project-scoped (no `{drawing}`) — the engineer can flip a Device's rack-mounted flag from the project-package review page BEFORE creating any rack drawing. The previous draft of this plan called `firstOrFail()` on the project's latest rack drawing and would 404 in that case. Authorisation now goes through the new `App\Policies\ProjectPolicy::update` (owner-OR-admin via `Project::user_id` + `User::isAdmin()`).

`route:list --name=drawings` now shows **11 routes** (6 Phase 17 + 2 Plan 18-01 + 3 Plan 18-03).

## Sortable.js + new Vite entry

**package.json:**
```diff
 "dependencies": {
-    "frappe-gantt": "^1.2.2"
+    "frappe-gantt": "^1.2.2",
+    "sortablejs": "^1.15.6"
 }
```

Installed as `sortablejs@1.15.7` (latest patch within ^1.15.6).

**vite.config.js:**
```diff
-input: ['resources/css/app.css', 'resources/js/app.js'],
+input: [
+    'resources/css/app.css',
+    'resources/js/app.js',
+    'resources/js/rack-editor.js',
+],
```

**`npm run build` outputs:**
- `assets/app-*.js` 94.33 kB / gzip 29.71 kB (Alpine app — unchanged)
- `assets/rack-editor-*.js` 39.73 kB / gzip 13.89 kB (NEW — Sortable.js + the Alpine factory)
- `_index-*.js` 37.09 kB / gzip 14.85 kB (shared chunk: axios + Alpine)

The 39.73 kB rack-editor chunk only loads on `/projects/{p}/drawings/{r}/edit` — Sortable.js stays out of every other page's bundle.

## Test counts

| Test | Cases | Assertions | Notes |
|------|-------|------------|-------|
| `tests/Feature/Drawings/RackElevationRenderServiceTest` | 9 | 64 | Kind guard, 42U rail, item placement, partial-data asterisks, CRIT-06 warning, lock annotation, 1s render budget, XSS escape. |
| `tests/Feature/Drawings/RackEditorEndpointsTest` | 11 | 35 | edit-page render/404/403, save-canvas success/422-validation/extra-key-drop/lock-roundtrip/cursor-walk-Warning-7-fix, flipRackMounted update / pre-rack-existence (Blocker 2 regression) / 403-non-owner. |
| **Total new** | **20** | **99** | |

Final sweep across Phase 17 + Phase 18: **48 passed, 1 expected D2-binary skip** on dev (no D2 binary at `/usr/local/bin/d2` — Phase 17 schematic feature test guarded with `markTestSkipped`). No Phase 17 regressions.

## Threat model dispositions enacted

| Threat ID | Mitigation |
|-----------|------------|
| T-18.03-01 (Tampering — rack-canvas payload) | Strict `$request->validate` with typed allow-list. Extra keys silently dropped (asserted by `test_save_rack_canvas_rejects_unknown_keys`). u_position 1..99, u_height 0.5..42, name max:200. |
| T-18.03-02 (XSS — equipment names in SVG) | Every `<text>` content goes through `htmlspecialchars(..., ENT_XML1 \| ENT_QUOTES, 'UTF-8')` in `RackElevationRenderService::escape`. Test `test_render_escapes_equipment_names` asserts `<script>alert(1)</script>` becomes `&lt;script&gt;alert(1)&lt;/script&gt;`. |
| T-18.03-03 (Spoofing — edit + rack-canvas) | `$this->authorize('update', $drawing)` via ProjectDrawingPolicy + project_id match check. Tests `test_edit_page_403s_for_non_owner_non_admin`. |
| T-18.03-03b (Spoofing — flip-rack-mounted) | `$this->authorize('update', $project)` via NEW ProjectPolicy. Test `test_flip_rack_mounted_403s_for_non_owner` + Blocker 2 regression `test_flip_rack_mounted_works_before_any_rack_drawing_exists`. |
| T-18.03-04 (DoS — rack-canvas spam) | `->middleware('throttle:60,1')` on rack-canvas + flip-rack-mounted. Render is sub-second (Warning 8 budget asserted). |
| T-18.03-05 (Info Disclosure — cross-project edit) | Controller asserts `$drawing->project_id !== $project->id → abort(404)`. |
| T-18.03-06 (Tampering — locked-item override) | Documented as accepted risk. Lock is a UX hint; cursor-walk in client JS preserves locks. Server faithfully persists; round-trip asserted by `test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it` (Warning 7). |
| T-18.03-07 (Injection — flip-rack-mounted SQL) | `whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])` — bound parameter, never string concat. |
| T-18.03-08 (Sortable.js bundle exposure) | Sortable.js loaded ONLY via the rack-editor.js Vite entry. No new global attack surface. |
| T-18.03-09 (Repudiation — render failures) | `saveRackCanvas` catches `Throwable`, sets `status=failed` + `error_message`, persists, logs `Log::error` with drawing_id. Failed renders surface in the index status pill. |
| T-18.03-10 (Tampering — rack_height_u boundary) | `$request->validate` min:1 max:99 + RackElevationRenderService re-validates ($heightU < 1 || > 99 throws). Defence in depth. |

## Deviations from Plan

### [Rule 2 — Add Critical] App\\Policies\\ProjectPolicy was missing

**Found during:** Task 2.

**Issue:** Plan §Step 2.2.1 stated "Confirm `app/Policies/ProjectPolicy.php` exists and registers an `update` method (owner-OR-admin). If not, add the policy in this task — but Phase 17 already shipped it for project-package review, so reuse." Phase 17 actually did NOT ship a ProjectPolicy — `ProjectController` uses inline ownership checks like `$project->user_id === auth()->id() || auth()->user()?->role === 'admin'`.

**Fix:** Created `app/Policies/ProjectPolicy.php` with `view`/`update`/`delete` methods (owner-OR-admin via `Project::user_id` + `User::isAdmin()`) and registered it in `AppServiceProvider::boot` alongside the existing three policies. The plan explicitly authorised this fall-through path.

**Files modified:** `app/Policies/ProjectPolicy.php` (created), `app/Providers/AppServiceProvider.php` (registered Gate::policy).

**Commit:** `f3ad476`.

### Other deviations

**None.** The cursor-walk algorithm, render constants, threat dispositions, and route shapes all landed exactly as specified.

## Manual verification (live env smoke test)

The plan includes a live smoke test sequence (Step 3.5). Listed here for the engineer's day-1 acceptance:

1. `/projects/{p}/drawings` → click `+ Create Drawing` → Rack → land on rack show page.
2. Click `Edit Rack` → editor renders with palette + 42U scaffold + U-rail.
3. Drag a Crestron AirMedia 3200 from the rack-mounted palette into the rack column.
4. Click `Save Rack` → page reloads → status pill shows Ready, SVG rendered (via the existing line-66 kind-agnostic branch).
5. Click `Download PDF` → A4 landscape PDF with title block + rack rendered.
6. Click `Download SVG` → standalone SVG file opens in the browser.
7. On a project with ZERO rack drawings, tick the `Rack?` checkbox in the Other-equipment palette → AJAX POST to `flip-rack-mounted` returns 200 + Device row updated. (Blocker 2 regression — also covered by `test_flip_rack_mounted_works_before_any_rack_drawing_exists`.)

## Requirements coverage

| Req | Coverage Status | Where |
|-----|-----------------|-------|
| **DRAW-07** | Engineer creates rack manually via picker (Plan 18-01) + edits via this plan's editor | This plan + 18-01 |
| **DRAW-08** | 1U-precise scale + U-numbered side rail | RackElevationRenderService::renderRail |
| **DRAW-09 (partial)** | Palette ordering at resolver layer + bottom-up u_position rendering. Full AVIXA auto-place algorithm deferred per CONTEXT.md decision (lands in v1.3.x quick task or v2.0). | rackStackForProject (18-01) + renderItems (this plan) |
| **DRAW-10** | Drag-reorder + per-item U-position lock (cursor walk preserves locks) | rack-editor.js onRackReorder + lock toggle |
| **DRAW-11** | Multi-rack per project — picker auto-increments label (18-01); editor handles each rack independently | This plan + 18-01 |
| **DRAW-12** | Totals footer — weight, current, BTU, U-utilisation; asterisks + ratio on partial data | RackElevationRenderService::renderFooter |
| **DRAW-13** | PDF + SVG (+ PNG bonus) export | DrawingExportRendererService::renderPdf/Svg/Png + pdf/drawings/rack.blade.php |

## v1.3.x backlog notes

**NOT shipped in v1.3 — call out for the next backlog grooming:**

- **Auto-fill keyword classifier + AVIXA auto-place** (DRAW-09 full): per CONTEXT.md, "If engineers later request 'auto-fill from project equipment', ship as a quick task that adds a '+ Auto-fill' button inside the rack editor." Estimated 1 day.
- **Project-package review page is_rack_mounted bulk-edit column**: the flipRackMountedFlag endpoint is already reachable from there (Blocker 2 fix), but the UI checkbox column is a follow-up. Estimated 0.5 day.
- **SVG lock glyph instead of "Locked"/"Unlock" text**: visual polish; current text labels work and are screen-reader friendly. Estimated 0.5 day.

## Self-Check: PASSED

- ✓ FOUND: `app/Services/Drawings/RackElevationRenderService.php`
- ✓ FOUND: `app/Policies/ProjectPolicy.php`
- ✓ FOUND: `resources/views/pdf/drawings/rack.blade.php`
- ✓ FOUND: `resources/views/projects/drawings/rack-edit.blade.php`
- ✓ FOUND: `resources/js/rack-editor.js`
- ✓ FOUND: `tests/Feature/Drawings/RackElevationRenderServiceTest.php`
- ✓ FOUND: `tests/Feature/Drawings/RackEditorEndpointsTest.php`
- ✓ FOUND commit: `dade6d8` (Task 1)
- ✓ FOUND commit: `f3ad476` (Task 2)
- ✓ FOUND commit: `ce981d9` (Task 3)
- ✓ All 9 RackElevationRenderServiceTest cases pass (64 assertions)
- ✓ All 11 RackEditorEndpointsTest cases pass (35 assertions)
- ✓ Full Drawings sweep: 48 passed, 1 skipped (D2 binary on dev only)
- ✓ `! grep -q "use Spatie.Browsershot" app/Services/Drawings/DrawingExportRendererService.php` — passes
- ✓ `route:list --name=drawings` shows 11 routes (6 P17 + 2 P18-01 + 3 P18-03)
- ✓ show.blade.php existing kind-agnostic SVG render branch (`@if ($drawing->isReady() && ! empty($drawing->generated_svg))`) is UNCHANGED — only an Edit Rack button was added in the action bar
- ✓ `npm run build` succeeds; manifest.json contains `resources/js/rack-editor.js` → `assets/rack-editor-*.js`
- ✓ `php -l` clean on every modified PHP file
