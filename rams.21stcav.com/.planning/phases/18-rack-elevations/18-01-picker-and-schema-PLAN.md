---
phase: 18-rack-elevations
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php
  - app/Models/Device.php
  - resources/data/device-port-catalog.json
  - app/Services/Drawings/DeviceCatalogService.php
  - database/seeders/DeviceCatalogSeeder.php
  - database/seeders/DatabaseSeeder.php
  - app/Http/Controllers/ProjectDrawingController.php
  - app/Services/Drawings/DrawingService.php
  - app/Services/Drawings/DrawingDataResolverService.php
  - resources/views/projects/drawings/_create-drawing-modal.blade.php
  - resources/views/projects/drawings/index.blade.php
  - routes/web.php
  - tests/Feature/Drawings/DeviceRackMetadataMigrationTest.php
  - tests/Feature/Drawings/DeviceCatalogSeederTest.php
  - tests/Feature/Drawings/DrawingPickerTest.php
  - tests/Unit/Services/Drawings/DrawingServiceRackTest.php
  - tests/Unit/Services/Drawings/RackStackForProjectTest.php
autonomous: true
requirements:
  - DRAW-08
  - DRAW-09
  - DRAW-11
  - DRAW-12
requirements_notes:
  - "DRAW-09 (partial — palette ordering only; AVIXA auto-ordering algorithm deferred per CONTEXT.md decision, lands in v1.3.x/v2.0)"

must_haves:
  truths:
    - "User sees a single + Create Drawing button on /projects/{id}/drawings (not three per-kind buttons)"
    - "Clicking + Create Drawing opens an Alpine modal with three kind cards: Signal Flow, Rack Elevation, Floor Plan"
    - "Signal Flow card has a Yes/No auto-generate toggle that defaults to Yes"
    - "Rack Elevation card has a single Create button (no auto-generate toggle); submitting creates an empty rack ProjectDrawing row with kind=rack and redirects to its show page"
    - "Floor Plan card is visibly disabled with a Coming in Phase 19 tooltip; POSTing kind=floor_plan to the picker redirects back with a 'kind' validation error in session (test asserts assertSessionHasErrors('kind'))"
    - "Devices table has u_height (decimal 4,2 nullable), is_rack_mounted (boolean nullable), requires_ventilation_gap_above (boolean nullable), requires_ventilation_gap_below (boolean nullable) columns — all default NULL"
    - "DeviceCatalogSeeder reads resources/data/device-port-catalog.json and upserts u_height/is_rack_mounted/current_draw_a/weight_kg/btu_per_hour onto Device rows by part_no — idempotent"
    - "Devices outside the JSON pack remain with NULL u_height (CRIT-06: never silent 1U guess)"
    - "ProjectDrawingController::createSchematic still works (Phase 17 compat) — picker submits route to it for kind=schematic + auto_generate=yes"
    - "DrawingService::generateInitial accepts kind=rack without throwing (creates empty rack row, no job dispatched, status=draft)"
    - "Existing Phase 17 tests still pass (no regressions to schematic flow)"
  artifacts:
    - path: "database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php"
      provides: "Devices table schema additions for rack metadata"
      contains: "u_height"
    - path: "resources/data/device-port-catalog.json"
      provides: "Hand-curated top-50 manufacturer JSON pack with u_height/is_rack_mounted/current_draw_a/weight_kg/btu_per_hour per part_no"
      min_lines: 350
    - path: "app/Services/Drawings/DeviceCatalogService.php"
      provides: "JSON pack reader + lookup by part_no (used by seeder + Plan 18-03 palette)"
      exports: ["lookupByPartNo", "all"]
    - path: "database/seeders/DeviceCatalogSeeder.php"
      provides: "Idempotent upsert of pack data onto existing Device rows"
    - path: "resources/views/projects/drawings/_create-drawing-modal.blade.php"
      provides: "Unified picker modal — Alpine + 3 kind cards"
      min_lines: 60
    - path: "app/Http/Controllers/ProjectDrawingController.php"
      provides: "Adds picker action + createRack action; refactors index button"
      contains: "createRack"
    - path: "tests/Feature/Drawings/DeviceRackMetadataMigrationTest.php"
      provides: "Asserts new device columns exist after migration"
    - path: "tests/Feature/Drawings/DrawingPickerTest.php"
      provides: "Asserts picker creates kind=rack rows and rejects floor_plan"
    - path: "tests/Unit/Services/Drawings/DrawingServiceRackTest.php"
      provides: "Service-level assertions for generateInitial(kind=rack): no job dispatch, status stays DRAFT, rack_meta scaffold correct"
    - path: "tests/Unit/Services/Drawings/RackStackForProjectTest.php"
      provides: "Locks the rackStackForProject return shape (palette key + per-row contract) Plan 18-03 consumes"
  key_links:
    - from: "resources/views/projects/drawings/index.blade.php"
      to: "resources/views/projects/drawings/_create-drawing-modal.blade.php"
      via: "@include + dispatch open-create-drawing"
      pattern: "open-create-drawing"
    - from: "resources/views/projects/drawings/_create-drawing-modal.blade.php"
      to: "ProjectDrawingController::picker"
      via: "POST /projects/{project}/drawings/picker"
      pattern: "projects\\.drawings\\.picker"
    - from: "ProjectDrawingController::createRack"
      to: "DrawingService::createForProject"
      via: "kind=rack"
      pattern: "ProjectDrawing::KIND_RACK"
    - from: "database/seeders/DeviceCatalogSeeder.php"
      to: "resources/data/device-port-catalog.json"
      via: "DeviceCatalogService::all()"
      pattern: "device-port-catalog\\.json"
---

<objective>
Land the Phase 18 foundations: (1) Device schema migration adding rack metadata columns (u_height, is_rack_mounted, ventilation gaps); (2) Hand-curated top-50 manufacturer JSON pack at resources/data/device-port-catalog.json + DeviceCatalogService reader + idempotent seeder; (3) Unified + Create Drawing picker UX replacing the per-kind buttons on the drawings index.

Purpose: Plan 18-03 cannot render rack elevations without u_height + is_rack_mounted on Device rows (CRIT-06: never silent 1U guess). Picker is the single entry point that lets engineers create rack drawings (and stays forward-compat for Phase 19 floor plans). Schema lands nullable-first (no backfill, no breaking changes to Phase 17 schematic pipeline).

Output:
  - Migration adding 4 nullable columns to devices.
  - JSON pack + service + seeder upserting rack metadata onto existing Device rows by part_no.
  - Alpine picker modal mirroring Phase 17 _regenerate-confirm-modal.blade.php pattern.
  - Refactored ProjectDrawingController with `picker` and `createRack` actions; `createSchematic` stays for Phase 17 compat.
  - Tests asserting the migration, seeder idempotency, picker behaviour, AND the cross-plan service contracts (DrawingService kind=rack flow + rackStackForProject return shape) Plan 18-03 consumes.
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
@.planning/phases/18-rack-elevations/18-CONTEXT.md
@.planning/research/SUMMARY.md
@.planning/research/STACK.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@./CLAUDE.md

