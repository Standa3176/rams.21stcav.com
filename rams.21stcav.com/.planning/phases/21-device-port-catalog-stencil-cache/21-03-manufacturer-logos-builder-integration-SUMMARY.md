---
phase: 21-device-port-catalog-stencil-cache
plan: 03
subsystem: drawings
tags: [drawings, manufacturer-logos, builder, mxgraph, integration, v2.0]

# Dependency graph
requires:
  - phase: 21-device-port-catalog-stencil-cache
    plan: 01
    provides: Project::devicesWithStencils() accessor, DeviceStencilCacheService firstOrCreate contract, AutoGenericStencilGenerator Tier 1 placeholder shape
  - phase: 21-device-port-catalog-stencil-cache
    plan: 02
    provides: 96 engineer-curated DeviceStencil rows (5 spike + 53 v1.3 + 39 gap-fill) + 40 DevicePort rows; manifest at resources/data/device-stencils-seed/clickshare-bar-pro.json carries D-14 manufacturer/logo split (manufacturer=Barco, logo_svg_path=/img/manufacturers/clickshare.svg)
  - phase: drawio-spike-260509-ibx
    provides: DrawIoSpikeBuilderService::xml() XML-escape helper pattern + emitMxGraph base64-stencil embed + canonical Teams Room cable chain heuristic (preserved verbatim by Plan 21-03)
provides:
  - "20 manufacturer-logo SVGs at public/img/manufacturers/{slug}.svg — 5 existing spike (clickshare/neat/netgear/samsung/sennheiser) PRESERVED + 15 new (crestron/cisco/qsc/bogen/polycom/logitech/shure/sony/extron/biamp/yamaha/atlona/lightware/q-sys/barco) per D-06 (viewBox 0 0 100 30, fill=currentColor)"
  - "ManufacturerLogoResolver — case-insensitive substring lookup with most-specific-first needle ordering: q-sys > qsc, clickshare > barco (D-14), poly alias → polycom; resolveSvg / resolveAssetPath / knownManufacturers public API; per-instance file-read memoisation"
  - "DrawIoBuilderService — DB-backed mxGraph builder reading from Project::devicesWithStencils(); 4-column shallow grid layout (videobar/byod/mic | switch | display | other) per Nit 9; canonical Teams Room cable chain preserved verbatim from spike for Phase 22 port-FK migration"
  - "DrawIoSpikeBuilderService — collapsed to a thin backwards-compat shim (~10-line body) delegating to DrawIoBuilderService; @deprecated docblock; D-08 preserves the class for any external reference"
  - "DrawIoSpikeController — first constructor parameter type-hint flipped from DrawIoSpikeBuilderService → DrawIoBuilderService; second parameter DrawingService PRESERVED per D-08 + Warning 2; route names + Blade view + saveXml/exportSvg method signatures unchanged"
affects: [phase 22 cable port FKs, phase 23 layout engine + port-rail glyphs, phase 24 curation UI]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Most-specific-first needle ordering for substring-match lookup tables (mirrors DrawIoSpikeBuilderService::STENCIL_ALIASES iteration pattern; new use case is brand-name → logo-slug resolution)"
    - "Deprecated-shim pattern for class rename — keep the original class with a 10-line delegate body + @deprecated docblock so external references survive the transition"
    - "Constructor-injected dependency declared even when not directly invoked (DeviceStencilCacheService on DrawIoBuilderService) — documents the contract that the model layer accessor honours the cache miss → Tier 1 auto-create side-effect"

key-files:
  created:
    - "public/img/manufacturers/crestron.svg"
    - "public/img/manufacturers/cisco.svg"
    - "public/img/manufacturers/qsc.svg"
    - "public/img/manufacturers/bogen.svg"
    - "public/img/manufacturers/polycom.svg"
    - "public/img/manufacturers/logitech.svg"
    - "public/img/manufacturers/shure.svg"
    - "public/img/manufacturers/sony.svg"
    - "public/img/manufacturers/extron.svg"
    - "public/img/manufacturers/biamp.svg"
    - "public/img/manufacturers/yamaha.svg"
    - "public/img/manufacturers/atlona.svg"
    - "public/img/manufacturers/lightware.svg"
    - "public/img/manufacturers/q-sys.svg"
    - "public/img/manufacturers/barco.svg"
    - "app/Services/Drawings/ManufacturerLogoResolver.php"
    - "app/Services/Drawings/DrawIoBuilderService.php"
    - "tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php"
    - "tests/Feature/Drawings/DrawIoBuilderServiceTest.php"
  modified:
    - "app/Services/Drawings/DrawIoSpikeBuilderService.php"
    - "app/Http/Controllers/Admin/DrawIoSpikeController.php"

