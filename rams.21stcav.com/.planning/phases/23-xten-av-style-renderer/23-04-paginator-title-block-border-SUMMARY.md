---
phase: 23
plan: 04
subsystem: drawings/renderer
tags: [renderer, paginator, title-block, border, mxfile, deterministic, v2.0, xten-av]
dependency_graph:
  requires:
    - Phase 23 Plan 01 (config/drawings.php sub_sheet_thresholds + sheet_number_format + page_dimensions; projects.metadata JSON column; Carbon::setTestNow harness)
    - Phase 23 Plan 02 (XtenAvLayoutEngine device-cell descriptor shape — paginator counts devices touching signal types)
    - Phase 23 Plan 03 (CableRouter edge descriptor shape — paginator filters cables by sourcePort/destPort signal_type)
    - Phase 21 (DeviceStencil + DevicePort models)
    - Phase 22 (cable_schedule_items port FKs)
  provides:
    - App\Services\Drawings\SheetPaginator::classify() — sheet allocation list (always system_overview; conditional audio/video/control/network sub-sheets)
    - App\Services\Drawings\TitleBlockRenderer::render() — 8-field title-block mxCell descriptors per sheet
    - App\Services\Drawings\SheetBorderRenderer::render() — single dashed-border mxCell per sheet
  affects:
    - Plan 23-05 (DrawIoBuilderService orchestrator — calls all three classes per sheet inside `<mxfile>` wrapper)
    - Plan 23-07 (final visual verification — confirms `<mxfile>` multi-page tabs render in draw.io v29.7.12 embed)
tech_stack:
  added: []
  patterns:
    - "Pure read-only services (D-LOCK-5/6 — no Eloquent writes, no AI calls, deterministic)"
    - "Call-site loadMissing (Phase 22 D-10 forbids class-level $with)"
    - "Config-driven thresholds + sheet numbers + page dimensions (Plan 01 keys are single source of truth)"
    - "XML-escape on every user-supplied string (htmlspecialchars ENT_XML1 | ENT_QUOTES)"
    - "Carbon::now() inside renderer + Carbon::setTestNow() in test setUp = deterministic date field"
    - "Generic naming D-09 — no Rams prefix"
    - "Strict force_sheets validation (non-array logged + ignored; unknown signal types silently dropped)"
key_files:
  created:
    - app/Services/Drawings/SheetPaginator.php
    - app/Services/Drawings/TitleBlockRenderer.php
    - app/Services/Drawings/SheetBorderRenderer.php
    - tests/Feature/Drawings/SheetPaginatorTest.php
    - tests/Feature/Drawings/TitleBlockRendererTest.php
    - tests/Feature/Drawings/SheetBorderRendererTest.php
  modified: []
decisions:
  - "D-06 implemented — SheetPaginator BOTH-AND threshold (min_cables_per_signal=5 AND min_devices_touching_signal=3); system_overview always emits; force_sheets metadata override forces sub-sheets regardless"
  - "D-08 implemented — TitleBlockRenderer 8-field source resolution (project / client / designed-by / drawn-by / checked-by / sheet / date / revision)"
  - "DRAW-49 implemented — SheetBorderRenderer dashed border with brand teal #1B7A7A, dashPattern 8 4, configurable inset"
  - "D-09 verified — generic naming. Class names SheetPaginator / TitleBlockRenderer / SheetBorderRenderer — NO rams_ / Rams prefix (SCC merge readiness preserved)"
  - "T-23-04-A1 mitigated — every user-supplied string in TitleBlockRenderer passes through xml() before interpolation (project name + client name + checked-by + Auth user name)"
  - "T-23-04-A2 mitigated — SheetPaginator validates force_sheets: non-array logged + ignored; non-string entries dropped; unknown signal types dropped"
  - "T-23-04-A3 accepted — TitleBlockRenderer reads Auth::user()->name not ->email (information-disclosure surface kept narrow per D-08)"
metrics:
  duration: ~35min
  tasks_completed: 3
  files_created: 6
  files_modified: 0
  tests_added: 25
  assertions: 58
  completed: 2026-05-14
requirements: [DRAW-47, DRAW-48, DRAW-49]
---

# Phase 23 Plan 04: SheetPaginator + TitleBlockRenderer + SheetBorderRenderer Summary

