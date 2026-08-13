# Phase 24: Stencil Curation UI + Quote-Import Auto-Stub - Pattern Map

**Mapped:** 2026-08-13
**Files analyzed:** 24 (17 new, 7 modified)
**Analogs found:** 22 / 24

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Http/Controllers/Admin/DeviceStencilController.php` | controller | CRUD + request-response | `app/Http/Controllers/Admin/DeviceCableRuleController.php` (secondary: `DeviceController.php`) | exact |
| `app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php` | validation (FormRequest) | request-response | `app/Http/Requests/Admin/DeviceCableRuleRequest.php` | exact |
| `app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php` | validation (FormRequest) | file-I/O | `app/Http/Controllers/SiteSurveyController.php:455-458` (inline rule, not a FormRequest class — closest shape) | role-match |
| `app/Services/Drawings/CategoryPortTemplateResolver.php` | service | transform | `app/Services/Imports/EquipmentCategoryClassifier.php` (mechanism only, not vocabulary) + `app/Services/Drawings/DrawingDataResolverService.php:437-469` | role-match |
| `app/Services/Drawings/StencilPromotionValidator.php` | service | transform (validation gate) | No direct analog — closest shape is `DeviceCableRuleRequest`'s array-element validation rules, adapted into a service class because D-04's gate must also run non-HTTP (artisan-adjacent) | no analog |
| `app/Services/QuoteImport/QuoteImportStencilStubber.php` | service | event-driven (import hook) | `app/Services/Drawings/DeviceStencilCacheService.php` (the service it wraps) + call-site precedent in `app/Jobs/ExtractQuoteJob.php` | role-match |
| `app/Console/Commands/StencilsReapplyTemplatesCommand.php` (`stencils:reapply-templates`) | console command | batch | `app/Console/Commands/PackagesReclassifyEquipmentCommand.php` (primary) + `app/Console/Commands/BackfillCablePortFksCommand.php` (secondary — `--apply`/`--commit` naming + per-row-decision table) | exact |
| `app/Console/Commands/StencilsCoverageReportCommand.php` (`stencils:coverage-report`) | console command | batch/report | `app/Console/Commands/PackagesReclassifyEquipmentCommand.php` (`$this->table()` report pattern, read-only path — no `--commit` needed) | role-match |
| `app/Models/DeviceStencilAudit.php` | model | CRUD | `app/Models/DevicePort.php` (sibling `belongsTo` model in the same domain, `casts`, `fillable`) | role-match |
| `database/migrations/2026_08_XX_*_add_needs_review_and_logo_path_to_device_stencils_and_create_audits.php` | migration | batch (schema + PHP backfill) | `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` | exact |
| `resources/views/admin/device-stencils/index.blade.php` | component (Blade view) | request-response | `resources/views/admin/devices/index.blade.php` (filter row) + `resources/views/admin/device-cable-rules/index.blade.php` (table chrome, badges) | exact |
| `resources/views/admin/device-stencils/edit.blade.php` | component (Blade view) | request-response + streaming (debounced preview) | `resources/views/admin/devices/edit.blade.php` (`<x-edit-action-bar>`, card layout, pill/badge CSS) | role-match |
| `resources/views/admin/device-stencils/_port-table.blade.php` | component (Blade partial, Alpine reactive) | event-driven (client-side reactive) | `resources/views/components/survey/repeater-equipment.blade.php` | exact |
| `config/drawings.php` (`port_templates` key, MODIFIED) | config | — | existing `category_to_zone` / `signal_colours` keys in the same file | exact |
| `app/Services/Drawings/AutoGenericStencilGenerator.php` (MODIFIED — `emitShape()`) | service | transform | itself (extend in place) + `resources/data/device-stencils-seed/neat-bar-pro.json` (target XML shape) | exact |
| `app/Jobs/ExtractQuoteJob.php` (MODIFIED) | event-driven job | event-driven | itself (add stubber call after `DB::transaction` closes, line ~173) | exact |
| `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` (MODIFIED — `buildExtractedData`) | service | transform | itself (add stubber call after the `equipment` map, ~line 115-160) | exact |
| `app/Jobs/ReimportQuoteJob.php` (MODIFIED) | event-driven job | event-driven | `ExtractQuoteJob.php` (sibling job, same stubber-after-transaction placement rule) — **verify `completePendingReimport`'s own transaction boundary before wiring (Assumption A2)** | role-match |
| `routes/web.php` (MODIFIED, ~line 251-303) | route | request-response | itself — `admin/device-cable-rules` block (route-ordering pitfall + `Route::resource` naming) | exact |
| `resources/views/layouts/navigation.blade.php` (MODIFIED, ~line 391-409) | component (nav) | — | itself — `admin.devices.index` nav `<a>` entry | exact |
| `tests/Feature/Drawings/QuoteImportStencilStubberTest.php` | test | — | `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` | exact |
| `tests/Feature/Drawings/CategoryPortTemplateResolverTest.php` | test | — | `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` | role-match |
| `tests/Feature/Drawings/DeviceStencilPromotionTest.php` | test | — | `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` | role-match |
| `tests/Feature/Drawings/DeviceStencilCurationFlowTest.php` | test | — | `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php` | role-match |
| `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` | test | — | no existing `Tests\Feature\Console\*` sibling found for the two artisan analogs — pattern from PHPUnit conventions doc only | no analog |

---

## Pattern Assignments

### `app/Http/Controllers/Admin/DeviceStencilController.php` (controller, CRUD + request-response)

**Analog:** `app/Http/Controllers/Admin/DeviceCableRuleController.php` (full CRUD + non-resource `preview` action) with `app/Http/Controllers/Admin/DeviceController.php` (edit-only, no create/destroy — matches D-14's "no create screen" constraint) as secondary.

**Imports pattern** (`DeviceCableRuleController.php:1-13`):
```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeviceCableRuleRequest;
use App\Models\DeviceCableRule;
use App\Services\CableScheduleGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
```
For `DeviceStencilController`, swap in `DeviceStencil`, `UpdateDeviceStencilPortsRequest`, `UploadDeviceStencilLogoRequest`, `CategoryPortTemplateResolver`, `AutoGenericStencilGenerator`, `StencilPromotionValidator`.

**Index + filter query pattern** (`DeviceController.php:33-75` — filter-row backing logic, since DRAW-50 needs 4 filters: source / needs_review / manufacturer / part_number search, closer in shape to this than the cable-rule's plain `orderBy('priority')->paginate(15)`):
```php
public function index(Request $request): View
{
    $query = Device::query();

    $projectId = $request->integer('project_id');
    // ... conditional ->where() per filter, then:

    $term = trim((string) $request->input('q', ''));
    if ($term !== '') {
        $query->where(function ($sub) use ($term) {
            $sub->where('manufacturer', 'like', "%{$term}%")
                ->orWhere('model', 'like', "%{$term}%")
                ->orWhere('part_no', 'like', "%{$term}%");
        });
    }

    $devices = $query->orderBy(...)->paginate(15)->appends($request->query());

    return view('admin.devices.index', [...]);
}
```
Adapt: `?source=`, `?needs_review=1|0`, `?manufacturer=`, `?q=` (part_number search — mono `--font-mono` per UI-SPEC). `needs_review` filter is index-backed per D-10 — do not wrap it in a `LIKE`.

**Edit / update pattern** (`DeviceCableRuleController.php:62-84`):
```php
public function edit(DeviceCableRule $deviceCableRule): View
{
    $rule = $deviceCableRule;
    return view('admin.device-cable-rules.edit', compact('rule'));
}

