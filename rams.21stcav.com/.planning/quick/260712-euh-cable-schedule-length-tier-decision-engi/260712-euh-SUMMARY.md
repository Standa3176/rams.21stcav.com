---
quick_id: 260712-euh
slug: cable-schedule-length-tier-decision-engi
date: 2026-07-12
completed: 2026-07-12
subsystem: cable-schedule
tags: [cable-rules, length-tiers, admin-ui, inference-engine]
dependency-graph:
  requires:
    - DeviceCableRule (from 260711-q7q)
    - CableScheduleGeneratorService::inferCableRun (from 260711-q7q)
    - device_cable_rules table + seeder (from 260711-q7q)
  provides:
    - length-tier decision engine (auto-swap cable spec by row length)
    - 5 new cable rules (USB2, USB3, DisplayPort, SDI, Optical fibre)
    - admin Alpine.js tier editor UI + FormRequest validation
  affects:
    - CableScheduleGeneratorService::inferCableRun signature (+ ?float $lengthM)
    - 5 inferCableRun call sites (generate / generateFromDevicesFlat / createDagEdge / buildSignalGraph x2 / buildRowsFromEquipmentLines)
    - DISTANCE_WARNING_RULES const + computeDistanceWarnings method DELETED
tech-stack:
  added: []
  patterns:
    - "length-tier picker: walks tiers ascending on max_m, first match wins, override cable_type / cores / to_endpoint / notes, preserve signal_type"
    - "null-length policy: passive tier 1 + '⚠ Length not confirmed' warning"
    - "over-max policy: last tier + '⚠⚠ exceeds max range' warning"
    - "sort-at-persist: FormRequest normalises tier order before validation so the read side never re-sorts"
key-files:
  created:
    - database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php
  modified:
    - app/Models/DeviceCableRule.php
    - app/Services/CableScheduleGeneratorService.php
    - database/seeders/DeviceCableRulesSeeder.php
    - app/Http/Requests/Admin/DeviceCableRuleRequest.php
    - resources/views/admin/device-cable-rules/_form.blade.php
    - resources/views/admin/device-cable-rules/index.blade.php
    - tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
    - tests/Feature/Admin/DeviceCableRuleControllerTest.php
    - tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php
decisions:
  - "Tier picker preserves flat signal_type — DAG walker + XLSX colouring depend on stable signal_type; a fibre-HDMI tier is still 'video', not 'network'."
  - "Sort at persist (in FormRequest), never at read — the seeder + FormRequest guarantee ascending-on-max_m ordering, inference walks left-to-right."
  - "Null length_tiers vs empty array: normaliseLengthTiers() collapses empty arrays to null so the model always stores the canonical 'no tier logic' flag."
  - "5 stale computeDistanceWarnings unit tests removed in T2 (not T5) — they directly tested a deleted private method via reflection; tier-picker cases in T5 cover the equivalent behaviour."
  - "SDI 12G tier deferred — ascending-first-match walk can't express 'bandwidth-aware 12G below 60m'; needs future signal-integrity picker (see plan Deferred/Next)."
  - "3 rules kept flat (priorities 41 mic, 60 Dante amp, 61 analogue amp) — analogue XLR + analogue speaker runs don't tier-swap; proves null-tier fallthrough in production."
metrics:
  duration: "~1h execution"
  completed_date: 2026-07-12
  tasks_completed: 5
  files_touched: 9
  commits: 5
  tests_new: 12
  tests_green: 97
---

# Quick Task 260712-euh: Cable Schedule Length-Tier Decision Engine Summary

One-liner: **Length-aware inferCableRun — data-driven per-row cable swap via DeviceCableRule::length_tiers with survey-length auto-tier + warning system; retires the hardcoded DISTANCE_WARNING_RULES cascade.**

## What Changed

The Tier 3-D DeviceCableRule engine (shipped 2026-07-11 in quick task 260711-q7q) gained per-rule length-tier decision logic. Every rule can now hold an ordered `length_tiers` JSON array; the inference engine walks it ascending on `max_m` and picks the first tier whose max_m ≥ the row's `approx_length_m`. That tier's `cable_type` / `cores` / `to_endpoint` / `notes` OVERRIDE the flat row values — `signal_type` is preserved so the DAG walker and XLSX colouring stay consistent.

The old hardcoded `DISTANCE_WARNING_RULES` const + `computeDistanceWarnings` method (which only appended warnings — never swapped the cable) is DELETED. Length-aware behaviour is now 100% data-driven and admin-editable.

## Tier Selection Contract

