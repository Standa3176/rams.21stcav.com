---
quick_id: 260711-ml0
slug: cable-schedule-intelligence-t1-bundle-signal-type-device-preference-word-boundary
type: quick
completed: 2026-07-11
tasks_completed: 3
commits:
  - hash: b9676b6
    task: T1-A
    message: "feat(cable-schedule): T1-A signal_type column + tinted Signal XLSX column"
  - hash: 105432d
    task: T1-B
    message: "feat(cable-schedule): T1-B prefer Device model over quote lines when project has one"
  - hash: 0eae352
    task: T1-C
    message: "fix(cable-schedule): T1-C word-boundary matchesAny() kills 'mic' / 'amp' / 'csc' false positives"
files_created:
  - database/migrations/2026_07_11_000001_add_signal_type_to_cable_schedule_items.php
  - tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php
files_modified:
  - app/Models/CableScheduleItem.php
  - app/Services/CableScheduleGeneratorService.php
  - app/Services/CableScheduleXlsxService.php
  - tests/Feature/Cable/CableScheduleXlsxRegressionTest.php
tests_run:
  - filter: CableScheduleGeneratorService
    passed: 4
    assertions: 18
  - filter: CableScheduleXlsxRegression
    passed: 3
    assertions: 60
  - filter: CableScheduleCompletionNotification
    passed: 2
    assertions: 8
  - filter: CableSchedule (full)
    passed: 35
    assertions: 213
---

# Quick 260711-ml0: Cable Schedule Intelligence T1 Bundle Summary

Cable schedule engineering upgrade — three linked improvements shipped as one
plan. Rows now carry a `signal_type` classification that surfaces as a tinted
Signal column in the XLSX. `generate()` prefers the `Device` collection over
quote lines when a project has one. `matchesAny()` no longer treats "Microsoft"
as a microphone, "Ceiling Lamp" as an amplifier, or "Cisco" as a HDBaseT
extender.

## What Shipped

### T1-A — signal_type column + tinted XLSX Signal column (`b9676b6`)

- New migration `2026_07_11_000001_add_signal_type_to_cable_schedule_items.php`
  adds nullable `VARCHAR(20) signal_type` after `cable_type` on
  `cable_schedule_items`. Additive, `->change()`-free, safe rollback.
- `CableScheduleItem::$fillable` gains `'signal_type'` between `sort_order`
  and the Phase 22 FK block. Existing entries verbatim.
- `CableScheduleGeneratorService::inferCableRun()` return shape extended
  from 4 keys → 5 keys. All 14 branches mapped per the plan's mapping
  reference (video / speaker / audio / control / network / unknown).
  Docblock updated to reflect the new contract.
- `generate()` (line ~99) and `buildRowsFromEquipmentLines()` (line ~165)
  both propagate `signal_type` through to the persisted `CableScheduleItem`
  rows / returned array rows.
- `CableScheduleXlsxService`: 8 columns → 9 columns. New "Signal" header
  inserted at column `E` between "Cable Type" (`D`) and "Cores" (now `F`).
  All 6 `A…:H…` merge sites bumped to `A…:I…` (title, project info,
  header row, per-data-row style, per-blank-row style, footer). Widths
  array updated to `[A=12, B=22, C=22, D=18, E=10, F=10, G=12, H=30, I=14]`.
- Per-row Signal cell tinted at ~15% opacity via a new private
  `tintedFill(string $hex): string` helper — blends `85% white + 15% base`
  RGB, returns `'FF' . XXXXXX` ARGB. Falls back to white on any parse
  failure. Header cell keeps the standard navy fill. Rows with
  `signal_type = null` keep the row's default background.
- Regression test comment bump — `test_xlsx_byte_identical_for_null_and_populated_fks`
  gets a one-line note explaining that the 9-column layout still hashes
  identically for both fixtures because both leave `signal_type` NULL.

### T1-B — Device-preferred generation path (`105432d`)

- `use App\Models\Device;` + `use Illuminate\Support\Collection;` added.
- `generate()` now short-circuits at the top: if
  `Device::where('project_id', $project->id)->get()` returns any rows, the
  method delegates to a new private `generateFromDevices()`. Zero-device
  projects (every project today) fall through to the existing quote-line
  path unchanged — the guard is inert on the current fleet.
- `generateFromDevices()` builds `from_location` as
  `"<room> — <manufacturer> <model><rack suffix>"`, where the rack suffix
  is `" (Rack, {u}U)"` when both `is_rack_mounted` and `u_height` are
  truthy (`u_height` is `decimal:2` cast, trailing zeros stripped).
  `notes` gets a `"[<signal_role>] "` prefix when `signal_role` is set —
  kept as an engineering hint, orthogonal to `signal_type` from
  `inferCableRun`.
- `buildRowsFromEquipmentLines()` (manual upload path) stays quote-line
  driven — no project context there, so no Device collection to prefer.
- Phase 22 port FK columns (`source_device_id`, `source_port_id`,
  `dest_device_id`, `dest_port_id`, `connector_override_note`) explicitly
  untouched — v1.3 surface-guard regression test still passes.

### T1-C — word-boundary matchesAny (`0eae352`)

- `matchesAny(string $haystack, array $keywords): bool` swaps
  `str_contains` for
  `preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $haystack) === 1`.
  Case-insensitive `i` flag is defensive — all current call sites already
  lowercase the haystack, but a future consumer might not.
