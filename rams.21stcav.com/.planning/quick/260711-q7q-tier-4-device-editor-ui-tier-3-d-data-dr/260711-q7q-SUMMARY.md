---
quick_id: 260711-q7q
slug: tier-4-device-editor-ui-tier-3-d-data-driven-cable-rules
date_completed: 2026-07-11
commits:
  - de45752  # Task 1 — Tier 4 admin device editor UI
  - 67d783d  # Task 2 — Tier 3-D data-driven cable rules engine + admin CRUD
tags: [admin-ui, cable-schedule, tier-4, refactor, tdd]
dependency_graph:
  requires:
    - 260711-oh4 (Device.is_critical + pse_budget_w + pd_load_w columns already shipped)
  provides:
    - /admin/devices — Tier 4 editor for signal_role + is_critical + PoE + room_name
    - /admin/device-cable-rules — Tier 4 CRUD for the 15 canonical cable inference rules
    - DeviceCableRule::forInference() — 1h Cache::remember contract for the service
tech_stack:
  patterns:
    - Data-driven inference (DB rows replace hardcoded PHP branches)
    - Cache-invalidation on model saved/deleted events
    - Splitting toggle branches into ordered priority rows (mic Shure/generic; amp Dante/analogue)
key_files:
  created:
    - app/Http/Controllers/Admin/DeviceController.php
    - app/Http/Controllers/Admin/DeviceCableRuleController.php
    - app/Http/Requests/Admin/DeviceUpdateRequest.php
    - app/Http/Requests/Admin/DeviceCableRuleRequest.php
    - app/Models/DeviceCableRule.php
    - database/migrations/2026_07_11_000000_create_device_cable_rules_table.php
    - database/seeders/DeviceCableRulesSeeder.php
    - resources/views/admin/devices/index.blade.php
    - resources/views/admin/devices/edit.blade.php
    - resources/views/admin/device-cable-rules/index.blade.php
    - resources/views/admin/device-cable-rules/edit.blade.php
    - resources/views/admin/device-cable-rules/_form.blade.php
    - tests/Feature/Admin/DeviceControllerTest.php
    - tests/Feature/Admin/DeviceCableRuleControllerTest.php
    - tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
  modified:
    - app/Services/CableScheduleGeneratorService.php  # inferCableRun() now iterates DeviceCableRule::forInference()
    - resources/views/layouts/navigation.blade.php    # Devices + Cable Rules under Admin ▾ Library
    - routes/web.php                                  # /admin/devices + /admin/device-cable-rules
    - tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php  # RefreshDatabase + seed
    - tests/Feature/Cable/CableScheduleDagGenerationTest.php           # seed in setUp() (Rule 3)
    - tests/Feature/Cable/CableScheduleStoreDeterministicTest.php      # seed in setUp() (Rule 3)
metrics:
  duration: 55m
  tasks: 2
  files_created: 15
  files_modified: 6
  tests_added: 17
  total_tests_green: 96 (485 assertions across DeviceControllerTest + DeviceCableRule* + CableSchedule* filters)
---

# Phase 260711-q7q: Tier 4 Device Editor UI + Data-Driven Cable Rules Engine

Follow-up to 260711-oh4 — Tier 4 admin surface for the `is_critical` / `pse_budget_w` / `pd_load_w` / `signal_role` / `room_name` Device columns AND a data-driven refactor of the 13-branch `inferCableRun()` cascade into an admin-editable `device_cable_rules` table.

## What shipped

### Task 1 — /admin/devices (commit `de45752`)

A Tier 4 asset-register editor mirroring the `admin/users` visual chrome:

