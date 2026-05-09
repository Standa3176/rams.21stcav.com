---
name: 260509-ibx draw.io embed spike context
description: Locked decisions for the 2-week draw.io / mxGraph build-vs-buy spike preceding the v2.0 engineering-grade drawings milestone
type: quick-task-context
date: 2026-05-09
---

# Quick Task 260509-ibx: draw.io Embed Spike — Context

**Gathered:** 2026-05-09
**Status:** Ready for planning (user selected "All clear" — defaults locked)

<domain>
## Task Boundary

This is a **2-week spike**, not the v2.0 milestone. Goal: prove (or
disprove) that draw.io / mxGraph embedded inside the existing Laravel
app can produce engineering-grade AV schematics matching the user's
Lucidchart Extron Concept reference, before committing 10–15 weeks
to the full v2.0 native build.

**End-of-spike decision gate:** at end of week 2 the user evaluates
the rendered output side-by-side with the Lucidchart reference. If
"same league" → green-light v2.0 milestone with confidence. If not →
fall back to evaluating Lucidchart API or XTEN-AV.

**Out of scope for this spike** — defer to the full v2.0 milestone:
- AI port extraction from datasheets
- More than one room archetype
- Cable schedule port-level FK migration
- Production deployment / engineer access (admin/dev surface only)
- AI chat-edit operations
- Multi-page schematic paginator
- Round-trip patch-merge logic (lock-on-edit suffices for spike)

</domain>

<decisions>
## Implementation Decisions (LOCKED — user selected "All clear")

### D-LOCK-1 — Self-host draw.io (not CDN)
- Vendor draw.io's `embed.html` + asset bundle into `public/vendor/drawio/`
- Same-origin = no CORS / postMessage friction
- Pin version (Apache 2.0 licensed, downloaded once, committed to repo OR `composer.json`-style pinned vendor manifest)
- Update model: manual review + replace, never auto-update

**Why:** prevents surprise breakages when drawio.com pushes a new version
that breaks our stencil schema; gives us full control of the editor surface

### D-LOCK-2 — Round-trip policy: lock-on-edit
- Auto-generation produces v0 of the drawing's mxGraph XML
- First engineer edit in the embed flips an `is_locked` flag on `project_drawings`
- Subsequent regen produces a NEW versioned drawing rather than overwriting
- Archive-prior pattern from v1.3 (`superseded_by_id` link)

**Why:** matches v1.3 RAMS / O&M / worksheet patterns; engineer-trust matters
more than convenience for a hand-tuned drawing; patch-merge can be a v2.1
follow-up if anyone actually asks for it

### D-LOCK-3 — Room archetype: small Teams Room (single)
- Simplest of the 6 archetypes user mentioned
- Most common in 21CAV's last 12 months of quotes
- Easiest visual comparison against Lucidchart Extron Concept reference
- All other archetypes (boardroom / divisible / classroom / townhall / huddle)
  deferred to v2.0

### D-LOCK-4 — Stencil scope: 5 stencils minimum viable
- Neat Bar Pro (or Crestron Flex small-room kit) — videobar
- Samsung 65" / 75" display (interactive or commercial)
- ClickShare Bar Pro
- Sennheiser TeamConnect ceiling mic OR Crestron table mic
- Netgear 12-port PoE+ Gigabit managed switch

Each stencil includes:
- Manufacturer logo (top of card)
- Generic name + model number
- Port rails (inputs left, outputs right) with hand-coded port metadata
- Connector glyphs (HDMI, RJ45, USB-C, etc.)
- Brand-consistent styling (teal accents, off-white card body)

### D-LOCK-5 — Stub port data, no AI extraction
- Port metadata hand-coded as static JSON for the 5 spike stencils
- AI port extraction from datasheets is a v2.0 Phase 21 concern
- For the spike, ports are "real enough" for visual fidelity testing

