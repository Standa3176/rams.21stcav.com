---
phase: 21-device-port-catalog-stencil-cache
plan: 01
type: execute
wave: 1
tags: [drawings, device-catalog, mxgraph, schema, foundation, v2.0]
depends_on: []
files_modified:
  - database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php
  - app/Models/DeviceStencil.php
  - app/Models/DevicePort.php
  - app/Services/Drawings/DeviceStencilCacheService.php
  - app/Services/Drawings/AutoGenericStencilGenerator.php
  - app/Models/Project.php
  - tests/Unit/Models/DeviceStencilTest.php
  - tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php
  - tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php
  - tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php
autonomous: true
requirements: [DRAW-31, DRAW-32, DRAW-34, DRAW-36]

must_haves:
  truths:
    - "When Phase 23's renderer (or this plan's accessor) asks for a stencil for an uncatalogued part_number, a Tier 1 auto-generic stencil is created and persisted on first reference (DRAW-34)"
    - "When the same part_number is asked for a second time (same project or any other project), the cached row is returned — no duplicate insert (D-03 cross-project propagation contract)"
    - "Phase 23's renderer can call $project->devicesWithStencils() and receive the project's hardware lines paired with their DeviceStencil row (DRAW-36)"
    - "The two new tables follow generic naming (no rams_/project_ prefix) so they port to SCC after the planned RAMS+SCC merge (D-09)"
    - "The auto-generic stencil's mxgraph_xml is a valid <shape> document containing the manufacturer + model + part_number text — Phase 23's draw.io embed renders it without fatal (D-04)"
  artifacts:
    - path: "database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php"
      provides: "device_stencils + device_ports tables with full column shape per D-02; FK device_ports.device_stencil_id cascade delete; unique index on device_stencils.part_number (case-insensitive enforced at app layer); unique compound (device_stencil_id, port_id) on device_ports"
      contains: "Schema::create('device_stencils'"
    - path: "app/Models/DeviceStencil.php"
      provides: "Eloquent model with $fillable, casts (metadata=>array), source enum constants, ports() HasMany, normalisedPartNumber() helper"
      exports: ["SOURCE_AUTO_GENERATED", "SOURCE_ENGINEER_CURATED", "SOURCE_AI_EXTRACTED", "ports", "isCurated"]
    - path: "app/Models/DevicePort.php"
      provides: "Eloquent model with $fillable, side/direction enum constants, stencil() BelongsTo"
      exports: ["SIDE_LEFT", "SIDE_RIGHT", "SIDE_TOP", "SIDE_BOTTOM", "DIRECTION_IN", "DIRECTION_OUT", "DIRECTION_IO", "stencil"]
    - path: "app/Services/Drawings/DeviceStencilCacheService.php"
      provides: "resolveForPartNumber($partNumber, array $hints) — firstOrCreate-on-normalised-part_number; auto-generic generator delegated to AutoGenericStencilGenerator; resolveMany($lines) bulk variant; pure read after first hit (no DB writes on cache hit). Race-condition rationale documented per D-03 (no transaction wrap; unique index makes firstOrCreate idempotent)."
      exports: ["resolveForPartNumber", "resolveMany"]
    - path: "app/Services/Drawings/AutoGenericStencilGenerator.php"
      provides: "build(array $hints): array — emits {mxgraph_xml, default_width, default_height, display_name} from manufacturer/model/name hints; visually basic per D-04; deterministic / idempotent given same hints"
      exports: ["build"]
    - path: "app/Models/Project.php"
      provides: "devicesWithStencils() accessor — returns equipment_list hardware joined to DeviceStencil via the cache service; mutates DB on first encounter of new part_numbers (Tier 1 placeholders auto-create) per D-07; race-condition rationale per D-03 documented in docblock"
      contains: "public function devicesWithStencils"
  key_links:
    - from: "Project::devicesWithStencils"
      to: "DeviceStencilCacheService::resolveMany"
      via: "service-class injection via app() container"
      pattern: "DeviceStencilCacheService"
    - from: "DeviceStencilCacheService::resolveForPartNumber"
      to: "DeviceStencil::firstOrCreate"
      via: "Eloquent firstOrCreate on normalised part_number"
      pattern: "firstOrCreate.*part_number"
    - from: "DeviceStencilCacheService::resolveForPartNumber (cache miss path)"
      to: "AutoGenericStencilGenerator::build"
      via: "constructor injection"
      pattern: "AutoGenericStencilGenerator"
    - from: "device_ports.device_stencil_id"
      to: "device_stencils.id"
      via: "FK with cascade delete (migration constraint)"
      pattern: "foreign\\('device_stencil_id'\\)"