public function update(DeviceCableRuleRequest $request, DeviceCableRule $deviceCableRule): RedirectResponse
{
    $data = $this->extractData($request);
    $deviceCableRule->update($data);

    Log::info('Admin: device cable rule updated', [
        'rule_id'  => $deviceCableRule->id,
        'admin_id' => auth()->id(),
    ]);

    return redirect()->route('admin.device-cable-rules.index')
        ->with('success', "Rule #{$deviceCableRule->id} updated.");
}
```
For `update` (D-01/D-02): this is the batched port-table save — accept the validated ports array, delete-and-reinsert `device_ports` rows for the stencil (or diff), then **regenerate `mxgraph_xml` via `AutoGenericStencilGenerator`/curated builder in the SAME request** (Pitfall 2 — never let `device_ports` and `mxgraph_xml` drift). Log via the same `Log::info('Admin: ...', ['admin_id' => auth()->id()])` convention.

**Preview action pattern** (JSON, non-persisting, mirrors `DeviceCableRuleController::preview` at `:101-127`):
```php
public function preview(Request $request, CableScheduleGeneratorService $generator): JsonResponse
{
    $data = $request->validate([
        'equipment' => ['required', 'string', 'min:1', 'max:255'],
        'length_m'  => ['nullable', 'numeric', 'gt:0', 'max:100000'],
    ]);

    Log::info('Admin: device cable rule preview requested', [
        'admin_id'  => auth()->id(),
        'equipment' => $data['equipment'],
    ]);

    return response()->json(
        $generator->previewInference($data['equipment'], ...)
    );
}
```
Adapt for `DeviceStencilController::preview`: validate the **unsaved** port array from the request body (not the DB), build a transient shape, run it through `AutoGenericStencilGenerator::build()` per Research Open Question 3's recommendation (single-shape scope only — do not synthesise a throwaway `Project` for `DrawIoBuilderService`), and return SVG (D-16) — not JSON. Per D-16's literal contract, this differs from the cable-rule preview's JSON-trace response; do not copy the response shape, only the "validate → call service → return, persist nothing" skeleton.

**Promote action** (new shape, no direct analog — nearest structural precedent is `destroy()`'s pattern of a state-changing POST with a `Log::info` + flash redirect):
```php
// DeviceCableRuleController::destroy, adapted shape
public function destroy(DeviceCableRule $deviceCableRule): RedirectResponse
{
    $id = $deviceCableRule->id;
    $deviceCableRule->delete();
    Log::info('Admin: device cable rule deleted', ['rule_id' => $id, 'admin_id' => auth()->id()]);
    return redirect()->route('admin.device-cable-rules.index')->with('success', "Rule #{$id} deleted.");
}
```
`promote()` must re-run `StencilPromotionValidator`'s hard-gate server-side regardless of client state (Security Domain: "Promote action bypassing server-side D-04 validation" threat), write a `device_stencil_audits` row (`action = 'promote'`, before/after port snapshot), then flip `source` + `needs_review = false`, and flash the criterion-4-visible success copy from UI-SPEC ("...promoted to Engineer-Curated. It now renders with full ports on every project using part number {part_number}.").

**Route-registration note** carried from `routes/web.php:295-299` (Pitfall 4): register `preview`/`promote`/`discard` literal-segment routes **before** any `Route::resource`-style binding, exactly as `device-cable-rules` does — or, since D-14 uses fully explicit named routes (no `Route::resource` call), just ensure `/admin/device-stencils/{deviceStencil}/preview` etc. don't collide with a wildcard route registered earlier in the group.

---

### `app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php` (validation, request-response)

**Analog:** `app/Http/Requests/Admin/DeviceCableRuleRequest.php`

**Full shape to mirror** (`DeviceCableRuleRequest.php:38-70`):
```php
class DeviceCableRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'priority'    => ['required', 'integer', 'min:0', 'max:9999'],
            // ...
            'length_tiers'              => ['nullable', 'array'],
            'length_tiers.*.max_m'      => ['required_with:length_tiers', 'numeric', 'gt:0'],
            'length_tiers.*.cable_type' => ['required_with:length_tiers', 'string', 'max:200'],
        ];
    }
}
```
This is the **exact shape** for a batched nested-array save: `ports` (array) with `ports.*.label`, `ports.*.side` (`in:left,right,top,bottom`), `ports.*.connector_type` (allowlist from `config('drawings.port_templates')`), `ports.*.signal_type` (`in:audio,video,control,network,usb,power,speaker,dante,unclassified`), `ports.*.direction` (`in:in,out,io`), `ports.*.sort_order` (integer), `ports.*.port_id` (string, and validate uniqueness **within the posted array** — Laravel's `distinct` rule on `ports.*.port_id` catches intra-array duplicates before they ever hit the DB's compound unique index, matching D-04's "catch it in validation, not as a 500"), `ports.*.x_pct` / `ports.*.y_pct` (`nullable`, `numeric`, `between:0,1`). `authorize()` copies verbatim (`auth()->user()?->isAdmin() ?? false`).

Note this FormRequest handles the **Save** action (structural persistence — always allowed per D-01, table is always the source of truth), which is distinct from `StencilPromotionValidator`'s **Promote** hard-gate (D-04) — do not conflate the two; a stencil can be saved with zero ports (auto-stubbed default state) but cannot be *promoted* with zero ports.

---

### `app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php` (validation, file-I/O)

**Analog:** `app/Http/Controllers/SiteSurveyController.php:455-458` (inline validation array — extract into a FormRequest class per Requests/Admin/ convention).

```php
// Source: app/Http/Controllers/SiteSurveyController.php:455-458
$request->validate([
    'photo'   => ['required', 'file', 'image', 'max:10240'],  // 10 MB
    'caption' => ['nullable', 'string', 'max:200'],
]);
```
Adapt to D-15's contract: `'logo' => ['required', 'file', 'mimes:svg,png,jpg,jpeg', 'max:2048']` (UI-SPEC's copy says "up to 2MB" — `max:2048` in KB) — note `image` alone won't accept `.svg` MIME types reliably across browsers/PHP fileinfo, so this needs `mimes:svg,png,jpg,jpeg` rather than the `'image'` rule the SiteSurvey photo upload uses. `authorize()` mirrors `DeviceCableRuleRequest`'s `auth()->user()?->isAdmin() ?? false`.

---

### `app/Services/Drawings/CategoryPortTemplateResolver.php` (service, transform)

**Analog:** `app/Services/Imports/EquipmentCategoryClassifier.php` (mechanism — priority-ordered decision tree) + `app/Services/Drawings/DrawingDataResolverService.php:437-469` (keyword→enum inference precedent).

**Mechanism to mirror** (`EquipmentCategoryClassifier.php:66-92`, abbreviated):
```php
public function classify(array $item): string
{
    // 1. Explicit-category short-circuit
    $rawCat = strtolower(trim((string) ($item['category'] ?? '')));
    if (in_array($rawCat, self::CATEGORIES, true)) {
        return $rawCat;
    }

    // 2. Build a lowercase haystack from every text field.
    $text = strtolower(trim(implode(' ', [
        (string) ($item['name']        ?? ''),
        (string) ($item['description'] ?? ''),
        (string) ($item['part_number'] ?? ''),
    ])));

    // 3. Priority-ordered decision tree — MOST SPECIFIC FIRST.
    foreach (['optional', 'option'] as $kw) {
        if (str_contains($text, $kw)) {
            return 'option';
        }
    }
    // ... more groups, each an early return ...
}
```

**Sibling keyword-inference precedent** (`DrawingDataResolverService.php:437-469`):
```php
private function inferRoleFromName(string $name): ?string
{
    $n = strtolower($name);

    foreach (['display', 'monitor', 'projector', 'screen', 'speaker', 'tv ', 'television'] as $kw) {
        if (str_contains($n, $kw)) {
            return \App\Models\Device::ROLE_DESTINATION;
        }
    }
    // ... more keyword groups ...

    return null;   // <-- KEY DIFFERENCE from EquipmentCategoryClassifier
}
```
**This `?string` return-null-on-no-match shape is the one to copy, not `EquipmentCategoryClassifier`'s unconditional `hardware` default.** D-07 requires: (a) single unambiguous keyword match → resolved device-type key → template lookup; (b) multi-keyword match covered by an explicit precedence rule in `config('drawings.port_templates')` (e.g. `bracket` beats `display`) → resolved via the rule; (c) multi-keyword match NOT covered, or zero matches → return `null` → caller emits a zero-port stub flagged `needs_review`. Do not add an unconditional catch-all default the way `EquipmentCategoryClassifier` does at its bottom — that is explicitly the wrong shape here (RESEARCH.md Code Examples section, verbatim).

**Config vocabulary** lives in `config/drawings.php` (see below) — the resolver reads `config('drawings.port_templates')`, never a DB table, per D-06.

---

### `app/Services/Drawings/StencilPromotionValidator.php` (service, transform/validation gate)

**No direct analog file** — closest structural precedent is `DeviceCableRuleRequest`'s per-element array validation (`length_tiers.*.max_m` etc.), but this must be a plain service class (not a FormRequest) because D-08's `stencils:reapply-templates` and D-04's promote-controller-action both need to call the same gate outside an HTTP request lifecycle.

**Contract to implement** (from CONTEXT.md D-04, restated as a testable method signature):
```php
class StencilPromotionValidator
{
    /**
     * @return array{blocking: string[], warnings: string[]}
     */
    public function evaluate(DeviceStencil $stencil): array
    {
        $blocking = [];
        $warnings = [];

        $ports = $stencil->ports; // hasMany, ordered — see DeviceStencil::ports()

        if ($ports->isEmpty()) {
            $blocking[] = 'This stencil has zero ports.';
        }

        foreach ($ports as $port) {
            foreach (['label', 'connector_type', 'signal_type', 'direction'] as $field) {
                if (blank($port->{$field})) {
                    $blocking[] = "Port \"{$port->label}\" is missing {$field}.";
                }
            }
        }

        $duplicateIds = $ports->pluck('port_id')->duplicates();
        foreach ($duplicateIds as $dup) {
            $blocking[] = "Duplicate port ID \"{$dup}\".";
        }

        if ($stencil->logo_svg === null && $stencil->logo_path === null) {
            $warnings[] = 'This stencil has no manufacturer logo — promotion will proceed without one.';
        }
        // ... unclassified signal_type / null x_pct+y_pct warnings ...

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
```
Enumerated-reason copy strings are dictated verbatim by UI-SPEC's Copywriting Contract table ("Blocked: this stencil has zero ports.", "Blocked: 2 ports are missing a signal type.", "Blocked: duplicate port ID \"hdmi-1\"." — note the UI groups per-field-type counts, not one line per port; the controller/view layer aggregates `evaluate()`'s raw per-port messages into that grouped phrasing).

---

### `app/Services/QuoteImport/QuoteImportStencilStubber.php` (service, event-driven)

**Analog:** wraps `app/Services/Drawings/DeviceStencilCacheService.php` (the service being called), with call-site placement dictated by Pitfall 5.

**Contract to wrap** (`DeviceStencilCacheService.php:62-96`, `resolveForPartNumber`):
```php
public function resolveForPartNumber(string $partNumber, array $hints = []): DeviceStencil
{
    $normalised = DeviceStencil::normalisePartNumber($partNumber);

    $existing = DeviceStencil::query()->where('part_number', $normalised)->first();
    if ($existing !== null) {
        return $existing;
    }

    $payload = $this->generator->build(array_merge($hints, ['part_number' => $partNumber]));

    return DeviceStencil::firstOrCreate(
        ['part_number' => $normalised],
        [
            'manufacturer'   => ...,
            'display_name'   => $payload['display_name'],
            'mxgraph_xml'    => $payload['mxgraph_xml'],
            'default_width'  => $payload['default_width'],
            'default_height' => $payload['default_height'],
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]
    );
}
```
`QuoteImportStencilStubber::stubFromEquipmentLines(array $lines): array` (or similar) should iterate the equipment array each of the 3 call sites already has in hand, call `CategoryPortTemplateResolver` first to get a template (or `null`), then call `DeviceStencilCacheService::resolveForPartNumber()` passing enough hints for `AutoGenericStencilGenerator::build()` — **but D-05 requires the generator to ALSO receive the resolved port template** so it can emit `<connections>` + provisional rails, which means `AutoGenericStencilGenerator::build()`'s hints array signature must grow a `ports` key (see modified-file entry below). After `resolveForPartNumber` returns, if the stencil was freshly created AND a template resolved, bulk-insert `device_ports` rows and set `needs_review = true` (D-10's real column) — this is new orchestration logic `DeviceStencilCacheService` itself does not do (it never inserts `device_ports` per its own docblock: "Auto-generated Tier 1 stencils carry NO ports — engineers add them via Phase 24's curation UI").

**Placement rule (Pitfall 5 — critical, not stylistic):**
```php
// Source: app/Jobs/ExtractQuoteJob.php:99-173 (DB::transaction boundary)
DB::transaction(function () use ($extracted, $projectService, $quoteVersioner) {
    // ... $this->package->update(...) and all persistence ...
});

$this->generateContentPack($extracted);   // <-- stubber call goes HERE, after the transaction closes, alongside/before this line
```
Never call `QuoteImportStencilStubber` from inside the `DB::transaction` closure — `DeviceStencilCacheService::resolveForPartNumber()`'s race-safety contract (21 D-03) depends on NOT being nested inside an ambient transaction (its own docblock: "NOT wrapped in DB::transaction... Wrapping this call in a transaction would block on the unique index without benefit and would actually HURT throughput").

**Three call sites, three placements:**
- `ExtractQuoteJob::handle()` — after `DB::transaction(...)` closes (line ~173), before/alongside `$this->generateContentPack($extracted)`.
- `QuoteWerksImportService::buildExtractedData()` — this method (line 115) never persists at all; call the stubber on the computed `$equipment` array right before `return`, or immediately after the caller (`QuoteImportService::importFromData`) persists — confirm placement doesn't duplicate work across the two.
- `ReimportQuoteJob` — **read `QuoteImportService::completePendingReimport`'s own transaction boundary before wiring (Assumption A2, unresolved by research)** — do not assume it mirrors `ExtractQuoteJob` without checking.

---

### `app/Console/Commands/StencilsReapplyTemplatesCommand.php` (`stencils:reapply-templates`, console, batch)

**Analog:** `app/Console/Commands/PackagesReclassifyEquipmentCommand.php` (primary — dry-run/`--commit`, per-row diff table, idempotency docblock) + `app/Console/Commands/BackfillCablePortFksCommand.php` (secondary — extensive docblock rationale style, `--apply`-vs-`--commit` naming choice already settled by D-08 as `--commit`).

**Signature + dry-run/commit skeleton** (`PackagesReclassifyEquipmentCommand.php:39-45, 66-89`):
```php
class PackagesReclassifyEquipmentCommand extends Command
{
    protected $signature = 'packages:reclassify-equipment
                            {package? : Package ID (optional; without = all packages)}
                            {--commit : Actually persist changes (default is dry-run)}';

    protected $description = '...';

    public function handle(EquipmentCategoryClassifier $classifier): int
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '── COMMIT MODE — changes will be persisted ──' : '── DRY-RUN MODE (default) — no writes ──');
        $this->newLine();

        $query = ProjectPackage::query()->with('project');
        if ($packageId) { $query->where('id', $packageId); }
        $packages = $query->get();
        // ...
    }
}
```
For `stencils:reapply-templates`: `{--commit}`, scope query to `DeviceStencil::where('source', DeviceStencil::SOURCE_AUTO_GENERATED)->whereDoesntHave('audits')` (D-08's exact re-apply eligibility rule — never touch a stencil with ANY `device_stencil_audits` row). Report per-stencil diff via `$this->table(...)` (see below), gate all writes behind `if ($commit)`.

**Report-table pattern** (`PackagesReclassifyEquipmentCommand.php:137-160`):
```php
$this->table(
    ['Pkg', 'Project', 'Scanned', 'Recategorised', 'Areas cleared', 'Category diff'],
    $reportRows,
);

