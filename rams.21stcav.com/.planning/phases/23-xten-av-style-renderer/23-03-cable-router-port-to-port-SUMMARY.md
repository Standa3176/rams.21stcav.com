---
phase: 23
plan: 03
subsystem: drawings/renderer
tags: [renderer, cables, port-fk, signal-colour, mxgraph, deterministic, v2.0, xten-av]
dependency_graph:
  requires:
    - Phase 23 Plan 02 (XtenAvLayoutEngine device cell descriptors — dev-{zone}-{idx} ids)
    - Phase 22 Plan 01 (cable_schedule_items port FK columns + 4 belongsTo relations)
    - Phase 22 config/cables.php signal_type_colours (D-10 single source of truth)
    - Phase 21 DeviceStencil + DevicePort models
  provides:
    - App\Services\Drawings\CableRouter::emitCables()
    - mxGraph edge descriptor list with stable cab-{id} edge ids and cab-{id}-warn glyph ids
    - D-07 NULL-FK fallback ladder (skip / coord-fallback+warn / named-port)
    - OQ-4 Path B Tier 1.5 fallback (no <constraint> in mxgraph_xml → coord-fallback regardless of FK)
  affects:
    - Plan 23-05 (DrawIoBuilderService orchestrator — will call emitCables() AFTER XtenAvLayoutEngine::placeDevices())
    - Plan 23-07 (final visual verification reads D-10 colour mapping side-by-side with reference image)
tech_stack:
  added: []
  patterns:
    - "Pure read-only helper (D-LOCK-5/6 — no Eloquent writes, no AI calls, deterministic)"
    - "Call-site eager-load via loadMissing (Phase 22 D-10 forbids class-level $with)"
    - "XML-escape on every user-supplied string (htmlspecialchars ENT_XML1 | ENT_QUOTES)"
    - "Config-as-single-source-of-truth (config('cables.signal_type_colours') — never hardcode hex)"
    - "Constraint-presence sniffing for Tier-tier detection (str_contains('<constraint'))"
    - "Generic naming D-09 — class is CableRouter, no Rams prefix"
key_files:
  created:
    - app/Services/Drawings/CableRouter.php
    - tests/Feature/Drawings/CableRouterTest.php
  modified: []
decisions:
  - "DRAW-43 implemented — exitPortId/entryPortId emitted ONLY when both stencils carry <constraint> AND both port FKs populated; falls to coordinate-style otherwise (OQ-4 Path B gate)"
  - "DRAW-44 implemented — strokeColor + fontColor sourced via config('cables.signal_type_colours')[$signal] with 'unknown' fallback; config/cables.php NOT mutated by Phase 23 per D-10"
  - "DRAW-45 implemented — cable_id literal becomes the edge cell value attribute (mxGraph default midpoint placement, no extra label cell)"
  - "D-07 implemented — NULL-FK fallback ladder: both device_ids NULL → skip (v1.3 surface); either port_id NULL → coordinate-style + ⚠ glyph; both port_ids present + curated → named-port"
  - "D-09 verified — class CableRouter, no Rams prefix (SCC merge readiness)"
  - "D-10 verified — config/cables.php diff empty; renderer is the only new caller"
  - "OQ-4 Path B implemented — stencils whose mxgraph_xml lacks <constraint> elements silently fall to coordinate-style + ⚠ glyph EVEN when port FKs are populated; current 94.8% Tier 1.5 ratio means most cables on real projects route this path until Phase 24 curation closes the gap"
  - "Pitfall 9 mitigated — call-site loadMissing (cableSchedules.items.{sourcePort,destPort,sourceDevice,destDevice}); CableScheduleItem::$with stays empty (D-10 reflection-locked from Phase 22)"
  - "T-23-03-A1 mitigated — cable_id passes through xml() before becoming the mxCell value attribute (htmlspecialchars ENT_XML1|ENT_QUOTES)"
  - "T-23-03-A2 mitigated — from_location/to_location are NOT interpolated by the renderer in this plan; they are surfaced only via the warn-glyph hover tooltip which Plan 23-05 will wire (out of scope here, but the escape path is the same xml() helper)"
