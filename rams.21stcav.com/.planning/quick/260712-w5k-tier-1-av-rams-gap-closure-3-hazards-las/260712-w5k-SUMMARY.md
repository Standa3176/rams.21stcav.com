---
quick_id: 260712-w5k
plan: 260712-w5k-tier-1-av-rams-gap-closure-3-hazards-laser-rf-pull-1-standard-2-emergency-fields
type: quick
completed_at: 2026-07-12T21:28:25Z
duration_minutes: ~40
tasks_completed: 4
tasks_total: 4
tags:
  - rams
  - tier1
  - baseline-hazards
  - standards
  - site-emergency
  - regression-lock
dependency_graph:
  requires:
    - config/rams_tier1.php (260712-twi shipped baseline)
    - App\Services\Rams\Tier1RamsDefaultsService (260712-twi)
    - resources/views/pdf/rams.blade.php Section 7.0 (260712-twi)
  provides:
    - 3 new AV-industry baseline hazards (laser, RF, cable-pull)
    - 1 new industry standard reference (BS EN 60825-1 laser safety)
    - 2 new site-emergency capture fields (electrical isolation switch + fire extinguisher class)
    - Regression lock on PDF Section 7.0 heading render (both branches)
  affects:
    - Every Tier1RamsDefaultsService-injected RAMS (fresh RAMS with no engineer-supplied hazards/standards)
    - Every RAMS review form (Site Emergency Details fieldset now has 10 fields, was 8)
    - Every RAMS PDF Section 7.0 populated-table branch (now 7 rows, was 5)
tech-stack:
  added: []
  patterns:
    - Append-only extension of existing tier-1 config arrays (never mutate existing entries)
    - Whitelist validation pattern preserved in RamsController::updateAndDownload
key-files:
  created:
    - tests/Feature/Rams/RamsSection70HeadingTest.php
  modified:
    - config/rams_tier1.php
    - resources/views/rams/review.blade.php
    - app/Http/Controllers/RamsController.php
    - resources/views/pdf/rams.blade.php
decisions:
  - "Section 7.0 heading investigation resolved to no bug — actual heading text is 'Site-Specific Emergency Details' (with hyphen), verification-time grep on 260712-twi searched for 'Site Emergency Details' (no hyphen) and falsely reported it missing. Locked with a regression test instead of a Blade change."
metrics:
  duration: "~40 minutes end-to-end"
  completed_date: "2026-07-12"
  atomic_commits: 4
  tests_touched:
    - Tier1BaselineHazardsRenderTest (3 green, unchanged)
    - Tier1RamsDefaultsServiceTest (5 green, unchanged)
    - Tier1SiteEmergencyFormAndRenderTest (3 green, unchanged)
    - Tier1CoshhTableRenderTest (3 green, unchanged)
    - Tier1PdfStructuralPolishTest (5 green, unchanged)
    - RamsSection70HeadingTest (3 green, NEW)
    - RamsUpdateAndDownloadTransactionTest (1 green, unchanged)
  final_test_sweep:
    filter: "Rams"
    result: "293 passed, 1191 assertions, 53.71s"
---

# Quick Task 260712-w5k: Tier-1 AV RAMS Gap Closure (3 hazards / 1 standard / 2 emergency fields / heading regression) Summary

> **H&S professional sign-off warning.** All content added to `config/rams_tier1.php`
> in this task (3 new baseline hazards, 1 new standard reference) is drafted from
> AV install industry practice and cites UK standards, but has **not** been formally
> reviewed by 21CAV's H&S consultant. The whole tier-1 baseline defaults layer is
> flagged as **safety-critical** in the config file's header comment. A full sign-off
> pass on the 11 baseline hazards + 10 standards + 7 COSHH inventory items is
> deferred to the "H&S professional sign-off" backlog item in `deferred_next` of
> the shipped plan. Engineer-supplied per-project data ALWAYS wins over these
> defaults — this file is a fallback layer only.

## Objective

Close 4 specific deviations found during live verification of the 260712-twi Tier-1
AV RAMS content upgrade on Tilda RAMS project #87:

1. 3 missing AV-industry baseline hazards (laser projector eye safety, RF exposure
   from wireless AV devices, cable-pulling injuries).
2. 1 missing AV-industry standard (BS EN 60825-1 laser safety).
3. 2 missing site-emergency capture fields (electrical isolation switch location +
   fire extinguisher class).
