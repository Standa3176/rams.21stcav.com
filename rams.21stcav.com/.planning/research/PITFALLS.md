# Domain Pitfalls — v1.3 Technical Drawings & Schematics

**Domain:** AV technical drawings (schematics, rack elevations, floor plans, drawing export) added to an existing Laravel 12 / Browsershot / Alpine.js stack
**Researched:** 2026-04-30
**Milestone:** v1.3 (Phases 17–20)
**Existing-stack constraints:** Server-rendered Blade + Alpine.js (no SPA), Browsershot (chrome-headless-shell) for PDF, ProjectDataService canonical merge, DocumentArtifactStorage for artifacts, database queue for async generation, internal single-tenant app, OSS-licensed deps must be commercial-internal compatible.

> **How to read this file:** Each pitfall has WARNING SIGN (how to spot it early), PREVENTION (concrete code/process step), and PHASE (which of 17/18/19/20 should address it). Phases follow the milestone roadmap convention used in v1.0–v1.2: 17=schematics, 18=rack elevations, 19=floor plans (in-app drawing tool), 20=export + O&M integration. Adjust per actual phase split during planning.

---

## CRITICAL PITFALLS

These cause rewrites, broken handover documents, or production outages. Treat as non-negotiable risk-register entries in PLAN.md.

### CRIT-01: Browsershot can't render React-canvas libs (TLDraw / Excalidraw) inside the existing PDF pipeline

**What goes wrong:**
TLDraw and Excalidraw are React SPAs that hydrate client-side, request Web Workers, sometimes use WebGL (Excalidraw 0.17+), and rely on `window.requestAnimationFrame` to draw. Browsershot's chrome-headless-shell is invoked via Puppeteer with a short default navigation timeout and no React lifecycle awareness. The PDF that comes out is **either blank, half-drawn, or shows the loader.**

The existing `PdfRenderService::fromBlade()` was migrated in 260427-qvr to render server-side Blade templates with mostly static HTML/CSS — adding a hydrating React canvas to that pipeline breaks the assumption that the page is "done" the moment Blade renders.

**Why it happens:**
- chrome-headless-shell is the **slimmer** Chromium variant (no full browser shell). It has been observed in this codebase as a moving target — symlink hopping between puppeteer-cache versions (260427-qvr docs the `/home/stcav/chrome` symlink dance).
- `waitUntilNetworkIdle()` only waits for HTTP idle; React hydration + canvas first-paint emit no network traffic.
- Web Workers (TLDraw uses one for shape commands) require headers and capability flags; chrome-headless-shell may silently disable them.
- `@page { @bottom-right { content: counter(page) } }` (the Chromium-native page numbering decision in 260427-qvr) does not interact well with absolutely-positioned canvas overlays — Chromium may paginate the canvas into multiple pages, slicing it.

**Consequences:**
Drawings missing from generated PDFs. O&M Manuals with "image not loaded" placeholders. RAMS PDFs delivered to client without the schematic the engineer drew. **This is a handover-blocker — clients reject incomplete docs.**

**Warning signs:**
- Browsershot logs `Navigation timeout of 30000 ms exceeded`
- PDF byte size < 5 KB (blank page baseline) for a drawing-bearing page
- "Loading..." text appearing in generated PDFs
- Canvas elements present in HTML preview but missing in rendered PDF

**Prevention:**
- **Never render the live canvas inside Browsershot.** Take the canvas state, export it to **flat SVG** in the browser when the user saves, store the SVG in `documents` disk, and embed the SVG into Blade for PDF rendering. Browsershot renders SVG reliably; it is the React lifecycle and WebGL that break.
- For TLDraw specifically: use `Editor.getSvg()` (or v2 equivalent `getSvgElement()`) at save time, not at render time. Same pattern for Excalidraw via `@excalidraw/utils#exportToSvg`.
- For auto-generated schematics (Phase 17): **render server-side as SVG directly from PHP** — no canvas lib needed at PDF-render time. Reserve the canvas lib for the editor only.
- In `PdfRenderService`, add `setOption('waitForFunction', 'window.__drawingReady === true')` only when the page sets that flag — most templates won't need it because we're rendering pre-baked SVG.
- Add a smoke-test view (`resources/views/pdf/_drawing-smoke.blade.php`) and a `php artisan pdf:smoke-test --drawings` flag, mirroring the 260427-qvr smoke pattern.

**Phase:** 17 (set the SVG-not-canvas decision before any code is written), revisited in 20 (export pipeline).

---

### CRIT-02: Drawing data drift vs canonical project data

