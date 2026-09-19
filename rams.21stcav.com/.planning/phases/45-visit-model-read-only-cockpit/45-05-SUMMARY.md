---
phase: 45-visit-model-read-only-cockpit
plan: 05
subsystem: visits
tags: [console-command, backfill, idempotency, d-01, d-02, d-03, d-04, d-05, vis-02, vis-03]
requires: [45-01, 45-02]
provides:
  - "visits:backfill — idempotent, dry-run-by-default console command; the only writer of visits rows in Phase 45"
  - "HTTP proof that /survey/{token} and /worksheet/{token} behave identically before and after a backfill"
affects: [45-06, 45-07, 45-08]
tech-stack:
  added: []
  patterns:
    - "Per-run counters reset at the top of handle() — Artisan reuses one command instance per process, so a property default silently accumulates across invocations"
    - "whereHas('signoffs') as an existence test, deliberately never iterating the signoff rows, because worksheet_signoffs has no unique constraint on worksheet_id"
key-files:
  created:
    - app/Console/Commands/BackfillVisitsCommand.php
    - tests/Feature/Console/BackfillVisitsCommandTest.php
    - tests/Feature/Visits/PublicTokenRoutesUnaffectedTest.php
  modified: []
decisions:
  - "Added an orphan-no-project outcome category not listed in the plan: site_surveys.project_id and worksheets.project_id are both nullOnDelete while visits.project_id is NOT NULL, so a detached source cannot be wrapped and must be reported rather than crash the run"
  - "Idempotency proven on BOTH row count and updated_at, with Carbon::setTestNow() advanced a day between runs so a rewrite would be unmistakable"
metrics:
  duration: ~45 min
  completed: 2026-09-19
---

# Phase 45 Plan 05: `visits:backfill` Summary

`visits:backfill` wraps the history the app already holds — every `SiteSurvey`, and every
`Worksheet` that carries at least one `WorksheetSignoff` — in one typed `visits` row, dry-run by
default, idempotent under repeated `--apply`, and writing to no table but `visits`. ROADMAP v4.0
criterion 2 is delivered here, narrowed by D-01.

## Verification — actual run

PowerShell with the explicit Herd binary (`php` is absent from the Bash PATH on this machine; a
piped `php … | tail` through Bash exits 0 while executing nothing):

```
  Tests:    24 passed (111 assertions)
  Duration: 7.74s
```

Command registration:

```
visits:backfill   Wrap every site survey, and every signed worksheet, in one typed visit. Idempotent and dry-run by default.
```

## What shipped

### `app/Console/Commands/BackfillVisitsCommand.php`

Signature `visits:backfill {project?} {--apply}`. Structure copied wholesale from
`BackfillCablePortFksCommand` / `BackfillInstallRecordsCommand`: `(int)` cast on the positional arg
with its SQL-injection note, empty-set early exit, `$summary` counters, per-row
`sprintf('  %s #%d — %s: %s', …)` lines, writes inside `DB::transaction()` and only under
`--apply`, a summary block, one structured `Log::info('visits:backfill completed', …)`,
`return self::SUCCESS`.

Outcome categories: `survey` / `worksheet-signed` / `worksheet-unsigned-skipped` /
`already-wrapped` / `orphan-no-project` / `wrote`.

| Decision | How it lands in the code |
|---|---|
| **D-01** | Surveys are wrapped unconditionally. Worksheets come from `Worksheet::withTrashed()->whereHas('signoffs')->orderBy('id')` — an **existence test**, so **one visit per worksheet, never one per signoff**. `worksheet_signoffs` has no unique constraint on `worksheet_id` (its migration says so verbatim), so iterating signoffs would mint a duplicate per resignoff. Unsigned worksheets come from a separate `whereDoesntHave('signoffs')` query purely so each is printed and counted under `worksheet-unsigned-skipped`. |
| **D-02** | Worksheet visits are `Visit::TYPE_INSTALL` with `is_backfilled = true`. The docblock states plainly that `install` is an inference. |
| **D-03** | Survey visits are hardcoded `Visit::TYPE_SITE_SURVEY`. `SiteSurvey.survey_type` is never read — and a test creates a survey with `survey_type = 'install'` to prove it. |
| **D-04** | `withTrashed()` on both source queries and **no** `superseded_at` filter. `title` and `scheduled_date` are denormalised so the row still renders after a force-delete. |
| **D-05** | Dry-run by default. The `already-wrapped` `exists()` guard runs FIRST on every row, before any other per-row work; `visits_source_unique` is the DB backstop under concurrency. |