- Multi-word keywords (`patch panel`, `audio processor`, `room kit`,
  `cisco switch`) still match because `\b` sits between tokens, not
  per-char. Hyphenated + digit-anchored keywords (`q-sys`, `4k`) also
  match under PCRE `\b` semantics — verified by inspection against every
  keyword list in the file.
- New unit test file
  `tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php` — 4
  cases, 18 assertions total. Tests exercise the public
  `buildRowsFromEquipmentLines` API so they also cover T1-A's
  `signal_type` shape extension end-to-end. No `RefreshDatabase` — pure
  in-memory.

## Test Results

| Filter                                        | Passed | Assertions |
| --------------------------------------------- | ------ | ---------- |
| `CableScheduleGeneratorService` (new, T1-C)   | 4      | 18         |
| `CableScheduleXlsxRegression`                 | 3      | 60         |
| `CableScheduleCompletionNotification`         | 2      | 8          |
| `CableSchedule` (broad — union of everything) | 35     | 213        |

All lints (`php -l`) clean on the 5 modified `.php` files + 1 new migration
+ 1 new unit test file.

## Deviations from Plan

**None functional.** One micro-observation:

- The plan's T1-B done criterion said
  `grep -c "generateFromDevices"` should return `2` (one definition,
  one call site). It actually returns `3` — the third hit is the
  `Log::info('CableScheduleGeneratorService: generateFromDevices complete', ...)`
  line at the end of the new method, which the plan itself specified as
  part of the method body. This is not a deviation from the code the
  plan asked for; only the plan's `grep` prediction was off by one.

- The `CableScheduleXlsxRegressionTest::test_xlsx_byte_identical_for_null_and_populated_fks`
  case flaked once during T1-A verification (took 29.73s in a cold-cache
  run and crossed a wall-clock second boundary in PhpSpreadsheet's
  `dcterms:created` metadata). Passed on every subsequent run in ~2.7s.
  This is a pre-existing PhpSpreadsheet timing quirk unrelated to any
  T1 change — my changes don't touch `dcterms:created`, and `build()`
  never invokes `generate()`. Documented here for the next agent that
  sees the intermittent fail — the fix if it ever becomes real would
  be to freeze `Carbon::setTestNow(...)` at the top of the test.

## Deploy Notes

**Migration required on live** — the deploy step is:

```bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan migrate --force
```

Only one migration to run
(`2026_07_11_000001_add_signal_type_to_cable_schedule_items.php`).
Additive nullable VARCHAR(20) column, no `->change()` on existing
columns, `down()` drops the column cleanly. No downtime beyond the
`ALTER TABLE` cost, which on the current row count is instant.

**Retro-populate:** existing `cable_schedule_items` rows have
`signal_type = NULL` and will render as an empty (untinted) cell in the
XLSX Signal column. To backfill, regenerate the affected schedule from
the RAMS UI — the generator will re-classify every row through the new
`inferCableRun` return shape and stamp `signal_type` on write. No manual
SQL required.

**Queue worker:** the RAMS queue worker on live is already running via
the `* * * * *` cron on the shared VPS — no worker setup required.
`BuildCableScheduleJob` (unchanged) will pick up regeneration requests
through the same queue.

## Deferred / Next

Surfaces from the plan's `<deferred_next>` — each is its own quick task:

- **T1-D — Quoted cable products get their own signal_type.** Parse
  `Cat6a` / `HDMI 2.1` / `speaker cable` products in `isCableProduct()`
  so the cable itself annotates downstream rows or surfaces as its own
  row with `signal_type = 'network' | 'video' | 'speaker'`. Not urgent
  — no user-visible gap today.
- **T1-E — Distance heuristics.** `approx_length_m` is `null` on every
  auto-generated row. Once room→rack proximity data (or a Device→Rack
  FK) exists, infer a floor estimate (~5m default, 15m ceiling drops,
  25m cross-room HDBaseT). Feeds engineering's cable-budget sheet.
- **T2-A — Auto-populate port FKs (`source_device_id` / `source_port_id`).**
  Now that T1-B fires when the project has Devices, the Device also
  implies a source port on its stencil. When the destination Device is
  known (e.g. display + codec in the same room), populate the four FK
  columns directly. This is the "port picker for free" outcome and
  unlocks the Phase 22 renderer without manual clicks.
- **T2-B — Signal-path DAG.** Given `signal_role` per Device + the
  tinted `signal_type` per cable row, walk the graph to produce a
  topological render (source → processor → destination). Full v2.0
  engineering-grade drawings scope.

## Self-Check

**Created files:**

- `database/migrations/2026_07_11_000001_add_signal_type_to_cable_schedule_items.php` — FOUND
- `tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php` — FOUND

**Modified files:**

- `app/Models/CableScheduleItem.php` — FOUND (fillable now includes `signal_type`)
- `app/Services/CableScheduleGeneratorService.php` — FOUND (Device import, guard, `generateFromDevices`, word-boundary `matchesAny`)
- `app/Services/CableScheduleXlsxService.php` — FOUND (9-column layout, `tintedFill()` helper)
- `tests/Feature/Cable/CableScheduleXlsxRegressionTest.php` — FOUND (comment bump above `test_xlsx_byte_identical_for_null_and_populated_fks`)

**Commits:**

- `b9676b6` — T1-A — FOUND on `feat/worksheet-classifier-universal`
- `105432d` — T1-B — FOUND on `feat/worksheet-classifier-universal`
- `0eae352` — T1-C — FOUND on `feat/worksheet-classifier-universal`

## Self-Check: PASSED