metrics:
  duration: ~30min
  tasks_completed: 1
  files_created: 2
  files_modified: 0
  tests_added: 16
  assertions: 41
  completed: 2026-05-14
requirements: [DRAW-43, DRAW-44, DRAW-45]
---

# Phase 23 Plan 03: CableRouter Port-to-Port + Signal Colours + Cable IDs Summary

**One-liner:** Wave 2 cable spine shipped — `CableRouter::emitCables()` reads `cable_schedule_items` port FKs and emits mxGraph edge descriptors with `exitPortId`/`entryPortId` when both stencils carry `<constraint>` elements (the 5.2% Tier 2 happy path), and silently falls back to coordinate-style edges + `⚠` glyph for Tier 1.5 stencils (94.8% of currently-seeded stencils per OQ-4 disposition) and NULL-FK rows (D-07). Pure read-only, deterministic, zero v1.3 surface change.

## Outcome

`CableRouter` is the third pure-read helper of Phase 23 (after `ZoneGrouper` and `XtenAvLayoutEngine`). It consumes:

- The project's `cableSchedules.items` collection (Phase 22 schema — 4 port FK columns + cable_id text + override note)
- Plan 23-02's flat device cell descriptor list (Plan 23-05 orchestrator splices `device_id` and `category` keys onto each cell before passing it in)

It emits a flat ordered descriptor list containing edge cells (and optional warn-glyph cells) ready for the orchestrator to serialise alongside Plan 23-02's zone + device cells.

### DRAW-43 — preferred path (exitPortId / entryPortId)

```xml
<mxCell id="cab-1" value="VID-1000"
        style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeWidth=2;fontSize=10;strokeColor=#2980B9;fontColor=#2980B9;exitPortId=hdmi-out-1;entryPortId=hdmi-in;"
        edge="1" parent="1" source="dev-rack-0" target="dev-wall-0">
  <mxGeometry relative="1" as="geometry"/>
</mxCell>
```

This style is emitted ONLY when:

1. `$item->source_port_id IS NOT NULL` AND `$item->dest_port_id IS NOT NULL`, AND
2. The source stencil's `mxgraph_xml` contains `<constraint` (Tier 2), AND
3. The dest stencil's `mxgraph_xml` contains `<constraint` (Tier 2).

If ANY of those three conditions fails, the router falls through to the coordinate-style fallback below.

### DRAW-43 / D-07 / OQ-4 Path B — coordinate-style fallback

```xml
<mxCell id="cab-1" value="VID-1000"
        style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeWidth=2;fontSize=10;strokeColor=#2980B9;fontColor=#2980B9;exitX=1;exitY=0.5;exitDx=0;exitDy=0;exitPerimeter=0;entryX=0;entryY=0.5;entryDx=0;entryDy=0;entryPerimeter=0;"
        edge="1" parent="1" source="dev-rack-0" target="dev-wall-0">
  <mxGeometry relative="1" as="geometry"/>
</mxCell>
<mxCell id="cab-1-warn" value="⚠"
        style="text;html=1;align=center;verticalAlign=middle;fontSize=12;fontColor=#E67E22;"
        vertex="1" parent="1">
  <mxGeometry x="..." y="..." width="20" height="20" as="geometry"/>
</mxCell>
```

Source-side edge is the right edge (`exitX=1`) when the device cell's `category` is in `SOURCE_LIKE_CATEGORIES` (`videobar`, `byod`, `mic`, `desk-mic`, `ceiling-mic`, `paging-station`, `call-station`); otherwise it's the left edge. Dest-side always defaults to the left edge per the D-07 heuristic.

### DRAW-44 — signal-type colour resolution (verbatim)

```php
$signal = (string) ($item->sourcePort?->signal_type ?? $item->destPort?->signal_type ?? 'unknown');
$colour = (string) (
    config('cables.signal_type_colours.' . $signal)
    ?? config('cables.signal_type_colours.unknown')
    ?? '#000000'
);
```

`config('cables.signal_type_colours')` is read exactly once per cable, and `config/cables.php` is the ONLY read site for the colour map across the renderer. The router does NOT mutate the config; Plan 23-07 owns the side-by-side verification against the XTEN-AV reference image and raises a separate config-update ticket if the colours need to shift (per D-10 open issue).