# Phase 17 foundations Phase 18 builds on
@app/Models/ProjectDrawing.php
@app/Models/Device.php
@app/Services/Drawings/DrawingService.php
@app/Services/Drawings/DrawingDataResolverService.php
@app/Http/Controllers/ProjectDrawingController.php
@app/Policies/ProjectDrawingPolicy.php
@resources/views/projects/drawings/index.blade.php
@resources/views/projects/drawings/_regenerate-confirm-modal.blade.php
@resources/views/projects/drawings/_status-pill.blade.php
@routes/web.php
@database/migrations/2026_04_28_151100_create_devices_table.php
@database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php
@database/seeders/DatabaseSeeder.php
@database/seeders/ManufacturerSeeder.php

<interfaces>
<!-- Key contracts the executor needs without spelunking -->

From app/Models/ProjectDrawing.php:
```
public const KIND_SCHEMATIC = 'schematic';
public const KIND_RACK = 'rack';
public const KIND_FLOOR_PLAN = 'floor_plan';

public const STATUS_DRAFT = 'draft';
public const STATUS_GENERATING = 'generating';
public const STATUS_READY = 'ready';
// ... etc

protected $fillable = [
    'project_id', 'site_survey_room_id', 'kind', 'rack_label',
    'version', 'superseded_by_id',
    'source_data', 'generated_svg', 'canvas_state', 'thumbnail_png_path',
    'status', 'error_message', 'filename',
    'completion_email_sent_at', 'failed_email_sent_at',
    'access_token', 'generated_by',
];
```

From app/Models/Device.php (current $fillable — extend with new columns):
```
protected $fillable = [
    'project_id', 'room_name', 'description', 'model', 'manufacturer',
    'part_no', 'signal_role', 'qty',
    'serial_number', 'mac_address', 'ip_address', 'vlan', 'port',
    'firmware_version', 'asset_tag',
    'commissioning_date', 'warranty_expiry',
];
```

From app/Services/Drawings/DrawingService.php (Phase 17):
```php
public function createForProject(
    Project $project, string $kind, ?int $roomId, int $userId,
): ProjectDrawing;

public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing;
// CURRENTLY throws RuntimeException for kind != schematic — Plan 18-01 must
// extend this method to accept kind=rack (creates row but does NOT dispatch
// any job, since Phase 18 rack rendering is synchronous in Plan 18-03).
```

From routes/web.php (Phase 17 routes — extend, don't replace):
```php
Route::get('projects/{project}/drawings', [ProjectDrawingController::class, 'index'])
    ->name('projects.drawings.index');
Route::post('projects/{project}/drawings/create-schematic',
    [ProjectDrawingController::class, 'createSchematic'])
    ->name('projects.drawings.create-schematic');
// — Plan 18-01 ADDS:
//   POST projects/{project}/drawings/picker  → ProjectDrawingController@picker
//   POST projects/{project}/drawings/create-rack → ProjectDrawingController@createRack
```

From resources/views/projects/drawings/_regenerate-confirm-modal.blade.php (pattern to mirror):
```blade
<div x-data="{ open: false, drawingId: null, hasUserEdits: false }"
     @open-regenerate-confirm.window="open = true; ...">
  <template x-if="open"> ... </template>
</div>
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Device schema migration + JSON pack + DeviceCatalogService + seeder</name>
  <files>
    - database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php
    - app/Models/Device.php
    - resources/data/device-port-catalog.json
    - app/Services/Drawings/DeviceCatalogService.php
    - database/seeders/DeviceCatalogSeeder.php
    - database/seeders/DatabaseSeeder.php
    - tests/Feature/Drawings/DeviceRackMetadataMigrationTest.php
    - tests/Feature/Drawings/DeviceCatalogSeederTest.php
  </files>
  <read_first>
    - app/Models/Device.php (current $fillable — append new columns)
    - database/migrations/2026_04_28_151100_create_devices_table.php (column order — verify part_no exists for ->after())
    - database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php (most recent migration shape — mirror this exactly: ->after('part_no'), nullable, dropColumn in down())
    - database/seeders/DatabaseSeeder.php (where to register DeviceCatalogSeeder)
    - database/seeders/ManufacturerSeeder.php (existing seeder pattern — chunked upsert, idempotent)
    - app/Services/Drawings/DrawingDataResolverService.php (service-namespace pattern — DeviceCatalogService lives at the same App\Services\Drawings\ namespace)
  </read_first>
  <behavior>
    - Test 1: After migration runs, Schema::hasColumn('devices', 'u_height') === true; same for is_rack_mounted, requires_ventilation_gap_above, requires_ventilation_gap_below.
    - Test 2: Existing Device rows (project_id 1, part_no 'AM-3200-GV') get u_height=1.0, is_rack_mounted=true after seeder runs; idempotent — running twice does not duplicate.
    - Test 3: Devices NOT in the JSON pack remain with NULL u_height (no silent default, CRIT-06).
    - Test 4: DeviceCatalogService::lookupByPartNo('AM-3200-GV') returns array with keys u_height, is_rack_mounted, current_draw_a, weight_kg, btu_per_hour. Returns null for unknown part_no. Lookup is case-insensitive trimmed (mirrors DrawingDataResolverService::loadSignalRolesForProject normalisation).
    - Test 5: DeviceCatalogService::all() returns array<string, array> keyed on normalised part_no.
  </behavior>
  <action>
**Step 1.1 — Write failing migration test FIRST (RED):**

Create `tests/Feature/Drawings/DeviceRackMetadataMigrationTest.php` using `RefreshDatabase`. Assert all four columns exist on the `devices` table:
```php
$this->assertTrue(\Schema::hasColumn('devices', 'u_height'));
$this->assertTrue(\Schema::hasColumn('devices', 'is_rack_mounted'));
$this->assertTrue(\Schema::hasColumn('devices', 'requires_ventilation_gap_above'));
$this->assertTrue(\Schema::hasColumn('devices', 'requires_ventilation_gap_below'));
```
Run `php artisan test --filter=DeviceRackMetadataMigrationTest` — MUST fail (columns don't exist yet).

**Step 1.2 — Ship the migration (GREEN):**

Create `database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php` mirroring the Phase 17 signal_classification migration shape exactly:
```php
return new class extends Migration {
    public function up(): void {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('u_height', 4, 2)->nullable()->after('signal_role');
            $table->boolean('is_rack_mounted')->nullable()->after('u_height');
            $table->boolean('requires_ventilation_gap_above')->nullable()->after('is_rack_mounted');
            $table->boolean('requires_ventilation_gap_below')->nullable()->after('requires_ventilation_gap_above');
        });
    }
    public function down(): void {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'u_height', 'is_rack_mounted',
                'requires_ventilation_gap_above', 'requires_ventilation_gap_below',
            ]);
        });
    }
};
```

DECIMAL(4,2) supports 1.0 / 1.5 / 2.0 / 4.0 etc up to 99.99 — explicit per CONTEXT.md "u_height (decimal, allows 1.5)". All four columns nullable — CRIT-06 protection (NULL surfaces "U-height unknown" warning, never silent 1U guess).

**Step 1.3 — Append to Device $fillable + casts:**

Add the four new columns to `Device::$fillable`. Add casts:
```php
'u_height' => 'decimal:2',
'is_rack_mounted' => 'boolean',
'requires_ventilation_gap_above' => 'boolean',
'requires_ventilation_gap_below' => 'boolean',
```

**Step 1.4 — Build the JSON pack at `resources/data/device-port-catalog.json`:**

Hand-curate the top-50 devices used in 21CAV's recent quotes. **Acceptance is a minimum of 50 entries — this is locked per CONTEXT.md decision D-13 ("Hand-curated top-50 manufacturer JSON pack"). Do NOT ship fewer.** Shape per entry:
```json
{
  "part_no": "AM-3200-GV",
  "manufacturer": "Crestron",
  "model": "AirMedia 3200",
  "u_height": 1.0,
  "is_rack_mounted": true,
  "current_draw_a": 0.5,
  "weight_kg": 1.8,
  "btu_per_hour": 60
}
```

Include AT LEAST these classes (look up actual specs from manufacturer datasheets — Crestron / Sony / Barco / Netgear / QSC / Shure / Logitech / ClickShare / Extron / Crestron Flex):

- Rack-mounted core: AM-3200-GV (Crestron 1U), RMC4 (Crestron 1U), AM-300 (1U), DGE-200 (1U), AM-3100 (1U)
- Network: M4250-10G2F (Netgear 1U), GS108T (Netgear desktop, is_rack_mounted=false), CISCO-CBS350-24 (1U)
- DSP/audio: Q-SYS-Core-110f (1U), TesiraFORTE-VI (1U), QSC-CX-Q-2K4 (2U amp, ventilation_gap_above=true)
- Wall/ceiling-mount destinations (is_rack_mounted=false, u_height=null): Sony FW-75BZ35L, Sony FW-65BZ40L, ClickShare Bar Pro, ClickShare CX-50, Logitech Rally Bar, MeetUp, Tap, Yealink MeetingBar A30
- Cameras (is_rack_mounted=false): Logitech Rally PTZ, Sony SRG-X400, AVer CAM550
- Mics/buttons (is_rack_mounted=false): Shure MXA920, MXA710, Crestron CCS-UC-1, ClickShare Button
- Sources (is_rack_mounted=false): Apple TV, Brightsign XD234, Sony BRAVIA HX1
- PDU: APC AP7900 (1U, requires_ventilation_gap_above=false)
- Mounts/brackets (is_rack_mounted=false, u_height=null): Vogel's PFW-6885, Chief LSM1U

Devices that mount on walls/ceilings get `is_rack_mounted: false` and `u_height: null` — that combination is meaningful (palette greys them out in Plan 18-03 but they remain selectable). Mounts/brackets get the same. Validate the JSON parses (`php -r 'json_decode(file_get_contents("resources/data/device-port-catalog.json"), true) ?: exit(1);'`).

**Target: 50 entries minimum, locked by CONTEXT.md.** If a SKU is no longer in 21CAV's recent quote pipeline (verify against the project_packages.equipment_list samples in storage), swap to a current equivalent and document the substitution in `18-01-SUMMARY.md` under a "JSON pack substitutions" subsection.

**Step 1.5 — Build DeviceCatalogService:**

Create `app/Services/Drawings/DeviceCatalogService.php`:
```php
namespace App\Services\Drawings;

