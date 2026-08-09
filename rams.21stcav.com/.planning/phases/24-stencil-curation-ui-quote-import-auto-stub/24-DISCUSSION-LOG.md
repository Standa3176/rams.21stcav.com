# Phase 24: Stencil Curation UI + Quote-Import Auto-Stub - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-09
**Phase:** 24-stencil-curation-ui-quote-import-auto-stub
**Areas discussed:** Port editor interaction model, Category templates → stencil XML
**Areas delegated to Claude:** Auto-stub hook points, `needs_review` storage + 92-stub backfill

---

## Gray areas offered

| Area | Selected |
|------|----------|
| Port editor interaction model | ✓ |
| Auto-stub hook points | (delegated) |
| Category templates → stencil XML | ✓ |
| `needs_review` storage + 92-stub backfill | (delegated) |

---

## Port editor interaction model

### Q1 — How should an engineer edit a stencil's ports?

| Option | Description | Selected |
|--------|-------------|----------|
| Hybrid: table + live preview | Editable port table is source of truth; read-only SVG preview re-renders on change. Reuses device-cable-rules form pattern, no drag maths. | ✓ |
| Full drag-on-canvas | Draggable handles snap to rails and write y_pct/x_pct. Matches roadmap wording but is a net-new UI pattern with no precedent in the admin surface. | |
| Table only, no preview | Plain CRUD like admin/device-cable-rules. Cheapest, but engineer can't tell if a stencil looks right without opening a project drawing. | |

**User's choice:** Hybrid: table + live preview
**Notes:** Context offered — `device_ports` stores position as two nullable decimals and Phase 23 computes positions when null, so dragging is a convenience over two numbers rather than the only way to express intent. → CONTEXT D-01.

### Q2 — How should the live preview be rendered?

| Option | Description | Selected |
|--------|-------------|----------|
| Server endpoint, real builder | Debounced POST runs the same service that produces production stencils against the unsaved port set, returns SVG. One renderer, one truth. | ✓ |
| Client-side JS redraw | Alpine redraws instantly from table state, but creates a second rendering implementation that must track the PHP builder forever. | |
| Preview refreshes on save only | Reuses the real builder but turns every positioning tweak into a save round-trip. | |

**User's choice:** Server endpoint, real builder
**Notes:** Decisive argument was drift — a preview that diverges from the real renderer silently teaches engineers the wrong thing about their own curation. → CONTEXT D-02.

### Q3 — Where should the curation audit trail live?

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated `device_stencil_audits` table | Generic-named (SCC-safe per 21 D-09): stencil_id, user_id, action, before/after snapshot, timestamp. | ✓ |
| `metadata` json on device_stencils | Zero migrations, uses the column Phase 21 reserved — but holds only the last edit, no queryable history. | |
| Both — table for history, metadata for display | Belt and braces, but introduces a denormalisation that can go stale. | |

**User's choice:** Dedicated `device_stencil_audits` table
**Notes:** `ProjectActivityLog` was checked and ruled out before the question was asked — it is project-scoped, and `device_stencils` are deliberately global (no `project_id`), which is what makes cross-project propagation work. → CONTEXT D-03.

### Q4 — What should "Promote to engineer-curated" validate?

| Option | Description | Selected |
|--------|-------------|----------|
| Hard-gate structure, soft-warn quality | Block on zero ports / missing required fields / duplicate port_id; warn on missing logo, unclassified signal_type, absent position hints. | ✓ |
| Soft-warn everything (Phase 22 parity) | Mirror the cable-schedule "never a hard block" precedent exactly. | |
| Hard-gate everything | Every field complete before promotion allowed. | |

**User's choice:** Hard-gate structure, soft-warn quality
**Notes:** Accepted a deliberate divergence from Phase 22's precedent. Rationale recorded: promotion removes the stencil from the review queue and propagates it everywhere, so a zero-port promotion hides the coverage gap it was created to expose. → CONTEXT D-04.

---

## Category templates → stencil XML

### Q1 — When the auto-stub seeds template ports, what happens to mxgraph_xml?

| Option | Description | Selected |
|--------|-------------|----------|
| Emit provisional rails + constraints | Rails and mxGraph constraints drawn in a distinct (dashed/muted) style. Ports become real Phase 23 routing targets; "needs promoting" signal survives as styling. | ✓ |
| Ports data-only, XML stays blank | Strictly honours 21 D-04 and criterion 6, but Phase 23 can't route cables to the ports and the drawing contradicts the data. | |
| Emit normal rails, no visual distinction | Best-looking drawings, but erases the signal distinguishing guessed ports from confirmed ones. | |

