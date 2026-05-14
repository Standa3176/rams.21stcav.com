---
phase: 23
plan: 01
subsystem: drawings/foundations
tags: [foundations, config, migration, fixtures, discovery, determinism, v2.0]
dependency_graph:
  requires:
    - Phase 21 (device_stencils table + DeviceStencilSeeder)
    - Phase 22 (config/cables.php signal_type_colours single source of truth)
  provides:
    - projects.metadata JSON column
    - config/drawings.php Phase 23 keys (zone_vocab / category_to_zone / sub_sheet_thresholds / sheet_number_format / page_dimensions)
    - tests/Fixtures/Drawings/Phase23FixtureFactory (4 deterministic factories)
    - OQ-1 + OQ-4 dispositions (markdown committed to .planning)
    - Carbon::setTestNow + no-actingAs determinism harness pattern
  affects:
    - Plan 23-02 (ZoneGrouper reads config + OQ-1 carry-forward)
    - Plan 23-03 (CableRouter reads OQ-4 carry-forward — Path B fallback)
    - Plan 23-04 (SheetPaginator reads sub_sheet_thresholds + sheet_number_format; TitleBlockRenderer reads page_dimensions + Project.metadata.drawing_checked_by)
    - Plan 23-05 (DrawIoBuilderService determinism test extends harness)
tech_stack:
  added: []
  patterns:
    - "Config-driven mappings (Phase 22 config/cables.php precedent applied to drawings)"
    - "Generic naming D-09 (no rams_ prefix) — `metadata` column ports cleanly to SCC merge"
    - "JSON cast 'metadata' => 'array' (Eloquent round-trip)"
    - "Carbon::setTestNow + Auth fallback harness (RESEARCH.md Pattern 3)"
    - "Idempotent fixture factories (firstOrCreate user, idempotent seeder)"
key_files:
  created:
    - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md
    - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md
    - database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php
    - tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php
    - tests/Feature/Drawings/ProjectMetadataMigrationTest.php
    - tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php
    - tests/Fixtures/Drawings/Phase23FixtureFactory.php
  modified:
    - app/Models/Project.php
    - config/drawings.php
decisions:
  - "D-01 implemented (config/drawings.php category_to_zone + zone_vocab keys)"
  - "D-04 implemented (zone_vocab enum + free-text escape hatch — escape hatch is Plan 02 ZoneGrouper concern, Plan 01 ships the vocab)"
  - "D-06 implemented (sub_sheet_thresholds: min_cables_per_signal=5, min_devices_touching_signal=3)"
  - "D-08 implemented (sheet_number_format AV-201..205 + Project.metadata.drawing_checked_by column)"
  - "D-09 verified generic naming (migration filename + column = `metadata`, NO `rams_` prefix)"
  - "OQ-1 Path B selected (high-level categories only — hardware falls through to name-keyword secondary derivation in Plan 02)"
  - "OQ-4 Path B selected (Tier 1.5 stencils have NO <constraint> elements — CableRouter falls back to device-edge heuristic with ⚠ glyph for them)"
metrics:
  duration: ~35min
  tasks_completed: 3
  files_created: 7
  files_modified: 2
  tests_added: 10
  assertions: 28
  completed: 2026-05-14
requirements: [DRAW-46, DRAW-47, DRAW-48]
---

# Phase 23 Plan 01: Foundations + Config + Test Scaffolds Summary

**One-liner:** Phase 23 data + config + test foundations shipped — projects.metadata JSON column, config/drawings.php zone_vocab/category_to_zone/threshold/sheet keys, 4 deterministic fixture factories, and BLOCKING dispositions for Open Questions 1+4 committed before any Wave 1 renderer code lands.

## Outcome

Lay the data + config + test-fixture foundation that Plans 02..07 build on, and RESOLVE the two BLOCKING open questions from 23-RESEARCH.md before any production renderer code is written.

- Open Question 1 (real category vocab vs D-01 seed map): **Path B selected.** Local DB has 0 packages; the canonical category vocabulary is the 7-key `$categoryOptions` list from `resources/views/project-packages/review.blade.php` (`hardware`, `cables`, `consumables`, `services`, `service_contracts`, `customer_supplied`, `option`). 0 of 22 D-01 lower-level seed keys appear in real data (0% overlap). Plan 02 ships a `category_to_zone` map where `hardware` falls through to a name-keyword secondary derivation (`ceiling` → CEILING, `rack`/`switch`/`amplifier`/`dsp`/`matrix` → RACK, `display`/`screen`/`projector` → WALL, etc.), and all other categories resolve OTHER. Disposition file: `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md`.