**One-liner:** Wave 2 sheet-chrome shipped — `SheetPaginator` (DRAW-47 BOTH-AND threshold + force_sheets override), `TitleBlockRenderer` (DRAW-48 8-field title block per D-08), `SheetBorderRenderer` (DRAW-49 brand-teal dashed border) emit pure deterministic mxCell descriptors with XML-escaped user strings, zero Eloquent writes, and the canonical AV-201..AV-205 sheet numbering range from Plan 01.

## Outcome

Three more pure-read helpers join `ZoneGrouper` / `XtenAvLayoutEngine` / `CableRouter` from the prior two waves. Plan 23-05's orchestrator (`DrawIoBuilderService` rewire) will call them in this order per sheet:

1. `app(SheetPaginator::class)->classify($project)` → ordered array of sheet descriptors
2. For each sheet:
   - `app(SheetBorderRenderer::class)->render()` → 1 border cell
   - `app(TitleBlockRenderer::class)->render($sheet, $project, $drawing)` → 8 title-block cells
   - Plan 02 zone + device cells (filtered by `$sheet['signal_filter']` where applicable)
   - Plan 03 edge cells (filtered by signal_type for sub-sheets)
3. Wrap each sheet in `<diagram id="…" name="…"><mxGraphModel>…</mxGraphModel></diagram>`; concatenate inside `<mxfile>`

### DRAW-47 — SheetPaginator threshold ladder (verbatim per D-06)

`system_overview` always emits FIRST (`AV-201`). For each of `audio / video / control / network`:

```
emit-sub-sheet IF (
    in_array($signal, Project.metadata.force_sheets, true)
    OR
    (cables-touching-signal >= min_cables_per_signal
     AND distinct-devices-touching-signal >= min_devices_touching_signal)
)
```

| Config key | Plan 01 value | Source |
|------------|---------------|--------|
| `drawings.sub_sheet_thresholds.min_cables_per_signal`        | 5 | Plan 01 Task 3 |
| `drawings.sub_sheet_thresholds.min_devices_touching_signal`  | 3 | Plan 01 Task 3 |

**Both** thresholds must be met — 4 cables on 5 devices does NOT emit; 5 cables on 2 devices does NOT emit. Only 5+ cables AND 3+ distinct devices triggers a sub-sheet.

**Force_sheets validation rules (T-23-04-A2):**
- `Project.metadata.force_sheets = 'audio'` (string not array) → `Log::warning` + ignore root
- `Project.metadata.force_sheets = ['audio', 'made-up-signal']` → only `audio` propagates; unknown signal silently dropped
- `Project.metadata.force_sheets = ['audio', 123, null]` → only `audio` (string-checked + vocab-checked) propagates
- Empty / missing → falls back to threshold-only logic

**Output ordering (always canonical regardless of force_sheets input order):**

```
[system_overview, audio, video, control, network]
```

`test_sheet_order_is_deterministic` locks this — force_sheets `['network', 'audio', 'control', 'video']` (shuffled) MUST still produce the canonical sequence.

### DRAW-48 — TitleBlockRenderer 8-field source resolution (verbatim per D-08)

| Field        | Source                                             | Fallback |
|--------------|----------------------------------------------------|----------|
| Project      | `Project.name`                                     | (empty string) |
| Client       | `Project.client_name`                              | (empty string) |
| Designed by  | `Auth::user()?->name`                              | `'—'`    |
| Drawn by     | same as Designed by (D-08 default)                 | `'—'`    |
| Checked by   | `Project.metadata['drawing_checked_by']`           | `'—'`    |
| Sheet        | `$sheet['sheet_number']` (from SheetPaginator)     | (always present) |
| Date         | `now()->format('Y-m-d')` (Carbon::setTestNow OK)   | (always present) |
| Rev          | `ProjectDrawing.version` cast to string            | `'R0'`   |

**Layout geometry:**

| Constant | Value | Purpose |
|----------|-------|---------|
| `FIELD_START_X`  | 60  | px x-origin of first field |
| `FIELD_WIDTH`    | 200 | px width per field cell |
| `FIELD_HEIGHT`   | 20  | px height per field cell |
| `FIELD_GAP`      | 30  | px gap between adjacent field cells |
| `y` coordinate   | `config('drawings.page_dimensions.title_block_y')` | Plan 01 = 940 |
| `FIELD_STYLE`    | `text;html=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontSize=10;fontColor=#333333;` | text-only mxCell |

**Per-cell descriptor shape:**

