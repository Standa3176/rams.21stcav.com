---
phase: 21-device-port-catalog-stencil-cache
plan: 03
type: execute
wave: 2
tags: [drawings, manufacturer-logos, builder, mxgraph, integration, v2.0]
depends_on: [21-01]
files_modified:
  - public/img/manufacturers/crestron.svg
  - public/img/manufacturers/cisco.svg
  - public/img/manufacturers/qsc.svg
  - public/img/manufacturers/bogen.svg
  - public/img/manufacturers/polycom.svg
  - public/img/manufacturers/logitech.svg
  - public/img/manufacturers/shure.svg
  - public/img/manufacturers/sony.svg
  - public/img/manufacturers/extron.svg
  - public/img/manufacturers/biamp.svg
  - public/img/manufacturers/yamaha.svg
  - public/img/manufacturers/atlona.svg
  - public/img/manufacturers/lightware.svg
  - public/img/manufacturers/q-sys.svg
  - public/img/manufacturers/barco.svg
  - app/Services/Drawings/ManufacturerLogoResolver.php
  - app/Services/Drawings/DrawIoBuilderService.php
  - app/Services/Drawings/DrawIoSpikeBuilderService.php
  - app/Http/Controllers/Admin/DrawIoSpikeController.php
  - tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php
  - tests/Feature/Drawings/DrawIoBuilderServiceTest.php
autonomous: true
requirements: [DRAW-35]

must_haves:
  truths:
    - "Top-15 additional manufacturer logo SVGs ship at public/img/manufacturers/{slug}.svg, single-colour currentColor, viewBox 0 0 100 30, alongside the 5 spike logos for a top-20 coverage set (DRAW-35). public/img/manufacturers/clickshare.svg is preserved (NOT deleted, NOT renamed) per D-14."
    - "DrawIoBuilderService reads device_stencils.mxgraph_xml + ports via Project::devicesWithStencils() instead of the hand-coded 21cav-mtr-spike.json — every hardware part_number on a project produces a stencil cell in the output mxGraph XML (covers Tier 1 placeholders for uncatalogued items via the cache contract from Plan 21-01)"
    - "The spike admin route admin.drawings.draw-io-spike.show stays bound to the same controller + Blade; only the builder dependency flips from DrawIoSpikeBuilderService to DrawIoBuilderService — engineer-facing functionality preserved (D-08). DrawIoSpikeController's existing DrawingService $drawings constructor parameter MUST be preserved (used by saveXml + exportSvg methods)."
    - "Manufacturer logos render in stencil headers via ManufacturerLogoResolver — when a stencil's manufacturer matches a known slug, the inline SVG glyph is embedded; when no match, header bar renders with text-only manufacturer name (graceful degrade). Resolver matches 'clickshare' substring BEFORE 'barco' so the spike's existing clickshare.svg keeps rendering for ClickShare Bar Pro stencils (per D-14)."
    - "DrawIoSpikeBuilderService still exists as a thin shim that delegates to DrawIoBuilderService (preserves the class name in case any code path or test references it; deprecation comment points future work to DrawIoBuilderService)"
  artifacts:
    - path: "public/img/manufacturers/{15 new SVGs}"
      provides: "Inline SVG manufacturer glyphs for Crestron, Cisco, QSC, Bogen, Polycom, Logitech, Shure, Sony, Extron, Biamp, Yamaha, Atlona, Lightware, Q-SYS, Barco — single-colour currentColor, viewBox 0 0 100 30. NOTE: barco.svg is a NEW SEPARATE file from the existing clickshare.svg per D-14 (both coexist; resolver matches clickshare first)."
    - path: "app/Services/Drawings/ManufacturerLogoResolver.php"
      provides: "Map manufacturer string → public asset path or inline SVG; case-insensitive lookup; memoised. resolveSvg($manufacturer) returns SVG markup OR null when no match. Substring needle order: clickshare BEFORE barco (D-14 collision rule)."
      exports: ["resolveSvg", "resolveAssetPath", "knownManufacturers"]
    - path: "app/Services/Drawings/DrawIoBuilderService.php"
      provides: "Generalised mxGraph XML builder; reads from $project->devicesWithStencils() (Plan 21-01); embeds device_stencils.mxgraph_xml via the same shape=stencil(base64) pattern as the spike builder; layouts cells in a deterministic grid; cables derived via the same canonical Teams Room chain heuristic for now (Phase 22 replaces this with port-level FK routing); empty-package case emits valid empty graph. Role inference is INTENTIONALLY shallow per Nit 9 fix — scoped to manufacturer-logo placement + a coarse 4-column layout heuristic (network-switch / display / mic / other); Phase 23 REPLACES this with proper category metadata + a real layout engine."
      exports: ["build"]
    - path: "app/Services/Drawings/DrawIoSpikeBuilderService.php"
      provides: "Backwards-compat shim — delegates build() to DrawIoBuilderService::build(); class kept so any external references don't break during the v2.0 phase rollout. @deprecated docblock points to DrawIoBuilderService"
      contains: "@deprecated"
    - path: "app/Http/Controllers/Admin/DrawIoSpikeController.php"
      provides: "Updated to inject DrawIoBuilderService directly (cleaner intent) — admin.drawings.draw-io-spike.show route name unchanged. CRITICAL (per D-08 + Warning 2): the existing DrawingService $drawings constructor parameter MUST be preserved alongside the builder type-hint flip. Existing constructor signature is `__construct(DrawIoSpikeBuilderService $builder, DrawingService $drawings)` — the executor flips ONLY the first parameter to `DrawIoBuilderService $builder`; the second parameter stays."
  key_links:
    - from: "DrawIoBuilderService::build"
      to: "Project::devicesWithStencils"
      via: "method call on injected Project instance"
      pattern: "devicesWithStencils"
    - from: "DrawIoBuilderService cell emit loop"
      to: "device_stencils.mxgraph_xml"
      via: "shape=stencil(base64-encoded mxgraph_xml) style fragment (same pattern as DrawIoSpikeBuilderService::emitMxGraph)"
      pattern: "shape=stencil"
    - from: "DrawIoBuilderService cell header bar"
      to: "ManufacturerLogoResolver::resolveSvg"
      via: "constructor injection"
      pattern: "ManufacturerLogoResolver"
    - from: "DrawIoSpikeBuilderService::build (shim)"
      to: "DrawIoBuilderService::build"
      via: "delegation; class instantiated via app()"
      pattern: "DrawIoBuilderService"
    - from: "DrawIoSpikeController::__construct"
      to: "DrawIoBuilderService + DrawingService (BOTH preserved per D-08)"
      via: "constructor injection — TWO parameters, NOT one"
      pattern: "DrawIoBuilderService.*DrawingService"
