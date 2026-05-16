# Phase 23 Verification — XTEN-AV-Style Renderer

**Phase:** 23 — XTEN-AV-Style Renderer
**Disposition author:** Claude Opus 4.7 (1M context) executor (GSD execute-phase / 23-07)
**Disposition date (automated rows):** 2026-05-15
**Branch at audit:** `feat/worksheet-classifier-universal`
**Audited HEAD:** `09b47c0` (final Phase 23 plan test commit — `test(23-07): Phase23InvariantGuardTest`)
**Manual UAT verifier:** _pending — to be filled by the user_
**Manual UAT date:** _pending_

This document closes every BLOCKING decision (D-01..D-10) and every functional requirement (DRAW-42..49) for Phase 23. Each row cites a specific test file + assertion (or commit SHA, or live verification step) so that any future agent / verifier can re-confirm the closure without re-deriving the evidence.

Two rows — D-10 colour side-by-side AND Open Question 3 multi-tab embed UX — CANNOT be discharged by automation. They are flagged `AWAITING HUMAN UAT` and surfaced at the Plan 23-07 checkpoint. They MUST be closed (or formally deferred via a separate ticket) before Phase 23 ships.

---

## D-10 Side-by-Side Result

**Status: AWAITING HUMAN UAT.** Automation cannot evaluate visual fidelity against the XTEN-AV PAGING SYSTEM reference image; this row requires the user to perform a side-by-side comparison.

### What the automated layer can confirm

| Property | Value | Evidence |
|----------|-------|----------|
| `config('cables.signal_type_colours')` is the SOLE colour source the renderer reads | YES | `tests/Feature/Drawings/Phase23InvariantGuardTest.php` `test_draw_44_edge_colour_from_config_cables` — asserts ≥1 config-derived hex appears as `strokeColor=...` in built XML |
| `config/cables.php` is unmodified by Phase 23 | YES | `tests/Feature/Drawings/V13SurfacesUntouchedTest.php` `test_config_cables_signal_type_colours_unchanged_by_phase_23` — asserts all 8 keys (`audio` / `video` / `control` / `network` / `usb` / `speaker` / `power` / `unknown`) carry their pre-Phase-23 hex values |
| Renderer never hardcodes colour hexes | YES | `app/Services/Drawings/CableRouter.php` reads ONLY via `config('cables.signal_type_colours.' . $signal)`; `grep` for literal `#[0-9A-F]{6}` across `app/Services/Drawings/*.php` returns only the brand-teal sheet border `#1B7A7A` (Plan 23-04 DRAW-49) which is NOT a signal colour |

### Current `config/cables.php` `signal_type_colours` mapping

| Signal type | Hex     | Plain-English name |
|-------------|---------|--------------------|
| audio       | #C0392B | red                |
| video       | #2980B9 | blue               |
| control     | #27AE60 | green              |
| network     | #8E44AD | purple             |
| usb         | #E67E22 | orange             |
| speaker     | #16A085 | teal               |
| power       | #7F8C8D | grey               |
| unknown     | #000000 | black              |

### Why a human side-by-side is required

`.planning/REQUIREMENTS.md` line 51 (DRAW-44 narrative) currently reads:

> `audio` purple, `video` purple, `control` blue, `network` blue, `USB` yellow/orange, `speaker/SPOUT` green.

That mapping does NOT match the current `config/cables.php` table above. One of the two is wrong. Per CONTEXT D-10, the BINDING visual contract is the XTEN-AV PAGING SYSTEM reference image (saved in the 2026-05-09 conversation thread). The reference image is what an engineer comparing a live render against an industry-standard schematic will see — not the REQUIREMENTS.md prose, and not the config table in isolation.

### Required user steps