```php
[
    'kind'   => 'title-block-field',
    'id'     => 'tb-' . $sheet['key'] . '-' . $fieldIndex,  // e.g. 'tb-system_overview-0'
    'value'  => 'Project: Acme Boardroom Refurb',          // xml()-escaped
    'style'  => self::FIELD_STYLE,
    'parent' => '1',
    'x' => 60, 'y' => 940, 'w' => 200, 'h' => 20,
]
```

### DRAW-49 — SheetBorderRenderer descriptor (verbatim)

```php
[
    'kind'   => 'border',
    'id'     => 'page-border',
    'value'  => '',
    'style'  => 'rounded=0;dashed=1;dashPattern=8 4;fillColor=none;strokeColor=#1B7A7A;strokeWidth=1.5;',
    'parent' => '1',
    'x' => 20, 'y' => 20, 'w' => 1560, 'h' => 960,
]
```

Geometry derives entirely from `config('drawings.page_dimensions')` — `x` and `y` = `border_inset` (20); `w` = `width - 2*border_inset` (1560 from 1600); `h` = `height - 2*border_inset` (960 from 1000).

## Tests Added

25 tests / 58 assertions across 3 files. All GREEN.

| File | Tests | Assertions |
|------|-------|------------|
| `tests/Feature/Drawings/SheetPaginatorTest.php`      |  8 | 18 |
| `tests/Feature/Drawings/TitleBlockRendererTest.php`  | 13 | 30 |
| `tests/Feature/Drawings/SheetBorderRendererTest.php` |  4 | 10 |

Run command:

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='SheetPaginator|TitleBlockRenderer|SheetBorderRenderer'
```

### Coverage map

**SheetPaginatorTest:**

| Test | Decision / Requirement | What it locks |
|------|------------------------|---------------|
| test_empty_project_emits_one_diagram                 | DRAW-47 floor | system_overview always emits even with zero cables |
| test_below_cable_threshold_no_sub_sheet              | D-06          | 4 cables on 3 devices → no sub-sheet (cable threshold fails) |
| test_below_device_threshold_no_sub_sheet             | D-06          | 5 cables on 2 devices → no sub-sheet (device threshold fails) |
| test_above_threshold_emits_sub_sheet                 | D-06 happy    | 5 cables AND 3 devices → audio sub-sheet emits as `AV-202` |
| test_force_sheets_metadata_override                  | D-06 tinker   | `metadata.force_sheets = ['audio','control']` forces both regardless of threshold |
| test_force_sheets_invalid_entry_is_ignored           | T-23-04-A2    | unknown signal in force_sheets silently dropped |
| test_force_sheets_non_array_metadata_is_ignored      | T-23-04-A2    | string-where-array-expected logged + ignored (no crash) |
| test_sheet_order_is_deterministic                    | DRAW-47       | shuffled force_sheets input → canonical [system_overview, audio, video, control, network] output |

**TitleBlockRendererTest:**

| Test | Decision / Requirement | What it locks |
|------|------------------------|---------------|
| test_title_block_emits_eight_fields                  | DRAW-48 count | exactly 8 descriptors, all kind=`title-block-field` |
| test_title_block_field_sources                       | D-08 sources  | Project/Client/Sheet/Date values match D-08 resolution |
| test_checked_by_fallback_to_dash                     | D-08 fallback | `metadata = null` → `Checked by: —` |
| test_checked_by_reads_metadata                       | D-08          | `metadata.drawing_checked_by = 'Bob Reviewer'` propagates |
| test_designed_by_falls_back_to_dash_when_no_user     | D-08 fallback | no `actingAs` → `Designed by: —` AND `Drawn by: —` |
| test_designed_by_reads_auth_user_name                | D-08          | `Auth::user()->name = 'Alice Engineer'` propagates to both Designed/Drawn |
| test_revision_falls_back_to_r0_when_no_drawing       | D-08 fallback | `$drawing = null` → `Rev: R0` |
| test_revision_reads_drawing_version                  | D-08          | `ProjectDrawing.version = 3` → `Rev: 3` |
| test_xss_escaped_in_project_name                     | T-23-04-A1    | `<script>` in Project.name → `&lt;script&gt;` in mxCell value |
| test_xss_escaped_in_client_name                      | T-23-04-A1    | `<img onerror>` in Project.client_name → `&lt;img onerror` |
| test_xss_escaped_in_checked_by_metadata              | T-23-04-A1    | `<svg onload>` in metadata.drawing_checked_by → `&lt;svg onload` |
| test_xss_escaped_in_designed_by_user_name            | T-23-04-A1    | `<script>steal()</script>` in Auth user name → `&lt;script&gt;steal()&lt;/script&gt;` |
| test_title_block_y_from_config                       | Plan 01 wire  | every cell's `y` reads from `config('drawings.page_dimensions.title_block_y')` |

**SheetBorderRendererTest:**

| Test | Requirement | What it locks |
|------|-------------|---------------|
| test_emits_one_border_cell                           | DRAW-49 count | single descriptor, kind=`border` |
| test_border_geometry_inset_from_page_bounds          | DRAW-49 geom  | x=y=inset; w=width-2·inset; h=height-2·inset |
| test_border_style_is_dashed                          | DRAW-49 style | style contains `dashed=1` + `fillColor=none` + `strokeColor=#1B7A7A` |
| test_render_is_deterministic                         | D-LOCK-5/6    | byte-identical descriptor across two calls (same config) |