---

<objective>
Ship the top-15 manufacturer logo glyphs to round out the top-20 coverage with the 5 spike logos (DRAW-35), and rewire the draw.io builder to read from the new `device_stencils` table instead of the hand-coded JSON pack — making Plan 21-01 + Plan 21-02's data layer the live runtime source for the spike admin route's mxGraph XML output.

After this plan, opening `admin.drawings.draw-io-spike.show` for a real project shows every hardware part_number as a stencil cell — curated devices render with full port detail (from Plan 21-02's seed pack); uncatalogued devices render as Tier 1 placeholders (from Plan 21-01's auto-generic). Manufacturer logos appear in the header bars of stencils whose manufacturer matches the top-20 set.

Per CONTEXT.md locked decisions:
- Spike admin route + controller method names + Blade view STAY in place; only the builder dependency flips. Controller's `DrawingService $drawings` second constructor parameter MUST be preserved (D-08 + Warning 2 fix).
- DrawIoSpikeBuilderService kept as a thin shim — backwards compat (D-08)
- Top-20 manufacturer logos as inline SVG, single-colour currentColor, viewBox 0 0 100 30 (D-06)
- ClickShare slug coexists with Barco slug — DO NOT delete `public/img/manufacturers/clickshare.svg`; resolver matches `clickshare` substring BEFORE `barco` (D-14)
- v1.3 D2 generator UNTOUCHED (D-10)
- resources/data/draw-io-stencils/21cav-mtr-spike.json kept as historical reference, NOT consumed by runtime after this plan (D-08)
- Role inference in this plan is INTENTIONALLY shallow — manufacturer-logo placement + coarse 4-column grid only; Phase 23 ships the real layout engine (Nit 9 fix; CONTEXT.md deferred section)

Purpose: deliver DRAW-35 (top-20 manufacturer logos) + complete the foundation/integration story for v2.0.

Output: 15 new SVG files (with `clickshare.svg` PRESERVED, NOT replaced); ManufacturerLogoResolver with D-14 ordered needles; renamed/generalised DrawIoBuilderService; backwards-compat shim on the original spike builder class; admin route smoke test.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-PLAN.md
@CLAUDE.md

# Spike code this plan rewires (READ existing DrawIoSpikeController constructor before refactoring — it has TWO params, not one):
@app/Services/Drawings/DrawIoSpikeBuilderService.php
@app/Http/Controllers/Admin/DrawIoSpikeController.php

# Plan 21-01 outputs this plan consumes:
@app/Models/Project.php
@app/Models/DeviceStencil.php
@app/Models/DevicePort.php
@app/Services/Drawings/DeviceStencilCacheService.php

