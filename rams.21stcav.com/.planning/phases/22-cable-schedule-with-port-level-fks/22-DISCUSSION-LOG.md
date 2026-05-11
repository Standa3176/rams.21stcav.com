# Phase 22: Cable Schedule with Port-Level FKs — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `22-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-05-10
**Phase:** 22-cable-schedule-with-port-level-fks
**Areas discussed:** Cascading dropdown UX shape

---

## Gray area selection

User was presented with 4 gray areas and asked which to discuss:

| Area | Selected |
|------|----------|
| Cascading dropdown UX shape | ✓ |
| Compatibility matrix definition | |
| Override workflow when warning fires | |
| Backfill command scope + cadence | |

The 3 unselected areas were folded into Claude's Discretion in `22-CONTEXT.md` with recommended defaults so the planner has direction without re-asking.

---

## Cascading dropdown UX shape

### Question 1: Which dropdown shape fits your engineers' workflow best?

| Option | Description | Selected |
|--------|-------------|----------|
| Modal picker per row | Keep text inputs. Add a small button per row. Tap → modal opens with 4-step picker. Mobile-friendly. Strictly additive — legacy rows untouched until picker is opened. | ✓ (Recommended) |
| Replace text inputs with inline dropdowns | Drop From/To text, replace with 4-cell cascading dropdown row. Cleanest data model but breaks the 8-column table on mobile, forces all-in adoption. | |
| Both — dropdowns alongside text inputs | Keep text inputs AND add cascading dropdowns. Maximum flexibility but doubles column count to 16, creates dual state to reconcile, two ways to do the same thing. | |

**User's choice:** Modal picker per row
**Notes:** Driven by the constraint that the existing edit form is already 8 columns wide and engineers use this on tablets/phones in the field. Inline dropdowns would either not fit or force a layout rebuild.

---

### Question 2: How should the 4-step picker be laid out inside the modal?

| Option | Description | Selected |
|--------|-------------|----------|
| Side-by-side: source left, destination right | Modal split vertically. Left column: source device + source port. Right column: dest device + dest port (filtered by signal-type compatibility). Matches "A → B" mental model. Reads at a glance. Works on tablet landscape; collapses to stacked on phone portrait. | ✓ (Recommended) |
| Vertical 4-step stepper (Next / Back buttons) | One dropdown at a time, full-width. More taps but only one decision visible at a time. Feels more "wizard-y" — better for first-time users, slower for power users. | |
| All 4 dropdowns vertically stacked, scrollable | All 4 dropdowns visible top-to-bottom in one column. Simpler to build; ugly on tablet (lots of empty horizontal space); fine on phone. | |

**User's choice:** Side-by-side: source left, destination right
**Notes:** Reinforces the natural mental model of cable routing (source → destination). Tablet landscape is the primary form factor for cable schedule editing.

---

### Question 3: Where does the "Pick ports" trigger sit on each cable row?

| Option | Description | Selected |
|--------|-------------|----------|
| Compact 🔗 icon between From and To columns | Tiny chain-link icon in a thin separator column between From and To inputs. Tap = open picker. Visual hint: teal/filled when FKs set, faded outline when unset. Adds 1 narrow column (8 → 9). Visually communicates "these two cells are paired" at a glance. | ✓ (Recommended) |
| New action column on the right (next to ✕ delete) | "Ports" column at far right. Shows 'Pick' button when unset, 'src.label → dst.label' when set. Easier to scan port assignment status across all rows; further from From/To context the picker actually populates. | |
| Inline link inside the From input itself | Below the From text input, show a small 'Pick ports →' link. Reuses existing space — no new column — but adds vertical height per row. | |

**User's choice:** Compact 🔗 icon between From and To columns
**Notes:** Status communication built into the icon's visual state (filled teal = set, faded outline = unset) makes scanning a long cable schedule for "which rows still need port assignment" possible at a glance.

---

### Question 4: When the picker confirms a port pair, what happens to the row's existing From/To text?

| Option | Description | Selected |
|--------|-------------|----------|
| Always overwrite with canonical labels | Picker writes "Crestron HD-MD-400 (HDMI 1)" → "Samsung QM65 (HDMI 1)" over whatever was in From/To, every time. Single source of truth: text always reflects the FK selection. Engineers wanting custom text just don't open the picker on that row. | ✓ (Recommended) |
| Only fill when text is empty; preserve existing | If From or To already has text, leave it alone; only the FK columns get populated. Engineers keep custom phrasing but text and FK can drift visibly out of sync. | |
| Prompt with 'Overwrite text?' confirm if non-empty | Show inline confirm before overwriting. Most polite — also most clicks. | |

**User's choice:** Always overwrite with canonical labels
**Notes:** Avoids the text/FK drift problem entirely. The picker is opt-in per row — engineers wanting custom text simply skip it.

---

## Pacing check

After 4 questions on the dropdown UX area:

| Option | Description | Selected |
|--------|-------------|----------|
| Move on — Claude's discretion on the rest | Lock in the 4 UX decisions. Compatibility matrix, override workflow, and backfill scope become Claude's discretion with sensible defaults captured in CONTEXT.md. | ✓ (Recommended) |
| Pull in compatibility matrix | | |
| Pull in override workflow | | |
| Pull in backfill command details | | |

**User's choice:** Move on — Claude's discretion on the rest

---

## Claude's Discretion (deferred areas, defaults captured in CONTEXT.md)

The user deferred these three areas to Claude. Recommended defaults written into `22-CONTEXT.md` for downstream agents:

- **Compatibility matrix:** Config-driven `config/cables.php` with default exact-connector match + explicit interop allowlist (HDMI↔DisplayPort, USB-C↔Thunderbolt, etc.).
- **Override workflow:** Inline warning banner inside the picker modal, required "Override reason" text field before Apply accepts. Persists to new `connector_override_note` column.
- **Backfill command:** Manual `php artisan cables:backfill-port-fks {project?}`, idempotent, dry-run by default, all rows targeted regardless of origin. Auto-fire on quote import deferred to v2.1.

---

## Deferred Ideas

Captured in `22-CONTEXT.md` `<deferred>` section:
- Auto-fire backfill on quote import (v2.1 polish)
- Bulk port re-assignment UI (Phase 24 fit)
- AI assist for ambiguous port mapping (Phase 25 scope per ROADMAP.md)
- Wholesale text-input removal post-adoption (v2.1+ post-shakedown)
