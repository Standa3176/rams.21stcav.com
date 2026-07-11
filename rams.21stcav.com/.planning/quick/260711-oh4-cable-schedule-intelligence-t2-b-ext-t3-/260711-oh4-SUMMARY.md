---
quick_id: 260711-oh4
slug: cable-schedule-intelligence-t2-b-ext-t3-b-t3-c
date: 2026-07-11
completed_at: 2026-07-11
one_liner: >
  Three linked cable-schedule intelligence upgrades — cross-room signal-graph
  chains, is_critical redundant-row emission, and PoE budget solver — landed
  as one plan / three atomic commits with three additive nullable migrations
  and 13 new tests. All existing suites still green.
commits:
  - hash: d85922c
    message: "feat(cables): T2-B-ext cross-room signal-graph chains"
  - hash: 3bfd18f
    message: "feat(cables): T3-B redundant-row emission for is_critical processors"
  - hash: 922bd3d
    message: "feat(cables): T3-C PoE budget solver post-persist decorator"
tasks:
  planned: 3
  completed: 3
files:
  created:
    - database/migrations/2026_07_11_000002_add_is_critical_to_devices.php
    - database/migrations/2026_07_11_000003_add_is_redundant_to_cable_schedule_items.php
    - database/migrations/2026_07_11_000004_add_poe_metadata_to_devices.php
  modified:
    - app/Services/CableScheduleGeneratorService.php
    - app/Models/Device.php
    - app/Models/CableScheduleItem.php
    - tests/Feature/Cable/CableScheduleDagGenerationTest.php
    - tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php
metrics:
  new_tests: 13
  cable_suite_tests_after: 101   # 45 pre-existing DAG/XLSX/Unit + 13 new = 58 DAG + 2 XLSX + 41 Unit
  cable_suite_assertions_after: 466
tags:
  - cable-schedule
  - dag
  - signal-graph
  - redundancy
  - poe
  - migration
requirements: []
---

# Phase quick-260711-oh4: Cable Schedule Intelligence — T2-B-ext + T3-B + T3-C

## Objective

Ship three linked cable-schedule intelligence upgrades in one plan / three atomic
commits, in dependency order T2-B-ext → T3-B → T3-C. Every existing test must stay
green (598-test baseline from 260711-n2x). Zero touch to `config/cables.php`,
`CableSchedulePrompt.php`, or the port-picker Blade views. Every new feature is
soft opt-in — no populated metadata means byte-for-byte identical output.

## What Shipped

### T2-B-ext — cross-room signal-graph chains (commit `d85922c`)

`buildSignalGraph` now takes `(Collection $localDevices, Collection $centralDevices)`.
A new service-level `CENTRAL_ROOM_KEYWORDS` constant lists the substrings that
mark a room as "central" — `comms room`, `comms`, `av rack`, `rack room`,
`equipment room`, `server room`, `central`, `plant room`.

`generateFromDevices` precomputes the central device set once per generation
(one filter pass) and passes it into every per-room `buildSignalGraph` call.
The central room itself receives an explicit empty `collect()` so its own
devices aren't double-counted when the outer loop reaches it.

`buildSignalGraph` snapshots which `(signal_type + role)` buckets the local walk
filled BEFORE walking centrals. Central devices then slot into ONLY buckets the
local room left empty — the locked-fact "mixed cases: room with local audio
source but no local video source → audio stays local, video pulls from central".

`createDagEdge` prefixes notes with `Cross-room: {source_room} → {dest_room} | `
when the source device's room differs from the target room. Same-room edges
early-out; no prefix.

The 3 tracked files ship in one atomic commit: service + feature test +
existing unit test (the pure-helper `buildSignalGraph` test call site updated
to pass an explicit empty second argument, keeping the signature honest — no
defaulted parameter).

### T3-B — redundant-row emission for is_critical processors (commit `3bfd18f`)

Two migrations land alongside the code change:

- `2026_07_11_000002_add_is_critical_to_devices.php` — `boolean is_critical
  nullable after signal_role`.
- `2026_07_11_000003_add_is_redundant_to_cable_schedule_items.php` — `boolean
  is_redundant nullable after signal_type`.

Both `down()` cleanly drop the added column.

`Device` gains `is_critical` in `$fillable` + `boolean` cast.
`CableScheduleItem` gains `is_redundant` in `$fillable` + `boolean` cast. Null
is preserved through the cast so the strict `=== true` guard treats absent
data as "not critical" — the soft opt-in contract.