| signal_type | hex (current) |
|-------------|---------------|
| audio       | #C0392B |
| video       | #2980B9 |
| control     | #27AE60 |
| network     | #8E44AD |
| usb         | #E67E22 |
| speaker     | #16A085 |
| power       | #7F8C8D |
| unknown     | #000000 |

### DRAW-45 — cable_id rendering

The `cable_id` string lives in the edge cell's `value` attribute directly. mxGraph's default behaviour places the value at the edge midpoint with its own anti-overlap algorithm — no extra label cell needed. The string is XML-escaped via `htmlspecialchars(ENT_XML1 | ENT_QUOTES, 'UTF-8')` before interpolation to mitigate T-23-03-A1 (engineer-typed cable IDs flow into the mxGraph XML attribute).

### D-07 NULL-FK fallback ladder (verbatim)

| `source_device_id` | `dest_device_id` | `source_port_id` | `dest_port_id` | Decision | Cells emitted |
|---|---|---|---|---|---|
| NULL | NULL | NULL | NULL | SKIP — pure-text legacy row, v1.3 surface handles per Phase 22 D-10 | 0 |
| set | NULL or set | * | * | Skip if either device not in current sheet's cells map; otherwise route via the rules below | 0 or 1+ |
| set | set | set | set | Use `portToPortStyle()` IF both stencils carry `<constraint>`; else `deviceEdgeStyle()` + ⚠ | 1 or 2 |
| set | set | NULL | * | `deviceEdgeStyle()` + ⚠ | 2 |
| set | set | * | NULL | `deviceEdgeStyle()` + ⚠ | 2 |

The router also logs `Log::warning` with structured context when a cable references a device that isn't on the current sheet's device cells list — happens when `nullOnDelete` has cleared a FK or when Plan 23-05's paginator filters the device off this page.

### OQ-4 Path B implementation (verbatim per discovery file)

```php
$srcHasConstraints = $this->stencilHasConstraints($src['stencil'] ?? null);
$dstHasConstraints = $this->stencilHasConstraints($dst['stencil'] ?? null);

$bothPortsPresent = $item->source_port_id !== null && $item->dest_port_id !== null;
$canUseNamedPorts = $bothPortsPresent && $srcHasConstraints && $dstHasConstraints;
```

`stencilHasConstraints()` returns `false` when:

- The stencil object is `null` (cell has no stencil — Tier 1 auto-generic Phase 21 D-04 path), OR
- The stencil's `mxgraph_xml` is empty, OR
- The XML doesn't contain the literal string `<constraint`.

The result: any cable whose source OR dest stencil is Tier 1.5 silently falls to the coordinate-style + ⚠ glyph path, **regardless of whether the port FKs are populated**. This matches the 23-DISCOVERY-OQ-4 disposition: 91 of 96 currently-seeded engineer-curated stencils (94.8%) carry no `<constraint>` elements in their auto-generic body shell. Phase 24's curation UI closes the gap — once an engineer adds `<constraint>` elements to a stencil's mxgraph_xml, every cable that touches a device of that part_number automatically upgrades to port-to-port routing on the next render.

### Eager-loading discipline (Phase 22 D-10)

```php
$project->loadMissing([
    'cableSchedules.items.sourcePort',
    'cableSchedules.items.destPort',
    'cableSchedules.items.sourceDevice',
    'cableSchedules.items.destDevice',
]);
```

`loadMissing` runs once at the top of `emitCables()`. `CableScheduleItem::$with` stays empty (D-10 reflection-locked from Phase 22 Plan 01) so v1.3 read paths (XLSX export, bound-PDF cable section, schematic generator) never gain 4 LEFT JOINs per legacy NULL-FK row.

`test_eager_loading_keeps_query_count_bounded` locks total queries under 10 for a 1-cable fixture (covers project, schedule, items, 4 batched port/device fetches, plus overhead).

## Tests Added

16 tests / 41 assertions across 1 file. All GREEN.

| File | Tests | Assertions |
|------|-------|------------|
| `tests/Feature/Drawings/CableRouterTest.php` | 16 | 41 |