`scheduled_date` is the source's own best date — a survey's `submitted_at ?? created_at`, a
worksheet's `latestSignoff()->signed_at ?? created_at`. `status` is `completed` (it happened).
`labour_resource_ids` starts empty; nothing in the historical record says who attended.

The docblock also records, explicitly, what the command **does not** do: it never `save()`s,
`update()`s or `touch()`es a `SiteSurvey` or `Worksheet` (both hold live public access tokens and
carry deliberate `$fillable` omissions from a security re-audit); it deletes nothing; it does not
backfill `install_record_id` (45-04's `install-records:backfill` owns programme linking, and a
survey visit routinely predates any install record); it assigns no labour.

### `tests/Feature/Console/BackfillVisitsCommandTest.php` — 19 cases

Dry-run writes nothing · `--apply` creates one visit per survey and one per signed worksheet · an
unsigned worksheet creates none · **a worksheet with THREE signoffs creates exactly ONE visit** ·
a second `--apply` creates nothing and rewrites nothing · a dry run after an apply reports every
row `already-wrapped` · counters reset per run · soft-deleted survey wrapped · superseded survey
wrapped · soft-deleted signed worksheet wrapped · survey visits typed `site_survey` + backfilled +
`install_record_id` null · worksheet visits typed `install` + backfilled · the dead `survey_type`
column is never used · `scheduled_date`/`title` denormalised from the source · a visit still
renders after its source is force-deleted · a source with no project lands in
`orphan-no-project` · the project argument scopes the backfill · **no `site_surveys` or
`worksheets` row is written** · an empty database is a clean no-op.

The idempotency proof covers **both** dimensions the plan demanded:

- **row count** — `Visit::count()` identical after the second `--apply`, plus
  `expectsOutputToContain('already-wrapped: 2  |  orphan-no-project: 0  |  wrote: 0')`
- **`updated_at`** — every visit's raw `updated_at` captured after run 1 and compared after run 2,
  with `Carbon::setTestNow()` advanced a full day in between so any rewrite would change the value
  unmistakably.

`test_no_site_survey_or_worksheet_row_is_written` compares the **entire `getRawOriginal()` array**
of every survey and worksheet (trashed included) before and after, again across a one-day clock
jump, then asserts `updated_at` individually so a failure names the property. It also asserts
nothing was deleted.

### `tests/Feature/Visits/PublicTokenRoutesUnaffectedTest.php` — 5 cases

Route names were re-derived from `routes/web.php` at execution time rather than trusted from the
plan: `survey.show` (`:82`) and `public-worksheet.show` (`:112`).

Each case performs **real HTTP GETs**, not a route-table check: it GETs the page, asserts 200 plus
distinctive page markers, runs `visits:backfill --apply`, asserts the backfill actually produced
visits, then GETs the **same URL** again and asserts the same status code and the same markers —
and that the `access_token` is byte-identical across the run. Cases: the survey link · the
worksheet link · an **unsigned** worksheet's link still works even though it deliberately gets no
visit · an unknown token still 404s afterwards · a second backfill run leaves both pages and both
tokens untouched.