- **Index** (`/admin/devices`): paginated 15/page ordered by `(project_id, room_name, id)`; filter by `?project_id=X` and search by `?q=…` (LIKE across manufacturer / model / part_no). Every row shows a signal-role badge (source=blue, destination=green, processor=purple, unclassified=muted), a ⚠ glyph for critical processors, and inline PSE / PD watts.
- **Edit form** (`/admin/devices/{device}/edit`): four signal-role radio pills (source / destination / processor / unclassified — the last maps to `null` via `prepareForValidation`), an `is_critical` checkbox with hidden-`0` sentinel, `pse_budget_w` + `pd_load_w` number inputs (step=0.5, nullable), and an editable `room_name`. All other identity fields (manufacturer / model / part_no) remain read-only — device rows are created by the label-photo capture flow and quote-import pipeline.
- **Auth gate**: `DeviceUpdateRequest::authorize()` returns `isAdmin()`. Non-admin GET or PUT returns 403.
- **Nav**: new "Devices" entry under Admin ▾ Library; `$adminActive` extended so the button highlights on `/admin/devices/*`.

Six feature tests / 45 assertions cover the admin gate, index rendering + project filter + free-text search, edit form fields, and update round-trip (classified persistence + `unclassified` → `null` mapping).

### Task 2 — /admin/device-cable-rules + data-driven inferCableRun() (commit `67d783d`)

The 13-branch hardcoded cascade in `CableScheduleGeneratorService::inferCableRun()` collapses into a single foreach walk over an admin-editable database table:

- **Migration**: `device_cable_rules` (id, priority uint, keywords json, cable_type, cores, signal_type, to_endpoint, notes, is_active + compound `(priority, is_active)` index). Reversible `down()`. Additive — no changes to existing tables.
- **Model**: `DeviceCableRule` exposes `forInference()` (Cache::remember 1h) and `flushCache()`. Boot hooks `static::saved` + `static::deleted` flush the cache automatically so admin CRUD writes propagate to the next generation instantly.
- **Seeder**: `DeviceCableRulesSeeder` inserts **15 canonical rules** using `updateOrCreate` keyed on priority (idempotent). See "Rule count deviation" below.
- **Service refactor**: `inferCableRun()` shrinks from ~160 lines of `if ($this->matchesAny(...))` branches to a single foreach + fallback. `matchesAny()` stays (still used by `buildQuotedCableOverrides` and `sortProcessors`). All eight private consts (LABOUR / CONSUMABLE / MOUNT / NON_PHYSICAL / QUOTED_CABLE / SIGNAL_PATH / CENTRAL_ROOM / DISTANCE_WARNING) stay untouched.
- **CRUD** (`/admin/device-cable-rules`): index / create / edit / update / delete (no show). Keywords entered as textarea (one-per-line), split + lowercased in `prepareForValidation()`. `signal_type` constrained to the 8 keys in `config('cables.signal_type_colours')`. Admin-only via `DeviceCableRuleRequest::authorize`.
- **Nav**: "Cable Rules" entry alongside "Devices" under Admin ▾ Library; `$adminActive` extended.

**17 new/updated tests all green:**
- `DeviceCableRuleInferenceTest` — 11 cases / 30 assertions covering seeder count + idempotency + 9 representative byte-for-byte inference outputs (Shure MXW, generic mic, Samsung QM85, Cisco Room Kit, PTZ, Q-Sys Core, ceiling speaker, Netgear switch, unknown widget, LEA amp priority-ordering).
- `DeviceCableRuleControllerTest` — 6 cases / 32 assertions covering gate + index + create + update + delete + cache flush on save/delete.
- Existing `CableScheduleGeneratorServiceTest` gains `RefreshDatabase` + seed in `setUp()` → 23 cases / 83 assertions still green.
- Pre-existing `CableScheduleDagGenerationTest` (17 cases) and `CableScheduleStoreDeterministicTest` (1 case) get the same seed hook (Rule 3 auto-fix — see deviations).

## Deviations from Plan

### 1. [Rule 1 — Plan text inconsistency] Rule count is 15, not 13

**Found during:** Task 2 seeder implementation.