key-decisions:
  - "Memoisation-via-sentinel pattern in ManufacturerLogoResolver — used `string|false` cache value (false = checked-and-missing) instead of separate `?string $svg + bool $checked` pair so a single array_key_exists check tells us whether the slug has been resolved. Mirrors common PSR-style cache-miss convention."
  - "DeviceStencilCacheService injected into DrawIoBuilderService constructor even though build() doesn't call it directly — documents the contract that Project::devicesWithStencils() honours the cache miss side-effect; keeps container wiring honest if a future refactor moves the call back into the builder. Rejected the cleaner alternative (drop the injection) to preserve the contract-as-code documentation."
  - "Layout heuristic kept aggressively shallow per Nit 9 — 4-column grid with hardcoded slug → role lookup for the 5 spike-promoted stencils only. Resisted the temptation to backfill role inference from manifest metadata (would conflict with Phase 23's planned layout engine) or from port-composition rules (explicitly deferred to Phase 23)."

patterns-established:
  - "Pattern: brand-name slug-resolver with collision-prone pairs ordered most-specific-first (q-sys before qsc; clickshare before barco) — Phase 24 curation UI's 'manufacturer' picker should mirror this ordering when rendering the list of choices"
  - "Pattern: deprecated-shim for class rename instead of immediate deletion — preserves backwards compatibility during multi-plan rollouts where dependent code may not have migrated yet"
  - "Pattern: TODO(phase-N) inline marker pointing at CONTEXT.md deferred section so future devs investigating the 'why is this so simple?' question land at the planning record, not just a bare TODO"

requirements-completed: [DRAW-35]

# Metrics
duration: 9min
completed: 2026-05-10
---

# Phase 21 Plan 03: Manufacturer Logos + Builder Integration Summary

**Top-15 manufacturer logo glyphs ship at `public/img/manufacturers/` (rounding out the top-20 coverage with the 5 spike logos); `DrawIoBuilderService` now reads from the new `device_stencils` table via `Project::devicesWithStencils()` instead of the spike's hand-coded JSON pack — every hardware part_number on a project produces a stencil cell (curated stencils with full mxgraph_xml from Plan 21-02's seed pack; uncatalogued part_numbers as Tier 1 placeholders auto-created by Plan 21-01's cache contract). DrawIoSpikeBuilderService preserved as a thin backwards-compat shim per D-08. ClickShare/Barco D-14 collision rule enforced via needle-ordering in ManufacturerLogoResolver. DrawIoSpikeController's two-parameter constructor (builder + DrawingService) preserved per D-08 + Warning 2 with reflection-asserted regression test.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-05-10T09:14:26Z
- **Completed:** 2026-05-10T09:24:20Z
- **Tasks:** 2 (both TDD: RED → GREEN per task)
- **Files modified:** 21 (19 created, 2 modified)
- **Tests:** 20 passed / 68 assertions across the new suite (14 ManufacturerLogoResolver + 6 DrawIoBuilderService); 73 + 58 = 131 / 1633 across the full Drawings test suite (1 pre-existing D2-binary skip on dev — zero regression vs Plan 21-02 baseline)

## Accomplishments