4. Confirm PDF Section 7.0 heading render (verification-time grep false-negative).

All 4 deviations closed. Zero migrations. 4 atomic commits. All 260712-twi tests
remain green. 293 Rams-scope tests / 1191 assertions green after the final sweep.

## Commits

| # | Hash      | Task                                                     | Files                                     |
| - | --------- | -------------------------------------------------------- | ----------------------------------------- |
| 1 | `72e9d56` | Append 3 AV-specific baseline hazards                    | config/rams_tier1.php                     |
| 2 | `68fd605` | Append BS EN 60825-1 laser safety standard               | config/rams_tier1.php                     |
| 3 | `d24f593` | Add 2 site-emergency capture fields end-to-end           | review.blade + RamsController + pdf.blade |
| 4 | `216435a` | Regression test: PDF Section 7.0 heading (both branches) | tests/.../RamsSection70HeadingTest.php    |

## What Changed

### Task 1 — 3 new baseline hazards (`config/rams_tier1.php`)

Appended after "Engineer Fatigue from Long Working Days". Baseline hazard array
length: **8 → 11**. First 8 preserved byte-for-byte.

- **Laser Projector Eye Safety** — pre 3/5, post 1/4. 5 controls cite BS EN 60825-1
  class determination, direct-beam avoidance, warning labels, lens-cap protocol,
  and commissioning-time 2.1m beam-height check.
- **RF Exposure from Wireless AV Devices** — pre 2/3, post 1/2. 4 controls cite
  Control of Electromagnetic Fields at Work Regulations 2016, UKCA/CE + Ofcom
  compliance check, minimum antenna distance, pregnancy declaration.
- **Cable Pulling Injuries** — pre 4/3, post 2/2. 5 controls cite MHOR 1992 for
  >20kg drum lifts, EN 388 cut-B pulling gloves, lubricant on >10m or 2+ bend
  runs, mechanical pull-winch >30m, designated banksman on blind pulls.

### Task 2 — 1 new standard reference (`config/rams_tier1.php`)

Appended after "PUWER 1998". Standards reference array length: **9 → 10**.
First 9 preserved byte-for-byte.

- **BS EN 60825-1:2014+A11:2021** — "Safety of laser products — Part 1:
  Equipment classification and requirements". Applies to every laser projector,
  rangefinder, or alignment tool. Class 1/2 permitted; Class 3R+ requires
  documented RA + signage + 2.1m beam-height geometry.

### Task 3 — 2 new site-emergency fields end-to-end

Whitelist pattern preserved — all 3 layers touched:

- **Form (`resources/views/rams/review.blade.php`)** — 2 new form-group blocks
  under Site Emergency Details fieldset, added after `defibrillator_location`:
  - `site_emergency_electrical_isolation_switch` — text input, placeholder guides
    engineers to record main DB / red mushroom E-STOP location.
  - `site_emergency_fire_extinguisher_class` — select dropdown with A / B / C /
    D / F / CO2 options.
- **Controller (`app/Http/Controllers/RamsController.php`)** — 2 new validation
  rules at lines 410-411 + 2 new mapping keys in `$reviewedData['site_emergency']`
  at lines 561-562. `$generatedData['site_emergency']` mirror at line 568 picks
  up both new keys automatically (mirror pattern preserved).
- **PDF (`resources/views/pdf/rams.blade.php`)** — 2 new `<tr>` rows in the
  Section 7.0 populated-table branch, after `defibrillator_location` row.
  Labels: "Electrical Isolation Switch" + "Fire Extinguisher Class Available".
  Uses null-coalesce defensive access `$siteEmerg['electrical_isolation_switch']
  ?? ''` in case a pre-w5k `generated_data` snapshot is loaded post-deploy.

Emergency capture form grows: **8 → 10 fields**. PDF populated-table branch grows:
**5 → 7 rows**.

### Task 4 — Regression test (`tests/Feature/Rams/RamsSection70HeadingTest.php`)

3 test methods, all green on first run. This is a **regression lock on already-
shipped behaviour**, not a bug fix. The verification-time grep on 260712-twi's
Tilda RAMS #87 falsely reported "Site Emergency Details" missing because it
searched without the hyphen — the actual heading text is `Site-Specific
Emergency Details`. This test locks the exact string against future accidental
renaming and locks the invariant that Section 7.0 heading always renders
(never conditional on data population).

