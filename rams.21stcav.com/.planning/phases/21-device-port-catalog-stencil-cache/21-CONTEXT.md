# Phase 21: Device Port Catalog + Stencil Cache - Context

**Gathered:** 2026-05-10
**Status:** Ready for planning
**Milestone:** v2.0 Engineering-Grade AV Drawings (Phase 1 of 5)
**Discussion outcome:** All clear — milestone-level decisions are locked in `.planning/REQUIREMENTS.md` and ROADMAP.md `## 🚧 v2.0` section. Spike `260509-ibx` already validated the platform (draw.io / mxGraph self-hosted) and the visual contract (XTEN-AV PAGING SYSTEM reference shared 2026-05-09). Phase 21 is the foundation everything else depends on; this CONTEXT.md captures the locked decisions so the planner can execute without revisiting.

<domain>
## Phase Boundary

Phase 21 ships the **two new tables** that the rest of v2.0 builds on:

1. **`device_ports`** — per-device port metadata: `label`, `side` (left/right/top/bottom), `connector_type` (HDMI / USB-A / USB-B / USB-C / RJ45 / RJ45-PoE / RS-232 / 3.5mm / XLR / PHX / DisplayPort / etc.), `signal_type` (audio / video / control / network / USB / power), `direction` (in / out / io), `sort_order`. Drives port-to-port cable routing in Phase 22.

2. **`device_stencils`** — pre-rendered mxGraph XML per `part_number`, cached cross-project via `firstOrCreate`. Drives the renderer in Phase 23. Phase 24 promotes `auto-generated` stencils to `engineer-curated` ones.

Phase 21 also ships:
- **Tier 1 auto-generic generator** — every uncatalogued `part_number` gets a placeholder stencil (rectangle, manufacturer + model + name, no port detail) on first reference. `firstOrCreate` caches per `part_number` so cross-project reuse is automatic.
- **Hand-curated top-50 seed pack** — promotes the 5 spike stencils + the v1.3 53-entry `resources/data/device-port-catalog.json` into the new tables, then hand-fills the gap to top-50 device coverage from last 12 months of 21CAV quote volume.
- **Top-20 manufacturer logo glyphs** — already have 5 from spike (clickshare / neat / netgear / samsung / sennheiser); gap-fill the next 15 (Crestron, Cisco, QSC, Bogen, Polycom, Logitech, Shure, Crestron, Q-SYS, Sony, Extron, Biamp, Yamaha, Atlona, Lightware — list curated against requirements DRAW-33).
- **`Project::devicesWithStencils()` accessor** — returns the project's `equipment_list` hardware joined to `device_stencils`, ready for Phase 23's renderer.
- **Generalised builder** — Plan 21-03 renames `DrawIoSpikeBuilderService` to `DrawIoBuilderService` and reads from the DB tables instead of the hand-coded JSON. The spike's admin route stays in place; only the underlying source flips.

**Maps requirements:** DRAW-31, DRAW-32, DRAW-33, DRAW-34, DRAW-35, DRAW-36 (6 of 28 v2.0 requirements; the foundation 1/5 of phases).

**NOT in scope:**
- Stencil curation **UI** — Phase 24's job. Phase 21 lays the data layer + service hooks so Phase 24 lands as a pure addition (admin route + Blade only).
- AI port extraction from datasheets — Phase 25's job. Phase 21's `source` enum reserves the `ai-extracted` value but does not write it.
- Cable schedule port-level FKs — Phase 22's job (`cable_schedule_items.source_port_id` etc.).
- Custom XTEN-AV-style render — Phase 23's job. Phase 21's auto-generic stencil is intentionally basic; Phase 21 lets uncatalogued items render *something* on day 1.
- Replacing the v1.3 D2 generator — Phase 25's job (DRAW-57 / DRAW-58 swap on bound PDF + O&M Manual). Phase 21 leaves `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DeviceCatalogService`, and `resources/data/device-port-catalog.json` untouched. They run alongside the new tables.

</domain>

<decisions>
## Implementation Decisions

All decisions are locked. The planner must NOT revisit them. Decision IDs are referenced in tasks via "per D-XX" so traceability is explicit.

### D-01 — Tier 1 + Tier 2 strategy combined; AI is Tier 3 polish

Phase 21 ships **Tier 1** (auto-generic placeholder per `part_number`) AND lays the data layer for **Tier 2** (engineer curation in Phase 24). AI extraction (Tier 3) lands in Phase 25.

