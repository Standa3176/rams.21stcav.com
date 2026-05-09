---
phase: quick-260509-ibx
plan: 01
type: execute
wave: 1
depends_on: []
autonomous: true
tags: [drawings, drawio, mxgraph, spike, v2.0, build-vs-buy]
files_modified:
  - public/vendor/drawio/                          # Task 1 — vendored draw.io static bundle (committed)
  - public/vendor/drawio/VERSION.md                # Task 1 — version + license + update procedure
  - resources/data/draw-io-stencils/21cav-mtr-spike.json  # Task 2 — 5-stencil pack
  - public/img/manufacturers/                      # Task 2 — manufacturer logos folder (created if absent)
  - app/Services/Drawings/DrawIoSpikeBuilderService.php   # Task 3 — deterministic mxGraph XML emitter
  - app/Http/Controllers/Admin/DrawIoSpikeController.php  # Task 4 — admin spike controller
  - resources/views/admin/drawings/draw-io-spike.blade.php # Task 4 — embed Blade
  - routes/web.php                                 # Task 4 — 3 new admin spike routes
  - app/Services/Drawings/DrawingService.php       # Task 5 — saveSpikeXml() method
  - app/Http/Controllers/Admin/DrawIoSpikeController.php  # Task 5 — save + svg export endpoints

requirements: []   # Pure spike — no roadmap requirement IDs (v1.3 shipped 2026-05-03; v2.0 not yet scoped)

must_haves:
  truths:
    # Spike outcome truths (D-LOCK acceptance from CONTEXT.md success criteria)
    - "Admin user opens admin/drawings/draw-io-spike/{project} and sees a Teams Room schematic auto-generated from the project's quote data — visible without any seed data clicks"
    - "5 stencils (Neat Bar Pro / Samsung display / ClickShare Bar Pro / Sennheiser TC mic / Netgear PoE switch) each render with manufacturer logo + port rails + connector glyphs in the embedded editor"
    - "Engineer can drag a device, redraw a cable, and click Save inside the iframe; reloading the page shows the saved state (round-trip integrity)"
    - "Round-trip cycle (load → edit → save → reload) completes in under 3 seconds on dev machine"
    - "Visual output is benchmarkable side-by-side against the user's Lucidchart Extron Concept reference at end-of-week-2 evaluation"
    # D-LOCK immutables (transcribed from CONTEXT.md — must remain true at end of spike)
    - "D-LOCK-1: draw.io is SELF-HOSTED at /vendor/drawio/embed.html — no CDN reference, version pinned in VERSION.md, manual update model only"
    - "D-LOCK-2: lock-on-edit honoured — first save flips ProjectDrawing.is_locked, subsequent saves create a NEW versioned row via DrawingService archive-prior pattern (mirrors v1.3 Phase 18 P03)"
    - "D-LOCK-3: ONLY the small Teams Room archetype is in scope — no boardroom/divisible/classroom/townhall/huddle scaffolding"
    - "D-LOCK-4: exactly 5 stencils (no more, no fewer) — videobar, display, BYOD, ceiling mic, network switch"
    - "D-LOCK-5: zero AI calls in this spike — port metadata is hand-coded JSON, builder service is pure deterministic data → mxGraph XML"
    - "D-LOCK-6: builder reads real ProjectPackage::extracted_data['equipment'] — never hardcoded JSON"
    - "D-LOCK-7: spike route is admin-middleware-gated and NOT linked from any user-facing Blade — engineers cannot stumble into it"
    - "D-LOCK-8: source of truth is mxGraph XML stored in project_drawings.canvas_state (existing mediumText column reused — NO migration); SVG export is preview-only at storage/app/documents/drawings/spike-{id}.svg"
    # v1.3 protection truths (cannot regress what shipped 2026-05-03)
    - "Existing v1.3 SchematicGeneratorService + SchematicD2SourceBuilder + D2 CLI pipeline run unchanged — the spike runs ALONGSIDE, never replaces"
    - "Existing /projects/{project}/drawings index page is untouched — no spike entry point leaks into the engineer-facing surface"
    - "No npm install / composer require commands run — vendored static bundle only"
  artifacts:
    - path: "public/vendor/drawio/embed.html"
      provides: "Self-hosted draw.io embed entry point — D-LOCK-1"
    - path: "public/vendor/drawio/VERSION.md"
      provides: "Pinned version + Apache 2.0 license attribution + manual update procedure"
      contains: "Downloaded version"
    - path: "resources/data/draw-io-stencils/21cav-mtr-spike.json"
      provides: "5-stencil mxGraph definition pack with port rails + connector glyphs"
      min_lines: 200
    - path: "app/Services/Drawings/DrawIoSpikeBuilderService.php"
      provides: "Deterministic ProjectPackage equipment → mxGraph XML emitter (D-LOCK-5/6)"
      exports: ["build(Project $project): string"]
    - path: "app/Http/Controllers/Admin/DrawIoSpikeController.php"
      provides: "show + saveXml + exportSvg admin-gated endpoints (D-LOCK-7)"
      exports: ["show", "saveXml", "exportSvg"]
    - path: "resources/views/admin/drawings/draw-io-spike.blade.php"
      provides: "Iframe embed + postMessage protocol bridge to draw.io editor"
      contains: "iframe src=\"/vendor/drawio/embed.html"
    - path: "app/Services/Drawings/DrawingService.php"
      provides: "saveSpikeXml() method — lock-on-edit + archive-prior for the spike row (D-LOCK-2)"
      contains: "saveSpikeXml"
  key_links:
    - from: "app/Services/Drawings/DrawIoSpikeBuilderService.php"
      to: "app/Models/ProjectPackage.php (extracted_data['equipment'])"
      via: "builder reads $project->latestPackage?->extracted_data['equipment']"
      pattern: "extracted_data.*equipment"
    - from: "resources/views/admin/drawings/draw-io-spike.blade.php"
      to: "/vendor/drawio/embed.html"
      via: "iframe + postMessage({action: 'load', xml: ...})"
      pattern: "postMessage.*action.*load"
    - from: "app/Http/Controllers/Admin/DrawIoSpikeController.php (saveXml)"
      to: "app/Services/Drawings/DrawingService.php (saveSpikeXml)"
      via: "lock-on-edit + archive-prior delegation"
      pattern: "saveSpikeXml"
    - from: "app/Services/Drawings/DrawingService.php (saveSpikeXml SVG path)"
      to: "app/Services/DocumentArtifactStorage.php (TYPE_DRAWING)"
      via: "writePath(TYPE_DRAWING, 'spike-{id}.svg')"
      pattern: "TYPE_DRAWING.*spike-"
---

<objective>
Run the 2-week build-vs-buy spike validating that draw.io / mxGraph embedded
inside this Laravel app can produce engineering-grade AV schematics matching
the user's Lucidchart Extron Concept reference, before committing 10–15 weeks
to a full v2.0 native build (Konva editor + custom port catalog + Lucidchart-
style renderer per memory `v2_engineering_grade_drawings_plan.md`).

**End-of-spike decision gate (week 2):** user evaluates rendered output side-
by-side with the Lucidchart reference. "Same league" → green-light v2.0 native
build with confidence. "Falls short" → fall back to evaluating Lucidchart API
or XTEN-AV.

Purpose: minimise the risk of a 10-15 week investment by spending ~2 weeks
on a fast prototype that lights up the entire data → render → round-trip path
for ONE room with FIVE devices, leaving the v1.3 production drawings pipeline
untouched.

Output: an admin-only sandbox URL that loads a real project's Teams Room data
into a self-hosted draw.io editor, lets the engineer drag/edit, saves the
mxGraph XML back to project_drawings.canvas_state, and writes an SVG preview
to storage/app/documents/drawings/spike-{id}.svg.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/STATE.md
@.planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-CONTEXT.md
@app/Models/ProjectDrawing.php
@app/Services/Drawings/DrawingService.php
@app/Services/Drawings/SchematicGeneratorService.php
@app/Services/Drawings/SchematicD2SourceBuilder.php
@app/Models/ProjectPackage.php
@app/Models/Project.php
@app/Services/DocumentArtifactStorage.php
@routes/web.php
@database/migrations/2026_05_01_000001_create_project_drawings_table.php

<interfaces>
<!-- Key types and contracts the executor needs. Extracted from codebase. -->
<!-- Executor uses these directly — no codebase exploration needed. -->

From `app/Models/ProjectDrawing.php` (Phase 17 P01):
```php
class ProjectDrawing extends Model {
    use HasFactory, SoftDeletes;

    public const KIND_SCHEMATIC  = 'schematic';
    public const KIND_RACK       = 'rack';
    public const KIND_FLOOR_PLAN = 'floor_plan';

    public const STATUS_DRAFT       = 'draft';
    public const STATUS_FOR_REVIEW  = 'for_review';
    public const STATUS_APPROVED    = 'approved';
    public const STATUS_SUPERSEDED  = 'superseded';
    public const STATUS_GENERATING  = 'generating';
    public const STATUS_READY       = 'ready';
    public const STATUS_FAILED      = 'failed';

    protected $fillable = [
        'project_id', 'site_survey_room_id', 'kind', 'rack_label',
        'version', 'sheet_number', 'superseded_by_id',
        'source_data', 'generated_svg', 'canvas_state', 'thumbnail_png_path',
        'status', 'error_message', 'filename',
        'completion_email_sent_at', 'failed_email_sent_at',
        'access_token', 'generated_by',
    ];

    public function isSuperseded(): bool { return ! is_null($this->superseded_by_id); }
    public function hasUserEdits(): bool { return ! empty($this->canvas_state); }
}
```

NOTE: `canvas_state` is `mediumText` (16 MB ceiling — see migration line 89), nullable.
That is exactly the column shape D-LOCK-8 calls for. **REUSE — DO NOT add a new
`mxgraph_xml` column** (no migration → no live `php artisan migrate` step,
fewer moving parts on deploy). `hasUserEdits()` becomes our lock-on-edit
trigger out of the box.

From `app/Services/Drawings/DrawingService.php` (v1.3 archive-prior pattern):
```php
class DrawingService {
    public function archivePrior(ProjectDrawing $existing, ProjectDrawing $newRow): void {
        $existing->status = ProjectDrawing::STATUS_SUPERSEDED;
        $existing->superseded_by_id = $newRow->id;
        $existing->save();
    }

    public function regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing {
        // wrapped in DB::transaction:
        //   1. replicate (drop per-version artifacts: canvas_state, generated_svg, ...)
        //   2. bump version
        //   3. archivePrior($existing, $newRow)
        // dispatch happens AFTER commit
    }
}
```

Mirror this pattern in saveSpikeXml() — task 5.

