---
phase: 23
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php
  - app/Models/Project.php
  - config/drawings.php
  - tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php
  - tests/Feature/Drawings/ProjectMetadataMigrationTest.php
  - tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php
  - tests/Fixtures/Drawings/Phase23FixtureFactory.php
  - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md
  - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md
autonomous: true
requirements:
  - DRAW-46
  - DRAW-47
  - DRAW-48
tags: [foundations, config, migration, fixtures, discovery, determinism, v2.0]
must_haves:
  truths:
    - "projects.metadata JSON column exists and round-trips array values via the cast"
    - "config/drawings.php carries category_to_zone + zone_vocab + sub_sheet_thresholds + sheet_number_format keys (additive — existing v1.3 keys untouched)"
    - "Open Question 1 (real category vocab vs D-01 seed map) is resolved with a written disposition committed at .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md before any Wave 1+ work runs"
    - "Open Question 4 (Tier 1.5 stencil constraint elements) is resolved with a written disposition at 23-DISCOVERY-OQ-4-TIER15-PORTS.md so the CableRouter (Plan 03) knows how aggressively to fall back to device-edge per D-07"
    - "Determinism harness (Carbon::setTestNow + Auth fallback) is in place so Plan 02-05's tests do not flap on the second-boundary or auth-context drift"
    - "4 fixture project factories exist (small_mtr / boardroom / paging_system / legacy_null_fk) ready for Plan 02-04 tests"
  artifacts:
    - path: "database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php"
      provides: "JSON nullable metadata column on projects"
      contains: "json('metadata')"
    - path: "config/drawings.php"
      provides: "Phase 23 zone vocab + category map + threshold + sheet format"
      contains: "category_to_zone"
    - path: "tests/Fixtures/Drawings/Phase23FixtureFactory.php"
      provides: "4 deterministic project fixture factories"
      exports: ["smallMtr", "boardroom", "pagingSystem", "legacyNullFk"]
    - path: ".planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md"
      provides: "Disposition for Open Question 1 — category vocab"
      min_lines: 30
    - path: ".planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md"
      provides: "Disposition for Open Question 4 — Tier 1.5 stencil port presence"
      min_lines: 20
  key_links:
    - from: "app/Models/Project.php"
      to: "projects.metadata column"
      via: "fillable + casts"
      pattern: "metadata.*array"
    - from: "config/drawings.php"
      to: "downstream renderer classes (Plan 02-04)"
      via: "config('drawings.category_to_zone') etc."
      pattern: "category_to_zone|zone_vocab|sub_sheet_thresholds|sheet_number_format"
    - from: "tests/Fixtures/Drawings/Phase23FixtureFactory.php"
      to: "Plan 02-05 feature tests"
      via: "static fixture builder methods"
      pattern: "Phase23FixtureFactory::"
---

<objective>
Lay the data + config + test-fixture foundation that Plans 02..07 build on, and RESOLVE the two BLOCKING open questions from 23-RESEARCH.md before any production renderer code is written.

Purpose: Phase 23 evolves a deterministic builder. If the category vocabulary in real production data does not match the D-01 seed map (Open Question 1), the ZoneGrouper in Plan 02 ships against a vocab nobody uses. If the 91 Tier 1.5 stencils do not carry `<constraint>` elements (Open Question 4), the CableRouter in Plan 03 cannot use `exitPortId` for them and must aggressively fall back to device-edge per D-07. Both questions must be answered with grep/tinker/DB queries on real data, with the disposition committed to git, BEFORE Wave 1 starts.

Output:
- `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php` (per D-08 + D-09 generic naming)
- `app/Models/Project.php` — `metadata` added to `$fillable` + `'metadata' => 'array'` cast
- `config/drawings.php` — Phase 23 keys appended (additive only; v1.3 keys untouched)
- `tests/Fixtures/Drawings/Phase23FixtureFactory.php` — 4 deterministic fixture factories
- 3 test files (Wave 0 scaffolds — RED by intent, GREEN after Plans 02-05)
- 2 DISCOVERY markdown files committing the OQ-1 + OQ-4 dispositions
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
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@.planning/phases/23-xten-av-style-renderer/23-VALIDATION.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md
@.planning/phases/22-cable-schedule-with-port-level-fks/22-01-SUMMARY.md
@app/Models/Project.php
@config/drawings.php
@config/cables.php

<interfaces>
<!-- Key contracts the executor must honour. -->