- Open Question 4 (Tier 1.5 stencil constraint presence): **Path B selected.** Tinker against local DB after seeding: `total_curated=96 / with_constraints=5 / needs_curation=91`. Only 5 of 96 engineer-curated stencils (5.2%) carry `<constraint>` elements — the 5 spike-promoted Tier 2 stencils (`bar-pro`, `neat-bar-pro`, `gs312tp`, `samsung-qm65c-t`, `sennheiser-tcc2`). The other 94.8% are Tier 1.5 auto-generic placeholders. CableRouter (Plan 03) MUST fall back to D-07 device-edge heuristic with ⚠ glyph whenever a cable's source OR dest stencil is Tier 1.5, regardless of FK presence (even with the FK set, the stencil shape has no constraint to attach to). Disposition file: `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md`.

- Migration `2026_05_13_120000_add_metadata_to_projects_table.php` applied locally (198.47ms). Reversible (`down()` drops cleanly). Column is `json` nullable with NULL default — strictly additive, existing projects unaffected. Phase 21 D-10 invariant verified: zero diff against the 5 v1.3 surfaces (`SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, `BoundPdfBuilderService`, `DrawingExportRendererService`).

- `Project::$fillable` extended with `metadata`. `Project::$casts` extended with `'metadata' => 'array'`. Inline comment cites D-06/D-08 source-of-truth refs + T-23-01-01 mass-assignment threat (controllers writing to `metadata` must validate shape; Phase 23 itself writes via tinker only — no user-facing surface ships in this phase).

- `config/drawings.php` appended Phase 23 keys (additive only — all 7 v1.3 keys untouched: `d2_binary_path`, `d2_layout`, `d2_timeout`, `d2_pinned_version`, `symbol_pack_path`, `signal_colours`, `title_block_fields`). New keys: `zone_vocab`, `category_to_zone`, `sub_sheet_thresholds`, `sheet_number_format`, `page_dimensions`.

- 4 deterministic fixture factories shipped (`Phase23FixtureFactory::smallMtr`, `boardroom`, `pagingSystem`, `legacyNullFk`). Each idempotent: `firstOrCreate` on user, hard-coded equipment ordering, idempotent stencil seeding via `\Artisan::call('db:seed', ['--class' => 'DeviceStencilSeeder'])` if `DeviceStencil::count() === 0`. Determinism harness (`Carbon::setTestNow('2026-05-13 12:00:00')` in setUp + no `actingAs()`) verified: `smallMtr()` called twice produces Projects with identical `part_number` ordering across two consecutive `devicesWithStencils()` calls.

## Tests Added

10 tests / 28 assertions across 3 files. All green.

| File | Tests | Assertions |
|------|-------|------------|
| `tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php` | 2 | 7 |
| `tests/Feature/Drawings/ProjectMetadataMigrationTest.php` | 5 | 8 |
| `tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php` | 3 | 13 |

Run command:
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='Phase23OpenQuestionsResolution|ProjectMetadataMigration|XtenAvDeterminismHarness'
```

## Tinker Counts (live data sample)

OQ-1 — production category strings observed in last 20 quotes:

```
[]
```
(Local dev DB has 0 ProjectPackage rows. Canonical vocab derived from review.blade.php `$categoryOptions` source-of-truth — 7 high-level keys.)

OQ-4 — Tier 1.5 stencil constraint presence (after running DeviceStencilSeeder):

```
total_curated=96
with_constraints=5
needs_curation=91
```

The 5 stencils carrying `<constraint>` elements:

| part_number       | manufacturer | model                         |
|-------------------|--------------|-------------------------------|
| bar-pro           | Barco        | ClickShare Bar Pro            |
| neat-bar-pro      | Neat         | Bar Pro                       |
| gs312tp           | Netgear      | GS312TP                       |
| samsung-qm65c-t   | Samsung      | QM65C-T                       |
| sennheiser-tcc2   | Sennheiser   | TeamConnect Ceiling 2         |

## Migration

| Property | Value |
|----------|-------|
| Filename | `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php` |
| Column   | `projects.metadata` |
| Type     | `json` |
| Nullable | yes |
| Default  | NULL |
| Position | after `notes` |
| Naming   | generic (no `rams_` prefix) per D-09 carry-forward (SCC merge readiness) |
| Local migrate runtime | 198.47ms (sqlite test DB) |

## Decisions Implemented

- **D-01** — `config/drawings.php` ships `category_to_zone` seed map. Per OQ-1 Path B, real-data shape is 7 high-level keys with `hardware` falling through to Plan 02's name-keyword secondary derivation.
- **D-04** — `config/drawings.php` ships `zone_vocab` (9 entries). Free-text escape hatch is Plan 02 ZoneGrouper concern (per D-04 free-text writes raw string; renderer creates a group per unique string).
- **D-06** — `sub_sheet_thresholds` shipped (`min_cables_per_signal=5`, `min_devices_touching_signal=3`). Tinker override path: `Project.metadata.force_sheets = ['audio', ...]`. Phase 24 ships the UI per CONTEXT D-06 deferred line.
- **D-08** — `sheet_number_format` shipped (AV-201..AV-205) extending v1.3 Phase 20 range. `Project.metadata.drawing_checked_by` write path is the new JSON column.
- **D-09** — generic naming verified: migration filename = `add_metadata_to_projects_table.php` (no `rams_` prefix), column = `metadata`. No service classes renamed in Plan 01 (none added).

## Threat Model Outcomes

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-23-01-01 | mitigate    | Inline docblock on `$fillable` cites the requirement: future Phase 24 controllers writing to `metadata` MUST validate shape. Phase 23 writes via tinker only — no user-facing surface. |
| T-23-01-02 | accept      | Phase 23 writes only `drawing_checked_by` (string) + `force_sheets` (array of signal-type strings). No PII, no secrets. |
| T-23-01-03 | accept      | Standard Laravel config-cache pattern. `php artisan config:clear` runbook entry below covers this. |

## Deviations from Plan

### 1. [Rule 3 — Blocking] Disposition file regex pattern adjusted

- **Found during:** Task 1 (writing the Phase23OpenQuestionsResolutionTest).
- **Issue:** Plan's regex template was `\*\*Selected:\*\*\s+Path\s+[ABC]` but I wrote the disposition headings as `**Path B selected**` (reads more naturally inline). Both shapes carry the same semantic meaning.
- **Fix:** Adjusted the test regex to `\*\*Path\s+[ABC]\s+selected/m` (consistent with what's actually written in both disposition files). Both `## Disposition` heading + Path-selection pattern are still asserted — the disposition contract is preserved.
- **Files modified:** `tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php`.
- **Commit:** `817a805`.

No other deviations — Tasks 2 + 3 executed exactly as planned.

## Authentication Gates

None — no external auth or paid API surface touched.

## Self-Check: PASSED

Verified at commit `ec37034`:

- File `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md` exists.
- File `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md` exists.
- File `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php` exists.
- File `tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php` exists.
- File `tests/Feature/Drawings/ProjectMetadataMigrationTest.php` exists.
- File `tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php` exists.
- File `tests/Fixtures/Drawings/Phase23FixtureFactory.php` exists.
- Commit `817a805` (docs: OQ-1 + OQ-4 dispositions) found in `git log`.
- Commit `7ddc5ac` (feat: projects.metadata JSON column) found in `git log`.
- Commit `ec37034` (feat: config + fixture factory + harness) found in `git log`.
- `php artisan test --filter='Phase23OpenQuestionsResolution|ProjectMetadataMigration|XtenAvDeterminismHarness'` exited 0 with 10 tests / 28 assertions GREEN.
- `php artisan migrate:status | grep 2026_05_13` shows `Ran`.
- `git diff --stat` against the 5 v1.3 invariant files returned empty.
- `grep -rE "AIManager|AICache|AIUsage" app/Services/Drawings/ tests/Fixtures/Drawings/` returned empty (D-LOCK-5).
- All touched PHP files passed `php -l` (no syntax errors).

## 🚨 Files to upload to live

Per `feedback_php_lint_before_push.md` correction: RAMS deploy = `git push` to `live` remote → SSH to `/home/stcav/rams.21stcav.com/` → `sudo -u stcav git pull` + `sudo -u stcav php artisan migrate --force` + `sudo -u stcav php artisan config:clear`. Files this plan changed (for traceability — the actual deploy is a git pull):

- `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php`  *(new — schema change)*
- `app/Models/Project.php`  *(modified — adds `metadata` fillable + array cast)*
- `config/drawings.php`  *(modified — appends 5 Phase 23 keys, all v1.3 keys untouched)*

Post-deploy runbook (RAMS):

```bash
ssh stcav@rams.21stcav.com
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan migrate --force
sudo -u stcav "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan config:clear
```
(Adjust PHP binary path on live — the local PHP binary at `/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe` is dev-only; live uses the system `php` binary.)

Plan 02..07 are unblocked.