From `app/Services/DocumentArtifactStorage.php`:
```php
public const TYPE_DRAWING = 'drawings';   // already exists since Phase 17

// Writing the SVG export:
$path = app(DocumentArtifactStorage::class)
    ->writePath(DocumentArtifactStorage::TYPE_DRAWING, "spike-{$drawing->id}.svg");
file_put_contents($path, $svgBytes);
```

From `app/Models/Project.php`:
```php
public function latestPackage()  // returns ProjectPackage|null
{ return $this->hasOne(ProjectPackage::class)->latestOfMany(); }
```

From `app/Models/ProjectPackage.php`:
```php
protected $casts = [
    'extracted_data' => 'array',   // ['equipment' => [...], 'project_name' => ..., ...]
    'equipment_list' => 'array',
    'cable_list'     => 'array',
];
```

Equipment item shape (from v1.3 SchematicD2SourceBuilder consumption + RamsDataBuilder):
```
[
  'name' => 'Neat Bar Pro',
  'part_number' => 'NEAT-BAR-PRO',
  'quantity' => 1,
  'area' => 'Boardroom 1',         // used to filter to Teams Room
  'category' => 'hardware',        // 'hardware' | 'cable' | 'service' | ...
  'manufacturer' => 'Neat',        // optional, may be absent
]
```

From `routes/web.php`:
```php
Route::middleware('admin')->group(function () {
    // existing admin routes (users, ai-usage, solution-types, worker)
});
```
The `admin` middleware alias gates access. Add the 3 new spike routes inside
this existing group (~line 179 onwards).

From `app/Services/Drawings/SchematicD2SourceBuilder.php` SYMBOL_ALIASES (lines 34–68):
The builder's name-fragment → symbol allowlist is the reference pattern for
the spike's `name → stencil-id` mapping. Same shape, different output target.
The new builder's mapping table mirrors this approach — first-match-wins,
lowercase fragments — but maps to mxGraph stencil IDs in our pack instead of
SVG filenames.
</interfaces>
</context>

<decision_rationale>
## Why these design choices (not visited again during execution)

**Why self-host the draw.io bundle (D-LOCK-1, locked):**
- CDN dependency = surprise breakages when drawio.com pushes a new version
  that breaks our stencil schema mid-spike → wastes spike days.
- Same-origin = zero CORS / postMessage friction (huge dev velocity win at
  this prototype tempo).
- Apache 2.0 — committing the bundle to the repo is legally and
  operationally clean. Repo size cost (~5–10 MB) is a one-time hit;
  reproducibility wins forever.

**Why lock-on-edit (D-LOCK-2, locked):**
- Mirrors the v1.3 RAMS / O&M / worksheet patterns the user is already
  trained on — zero new mental model. Engineer-trust matters far more than
  convenience for a hand-tuned drawing.
- v1.3 Phase 18 P03 already shipped the archive-prior + supersede helper
  (`DrawingService::archivePrior`) — re-using it costs ~5 LOC vs. a fresh
  patch-merge implementation that would consume 1+ day of spike budget.
- patch-merge logic can be a v2.1 follow-up if anyone actually asks for it
  during real engineer use — YAGNI.

**Why small Teams Room only (D-LOCK-3, locked):**
- Most common archetype in 21CAV's last 12 months of quotes (highest signal
  per spike-day spent).
- Easiest visual comparison against the Lucidchart Extron Concept reference
  the user is benchmarking against.
- Speculatively scaffolding for the other 5 archetypes during a 2-week
  spike would: (a) inflate stencil count from 5 → 25+, (b) risk wrong
  architectural shape, (c) eat budget without informing the build-vs-buy
  decision.

**Why exactly 5 stencils (D-LOCK-4, locked):**
- Each stencil is hand-tuned visually — manufacturer logo + port rails +
  connector glyphs + brand-consistent styling. ~70% of spike's design
  effort lands here. 5 stencils ≈ 3 dev-days of careful work; 25 stencils
  ≈ 15 dev-days, blowing the 2-week budget on the wrong axis.
- 5 covers the canonical Teams Room signal chain: source → distribution →
  display + audio + control + connectivity. Enough surface to validate
  fidelity; not so much that visual issues hide in volume.

**Why stub port metadata, no AI extraction (D-LOCK-5, locked):**
- AI port extraction from datasheets is a v2.0 Phase 21 concern (per memory
  `v2_engineering_grade_drawings_plan.md`).
- Hand-coded port metadata is "real enough" for visual-fidelity testing —
  the spike's pass criterion is visual comparison against Lucidchart, not
  port-routing correctness.
- Eliminates AI cost + AI flakiness from the spike's measurement, so
  results unambiguously indict draw.io fidelity (good or bad), not the AI
  layer.

**Why reuse `canvas_state` instead of new `mxgraph_xml` column (D-LOCK-8 nuance):**
- CONTEXT.md says "Existing canvas_state column repurposed if compatible".
  `canvas_state` is `mediumText` (16 MB ceiling), nullable, in `$fillable`,
  and was added in v1.3 Phase 17 P01 with PITFALLS.md MOD-05 explicitly
  earmarking it for "Konva scene graph for user edits" — a near-identical
  shape to mxGraph XML.
- Reusing it: zero migration on live, zero `php artisan migrate` step
  during the user's local-edit-then-upload deploy, zero risk of column-
  conflict on the existing v1.3 row.
- `ProjectDrawing::hasUserEdits()` already returns `! empty($this->canvas_state)`
  — that's our D-LOCK-2 lock-on-edit trigger gratis.
- If the spike succeeds and v2.0 goes ahead, a future refactor can rename
  `canvas_state` → `editor_state` for clarity. Today, ship.

**Why admin-only surface, not linked from project page (D-LOCK-7, locked):**
- Engineers MUST NOT see this during the 2-week evaluation — it's an
  unfinished prototype with 5 devices and one archetype; exposing it would
  confuse the production drawings UX they already trust.
- `Route::middleware('admin')` group already exists (line 179). Drop the
  3 new routes in there; instant admin gate, zero new middleware code.
- Engineers will get the v2.0 milestone version (or a vendor alternative)
  if the spike succeeds — that's when the user-facing rollout happens.
</decision_rationale>

<spike_success_criteria>
## End-of-week-2 evaluation checklist (transcribed verbatim from CONTEXT.md)

The user evaluates these to decide green-light v2.0 vs. fall-back to
Lucidchart API / XTEN-AV. Executor and any later evaluator should have
this list visible at SUMMARY.md time.

1. **Visual fidelity** — side-by-side with Lucidchart Extron Concept
   reference, does the rendered Teams Room schematic look "same league"?
2. **Round-trip integrity** — load XML → edit a device's position in
   the embed → save → reload page → exact same XML / SVG output
3. **Brand alignment** — Crestron + Sennheiser + Cisco + Samsung logos
   render correctly in their respective stencils
4. **Performance** — round-trip (load → edit → save → re-render) under
   3 seconds on dev machine
5. **Cost reality check** — actual time spent on the spike vs the
   planned 2 weeks. If it took 4+ weeks for one room with 5 stencils,
   the full v2.0 milestone estimate is wrong and should be re-scoped
   before commit.

## What "good" looks like at end of spike

- Engineer or PM can open `admin/drawings/draw-io-spike/{project}` for
  the prototype project
- Sees a Teams Room schematic auto-generated from the project's quote data
- The 5 stencils each show their port rails + manufacturer styling
- Engineer can drag a device, redraw a cable, save the change
- Reloading the page shows the saved state
- The visual output is benchmarkable against the Lucidchart reference —
  "yes that's engineering-grade" or "no, falls short here / here / here"

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
project_drawings table (canvas_state column)
```
</spike_success_criteria>

<tasks>

<task type="auto">
  <name>Task 1: Self-host draw.io vendor bundle (D-LOCK-1)</name>
  <files>
    public/vendor/drawio/                (new directory — committed bundle, several MB)
    public/vendor/drawio/VERSION.md      (new file — version pin + license + update procedure)
  </files>
  <action>
Per D-LOCK-1, vendor a pinned draw.io release bundle into `public/vendor/drawio/`
so the embed loads same-origin. NO CDN reference anywhere in the spike.

**Steps:**

1. Determine the latest stable draw.io release tag from
   https://github.com/jgraph/drawio/releases (Apache 2.0 license). Pick the
   latest non-prerelease tag (e.g. `v25.x.x` or whatever is current at
   execution time). Document the EXACT tag chosen.

2. Download the release source archive (zip OR tar.gz) and extract. The
   draw.io repo ships its embed.html + all required JS/CSS/img assets in the
   `src/main/webapp/` subtree (path: `src/main/webapp/`).

3. Copy ONLY the embed-runtime subset into `public/vendor/drawio/` (not the
   server-side / Java pieces — they're not used by the iframe-embed flow).
   Minimum required folders/files (in order of certainty):
     - `src/main/webapp/embed.html`     → `public/vendor/drawio/embed.html`
     - `src/main/webapp/js/`            → `public/vendor/drawio/js/`
     - `src/main/webapp/styles/`        → `public/vendor/drawio/styles/`
     - `src/main/webapp/images/`        → `public/vendor/drawio/images/`
     - `src/main/webapp/resources/`     → `public/vendor/drawio/resources/`
     - `src/main/webapp/shapes/`        → `public/vendor/drawio/shapes/`
     - `src/main/webapp/stencils/`      → `public/vendor/drawio/stencils/`
     - `src/main/webapp/mxgraph/`       → `public/vendor/drawio/mxgraph/`
     - `src/main/webapp/connect/` (if it exists at the chosen tag — copy if present, skip if not)

   If the repo layout has changed at the chosen tag and a folder name above
   doesn't exist, copy what IS present from `src/main/webapp/` and note the
   deviation in VERSION.md. The pass criterion is: hitting
   `http://localhost:8000/vendor/drawio/embed.html` in a browser shows
   the editor with no 404s in the Network tab.

4. Create `public/vendor/drawio/VERSION.md` with EXACTLY this content
   (substitute real values for `{...}` placeholders):

   ```markdown
   # draw.io vendored bundle

   **Source:** https://github.com/jgraph/drawio
   **Version (tag):** {exact git tag, e.g. v25.0.5}
   **Downloaded:** {YYYY-MM-DD of the download}
   **Downloaded by:** Quick task 260509-ibx (draw.io embed spike)
   **License:** Apache 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
   **License caveat:** mxGraph (the underlying library) is also Apache 2.0,
   but JGraph's commercial library has a clause restricting use in
   "competing diagram editor products". Internal AV-tool use is fine; flag
   if 21CAV ever spins out a tools-as-product business.

   ## Update procedure

   Manual review + replace, NEVER auto-update.

   1. Read the upstream CHANGELOG between the current pinned tag and the new
      tag, paying attention to any breaking changes in mxGraph stencil
      schema, postMessage protocol, or embed.html query parameters.
   2. Re-run the spike's stencil pack through the new version locally before
      replacing on the server.
   3. Replace the contents of public/vendor/drawio/ with the new bundle.
   4. Update Version + Downloaded + Downloaded by in this file.
   5. Smoke-test the spike route in a fresh browser session (cache busted).

   ## What's in this folder

   - embed.html — entry point loaded by the iframe in
     resources/views/admin/drawings/draw-io-spike.blade.php
   - js/, styles/, images/, resources/, shapes/, stencils/, mxgraph/ —
     supporting assets the embed.html needs at runtime.

   ## What's NOT in this folder

   - Server-side Java sources (war/, etc/)
   - Build tooling (Gruntfile, package.json from upstream)
   - Documentation, tests, screenshots from the upstream repo

   The intent is to ship the smallest possible runtime subset that lets
   `/vendor/drawio/embed.html` load fully in an iframe.
   ```