Day 1 every project gets stencils — even uncatalogued items render *something*. Phase 24's curation UI then upgrades them in-place; cross-project propagation is automatic via the `firstOrCreate(part_number)` cache.

### D-02 — Two new tables: `device_ports` + `device_stencils`

**`device_stencils`:**
- `part_number` (varchar 100, **unique** — case-insensitive lookup via `whereRaw('LOWER(TRIM(part_number)) = ?', ...)` mirroring DeviceCatalogSeeder pattern)
- `manufacturer` (varchar 100, nullable)
- `model` (varchar 100, nullable)
- `display_name` (varchar 200, nullable — fallback to `manufacturer + model` when null)
- `mxgraph_xml` (text — full `<shape>...</shape>` XML)
- `logo_svg` (text, nullable — inline SVG glyph; if null, falls back to manufacturer logo lookup table)
- `default_width` (smallint default 220)
- `default_height` (smallint default 140)
- `source` (enum: `auto-generated` / `engineer-curated` / `ai-extracted` — defaults to `auto-generated`)
- `metadata` (json, nullable — reserved for Phase 24 curation extras: notes, last-edited-by, etc.)
- `created_at`, `updated_at`

**`device_ports`:**
- `id` (bigint PK)
- `device_stencil_id` (FK to `device_stencils.id`, cascading delete)
- `label` (varchar 100 — e.g. "HDMI 1", "LAN POE+1")
- `side` (enum: `left` / `right` / `top` / `bottom`)
- `connector_type` (varchar 50 — `hdmi` / `usb-a` / `usb-b` / `usb-c` / `rj45` / `rj45-poe` / `rs232` / `3.5mm` / `xlr` / `phoenix` / `dp` / `optical-audio` / `power` / `line-in` / etc.; **NOT** an enum — engineer-extensible)
- `signal_type` (varchar 30 — `audio` / `video` / `control` / `network` / `usb` / `power` / `speaker` / `dante` / etc.)
- `direction` (enum: `in` / `out` / `io`)
- `sort_order` (smallint — for stable rendering)
- `port_id` (varchar 50 — engineer-supplied stable identifier, e.g. `hdmi-1`, `port-3` — used as mxGraph constraint name for cable termination in Phase 23. **Unique per `device_stencil_id`**.)
- `y_pct` (decimal 5,4 nullable — vertical position 0..1 for left/right side ports, used by the renderer; null for top/bottom ports)
- `x_pct` (decimal 5,4 nullable — horizontal position 0..1 for top/bottom side ports)
- `created_at`, `updated_at`

**Generic naming, NOT RAMS-specific** — these tables port to SCC after the planned RAMS+SCC merge (per memory `rams_scc_merge.md`). No `rams_` prefix; no `project_` prefix.

### D-03 — Cross-project caching via `firstOrCreate(part_number)`

`DeviceStencilCacheService::resolveForPartNumber($partNumber, array $hints = [])`:
1. Normalise `$partNumber` (lowercase trim — mirrors DeviceCatalogService)
2. `DeviceStencil::firstOrCreate(['part_number' => $normalised], $autoGenericPayload)`
3. If hit, return existing row (Tier 2 curation already applied).
4. If miss, generator builds an auto-generic mxGraph XML from `hints` (manufacturer / model / name from the equipment line) and `firstOrCreate` writes it.

Subsequent projects with the same part_number get the cached version automatically — including any Tier 2 / Tier 3 upgrades.

**Concurrency / race-condition note:** `firstOrCreate` is NOT wrapped in an explicit DB transaction. Race condition is benign because:
- The unique index on `device_stencils.part_number` (case-insensitive enforced at app layer via `normalisePartNumber`) means a concurrent double-insert on a fresh part_number raises a `QueryException` on the loser's INSERT, which Eloquent catches and retries as a SELECT (Laravel's `firstOrCreate` implementation handles this). Net result: exactly one row, no data loss.
- Stencil rows are read-only after creation from the cache service's perspective. Updates only happen via Phase 24's curation UI (single-engineer write surface, not a hot path).
- Tier 2 / Tier 3 promotions update an existing row (no new insert), so no race.

Document this rationale in `DeviceStencilCacheService::resolveForPartNumber`'s docblock so a future dev doesn't reflexively wrap the call in a transaction (which would block on the unique index anyway and provide no benefit).

### D-04 — Auto-generic stencil shape (Tier 1)

