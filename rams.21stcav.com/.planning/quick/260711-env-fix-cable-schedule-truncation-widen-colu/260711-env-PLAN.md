---
quick_id: 260711-env
slug: fix-cable-schedule-truncation-widen-columns
date: 2026-07-11
description: Fix live cable schedule generation failing with SQLSTATE[22001] "Data too long for column 'from_location'" — widen from_location/to_location/cable_type to TEXT and clip long equipment names before concatenation.
type: quick
autonomous: true
files_modified:
  - database/migrations/2026_07_11_000000_widen_cable_schedule_item_location_columns.php
  - app/Services/CableScheduleGeneratorService.php
---

<objective>
Cable schedule generation is crashing on live with:

  SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'from_location'

Root cause is two-layered:

1. `CableScheduleGeneratorService::generate()` (line 91) and
   `CableScheduleGeneratorService::buildRowsFromEquipmentLines()` (line 155)
   both do `$roomName . ' — ' . $equipName` where `$equipName` falls back from
   `name` to `description` — and `description` on some QuoteWerks lines is a
   200+ char marketing blurb (e.g. "OE Electrics Phase On/Under table power
   module PHASE is the perfect solution for providing discreet…"). Combined
   with the room name, it blows past VARCHAR(255).

2. The `cable_schedule_items` schema declares `from_location`, `to_location`,
   and `cable_type` as unqualified `->string(...)` → VARCHAR(255). No headroom.

Fix both layers, non-lossy:

- Widen the three columns to TEXT via a new migration (TEXT holds any
  VARCHAR(255) verbatim, existing data preserved, safe rollback back to
  VARCHAR(255)).
- Clip `$equipName` with `Str::limit($equipName, 180, '…')` before every
  `from_location` concatenation in the service. Belt-and-braces so the XLSX
  cell stays human-readable even after the schema widen.

Purpose: unblock live cable schedule generation without losing data.
Output: 1 new migration + 1 patched service class.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@app/Services/CableScheduleGeneratorService.php
@database/migrations/2026_03_09_000002_create_cable_schedules_table.php

<interfaces>
<!-- Current column definitions in 2026_03_09_000002_create_cable_schedules_table.php lines 22-34 -->

```php
Schema::create('cable_schedule_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('cable_schedule_id')->constrained()->cascadeOnDelete();
    $table->string('cable_id')->nullable();
    $table->string('from_location')->nullable();   // VARCHAR(255) — TOO SMALL
    $table->string('to_location')->nullable();     // VARCHAR(255) — TOO SMALL
    $table->string('cable_type')->nullable();      // VARCHAR(255) — TOO SMALL
    $table->string('cores')->nullable();
    $table->decimal('approx_length_m', 8, 1)->nullable();
    $table->text('notes')->nullable();
    $table->unsignedSmallInteger('sort_order')->default(0);
    $table->timestamps();
});
```

Model: `App\Models\CableScheduleItem` (mass-assigned in service).

The two write sites in `CableScheduleGeneratorService`:

- `generate()` line 88-98 — `CableScheduleItem::create([...])`
- `buildRowsFromEquipmentLines()` line 152-162 — returns rows array

Both build `from_location` as `$roomName . ' — ' . $equipName` (or
`$sourceLabel . ' — ' . $equipName` in the manual flow) where
`$equipName = (string) ($item['name'] ?? $item['description'] ?? '')`.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Widen cable_schedule_items location + cable_type columns to TEXT</name>
  <files>database/migrations/2026_07_11_000000_widen_cable_schedule_item_location_columns.php</files>
  <action>
Create a new migration at
`database/migrations/2026_07_11_000000_widen_cable_schedule_item_location_columns.php`
with the class returned as an anonymous `Migration` subclass (match the style
of the sibling `2026_03_09_000002_create_cable_schedules_table.php`).

`up()`:
- Use `Schema::table('cable_schedule_items', function (Blueprint $table) { ... })`.
- Call `$table->text('from_location')->nullable()->change();`
- Call `$table->text('to_location')->nullable()->change();`
- Call `$table->text('cable_type')->nullable()->change();`

`down()`:
- Reverse to VARCHAR(255), nullable, no default — matching the original schema
  in `2026_03_09_000002_create_cable_schedules_table.php` exactly.
- `$table->string('from_location', 255)->nullable()->change();`
- `$table->string('to_location', 255)->nullable()->change();`
- `$table->string('cable_type', 255)->nullable()->change();`

Requires `doctrine/dbal` for `->change()` — already in `composer.json` per
CLAUDE.md, so no composer install needed.

Do NOT touch `cores`, `notes` (already TEXT), `sort_order`, `cable_id`, or
`approx_length_m`. Only the three columns above.
  </action>
  <verify>
    <automated>php artisan migrate --pretend 2>&amp;1 | grep -c "widen_cable_schedule_item_location_columns"</automated>
  </verify>
  <done>
Migration file exists, `php artisan migrate --pretend` lists it, and `php -l`
on the file returns "No syntax errors". Running `php artisan migrate` locally
converts the three columns to `TEXT` (verify with `SHOW COLUMNS FROM
cable_schedule_items`). Rollback via `php artisan migrate:rollback --step=1`
returns the columns to `varchar(255)` with the same nullable + no-default
signature as the original create migration.
  </done>
</task>

<task type="auto">
  <name>Task 2: Clip equipment name in CableScheduleGeneratorService before from_location concatenation</name>
  <files>app/Services/CableScheduleGeneratorService.php</files>
  <action>
Add `use Illuminate\Support\Str;` to the `use` block at the top of the file
(currently only imports `ProjectDataService`, `CableSchedule`, `CableScheduleItem`, `Log`).

Patch two sites — same clipping rule at both:

1. In `generate()` around line 80, after `$equipName = (string) ($item['name']
   ?? $item['description'] ?? '');` and after the `if ($equipName === '')
   continue;` guard, add:

       $equipNameShort = Str::limit($equipName, 180, '…');

   Then change the `CableScheduleItem::create([...])` call so
   `'from_location' => $roomName . ' — ' . $equipNameShort,` — do NOT touch
   `$equipName` elsewhere; `inferCableRun($equipName)` still receives the full
   string so classification keeps working against untrimmed marketing copy.

2. In `buildRowsFromEquipmentLines()` around line 145, same pattern:
   after `$equipName = (string) ($item['name'] ?? $item['description'] ?? '');`
   and its empty-guard, add:

       $equipNameShort = Str::limit($equipName, 180, '…');

   Change the returned row so `'from_location' => trim($sourceLabel) . ' — ' .
   $equipNameShort,`. Keep `inferCableRun($equipName)` on the full string.

Rationale for 180: leaves ~70 chars for room name + separator + horizontal
ellipsis and keeps XLSX cells visually manageable even though the column is
now TEXT.

Do NOT reformat unrelated lines, do NOT change classification logic, do NOT
change `to_location` / `cable_type` / `notes` — the schema widen is the
schema-level safety net for those; this task is only about keeping the
displayed name readable and defensively short.
  </action>
  <verify>
    <automated>php -l app/Services/CableScheduleGeneratorService.php &amp;&amp; grep -c "Str::limit(\$equipName, 180" app/Services/CableScheduleGeneratorService.php</automated>
  </verify>
  <done>
`php -l` returns "No syntax errors". `grep -c "Str::limit(\$equipName, 180"`
returns `2` (once per method). `use Illuminate\Support\Str;` present exactly
once. Existing CableSchedule test suite (`php artisan test
--filter=CableSchedule`) still passes — no test file changes needed, this is
purely a defensive length clip.
  </done>
</task>

</tasks>

<verification>
End-to-end smoke check after both tasks:

1. `php artisan migrate` — new migration runs cleanly on local.
2. `php -l app/Services/CableScheduleGeneratorService.php` — clean.
3. `php artisan test --filter=CableSchedule` — all existing green tests still
   green (no regressions).
4. Regenerate a cable schedule locally on a project that previously
   triggered the truncation (or manually craft a fixture line with a 250+
   char `description` and empty `name`) — verify no SQLSTATE[22001], and
   confirm the `from_location` cell in the resulting XLSX shows the clipped
   name with a trailing `…`.

No new tests required for this fix — the failure mode is a schema-length
issue and a display-cleanliness issue, both empirically fixed by the two
changes above.
</verification>

<success_criteria>
- Live `Data too long for column 'from_location'` error no longer possible:
  columns are TEXT (schema-side safety) AND `$equipName` is clipped to 180
  chars before concatenation (display-side safety).
- Rollback path exists and is safe (VARCHAR(255) can hold any prior insert
  since the service now clips inputs first).
- No changes outside the two files listed. No test churn. No behavioural
  change to classification, cable-type inference, or `to_location` values.
</success_criteria>

<deferred_ops>
## Deferred / Ops (not a code task — surface in SUMMARY.md for the user)

The prod queue worker cron @reboot entry for the RAMS box is still pending
sign-off from the sysadmin side. It is NOT part of this fix. Executor: when
writing the SUMMARY.md, add a "Ops follow-up" bullet reminding the user:

> Consider adding `@reboot cd /home/rams/rams.21stcav.com && php artisan
> queue:work --tries=1 --timeout=0 &` (or a systemd unit) so the RAMS queue
> resumes automatically after a VPS reboot. Not required for this hotfix —
> cable schedule generation is a synchronous artisan/HTTP call path, not a
> queued job. Noted here so it doesn't get lost.
</deferred_ops>

<output>
Create `.planning/quick/260711-env-fix-cable-schedule-truncation-widen-colu/260711-env-SUMMARY.md` when done.
</output>
