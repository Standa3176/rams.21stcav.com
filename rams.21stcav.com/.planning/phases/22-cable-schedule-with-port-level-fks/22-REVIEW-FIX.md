---
phase: 22-cable-schedule-with-port-level-fks
fixed_at: 2026-05-12T00:00:00Z
review_path: .planning/phases/22-cable-schedule-with-port-level-fks/22-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 22: Code Review Fix Report

**Fixed at:** 2026-05-12
**Source review:** `.planning/phases/22-cable-schedule-with-port-level-fks/22-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope (critical + warning): 4
- Fixed: 4
- Skipped: 0
- Info findings (IN-01..IN-05): out of scope per `fix_scope=critical_warning`

**Verification:**
- All commits passed Tier 1 (re-read) and Tier 2 (`php -l` syntax check) verification.
- Cable test suite: 74 passed / 4 skipped (preexisting PhpSpreadsheet + D2 binary unavailability in local env), 337 assertions.
  - Baseline was 74 tests / 331 assertions. The new WR-01 test added 6 assertions (337 - 331 = 6). No regressions.
- Schematic test suite: 11 passed / 2 skipped (preexisting D2 binary unavailability), 37 assertions. No regressions.
- All D-10 v1.3 surface files untouched (CableScheduleXlsxService, CableScheduleGeneratorService, SchematicGeneratorService, SchematicD2SourceBuilder, DrawingDataResolverService) — verified by `test_v13_surface_files_have_zero_phase22_column_references` still green.

## Fixed Issues

### WR-01: Cross-project guard skipped when schedule has NULL project_id — latent bypass

**Files modified:** `app/Http/Controllers/CableScheduleController.php`, `tests/Feature/Cable/CableScheduleCrossProjectFkInjectionTest.php`
**Commit:** `a892aa2`
**Applied fix:** Restructured the T-22-A4 guard as an `if/else` on `$cableSchedule->project_id`. The NULL-project branch (legacy standalone schedules) now scans the payload for any non-null `source_device_id` or `dest_device_id` and rejects with 422 + named `Log::warning('CableScheduleController: port FKs submitted on legacy NULL-project schedule', [...])`. Error key kept as `items.0.source_device_id` per REVIEW.md guidance — this case is a configuration mismatch, not a per-row violation, and the picker UI already gates this client-side. Added new feature test `test_legacy_schedule_rejects_port_fks_in_payload` that pre-seeds a text-only item, posts a crafted device FK, asserts 422 + session error on `items.0.source_device_id`, and asserts the pre-seeded row survives (proves guard fires before `items()->delete()`).

### WR-02: 422 error always keyed under `items.0.source_device_id`, even when the offender is `dest_device_id`

**Files modified:** `app/Http/Controllers/CableScheduleController.php`, `tests/Feature/Cable/CableScheduleCrossProjectFkInjectionTest.php`
**Commit:** `009a0e9`
**Applied fix:** Replaced the flat-list collapsed walk with two per-side maps (`$sourceSubmissions`, `$destSubmissions`), each carrying `['key' => "items.{N}.{side}_device_id", 'id' => $id]`. After querying `$offendingDeviceIds` via `whereIn('id', ...) AND project_id != $cableSchedule->project_id` plucked back as a list, the error message foreach walks each side and only keys messages on the actual offending side(s). Log context extended with `offending_device_ids` for forensic clarity. Updated test `test_cross_project_dest_device_returns_422_t22_a4` to expect `items.0.dest_device_id` (the corrected per-side key) and explicitly assert that `items.0.source_device_id` is NOT in the error bag. Source-side test (`test_cross_project_source_device_returns_422_t22_a4`) retains its `items.0.source_device_id` expectation — unchanged.

### WR-03: `optional($d->stencil)?->ports` chains null-safe over an already-null-safe `optional()`

**Files modified:** `app/Http/Controllers/CableScheduleController.php`
**Commit:** `b76b38f`
**Applied fix:** Replaced `optional($d->stencil)?->ports ?? collect()` with `$d->stencil?->ports ?? collect()` at line 152. Single null-safe access, matches resolver service style (`CablePortFkResolverService.php:199`). No behavioural change.

### WR-04: `->filter()` with no callback also drops integer `0` and bool `false` — fine today, fragile if `Device::$primaryKey` ever changes

**Files modified:** `app/Http/Controllers/CableScheduleController.php`
**Commit:** `86ca1f4`
**Applied fix:** Replaced callback-less `->filter()` with explicit `->filter(fn ($id) => $id !== null && $id !== '')`. Added inline comment explaining the future-proofing intent (non-int Device PK post-SCC merge). Note: WR-02's fix later restructured this same block into per-side `$sourceSubmissions` / `$destSubmissions` maps that use the same explicit predicate — so the explicit-predicate intent is preserved in the final state.

## Skipped Issues

None — all 4 warnings were fixed cleanly.

## Out-of-Scope Findings (Info)

Per `fix_scope=critical_warning`, the following Info findings were NOT addressed in this iteration:

- IN-01: ASCII art divider convention non-uniform in `CablePortFkResolverService.php` — style nit, optional.
- IN-02: Hardcoded teal hex `#1B7A7A` repeated in Blade files instead of a CSS class — non-blocking refactor.
- IN-03: Picker modal warning banner uses `⚠` emoji — minor convention bend.
- IN-04: Resolver `reason` strings interpolate raw `$text` — low-probability log-content concern.
- IN-05: `BackfillCablePortFksCommand` test naming uses inconsistent `_t22_aN` suffix — minor cohesion call.

These can be picked up in a future Info-pass iteration if desired.

---

_Fixed: 2026-05-12_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
