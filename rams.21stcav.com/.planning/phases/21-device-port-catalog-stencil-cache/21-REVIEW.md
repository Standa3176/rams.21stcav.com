---
phase: 21-device-port-catalog-stencil-cache
reviewed: 2026-05-10T00:00:00Z
depth: standard
files_reviewed: 31
files_reviewed_list:
  - app/Http/Controllers/Admin/DrawIoSpikeController.php
  - app/Models/DevicePort.php
  - app/Models/DeviceStencil.php
  - app/Models/Project.php
  - app/Services/Drawings/AutoGenericStencilGenerator.php
  - app/Services/Drawings/DeviceStencilCacheService.php
  - app/Services/Drawings/DeviceStencilSeedReader.php
  - app/Services/Drawings/DrawIoBuilderService.php
  - app/Services/Drawings/DrawIoSpikeBuilderService.php
  - app/Services/Drawings/ManufacturerLogoResolver.php
  - database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/DeviceStencilSeeder.php
  - resources/data/device-stencils-seed/_top-50-gap.json
  - resources/data/device-stencils-seed/_v1.3-promoted.json
  - resources/data/device-stencils-seed/clickshare-bar-pro.json
  - resources/data/device-stencils-seed/neat-bar-pro.json
  - resources/data/device-stencils-seed/netgear-gs312tp.json
  - resources/data/device-stencils-seed/samsung-qm65c-t.json
  - resources/data/device-stencils-seed/sennheiser-tcc2.json
  - scripts/derive-top50-gap.php
  - scripts/generate-top50-gap.php
  - scripts/promote-v13-catalog.php
  - tests/Feature/Drawings/DeviceStencilSeederTest.php
  - tests/Feature/Drawings/DrawIoBuilderServiceTest.php
  - tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php
  - tests/Feature/Drawings/SeedPackCoverageTest.php
  - tests/Fixtures/seed-coverage/top-50-snapshot.json
  - tests/Unit/Models/DeviceStencilTest.php
  - tests/Unit/Services/Drawings/AutoGenericStencilGeneratorTest.php
  - tests/Unit/Services/Drawings/DeviceStencilCacheServiceTest.php
  - tests/Unit/Services/Drawings/DeviceStencilSeedReaderTest.php
  - tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php
findings:
  critical: 0
  warning: 3
  info: 5
  total: 8
status: issues_found
---

# Phase 21: Code Review Report

**Reviewed:** 2026-05-10
**Depth:** standard
**Files Reviewed:** 31
**Status:** issues_found

## Summary

Phase 21 is the foundation of milestone v2.0 (Engineering-Grade AV Drawings). It introduces two new tables (`device_stencils`, `device_ports`), a cross-project caching service keyed on normalised `part_number`, an auto-generator for Tier 1 placeholders, a seed-pack reader/seeder, a generalised draw.io builder reading from the new tables, and a manufacturer-logo resolver. The spike admin route is rewired to read from the live DB.

**Phase 21 specific verifications passed:**

- DB unique index on `device_stencils.part_number` is present (migration line 48: `->unique()`), so `firstOrCreate` race-safety in `DeviceStencilCacheService::resolveForPartNumber` is correctly underpinned by the schema. The compound unique on `device_ports(device_stencil_id, port_id)` (line 93) protects port idempotency.
- Spike admin route is gated. Routes are inside `Route::middleware('admin')->group(...)` (`routes/web.php` line 179) and the `admin` alias resolves to `EnsureUserIsAdmin` which calls `abort(403, ...)` on non-admin users.
- XSS escape pattern is consistent — `AutoGenericStencilGenerator::xml()` and `DrawIoBuilderService::xml()` both use `htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8')` for every interpolated user-supplied value.
- `ManufacturerLogoResolver::resolveSvg` does NOT take user input as the file slug — slugs come from a hardcoded `MANUFACTURER_NEEDLES` const table whose values are all hardcoded literals. No path traversal vector.
- `Project::devicesWithStencils()` correctly avoids `DB::transaction` per the documented contract (the unique index handles the race).

The eight findings below are all non-blocking: three warnings touch on documentation/test consistency drift that will eventually trip Phase 22+ contributors, five info items are opportunistic clean-ups.

## Warnings

