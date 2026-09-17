---
phase: quick
plan: 260917-r80
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Core/Modules/Projects/ProjectDataService.php
  - tests/Unit/ProjectDataServiceTest.php
  - tests/Unit/InstallTaskGeneratorServiceTest.php
autonomous: true
requirements: [QUICK-r80]
must_haves:
  truths:
    - "After an engineer edits equipment categories on a package (e.g. hardware -> customer_supplied) and regenerates the install programme or a worksheet, the regenerated document reflects the edit"
    - "ProjectDataService::resolve()['_raw_equipment'] reads the same equipment copy the package-edit save path actually updates, not a copy it never touches"
  artifacts:
    - path: app/Core/Modules/Projects/ProjectDataService.php
      provides: "_raw_equipment build reads extracted_data['equipment'] before extracted_data['equipment_list']"
    - path: tests/Unit/ProjectDataServiceTest.php
      provides: "Regression test reproducing the production fixture: both keys present, divergent category and row count"
    - path: tests/Unit/InstallTaskGeneratorServiceTest.php
      provides: "End-to-end regression test (real ProjectDataService, not mocked) proving InstallTask.equipment_category reflects the edited copy"
  key_links:
    - from: "ProjectDataService::resolveUncached()"
      to: "InstallTaskGeneratorService::generate() / WorksheetGeneratorService::resolveAndDistributeRooms()"
      via: "$data['_raw_equipment']"
      pattern: "_raw_equipment"
---

<objective>
Fix the install-programme and worksheet generators reading a stale equipment key, so engineer
edits to equipment categories actually take effect.

Purpose: On production package 165, an engineer moved 36 items from `hardware` to
`customer_supplied` via the package-review edit form. The install programme was regenerated
and still showed the old categorisation. Root cause: `ProjectDataService::resolveUncached()`
builds `_raw_equipment` — the single feed for both `InstallTaskGeneratorService::generate()`
and `WorksheetGeneratorService`'s room/equipment distribution — with
`$source['equipment_list'] ?? $source['equipment'] ?? $source['hardware_list'] ?? []`. The
package-edit save path (`ProjectPackageReviewController::update()`/`approve()`) only ever
writes `extracted_data['equipment']` (via `array_merge($package->extracted_data, $payload)`)
and the `equipment_list` **column** — it never touches the JSON key
`extracted_data['equipment_list']`. Because that stale JSON key is still present, the `??`
chain never falls through to the edited `extracted_data['equipment']` copy, so every edit is
invisible to both generators by construction.

Output: `_raw_equipment` sourced from the copy the save path actually keeps current
(`extracted_data['equipment']`), with a regression test that reproduces the exact
production shape (both keys present, values diverging) so the fix can't regress silently.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md

<interfaces>
<!-- Read-only reference: current buggy read site and the sibling method that
already gets the priority right, in the same file. -->

From app/Core/Modules/Projects/ProjectDataService.php (`resolveUncached()`, ~line 116-118 —
the bug):
```php
// Raw equipment list with area tags preserved (for room-level distribution
// by downstream generators like WorksheetGeneratorService).
// Uses equipment_list > equipment > hardware_list in priority since
// equipment_list has the broadest coverage with area fields intact.
$rawEquipment = (array) ($source['equipment_list'] ?? $source['equipment'] ?? $source['hardware_list'] ?? []);
```

From the same file (`resolveEquipment()`, ~line 210 — already correct, and the pattern to match):
```php
$all = $source['equipment'] ?? $source['equipment_list'] ?? [];
```

From app/Http/Controllers/ProjectPackageReviewController.php (`update()` — the save path that
creates the divergence; `approve()` is identical in this respect):
```php
$merged = array_merge($package->extracted_data ?? [], $payload);
// ...
$package->update([
    'extracted_data' => $merged,               // writes $merged['equipment'] — never 'equipment_list'
    'equipment_list' => $payload['equipment'] ?? [],  // the COLUMN, not extracted_data['equipment_list']
    'status'         => ProjectPackage::STATUS_REVIEWED,
]);
```

Both `InstallTaskGeneratorService::resolveAndDistributeRooms()` (:181) and
`WorksheetGeneratorService::resolveAndDistributeRooms()` (:360) already read
`$data['_raw_equipment'] ?? $data['equipment'] ?? []` — correct priority, fed by the single
upstream `ProjectDataService` method above. Neither generator needs its own code change;
fixing the one upstream read site fixes both.

