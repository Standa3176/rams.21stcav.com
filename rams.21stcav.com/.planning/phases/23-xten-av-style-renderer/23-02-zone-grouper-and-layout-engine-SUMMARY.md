---
phase: 23
plan: 02
subsystem: drawings/renderer
tags: [renderer, layout, zone, mxgraph, deterministic, v2.0, xten-av]
dependency_graph:
  requires:
    - Phase 23 Plan 01 (config/drawings.php Phase 23 keys + OQ-1 disposition committed)
    - Phase 21 (DeviceStencil model — mxgraph_xml + default_width/height + source columns)
    - Project::devicesWithStencils() accessor (Phase 21 D-07)
  provides:
    - App\Services\Drawings\ZoneGrouper::assign()
    - App\Services\Drawings\XtenAvLayoutEngine::placeDevices()
    - Stable device-cell id format: dev-{zone-slug}-{index}
    - Stable zone-cell id format: zone-{zone-slug}
  affects:
    - Plan 23-03 (CableRouter consumes device-cell ids from this plan as edge source/target)
    - Plan 23-05 (DrawIoBuilderService orchestrator rewire — call sites for both helpers land here)
tech_stack:
  added: []
  patterns:
    - "Pure read-only helpers (D-LOCK-5/6 — no Eloquent writes, no AI calls)"
    - "XML-escape on every user-supplied string (htmlspecialchars ENT_XML1 | ENT_QUOTES)"
    - "Constructor-injection-friendly stateless services (single public method)"
    - "Constant-array keyword tables (NAME_KEYWORD_TO_ZONE — OQ-1 Path B)"
    - "Base64 stencil embedding via shape=stencil(<base64>) — mirrors Phase 21 P03"
    - "Generic naming D-09 — no Rams prefix"
key_files:
  created:
    - app/Services/Drawings/ZoneGrouper.php
    - app/Services/Drawings/XtenAvLayoutEngine.php
    - tests/Feature/Drawings/ZoneGrouperTest.php
    - tests/Feature/Drawings/XtenAvLayoutEngineTest.php
  modified: []
decisions:
  - "D-01 implemented — config('drawings.category_to_zone') drives lookup; null/OTHER values trigger Path B fallback"
  - "D-02 implemented — $line['zone'] always wins, raw string used as group key"
  - "D-04 implemented — free-text zones create a dashed group per unique case-sensitive string; vocab zones come first, free-text alphabetical after"
  - "D-09 verified — generic naming. Classes named ZoneGrouper / XtenAvLayoutEngine — NO rams_ / Rams prefix"
  - "OQ-1 Path B implemented as NAME_KEYWORD_TO_ZONE constant on ZoneGrouper with first-match-wins case-insensitive substring scan; ceiling/paging/intercom evaluated before generic rack/switch tokens"
  - "T-23-02-A1 mitigated — zone label passes through xml() before becoming an mxCell value attribute"
  - "T-23-02-A2 mitigated — device name passes through xml() before becoming an mxCell value attribute"
metrics:
  duration: ~25min
  tasks_completed: 2
  files_created: 4
  files_modified: 0
  tests_added: 23
  assertions: 41
  completed: 2026-05-14
requirements: [DRAW-42, DRAW-46]
---

# Phase 23 Plan 02: ZoneGrouper + XtenAvLayoutEngine Summary

**One-liner:** Wave 2 spine shipped — `ZoneGrouper` (D-01/D-02/D-04 + OQ-1 Path B name-keyword fallback) and `XtenAvLayoutEngine` (DRAW-42 device cells + DRAW-46 dashed zone groups) emit deterministic mxCell descriptors with XML-escaped user strings, zero Eloquent writes, and the same base64 stencil-embed pattern Phase 21 P03 locked.

## Outcome

Phase 23's visual spine ships in two pure-read helpers. The orchestrator (Plan 05) will compose them: `ZoneGrouper::assign()` → `XtenAvLayoutEngine::placeDevices()` → flat ordered mxCell descriptor list → mxGraph XML.

