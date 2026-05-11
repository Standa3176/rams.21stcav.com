# Phase 22: Cable Schedule with Port-Level FKs — Context

**Gathered:** 2026-05-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Add four nullable port-level foreign key columns to `cable_schedule_items` (`source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id`), rebuild the cable schedule edit UI with a modal port picker per row, validate connector compatibility on save (warning + override-with-note, never a hard block), and ship a one-shot artisan command that backfills port FKs for legacy rows where the quote `cable_list` "X to Y" naming is unambiguous.

**NOT in scope:**
- The renderer that consumes these FKs (Phase 23 — XTEN-AV-style port-to-port routing)
- The stencil curation UI that promotes auto-generated stencils to engineer-curated (Phase 24)
- AI-assisted port mapping for ambiguous cables (Phase 25)
- Changes to the existing schematic SVG generator (D2 path) or cable schedule XLSX export — both must continue rendering legacy rows where the new FKs are NULL

</domain>

<decisions>
## Implementation Decisions

### Carry-forward (locked in Phase 21, applies to Phase 22)

- **Generic naming convention (Phase 21 D-09):** FK column names use `source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` as spec'd in DRAW-37 — no `rams_` / `project_` prefix.
- **Don't break v1.3 surface (Phase 21 D-10):** `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `CableScheduleGeneratorService`, and the bound-PDF cable-list section MUST continue rendering legacy rows where the new FK columns are NULL. Phase 22 is strictly additive — no behaviour change for existing data paths.
- **DevicePort enum constants:** UI filtering and ordering use `DevicePort::SIDE_INPUT` / `SIDE_OUTPUT` and `DevicePort::DIRECTION_*` constants established in Phase 21. The picker filters and sorts using these, not magic strings.

### Cascading Dropdown UX (the only area you actively discussed)

- **D-01 — Modal picker per row:** Keep the existing `from_location` / `to_location` text inputs in the edit table. Add a compact trigger per row that opens a modal containing the 4-step cascading picker. Legacy text-only rows stay editable as plain text — port FKs only get populated when the engineer explicitly opens the picker on a row. Rationale: existing form is already 8 columns wide; inline cascading dropdowns wouldn't fit on tablets, and engineers need this to work on mobile mid-install.

- **D-02 — Side-by-side modal layout, source-left / destination-right:**
  ```
  ┌─ Pick ports ─────────────────────────────────────┐
  │  SOURCE                  DESTINATION             │
  │  [Device ▼]              [Device ▼]              │
  │  [Port ▼]                [Port ▼ — filtered by   │
  │                           signal-type compat]    │
  │                                                  │
  │  [Cancel]                              [Apply]   │
  └──────────────────────────────────────────────────┘
  ```
  Matches the natural "A → B" mental model for cable routing. Reads at a glance. Works on tablet landscape; collapses cleanly to stacked on phone portrait. Rejected the vertical 4-step stepper (too many taps for power users) and the all-stacked layout (wastes horizontal space on tablet).

- **D-03 — Compact 🔗 icon between From and To columns:** Tiny chain-link icon sits in a thin separator column between the From and To inputs. Tap = open picker. Two visual states:
  - **Unset:** faded outline icon (visual cue that port FKs are missing on this row)
  - **Set:** filled teal icon (consistent with the existing teal action colour in the codebase per dashboard styling)
  Adds 1 narrow column to the table (8 → 9), still fits on tablet. Visually communicates "these two cells are paired by FK" at a glance.

- **D-04 — Picker overwrites From/To text with canonical labels on Apply:** When the engineer confirms a port pair in the modal, write the canonical labels into the From and To text inputs (overwriting any existing text). Format:
  ```
  From: "Crestron HD-MD-400 (HDMI 1)"
  To:   "Samsung QM65 (HDMI 1)"
  ```
  Single source of truth — text always reflects the FK selection. Engineers who want custom freeform text (e.g. "Mains via 13A spur") simply don't open the picker on that row. Rejected "preserve existing text" (creates text/FK drift) and "prompt on overwrite" (extra clicks every time, friction in the field).

### Claude's Discretion — sensible defaults for the planner

The three deferred areas below are flexible. Planner and researcher should adopt these defaults unless deeper investigation surfaces a reason to change. Locked in CONTEXT for clarity — engineers don't have to revisit these unless they push back.