- 15 new manufacturer SVG glyphs landed at `public/img/manufacturers/` per D-06 (viewBox 0 0 100 30, fill=currentColor, single-colour text-based glyphs in the 400-700 byte range, well under the 4 KB budget): crestron, cisco, qsc, bogen, polycom, logitech, shure, sony, extron, biamp, yamaha, atlona, lightware, q-sys, barco
- 20-asset coverage achieved (15 new + 5 existing spike) — DRAW-35 deliverable in place
- `ManufacturerLogoResolver` ships with case-insensitive substring lookup ordered most-specific-first per the D-14 collision rule: `q-sys` before `qsc` (so a Q-SYS Core 110f doesn't pick up the QSC logo) AND `clickshare` before `barco` (so Barco ClickShare products keep using the existing spike asset). `poly` is aliased to `polycom` for the rebrand.
- Public contract: `resolveSvg(?string)` returns inline SVG markup or null; `resolveAssetPath(?string)` returns `/img/manufacturers/{slug}.svg` for Phase 24 curation UI; `knownManufacturers()` returns the 20-slug list sorted alphabetically. Per-instance file-read memoisation via string|false sentinel.
- `DrawIoBuilderService` reads from `Project::devicesWithStencils()` (Plan 21-01 cache contract) — every hardware part_number lands a stencil cell. Curated stencils render with full mxgraph_xml (base64-embedded via `shape=stencil(...)`); uncatalogued part_numbers auto-create as Tier 1 placeholders on first read.
- Layout heuristic INTENTIONALLY shallow per Nit 9 — 4-column grid keyed off the 5 spike-promoted slugs (videobar/byod/ceiling-mic | switch | display | other), with TODO(phase-23) inline marker pointing at CONTEXT.md's deferred section. NO port-composition heuristic dispatch.
- Cable derivation preserved verbatim from spike (canonical Teams Room signal chain: videobar → display, byod → videobar, ceiling-mic / videobar / display → switch). cableList parameter accepted for forward-compat with Phase 22's port-FK migration.
- `DrawIoSpikeBuilderService` collapsed to a 10-line backwards-compat shim — constructor takes `DrawIoBuilderService`, `build()` delegates `return $this->builder->build($project)`. `@deprecated` docblock points at DrawIoBuilderService. Class preserved per D-08 so any external reference survives.
- `DrawIoSpikeController` refactored: first constructor parameter type-hint flipped from `DrawIoSpikeBuilderService` → `DrawIoBuilderService`; second parameter `DrawingService $drawings` PRESERVED per D-08 + Warning 2 (consumed by `saveXml` via `saveSpikeXml` AND `exportSvg` via `saveSpikeSvg`). Reflection assertion in `DrawIoBuilderServiceTest::test_d08_spike_controller_constructor_has_two_parameters` enforces the 2-parameter count + both type names.
- Route names + Blade view + show/saveXml/exportSvg method signatures unchanged. `php artisan route:list --name=draw-io-spike` returns all 3 routes.
- D-10 verified: zero diff against `app/Services/Drawings/DeviceCatalogService.php`, `app/Services/Drawings/SchematicGeneratorService.php`, `app/Services/Drawings/SchematicD2SourceBuilder.php`, `app/Services/Drawings/DrawingDataResolverService.php`, `resources/data/device-port-catalog.json` — v1.3 D2 generator surface untouched.
- D-14 verified: `git diff public/img/manufacturers/clickshare.svg` returns empty — spike asset preserved.
- Backwards-compat verified: `class_exists(DrawIoSpikeBuilderService::class)` returns true; `DrawIoSpikeBuilderService::build()` produces identical output to `DrawIoBuilderService::build()` for the same project (delegation working).

## Task Commits

Each task was committed atomically (TDD RED + GREEN per task):

1. **Task 1: 15 SVGs + ManufacturerLogoResolver**
   - RED: `6e6b174` (test: 13 failing tests for ManufacturerLogoResolver)
   - GREEN: `233dbfc` (feat: 15 SVGs + ManufacturerLogoResolver — 14 tests / 46 assertions GREEN)

2. **Task 2: DrawIoBuilderService + shim + controller refactor**
   - RED: `7d452b5` (test: 6 failing tests for DrawIoBuilderService incl. D-08 reflection check)
   - GREEN: `5936feb` (feat: rewire builder + collapse spike to shim + flip controller type-hint — 6 tests / 22 assertions GREEN; full Drawings suite 131 / 1633 GREEN with zero regression)

**Plan metadata commit:** appended after this summary writes (includes SUMMARY.md, STATE.md, ROADMAP.md updates).

## Files Created/Modified

### Created (production assets)

- `public/img/manufacturers/crestron.svg` (588 bytes — text-based CRESTRON glyph)
- `public/img/manufacturers/cisco.svg` (421 bytes)
- `public/img/manufacturers/qsc.svg` (415 bytes)
- `public/img/manufacturers/bogen.svg` (421 bytes)
- `public/img/manufacturers/polycom.svg` (497 bytes)
- `public/img/manufacturers/logitech.svg` (427 bytes)
- `public/img/manufacturers/shure.svg` (421 bytes)
- `public/img/manufacturers/sony.svg` (419 bytes)
- `public/img/manufacturers/extron.svg` (423 bytes)
- `public/img/manufacturers/biamp.svg` (421 bytes)
- `public/img/manufacturers/yamaha.svg` (423 bytes)
- `public/img/manufacturers/atlona.svg` (423 bytes)
- `public/img/manufacturers/lightware.svg` (429 bytes)
- `public/img/manufacturers/q-sys.svg` (558 bytes)
- `public/img/manufacturers/barco.svg` (706 bytes — D-14 separate file from clickshare.svg)

### Created (production code)

- `app/Services/Drawings/ManufacturerLogoResolver.php` — case-insensitive substring lookup with MANUFACTURER_NEEDLES table ordered most-specific-first per D-14 + collision-avoidance rules; per-instance file-read memoisation via string|false sentinel; resolveSvg / resolveAssetPath / knownManufacturers public contract
- `app/Services/Drawings/DrawIoBuilderService.php` — DB-backed mxGraph builder; reads from Project::devicesWithStencils(); 4-column shallow grid layout per Nit 9 with TODO(phase-23) marker; canonical Teams Room cable chain preserved verbatim; constructor-injected DeviceStencilCacheService + ManufacturerLogoResolver

### Created (tests)

- `tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php` — 14 tests / 46 assertions covering: known-manufacturer lookup, case-insensitivity, unknown returns null, null/empty input, knownManufacturers returns 20 sorted unique slugs, D-14 ClickShare-precedes-Barco assertion, D-14 fallback (Barco F50 → barco.svg), q-sys/qsc collision avoidance, poly alias → polycom, resolveAssetPath, D-14 clickshare.svg preservation, repeated-call byte-identical memoisation
- `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` — 6 tests / 22 assertions covering: build() emits valid mxGraphModel with exactly 2 vertex cells (curated + auto-generic; cable category filtered out), curated stencil mxgraph_xml base64-embedded, empty package emits valid empty graph with zero vertices, build() byte-identical across calls (deterministic), D-08 + Warning 2 reflection assertion (controller constructor has 2 parameters with correct type-hints), spike-builder shim still exists and delegates identically to DrawIoBuilderService

### Modified

- `app/Services/Drawings/DrawIoSpikeBuilderService.php` — collapsed from ~560 lines to a 10-line backwards-compat shim. Constructor takes `DrawIoBuilderService $builder`; `build(Project $project)` delegates `return $this->builder->build($project)`. Class-level `@deprecated` docblock. STENCIL_ALIASES / ROLE_COLUMN / mapEquipmentToCells / deriveCables / emitMxGraph / emptyGraph / xml() / loadStencilPack ALL moved/replaced inside DrawIoBuilderService.
- `app/Http/Controllers/Admin/DrawIoSpikeController.php` — single-line `use` statement swap: `App\Services\Drawings\DrawIoSpikeBuilderService` → `App\Services\Drawings\DrawIoBuilderService`. Constructor first parameter type-hint flipped to `DrawIoBuilderService`. SECOND PARAMETER `DrawingService $drawings` PRESERVED per D-08 + Warning 2. Method bodies (show / saveXml / exportSvg) unchanged.

## Decisions Made

- **Memoisation-via-sentinel pattern** in ManufacturerLogoResolver — used `string|false` cache value (false = checked-and-missing) instead of a separate `?string + bool $checked` pair so a single `array_key_exists` check tells us whether the slug has been resolved. Mirrors common PSR-style cache-miss convention; reads cleaner than the two-property alternative.

- **DeviceStencilCacheService injected into DrawIoBuilderService constructor** even though `build()` doesn't call it directly — the cache miss → Tier 1 auto-create side-effect lives inside `Project::devicesWithStencils()` (Plan 21-01). Declaring the dependency on the builder constructor documents the contract that the model layer honours the cache; keeps container wiring honest if a future refactor moves the call site back. Rejected the cleaner alternative (drop the injection) because the contract-as-code documentation is worth the explicit unused-by-build dependency.

- **Layout heuristic kept aggressively shallow** per Nit 9 — 4-column grid with hardcoded slug → role lookup for the 5 spike-promoted stencils only. Resisted the temptation to (a) backfill role inference from manifest metadata (would conflict with Phase 23's planned layout engine which uses metadata.role), or (b) infer roles from port-composition rules (network-switch column for stencils with >=8 same-direction ethernet ports, etc. — explicitly deferred to Phase 23 in CONTEXT.md). Inline TODO(phase-23) comment + class docblock state the deferral so future devs investigating the simplicity find the planning trail.

## Deviations from Plan

None — plan executed exactly as written. The three implementation choices (memoisation sentinel, contract-as-code injection, shallow layout discipline) were already specified in the plan's `<behavior>` and `<action>` blocks; this summary just documents the rationale.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required. All assets are self-contained in the repo.

## Next Phase Readiness

**Phase 22 (cable schedule port FKs):**

- DrawIoBuilderService::deriveCables already accepts `cable_list` for forward-compat — Phase 22 implementation replaces the canonical Teams Room chain heuristic with port-FK lookup from `cable_schedule_items.source_port_id` / `dest_port_id`
- 40 DevicePort rows from Plan 21-02's spike stencils ready to seed cable schedule FK matching tests
- ManufacturerLogoResolver covers the top-20 manufacturers — Phase 22's cable-route render can colour-code edges by manufacturer-pair if useful

**Phase 23 (renderer / layout engine):**

- DrawIoBuilderService's TODO(phase-23) marker points future implementers at CONTEXT.md deferred section
- 4-column shallow heuristic is the "before" baseline against which Phase 23's metadata.role-driven layout engine can be benchmarked
- ManufacturerLogoResolver provides the inline-SVG glyphs Phase 23's renderer can drop into header bars (currently the builder fetches them but doesn't yet inject — Phase 23's per-cell header treatment closes the loop)
- shape=stencil(base64) embedding pattern proven on production data — Phase 23's port-rail glyphs can layer on top using the same encoding

**Phase 24 (curation UI):**

- ManufacturerLogoResolver::knownManufacturers() returns the 20-slug list sorted alphabetically — ready-made dropdown source for the curation UI's manufacturer picker
- ManufacturerLogoResolver::resolveAssetPath() returns the public `/img/manufacturers/{slug}.svg` URL — Phase 24's `<img src="...">` previews can render server-rendered (no inline SVG balloon) and survive browser caching
- D-14 needle ordering (clickshare BEFORE barco) is the rule the curation UI must mirror when rendering the manufacturer picker so engineers don't get confused by ClickShare being a Barco product

## Self-Check: PASSED

Verified all created files exist and all task commits are reachable:

```
FOUND: public/img/manufacturers/crestron.svg
FOUND: public/img/manufacturers/cisco.svg
FOUND: public/img/manufacturers/qsc.svg
FOUND: public/img/manufacturers/bogen.svg
FOUND: public/img/manufacturers/polycom.svg
FOUND: public/img/manufacturers/logitech.svg
FOUND: public/img/manufacturers/shure.svg
FOUND: public/img/manufacturers/sony.svg
FOUND: public/img/manufacturers/extron.svg
FOUND: public/img/manufacturers/biamp.svg
FOUND: public/img/manufacturers/yamaha.svg
FOUND: public/img/manufacturers/atlona.svg
FOUND: public/img/manufacturers/lightware.svg
FOUND: public/img/manufacturers/q-sys.svg
FOUND: public/img/manufacturers/barco.svg
FOUND: public/img/manufacturers/clickshare.svg (D-14 PRESERVED — git diff empty)
FOUND: app/Services/Drawings/ManufacturerLogoResolver.php
FOUND: app/Services/Drawings/DrawIoBuilderService.php
FOUND: app/Services/Drawings/DrawIoSpikeBuilderService.php (shim — class still exists per D-08)
FOUND: app/Http/Controllers/Admin/DrawIoSpikeController.php (DrawingService $drawings preserved per D-08 + Warning 2)
FOUND: tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php
FOUND: tests/Feature/Drawings/DrawIoBuilderServiceTest.php
FOUND commit: 6e6b174 (RED Task 1 — 13 failing resolver tests)
FOUND commit: 233dbfc (GREEN Task 1 — 15 SVGs + ManufacturerLogoResolver)
FOUND commit: 7d452b5 (RED Task 2 — 6 failing builder tests)
FOUND commit: 5936feb (GREEN Task 2 — DrawIoBuilderService + shim + controller refactor)
```

Total file count `ls public/img/manufacturers/*.svg | wc -l` = 20 (✓ matches plan).

D-10 + D-14 invariants verified via `git diff` — empty for both v1.3 D2 generator surface AND clickshare.svg.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries introduced. The plan's threat register entries (T-21.03-01 through T-21.03-05) all fall on pre-existing surfaces inherited from Plan 21-01 (cache write-on-read), Plan 17 P02 (XML escape), and the spike (saveXml / exportSvg). The existing mitigations (htmlspecialchars escape on every interpolated user value, unique index race-safety on device_stencils.part_number, reflection-asserted preservation of DrawingService dependency) carry forward unchanged.

## 🚨 Files to upload to live (per D-13 / CLAUDE.md local-then-upload workflow)

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
18. `app/Services/Drawings/DrawIoSpikeBuilderService.php` (shim — replaces existing file body)
19. `app/Http/Controllers/Admin/DrawIoSpikeController.php` (preserves `DrawingService $drawings` parameter per D-08 + Warning 2)

**DO NOT upload:** `public/img/manufacturers/clickshare.svg` is unchanged (preservation per D-14) — already on live from spike, do not overwrite. Tests stay local — do not deploy `tests/`.

### Post-upload commands on live (in order)

```bash
php artisan migrate                                       # safe re-run; no-op if Plan 21-01 already migrated
php artisan db:seed --class=DeviceStencilSeeder           # safe re-run; idempotent — populates ~96 stencils + 40 ports
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Verification on live AFTER upload

1. Visit `admin.drawings.draw-io-spike.show` for a real project — page MUST load 200 OK; the draw.io iframe MUST render device cards from the project's equipment_list (mix of curated + Tier 1 placeholders depending on quote contents)
2. Click "save" inside the draw.io editor — saveXml route MUST persist via `DrawingService::saveSpikeXml` (proves D-08 + Warning 2 preservation end-to-end)
3. Visual confirmation: pick a project whose quote includes a Crestron part — the rendered stencil's manufacturer header bar should show CRESTRON in the top-20 logo style (NOTE: the builder fetches `manufacturer_logo` SVG into the per-cell array but the actual header-bar inline-SVG injection is Phase 23's render-pass deliverable; the resolver + asset pipeline is in place and ready)
4. Visual confirmation: pick a project whose quote includes "Barco ClickShare CX-50" — the rendered stencil's manufacturer header bar should show the existing CLICKSHARE logo (NOT the new generic BARCO logo) — proves D-14 needle ordering on production data
5. `\App\Models\DeviceStencil::count()` should reflect seed pack (~96) + any Tier 1 placeholders auto-created by the live admin route loads
6. `git diff app/Services/Drawings/DeviceCatalogService.php app/Services/Drawings/SchematicGeneratorService.php public/img/manufacturers/clickshare.svg` returns empty — v1.3 schematic pipeline still alive AND clickshare.svg preserved

---
*Phase: 21-device-port-catalog-stencil-cache*
*Plan: 03*
*Completed: 2026-05-10*