**User's choice:** Emit provisional rails + constraints
**Notes:** Supersedes Phase 21 D-04 for the stub-with-ports case. Decisive fact surfaced during scouting: per 21 D-02, `port_id` is the mxGraph constraint name used for cable termination in Phase 23, so ports absent from the XML are invisible to the port-to-port router. Tension with ROADMAP criterion 6 was flagged and resolved in CONTEXT D-05's planner note. → CONTEXT D-05.

### Q2 — Where should the category → port-template vocabulary live?

| Option | Description | Selected |
|--------|-------------|----------|
| New key in `config/drawings.php` | `port_templates` alongside existing `category_to_zone` / `signal_colours`. Version-controlled, so determinism is enforced by git. | ✓ |
| New dedicated config file | Same benefits, keeps drawings.php smaller. | |
| DB table, admin-editable | Tunable without deploy, but breaks criterion 2's determinism guarantee. | |

**User's choice:** New key in `config/drawings.php`
**Notes:** Scouting first confirmed `config/drawings.php` already holds `signal_colours`, `zone_vocab`, `category_to_zone`, and four other maps. Also established during this question that the device-type vocabulary must be NEW — `EquipmentCategoryClassifier`'s categories are a commercial axis and `Device::ROLE_*` is only source/destination/processor. → CONTEXT D-06.

### Q3 — How should a description matching multiple category keywords resolve?

| Option | Description | Selected |
|--------|-------------|----------|
| Explicit precedence list, else zero-port | Known compounds enumerated with a declared winner; any unenumerated multi-match → zero-port stub flagged for review. | ✓ |
| Any multi-match → zero-port stub | Strictest reading of criterion 2; no precedence rules at all. | |
| Priority-ordered tree, first match wins | Mirrors EquipmentCategoryClassifier exactly; never ambiguous, but always produces an answer including a wrong one. | |

**User's choice:** Explicit precedence list, else zero-port
**Notes:** "Samsung 65\" Display Bracket" used as the worked example — matches `display` and `bracket`, whose templates are opposites (2 HDMI vs zero ports). Recorded in CONTEXT as a named test case. → CONTEXT D-07.

### Q4 — What happens to stubs created from an older template version?

| Option | Description | Selected |
|--------|-------------|----------|
| Opt-in artisan re-apply command | `stencils:reapply-templates`, dry-run by default, touching only auto-generated stencils with no audit rows. | ✓ |
| Auto re-apply on next import | Self-healing, but mutates data as a side effect of an unrelated import. | |
| Never re-apply | Simplest, but strands the earliest-imported (highest-volume) devices on the worst templates. | |

**User's choice:** Opt-in artisan re-apply command
**Notes:** Pattern borrowed from `PackagesReclassifyEquipmentCommand` (260725-qw3) and `BackfillCablePortFksCommand` (Phase 22). This choice also resolved the delegated backfill question — see CONTEXT D-11. → CONTEXT D-08.

---

## Claude's Discretion

Delegated by the user at gray-area selection; decided and recorded as binding in CONTEXT.md.

- **Auto-stub hook points** → CONTEXT D-09. One `QuoteImportStencilStubber` called from all three import paths. Corrects a ROADMAP defect: 24-01 named only `ExtractQuoteJob`, which serves the PDF-upload path only, while QuoteWerks (a separate service) has been the default import tab since 260725-qw4.
- **`needs_review` storage** → CONTEXT D-10. Real indexed boolean column, not a `metadata` json flag — MariaDB cannot index a json extract, and criterion 3 requires a filtered list view.
- **92-stub backfill** → CONTEXT D-11. No separate mechanism; the stubs qualify under D-08's re-apply rule already.
- **Logo upload security** → CONTEXT D-12. Mandatory routing through the existing `SvgSanitizerService`.

## Scope corrections raised during scouting

- **DRAW-54 mis-mapped** → CONTEXT D-13. ROADMAP claims DRAW-50..54 for Phase 24; REQUIREMENTS.md:71 files DRAW-54 under Phase 25, and Phase 24's own goal text agrees. Phase 24 = DRAW-50..53. User raised no objection.
- **Route name conflict** → CONTEXT D-14. DRAW-50's `/admin/device-stencils` chosen over the goal text's `/admin/stencils`, matching `admin/devices` and `admin/device-cable-rules`. User raised no objection.

## Deferred Ideas

- AI port extraction from datasheets — Phase 25 (DRAW-54)
- Full drag-on-canvas positioning — rejected for this phase, additive later if numeric entry proves painful
- Bulk / keyboard affordances for the review queue — offered, not discussed
- `stencils:coverage-report` fixture provenance (Phase 21 D-15 circularity rule by analogy) — offered, not discussed; planner to carry forward
- Whether plan 24-03's engineer fill belongs in this phase — offered, not discussed; left in scope, bounded to top-10 by criterion 5