### WR-01: `top-50-snapshot.json` contains 40 part_numbers, not 50 — invalidates SeedPackCoverageTest's documented "exactly 50" claim

**File:** `tests/Fixtures/seed-coverage/top-50-snapshot.json:6-48`
**Issue:** The snapshot's `top_50` array contains exactly 40 entries (counted: lines 7-47, one entry per line). However, `SeedPackCoverageTest::topFiftyReference()` doesn't enforce 50 — it just uses `array_map` over whatever is present, so the test still passes. The misleading parts:

1. The test class docblock (`SeedPackCoverageTest.php:33`) explicitly claims: *"The local dev snapshot has exactly 50 part_numbers; threshold stays at 80%."* — this is no longer true.
2. Files named `top-50-*` and `top_50` keys imply 50 entries; the actual list is 40, so a future contributor regenerating the snapshot may be confused about the target.
3. The 80% threshold is now measured against 40, meaning ≥32 must be covered (rather than ≥40 of 50). This is empirically generous, not a bug — but the threshold's "meaningful 80%" interpretation is weakened.

The test itself is correct; the documentation is stale.

**Fix:** Either pad the snapshot to 50 part_numbers (re-run `scripts/derive-top50-gap.php` against a richer dev DB), or amend the docblock to reflect the current count and rename the field to `top_n` with a `count` sibling:

```php
// In SeedPackCoverageTest.php docblock (line ~33):
// "The local dev snapshot has 40 part_numbers (smaller than the
//  50-entry target); 80% threshold therefore requires >=32 covered.
//  Production servers with >=50 packages take the live-query path."
```

Or document a concrete ratio target on the snapshot itself (`expected_min_coverage_pct: 80`) so the test reads it rather than hard-coding 80.0.

### WR-02: `_top-50-gap.json` contains 39 entries — gap_count + v1.3 (53) + spike (5) = 97, but the seeder threshold floor is set at 58 in `DeviceStencilSeederTest`

**File:** `tests/Feature/Drawings/DeviceStencilSeederTest.php:43-44`
**Issue:** The assertion `assertGreaterThanOrEqual(58, $count, ...)` will pass with 97 actual rows, but the failure message says *"Seeder must create >=58 stencils (5 spike + 53 v1.3 + gap-fill); got {$count}"* — implying the gap-fill is allowed to be 0. In practice the gap pack ships with 39 entries (per `_top-50-gap.json` provenance string at line 4: *"Initial count: 39 entries"*) so the floor is too low to detect a real regression where the gap pack accidentally drops to 0.

If a future commit accidentally truncates `_top-50-gap.json` to an empty `stencils: []`, the test would still pass (5 + 53 = 58 ≥ 58). Same risk applies to `_v1.3-promoted.json`.

**Fix:** Tighten the floor to reflect the actual shipped count, OR split into separate assertions per pack source:

```php
public function test_first_run_creates_at_least_97_stencils(): void
{
    // ...seed...
    $count = DeviceStencil::count();
    $this->assertGreaterThanOrEqual(95, $count,  // 5+53+39=97; small slack for evolution
        "Seeder must create >=95 stencils (5 spike + 53 v1.3-promoted + 39 gap-fill); got {$count}");
}

// Add per-pack regression detection:
public function test_v1_3_promoted_pack_seeds_at_least_50_entries(): void
{
    $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])->assertExitCode(0);
    $v13Count = DeviceStencil::query()
        ->whereJsonContains('metadata->provenance', 'v1.3-catalog-promoted')
        ->count();
    $this->assertGreaterThanOrEqual(50, $v13Count);
}
```

### WR-03: `ManufacturerLogoResolver` ships `bogen` in the needle table but neither the docblock nor the unit test acknowledges it — `count(20)` assertion is fragile

**File:** `app/Services/Drawings/ManufacturerLogoResolver.php:23-26` (docblock) and `tests/Unit/Services/Drawings/ManufacturerLogoResolverTest.php:64-84`
**Issue:** Class docblock (line 23-26) lists *"The 20 unique slugs"* but only enumerates 19: `atlona, barco, biamp, cisco, clickshare, crestron, extron, lightware, logitech, neat, netgear, polycom, q-sys, qsc, samsung, sennheiser, shure, sony, yamaha`. The needle table at line 61 (`'bogen' => 'bogen'`) adds a 20th slug that's missing from the doc enumeration.