1. Open the XTEN-AV PAGING SYSTEM reference image (2026-05-09 conversation).
2. Pick or create a project with at least one cable per signal type (`audio` / `video` / `control` / `network` / `usb` / `speaker`). The seeded paging-system fixture is fine for this.
3. Browse to `/admin/drawings/draw-io-spike/{project-id}` on local dev and look at the rendered XML in the draw.io iframe.
4. For each signal type, write down what colour the reference image uses for the SAME signal type and confirm whether the rendered output matches.
5. Fill the table below with `Y` / `N` per row, the description of the reference colour, and a SHIP / HOLD disposition.

### Side-by-side comparison (PLEASE FILL IN)

| Signal type | Reference image colour | `config/cables.php` hex | Match? (Y/N) |
|-------------|------------------------|-------------------------|--------------|
| audio       | _pending — fill in_    | #C0392B (red)           | _pending_    |
| video       | _pending_              | #2980B9 (blue)          | _pending_    |
| control     | _pending_              | #27AE60 (green)         | _pending_    |
| network     | _pending_              | #8E44AD (purple)        | _pending_    |
| usb         | _pending_              | #E67E22 (orange)        | _pending_    |
| speaker     | _pending_              | #16A085 (teal)          | _pending_    |

### Disposition (PLEASE FILL IN)

- [ ] **MATCHES — Phase 23 SHIPS with current `config/cables.php`.** Update REQUIREMENTS.md line 51 narrative to reflect the actual approved colour table (narrative drift, not a code defect).
- [ ] **MISMATCHES — Phase 23 SHIPS as-is BUT raise a SEPARATE config-update ticket.** Per CONTEXT D-10, Phase 23 MUST NOT mutate `config/cables.php`. The follow-up ticket changes `config/cables.php` AND re-confirms DRAW-44 against the reference image post-update.
- [ ] **MISMATCHES — Phase 23 HOLD.** Only choose if the colour mismatch would cause real-world signal-type misreading on the engineering floor.

**Separate ticket reference (if MISMATCHES):** _pending_

---

## Open Q3 Multi-Page Embed UX Result

**Status: AWAITING HUMAN UAT.** Automation can confirm the renderer emits valid `<mxfile>` multi-`<diagram>` XML; it CANNOT confirm that the draw.io v29.7.12 embed iframe renders that XML with working tab navigation in a real browser.

### What the automated layer can confirm

| Property | Value | Evidence |
|----------|-------|----------|
| Multi-sheet projects wrap output in `<mxfile>` with multiple `<diagram>` children | YES | `tests/Feature/Drawings/Phase23InvariantGuardTest.php` `test_draw_47_multi_page_wraps_in_mxfile` — asserts `<mxfile` present AND `substr_count($xml, '<diagram ') > 1` for a paging-system fixture with `force_sheets = ['audio','network']` |
| Each sheet carries an 8-field title block AND a dashed border | YES | `Phase23InvariantGuardTest::test_draw_48_title_block_eight_fields_per_sheet` + `test_draw_49_dashed_border_on_every_diagram` — assertion ladders bind border count ≥ sheet count |
| Empty projects keep legacy single-`<mxGraphModel>` shape (no `<mxfile>` wrapper — backwards compat) | YES | `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` `test_empty_project_emits_legacy_single_mxgraphmodel` (Plan 23-05) — preserves Phase 21 P03 contract |
| Spike admin route URL / controller / shim unchanged (Phase 21 D-08) | YES | `V13SurfacesUntouchedTest::test_draw_io_spike_controller_constructor_has_two_parameters` + `test_draw_io_spike_builder_service_shim_still_delegates` |

### Why a human browser UAT is required

The `<mxfile host="app.diagrams.net" agent="21cav-rams-renderer/v23" version="29.7.12">` wrapper is the documented draw.io multi-page format, but only the live draw.io v29.7.12 iframe (loaded via the existing spike Blade view) can confirm that:

- The iframe loads the wrapper without erroring.
- A clickable tab bar appears (one tab per `<diagram>` child).
- Switching tabs swaps the rendered cell set AND updates the title-block `Sheet:` field from `AV-201` to `AV-202` etc.
- The dashed border stays present on every tab.

