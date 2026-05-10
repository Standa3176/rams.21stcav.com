---
phase: 21-device-port-catalog-stencil-cache
plan: 01
subsystem: database
tags: [drawings, mxgraph, schema, eloquent, cache, foundation, v2.0]

# Dependency graph
requires:
  - phase: 17-system-schematics-shared-foundations
    provides: DeviceCatalogService case-insensitive trimmed lookup pattern (mirrored by DeviceStencil::normalisePartNumber)
  - phase: drawio-spike-260509-ibx
    provides: DrawIoSpikeBuilderService::xml() XML-escape helper pattern (mirrored by AutoGenericStencilGenerator::xml)
provides:
  - "device_stencils table — cross-project mxGraph stencil cache keyed on normalised part_number, race-safe via unique index"
  - "device_ports table — per-device port metadata (label/side/connector/signal/direction/sort_order/port_id) ready for Phase 22 cable schedule FKs"
  - "DeviceStencil + DevicePort Eloquent models with enum-style SOURCE_/SIDE_/DIRECTION_ constants matching the Device::ROLE_* convention"
  - "AutoGenericStencilGenerator — Tier 1 placeholder mxGraph emitter, deterministic, XML-escaped, no port rails per D-04"
  - "DeviceStencilCacheService — firstOrCreate(part_number) cross-project cache; mock-asserted no-generator-on-hit short-circuit; race-safety rationale documented per D-03"
  - "Project::devicesWithStencils() accessor — returns hardware lines paired with their DeviceStencil row, auto-creates Tier 1 placeholders on first read"
affects: [phase 21-02 seed pack, phase 21-03 builder integration, phase 22 cable port FKs, phase 23 renderer, phase 24 curation UI, phase 25 AI port extraction]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Generic-named tables (no rams_/project_ prefix) for SCC merge readiness (D-09)"
    - "firstOrCreate(part_number) cross-project caching with documented no-transaction rationale (race-safety via unique index, D-03)"
    - "Cache-hit short-circuit BEFORE building auto-generic payload (avoids wasted generator work)"
    - "XML-escape every interpolated user value via htmlspecialchars(ENT_XML1|ENT_QUOTES) — mirrors v1.3 DrawIoSpikeBuilderService::xml() pattern"
    - "Side-effect on a read accessor (Project::devicesWithStencils auto-creates Tier 1 placeholders) — explicitly documented in docblock"

key-files:
  created:
    - "database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php"
    - "app/Models/DeviceStencil.php"
    - "app/Models/DevicePort.php"
    - "app/Services/Drawings/AutoGenericStencilGenerator.php"
    - "app/Services/Drawings/DeviceStencilCacheService.php"
    - "tests/Unit/Models/DeviceStencilTest.php"
    - "tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php"
    - "tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php"
    - "tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php"
  modified:
    - "app/Models/Project.php"

key-decisions:
  - "Cache-hit short-circuit added inside DeviceStencilCacheService — was implementation choice not in plan; needed so AutoGenericStencilGenerator is NOT invoked on cache hit (Mockery test asserts shouldNotReceive('build') on hit)"
  - "Body-text fallback: when model is empty, emit display_name in the body so the auto-generic card never renders blank text"
  - "Cache writes pass merged hints (manufacturer/model/name + part_number) to AutoGenericStencilGenerator so display_name + manufacturer/model columns are populated even when only part_number is supplied"

patterns-established:
  - "Pattern: enum-style public const on the model (DeviceStencil::SOURCE_*, DevicePort::SIDE_*/DIRECTION_*) — mirrors Device::ROLE_* convention; consumed by Phase 21-02 seeder + Phase 24 curation UI"
  - "Pattern: cross-project cache via firstOrCreate(part_number) with unique-index race-safety rather than DB::transaction wrapping"
  - "Pattern: enriched-line return shape (...input_line, 'stencil' => DeviceStencil|null) for bulk operations — Phase 22's cable resolver should mirror this"

