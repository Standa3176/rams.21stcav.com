---
phase: 22-cable-schedule-with-port-level-fks
plan: 03
subsystem: cable-schedule
tags: [cable, backfill, artisan, deterministic, service, security, v2.0]
requires:
  - cable_schedule_items_port_fk_columns
  - cable_schedule_item_belongsto_relations
  - cable_compatibility_config
provides:
  - cable_port_fk_resolver_service
  - cables_backfill_port_fks_artisan
  - t22_a5_sql_injection_mitigation
  - t22_a6_wrong_tenant_write_mitigation
affects:
  - none (pure additions; CableScheduleGeneratorService UNTOUCHED per D-LOCK)
tech-stack:
  added: []
  patterns:
    - pure-function-service-collection-input
    - dry-run-default-apply-opt-in-artisan
    - per-project-eager-load-inside-iteration
    - setRelation-without-native-belongsTo
    - int-cast-on-cli-arg-sql-injection-mitigation
    - tdd-red-green-per-task
key-files:
  created:
    - app/Services/Cable/CablePortFkResolverService.php
    - app/Console/Commands/BackfillCablePortFksCommand.php
    - tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php
    - tests/Feature/Cable/BackfillCablePortFksCommandTest.php
  modified: []
decisions:
  - "WRITES happen ONLY on overall match='matched' — the resolver may expose partial source/dest diagnostics on 'ambiguous' but the COMMAND must not act on partial data (D-LOCK + DRAW-41 — 'leaves nullable where ambiguous'). The if-branch in handle() is `if ($apply && $tag === 'matched')` with no else branch for partial writes."
  - "Stencil attachment via setRelation('stencil', $stencil) — Device has no native belongsTo(DeviceStencil) relation; stencils are looked up by NORMALISED part_number. The command pre-loads stencils per-project and attaches them so the resolver reads $device->stencil->ports inside the loop without N+1 DB hits."
  - "Per-project Device load inside the iteration is the T-22-A6 mitigation by construction — cross-tenant writes are physically impossible because Project A's devices are never loaded while iterating Project B's cable items."
  - "(int) cast on the {project?} arg + Eloquent parameterised bindings is the T-22-A5 SQL injection mitigation — `(int) '5; DROP TABLE devices;'` evaluates to 5 in PHP semantics, then Eloquent binds it as a query parameter."
  - "Strict matching with `strlen($candidate) >= 3` guard mitigates Pitfall 3 — prevents trivial 1-2 char manufacturer abbreviations from cross-matching unintended devices."
  - "CableScheduleGeneratorService UNTOUCHED — auto-fire on quote import explicitly deferred to v2.1 per CONTEXT D-LOCK. Engineers run the command manually with dry-run first."
metrics:
  duration: 12min
  tasks: 2
  files: 4
  completed: 2026-05-12
---

# Phase 22 Plan 03: Cable Port FK Backfill — Summary

**One-liner:** Pure-function deterministic resolver + dry-run-default artisan command that backfills `cable_schedule_items` port FKs from existing from/to_location text against the project's catalogued devices. Idempotent, per-project scoped, T-22-A5 / T-22-A6 mitigated, CableScheduleGeneratorService UNTOUCHED.

## Tasks executed

| # | Task | Files | Commit | Tests |
|---|------|-------|--------|-------|
| 1 | CablePortFkResolverService — pure deterministic matcher (RED → GREEN) | `app/Services/Cable/CablePortFkResolverService.php`, `tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php` | `52c2e18` (RED) + `ed02cdd` (GREEN) | 10 pass / 36 assertions |
| 2 | BackfillCablePortFksCommand — dry-run-default artisan (RED → GREEN) | `app/Console/Commands/BackfillCablePortFksCommand.php`, `tests/Feature/Cable/BackfillCablePortFksCommandTest.php` | `2fa1c65` (RED) + `a5d32f0` (GREEN) | 11 pass / 55 assertions |