Visually basic but structurally correct. Shape:
- Outer rounded rectangle (220 x 140 default), `#FAFAF6` fill, `#1B7A7A` stroke (matching v1.3 brand palette + spike)
- Top header bar (30px, `#1B7A7A` fill) with `manufacturer` text (white, 12pt bold)
- Below header: `model` (or `display_name`) in `#1B7A7A` 11pt
- Below model: `part_number` in italic 9pt grey
- **No port rails** — Phase 24's curation UI adds them. Tier 1 is the placeholder; engineers know on sight which devices need promoting.
- Source = `auto-generated`

### D-05 — Seed pack: promote ALL 53 v1.3 entries + 5 spike, then hand-curate gap to top-50

The seed pack process (resolved scope: **promote ALL 53 v1.3 entries**, not just top-50 overlap — sunk cost is already curated):

1. **Promote the 5 spike stencils** at `resources/data/draw-io-stencils/21cav-mtr-spike.json` (Neat Bar Pro / Samsung 65" / ClickShare Bar Pro / Sennheiser TCC2 / Netgear M4250) — split the JSON into per-stencil curation manifests at `resources/data/device-stencils-seed/{slug}.json` for git-trackable curation. Source = `engineer-curated`.

2. **Promote ALL 53 entries from v1.3 `device-port-catalog.json`** — for every entry with `u_height` + `is_rack_mounted`, generate a Tier 1.5 stencil (auto-generic header + body, but with manufacturer + model + part_number filled from the JSON) AND port rows from a hand-derived port table for the highest-volume entries (signals partially inferable: HDMI = video; RJ45 = network; USB-* = USB; XLR = audio; line-in = audio). Where signal_type can't be inferred, leave as `unclassified` and surface to Phase 24 curation. Source = `engineer-curated` for the manually-derived ports; `auto-generated` for the body shell when unedited. Tag entries that need Phase 24 polish via `metadata.needs_phase_24_curation = true`.

3. **Hand-fill the GAP from 53 v1.3 entries up to top-50 21CAV-volume coverage** — manifest in `resources/data/device-stencils-seed/{slug}.json` per device, idempotent seeder reads them. Top-50 list derived from last 12 months of 21CAV quote volume (see Plan 21-02 Task 2 for derivation method). The "gap" = top-50 part_numbers that are NOT already in the 53 v1.3 entries. Net seeded count: ~53 + delta_gap (where delta_gap = top-50 entries not in the 53 = empirically estimated 10-25 entries depending on how much overlap exists).

**Coverage target:** ≥80% of unique hardware part_numbers across a sample of recent 21CAV quotes have a curated DeviceStencil row after seeding (independent of the seed-pack source — the assertion sample comes from real production data; see Plan 21-02 Task 2 for the independent fixture-source rule).

**Idempotency:** `DeviceStencilSeeder` uses `whereRaw('LOWER(TRIM(part_number)) = ?', [...])` + `updateOrCreate` so re-running rewrites the same values without duplicating rows.

**Visual style** for the curated pack: matches the spike's 5 stencils — manufacturer logo glyph top, name + model below, port rails left/right, connector glyphs at outside edge. This is the XTEN-AV PAGING SYSTEM visual contract.

### D-06 — Top-20 manufacturer logos as inline SVG glyphs

Already shipped from spike (5): `public/img/manufacturers/{clickshare,neat,netgear,samsung,sennheiser}.svg`.

Plan 21-03 ships the next 15 (manufacturer slug-named SVG files):
- crestron, cisco, qsc, bogen, polycom, logitech, shure, q-sys, sony, extron, biamp, yamaha, atlona, lightware, barco

(Some of these manufacturers reuse existing — Barco = ClickShare, so the 15 may shrink to 13 unique. Planner verifies during Plan 21-03 task 1. Slug collision rule: see D-14.)

Each is a hand-traced or simplified inline SVG with `viewBox="0 0 100 30"` (wide-and-thin landscape glyph for the device-card header). Apache 2.0 / public-domain trademark text representation only — NOT a verbatim brand-asset copy. Single-colour `currentColor` so device cards can recolour the logo to match the header bar.

Logos store at `public/img/manufacturers/{slug}.svg`. Stencils reference via `logo_url` field OR inline via `device_stencils.logo_svg`. Plan 21-03's `DrawIoBuilderService` does the lookup at render time, NOT at seed time, so a logo swap doesn't require re-seeding.

### D-07 — `Project::devicesWithStencils()` accessor shape

```php
/**
 * @return array<int, array{
 *   part_number: string,
 *   manufacturer: ?string,
 *   model: ?string,
 *   name: string,
 *   quantity: int,
 *   area: ?string,
 *   stencil: ?DeviceStencil,
 * }>
 */
public function devicesWithStencils(): array
```

- Reads `latestPackage->extracted_data['equipment']` (fallback to `equipment_list`)
- Filters to `category === 'hardware'` (mirrors `Project::hardwarePartNumbers()`)
- Joins each line to `DeviceStencil` via `firstOrCreate(part_number)` through the cache service so missing stencils auto-generate Tier 1 placeholders **on first read** (per D-03)
- Returns ready for Phase 23 renderer consumption

**Side-effect note:** the accessor MUTATES the database when uncatalogued part_numbers are first encountered (Tier 1 placeholders auto-create). This is intentional and matches the Tier 1 strategy. It's a **read** path that warms the cache; subsequent reads are pure SELECTs. Document the side effect on the accessor's docblock. Race-condition rationale (no transaction wrapping) per D-03.

### D-08 — Generalise spike builder; preserve admin route + controller signature

Plan 21-03 renames `app/Services/Drawings/DrawIoSpikeBuilderService.php` → `app/Services/Drawings/DrawIoBuilderService.php` and rewires it to read `device_stencils.mxgraph_xml` via `Project::devicesWithStencils()` instead of the hand-coded JSON pack.

The hand-coded JSON pack at `resources/data/draw-io-stencils/21cav-mtr-spike.json` is **kept in repo as historical reference** — Plan 21-02 reads from it during the seed promotion, but neither Plan 21-03's builder nor any runtime path touches it after that.

The spike's admin route (`admin.drawings.draw-io-spike.show`) stays bound to the same controller + Blade. **Critical preservation rule:** `DrawIoSpikeController::__construct` currently takes TWO dependencies — `DrawIoSpikeBuilderService $builder` AND `DrawingService $drawings`. Plan 21-03 MUST preserve the `DrawingService $drawings` parameter (used by `saveXml` + `exportSvg` methods); only the builder type-hint flips. Any executor that drops the `DrawingService` parameter breaks `saveSpikeXml` and `saveSpikeSvg`. Engineer-facing functionality preserved during the v2.0 phase rollout.

### D-09 — RAMS+SCC merge readiness

Per memory note `rams_scc_merge.md`, the two Laravel apps merge after v2.0 ships. Phase 21's two new tables (`device_ports`, `device_stencils`) MUST use generic naming so they port to SCC without rename. NO `rams_` prefix; NO controllers (Phase 21 has zero controllers — Phase 24 ships the admin controller).

Migration class names follow Laravel anonymous-migration convention: `2026_05_10_120000_create_device_stencils_and_device_ports.php`. Single migration creates both tables in dependency order (`device_stencils` first, then `device_ports` with the FK).

### D-10 — v1.3 D2 generator left untouched

`SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, and `resources/data/device-port-catalog.json` are NOT modified. The v1.3 schematic pipeline runs alongside the new tables. Phase 25 (DRAW-57 / DRAW-58) handles the swap once the new pipeline proves out on real projects.

`resources/data/device-port-catalog.json` STAYS as the source of truth for `u_height` / `is_rack_mounted` / weight / current / BTU — Phase 18's rack elevation render depends on it. Phase 21 reads from it during seed promotion (Plan 21-02) but doesn't migrate it into the new tables; rack metadata stays separate from stencil/port metadata.

### D-11 — Test approach: feature tests over project equipment_lists

Every plan ships feature tests that hit a synthetic project's `latestPackage->extracted_data['equipment']` and assert the expected DB outcome:
- Plan 21-01: cache hit on second `devicesWithStencils()` call; auto-generic placeholder shape sanity (mxgraph_xml contains `<shape>` + manufacturer + model)
- Plan 21-02: seeder idempotency (re-run produces zero new rows); top-50 coverage assertion against an INDEPENDENT fixture sample (NOT generated from seed pack — see D-15) — ≥80% of hardware part_numbers have `engineer-curated` source
- Plan 21-03: builder smoke test on a real project's equipment_list — every hardware part has SOME stencil rendered; logo lookup hits manufacturer SVG when present

### D-12 — H-07 storage / artifact convention

Phase 21 produces NO file artifacts on disk (no DOCX / PDF / SVG output). The two new tables hold `mxgraph_xml` + `logo_svg` as text. No `DocumentArtifactStorage::TYPE_*` constant additions needed.

### D-13 — Local-edit-then-upload deployment

Per CLAUDE.md + memory `feedback_local_then_upload.md`: each plan's SUMMARY.md MUST end with a **🚨 Files to upload to live** section listing every file that needs deployment. Plan 21-01 ships a migration → SUMMARY.md MUST call out `php artisan migrate` AFTER upload, BEFORE clicking new UI elements (in this case, BEFORE re-loading the spike admin route which now expects the tables). Plans 21-02 and 21-03 also need post-upload commands (`php artisan db:seed --class=DeviceStencilSeeder` for 21-02; route cache rebuild for 21-03).

### D-14 — ClickShare / Barco slug policy (resolve filename collision)

**Rule:** `clickshare` and `barco` are TWO SEPARATE slugs. Both files coexist at `public/img/manufacturers/clickshare.svg` (already shipped from spike) AND `public/img/manufacturers/barco.svg` (Plan 21-03 ships).

**Resolver lookup order** (Plan 21-03's `ManufacturerLogoResolver`):
1. Match `clickshare` substring FIRST — returns slug `clickshare` → `clickshare.svg` (preserves the spike's existing ClickShare Bar Pro stencil).
2. Match `barco` substring SECOND — returns slug `barco` → `barco.svg` (covers other Barco product lines: F50 / F70 / G62 / etc.).

**Justification:** the spike already shipped `clickshare.svg` as the visual identity for the ClickShare Bar Pro Teams Room stencil. Renaming it to `barco.svg` (option a) would orphan that file, force a `git mv` migration, and break the spike's stencil pack visual reference. Keeping `clickshare` as a distinct product-line slug (option b) preserves the spike output, gives Plan 21-03's resolver a graceful fallback for non-ClickShare Barco products (e.g. F-series projectors), and matches AV-industry naming (engineers say "ClickShare", not "Barco wireless presentation system").

**Plan 21-03 explicit constraints:**
- DO NOT delete `public/img/manufacturers/clickshare.svg`.
- DO NOT rename it.
- DO ship `public/img/manufacturers/barco.svg` as a separate asset.
- Resolver's substring-needle table MUST list `clickshare → clickshare` BEFORE `barco → barco` so the most-specific match wins.

### D-15 — Top-50 coverage test fixture provenance (independence rule)

The `SeedPackCoverageTest` assertion (≥80% top-50 coverage) is meaningful ONLY if the fixture data is INDEPENDENT of the seed pack itself — otherwise the assertion is tautological (you'd be asserting "the seed pack covers itself").

**Required fixture provenance for Plan 21-02:**
The "top 50 reference list" used by `SeedPackCoverageTest` MUST come from one of these independent sources, in priority order:

1. **Live DB query (preferred)** — at test setup time, query `ProjectPackage::query()->whereNotNull('extracted_data')->orderBy('created_at', 'desc')->limit(200)` and aggregate the `extracted_data['equipment']` part_numbers by frequency. The query produces the test fixture at runtime — the seed pack is not consulted. The test then asserts ≥80% of the top-50 frequent part_numbers have a curated DeviceStencil.

2. **Stamped JSON snapshot (fallback when no live data)** — if the test environment has no project data, ship `tests/fixtures/seed-coverage/top-50-snapshot.json` containing the top-50 part_numbers from a one-time real-data extraction at planning time, frozen as a test fixture. The snapshot's provenance is documented in its first-line comment: "Generated YYYY-MM-DD from production projects 1..N — DO NOT regenerate from seed pack".

3. **5 synthetic project fixtures** — if neither live data nor snapshot is available, the test runs against `tests/fixtures/seed-coverage/{1..5}.json` where each fixture's part_numbers are HAND-CHOSEN to mix curated + uncatalogued (≥20% intentionally uncatalogued so the 80% threshold is non-trivial). The fixture creation date + author MUST be in a top-line comment.

**Forbidden:** generating the "top 50 reference list" from `_top-50-curated.json` directly. That makes the assertion circular.

Plan 21-02 Task 2 MUST document which of the three sources it uses + provide the fixture file with provenance comment.

</decisions>

<deferred>
## Deferred to other phases / milestones

- **Stencil curation UI** — Phase 24 (admin route, drag-port handles, manufacturer logo upload, "promote to curated" action)
- **AI port extraction** — Phase 25 (`DevicePortExtractorService` reading manufacturer datasheet PDFs via Claude vision)
- **Port-level cable schedule FKs** — Phase 22 (`cable_schedule_items.source_port_id` + `dest_port_id`)
- **XTEN-AV-style renderer** — Phase 23 (port-to-port cable routing, sub-room zones, multi-page paginator)
- **Bound PDF + O&M swap** — Phase 25 (DRAW-57 / DRAW-58)
- **Floor plans** — v2.1 (DRAW-14..20 from v1.3 backlog)
- **Full role-inference engine** — Phase 23 (renderer). Plan 21-03's role inference is INTENTIONALLY shallow — manufacturer-logo placement + a coarse network-switch / display / mic / other column heuristic ONLY (just enough for the spike admin route to keep rendering recognisable layouts). Phase 23 REPLACES this heuristic with a proper category metadata lookup driven by `device_stencils.metadata.role` + a layout-engine that uses port composition deterministically. Plan 21-03's heuristic is sized to "stop the spike from regressing"; it is NOT the long-term layout engine.
</deferred>

<critical_constraints>
## Critical Constraints

- **Generic naming** — `device_ports` and `device_stencils` tables MUST port to SCC without rename (per D-09).
- **Don't break v1.3** — DO NOT delete `resources/data/device-port-catalog.json`, `app/Services/DeviceCatalogService.php`, `app/Services/Drawings/SchematicGeneratorService.php`, or `SchematicD2SourceBuilder.php`. They're still in use (per D-10).
- **Don't pre-build Phase 24 UI** — Phase 21 has ZERO controllers. Phase 24 lands as a pure addition.
- **Idempotent seeder** — `whereRaw('LOWER(TRIM(part_number)) = ?', [...])` matching pattern for case-insensitive part_number lookup (per D-05, mirrors DeviceCatalogSeeder).
- **`firstOrCreate` is the cache contract** — the renderer ALWAYS calls through the cache service; never directly instantiates auto-generic stencils. This guarantees cross-project propagation when Phase 24 promotes a stencil.
- **Spike admin route stays live** — Plan 21-03 generalises the underlying builder, NOT the surface URL. The `admin.drawings.draw-io-spike.show` route name + controller method names + Blade view are preserved (per D-08). Controller's `DrawingService $drawings` constructor parameter MUST be preserved alongside the builder type-hint flip (per D-08).
- **ClickShare slug preservation** — `public/img/manufacturers/clickshare.svg` MUST NOT be deleted or renamed; resolver matches `clickshare` substring BEFORE `barco` (per D-14).
- **Lint** — every touched PHP file lints clean with Herd PHP 8.4: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`.
- **Migration runs on live AFTER upload** — Plan 21-01's SUMMARY MUST call out `php artisan migrate` as a post-upload step (per D-13).
- **Visual contract is XTEN-AV PAGING SYSTEM** — every curated stencil in Plan 21-02 should match the visual style: manufacturer logo top, name+model below, port rails left/right, connector glyphs outside edge.
- **Atomic commits per plan** — `feat(phase-21):` prefix for code commits.
- **Sequential plan execution** — Plans 21-01 → 21-02 → 21-03 run SEQUENTIALLY in separate executor sessions, NOT in parallel. Although 21-02 and 21-03 are nominally Wave 2 (both depend only on 21-01 in the depends_on graph), they both run feature tests that touch the `device_stencils` + `device_ports` tables; running their test suites concurrently risks collisions on shared table state. The `wave: 2` field in their frontmatter expresses dependency parallelism, but operationally the user runs `/gsd-execute-phase 21` once per plan, completing 21-02 before starting 21-03.
</critical_constraints>

<plan_outline>
## Plan Outline (planner reference)

**Wave 1 — foundation:**
- **Plan 21-01** — Schema + Models + Cache Service + Auto-Generic Generator + `Project::devicesWithStencils()`. Unblocks 21-02 and 21-03.

**Wave 2 — sequential build-out (depends_on: [21-01]; run 21-02 before 21-03):**
- **Plan 21-02** — Seed pack: promote 5 spike stencils + ALL 53 v1.3 catalog entries; hand-curate gap to top-50; idempotent seeder.
- **Plan 21-03** — Top-15 manufacturer logos; rename `DrawIoSpikeBuilderService` → `DrawIoBuilderService`; rewire to read from `device_stencils` table; spike admin route now uses live DB-backed builder.

Plans 21-02 and 21-03 have **zero `files_modified` overlap** (logos and seed manifests are disjoint from the seed-promotion files), so they COULD run parallel from a file-conflict perspective — but per critical_constraints "Sequential plan execution", they run one at a time to avoid feature-test collisions on shared `device_stencils` / `device_ports` table state. See PHASE-MANIFEST.md if present.
</plan_outline>
</content>