---

<objective>
Lay the database + model + cache foundation for v2.0's engineering-grade drawings. Two new generic-named tables (`device_stencils` + `device_ports` per D-02 / D-09), a model layer with the standard signal-role-style enum constant pattern from `Device::ROLE_*`, a cache service that mirrors `DeviceCatalogService::lookupByPartNo` semantics (case-insensitive trimmed lookup) but writes through `firstOrCreate` for Tier 1 placeholders (per D-03), and the accessor Phase 23's renderer will consume (per D-07).

After this plan, the spike admin route's `DrawIoSpikeBuilderService::build()` still returns its hand-coded JSON output (Plan 21-03 swaps that). But every project gains `$project->devicesWithStencils()`, and every uncatalogued part_number that any code reads through the cache service gets a Tier 1 placeholder persisted automatically.

Per CONTEXT.md locked decisions:
- Generic naming for the two tables — no `rams_` / `project_` prefix (D-09)
- `firstOrCreate(part_number)` is the cross-project caching contract (D-03)
- Auto-generic Tier 1 shape per D-04 (no port rails — Phase 24 adds them)
- v1.3 D2 generator + `device-port-catalog.json` + `DeviceCatalogService` UNTOUCHED (D-10)
- No transaction wrapping on `firstOrCreate` — unique index makes the operation race-safe (D-03 concurrency note)

Purpose: deliver DRAW-31 (device_ports table), DRAW-32 (device_stencils table), DRAW-34 (auto-generic placeholder), DRAW-36 (Project accessor) — 4 of 6 Phase 21 requirements.

Output: two new tables, two new models, two new services, one Project accessor, four test files (DeviceStencilTest, AutoGenericStencilGeneratorTest, DeviceStencilCacheServiceTest, ProjectDevicesWithStencilsTest). No file artifacts on disk (mxgraph_xml lives in the table per D-12).
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
@CLAUDE.md

# v1.3 Phase 18 patterns to mirror:
@app/Services/Drawings/DeviceCatalogService.php
@database/seeders/DeviceCatalogSeeder.php
@database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php

# Spike output shape — Plan 21-03 will rewire this:
@app/Services/Drawings/DrawIoSpikeBuilderService.php
@resources/data/draw-io-stencils/21cav-mtr-spike.json

# Project / Package shape — Phase 23 renderer consumes the accessor:
@app/Models/Project.php
@app/Models/ProjectPackage.php

<interfaces>
<!-- Key types and contracts the executor needs. Extracted from codebase + CONTEXT.md D-02. -->

From `app/Models/Project.php`:
```php
public function latestPackage(): HasOne; // returns ProjectPackage|null
public function hardwarePartNumbers(): array; // lowercased, trimmed, deduped part numbers from latestPackage->equipment_list filtered to category=hardware
```

From `app/Models/ProjectPackage.php`:
```php
protected $casts = [
    'extracted_data' => 'array',
    'equipment_list' => 'array',
    'cable_list'     => 'array',
];
// Equipment line shape (canonical):
//   ['quantity' => int, 'part_number' => string, 'name' => string, 'area' => string, 'category' => 'hardware'|'cable'|'service']
```

From `app/Services/Drawings/DeviceCatalogService.php` — pattern Plan 21-01 mirrors (case-insensitive trimmed part_no lookup, memoised cache):
```php
private function path(): string;
public function all(): array; // keyed on strtolower(trim($row['part_no']))
public function lookupByPartNo(?string $partNo): ?array;
```