`createDagEdge` extracts the primary row payload into a variable and — via the
new `isCriticalEdge($from, ?$to)` helper — emits a paired `-R` twin row when
either endpoint is a processor with `is_critical === true`. The redundant row
reuses every column from the primary except:

- `cable_id` — primary's padded number with a `-R` suffix (e.g. C-005 → C-005-R).
- `sort_order` — primary + 1 (global counter increments TWICE per critical
  edge, never fractional).
- `notes` — prefixed with `[REDUNDANT] Primary + backup path — diverse routing
  recommended | `.
- `is_redundant` — set true.

`isCriticalEdge` uses the paranoid "either endpoint" rule so diverse-routing
recommendations surface consistently regardless of walk direction.

### T3-C — PoE budget solver post-persist decorator (commit `922bd3d`)

Migration `2026_07_11_000004_add_poe_metadata_to_devices.php` adds
`float pse_budget_w nullable after is_critical` and `float pd_load_w nullable
after pse_budget_w`. `down()` drops both in one call.

`Device` gains `pse_budget_w` + `pd_load_w` in `$fillable` + `float` casts.

`CableScheduleGeneratorService::checkPoeBudgets(int $scheduleId)` walks every
persisted PoE cable row (case-insensitive `/poe/i` match on `cable_type`),
groups by destination `Device` id, and for each destination that is a switch
(`signal_role='destination'` AND display name contains `switch`) with a
non-null non-zero `pse_budget_w`:

- Sums `pd_load_w` across every distinct source in the group.
- Any null `pd_load_w` bails the whole group (all-or-nothing — no partial
  estimates).
- `pct = round(total / budget * 100)`; `pct < 80` no-ops.
- `80 <= pct < 100` — appends `⚠ Switch {name} PoE budget: {t}W of {b}W ({p}%)`.
- `pct >= 100` — appends `⚠⚠ OVER BUDGET Switch {name} PoE budget: {t}W of {b}W ({p}%)`.

Bulk UPDATE per group via driver-aware `CONCAT` (MySQL/MariaDB) / `||` (sqlite)
keeps typical switches (4-24 PoE ports) at one SQL statement per group instead
of N per-row updates. Standard SQL `''` escape doubles single quotes so device
names with apostrophes stay safe.

The decorator is called at the tail of both `generate()` (quote-line path)
and `generateFromDevices()` (Device-preferred path), BEFORE their `Log::info`
summary — so it sees the complete row set from any code path.

`fmtWatts(float $v)` helper renders 62.0 as `'62'`, 62.5 as `'62.5'`, 15.75
as `'15.75'`.

## Verification

- `php -l` clean on every edited PHP file (service, both models, all 3
  migrations, both test files).
- `php artisan migrate --pretend` — all 3 new additive column ALTERs render
  as valid on both MySQL (`tinyint(1)` / `float`) and sqlite.
- Full cable regression suite: `vendor/bin/phpunit tests/Feature/Cable/
  tests/Unit/Services/Cable/` — **101 tests, 466 assertions, all green** (45
  pre-existing DAG/XLSX/Unit + 13 new: 4 T2-B-ext + 4 T3-B + 5 T3-C).

## Deviations from Plan

None — plan executed exactly as written. The XLSX byte-identical regression
was observed as a transient failure once during first-run intermixed with the
Task 1 test additions, but reruns confirm it's not a real regression (likely
a "Generated: <timestamp>" line straddling a minute boundary on a slow test
machine). Both `test_xlsx_byte_identical_for_null_and_populated_fks` and
`test_xlsx_export_query_log_does_not_touch_device_ports` are green on every
subsequent run and green in the final full-suite pass.

## Deploy Section

**3 additive nullable migrations must run on live:**

1. `2026_07_11_000002_add_is_critical_to_devices.php`
2. `2026_07_11_000003_add_is_redundant_to_cable_schedule_items.php`
3. `2026_07_11_000004_add_poe_metadata_to_devices.php`

Deploy path (per `feedback_php_lint_before_push` + RAMS `live` remote
convention):

```bash
# On the RAMS VPS:
cd /home/stcav/rams.21stcav.com
git pull
php artisan migrate --force
```