class DeviceCatalogService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private function path(): string {
        return resource_path('data/device-port-catalog.json');
    }

    /** @return array<string, array<string, mixed>> keyed on normalised part_no */
    public function all(): array {
        if ($this->cache !== null) return $this->cache;
        $raw = file_get_contents($this->path());
        if ($raw === false) {
            throw new \RuntimeException("DeviceCatalogService: cannot read {$this->path()}");
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            throw new \RuntimeException('DeviceCatalogService: JSON pack is not a list');
        }
        $this->cache = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['part_no'] ?? '')));
            if ($key === '') continue;
            $this->cache[$key] = $row;
        }
        return $this->cache;
    }

    public function lookupByPartNo(?string $partNo): ?array {
        if ($partNo === null || trim($partNo) === '') return null;
        $key = strtolower(trim($partNo));
        return $this->all()[$key] ?? null;
    }
}
```

Mirrors DrawingDataResolverService normalisation (lowercase trim part_no). Memoised — Plan 18-03 palette will hit it dozens of times per render.

**Step 1.6 — Build DeviceCatalogSeeder:**

Create `database/seeders/DeviceCatalogSeeder.php`:
```php
namespace Database\Seeders;

use App\Models\Device;
use App\Services\Drawings\DeviceCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DeviceCatalogSeeder extends Seeder
{
    public function __construct(private readonly DeviceCatalogService $catalog) {}

    public function run(): void
    {
        $count = 0;
        foreach ($this->catalog->all() as $partNoLower => $row) {
            // Update ALL existing Device rows with this part_no across every project.
            // Idempotent — overwrites whatever was there. Devices not in the pack are untouched.
            $count += Device::query()
                ->whereRaw('LOWER(TRIM(part_no)) = ?', [$partNoLower])
                ->update([
                    'u_height' => $row['u_height'] ?? null,
                    'is_rack_mounted' => $row['is_rack_mounted'] ?? null,
                    'requires_ventilation_gap_above' => $row['requires_ventilation_gap_above'] ?? null,
                    'requires_ventilation_gap_below' => $row['requires_ventilation_gap_below'] ?? null,
                ]);
        }
        Log::info('DeviceCatalogSeeder: applied catalog', ['rows_updated' => $count]);
    }
}
```

Uses raw `LOWER(TRIM(part_no))` to match the case-insensitive lookup. Idempotent — re-running just re-applies the same values. Devices NOT in the pack stay with NULL u_height (CRIT-06 honoured).

Wire into `database/seeders/DatabaseSeeder.php`:
```php
$this->call(DeviceCatalogSeeder::class);
```

**Step 1.7 — Write seeder behaviour test (RED → GREEN):**

Create `tests/Feature/Drawings/DeviceCatalogSeederTest.php`:
- Insert a Device with part_no='AM-3200-GV', u_height=null, is_rack_mounted=null.
- Insert a Device with part_no='UNKNOWN-XYZ-999', u_height=null.
- Run `$this->seed(DeviceCatalogSeeder::class);`
- Assert AM-3200-GV row now has u_height=1.0 and is_rack_mounted=true.
- Assert UNKNOWN-XYZ-999 row STILL has u_height=null (CRIT-06 — no silent fill).
- Run seeder again. Assert AM-3200-GV row STILL has u_height=1.0 (idempotent).

Run `php artisan test --filter='DeviceCatalogSeederTest|DeviceRackMetadataMigrationTest'` — both pass.

DO NOT add a backfill prompt to project-package-review (per CONTEXT.md "is_rack_mounted checkbox UX" — that lands in Task 3 picker work via the rack editor palette in Plan 18-03; quote-review extension is a recommendation, not locked).
  </action>
  <acceptance_criteria>
    - `php artisan migrate` runs cleanly; `php artisan migrate:rollback` then `php artisan migrate` re-applies cleanly.
    - `Schema::hasColumn('devices', 'u_height')` true; same for the other three.
    - `php artisan db:seed --class=DeviceCatalogSeeder` exits 0; running twice is idempotent (no duplicates, same end state).
    - `php artisan test --filter='DeviceRackMetadataMigrationTest|DeviceCatalogSeederTest'` — all green.
    - `resources/data/device-port-catalog.json` parses as JSON AND contains AT LEAST 50 entries (verified via `php -r 'echo count(json_decode(file_get_contents("resources/data/device-port-catalog.json"), true)) >= 50 ? "OK" : exit(1);'`).
    - `app/Services/Drawings/DeviceCatalogService.php` exists, `lookupByPartNo` returns array for known parts and null for unknown.
    - DeviceCatalogService class is auto-loadable: `php artisan tinker --execute='echo get_class(app(\App\Services\Drawings\DeviceCatalogService::class));'` outputs the class name.
  </acceptance_criteria>
  <verify>
    <automated>php artisan test --filter='DeviceRackMetadataMigrationTest|DeviceCatalogSeederTest' && php -r 'exit(count(json_decode(file_get_contents("resources/data/device-port-catalog.json"), true)) >= 50 ? 0 : 1);' && echo "JSON OK with >= 50 entries"</automated>
  </verify>
  <done>
    Devices table has 4 new nullable columns. JSON pack has AT LEAST 50 entries (CONTEXT.md D-13 locked target). DeviceCatalogService memoises lookups. Seeder is idempotent. CRIT-06 honoured (devices outside pack stay NULL).
  </done>
</task>

<task type="auto">
  <name>Task 2: Extend DrawingService for kind=rack + add createRack/picker controller actions + service-level tests</name>
  <files>
    - app/Services/Drawings/DrawingService.php
    - app/Services/Drawings/DrawingDataResolverService.php
    - app/Http/Controllers/ProjectDrawingController.php
    - routes/web.php
    - tests/Unit/Services/Drawings/DrawingServiceRackTest.php
    - tests/Unit/Services/Drawings/RackStackForProjectTest.php
  </files>
  <read_first>
    - app/Services/Drawings/DrawingService.php (current generateInitial throws for kind!=schematic — must extend)
    - app/Services/Drawings/DrawingDataResolverService.php (rackStackForProject() currently throws — fill body)
    - app/Http/Controllers/ProjectDrawingController.php (current shape; mirror createSchematic for createRack)
    - app/Policies/ProjectDrawingPolicy.php (owner-OR-admin gate)
    - routes/web.php (Phase 17 drawings routes block — add picker + create-rack BEFORE the {drawing} wildcard)
    - tests/Unit/Services/Drawings/SchematicGeneratorServiceTest.php (or nearest equivalent — mirror unit-test pattern under tests/Unit/Services/Drawings/)
  </read_first>
  <action>
**Step 2.1 — Extend `DrawingService::generateInitial` to accept kind=rack:**

Current Phase 17 implementation throws RuntimeException for kind!=schematic. Refactor:
```php
public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing
{
    return match ($drawing->kind) {
        ProjectDrawing::KIND_SCHEMATIC => $this->generateInitialSchematic($drawing, $userId),
        ProjectDrawing::KIND_RACK => $this->generateInitialRack($drawing, $userId),
        default => throw new \RuntimeException(
            "DrawingService::generateInitial: kind '{$drawing->kind}' lands in Phase 19"
        ),
    };
}