One documented subtlety: `SurveyController::show` lazily seeds `survey_data` on the first render of
a survey that has none (`:69-72`) — a write owned entirely by the public route, not the backfill. A
`warmUp()` helper performs one GET to settle it so the before/after comparison measures the
backfill and nothing else.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Added an `orphan-no-project` outcome category**
- **Found during:** Task 1
- **Issue:** the plan's category list has no home for a source whose `project_id` is NULL. Both
  `site_surveys.project_id` (`2026_03_14_000020_add_project_id_to_module_tables.php`) and
  `worksheets.project_id` (`2026_04_11_000001_create_worksheets_table.php:25-28`) are
  `nullable()->nullOnDelete()`, while `visits.project_id` is **NOT NULL** (the project is a
  visit's mandatory parent, D-06 refinement). Without a guard the command would throw on the
  first detached survey in production.
- **Fix:** second pre-flight guard in `skipRow()`, reported in its own category — exactly the
  shape 45-04's `BackfillInstallRecordsCommand` already uses for the same situation.
- **Files modified:** `app/Console/Commands/BackfillVisitsCommand.php`
- **Commit:** `fd12c2ed`

**2. [Rule 1 - Bug] Per-run counters accumulated across invocations**
- **Found during:** Task 2
- **Issue:** `$summary` was initialised as a property default. Artisan resolves a command **once**
  and reuses the instance for every invocation in the same process, so a second run reported the
  cumulative total of both runs — the idempotency summary printed `already-wrapped: 1 | wrote: 1`
  when nothing had been written. The bug lived in the exact line of output an operator would read
  to decide whether a re-run was safe.
- **Fix:** reset `$this->summary` at the top of `handle()`, with a comment naming the cause.
  A dedicated regression test (`test_the_summary_counters_are_reset_per_run_not_accumulated`)
  asserts the full summary line for two consecutive runs.
- **Files modified:** `app/Console/Commands/BackfillVisitsCommand.php`,
  `tests/Feature/Console/BackfillVisitsCommandTest.php`
- **Commits:** `fd12c2ed`, `7ee2b40d`

**3. [Rule 1 - Bug] Two `expectsOutputToContain()` calls against the same output line can never both pass**
- **Found during:** Task 2
- **Issue:** `PendingCommand` registers each expected substring as a separate Mockery `doWrite`
  expectation (`vendor/laravel/framework/src/Illuminate/Testing/PendingCommand.php:614-621`).
  Mockery resolves a call against the **first** matching expectation, so asserting
  `'already-wrapped: 2'` and `'wrote: 0'` — both on one summary line — leaves the second
  permanently unsatisfied and fails for a reason unrelated to the code.
- **Fix:** one substring spanning the whole assertion, with the trap documented inline so the next
  reader does not "helpfully" split it again.
- **Files modified:** `tests/Feature/Console/BackfillVisitsCommandTest.php`
- **Commit:** `7ee2b40d`

## Guarded files — untouched, verified

`git diff --name-only 4abd2b24 --` (the phase-start SHA from `45-BASELINE.md`) returns **empty**
for all four:

| File | Status |
|---|---|
| `app/Models/SiteSurvey.php` | untouched — `$fillable` security omissions intact |
| `app/Models/Worksheet.php` | untouched — same |
| `routes/web.php` | untouched — no public route added, renamed or reordered |
| `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` | untouched — the cockpit is a staff-auth surface and that file's docblock (`:55-61`) forbids adding one to its `CLIENT_FACING_PATHS` scan |

`45-BASELINE.md` was not edited and no test in the D-06 gate subset was touched. No package was
installed. No `migrate:fresh --env=testing` was run anywhere in this plan.

## Not run here, deliberately

`visits:backfill --apply` against real data. That is a deployment step on the live VPS, not part of
this plan.

## Concurrency and boundaries

`.planning/STATE.md` and `.planning/ROADMAP.md` were intentionally left to the orchestrator —
45-05 is a wave-3 plan with concurrent siblings, and two agents editing the same state file clobber
each other (the same call 45-02 made).

## Commits

| Commit | Scope |
|---|---|
| `25ca3d16` | `test(45-05)`: failing `visits:backfill` + public-token-routes tests (23 RED — "The command \"visits:backfill\" does not exist") |
| `fd12c2ed` | `feat(45-05)`: the idempotent `visits:backfill` command |
| `7ee2b40d` | `test(45-05)`: per-run counter regression + Mockery substring fix (24 passed) |

## Self-Check: PASSED

All three files exist on disk; all three commits resolve in `git log`.