$this->newLine();
$this->line(sprintf('── Totals: %d ... · %d ...', $packagesChanged, $totalRowsChanged));

if (! $commit) {
    $this->newLine();
    $this->warn('DRY-RUN — no packages were changed.');
    $this->line('Re-run with --commit to persist. Command is idempotent — running twice with --commit produces no additional diffs.');
} else {
    $this->info('Changes persisted. Re-run with --commit to verify idempotence (should show zero further diffs).');
}
```
Copy this exact idempotency-messaging pattern — Pitfall 3 requires `stencils:reapply-templates --commit` run twice in a row to diff zero, and the command's own stdout should say so, mirroring this convention.

**Determinism requirement feeding this command** (Pitfall 3, from `AutoGenericStencilGenerator.php:28-32` docblock, unmodified contract that D-05's extension must preserve):
```
Determinism: the same hints array produces byte-identical output across
calls — no random IDs, no timestamps, no environment-dependent values.
```
Derive template-generated `port_id` deterministically from `connector_type` + `sort_order` (e.g. `"hdmi-1"`, `"hdmi-2"`) — mirrors the hand-curated seed pack's own naming (`neat-bar-pro.json`'s `hdmi-in`/`hdmi-out`/`usb-c`/`power`/`lan`/`audio-out`). Never `Str::uuid()` or `time()`-derived values for template ports.

---

### `app/Console/Commands/StencilsCoverageReportCommand.php` (`stencils:coverage-report`, console, batch/report)

**Analog:** `PackagesReclassifyEquipmentCommand.php`'s `$this->table()` reporting half only (no `--commit` needed — read-only). Criterion 5's "top-10 by quote volume" input source is an **open item flagged to the planner** (CONTEXT.md deferred list + RESEARCH.md Wave 0 gap) — do not derive the reference list from the seed pack itself (21 D-15 independence rule, applied by analogy).

---

### `app/Models/DeviceStencilAudit.php` (model, CRUD)

**Analog:** `app/Models/DevicePort.php` (sibling model in the same migration/domain — `belongsTo`, `fillable`, `casts`, docblock `@property` convention).

```php
// Source: app/Models/DevicePort.php:43-88 (structure to mirror)
class DevicePort extends Model
{
    public const SIDE_LEFT = 'left';
    // ... enum consts ...