### ZoneGrouper precedence ladder (verbatim per D-01 / D-02 / D-04 + OQ-1 Path B)

1. **`$line['zone']` non-empty** → use raw string verbatim (D-02 per-device override; D-04 free-text path supported).
2. **Category map lookup** → `config('drawings.category_to_zone')[$line['category']]`. A non-null, non-OTHER value wins. (Per OQ-1 Path B disposition, `hardware` is mapped to `null` here so the lookup short-circuits to step 3 for real production data.)
3. **NAME_KEYWORD_TO_ZONE scan** (OQ-1 Path B) → first-match-wins case-insensitive substring match against `strtolower($line['name'])`, falling back to `strtolower($line['model'])` when name is empty. Keyword order is significant — `ceiling` / `paging` / `intercom` evaluated before generic `rack` / `switch` so "Ceiling Camera Bracket" resolves to CEILING.
4. **`'OTHER'`** fallback.

Ordering rules:
- Zones in `config('drawings.zone_vocab')` come first, in vocab order.
- Free-text zones come after, sorted alphabetically (case-sensitive).
- Devices within a zone preserve input order from `devicesWithStencils()` (stable).

### XtenAvLayoutEngine descriptor shape

Each descriptor is a flat associative array (no nesting). Zone container appears BEFORE its child devices in the returned list — guarantees the orchestrator can serialise in order without needing a separate parent-resolution pass.

**Zone descriptor:**

```
[
    'kind'   => 'zone',
    'id'     => 'zone-rack',                  // 'zone-' . slug(zoneName)
    'value'  => 'RACK',                       // XML-escaped
    'style'  => self::ZONE_STYLE,             // see DRAW-46 style string below
    'parent' => '1',                          // root mxCell
    'x' => 60, 'y' => 60, 'w' => 280, 'h' => 204,
]
```

**Device descriptor:**

```
[
    'kind'        => 'device',
    'id'          => 'dev-rack-0',            // 'dev-' . zoneSlug . '-' . deviceIndex
    'value'       => 'Neat Bar Pro',          // XML-escaped device name/display_name/part_number cascade
    'style'       => 'shape=stencil(<base64>);whiteSpace=wrap;html=1;verticalLabelPosition=top;verticalAlign=bottom;fontSize=10;fontColor=#333333;',
    'parent'      => 'zone-rack',             // points at the zone container id
    'x' => 20, 'y' => 44, 'w' => 220, 'h' => 140,   // relative to zone — mxGraph parent-relative geometry
    'part_number' => 'NEAT-BAR-PRO',          // carried forward for Plan 23-03 CableRouter
    'stencil'     => DeviceStencil|object,    // carried forward for downstream layers
]
```

### DRAW-46 dashed-group style string (verbatim)

```
rounded=0;dashed=1;dashPattern=5 5;fillColor=none;strokeColor=#888888;strokeWidth=1;fontSize=10;fontColor=#666666;verticalAlign=top;align=left;spacingTop=4;spacingLeft=8;
```

Matches 23-RESEARCH.md Example 5. Light grey 1 px dashed border, transparent fill, title in top-left at 10 pt grey on white.

### DRAW-42 base64-stencil embed pattern (verbatim)

```
shape=stencil(<base64-of-stencil->mxgraph_xml>);whiteSpace=wrap;html=1;verticalLabelPosition=top;verticalAlign=bottom;fontSize=10;fontColor=#333333;
```

Identical splice pattern to `DrawIoBuilderService::emitMxGraph()` line ~296 (Phase 21 P03) — same encoder, same prefix tokens. Tier 1 auto-generated stencils and Tier 2 engineer-curated stencils flow through the same code path (CONTEXT D-04 carry-forward verified — `test_curated_and_tier1_stencils_both_render` GREEN).

### Layout geometry

