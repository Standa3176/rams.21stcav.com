# Phase 23: XTEN-AV-Style Renderer — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-13
**Phase:** 23-xten-av-style-renderer
**Areas discussed:** Zones + overrides (DRAW-46), Activation surface

---

## Phase Target Clarification

| Option | Description | Selected |
|--------|-------------|----------|
| Phase 23 renderer | Discuss Phase 23: XTEN-AV-Style Renderer — the next v2.0 milestone phase. Treat 'ram app' as a transcription artifact. | ✓ |
| New phase 23 about RAMS | Insert a new RAMS-app-related phase as 23 (and renumber the v2.0 renderer to 23.1/24+). | |
| Different phase number | Neither — meant a different phase. | |

**User's choice:** Phase 23 renderer
**Notes:** "ram app" treated as transcription artifact. Confirmed user wants Phase 23 XTEN-AV-Style Renderer (per ROADMAP.md line 46) — the v2.0 milestone's visual deliverable phase.

---

## Gray Area Selection

| Option | Description | Selected |
|--------|-------------|----------|
| Paginator policy | DRAW-47 multi-page paginator — when sub-sheets appear, what goes on system overview. | |
| Zones + overrides | DRAW-46 sub-room zones — category → zone map, override storage, override UI sequencing. | ✓ |
| NULL-FK fallback | Renderer behaviour when port FKs are NULL but device known. | |
| Activation surface | How engineers reach Phase 23 output while shipping viewable-but-not-default. | ✓ |

**User's choice:** Zones + overrides AND Activation surface
**Notes:** Other 4 areas (paginator, NULL-FK fallback, title block, cable routing/labels) get Claude's-discretion defaults captured in CONTEXT.md D-06..D-09 with explicit rationale.

---

## Zones + Overrides (DRAW-46)

### Q1: How should default zones be derived for each device?

| Option | Description | Selected |
|--------|-------------|----------|
| Config-map by category | Static `config/drawings.php` map: device category → default zone. Mirrors `config/cables.php` Phase-22-locked pattern. Engineer tunes by editing config. | ✓ |
| Stencil metadata | Add `default_zone` to `device_stencils.metadata` JSON. Per-part_number declaration. More granular but adds curation burden. | |
| Hybrid — metadata if set, else category-map | Renderer reads metadata first, falls back to config map. Best of both; slightly more renderer logic. | |

**User's choice:** Config-map by category
**Notes:** Recommended option won. Engineering tunes a single config file — no curation burden on every new seed entry, no metadata layer to maintain. Captured as D-01 in CONTEXT.md.

### Q2: Where does the engineer's per-device-instance zone override live?

| Option | Description | Selected |
|--------|-------------|----------|
| equipment_list line | Add `zone` key to per-equipment JSON in `latestPackage->extracted_data['equipment']`. Zero migrations. Lives WITH device-instance data the renderer already reads. | ✓ |
| New pivot table | `project_device_zones` table with FKs. More normalised but creates fragile equipment-line identity problem on re-import. Migration + new model. | |
| Render-time-only | No override stored at all in Phase 23. Always uses category-map default. Override deferred to Phase 24. | |

**User's choice:** equipment_list line
**Notes:** Captured as D-02 in CONTEXT.md. Rejected the pivot table option because re-imports would break the identity link ("which line index? which part_number?"). Zero migrations is a strong win.

### Q3: Does the per-device zone override UI ship in Phase 23 or defer?

| Option | Description | Selected |
|--------|-------------|----------|
| Defer to Phase 24 | Phase 23 ships renderer + reads `zone` from equipment_list line, no UI for setting it. Engineers edit JSON directly via quote-review until Phase 24. | |
| Ship in Phase 23 | Add 'zone' dropdown column to quote-review equipment table. UI scope ~1 plan. Engineers can override on day 1. | ✓ |
| Ship a thin Phase 23.1 | Phase 23 stays renderer-only; decimal 23.1 ships the override UI. | |

**User's choice:** Ship in Phase 23
**Notes:** Captured as D-03 in CONTEXT.md. User wants engineers to have the override on day 1 without waiting for Phase 24. ~1 plan of UI scope added to Phase 23.

### Q4: How is the zone vocabulary defined?

| Option | Description | Selected |
|--------|-------------|----------|
| Fixed enum in config | Hard-coded list in `config/drawings.php`: RACK, CEILING, WALL, TABLE, RECEPTION, FLOOR, PAGING_STATION, EXTERNAL, OTHER. Dropdown reads from config. | |
| Free-text per project | Engineer types any zone string. Most flexible but risks fragmentation across projects. | |
| Config enum + 'Other (free text)' | Dropdown shows config enum + Other option that opens free-text input. Strict-by-default with escape hatch. | ✓ |