## Decisions Implemented

- **DRAW-47 (D-06)** — `SheetPaginator::classify()` reads `config('drawings.sub_sheet_thresholds')` (BOTH-AND gate); system_overview always at index 0; sub-sheets ordered canonically `[audio, video, control, network]`; `Project.metadata.force_sheets` overrides threshold per signal_type per D-06 deferred-UI escape hatch.
- **DRAW-48 (D-08)** — `TitleBlockRenderer::render()` emits 8 fields per sheet sourced verbatim from the D-08 table; `Carbon::now()` honours `setTestNow()` for test determinism; `ProjectDrawing` is optional (null → `Rev: R0`); `Auth::user()` is optional (null → `Designed by: —` AND `Drawn by: —`).
- **DRAW-49** — `SheetBorderRenderer::render()` emits a single dashed border mxCell at page bounds with `config('drawings.page_dimensions.border_inset')` insets; brand teal `#1B7A7A` at 1.5px with 8/4 dash pattern matches the XTEN-AV reference image visual contract.
- **D-09 (generic naming)** — Class names `SheetPaginator`, `TitleBlockRenderer`, `SheetBorderRenderer` carry no `Rams` prefix. File names match. SCC merge readiness preserved.

## Threat Model Outcomes

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-23-04-A1 (XSS via title-block field values) | mitigate | Implemented — `TitleBlockRenderer::xml()` passes every user string through `htmlspecialchars($s, ENT_XML1 \| ENT_QUOTES, 'UTF-8')` before interpolation. Four XSS tests cover project name, client name, checked-by metadata, AND Auth user name — all GREEN. |
| T-23-04-A2 (force_sheets type confusion) | mitigate | Implemented — `SheetPaginator::forcedSheets()` validates: non-array root logged-and-ignored; non-string entries dropped; entries not in `{audio, video, control, network}` dropped. Three tests lock the validation rules. |
| T-23-04-A3 (info disclosure via Auth user email) | accept | `TitleBlockRenderer::render()` reads `Auth::user()?->name` not `->email`. Test `test_designed_by_reads_auth_user_name` covers the happy path; no tests inspect or assert email-shaped strings, by design. |

## Deviations from Plan

### 1. [Rule 3 — test-mechanics] Replaced `Device::factory()` and `ProjectDrawing::factory()` with `Device::create()` / `ProjectDrawing::create()` in test fixtures

- **Found during:** Task 1 + Task 2 RED phase fixture composition.
- **Issue:** The plan's literal pseudo-code uses `Device::factory()->create([...])` and `ProjectDrawing::factory()->create([...])`. Neither factory class exists in this repo:
  - `database/factories/DeviceFactory.php` — DOES NOT EXIST. `App\Models\Device` does NOT use the `HasFactory` trait.
  - `database/factories/ProjectDrawingFactory.php` — DOES NOT EXIST. `App\Models\ProjectDrawing` carries the `HasFactory` trait but no factory class was ever generated.
  Running the literal pseudo-code would error with `BadMethodCallException` / `Class … does not exist`.
- **Fix:** Used direct `Device::create([...])` / `ProjectDrawing::create([...])` calls with explicit attributes. Matches the precedent set by `tests/Feature/Drawings/CableRouterTest.php` (Plan 23-03) which uses `Device::create()` for the same reason. ALL plan behaviour preserved — fixture is identical except for the Eloquent entrypoint.
- **Files modified:** `tests/Feature/Drawings/SheetPaginatorTest.php`, `tests/Feature/Drawings/TitleBlockRendererTest.php`.
- **Commits:** `d059af8` (RED — SheetPaginator), `1499ac6` (RED — TitleBlockRenderer).