From Project::devicesWithStencils() — Phase 21 D-07 (read-only here):
```php
// Returns array<int, array{
//   'part_number': string, 'manufacturer': string, 'model': string,
//   'name': string, 'quantity': int, 'area': string, 'category': string,
//   'zone'?: string,                     // NEW (D-02) — read via line['zone'] ?? null
//   'stencil': ?DeviceStencil,
// }>
public function devicesWithStencils(): array;
```

From config/cables.php (Phase 22 locked single source of truth):
```php
'signal_type_colours' => [
    'audio' => '#C0392B',  'video' => '#2980B9',
    'control' => '#27AE60','network' => '#8E44AD',
    'usb' => '#E67E22',    'speaker' => '#16A085',
    'power' => '#7F8C8D',  'unknown' => '#000000',
],
```
DO NOT MODIFY this file in Phase 23 — Plan 07 verifies it side-by-side and raises a separate ticket if XTEN-AV reference disagrees.

From existing config/drawings.php (v1.3 keys — must remain untouched):
```php
'd2_binary_path', 'd2_layout', 'd2_timeout', 'd2_pinned_version',
'symbol_pack_path', 'signal_colours', 'title_block_fields'
```
Phase 23 APPENDS new keys after these. Existing keys are read by v1.3's D2 generator + bound-PDF surfaces (Phase 21 D-10 invariant).
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Migration runtime | Schema change on `projects` table |
| `Project::$fillable` mass-assignment | Future Phase 24 UI may POST to metadata |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-01-01 | Tampering | `Project.metadata` mass-assignment | mitigate | Cast `'metadata' => 'array'` ensures non-JSON input is rejected at boundary; controllers writing to Project (e.g. `ProjectController@update`) MUST explicitly whitelist `metadata` writes (Phase 23 itself writes via tinker only — no user-facing surface). Note in Project.php docblock that metadata write paths must validate shape. |
| T-23-01-02 | Information Disclosure | metadata JSON column might persist sensitive PII | accept | Phase 23 writes only `drawing_checked_by` (string name) + `force_sheets` (array of signal-type strings). No PII, no secrets. Phase 24 force-sheet UI is a separate threat-model. |
| T-23-01-03 | Tampering | config/drawings.php changes affect runtime behaviour | accept | Standard Laravel config-cache pattern. `php artisan config:clear` required after edit (added to plan's "Files to upload to live" runbook). |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: BLOCKING discovery — resolve Open Question 1 (category vocab) + Open Question 4 (Tier 1.5 ports)</name>
  <files>
    .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md,
    .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md,
    tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md (§"Open Questions" lines 819-850 — full text of OQ-1 + OQ-4 with recommended resolution paths)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-01 seed map at lines 53-66 — the candidate vocab)
    - .planning/phases/21-device-port-catalog-stencil-cache/21-02-seed-pack-promote-and-curate-PLAN.md (Tier 1.5 stencil shape — `metadata.needs_phase_24_curation = true`)
    - app/Models/DeviceStencil.php (full file — to understand `mxgraph_xml` column shape + `source` enum values)
    - app/Models/Project.php (lines 95-130 — current $fillable + $casts shape)
  </read_first>
  <behavior>
    - The two DISCOVERY markdown files commit the resolution of OQ-1 and OQ-4 with grep/DB-query evidence, so Plan 02 (ZoneGrouper) and Plan 03 (CableRouter) ship against verified assumptions
    - Phase23OpenQuestionsResolutionTest enforces that the DISCOVERY files exist and contain the required headings (so a future dev can't silently delete them)
    - Tests assert each DISCOVERY file has a "Disposition" section with `## Disposition` heading + non-empty body
  </behavior>
  <action>
**Step 1 — Inspect real category vocab (OQ-1) via tinker against local DB:**

Run this shell command (PowerShell-safe) and pipe output into the DISCOVERY file:
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan tinker --execute="echo json_encode(\App\Models\ProjectPackage::query()->whereNotNull('extracted_data')->latest()->take(20)->get()->flatMap(fn(\$p) => collect(data_get(\$p->extracted_data, 'equipment', []))->pluck('category'))->filter()->unique()->values()->all(), JSON_PRETTY_PRINT);"
```

Capture the JSON array of distinct category strings actually present in the last 20 quotes.

**Step 2 — Write `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md`** with these exact sections (markdown headings verbatim so the test in this task can grep them):

```markdown
# Phase 23 Discovery — Open Question 1: Category Vocabulary

**Resolved:** {YYYY-MM-DD}
**Researcher:** Plan 23-01 Task 1 (per D-01 + D-09 generic naming)

## Production Category Strings (sample)

{pasted JSON output from Step 1 — verbatim}

## Comparison to D-01 Seed Map

D-01 candidate keys (from 23-CONTEXT.md lines 53-66):
- rack-mount-switch, network-switch, poe-switch, amplifier, dsp, matrix, processor → RACK
- ceiling-mic, ceiling-speaker, ceiling-camera → CEILING
- display, screen, projector → WALL
- touchpanel, desk-mic, tabletop-codec → TABLE
- paging-station, call-station → PAGING_STATION
- intercom, door-station → RECEPTION
- ups, distribution-strip → FLOOR

Real-data category strings observed:
{list — derived from Step 1 output}

Overlap:
{count + percentage of D-01 keys present in real data}

## Disposition

ONE of the following three paths MUST be selected and justified:

**Path A (Adopt and tolerate):** D-01 map shipped verbatim; categories not in the map default to `OTHER` zone per the renderer resolution rule. ZoneGrouper handles missing categories gracefully. Phase 24 polish adds finer derivation. RECOMMENDED if overlap ≥30%.

**Path B (Reduce to high-level categories only):** D-01 seed map replaced with the 7 high-level `categoryOptions` strings from `resources/views/project-packages/review.blade.php` (`hardware`, `cables`, `consumables`, `services`, `service_contracts`, `customer_supplied`, `option`). Most map to OTHER; `hardware` falls through to a name-keyword secondary derivation (`'ceiling' → CEILING`, `'rack' → RACK`, else `OTHER`). RECOMMENDED if overlap <30%.

**Path C (Defer with regex)**: keep D-01 as a substring-match against the device `name` field instead of `category` (e.g. `Str::contains(strtolower($line['name']), 'ceiling')` → CEILING). Lower-fidelity but works against real data shape. RECOMMENDED if Path A overlap is 0% but device names follow naming conventions.

**Selected:** Path {A|B|C}
**Rationale:** {2-3 sentences explaining why the data supports this choice}
**Implication for Plan 02 (ZoneGrouper):** {short — what the executor needs to know}

## Plan 02 carry-forward instruction

Plan 02 Task 1 (ZoneGrouper construction) reads `config('drawings.category_to_zone')` populated in this plan's Task 3 according to the Selected Path above. If Path B or Path C selected, Plan 02 Task 1 implements the name-keyword secondary derivation per the rules logged here.
```

**Step 3 — Inspect Tier 1.5 stencil constraint presence (OQ-4) via tinker:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan tinker --execute="echo 'total_curated=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->count() . PHP_EOL; echo 'with_constraints=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->where('mxgraph_xml', 'like', '%<constraint%')->count() . PHP_EOL; echo 'needs_curation=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->whereJsonContains('metadata->needs_phase_24_curation', true)->count() . PHP_EOL;"
```

**Step 4 — Write `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md`:**

```markdown
# Phase 23 Discovery — Open Question 4: Tier 1.5 Stencil Port Presence

**Resolved:** {YYYY-MM-DD}
**Researcher:** Plan 23-01 Task 1 (per D-07 carry-forward)

## Tinker counts

- total engineer-curated stencils: {N}
- with `<constraint>` elements: {M}
- flagged `needs_phase_24_curation = true`: {K}
- ratio with constraints: {M/N as percentage}

## Disposition

ONE of:

**Path A (Constraints present in Tier 1.5):** CableRouter (Plan 03) attempts `exitPortId` resolution for Tier 1.5 stencils as well as Tier 2. Falls back to coordinate-style (`exitX/exitY`) only when port_id resolution fails per port.

**Path B (Constraints absent in Tier 1.5):** CableRouter falls back to D-07 device-edge heuristic (with ⚠ glyph) for any cable whose source OR dest stencil is Tier 1.5 (regardless of FK presence). Plan 03 Task 2 grep test asserts: when iterating Tier 1.5 stencil's cables, render path takes the edge-heuristic branch.

**Selected:** Path {A|B}
**Rationale:** {short — based on the count}
**Implication for Plan 03 (CableRouter):** {short}
```

**Step 5 — Write the resolution-enforcement test `tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 1 — enforces the two BLOCKING Wave 0 dispositions.
 *
 * If these files are missing or the disposition section is empty, Plan 02+03
 * are running against unverified assumptions per 23-RESEARCH.md Open Questions
 * 1 + 4. Failing this test red-blocks the entire Phase 23.
 */
class Phase23OpenQuestionsResolutionTest extends TestCase
{
    public function test_oq1_disposition_file_exists_with_selected_path(): void
    {
        $path = base_path('.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('## Disposition', $contents);
        $this->assertMatchesRegularExpression('/\*\*Selected:\*\*\s+Path\s+[ABC]/m', $contents);
        $this->assertStringContainsString('## Plan 02 carry-forward instruction', $contents);
    }

    public function test_oq4_disposition_file_exists_with_selected_path(): void
    {
        $path = base_path('.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('## Disposition', $contents);
        $this->assertMatchesRegularExpression('/\*\*Selected:\*\*\s+Path\s+[AB]/m', $contents);
    }
}
```

**Step 6 — Commit (TDD GREEN per task):**
```
git add .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-{1,4}*.md tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php
git commit -m "docs(23-01): resolve Open Questions 1+4 from RESEARCH.md (per D-01/D-07)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=Phase23OpenQuestionsResolutionTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - File `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md` exists
    - File `.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md` exists
    - `grep -c "^## Disposition" .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md` returns ≥1
    - `grep -E "^\*\*Selected:\*\*\s+Path\s+[ABC]" .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md` returns 1 match
    - `grep -E "^\*\*Selected:\*\*\s+Path\s+[AB]" .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md` returns 1 match
    - `php artisan test --filter=Phase23OpenQuestionsResolutionTest` exits 0
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/Phase23OpenQuestionsResolutionTest.php` prints "No syntax errors"
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty (v1.3 surfaces untouched per Phase 21 D-10)
  </acceptance_criteria>
  <done>Both DISCOVERY markdown files committed with a Selected Path; resolution test green. Plan 02 + Plan 03 unblocked.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Migration + Project model — add `metadata` JSON column (per D-08, D-09)</name>
  <files>
    database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php,
    app/Models/Project.php,
    tests/Feature/Drawings/ProjectMetadataMigrationTest.php
  </files>
  <read_first>
    - app/Models/Project.php lines 95-150 (current $fillable + $casts shape)
    - database/migrations/2026_03_14_000001_create_projects_table.php (original projects schema — confirm no existing metadata column)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-08 lines 95-105 (title-block source of truth — `Project.metadata.drawing_checked_by`) + D-09 carry-forward (generic naming)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Runtime State Inventory" (assumption A4 verified)
  </read_first>
  <behavior>
    - Migration is reversible (`down()` drops the column cleanly)
    - Column is `json` nullable with NULL default — does NOT break existing projects
    - Project model `$fillable` accepts `'metadata'` write
    - Project model `$casts` includes `'metadata' => 'array'` (round-trips PHP array ↔ JSON automatically)
    - Test asserts: column exists; nullable; cast round-trips an array; default NULL on insert; rollback drops cleanly
  </behavior>
  <action>
**Step 1 — Create migration `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php`:**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 Plan 01 Task 2 — adds projects.metadata JSON column.
 *
 * Purpose: per CONTEXT D-08 the Phase 23 title block reads `drawing_checked_by`
 * from `Project.metadata.drawing_checked_by`; per D-06 the SheetPaginator's
 * tinker override reads `Project.metadata.force_sheets`. Generic name (no
 * `rams_` prefix) per D-09 carry-forward (SCC merge readiness).
 *
 * Strictly additive — NULL default — existing projects unaffected. Phase 21
 * D-10 invariant: v1.3 D2 generator surfaces never read this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // JSON nullable. Default NULL — Phase 23 writes happen via tinker
            // only; Phase 24 force-sheet UI is the first writer surface.
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
```

**Step 2 — Update `app/Models/Project.php`:**

Add `'metadata'` to the `$fillable` array (after `'notes'` on line 120 — keep the ordering near the comment "Phase 4 - Tier 1 OM lifecycle dates" boundary; insert BEFORE that comment block so metadata sits alongside other free-form keys):

```php
// In the $fillable array, after 'notes':
'metadata',
// Phase 4 - Tier 1 OM lifecycle dates
'handover_date',
'defects_liability_end',
```

Add `'metadata' => 'array'` to the `$casts` array (after the existing datetime casts):

```php
// In $casts, after the existing datetime casts:
'metadata' => 'array',
```

Add a PHPDoc to the model class noting the new property (per CLAUDE.md PHPDoc convention) — add this @property line in the class-level docblock OR (if no docblock exists yet) inline above $fillable:

```php
/**
 * @property array|null $metadata  Phase 23 — drawing_checked_by + force_sheets keys. See 23-CONTEXT.md D-06/D-08.
 */
```

**Step 3 — Run `php -l` and assert no syntax errors:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Models/Project.php
```

**Step 4 — Write the test `tests/Feature/Drawings/ProjectMetadataMigrationTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 2 — locks the metadata JSON column shape per D-08 + D-09.
 */
class ProjectMetadataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_table_has_nullable_json_metadata_column(): void
    {
        $this->assertTrue(Schema::hasColumn('projects', 'metadata'));
        $columnType = Schema::getColumnType('projects', 'metadata');
        $this->assertContains($columnType, ['json', 'text', 'longtext']); // sqlite reports text/longtext; mysql reports json
    }

    public function test_metadata_round_trips_via_array_cast(): void
    {
        $project = Project::factory()->create([
            'metadata' => [
                'drawing_checked_by' => 'Alice Engineer',
                'force_sheets' => ['audio', 'video'],
            ],
        ]);

        $reloaded = Project::find($project->id);
        $this->assertSame('Alice Engineer', $reloaded->metadata['drawing_checked_by']);
        $this->assertSame(['audio', 'video'], $reloaded->metadata['force_sheets']);
    }

    public function test_metadata_defaults_null(): void
    {
        $project = Project::factory()->create();
        $this->assertNull($project->fresh()->metadata);
    }

    public function test_metadata_is_in_fillable(): void
    {
        $fillable = (new Project)->getFillable();
        $this->assertContains('metadata', $fillable);
    }

    public function test_metadata_cast_is_array(): void
    {
        $casts = (new Project)->getCasts();
        $this->assertArrayHasKey('metadata', $casts);
        $this->assertSame('array', $casts['metadata']);
    }
}
```

**Step 5 — Run migrations + tests:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan migrate
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ProjectMetadataMigrationTest --stop-on-failure
```

**Step 6 — Verify v1.3 surfaces untouched (Phase 21 D-10):**

```
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```
Expected: empty output (v1.3 D-10 invariant).

**Step 7 — Atomic commit:**
```
git add database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php app/Models/Project.php tests/Feature/Drawings/ProjectMetadataMigrationTest.php
git commit -m "feat(23-01): add projects.metadata JSON column for Phase 23 (per D-08, D-09)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Models/Project.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ProjectMetadataMigrationTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php` prints "No syntax errors"
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Models/Project.php` prints "No syntax errors"
    - `php artisan test --filter=ProjectMetadataMigrationTest` exits 0 (5 tests pass)
    - `grep -c "'metadata'," app/Models/Project.php` returns ≥1 (added to $fillable)
    - `grep -c "'metadata' => 'array'" app/Models/Project.php` returns 1 (cast added)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git log --oneline -1` shows the commit "feat(23-01): add projects.metadata JSON column for Phase 23 (per D-08, D-09)"
  </acceptance_criteria>
  <done>Migration committed, model updated, 5 tests green, v1.3 surfaces show empty diff.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: config/drawings.php Phase 23 keys + fixture factory + determinism harness</name>
  <files>
    config/drawings.php,
    tests/Fixtures/Drawings/Phase23FixtureFactory.php,
    tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php
  </files>
  <read_first>
    - config/drawings.php (full file — confirm current v1.3 keys before append)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-01 (lines 53-67) + D-04 (lines 73-77) + D-06 (lines 89-92) + D-08 (lines 95-105)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pattern 2: Config-driven mappings" (lines 273-308 — example structure)
    - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md (Selected Path from Task 1 — informs which `category_to_zone` shape to ship)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pattern 3: Determinism contract" (lines 311-321 — Carbon::setTestNow + Auth fallback)
  </read_first>
  <behavior>
    - config/drawings.php APPENDS 4 Phase 23 keys after the existing v1.3 keys — zero modification to existing v1.3 keys
    - Fixture factory produces 4 deterministic project shapes (small_mtr / boardroom / paging_system / legacy_null_fk) reusable by Plans 02-06
    - Determinism harness test reads back the Phase 23 keys + freezes time + asserts a placeholder builder render is byte-identical across two calls (this is the canary Plan 05 extends with the real builder)
  </behavior>
  <action>
**Step 1 — Append Phase 23 keys to `config/drawings.php`:**

The existing file is 50 lines. APPEND (do not modify existing keys) after line 50 (the closing `];`). Replace the final `];` with the Phase 23 block ABOVE it, then `];`:

```php
    // ── Phase 23 zone derivation (DRAW-46) ────────────────────────────────
    // Per CONTEXT D-01 + D-04. Vocab is the canonical enum; engineer can
    // type free-text in the review-form dropdown to create a separate
    // dashed group (D-04 escape hatch). The category_to_zone map shape
    // depends on Plan 01 Task 1's OQ-1 disposition — see
    // .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md.
    //
    // Renderer resolution:
    //   $zone = $line['zone'] ?? $config['category_to_zone'][$line['category']] ?? 'OTHER';
    'zone_vocab' => [
        'RACK', 'CEILING', 'WALL', 'TABLE',
        'RECEPTION', 'FLOOR', 'PAGING_STATION',
        'EXTERNAL', 'OTHER',
    ],
    'category_to_zone' => [
        // PER OQ-1 DISPOSITION (Plan 23-01 Task 1): seed map. If OQ-1 selected
        // Path B (reduce to high-level), replace this array with the 7-key
        // high-level map per the disposition file's Plan 02 carry-forward
        // instruction. If Path A, keep the lower-level keys below.
        'rack-mount-switch'   => 'RACK',
        'network-switch'      => 'RACK',
        'poe-switch'          => 'RACK',
        'amplifier'           => 'RACK',
        'dsp'                 => 'RACK',
        'matrix'              => 'RACK',
        'processor'           => 'RACK',
        'ceiling-mic'         => 'CEILING',
        'ceiling-speaker'     => 'CEILING',
        'ceiling-camera'      => 'CEILING',
        'display'             => 'WALL',
        'screen'              => 'WALL',
        'projector'           => 'WALL',
        'touchpanel'          => 'TABLE',
        'desk-mic'            => 'TABLE',
        'tabletop-codec'      => 'TABLE',
        'paging-station'      => 'PAGING_STATION',
        'call-station'        => 'PAGING_STATION',
        'intercom'            => 'RECEPTION',
        'door-station'        => 'RECEPTION',
        'ups'                 => 'FLOOR',
        'distribution-strip'  => 'FLOOR',
    ],

    // ── Phase 23 paginator threshold (DRAW-47, per D-06) ──────────────────
    // Sub-sheet emits when BOTH cable-count >= min_cables_per_signal
    // AND device-count touching that signal >= min_devices_touching_signal.
    // Engineer tinker override via Project.metadata.force_sheets = ['audio', ...]
    // (Phase 24 ships the proper UI per CONTEXT D-06 deferred line).
    'sub_sheet_thresholds' => [
        'min_cables_per_signal'       => 5,
        'min_devices_touching_signal' => 3,
    ],

    // ── Phase 23 sheet numbering (DRAW-47/48, per D-08) ───────────────────
    // Extends v1.3 Phase 20 AV-201..299 schematic range. The SheetPaginator
    // (Plan 23-04) maps emitted sheets to these strings; AV-201 always
    // emits (system overview); AV-202..205 are conditional per threshold.
    'sheet_number_format' => [
        'system_overview' => 'AV-201',
        'audio'           => 'AV-202',
        'video'           => 'AV-203',
        'control'         => 'AV-204',
        'network'         => 'AV-205',
    ],

    // ── Phase 23 layout dimensions ────────────────────────────────────────
    // Page bounds for each emitted <diagram>. Matches the current builder's
    // implicit 1600x1000 landscape. Sheet border (DRAW-49) insets 20 px.
    'page_dimensions' => [
        'width'         => 1600,
        'height'        => 1000,
        'border_inset'  => 20,
        'title_block_y' => 940,   // y-coordinate where the title block row starts
    ],
];
```

After saving, run `php -l config/drawings.php` and assert "No syntax errors". Then run `php artisan config:clear` to refresh the cached config.

**Step 2 — Create `tests/Fixtures/Drawings/Phase23FixtureFactory.php`:**

```php
<?php

namespace Tests\Fixtures\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;

/**
 * Phase 23 deterministic fixture factory. Returns Project instances loaded
 * with the relations Plan 02-06 tests need. Each factory is idempotent:
 * runs against ::refreshDatabase. Stencil rows are sourced from the Phase 21
 * seed pack via DeviceStencilSeeder — fixtures attach FK relations only.
 *
 * Per 23-RESEARCH.md "Fixture projects (4)" lines 759-765.
 */
class Phase23FixtureFactory
{
    /**
     * Small Teams Room — 5 curated devices + 6 port-to-port cables.
     * Happy path for DRAW-42/43/44/45.
     */
    public static function smallMtr(): Project
    {
        // {Implementation: create Project via factory, attach a ProjectPackage
        //  with extracted_data.equipment listing 5 of the Phase 21 seed-pack
        //  part_numbers (Neat Bar Pro / Samsung QM65 / ClickShare Bar Pro /
        //  Sennheiser TCC2 / Netgear GS312TP). Attach Device rows with FK to
        //  the stencils. Create a single CableSchedule with 6 CableScheduleItem
        //  rows populating all 4 port FK columns (source_device_id, source_port_id,
        //  dest_device_id, dest_port_id) + cable_id values from the canonical
        //  Teams Room chain. Return the Project.}
        // EXECUTOR: implement using existing Factory classes; reuse the seeder's
        // part_numbers verbatim so the Phase 21 stencils get FK-attached.
        // ...
    }

    /**
     * Boardroom — ~30 devices across RACK + CEILING + WALL + TABLE zones,
     * ~25 cables. Below D-06 threshold on most signal types → no sub-sheets
     * (DRAW-47 below-threshold negative case).
     */
    public static function boardroom(): Project { /* ... */ }

    /**
     * Paging system — ~40 devices, ~50 cables (≥5 per signal type) →
     * emits multiple sub-sheets (DRAW-47 above-threshold positive case).
     */
    public static function pagingSystem(): Project { /* ... */ }

    /**
     * Legacy NULL-FK — 8 devices + 6 cables, 3 of which have ALL 4 port FKs
     * NULL but populated from_location / to_location text. Exercises D-07
     * NULL-FK fallback ladder.
     */
    public static function legacyNullFk(): Project { /* ... */ }
}
```

NOTE: full bodies for the four factories are nontrivial (~100-200 LOC each). The executor implements them using the existing `Project::factory()` + `Device::factory()` + `DeviceStencilSeeder` infrastructure. Each factory MUST:
- Return a fully-loaded `Project` ready for `$project->devicesWithStencils()` calls
- Be deterministic (no `Str::random`, no `now()` in fixture data)
- Use `DeviceStencil::where('part_number', ...)->first()` to FK-attach to seeded stencils
- Document what it tests in a PHPDoc above the static method

**Step 3 — Write the determinism harness test `tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 3 — Wave 0 canary for the determinism contract.
 *
 * Plan 05 extends this with the real DrawIoBuilderService::build() byte-identity
 * assertion. Plan 01's job: PROVE the harness works by freezing time +
 * Auth context + fixture ordering and rendering a placeholder string twice.
 *
 * Per 23-RESEARCH.md Pattern 3 (lines 311-321) + Pitfall 3 (lines 367-371).
 */
class XtenAvDeterminismHarnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time so TitleBlockRenderer::render's now()->format('Y-m-d')
        // produces stable bytes across calls.
        Carbon::setTestNow('2026-05-13 12:00:00');
        // Do NOT call actingAs() — designed-by falls back to '—' per D-08.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_phase_23_config_keys_are_loaded(): void
    {
        $this->assertIsArray(config('drawings.zone_vocab'));
        $this->assertContains('RACK', config('drawings.zone_vocab'));
        $this->assertContains('OTHER', config('drawings.zone_vocab'));

        $this->assertIsArray(config('drawings.category_to_zone'));

        $this->assertSame(5, config('drawings.sub_sheet_thresholds.min_cables_per_signal'));
        $this->assertSame(3, config('drawings.sub_sheet_thresholds.min_devices_touching_signal'));

        $this->assertSame('AV-201', config('drawings.sheet_number_format.system_overview'));
        $this->assertSame('AV-205', config('drawings.sheet_number_format.network'));

        $this->assertSame(1600, config('drawings.page_dimensions.width'));
    }

    public function test_v1_3_signal_colours_key_untouched(): void
    {
        // Phase 22 locked config/cables.php as single source of truth for the
        // renderer; v1.3 config/drawings.php signal_colours stays for the D2
        // schematic generator (Phase 17). Plan 01 must NOT touch it.
        $this->assertSame('#C0392B', config('drawings.signal_colours.audio'));
        $this->assertSame('#8E44AD', config('drawings.signal_colours.network'));
    }

    public function test_fixture_factory_smallmtr_is_deterministic(): void
    {
        $a = Phase23FixtureFactory::smallMtr();
        $b = Phase23FixtureFactory::smallMtr();

        // Same equipment_list count
        $aLines = $a->fresh()->devicesWithStencils();
        $bLines = $b->fresh()->devicesWithStencils();

        $this->assertCount(count($aLines), $bLines);
        // Part numbers identical in order
        $this->assertSame(
            collect($aLines)->pluck('part_number')->all(),
            collect($bLines)->pluck('part_number')->all(),
        );
    }
}
```

**Step 4 — Run `php -l` + tests + v1.3 invariant check:**

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l config/drawings.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Fixtures/Drawings/Phase23FixtureFactory.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan config:clear
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=XtenAvDeterminismHarnessTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```

**Step 5 — Commit:**
```
git add config/drawings.php tests/Fixtures/Drawings/Phase23FixtureFactory.php tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php
git commit -m "feat(23-01): config/drawings.php Phase 23 keys + fixture factory + determinism harness (per D-01, D-04, D-06, D-08)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l config/drawings.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Fixtures/Drawings/Phase23FixtureFactory.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/XtenAvDeterminismHarnessTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=XtenAvDeterminismHarnessTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "category_to_zone" config/drawings.php` returns ≥1
    - `grep -c "zone_vocab" config/drawings.php` returns ≥1
    - `grep -c "sub_sheet_thresholds" config/drawings.php` returns ≥1
    - `grep -c "sheet_number_format" config/drawings.php` returns ≥1
    - `grep -c "'signal_colours'" config/drawings.php` returns 1 (v1.3 key untouched)
    - `grep -c "'d2_binary_path'" config/drawings.php` returns 1 (v1.3 key untouched)
    - `php artisan test --filter=XtenAvDeterminismHarnessTest` exits 0 (3 tests pass)
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l config/drawings.php` prints "No syntax errors"
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `grep -c "AIManager\|AICache\|AIUsage" app/Services/Drawings/ tests/Fixtures/Drawings/` returns 0 (D-LOCK-5 invariant)
    - `git log --oneline -1` shows the commit "feat(23-01): config/drawings.php Phase 23 keys..."
  </acceptance_criteria>
  <done>config keys appended; 4 fixture factories implemented; determinism harness green; v1.3 invariants intact.</done>
</task>

</tasks>

<verification>
- All 3 tasks committed atomically (TDD RED-then-GREEN where applicable)
- `php artisan test --filter='Phase23OpenQuestionsResolution|ProjectMetadataMigration|XtenAvDeterminismHarness'` exits 0
- `git diff --stat` on the 5 v1.3 invariant files returns empty
- `grep -E "AIManager|AICache|AIUsage" app/Services/Drawings/ tests/Fixtures/Drawings/` returns empty (D-LOCK-5)
- Both DISCOVERY-OQ-1 and DISCOVERY-OQ-4 markdown files exist with `## Disposition` + `**Selected:** Path X` heading
- `php artisan migrate:status` shows the new `2026_05_13_120000_add_metadata_to_projects_table` ran
</verification>

<success_criteria>
Wave 1 (Plans 02, 03, 04) is unblocked when:
1. The `projects.metadata` column is live (Plan 04 TitleBlockRenderer + Plan 04 SheetPaginator read it)
2. config/drawings.php carries the 4 Phase 23 keys (Plan 02 ZoneGrouper reads category_to_zone; Plan 04 SheetPaginator reads sub_sheet_thresholds; Plan 04 TitleBlockRenderer reads sheet_number_format + page_dimensions)
3. OQ-1 disposition tells Plan 02 which category vocab to honour
4. OQ-4 disposition tells Plan 03 how aggressively to fall back to device-edge
5. The fixture factory is implemented so Plans 02-04 don't each invent their own
6. The determinism harness setUp pattern (Carbon::setTestNow + no actingAs) is locked so Plan 05 can extend it without flapping
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-01-SUMMARY.md` documenting:
- Selected disposition for OQ-1 (Path A/B/C + rationale)
- Selected disposition for OQ-4 (Path A/B + rationale)
- Live tinker counts (curated stencils, Tier 1.5 with constraints, real category strings observed)
- Migration timestamp
- Tests added (count + assertions)
- Decision IDs implemented: D-01 (config seed), D-04 (zone_vocab), D-06 (sub_sheet_thresholds), D-08 (metadata.drawing_checked_by + sheet_number_format), D-09 (generic naming verified)

End with the 🚨 "Files to upload to live" section listing:
- `database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php`
- `app/Models/Project.php`
- `config/drawings.php`
- Note: run `php artisan migrate --force` + `php artisan config:clear` on live after upload.
</output>