- `test_heading_renders_when_emergency_data_populated` — populated case,
  heading + hospital name present, amber banner absent.
- `test_heading_renders_when_emergency_data_empty` — no `site_emergency` key at
  all → heading + amber banner render together.
- `test_heading_renders_when_emergency_all_keys_present_but_blank` — sub-array
  present but all values blank → `array_filter` reduces to empty → banner branch
  fires; heading still renders.

## Deviations from Plan

None. Plan executed exactly as written.

A small defensive touch was added on the PDF Blade rows for the 2 new emergency
fields — `$siteEmerg['electrical_isolation_switch'] ?? ''` before the `?:` — so
a pre-w5k `generated_data` snapshot loaded after deploy does not trigger an
"undefined array key" warning at PDF render time. This is safer than the bare
`$siteEmerg['key']` pattern the existing rows use, and matches how `$hasSiteEmerg`
is computed in the surrounding `@php` block. Not a deviation — a defensive
enhancement inside the same edit surface.

Task 4's populated-table test was updated to seed all 10 emergency keys before
rendering, so the direct `$siteEmerg['fire_warden_contact']` access in the
existing template rows does not trigger a stray warning under strict test mode.
Matches the shape used by the sibling `Tier1SiteEmergencyFormAndRenderTest`.

## Test Sweep

- Task 1: `Tier1BaselineHazardsRenderTest` (3) + `Tier1RamsDefaultsServiceTest`
  (5) — green.
- Task 2: same suites re-run — green.
- Task 3: `Tier1SiteEmergencyFormAndRenderTest` (3) +
  `RamsUpdateAndDownloadTransactionTest` (1) — green.
- Task 4: `RamsSection70HeadingTest` (3, NEW) — green on first run.
- Final full sweep — `php artisan test --filter=Rams`: **293 passed, 1191
  assertions, 53.71s**.

## Deploy Notes

**No migrations.** Config-array append + view + controller edits + one new
test file. Deploy sequence:

```
git pull
php artisan config:cache
php artisan view:clear
```

Then regenerate RAMS #87 (or a fresh RAMS) to visually confirm:

1. **Baseline hazard count is 11 on a fresh RAMS with empty engineer-supplied
   hazards.** On Tilda #87 the engineer-supplied hazards still win (Tier1RamsDefaultsService
   only injects when reviewed data is empty) — this is expected.
2. **Standards Applicable table (PDF Section 3) now shows a BS EN 60825-1 row.**
   10 total rows.
3. **Site Emergency form (review page, WORKS & PERMITS tab) shows 10 fields**
   — the 8 existing + electrical isolation switch text input + fire
   extinguisher class dropdown at the bottom.
4. **PDF Section 7.0 populated-table branch shows the 2 new rows** — Electrical
   Isolation Switch + Fire Extinguisher Class Available — after the engineer
   fills in the new fields at review time.

## Deferred / Next

Carried over from the shipped plan (unchanged):

- Full H&S professional sign-off session on the 11 baseline hazards + 10
  standards + full COSHH inventory before wider client-facing rollout.
- Sensitive-space hazard baselines per space type (data centre / broadcast
  studio / medical clinical space / education/school) — different environments
  have different mandatory precaution sets.
- F10 CDM notification auto-trigger from project meta (>500 person-days or >30
  working days with >20 workers on-site simultaneously). Requires project
  metadata capture (working-day estimate, peak worker count) which does not
  exist in the current schema.

## Self-Check: PASSED

- config/rams_tier1.php — 11 baseline hazards, 10 standards references (confirmed
  via `php artisan tinker`).
- app/Http/Controllers/RamsController.php — 2 new validation rules + 2 new
  mapping keys present, `php -l` clean.
- resources/views/rams/review.blade.php — 2 new form-group blocks present under
  Site Emergency Details.
- resources/views/pdf/rams.blade.php — 2 new `<tr>` rows in Section 7.0
  populated-table branch present.
- tests/Feature/Rams/RamsSection70HeadingTest.php — file exists, `php -l` clean,
  3 methods green on first run.
- Commits 72e9d56, 68fd605, d24f593, 216435a all present in `git log --oneline`.
- Full Rams test sweep: 293 passed / 1191 assertions.