    protected $fillable = [
        'device_stencil_id',
        'label',
        // ...
    ];

    protected $casts = [
        'y_pct'      => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function stencil(): BelongsTo
    {
        return $this->belongsTo(DeviceStencil::class);
    }
}
```
For `DeviceStencilAudit`: `public const ACTION_PROMOTE = 'promote'; ACTION_EDIT = 'edit'; ACTION_DISCARD_REGENERATE = 'discard-regenerate';` (mirrors `DeviceStencil::SOURCE_*` const-per-enum-value style at `DeviceStencil.php:56-60`), `fillable = ['device_stencil_id', 'user_id', 'action', 'before_snapshot', 'after_snapshot']`, `casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array']`, `belongsTo(DeviceStencil::class)` + `belongsTo(User::class)`. `DeviceStencil::audits(): HasMany` is the reverse relation `StencilsReapplyTemplatesCommand` needs for `whereDoesntHave('audits')`.

---

### `database/migrations/2026_08_XX_*_add_needs_review_and_logo_path_to_device_stencils_and_create_audits.php` (migration)

**Analog:** `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php`

```php
// Structure to mirror — table-creation half:
Schema::create('device_stencils', function (Blueprint $table) {
    $table->id();
    $table->string('part_number', 100)->unique();
    // ...
    $table->string('source', 30)->default('auto-generated');
    $table->json('metadata')->nullable();
    $table->timestamps();
});
```
New migration adds, in the same `up()`:
```php
Schema::table('device_stencils', function (Blueprint $table) {
    $table->boolean('needs_review')->default(false)->index();  // D-10 — real indexed column
    $table->string('logo_path', 255)->nullable();               // D-15 — file-storage sibling to logo_svg
});

Schema::create('device_stencil_audits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('device_stencil_id')->constrained('device_stencils')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');
    $table->string('action', 30);           // promote / edit / discard-regenerate
    $table->json('before_snapshot')->nullable();
    $table->json('after_snapshot')->nullable();
    $table->timestamps();
});
```
**Backfill step (Pitfall 1 — must be PHP, not raw SQL):**
```php
// Do NOT: DB::statement("... WHERE JSON_EXTRACT(metadata, '$.needs_phase_24_curation') = true");
// This breaks portability across MySQL/MariaDB/SQLite (phpunit.xml uses sqlite :memory:).
DB::table('device_stencils')->get()->each(function ($row) {
    $metadata = json_decode((string) $row->metadata, true) ?: [];
    if (($metadata['needs_phase_24_curation'] ?? false) === true) {
        DB::table('device_stencils')->where('id', $row->id)->update(['needs_review' => true]);
    }
});
```
~96 rows total per the phase's own audit — no chunking needed. Run this inside the same migration's `up()`, after the `Schema::table` alter.

---

### `resources/views/admin/device-stencils/index.blade.php` (Blade, request-response)

**Analog:** `resources/views/admin/devices/index.blade.php` (filter row + `.dv-table` chrome) + `resources/views/admin/device-cable-rules/index.blade.php` (badge/status pill markup, empty-state copy pattern).

**Filter-row form** (`devices/index.blade.php:112-130`):
```blade
<form method="GET" action="{{ route('admin.devices.index') }}" class="dv-filter-row">
    <label for="dv-q">Search</label>
    <input type="text" id="dv-q" name="q" value="{{ $q }}" placeholder="manufacturer / model / part no…" autocomplete="off">

    <label for="dv-project">Project</label>
    <select id="dv-project" name="project_id">
        <option value="">All projects</option>
        @foreach ($projects as $p)
            <option value="{{ $p->id }}" {{ (int) $projectId === (int) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
        @endforeach
    </select>

    <button type="submit" class="btn btn-teal btn-sm">Apply</button>
    @if ($q !== '' || $projectId > 0)
        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline btn-sm">Clear</a>
    @endif
</form>
```
Rename the scoped class to `.stc-filter-row` per UI-SPEC point 1 (byte-identical `12px 14px` padding, `.dv-filter-row` CSS block at `devices/index.blade.php:62-91` copies over verbatim under the new class name). Add `Source` and `Needs review` `<select>`s alongside `Manufacturer` and the `q` search box — same 4-field GET-form-with-Apply/Clear shape, not `x-model.debounce`.

**Status badge pattern** (`device-cable-rules/index.blade.php:214-223`):
```blade
@if ($rule->is_active)
    <span class="badge badge-success" style="...">
        <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Active
    </span>
@else
    <span class="badge badge-muted" style="...">Inactive</span>
@endif
```
Adapt for the Source badge (`.badge-grey`/`.badge-green`/`.badge-blue` per UI-SPEC's mapping) and the separate `Needs review` `.badge-yellow` pill (absence = "no", per UI-SPEC — do not render a muted "No" pill, unlike this Active/Inactive pattern which always renders one state or the other).

**Empty-state pattern** (`device-cable-rules/index.blade.php:238-244`):
```blade
@empty
<tr>
    <td colspan="7" style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">
        No cable rules yet.
        <a href="{{ route('admin.device-cable-rules.create') }}" style="color:var(--teal-700);font-weight:600;">Create one →</a>
    </td>
</tr>
@endforelse
```
UI-SPEC dictates two distinct empty-state copy blocks (unfiltered vs filtered) — do not just copy "No stencils yet."; use the exact copy from UI-SPEC's Copywriting Contract table, and no "Create one" link (D-14: no create screen).

---

### `resources/views/admin/device-stencils/edit.blade.php` (Blade, request-response + streaming preview)

**Analog:** `resources/views/admin/devices/edit.blade.php`

**Action-bar + card structure** (`devices/edit.blade.php:132-141, 159-190`):
```blade
<x-edit-action-bar
    :form-id="'device-edit-form'"
    :cancel-url="route('admin.devices.index', ['project_id' => $device->project_id])"
    save-label="Save Device">
    <x-slot name="title">
        Edit: {{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: 'Device #'.$device->id }}
    </x-slot>
</x-edit-action-bar>

<form method="POST" action="{{ route('admin.devices.update', $device) }}" id="device-edit-form">
    @csrf @method('PUT')

    <div class="card dv-form-card">
        <h2>Identity</h2>
        <p class="dv-card-hint">...</p>
        <div class="dv-readonly-grid">...</div>
    </div>
</form>
```
Per UI-SPEC point 3: `<x-edit-action-bar>` slot carries Cancel + title only (no plain Save button in the bar — batched port-table submit happens via the port-table card's own action, Promote/Discard live in the footer). Two-column layout (port table ~60% left, preview pane ~40% right, stacking under 900px) is genuinely new markup — no existing screen has a two-pane layout; build fresh Tailwind/CSS-grid, not copied from either analog.

**Footer actions** (`devices/edit.blade.php:260-265`, `.dv-footer-actions`):
```blade
<div class="dv-footer-actions">
    <button type="submit" class="btn btn-teal">Save Device</button>
    <a href="{{ route('admin.devices.index', ['project_id' => $device->project_id]) }}" class="btn btn-outline btn-sm">Cancel</a>
</div>
```
Rename scoped class per-screen (`.stc-footer-actions` or reuse `.dv-footer-actions` verbatim — UI-SPEC doesn't mandate a rename here, unlike `.dv-filter-row`). Add `Promote to Engineer-Curated` (`.btn-primary`, disabled per D-04) and `Discard & Regenerate` (`.btn-danger-outline`, `data-confirm` attributes per the cable-rule delete-button pattern below) alongside Cancel.

**`data-confirm` destructive-action pattern** (`device-cable-rules/index.blade.php:228-234`):
```blade
<form method="POST" action="{{ route('admin.device-cable-rules.destroy', $rule) }}"
      data-confirm="Delete rule #{{ $rule->id }} (priority {{ $rule->priority }})?"
      data-confirm-label="Delete"
      data-confirm-danger="1" style="margin:0;">
    @csrf @method('DELETE')
    <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
</form>
```
Copy verbatim for `Discard & Regenerate`, with UI-SPEC's exact copy: `data-confirm="Discard all edits and regenerate this stencil from its category template?" data-confirm-label="Discard & Regenerate" data-confirm-danger="1"`.

---

### `resources/views/admin/device-stencils/_port-table.blade.php` (Blade partial, Alpine reactive)

**Analog:** `resources/views/components/survey/repeater-equipment.blade.php` — **NOT** `resources/views/project-packages/review.blade.php`'s `equipmentSection()` (confirmed poor fit by research: DOM-toggle-based, no reactive JS state to serialize into the debounced preview POST body).

**`x-for` reactive repeater shape** (`repeater-equipment.blade.php:16-27, 38-53`):
```blade
<div class="flex items-center justify-between mb-3">
    <h3>Equipment Items</h3>
    <button type="button" @click="addEquipment()" class="...">Add</button>
</div>

<template x-for="(item, idx) in (currentRoom?.equipment ?? [])" :key="idx">
    <div class="border border-gray-200 rounded-xl p-3 space-y-2.5">
        <div class="flex items-center justify-between">
            <span x-text="'Item ' + (idx + 1)"></span>
            <button type="button" @click="removeEquipment(idx)" class="...">
                <svg>...</svg>
            </button>
        </div>
        <select x-model="item.type">
            <option value="">Select type…</option>
            <option value="display">Display / Screen</option>
            <!-- ... -->
        </select>
    </div>
</template>
```
Build the port table on this exact shape: `x-data` parent holds `ports: []`; `x-for="(port, idx) in ports" :key="idx"`; `x-model="port.label"`, `x-model="port.side"`, etc. per column; `addPort()`/`removePort(idx)` push/splice the array; `$watch('ports', () => this.debouncedPreview(), { deep: true })` drives D-02. Row-delete button gets `aria-label="Remove port {label}"` per UI-SPEC point 4 (a11y batch-6 convention) — `repeater-equipment.blade.php`'s delete button has no `aria-label` (predates that convention), so do NOT copy its accessibility gap, only its reactive shape.

---

### Debounced preview POST (D-02, D-16) — used inside `_port-table.blade.php` or `edit.blade.php`'s `x-data`

**Analog:** `resources/views/surveys/show.blade.php:1761-1799`

```js
// Source: resources/views/surveys/show.blade.php:1761-1769
debouncedAutosave() {
    if (this._autosaveTimer) clearTimeout(this._autosaveTimer);
    this._autosaveTimer = setTimeout(() => {
        if (this.screen === 'step' && this.currentRoom && !this.readonly) {
            this.autosave();
        }
    }, 600);
},
```
```js
// Source: resources/views/surveys/show.blade.php:1786-1799 (fetch + CSRF header)
const resp = await fetch('/survey/' + this.token + '/step-save', {
    method:  'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        'Accept':       'application/json',
    },
    body: JSON.stringify({ /* ... */ }),
});
if (!resp.ok) throw new Error('Save failed');
```
Copy the 600ms debounce constant verbatim (UI-SPEC explicitly locks this against the 200ms ⌘K-search convention — do not reuse that faster value here). The preview fetch differs from this analog in one respect: it must swap the response into an `<img>`/inline-SVG target rather than setting a "last saved" timestamp, and per D-16 the endpoint returns raw SVG (`Accept: image/svg+xml` or just read `.text()`), not JSON. No local `AbortController` precedent exists in this codebase (Assumption A1, LOW confidence) — write it net-new: store the previous request's controller, `.abort()` before issuing a new preview fetch, silently `catch` `AbortError`.

---

### `config/drawings.php` (MODIFIED — new `port_templates` key)

**Analog:** existing `category_to_zone` / `signal_colours` keys in the same file.

```php
// Source: config/drawings.php:70-86 (category_to_zone) — shape to mirror
'category_to_zone' => [
    'hardware'          => null,          // fall through to name-keyword
    'cables'            => 'OTHER',
    // ...
],
```
New key, sibling to this:
```php
'port_templates' => [
    // device-type key => ordered list of port definitions (connector_type, signal_type, direction, side)
    'display' => [
        ['connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'side' => 'left'],
        // ...
    ],
    'bracket' => [],   // zero-port — mounting hardware has no signal ports
    // ...
],
'port_template_precedence' => [
    // D-07 explicit multi-match conflict resolution — declared winner per pair
    ['bracket', 'display'] => 'bracket',
    ['mount', 'screen']    => 'mount',
    // 'cable' beats everything — handle as a first-checked short-circuit, not a pair rule
],
```
Exact key names/shape are the planner's call — the pattern constraint from D-06 is: **top-level key in this file, version-controlled, sibling to the three existing vocab maps, never a DB table.**

---

### `app/Services/Drawings/AutoGenericStencilGenerator.php` (MODIFIED — `emitShape()`, D-05)

**Analog:** itself (extend in place) + `resources/data/device-stencils-seed/neat-bar-pro.json` (target `<connections>` XML shape, verified production data) + `app/Services/Drawings/CableRouter.php:271-279` (consumer contract, unmodified).

**Current no-ports emission** (`AutoGenericStencilGenerator.php:141-185`, what's being extended):
```php
// No <connections> element by design (D-04: Tier 1 has no port rails).
return sprintf(
    '<shape name="%s" h="140" w="220" aspect="variable" strokewidth="inherit">'
        .'<background>...</background>'
        .'<foreground>...</foreground>'
    .'</shape>',
    $stencilName, $bodyFill, $headerFill, ...
);
```

**Target shape to emit when hints carry a resolved port template** (`neat-bar-pro.json:17`, verified live seed data):
```
<shape name="21cav.neat-bar-pro" h="160" w="240" aspect="variable" strokewidth="inherit">
  <connections>
    <constraint x="0" y="0.2"  perimeter="0" name="hdmi-in"/>
    <constraint x="0" y="0.45" perimeter="0" name="usb-c"/>
    <constraint x="0" y="0.85" perimeter="0" name="power"/>
    <constraint x="1" y="0.2"  perimeter="0" name="hdmi-out"/>
    <constraint x="1" y="0.45" perimeter="0" name="lan"/>
    <constraint x="1" y="0.7"  perimeter="0" name="audio-out"/>
  </connections>
  <background>...</background>
  <foreground>...</foreground>
</shape>
```
Coordinate mapping from `device_ports` columns (per D-05/Pattern 1): `side=left` → `x="0" y="{y_pct}"`; `side=right` → `x="1" y="{y_pct}"`; `side=top` → `x="{x_pct}" y="0"`; `side=bottom` → `x="{x_pct}" y="1"`. Insert the `<connections>` block immediately after the opening `<shape ...>` tag, before `<background>`.

**Escaping — reuse the existing helper, do not add a second one** (`AutoGenericStencilGenerator.php:204-207`):
```php
private function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
```
Route every new interpolated value (port label, connector-type glyph text) through this — Security Domain's "Untrusted part_number/manufacturer/... reaching mxGraph XML text nodes" threat applies equally to port labels.

**Consumer contract — read-only, do not modify** (`CableRouter.php:271-279`):
```php
private function stencilHasConstraints(?object $stencil): bool
{
    if ($stencil === null) {
        return false;
    }
    $xml = (string) ($stencil->mxgraph_xml ?? '');
    return $xml !== '' && str_contains($xml, '<constraint');
}
```
This is a **substring check** — Pitfall 2 requires every emitted `<constraint name="{port_id}">` to exactly match a `device_ports.port_id` value, or `CableRouter` silently fails to terminate the cable at render time (no PHP exception). Add a feature test asserting parity between saved `device_ports.port_id` values and `<constraint name="...">` substrings in the saved `mxgraph_xml`.

**Provisional visible styling** (separate from the invisible `<connections>` constraint markers — UI-SPEC point 5 values): dashed rail `stroke-dasharray="4 3"`, colour `#94A3B8` (`var(--ink-400)`), `opacity: 0.6` for template-derived/unverified ports; solid full-opacity signal-type colour (from `config('drawings.signal_colours')`) once "verified" (session-edited or already `engineer-curated`). This is a second, additive emission in `<foreground>` — do not conflate it with the `<connections>` block, which carries no visual styling of its own.

---

### `app/Jobs/ExtractQuoteJob.php` (MODIFIED)

**Analog:** itself.

```php
// Source: app/Jobs/ExtractQuoteJob.php:99-173 (transaction, then line 175)
DB::transaction(function () use ($extracted, $projectService, $quoteVersioner) {
    // ... $this->package->update([...]), $quoteVersioner->create(...), $projectService->log(...)
});

$this->generateContentPack($extracted);
```
Insert the `QuoteImportStencilStubber` call after the `DB::transaction(...)` block closes (Pitfall 5), reading from `$extracted['equipment_list']` (the same array the transaction just persisted onto `$this->package`). Surface the "stubs created" toast per D-09/UI-SPEC by attaching a count to whatever return/flash mechanism the job's caller (`QuoteImportController.php:57`) already reads.

---

### `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` (MODIFIED — `buildExtractedData`)

**Analog:** itself.

```php
// Source: app/Core/Modules/QuoteImport/QuoteWerksImportService.php:115-160 (method body, abbreviated)
public function buildExtractedData(array $parsedShape): array
{
    $equipment = array_map(function (array $item) {
        // ... category classify, section-header reroute ...
        return [
            'quantity'    => $qty,
            'part_number' => $partNumber,
            'name'        => $description,
            'category'    => $category,
            // ...
        ];
    }, $parsedShape['equipment'] ?? []);

    // ...
}
```
This method never persists (persistence happens later in `QuoteImportService::importFromData`'s own `DB::transaction`, `app/Core/Modules/QuoteImport/QuoteImportService.php:347-390`) — call `QuoteImportStencilStubber` on the computed `$equipment` array either right before this method's `return`, or after `QuoteImportService::importFromData` persists the `ProjectPackage` (planner's choice, per Pitfall 5's guidance for this specific call site — either way, outside any transaction).

---

### `app/Jobs/ReimportQuoteJob.php` (MODIFIED)

**Analog:** `ExtractQuoteJob.php` (sibling job, same after-transaction placement rule) — **do not treat this as a safe copy-paste without first reading `QuoteImportService::completePendingReimport`'s transaction boundaries** (Assumption A2 in RESEARCH.md — unverified this session).

---

### `routes/web.php` (MODIFIED, ~line 251-303)

**Analog:** itself — the `device-cable-rules` block, including its documented route-ordering pitfall.

```php
// Source: routes/web.php:283-303
Route::get('/admin/devices', [DeviceController::class, 'index'])
    ->name('admin.devices.index');
Route::get('/admin/devices/{device}/edit', [DeviceController::class, 'edit'])
    ->name('admin.devices.edit');
Route::put('/admin/devices/{device}', [DeviceController::class, 'update'])
    ->name('admin.devices.update');

// 260712-ip3: the preview endpoint MUST be registered BEFORE the
// resource route so Laravel doesn't try to bind `preview` as a
// `{deviceCableRule}` model-bound parameter and 404 on the string.
Route::get('admin/device-cable-rules/preview', [DeviceCableRuleController::class, 'preview'])
    ->name('admin.device-cable-rules.preview');
Route::resource('admin/device-cable-rules', DeviceCableRuleController::class)
    ->except(['show'])
    ->names('admin.device-cable-rules')
    ->parameters(['device-cable-rules' => 'deviceCableRule']);
```
Add inside the same `Route::middleware('admin')->group()` block (opens at line 251): `admin.device-stencils.index` / `.edit` / `.update` / `.promote` / `.preview` per D-14's explicit naming (not a bare `Route::resource` — D-14 lists 5 named actions, no `create`/`store`/`destroy`). Register `preview` (and any other literal-segment action sharing the `{deviceStencil}` wildcard's URI prefix) before the wildcard-bearing routes, mirroring the comment above verbatim as the rationale.

---

### `resources/views/layouts/navigation.blade.php` (MODIFIED, ~line 391-409)

**Analog:** itself — the `admin.devices.index` nav entry.

```blade
<a href="{{ route('admin.devices.index') }}"
   class="tnav-admin-item {{ request()->routeIs('admin.devices.*') ? 'active' : '' }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="4" y="4" width="16" height="16" rx="2"/>
        <line x1="2" y1="9"  x2="4" y2="9"/><line x1="2"  y1="15" x2="4"  y2="15"/>
        <line x1="20" y1="9" x2="22" y2="9"/><line x1="20" y1="15" x2="22" y2="15"/>
        <line x1="9" y1="2"  x2="9" y2="4"/><line x1="15" y1="2"  x2="15" y2="4"/>
        <line x1="9" y1="20" x2="9" y2="22"/><line x1="15" y1="20" x2="15" y2="22"/>
    </svg>
    Devices
</a>
```
Add a new `<a>` with `route('admin.device-stencils.index')`, `request()->routeIs('admin.device-stencils.*')`, and a fresh hand-rolled inline `<svg>` (`viewBox="0 0 24 24"`, `stroke-width="1.75"`, `stroke-linecap="round"` `stroke-linejoin="round"`, per UI-SPEC's Design System icon-library row) — do not import an icon font/library. Place beside `admin.devices.index` / `admin.device-cable-rules.index` per CONTEXT.md's Integration Points note.

---

### `tests/*` (Wave 0 feature tests)

**Analog:** `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php`

**Class + fixture-builder shape** (`ProjectDevicesWithStencilsTest.php:1-59`):
```php
namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDevicesWithStencilsTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithEquipment(array $equipment): Project
    {
        $user = User::factory()->create();
        $project = Project::create([...]);
        ProjectPackage::create([
            'project_id'     => $project->id,
            'extracted_data' => ['equipment' => $equipment],
            'equipment_list' => $equipment,
            // ...
        ]);
        return $project->fresh();
    }

    public function test_returns_enriched_lines_for_hardware_equipment(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'manufacturer' => 'NEAT', 'model' => 'Bar Pro', 'area' => 'Boardroom', 'category' => 'hardware'],
        ]);
        // ... assertions ...
    }
}
```
PHPUnit 11 (**not Pest** — no test in this codebase uses Pest's `it()`/`test()` functional style), `extends Tests\TestCase`, `use RefreshDatabase;`, `public function test_*(): void` method names. Every new test class in this phase (`QuoteImportStencilStubberTest`, `CategoryPortTemplateResolverTest`, `DeviceStencilPromotionTest`, `DeviceStencilCurationFlowTest`) copies this skeleton — programmatic `Project`/`ProjectPackage`/`DeviceStencil` fixtures via `::create()`, never a real uploaded PDF (none exists on disk — confirmed by research; the "Light Forms 21CQ30451-01-OPS" fixture from `tests/Feature/Rams/DocxBuilderPdfParityTest.php:65-106`'s `makeRams()` helper is the project's established synthetic-fixture convention for this exact "no real PDF" situation).

**Named test case UI-SPEC requires:** `CategoryPortTemplateResolverTest` MUST include a `test_display_bracket_resolves_to_bracket_not_display()` (or similarly named) case using "Samsung 65\" Display Bracket" as input — this is UI-SPEC line 241's explicitly named ambiguity fixture (D-07's canonical multi-keyword precedence test).

---

## Shared Patterns

### Admin-only auth gate
**Source:** `routes/web.php:251` (`Route::middleware('admin')->group()`) + `DeviceCableRuleRequest.php:40-43` (`authorize()`)
**Apply to:** `DeviceStencilController` (all actions, via route group), `UpdateDeviceStencilPortsRequest`, `UploadDeviceStencilLogoRequest` (both via `authorize()`).
```php
public function authorize(): bool
{
    return auth()->user()?->isAdmin() ?? false;
}
```

### Structured admin-action logging
**Source:** `DeviceCableRuleController.php:51-55, 75-79, 91-94`
**Apply to:** every state-changing `DeviceStencilController` action (update/promote/discard).
```php
Log::info('Admin: device cable rule updated', [
    'rule_id'  => $deviceCableRule->id,
    'admin_id' => auth()->id(),
]);
```

### Dry-run-by-default artisan commands with `--commit`
**Source:** `PackagesReclassifyEquipmentCommand.php:41-45, 151-158`
**Apply to:** `stencils:reapply-templates` (per D-08, exact flag name `--commit`, not `--apply`).

### XML escaping for untrusted interpolated text
**Source:** `AutoGenericStencilGenerator.php:204-207`
**Apply to:** every new value the D-05 extension interpolates into `mxgraph_xml` (port labels, connector-type text).
```php
private function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
```

### 600ms debounce + CSRF-header fetch
**Source:** `resources/views/surveys/show.blade.php:1761-1799`
**Apply to:** the port-table's live-preview trigger (`_port-table.blade.php` or `edit.blade.php`'s Alpine root).

### `firstOrCreate` cross-project cache — never wrap in a transaction
**Source:** `DeviceStencilCacheService.php:47-58` (docblock) + Pitfall 5
**Apply to:** `QuoteImportStencilStubber`'s three call sites — always call after the ambient import transaction closes.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `app/Services/Drawings/StencilPromotionValidator.php` | service | validation gate | No existing service class in this codebase performs a pure structural hard/soft two-tier validation gate reusable outside an HTTP FormRequest — closest shape (`DeviceCableRuleRequest`'s array-element rules) is HTTP-bound, but D-08's `stencils:reapply-templates` and D-04's promote action both need the same gate callable from a console command context too. Build fresh per the contract sketched above; RESEARCH.md's Architectural Responsibility Map already assigns this to "API/Backend (Laravel FormRequest/service)" without naming a copy source. |
| `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` | test | — | No existing `Tests\Feature\Console\*` test class was found for either `PackagesReclassifyEquipmentCommand` or `BackfillCablePortFksCommand` in this session's search — the artisan commands themselves are strong analogs for the *command*, but no test-file sibling was located to copy test structure from. Fall back to the general PHPUnit 11 + `RefreshDatabase` + `$this->artisan(...)->assertExitCode(0)` convention (Laravel's own testing helpers), no project-specific console-test precedent to cite. |

---

## Metadata

**Analog search scope:** `app/Http/Controllers/Admin/`, `app/Http/Requests/Admin/`, `app/Services/Drawings/`, `app/Services/Imports/`, `app/Console/Commands/`, `app/Models/`, `database/migrations/`, `resources/views/admin/`, `resources/views/components/survey/`, `resources/views/surveys/`, `config/`, `routes/`, `tests/Feature/Drawings/`
**Files scanned:** 24 (all read directly this session, in addition to the analogs already cited with file:line precision in 24-RESEARCH.md)
**Pattern extraction date:** 2026-08-13