**What goes wrong:**
User auto-generates a rack elevation. User opens the editor, manually nudges 3 boxes, adds a custom label. User leaves. Two days later, equipment-manager updates the QuoteWerks SQL — one new amplifier added, one item removed. PM clicks "regenerate rack" → either:
- **Their manual edits are wiped** (silent data loss), or
- **The regen doesn't run** because we locked it (silent staleness — drawing now misrepresents quote), or
- **We attempt a merge** (almost always wrong because canvas geometry doesn't 3-way-merge cleanly).

**Why it happens:**
This is the same anchor problem as `RamsDocument.reviewed_data` vs `extracted_data` — but worse, because canvas position is **structural**, not just textual. There is no equivalent of "show diff and let the user accept."

**Consequences:**
Engineer loses 30 mins of layout work mid-project → product becomes "the thing that eats my work." Or quote-correctness drifts → client gets a rack drawing showing equipment that isn't in the BOM. Either failure mode is severe.

**Warning signs:**
- Support tickets: "I just edited my drawing and it reset"
- Drawings showing equipment that doesn't appear in the latest cable schedule
- Discrepancy between drawing's equipment count and QuoteWerks SQL count

**Prevention:**
Adopt the **lock + prompt** pattern (same shape as the existing `reviewed_data` vs `extracted_data` flow):
1. Auto-generation produces `generated_data` (read-only baseline).
2. First user edit forks into `edited_data` and the model gains `is_user_edited = true`.
3. When equipment changes upstream, set `regeneration_pending = true` instead of regenerating.
4. UI surfaces a banner: "Equipment changed — regenerate (loses your edits) / keep edits (drawing is stale) / merge (manual)."
5. NEVER auto-regenerate a user-edited drawing.

For schematics (Phase 17): use **AVIXA-style equipment IDs as stable anchors** — when regenerating, equipment with the same `equipment_id` keeps its prior position if a position exists in `edited_data`, new equipment is appended, removed equipment is deleted. This is mergeable; floor plans are not.

For floor plans (Phase 19): equipment glyphs are **referenced** to rooms (anchor by `room_id` + `equipment_id`), not absolutely positioned. The room polygon is hand-drawn; the equipment placement is "snap into the room." Then equipment changes only delete/add glyphs, never move walls.

For rack elevations (Phase 18): U-position is canonical (1U from top), ordering is the user's edit. Regen reapplies U-heights; ordering survives unless an item is removed.

**Phase:** 17 (decision must be made before the first auto-generated drawing ships), enforced in 18 / 19 / 20.

---

### CRIT-03: Browsershot queue worker memory ballooning when rendering many drawings

**What goes wrong:**
A single Browsershot invocation forks a chrome-headless-shell process. With small Blade pages (current RAMS/O&M), each fork peaks ~150–300 MB and exits in 2–4 s. Embed a 50-equipment SVG schematic and 100-wall floor plan into the same PDF and the fork peaks 600 MB+ and may take 15–30 s. **Multiply by queue concurrency** and the AlmaLinux VM is one OOMkill away from a Postmark-bounce cascade (every "doc ready" email retries, queue backs up, system unresponsive).

The 260427-qvr migration explicitly sets `--timeout=600` in the queue worker — this was for OCR, but the same worker now renders PDFs too. A single hung Browsershot can hold a 600 MB worker for 10 minutes.

**Why it happens:**
- Browsershot doesn't reuse Chromium between invocations (each `Browsershot::html()->savePdf()` is a fresh fork).
- chrome-headless-shell does NOT honour `--memory-pressure-off` reliably.
- SVG with thousands of nodes (a schematic with 50 equipment + 200 cables = 1,000+ SVG elements) is a memory hot spot in Skia.
- Queue has no per-job memory cap; one bad job can trigger OOMkill of unrelated jobs.

**Consequences:**
Production downtime. Worse, because emails go via the queue (NOTF-01..NOTF-05), an OOMkilled queue worker means **doc-ready notifications never send** — clients don't know their handover is ready, engineers think the system is broken.

**Warning signs:**
- `dmesg | grep -i "out of memory"` shows recent kills of `php` processes
- Queue depth grows monotonically rather than draining
- Drawing render durations creeping up release-over-release

**Prevention:**
- Add Chromium flag `--disable-dev-shm-usage` to `PdfRenderService::fromBlade()` (the search results flagged this as the universal Docker/Alpine fix; same applies to AlmaLinux when /dev/shm is small).
- Add `--memory-pressure-off`, `--no-zygote`, `--disable-gpu` to chromium args (already partially set; verify all three).
- Cap queue concurrency to 1 worker at a time for the drawing queue (separate connection: `queues=>['drawings','default']`).
- Set a per-job memory probe: at the start of `BuildDrawingPdfJob::handle()`, log `memory_get_usage(true)`; at end, log delta. If delta > 400 MB, alert.
- Set Browsershot `setTimeout(180)` explicitly for drawing renders; abort hung renders rather than hold a worker.
- For the worst case (huge floor plans), pre-rasterise the SVG to a single PNG via Imagick in the queue, embed the PNG into Blade — single image is ~1/10th the memory of an SVG with 1,000 elements.
- Add `php artisan queue:work --max-jobs=50 --max-time=3600` so workers cycle and release memory.

**Phase:** 20 (export pipeline integration with Browsershot — this is where rendering happens and where the queue load lives).

---

### CRIT-04: Chrome version drift in chrome-headless-shell between dev and prod

**What goes wrong:**
260427-qvr fixed a real production outage: queue worker running as root, writing files PHP-FPM couldn't read. The same architecture has a second time bomb: **`chrome-headless-shell` lives in puppeteer-cache under a versioned path** (`linux-147.0.7727.57/...`). When puppeteer is reinstalled or upgraded, the path changes. The `/home/stcav/chrome` symlink fixes that — but only if someone remembers to repoint it.

Drawing-specific risk: a Chromium upgrade between releases changes how SVG `<foreignObject>`, CSS `text-shadow`, or font fallback render. A drawing that looked perfect on dev (Chrome 134) renders with the wrong font in prod (chrome-headless-shell 147), and the engineer in the field sees garbled equipment labels on their tablet PDF.

**Why it happens:**
- chrome-headless-shell auto-updates with `npx puppeteer browsers install`. Production never runs that.
- Local dev uses real Chrome (147+). Prod uses chrome-headless-shell pinned at install time.
- SVG rendering is the **most version-sensitive** part of Chromium (Skia gets new tweaks every release).
- Font fallback in chrome-headless-shell is poorer than full Chrome — if a font is missing, Chrome substitutes silently; chrome-headless-shell may render `□□□`.

**Consequences:**
Drawings render correctly in dev, wrong on prod. Bug reproducible only in production. Hours of "but it works on my machine" debugging.

**Warning signs:**
- `/home/stcav/chrome` symlink target differs between dev runbook docs and what's actually on disk
- New PDFs show `□□□` glyphs where text should be
- Side-by-side dev/prod PDF comparisons show different fonts

**Prevention:**
- Document the chrome-headless-shell version in `.env.example` next to `CHROME_PATH=` (e.g. `# chrome-headless-shell pinned to 147.0.7727.57 — bump only after smoke-test passes`).
- Extend the existing `php artisan pdf:smoke-test` command (built in 260427-qvr) to render a **drawing-specific** test page: SVG with equipment glyphs, AVIXA symbols, the actual fonts used in production, page-break across two pages. Run after every Chromium upgrade.
- Pin font files INTO the project: load them as `@font-face` from `public/fonts/` not Google Fonts. Set `font-display: block` so Browsershot waits for the font.
- Use `setOption('waitForFunction', 'document.fonts.ready')` in `PdfRenderService` before saving the PDF — guarantees fonts loaded.
- Build a "chrome upgrade runbook" (one page) that mirrors the 260427-qvr deployment runbook: pin → test → symlink → smoke-test → deploy.
- After every prod deploy, run `php artisan pdf:smoke-test --drawings`. Diff bytes against a known-good baseline; if delta > 5%, manual review.
- `sudo -u stcav -H /home/stcav/chrome --version` printed in deploy log.

**Phase:** 20 (export pipeline + deploy hygiene — pairs with the existing 260427-qvr runbook).

---

### CRIT-05: Signal-flow direction reversed in auto-generated schematics

**What goes wrong:**
Auto-schematic generator infers signal flow from cable schedule rows like `[source: HDMI Wallplate-1, dest: Display-1, cable_type: HDMI]`. If the parser treats `source/dest` as semantic ("source" means "first column in the QuoteWerks export") rather than electrical ("source = signal origin"), the resulting schematic shows arrows pointing the wrong way. Engineers reading it cannot use it; commissioning fails because cabling is checked against a wrong drawing.

**Why it happens:**
QuoteWerks exports cable rows in an unspecified direction — sometimes alphabetical, sometimes the order the engineer typed them. An HDMI Wallplate "source" point may be terminologically a "source" (signal originates here from a laptop) or a "drop" (cable terminates here from a matrix). Without an electrical-direction column, the schematic infers wrong half the time.

**Consequences:**
Drawing is **electrically reversed**. Engineer reviews drawing, says "looks fine" (because boxes are right), discovers in commissioning that the output of the matrix is shown going INTO the matrix. Drawing becomes useless for the actual use case (commissioning + handover reference).

**Warning signs:**
- Engineer asks "is this drawing right?" repeatedly during commissioning
- Cable count from the schematic does not match the cable schedule total
- Display showing arrows going INTO a "Source" labelled box

**Prevention:**
- **Never infer direction from row order.** Use equipment role classification (already partially built — `Device::isSource() / isDestination() / isProcessor()`) to determine arrow direction. A "Display" is always a destination; a "Laptop input plate" is always a source; a "Matrix" has typed inputs and outputs.
- Where ambiguity exists, **render the cable as undirected** (line, not arrow) and surface a warning: "12 cables had ambiguous direction — review before sharing."
- Validate against the existing `equipment_classification` data — extend `Device::signalDirection()` if missing.
- For cables between two processors, fall back to `cable_schedule.direction` column if present; otherwise undirected.
- AVIXA D401.01 mandates arrow conventions — cite in code comments.
- Add a unit test: known cable schedule (Display ← Matrix ← Source) renders with arrows in that direction.

**Phase:** 17 (schematic auto-generation must get this right at v1).

---

### CRIT-06: U-height accuracy in rack elevations

**What goes wrong:**
Rack elevation says "1U" because the equipment classifier defaulted to 1U for unknown items. Real device is 2U. Drawing places the next item flush against it; in real life, the next item collides. Or worse, drawing shows 12U of equipment, real rack needs 18U → the rack we ordered is too small.

**Why it happens:**
- Spec sheets vary. One vendor's "1U" amplifier is 1.5U with cable management.
- Equipment classifier (existing in `Device::classify()`) doesn't currently store U-height — it stores category.
- Rack ventilation requirement (1U gap above amps, behind matrix) is a tribal-knowledge rule; it's not in any data field.
- Ports on the back can't be physically reached if U-spacing is wrong.

**Consequences:**
Drawing-vs-reality mismatch. Engineer arrives on site, rack doesn't fit. Project delayed. Worst case: equipment runs hot because of zero ventilation gap → MTBF drops, warranty claim, return visit.

**Warning signs:**
- Rack drawing total U exactly equals rack capacity (statistically suspicious — should always have spare U)
- All equipment drawn flush against neighbours (no gaps anywhere = ventilation ignored)
- Engineer overrides U-height on most items (signal default is wrong)

**Prevention:**
- **Make U-height explicit in `Device` schema** — add `u_height` (decimal, allows 1.5) and `requires_ventilation_gap_above` (boolean), `requires_ventilation_gap_below` (boolean).
- Source U-height from the QuoteWerks parser if the part number resolves to a known DB; otherwise prompt during quote review (existing review flow). Default to **null, not 1U** — render "U-height unknown" warning instead of guessing.
- Default ventilation rules per category: amplifiers above & below, matrices above, displays N/A.
- Total rack U validates against `rack_size_u` field; if exceeds, banner: "Equipment exceeds rack capacity — increase rack size."
- Add a "U-height unknown" count to the Phase 08 dashboard.

**Phase:** 18 (rack elevation generator).

---

## MODERATE PITFALLS

These cause significant rework or persistent friction. Plan for them but don't gate the milestone on them.

### MOD-01: DXF/DWG export — license traps and "DWG that's actually DXF"

**What goes wrong:**
Engineering asks "can you export to DWG?" Devs find LibreDWG, ship it, marketing announces DWG export. Two months later: a license auditor (or paranoid CTO) notes **LibreDWG is GPLv3** — linking it into the closed-source RAMS application requires the entire app to be released under GPL. Internal tool? Maybe okay. But if the milestone v1.4 client portal gives external users access to functionality that depends on LibreDWG, that's distribution → GPL applies.

Or: dev finds a "DWG export" library, ships it, AutoCAD won't open the files because they're actually DXF (text format) renamed to .dwg. Engineers think we're broken.

**Why it happens:**
- True binary DWG is a closed format; OSS implementations are limited (LibreDWG is the only credible one, and it's GPLv3).
- DXF is open (text-based), supported by many MIT/BSD libs.
- "DWG export" libraries in PHP/JS land almost universally output DXF.
- GPL "this is fine for internal" is true for use, false for distribution. Internal tools that get distributed to subcontractors trigger GPL obligations.

**Consequences:**
- Either: forced GPL on the entire RAMS codebase, or
- Strip DWG export feature, broken promise, engineer disappointment.

**Warning signs:**
- A dev PR adds a Composer/npm dep with `"license": "GPL-3.0"` or similar
- Engineer report "AutoCAD won't open this DWG"
- Client portal gives external users access to the DWG export endpoint

**Prevention:**
- Ship **DXF export only** in v1.3. Be explicit: "DXF export (compatible with AutoCAD, BricsCAD, FreeCAD)."
- Use a permissive-licensed DXF library: in PHP, hand-rolled DXF text writer (DXF is just lines of text — group code + value); or call an MIT-licensed Node library via shell.
- If true DWG is required: run LibreDWG as a **separately-installed binary** invoked via `Process::run()` — this is the GPL "linked independent program" exception (per OSArch discussion). NEVER include LibreDWG as a Composer/npm dep linked into the app.
- Document the license decision in `decisions:` block of the phase SUMMARY (mirror the 260427-qvr pattern).
- License audit: `composer licenses` and `npm ls --all --json | jq '.dependencies | with_entries(select(.value.license))'` after every dep change. Alert on anything matching `GPL`, `AGPL`, `LGPL` (the last is okay if dynamically linked, but flag for review).

**Phase:** 20 (export pipeline).

---

### MOD-02: Coordinate system mismatch — canvas Y-down vs DXF Y-up

**What goes wrong:**
Floor plan drawn in canvas at coordinates (50, 100) means "50 right, 100 down from top-left." DXF stores the same point as (50, 100) meaning "50 right, 100 UP from origin." Export the canvas to DXF directly → drawing is **vertically mirrored**. Doors at the top of the drawing land at the bottom in AutoCAD.

**Why it happens:**
HTML5 canvas: y increases downward (origin top-left). CAD (DXF, DWG, SVG with default transform): y increases upward (origin bottom-left). Always trips devs the first time.

**Consequences:**
Mirrored drawings shipped to clients. AutoCAD users open and laugh. Have to re-export everything.

**Warning signs:**
- Imported DXF in AutoCAD looks "upside down"
- Door labelled "north entry" appears at bottom of CAD drawing

**Prevention:**
- In the DXF exporter, apply `y_dxf = max_canvas_y - y_canvas` to every point before writing.
- Unit test: a triangle with top vertex at canvas (100, 0) must export to DXF with that vertex at the highest Y value, not the lowest.
- Same correction for SVG export if SVG is going through a transform.

**Phase:** 20.

---

### MOD-03: Unit confusion — px vs mm vs inch vs feet

**What goes wrong:**
Canvas drawn at 1 px = 1 cm wall length. Export to DXF without specifying units → AutoCAD opens it assuming 1 unit = 1 inch (or 1 mm depending on template). Wall that should be 5 m shows as 5 inches. Engineer scales it manually, gets it 90% right, ships drawing with wrong dimensions to client.

**Why it happens:**
- Canvas has no inherent unit — it's pixels.
- DXF has units, but the default is `$INSUNITS = 0` (unitless) which different CAD tools interpret differently.
- AV drawings in UK convention are mm; in US convention are feet+inches.
- Engineers may print drawings to fit page, losing the scale entirely.

**Consequences:**
Wrong-size drawings shipped. Worst case: client measures rack space from drawing, orders a rack that doesn't fit.

**Warning signs:**
- DXF imported into AutoCAD displays at obviously wrong scale (postage-stamp or building-sized)
- Engineer asks "what's the scale on this drawing?"
- Drawings printed without scale bar

**Prevention:**
- Define ONE canonical unit at the data layer: **mm** (UK convention; matches both manufacturer spec sheets and the 21CAV market).
- Store all drawing coordinates in mm. Render to canvas at a configurable mm-per-px scale (e.g., 1 px = 10 mm = 1 cm).
- DXF export: emit `$INSUNITS=4` (mm). SVG export: emit `width="...mm"` not `width="...px"`.
- Display a scale bar on every PDF drawing — "0 ━━ 1 m ━━ 2 m." Eyes catch wrong-scale before fingers do.

**Phase:** 19 (floor plan tool — biggest unit risk) and 20 (export units).

---

### MOD-04: Auto-save thrashing or lost work

**What goes wrong:**
- Save every keystroke → 100 POSTs per minute, db row churn, queue spam.
- Save on blur only → user closes tab while drawing → 30 mins of work gone.
- Debounced 500 ms with naive setTimeout → user navigates away mid-debounce → save never fires.

**Why it happens:**
Vanilla Alpine.js doesn't have a built-in debounce-with-flush-on-unmount. The temptation is to roll one inline; the bug is forgetting `beforeunload`.

**Consequences:**
Lost work → the product becomes "the thing that eats my work" (same failure mode as CRIT-02 but from a different cause). Or DB-bloating saves.

**Warning signs:**
- DB shows hundreds of save events per drawing (debounce broken)
- Support tickets: "I made a change but it's not there when I come back"
- `drawings.updated_at` timestamps clustered far in the past relative to last edit time

**Prevention:**
- Debounced save (1500 ms — slower than typing-feel because canvas state is large) AND a `beforeunload` flush AND a `visibilitychange` flush (mobile Safari fires this when tab backgrounded).
- Server endpoint deduplicates: hash the canvas-JSON before write; if hash unchanged, no DB write.
- Show a small "saved 3s ago" indicator — gives users confidence and surfaces if save is broken.
- Save versioned snapshots (every 10th save, or on explicit "save snapshot" button) so users can restore from a recent past state.

**Phase:** 19.

---

### MOD-05: Canvas-JSON file size in DB

**What goes wrong:**
TLDraw serializes a 100-shape drawing to ~150–500 KB JSON. A floor plan with 200 walls + 50 equipment glyphs + freehand lines can easily hit 1–2 MB. Stored in MySQL `TEXT` column (max 64 KB) → silent truncation, drawing won't reload.

Stored in `LONGTEXT` (4 GB max) → fine for size, but: SELECT * queries pull MBs across the wire, backups balloon, replication lags, and indexing is impossible.

**Why it happens:**
- Each TLDraw shape stores style, geometry, ID, parent, and meta. ~1–3 KB per shape.
- Freehand strokes serialize every point at ~30 points per second of drawing.
- Excalidraw's element model is similar.

**Consequences:**
- Truncation: drawings won't reload, rage-tickets.
- LONGTEXT performance: dashboard queries that JOIN drawings get slow as drawing count grows.
- Backups: nightly mysqldump goes from minutes to hours.

**Warning signs:**
- mysqldump of `drawings` table > 100 MB
- Reload of drawing returns malformed JSON
- Dashboard listing page slows by > 200ms after drawings ship

**Prevention:**
- Use `MEDIUMTEXT` (16 MB) — generous headroom, no LONGTEXT cost.
- **Never** SELECT the canvas blob in list/dashboard queries. Project drawing list shows `id, project_id, drawing_type, updated_at, file_size`; canvas blob is fetched only when the editor opens.
- Compress JSON server-side before storing: `gzcompress($json, 6)` cuts size 70–80%. Store in `BLOB` (LONGBLOB if needed). Read path decompresses.
- Render result (SVG/PNG) cached separately in `documents` disk via `DocumentArtifactStorage` — the editor reloads the JSON, but PDF generation reads the cached SVG.
- Per-drawing size limit: reject saves > 5 MB compressed with a friendly error ("Drawing too complex — split into multiple drawings").

**Storage planning estimate:**
- Average drawing: ~200 KB JSON, ~50 KB compressed
- Heavy floor plan: ~1.5 MB JSON, ~300 KB compressed
- 500 projects × 4 drawings each = 2,000 drawings × 200 KB = ~400 MB DB column
- Generated SVG/PNG artifacts on disk: assume 100 KB each = 200 MB on `documents` disk
- Backup impact: include drawing rows in the same nightly dump; compressed SQL dump grows by ~150 MB

**Phase:** 19 (decision must be made before first drawing saves).

---

### MOD-06: TLDraw bundle size hits the global Alpine bundle

**What goes wrong:**
Dev imports `@tldraw/tldraw` from `resources/js/app.js` — Vite bundles it into the global app bundle that ships on every page including login, dashboard, RAMS index. Bundle goes from ~150 KB (Alpine + Axios) to ~2.5 MB. Every page on the site loads slowly, the dashboard the engineer uses 100x/day feels broken.

**Why it happens:**
TLDraw is React + tldraw-editor + tldraw-store + tldraw-ui = 2+ MB minified. Excalidraw is similar. They are designed as standalone apps, not as widgets in a multi-page server-rendered app.

**Consequences:**
Site-wide slowdown. CI build times double. CDN bandwidth costs (if applicable). Engineer trust drops.

**Warning signs:**
- `du -sh public/build/` jumps after a TLDraw-adding PR
- Dashboard Lighthouse score drops by 20+ points
- Vite build time doubles

**Prevention:**
- Lazy-load via dynamic import in a per-page Vite entry: only the drawing editor pages import TLDraw.
- `vite.config.js` configure code splitting: drawing pages get their own chunk.
- Consider Konva.js (200 KB) or vanilla canvas for non-React paths if bundle size is critical — but at the cost of mobile UX (see MOD-08).
- Monitor: `du -sh public/build/` after every Vite build. Alert if the global chunk exceeds 500 KB.

**Phase:** 19.

---

### MOD-07: localStorage as canvas state truth → multi-device conflicts

**What goes wrong:**
Engineer A opens drawing on tablet at site, edits floor plan. Saves to localStorage as the offline-capable cache. Comes back to office, opens same drawing on desktop — desktop pulls from server (which is one save behind tablet). Engineer thinks tablet edits are saved, they're not.

**Why it happens:**
Canvas libs love localStorage as offline caching. But this codebase's existing pattern (per the field stack) is **online-only** for the mobile field view. Mixing offline-localStorage-truth with online-server-truth without conflict resolution → drift.

**Consequences:**
Lost work, contradictory drawings between team members.

**Warning signs:**
- "I edited this on iPad but desktop shows the old version"
- Drawing rows where `updated_at` is older than file modification on iPad

**Prevention:**
- **Server is the only truth.** localStorage is read-only cache for offline read; never an offline write target.
- Display a "you're offline" banner if last save was > 30 s ago AND `navigator.onLine === false`.
- If someone else has edited the drawing since you opened it, on save show: "Drawing changed since you opened it. Reload (loses your work) / Force save (overwrites theirs)?"
- Add a `drawing_locks` table — soft pessimistic lock with 5-min TTL. First editor gets the lock; second editor sees "locked by Engineer A 2 min ago."

**Phase:** 19.

---

### MOD-08: iPad Safari touch gotchas

**What goes wrong:**
- Two-finger pan zooms the page instead of panning the canvas.
- Drawing while resting palm on screen creates accidental strokes (no palm rejection).
- `touchstart` fires, then `mousedown` fires 300 ms later — duplicate event handling.
- After PWA install, viewport meta is wrong → canvas reports 980 px wide, drawing tiny.

**Why it happens:**
- iPad Safari's pinch-zoom default applies to the whole page unless `touch-action: none` is set on the canvas element.
- Apple Pencil + finger palm = two simultaneous touches; Safari treats as multi-touch unless library handles `pointerType === 'pen'` distinctly.
- iOS Safari has historic 300 ms delay between touchend and the synthesized mousedown for "tap." `touch-action: manipulation` removes it.

**Consequences:**
Engineers with iPads can't actually use the drawing tool. Field UX breaks. Drawing tool becomes a desktop-only feature, defeating the v1.2 mobile-field-view investment.

**Warning signs:**
- Engineer in field reports "I can't draw — every time I touch the screen the page zooms"
- Pencil strokes contain accidental jumps where palm registered as touch
- Tap actions feel laggy on iPad

**Prevention:**
- Use a library that handles pointer events natively. **TLDraw has best-in-class iPad / Pencil support** (palm rejection, pressure sensitivity, two-finger pan/zoom). Konva supports gestures but you wire palm rejection yourself. Fabric.js has known iPad bugs (search results show fabricjs/fabric.js#8849 unresolved).
- Set `touch-action: none` on the canvas element. Set `<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">` on drawing pages only.
- Test on real iPad — not just desktop Chrome DevTools mobile emulation. Same lesson as INST-03's HEIC: device-specific behaviour can only be validated on device.
- Confirm Apple Pencil rendering via `pointerType === 'pen'` — pressure-sensitive line widths.

**Phase:** 19.

---

### MOD-09: HiDPI / Retina rendering — same lesson as commissioning signature pad

**What goes wrong:**
Canvas drawn at CSS dimensions 800×600 on iPad Pro (devicePixelRatio = 2). Canvas backing store is 800×600. Lines look blurry, like a low-res image scaled up. PDF export inherits the blur. Client receives blurry handover docs.

This is the **exact** issue solved for INST-05's commissioning signature canvas in v1.2.

**Why it happens:**
HTML canvas defaults to 1:1 backing store. On a 2× DPR display, every CSS pixel is 2 device pixels — but the canvas only has data for 1 device pixel. Browser stretches with bilinear interp = blur.

**Consequences:**
Blurry drawings on every retina device. Engineer reviews on iPad → "looks ok, a bit fuzzy." Client opens PDF on retina laptop → "this looks unprofessional."

**Warning signs:**
- Drawings look sharp on engineer's HD monitor, blurry on retina iPad / 4K display
- PDFs look soft compared to the on-screen editor

**Prevention:**
- **Apply the v1.2 commissioning fix:** set canvas.width = cssWidth × DPR; canvas.height = cssHeight × DPR; ctx.scale(DPR, DPR). All drawing libs (TLDraw, Konva, Excalidraw) handle this internally — but only if you use their stage/canvas factory, not raw `<canvas>`.
- For SVG-based libs (TLDraw v2 renders as SVG primarily): no DPR issue at edit time, but watch the canvas-export-to-PNG path — that step needs DPR.
- Reuse the v1.2 `signature_canvas` blade as a reference; don't reinvent.
- On PDF export, render SVG (not raster) where possible; SVG is resolution-independent.
- Side-by-side test: same drawing on a non-retina monitor and a retina iPad. Both should look sharp.

**Phase:** 19.

---

### MOD-10: Auto-generated drawing references break O&M Manual on regen

**What goes wrong:**
O&M Manual generated yesterday includes a SVG link to `documents/drawings/proj-123-rack.svg`. Today engineer regenerates the rack drawing — `DocumentArtifactStorage::writePath()` overwrites the file. O&M Manual still references the same path → it now displays the new drawing, but the O&M's text section says "as shown above, the rack contains the originally-quoted equipment" — text is stale relative to image. **Doc internally contradicts itself.**

Or worse: regen path changes (timestamp suffix), O&M points to a 404, doc renders with broken image.

**Why it happens:**
- Existing pipeline assumes generated artifacts are "regenerable from canonical data." Drawings break this — they have user edits that can't be regenerated.
- O&M Manual generation reads drawings at generation time; subsequent drawing changes don't trigger O&M re-gen.
- DocumentArtifactStorage doesn't version artifacts.

**Consequences:**
Inconsistent handover docs. Engineer doesn't trust the system.

**Warning signs:**
- Client opens O&M PDF, drawing image differs from text body's described equipment count
- Broken image placeholders in delivered O&M PDFs

**Prevention:**
- Version drawing artifacts: `documents/drawings/proj-123-rack-v3.svg` where v3 = a version stamp on `Drawing.id + updated_at`. O&M references the exact version it embedded.
- On drawing save, mark all O&M Manuals that embed it as `regen_recommended = true`. Surface in dashboard.
- Don't auto-regen the O&M (would lose user edits) — prompt: "rack drawing changed since this O&M was generated — regenerate O&M / keep current?"
- Add `referenced_by` table linking drawings to O&M / RAMS docs that include them.

**Phase:** 20 (export integration into O&M).

---

### MOD-11: DocumentArtifactStorage path collisions across drawing types

**What goes wrong:**
Project 123 has a schematic, rack elevation, and floor plan. All write under one drawing type → filename collisions. Or each gets its own type constant → 3 new constants + 3 new disk subdirectories + reader logic in 3 places.

**Why it happens:**
DocumentArtifactStorage's H-07 contract uses `TYPE_*` constants. Adding 3 drawing types triples the surface area unless we plan it.

**Warning signs:**
- Files overwriting each other on save
- Reader returns null for drawings that exist on disk

**Prevention:**
- Add ONE constant: `TYPE_DRAWING`. Use a sub-prefix in the filename: `proj-{id}-schematic-v{n}.svg`, `proj-{id}-rack-v{n}.svg`, `proj-{id}-floorplan-v{n}.svg`.
- Helper method: `DocumentArtifactStorage::drawingPath(int $projectId, string $kind, int $version, string $ext): string`.
- Reader path stays single-disk-lookup; type-aware routing happens in the helper, not in DocumentArtifactStorage internals.

**Phase:** 20.

---

### MOD-12: Notification timing — drawing-ready emails sent before download is ready

**What goes wrong:**
Phase 09's NotificationRecipientResolver pattern says: set idempotency timestamp BEFORE send. Following that pattern for drawing-ready: drawing job marks `notified_at`, sends email. **But** drawing PDF render is a separate downstream step that may still be queued. Email arrives, link 404s.

**Why it happens:**
260427-qvr migrated PDF rendering to a synchronous flow inside the existing services. If drawing PDFs go through a separate queued job (likely, given memory concerns in CRIT-03), there's a window where the drawing exists but the PDF doesn't.

**Consequences:**
Click email → 404 → "the system is broken" rage ticket.

**Warning signs:**
- 404 hits on drawing-PDF endpoints clustered near email send timestamps
- Clients reporting "your email said it was ready but the link was broken"

**Prevention:**
- Notification fires from the LAST job in the chain — the PDF render job, not the drawing-save job.
- Use Laravel `Bus::chain([SaveDrawing, RenderDrawingPdf, NotifyDrawingReady])`.
- If PDF render fails, do NOT send the email; surface failure in dashboard. (Mirrors NOTF-04 pattern.)

**Phase:** 20.

---

## MINOR PITFALLS

### MIN-01: Symbol consistency
**What goes wrong:** Mixing AVIXA, ANSI, and manufacturer-specific symbols in one drawing → inconsistent visual language, engineer reading-time goes up.
**Warning sign:** Drawing review surfaces "this symbol means X here but Y on page 3."
**Prevention:** Single symbol set as the v1 default. Standardise on AVIXA D401.01 since 21CAV is UK/EU and AVIXA is the broad-AV-industry standard. Make symbols an asset folder (`public/drawings/symbols/avixa/`).
**Phase:** 17.

### MIN-02: Cable type misrepresented
**What goes wrong:** HDMI cable rendered with the same line as a network cable → engineer can't tell signal types apart.
**Warning sign:** Engineer asks "which line is the HDMI?" while reading a generated schematic.
**Prevention:** Map cable type → line style: HDMI = solid red, Cat6 = solid blue, fibre = dashed orange, audio = solid green. Document in a legend on every drawing.
**Phase:** 17.

### MIN-03: Foreign-object SVG fallback
**What goes wrong:** SVG with `<foreignObject>` (used by some chart libs to embed HTML for text) doesn't render in older Chromium / chrome-headless-shell. Text disappears.
**Warning sign:** Equipment labels missing in PDF but visible in browser preview.
**Prevention:** Avoid `<foreignObject>` in any SVG that flows through Browsershot. Render text as `<text>` elements, not embedded HTML.
**Phase:** 17.

### MIN-04: Page break across drawings in O&M PDF
**What goes wrong:** Floor plan SVG rendered into O&M Blade — Chromium paginates it, slicing the drawing in half across two PDF pages.
**Warning sign:** Multi-page drawing appearance where a single-page drawing was expected.
**Prevention:** Wrap drawings in `<div style="page-break-inside: avoid; break-inside: avoid;">`. Also set `max-height: 100vh` so the drawing scales to fit one page.
**Phase:** 20.

### MIN-05: Firefox vs Chrome canvas rendering differences
**What goes wrong:** Engineer using Firefox sees a slightly different rendering of TLDraw than the Chrome user — antialiasing, line caps, gradient handling differ.
**Warning sign:** Bug report from one engineer that another can't reproduce.
**Prevention:** Document supported browsers (existing platform: Chrome / Edge / Safari only for the drawing editor). Display a "Firefox unsupported" banner on drawing pages if `navigator.userAgent.includes('Firefox')`.
**Phase:** 19.

### MIN-06: Disk space growth from drawing artifacts
**What goes wrong:** SVG drawings ~100 KB each, but raster fallback PNGs (for old-PDF compatibility) = ~1 MB each. 500 projects × 4 drawings × 1 MB = 2 GB on disk.
**Warning sign:** `df -h` shows `documents` partition >80% full; deploy fails during `composer install`.
**Prevention:** Monitor `df -h` on the production VM. Add `php artisan documents:prune-drawings` to remove drawings for archived projects (matches `Project::STATUS_ARCHIVED`).
**Phase:** 20.

### MIN-07: Backup strategy for drawings
**What goes wrong:** Drawings are stored partly in DB (canvas JSON) and partly on disk (rendered SVG/PNG). Backup-DB-only loses rendered artifacts; backup-disk-only loses the source-of-truth canvas state.
**Warning sign:** Test restore reveals broken drawing references.
**Prevention:** Backup both. Document in `BACKUP-OPS-CHECKLIST.md` (mirrors POSTMARK-OPS-CHECKLIST.md pattern from v1.1). Test restore.
**Phase:** 20.

### MIN-08: AI in drawing generation — keep the constraint
**What goes wrong:** Tempting to ask Claude to "generate a schematic from this equipment list." Violates the v1.0 constraint (AI = formatting only, never inventing scope). AI invents an equipment item that's not in the quote, drawing diverges from BOM.
**Warning sign:** Drawing contains an equipment label that doesn't match any row in `ProjectDataService::equipment()`.
**Prevention:** AI may assist with **layout heuristics** (signal-flow grouping, suggested glyph positions) but the equipment list is read directly from `ProjectDataService::equipment()`. Never let AI inject equipment IDs.
**Phase:** 17.

### MIN-09: Existing tests reference HTML internals
**What goes wrong:** Same lesson as 260427-qvr's "Deferred Items": existing PDF tests assert HTML internals; adding drawing PDFs will likely break those assertions on a snapshot test.
**Warning sign:** CI fails `RamsPdfScopeTest` after the first drawing-into-RAMS-or-O&M PR.
**Prevention:** When adding tests, prefer "PDF was created and is non-empty" (file_exists + filesize > N) over "PDF contains exact string."
**Phase:** 20.

---

## Phase-Specific Risk Register Summary

| Phase | Top Risk | Top Prevention |
|-------|----------|----------------|
| 17 (schematics) | CRIT-05 reversed signal-flow direction | Use `Device::isSource/isDestination/isProcessor()` not row order |
| 17 (schematics) | CRIT-02 drift vs canonical data | `equipment_id`-anchored merge, lock-on-edit pattern |
| 17 (schematics) | CRIT-01 React lib in PDF | Render server-side SVG; never run TLDraw inside Browsershot |
| 18 (rack elevations) | CRIT-06 U-height defaults | Add `u_height` field on Device, default null not 1U |
| 18 (rack elevations) | CRIT-02 user edits lost on regen | Same lock-on-edit pattern as 17 |
| 19 (floor plans / drawing tool) | MOD-08 iPad touch | TLDraw (not Fabric); test on real iPad |
| 19 (floor plans / drawing tool) | MOD-09 retina blur | Reuse v1.2 commissioning DPR fix |
| 19 (floor plans / drawing tool) | MOD-04 lost work | Debounce + beforeunload + visibilitychange flush |
| 19 (floor plans / drawing tool) | MOD-05 storage size | MEDIUMTEXT + gzcompress, never SELECT in lists |
| 19 (floor plans / drawing tool) | MOD-06 bundle bloat | Lazy-load TLDraw via Vite dynamic import |
| 20 (export + O&M integration) | CRIT-03 queue OOM | `--disable-dev-shm-usage`, drawings on dedicated queue, memory probe |
| 20 (export + O&M integration) | CRIT-04 Chrome version drift | Extend `pdf:smoke-test --drawings`, pin chrome-headless-shell version in `.env.example` |
| 20 (export + O&M integration) | MOD-01 GPL trap | DXF only (text-based), avoid LibreDWG-as-library |
| 20 (export + O&M integration) | MOD-10 O&M references stale | Versioned filenames + `regen_recommended` flag |

---

## Sources

**Existing project context (HIGH confidence):**
- `.planning/PROJECT.md` — v1.0/v1.1/v1.2 shipped state, constraints, key decisions
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot migration runbook including chrome symlink, queue worker user, smoke-test pattern
- v1.2 INST-05 commissioning checklist — DPI signature canvas precedent for retina rendering
- v1.1 NotificationRecipientResolver / NOTF-* — idempotency-first dispatch pattern for MOD-12

**External research (MEDIUM confidence — verify in implementation):**
- [tldraw drawing & canvas interactions](https://tldraw.dev/features/composable-primitives/drawing-and-canvas-interactions) — pressure sensitivity, palm rejection, zoom-adaptive precision
- [Konva multi-touch scale](https://konvajs.org/docs/sandbox/Multi-touch_Scale_Stage.html) — confirms Konva handles pinch-zoom but you implement palm rejection
- [Fabric.js issue #8849](https://github.com/fabricjs/fabric.js/issues/8849) — known iPad touch event capture bug
- [Browsershot creating images](https://spatie.be/docs/browsershot/v4/usage/creating-images) — `waitUntilNetworkIdle`, `waitForFunction`, `setDelay`, font-render-hinting flag
- [Browsershot fonts discussion #450](https://github.com/spatie/browsershot/discussions/450) — local fonts loading pattern
- [LibreDWG OSArch licensing thread](https://community.osarch.org/discussion/2025/use-libredwg-as-a-linked-independent-program-for-a-commercial-software) — GPL "linked independent program" exception
- [LibreDWG GNU project page](https://www.gnu.org/software/libredwg/) — GPLv3 license confirmed
- [signature_pad #71 retina reload](https://github.com/szimek/signature_pad/issues/71) — DPR scaling pattern
- [AVIXA D401.01 architectural drawing symbols](https://www.avixa.org/standards/audio-video-and-control-architectural-drawing-symbols) — symbol standard reference
- [AVIXA F502.01 rack building](https://www.avixa.org/standards/current-standards) — rack design including thermal management
- [MySQL TEXT storage sizes](https://www.atlassian.com/data/databases/understanding-strorage-sizes-for-mysql-text-data-types) — TEXT 64KB / MEDIUMTEXT 16MB / LONGTEXT 4GB
- [PDF gotchas with headless Chrome](https://nathanfriend.com/2019/04/15/pdf-gotchas-with-headless-chrome.html) — pagination + page-break behaviour

**Confidence flags for downstream:**
- CRIT-01 (Browsershot + React canvas) — verify by running `php artisan pdf:smoke-test --drawings` against a TLDraw-hosted page during early Phase 20 spike. If it works, downgrade to MODERATE.
- CRIT-03 (memory) — measured on similar Browsershot deployments but specific limits depend on production VM RAM. Verify with a load test before Phase 20 acceptance.
- MOD-08 (iPad touch) — TLDraw's claims are HIGH confidence (well-documented), Konva claims are HIGH, Fabric.js claims (broken) are MEDIUM (one open issue, may be edge case).
- MOD-01 (GPL) — license interpretation is LOW-MEDIUM confidence; consult counsel before shipping any DWG export, even "internal."
