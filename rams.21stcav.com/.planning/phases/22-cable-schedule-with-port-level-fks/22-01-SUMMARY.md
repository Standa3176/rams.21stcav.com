---
phase: 22-cable-schedule-with-port-level-fks
plan: 01
subsystem: cable-schedule
tags: [cable, schema, eloquent, config, foundation, v2.0]
requires: []
provides:
  - cable_schedule_items_port_fk_columns
  - cable_schedule_item_belongsto_relations
  - cable_compatibility_config
  - cable_connector_compatibility_service
affects:
  - app/Models/CableScheduleItem.php
tech-stack:
  added: []
  patterns:
    - anonymous-class-migration
    - foreignId-constrained-nullOnDelete
    - pure-function-service-over-config
    - schema-getindexes-laravel11
    - reflectionproperty-eager-load-guard
key-files:
  created:
    - database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php
    - config/cables.php
    - app/Services/Cable/CableConnectorCompatibilityService.php
    - tests/Feature/Cable/CableScheduleItemMigrationTest.php
    - tests/Unit/Models/CableScheduleItemRelationsTest.php
    - tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php
  modified:
    - app/Models/CableScheduleItem.php
decisions:
  - "nullOnDelete (not cascadeOnDelete) on all 4 FKs so deleting a Device clears the FK and preserves the cable row's text representation — same state as a never-FK'd legacy row"
  - "D-10 guard: CableScheduleItem::$with stays empty; class-level eager load of 4 belongsTo relations would force LEFT JOINs on every legacy NULL-FK row across XLSX export + bound-PDF + schematic generator read paths"
  - "Pitfall 4: empty/whitespace-only connector_type returns compatible with 'connector type not catalogued — assume compatible' — Tier 1.5 stencils (91/96 seeded) have empty ports until Phase 24 curation"
  - "Allowlist matches bidirectionally per A4 — HDMI↔DP works the same as DP↔HDMI; engineers extend via config edit, no code change"
  - "Generic column naming (Phase 21 D-09) — source_device_id / dest_port_id with no rams_/project_ prefix so the table ports cleanly to SCC post-merge"
metrics:
  duration: 7min
  tasks: 3
  files: 7
  completed: 2026-05-12
---

# Phase 22 Plan 01: Schema, Model, Config + Compatibility Service Summary

**One-liner:** Strictly-additive port-FK foundation — 5 nullable columns on `cable_schedule_items`, 4 `belongsTo` relations on the model, config-driven connector-compatibility allowlist + Phase 23 colour map, and a pure-function compat service. Zero v1.3 surface change.

## Tasks executed

| # | Task | Files | Commit | Tests |
|---|------|-------|--------|-------|
| 1 | Migration adding port FK columns + override note + port-pair index | `database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php`, `tests/Feature/Cable/CableScheduleItemMigrationTest.php` | `ddf12db` | 5 pass / 19 assertions |
| 2 | Extend CableScheduleItem with $fillable + 4 belongsTo relations | `app/Models/CableScheduleItem.php`, `tests/Unit/Models/CableScheduleItemRelationsTest.php` | `7c1c55b` | 7 pass / 31 assertions |
| 3 | config/cables.php + CableConnectorCompatibilityService | `config/cables.php`, `app/Services/Cable/CableConnectorCompatibilityService.php`, `tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php` | `040db26` | 12 pass / 27 assertions |

**Total new tests:** 24 (5 Feature + 7 + 12 Unit) — all green.

## Schema delta

`cable_schedule_items` now carries 5 new nullable columns + 1 index:

| Column | Type | FK target | On delete | Purpose |
|--------|------|-----------|-----------|---------|
| `source_device_id` | unsignedBigInteger nullable | `devices.id` | `nullOnDelete` | DRAW-37 source-side device link |
| `source_port_id` | unsignedBigInteger nullable | `device_ports.id` | `nullOnDelete` | DRAW-37 source port link |
| `dest_device_id` | unsignedBigInteger nullable | `devices.id` | `nullOnDelete` | DRAW-37 dest-side device link |
| `dest_port_id` | unsignedBigInteger nullable | `device_ports.id` | `nullOnDelete` | DRAW-37 dest port link |
| `connector_override_note` | text nullable | — | — | Engineer override reason when connectors mismatch (DRAW-39) |