### 2. [Rule 2 — Missing critical functionality] Added 3 extra XSS tests (client name, checked-by, designed-by) beyond plan's single project-name XSS test

- **Found during:** Task 2 RED authoring — checker warning #9 from `<critical_invariants>` block #12 explicitly requires "XSS test coverage … for project name, client name, checked-by, designed-by per checker warning #9". The plan's literal test list (Step 1, lines 643-666) carries only one XSS test (`test_xss_escaped_in_project_name`).
- **Issue:** Three of the four user-supplied strings that flow into the title block would have been XSS-untested if I shipped the plan verbatim. T-23-04-A1 mitigation requires defence-in-depth — every interpolated user string needs an XSS test, not just the project name.
- **Fix:** Added 3 additional XSS tests:
  - `test_xss_escaped_in_client_name` — `<img src=x onerror=alert(1)>` in `Project.client_name`
  - `test_xss_escaped_in_checked_by_metadata` — `<svg onload=alert(1)>` in `Project.metadata.drawing_checked_by`
  - `test_xss_escaped_in_designed_by_user_name` — `<script>steal()</script>` in `Auth::user()->name`
  All three GREEN. Test count for TitleBlockRendererTest: 13 (plan asked for 10).
- **Files modified:** `tests/Feature/Drawings/TitleBlockRendererTest.php`.
- **Commit:** `1499ac6` (RED).

### 3. [Rule 2 — Missing critical functionality] Empty-string user-name normalisation in TitleBlockRenderer

- **Found during:** Task 2 GREEN code review — `Auth::user()?->name ?? '—'` only fires the `'—'` fallback for null; if the user's name column ever holds an empty string (legacy seeded rows, fixture quirks), the rendered title block would emit `Designed by: ` (trailing space, missing value) instead of `Designed by: —`.
- **Issue:** Visual contract breaks silently — engineers reviewing the rendered drawing would see a dangling colon and no diagnostic. Same risk for `drawing_checked_by` if the metadata key is set to an empty string.
- **Fix:** Added explicit empty-string normalisation:
  - `if ($userName === '') { $userName = '—'; }` after the null-coalesce
  - `if ($checkedBy === '') { $checkedBy = '—'; }` after the null-coalesce
- **Files modified:** `app/Services/Drawings/TitleBlockRenderer.php`.
- **Commit:** `bf8c9bd` (GREEN).

No other deviations. The plan's `<action>` block was implementable verbatim apart from the three points documented above.

## Authentication Gates

None — no external auth or paid API surface touched. Pure read-only code path.

## Self-Check: PASSED

Verified at commits `2407249` (Task 1 GREEN), `bf8c9bd` (Task 2 GREEN), `3b64f2f` (Task 3 GREEN):

Files exist:
- FOUND: `app/Services/Drawings/SheetPaginator.php`
- FOUND: `app/Services/Drawings/TitleBlockRenderer.php`
- FOUND: `app/Services/Drawings/SheetBorderRenderer.php`
- FOUND: `tests/Feature/Drawings/SheetPaginatorTest.php`
- FOUND: `tests/Feature/Drawings/TitleBlockRendererTest.php`
- FOUND: `tests/Feature/Drawings/SheetBorderRendererTest.php`
- FOUND: `.planning/phases/23-xten-av-style-renderer/23-04-paginator-title-block-border-SUMMARY.md` (this file)

Commits exist (in `git log --oneline -10`):
- FOUND: `d059af8` (test RED — SheetPaginator)
- FOUND: `2407249` (feat GREEN — SheetPaginator)
- FOUND: `1499ac6` (test RED — TitleBlockRenderer)
- FOUND: `bf8c9bd` (feat GREEN — TitleBlockRenderer)
- FOUND: `f1df1ca` (test RED — SheetBorderRenderer)
- FOUND: `3b64f2f` (feat GREEN — SheetBorderRenderer)