- **Compatibility matrix → config-driven (`config/cables.php`):** Ship a default rule set that does exact connector match for "compatible" (HDMI↔HDMI ✓, HDMI↔RJ45 ⚠) with an explicit allowlist for known-interoperable pairs (HDMI↔DisplayPort via active adapter, USB-C↔Thunderbolt, RJ45↔SFP+ via SFP module). Strict-by-default with named exceptions is more transparent than a fuzzy "same signal_type" rule and keeps the warning meaningful. Engineers tune the matrix via config — no code change required. Phase 23's port-to-port renderer reads the same config for signal-type colour coding so the two stay consistent.

- **Override workflow → inline warning banner with required note:** When the picker's Apply would create an incompatible pair, the modal shows a yellow warning banner with a single required text field "Override reason (required)" before the Apply button accepts. The note persists to a new `connector_override_note` (text, nullable) column on `cable_schedule_items`. Rationale:
  - Inline beats modal-confirm — engineer already IS in a modal, another modal-on-modal feels wrong
  - Required note > optional note > silent override → forces a one-liner that gives auditable context without much friction
  - No "block-the-save" option per DRAW-39 (must always allow engineer override)

- **Backfill command → manual `php artisan cables:backfill-port-fks {project? : ID}`:** Idempotent, all rows targeted regardless of origin (quote-imported AND engineer-added). Default behaviour is dry-run (logs per-row decisions to stdout, writes nothing). Pass `--apply` to actually populate FKs. Per-row decision categories: `matched` (single-connector deterministic match), `ambiguous` (multiple matching connectors, left NULL), `no-device-match` (text didn't resolve to a device, left NULL), `already-set` (FK columns already populated, skipped). Auto-fire on quote import deferred to a v2.1 polish — keeps Phase 22 scope tight + avoids touching the existing quote import pipeline which v1.0 declared a non-negotiable constraint.

### Folded Todos

No pending todos matched Phase 22 scope.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 22 spec
- `.planning/REQUIREMENTS.md` §"Phase 22 — Cable Schedule with Port-Level FKs" — DRAW-37..41 acceptance criteria (the binding contract)
- `.planning/ROADMAP.md` §"Phase 22: Cable Schedule with Port-Level FKs" — Goal, Depends on, Requirements, Success Criteria (5 criteria)

### Phase 21 carry-forward
- `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` — D-09 generic naming, D-10 don't-break-v1.3 constraint
- `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` — `DevicePort` model API surface, `SIDE_*` / `DIRECTION_*` enum constants, FK semantics on the `device_stencils` ↔ `device_ports` relationship
- `.planning/phases/21-device-port-catalog-stencil-cache/21-02-seed-pack-promote-and-curate-SUMMARY.md` — 96 seeded stencils + 40 ports as of 2026-05-10 (the data the picker will pull from); `signal_type` field semantics on ports

### Code to read before planning
- `app/Models/CableScheduleItem.php` — current model shape (no FK columns yet, plain `from_location` / `to_location` strings)
- `app/Models/DevicePort.php` — Phase 21 port model, signal_type field, side/direction constants
- `app/Http/Controllers/CableScheduleController.php` — current edit/update handler
- `app/Services/CableScheduleGeneratorService.php` — writes existing rows on quote import (must not change behaviour)
- `resources/views/cable-schedule/edit.blade.php` — current edit UI (text inputs, vanilla JS table)
- `database/migrations/2026_03_09_000002_create_cable_schedules_table.php` — current schema for the migration to extend

### v1.3 surface that must not regress (D-10 carry-forward)
- `app/Services/Drawings/SchematicGeneratorService.php`
- `app/Services/Drawings/SchematicD2SourceBuilder.php`
- `app/Services/Drawings/DrawingDataResolverService.php` — cable adjacency builder

### Visual contract (informational — Phase 23 will render from this data)
- XTEN-AV PAGING SYSTEM reference image (conversation 2026-05-09) — port-to-port routing pattern

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `x-document-edit-drawer` Blade component (`resources/views/components/document-edit-drawer.blade.php` if it follows convention) — existing edit-drawer pattern. The port-picker modal can borrow its open/close + backdrop + Apply/Cancel button layout for consistency with the rest of the app.
- `DevicePort` model with `signal_type` + `connector_type` + side/direction constants — the cascading picker reads from this directly via `Project::devicesWithStencils()` (Phase 21's accessor) to know what ports exist for each device on the project.
- `Project::devicesWithStencils()` (Phase 21 D-07) — already returns the project's device list + their stencils + ports. Picker can call this on modal open to populate the source/dest device dropdowns. Cross-project caching means the picker doesn't trigger new stencil writes — those happened when the project was first rendered.

### Established Patterns
- **Vanilla JS + Blade, no Alpine in `cable-schedule/edit.blade.php` yet.** The codebase HAS Alpine (per CLAUDE.md stack) but this specific form is plain HTML inputs + small inline scripts for row add/remove. Phase 22 can either:
  - Stay vanilla JS (consistent with the file's existing style)
  - Introduce Alpine.js bindings (consistent with the project's overall stack, easier reactive port filtering)
  Planner decides — both are viable. Recommend Alpine.js for the picker modal specifically (heavy reactive cascading is easier with Alpine's `x-data` + `x-show` + `x-for`) while leaving the row-level table unchanged.
- **CSRF + form-encoded POST submit** for the existing edit/update endpoint — picker should integrate by writing FK values into hidden `<input name="items[N][source_device_id]">` fields when Apply is hit. Submit through the existing form, no separate AJAX endpoint needed.
- **No existing connector-compatibility config** — `config/cables.php` doesn't exist yet, would be a new config file.

### Integration Points
- **`CableScheduleController@update`** — needs to accept and persist the 4 new FK columns + the `connector_override_note` column. Existing per-row validation needs `source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` rules (nullable, exists-in-table) + the override note (nullable string, max 500).
- **`CableScheduleItem` model** — add `belongsTo` relationships for `sourceDevice`, `sourcePort`, `destDevice`, `destPort`. Add the override note to `$fillable`.
- **`CableScheduleGeneratorService`** — writes new rows on quote import; should NOT auto-populate port FKs from the parsed quote (per the deferral note above). Existing behaviour stays untouched. The separate backfill command handles legacy + just-imported rows alike.
- **Phase 23 renderer (downstream consumer)** — reads `cable_schedule_items.source_port_id` + `dest_port_id` to draw port-to-port cable routing. Must handle NULL gracefully — render those rows as "best-effort from text-only data" rather than crashing.

</code_context>

<specifics>
## Specific Ideas

- **Canonical port label format for picker auto-fill:** `"{Manufacturer} {Model} ({Port label})"` — e.g. `"Crestron HD-MD-400 (HDMI 1)"`, `"Samsung QM65 (HDMI 1)"`. Generated from `DeviceStencil.manufacturer` + `DeviceStencil.model` (or `DeviceStencil.display_name` fallback) + `DevicePort.label`.
- **🔗 icon teal colour** should match the existing teal action colour used by the `btn-teal` class on the Save Changes button in `cable-schedule/edit.blade.php` line 47 — keeps the visual language consistent.
- **Override note column name:** `connector_override_note` (nullable text, max 500 chars). Sits adjacent to the FK columns on the `cable_schedule_items` migration.

</specifics>

<deferred>
## Deferred Ideas

- **Auto-fire backfill on quote import** — currently the backfill is a manual artisan command. A future v2.1 polish could hook it into `CableScheduleGeneratorService` so newly-imported quotes try-and-set FKs automatically (with the same dry-run-by-default pattern flipped to auto-apply). Deferred because: (1) keeps Phase 22 scope tight, (2) avoids modifying the existing quote import pipeline which is a v1.0 non-negotiable constraint, (3) the manual command covers the same use case with engineer-in-the-loop safety.
- **Bulk port re-assignment** ("change all HDMI cables from src device X to device Y") — UX pattern would help when a project's device gets swapped post-install. Out of Phase 22 scope; better fit for Phase 24's stencil curation UI which already deals with bulk operations on the catalog.
- **AI assist for ambiguous port mapping** — when the deterministic backfill leaves a row as `ambiguous`, Claude vision over the device datasheet PDFs could propose a port pair for engineer review. Out of scope — Phase 25's explicit deliverable per ROADMAP.md.
- **Wholesale text-input removal in a future phase** — once port FK adoption is high (Phase 24 curation + Phase 25 AI assist bringing the catalog hit rate up), a future polish could deprecate the freeform `from_location` / `to_location` columns. NOT a v2.0 conversation — flag for v2.1+ post-shakedown.

### Reviewed Todos (not folded)
No todos surfaced for review.

</deferred>

---

*Phase: 22-cable-schedule-with-port-level-fks*
*Context gathered: 2026-05-10*