This is precisely the UX dependency the planner flagged as "Open Question 3 — Multi-page `<mxfile>` embed UX must be verified in a real browser before Phase 23 ships, because the renderer can be byte-perfect and the iframe still hide tabs if it's running in a single-page embed mode."

### Required user steps

1. Pick a paging-system project (≥5 cables on at least one signal type AND ≥3 devices touching that signal — OR use `Project.metadata.force_sheets = ['audio','network']` to bypass the D-06 threshold).
2. Open `/admin/drawings/draw-io-spike/{project-id}` in a modern browser (Chrome/Firefox/Safari).
3. Confirm the draw.io iframe loads and shows rendered content (cells visible, no error overlay).
4. Look for a tab bar at the bottom or top of the iframe — one tab per emitted sheet.
5. Click each tab and confirm:
   - Content updates (different cells visible per tab).
   - Title-block `Sheet:` field changes from `AV-201` → `AV-202` → etc.
   - Dashed border remains on every tab.
6. Fill the disposition below.

### Disposition (PLEASE FILL IN)

| Question | Answer |
|----------|--------|
| Browser + version                              | _pending_ |
| Project tested (project id / fixture name)     | _pending_ |
| `<diagram>` child count in emitted XML         | _pending_ |
| Tab bar renders?                               | _pending (YES / NO)_ |
| Per-tab content updates on click?              | _pending (YES / NO)_ |
| Title-block `Sheet:` field changes per tab?    | _pending (YES / NO)_ |
| Dashed border on every tab?                    | _pending (YES / NO)_ |

**Disposition:**

- [ ] **PASS — multi-page `<mxfile>` renders with working tabs in draw.io v29.7.12 embed.** Phase 23 SHIPS. Open Q3 closed.
- [ ] **FAIL — embed renders only the first sheet, no tab navigation.** Defer Open Q3 to a Phase 24 polish ticket (single-page fallback is acceptable: each sheet renders, engineers click the "all sheets" link in Blade as a workaround). Phase 23 SHIPS with this Phase 24 followup queued.
- [ ] **FAIL — embed errors / refuses to render the wrapper.** Phase 23 HOLD. Investigate the iframe query-params (see 23-RESEARCH.md Open Q3 recommendation) or strip the `<mxfile>` wrapper and emit a single mega-sheet as a temporary fallback.

**Follow-up Phase 24 ticket reference (if FAIL — defer):** _pending_

---

## D-01..D-10 Closure Log

Each decision row cites the FIRST piece of evidence that closes it — typically a test method, occasionally a config-key location or a SUMMARY paragraph. All test paths are relative to the repo root.