5. Confirm the bundle is committed to git in Task 1's commit (D-LOCK-1
   "downloaded once, committed to repo"). Repo size hit is a one-time cost;
   reproducibility wins.

**What NOT to do:**

- Do NOT reference the diagrams.net CDN (`https://embed.diagrams.net`) anywhere.
  Same-origin is the whole point of D-LOCK-1.
- Do NOT add a draw.io npm or composer package — vendored static bundle only
  (per scope constraints).
- Do NOT auto-fetch the bundle in a Composer post-install script. Manual
  download + commit only, per D-LOCK-1 "manual review + replace".
- Do NOT include the server-side Java pieces from the upstream repo. The
  iframe embed only needs the static webapp subset.
  </action>
  <verify>
    <automated>
      # 1. Bundle landed at expected path (run from project root)
      test -f public/vendor/drawio/embed.html && echo "OK: embed.html exists"

      # 2. VERSION.md exists with required keys
      grep -q "Version (tag):" public/vendor/drawio/VERSION.md \
        && grep -q "License: Apache 2.0" public/vendor/drawio/VERSION.md \
        && grep -q "Update procedure" public/vendor/drawio/VERSION.md \
        && echo "OK: VERSION.md complete"

      # 3. No CDN references introduced anywhere in the bundle's HTML
      ! grep -r "embed.diagrams.net" public/vendor/drawio/embed.html && echo "OK: no CDN reference"

      # 4. Manual browser check (developer runs once, records result):
      #    - Start `php artisan serve` (or use Herd's site URL)
      #    - Open http://{site}/vendor/drawio/embed.html in a browser
      #    - Confirm: editor surface visible, NO 4xx/5xx in Network tab,
      #      mxgraph assets load
      #    Record outcome in the SUMMARY.md verification block.
    </automated>
  </verify>
  <done>
- `public/vendor/drawio/embed.html` returns the editor when loaded via the
  Laravel dev server (manual browser check, recorded in SUMMARY.md)
- `public/vendor/drawio/VERSION.md` contains exact upstream tag, download
  date, Apache 2.0 license note, and the manual-update procedure
- Bundle committed to git (one commit, prefix `feat(drawio-spike-260509-ibx):`)
- No CDN reference anywhere in the bundle's HTML files (verified by grep)
- No npm/composer package additions (verified: package.json + composer.json
  diffs are empty)
  </done>
</task>

