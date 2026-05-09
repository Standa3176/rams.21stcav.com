---
quick_task: 260509-ibx
title: draw.io embed spike — sandbox, one stencil pack, lock-on-edit, real project hookup
date: 2026-05-09
status: implementation_complete_pending_browser_uat
tags: [drawings, drawio, mxgraph, spike, v2.0, build-vs-buy]
duration_minutes: ~70
tasks_completed: 5
commits: 5
files_changed: 2911   # 2899 vendored bundle + 12 spike-source files
---

# Quick Task 260509-ibx: draw.io Embed Spike — SUMMARY

draw.io v29.7.12 self-hosted under `public/vendor/drawio/`, 5-stencil pack
for the small Teams Room archetype, deterministic ProjectPackage→mxGraph
XML builder, admin-only embed Blade with full postMessage round-trip,
lock-on-edit + archive-prior storage reusing the existing
`canvas_state` column (no migration). v1.3 D2 schematic generator
byte-identical (zero diff). Implementation complete; awaiting browser
UAT + side-by-side fidelity comparison against Lucidchart Extron Concept
reference at end of week 2.

## What changed

- **Task 1** — Vendored draw.io v29.7.12 (Apache 2.0) into
  `public/vendor/drawio/` (~132 MB on disk; 2899 files). VERSION.md
  pins tag + license + manual-update procedure. `embed.html` is a copy
  of `index.html` (recent draw.io ships single entry that handles
  `?embed=1&proto=json`). `js/mermaid/` dropped (~3 MB, separate diagram
  tool, not used). Commit `d43847a`.