| Decision | Status | Evidence |
|----------|--------|----------|
| **D-01** — Default zone derived from category via `config/drawings.php` map | SATISFIED | `config/drawings.php` `category_to_zone` key (Plan 23-01) + `tests/Feature/Drawings/ZoneGrouperTest.php` (15 tests / 22 assertions including `test_unknown_category_falls_to_other` + `test_missing_category_falls_to_other`) + `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md` (real-data resolution: Path B name-keyword fallback because `hardware` category dominates) |
| **D-02** — Per-device-instance zone override on `equipment_list` line | SATISFIED | `tests/Feature/Drawings/ZoneGrouperTest.php::test_per_device_zone_override_wins` + Plan 23-06 write side `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` (7 tests covering vocab persistence, free-text, Unicode, XSS reject, length cap, empty-omit, full-field preservation) |
| **D-03** — Zone override UI ships in Phase 23 (review form column) | SATISFIED | Plan 23-06 SUMMARY + `resources/views/project-packages/review.blade.php` (zone `<th>` column + `zonePicker` Alpine factory + `window.__zoneVocab` published via `@js`) + `ReviewZoneDropdownTest::test_review_form_persists_vocab_zone_value` |
| **D-04** — Zone vocabulary = config enum + free-text escape hatch | SATISFIED | `config/drawings.php` `zone_vocab` key (Plan 23-01) + `ZoneGrouperTest::test_free_text_zone_creates_separate_group` + `ReviewZoneDropdownTest::test_review_form_persists_unicode_zone_label` (Unicode `Régie` accept) + `ReviewZoneDropdownTest::test_review_form_rejects_xss_payload_in_zone` (regex `/^[\p{L}\p{N} _\-]+$/u` allowlist) |
| **D-05** — Evolve spike route in place; preserve public contract | SATISFIED | `V13SurfacesUntouchedTest::test_draw_io_spike_controller_constructor_has_two_parameters` (reflection assertion — 2-param ctor preserved) + `V13SurfacesUntouchedTest::test_draw_io_spike_builder_service_shim_still_delegates` (Phase 21 D-08 10-line shim still wraps `DrawIoBuilderService`) + Plan 23-05 SUMMARY ("`build(Project): string` signature unchanged; spike admin route URL + controller class + constructor signature all unchanged") |
| **D-06** — Paginator policy: BOTH-AND threshold + `force_sheets` metadata override | SATISFIED | `tests/Feature/Drawings/SheetPaginatorTest.php` (8 tests covering `test_below_cable_threshold_no_sub_sheet`, `test_below_device_threshold_no_sub_sheet`, `test_above_threshold_emits_sub_sheet`, `test_force_sheets_metadata_override`, `test_force_sheets_invalid_entry_is_ignored`, `test_force_sheets_non_array_metadata_is_ignored`, `test_sheet_order_is_deterministic`) + `Phase23InvariantGuardTest::test_draw_47_multi_page_wraps_in_mxfile` (force_sheets escape hatch end-to-end) |
| **D-07** — NULL-FK fallback ladder (skip / coord-fallback + ⚠ / named-port) | SATISFIED | `tests/Feature/Drawings/CableRouterTest.php` (16 tests including `test_null_fk_renders_with_warning_glyph`, `test_source_port_null_dest_port_present_falls_back`, `test_dest_port_null_source_port_present_falls_back`, `test_double_null_fk_cable_is_skipped`) + Plan 23-03 SUMMARY 5-row fallback table |
| **D-08** — Title block source resolution (8-field mixed defaults + override stub) | SATISFIED | `tests/Feature/Drawings/TitleBlockRendererTest.php` (13 tests covering all 8 fields + 4 XSS escape paths + `test_checked_by_reads_metadata` + `test_designed_by_reads_auth_user_name` + `test_revision_reads_drawing_version`) + Plan 23-05 SUMMARY orchestrator drawing-revision resolution (`Project::drawings()->where('kind', KIND_SCHEMATIC)->where('status', '!=', STATUS_SUPERSEDED)->latest('updated_at')->first()`) |
| **D-09** — Generic naming (no `rams_` prefix; SCC merge readiness) | SATISFIED | All Phase 23 service classes pass the generic-naming audit: `ZoneGrouper`, `XtenAvLayoutEngine`, `CableRouter`, `SheetPaginator`, `TitleBlockRenderer`, `SheetBorderRenderer`, `DrawIoBuilderService` — zero `Rams` prefix. Migration filename `2026_05_13_120000_add_metadata_to_projects_table.php` — column = `metadata`, NOT `rams_metadata`. Verified across all Plan SUMMARYs (01-06) under "D-09 verified" decision lines |
| **D-10** — Colour single source of truth (`config/cables.php signal_type_colours`) | **CODE-SATISFIED — AWAITING HUMAN UAT for visual fidelity.** See `## D-10 Side-by-Side Result` above. Code-side evidence: `V13SurfacesUntouchedTest::test_config_cables_signal_type_colours_unchanged_by_phase_23` (8-key value lock) + `Phase23InvariantGuardTest::test_draw_44_edge_colour_from_config_cables` (renderer reads config, not literals) + zero diff on `git diff --stat config/cables.php` against the Phase 23 fork base. Visual-fidelity row pending the manual side-by-side. |

---

## DRAW-42..49 Closure Log