Acceptance criteria:
- `php artisan test --filter='SheetPaginator|TitleBlockRenderer|SheetBorderRenderer'` exits 0 with **25 tests / 58 assertions** GREEN.
- `grep -c "config('drawings.sub_sheet_thresholds" app/Services/Drawings/SheetPaginator.php` = **3** (≥1 required).
- `grep -c "force_sheets" app/Services/Drawings/SheetPaginator.php` = **5** (≥1 required — D-06 override).
- `grep -c "Auth::user" app/Services/Drawings/TitleBlockRenderer.php` = **2** (≥1 required — D-08 designed-by).
- `grep -c "drawing_checked_by" app/Services/Drawings/TitleBlockRenderer.php` = **2** (≥1 required — D-08 checked-by).
- `grep -c "htmlspecialchars" app/Services/Drawings/TitleBlockRenderer.php` = **2** (≥1 required — T-23-04-A1).
- `grep -c "dashed=1" app/Services/Drawings/SheetBorderRenderer.php` = **1** (≥1 required — DRAW-49 style).
- `grep -cE "AIManager\|AICache\|->update\(|->save\(|::create\(" app/Services/Drawings/SheetPaginator.php` = **0** (D-LOCK-5/6).
- `grep -cE "AIManager\|AICache\|->update\(|->save\(|::create\(" app/Services/Drawings/TitleBlockRenderer.php` = **0** (D-LOCK-5/6).
- `grep -cE "AIManager\|AICache\|->update\(|->save\(|::create\(" app/Services/Drawings/SheetBorderRenderer.php` = **0** (D-LOCK-5/6).
- `git diff --stat` against the 5 v1.3 invariant files (`SchematicGeneratorService.php`, `SchematicD2SourceBuilder.php`, `DrawingDataResolverService.php`, `BoundPdfBuilderService.php`, `DrawingExportRendererService.php`) = empty.
- `git diff --stat` against `config/cables.php` = empty (D-10 single source of truth — not mutated by this plan).
- `git diff --stat` against `app/Services/Drawings/DrawIoBuilderService.php` = empty (orchestrator rewire is Plan 23-05's job — NOT touched here).
- All three touched PHP services + all three touched PHP tests pass `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l` with "No syntax errors detected".

## Known Stubs

None. All three renderers ship with complete logic; no placeholder values flow to UI. Plan 23-05 wires them into `DrawIoBuilderService` — until then they exist but are not called from any public surface, by design (mirrors the Plan 02 and Plan 03 strategy).

## Threat Flags

None. The three renderers introduce no new network endpoints, no new auth paths, no file access, no schema changes. They are pure in-memory read transforms from existing Phase 21/22/23-Plan-01 columns + config keys to mxGraph XML descriptor arrays.

## 🚨 Files to upload to live

Per `feedback_local_then_upload.md` + `feedback_php_lint_before_push.md`: RAMS deploy = `git push` to `live` remote → SSH to `/home/stcav/rams.21stcav.com/` → `sudo -u stcav git pull` + `sudo -u stcav php artisan config:clear`. **No migration in this plan.** **No view changes.** **No Composer / npm changes.** Pure additive read-only service classes.

Files this plan added (for traceability — the actual deploy is a git pull):

- `app/Services/Drawings/SheetPaginator.php`  *(new — pure read-only helper; no DB / no route / no AI / no v1.3 surface touch)*
- `app/Services/Drawings/TitleBlockRenderer.php`  *(new — pure read-only helper; no DB / no route / no AI / no v1.3 surface touch)*
- `app/Services/Drawings/SheetBorderRenderer.php`  *(new — pure read-only helper; no DB / no route / no AI / no v1.3 surface touch)*
- `tests/Feature/Drawings/SheetPaginatorTest.php`  *(new — test file; production not affected)*
- `tests/Feature/Drawings/TitleBlockRendererTest.php`  *(new — test file; production not affected)*
- `tests/Feature/Drawings/SheetBorderRendererTest.php`  *(new — test file; production not affected)*

No migration. Plan 23-01's `2026_05_13_120000_add_metadata_to_projects_table.php` is the only Phase 23 migration; it shipped with that plan.

**Until Plan 05 ships, deploying Plan 04 is a no-op for engineers visiting `/admin/drawings/draw-io-spike/{project}`** — the spike route still emits the Phase 21 P03 builder output unchanged. `DrawIoBuilderService` does NOT yet call any of the three new classes. Plan 05 is the orchestrator-rewire plan that activates this code path inside the `<mxfile>` wrapper.

Post-deploy runbook (RAMS):

```bash
ssh stcav@rams.21stcav.com
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav php artisan config:clear
```

(No migration step. No queue restart needed — no jobs touched.)

Plan 23-05 (DrawIoBuilderService orchestrator rewire) is unblocked — it now has all six pure-read helpers it needs: `ZoneGrouper`, `XtenAvLayoutEngine`, `CableRouter`, `SheetPaginator`, `TitleBlockRenderer`, `SheetBorderRenderer`.