The test `test_known_manufacturers_returns_twenty_unique_slugs` asserts `count(20)` and uses `assertContains` (not `assertEquals`) for the 19 expected slugs, so it accidentally passes — but if a contributor reads only the docblock + test expectations, they'd reasonably believe the table has 19 entries and `count(20)` would look like a typo.

`bogen.svg` does exist in `public/img/manufacturers/` (verified) so this is a doc-versus-code drift, not a missing asset.

**Fix:** Add `bogen` to the docblock + test expectation list:

```php
// Class docblock — line 23:
// The 20 unique slugs (alphabetical):
//   atlona, barco, biamp, bogen, cisco, clickshare, crestron, extron,
//   lightware, logitech, neat, netgear, polycom, q-sys, qsc, samsung,
//   sennheiser, shure, sony, yamaha.

// Test — ManufacturerLogoResolverTest.php line ~77:
$expected = [
    'atlona', 'barco', 'biamp', 'bogen', 'cisco', 'clickshare', 'crestron',
    'extron', 'lightware', 'logitech', 'neat', 'netgear', 'polycom',
    'q-sys', 'qsc', 'samsung', 'sennheiser', 'shure', 'sony', 'yamaha',
];
```

Either that, or remove `bogen` from the needle table if it's not intentional (the docblock implies it shouldn't be there). The test should also use `assertEqualsCanonicalizing` against the full list rather than `assertContains` of a partial list, so silent drift cannot recur.

## Info

### IN-01: `DrawIoBuilderService::deriveCables` ignores `$srcPort` / `$tgtPort` for actual constraint targeting

**File:** `app/Services/Drawings/DrawIoBuilderService.php:345-358`
**Issue:** The emit closure receives `$srcPort` / `$tgtPort` (e.g. `'hdmi-out'`, `'hdmi-1'`) but only uses them as boolean signals to choose between adding `exitX=0;exitY=0;...` / `entryX=0;entryY=0;...` constraints (literal corner anchors) or empty strings. The named port_id is never propagated into the mxGraph attributes so cables always render terminating at the cell's top-left corner regardless of which port the chain semantically represents. The shape's `<connections><constraint name="hdmi-out"/>` definitions go unused by the edge cells.

This matches the documented "shallow Phase 21" behaviour (Phase 22 will introduce real port-FK routing), but the visual output is misleading — the cable enters the corner, not the labelled port — and the `$srcPort`/`$tgtPort` parameters are misleading dead-weight as written.

**Fix (when convenient):** Either add a TODO comment annotating the gap, or render constraints using the named port via the `exitX`/`exitY` lookup table that the renderer can derive from `device_ports.x_pct`/`y_pct`:

```php
// In emitMxGraph, when sourcePort != null AND we know the source stencil:
// $port = DevicePort::where('device_stencil_id', $sourceStencilId)
//     ->where('port_id', $sourcePort)->first();
// if ($port?->x_pct !== null) {
//     $exitConstraint = sprintf('exitX=%.4f;exitY=%.4f;exitDx=0;exitDy=0;exitPerimeter=0;',
//         (float)$port->x_pct, (float)$port->y_pct);
// }
```

Phase 22's port-FK migration likely subsumes this — but a one-line `// TODO(phase-22): exit/entry coordinates currently anchor (0,0); replace with port x_pct/y_pct lookup` would prevent future code-spelunkers wasting time wondering why source_port is wired in.

### IN-02: `DeviceStencilSeeder` sets `metadata.needs_phase_24_curation = true` for v1.3 + gap entries but the per-file spike manifests don't carry this flag — coverage assertion semantics are file-shape-coupled

**File:** `tests/Feature/Drawings/DeviceStencilSeederTest.php:113-125`
**Issue:** `test_v13_promoted_stencils_carry_needs_curation_flag` queries via `whereJsonContains('metadata->needs_phase_24_curation', true)` and asserts `>=53`. This works because v1.3 (53) + gap (39) all flag true while spike per-file (5) do not. But the phrasing in the failure message says "53 v1.3 + gap-fill" implying the floor includes both — meaning the test would still pass if the gap pack accidentally lost its flag (53 ≥ 53). 

This is the same pattern as WR-02 — coupling unrelated source provenances through a single floor.

