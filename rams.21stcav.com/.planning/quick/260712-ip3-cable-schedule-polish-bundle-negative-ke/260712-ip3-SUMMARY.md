---
quick_id: 260712-ip3
slug: cable-schedule-polish-bundle-negative-ke
subsystem: cable-schedules
tags: [cable-schedule, device-cable-rule, admin, inference, ux-polish]
completed: 2026-07-12
duration_minutes: 15
tasks_completed: 3
tasks_total: 3
commits:
  - c594ffa  fix(cable-rules): add negative_keywords column to kill brand-name collisions (260712-ip3)
  - 6944e00  feat(cable-schedule): regenerate button on edit page (260712-ip3)
  - 22fce18  feat(admin): rule preview endpoint + inline tester on device-cable-rules index (260712-ip3)
requires:
  - DeviceCableRule model + seeder + admin CRUD from 260711-q7q
  - length_tiers column + inference tier picker from 260712-euh
provides:
  - device_cable_rules.negative_keywords JSON column (nullable, additive)
  - CableScheduleGeneratorService::ruleMatches() + ::previewInference() + ::resolveTierUsed()
  - GET /admin/device-cable-rules/preview JSON endpoint
  - ↻ Regenerate button on cable-schedule edit page
  - Alpine.js rule tester card on /admin/device-cable-rules
affects:
  - inferCableRun() output for equipment names that match multiple rules
    where an earlier rule now declares a negative_keywords entry
  - Seeder priorities 61 (analogue amp), 70 (VC codec), 80 (camera / PTZ)
tech_stack:
  added:
    - Alpine.js `x-data` component for inline preview trace
  patterns:
    - textarea shim → prepareForValidation() split → cast to array on model
      (mirrors 260711-q7q keywords_raw + 260712-euh length_tiers editors)
key_files:
  created:
    - database/migrations/2026_07_12_100000_add_negative_keywords_to_device_cable_rules.php
    - tests/Feature/Cable/CableScheduleRegenerationTest.php
    - tests/Feature/Admin/DeviceCableRulePreviewTest.php
  modified:
    - app/Http/Controllers/Admin/DeviceCableRuleController.php
    - app/Http/Requests/Admin/DeviceCableRuleRequest.php
    - app/Models/DeviceCableRule.php
    - app/Services/CableScheduleGeneratorService.php
    - database/seeders/DeviceCableRulesSeeder.php
    - resources/views/admin/device-cable-rules/_form.blade.php
    - resources/views/admin/device-cable-rules/index.blade.php
    - resources/views/cable-schedule/edit.blade.php
    - routes/web.php
    - tests/Feature/Admin/DeviceCableRuleControllerTest.php
    - tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