Confirmed at import time (`QuoteWerksImportService::mapParsedShapeToExtractedData()` and
`ExtractQuoteJob`): `equipment`, `equipment_list`, and `line_items` are all assigned the exact
same array (same object, same `area` tags) on a fresh import. Flipping the priority is
therefore a no-op for unedited packages and only changes behaviour once the two copies have
diverged — exactly the case that needs fixing.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Fix _raw_equipment priority order in ProjectDataService</name>
  <files>app/Core/Modules/Projects/ProjectDataService.php, tests/Unit/ProjectDataServiceTest.php</files>
  <behavior>
    - Test: `extracted_data` carries both `equipment` (edited: 2 items, category=customer_supplied,
      with `area` tags) and `equipment_list` (stale: 3 items, category=hardware, same names) —
      `resolve()['_raw_equipment']` must return the 2-item edited set with category
      `customer_supplied`, not the 3-item stale set.
    - Test (regression guard): `reviewed_data` still wins over both when present (existing
      `test_resolve_falls_back_to_extracted_data`-style tests must keep passing unmodified).
    - Test (no-op guard for fresh imports): when `equipment` and `equipment_list` are identical
      arrays (the QuoteWerks-import shape), `_raw_equipment` is unchanged either way — assert
      it equals that shared array.
  </behavior>
  <action>
In `app/Core/Modules/Projects/ProjectDataService.php`, in `resolveUncached()`, change the
`_raw_equipment` build to prefer `equipment` over `equipment_list`, matching the priority
`resolveEquipment()` already uses a few lines below in the same file:

Replace:
```php
$rawEquipment = (array) ($source['equipment_list'] ?? $source['equipment'] ?? $source['hardware_list'] ?? []);
```
with:
```php
$rawEquipment = (array) ($source['equipment'] ?? $source['equipment_list'] ?? $source['hardware_list'] ?? []);
```