private function generateInitialSchematic(ProjectDrawing $drawing, int $userId): ProjectDrawing
{
    // Existing Phase 17 body — flip status to GENERATING, dispatch BuildSchematicJob.
    $drawing->update([
        'status' => ProjectDrawing::STATUS_GENERATING,
        'generated_by' => $userId,
    ]);
    \App\Jobs\BuildSchematicJob::dispatch($drawing->id);
    Log::info('DrawingService: generateInitial dispatched (schematic)', [
        'drawing_id' => $drawing->id,
    ]);
    return $drawing;
}

private function generateInitialRack(ProjectDrawing $drawing, int $userId): ProjectDrawing
{
    // Phase 18: rack creation is SYNCHRONOUS — no job dispatched. The drawing
    // stays in STATUS_DRAFT until the engineer saves rack canvas state in
    // Plan 18-03's editor (which then renders synchronously to STATUS_READY).
    // CONTEXT.md: "NO BuildRackElevationJob — there's no AI/D2/heavy work to defer."
    // Initialise an empty rack source_data shape so Plan 18-03 can read it.
    $drawing->update([
        'status' => ProjectDrawing::STATUS_DRAFT,
        'generated_by' => $userId,
        'source_data' => array_merge(
            (array) ($drawing->source_data ?? []),
            [
                'rack_meta' => [
                    'rack_label' => $drawing->rack_label ?? 'Rack 1',
                    'rack_height_u' => 42,
                    'nominal_voltage_v' => 230,
                    'floor' => null,
                ],
                'rack_items' => [],
            ],
        ),
    ]);
    Log::info('DrawingService: generateInitial empty rack created (sync flow)', [
        'drawing_id' => $drawing->id,
    ]);
    return $drawing;
}
```

42U baseline + 230V nominal per CONTEXT.md "Claude's Discretion" (UK mains; engineer overrides per rack on edit). Status stays DRAFT — Plan 18-03's editor flips it to READY on first save with rendered SVG.

**Step 2.1.1 — Lock the rack-flow contract with a unit test (Blocker 3 fix):**

Create `tests/Unit/Services/Drawings/DrawingServiceRackTest.php`. Mirror `tests/Unit/Services/Drawings/SchematicGeneratorServiceTest.php` if it exists, otherwise mirror any service-level test under `tests/Unit/Services/`. Use `RefreshDatabase` (these are unit-level service tests, but they hit Eloquent, so `RefreshDatabase` is required).

Required assertions (one test per behaviour for clarity):
```php
public function test_generate_initial_for_rack_does_not_dispatch_any_job(): void
{
    \Illuminate\Support\Facades\Bus::fake();
    $project = \App\Models\Project::factory()->create();
    $drawing = \App\Models\ProjectDrawing::factory()->create([
        'project_id' => $project->id,
        'kind' => 'rack',
        'status' => 'draft',
    ]);

    app(\App\Services\Drawings\DrawingService::class)
        ->generateInitial($drawing, $project->user_id);

    \Illuminate\Support\Facades\Bus::assertNothingDispatched();
}

public function test_generate_initial_for_rack_keeps_status_draft(): void
{
    // ... same fixture
    $result = app(DrawingService::class)->generateInitial($drawing, $userId);
    $this->assertSame('draft', $result->fresh()->status);
}

public function test_generate_initial_for_rack_seeds_42u_rack_meta(): void
{
    // ... same fixture
    $result = app(DrawingService::class)->generateInitial($drawing, $userId)->fresh();
    $this->assertSame(42, $result->source_data['rack_meta']['rack_height_u'] ?? null);
    $this->assertSame(230, $result->source_data['rack_meta']['nominal_voltage_v'] ?? null);
    $this->assertNull($result->source_data['rack_meta']['floor'] ?? 'X');
}