| Length | Behaviour |
|--------|-----------|
| null   | tier 1 (safest passive) + `⚠ Length not confirmed on survey — assuming passive tier` |
| ≤ tier 0 max_m | tier 0 |
| ≤ tier N max_m | tier N (first match ascending) |
| > every tier's max_m | last tier + `⚠⚠ Length {L}m exceeds max range for this cable type — consider signal repeater / regen` |

Warnings join with the existing ` | ` separator convention. Tier notes chain: `{tier notes} | {warning}` (or just `{warning}` when tier has empty notes).

## Rule Coverage After Seed

**20 total rules** (15 pre-existing + 5 new).

**12 rules with tiers:**

| Priority | Keywords | Tier 1 | Tier 2 | Tier 3 |
|---------:|----------|--------|--------|--------|
| 10 | display / HDMI / projector | HDMI 2.0 (15m) | Cat6a HDBaseT (70m) | HDMI over fibre extender (300m) |
| 20 | HDBaseT / extender | Cat6a shielded (100m) | HDBaseT over fibre (300m) | — |
| 30 | speaker / pendant | 2-core 1.5mm (30m) | 4-core star quad 2.5mm (100m) | — |
| 40 | Shure MXW | Cat6 Shure (90m) | Fibre + Shure media converter (300m) | — |
| 50 | DSP / Q-Sys / Biamp | Cat6 Dante/AES67 (90m) | Fibre + Dante media converter (300m) | — |
| 70 | VC codec | Cat6 PoE (90m) | Fibre + PoE media converter (300m) | — |
| 80 | camera / PTZ | Cat6 PoE (90m) | Fibre + PoE media converter (300m) | — |
| 90 | touch panel / keypad | Cat6 PoE (90m) | Fibre + PoE media converter (200m) | — |
| 100 | control / Crestron | Cat6 (100m) | Fibre + media converter (300m) | — |
| 110 | switch / Netgear | Cat6 (100m) | Single-mode fibre uplink (500m) | — |
| 120 | patch panel / keystone | Cat6 (100m) | Single-mode fibre patch (500m) | — |
| 130 | MXWAPX / access point | Cat6 PoE (90m) | Fibre + PoE media converter (300m) | — |

**3 rules kept flat** (priorities 41, 60, 61) — analogue paths that don't tier-swap.

**5 new rules at priorities 140–144:**

| Priority | Keywords | Tier 1 | Tier 2 | Tier 3 |
|---------:|----------|--------|--------|--------|
| 140 | usb 2.0 / usb2 | USB 2.0 (5m) | Active repeater (20m) | USB over fibre (50m) |
| 141 | usb 3.0 / usb3 / usb-c | USB 3.0 (3m) | Active optical (15m) | USB 3.0 over fibre (50m) |
| 142 | displayport / dp | DisplayPort 1.4 (2m) | Active DP optical (15m) | DP over fibre (100m) |
| 143 | sdi / bnc | 3G-SDI coax (100m) | SDI over fibre (500m) | — (12G deferred) |
| 144 | fibre / om3 / om4 / os2 | OM4 multimode (550m) | OS2 single-mode (40 km) | — |

## Commits

| Task | Hash | Message |
|------|------|---------|
| T1 | `3ae22a7` | feat(cable-rules): add length_tiers JSON column + model cast (260712-euh T1) |
| T2 | `4bd576d` | feat(cable-generator): length-aware inferCableRun + retire DISTANCE_WARNING_RULES (260712-euh T2) |
| T3 | `af7deb2` | feat(cable-rules): seed length_tiers + add USB/DP/SDI/fibre rules (260712-euh T3) |
| T4 | `61a72cd` | feat(cable-rules-admin): Alpine tier editor + validation + index badge (260712-euh T4) |
| T5 | `51428a6` | test(cable-rules): 12 tier-selection + CRUD cases + count bump to 20 (260712-euh T5) |

## Test Coverage

- **DeviceCableRuleInferenceTest**: 19 passed (11 pre-existing + 8 new tier-selection) / 62 assertions
- **DeviceCableRuleControllerTest**: 10 passed (6 pre-existing + 4 new CRUD) / 40 assertions
- **CableScheduleGeneratorServiceTest**: 18 passed / 70 assertions (5 stale computeDistanceWarnings tests removed in T2)
- **CableScheduleDagGenerationTest**: 19 passed / 110 assertions (byte-for-byte regression preserved)
- **CableScheduleStoreDeterministicTest**: 2 passed / 22 assertions
- **StencilPortResolverTest**: 4 passed / 9 assertions
- **All CableSchedule + DeviceCableRule filter sweeps**: 97 passed / 467 assertions