**Fix:** Optional — split into two assertions if you want regression sensitivity per pack source:

```php
$v13Count = DeviceStencil::query()
    ->whereJsonContains('metadata->provenance', 'v1.3-catalog-promoted')->count();
$gapCount = DeviceStencil::query()
    ->whereJsonContains('metadata->provenance', 'top-50-gap-derived')->count();
$this->assertGreaterThanOrEqual(53, $v13Count);
$this->assertGreaterThanOrEqual(35, $gapCount);
```

### IN-03: `Project::devicesWithStencils()` resolves `DeviceStencilCacheService` via service container on every call

**File:** `app/Models/Project.php:315`
**Issue:** `app(\App\Services\Drawings\DeviceStencilCacheService::class)` is invoked inside the model method. While Laravel's container is fast, doing this from inside a model breaks the service-injection convention used by every other class in the codebase. It also makes the call site harder to mock in unit tests (you have to swap container bindings instead of injecting a stub).

This is a known Eloquent constraint — model methods don't get constructor injection — so it's a forgivable trade. But if `Project::devicesWithStencils()` ends up being called inside loops elsewhere, container lookups add up.

**Fix (style/convention only):** Either accept the cache as a method parameter (caller-provided), or move the orchestration out of the model entirely:

```php
// Option A — service does the work; Project just provides equipment lines:
public function devicesWithStencils(DeviceStencilCacheService $cache = null): array
{
    $cache ??= app(DeviceStencilCacheService::class);
    // ...
    return $cache->resolveMany($lines);
}

// Option B — extract to a dedicated service:
// app/Services/Drawings/ProjectDevicesResolver.php
// Then DrawIoBuilderService injects it directly.
```

Not a bug; consider for Phase 22 cleanup when port-FK routing extends this surface.

### IN-04: One-time `scripts/*.php` generators bootstrap full Laravel kernel just to get container access — heavy for what they do

**File:** `scripts/derive-top50-gap.php:33-37`, `scripts/generate-top50-gap.php:33-37`, `scripts/promote-v13-catalog.php:20-24`
**Issue:** All three scripts run:

```php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
```

`promote-v13-catalog.php` only uses the kernel to get `app(AutoGenericStencilGenerator::class)` — a class that has zero constructor dependencies and could be `new AutoGenericStencilGenerator()` directly.

`derive-top50-gap.php` and `generate-top50-gap.php` use the kernel for DB queries against `ProjectPackage` (legitimate use), but `promote-v13-catalog.php` doesn't need it.

These are committed audit-trail scripts (per phase context: lighter scrutiny applies), but if they're ever ported into a Laravel `artisan` command, the kernel boot becomes free.

**Fix (optional):** For `promote-v13-catalog.php`, drop the kernel boot:

```php
require __DIR__.'/../vendor/autoload.php';
$generator = new App\Services\Drawings\AutoGenericStencilGenerator();
```

For the others, leave as-is.

### IN-05: `_top-50-gap.json` provenance comment, generator script, and snapshot all hard-code "2026-05-10" — re-running these scripts on a different date will produce churn

**File:** `scripts/generate-top50-gap.php:174` and `scripts/derive-top50-gap.php:111`
**Issue:** The output JSON contains `"generated": date('Y-m-d')` which means re-running the script changes one byte in the seed pack on every other day. The snapshot's `generated_at` is hand-coded `"2026-05-10"` (a string literal) so it's stable, but the gap pack's `generated` field will drift.

This breaks the documented "deterministic — same hints produce byte-identical output" contract that `AutoGenericStencilGeneratorTest::test_build_is_deterministic_for_same_hints` enforces at the generator level. The seed pack is a step removed from that test (the JSON is a derived artifact, not the generator output) so no test fails — but a CI re-run that re-generates seed packs would dirty the working tree.

**Fix:** Either pin the date at script-author-time or hash-fingerprint the content:

```php
// Option A — pin to the script author's date, update manually on intentional regen:
'generated' => '2026-05-10',

// Option B — fingerprint instead of date:
'generated_fingerprint' => substr(sha1(json_encode($stencils)), 0, 12),
```

This is purely a convenience cleanup — the seed pack is not regenerated as part of any automated workflow.

---

_Reviewed: 2026-05-10_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