Each requirement row cites the test method that asserts its observable behaviour. All test methods are in `tests/Feature/Drawings/Phase23InvariantGuardTest.php` unless noted otherwise. The companion lower-level tests (per-helper unit suites) provide finer-grained coverage and are cited where they add evidence beyond the invariant guard.

| Requirement | Status | Evidence |
|-------------|--------|----------|
| **DRAW-42** — Custom device-card stencils (logo / name / model / port rails) | SATISFIED | `Phase23InvariantGuardTest::test_draw_42_device_cards_emit_base64_stencil_with_value` (asserts `shape=stencil(` present + `<mxCell id="dev-{zoneSlug}-{idx}" value="…"`) + `XtenAvLayoutEngineTest::test_device_cell_style_contains_base64_stencil` + `XtenAvLayoutEngineTest::test_curated_and_tier1_stencils_both_render` (Phase 21 D-04 carry-forward — both stencil tiers flow through same embed) |
| **DRAW-43** — Port-to-port cable routing | SATISFIED | `Phase23InvariantGuardTest::test_draw_43_emits_port_to_port_or_coordinate_edge` (asserts either `exitPortId=`/`entryPortId=` happy path OR `exitX=`/`entryX=` coordinate fallback) + `CableRouterTest::test_port_to_port_edge_uses_exit_port_id` (Tier 2 happy path) + `CableRouterTest::test_tier15_source_stencil_falls_back_to_coordinate_style` + `test_tier15_dest_stencil_falls_back_to_coordinate_style` + `test_both_tier15_stencils_fall_back` (OQ-4 Path B Tier 1.5 gate) |
| **DRAW-44** — Signal-type colour coding | **CODE-SATISFIED — AWAITING HUMAN UAT (D-10 row).** Code-side: `Phase23InvariantGuardTest::test_draw_44_edge_colour_from_config_cables` (≥1 config hex appears as `strokeColor=`) + `CableRouterTest::test_cable_colour_from_config_signal_type_colours` (exact-match per signal type) + `CableRouterTest::test_unknown_signal_type_falls_back_to_unknown_colour` (`unknown` key fallback). Visual fidelity row depends on the D-10 manual side-by-side above. |
| **DRAW-45** — Cable ID label at midpoint | SATISFIED | `Phase23InvariantGuardTest::test_draw_45_edge_value_attribute_carries_cable_id` (regex match on `<mxCell id="cab-\d+" value="[A-Z0-9\-]+" ... edge="1"`) + `CableRouterTest::test_edge_value_is_cable_id` + `CableRouterTest::test_cable_id_xss_escaped` (T-23-03-A1 defence-in-depth) |
| **DRAW-46** — Sub-room zones as dashed groups | SATISFIED | `Phase23InvariantGuardTest::test_draw_46_zones_render_as_dashed_groups` (regex match on `<mxCell id="zone-..." value="..." style="...dashed=1..."`) + `XtenAvLayoutEngineTest::test_zone_emits_dashed_group_with_children` + `ZoneGrouperTest` (15 tests / 22 assertions covering D-01 / D-02 / D-04 / OQ-1 Path B) + Plan 23-06 review-form write side (`ReviewZoneDropdownTest` 7 tests) |
| **DRAW-47** — Multi-page paginator | SATISFIED | `Phase23InvariantGuardTest::test_draw_47_multi_page_wraps_in_mxfile` (asserts `<mxfile` + `substr_count(<diagram ) > 1` for force_sheets fixture) + `SheetPaginatorTest` (8 tests covering BOTH-AND threshold + force_sheets override + canonical ordering) + `DrawIoBuilderServiceMultiSheetTest::test_paging_system_fixture_emits_multiple_sheets`. Open Q3 multi-tab embed UX is the AWAITING HUMAN UAT row above. |
| **DRAW-48** — Standardised 8-field title block | SATISFIED | `Phase23InvariantGuardTest::test_draw_48_title_block_eight_fields_per_sheet` (asserts ≥8 `id="tb-` cells + all 8 field labels present) + `TitleBlockRendererTest` (13 tests covering all 8 fields + 4 XSS paths + Carbon-frozen date determinism + Auth user / metadata fallbacks) |
| **DRAW-49** — Dashed sheet border on every page | SATISFIED | `Phase23InvariantGuardTest::test_draw_49_dashed_border_on_every_diagram` (asserts border-cell count ≥ sheet count) + `SheetBorderRendererTest` (4 tests covering geometry inset, dashed style, brand-teal `#1B7A7A`, determinism) + `DrawIoBuilderServiceMultiSheetTest::test_each_sheet_has_dashed_border_and_title_block` |
| _Bonus_ — Determinism contract (D-LOCK-5/6 carry-forward) | SATISFIED | `Phase23InvariantGuardTest::test_phase_23_builder_is_byte_identical_across_calls` (`build()` twice → `assertSame`) + `V13SurfacesUntouchedTest::test_no_phase_23_class_writes_to_database` (zero Eloquent writes) + `V13SurfacesUntouchedTest::test_no_phase_23_class_calls_ai` (zero `AIManager` / `AICache` / `AIUsage` references) + `DrawIoBuilderServiceMultiSheetTest::test_determinism_across_calls` |

