---
phase: 23
plan: 06
type: execute
wave: 3
depends_on: [23-01]
files_modified:
  - resources/views/project-packages/review.blade.php
  - app/Http/Controllers/ProjectPackageReviewController.php
  - tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php
autonomous: false
requirements:
  - DRAW-46
tags: [ui, review-form, zone, blade, alpine, validation, v2.0]
must_haves:
  truths:
    - "Engineer can pick a zone per equipment row in the quote-review table via a dropdown column"
    - "Zone vocab from config('drawings.zone_vocab') populates the dropdown; an 'Other...' option opens a free-text input below (D-04 escape hatch)"
    - "Form submit persists equipment[N][zone] into the equipment line's JSON in latestPackage->extracted_data['equipment']"
    - "Server-side validation enforces equipment.*.zone is nullable string, max 50 chars, regex /^[A-Za-z0-9 _\\-]+$/u (Pitfall 8 XSS mitigation)"
    - "Free-text override that violates the regex returns a 422 with the existing form-error rendering pattern (not silent)"
    - "Dropdown shows the existing zone value as 'selected' when re-rendering after save (round-trip preserved)"
    - "Help text below the dropdown documents the D-04 tradeoff: 'Free text creates a separate group on the diagram — use the dropdown for consistency'"
    - "Existing review form fields (part_number, name, category, area) are UNCHANGED — strictly additive column"
  artifacts:
    - path: "resources/views/project-packages/review.blade.php"
      provides: "New 'Zone' dropdown column in the equipment table — both static + JS row-template variants"
      contains: "name=\"equipment[{{ $i }}][zone]\""
    - path: "app/Http/Controllers/ProjectPackageReviewController.php"
      provides: "parseReviewPayload() persists 'zone' key on each equipment entry + validation rule"
      contains: "'zone'"
    - path: "tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php"
      provides: "End-to-end review-form persistence test (DRAW-46 D-03 write side)"
      contains: "test_review_form_persists_zone"
  key_links:
    - from: "resources/views/project-packages/review.blade.php"
      to: "config('drawings.zone_vocab')"
      via: "@foreach blade loop in the dropdown"
      pattern: "config\\('drawings\\.zone_vocab'\\)"
    - from: "app/Http/Controllers/ProjectPackageReviewController.php"
      to: "Project::devicesWithStencils() consumer (Plan 02 ZoneGrouper)"
      via: "writes equipment[N][zone] into extracted_data; the accessor reads it back per D-02"
      pattern: "equipment.*zone"
---

<objective>
Ship the D-03 zone-dropdown column on the quote-review equipment table. Engineers gain a per-row zone picker that writes `equipment[N][zone]` into `latestPackage->extracted_data` via the existing review form POST. Plan 02's ZoneGrouper reads this back as the D-02 per-device override.

The work is two surfaces:
- **Blade view** — add one new column header + per-row dropdown to the existing equipment table. Both the server-rendered `@foreach` loop AND the JavaScript row-template (used by Add-Row) must include the dropdown.
- **Controller** — `parseReviewPayload()` must capture the `zone` key on each equipment entry; the request must validate `equipment.*.zone` per the regex allowlist (Pitfall 8 — defence-in-depth against XSS).

This plan ships AUTONOMOUS=FALSE because it includes a final visual-verify checkpoint where the user opens the review page in a browser to confirm the dropdown renders correctly + the round-trip works.

Output:
- Modified `resources/views/project-packages/review.blade.php` (additive dropdown column)
- Modified `app/Http/Controllers/ProjectPackageReviewController.php` (validation rule + parser key)
- 1 new feature test
- 1 manual visual-verify checkpoint
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@app/Http/Controllers/ProjectPackageReviewController.php
@resources/views/project-packages/review.blade.php
@config/drawings.php

<interfaces>
<!-- Contracts to extend (NOT replace) -->

ProjectPackageReviewController::parseReviewPayload (lines 867-940) — existing equipment loop:
```php
// Lines 873-886 — current shape:
$equipment = [];
foreach (array_values($raw['equipment'] ?? []) as $item) {
    if (empty($item['name']) && empty($item['quantity'])) {
        continue;
    }
    $category = $this->normaliseEquipmentCategory($item);
    $equipment[] = [
        'quantity'    => max(1, (int) ($item['quantity']    ?? 1)),
        'part_number' => trim((string) ($item['part_number'] ?? '')),
        'name'        => trim((string) ($item['name']        ?? '')),
        'area'        => trim((string) ($item['area']        ?? '')),
        'category'    => $category,
    ];
}
```