Update the comment immediately above it (currently claims "equipment_list has the broadest
coverage with area fields intact") to state the real invariant: `equipment` and
`equipment_list` are written identically at import time (see QuoteWerksImportService /
ExtractQuoteJob), but `ProjectPackageReviewController::update()`/`approve()` only refresh
`extracted_data['equipment']` on save — `extracted_data['equipment_list']` is a write-once
JSON key that goes stale the moment a package is edited. Reading `equipment` first means
edits take effect; `equipment_list`/`hardware_list` remain as fallbacks for the (currently
nonexistent) case where only they are present. Cite 260917-r80.

Do not touch `resolveEquipment()` (~line 210) — its `equipment ?? equipment_list` order is
already correct and is the reference this fix aligns with. Do not touch
`ProjectPackageReviewController` or any other consumer of `extracted_data['equipment_list']`
directly (`OmManualGeneratorService`, `PackagesReclassifyEquipmentCommand`,
`ProjectPackageReviewController::show()`) — out of scope for this task, which targets only the
`_raw_equipment` feed shared by the install-programme and worksheet generators.

Add the new test(s) to `tests/Unit/ProjectDataServiceTest.php`, in the "Merge priority" section
near `test_resolve_falls_back_to_extracted_data`, using the existing `stdClass` package-stub +
`makeProjectStub()` pattern already in that file (no new test infrastructure needed). Assert on
`$result['_raw_equipment']` specifically (not `$result['equipment']`, which goes through
`resolveEquipment()`'s separate, already-correct path).

Write the test first (RED — confirms it fails against the current `equipment_list ?? equipment`
order), then apply the one-line fix (GREEN).
  </action>
  <verify>
    <automated>php artisan test tests/Unit/ProjectDataServiceTest.php --stop-on-failure</automated>
  </verify>
  <done>New tests pass; `_raw_equipment` returns the edited `equipment` copy when both keys are
present and diverge; existing `resolveEquipment()`/reviewed_data priority tests are unaffected.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: End-to-end regression — InstallTaskGeneratorService reflects the edited category</name>
  <files>tests/Unit/InstallTaskGeneratorServiceTest.php</files>
  <behavior>
    - Test: real `ProjectPackage` row (not mocked `ProjectDataService`) with `extracted_data`
      shaped exactly like the production fixture — `equipment` edited to `customer_supplied`
      (2 items, area tags set), `equipment_list` stale at `hardware` (3 items, same names/areas)
      — wired to a real `Project` via `latestPackage()`. Running the real
      `ProjectDataService::resolve()` → `InstallTaskGeneratorService::generate()` pipeline
      (no `ProjectDataService` mock) must create `InstallTask` rows with
      `equipment_category === 'customer_supplied'` for those items, and exactly 2 tasks (the
      edited set), not 3 (the stale set).
  </behavior>
  <action>
Add a new test method to `tests/Unit/InstallTaskGeneratorServiceTest.php` that, unlike every
existing test in that file, does NOT call `makeProjectDataService()` (the Mockery stub) —
it must exercise the real `ProjectDataService` so Task 1's fix is proven at the boundary the
bug was actually observed at (a regenerated install programme), not just inside
`ProjectDataService`'s own unit tests.

Steps:
1. `$project = Project::factory()->create();`
2. Create a real `ProjectPackage::create([...])` row (fillable: `project_id`, `extracted_data`,
   `equipment_list`, `status`) attached to `$project`, with:
   - `extracted_data['equipment']` = 2 items, `category => 'customer_supplied'`, distinct
     `area` values (e.g. `'Boardroom'`, `'Lobby'`) so Strategy 1 area-tag room-building fires.
   - `extracted_data['equipment_list']` = 3 items, `category => 'hardware'`, same `area`
     values as above plus one extra — this is the stale copy that must NOT win.
   - `status => ProjectPackage::STATUS_REVIEWED` (mirrors the post-edit state).
3. `$pds = app(\App\Core\Modules\Projects\ProjectDataService::class);` — the real service,
   not a Mockery double.
4. `$generator = new InstallTaskGeneratorService($pds);` (or resolve via container if the
   constructor takes only `ProjectDataService`).
5. Create an `InstallProgramme` for `$project` and call `$generator->generate($programme)`.
6. Assert `$programme->tasks()->count() === 2` and every task's `equipment_category` is
   `'customer_supplied'`.

Note: `customer_supplied` is not in `InstallTaskGeneratorService::EXCLUDED_CATEGORIES`, so
these items are expected to still produce install tasks (deliberately out of scope — see
Constraints). The point of this test is that the category value on the generated task is
correct, proving the edit took effect, not that customer-supplied items are excluded.

Write the test first (RED against pre-fix code — revert Task 1's one-line change locally to
confirm RED if needed, since Task 1 will already be GREEN by the time this task runs), confirm
it passes once both tasks are applied (GREEN).
  </action>
  <verify>
    <automated>php artisan test tests/Unit/InstallTaskGeneratorServiceTest.php --stop-on-failure</automated>
  </verify>
  <done>New end-to-end test passes: generated `InstallTask` rows carry `customer_supplied`,
matching the edited source, not the stale 3-item `hardware` copy.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Engineer edit (package-review form) -> stored JSON | Engineer-supplied category/area strings persisted into `extracted_data`/`equipment_list`; already validated/normalised by `EquipmentCategoryClassifier` and zone-regex validation upstream of this task — untouched here |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-r80-01 | Tampering (data integrity) | `ProjectDataService::resolveUncached()` `_raw_equipment` | mitigate | This task: read the copy the save path actually updates (`equipment`), eliminating the silent-revert-to-stale-data class of bug for this field |
| T-r80-02 | Repudiation | Stale `extracted_data['equipment_list']` left un-synced after this fix | accept | Deliberately out of scope (minimal-diff constraint); no consumer reached via `ProjectDataService` reads it anymore after this fix, and direct readers of that key (`OmManualGeneratorService`, `PackagesReclassifyEquipmentCommand`) are unrelated to the install-programme/worksheet symptom this task closes — flagged as a residual follow-up, not silently dropped |
| T-r80-03 | Tampering | `InstallTaskGeneratorService::EXCLUDED_CATEGORIES` unchanged | accept | Explicitly out of scope per task instructions — whether 21CAV installs client-supplied kit is an unresolved business decision for the user, not a bug; changing it here would silently alter on-site task lists |
</threat_model>

<verification>
```powershell
php artisan test tests/Unit/ProjectDataServiceTest.php tests/Unit/InstallTaskGeneratorServiceTest.php --stop-on-failure
```
Then the full suite to confirm no regressions:
```powershell
php artisan test
```
Expect no new failures against the last known baseline (875 passing / 0 failed for the
`Rams` filter at last run — confirm via `php artisan test --filter=Rams`).

Manual smoke test (optional, on staging/local with a copy of package 165's shape): edit an
equipment item's category on a package review form, regenerate the install programme, confirm
the new category appears on the regenerated tasks.
</verification>

<success_criteria>
- `ProjectDataService::resolve()['_raw_equipment']` reads `extracted_data['equipment']` before
  `extracted_data['equipment_list']`, matching the already-correct order in `resolveEquipment()`.
- A regression test reproducing the production fixture (both keys present, diverging category
  and row count) exists and passes.
- An end-to-end test proves `InstallTaskGeneratorService::generate()` — using the real
  `ProjectDataService`, not a mock — produces tasks carrying the edited category.
- `InstallTaskGeneratorService::EXCLUDED_CATEGORIES` and `WorksheetGeneratorService`'s
  equivalent are untouched.
- Full test suite shows no new failures vs the pre-task baseline.
</success_criteria>

<output>
Create `.planning/quick/260917-r80-fix-generators-reading-stale-extracted-d/260917-r80-SUMMARY.md` when done.
</output>