**Issue:** The plan's `must_haves.truths` claims "the seeder inserts EXACTLY 13 rules matching every current inferCableRun() branch by priority", and the `done` block says `Rule::count() === 13`. But the plan's explicit priority list in the `<action>` block enumerates 15 distinct rows (priorities 10, 20, 30, **40, 41**, 50, **60, 61**, 70, 80, 90, 100, 110, 120, 130). The plan itself explains the discrepancy: the original 13-branch cascade included two nested `isShure` / `isDante` toggles that each map to two output shapes, so splitting them into ordered priority rows (Shure at 40 before generic mic at 41; Dante amp at 60 before analog amp at 61) is the only way to preserve byte-for-byte parity.

**Fix:** Implemented 15 rules as the explicit priority list requires. Byte-for-byte regression suite (`DeviceCableRuleInferenceTest`) verifies parity across all mic + amp variants. The seeder count assertion targets `15`, not `13`.

**Files:** `database/seeders/DeviceCableRulesSeeder.php`, `tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php`.

### 2. [Rule 3 — Blocking test regression] Two pre-existing feature suites need the seed hook

**Found during:** Task 2 verification (`--filter=CableSchedule` full sweep).

**Issue:** `CableScheduleDagGenerationTest` and `CableScheduleStoreDeterministicTest` exercise `CableScheduleGeneratorService::generate()` end-to-end. After the DB-driven refactor these tests hit an empty `device_cable_rules` table (RefreshDatabase wipes the schema) → every equipment name returns the TBC placeholder → 6 assertion failures on specific `cable_type` values.

**Fix:** Added `DeviceCableRulesSeeder` to both `setUp()` methods. Zero production-code change; both suites now green (17 cases each still verify the DAG + store contracts).

**Files:** `tests/Feature/Cable/CableScheduleDagGenerationTest.php`, `tests/Feature/Cable/CableScheduleStoreDeterministicTest.php`.

### 3. [Rule 3 — Test payload] `assertSee` on the notes column doesn't work

**Found during:** `DeviceCableRuleControllerTest::test_admin_index_renders_...`.

**Issue:** The plan asked the index test to `assertSee('Wireless mic access point')` — that string lives in the `notes` field of priority 130. The rendered index blade doesn't include a Notes column (kept the row height tight), so the notes text never appears in HTML.

**Fix:** Changed the assertion to `assertSee('mxwapx')` — a unique keyword from priority 130 that IS rendered in the Keywords column. Same test intent (proves the last-priority row rendered) via a value that's actually visible.

## Auth gates

None triggered — no third-party service touched by either task.

## Rules count sanity check

`DeviceCableRule::count()` === **15** after seeding. Verified by `DeviceCableRuleInferenceTest::test_seeder_produces_expected_row_count_and_is_idempotent`.

## Byte-for-byte regression assertion

Verified. `DeviceCableRuleInferenceTest` runs 9 representative equipment names through the DB-driven `buildRowsFromEquipmentLines([[name]])` shim and asserts exact string equality against the known-good pre-refactor output (cable_type + signal_type + cores + to_location). All 9 pass. In addition, the pre-existing `CableScheduleGeneratorServiceTest` (23 cases / 83 assertions) still green — including the T1-D quoted-cable override tests and the T1-C word-boundary regression cases from the original 260703 rollout.

## Test tally

Filtered sweep `DeviceControllerTest|DeviceCableRule|CableSchedule` — **96 passed / 485 assertions** in 15.6s. Zero regressions.

Full breakdown:
- **Task 1:** `DeviceControllerTest` — 6 / 45.
- **Task 2 new:** `DeviceCableRuleInferenceTest` — 11 / 30, `DeviceCableRuleControllerTest` — 6 / 32.
- **Task 2 existing (post-migration):** `CableScheduleGeneratorServiceTest` — 23 / 83, `CableScheduleDagGenerationTest` — 17 / (subset of 378), `CableScheduleStoreDeterministicTest` — 1, `CableScheduleXlsxRegressionTest` + `CableScheduleUpdatePersistsPortFksTest` + others in the Cable/Notifications suites — round out the balance.