**User's choice:** Config enum + "Other (free text)"
**Notes:** Captured as D-04 in CONTEXT.md. Strict default zones for visual consistency across projects, but engineer has an escape hatch for one-off scenarios.

---

## Activation Surface

### Q1: How should engineers reach Phase 23's renderer output?

| Option | Description | Selected |
|--------|-------------|----------|
| Evolve spike route in place | Existing `/admin/drawings/draw-io-spike/{project}` route already renders via `DrawIoBuilderService`. Phase 23 upgrades the builder behind it. Same URL, same controller, same Blade. Phase 21 D-08 preserved. | ✓ |
| Add /admin/drawings/v2 alongside | Keep spike route frozen as baseline + add new v2 route. Two admin routes coexist; A/B compare possible. | |
| Project page tab visible to admins | 'V2 Schematic' tab on project show page, admin-only. Larger UI scope. | |
| Project page tab for ALL users | Same tab visible to non-admin engineers + PMs. Highest surface, earliest exposure. | |

**User's choice:** Evolve spike route in place
**Notes:** Captured as D-05 in CONTEXT.md. Smallest scope; matches Phase 21 D-08 promise to keep the spike route live. Internal renaming permitted (new supporting classes alongside `DrawIoBuilderService`), but the public route + service method signature is the contract. Engineers visit the URL they already know — no relearning, no project-page surface area to design.

---

## Final Decision Check

### Q: Ready for context or more gray areas to discuss?

| Option | Description | Selected |
|--------|-------------|----------|
| Ready for context | Capture decisions in CONTEXT.md. Paginator policy, NULL-FK fallback, title block, cable routing get Claude's-discretion defaults. | ✓ |
| Discuss paginator policy | DRAW-47 — when sub-sheets appear. | |
| Discuss NULL-FK fallback | Renderer behaviour when port FK NULL but device known. | |
| Discuss title block + cable routing | DRAW-48 fields + DRAW-43/45 routing algorithm + label collisions. | |

**User's choice:** Ready for context
**Notes:** D-06 (paginator threshold), D-07 (NULL-FK warning glyph + card-edge fallback), D-08 (title block source mix), D-09 (default orthogonal routing) captured as Claude's-discretion defaults with explicit rationale. D-10 flags the signal-type colour discrepancy between `config/cables.php` (Phase 22 locked) and REQUIREMENTS.md DRAW-44 narrative — planner must verify against the XTEN-AV reference image before shipping.

---

## Claude's Discretion (defaults locked without explicit user input)

- **Paginator policy (DRAW-47)** — Threshold-based: system overview always; audio/video/control/network sub-sheets emit only when ≥5 cables of that signal type AND ≥3 devices touching that signal type on the project. Engineer override deferred to Phase 24 (tinker-only via `Project.metadata.force_sheets` in Phase 23).
- **NULL-FK fallback (Phase 22 ambiguous rows)** — Render cable to device-card edge (heuristic: source-like categories use right edge, else left) with small ⚠ warning glyph. Skip cable entirely if BOTH device IDs NULL.
- **Title block source-of-truth (DRAW-48)** — project / client / sheet # / date / revision are derived; designed-by + drawn-by auto-fill from `Auth::user()->name`; checked-by reads `Project.metadata.drawing_checked_by` (defaults "—"). Title-block edit UI deferred.
- **Cable routing + labels (DRAW-43/45)** — draw.io default orthogonal routing. Cable IDs from `cable_schedule_items.cable_id` placed at midpoint with built-in anti-overlap. Bundle-parallel + aggressive collision avoidance deferred to v2.1 polish.
- **Signal-type colour map (DRAW-44)** — Reads `config/cables.php` `signal_type_colours` (Phase 22 single source of truth). Open issue D-10: planner verifies the current config values match the XTEN-AV reference image BEFORE shipping; if mismatch, raises a separate config-update ticket — does NOT silently change colours during Phase 23.

## Deferred Ideas (raised during analysis)

- Title-block edit UI → Phase 24 or 23.1
- Force-sheet toggle UI → Phase 24
- Bundle-parallel-cable router → v2.1 polish
- Re-align REQUIREMENTS.md DRAW-44 narrative ↔ `config/cables.php` (if reference image disagrees) → separate ticket