From `app/Models/Device.php` — enum-constant pattern Plan 21-01 mirrors on DeviceStencil.source / DevicePort.side / DevicePort.direction:
```php
public const ROLE_SOURCE = 'source';
public const ROLE_DESTINATION = 'destination';
public const ROLE_PROCESSOR = 'processor';
```

New types this plan creates (Phase 22+23+24 consume):
```php
// app/Models/DeviceStencil.php
class DeviceStencil extends Model {
    public const SOURCE_AUTO_GENERATED = 'auto-generated';
    public const SOURCE_ENGINEER_CURATED = 'engineer-curated';
    public const SOURCE_AI_EXTRACTED = 'ai-extracted';

    protected $fillable = [
        'part_number', 'manufacturer', 'model', 'display_name',
        'mxgraph_xml', 'logo_svg', 'default_width', 'default_height',
        'source', 'metadata',
    ];
    protected $casts = ['metadata' => 'array'];

    public function ports(): HasMany; // DevicePort, ordered by sort_order
    public function isCurated(): bool; // source !== SOURCE_AUTO_GENERATED
    public static function normalisePartNumber(string $partNumber): string;
}

// app/Models/DevicePort.php
class DevicePort extends Model {
    public const SIDE_LEFT = 'left';
    public const SIDE_RIGHT = 'right';
    public const SIDE_TOP = 'top';
    public const SIDE_BOTTOM = 'bottom';
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IO = 'io';

    protected $fillable = [
        'device_stencil_id', 'label', 'side', 'connector_type',
        'signal_type', 'direction', 'sort_order', 'port_id', 'y_pct', 'x_pct',
    ];
    protected $casts = ['y_pct' => 'decimal:4', 'x_pct' => 'decimal:4', 'sort_order' => 'integer'];

    public function stencil(): BelongsTo; // DeviceStencil
}

// app/Services/Drawings/DeviceStencilCacheService.php
class DeviceStencilCacheService {
    public function __construct(private AutoGenericStencilGenerator $generator) {}
    public function resolveForPartNumber(string $partNumber, array $hints = []): DeviceStencil;
    /** @param array<int, array{part_number:string, manufacturer?:?string, model?:?string, name?:string, quantity?:int, area?:?string}> $lines */
    public function resolveMany(array $lines): array; // returns array<int, array{...line, stencil: DeviceStencil}>
}

// app/Services/Drawings/AutoGenericStencilGenerator.php
class AutoGenericStencilGenerator {
    /** @param array{manufacturer?:?string, model?:?string, name?:?string, part_number?:?string} $hints
     *  @return array{mxgraph_xml:string, default_width:int, default_height:int, display_name:string} */
    public function build(array $hints): array;
}
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Migration + Models + Source/Side/Direction enum constants</name>
  <files>
    database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php,
    app/Models/DeviceStencil.php,
    app/Models/DevicePort.php,
    tests/Unit/Models/DeviceStencilTest.php
  </files>
  <behavior>
    - Migration up() creates `device_stencils` (id, part_number unique, manufacturer nullable, model nullable, display_name nullable, mxgraph_xml longText, logo_svg longText nullable, default_width unsignedSmallInteger default 220, default_height unsignedSmallInteger default 140, source string default 'auto-generated', metadata json nullable, timestamps) THEN `device_ports` (id, device_stencil_id FK→device_stencils.id cascade delete, label, side string, connector_type string, signal_type string, direction string, sort_order unsignedSmallInteger default 0, port_id string, y_pct decimal(5,4) nullable, x_pct decimal(5,4) nullable, timestamps) with compound unique index on (device_stencil_id, port_id) (per D-02)
    - Migration down() drops device_ports first, then device_stencils (FK order)
    - DeviceStencil model declares the three SOURCE_* constants (D-04) + $fillable + $casts (metadata=>array) + ports() HasMany ordered by sort_order asc + isCurated() helper + static normalisePartNumber() returning strtolower(trim($partNumber))
    - DevicePort model declares the SIDE_* + DIRECTION_* constants + $fillable + $casts + stencil() BelongsTo
    - DeviceStencilTest asserts: SOURCE_AUTO_GENERATED constant value === 'auto-generated', isCurated() returns false for auto-generated and true for engineer-curated, normalisePartNumber('  AM-300 ') === 'am-300', ports() relation type === HasMany
    - Migration runs clean against existing DB (no FK conflicts; device_stencils.part_number unique index does not collide with anything)
  </behavior>
  <action>
    Create the migration at `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` per D-02 column shape and D-09 generic-naming constraint. Mirror the anonymous-migration class pattern from `database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php`. Use `Schema::create` for both tables in dependency order; use `$table->foreignId('device_stencil_id')->constrained('device_stencils')->cascadeOnDelete()` on device_ports.

    Create `app/Models/DeviceStencil.php` mirroring the structure of `app/Models/Device.php` (enum-style public const SOURCE_* values, $fillable list matching the migration columns minus id/timestamps, $casts with metadata => array). Add `ports(): HasMany` returning `$this->hasMany(DevicePort::class)->orderBy('sort_order')`. Add `isCurated(): bool` returning `$this->source !== self::SOURCE_AUTO_GENERATED`. Add static `normalisePartNumber(string $partNumber): string` returning `strtolower(trim($partNumber))` (mirrors DeviceCatalogService::all()'s key derivation).

    Create `app/Models/DevicePort.php` mirroring the same pattern with SIDE_* + DIRECTION_* constants, $fillable, $casts (y_pct + x_pct as decimal:4, sort_order as integer), and `stencil(): BelongsTo` returning `$this->belongsTo(DeviceStencil::class)`.

    Per D-09 (RAMS+SCC merge readiness), table names + model names use generic terms — no `rams_` / `project_` prefix.

    Lint touched PHP files with `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`.

    Write `tests/Unit/Models/DeviceStencilTest.php` with the assertions listed in &lt;behavior&gt; — pure unit tests against the in-memory model (RefreshDatabase trait so the migration runs against the test DB).
  </action>
  <verify>
    <automated>php artisan migrate --pretend 2>&1 | grep -E "device_stencils|device_ports" &amp;&amp; php artisan test --filter=DeviceStencilTest</automated>
  </verify>
  <done>
    - Migration up() runs clean against fresh DB; down() rolls back without errors
    - DeviceStencil + DevicePort models exist with all enum constants per D-02
    - `php artisan test --filter=DeviceStencilTest` passes
    - Lint clean on all 4 touched files
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: AutoGenericStencilGenerator + DeviceStencilCacheService</name>
  <files>
    app/Services/Drawings/AutoGenericStencilGenerator.php,
    app/Services/Drawings/DeviceStencilCacheService.php,
    tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php,
    tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php
  </files>
  <behavior>
    - AutoGenericStencilGenerator::build($hints) returns an array {mxgraph_xml, default_width: 220, default_height: 140, display_name} where:
      - display_name = $hints['name'] OR ($hints['manufacturer'].' '.$hints['model']) OR $hints['part_number'] OR 'Unknown Device'
      - mxgraph_xml = a `<shape name="auto-XXX" h="140" w="220" aspect="variable" strokewidth="inherit">...<background>...rounded rect FAFAF6/1B7A7A...</background><foreground>...header bar 1B7A7A with manufacturer text white 12pt bold...model 11pt teal...part_number italic 9pt grey...</foreground></shape>` (per D-04 visual spec; NO connection rails — Phase 24's job)
      - mxgraph_xml is XML-escaped on every interpolated user value (mirrors DrawIoSpikeBuilderService::xml() helper, htmlspecialchars ENT_XML1|ENT_QUOTES)
      - Deterministic: same $hints → same mxgraph_xml byte-for-byte (no random IDs / timestamps inside)
    - AutoGenericStencilGeneratorTest asserts: build() with full hints produces XML containing 'NEAT', 'Bar Pro', the part number; XSS hint (manufacturer = '<script>') is escaped to '&lt;script&gt;'; no port-rail elements (assert mxgraph_xml does NOT contain 'constraint' or 'connections'); deterministic across two calls with same hints
    - DeviceStencilCacheService::resolveForPartNumber($partNumber, $hints) calls DeviceStencil::firstOrCreate(['part_number' => normalised], $autoGenericPayload) — on miss, the auto-generic payload is built from $hints via AutoGenericStencilGenerator::build() and stored with source = SOURCE_AUTO_GENERATED
    - resolveForPartNumber is NOT wrapped in DB::transaction — race-condition is benign per D-03 (unique index on part_number makes firstOrCreate atomic at the DB layer; concurrent first-call on same part_number raises QueryException on the loser, Eloquent retries as SELECT, net result is exactly one row). Docblock MUST document this rationale so a future dev doesn't reflexively wrap in a transaction.
    - resolveMany($lines) is a thin wrapper that calls resolveForPartNumber for each line and returns a list of [...line, 'stencil' => DeviceStencil]; lines with empty part_number are returned with stencil = null and a Log::info skip
    - DeviceStencilCacheServiceTest asserts: first call to resolveForPartNumber('NEAT-BAR-PRO', [hints]) creates a DeviceStencil with source=auto-generated; second call returns the same row (id matches) without creating a duplicate (DB has exactly 1 row); a third call with hints UPGRADED to engineer-curated (manually inserted into DB) returns the curated row, NOT a new auto-generic (per D-03 cache contract); resolveMany on a list with one valid + one empty-part_number returns 2 entries with the second's stencil=null
  </behavior>
  <action>
    Create `app/Services/Drawings/AutoGenericStencilGenerator.php` per D-04 visual spec. Use sprintf to assemble the mxgraph_xml; XML-escape every user-supplied value via the same htmlspecialchars(ENT_XML1|ENT_QUOTES) pattern from DrawIoSpikeBuilderService::xml() (T-17.02-01 protection carries forward). Layout: 220x140 outer rounded rect, 30px-tall teal header bar at top with manufacturer in white bold, body shows model (or display_name) in teal 11pt at y=55, part_number in italic grey 9pt at y=85, "Tier 1 placeholder" annotation in 7pt at y=130 so engineers know on sight which devices need promoting. NO `<connections>` element — auto-generic stencils have no port rails (D-04).

    Create `app/Services/Drawings/DeviceStencilCacheService.php` mirroring DeviceCatalogService's docblock and constructor-injection pattern. Constructor takes `AutoGenericStencilGenerator $generator`. Public methods:
    - `resolveForPartNumber(string $partNumber, array $hints = []): DeviceStencil` — normalises via DeviceStencil::normalisePartNumber, then `DeviceStencil::firstOrCreate(['part_number' => $normalised], $payload)` where $payload comes from $generator->build($hints) merged with manufacturer/model/source defaults.
    - `resolveMany(array $lines): array` — loops resolveForPartNumber, returns enriched lines.

    Document on the docblock: this service MUTATES the database on cache miss (per D-07 side-effect note); subsequent calls are pure SELECTs.

    **Race-condition / transaction docblock (per D-03):** add an explicit comment block on `resolveForPartNumber` stating:
    ```
    /**
     * NOTE: NOT wrapped in DB::transaction. Race-safety is provided by the
     * unique index on device_stencils.part_number — concurrent first-calls
     * on a fresh part_number race the INSERT; the loser hits a UNIQUE-violation
     * QueryException which Eloquent's firstOrCreate catches and retries as a
     * SELECT (Laravel core behaviour). Net: exactly one row, no data loss.
     * Stencil rows are read-only after creation from this service's perspective;
     * Phase 24 curation upgrades update an existing row (no new insert race).
     * Wrapping this call in a transaction would block on the unique index without
     * benefit and would actually HURT throughput under concurrent renderer hits.
     */
    ```

    Lint touched PHP files.

    Write the two test files with the assertions listed in &lt;behavior&gt;. RefreshDatabase trait. Use Mockery to inject a fake AutoGenericStencilGenerator into DeviceStencilCacheServiceTest cases that don't need real XML.
  </action>
  <verify>
    <automated>php artisan test --filter=AutoGenericStencilGeneratorTest &amp;&amp; php artisan test --filter=DeviceStencilCacheServiceTest</automated>
  </verify>
  <done>
    - AutoGenericStencilGenerator emits valid mxGraph shape XML matching D-04 spec, XSS-safe, deterministic
    - DeviceStencilCacheService uses firstOrCreate per D-03 — second call returns same row, never duplicates
    - Race-condition rationale documented on resolveForPartNumber docblock per D-03
    - Cache lookup respects engineer-curated upgrades (Phase 24 forward-compat)
    - All tests pass
    - Lint clean
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Project::devicesWithStencils() accessor + feature test</name>
  <files>
    app/Models/Project.php,
    tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php
  </files>
  <behavior>
    - Project::devicesWithStencils() reads $this->latestPackage?->extracted_data['equipment'] (fallback to equipment_list) — same source pattern as Project::hardwarePartNumbers()
    - Filters lines to category === 'hardware' (skip 'cable' / 'service' / null)
    - For each line, builds a hint array {part_number, manufacturer, model, name, quantity, area} and calls app(DeviceStencilCacheService::class)->resolveMany($lines)
    - Returns the enriched array per D-07 shape; lines with empty part_number have stencil=null
    - Empty package case: returns []
    - Side-effect documented in docblock: first call for an uncatalogued part_number persists a Tier 1 stencil; subsequent calls are pure SELECTs. Race-condition rationale per D-03 (no transaction wrap; unique index makes the firstOrCreate inside the cache service idempotent).
    - ProjectDevicesWithStencilsTest creates a project + package with 3 hardware lines (one cataloguable as a v1.3 catalog part_no, one auto-generic, one with empty part_number); calls devicesWithStencils(); asserts 3 entries returned, 2 with non-null stencil, 1 with null; calls devicesWithStencils() a SECOND time and asserts DB count of device_stencils === 2 (no duplicate insert); modifies one stencil to source=engineer-curated then calls a third time and asserts the curated row is returned (not overwritten)
  </behavior>
  <action>
    Add `devicesWithStencils()` method to `app/Models/Project.php`. Place it AFTER `hardwarePartNumbers()` so the methods cluster logically. PHPDoc must describe:
    - The side effect (Tier 1 placeholders auto-create on first read; per D-07)
    - The race-condition rationale (per D-03 — no transaction wrapping needed; unique index on device_stencils.part_number makes the underlying firstOrCreate atomic; concurrent reads on a fresh part_number converge to the same row).

    Pattern (mirrors Project::hardwarePartNumbers() loop):
    ```php
    public function devicesWithStencils(): array
    {
        $eq = $this->latestPackage?->extracted_data['equipment']
            ?? $this->latestPackage?->equipment_list
            ?? [];
        if (! is_array($eq)) return [];

        $lines = [];
        foreach ($eq as $line) {
            if (! is_array($line)) continue;
            $cat = strtolower(trim((string) ($line['category'] ?? 'hardware')));
            if ($cat !== 'hardware') continue;
            $lines[] = [
                'part_number' => (string) ($line['part_number'] ?? ''),
                'manufacturer' => $line['manufacturer'] ?? null,
                'model' => $line['model'] ?? null,
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'area' => $line['area'] ?? null,
            ];
        }

        return app(\App\Services\Drawings\DeviceStencilCacheService::class)->resolveMany($lines);
    }
    ```

    Lint Project.php.

    Write `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` per &lt;behavior&gt;. Use `RefreshDatabase` trait. Build the project + package via factories (or direct `create([...])` if no factory exists). Assert via `DeviceStencil::count()` and inspecting the returned array shape.
  </action>
  <verify>
    <automated>php artisan test --filter=ProjectDevicesWithStencilsTest</automated>
  </verify>
  <done>
    - Project::devicesWithStencils() returns the documented shape per D-07
    - Cache hit on second call (no duplicate inserts, asserted via DeviceStencil::count())
    - Engineer-curated upgrades survive subsequent calls
    - Docblock documents both the side effect AND the race-safety rationale (per D-03)
    - Test passes
    - Lint clean
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| QuoteWerks → ProjectPackage.extracted_data['equipment'] | manufacturer/model/name strings are AI-extracted from PDFs, MUST be treated as untrusted input when interpolated into mxgraph_xml |
| Cache service → DB write (Tier 1 auto-create) | the read-path mutates state; must be idempotent under concurrent access |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-21.01-01 | Tampering | AutoGenericStencilGenerator XML output | mitigate | XML-escape every interpolated value via htmlspecialchars(ENT_XML1\|ENT_QUOTES) — mirrors DrawIoSpikeBuilderService::xml() helper (Warning 7 / T-17.02-01 carries forward) |
| T-21.01-02 | Information Disclosure | DeviceStencilCacheService cross-project cache | accept | Cross-project caching is the desired contract (D-03). part_numbers are not customer secrets; manufacturer + model + part_number is shipped on every quote PDF anyway |
| T-21.01-03 | Denial of Service | Tier 1 auto-create on every accessor call | mitigate | First-call writes; subsequent calls are pure SELECTs. firstOrCreate is atomic at the DB layer (unique index on part_number) so concurrent first-calls don't double-insert (per D-03 — no transaction wrapper needed; would HURT throughput) |
| T-21.01-04 | Repudiation | DeviceStencil.source enum changes | accept | Phase 21 has no audit log on source flips. Phase 24's curation UI is the appropriate place to add change tracking when promotion semantics matter |
</threat_model>

<verification>
- `php artisan migrate --pretend` shows the new tables in the dry-run output
- `php artisan migrate` then `php artisan migrate:rollback` runs clean both ways
- All four test files pass: `php artisan test --filter='DeviceStencilTest|AutoGenericStencilGeneratorTest|DeviceStencilCacheServiceTest|ProjectDevicesWithStencilsTest'`
- Lint clean on every touched PHP file with Herd PHP 8.4
- Sanity check the auto-generic XML in tinker: `app(\App\Services\Drawings\DeviceStencilCacheService::class)->resolveForPartNumber('TEST-001', ['manufacturer' => 'Acme', 'model' => 'Widget']);` then inspect `DeviceStencil::where('part_number', 'test-001')->first()->mxgraph_xml` — should be a valid `<shape>` document with 'Acme' + 'Widget'
</verification>

<success_criteria>
- All 5 must_have truths are observable
- DRAW-31, DRAW-32, DRAW-34, DRAW-36 deliverable artifacts in place
- Two new tables ship with generic naming (D-09)
- Cache service implements `firstOrCreate(part_number)` contract (D-03) with documented race-safety rationale
- Tier 1 auto-generic stencil renders per D-04 visual spec
- v1.3 D2 generator + DeviceCatalogService + device-port-catalog.json untouched (D-10) — `git diff app/Services/DeviceCatalogService.php app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php resources/data/device-port-catalog.json` returns empty
- Plan 21-02 + Plan 21-03 unblocked
</success_criteria>

<output>
After completion, create `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` following the standard summary template.

**🚨 Files to upload to live (per D-13 / CLAUDE.md local-then-upload workflow):**

1. `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php`
2. `app/Models/DeviceStencil.php`
3. `app/Models/DevicePort.php`
4. `app/Services/Drawings/DeviceStencilCacheService.php`
5. `app/Services/Drawings/AutoGenericStencilGenerator.php`
6. `app/Models/Project.php`

(Tests stay local — do not deploy `tests/`.)

**Post-upload commands on live (in order):**
```bash
php artisan migrate                     # creates device_stencils + device_ports tables
php artisan config:clear                # only if .env or config touched (this plan: no)
php artisan cache:clear                 # belt-and-braces; new model class autoload
```

**Verification on live AFTER migration:**
- Visit `admin.drawings.draw-io-spike.show` for a real project — page MUST still load (Plan 21-01 doesn't touch the spike builder; this is just smoke-testing the new tables don't break autoload)
- `php artisan tinker` → `\App\Models\Project::find(1)->devicesWithStencils()` should return an array (may be empty if no package); after first run, `\App\Models\DeviceStencil::count()` should equal the number of unique hardware part_numbers in that project
</output>
</content>