# Existing manufacturer logos (5 from spike — visual style template for the new 15):
@public/img/manufacturers/neat.svg
@public/img/manufacturers/clickshare.svg
@public/img/manufacturers/netgear.svg
@public/img/manufacturers/samsung.svg
@public/img/manufacturers/sennheiser.svg

<interfaces>
Logo resolver contract:

```php
class ManufacturerLogoResolver {
    /** Returns inline SVG markup (string) for the manufacturer or null when no match */
    public function resolveSvg(?string $manufacturer): ?string;
    /** Returns the public asset path or null */
    public function resolveAssetPath(?string $manufacturer): ?string;
    /** @return array<int, string> known manufacturer slugs */
    public function knownManufacturers(): array;
}
```

Builder contract:

```php
class DrawIoBuilderService {
    public function __construct(
        private DeviceStencilCacheService $cache,
        private ManufacturerLogoResolver $logos,
    ) {}
    public function build(Project $project): string;
}

class DrawIoSpikeBuilderService {
    /** @deprecated Use DrawIoBuilderService directly. This shim delegates for backwards compat. */
    public function __construct(private DrawIoBuilderService $builder) {}
    public function build(Project $project): string;
}
```

Controller constructor — PRESERVED two-parameter signature (D-08 + Warning 2):

```php
// app/Http/Controllers/Admin/DrawIoSpikeController.php
class DrawIoSpikeController extends Controller {
    public function __construct(
        private readonly DrawIoBuilderService $builder,   // ← FLIPPED from DrawIoSpikeBuilderService
        private readonly DrawingService $drawings,        // ← PRESERVED — used by saveXml + exportSvg
    ) {}
    // show($project), saveXml(...), exportSvg(...) — method bodies UNCHANGED
}
```

Manufacturer slug lookup table (case-insensitive substring match, MOST-SPECIFIC FIRST per D-14 collision rule):

| Order | Input string contains | Slug | Notes |
|-------|-----------------------|------|-------|
| 1 | `q-sys` | q-sys | before qsc to avoid 'qsc' substring matching 'q-sys' |
| 2 | `qsc` | qsc | |
| 3 | `clickshare` | clickshare | **D-14 — BEFORE barco** so existing clickshare.svg renders for ClickShare products |
| 4 | `barco` | barco | falls through after clickshare; covers F-series projectors etc. |
| 5 | `crestron` | crestron | |
| 6 | `cisco` | cisco | |
| 7 | `bogen` | bogen | |
| 8 | `polycom` | polycom | |
| 9 | `poly` | polycom | alias — Polycom legacy |
| 10 | `logitech` | logitech | |
| 11 | `shure` | shure | |
| 12 | `sony` | sony | |
| 13 | `extron` | extron | |
| 14 | `biamp` | biamp | |
| 15 | `yamaha` | yamaha | |
| 16 | `atlona` | atlona | |
| 17 | `lightware` | lightware | |
| 18 | `neat` | neat | existing spike asset |
| 19 | `samsung` | samsung | existing spike asset |
| 20 | `netgear` | netgear | existing spike asset |
| 21 | `sennheiser` | sennheiser | existing spike asset |