Run command:

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=CableRouterTest
```

### Coverage map

| Test | Requirement / Decision | What it locks |
|------|------------------------|---------------|
| test_port_to_port_edge_uses_exit_port_id | DRAW-43 happy path | `exitPortId=hdmi-out-1` + `entryPortId=hdmi-in` in edge style for Tier 2 + both FK populated |
| test_edge_value_is_cable_id | DRAW-45 | `value` attribute is the verbatim `cable_id` text (escaped) |
| test_cable_colour_from_config_signal_type_colours | DRAW-44 | strokeColor + fontColor match `config('cables.signal_type_colours.video')` exactly |
| test_unknown_signal_type_falls_back_to_unknown_colour | DRAW-44 fallback | Unknown signal types resolve to `#000000` via `'unknown'` key |
| test_null_fk_renders_with_warning_glyph | D-07 NULL port + ⚠ | No `exitPortId` in style; `exitX=` present; ⚠ glyph cell emitted |
| test_source_port_null_dest_port_present_falls_back | D-07 single-side NULL | Asymmetric NULL still falls to coordinate-style with ⚠ |
| test_dest_port_null_source_port_present_falls_back | D-07 single-side NULL | Symmetric verification of the other side |
| test_double_null_fk_cable_is_skipped | D-07 leg 1 | Both device_ids NULL → empty descriptor list (v1.3 surface owns the row) |
| test_tier15_source_stencil_falls_back_to_coordinate_style | OQ-4 Path B | Source stencil w/o `<constraint>` drops `exitPortId` even when FK is set |
| test_tier15_dest_stencil_falls_back_to_coordinate_style | OQ-4 Path B | Dest stencil w/o `<constraint>` drops `entryPortId` even when FK is set |
| test_both_tier15_stencils_fall_back | OQ-4 Path B (worst case) | Both sides Tier 1.5 → pure coord-style + ⚠ |
| test_cable_id_xss_escaped | T-23-03-A1 | `<script>` → `&lt;script&gt;` in mxCell value |
| test_eager_loading_keeps_query_count_bounded | Pitfall 9 | Total queries < 10 with loadMissing on cableSchedules.items.{4 relations} |
| test_router_does_not_write_to_database | D-LOCK-5/6 | Row counts on 5 tables unchanged after emitCables() runs |
| test_emits_stable_descriptors_across_calls | Determinism | Same input → same id + style arrays, twice in a row |
| test_skips_cable_when_device_id_not_in_cells_map | Paginator-friendly | Cables whose source/dest device is filtered off the sheet emit zero cells |

## Decisions Implemented

- **DRAW-43** — `portToPortStyle()` emits `exitPortId={src->port_id};entryPortId={dst->port_id};` only when both stencils carry `<constraint>` AND both port FKs are populated.
- **DRAW-44** — `config('cables.signal_type_colours.' . $signal)` is the only colour read site; defaults via `'unknown'` key then literal `#000000`. The config is NOT mutated by this plan (D-10 lock).
- **DRAW-45** — `value` attribute = XML-escaped `$item->cable_id`. mxGraph renders this at the edge midpoint by default; no extra label cell.
- **D-07** — Three-leg ladder implemented exactly as documented in CONTEXT lines 92-94. Both device_ids NULL → skip; either port NULL → coord-fallback + ⚠; both ports → named-port path.
- **D-09 (generic naming)** — Class is `CableRouter`, file is `app/Services/Drawings/CableRouter.php`. No `Rams` prefix. SCC merge readiness preserved.
- **D-10 (colour single source of truth)** — `git diff --stat config/cables.php` empty. Renderer reads only.
- **OQ-4 Path B** — `stencilHasConstraints()` substring-checks for `<constraint` in `mxgraph_xml`; absence forces fallback regardless of FK presence. Three tests (source-only, dest-only, both) lock this behaviour.