| Constant | Value | Purpose |
|----------|-------|---------|
| `COLUMN_GAP` | 30 | px between devices horizontally inside a zone |
| `ROW_GAP` | 20 | px between devices vertically inside a zone |
| `ZONE_PADDING` | 20 | px inset from zone border on every side |
| `ZONE_TITLE_HEIGHT` | 24 | px strip reserved for zone label at the top of the box |
| `ZONE_X_START` | 60 | px x-origin of the first zone |
| `ZONE_Y_START` | 60 | px y-origin of every zone (all zones share the top edge) |
| `ZONE_SPACING` | 40 | px gap between adjacent zones |
| `MAX_COLS_PER_ZONE` | 4 | devices wrap to a new row after 4 columns |
| `DEFAULT_DEVICE_W` | 220 | fallback width when stencil has no `default_width` |
| `DEFAULT_DEVICE_H` | 140 | fallback height when stencil has no `default_height` |

All integer pixels. No randomness, no `now()`, no time-of-day reads — `test_emits_stable_ids_across_calls` GREEN.

## Tests Added

23 tests / 41 assertions across 2 files. All GREEN.

| File | Tests | Assertions |
|------|-------|------------|
| `tests/Feature/Drawings/ZoneGrouperTest.php` | 15 | 22 |
| `tests/Feature/Drawings/XtenAvLayoutEngineTest.php` | 8 | 19 |