---

## Phase 21 + Phase 22 Carry-Forward Invariants

These rows assert that Phase 23 has NOT regressed any prior-phase invariant. Each row points at a single test that fails on the FIRST Phase 23 commit which tries to mutate the protected surface.

| Carry-forward invariant | Status | Evidence |
|-------------------------|--------|----------|
| Phase 21 D-08 — Spike admin route + controller signature preserved | SATISFIED | `V13SurfacesUntouchedTest::test_draw_io_spike_controller_constructor_has_two_parameters` (reflection: exactly 2 params, types `DrawIoBuilderService` then `DrawingService`) + `test_draw_io_spike_builder_service_shim_still_delegates` (10-line shim wraps `DrawIoBuilderService`) |
| Phase 21 D-10 — 5 v1.3 surface files unchanged (D2 generator + bound PDF + O&M legacy renderer) | SATISFIED | `V13SurfacesUntouchedTest::test_v13_surface_files_still_exist` (all 5 paths exist) + `test_v13_surfaces_have_no_phase_23_imports` (zero `use App\Services\Drawings\(ZoneGrouper\|XtenAvLayoutEngine\|CableRouter\|SheetPaginator\|TitleBlockRenderer\|SheetBorderRenderer)` across all 5 files) |
| Phase 22 D-10 — `CableScheduleItem::$with` empty (no class-level eager-load LEFT JOINs) | SATISFIED | `V13SurfacesUntouchedTest::test_cable_schedule_item_with_property_is_empty` (reflection: `$with === []`) + `CableRouterTest::test_eager_loading_keeps_query_count_bounded` (call-site `loadMissing` discipline) |
| Phase 22 D-10 — `config/cables.php signal_type_colours` is the single source of truth | **CODE-SATISFIED — AWAITING HUMAN UAT (D-10 row).** Code-side: `V13SurfacesUntouchedTest::test_config_cables_signal_type_colours_unchanged_by_phase_23` (8-key value lock) + zero hardcoded signal-colour hexes in `app/Services/Drawings/CableRouter.php`. |
| CLAUDE.md AI-only-for-formatting constraint (D-LOCK-5) | SATISFIED | `V13SurfacesUntouchedTest::test_no_phase_23_class_calls_ai` (zero `AIManager` / `AICache` / `AIUsage` references in the 6 Phase 23 helpers + the rewired orchestrator) |
| Determinism contract (D-LOCK-5/6 — no Eloquent writes in renderer path) | SATISFIED | `V13SurfacesUntouchedTest::test_no_phase_23_class_writes_to_database` (zero `->update(` / `->save(` / `->delete(` / `::create(` / `::firstOrCreate(` / `::updateOrCreate(` / `DB::insert(` / `DB::update(` across the 6 Phase 23 helpers) |

---

## Phase 23 Full Test Suite — Aggregate Snapshot