public function test_generate_initial_for_rack_seeds_empty_rack_items_array(): void
{
    // ... same fixture
    $result = app(DrawingService::class)->generateInitial($drawing, $userId)->fresh();
    $this->assertIsArray($result->source_data['rack_items'] ?? null);
    $this->assertSame([], $result->source_data['rack_items']);
}
```

These four assertions lock the behaviours Plan 18-03's `editRack` controller depends on at the service layer (independent of the controller path). Run `php artisan test --filter=DrawingServiceRackTest` — must be GREEN after Step 2.1 lands.

**Step 2.2 — Fill `DrawingDataResolverService::rackStackForProject` body:**

Replace the throw stub with:
```php
public function rackStackForProject(\App\Models\Project $project): array
{
    $data = $this->projectDataService->resolve($project);
    $equipment = (array) ($data['equipment'] ?? []);

    // Pre-load Device rows keyed by normalised part_no so the palette in
    // Plan 18-03 can show is_rack_mounted/u_height per item without N+1.
    $deviceMeta = \App\Models\Device::query()
        ->where('project_id', $project->id)
        ->whereNotNull('part_no')
        ->get(['part_no', 'u_height', 'is_rack_mounted',
               'requires_ventilation_gap_above', 'requires_ventilation_gap_below']);

    $metaByPart = [];
    foreach ($deviceMeta as $d) {
        $key = strtolower(trim((string) $d->part_no));
        if ($key === '') continue;
        $metaByPart[$key] = [
            'u_height' => $d->u_height !== null ? (float) $d->u_height : null,
            'is_rack_mounted' => $d->is_rack_mounted,
            'requires_ventilation_gap_above' => $d->requires_ventilation_gap_above,
            'requires_ventilation_gap_below' => $d->requires_ventilation_gap_below,
        ];
    }

    // Filter mounts/brackets/cables/services out — same exclusion list as
    // adjacencyForProject (mirrors filterHardware).
    // Per Plan 18-03 contract: rack-mounted rows come FIRST, others second
    // (palette ordering — DRAW-09 partial coverage; full AVIXA auto-place
    // algorithm is deferred to v1.3.x/v2.0 per CONTEXT.md).
    $rackMounted = [];
    $other = [];
    foreach ($this->filterHardware($equipment) as $idx => $item) {
        $partNo = (string) ($item['part_no'] ?? '');
        $key = strtolower(trim($partNo));
        $meta = $metaByPart[$key] ?? [
            'u_height' => null,
            'is_rack_mounted' => null,
            'requires_ventilation_gap_above' => null,
            'requires_ventilation_gap_below' => null,
        ];
        $row = [
            'equipment_id' => $item['id'] ?? $partNo ?: ('eq-'.$idx),
            'name' => (string) ($item['name'] ?? $item['description'] ?? ''),
            'manufacturer' => (string) ($item['manufacturer'] ?? ''),
            'part_no' => $partNo,
            'qty' => (int) ($item['qty'] ?? 1),
            'u_height' => $meta['u_height'],
            'is_rack_mounted' => $meta['is_rack_mounted'],
            'requires_ventilation_gap_above' => $meta['requires_ventilation_gap_above'],
            'requires_ventilation_gap_below' => $meta['requires_ventilation_gap_below'],
        ];
        if ($meta['is_rack_mounted'] === true) {
            $rackMounted[] = $row;
        } else {
            $other[] = $row;
        }
    }

    return [
        'palette' => array_merge($rackMounted, $other),
    ];
}
```

Returns `['palette' => array<row>]` — Plan 18-03's editor reads this and groups palette rows further by `is_rack_mounted=true` first, others second. Honours DATA-03 (only consumes ProjectDataService::resolve()).

**Step 2.2.1 — Lock the cross-plan return-shape contract (Blocker 4 fix):**

Create `tests/Unit/Services/Drawings/RackStackForProjectTest.php`. This test is the contract Plan 18-03's `editRack` controller depends on. Use `RefreshDatabase`.

Fixture: a project with three quote-import-style equipment items + matching Device rows:
1. **Rack-mounted item** — name="Crestron AirMedia 3200", part_no="AM-3200-GV"; Device row with `is_rack_mounted=true, u_height=1.0`.
2. **Non-rack item** — name="Sony FW-75BZ35L Display", part_no="FW-75BZ35L"; Device row with `is_rack_mounted=false, u_height=null`.
3. **Cable line item** — name="HDMI 2.0 cable 5m", part_no="HDMI-CAB-5M"; this MUST be filtered out by `filterHardware()` (cable category).

Assertions:
```php
$result = app(DrawingDataResolverService::class)->rackStackForProject($project);

// 1. Top-level shape
$this->assertIsArray($result);
$this->assertArrayHasKey('palette', $result);
$this->assertCount(2, $result['palette']); // cable filtered out

// 2. Per-row contract — every row has these exact keys
foreach ($result['palette'] as $row) {
    $this->assertSame(
        ['equipment_id', 'name', 'manufacturer', 'part_no', 'qty',
         'u_height', 'is_rack_mounted',
         'requires_ventilation_gap_above', 'requires_ventilation_gap_below'],
        array_keys($row),
        'palette row keys must match Plan 18-03 contract exactly',
    );
}

// 3. Cable item excluded
$partNos = array_column($result['palette'], 'part_no');
$this->assertNotContains('HDMI-CAB-5M', $partNos);

// 4. Ordering — rack-mounted FIRST, others SECOND
$this->assertSame('AM-3200-GV', $result['palette'][0]['part_no']);
$this->assertSame(true, $result['palette'][0]['is_rack_mounted']);
$this->assertSame('FW-75BZ35L', $result['palette'][1]['part_no']);
$this->assertSame(false, $result['palette'][1]['is_rack_mounted']);

// 5. Type contract — u_height is float-or-null, booleans are bool-or-null
$this->assertSame(1.0, $result['palette'][0]['u_height']);
$this->assertNull($result['palette'][1]['u_height']);
```

Run `php artisan test --filter=RackStackForProjectTest` — GREEN after Step 2.2 lands. Plan 18-03 can rely on these guarantees without re-discovering them.

**Step 2.3 — Add `createRack` and `picker` actions to ProjectDrawingController:**

Add after `createSchematic`:
```php
/**
 * Phase 18 — create an empty rack drawing. SYNCHRONOUS flow: no job
 * dispatched. Engineer always builds the rack manually in Plan 18-03's
 * editor (CONTEXT.md "NO auto-place flow"). Redirects to the rack editor
 * (show page) for immediate building.
 */
public function createRack(Request $request, Project $project): RedirectResponse
{
    if (! $request->user()) {
        abort(403);
    }
    $userId = (int) $request->user()->id;

    $rackLabel = trim((string) $request->input('rack_label', 'Rack '.($project->drawings()
        ->where('kind', ProjectDrawing::KIND_RACK)
        ->whereNull('superseded_by_id')
        ->count() + 1)));

    $drawing = $this->drawingService->createForProject(
        $project,
        ProjectDrawing::KIND_RACK,
        null, // no specific room — rack is project-level
        $userId,
    );
    $drawing->update(['rack_label' => $rackLabel]);

    $this->drawingService->generateInitial($drawing, $userId);

    return redirect()
        ->route('projects.drawings.show', [$project, $drawing])
        ->with('status', "Rack '{$rackLabel}' created — drag equipment from the palette to build it.");
}