## Deploy

- **REQUIRED live steps** (in order):
  1. `php artisan migrate --force` — creates `device_cable_rules` table.
  2. `php artisan db:seed --class=DeviceCableRulesSeeder --force` — inserts 15 canonical rules. Idempotent (`updateOrCreate` on priority) — safe to re-run at any time.
  3. `php artisan cache:clear` — flushes the 1h `device_cable_rules.for_inference` cache so next generation picks up new rules immediately. Skipping this step means the first generation after deploy may hit the fallback TBC path for up to 1 hour until the cache TTL expires.

- **No env changes.**
- **No queue-worker changes** (inferCableRun is synchronous; the cache uses whatever driver the app already runs on — currently `database`).
- **Rollback safety:** `Migration::down()` drops the table. Rollback also requires reverting both commits so the service falls back to its hardcoded branches. Down-migration is destructive of admin-authored rules — recommend a `SELECT * FROM device_cable_rules` dump before rollback if any post-seed edits exist.

- **Verify after deploy:**
  1. As an admin, hit `/admin/devices` — 200. See list of every Device row, badge column shows signal role.
  2. Edit a device — flip signal_role + tick is_critical + set pse_budget_w=740 → save → confirms redirect + persistence.
  3. Hit `/admin/device-cable-rules` — 200. See 15 seeded rules ordered by priority (10 = HDMI 2.0 first, 130 = wireless AP last).
  4. Regenerate a cable schedule for a project containing a Samsung QM85 or Cisco Room Kit — confirm the row's `cable_type` matches the seeded rule.
  5. As a non-admin, hit either URL — 403.

## Follow-up backlog

Not in scope; log if useful next pass:

- Bulk-edit action on `/admin/devices` for setting `is_critical` + PoE metadata across a project.
- Per-manufacturer rule packs (Shure / QSC / Crestron / Extron starter sets — ~40 vendor-specific rules bulk-importable from JSON).
- "Test this equipment name" preview tool on the rules index — runs a name through `DeviceCableRule::forInference()` and highlights the winning rule.
- Cross-project cable-run caching (per-project cache-tag) so cached rows bust only when THAT project's devices change.
- `signal_types` admin table with editable colour tokens so new signal types (fibre / coax-video / iso-audio) can be added without a config edit.

## Self-Check: PASSED

**Files created (checked with `[ -f path ]`):**
- FOUND: `app/Http/Controllers/Admin/DeviceController.php`
- FOUND: `app/Http/Controllers/Admin/DeviceCableRuleController.php`
- FOUND: `app/Http/Requests/Admin/DeviceUpdateRequest.php`
- FOUND: `app/Http/Requests/Admin/DeviceCableRuleRequest.php`
- FOUND: `app/Models/DeviceCableRule.php`
- FOUND: `database/migrations/2026_07_11_000000_create_device_cable_rules_table.php`
- FOUND: `database/seeders/DeviceCableRulesSeeder.php`
- FOUND: `resources/views/admin/devices/index.blade.php`
- FOUND: `resources/views/admin/devices/edit.blade.php`
- FOUND: `resources/views/admin/device-cable-rules/index.blade.php`
- FOUND: `resources/views/admin/device-cable-rules/edit.blade.php`
- FOUND: `resources/views/admin/device-cable-rules/_form.blade.php`
- FOUND: `tests/Feature/Admin/DeviceControllerTest.php`
- FOUND: `tests/Feature/Admin/DeviceCableRuleControllerTest.php`
- FOUND: `tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php`

**Commits (verified via `git log --oneline`):**
- FOUND: `de45752` — feat(admin): Tier 4 device editor UI (/admin/devices)
- FOUND: `67d783d` — refactor(cable-schedule): data-driven DeviceCableRule engine + admin CRUD