Phase 23 extends with `'zone' => $this->normaliseEquipmentZone($item)` — adds one key. Existing keys untouched.

Static Blade row (lines 700-760) — existing column shape:
```blade
<td style="width:150px;">
<select name="equipment[{{ $i }}][category]" data-equip-category>
    @foreach ($categoryOptions as $value => $label)
        <option value="{{ $value }}" {{ $selectedCategory === $value ? 'selected' : '' }}>
            {{ $label }}
        </option>
    @endforeach
</select>
</td>
<td class="col-area">
    <input type="text" name="equipment[{{ $i }}][area]" value="{{ old(...) }}" ... />
</td>
```

Phase 23 adds a new `<td>` AFTER the category dropdown:
```blade
<td class="col-zone" style="width:140px;">
    <div x-data="zonePicker({{ json_encode($item['zone'] ?? '') }}, @js(config('drawings.zone_vocab')))">
        <select x-model="selectedZone" @change="onZoneChange" name="equipment[{{ $i }}][zone]" x-show="!isFreeText">
            <option value="">— default by category —</option>
            <template x-for="z in vocab"><option :value="z" x-text="z" :selected="z === selectedZone"></option></template>
            <option value="__other__">Other (free text)…</option>
        </select>
        <input type="text"
               x-show="isFreeText"
               x-model="freeText"
               @input="freeText = $event.target.value"
               :name="'equipment[{{ $i }}][zone]'"
               maxlength="50"
               pattern="^[A-Za-z0-9 _\-]+$"
               placeholder="e.g. Server Cabinet" />
        <small class="form-hint">Free text creates a separate group on the diagram — use the dropdown for consistency.</small>
    </div>
</td>
```

JS row-template (lines 1373+) — equivalent block in the row template for Add-Row.