/**
 * Phase 18 — unified create-drawing picker. Single endpoint that dispatches
 * to the kind-specific create action. Floor plans are deferred to Phase 19
 * (returns redirect with session 'kind' validation error — standard Laravel
 * pattern; the picker modal surfaces it).
 */
public function picker(Request $request, Project $project): RedirectResponse
{
    $kind = (string) $request->input('kind', '');
    return match ($kind) {
        ProjectDrawing::KIND_SCHEMATIC => $this->createSchematic($request, $project),
        ProjectDrawing::KIND_RACK => $this->createRack($request, $project),
        ProjectDrawing::KIND_FLOOR_PLAN => back()->withErrors([
            'kind' => 'Floor plans land in Phase 19 — coming soon.',
        ]),
        default => back()->withErrors(['kind' => 'Unknown drawing kind']),
    };
}
```

**Step 2.4 — Wire routes:**

Edit `routes/web.php` Phase 17 drawings block. Add BEFORE the `{drawing}` wildcard:
```php
Route::post('projects/{project}/drawings/picker',
    [ProjectDrawingController::class, 'picker'])
    ->name('projects.drawings.picker');
Route::post('projects/{project}/drawings/create-rack',
    [ProjectDrawingController::class, 'createRack'])
    ->name('projects.drawings.create-rack');
```

KEEP the existing `create-schematic` route for backwards compat (some test fixtures + the picker fallback both call it).

**Step 2.5 — Quick smoke test:**

```bash
php artisan route:list --name=drawings
```
Should list `projects.drawings.picker` and `projects.drawings.create-rack` alongside the existing 6 Phase 17 routes.

Run existing Phase 17 tests + the two new unit tests to confirm zero regressions:
```bash
php artisan test --filter='Drawings|DrawingServiceRackTest|RackStackForProjectTest'
```
  </action>
  <acceptance_criteria>
    - `php artisan route:list --name=drawings` lists `projects.drawings.picker` and `projects.drawings.create-rack`.
    - `DrawingService::generateInitial` accepts kind=rack without throwing (verified by `DrawingServiceRackTest`).
    - `DrawingServiceRackTest` asserts: no job dispatched, status stays DRAFT, rack_meta.rack_height_u=42, rack_items=[].
    - `DrawingDataResolverService::rackStackForProject($project)` returns `['palette' => [...]]` with rack-mounted rows first.
    - `RackStackForProjectTest` asserts: top-level `palette` key, exact per-row key list, cable items filtered out, rack-mounted-first ordering, type contract on u_height/is_rack_mounted.
    - All Phase 17 tests still pass: `php artisan test --filter='Drawings'`.
    - `app/Services/Drawings/DrawingService.php` line count grew by reasonable amount; original schematic flow logic preserved verbatim.
  </acceptance_criteria>
  <verify>
    <automated>php artisan route:list --name=drawings | grep -E 'projects\.drawings\.(picker|create-rack)' && php artisan test --filter='Drawings|DrawingServiceRackTest|RackStackForProjectTest'</automated>
  </verify>
  <done>
    DrawingService dispatches by kind. rackStackForProject body returns palette shape Plan 18-03 needs. picker/createRack routes registered. Phase 17 tests untouched. Cross-plan contracts (DrawingService rack flow + rackStackForProject return shape) locked by dedicated unit tests so Plan 18-03 doesn't re-discover them.
  </done>
</task>

<task type="auto">
  <name>Task 3: Picker modal Blade + index.blade.php refactor + feature test</name>
  <files>
    - resources/views/projects/drawings/_create-drawing-modal.blade.php
    - resources/views/projects/drawings/index.blade.php
    - tests/Feature/Drawings/DrawingPickerTest.php
  </files>
  <read_first>
    - resources/views/projects/drawings/_regenerate-confirm-modal.blade.php (Alpine modal pattern to mirror — x-data + open-event listener + form action)
    - resources/views/projects/drawings/index.blade.php (current "Generate Schematic" form button — replace with picker trigger; PRESERVE the existing Schematics section for Phase 17)
    - resources/views/projects/drawings/_status-pill.blade.php (CSS class pattern for badges)
    - app/Models/ProjectDrawing.php (KIND_* constants — Blade uses these)
  </read_first>
  <action>
**Step 3.1 — Build `_create-drawing-modal.blade.php`:**

Mirror `_regenerate-confirm-modal.blade.php` Alpine + modal shell pattern. Three kind cards stacked vertically; Schematic card has Yes/No auto-gen radio; Rack card has Create button; Floor Plan card disabled.

```blade
{{-- Phase 18 Plan 01 — unified + Create Drawing picker.
     Mirrors _regenerate-confirm-modal.blade.php Alpine pattern.
     Three kind cards: Signal Flow (with auto-gen toggle), Rack Elevation
     (single Create button), Floor Plan (disabled — Phase 19). --}}