requirements-completed: [DRAW-31, DRAW-32, DRAW-34, DRAW-36]

# Metrics
duration: 13min
completed: 2026-05-10
---

# Phase 21 Plan 01: Schema + Models + Cache Service Summary

**Two new generic-named tables (`device_stencils` + `device_ports`) with Eloquent models, a Tier 1 auto-generic mxGraph generator, cross-project firstOrCreate cache service, and a `Project::devicesWithStencils()` accessor — the v2.0 engineering-grade drawings foundation that Phases 21-02, 21-03, 22, 23, and 24 all build on.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-05-10T08:26:35Z
- **Completed:** 2026-05-10T08:39:11Z
- **Tasks:** 3 (all TDD: RED → GREEN per task)
- **Files modified:** 10 (1 modified, 9 created)
- **Tests:** 35 passed / 126 assertions across the new suite; 89 / 330 across the full drawings suite (1 pre-existing D2-binary skip on dev)

## Accomplishments

- `device_stencils` + `device_ports` tables created with the full D-02 column shape, FK cascade, unique index on part_number, and compound unique on (device_stencil_id, port_id)
- DeviceStencil + DevicePort Eloquent models with the SOURCE_/SIDE_/DIRECTION_ enum constants Phase 21-02 seeder + Phase 24 curation UI will consume
- AutoGenericStencilGenerator emits a 220x140 brand-aligned `<shape>` document per D-04 — XSS-escaped (T-21.01-01), no port rails, deterministic across calls, well-formed XML (DOMDocument::loadXML returns YES)
- DeviceStencilCacheService implements the `firstOrCreate(part_number)` cross-project caching contract per D-03 — second call returns same row (no duplicate insert), engineer-curated rows survive subsequent reads (Phase 24 forward-compat), generator NOT invoked on cache hit (Mockery-asserted)
- Project::devicesWithStencils() accessor returns enriched hardware lines, side-effect-documents the cache miss → Tier 1 placeholder auto-create, falls back from extracted_data['equipment'] to equipment_list for legacy projects
- D-10 verified — zero diff against the v1.3 D2 generator surface (DeviceCatalogService / SchematicGeneratorService / SchematicD2SourceBuilder / DrawingDataResolverService / device-port-catalog.json)

## Task Commits

Each task was committed atomically (TDD RED + GREEN per task):

1. **Task 1: Migration + Models + Source/Side/Direction enum constants**
   - RED: `72f7e94` (test: 13 failing tests for DeviceStencil + DevicePort)
   - GREEN: `2e333ae` (feat: migration + DeviceStencil + DevicePort models)

2. **Task 2: AutoGenericStencilGenerator + DeviceStencilCacheService**
   - RED: `e63da58` (test: 15 failing tests for the two services)
   - GREEN: `06c9052` (feat: AutoGenericStencilGenerator + DeviceStencilCacheService)

3. **Task 3: Project::devicesWithStencils() accessor + feature test**
   - RED: `8711123` (test: 7 failing feature tests for the accessor)
   - GREEN: `45ee705` (feat: devicesWithStencils() accessor on Project model)

**Plan metadata commit:** appended after this summary writes (includes SUMMARY.md, STATE.md, ROADMAP.md, REQUIREMENTS.md updates).

## Files Created/Modified

### Created (production)