Plus index `cable_schedule_items_port_pair_idx` on `(source_port_id, dest_port_id)` for Phase 23's renderer port-pair lookup queries.

Migration is reversible via `dropConstrainedForeignId` × 4 + `dropColumn('connector_override_note')` + `dropIndex` in `down()` — verified.

## Model contract

`CableScheduleItem::$fillable` now whitelists **14 keys** (9 originals + 5 Phase 22 additions). 4 new `belongsTo` relations:

```php
public function sourceDevice(): BelongsTo  // devices,  source_device_id
public function sourcePort():   BelongsTo  // ports,    source_port_id
public function destDevice():   BelongsTo  // devices,  dest_device_id
public function destPort():     BelongsTo  // ports,    dest_port_id
```

**No `$with` property** — D-10 guard verified by reflection in `test_with_property_is_empty_to_prevent_eager_load_regression`. Plan 22-02's picker page will eager-load AT THE CALL SITE only (`$schedule->load('items.sourcePort')`).

## Compatibility matrix (config/cables.php)

`compatibility_aliases` ships with 4 bidirectional pairs:

| From | To | Note |
|------|-----|------|
| hdmi | dp | HDMI ↔ DisplayPort via active adapter |
| usb-c | thunderbolt | USB-C ↔ Thunderbolt 3/4 backwards-compatible |
| rj45 | sfp-plus | RJ45 ↔ SFP+ via SFP module |
| usb-c | hdmi | USB-C → HDMI via DisplayPort Alt Mode adapter |

`signal_type_colours` ships 8 keys (audio/video/control/network/usb/speaker/power/unknown) — same hex values as `config/drawings.php` `signal_colours` for v1.3↔Phase 23 surface coherence. Plan 22-02's picker reads `compatibility_aliases` client-side via `@js(config('cables.compatibility_aliases'))`; Phase 23's renderer reads `signal_type_colours` for port edge colouring.

## Compatibility service contract

```php
CableConnectorCompatibilityService::check(string $src, string $dst): array{compatible: bool, reason: ?string}
```

Behavioural matrix (locked by 12 unit tests):

| Inputs | Result | Reason |
|--------|--------|--------|
| `('hdmi', 'hdmi')` | compatible | `null` |
| `('HDMI', 'hdmi')` | compatible | `null` (case-insensitive trim) |
| `('hdmi', 'dp')` | compatible | "HDMI ↔ DisplayPort via active adapter" |
| `('dp', 'hdmi')` | compatible | "HDMI ↔ DisplayPort via active adapter" (bidirectional, A4) |
| `('hdmi', 'rj45')` | **incompatible** | "Connector mismatch: hdmi → rj45" |
| `('', 'hdmi')` | compatible | "connector type not catalogued — assume compatible" (Pitfall 4) |
| `('   ', 'hdmi')` | compatible | same (whitespace coalesces to empty) |

## Verification

### Wave-gate

```
php artisan test --filter='Cable|Connector'
→ 41 passed, 1 skipped (pre-existing D2 binary skip on dev), 142 assertions, 12.77s
```

### D-10 cross-check (v1.3 surfaces must NOT change)

| File | `git diff HEAD~3 HEAD` |
|------|----------------------|
| `app/Services/Drawings/SchematicGeneratorService.php` | empty ✓ |
| `app/Services/Drawings/SchematicD2SourceBuilder.php` | empty ✓ |
| `app/Services/Drawings/DrawingDataResolverService.php` | empty ✓ |
| `app/Services/CableScheduleXlsxService.php` | empty ✓ |
| `app/Services/CableScheduleGeneratorService.php` | empty ✓ |

All five v1.3 schematic + cable surfaces unchanged. D-10 invariant satisfied.

### Migration roundtrip

```
php artisan migrate:fresh --env=testing      → DONE (244ms for 22-01 migration)
php artisan migrate:rollback --step=1        → DONE (308ms — down() runs cleanly)
php artisan migrate                          → DONE (re-applied 275ms)
```