## Threat Model Outcomes

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-23-03-A1 (XSS via cable_id rendered as edge value) | mitigate | Implemented — `xml()` helper passes every cable_id through `htmlspecialchars($s, ENT_XML1 \| ENT_QUOTES, 'UTF-8')` before interpolation. `test_cable_id_xss_escaped` GREEN. |
| T-23-03-A2 (XSS via from_location/to_location in D-07 hover) | mitigate (deferred wiring) | The `xml()` helper is in place; wiring of from_location/to_location into a tooltip is Plan 23-05's job (orchestrator), not Plan 23-03's. Plan 23-03 does NOT render those columns. Defence-in-depth pattern locked. |
| T-23-03-A3 (unknown signal_type → unknown colour) | accept | Documented behaviour. `test_unknown_signal_type_falls_back_to_unknown_colour` GREEN. |
| T-23-03-A4 (DoS via 500+ cable_schedule_items overwhelming render) | accept + log | `Log::warning` fires when descriptor count > 200. Pitfall 4's 5 MB postMessage cap pre-enforced by DrawIoSpikeController upstream. |

## Pitfall Verifications

- **Pitfall 1 (eager-load discipline):** `CableScheduleItem::$with` stays empty — verified by grep returning 0 actual property declarations (the 1 grep hit is the docblock comment). `loadMissing` lives at the call site only.
- **Pitfall 9 (N+1):** `test_eager_loading_keeps_query_count_bounded` asserts total queries < 10 for a 1-cable fixture exercising all 4 belongsTo relations.
- **Determinism (D-LOCK-5/6):** `test_emits_stable_descriptors_across_calls` + `test_router_does_not_write_to_database` lock the contract together — same input twice = same id/style arrays, and zero row count changes on any of the 5 in-scope tables.

## Deviations from Plan

### 1. [Rule 2 — Missing critical functionality] OQ-4 Path B Tier 1.5 fallback added to the action's pseudo-code

- **Found during:** Task 1 GREEN — the plan's pseudo-code `portToPortStyle()` always emitted `exitPortId` + a coordinate fallback together, relying on draw.io to "silently ignore" the unresolvable `exitPortId` for Tier 1.5 stencils.
- **Issue:** Per `23-DISCOVERY-OQ-4-TIER15-PORTS.md` disposition (Path B), drawing the edge to a named port that doesn't exist in the stencil shape would produce a draw.io render error or silently dangle the edge. The discovery file's mandated implementation is to **drop `exitPortId` entirely** when the stencil's `mxgraph_xml` lacks `<constraint>` elements — not to emit it speculatively. The prompt's `<critical_invariants>` #3 explicitly requires this behaviour. Without it, 94.8% of currently-seeded stencils would render with malformed edge styles.
- **Fix:** Added `stencilHasConstraints(?object $stencil): bool` helper that substring-checks `mxgraph_xml` for `<constraint`. The named-port path is gated on `$bothPortsPresent && $srcHasConstraints && $dstHasConstraints`. When any check fails, `portToPortStyle()` is not called at all — the router uses `deviceEdgeStyle()` + ⚠ glyph instead. Added 3 tests covering source-only, dest-only, and both-sides Tier 1.5.
- **Files modified:** `app/Services/Drawings/CableRouter.php`, `tests/Feature/Drawings/CableRouterTest.php`.
- **Commits:** Single GREEN commit `7e4a1df` (RED was committed before the fallback gate existed; the GREEN commit added both the gate and its tests together).

### 2. [Rule 3 — test fixture mechanic] Test `test_unknown_signal_type_falls_back_to_unknown_colour` ordering

- **Found during:** Task 1 GREEN initial run — the planner's draft called `DB::table('device_ports')->update()` BEFORE `makeProjectWithCables()`, but the fixture creates fresh ports inside that helper, so the pre-update was a no-op.
- **Fix:** Reordered — fixture builds first, then `DB::table()->update()`, then `$f['project']->fresh()` to invalidate any cached relations before passing to the router.
- **Files modified:** `tests/Feature/Drawings/CableRouterTest.php` only (no production code change).
- **Commit:** Same GREEN commit `7e4a1df`.

No other deviations. The plan's `<action>` block was implementable verbatim apart from the OQ-4 Path B gating issue documented above.

## Authentication Gates

None — no external auth or paid API surface touched. Pure read-only code path.

## Self-Check: PASSED

Verified at commits `3865be6` (Task 1 RED) + `7e4a1df` (Task 1 GREEN):

Files exist:
- FOUND: `app/Services/Drawings/CableRouter.php`
- FOUND: `tests/Feature/Drawings/CableRouterTest.php`
- FOUND: `.planning/phases/23-xten-av-style-renderer/23-03-cable-router-port-to-port-SUMMARY.md` (this file)