Regression proof: every canonical byte-for-byte assertion in `DeviceCableRuleInferenceTest` still passes because `buildRowsFromEquipmentLines(..., null)` pipes null length through, and the tier picker returns tier 1 (whose cable_type matches the pre-260712-euh flat string).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Removed 5 stale computeDistanceWarnings tests during T2**
- **Found during:** Task 2
- **Issue:** Task 2 deletes the `computeDistanceWarnings` method. `CableScheduleGeneratorServiceTest.php` had 5 tests calling that method directly via `\ReflectionMethod`, causing `ReflectionException: Method does not exist` fatals.
- **Fix:** Deleted the 5 stale tests (`test_hdmi_over_15m_appends_warning`, `test_cat6_poe_over_100m_appends_warning`, `test_speaker_2core_over_30m_appends_warning`, `test_multiple_warnings_joined_with_pipe`, `test_room_without_survey_match_leaves_length_null`) and replaced them with a retirement comment pointing to the Task 5 tier-selection cases which cover equivalent behaviour through `inferCableRun` directly.
- **Files modified:** `tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php`
- **Commit:** `4bd576d` (T2)

**2. [Rule 2 - Defensive] Added non-array filter in `normaliseLengthTiers`**
- **Found during:** Task 4
- **Issue:** `usort` on a JSON-decoded array crashes if any element isn't itself an array (e.g. malformed client submits `["string", {...}]`).
- **Fix:** Added `array_filter(..., is_array)` before `usort` in `DeviceCableRuleRequest::normaliseLengthTiers()`. Empty result after filtering collapses to null.
- **Files modified:** `app/Http/Requests/Admin/DeviceCableRuleRequest.php`
- **Commit:** `61a72cd` (T4)

**3. [Rule 4 - Scope-adjacent] SDI 12G tier deferred (documented in plan)**
- **Found during:** Task 3 (planner-documented deferral, not a runtime deviation)
- **Issue:** Plan spec called out that the ascending-first-match walk can't express "12G-SDI upgrade at ≤60m for higher bandwidth" — a smaller max_m tier would never fire because tier 0 (100m/3G) matches first.
- **Resolution:** Only 2 tiers seeded for SDI (100m coax → 500m fibre). Bandwidth-aware picker deferred to a future signal-integrity intelligence quick task (see plan's Deferred / Next).

## Auth Gates

None — task was fully autonomous; no external services touched.

## Known Stubs

None. Every rule with tiers has its full tier list populated; the tier picker exercises every code path via the T5 tests.

## Deploy Plan

**Migration + seeder + cache flush required on live.**

```bash
cd /home/stcav/rams.21stcav.com.git/rams.21stcav.com
git pull                                                                # pulls the 5 commits
sudo -u stcav php artisan migrate --force                               # adds length_tiers column
sudo -u stcav php artisan db:seed --class="Database\\Seeders\\DeviceCableRulesSeeder" --force
                                                                        # tiers populate on 12 existing + adds 5 new = 20 total
sudo -u stcav php artisan cache:clear                                   # flushes the 1h inference cache
```

**Existing schedules unchanged until regenerated.** On regeneration:
- rows with tier-configured rules AND populated `approx_length_m` auto-swap to the correct tier
- rows without length default to tier 1 (safest passive) with `⚠ Length not confirmed on survey — assuming passive tier` in notes

No env changes. No queue-worker changes. After deploy admin sees 5 new rules at `/admin/device-cable-rules` (priority 140–144) plus tier count badges on the 12 rules that got tiers.

## Verification Steps After Deploy

1. Log in as admin, visit `/admin/device-cable-rules`
2. Confirm 20 rows in the table
3. Confirm tier badges on rules 10, 20, 30, 40, 50, 70, 80, 90, 100, 110, 120, 130, 140, 141, 142, 143, 144
4. Edit the priority 10 (HDMI) rule → confirm the collapsible "Length Tiers (3)" panel shows 3 pre-populated tiers
5. Pick any live project with a site survey + parsed room lengths → regenerate its cable schedule → verify:
   - Rooms with parsed length < 15m → HDMI displays get `HDMI 2.0` cable_type
   - Rooms with parsed length between 15–70m → HDMI displays get `Cat6a (shielded) HDBaseT`
   - Rooms with parsed length > 70m → HDMI displays get `HDMI over fibre extender`
   - Rooms with no parsed length → tier 1 cable_type + `⚠ Length not confirmed` notes suffix

## Self-Check: PASSED

- Migration file exists: `database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php` — FOUND
- SUMMARY file exists: `.planning/quick/260712-euh-cable-schedule-length-tier-decision-engi/260712-euh-SUMMARY.md` — FOUND
- Commits present on `feat/worksheet-classifier-universal`: `3ae22a7`, `4bd576d`, `af7deb2`, `61a72cd`, `51428a6` — ALL FOUND (verified via `git log`)
- All modified files exist per key-files list — verified