decisions:
  - Kept negative_keywords column strictly additive (nullable JSON) — no
    backfill required for existing deploys; null / empty array collapses
    to "no exclusion" (byte-for-byte identical to pre-260712-ip3
    behaviour for every rule that doesn't declare an exclusion list).
  - Migration file suffix `100000` guarantees ordering AFTER the
    `2026_07_12_000000` length_tiers migration so the negative_keywords
    column always sits after length_tiers on the table.
  - previewInference() + resolveTierUsed() reuse the exact same
    DeviceCableRule::forInference() collection + pickTier() logic as
    inferCableRun() so preview output is byte-identical to prod for the
    same (name, length) pair — no divergent code paths to drift.
  - Regenerate button reuses the existing cable-schedules.retry-generation
    route + policy check; no new controller / route / service code, just
    a Blade surface + 4 feature tests.
metrics:
  duration_minutes: 15
  tests_added: 13
  tests_total_green: 116 (44 DeviceCableRule* + 72 CableSchedule*)
  file_count: 14
---

# Cable Schedule Polish Bundle (260712-ip3) — Summary

Three follow-up fixes from the 260712-euh verification pass. Landed as
three atomic commits, one per task, in order.

## One-liner

`negative_keywords` exclusion column kills Logitech USB 3.0 webcam →
codec rule collision (priority 70 → 141 fallthrough), a first-class
Regenerate button on the cable-schedule edit page eliminates the
buried `⋮` action, and a JSON preview endpoint lets admins test any
rule change from the browser without SSH-ing to the box.

## What shipped

### Task 1 — negative_keywords column (`c594ffa`)

Nullable JSON `negative_keywords` column on `device_cable_rules`. When
set, the inference walker treats a rule as SKIPPED whenever the
equipment name matches ANY entry — even if the positive keyword list
also matched.

Seeded exclusion lists:

| Priority | Rule           | negative_keywords                                       |
| -------- | -------------- | ------------------------------------------------------- |
| 61       | Analogue amp   | `['lamp', 'champagne']` (defensive)                     |
| 70       | VC codec       | `['usb 3', 'usb 3.0', 'usb-c webcam', 'usb hub']`       |
| 80       | Camera / PTZ   | `['usb 3', 'usb-c webcam']`                             |

Real-world regression closed: `Logitech USB 3.0 Webcam` previously
hijacked the priority 70 codec rule on the `logitech` keyword AND the
priority 80 camera rule on the `webcam` keyword before the priority
141 USB 3 rule could win. Cable schedules mis-routed the run as
`Cat6 (PoE)` / `video` instead of `USB 3.0` / `usb`.

New service helper `ruleMatches(string $lower, DeviceCableRule $rule)`
folds the positive + negative test into one call. One line changed in
`inferCableRun()` — the loop body is otherwise unchanged.

Admin CRUD surface: `_form.blade.php` grows a `Negative Keywords`
textarea below Keywords; `index.blade.php` shows an `N excl` hint
next to the keyword count when the list is non-empty.

**Tests:** 6 new inference cases in `DeviceCableRuleInferenceTest`,
3 new CRUD cases in `DeviceCableRuleControllerTest`. All 38 tests in
those two suites green. 18 byte-for-byte regressions in
`CableScheduleGeneratorServiceTest` still green.

### Task 2 — Regenerate button (`6944e00`)

Adds `↻ Regenerate` button to the cable-schedule edit page-header
actions group, sitting between `History` and `Edit drawer`. Reuses
the existing `cable-schedules.retry-generation` route +
`CableScheduleController::retryGeneration()` action (both verified at
`routes/web.php:388` and `CableScheduleController.php:480`). No
controller / route / service code changed.

Button behaviour:
- Gated on the `update` CableSchedulePolicy so a future per-user rule
  propagates automatically.
- Visually disabled + inert (opacity `.5` + `pointer-events:none`)
  when `status === 'generating'`.
- `data-confirm` attribute drives the shared appConfirm modal from
  260504-m2k. No new JS.

**Tests:** 4 new feature cases in `CableScheduleRegenerationTest`.
Full `tests/Feature/Cable` suite (56 tests) green.

### Task 3 — Rule preview endpoint + Alpine tester (`22fce18`)

New JSON endpoint at
`GET /admin/device-cable-rules/preview?equipment=<name>&length_m=<n>`.
Returns matched rule + full walker trace with per-rule verdict of
`matched` / `skipped_keywords` / `skipped_negative` /
`skipped_earlier_match` plus a human-readable reason.

Route is registered BEFORE the `Route::resource` for
`device-cable-rules` so Laravel doesn't try to bind `preview` as a
`{deviceCableRule}` model-bound parameter and 404 on the string.

New public method `CableScheduleGeneratorService::previewInference()`
walks the SAME `DeviceCableRule::forInference()` collection as
`inferCableRun()` and reuses the private `pickTier()` helper so
preview output is byte-identical to prod for the same (name, length)
pair. New private helper `resolveTierUsed()` mirrors the pickTier
selection for the preview payload without merging into a full row.

Admin index page grows a `🧪 Test a rule` Alpine.js card ABOVE the
rules table: equipment textbox + optional length input + Preview
button + summary block + trace `<table>` with columns Priority /
Keywords / Verdict / Reason.

**Tests:** 6 new feature cases in `DeviceCableRulePreviewTest`. Full
`--filter=CableSchedule` sweep (72 tests) + full `--filter=DeviceCableRule`
sweep (44 tests) green.

## Deviations from Plan

**Deviations captured from user-supplied constraints, applied over the
planner's default:**

1. **[Constraint override] Migration filename dropped `_table` suffix.**
   Plan: `2026_07_12_100000_add_negative_keywords_to_device_cable_rules_table.php`.
   User constraint: `2026_07_12_100000_add_negative_keywords_to_device_cable_rules.php`.
   Followed the constraint.

2. **[Constraint override] Seeder exclusion lists tuned per user.**
   Plan proposed:
   - Priority 60 (Dante amp): `['usb', 'webcam', 'camera']`
   - Priority 70 (codec): `['usb 3', 'usb-c', 'usb 2', 'webcam']`
   - Priority 80 (camera): `['usb 3', 'usb-c']`

   Constraint required:
   - Priority 61 (analogue amp — plan said 65, but 65 doesn't exist;
     applied to the only "amp analog" row, which is priority 61):
     `['lamp', 'champagne']`
   - Priority 70 (codec): `['usb 3', 'usb 3.0', 'usb-c webcam', 'usb hub']`
   - Priority 80 (camera): `['usb 3', 'usb-c webcam']`

   The plan's proposed exclusions on priority 60 (Dante amp) would
   have skipped `Q-Sys Core USB Hub` — legitimate but harmless. The
   constraint's tighter lists limit exclusion to only the surfaces
   where the real collision occurs (VC codec + camera). Followed the
   constraint.

3. **[Rule 3 - Blocking-issue auto-fix] Extended DeviceCableRuleController
   `extractData()` to strip the new `negative_keywords_raw` textarea shim.**
   The plan lists the seeder + FormRequest + Blade + service edits for
   Task 1 but doesn't explicitly call out the controller change.
   Without the strip, `updateOrCreate` would receive an unfillable key
   and the request would silently drop `negative_keywords`. Fixed
   inline as part of Task 1's commit.

None of these are architectural — Rule 4 (ask user) was not needed.

## Deploy checklist (live)

Migration + seeder + cache flush required on live for Task 1 changes.
Tasks 2 & 3 are code-only (no schema, no config).

```
php artisan migrate --force
php artisan db:seed --class=DeviceCableRulesSeeder --force
php artisan cache:clear
php artisan view:clear
```

- `migrate` runs `2026_07_12_100000_add_negative_keywords_to_device_cable_rules.php`.
- `db:seed` upserts `negative_keywords` onto rules 61 / 70 / 80 and
  scrubs any stale non-null value on every other row.
- `cache:clear` drops the pre-seed `DeviceCableRule::forInference()`
  cache so the next request reads the freshly-seeded exclusion lists.
- `view:clear` flushes compiled Blade cache so the Task 3 admin index
  page renders the new `🧪 Test a rule` card + the Task 2 Regenerate
  button on the cable-schedule edit page.

## Verification instructions (post-deploy)

1. Load `/admin/device-cable-rules` — the `🧪 Test a rule` card sits
   at the top of the page, above the rules table.
2. Hit `/admin/device-cable-rules/preview?equipment=Logitech%20USB%203.0%20webcam&length_m=20`
   in the browser (or via the inline card) and confirm the JSON body:
   - `matched_priority` = **141** (USB 3 rule), NOT 70 (codec).
   - `signal_type` = `usb`.
   - Trace row with `priority: 70` has `verdict: skipped_negative`.
3. Load `/cable-schedules/{id}/edit` on any draft schedule — the
   `↻ Regenerate` button sits between `↻ History` and the Edit drawer.
   Click, confirm the appConfirm modal, and watch the schedule flip to
   `generating` + rebuild.

## Self-Check: PASSED

- Task 1 files present:
  - `database/migrations/2026_07_12_100000_add_negative_keywords_to_device_cable_rules.php` — FOUND
  - `app/Models/DeviceCableRule.php` — FOUND (fillable + cast added)
  - `app/Services/CableScheduleGeneratorService.php` — FOUND (`ruleMatches()` present)
  - `database/seeders/DeviceCableRulesSeeder.php` — FOUND (3 negative lists seeded)
- Task 2 files present:
  - `resources/views/cable-schedule/edit.blade.php` — FOUND (`↻ Regenerate` present)
  - `tests/Feature/Cable/CableScheduleRegenerationTest.php` — FOUND (4 tests)
- Task 3 files present:
  - `routes/web.php` — FOUND (preview route BEFORE resource)
  - `app/Http/Controllers/Admin/DeviceCableRuleController.php` — FOUND (`preview()` method)
  - `app/Services/CableScheduleGeneratorService.php` — FOUND (`previewInference()` + `resolveTierUsed()`)
  - `resources/views/admin/device-cable-rules/index.blade.php` — FOUND (Alpine card at top)
  - `tests/Feature/Admin/DeviceCableRulePreviewTest.php` — FOUND (6 tests)
- Commits present in git log:
  - `c594ffa` — FOUND
  - `6944e00` — FOUND
  - `22fce18` — FOUND