- `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` — anonymous-class migration creating both tables in dependency order with FK cascade and compound unique index
- `app/Models/DeviceStencil.php` — Eloquent model with SOURCE_AUTO_GENERATED / SOURCE_ENGINEER_CURATED / SOURCE_AI_EXTRACTED enum constants, `ports() HasMany` ordered by sort_order, `isCurated()` helper, static `normalisePartNumber()` (lowercase trim mirroring DeviceCatalogService key derivation)
- `app/Models/DevicePort.php` — Eloquent model with SIDE_LEFT/RIGHT/TOP/BOTTOM, DIRECTION_IN/OUT/IO enum constants, `stencil() BelongsTo`, decimal:4 casts on x_pct/y_pct
- `app/Services/Drawings/AutoGenericStencilGenerator.php` — Tier 1 placeholder builder, 220x140 rounded-rect outer, 30px teal header bar, manufacturer/model/part_number text, "Tier 1 placeholder" annotation at 7pt grey at the bottom, NO `<connections>` / `<constraint>` elements, deterministic, XML-escaped
- `app/Services/Drawings/DeviceStencilCacheService.php` — `resolveForPartNumber()` short-circuits on cache hit then falls back to firstOrCreate; `resolveMany()` returns enriched lines with stencil = null for empty part_numbers; full race-safety / no-transaction rationale documented on the resolveForPartNumber docblock per D-03 + T-21.01-03

### Created (tests)

- `tests/Unit/Models/DeviceStencilTest.php` — 13 tests / 52 assertions across migration shape, FK cascade, enum constants, normalisePartNumber, ports() relation type + ordering, metadata array cast
- `tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php` — 7 tests / 22 assertions covering payload shape, mxgraph_xml content, XSS escaping, no-port-rails, determinism, display_name fallback ladder
- `tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php` — 8 tests / 26 assertions covering first-create, cache hit, case-insensitive lookup, engineer-curated preservation, resolveMany shape, null stencil for empty part_number, mock-asserted generator-not-invoked-on-hit, mock-asserted generator-invoked-once-on-miss
- `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` — 7 tests / 26 assertions covering enrichment, category filter, empty part_number tolerance, cache hit on second call, engineer-curated row preservation, empty package, extracted_data → equipment_list legacy fallback

### Modified

- `app/Models/Project.php` — added `devicesWithStencils(): array` immediately after `hardwarePartNumbers()`. Mirrors hardwarePartNumbers loop pattern (read latestPackage, filter to category=hardware) but routes through `app(DeviceStencilCacheService::class)->resolveMany($lines)`. PHPDoc documents the side-effect (Tier 1 auto-create on first read per D-07) and the race-safety rationale (no DB::transaction wrap per D-03).

## Decisions Made