Existing `@js(config(...))` precedent: review.blade.php already uses `@js(config('cables.compatibility_aliases'))` per Phase 22 P02 — same pattern Plan 06 follows.
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Free-text zone input | Engineer-typed — interpolated into mxGraph XML by Plan 02 XtenAvLayoutEngine (already escaped there); also rendered in `<option>` text on subsequent edits |
| Form POST `equipment.*.zone` | Untrusted at the network boundary |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-06-A1 | Tampering (XSS) | `equipment[N][zone]` raw POST → persisted to extracted_data JSON → rendered into mxGraph value | mitigate | Defence-in-depth — TWO mitigations: (1) server-side regex validation `nullable\|string\|max:50\|regex:/^[A-Za-z0-9 _\\-]+$/u` rejects payloads with HTML/script chars; (2) Plan 02 XtenAvLayoutEngine xml() escape on emit. Pitfall 8 verified. |
| T-23-06-A2 | Tampering | Form bypasses select dropdown and posts arbitrary `zone` value | mitigate | Same regex validation. The select dropdown is UX-only; server enforces the contract. |
| T-23-06-A3 | DoS | Engineer types 200-char free-text zone | mitigate | `max:50` validation rule + HTML `maxlength="50"` attribute. |
| T-23-06-A4 | Cross-project Tampering | Form POST persists zone for project A's package while engineer is logged in to project B | accept | Existing `authorizePackage()` middleware on `ProjectPackageReviewController::update` already protects this — Plan 06 inherits. |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Controller — validate + parse equipment[N][zone] in parseReviewPayload</name>
  <files>
    app/Http/Controllers/ProjectPackageReviewController.php,
    tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php
  </files>
  <read_first>
    - app/Http/Controllers/ProjectPackageReviewController.php lines 303-360 (update method) + lines 867-940 (parseReviewPayload)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-02 (line 69) + D-03 (line 71) + D-04 (lines 73-77) — review-form contract
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pitfall 8" (lines 400-404) — XSS validation regex pattern
    - app/Models/ProjectPackage.php (extracted_data shape — equipment[N] is a fillable JSON array)
    - tests/Feature/ProjectPackages/ (existing review-controller test file naming patterns)
  </read_first>
  <behavior>
    - `parseReviewPayload` captures `equipment[N][zone]` into the equipment array entry (alongside existing quantity/part_number/name/area/category)
    - Validation added BEFORE `parseReviewPayload` call (or inside `update()` via $request->validate, matching the existing controller's validation pattern):
      - rule: `'equipment.*.zone' => 'nullable|string|max:50|regex:/^[A-Za-z0-9 _\\-]+$/u'`
    - When request fails validation, redirect back with errors (Laravel default redirect-back-with-errors flow)
    - When `zone` is empty string OR null OR missing, the entry's `zone` key is omitted (null/empty = "fall through to category default" per D-01)
    - Idempotent: re-saving the same form preserves the zone value
  </behavior>
  <action>
**Step 1 — Write `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` (RED):**

```php
<?php

namespace Tests\Feature\ProjectPackages;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 23 Plan 06 — DRAW-46 D-03 zone-dropdown write-side test.
 */
class ReviewZoneDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewableProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id' => $project->id,
            'extracted_data' => [
                'equipment' => [
                    ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => 'Server Room', 'quantity' => 1],
                ],
            ],
        ]);
        return ['user' => $user, 'package' => $package];
    }

    public function test_review_form_persists_zone_on_known_vocab_value(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $response = $this->put(route('project-packages.review.update', $f['package']), [
            '_token' => csrf_token(),
            'equipment' => [
                ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => 'Server Room', 'quantity' => 1, 'zone' => 'RACK'],
            ],
            'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
        ]);

        $response->assertRedirect();
        $f['package']->refresh();
        $this->assertSame('RACK', $f['package']->extracted_data['equipment'][0]['zone'] ?? null);
    }

    public function test_review_form_persists_free_text_zone_within_regex(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->put(route('project-packages.review.update', $f['package']), [
            '_token' => csrf_token(),
            'equipment' => [
                ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => 'Server Room', 'quantity' => 1, 'zone' => 'Server Cabinet'],
            ],
            'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
        ]);

        $f['package']->refresh();
        $this->assertSame('Server Cabinet', $f['package']->extracted_data['equipment'][0]['zone'] ?? null);
    }

    public function test_review_form_rejects_xss_payload_in_zone(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $response = $this->from(route('project-packages.review.show', $f['package']))->put(
            route('project-packages.review.update', $f['package']),
            [
                '_token' => csrf_token(),
                'equipment' => [
                    ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => '', 'quantity' => 1, 'zone' => '<script>alert(1)</script>'],
                ],
                'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
            ]
        );

        $response->assertSessionHasErrors(['equipment.0.zone']);
        $f['package']->refresh();
        // Zone NOT persisted on validation failure
        $this->assertArrayNotHasKey('zone', $f['package']->extracted_data['equipment'][0] ?? []);
    }

    public function test_review_form_rejects_zone_over_50_chars(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $longZone = str_repeat('A', 51);
        $response = $this->from(route('project-packages.review.show', $f['package']))->put(
            route('project-packages.review.update', $f['package']),
            [
                '_token' => csrf_token(),
                'equipment' => [
                    ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => '', 'quantity' => 1, 'zone' => $longZone],
                ],
                'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
            ]
        );

        $response->assertSessionHasErrors(['equipment.0.zone']);
    }

    public function test_empty_zone_is_omitted_not_persisted_as_empty_string(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->put(route('project-packages.review.update', $f['package']), [
            '_token' => csrf_token(),
            'equipment' => [
                ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => '', 'quantity' => 1, 'zone' => ''],
            ],
            'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
        ]);

        $f['package']->refresh();
        // Empty zone falls through to category default per D-01 — DO NOT persist as ''
        $this->assertArrayNotHasKey('zone', $f['package']->extracted_data['equipment'][0] ?? []);
    }

    public function test_existing_equipment_fields_remain_unchanged_after_zone_addition(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->put(route('project-packages.review.update', $f['package']), [
            '_token' => csrf_token(),
            'equipment' => [
                ['part_number' => 'A', 'name' => 'Switch', 'category' => 'hardware', 'area' => 'Server Room', 'quantity' => 3, 'zone' => 'RACK'],
            ],
            'project' => ['project_name' => 'P', 'quote_ref' => '', 'client_name' => '', 'site_name' => '', 'site_address' => '', 'prepared_by' => '', 'overview' => ''],
        ]);

        $f['package']->refresh();
        $item = $f['package']->extracted_data['equipment'][0];
        $this->assertSame('A', $item['part_number']);
        $this->assertSame('Switch', $item['name']);
        $this->assertSame('hardware', $item['category']);
        $this->assertSame('Server Room', $item['area']);
        $this->assertSame(3, $item['quantity']);
        $this->assertSame('RACK', $item['zone']);
    }
}
```

Commit RED: `git commit -am "test(23-06): RED — review form zone dropdown D-03 write side"`

**Step 2 — Modify `app/Http/Controllers/ProjectPackageReviewController.php`:**

(a) **Add validation rule to the `update()` method at line ~305 BEFORE calling `parseReviewPayload`:**

Find the existing `update()` method (line 303). Add `$request->validate(...)` near the top:

```php
public function update(Request $request, ProjectPackage $package): RedirectResponse
{
    $this->authorizePackage($package);

    // Phase 23 Plan 06 — zone validation (DRAW-46 D-03 + Pitfall 8 XSS mitigation)
    $request->validate([
        'equipment.*.zone' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 _\-]+$/u'],
    ]);

    $payload = $this->parseReviewPayload($request);
    // ... rest unchanged
```

If the `approve()` method (line 367 calls `parseReviewPayload` too) also accepts the same POST, ADD the same validation there. Search for `$this->parseReviewPayload($request)` calls and ensure each is preceded by the validation block (use one private helper `validateEquipmentZones(Request $request): void` if both methods need it to avoid duplication).

(b) **Modify `parseReviewPayload()` at line ~867 — equipment loop:**

Find the equipment loop (lines 873-886). Insert the zone parse after `'category' => $category,`:

```php
$equipment = [];
foreach (array_values($raw['equipment'] ?? []) as $item) {
    if (empty($item['name']) && empty($item['quantity'])) {
        continue;
    }
    $category = $this->normaliseEquipmentCategory($item);
    $entry = [
        'quantity'    => max(1, (int) ($item['quantity']    ?? 1)),
        'part_number' => trim((string) ($item['part_number'] ?? '')),
        'name'        => trim((string) ($item['name']        ?? '')),
        'area'        => trim((string) ($item['area']        ?? '')),
        'category'    => $category,
    ];

    // Phase 23 Plan 06 — zone (D-02 per-device override; D-04 free-text path).
    // Empty / whitespace-only zone is OMITTED so it falls through to D-01
    // category default in the renderer.
    $zone = trim((string) ($item['zone'] ?? ''));
    if ($zone !== '') {
        $entry['zone'] = $zone;
    }

    $equipment[] = $entry;
}
$raw['equipment'] = $equipment;
```

(c) **Run `php -l` + tests:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Http/Controllers/ProjectPackageReviewController.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ReviewZoneDropdownTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```

**Step 3 — Commit GREEN:**
```
git add app/Http/Controllers/ProjectPackageReviewController.php tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php
git commit -m "feat(23-06): controller persists equipment[N][zone] with validation (DRAW-46 D-03; Pitfall 8 XSS mitigation)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Http/Controllers/ProjectPackageReviewController.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ReviewZoneDropdownTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan test --filter=ReviewZoneDropdownTest` exits 0 (6 tests pass)
    - `grep -c "'equipment.\*.zone'" app/Http/Controllers/ProjectPackageReviewController.php` returns ≥1 (validation rule added)
    - `grep -c "regex:/\^\[A-Za-z0-9 _\\\\\\-\]+\$/u" app/Http/Controllers/ProjectPackageReviewController.php` returns ≥1 (Pitfall 8 regex)
    - `grep -c "'zone'" app/Http/Controllers/ProjectPackageReviewController.php` returns ≥2 (validation + parser)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Http/Controllers/ProjectPackageReviewController.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>Controller persists + validates zone; 6 feature tests green; v1.3 surfaces intact.</done>
</task>

<task type="auto">
  <name>Task 2: Blade — add zone dropdown column (static + JS row template)</name>
  <files>
    resources/views/project-packages/review.blade.php
  </files>
  <read_first>
    - resources/views/project-packages/review.blade.php lines 700-770 (static equipment row — find the column structure)
    - resources/views/project-packages/review.blade.php lines 1370-1410 (JS row-template — addRow function output)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-03 (line 71) + D-04 (line 73-77) + specifics line 211 (display vs storage casing)
    - app/Http/Controllers/ProjectPackageReviewController.php (just modified — confirm parseReviewPayload reads `equipment.*.zone`)
    - config/drawings.php (Plan 01 — zone_vocab array source)
  </read_first>
  <behavior>
    - Static row Blade: add a new `<td class="col-zone">` AFTER the existing category `<td>` (around line ~746)
    - JS row template: add equivalent `<td>` to the addRow string template (around line ~1397)
    - Dropdown options: `<option value="">` (default-by-category) + every entry from `config('drawings.zone_vocab')` + `<option value="__other__">` (free-text trigger)
    - Free-text input appears when `__other__` selected; submitted as `equipment[N][zone]` (the picker swaps which input is the named one — only one is sent per row)
    - Existing `{{ old('equipment.{$i}.zone', $item['zone'] ?? '') }}` preserves the value across save round-trips
    - Help text below the picker per CONTEXT specifics line 211: "Free text creates a separate group on the diagram — use the dropdown for consistency."
    - Alpine.js `zonePicker()` data function — single instance per row (mirrors Phase 22's portPicker pattern but simpler)
  </behavior>
  <action>
**Step 1 — Edit static row in review.blade.php.**

Locate the category `<td>` at approximately line 738-746:

```blade
<td style="width:150px;">
<select name="equipment[{{ $i }}][category]" data-equip-category>
    @foreach ($categoryOptions as $value => $label)
        <option value="{{ $value }}" {{ $selectedCategory === $value ? 'selected' : '' }}>
            {{ $label }}
        </option>
    @endforeach
</select>
</td>
```

INSERT a new `<td>` immediately AFTER the closing `</td>` of category and BEFORE the existing `<td class="col-area">`:

```blade
<td class="col-zone" style="width:140px;">
    @php
        $currentZone = old("equipment.{$i}.zone", $item['zone'] ?? '');
        $vocab = config('drawings.zone_vocab', []);
        $isVocabValue = $currentZone !== '' && in_array($currentZone, $vocab, true);
        $isFreeText = $currentZone !== '' && ! $isVocabValue;
    @endphp
    <div x-data="zonePicker({{ json_encode($currentZone) }}, {{ json_encode($vocab) }}, {{ $isFreeText ? 'true' : 'false' }})"
         class="zone-picker">
        <select x-show="!isFreeText"
                x-model="selected"
                @change="onChange"
                :name="isFreeText ? '' : 'equipment[{{ $i }}][zone]'"
                style="font-size:.82rem;">
            <option value="">— default by category —</option>
            <template x-for="z in vocab" :key="z">
                <option :value="z" x-text="z" :selected="z === selected"></option>
            </template>
            <option value="__other__">Other (free text)…</option>
        </select>
        <input x-show="isFreeText"
               type="text"
               x-model="freeText"
               :name="isFreeText ? 'equipment[{{ $i }}][zone]' : ''"
               maxlength="50"
               pattern="^[A-Za-z0-9 _\-]+$"
               placeholder="e.g. Server Cabinet"
               style="font-size:.82rem;" />
        <button type="button"
                x-show="isFreeText"
                @click="cancelFreeText"
                style="font-size:.7rem;color:#777;background:none;border:0;padding:2px 4px;">
            ↩ use dropdown
        </button>
        <small class="form-hint" style="display:block;font-size:.65rem;color:#666;margin-top:2px;">
            Free text creates a separate group on the diagram — use the dropdown for consistency.
        </small>
    </div>
    @error("equipment.{$i}.zone")
        <p class="form-error">{{ $message }}</p>
    @enderror
</td>
```

**Step 2 — Add corresponding `<th>` column header** by locating the equipment table's `<thead>` (search for "Part Number" or "Category" `<th>`). Add `<th style="width:140px;">Zone</th>` after the Category header.

**Step 3 — Edit JS row-template** at approximately line 1395-1398. Find the addRow function — search for `equipment[${idx}][category]` (line 1386). The template ends with a closing `</tr>`. INSERT the equivalent zone `<td>` after the category `<td>` and BEFORE the area `<td>` (mirror the static row block structure but use `${idx}` instead of `{{ $i }}`):

```javascript
// Inside the addRow template literal, after the category </td> block:
`<td class="col-zone" style="width:140px;">
    <div x-data="zonePicker('', ${JSON.stringify(window.__zoneVocab || [])}, false)" class="zone-picker">
        <select x-show="!isFreeText" x-model="selected" @change="onChange" :name="isFreeText ? '' : 'equipment[${idx}][zone]'" style="font-size:.82rem;">
            <option value="">— default by category —</option>
            <template x-for="z in vocab" :key="z"><option :value="z" x-text="z"></option></template>
            <option value="__other__">Other (free text)…</option>
        </select>
        <input x-show="isFreeText" type="text" x-model="freeText" :name="isFreeText ? 'equipment[${idx}][zone]' : ''" maxlength="50" pattern="^[A-Za-z0-9 _\\\\-]+$" placeholder="e.g. Server Cabinet" style="font-size:.82rem;" />
        <button type="button" x-show="isFreeText" @click="cancelFreeText" style="font-size:.7rem;color:#777;background:none;border:0;padding:2px 4px;">↩ use dropdown</button>
        <small class="form-hint" style="display:block;font-size:.65rem;color:#666;margin-top:2px;">Free text creates a separate group on the diagram — use the dropdown for consistency.</small>
    </div>
</td>`
```

ALSO: in a `<script>` block at the bottom of the Blade (near the existing `@js(config('cables.compatibility_aliases'))` from Phase 22), publish the vocab to the JS template:

```blade
<script>
    window.__zoneVocab = @js(config('drawings.zone_vocab', []));

    function zonePicker(initial, vocab, isFreeTextInitial) {
        return {
            selected: initial && vocab.includes(initial) ? initial : (initial && !vocab.includes(initial) && initial !== '' ? '__other__' : ''),
            freeText: initial && !vocab.includes(initial) ? initial : '',
            isFreeText: isFreeTextInitial,
            vocab: vocab,
            onChange() {
                if (this.selected === '__other__') {
                    this.isFreeText = true;
                    if (this.freeText === '') { this.freeText = ''; }
                } else {
                    this.isFreeText = false;
                    this.freeText = '';
                }
            },
            cancelFreeText() {
                this.isFreeText = false;
                this.freeText = '';
                this.selected = '';
            },
        };
    }
</script>
```

NOTE on the existing review.blade — there's already an `@js(config('cables.compatibility_aliases'))` somewhere; mirror that placement.

**Step 4 — Run `php -l` (Blade compiles via Laravel — invoke artisan view:cache to compile + check):**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan view:cache 2>&1 | tee /tmp/view-cache.log
grep -i 'error\|exception' /tmp/view-cache.log; echo "exit=$?"
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan view:clear
```
Expect: no errors emitted by view:cache. The grep returns 1 (no match) on success.

**Step 5 — Re-run the controller test to confirm the round-trip Blade-render → form submit still passes:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ReviewZoneDropdownTest --stop-on-failure
```

Tests should still pass (they don't render the Blade — pure controller-level POST tests).

**Step 6 — v1.3 invariant check:**
```
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```

**Step 7 — Commit:**
```
git add resources/views/project-packages/review.blade.php
git commit -m "feat(23-06): zone dropdown column on review form (DRAW-46 D-03 + D-04 free-text escape hatch)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan view:cache</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ReviewZoneDropdownTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan view:cache` exits 0 (Blade compiles without errors)
    - `php artisan test --filter=ReviewZoneDropdownTest` exits 0 after Blade changes (unchanged from Task 1)
    - `grep -c 'equipment\[{{ $i }}\]\[zone\]' resources/views/project-packages/review.blade.php` returns ≥1 (static row)
    - `grep -c 'equipment\[\${idx}\]\[zone\]' resources/views/project-packages/review.blade.php` returns ≥1 (JS row template)
    - `grep -c 'zonePicker(' resources/views/project-packages/review.blade.php` returns ≥2 (one Alpine + one definition)
    - `grep -c '__zoneVocab' resources/views/project-packages/review.blade.php` returns ≥1 (JS template gets the vocab)
    - `grep -c "Free text creates a separate group" resources/views/project-packages/review.blade.php` returns ≥1 (D-04 help text)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
  </acceptance_criteria>
  <done>Blade modified additively; view:cache compiles; controller test still green; v1.3 untouched.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: Manual visual verification — zone dropdown UX in browser</name>
  <what-built>
    The zone-dropdown column is now part of the quote-review equipment table:
    - Static rendering on page load: dropdown shows the existing zone or "— default by category —" when unset
    - Add-Row button creates new rows with the same dropdown
    - Selecting "Other (free text)..." reveals a text input (50-char max, regex-restricted)
    - "↩ use dropdown" button reverts back to the select
    - Form save persists the zone into the equipment line's JSON
    - Round-trip: re-opening the review page shows the persisted zone selected
  </what-built>
  <how-to-verify>
    1. Open any project's quote-review page in a browser (e.g. `/project-packages/{id}/review` against local dev or staging)
    2. **Static render test:**
       - Locate the equipment table — confirm a new "Zone" column header appears between "Category" and "Area"
       - For an unset row, the dropdown shows "— default by category —"
       - For a row whose `extracted_data.equipment[N].zone` is already set (you can inject via tinker), the dropdown shows the persisted value
    3. **Dropdown happy path:**
       - Pick "RACK" on one row → confirm the select shows "RACK"
       - Pick "CEILING" on another row → confirm the select shows "CEILING"
       - Save the form → reload → confirm the persisted values appear selected
    4. **Free-text escape hatch (D-04):**
       - Pick "Other (free text)..." → confirm the text input replaces the select
       - Type "Server Cabinet" → save → reload → confirm the text input is shown with the persisted text
       - Click "↩ use dropdown" → confirm the input is replaced by the select again
    5. **Validation rejection:**
       - In the free-text input, type `<script>alert(1)</script>` and submit
       - Confirm a form error renders below the row stating the zone field has an invalid format
       - Confirm the equipment line's `zone` was NOT persisted (check via tinker: `data_get($package->extracted_data, 'equipment.0.zone')`)
    6. **Add-Row test:**
       - Click "Add Row" → confirm the new row contains the zone dropdown column
       - Pick a zone on the new row → save → reload → confirm persisted
    7. **D-LOCK invariant — Plan 02 ZoneGrouper read-side roundtrip:**
       - In tinker, run `$project->fresh()->devicesWithStencils()` on a project with zone-set equipment lines
       - Confirm the `zone` key appears in the returned lines for rows where it was set
       - Confirm Plan 05 `DrawIoBuilderService::build($project)` produces XML containing the chosen zone as a `<mxCell value="ZONE-NAME">` zone-container cell

    Type "approved" to continue OR describe issues if any step fails.
  </how-to-verify>
  <resume-signal>Type "approved" or describe issues with the dropdown UX</resume-signal>
</task>

</tasks>

<verification>
- Tasks 1 + 2 committed atomically
- `php artisan test --filter=ReviewZoneDropdownTest` exits 0 (6 tests pass)
- `php artisan view:cache` exits 0 (Blade compiles)
- `git diff --stat` empty on the 5 v1.3 invariant files
- Task 3 checkpoint: visual verification passes for static render + free-text + validation rejection + add-row + round-trip into renderer
</verification>

<success_criteria>
- Engineer can pick a zone per equipment row from the dropdown OR type a free-text override
- Server-side validation rejects HTML/script payloads (Pitfall 8 mitigation)
- The renderer (Plan 05 DrawIoBuilderService → Plan 02 ZoneGrouper) reads the persisted zone via the existing `Project::devicesWithStencils()` accessor — D-02 per-device override active

Phase 23 is now functionally complete pending Plan 07 final verification.
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-06-SUMMARY.md` documenting:
- The validation rule verbatim: `'equipment.*.zone' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 _\-]+$/u']`
- The Alpine.js zonePicker() data function pattern
- Empty zone is OMITTED from JSON (not persisted as '') — falls through to D-01 default
- Decision IDs implemented: D-02 (per-device override write side), D-03 (UI in Phase 23, not deferred), D-04 (free-text escape hatch + help text), D-09 (generic naming — no `rams_zone`)
- T-23-06-A1 + T-23-06-A2 + T-23-06-A3 mitigations verified
- 6 tests + 1 visual checkpoint
- Browser UAT notes from the human-verify task

End with the 🚨 "Files to upload to live" section listing:
- `resources/views/project-packages/review.blade.php`
- `app/Http/Controllers/ProjectPackageReviewController.php`
- Note: run `php artisan view:clear && php artisan config:clear` on live after upload.
</output>