### D-LOCK-6 — Real project hookup IN scope
- Wire the builder service to one real project's package data
- NOT hardcoded JSON — must read `ProjectPackage::extracted_data['equipment']`
  filtered to a Teams-Room area
- Validates the data path AND the visual output simultaneously

### D-LOCK-7 — Admin / dev surface only
- New route `admin/drawings/draw-io-spike/{project}` or similar
- Auth-gated to admin (or auth+spike-flag)
- NOT linked from project page; engineers don't see it during spike
- v2.0 milestone makes it engineer-facing

### D-LOCK-8 — Storage: mxGraph XML + SVG export
- DB column: `project_drawings.mxgraph_xml` (text, nullable)
- Existing `canvas_state` column (added in v1.3 Phase 17 foundations) repurposed if compatible
- SVG export written alongside as preview (`storage/app/documents/drawings/spike-{id}.svg`)
- Source of truth: XML. SVG is for thumbnail/embed-in-PDF use only

</decisions>

<specifics>
## Spike Success Criteria

User evaluates these at end-of-week-2 to decide green-light vs fall-back:

1. **Visual fidelity** — side-by-side with Lucidchart Extron Concept reference,
   does the rendered Teams Room schematic look "same league"?
2. **Round-trip integrity** — load XML → edit a device's position in the
   embed → save → reload page → exact same XML / SVG output
3. **Brand alignment** — Crestron + Sennheiser + Cisco + Samsung logos
   render correctly in their respective stencils
4. **Performance** — round-trip (load → edit → save → re-render) under 3
   seconds on dev machine
5. **Cost reality check** — actual time spent on the spike vs the planned
   2 weeks. If it took 4+ weeks for one room with 5 stencils, the full
   v2.0 milestone estimate is wrong and should be re-scoped before commit

## Pipeline being prototyped

```
ProjectPackage.extracted_data
    ↓
DrawIoSpikeBuilderService (NEW)
    ↓ (deterministic — no AI)
mxGraph XML
    ↓ (postMessage to embed)
Self-hosted draw.io editor
    ↓ (engineer drag/edit)
mxGraph XML (round-trip)
    ↓
SVG export (preview)
    ↓
project_drawings table
```

## What "good" looks like at end of spike

- Engineer or PM can open `admin/drawings/draw-io-spike/{project}` for
  the prototype project
- Sees a Teams Room schematic auto-generated from the project's quote data
- The 5 stencils each show their port rails + manufacturer styling
- Engineer can drag a device, redraw a cable, save the change
- Reloading the page shows the saved state
- The visual output is benchmarkable against the Lucidchart reference
  — "yes that's engineering-grade" or "no, falls short here / here / here"

</specifics>

<canonical_refs>
## Canonical References

- **draw.io embed docs:** https://www.drawio.com/doc/faq/embed-mode
- **mxGraph reference:** https://jgraph.github.io/mxgraph/docs/manual.html
- **Lucidchart Extron Concept reference** — visual benchmark from session conversation; user has the PDF
- **memory file** `v2_engineering_grade_drawings_plan.md` — original 5-phase native plan; this spike replaces Phases 23 + 24 with draw.io if successful
- **memory file** `schematic_fidelity_v2_deferred.md` — what v1.3 explicitly omitted that v2.0 must deliver
- **v1.3 lock-on-edit pattern** — `app/Services/DrawingService.php`, the archive-prior + supersede flow that D-LOCK-2 mirrors
- **v1.3 canvas_state column** — `project_drawings.canvas_state` (added Phase 17 P01) — candidate for reuse if shape compatible

## Licensing

- draw.io: Apache 2.0 — free commercial use ✓
- mxGraph: Apache 2.0 ✓
- **Caveat:** JGraph commercial library (mxGraph's parent) has a clause restricting use to "competing diagram editor products" — does NOT apply to internal AV-tool use, but flag if 21CAV ever spins out a tools-as-product business

</canonical_refs>