Re-verified at HEAD `09b47c0` on 2026-05-15:

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test \
  --filter='V13SurfacesUntouchedTest|Phase23InvariantGuardTest' --stop-on-failure
```

Result: **17 tests / 165 assertions GREEN** across the two static invariant guards (8 V13Surfaces + 9 Phase23Invariant). Run duration: 6.38s.

Lower-level Phase 23 suites (per Plan 01-06 SUMMARYs — all GREEN at their respective Plan SUMMARY commits):

| Suite | Tests | Assertions | Plan |
|-------|-------|------------|------|
| Phase23OpenQuestionsResolutionTest | 2  | 7  | 23-01 |
| ProjectMetadataMigrationTest        | 5  | 8  | 23-01 |
| XtenAvDeterminismHarnessTest        | 3  | 13 | 23-01 |
| ZoneGrouperTest                     | 15 | 22 | 23-02 |
| XtenAvLayoutEngineTest              | 8  | 19 | 23-02 |
| CableRouterTest                     | 16 | 41 | 23-03 |
| SheetPaginatorTest                  | 8  | 18 | 23-04 |
| TitleBlockRendererTest              | 13 | 30 | 23-04 |
| SheetBorderRendererTest             | 4  | 10 | 23-04 |
| DrawIoBuilderServiceMultiSheetTest  | 8  | 24 | 23-05 |
| DrawIoBuilderServiceTest            | 6  | 22 | 23-05 (adjusted from Phase 21 P03) |
| ReviewZoneDropdownTest              | 7  | — (see 23-06 SUMMARY) | 23-06 |
| Phase23InvariantGuardTest           | 9  | (part of 165 above) | 23-07 |
| V13SurfacesUntouchedTest            | 8  | (part of 165 above) | 23-07 |

These per-suite counts are sourced from each Plan SUMMARY's "Self-Check: PASSED" block and were green at the SUMMARY commit time. Run the full Phase 23 filter (`--filter='Drawings|XtenAv|SheetPaginator|ZoneGrouper|CableRouter|TitleBlock|SheetBorder|Phase23|ReviewZone|V13Surfaces'`) for the final aggregate check at ship time.

---

## Phase 23 Static Guard Commits

| Commit  | Subject                                                                                                              |
|---------|----------------------------------------------------------------------------------------------------------------------|
| `5c46659` | `test(23-07): V13SurfacesUntouchedTest — guard 5 v1.3 surfaces + spike shim + config/cables single source of truth` |
| `09b47c0` | `test(23-07): Phase23InvariantGuardTest — per-DRAW-XX CI assertions (DRAW-42..49)`                                  |

These two commits red-block any future Phase that tries to:

- Mutate a v1.3 surface file (Phase 21 D-10 / Phase 22 D-10)
- Restore a class-level eager-load on `CableScheduleItem`
- Change the spike controller constructor signature (Phase 21 D-08)
- Mutate `config/cables.php signal_type_colours` (Phase 22 D-10)
- Introduce a Phase 23 renderer class that writes to the database (D-LOCK-6)
- Introduce a Phase 23 renderer class that calls `AIManager` / `AICache` / `AIUsage` (D-LOCK-5)
- Regress any DRAW-42..49 observable behaviour

---

## Ship Decision

**Status: BLOCKED ON HUMAN UAT.** Phase 23 is code-complete. All automated rows are SATISFIED. The remaining two items — D-10 colour side-by-side and Open Q3 multi-tab embed UX — are surfaced at the Plan 23-07 BLOCKING checkpoint.

The user will record SHIP or HOLD here after performing the two manual UAT items above:

- [ ] **SHIP — Phase 23 verified end-to-end. Tag this commit + push to live.** _(date / verifier:_ _pending_ _)_
- [ ] **HOLD — see blocker note below.** _(date / verifier:_ _pending_ _)_

**Blocker (if HOLD):** _pending_

---

*Phase: 23-xten-av-style-renderer*
*Document last updated: 2026-05-15 (automated rows) — manual UAT rows pending*