**Total new tests:** 21 (10 unit + 11 feature) — all green. Full `--filter=Cable` suite: **73 pass + 4 env-skipped / 330 assertions** (no regression from Plan 22-02's 52 + 4 baseline → +21 new tests exactly accounted for).

## Resolver service contract

```php
CablePortFkResolverService::resolve(
    CableScheduleItem $item,
    iterable<Device>  $projectDevices,  // each Device pre-attached with stencil + ports
): array{
    match:            matched|ambiguous|no-device-match,
    source_device_id: ?int,
    source_port_id:   ?int,
    dest_device_id:   ?int,
    dest_port_id:     ?int,
    reason:           string,
}
```

**Algorithm (5 steps):**

1. Normalise `from_location` / `to_location` (lowercase, trim, collapse whitespace)
2. For each side, filter devices whose normalised `manufacturer model`, `manufacturer part_no`, bare `model`, or bare `part_no` appears in the text as a substring (>= 3 chars — Pitfall 3 trivial-collision guard)
3. Map `cable_type` → connector hint via `CABLE_TYPE_TO_CONNECTOR`:

   | Cable text | Connector hint |
   |------------|----------------|
   | HDMI | hdmi |
   | CAT6 / CAT6a / CAT5e / UTP | rj45 |
   | USB / USB-C | usb-c |
   | XLR | xlr |
   | RS232 / RS-232 | rs232 |
   | 3.5mm | 3.5mm |
   | Speakon | speakon |
   | PHX / Phoenix | phoenix |
   | DP | dp |
   | (empty / other) | (no hint — any non-empty connector port matches) |

4. Filter the matched device's stencil ports by the connector hint. Pitfall 4: empty connector_type ports are excluded as 'unknown' regardless of whether a hint exists — Tier 1.5 stencils (91/96 per Phase 21 P02 SUMMARY) explicitly fail to match.
5. Aggregate: both sides matched → `matched`; both `no-device-match` → `no-device-match`; anything else → `ambiguous` with partial diagnostics in the return shape.

**Decision matrix (locked by 10 unit tests):**

| Source state | Dest state | Overall match | Resolver returns |
|--------------|-----------|---------------|------------------|
| matched | matched | `matched` | both device_id + port_id ids set |
| matched | ambiguous | `ambiguous` | source_*_id set; dest_*_id NULL (partial diagnostics for the COMMAND's log) |
| ambiguous | anything | `ambiguous` | source_*_id NULL |
| no-device-match | no-device-match | `no-device-match` | all 4 ids NULL |
| anything else mixed | … | `ambiguous` | per-side: ids only populated on that side's `matched` outcome |

**Purity:** zero DB writes, zero side effects. Locked by `test_resolver_is_pure_no_db_writes` — 3× `resolve()` leaves `devices` + `device_ports` + `device_stencils` + `cable_schedule_items` row counts unchanged + the input item's FK columns stay NULL.

## Command contract

`php artisan cables:backfill-port-fks {project?} {--apply}`

**Signature precedent:** mirrors `RamsRefreshComplianceCommand` lines 39-41 but FLIPS the default — dry-run is implicit, `--apply` opts in to writes. Safer because backfill writes span N rows × M projects rather than a single document.

**Usage:**

```bash
php artisan cables:backfill-port-fks            # dry-run, all projects
php artisan cables:backfill-port-fks --apply    # write, all projects
php artisan cables:backfill-port-fks 5          # dry-run, project 5
php artisan cables:backfill-port-fks 5 --apply  # write, project 5
```

**Per-row outcomes (CONTEXT.md D-LOCK):**

| Category | Trigger | Action on `--apply` |
|----------|---------|---------------------|
| `matched` | Resolver returns `match === 'matched'` | All 4 FKs written atomically inside `DB::transaction` |
| `ambiguous` | Resolver returns `match === 'ambiguous'` (any side ambiguous) | **ALL 4 FKs left NULL** — NO partial writes per D-LOCK + DRAW-41 |
| `no-device-match` | Resolver returns `match === 'no-device-match'` | All 4 FKs left NULL |
| `already-set` | Item already has `source_device_id` OR `dest_device_id` populated | Resolver NOT called, row UNTOUCHED (idempotent) |

**Output format:**

```
[DRY RUN] cables:backfill-port-fks — pass --apply to persist.
  #1 — matched: both sides resolved deterministically
  #2 — ambiguous: source: text 'X' matched multiple devices (5, 7); dest: ...
  #3 — no-device-match: neither side text resolved to a catalogued device

Summary:
  matched: 1  |  ambiguous: 1  |  no-device-match: 1  |  already-set: 0  |  wrote: 0
```

On `--apply` the header line becomes `cables:backfill-port-fks — APPLYING writes.` (no `[DRY RUN]` tag) and `wrote` reflects the number of rows persisted.

**Stencil attachment pattern:** Device has no native `belongsTo(DeviceStencil)` relation — stencils are looked up by *normalised* part_number, not FK. The command pre-loads stencils per-project via `DeviceStencil::whereIn('part_number', $partNumbers)->with('ports')` and attaches them to each Device via `setRelation('stencil', $stencil)`. The resolver then reads `$device->stencil->ports` inside the per-row loop without any further DB hits.

## Security mitigations

### T-22-A5 — SQL injection via `--project` arg

```php
$projectId = $projectArg !== null ? (int) $projectArg : null;
```

PHP's int cast silently truncates `"5; DROP TABLE devices;"` to integer `5`. Eloquent's `where('project_id', $projectId)` uses PDO parameterised binding. The malicious payload never reaches raw SQL.

**Test:** `test_sql_injection_via_project_arg_neutralised_t22_a5` invokes the command with the malicious arg + `--apply`, then asserts `Schema::hasTable('devices')` AND `Schema::hasTable('cable_schedule_items')` both return true.

### T-22-A6 — Wrong-tenant write impossible by construction

```php
$devicesByProject[$scheduleProjectId] = $this->loadProjectDevicesWithStencils($scheduleProjectId);
// $this->loadProjectDevicesWithStencils → Device::where('project_id', $projectId)->get()
```

The matcher loads ONLY devices for each cable item's `cable_schedule.project_id`. Cross-project text matches are physically impossible — even when Project A has a Crestron device whose name happens to match Project B's cable text, the matcher never sees Project A's device while iterating Project B's items.

**Test:** `test_cross_project_match_impossible_by_construction_t22_a6` builds Project A with the only Crestron device + Project B with a cable item referencing "Crestron HD-MD-400" text but no devices. Running the command with `--apply` (no `--project` arg → iterates ALL) leaves Project B's row entirely NULL.

## D-LOCK verification — CableScheduleGeneratorService UNTOUCHED

```bash
git diff HEAD~4 HEAD -- app/Services/CableScheduleGeneratorService.php
→ empty (zero lines)

git diff HEAD~4 HEAD -- app/Services/Drawings/
→ empty (zero lines — v1.3 surface invariant preserved across Plan 22-03 too)
```

Auto-fire on quote import is explicitly deferred to v2.1 polish per CONTEXT.md `<deferred>`. Engineers manually trigger this command with dry-run first.

## Critical invariant: ambiguous → ALL 4 FKs NULL

This is the locked decision from CONTEXT.md D-LOCK + DRAW-41 acceptance ("leaves nullable where ambiguous"). The plan's RED test 10 + the command's feature test `test_ambiguous_overall_leaves_all_four_fks_null` lock it explicitly:

**Fixture:**
- Source: ONE Crestron HD-MD-400 with ONE HDMI port → resolver reports source matched, source_device_id + source_port_id set
- Dest: ONE Samsung QM65 with TWO HDMI ports → resolver reports dest ambiguous
- Overall match: `ambiguous`

**Expectation:** after `--apply`, the row's source_device_id, source_port_id, dest_device_id, dest_port_id are ALL four NULL — even though the resolver's return shape carried source_device_id+source_port_id values for diagnostic logging.

**Implementation:** the command's only write branch is:

```php
if ($apply && $tag === 'matched') {
    DB::transaction(function () use ($item, $decision) {
        $item->update([...all 4 FKs...]);
    });
    $summary['wrote']++;
}
```

No else branch for partial writes. The resolver's partial data is consumed only via `$decision['reason']` for the log line.

## Verification

### Wave 2 gate

```
php artisan test --filter=CablePortFkResolverServiceTest
→ 10 pass / 36 assertions / 3.82s

php artisan test --filter=BackfillCablePortFksCommandTest
→ 11 pass / 55 assertions / 4.04s

php artisan test --filter=Cable
→ 73 pass + 4 env-skipped / 330 assertions / 9.68s
```

### Smoke test (live dev DB)

```bash
php artisan list | grep cables
→   cables:backfill-port-fks        Resolve and populate port-level FKs ...

php artisan cables:backfill-port-fks --help
→ Shows {project?} arg + --apply flag with descriptions

php artisan cables:backfill-port-fks
→ [DRY RUN] cables:backfill-port-fks — pass --apply to persist.
→ No cable_schedule_items found.
```

### T-22-A5 / T-22-A6 gate

```
php artisan test --filter=test_sql_injection_via_project_arg_neutralised_t22_a5
→ 1 pass

php artisan test --filter=test_cross_project_match_impossible_by_construction_t22_a6
→ 1 pass
```

### D-LOCK auto-fire-deferred gate

```
git diff HEAD~4 HEAD -- app/Services/CableScheduleGeneratorService.php
→ empty (zero changes)
```

## Deviations from Plan

**1. [Rule 3 — test fixture mechanic] Two-device ambiguous test shares stencil via attachStencilToDevice**
- **Found during:** Task 1 GREEN initial run
- **Issue:** `test_two_matching_devices_returns_ambiguous` initially called `makeDeviceWithSinglePort` twice for the same part_no, triggering `UNIQUE constraint failed: device_stencils.part_number` (stencils are catalogued by part_number — cross-project shared per Plan 21).
- **Fix:** Created the second Device manually + looked up the existing DeviceStencil via `DeviceStencil::where('part_number', ...)->first()` + attached via `attachStencilToDevice` helper. This matches the production semantics: two physical units of the same model share a stencil.
- **Files modified:** `tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php`
- **Commit:** `ed02cdd` (Task 1 GREEN — same commit as the fixture fix)

**2. [Rule 3 — test fixture mechanic] Project-scoping test fixture uses firstOrCreate for shared stencils**
- **Found during:** Task 2 GREEN initial run
- **Issue:** `test_project_arg_scopes_backfill` calls `makeProjectWithThreeItems` twice (Project A + Project B). The second call tried to `DeviceStencil::create(['part_number' => 'dm-nvx-360', ...])` for the ambiguous device → UNIQUE constraint violation.
- **Fix:** Swapped `DeviceStencil::create(...)` for `DeviceStencil::firstOrCreate([...])` in the fixture helper. The ports are then created only when the stencil is fresh (`$ambiguousStencil->ports()->count() === 0` guard). Mirrors the same pattern in `makeCatalogedDevice`. Reflects production semantics: stencils are catalog-wide; both projects' devices reuse the same DM-NVX-360 stencil row.
- **Files modified:** `tests/Feature/Cable/BackfillCablePortFksCommandTest.php`
- **Commit:** `a5d32f0` (Task 2 GREEN — same commit as the fixture fix)

Both deviations are test-fixture mechanics that align tests with production catalog-sharing semantics. Zero production code changes from either.

## Files for live deploy

```
app/Services/Cable/CablePortFkResolverService.php       (NEW — pure-function service)
app/Console/Commands/BackfillCablePortFksCommand.php    (NEW — artisan command, auto-registered)
```

After upload, run on live (no migration / no view / no Composer / no npm changes):

```bash
# Smoke the command is registered + visible:
php artisan list | grep cables

# Run dry-run against every project (logs decisions; writes nothing):
php artisan cables:backfill-port-fks

# Review the report. If happy, apply the writes:
php artisan cables:backfill-port-fks --apply

# Idempotency check — a second --apply must report already-set + wrote: 0:
php artisan cables:backfill-port-fks --apply
```

Per-project scoping is also supported:

```bash
php artisan cables:backfill-port-fks 123 --apply     # only project 123
```

## Backfill usage notes (engineer-facing)

1. **Always dry-run first.** The default behaviour is read-only — review the per-row output for surprises before adding `--apply`.
2. **Already-set rows are sticky.** If a row has `source_device_id` or `dest_device_id` set from the picker UI (Plan 22-02), the backfill skips it even on `--apply`. No overwrite. To re-backfill, use the picker UI's Clear button to NULL the FKs first.
3. **Ambiguous rows stay NULL.** When the resolver can't deterministically pick a single port (e.g. device with 2 HDMI ports + cable_type=HDMI; or 2 devices in the project with the same manufacturer+model), the row stays untouched. Engineer uses the picker UI to manually pick the right port.
4. **Tier 1.5 stencils don't backfill.** Most seeded stencils (91/96) have no ports catalogued (Phase 21 P02 SUMMARY). The backfill correctly reports `no-device-match` for those rows; the picker UI lets the engineer continue with text-only entries. Phase 24's curation UI closes this gap.

## Threat surface scan

No new endpoints, no new HTTP routes, no new trust boundaries. The command is CLI-only (admin-by-convention per RESEARCH.md §"Security Domain"). T-22-A5 + T-22-A6 are both mitigated and locked by feature tests.

## Next plan readiness

Plan 22-03 closes Phase 22:

- **DRAW-37** ✓ (schema + model — Plan 22-01)
- **DRAW-38** ✓ (picker UI + update handler — Plan 22-02)
- **DRAW-39** ✓ (compat warning + override note — Plans 22-01 + 22-02)
- **DRAW-40** ✓ (auto-derive deterministic port FKs from `from_location` / `to_location` — this plan)
- **DRAW-41** ✓ (one-shot backfill command with per-row categorised report — this plan)

Phase 23 (port-to-port renderer) can now consume populated FKs from rows the picker or backfill has resolved, AND gracefully fall back to text-only rendering for rows where backfill left FKs NULL (ambiguous + no-device-match + Tier 1.5).

## Self-Check: PASSED

Files exist:
- FOUND: app/Services/Cable/CablePortFkResolverService.php
- FOUND: app/Console/Commands/BackfillCablePortFksCommand.php
- FOUND: tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php
- FOUND: tests/Feature/Cable/BackfillCablePortFksCommandTest.php

Commits exist:
- FOUND: 52c2e18 (Task 1 RED — failing resolver tests)
- FOUND: ed02cdd (Task 1 GREEN — resolver service + fixture fix)
- FOUND: 2fa1c65 (Task 2 RED — failing command tests)
- FOUND: a5d32f0 (Task 2 GREEN — command + fixture fix)

D-LOCK verified:
- ZERO diff: app/Services/CableScheduleGeneratorService.php
- ZERO diff: app/Services/Drawings/* (v1.3 surface invariant)

Acceptance criteria:
- `php artisan list | grep cables` → cables:backfill-port-fks shown
- `php artisan cables:backfill-port-fks --help` → project arg + --apply flag visible
- `php artisan cables:backfill-port-fks` (no args) → [DRY RUN] tag, zero writes
- `php artisan cables:backfill-port-fks --apply` → writes matched rows; ambiguous stays NULL
- Re-running with `--apply` → already-set: 1, wrote: 0 (idempotent)
- All 21 new tests pass / 91 assertions
- T-22-A5 + T-22-A6 mitigations locked by feature tests