Commits exist (in `git log --oneline -5`):
- FOUND: `3865be6` (test RED — 16 failing tests)
- FOUND: `7e4a1df` (feat GREEN — CableRouter class)

Acceptance criteria:
- `php artisan test --filter=CableRouterTest` exits 0 with **16 tests / 41 assertions** GREEN.
- `grep -c "AIManager|AICache|AIUsage" app/Services/Drawings/CableRouter.php` = **0** (D-LOCK-5).
- `grep -c "->update(|->save(|::create(|DB::insert|DB::update" app/Services/Drawings/CableRouter.php` = **0** (D-LOCK-6).
- `grep -c "htmlspecialchars" app/Services/Drawings/CableRouter.php` ≥ **1** (T-23-03-A1 — exact count: 1, in the `xml()` helper).
- `grep -c "config('cables.signal_type_colours" app/Services/Drawings/CableRouter.php` ≥ **1** (DRAW-44 — exact count: 2, the primary read + the unknown-fallback read).
- `grep -c "exitPortId" app/Services/Drawings/CableRouter.php` ≥ **1** (DRAW-43 — exact count: 2, in `portToPortStyle()` + docblock).
- `grep -c "loadMissing" app/Services/Drawings/CableRouter.php` ≥ **1** (Pitfall 9 — exact count: 1, at top of `emitCables()`).
- `grep -c "protected \$with" app/Models/CableScheduleItem.php` = **0** as a property declaration (the 1 grep hit is the docblock comment forbidding the property).
- `git diff --stat` on all 5 v1.3 invariant files (SchematicGeneratorService, SchematicD2SourceBuilder, DrawingDataResolverService, BoundPdfBuilderService, DrawingExportRendererService): empty.
- `git diff --stat config/cables.php`: empty (D-10 single source of truth — DO NOT MODIFY in Phase 23).
- `git diff --stat app/Models/CableScheduleItem.php`: empty.
- `git diff --stat app/Services/Drawings/DrawIoBuilderService.php`: empty (orchestrator rewire is Plan 23-05).
- Both touched PHP files pass `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l` with "No syntax errors detected".

## Known Stubs

None. `CableRouter::emitCables()` ships with complete logic for all three D-07 legs + the OQ-4 Path B gate. No placeholder values, no TODO markers. Plan 23-05 wires the router into `DrawIoBuilderService` — until then `CableRouter` exists but is not called from any public surface, by design (mirrors the Plan 23-02 strategy).

## Threat Flags

None. The router introduces no new network endpoints, no new auth paths, no file access, and no new schema. It is a pure in-memory read transform from existing Phase 22 columns to mxGraph XML descriptor arrays.

## 🚨 Files to upload to live

Per `feedback_local_then_upload.md` + `feedback_php_lint_before_push.md`: RAMS deploy = `git push` to `live` remote → SSH to `/home/stcav/rams.21stcav.com/` → `sudo -u stcav git pull` + `sudo -u stcav php artisan config:clear`. **No migration in this plan.** **No view changes.** **No Composer / npm changes.** Pure additive read-only service class.

Files this plan added (for traceability — the actual deploy is a git pull):

- `app/Services/Drawings/CableRouter.php`  *(new — pure read-only helper; no DB / no route / no AI / no v1.3 surface touch)*
- `tests/Feature/Drawings/CableRouterTest.php`  *(new — test file; production not affected)*

**Until Plan 05 ships, deploying Plan 03 is a no-op for engineers visiting `/admin/drawings/draw-io-spike/{project}`** — the spike route still emits the Phase 21 P03 builder output unchanged. `DrawIoBuilderService` does NOT yet call `CableRouter`. Plan 05 is the orchestrator-rewire plan that activates this code path.

Post-deploy runbook (RAMS):

```bash
ssh stcav@rams.21stcav.com
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav php artisan config:clear
```

(No migration step. No queue restart needed — no jobs touched.)

Plans 23-04 (TitleBlockRenderer + SheetPaginator) and 23-05 (DrawIoBuilderService orchestrator rewire) are unblocked.