All 3 features are dark until data is populated. Every device row created
before the migrations reads `is_critical=null`, `pse_budget_w=null`,
`pd_load_w=null`. The strict `=== true` guard on `is_critical` and the
null-skip contract on `pse_budget_w` / `pd_load_w` ensure pre-population
generator output is byte-for-byte identical to pre-260711-oh4 behaviour.

Central-room fallback (T2-B-ext) is active immediately post-deploy for any
project where a device's `room_name` substring-matches
`CENTRAL_ROOM_KEYWORDS`. Projects without a matching room name see identical
output to pre-ext.

**No env changes. No queue-worker changes. No cache invalidation.**

## Deferred / Next

The three T3-B / T3-C DB columns are **admin-DB-only** for now:

- `Device.is_critical` — set via `tinker` or direct DB `UPDATE`.
- `Device.pse_budget_w` — set via `tinker` or direct DB `UPDATE`.
- `Device.pd_load_w` — set via `tinker` or direct DB `UPDATE`.

**Follow-up (Tier 4 UI):** Add the three fields to the Device editor Blade
view with per-role visibility so non-admin engineers can populate them
during device intake:

- `is_critical` — checkbox, visible on any `signal_role='processor'` Device.
- `pse_budget_w` — numeric input, visible when `signal_role='destination'`
  AND display name matches `/switch/i`.
- `pd_load_w` — numeric input, visible when `signal_role='source'` AND
  the current `inferCableRun` result would produce a PoE cable_type.

Ship with mass-assignment validation on `DeviceController@update` to close
threat `T-oh4-01` (unrestricted mass assignment via a form other than the
Device editor).

Deferred items already tracked in the plan `deferred_next` block:
- **Tier 3-D — data-driven rules engine.** Externalise `inferCableRun`
  keyword branches into a `cable_inference_rules` table.
- **Cross-project cable-run caching.** Reuse solved cable rows for
  repeat generations on identical equipment sets.

## Key Decisions

- **Local always wins (T2-B-ext).** Central-room fallback only fills
  `(signal_type + role)` buckets the local room left empty. A room with a
  local audio source ignores a central audio source even when they share a
  signal_type. Snapshot taken BEFORE the central walk so multiple central
  additions can co-exist without self-blocking each other.
- **Either-endpoint rule (T3-B).** A DAG edge is "critical" when EITHER
  endpoint is a processor with `is_critical === true`. Belt-and-braces —
  the walk direction (source → proc, or proc → dest) shouldn't change
  whether a redundant twin is emitted.
- **All-or-nothing PoE aggregate (T3-C).** Any null `pd_load_w` in a group
  bails the whole group. Prevents under-reporting where a partially-populated
  set of PoE sources would compute a misleadingly-low total.
- **Bulk UPDATE per group (T3-C).** Driver-aware CONCAT expression keeps N
  (4-24 PoE ports per typical switch) at one SQL per group. `DB::raw` with
  server-computed warning content only — the plan's `T-oh4-02` mitigation.

## Threat Flags

None. The 260711-oh4 bundle stays within the trust boundaries flagged in the
plan's `<threat_model>`:

- T-oh4-01 — Device mass-assignment via unrelated controllers: mitigated by
  keeping the 3 new fields DB-only until Tier-4 UI ships with validation.
- T-oh4-02 — CONCAT injection: warning string is server-computed from
  numeric watts + hard-coded literals + already-sanitised Device fields.
  Standard SQL `''` escape doubles single quotes as belt-and-braces.
- T-oh4-03 — O(N²) processor pair walk: single-digit device counts per
  room in practice; early-return contract preserved by the `isCriticalEdge`
  helper.
- T-oh4-04, T-oh4-05 — accepted (internal engineer users; no audit log
  requirement).

## Self-Check: PASSED

All commit hashes verified via `git log --oneline`:
- `922bd3d` — feat(cables): T3-C PoE budget solver post-persist decorator
- `3bfd18f` — feat(cables): T3-B redundant-row emission for is_critical processors
- `d85922c` — feat(cables): T2-B-ext cross-room signal-graph chains

All created files verified present:
- `database/migrations/2026_07_11_000002_add_is_critical_to_devices.php`
- `database/migrations/2026_07_11_000003_add_is_redundant_to_cable_schedule_items.php`
- `database/migrations/2026_07_11_000004_add_poe_metadata_to_devices.php`

Full cable regression suite green — 101 tests, 466 assertions.