(Top-20 unique manufacturer assets — `clickshare` + `barco` are TWO separate assets per D-14; total file count = 20 SVGs in `public/img/manufacturers/`.)
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Top-15 manufacturer logo SVGs + ManufacturerLogoResolver (with D-14 slug-collision handling)</name>
  <files>
    public/img/manufacturers/crestron.svg,
    public/img/manufacturers/cisco.svg,
    public/img/manufacturers/qsc.svg,
    public/img/manufacturers/bogen.svg,
    public/img/manufacturers/polycom.svg,
    public/img/manufacturers/logitech.svg,
    public/img/manufacturers/shure.svg,
    public/img/manufacturers/sony.svg,
    public/img/manufacturers/extron.svg,
    public/img/manufacturers/biamp.svg,
    public/img/manufacturers/yamaha.svg,
    public/img/manufacturers/atlona.svg,
    public/img/manufacturers/lightware.svg,
    public/img/manufacturers/q-sys.svg,
    public/img/manufacturers/barco.svg,
    app/Services/Drawings/ManufacturerLogoResolver.php,
    tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php
  </files>
  <behavior>
    - Each new SVG is a hand-traced or text-based simplified manufacturer glyph at viewBox="0 0 100 30" (wide-and-thin landscape format), single-colour using `currentColor` so cards can recolour the logo to match header bar (per D-06)
    - **D-14 preservation rule:** `public/img/manufacturers/clickshare.svg` is NOT touched. The new `barco.svg` is a SEPARATE file. After this task, the directory contains BOTH `clickshare.svg` (existing) AND `barco.svg` (new). `git status` MUST NOT show clickshare.svg as modified or deleted.
    - File size budget: each SVG ≤ 4 KB (text-based ones land at ~500 bytes)
    - Apache 2.0-compatible: no copyrighted brand-asset files copied verbatim — these are stylised text-based representations OR simplified geometric primitives that fall under nominative-fair-use territory. Document this in resolver docblock
    - ManufacturerLogoResolver builds an internal map of {needle => slug} for the 21 needles → 20 unique slugs (poly is an alias for polycom; clickshare and barco are separate per D-14); resolveSvg(name) does case-insensitive substring match (most-specific needle first per the table in &lt;interfaces&gt;) and returns inline SVG markup via file_get_contents on hit, null on miss
    - resolveSvg() memoises file reads — one file_get_contents per slug per request
    - resolveAssetPath() returns "/img/manufacturers/{slug}.svg" (web-accessible URL — useful for Phase 24 curation UI)
    - knownManufacturers() returns the 20-slug list (sorted alphabetically): atlona, barco, biamp, cisco, clickshare, crestron, extron, lightware, logitech, neat, netgear, polycom, q-sys, qsc, samsung, sennheiser, shure, sony, yamaha
    - ManufacturerLogoResolverTest asserts:
      - resolveSvg('Crestron Electronics') returns SVG markup
      - resolveSvg('crestron') case-insensitive match works
      - resolveSvg('Acme Corp Ltd') returns null
      - resolveSvg(null) returns null
      - knownManufacturers() returns 20 entries (one per unique slug)
      - **D-14 critical assertion:** resolveSvg('Barco ClickShare Bar Pro') returns the contents of `clickshare.svg` (NOT `barco.svg`) because the substring `clickshare` matches first per the needle order
      - **D-14 fallback assertion:** resolveSvg('Barco F50 Projector') returns the contents of `barco.svg` because `clickshare` does not match, `barco` does
      - resolveSvg('Q-SYS Core 110f') returns q-sys.svg, NOT qsc.svg (q-sys needle is before qsc)
      - File `public/img/manufacturers/clickshare.svg` is non-empty (preservation check — explicit assertion that the spike's file still exists and is readable)
  </behavior>
  <action>
    Create the 15 new SVG files. Style template from existing 5: simple, single-colour, text-based or geometric. Use `currentColor` as fill so the rendering context recolours.

    Approach for text-based logos (most): a single `<text>` element centred in the viewBox with the manufacturer name in bold sans-serif. Example:
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 30" fill="currentColor"><text x="50" y="22" font-family="Arial, sans-serif" font-size="16" font-weight="700" text-anchor="middle">EXTRON</text></svg>`

    For Q-SYS / QSC / Crestron / Cisco / Sony / Logitech / Shure — same text-only pattern. Use the spike's existing samsung.svg / netgear.svg etc. as visual examples for shape and proportion. Document in resolver docblock that these are simplified text-based glyphs intended for engineering schematic display, not brand-asset reproductions; replace with manufacturer-supplied vector files when available via Phase 24 logo upload UI.

    **D-14 explicit constraints:**
    - DO NOT touch `public/img/manufacturers/clickshare.svg`. Verify before task completion: `git status public/img/manufacturers/clickshare.svg` returns clean.
    - DO ship `public/img/manufacturers/barco.svg` as a NEW file with text "BARCO" (or simplified Barco geometric primitive). This file represents non-ClickShare Barco products (F-series projectors, G-series cinema projectors, etc.).

    Verify file size: each file should be under 1 KB for text-only and under 4 KB for any geometric shape.

    Create `app/Services/Drawings/ManufacturerLogoResolver.php`. Internal map: `private const MANUFACTURER_NEEDLES` ordered most-specific first per the table in &lt;interfaces&gt; — array of `needle => slug`.

    **Critical needle ordering (D-14 + collision avoidance):**
    ```php
    private const MANUFACTURER_NEEDLES = [
        // q-sys before qsc (avoid q-sys substring matching qsc rule)
        'q-sys' => 'q-sys',
        'qsc' => 'qsc',
        // clickshare before barco — D-14 — preserve spike's clickshare.svg
        'clickshare' => 'clickshare',
        'barco' => 'barco',
        // remaining manufacturers alphabetical (no inter-collisions among them)
        'crestron' => 'crestron',
        'cisco' => 'cisco',
        'bogen' => 'bogen',
        'polycom' => 'polycom',
        'poly' => 'polycom', // alias
        'logitech' => 'logitech',
        'shure' => 'shure',
        'sony' => 'sony',
        'extron' => 'extron',
        'biamp' => 'biamp',
        'yamaha' => 'yamaha',
        'atlona' => 'atlona',
        'lightware' => 'lightware',
        // existing spike manufacturers
        'neat' => 'neat',
        'samsung' => 'samsung',
        'netgear' => 'netgear',
        'sennheiser' => 'sennheiser',
    ];
    ```

    resolveSvg() iterates this map case-insensitively (mirrors DrawIoSpikeBuilderService::STENCIL_ALIASES iteration pattern); on hit, file_get_contents(public_path("img/manufacturers/{slug}.svg")) → return; memoise per-slug. Use Laravel's `public_path()` helper. Document the D-14 ordering rationale in a class-level docblock comment.

    Lint touched PHP files with Herd PHP 8.4.

    Write the test file with assertions per behavior block, including the D-14 critical assertion (Barco ClickShare → clickshare.svg) and the D-14 fallback assertion (Barco F50 → barco.svg). Test against the real files (they ARE the test fixtures).
  </action>
  <verify>
    <automated>php artisan test --filter=ManufacturerLogoResolverTest &amp;&amp; test -f public/img/manufacturers/clickshare.svg &amp;&amp; test -f public/img/manufacturers/barco.svg</automated>
  </verify>
  <done>
    - 20 SVG files exist at `public/img/manufacturers/` (5 existing + 15 new) — `clickshare.svg` preserved per D-14
    - `barco.svg` exists as a SEPARATE file from `clickshare.svg`
    - ManufacturerLogoResolver resolves all 20 case-insensitively + handles aliases (poly → polycom)
    - D-14 ordering verified: 'Barco ClickShare ...' returns clickshare.svg; 'Barco F50' returns barco.svg
    - q-sys / qsc collision avoided (q-sys needle precedes qsc)
    - Tests pass
    - Lint clean
    - Each SVG file ≤ 4 KB
    - `git status public/img/manufacturers/clickshare.svg` returns clean (preservation check)
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Generalise DrawIoSpikeBuilderService → DrawIoBuilderService + backwards-compat shim + controller refactor (preserving DrawingService dependency)</name>
  <files>
    app/Services/Drawings/DrawIoBuilderService.php,
    app/Services/Drawings/DrawIoSpikeBuilderService.php,
    app/Http/Controllers/Admin/DrawIoSpikeController.php,
    tests/Feature/Drawings/DrawIoBuilderServiceTest.php
  </files>
  <behavior>
    - DrawIoBuilderService::build(Project $project): string
      - Calls $project->devicesWithStencils() (Plan 21-01) — gets enriched lines with stencil instances (cache hits or freshly auto-generated Tier 1 placeholders)
      - For each line, emits an mxCell vertex with shape=stencil(base64-encoded device_stencils.mxgraph_xml) — same encoding pattern as DrawIoSpikeBuilderService::emitMxGraph
      - **Layout heuristic — INTENTIONALLY SHALLOW (Nit 9 fix):** scoped to manufacturer-logo placement + a coarse 4-column grid for visual recognisability. NOT a full role-inference engine. Phase 23 REPLACES this with a proper layout engine driven by `device_stencils.metadata.role` + port-composition rules. The shallow heuristic:
        - For lines whose stencil's part_number matches one of the 5 spike-promoted slugs (neat-bar-pro, samsung-qm65c-t, clickshare-bar-pro, sennheiser-tcc2, netgear-gs312tp), use the role from a small in-class STENCIL_ROLES constant (col 0 = videobar/byod/mic, col 1 = network-switch, col 2 = display).
        - For lines that don't match a known spike slug, place them in a 4th "other" column on the right. NO heuristic dispatch based on port composition — that's deferred to Phase 23.
        - Code comment + class docblock MUST state: "// Phase 23 will replace this 4-column heuristic with a proper category-metadata-driven layout engine. The current logic is sized to 'stop the spike admin route from regressing visually' — NOT to be the long-term layout strategy. See CONTEXT.md deferred section."
      - Empty package case: emits the existing emptyGraph() XML (preserved verbatim from spike)
      - Manufacturer logo: looked up via ManufacturerLogoResolver. The spike-style stencil packs already include their manufacturer name in the header bar text via mxgraph_xml — the new uncatalogued Tier 1 stencils omit the logo (text-only header). Phase 23 ships the full XTEN-AV header-bar-with-logo treatment.
      - Cable derivation: for now, preserves the spike's canonical Teams Room chain (deriveCables logic). Phase 22 replaces this with port-level FK routing once cable_schedule_items have source_port_id / dest_port_id.
    - DrawIoSpikeBuilderService becomes a thin shim — constructor takes DrawIoBuilderService; build() delegates `return $this->builder->build($project);`. Class kept so existing routes / controllers / tests don't break. Add @deprecated docblock pointing to DrawIoBuilderService.
    - **Controller refactor (D-08 + Warning 2 — CRITICAL preservation):** DrawIoSpikeController's existing constructor is:
      ```php
      public function __construct(
          private readonly DrawIoSpikeBuilderService $builder,
          private readonly DrawingService $drawings,
      ) {}
      ```
      The refactor flips ONLY the FIRST parameter type-hint to `DrawIoBuilderService`. The SECOND parameter `DrawingService $drawings` MUST be preserved — it is consumed by `saveXml()` (calls `$this->drawings->saveSpikeXml(...)`) and `exportSvg()` (calls `$this->drawings->saveSpikeSvg(...)`). Dropping it breaks the spike's persistence pipeline.
      Final controller constructor:
      ```php
      public function __construct(
          private readonly DrawIoBuilderService $builder,    // FLIPPED
          private readonly DrawingService $drawings,         // PRESERVED — DO NOT REMOVE
      ) {}
      ```
      Route name + Blade view + method signatures (show / saveXml / exportSvg) unchanged.
    - DrawIoBuilderServiceTest creates a project with 4 hardware lines (1 cataloguable matching a seed-pack stencil from Plan 21-02 — neat-bar-pro; 1 matching auto-generic from a brand-new part_number; 1 with empty part_number; 1 cable category, must be filtered out). Asserts: build() output is a valid mxGraphModel XML string starting with `<mxGraphModel`; contains exactly 2 vertex="1" cells (the 2 valid hardware lines); the curated stencil's mxgraph_xml is base64-embedded in the style; second project run with same equipment list produces byte-identical output (deterministic). Additionally asserts the controller still has BOTH constructor parameters via reflection: `(new ReflectionClass(DrawIoSpikeController::class))->getConstructor()->getNumberOfParameters() === 2`.
    - Smoke test: open admin.drawings.draw-io-spike.show for a real recent project — page loads (200 OK), iframe receives load message, devices appear (manual visual check, recorded in SUMMARY).
  </behavior>
  <action>
    Create `app/Services/Drawings/DrawIoBuilderService.php`. Copy structure from DrawIoSpikeBuilderService:
    - Constructor takes DeviceStencilCacheService + ManufacturerLogoResolver via type-hinted constructor injection
    - build(Project $project): string
      1. Empty-package guard → return $this->emptyGraph()
      2. $lines = $project->devicesWithStencils() (returns enriched lines with stencil instances; includes Tier 1 auto-create side effect from Plan 21-01)
      3. $deviceCells = $this->mapLinesToCells($lines, $package) — adapts spike's mapEquipmentToCells to consume DeviceStencil rows directly using the SHALLOW heuristic per &lt;behavior&gt;
      4. $cableCells = $this->deriveCables($deviceCells, (array) ($package->cable_list ?? [])) — copy from spike verbatim (Phase 22 replaces)
      5. return $this->emitMxGraph($deviceCells, $cableCells)
    - mapLinesToCells: same 3-column grid + per-column row tracker. **Shallow role-inference per Nit 9 (manufacturer-logo placement scope ONLY):** if line.stencil's part_number matches one of the 5 spike-promoted slugs (neat-bar-pro, samsung-qm65c-t, clickshare-bar-pro, sennheiser-tcc2, netgear-gs312tp), use the role from a small in-class STENCIL_ROLES constant. Otherwise, lay out in a 4th "other" column on the right. **DO NOT** add port-composition-based heuristic dispatch (network-switch column inference, audio-in dominance for mic column, etc.) — that's Phase 23. Add a TODO comment with reference: `// TODO(phase-23): replace this 4-column shallow heuristic with metadata.role-driven layout engine. See .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md deferred section ("Full role-inference engine — Phase 23").`
    - emitMxGraph: copy from spike verbatim. The shape=stencil(base64) embedding still works because the new stencils' mxgraph_xml is the same `<shape>` format.
    - emptyGraph: copy from spike verbatim.
    - xml() helper: copy from spike verbatim (htmlspecialchars ENT_XML1|ENT_QUOTES — Warning 7 / T-17.02-01 carries forward).

    Refactor `app/Services/Drawings/DrawIoSpikeBuilderService.php`:
    - Strip out everything except the constructor + build() method body
    - Constructor takes DrawIoBuilderService $builder
    - build() delegates: `return $this->builder->build($project);`
    - Add @deprecated docblock at class level pointing to DrawIoBuilderService
    - Remove ALL private constants + helper methods (they all moved to DrawIoBuilderService)
    - Total class body ≤ 30 lines

    **Update `app/Http/Controllers/Admin/DrawIoSpikeController.php` (CRITICAL — preserve DrawingService dependency per D-08 + Warning 2):**
    - The existing constructor has TWO parameters: `DrawIoSpikeBuilderService $builder` AND `DrawingService $drawings`.
    - Flip ONLY the first parameter's type-hint: `DrawIoSpikeBuilderService` → `DrawIoBuilderService`.
    - **DO NOT REMOVE the second parameter `DrawingService $drawings`.** It is used by `saveXml()` (calls `$this->drawings->saveSpikeXml(...)`) AND `exportSvg()` (calls `$this->drawings->saveSpikeSvg(...)`). Removing it breaks the persistence + SVG export pipeline.
    - Update the corresponding `use` statement: `use App\Services\Drawings\DrawIoSpikeBuilderService;` → `use App\Services\Drawings\DrawIoBuilderService;`. The `use App\Services\Drawings\DrawingService;` import stays untouched.
    - Route name + Blade view + method signatures (show / saveXml / exportSvg) unchanged.
    - If the parameter name is being renamed for clarity (`$spikeBuilder` → `$builder`), update all usages inside the class body consistently. (Note: the existing constructor uses `$builder` already, so this is a no-op rename.)

    Lint all touched PHP files with Herd PHP 8.4.

    Write `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` per behavior block. Use RefreshDatabase. Seed via running `Database\Seeders\DeviceStencilSeeder` in setUp() — Plan 21-02's seeder handles idempotency. Create the project + package via factories. Assert XML output via simple string contains + structural string assertions. Add the controller-constructor-preservation reflection assertion explicitly.
  </action>
  <verify>
    <automated>php artisan test --filter=DrawIoBuilderServiceTest &amp;&amp; php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app-&gt;boot(); \$rc = new ReflectionClass(App\Http\Controllers\Admin\DrawIoSpikeController::class); echo \$rc-&gt;getConstructor()-&gt;getNumberOfParameters() === 2 ? 'OK' : 'FAIL';"</automated>
  </verify>
  <done>
    - DrawIoBuilderService exists; reads from device_stencils via Project::devicesWithStencils()
    - Role inference is SHALLOW per Nit 9 fix — 4-column grid + spike-slug lookup ONLY; no port-composition heuristic; TODO(phase-23) comment in place
    - DrawIoSpikeBuilderService is a thin shim (≤ 30 lines body) delegating to DrawIoBuilderService
    - DrawIoSpikeController refactor PRESERVES `DrawingService $drawings` constructor parameter per D-08 + Warning 2 — verified via reflection assertion in test
    - Test passes; second build() call is byte-identical (deterministic)
    - Spike admin route smoke test 200 OK on a real project; saveXml + exportSvg methods still functional (manual or test-scoped check)
    - Lint clean on all touched PHP files
    - v1.3 D2 generator + DeviceCatalogService + device-port-catalog.json untouched (D-10) — `git diff app/Services/DeviceCatalogService.php app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php resources/data/device-port-catalog.json` returns empty
    - `public/img/manufacturers/clickshare.svg` untouched per D-14 — `git diff public/img/manufacturers/clickshare.svg` returns empty
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| device_stencils.mxgraph_xml → mxGraph rendered iframe | stencil XML is engineer-controlled (seed pack) OR auto-generated from quote data; either way must be XML-safe at emit time |
| Manufacturer SVG asset files → inline-embed into mxgraph_xml | SVG files are repo-controlled; safe |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-21.03-01 | Tampering | DrawIoBuilderService XML emission | mitigate | Reuses spike's xml() helper (htmlspecialchars ENT_XML1\|ENT_QUOTES). Equipment names from QuoteWerks are still untrusted; same protection as Plan 17 P02 Warning 7 / T-17.02-01 |
| T-21.03-02 | Spoofing | Manufacturer name → logo lookup | accept | Manufacturer is an attribute on a DeviceStencil row (engineer-controlled via seed pack OR auto-generated from quote data); spoofing the lookup just shows a different (or no) logo — visual issue, not security. D-14 needle ordering protects against ClickShare→generic-Barco regression. |
| T-21.03-03 | Information Disclosure | Logo SVG content | accept | Public assets, intentionally world-readable |
| T-21.03-04 | Denial of Service | DeviceStencilCacheService write-on-read inside builder | mitigate | First-call writes, subsequent calls SELECT only (Plan 21-01 contract). Builder calls Project::devicesWithStencils() once per build; cache writes amortise across project lifetimes. |
| T-21.03-05 | Tampering | DrawIoSpikeController dependency drop | mitigate | D-08 + Warning 2 enforces preservation of `DrawingService $drawings` second constructor param; reflection assertion in test enforces the count remains 2. Dropping it would silently break saveSpikeXml + saveSpikeSvg. |
</threat_model>

<verification>
- `php artisan test --filter='ManufacturerLogoResolverTest|DrawIoBuilderServiceTest'` all pass
- `ls public/img/manufacturers/*.svg | wc -l` returns 20 (5 existing + 15 new; clickshare.svg preserved + barco.svg added)
- `git diff public/img/manufacturers/clickshare.svg` returns empty (D-14 preservation check)
- Reflection check: `DrawIoSpikeController` constructor still has 2 parameters (D-08 + Warning 2 preservation check)
- Smoke test: visit `admin.drawings.draw-io-spike.show` route for a real project (e.g. project ID 1) → page returns 200; draw.io iframe loads; devices visible. Click "save" — saveXml route still functional (proves DrawingService dependency preserved end-to-end).
- `git diff app/Services/DeviceCatalogService.php app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php resources/data/device-port-catalog.json` returns empty (D-10)
- Lint clean on every touched PHP file with Herd PHP 8.4
- DrawIoSpikeBuilderService class still exists (`grep -l "class DrawIoSpikeBuilderService" app/`) — backwards compat preserved
</verification>

<success_criteria>
- All 5 must_have truths are observable
- DRAW-35 deliverable artifacts in place (top-20 manufacturer logos with D-14 clickshare/barco coexistence)
- Spike admin route still works against a real project (smoke test) — saveXml + exportSvg paths still functional via preserved DrawingService injection (D-08 + Warning 2)
- DrawIoBuilderService is the canonical entry point; DrawIoSpikeBuilderService preserved as shim
- Role inference is SHALLOW (Nit 9 — manufacturer-logo placement + 4-column grid only); Phase 23 owns the real layout engine
- v1.3 D2 generator UNTOUCHED (D-10)
- `public/img/manufacturers/clickshare.svg` UNTOUCHED (D-14)
- Phase 21 complete — all 6 requirement IDs (DRAW-31, 32, 33, 34, 35, 36) satisfied across the 3 plans
</success_criteria>

<output>
After completion, create `.planning/phases/21-device-port-catalog-stencil-cache/21-03-manufacturer-logos-builder-integration-SUMMARY.md` following the standard summary template.

**🚨 Files to upload to live (per D-13 / CLAUDE.md local-then-upload workflow):**

1. `public/img/manufacturers/crestron.svg`
2. `public/img/manufacturers/cisco.svg`
3. `public/img/manufacturers/qsc.svg`
4. `public/img/manufacturers/bogen.svg`
5. `public/img/manufacturers/polycom.svg`
6. `public/img/manufacturers/logitech.svg`
7. `public/img/manufacturers/shure.svg`
8. `public/img/manufacturers/sony.svg`
9. `public/img/manufacturers/extron.svg`
10. `public/img/manufacturers/biamp.svg`
11. `public/img/manufacturers/yamaha.svg`
12. `public/img/manufacturers/atlona.svg`
13. `public/img/manufacturers/lightware.svg`
14. `public/img/manufacturers/q-sys.svg`
15. `public/img/manufacturers/barco.svg` (NEW — coexists with existing clickshare.svg per D-14; do NOT replace clickshare.svg)
16. `app/Services/Drawings/ManufacturerLogoResolver.php`
17. `app/Services/Drawings/DrawIoBuilderService.php`
18. `app/Services/Drawings/DrawIoSpikeBuilderService.php` (shim — replaces existing file)
19. `app/Http/Controllers/Admin/DrawIoSpikeController.php` (preserves `DrawingService $drawings` parameter per D-08 + Warning 2)

**DO NOT upload:** `public/img/manufacturers/clickshare.svg` is unchanged (preservation per D-14) — already on live from spike, do not overwrite.

(Tests stay local — do not deploy `tests/`.)

**Post-upload commands on live (in order):**
```bash
php artisan migrate                     # safe re-run; no-op if already migrated
php artisan db:seed --class=DeviceStencilSeeder   # safe re-run; idempotent
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

**Verification on live AFTER upload:**
1. Visit `admin.drawings.draw-io-spike.show` for a real project — page MUST load 200 OK; the draw.io iframe MUST render device cards from the project's equipment_list (mix of curated + Tier 1 placeholders depending on quote contents)
2. Click "save" inside the draw.io editor — saveXml route MUST persist via DrawingService::saveSpikeXml (proves D-08 + Warning 2 preservation end-to-end)
3. Visual confirmation: pick a project whose quote includes a Crestron part — the rendered stencil's manufacturer header bar should show CRESTRON in the top-20 logo style
4. Visual confirmation: pick a project whose quote includes "Barco ClickShare CX-50" — the rendered stencil's manufacturer header bar should show the existing CLICKSHARE logo (NOT the new generic BARCO logo) — proves D-14 needle ordering on production data
5. `\App\Models\DeviceStencil::count()` should reflect seed pack (~58-78) + any Tier 1 placeholders auto-created by the live admin route loads
6. `git diff app/Services/DeviceCatalogService.php app/Services/Drawings/SchematicGeneratorService.php public/img/manufacturers/clickshare.svg` returns empty — v1.3 schematic pipeline still alive AND clickshare.svg preserved
</output>
</content>