### Config visibility

```
php artisan config:show cables.compatibility_aliases
→ 4 entries (HDMI↔DP, USB-C↔Thunderbolt, RJ45↔SFP+, USB-C↔HDMI) returned with from/to/note keys.
```

## Deviations from Plan

**None of substance.** Two minor mechanical adjustments inside test fixtures:

1. **Task 1 test — Device fixture needed `description`** (Rule 3 — blocking issue)
   - **Found during:** Task 1 GREEN run
   - **Issue:** `Device::create([...])` failed with `NOT NULL constraint failed: devices.description` on SQLite when assembling the test fixture for `test_device_delete_sets_fk_null`.
   - **Fix:** Added `'description' => 'Crestron HD-MD-400 HDMI Multiformat Receiver'` to the Device fixture. No production code change.
   - **Files modified:** `tests/Feature/Cable/CableScheduleItemMigrationTest.php`
   - **Commit:** `ddf12db` (same commit)

2. **Task 1 test — `forceFill` over `create` for new FK columns** (Rule 3 — test ordering)
   - **Found during:** Task 1 GREEN run
   - **Issue:** `CableScheduleItem::create([..., 'source_device_id' => ...])` silently dropped the new key because Task 2's `$fillable` whitelist hadn't landed yet. The test for `nullOnDelete` and the long-text override-note test both depended on the column reaching the DB.
   - **Fix:** Swapped `create()` for `(new CableScheduleItem)->forceFill([...])->save()` in the two affected tests. Explicit comment notes that the migration test validates the schema migration alone; Task 2 covers the fillable contract end-to-end.
   - **Files modified:** `tests/Feature/Cable/CableScheduleItemMigrationTest.php`
   - **Commit:** `ddf12db` (same commit)

Both deviations are test-fixture mechanics, not behavioural changes. Neither affected production code or contract surface.

## Files for live deploy

```
database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php   (NEW — run `php artisan migrate` on live AFTER upload)
app/Models/CableScheduleItem.php                                                  (MODIFIED — 5 new fillable keys + 4 belongsTo)
config/cables.php                                                                 (NEW)
app/Services/Cable/CableConnectorCompatibilityService.php                         (NEW)
```

After upload, run on live:
```bash
php artisan migrate --force
php artisan config:clear
```

**No view rebuilds, no Composer changes, no npm changes.** Zero new dependencies.

## Threat surface scan

No new endpoints, auth paths, or trust boundaries added by Plan 22-01. T-22-A1 (mass-assignment via picker hidden fields) is mitigated by `$fillable` whitelist and proved by the relation test `test_unknown_keys_are_dropped_by_fillable`.

T-22-A2..T-22-A6 (XSS via override note, CSRF, cross-project FK injection, SQL injection on backfill arg, backfill writes wrong tenant) are all owned by Plans 22-02 and 22-03 — out of this plan's scope.

## Next plan readiness

Plan 22-01 unblocks both downstream plans:

- **Plan 22-02 (picker UI + update handler)** — can now bind the picker modal to the new FK columns, eager-load via `$schedule->load('items.sourcePort', 'items.destPort')` AT THE CALL SITE only, and inject `config('cables.compatibility_aliases')` into Alpine via `@js(...)` for client-side filtering. Server-side validation rules can add `exists:devices,id` + `exists:device_ports,id` on the 4 new keys.
- **Plan 22-03 (backfill command)** — can call `CableScheduleItem::sourceDevice()` to verify deterministic resolution and write FKs via the same fillable whitelist as the picker.

## Self-Check: PASSED

Files exist:
- FOUND: database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php
- FOUND: app/Models/CableScheduleItem.php
- FOUND: config/cables.php
- FOUND: app/Services/Cable/CableConnectorCompatibilityService.php
- FOUND: tests/Feature/Cable/CableScheduleItemMigrationTest.php
- FOUND: tests/Unit/Models/CableScheduleItemRelationsTest.php
- FOUND: tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php

Commits exist:
- FOUND: ddf12db (Task 1 migration)
- FOUND: 7c1c55b (Task 2 model)
- FOUND: 040db26 (Task 3 config + service)