- **Cache-hit short-circuit BEFORE building auto-generic payload (D-03 implementation choice):** the plan said "DeviceStencil::firstOrCreate(['part_number' => normalised], $autoGenericPayload)" which would invoke `AutoGenericStencilGenerator::build()` even on cache hit (because PHP evaluates the second array literally before passing it). The Mockery test "generator is not invoked on cache hit" asserts `shouldNotReceive('build')`. Resolved by adding an explicit `Where::where('part_number', $normalised)->first()` short-circuit before falling back to firstOrCreate on miss. This preserves the firstOrCreate race-safety semantics (loser of a concurrent first-call still gets the existing row via firstOrCreate's catch-and-retry) while avoiding wasted generator work on the common-case cache hit. Race-safety rationale is unchanged from D-03 — documented on the docblock so a future dev doesn't reflexively wrap in DB::transaction.

- **Body-text fallback when model is empty:** plan said "model (or display_name) in #1B7A7A 11pt" — implemented as `$bodyText = $model !== '' ? $model : $displayName` so cards always render some identifying body text even when manufacturer is the only metadata supplied.

- **Hint merging in cache miss path:** `resolveForPartNumber` merges `$hints` with `['part_number' => $partNumber]` before passing to the generator. This guarantees the generator's display_name fallback ladder (name → manufacturer+model → part_number → "Unknown Device") always has the part_number available even when callers pass only manufacturer/model.

## Deviations from Plan

None - plan executed exactly as written. The cache-hit short-circuit (decisions #1 above) is an implementation detail that satisfies the plan's behavior contract more efficiently; the public API and race-safety semantics match D-03 verbatim.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

**Plan 21-02 (seed pack: promote 5 spike + 53 v1.3 entries; hand-curate gap to top-50):**
- DeviceStencil model + table ready for `updateOrCreate(['part_number' => normalised(...)], [...])` seeder writes
- DevicePort model + FK cascade ready for hand-curated port rail rows
- DeviceStencil::normalisePartNumber() helper ready to mirror the seeder's `whereRaw('LOWER(TRIM(part_number)) = ?', ...)` pattern from DeviceCatalogSeeder

**Plan 21-03 (manufacturer logos + DrawIoBuilderService rename + DB-backed builder):**
- Project::devicesWithStencils() ready to consume in the rewired builder
- DeviceStencil.mxgraph_xml + logo_svg columns ready to back the builder's per-cell shape lookup
- Race-safety contract means concurrent renderer hits won't double-insert on cold-cache requests

**Phase 22 (cable schedule port FKs):**
- DevicePort.id ready for `cable_schedule_items.source_port_id` + `dest_port_id` FK targets
- DevicePort.port_id (varchar 50, unique per stencil) is the engineer-supplied stable identifier — Phase 22's cable resolver should match port_id, not the auto-incrementing FK, so port rows can be re-created without breaking schedule references

**Phase 23 (renderer):**
- DeviceStencil.mxgraph_xml drives stencil shape lookup
- DeviceStencil.default_width / default_height drive cell sizing
- DevicePort metadata (side / connector_type / signal_type / direction / y_pct / x_pct) drives port-rail glyph placement
- DeviceStencil::isCurated() ready for "needs curation" badge surfacing

**Phase 24 (curation UI):**
- DeviceStencil.metadata (json nullable) reserved for curation extras (notes, last-edited-by, etc.)
- DeviceStencil.source flip from auto-generated → engineer-curated tested + asserted to survive subsequent cache reads
- DevicePort row insertion / mutation contract is FK-cascade-safe — deleting a stencil clears its ports

## Self-Check: PASSED

Verified all created files exist and all task commits are reachable:

```
FOUND: database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php
FOUND: app/Models/DeviceStencil.php
FOUND: app/Models/DevicePort.php
FOUND: app/Services/Drawings/AutoGenericStencilGenerator.php
FOUND: app/Services/Drawings/DeviceStencilCacheService.php
FOUND: app/Models/Project.php (devicesWithStencils() accessor present)
FOUND: tests/Unit/Models/DeviceStencilTest.php
FOUND: tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php
FOUND: tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php
FOUND: tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php
FOUND commit: 72f7e94 (RED Task 1)
FOUND commit: 2e333ae (GREEN Task 1)
FOUND commit: e63da58 (RED Task 2)
FOUND commit: 06c9052 (GREEN Task 2)
FOUND commit: 8711123 (RED Task 3)
FOUND commit: 45ee705 (GREEN Task 3)
```

## 🚨 Files to upload to live (per D-13 / CLAUDE.md local-then-upload workflow)

1. `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php`
2. `app/Models/DeviceStencil.php`
3. `app/Models/DevicePort.php`
4. `app/Services/Drawings/DeviceStencilCacheService.php`
5. `app/Services/Drawings/AutoGenericStencilGenerator.php`
6. `app/Models/Project.php`

(Tests stay local — do not deploy `tests/`.)

### Post-upload commands on live (in order)

```bash
php artisan migrate                     # creates device_stencils + device_ports tables
php artisan config:clear                # belt-and-braces; new model class autoload
php artisan cache:clear                 # belt-and-braces; new model class autoload
```

(No `.env` or config touched in Plan 21-01, but cache:clear after a model addition keeps the autoloader honest.)

### Verification on live AFTER migration

- Visit `admin.drawings.draw-io-spike.show` for a real project — page MUST still load (Plan 21-01 doesn't touch the spike builder; smoke-tests the new tables don't break autoload)
- `php artisan tinker` → `\App\Models\Project::find(1)->devicesWithStencils()` should return an array (may be empty if no package); after first run, `\App\Models\DeviceStencil::count()` should equal the number of unique hardware part_numbers in that project

---
*Phase: 21-device-port-catalog-stencil-cache*
*Plan: 01*
*Completed: 2026-05-10*