- **Task 2** — Hand-coded 5-stencil pack at
  `resources/data/draw-io-stencils/21cav-mtr-spike.json` (Neat Bar Pro
  / Samsung QM65C-T / ClickShare Bar Pro / Sennheiser TCC2 / Netgear
  GS312TP). Each stencil ships brand chrome (#1B7A7A teal heading +
  #FAFAF6 cream body + #C07000 orange port-rail accents) + hand-coded
  port metadata + native draw.io `<shape>` XML with port `<constraint>`
  elements so cables can connect to specific ports. 5 manufacturer SVG
  wordmarks hand-drawn (no copy of upstream brand files). Commit
  `12e32d3`.
- **Task 3** — `DrawIoSpikeBuilderService` — pure data → mxGraph XML
  emitter. STENCIL_ALIASES first-match-wins fragment table mirrors v1.3
  `SchematicD2SourceBuilder::SYMBOL_ALIASES` shape but maps to mxGraph
  stencil IDs. 3-column deterministic grid (sources → switch → display).
  Embeds each stencil shape inline as `shape=stencil(<base64>)` style
  fragment. Deterministic + zero AI surface (D-LOCK-5/6). Commit
  `7959994`.
- **Task 4** — `DrawIoSpikeController` (admin) + 3 routes
  (show/saveXml/exportSvg) inside the existing
  `Route::middleware('admin')` group + Blade view embedding
  `/vendor/drawio/embed.html` in an iframe with full postMessage
  protocol bridge (init→load, save, autosave, export xmlsvg). Alpine.js
  `drawIoSpike()` component. T-260509-ibx-04 mitigation:
  `e.source === iframe.contentWindow` filter on every postMessage. T-03
  mitigation: 5 MB cap on xml/svg payloads. Zero user-facing entry
  point. Commit `ca11512`.
- **Task 5** — `DrawingService::saveSpikeXml` (lock-on-edit +
  archive-prior, mirrors v1.3 Phase 18 P03) + `saveSpikeSvg` (writes
  via `DocumentArtifactStorage::TYPE_DRAWING`). Zero deletions in
  `DrawingService.php` — purely additive. NO migration (D-LOCK-8
  reuses the existing `canvas_state` mediumText column). Smoke tests
  pass: first save lock-flips same row, second save versions + prior
  superseded. Commit `2c44952`.

## D-LOCK audit

| Lock | Promise | How verified | Result |
|------|---------|--------------|--------|
| D-LOCK-1 | Self-host draw.io, no CDN | `grep -r 'embed.diagrams.net' public/vendor/drawio/embed.html public/vendor/drawio/index.html resources/views/admin/drawings/` returns empty | ✅ Pass |
| D-LOCK-2 | Lock-on-edit + archive-prior | `saveSpikeXml` reuses `archivePrior()` inside `DB::transaction`; smoke test (first save same row + lock flip; second save new row v=2 + prior STATUS_SUPERSEDED) | ✅ Pass |
| D-LOCK-3 | Small Teams Room archetype only | Builder filter targets areas matching `teams \| meeting room \| mtr \| collaboration`; no other archetype scaffolding present | ✅ Pass |
| D-LOCK-4 | Exactly 5 stencils | JSON validate: stencil count = 5; IDs match the locked list (Neat Bar Pro / Samsung / ClickShare / Sennheiser TCC / Netgear PoE 12-pt) | ✅ Pass |
| D-LOCK-5 | Zero AI in spike | `grep -E "AIManager\|AICache\|AIUsage" app/Services/Drawings/DrawIoSpikeBuilderService.php` returns empty | ✅ Pass |
| D-LOCK-6 | Real ProjectPackage hookup | `build()` reads `$project->latestPackage->extracted_data['equipment']` (with `equipment_list` legacy fallback). Tinker test against project ID 3 produces 3678-byte XML containing devices from real package data | ✅ Pass |
| D-LOCK-7 | Admin-only surface | `route:list -v` shows web/auth/admin middleware chain; `grep -r 'draw-io-spike' resources/views/{projects,components,layouts}/` returns empty | ✅ Pass |
| D-LOCK-8 | Reuse canvas_state, no migration | `git log HEAD~5..HEAD --oneline -- database/migrations/ \| wc -l` = 0; saveSpikeXml writes to `canvas_state`; `hasUserEdits()` is the lock predicate | ✅ Pass |

## Spike success criteria evaluation

These are the 5 build-vs-buy decision inputs the user evaluates at end
of week 2. Implementation only fills criteria 4–5 deterministically;
1–3 require human side-by-side visual review with the Lucidchart Extron
Concept reference PDF, which is the whole point of the 2-week window.

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | Visual fidelity vs Lucidchart Extron Concept reference | 🟡 **Pending — UAT** | Stencils ship brand chrome (teal/cream/orange) + port rails + connector glyphs; user evaluates side-by-side at end of week 2. |
| 2 | Round-trip integrity (load → edit → save → reload preserves state) | 🟢 Implementation correct | Tinker test: first save returns same row + lock flips; second save returns new versioned row + prior STATUS_SUPERSEDED; canvas_state preserved byte-for-byte. Browser UAT pending — user drags a device, saves, reloads, confirms. |
| 3 | Brand alignment (manufacturer logos render in stencils) | 🟡 **Pending — UAT** | 5 hand-drawn SVG wordmarks at `public/img/manufacturers/{name}.svg` referenced from stencil JSON `logo_url`. Stencil chrome embeds brand colours; logo visibility depends on draw.io's `<image>` rendering with the embedded URL — verify in iframe. |
| 4 | Round-trip performance < 3 seconds on dev | 🟡 **Pending — measurement** | Builder is sub-second (deterministic, in-memory); persistXml endpoint is one Eloquent update + one supersede flip on subsequent saves. Browser-side load → POST → reload measured during UAT. |
| 5 | Cost reality check (actual time vs planned 2 weeks) | 🟢 ~70 minutes for the implementation skeleton | Implementation cost remarkably low because (a) D-LOCK-8 reused canvas_state (no migration friction), (b) v1.3 archivePrior helper was reusable, (c) no npm install / composer require during the spike. Visual-fidelity tuning + benchmarking budget intentionally preserved for the remaining ~2-week window. |

## Build-vs-buy recommendation

**🟡 Implementation skeleton landed; verdict pending visual UAT.**

The 5 deterministic engineering D-LOCKs (data path, storage, lock-on-edit,
admin gating, no migration) all came in cleanly. The remaining open
question is the one the spike was designed to answer: do the stencils
look "engineering-grade" against the Lucidchart Extron Concept reference?

Supporting bullets:
- The cheap path through the spike (~70 minutes) leaves ~2 weeks of
  visual-fidelity tuning + benchmarking budget intact. If the v2.0 native
  build estimate (10–15 weeks) is anywhere near accurate, the spike
  remains a high-leverage de-risking move.
- Round-trip integrity is mechanically correct via the v1.3 archive-prior
  pattern reuse. This means the v2.0 lock-on-edit story is already
  validated regardless of the visual-fidelity outcome.
- The stencils as currently authored (native `<shape>` XML with port
  constraints + brand chrome + hand-drawn manufacturer wordmarks) are
  a good middle path — better than generic draw.io "rectangle" shapes,
  but absent of the device-photo realism that XTEN-AV / Lucidchart
  Extron Concept use. End-of-week-2 evaluation should explicitly check
  the gap between "engineering-grade schematic" (port rails + connector
  callouts) and "presentation-grade visual" (device renders) and decide
  which level matches 21CAV's actual deliverable need.

## Carry-forwards to v2.0 (if spike succeeds)

- **T-260509-ibx-05** — engineer-saved SVG sanitisation. Spike scope:
  SVG is preview-only, not yet rendered in any client surface. v2.0
  must add SVG sanitisation (DOMPurify or server-side svg-sanitize)
  before rendering engineer-edited SVGs anywhere user-facing.
- **Manufacturer logo licensing** — 5 hand-drawn wordmark SVGs are
  internal-use-only (D-LOCK-7 admin gating means no external
  distribution). v2.0 must source either officially-licensed
  manufacturer logos OR keep them internal-only forever.
- **Port-routing fidelity gaps** — port metadata is hand-coded "real
  enough for visual fidelity testing" (D-LOCK-5). v2.0 Phase 21 still
  needs AI port extraction from datasheets to be production-grade.
- **Area tagging** — current Teams Room area filter matches
  `teams|meeting room|mtr|collaboration` substrings. Real 21CAV
  projects use varied area names ("VC Room (22)", "Breakout Area",
  "Cinnamon"). v2.0 needs either (a) a survey-driven canonical
  "room archetype" tag or (b) a smarter area→archetype classifier.
  Spike's last-resort fallback (first 8 hardware items, no cable/service)
  is acceptable for the window but not for production.
- **Stencil expansion** — 5 stencils cover small Teams Room only.
  Boardroom/divisible/classroom/townhall/huddle archetypes will need
  ~25 more stencils. v2.0 must decide whether to hand-author each one
  (high quality, slow) or AI-generate from datasheets (fast, lower
  quality bar).
- **Mermaid integration dropped** — `js/mermaid/` (~3 MB) was excluded
  from the vendored bundle. If v2.0 ever needs mermaid-style flow
  diagrams alongside AV schematics, re-vendor that subtree.

## Self-Check: PASSED

Files created:
- `public/vendor/drawio/embed.html` — FOUND
- `public/vendor/drawio/VERSION.md` — FOUND
- `resources/data/draw-io-stencils/21cav-mtr-spike.json` — FOUND
- `public/img/manufacturers/{neat,samsung,clickshare,sennheiser,netgear}.svg` — FOUND (5/5)
- `app/Services/Drawings/DrawIoSpikeBuilderService.php` — FOUND
- `app/Http/Controllers/Admin/DrawIoSpikeController.php` — FOUND
- `resources/views/admin/drawings/draw-io-spike.blade.php` — FOUND

Commits exist (verified via `git log --oneline -5`):
- d43847a — Task 1 vendor bundle
- 12e32d3 — Task 2 stencil pack
- 7959994 — Task 3 builder service
- ca11512 — Task 4 controller + Blade + routes
- 2c44952 — Task 5 saveSpikeXml + saveSpikeSvg

D-LOCK regression scan: 8/8 pass.
v1.3 D2 pipeline byte-identical: 0 diff lines.
PHP syntax sweep: clean across all 4 touched PHP files.

## 🚨 Files to upload to live

**No `php artisan migrate` step required** — D-LOCK-8 reuses the
existing `canvas_state` column. Upload files → admin user visits
spike URL to test.

### Vendored bundle (large directory — rsync the whole subtree)

```
public/vendor/drawio/
```

(2899 files, ~132 MB. Use `rsync -av --delete public/vendor/drawio/
user@live:/path/to/site/public/vendor/drawio/` or equivalent. Pinned
version + license + update procedure documented in
`public/vendor/drawio/VERSION.md`.)

### PHP source

```
app/Services/Drawings/DrawIoSpikeBuilderService.php          (NEW)
app/Services/Drawings/DrawingService.php                      (MODIFIED — adds saveSpikeXml + saveSpikeSvg)
app/Http/Controllers/Admin/DrawIoSpikeController.php          (NEW)
```

### Blade view

```
resources/views/admin/drawings/draw-io-spike.blade.php        (NEW)
```

### Routes

```
routes/web.php                                                (MODIFIED — 3 new routes inside admin middleware group)
```

### Stencil pack

```
resources/data/draw-io-stencils/21cav-mtr-spike.json          (NEW)
```

### Manufacturer logos

```
public/img/manufacturers/neat.svg                             (NEW)
public/img/manufacturers/samsung.svg                          (NEW)
public/img/manufacturers/clickshare.svg                       (NEW)
public/img/manufacturers/sennheiser.svg                       (NEW)
public/img/manufacturers/netgear.svg                          (NEW)
```

### After upload

1. (Optional) `php artisan route:clear && php artisan config:clear`
   on live so the new spike routes show in `route:list`.
2. As an admin user, visit
   `https://{live-host}/admin/drawings/draw-io-spike/{realProjectId}`
   for a project that has a `ProjectPackage` with extracted equipment.
3. Verify in browser DevTools Network tab: no 4xx/5xx; iframe loads
   `/vendor/drawio/embed.html?embed=1&proto=json&libraries=1`; initial
   XML auto-loads via postMessage `init` → `action: load`.
4. Drag a device, click Save (or Ctrl+S inside the embed). Verify
   POST `admin/drawings/draw-io-spike/{id}/save` returns 200; status
   pill flashes "Saved"; reload preserves the new position.
5. Click Export SVG. Verify
   `storage/app/documents/drawings/spike-{drawing_id}.svg` exists on
   the live filesystem.
6. **Side-by-side visual comparison** with Lucidchart Extron Concept
   reference PDF — record verdict in this file or a follow-up note.

### Forbidden-paths diff (D-LOCK regression)

| Check | Result |
|-------|--------|
| `git log HEAD~5..HEAD -- database/migrations/` | 0 commits — no migration |
| `git diff HEAD~5..HEAD -- app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php` | 0 lines — v1.3 D2 pipeline byte-identical |
| `grep -r "embed.diagrams.net" public/ resources/` (in spike-touched files) | empty — no CDN reference |
| `grep -r "draw-io-spike" resources/views/{projects,components,layouts}/` | empty — no user-facing link |
| `grep -E "AIManager\|AICache\|AIUsage" app/Services/Drawings/DrawIoSpikeBuilderService.php` | empty — zero AI surface |