Run command:
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='ZoneGrouper|XtenAvLayoutEngine'
```

### Coverage map

ZoneGrouperTest — Path B keyword fallback is now first-class tested (the planner's original test set missed it; tests added per OQ-1 disposition):

| Test | Decision | Disposition reference |
|------|----------|------------------------|
| test_per_device_zone_override_wins | D-02 | CONTEXT D-02 |
| test_free_text_zone_creates_separate_group | D-04 | CONTEXT D-04 (escape hatch) |
| test_hardware_category_falls_through_to_name_keyword_ceiling | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md table row 1 |
| test_hardware_category_falls_through_to_name_keyword_rack | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md table row 7 |
| test_hardware_category_falls_through_to_name_keyword_wall | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md table row 8 |
| test_hardware_category_falls_through_to_name_keyword_table | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md table row 9 |
| test_keyword_ordering_ceiling_before_rack | OQ-1 Path B ordering rule | 23-DISCOVERY-OQ-1-CATEGORIES.md "evaluated in this order to avoid false matches" |
| test_name_keyword_matching_is_case_insensitive | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md "case-insensitive substring match" |
| test_name_keyword_falls_back_to_model_when_name_empty | OQ-1 Path B | 23-DISCOVERY-OQ-1-CATEGORIES.md "(and falls back to model if name is empty)" |
| test_unknown_category_falls_to_other | D-01 fallback | CONTEXT D-01 |
| test_missing_category_falls_to_other | D-01 fallback | CONTEXT D-01 |
| test_lines_without_stencil_are_excluded | Phase 21 D-07 carry-forward | CONTEXT lines 285-300 in Project model |
| test_zone_order_follows_vocab_then_free_text_alphabetical | Determinism contract | PLAN behavior section |
| test_within_zone_order_preserves_input_order | Stability contract | PLAN behavior section |
| test_empty_input_returns_empty_array | Edge case | PLAN behavior section |

XtenAvLayoutEngineTest:

| Test | Requirement | What it locks |
|------|-------------|---------------|
| test_emits_zone_container_before_device_cells | DRAW-46 | Zone descriptor appears before child device descriptors in returned array |
| test_zone_emits_dashed_group_with_children | DRAW-46 | `dashed=1` + `fillColor=none` in style; children reference zone's id as parent |
| test_device_cell_style_contains_base64_stencil | DRAW-42 | `shape=stencil(<base64>)` matches the Phase 21 P03 encoder verbatim |
| test_curated_and_tier1_stencils_both_render | Phase 21 D-04 | Both stencil sources flow through the same code path |
| test_zone_label_xss_escaped | T-23-02-A1 | `<script>` → `&lt;script&gt;` |
| test_device_name_xss_escaped | T-23-02-A2 | `<img onerror=x>` → `&lt;img onerror=x&gt;` |
| test_emits_stable_ids_across_calls | D-LOCK-5/6 | `array_column(a, 'id') === array_column(b, 'id')` for same input |
| test_empty_zoned_input_returns_empty_array | Edge case | `[]` in → `[]` out |

## Decisions Implemented

- **D-01 (category map)** — `ZoneGrouper::assign()` reads `config('drawings.category_to_zone')` directly. Lookup returns null for `hardware` (the only renderer-relevant key in real data) so the OQ-1 Path B name-keyword scan runs as documented.
- **D-02 (per-device override)** — `$line['zone']` is checked first; non-empty string wins unconditionally.
- **D-04 (free-text escape hatch + zone vocab)** — Free-text zone strings are used verbatim as group keys; renderer creates a distinct dashed group per unique case-sensitive string. Vocab zones (from `config('drawings.zone_vocab')`) sort first, free-text alphabetically after.
- **D-09 (generic naming)** — Class names `ZoneGrouper` and `XtenAvLayoutEngine` contain no `Rams` prefix. Verified by file naming + class declarations. SCC merge readiness preserved.

## Threat Model Outcomes

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-23-02-A1 (XSS via free-text zone string) | mitigate | Implemented — `XtenAvLayoutEngine::xml()` private method passes every zone label through `htmlspecialchars($s, ENT_XML1 \| ENT_QUOTES, 'UTF-8')` before interpolation. `test_zone_label_xss_escaped` GREEN. |
| T-23-02-A2 (XSS via device name) | mitigate | Implemented — same `xml()` helper applied to the device name/display_name/part_number cascade. `test_device_name_xss_escaped` GREEN. |
| T-23-02-A3 (DoS via 10 RACK variants) | accept | Documented tradeoff per CONTEXT D-04. Renderer creates a group per unique string; consistency-vs-flexibility tradeoff lives in the Plan 06 dropdown helper text, not the renderer. |

## Deviations from Plan

### 1. [Rule 2 — Missing critical functionality] OQ-1 Path B name-keyword scan added to ZoneGrouper

- **Found during:** Task 1 RED phase planning.
- **Issue:** The plan's pseudo-code (`<action>` Step 3 in Task 1) implemented only the 3-step ladder (override → category map → OTHER). It did NOT implement the OQ-1 Path B name-keyword secondary derivation that the `<critical_invariants>` block #3 in the prompt explicitly requires AND that `23-DISCOVERY-OQ-1-CATEGORIES.md` documents as the BLOCKING DISPOSITION. With the plan's literal pseudo-code, `category=hardware` maps to `null` in config, and `null ?? 'OTHER'` would short-circuit to OTHER immediately — every hardware line would land in OTHER and the XTEN-AV reference visual (RACK / CEILING / WALL / etc.) would NEVER appear. That's broken behaviour shipping silently.
- **Fix:** Added the `NAME_KEYWORD_TO_ZONE` protected const on `ZoneGrouper` per the discovery file's keyword table (verbatim, including ordering rule "ceiling before generic rack"). Updated `resolveZone()` to fall through to the keyword scan when the category map returns `null` OR `'OTHER'`. Added 7 new tests covering the keyword fallback (ceiling/rack/wall/table groupings + ordering + case-insensitivity + name-vs-model fallback).
- **Files modified:** `app/Services/Drawings/ZoneGrouper.php`, `tests/Feature/Drawings/ZoneGrouperTest.php`.
- **Commits:** `6ead8c4` (RED) + `1951468` (GREEN).

### 2. [Rule 2 — Missing critical functionality] Empty-zone footprint floor

- **Found during:** Task 2 GREEN phase (XtenAvLayoutEngine `boundsOf()`).
- **Issue:** Plan pseudo-code's `boundsOf` would emit `width=20 height=20` (just padding) for a zone with zero child devices — a near-invisible 20x20 dot. Not user-facing yet, but if Plan 23-04's paginator ever sends an empty zone forward (e.g. all devices filtered out), the rendered dashed box would be undetectable in the visual contract.
- **Fix:** `boundsOf()` empty-input branch returns the default-device footprint plus padding + title-strip (260 × 184 px) so an empty group renders as a visible, if small, labeled box. Defensive — current orchestrator never produces empty zones (ZoneGrouper filters lines without stencils), but the guarantee survives future refactors.
- **Files modified:** `app/Services/Drawings/XtenAvLayoutEngine.php`.
- **Commit:** `d903a3d`.

No other deviations.

## Authentication Gates

None — no external auth or paid API surface touched.

## Self-Check: PASSED

Verified at commits `1951468` (Task 1) + `d903a3d` (Task 2):

- File `app/Services/Drawings/ZoneGrouper.php` exists.
- File `app/Services/Drawings/XtenAvLayoutEngine.php` exists.
- File `tests/Feature/Drawings/ZoneGrouperTest.php` exists.
- File `tests/Feature/Drawings/XtenAvLayoutEngineTest.php` exists.
- Commit `6ead8c4` (test RED — ZoneGrouper) found in `git log`.
- Commit `1951468` (feat GREEN — ZoneGrouper) found in `git log`.
- Commit `ea2414a` (test RED — XtenAvLayoutEngine) found in `git log`.
- Commit `d903a3d` (feat GREEN — XtenAvLayoutEngine) found in `git log`.
- `php artisan test --filter='ZoneGrouper|XtenAvLayoutEngine'` exited 0 with 23 tests / 41 assertions GREEN.
- Grep for `AIManager|AICache|AIUsage` against both helpers: 0 hits (D-LOCK-5).
- Grep for `->update|->save|::create|DB::` against both helpers: 0 hits (D-LOCK-6).
- Grep for `htmlspecialchars` against XtenAvLayoutEngine: 1 hit (T-23-02-A1 + T-23-02-A2 mitigation).
- Grep for `shape=stencil(` against XtenAvLayoutEngine: 2 hits (DRAW-42 base64 embed — prefix const + comment).
- Grep for `dashed=1` against XtenAvLayoutEngine: 2 hits (DRAW-46 dashed zone — const value + comment).
- `git diff --stat` against the 5 v1.3 invariant files: empty (Phase 21 D-10 carry-forward preserved).
- `git diff --stat` against `app/Services/Drawings/DrawIoBuilderService.php`: empty (orchestrator rewire is Plan 23-05's job — Plan 02 does not touch it).
- Both touched PHP files pass `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l` with "No syntax errors detected".

## Known Stubs

None. Both helpers ship with complete logic; no placeholder values flow to UI. Plan 23-05 wires them into `DrawIoBuilderService` — until then they exist but are not called from any public surface, by design.

## 🚨 Files to upload to live

Per `feedback_php_lint_before_push.md`: RAMS deploy = `git push` to `live` remote → SSH to `/home/stcav/rams.21stcav.com/` → `sudo -u stcav git pull` + `sudo -u stcav php artisan config:clear`. No migration in this plan (Plan 23-01 already shipped the schema change), so no `migrate --force` needed.

Files this plan added (for traceability — the actual deploy is a git pull):

- `app/Services/Drawings/ZoneGrouper.php`  *(new — pure read-only helper, no DB/route/AI touch)*
- `app/Services/Drawings/XtenAvLayoutEngine.php`  *(new — pure read-only helper, no DB/route/AI touch)*
- `tests/Feature/Drawings/ZoneGrouperTest.php`  *(new — test file; production not affected)*
- `tests/Feature/Drawings/XtenAvLayoutEngineTest.php`  *(new — test file; production not affected)*

No production behaviour change yet — `DrawIoBuilderService` does NOT call either helper. Plan 23-05 is the orchestrator-rewire plan that activates them. **Until Plan 05 ships, deploying Plan 02 is a no-op for engineers visiting `/admin/drawings/draw-io-spike/{project}`** — the spike route still emits the Phase 21 P03 builder output unchanged.

Post-deploy runbook (RAMS):

```bash
ssh stcav@rams.21stcav.com
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav php artisan config:clear
```

(No migration step. Plan 23-01's `2026_05_13_120000_add_metadata_to_projects_table.php` is the only Phase 23 migration; it shipped with that plan.)

Plans 23-03 (CableRouter) and 23-05 (DrawIoBuilderService orchestrator rewire) are unblocked.