<div x-data="{ open: false, kind: 'schematic', autoGenerate: 'yes' }"
     @open-create-drawing.window="open = true"
     @keydown.escape.window="open = false">
    <template x-if="open">
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
             @click.self="open = false"
             role="dialog"
             aria-modal="true"
             aria-labelledby="create-drawing-title">
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-2xl w-full mx-4">
                <h3 id="create-drawing-title" class="font-semibold text-lg text-gray-900 mb-4">
                    Create Drawing
                </h3>

                {{-- Signal Flow card (auto-gen Yes/No) --}}
                <form method="POST"
                      action="{{ route('projects.drawings.picker', $project) }}"
                      class="border border-gray-200 rounded-lg p-4 mb-3 hover:border-teal-500 transition">
                    @csrf
                    <input type="hidden" name="kind" value="schematic">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">Signal Flow Schematic</div>
                            <p class="text-sm text-gray-500 mt-1">
                                Per-room signal-flow diagram with cable IDs, port labels, and AVIXA-style symbols.
                            </p>
                            <label class="inline-flex items-center mt-3 text-sm text-gray-700">
                                <input type="radio" name="auto_generate" value="yes" checked
                                       class="mr-2">
                                Auto-generate from project data
                            </label>
                            <label class="inline-flex items-center ml-4 mt-3 text-sm text-gray-700">
                                <input type="radio" name="auto_generate" value="no"
                                       class="mr-2">
                                Start blank
                            </label>
                        </div>
                        <button type="submit"
                                class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                            Create
                        </button>
                    </div>
                </form>

                {{-- Rack Elevation card (NO auto-gen toggle — engineer always manually builds) --}}
                <form method="POST"
                      action="{{ route('projects.drawings.picker', $project) }}"
                      class="border border-gray-200 rounded-lg p-4 mb-3 hover:border-teal-500 transition">
                    @csrf
                    <input type="hidden" name="kind" value="rack">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">Rack Elevation</div>
                            <p class="text-sm text-gray-500 mt-1">
                                1U-precise rack drawing with U-numbered rail. You'll drag equipment from a palette into U-slots.
                            </p>
                            <p class="text-xs text-gray-400 mt-2">
                                A 42U rack will be created — you can adjust the height in the editor.
                            </p>
                        </div>
                        <button type="submit"
                                class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                            Create
                        </button>
                    </div>
                </form>

                {{-- Floor Plan card — DISABLED, Phase 19 --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-3 opacity-50 cursor-not-allowed"
                     title="Coming in Phase 19">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-700">Floor Plan / Elevation</div>
                            <p class="text-sm text-gray-500 mt-1">
                                In-browser canvas drawing tool with walls, doors, windows, and equipment glyphs.
                            </p>
                        </div>
                        <span class="bg-gray-200 text-gray-500 px-3 py-2 rounded-md text-xs font-medium whitespace-nowrap">
                            Coming in Phase 19
                        </span>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="mt-3 bg-red-50 border border-red-200 text-red-800 p-3 rounded text-sm">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="mt-4 flex justify-end">
                    <button type="button"
                            @click="open = false"
                            class="px-4 py-2 rounded border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
```

Per CONTEXT.md security model: Blade-escaped values use `{{ }}` (not `{!! !!}`). No `route('projects.drawings.create-schematic')` URLs hardcoded — picker route is the single entry; backwards-compat schematic route still exists for direct test calls.

**Step 3.2 — Refactor `index.blade.php`:**

Replace the existing form-button at lines 26-32 with a single trigger button:
```blade
<button type="button"
        x-data
        @click="$dispatch('open-create-drawing')"
        class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-medium px-4 py-2 rounded-lg text-sm shadow-sm">
    <span aria-hidden="true">＋</span>
    <span>Create Drawing</span>
</button>
```

**PRESERVE the existing Phase 17 Schematics section verbatim.** Add a Rack Elevations section BELOW it (mirroring its shape), showing rack rows once created:
```blade
{{-- ───── Rack Elevations (Phase 18) ──────────────────────────── --}}
<h2 class="text-lg font-semibold mt-6 mb-3 text-gray-800">
    Rack Elevations
    @php($racks = $drawings->where('kind', \App\Models\ProjectDrawing::KIND_RACK))
    <span class="text-sm text-gray-500 font-normal">({{ $racks->count() }})</span>
</h2>

@forelse ($racks as $drawing)
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-3 flex items-center justify-between">
        <div class="min-w-0">
            <div class="font-medium text-gray-900 truncate">
                {{ $drawing->rack_label ?? 'Rack '.$drawing->id }}
            </div>
            <div class="text-xs text-gray-500">
                Revision {{ $drawing->revisionLabel() }}
                · Updated {{ $drawing->updated_at?->diffForHumans() }}
            </div>
        </div>
        <div class="flex items-center gap-3 flex-wrap justify-end">
            @include('projects.drawings._status-pill', ['drawing' => $drawing])

            @if ($drawing->isReady())
                <a href="{{ route('projects.drawings.download', [$project, $drawing, 'pdf']) }}" class="text-sm text-teal-700 hover:underline">PDF</a>
                <a href="{{ route('projects.drawings.download', [$project, $drawing, 'svg']) }}" class="text-sm text-teal-700 hover:underline">SVG</a>
            @endif

            <a href="{{ route('projects.drawings.show', [$project, $drawing]) }}"
               class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1 rounded-md text-sm">
                Open
            </a>
        </div>
    </div>
@empty
    <div class="bg-gray-50 border border-dashed border-gray-300 rounded-lg p-6 text-center text-sm text-gray-500">
        No rack elevations yet — click <span class="font-medium text-gray-700">Create Drawing</span> above to start.
    </div>
@endforelse
```

@include the new picker modal at the end alongside the existing regenerate-confirm-modal include:
```blade
@include('projects.drawings._create-drawing-modal', ['project' => $project])
@include('projects.drawings._regenerate-confirm-modal', ['project' => $project])
```

**Step 3.3 — Build feature test:**

Create `tests/Feature/Drawings/DrawingPickerTest.php`:
```php
namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawingPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_creates_rack_drawing_with_default_label_and_42u(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'rack',
            ]);

        $rack = ProjectDrawing::where('project_id', $project->id)
            ->where('kind', 'rack')
            ->first();

        $this->assertNotNull($rack);
        $this->assertSame('rack', $rack->kind);
        $this->assertSame('draft', $rack->status); // sync flow — no job, no GENERATING
        $this->assertSame('Rack 1', $rack->rack_label);
        $this->assertSame(42, $rack->source_data['rack_meta']['rack_height_u'] ?? null);
        $this->assertIsArray($rack->source_data['rack_items'] ?? null);
        $response->assertRedirect(route('projects.drawings.show', [$project, $rack]));
    }

    public function test_picker_rejects_floor_plan_kind(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->from(route('projects.drawings.index', $project))
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'floor_plan',
            ]);

        $response->assertSessionHasErrors('kind');
        $this->assertSame(0, ProjectDrawing::where('kind', 'floor_plan')->count());
    }

    public function test_picker_dispatches_to_create_schematic_for_kind_schematic(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        \Illuminate\Support\Facades\Bus::fake();

        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'schematic',
                'auto_generate' => 'yes',
            ]);

        $schem = ProjectDrawing::where('kind', 'schematic')->first();
        $this->assertNotNull($schem);
        $this->assertSame('generating', $schem->status); // existing Phase 17 flow
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\BuildSchematicJob::class);
    }

    public function test_creating_a_second_rack_increments_label_to_rack_2(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), ['kind' => 'rack']);
        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), ['kind' => 'rack']);

        $racks = ProjectDrawing::where('project_id', $project->id)
            ->where('kind', 'rack')
            ->orderBy('id')
            ->pluck('rack_label');
        $this->assertSame(['Rack 1', 'Rack 2'], $racks->toArray());
    }
}
```

Run `php artisan test --filter=DrawingPickerTest` — should be GREEN now that Tasks 1-2 are landed.
  </action>
  <acceptance_criteria>
    - Visiting `/projects/{id}/drawings` shows ONE "+ Create Drawing" button (not three).
    - Clicking it opens an Alpine modal with three kind cards (visual check via grep — `_create-drawing-modal.blade.php` includes "Signal Flow", "Rack Elevation", "Floor Plan" + "Coming in Phase 19").
    - Phase 17 Schematics section is preserved on the index page: `grep -n "Schematics" resources/views/projects/drawings/index.blade.php` returns >= 1 hit.
    - POST to `/projects/{id}/drawings/picker` with kind=rack creates a ProjectDrawing row, status=draft, rack_label="Rack 1", source_data.rack_meta.rack_height_u=42.
    - POST with kind=floor_plan returns a redirect with session errors (no rows created).
    - POST with kind=schematic + auto_generate=yes dispatches BuildSchematicJob (Phase 17 path preserved).
    - All four DrawingPickerTest cases pass.
    - Phase 17 tests still pass: `php artisan test --filter=Drawings`.
    - Index page still renders without errors when 0 racks exist (the empty-state row).
  </acceptance_criteria>
  <verify>
    <automated>php artisan test --filter='DrawingPickerTest|Drawings' && grep -q "Schematics" resources/views/projects/drawings/index.blade.php && echo "Schematics section preserved"</automated>
  </verify>
  <done>
    Picker modal lives. Index page has one button. Phase 17 Schematics section preserved. Rack rows render in their own section. Phase 17 schematic flow intact. Tests green.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Browser → Picker form POST | Authenticated engineer submits kind + auto_generate; controller validates kind against an allow-list (schematic / rack / floor_plan) — anything else returns back() with errors. Floor_plan rejected with session 'kind' error deliberately. |
| Engineer → DeviceCatalogService JSON | The JSON pack is read-only at runtime, ships in repo (not user-uploadable) — no injection vector. JSON parse failures throw RuntimeException loudly. |
| Seeder → DB | DeviceCatalogSeeder uses parametrised whereRaw('LOWER(TRIM(part_no)) = ?') — no user-controlled input flows into the SQL; the part_no values come from the in-repo JSON pack. |
| Migration → DB | Schema-only DDL. Nullable-first columns; rollback path tested. No data backfill from user input. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-18.01-01 | Tampering | ProjectDrawingController::picker | mitigate | Validate `kind` against ProjectDrawing::KIND_* allow-list (match expression — default returns back() with errors). Reject anything else BEFORE creating any drawing row. |
| T-18.01-02 | Spoofing | createRack endpoint | mitigate | `if (! $request->user()) abort(403);` — Laravel auth middleware enforces user existence; controller asserts user is present. ProjectDrawingPolicy gates view/update/delete on the resulting row (owner OR admin). |
| T-18.01-03 | Information Disclosure | rackStackForProject palette | mitigate | Routes through DrawingDataResolverService → ProjectDataService::resolve() (DATA-03 contract). Equipment list scoped to $project->id; no cross-project leakage. RackStackForProjectTest verifies scope. |
| T-18.01-04 | Tampering | Picker form CSRF | mitigate | @csrf token on every form in _create-drawing-modal.blade.php (Laravel default; matches existing Phase 17 forms). |
| T-18.01-05 | XSS | rack_label render in index | mitigate | `{{ $drawing->rack_label }}` Blade-escaped (per CONTEXT.md security model). NEVER `{!! !!}` for user-controlled strings. |
| T-18.01-06 | Denial-of-Service | Picker spam-create | accept | Rack creation is cheap (one Eloquent insert + one update; no job dispatched). At 1 req/sec sustained an engineer would create 3,600 rows in an hour — well within DB bounds for an internal tool. Project ownership scoping limits blast radius. |
| T-18.01-07 | Repudiation | Drawing creation audit | mitigate | DrawingService::createForProject already logs `Log::info('DrawingService: drawing created', [...])` with project_id + kind + user_id (Phase 17 baseline). Plan 18-01 inherits this for kind=rack. |
| T-18.01-08 | Elevation of Privilege | Cross-project drawing creation | mitigate | Route binds `{project}` via Eloquent route model binding; controller does NOT enforce ownership at picker level (Phase 17 createSchematic also doesn't — gap pre-existing). Plan 18-03 follows same posture; Phase 21 client portal will add ProjectPolicy gating. Documented in CONTEXT.md as deferred. |
| T-18.01-09 | Tampering | DeviceCatalogSeeder injection | mitigate | JSON pack ships in repo (not engineer-uploaded). whereRaw uses bound parameter — no SQL injection risk. |
| T-18.01-10 | Information Disclosure | DeviceCatalogService file read | mitigate | Path is hardcoded `resource_path('data/device-port-catalog.json')` — no user-controlled path traversal possible. |
</threat_model>

<verification>
End-to-end smoke test (manual + automated):

1. **Migration up/down:**
   - `php artisan migrate:fresh --seed` — exits 0; devices table has new columns; AM-3200-GV (if seeded via existing flows) gets u_height=1.0.
2. **Picker UI:**
   - Visit `/projects/{id}/drawings` — ONE "+ Create Drawing" button (the Phase 17 "+ Generate Schematic" button is gone).
   - Click it — modal opens with three kind cards.
   - Click "Create" on Rack card — redirects to /projects/{id}/drawings/{drawing} (rack show page; Plan 18-03 fills the editor).
   - Index reloads — Rack 1 listed under "Rack Elevations" section, status=Draft.
   - Phase 17 Schematics section still visible on the same page.
3. **Phase 17 regression:**
   - From the picker modal, "Signal Flow" card with Auto-generate=yes → still produces a schematic with status=generating; BuildSchematicJob dispatched.
4. **CRIT-06 verification:**
   - Devices not in the JSON pack remain with u_height=null after seeder runs.
5. **Test suite:**
   - `php artisan test --filter='Drawings|DrawingServiceRackTest|RackStackForProjectTest'` — all green (Phase 17 tests + new Phase 18 tests + new unit tests).
6. **Route registry:**
   - `php artisan route:list --name=drawings` shows 8 routes (6 Phase 17 + 2 new: picker, create-rack).
</verification>

<success_criteria>
- [ ] Migration adds 4 nullable columns to devices (u_height decimal 4,2; is_rack_mounted bool; requires_ventilation_gap_above bool; requires_ventilation_gap_below bool).
- [ ] Migration down() reversibly drops them.
- [ ] resources/data/device-port-catalog.json has AT LEAST 50 entries (locked CONTEXT.md target — not a hedge) with the documented shape.
- [ ] DeviceCatalogService::lookupByPartNo is case-insensitive trimmed and returns null for unknowns.
- [ ] DeviceCatalogSeeder is idempotent and doesn't backfill devices outside the pack (CRIT-06 honoured).
- [ ] DrawingService::generateInitial accepts kind=rack and creates an empty 42U rack scaffold in source_data — synchronous, no job dispatched. **DrawingServiceRackTest locks this contract** at the service layer.
- [ ] DrawingDataResolverService::rackStackForProject returns ['palette' => […]] with per-row u_height/is_rack_mounted from Device + DATA-03-respecting equipment list. **RackStackForProjectTest locks the cross-plan return shape** Plan 18-03 consumes.
- [ ] ProjectDrawingController gains createRack + picker actions; createSchematic preserved verbatim.
- [ ] routes/web.php gains projects.drawings.picker + projects.drawings.create-rack BEFORE the {drawing} wildcard.
- [ ] _create-drawing-modal.blade.php exists with 3 cards (Signal Flow toggle, Rack create, Floor Plan disabled).
- [ ] index.blade.php has ONE "+ Create Drawing" button + a "Rack Elevations" section with empty-state + Phase 17 Schematics section preserved (grep verifies).
- [ ] DrawingPickerTest covers: rack create, floor_plan reject, schematic dispatch, rack label increment.
- [ ] Phase 17 test suite still green: `php artisan test --filter=Drawings`.
- [ ] Threat model dispositions enacted in code (kind allow-list, CSRF, Blade escape, scoped queries).
</success_criteria>

<output>
After completion, create `.planning/phases/18-rack-elevations/18-01-SUMMARY.md` summarising:
- Migration filename + columns added.
- JSON pack entry count (must be >= 50) + a few representative parts. If any SKUs were substituted from the planned list (because they're no longer in 21CAV's recent quote pipeline), document each substitution under a "JSON pack substitutions" subsection.
- DeviceCatalogService API surface.
- Picker route names + how Phase 17 createSchematic is reached through it.
- DrawingService + DrawingDataResolverService extension points (so Plan 18-03 reads them).
- Test counts (DrawingServiceRackTest: 4 cases; RackStackForProjectTest: 5 assertions; DrawingPickerTest: 4 cases; etc).
- Any deviation from this plan (with rationale).
</output>