<task type="auto">
  <name>Task 2: Stencil pack for the 5 spike devices (D-LOCK-4 + D-LOCK-5)</name>
  <files>
    resources/data/draw-io-stencils/21cav-mtr-spike.json   (new — 5-stencil mxGraph definition pack)
    public/img/manufacturers/                              (created if absent — destination for SVG logos)
    public/img/manufacturers/neat.svg                      (new — manufacturer logo, hand-drawn or sourced under permissive license)
    public/img/manufacturers/samsung.svg                   (new)
    public/img/manufacturers/clickshare.svg                (new — Barco's ClickShare wordmark)
    public/img/manufacturers/sennheiser.svg                (new)
    public/img/manufacturers/netgear.svg                   (new)
  </files>
  <action>
Per D-LOCK-4 + D-LOCK-5, hand-build a 5-stencil mxGraph definition pack
covering exactly the canonical small Teams Room signal chain. ~70% of the
spike's visual-fidelity outcome rides on how good these 5 stencils look —
this is where engineering-grade visual quality wins or loses against the
Lucidchart reference.

**The five stencils (no more, no fewer — D-LOCK-4):**

| Stencil ID                    | Manufacturer  | Model                                        | Role            |
|-------------------------------|---------------|----------------------------------------------|-----------------|
| `21cav.mtr.neat-bar-pro`      | Neat          | Bar Pro                                      | videobar        |
| `21cav.mtr.samsung-display`   | Samsung       | 65"/75" QM65C-T or BE65C-H (interactive opt) | display         |
| `21cav.mtr.clickshare-bar-pro`| Barco         | ClickShare Bar Pro                           | BYOD wireless   |
| `21cav.mtr.sennheiser-tcc`    | Sennheiser    | TeamConnect Ceiling 2 (TCC2)                 | ceiling mic     |
| `21cav.mtr.netgear-poe-12pt`  | Netgear       | GS312TP (12-port PoE+ Gigabit managed)       | network switch  |

**Each stencil MUST include:**

- **Manufacturer logo** (top of the card, ~16px tall — referenced from
  `/img/manufacturers/{name}.svg` via `<image>` element inside the stencil
  background, OR inline `<svg>` if the logo is small enough). Stencils
  reference logos via the URL `/img/manufacturers/{name}.svg` so the
  vendored bundle and the manufacturer logos sit in different folders
  (separation of concerns: the bundle is upstream-managed, logos are 21CAV-
  authored).
- **Generic name + model number** (e.g. "Neat Bar Pro" / "Samsung QM65C-T")
  rendered as styled text in the card body.
- **Port rails** with hand-coded ports — inputs on the LEFT edge, outputs on
  the RIGHT edge. Each port carries metadata: `{id, label, type, direction}`
  where `type` ∈ {hdmi, usb-c, usb-a, rj45, rj45-poe, displayport, optical-
  audio, audio-3.5mm, line-in, mic-in, lan, antenna}.
- **Connector glyphs** at each port (small icons/letters: HDMI, USB-C, RJ45,
  etc.) — these are what make the stencil read as "engineering-grade" rather
  than "marketing diagram".
- **Brand-consistent 21CAV styling** — teal `#1B7A7A` heading bar, off-white
  `#FAFAF6` cream card body, brand orange `#C07000` for accent strokes
  (matches the v1.3 RAMS / Mini O&M Tier 1 palette so the spike looks like
  it belongs to this app).

**Hand-coded port metadata per stencil (D-LOCK-5 — no AI):**

Use these as the canonical lists. They are "real enough" for the visual-
fidelity test even where they may be slightly off vs. each device's real
spec sheet. The pass criterion is "looks engineering-grade", not "ports
match the manufacturer's PDF byte-for-byte" — that's a v2.0 concern.

- **Neat Bar Pro:** 1× HDMI in (PC), 1× HDMI out (Display), 1× USB-C in/out
  (BYOD/host), 1× RJ45 LAN, 1× power, 1× 3.5mm audio out (aux)
- **Samsung 65"/75" display:** 3× HDMI in (HDMI 1/2/3), 1× DisplayPort in,
  1× optical audio out, 1× LAN, 1× USB host, 1× 3.5mm audio out, 1× power
- **ClickShare Bar Pro:** 1× HDMI in (host), 1× HDMI out (display), 2× USB-C
  in (BYOD), 1× LAN, 1× analogue audio in/out, 1× power
- **Sennheiser TCC2:** 1× RJ45 PoE+ (Dante / network audio), 1× analogue
  audio out (line-level), 1× control LAN, 1× firmware-update USB-C
- **Netgear GS312TP (12-port PoE+):** 12× RJ45 PoE+ (numbered 1–12), 1×
  console port, 1× power, status LEDs row (decorative — not "wired" ports)

**Stencil JSON shape (mxGraph):**

draw.io's stencil format is XML inside an mxGraph stencil shape definition.
Two options the executor chooses between by visual outcome:

  (a) Hand-write each stencil as a draw.io shape XML (the format described
      at https://www.drawio.com/doc/faq/shape-complex-create) embedded in
      a JSON wrapper, OR
  (b) Use simple HTML/SVG composition inside a card-shaped mxCell with
      child cells for the port rails (less powerful but faster to author,
      and may be enough at spike fidelity).

The executor SHOULD start with (b) for one stencil, evaluate visually
against the brand requirement, and escalate to (a) only if (b) doesn't
land "engineering-grade". Time-box this: if (a) becomes a 1-day rabbit
hole on stencil 1 of 5, fall back to (b) and document in the spike SUMMARY.

**JSON file structure** (`resources/data/draw-io-stencils/21cav-mtr-spike.json`):

```json
{
  "pack_id": "21cav.mtr.spike",
  "pack_name": "21CAV — Small Teams Room Spike (260509-ibx)",
  "pack_version": "0.1.0",
  "license": "Internal use only — 21CAV — Apache 2.0 mxGraph base, hand-coded stencil definitions",
  "stencils": [
    {
      "id": "21cav.mtr.neat-bar-pro",
      "manufacturer": "Neat",
      "model": "Bar Pro",
      "role": "videobar",
      "logo_url": "/img/manufacturers/neat.svg",
      "ports": [
        {"id": "hdmi-in",  "label": "HDMI IN",  "type": "hdmi",  "direction": "in",  "side": "left", "y_pct": 0.20},
        {"id": "hdmi-out", "label": "HDMI OUT", "type": "hdmi",  "direction": "out", "side": "right", "y_pct": 0.20},
        {"id": "usb-c",    "label": "USB-C",    "type": "usb-c", "direction": "io",  "side": "left", "y_pct": 0.45},
        {"id": "lan",      "label": "LAN",      "type": "rj45",  "direction": "io",  "side": "right", "y_pct": 0.45},
        {"id": "audio-out","label": "AUX OUT",  "type": "audio-3.5mm", "direction": "out", "side": "right", "y_pct": 0.70},
        {"id": "power",    "label": "PWR",      "type": "power", "direction": "in",  "side": "left", "y_pct": 0.85}
      ],
      "mxgraph_shape_xml": "<shape ...>...</shape>",
      "default_size": {"w": 220, "h": 140}
    },
    { "id": "21cav.mtr.samsung-display", ...},
    { "id": "21cav.mtr.clickshare-bar-pro", ...},
    { "id": "21cav.mtr.sennheiser-tcc", ...},
    { "id": "21cav.mtr.netgear-poe-12pt", ...}
  ]
}
```

The `mxgraph_shape_xml` field is what the embed loads at run-time; the
other fields (ports, logo_url, default_size) are consumed by the builder
service in Task 3 to wire devices to the right stencil + right port for
cable terminations.

**Manufacturer logos (`public/img/manufacturers/*.svg`):**

- For each of the 5 manufacturers, source a permissively-licensed SVG
  wordmark / logomark, OR hand-draw a simple wordmark in the brand colour
  if licensing is unclear (the spike is internal-only — D-LOCK-7 — so
  internal use is fine; flag any commercial trademark concern in the
  spike SUMMARY for v2.0 to resolve before any external distribution).
- Keep file size <8 KB each. ViewBox sized so the logo renders crisp at
  16px tall (the stencil header bar height).
- If sourcing from manufacturer press kits, document the source URL in a
  comment at the top of each SVG so the spike SUMMARY can list provenance.

**Brand consistency check:**

Every stencil MUST use the v1.3 palette so the spike looks native to the
app rather than a foreign tool dropped in:

  - Heading bar / title text: `#1B7A7A` (brand teal)
  - Card body fill:           `#FAFAF6` (cream)
  - Accent / port-rail strokes: `#C07000` (brand orange) — sparingly
  - Connector-glyph icon strokes: `#333333` (near-black)
  - Default font: Figtree (matches the rest of the app per CLAUDE.md
    Tailwind config). Fallback to Inter / system-ui if Figtree isn't
    embedded in the SVG-rendered stencil.

**What NOT to do:**

- Do NOT introduce a 6th stencil "for completeness" — D-LOCK-4 locks 5.
- Do NOT pull port metadata from a datasheet via AI — D-LOCK-5 locks
  hand-coded JSON only.
- Do NOT add stencils for the boardroom / huddle / classroom / divisible /
  townhall archetypes — D-LOCK-3 locks Teams Room only.
- Do NOT make the stencils generic / "any device" — the WHOLE POINT of
  this task is engineering-grade visual specificity. A generic rectangle
  fails the spike.
  </action>
  <verify>
    <automated>
      # 1. JSON file is valid + has exactly 5 stencils (no more, no fewer)
      php -r "
        \$data = json_decode(file_get_contents('resources/data/draw-io-stencils/21cav-mtr-spike.json'), true);
        if (!is_array(\$data)) { fwrite(STDERR, 'INVALID JSON'); exit(1); }
        \$count = count(\$data['stencils'] ?? []);
        if (\$count !== 5) { fwrite(STDERR, \"Expected 5 stencils, got {\$count}\"); exit(1); }
        \$expected = ['21cav.mtr.neat-bar-pro','21cav.mtr.samsung-display','21cav.mtr.clickshare-bar-pro','21cav.mtr.sennheiser-tcc','21cav.mtr.netgear-poe-12pt'];
        \$ids = array_column(\$data['stencils'], 'id');
        sort(\$ids); sort(\$expected);
        if (\$ids !== \$expected) { fwrite(STDERR, 'Stencil IDs do not match D-LOCK-4 list'); exit(1); }
        echo 'OK: 5 stencils, IDs match D-LOCK-4';
      "

      # 2. Every stencil has the required keys (D-LOCK-5 hand-coded port metadata)
      php -r "
        \$data = json_decode(file_get_contents('resources/data/draw-io-stencils/21cav-mtr-spike.json'), true);
        foreach (\$data['stencils'] as \$s) {
          foreach (['id','manufacturer','model','role','logo_url','ports','mxgraph_shape_xml','default_size'] as \$key) {
            if (!array_key_exists(\$key, \$s)) { fwrite(STDERR, \"Stencil {\$s['id']} missing key {\$key}\"); exit(1); }
          }
          if (count(\$s['ports']) === 0) { fwrite(STDERR, \"Stencil {\$s['id']} has zero ports\"); exit(1); }
        }
        echo 'OK: all stencils have required keys + nonzero ports';
      "

      # 3. Manufacturer logos exist
      for f in neat samsung clickshare sennheiser netgear; do
        test -f "public/img/manufacturers/${f}.svg" || { echo "MISSING: ${f}.svg"; exit 1; }
      done
      echo "OK: 5 manufacturer SVGs present"

      # 4. Visual check (manual — recorded in SUMMARY.md):
      #    - Open one of the stencils in a draw.io scratch test:
      #      load embed.html, paste a single-stencil mxGraph XML into the
      #      load message, screenshot the result.
      #    - Compare side-by-side with the Lucidchart Extron Concept reference.
      #    - "Same league" = pass; "obviously child's-drawing" = fail (split
      #      the task, escalate stencil format from (b) HTML/SVG card to
      #      (a) full mxGraph shape XML, redo).
    </automated>
  </verify>
  <done>
- `resources/data/draw-io-stencils/21cav-mtr-spike.json` exists with EXACTLY
  5 stencils matching the D-LOCK-4 IDs
- Every stencil has: id, manufacturer, model, role, logo_url, ports[],
  mxgraph_shape_xml, default_size — verified by automated check
- Each stencil has at least 1 port with the documented hand-coded metadata
  shape — verified by automated check
- 5 manufacturer SVG logos sit at `public/img/manufacturers/{name}.svg`
- Brand palette (#1B7A7A teal, #FAFAF6 cream, #C07000 orange) used
  consistently across all 5 stencils — verified by visual inspection,
  recorded in SUMMARY.md
- Manual browser check: load one stencil into a scratch draw.io session via
  postMessage; visual outcome captured in SUMMARY.md as "looks engineering-
  grade" (or "needs upgrade to (a) full mxGraph shape XML — escalating")
- Single atomic commit `feat(drawio-spike-260509-ibx): hand-coded 5-stencil pack for small Teams Room`
  </done>
</task>

<task type="auto">
  <name>Task 3: DrawIoSpikeBuilderService — deterministic mxGraph XML emitter (D-LOCK-5 + D-LOCK-6)</name>
  <files>
    app/Services/Drawings/DrawIoSpikeBuilderService.php   (new)
  </files>
  <action>
Per D-LOCK-5 (no AI) + D-LOCK-6 (real project hookup), build a pure-PHP
deterministic emitter that turns `ProjectPackage::extracted_data['equipment']`
filtered to a Teams Room area into mxGraph XML referencing the Task 2
stencil pack.

**File:** `app/Services/Drawings/DrawIoSpikeBuilderService.php`

**Class shape:**

```php
namespace App\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectPackage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Quick task 260509-ibx — draw.io embed spike.
 *
 * Pure data aggregator → mxGraph XML emitter for the small Teams Room
 * archetype (D-LOCK-3). NO AI usage (D-LOCK-5). NO Eloquent writes.
 * NO HTTP calls. Hand the same Project twice → same mxGraph XML twice
 * (idempotent).
 *
 * Pipeline: ProjectPackage.extracted_data['equipment']
 *           → filter to Teams Room area
 *           → map each equipment item to a stencil ID via name/part-number fragments
 *           → emit mxGraph XML positioned in a deterministic grid
 *           → derive cables from cable_list OR signal_role inference
 *           → return string ready for postMessage(action: load) into the embed iframe
 *
 * Mirrors SchematicD2SourceBuilder shape (Phase 17 P02) — same first-match-wins
 * fragment-mapping idea, different output target (mxGraph XML, not D2 source).
 *
 * @see app/Services/Drawings/SchematicD2SourceBuilder.php — reference pattern.
 * @see resources/data/draw-io-stencils/21cav-mtr-spike.json — stencil pack consumed.
 * @see .planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-CONTEXT.md
 */
class DrawIoSpikeBuilderService
{
    /**
     * First-match-wins fragment → stencil-id allowlist.
     *
     * Mirrors SchematicD2SourceBuilder::SYMBOL_ALIASES shape but maps to
     * mxGraph stencil IDs from the 21cav-mtr-spike pack instead of D2
     * SVG filenames. Order matters — more specific fragments come first.
     *
     * @var array<string, string>
     */
    private const STENCIL_ALIASES = [
        // Most specific first
        'neat bar pro'         => '21cav.mtr.neat-bar-pro',
        'neat bar'             => '21cav.mtr.neat-bar-pro',
        'clickshare bar pro'   => '21cav.mtr.clickshare-bar-pro',
        'clickshare'           => '21cav.mtr.clickshare-bar-pro',
        'teamconnect ceiling'  => '21cav.mtr.sennheiser-tcc',
        'tcc2'                 => '21cav.mtr.sennheiser-tcc',
        'sennheiser'           => '21cav.mtr.sennheiser-tcc',
        // Generic match catches the family
        'samsung'              => '21cav.mtr.samsung-display',
        'display'              => '21cav.mtr.samsung-display',
        'tv'                   => '21cav.mtr.samsung-display',
        'screen'               => '21cav.mtr.samsung-display',
        'monitor'              => '21cav.mtr.samsung-display',
        'gs312tp'              => '21cav.mtr.netgear-poe-12pt',
        'netgear'              => '21cav.mtr.netgear-poe-12pt',
        'poe switch'           => '21cav.mtr.netgear-poe-12pt',
        'network switch'       => '21cav.mtr.netgear-poe-12pt',
    ];

    /**
     * Build the mxGraph XML for a project's small Teams Room equipment.
     *
     * Reads $project->latestPackage->extracted_data['equipment'], filters to
     * the Teams Room area (case-insensitive substring match on 'teams' OR
     * 'meeting room' OR 'mtr' — picks the FIRST area whose name matches,
     * deterministic ordering by area name asc), maps each equipment item
     * to a stencil from the pack, lays them out in a deterministic grid
     * (3 columns × N rows; column 0 = sources/inputs, column 1 = central
     * processing, column 2 = displays/outputs based on signal_role
     * inference), draws cables.
     *
     * Returns mxGraph XML wrapped in <mxGraphModel><root>...</root></mxGraphModel>
     * shape that draw.io's embed.html accepts via postMessage({action:'load',xml}).
     *
     * Empty / no-package case: returns a valid empty <mxGraphModel> so the
     * embed loads with no devices but no error.
     */
    public function build(Project $project): string
    {
        $package = $project->latestPackage;
        if ($package === null) {
            Log::info('DrawIoSpikeBuilderService: no latest package — emitting empty graph', [
                'project_id' => $project->id,
            ]);
            return $this->emptyGraph();
        }

        $equipment = (array) ($package->extracted_data['equipment'] ?? []);
        if (empty($equipment)) {
            $equipment = (array) ($package->equipment_list ?? []);   // fallback
        }

        $teamsRoomItems = $this->filterToTeamsRoom($equipment);
        $deviceCells    = $this->mapEquipmentToCells($teamsRoomItems);
        $cableCells     = $this->deriveCables($deviceCells, (array) ($package->cable_list ?? []));

        return $this->emitMxGraph($deviceCells, $cableCells);
    }

    /**
     * Filter the equipment list to items in a Teams Room area.
     *
     * Match logic (case-insensitive substring):
     *   - area contains 'teams' OR
     *   - area contains 'meeting room' OR
     *   - area contains 'mtr' OR
     *   - room/area is unset AND we found nothing else (last-resort —
     *     dump everything so the spike still has something to render
     *     on legacy projects without proper area tagging)
     *
     * @param array<int, array<string, mixed>> $equipment
     * @return array<int, array<string, mixed>>
     */
    private function filterToTeamsRoom(array $equipment): array
    {
        // Implementation: iterate, lowercase the area, substring match.
    }

    /**
     * Map each equipment line to a placed mxCell descriptor.
     *
     * Returns a list of:
     *   [
     *     'cell_id'     => 'dev-1',
     *     'stencil_id'  => '21cav.mtr.neat-bar-pro',
     *     'label'       => 'Neat Bar Pro',
     *     'part_number' => 'NEAT-BAR-PRO',
     *     'x' => 100, 'y' => 100, 'w' => 220, 'h' => 140,
     *     'role' => 'videobar',  // inferred from stencil's role field for cable direction
     *   ]
     *
     * Fragment match: lowercase the equipment name + part number, find
     * first-match-wins entry from STENCIL_ALIASES. Items that don't match
     * any fragment are SKIPPED with a Log::info — D-LOCK-3 locks 5
     * stencils for the small Teams Room only; rendering a generic
     * placeholder for unmapped items would fail the visual-fidelity test.
     *
     * Layout: simple deterministic 3-column grid:
     *   - column 0 (x=80):  sources, BYOD, microphones (videobar/clickshare/tcc)
     *   - column 1 (x=380): switch, processors (network-switch)
     *   - column 2 (x=680): displays (samsung-display)
     * Rows stack at y = 80 + (rowIndex * 200).
     *
     * @param array<int, array<string, mixed>> $equipment
     * @return list<array<string, mixed>>
     */
    private function mapEquipmentToCells(array $equipment): array
    {
        // Implementation: load stencil pack JSON via file_get_contents +
        // json_decode (cached as a static for the request), iterate over
        // equipment, find first-match-wins alias, allocate grid slot,
        // expand by quantity (1 device per quantity unit; quantity > 5
        // capped at 5 for spike sanity — log a warning for v2.0).
    }

    /**
     * Derive cable mxCells from cable_list when present, else infer
     * default cables from the signal-role chain typical of a small
     * Teams Room:
     *
     *   videobar.hdmi-out   → display.hdmi-1
     *   byod.hdmi-out       → videobar.hdmi-in
     *   ceiling-mic.lan     → switch.port-1
     *   videobar.lan        → switch.port-2
     *   display.lan         → switch.port-3
     *
     * Each emitted as an mxCell edge with source/target referencing the
     * device's cell_id and a port id from the stencil's port list.
     *
     * @param list<array<string, mixed>> $deviceCells
     * @param array<int, array<string, mixed>> $cableList
     * @return list<array<string, mixed>>
     */
    private function deriveCables(array $deviceCells, array $cableList): array
    {
        // Implementation: prefer cable_list when populated; fall back to
        // canonical Teams Room signal chain. Both produce mxCell edge
        // descriptors of shape:
        //   ['edge_id'=>'cab-1','source'=>'dev-1','target'=>'dev-2',
        //    'source_port'=>'hdmi-out','target_port'=>'hdmi-in',
        //    'signal_type'=>'video']
    }

    /**
     * Emit the full mxGraph XML document.
     *
     * Shape:
     *   <mxGraphModel dx="..." dy="..." grid="1" gridSize="10" ...>
     *     <root>
     *       <mxCell id="0"/>
     *       <mxCell id="1" parent="0"/>
     *       <!-- one mxCell vertex per device, referencing stencil_id via shape= -->
     *       <!-- one mxCell edge per cable -->
     *     </root>
     *   </mxGraphModel>
     *
     * Uses XML escaping on every label / part_number — stencil IDs are
     * application-controlled so safe; equipment names from QuoteWerks are
     * untrusted (Warning 7 / T-17.02-01 from Phase 17 P02 carries forward).
     */
    private function emitMxGraph(array $deviceCells, array $cableCells): string
    {
        // Implementation: simple sprintf-driven XML with htmlspecialchars
        // on every user-data interpolation. NO DOMDocument needed for the
        // spike — string concat is simpler and the output shape is fixed.
    }

    private function emptyGraph(): string
    {
        return <<<'XML'
<mxGraphModel dx="800" dy="600" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1169" pageHeight="826" math="0" shadow="0">
  <root>
    <mxCell id="0"/>
    <mxCell id="1" parent="0"/>
  </root>
</mxGraphModel>
XML;
    }
}
```

**Defensive guards (mirror v1.3 patterns):**

- Treat missing `latestPackage` as "emit empty graph" (no exception). The
  spike must render even on projects without a quote import yet.
- Treat missing `extracted_data['equipment']` as falling back to
  `equipment_list`. Older projects landed equipment via either path.
- Cap quantity expansion at 5 per line (a single 12-port switch line with
  quantity=12 would otherwise paint 12 switches; log a warning).
- Skip equipment that doesn't match any STENCIL_ALIASES fragment (log
  Info, not Warning — D-LOCK-3 locks 5 stencils so unmapped items are
  expected to be skipped).

**Pipeline integrity (do NOT change):**

- DO NOT modify `SchematicGeneratorService` / `SchematicD2SourceBuilder` /
  `DrawingDataResolverService`. The v1.3 D2 schematic pipeline runs
  unchanged. This new builder is additive.
- DO NOT add a route, controller, or job — Task 4 owns the controller; this
  is a pure service.

**XML safety:**

Every interpolated string from QuoteWerks data MUST be passed through
`htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8')` before being placed
in attribute or text positions. Equipment names like `O'Brien & Co. — "AV"`
will otherwise break the mxGraph XML parser inside the embed.

**Linting:**

Run `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawIoSpikeBuilderService.php`
before committing. Must report `No syntax errors detected`.
  </action>
  <verify>
    <automated>
      # 1. Syntax check (Herd PHP 8.4 per project convention)
      "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawIoSpikeBuilderService.php

      # 2. Smoke test: builder produces valid XML for an empty project
      php artisan tinker --execute="
        \$p = new App\Models\Project(['id' => 99999]);
        \$svc = app(App\Services\Drawings\DrawIoSpikeBuilderService::class);
        \$xml = \$svc->build(\$p);
        if (strpos(\$xml, '<mxGraphModel') === false) { fwrite(STDERR, 'No mxGraphModel root'); exit(1); }
        if (simplexml_load_string(\$xml) === false) { fwrite(STDERR, 'Invalid XML'); exit(1); }
        echo 'OK: empty-project XML valid (', strlen(\$xml), ' bytes)';
      "

      # 3. Smoke test: builder produces non-empty graph for a real project
      #    (executor picks one project ID with a Teams-Room area; substitute below)
      #    The test asserts: at least 1 mxCell vertex with a non-zero stencil id reference,
      #    AND every emitted XML parses with simplexml_load_string.
      php artisan tinker --execute="
        \$p = App\Models\Project::whereHas('packages')->latest('id')->first();
        if (!\$p) { echo 'SKIP: no project with package'; exit(0); }
        \$svc = app(App\Services\Drawings\DrawIoSpikeBuilderService::class);
        \$xml = \$svc->build(\$p);
        if (simplexml_load_string(\$xml) === false) { fwrite(STDERR, 'Invalid XML for real project'); exit(1); }
        echo 'OK: real project ', \$p->id, ' XML ', strlen(\$xml), ' bytes';
      "

      # 4. Determinism check: same project → same XML twice
      php artisan tinker --execute="
        \$p = App\Models\Project::whereHas('packages')->latest('id')->first();
        if (!\$p) { echo 'SKIP'; exit(0); }
        \$svc = app(App\Services\Drawings\DrawIoSpikeBuilderService::class);
        if (\$svc->build(\$p) !== \$svc->build(\$p)) { fwrite(STDERR, 'Non-deterministic'); exit(1); }
        echo 'OK: deterministic';
      "

      # 5. AI surface check: NO AI imports / calls in this service
      ! grep -E "AIManager|\\\\\\\\AI\\\\\\\\|AICache|AIUsage" app/Services/Drawings/DrawIoSpikeBuilderService.php \
        && echo 'OK: zero AI surface (D-LOCK-5)'
    </automated>
  </verify>
  <done>
- `app/Services/Drawings/DrawIoSpikeBuilderService.php` exists and `php -l` clean
- `build(Project $project): string` returns valid mxGraph XML for both empty
  and real projects (verified by tinker smoke tests)
- Determinism: same project → same XML byte-for-byte twice in a row
- Zero AI surface — `grep -E "AIManager|AICache|AIUsage"` returns no matches
- No modifications to `SchematicGeneratorService` / `SchematicD2SourceBuilder`
  / `DrawingDataResolverService` (verified: `git diff` on those 3 files = 0 lines)
- Single atomic commit `feat(drawio-spike-260509-ibx): deterministic mxGraph builder for Teams Room`
  </done>
</task>

<task type="auto">
  <name>Task 4: Admin spike route + controller + Blade with embed (D-LOCK-7)</name>
  <files>
    app/Http/Controllers/Admin/DrawIoSpikeController.php          (new)
    resources/views/admin/drawings/draw-io-spike.blade.php        (new)
    routes/web.php                                                (modified — 3 new admin routes added inside the existing admin group)
  </files>
  <action>
Per D-LOCK-7, add an admin-only Blade page that embeds the self-hosted draw.io
editor in an iframe and wires the postMessage protocol for load + save +
SVG export. Engineers MUST NOT see this — no link from any user-facing page.

**Routes (3 new, all inside the existing `Route::middleware('admin')` group
at `routes/web.php` ~line 179):**

```php
// ── Quick task 260509-ibx — draw.io embed spike (D-LOCK-7 admin-only) ──
// Sandbox surface — NOT linked from any user-facing Blade. Spike outcome
// drives the v2.0 build-vs-buy decision at end of week 2.
Route::get('admin/drawings/draw-io-spike/{project}',
    [\App\Http\Controllers\Admin\DrawIoSpikeController::class, 'show'])
    ->name('admin.drawings.draw-io-spike.show');
Route::post('admin/drawings/draw-io-spike/{project}/save',
    [\App\Http\Controllers\Admin\DrawIoSpikeController::class, 'saveXml'])
    ->name('admin.drawings.draw-io-spike.save');
Route::post('admin/drawings/draw-io-spike/{project}/export-svg',
    [\App\Http\Controllers\Admin\DrawIoSpikeController::class, 'exportSvg'])
    ->name('admin.drawings.draw-io-spike.export-svg');
```

The `admin` middleware group is already in place — these routes inherit
admin-gating without any new middleware code.

**Controller:** `app/Http/Controllers/Admin/DrawIoSpikeController.php`

```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\Drawings\DrawIoSpikeBuilderService;
use App\Services\Drawings\DrawingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Quick task 260509-ibx — admin-only draw.io embed spike controller.
 *
 * D-LOCK-7: admin middleware-gated, NOT linked from any user-facing page.
 * D-LOCK-1: spike Blade loads /vendor/drawio/embed.html (self-hosted).
 * D-LOCK-2: saveXml delegates to DrawingService::saveSpikeXml (Task 5)
 *           which honours lock-on-edit + archive-prior.
 * D-LOCK-6: builder reads real ProjectPackage::extracted_data.
 * D-LOCK-7: route prefix admin/drawings/draw-io-spike — admin middleware.
 *
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php
 * @see resources/views/admin/drawings/draw-io-spike.blade.php
 */
class DrawIoSpikeController extends Controller
{
    public function __construct(
        private readonly DrawIoSpikeBuilderService $builder,
        private readonly DrawingService $drawings,
    ) {}

    public function show(Project $project): View
    {
        // Resolve / create the spike drawing row for this project. Single
        // row per project (kind=schematic, sub-discriminated by source_data
        // ['spike']=true so it doesn't show up on the user-facing index).
        $drawing = $this->resolveOrCreateSpikeDrawing($project);

        // Initial XML: persisted canvas_state if engineer has edited
        // (post-lock state), else freshly-built from project data.
        $xml = $drawing->canvas_state ?: $this->builder->build($project);

        return view('admin.drawings.draw-io-spike', [
            'project'   => $project,
            'drawing'   => $drawing,
            'xml'       => $xml,
            'is_locked' => ! empty($drawing->canvas_state),
            'embed_url' => '/vendor/drawio/embed.html?embed=1&proto=json&libraries=1',
        ]);
    }

    public function saveXml(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'xml' => ['required', 'string', 'min:50', 'max:5242880'], // 5 MB cap
        ]);

        $drawing = $this->resolveOrCreateSpikeDrawing($project);
        $newRow  = $this->drawings->saveSpikeXml($drawing, $validated['xml'], (int) Auth::id());

        return response()->json([
            'ok'              => true,
            'drawing_id'      => $newRow->id,
            'version'         => $newRow->version,
            'previous_locked' => $drawing->id !== $newRow->id,
            'redirect'        => null, // engineer keeps editing in place
        ]);
    }

    public function exportSvg(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'svg' => ['required', 'string', 'min:50', 'max:5242880'],
        ]);

        $drawing = $this->resolveOrCreateSpikeDrawing($project);
        $path    = $this->drawings->saveSpikeSvg($drawing, $validated['svg']);

        return response()->json(['ok' => true, 'svg_path' => basename($path)]);
    }

    /**
     * One spike drawing row per project. source_data['spike'] = true is the
     * discriminator that excludes it from the user-facing index page (which
     * the index controller filters via whereJsonDoesntContain or similar in
     * Task 4 — but for the spike's window the index already filters by
     * superseded_by_id NULL so the spike row simply won't be linked from
     * anywhere — D-LOCK-7).
     */
    private function resolveOrCreateSpikeDrawing(Project $project): ProjectDrawing
    {
        // Find the most-recent non-superseded spike row for this project,
        // or create a new one with kind=schematic + source_data.spike=true.
        // Implementation note: avoid touching DrawingService::createForProject
        // because that would burn a sheet number from the AVIXA allocator —
        // wasteful for a sandbox row. Use ProjectDrawing::create directly.
        $existing = ProjectDrawing::query()
            ->where('project_id', $project->id)
            ->whereNull('superseded_by_id')
            ->where('kind', ProjectDrawing::KIND_SCHEMATIC)
            ->whereJsonContains('source_data->spike', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $row = ProjectDrawing::create([
            'project_id'         => $project->id,
            'site_survey_room_id' => null,
            'kind'               => ProjectDrawing::KIND_SCHEMATIC,
            'version'            => 1,
            'status'             => ProjectDrawing::STATUS_DRAFT,
            'source_data'        => ['spike' => true, 'spike_id' => '260509-ibx'],
            'generated_by'       => Auth::id(),
        ]);

        Log::info('DrawIoSpikeController: spike drawing created', [
            'drawing_id' => $row->id,
            'project_id' => $project->id,
        ]);

        return $row;
    }
}
```

**Blade view:** `resources/views/admin/drawings/draw-io-spike.blade.php`

```blade
@extends('layouts.app')

@section('content')
<div x-data="drawIoSpike({
    embedUrl: '{{ $embed_url }}',
    initialXml: @js($xml),
    saveUrl: '{{ route('admin.drawings.draw-io-spike.save', $project) }}',
    exportSvgUrl: '{{ route('admin.drawings.draw-io-spike.export-svg', $project) }}',
    csrf: '{{ csrf_token() }}',
    isLocked: {{ $is_locked ? 'true' : 'false' }},
})" class="px-6 py-4">

  <header class="flex items-center justify-between mb-3">
    <div>
      <h1 class="text-xl font-semibold text-[#1B7A7A]">draw.io Spike — {{ $project->name }}</h1>
      <p class="text-sm text-gray-600">
        Quick task 260509-ibx — admin sandbox.
        @if($is_locked)
          <span class="inline-block px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-800 ml-2">
            🔒 Engineer-edited (locked)
          </span>
        @else
          <span class="inline-block px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-700 ml-2">
            Auto-generated
          </span>
        @endif
      </p>
    </div>
    <div class="flex gap-2">
      <button @click="exportSvgNow()" class="btn-outline">📤 Export SVG</button>
      <a href="{{ route('projects.show', $project) }}" class="btn-outline">← Back to project</a>
    </div>
  </header>

  <iframe
    x-ref="embed"
    src="{{ $embed_url }}"
    @load="onEmbedReady()"
    class="w-full"
    style="height: calc(100vh - 140px); border: 1px solid #d1d5db; background: #fff;"
    allow="clipboard-read; clipboard-write"
  ></iframe>

  <div x-show="status" x-text="status"
       class="fixed bottom-4 right-4 px-3 py-2 rounded shadow"
       :class="statusKind === 'error' ? 'bg-red-600 text-white' : 'bg-[#1B7A7A] text-white'">
  </div>
</div>

@push('scripts')
<script>
function drawIoSpike(cfg) {
  return {
    embedUrl: cfg.embedUrl,
    initialXml: cfg.initialXml,
    saveUrl: cfg.saveUrl,
    exportSvgUrl: cfg.exportSvgUrl,
    csrf: cfg.csrf,
    isLocked: cfg.isLocked,
    status: '',
    statusKind: 'info',

    init() {
      // draw.io embed.html sends postMessage events to window. Listen here;
      // dispatch by event.data.event field (per
      // https://www.drawio.com/doc/faq/embed-mode).
      window.addEventListener('message', (e) => this.onMessage(e));
    },

    onEmbedReady() {
      // Editor is loaded but may not yet have processed the xml. The
      // 'init' event from the embed signals readiness; we wait for it.
    },

    onMessage(e) {
      // Filter to messages from our iframe only.
      if (!this.$refs.embed || e.source !== this.$refs.embed.contentWindow) return;
      let msg;
      try { msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; }
      catch { return; }
      if (!msg || !msg.event) return;

      switch (msg.event) {
        case 'init':
          this.postToEmbed({ action: 'load', xml: this.initialXml });
          break;
        case 'save':
          this.persistXml(msg.xml);
          break;
        case 'export':
          if (msg.format === 'xmlsvg' || msg.format === 'svg') {
            this.persistSvg(msg.data);
          }
          break;
        case 'autosave':
          // Treat autosaves the same as explicit saves — round-trip integrity test.
          this.persistXml(msg.xml);
          break;
      }
    },

    postToEmbed(payload) {
      this.$refs.embed.contentWindow.postMessage(JSON.stringify(payload), '*');
    },

    async persistXml(xml) {
      this.flash('Saving…', 'info');
      try {
        const r = await fetch(this.saveUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
          body: JSON.stringify({ xml }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        this.isLocked = true;
        this.flash(j.previous_locked ? 'Saved as new version' : 'Saved', 'info');
      } catch (err) { this.flash('Save failed: ' + err.message, 'error'); }
    },

    async persistSvg(svg) {
      try {
        const r = await fetch(this.exportSvgUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
          body: JSON.stringify({ svg }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        this.flash('SVG exported', 'info');
      } catch (err) { this.flash('SVG export failed: ' + err.message, 'error'); }
    },

    exportSvgNow() {
      this.postToEmbed({ action: 'export', format: 'xmlsvg' });
    },

    flash(msg, kind = 'info') {
      this.status = msg; this.statusKind = kind;
      setTimeout(() => { this.status = ''; }, 2500);
    },
  };
}
</script>
@endpush
@endsection
```

**Embed URL parameters explained:**

- `embed=1` — tells draw.io to run in embed mode (no native UI chrome).
- `proto=json` — postMessage payloads use JSON.
- `libraries=1` — engineer can open the stencils sidebar.

The embed protocol reference: https://www.drawio.com/doc/faq/embed-mode

**What NOT to do:**

- Do NOT add a link to this Blade from `projects/show.blade.php`,
  `projects/index.blade.php`, or `drawings/index.blade.php` — D-LOCK-7
  forbids any user-facing entry point.
- Do NOT put the spike route OUTSIDE the existing
  `Route::middleware('admin')` group — admin gating is non-negotiable.
- Do NOT register a sidebar nav link in `layouts/navigation.blade.php`.
- Do NOT load the stencil pack JSON into the page (Task 3's builder reads
  it server-side; the iframe doesn't need it directly because the mxGraph
  XML emitted by Task 3 already references the stencil shapes by ID).

**Linting:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Http/Controllers/Admin/DrawIoSpikeController.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l routes/web.php
```
  </action>
  <verify>
    <automated>
      # 1. Syntax checks
      "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Http/Controllers/Admin/DrawIoSpikeController.php
      "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l routes/web.php

      # 2. Routes are admin-gated (D-LOCK-7)
      php artisan route:list --columns=name,uri,middleware 2>/dev/null | grep "admin.drawings.draw-io-spike" \
        && echo "OK: routes registered"
      php artisan route:list --columns=name,middleware 2>/dev/null \
        | grep "admin.drawings.draw-io-spike" | grep "admin" \
        && echo "OK: admin middleware applied (D-LOCK-7)"

      # 3. No user-facing link added (D-LOCK-7 visibility check)
      ! grep -r "draw-io-spike" resources/views/projects/ resources/views/components/ resources/views/layouts/navigation.blade.php \
        && echo "OK: no user-facing link to spike"

      # 4. Manual browser check (recorded in SUMMARY.md):
      #    - Visit admin/drawings/draw-io-spike/{realProjectId} as admin user
      #    - Confirm: iframe loads, draw.io editor visible, initial XML
      #      auto-loaded showing devices from project's quote data, status
      #      pill shows "Auto-generated"
      #    - Drag a device, click File → Save (or hit Ctrl+S in the embed)
      #    - Confirm: status pill flashes "Saved", page reload shows the
      #      new position, status pill now reads "🔒 Engineer-edited (locked)"
    </automated>
  </verify>
  <done>
- 3 routes registered (`admin.drawings.draw-io-spike.show|save|export-svg`),
  all under `admin` middleware (verified by `route:list`)
- `app/Http/Controllers/Admin/DrawIoSpikeController.php` exists, `php -l`
  clean, has 3 public methods (show/saveXml/exportSvg) + `resolveOrCreateSpikeDrawing` helper
- `resources/views/admin/drawings/draw-io-spike.blade.php` exists with
  iframe pointed at `/vendor/drawio/embed.html?embed=1&proto=json&libraries=1`
  and the documented postMessage protocol
- Manual browser check passes — XML loads, drag works, save round-trips
  (recorded in SUMMARY.md)
- Zero user-facing links — `grep -r "draw-io-spike" resources/views/projects/
  resources/views/components/ resources/views/layouts/` returns empty
- Single atomic commit `feat(drawio-spike-260509-ibx): admin embed Blade + 3 routes (D-LOCK-7)`
  </done>
</task>

<task type="auto">
  <name>Task 5: Storage + lock-on-edit (D-LOCK-2 + D-LOCK-8) — saveSpikeXml + saveSpikeSvg</name>
  <files>
    app/Services/Drawings/DrawingService.php   (modified — adds saveSpikeXml() + saveSpikeSvg() methods)
  </files>
  <action>
Per D-LOCK-2 (lock-on-edit + archive-prior, mirroring v1.3) + D-LOCK-8
(canvas_state column reused — NO migration), extend
`app/Services/Drawings/DrawingService.php` with two new methods:

1. `saveSpikeXml(ProjectDrawing $drawing, string $xml, int $userId): ProjectDrawing`
2. `saveSpikeSvg(ProjectDrawing $drawing, string $svg): string`

**No new column. No migration. No new file (extending existing service so
the spike sits next to the v1.3 archive-prior pattern that mirrors it).**

**Method 1: `saveSpikeXml` — lock-on-edit + archive-prior**

```php
/**
 * Quick task 260509-ibx — persist a draw.io spike's mxGraph XML edit.
 *
 * Lock-on-edit policy (D-LOCK-2, mirrors v1.3 Phase 18 P03 archive-prior):
 *   - First save (canvas_state empty): write XML directly to the row's
 *     canvas_state column. The act of writing flips the lock — subsequent
 *     calls to ProjectDrawing::hasUserEdits() return true.
 *   - Subsequent save (canvas_state populated): replicate row, bump version,
 *     write the new XML to the new row, archive the prior row via
 *     archivePrior() (sets STATUS_SUPERSEDED + superseded_by_id link).
 *
 * Wrapped in DB::transaction so a failure rolls back BOTH the new row and
 * the supersede flip. Uses canvas_state (mediumText, 16 MB) per D-LOCK-8 —
 * existing column added in Phase 17 P01 with PITFALLS.md MOD-05 explicitly
 * earmarking it for "Konva scene graph for user edits", a near-identical
 * shape to mxGraph XML. NO migration.
 *
 * Returns the row that now holds the saved XML — same row on first save,
 * a new versioned row on subsequent saves.
 *
 * @see DrawingService::archivePrior() — supersede helper called inside the txn.
 * @see ProjectDrawing::hasUserEdits()  — lock-state predicate.
 */
public function saveSpikeXml(ProjectDrawing $drawing, string $xml, int $userId): ProjectDrawing
{
    if (! $drawing->hasUserEdits()) {
        // ── First save — direct write, no archive-prior. ────────────────
        $drawing->update([
            'canvas_state' => $xml,
            'generated_by' => $userId,
        ]);

        Log::info('DrawingService: spike XML first-save (lock flip)', [
            'drawing_id' => $drawing->id,
            'project_id' => $drawing->project_id,
            'xml_bytes'  => strlen($xml),
        ]);

        return $drawing;
    }

    // ── Subsequent save — replicate + bump + archive prior. ────────────
    $newRow = DB::transaction(function () use ($drawing, $xml, $userId): ProjectDrawing {
        $newRow = $drawing->replicate([
            'canvas_state',
            'generated_svg',
            'thumbnail_png_path',
            'filename',
            'completion_email_sent_at',
            'failed_email_sent_at',
            'superseded_by_id',
            'access_token',
        ]);

        $newRow->version       = ((int) $drawing->version) + 1;
        $newRow->status        = ProjectDrawing::STATUS_DRAFT;
        $newRow->generated_by  = $userId;
        $newRow->canvas_state  = $xml;
        $newRow->error_message = null;
        $newRow->save();

        $this->archivePrior($drawing, $newRow);

        return $newRow;
    });

    Log::info('DrawingService: spike XML versioned save (archive-prior)', [
        'old_drawing_id' => $drawing->id,
        'new_drawing_id' => $newRow->id,
        'version'        => $newRow->version,
        'xml_bytes'      => strlen($xml),
    ]);

    return $newRow;
}
```

**Method 2: `saveSpikeSvg` — preview-only SVG export**

```php
/**
 * Quick task 260509-ibx — write the SVG export from the embed to disk
 * via DocumentArtifactStorage::TYPE_DRAWING.
 *
 * Preview-only — D-LOCK-8 makes mxGraph XML the source of truth, SVG is
 * for thumbnail/embed-in-PDF use only. Writes to:
 *   storage/app/documents/drawings/spike-{drawing_id}.svg
 *
 * Returns the absolute path written (caller may basename() for client display).
 */
public function saveSpikeSvg(ProjectDrawing $drawing, string $svg): string
{
    $artifacts = app(\App\Services\DocumentArtifactStorage::class);
    $filename  = sprintf('spike-%d.svg', $drawing->id);
    $path      = $artifacts->writePath(\App\Services\DocumentArtifactStorage::TYPE_DRAWING, $filename);

    if (file_put_contents($path, $svg) === false) {
        throw new \RuntimeException("DrawingService::saveSpikeSvg: failed to write {$path}");
    }

    Log::info('DrawingService: spike SVG written', [
        'drawing_id' => $drawing->id,
        'path'       => $path,
        'svg_bytes'  => strlen($svg),
    ]);

    return $path;
}
```

**Where to insert in the file:**

Insert both methods AFTER `archivePrior()` (the existing v1.3 archive-prior
helper at the bottom of the class). Place them last so the existing v1.3
methods (createForProject / generateInitial / regenerate / archivePrior)
are visually first when reading the file — the spike is additive.

**Why no new column / no migration:**

CONTEXT.md D-LOCK-8: "Existing canvas_state column (added in v1.3 Phase 17
foundations) repurposed if compatible". Per `database/migrations/
2026_05_01_000001_create_project_drawings_table.php` line 89, canvas_state
is `mediumText` (16 MB ceiling), nullable, in `$fillable` (line 73 of
ProjectDrawing.php). MOD-05 in PITFALLS.md explicitly earmarks the column
for engineer-edited canvas state. Reusing it means:

- Zero migration on live → zero `php artisan migrate` step on deploy
- Zero risk of column-conflict with existing v1.3 rows
- `hasUserEdits()` is already implemented as `! empty($this->canvas_state)`
  — that's the lock-on-edit trigger, free.

**What NOT to do:**

- Do NOT add a `mxgraph_xml` migration column (D-LOCK-8 + scope constraint
  reuses canvas_state if compatible — it is).
- Do NOT modify the existing `regenerate()` or `archivePrior()` methods —
  the new methods CALL `archivePrior()`, they don't change it.
- Do NOT add a constructor change (the existing constructor's services are
  what we need: `archivePrior()` is on `$this`, `DocumentArtifactStorage` is
  resolved via `app()` for the SVG write to keep the constructor signature
  unchanged → smaller diff → zero risk to v1.3 callers).
- Do NOT add a new `STATUS_SPIKE_EDITED` constant. The spike row stays in
  `STATUS_DRAFT` even after a save — the v1.3 status state machine is
  untouched (the spike's "is the engineer editing" question is answered by
  `hasUserEdits()`, not status).

**Linting:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawingService.php
```
  </action>
  <verify>
    <automated>
      # 1. Syntax check
      "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawingService.php

      # 2. Methods exist on the class
      php artisan tinker --execute="
        \$cls = new ReflectionClass(App\Services\Drawings\DrawingService::class);
        if (!\$cls->hasMethod('saveSpikeXml')) { fwrite(STDERR, 'saveSpikeXml missing'); exit(1); }
        if (!\$cls->hasMethod('saveSpikeSvg')) { fwrite(STDERR, 'saveSpikeSvg missing'); exit(1); }
        echo 'OK: both spike methods present';
      "

      # 3. NO migration was added (D-LOCK-8 reuse-canvas_state check)
      git diff --stat HEAD -- database/migrations/ | grep -v "^$" \
        && { echo "FAIL: migration added — D-LOCK-8 reuses canvas_state, no migration expected"; exit 1; } \
        || echo "OK: no migration added (D-LOCK-8)"

      # 4. Lock-on-edit + archive-prior smoke (uses an in-memory project
      #    drawing — exercises the first-save → second-save flow):
      php artisan tinker --execute="
        \$p = App\Models\Project::whereHas('packages')->first();
        if (!\$p) { echo 'SKIP: no project'; exit(0); }
        \$d = App\Models\ProjectDrawing::create([
          'project_id' => \$p->id,
          'kind' => App\Models\ProjectDrawing::KIND_SCHEMATIC,
          'version' => 1,
          'status' => App\Models\ProjectDrawing::STATUS_DRAFT,
          'source_data' => ['spike' => true, 'spike_id' => 'smoke-test'],
        ]);
        \$svc = app(App\Services\Drawings\DrawingService::class);

        // First save → same row, lock flips
        \$r1 = \$svc->saveSpikeXml(\$d, '<mxGraphModel/><!-- v1 -->', 1);
        if (\$r1->id !== \$d->id) { fwrite(STDERR, 'first save should NOT version'); exit(1); }
        if (!\$r1->fresh()->hasUserEdits()) { fwrite(STDERR, 'lock did not flip'); exit(1); }

        // Second save → new row, prior superseded
        \$r2 = \$svc->saveSpikeXml(\$r1->fresh(), '<mxGraphModel/><!-- v2 -->', 1);
        if (\$r2->id === \$d->id) { fwrite(STDERR, 'second save should version'); exit(1); }
        if (\$r1->fresh()->status !== App\Models\ProjectDrawing::STATUS_SUPERSEDED) {
          fwrite(STDERR, 'prior not superseded'); exit(1);
        }
        if ((int) \$r2->version !== 2) { fwrite(STDERR, 'version not bumped'); exit(1); }

        // Cleanup
        \$r2->forceDelete(); \$r1->forceDelete();
        echo 'OK: lock-on-edit + archive-prior round-trip';
      "

      # 5. SVG write smoke — round-trip a small fake SVG to disk via TYPE_DRAWING
      php artisan tinker --execute="
        \$p = App\Models\Project::whereHas('packages')->first();
        if (!\$p) { echo 'SKIP'; exit(0); }
        \$d = App\Models\ProjectDrawing::create([
          'project_id' => \$p->id,
          'kind' => App\Models\ProjectDrawing::KIND_SCHEMATIC,
          'version' => 1,
          'status' => App\Models\ProjectDrawing::STATUS_DRAFT,
          'source_data' => ['spike' => true, 'spike_id' => 'svg-smoke'],
        ]);
        \$svc = app(App\Services\Drawings\DrawingService::class);
        \$path = \$svc->saveSpikeSvg(\$d, '<svg xmlns=\"http://www.w3.org/2000/svg\"><circle r=\"5\"/></svg>');
        if (!is_file(\$path)) { fwrite(STDERR, 'SVG not on disk'); exit(1); }
        if (!str_contains(\$path, 'drawings/spike-')) { fwrite(STDERR, 'wrong path'); exit(1); }
        @unlink(\$path); \$d->forceDelete();
        echo 'OK: SVG written via TYPE_DRAWING';
      "
    </automated>
  </verify>
  <done>
- `DrawingService::saveSpikeXml` exists, follows the lock-on-edit → archive-
  prior pattern (verified by smoke test: first save same row, second save
  versioned + prior superseded)
- `DrawingService::saveSpikeSvg` exists, writes to
  `storage/app/documents/drawings/spike-{id}.svg` via DocumentArtifactStorage
  TYPE_DRAWING (verified by smoke test)
- NO migration added (verified by `git diff --stat HEAD -- database/migrations/`
  returning empty) — D-LOCK-8 reuse of canvas_state honoured
- Existing v1.3 methods (createForProject / generateInitial / regenerate /
  archivePrior) byte-identical (verified: `git diff app/Services/Drawings/
  DrawingService.php` shows ONLY additions below archivePrior, zero deletions)
- `php -l` clean
- Single atomic commit `feat(drawio-spike-260509-ibx): saveSpikeXml + saveSpikeSvg lock-on-edit (D-LOCK-2 + D-LOCK-8)`
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Browser → admin spike controller | Authenticated admin user posts mxGraph XML + SVG. Untrusted bytes despite admin role (XSS-via-storage risk if SVG re-rendered without sanitisation later). |
| QuoteWerks-imported equipment data → builder service | Untrusted strings from external PDF parse land in mxGraph XML labels. Warning 7 / T-17.02-01 from Phase 17 P02 carries forward. |
| iframe (draw.io embed) → parent window via postMessage | Vendored bundle is same-origin (D-LOCK-1), so postMessage origin is trustworthy AT THE BUNDLE BOUNDARY, but the bundle itself is upstream code we don't audit line-by-line. Mitigation: filter postMessage events by `e.source === iframe.contentWindow`. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-260509-ibx-01 | Tampering / Injection | Equipment names from QuoteWerks rendered into mxGraph XML labels | mitigate | `htmlspecialchars($s, ENT_XML1 \| ENT_QUOTES, 'UTF-8')` on every interpolation in `DrawIoSpikeBuilderService::emitMxGraph` — Task 3. Mirrors Phase 17 P02 Warning 7 fix. |
| T-260509-ibx-02 | Information disclosure / IDOR | `admin/drawings/draw-io-spike/{project}` could leak any project's data to admin | accept | D-LOCK-7 admin gating + admin-only-feature scope; admins already have full project visibility per existing app authz. |
| T-260509-ibx-03 | DoS / Resource exhaustion | Engineer pastes huge mxGraph XML or SVG (5+ MB) | mitigate | Request-validate `xml`/`svg` to `max:5242880` (5 MB) in `saveXml` / `exportSvg` — Task 4. |
| T-260509-ibx-04 | Spoofing | postMessage event from unrelated iframe (cross-frame attack) | mitigate | JS handler filters `e.source === this.$refs.embed.contentWindow` before processing — Task 4 Blade. |
| T-260509-ibx-05 | Tampering / XSS-via-storage | Engineer-saved SVG re-rendered via `<img src>` or `<embed>` later contains script | accept | Spike scope: SVG is preview-only, NOT yet rendered in any client surface. v2.0 must add SVG sanitisation (DOMPurify or server-side svg-sanitize) before rendering engineer-edited SVGs anywhere user-facing. Logged as v2.0 carry-forward. |
| T-260509-ibx-06 | Tampering | Vendored draw.io bundle ships with a vulnerability we don't audit | accept | Apache 2.0 upstream is widely used; pin a stable tag, document update procedure (D-LOCK-1 VERSION.md). v2.0 to formalise periodic upstream-CVE review. |
| T-260509-ibx-07 | Repudiation | Engineer edits, then claims they didn't — no audit trail | mitigate | `Log::info` on every save with drawing_id + project_id + user_id + xml_bytes — Task 5. Combined with `generated_by` column on each new versioned row gives a full edit history. |
</threat_model>

<verification>
## Phase-level checks (after all 5 tasks land)

1. **End-to-end round-trip in a real browser** (manual, recorded in SUMMARY.md):
   - Visit `admin/drawings/draw-io-spike/{realProjectIdWithTeamsRoom}` as
     admin user
   - Embed loads draw.io editor, initial XML auto-loads showing 5 device
     stencils from real project data
   - Drag one device to a new position, click File → Save (or Ctrl+S)
   - Network tab shows POST `admin/drawings/draw-io-spike/{id}/save` 200
   - Status pill flashes "Saved", changes from "Auto-generated" to
     "🔒 Engineer-edited (locked)"
   - Reload the page → device is in the new position (round-trip integrity)
   - Click Export SVG → POST `admin/drawings/draw-io-spike/{id}/export-svg`
     returns 200, file lands at `storage/app/documents/drawings/spike-{id}.svg`

2. **Side-by-side visual fidelity check** (manual, primary spike outcome):
   - Open the rendered Teams Room schematic from step 1 alongside the
     user's Lucidchart Extron Concept reference PDF
   - Record verdict in SUMMARY.md as "🟢 same league" / "🟡 close, with
     these specific gaps:" / "🔴 falls short"
   - This verdict is the build-vs-buy decision input for v2.0

3. **Performance budget** (`Performance.now()` in browser DevTools):
   - Round-trip (load → drag → save → reload) under 3 seconds on dev
     machine — record ms in SUMMARY.md

4. **D-LOCK regression scan** (run as final pre-commit):
   - `grep -r "embed.diagrams.net" public/ resources/` returns empty
     (D-LOCK-1)
   - `grep -r "draw-io-spike" resources/views/projects/ resources/views/
     components/ resources/views/layouts/navigation.blade.php` returns
     empty (D-LOCK-7)
   - `git diff --stat HEAD -- database/migrations/` returns empty (D-LOCK-8
     reuse of canvas_state)
   - `git diff HEAD -- app/Services/Drawings/SchematicGeneratorService.php
     app/Services/Drawings/SchematicD2SourceBuilder.php
     app/Services/Drawings/DrawingDataResolverService.php` returns empty
     (v1.3 D2 pipeline untouched)
   - `grep -E "AIManager|AICache|AIUsage" app/Services/Drawings/
     DrawIoSpikeBuilderService.php` returns empty (D-LOCK-5)

5. **PHP syntax sweep** (Herd PHP 8.4):
   ```
   for f in \
     app/Services/Drawings/DrawingService.php \
     app/Services/Drawings/DrawIoSpikeBuilderService.php \
     app/Http/Controllers/Admin/DrawIoSpikeController.php \
     routes/web.php; do
     "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l "$f" || exit 1
   done
   ```
</verification>

<success_criteria>
## When this plan is "done"

- All 5 tasks completed with their `<verify>` automated checks passing.
- 5 atomic commits land, each prefixed `feat(drawio-spike-260509-ibx):`.
- Manual browser round-trip recorded in SUMMARY.md with screenshot or HTML
  capture proving load → drag → save → reload preserves engineer changes.
- Side-by-side visual fidelity verdict against the Lucidchart Extron Concept
  reference recorded in SUMMARY.md (this is THE primary spike outcome —
  determines the v2.0 build-vs-buy decision).
- Round-trip performance under 3 seconds on dev machine, recorded in ms.
- D-LOCK regression scan (verification step 4) returns clean across all
  6 sub-checks.
- v1.3 production drawings pipeline byte-identical (no diff in
  SchematicGeneratorService / SchematicD2SourceBuilder / DrawingDataResolverService).
- No npm install / composer require run during the spike.
- No migration added (D-LOCK-8 canvas_state reuse means zero
  `php artisan migrate` step during the user's local-then-upload deploy).
</success_criteria>

<output>
After completion, create:
`.planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-SUMMARY.md`

Required SUMMARY.md sections (per project conventions):

1. **What changed** — bullet list, one per task, ~1-2 lines each
2. **D-LOCK audit table** — verify each of D-LOCK-1 through D-LOCK-8 stayed
   honoured, with the verification command/check that proved it
3. **Spike success criteria evaluation** — copy the 5-point list from
   CONTEXT.md, fill in the actual outcomes:
     - Visual fidelity verdict + screenshot/HTML capture path
     - Round-trip integrity outcome
     - Brand alignment outcome
     - Performance ms measurement
     - Cost reality check (actual time spent vs planned 2 weeks)
4. **Build-vs-buy recommendation** — "🟢 green-light v2.0 native build" /
   "🟡 needs spec adjustment, here's how" / "🔴 fall back to Lucidchart API /
   XTEN-AV evaluation" — ONE-line verdict followed by 3-5 supporting bullets
5. **🚨 Files to upload to live** — MANDATORY per project conventions
   (CLAUDE.md user constraint). Every modified/created file with absolute
   path from project root. Group by:
     - **Vendored bundle** (`public/vendor/drawio/` — note: large directory
       with many files, list as a directory + file count rather than every
       single asset; the user will rsync the whole subtree)
     - **PHP source** (DrawIoSpikeBuilderService, DrawIoSpikeController,
       DrawingService.php for the saveSpike methods)
     - **Blade view** (resources/views/admin/drawings/draw-io-spike.blade.php)
     - **Routes** (routes/web.php)
     - **Stencil pack** (resources/data/draw-io-stencils/21cav-mtr-spike.json)
     - **Manufacturer logos** (public/img/manufacturers/*.svg)
   **NO `php artisan migrate` step** — D-LOCK-8 reuses canvas_state, zero
   migrations added. Call this out explicitly in the upload section so the
   user knows: "Upload files → no migrate command → admin user visits
   spike URL to test."
6. **Carry-forwards to v2.0 (if spike succeeds)** — list any deferred
   concerns spotted during the spike (e.g. T-260509-ibx-05 SVG sanitisation,
   licensing of manufacturer logos, port-routing fidelity gaps)
</output>
