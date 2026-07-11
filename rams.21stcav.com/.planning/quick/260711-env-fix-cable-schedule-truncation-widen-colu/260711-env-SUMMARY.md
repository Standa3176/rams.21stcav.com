---
quick_id: 260711-env
slug: fix-cable-schedule-truncation-widen-columns
date_completed: 2026-07-11
type: quick
autonomous: true
tags: [cable-schedule, migration, hotfix, live-bug]
requires: []
provides:
  - text-width cable_schedule_items.from_location column
  - text-width cable_schedule_items.to_location column
  - text-width cable_schedule_items.cable_type column
  - clipped-equipment-name defensive layer in CableScheduleGeneratorService
affects:
  - Cable schedule generation (deterministic pipeline)
  - Cable schedule manual upload flow (CableScheduleController::store)
tech_stack:
  patterns:
    - Two-layer defensive fix (schema widen + display clip)
    - Non-lossy migration with safe reversible down() to VARCHAR(255)
key_files:
  created:
    - database/migrations/2026_07_11_000000_widen_cable_schedule_item_location_columns.php
  modified:
    - app/Services/CableScheduleGeneratorService.php
decisions:
  - Widen to TEXT (not larger VARCHAR) — no cap, safe headroom for arbitrarily long marketing blurbs
  - Clip $equipName to Str::limit(180, '…') before concatenation — keeps XLSX cell visually manageable even though column is now TEXT
  - Do NOT clip $equipName before inferCableRun() — classification/keyword matching must see the full untrimmed string
metrics:
  duration: ~5m
  commits: 2
---

# Quick 260711-env: Fix Cable Schedule Truncation — Widen Columns Summary

Fixed live `SQLSTATE[22001]: Data too long for column 'from_location'` crash on cable schedule generation by widening three `cable_schedule_items` columns to TEXT and defensively clipping equipment names to 180 chars before the `roomName — equipName` concatenation.

## What Shipped

**Two atomic commits** landed on `feat/worksheet-classifier-universal`:

- **`ab64ac8`** — `feat(cable-schedule): widen cable_schedule_items location + cable_type columns to TEXT`
  New migration `2026_07_11_000000_widen_cable_schedule_item_location_columns.php`. `up()` moves `from_location`, `to_location`, `cable_type` from `VARCHAR(255)` → `TEXT` via `->change()` (doctrine/dbal ^4.4 already in composer.json). `down()` reverses to `VARCHAR(255)` nullable, matching the original create migration exactly.

- **`d0ab3c4`** — `fix(cable-schedule): clip long equipment names before from_location concat`
  Added `use Illuminate\Support\Str;` import. Two write sites in `CableScheduleGeneratorService` (`generate()` line ~80 and `buildRowsFromEquipmentLines()` line ~145) now compute `$equipNameShort = Str::limit($equipName, 180, '…')` after the empty-guard, and pass `$equipNameShort` into the `from_location` concat. `inferCableRun($equipName)` still receives the untrimmed name so classification stays lossless.

## Root Cause

Two-layered bug:

1. **Service layer:** `$equipName` falls back from `name` → `description`. On some QuoteWerks lines, `description` is a 200+ char marketing blurb (OE Electrics Phase power module was the trigger example). Combined with a room name via `' — '`, blows past VARCHAR(255).
2. **Schema layer:** Three `cable_schedule_items` columns were declared as unqualified `->string(...)` in the original `2026_03_09_000002_create_cable_schedules_table.php` migration → VARCHAR(255). No headroom for edge cases.

Fixed both layers so:
- Schema no longer has a bounded upper limit (TEXT holds any prior VARCHAR(255) value verbatim — the widen is genuinely lossless).
- Display stays human-readable via the 180-char clip — even after the widen, XLSX cells don't become 300-char wrap-monsters.

## Verification

- `php -l` on both files: **no syntax errors**.
- `grep -c "Str::limit(\$equipName, 180"` on the service: **2** (one per method), as required.
- `use Illuminate\Support\Str;` present exactly once.
- Original create-migration schema signature preserved on the `down()` path — nullable, VARCHAR(255), no default.

## Deviations from Plan

None. Plan executed exactly as written — file paths, method signatures, and clip length (180) all as specified. Migration filename, migration structure, service edit locations, and grep count all matched the plan's verification checks.

## Deferred / Ops

**RAMS queue worker is not running on live.** To fix persistently, add a cron entry mirroring the SCC worker (see `ps -ef | grep queue:work` output from `/home/stcav/service.21stcav.com` — that one's been running since May 21). Suggested cron `@reboot`:

```
sudo -u stcav /usr/local/bin/php /home/stcav/rams.21stcav.com.git/rams.21stcav.com/artisan queue:work --tries=3 --timeout=600 --sleep=3 >> /home/stcav/rams.21stcav.com.git/rams.21stcav.com/storage/logs/queue.log 2>&1 &
```

Not required for this hotfix — cable schedule generation is a synchronous artisan/HTTP call path, not a queued job. Logging it here so it doesn't get lost.

Plan's `<deferred_ops>` block also referenced a lighter fallback (`@reboot cd /home/rams/rams.21stcav.com && php artisan queue:work --tries=1 --timeout=0 &`) — either shape is fine; the SCC-mirroring variant above is preferred because it matches an already-battle-tested worker on the same VPS.

## Deploy Path

Per project convention (RAMS deploy = git push then SSH + git pull + migrate):

1. `git push` on `feat/worksheet-classifier-universal` (or merge to `live` if that's the deploy branch).
2. SSH to the RAMS box, `cd /home/rams/rams.21stcav.com` (adjust path), `git pull`.
3. Run `php artisan migrate --force` — the new migration `2026_07_11_000000_widen_cable_schedule_item_location_columns` will fire and widen the three columns.
4. No cache clear or queue restart required — service change is picked up on the next request.

Regenerate the cable schedule for the project that was throwing SQLSTATE[22001] to confirm.

## Self-Check: PASSED

- `database/migrations/2026_07_11_000000_widen_cable_schedule_item_location_columns.php` — **FOUND**
- `app/Services/CableScheduleGeneratorService.php` — **MODIFIED** (diff shows +7/-2)
- Commit `ab64ac8` — **FOUND** in `git log`
- Commit `d0ab3c4` — **FOUND** in `git log`
