---
phase: 45-visit-model-read-only-cockpit
plan: 02
subsystem: visits
tags: [schema, model, factory, unit-tests, d-04, idempotency]
requires: [45-01]
provides:
  - "visits table (project-parented, source-pair identified, unique composite index)"
  - "App\\Models\\Visit — TYPE_*/STATUS_*/SOURCE_* constants, withTrashed() source(), derived isSuperseded()"
  - "Database\\Factories\\VisitFactory — default + backfilledFromSurvey/backfilledFromWorksheet states"
affects: [45-04, 45-05, 45-06, 45-07]
tech-stack:
  added: []
  patterns:
    - "Non-FK (source_type, source_id) pair with unique composite index — deliberate deviation from the repo's real-FK habit, documented at the definition site"
    - "Derived-not-stored superseded marker read off a withTrashed() source"
key-files:
  created:
    - database/migrations/2026_09_19_140000_create_visits_table.php
    - app/Models/Visit.php
    - database/factories/VisitFactory.php
    - tests/Unit/Models/VisitTest.php
  modified: []
decisions:
  - "install_record_id shipped as a bare nullable indexed column — no constrained(); Plan 45-04 creates install_records and owns the FK"
  - "isSuperseded() returns false when the source has been force-deleted (no source to read state from); the denormalised title/scheduled_date carry rendering"
metrics:
  duration: ~20 min
  completed: 2026-09-19
---

# Phase 45 Plan 02: visits table + Visit model Summary

The `visits` spine landed: a project-parented, typed visit row that wraps a `SiteSurvey` or
`Worksheet` through a non-FK `(source_type, source_id)` pair with a unique composite index, so it
survives its paperwork being soft-deleted, superseded or force-deleted (D-04) while making the
45-05 backfill idempotent at the database level rather than only in application code.

## What shipped

### `visits` table — `database/migrations/2026_09_19_140000_create_visits_table.php`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `project_id` | foreignId, **NOT NULL**, `constrained('projects')->cascadeOnDelete()` | the mandatory parent, per the D-06 refinement |
| `install_record_id` | unsignedBigInteger, **nullable**, indexed | **no `constrained()`** — 45-04 owns the FK |
| `type` | string(32), indexed | |
| `status` | string(24), default `completed`, indexed | |
| `scheduled_date` | date, nullable, indexed | |
| `labour_resource_ids` | json, nullable | array of ints |
| `source_type` | string(24), nullable | `site_survey` \| `worksheet` |
| `source_id` | unsignedBigInteger, nullable | **not a FK** |
| `is_backfilled` | boolean, default false, indexed | D-02's explicit queryable marker |
| `title` | string(200), nullable | denormalised so the row renders standalone |
| `summary` | text, nullable | denormalised |
| `created_at` / `updated_at` | timestamps | |

Indexes: `unique(['source_type','source_id'], 'visits_source_unique')`, `index(['project_id','type'])`,
plus the single-column indexes above. **No `softDeletes()`** — no delete path in this phase, and the
trait's global scope would be one more thing the backfill guard must reason about.

The migration carries a docblock stating, at the definition site, *why* `source_id` is not a foreign
key: every FK in this schema is `cascadeOnDelete` or `nullOnDelete`; a cascade would hard-delete the
visit when a survey is force-deleted and `nullOnDelete` would silently detach it — either un-happens
the trip to site, which D-04 forbids. It says explicitly that this deviates from the repo's general
real-FK habit on purpose, so a later reviewer does not "fix" it.

### `App\Models\Visit`

- `TYPE_SITE_SURVEY` / `TYPE_FIRST_FIX` / `TYPE_INSTALL` / `TYPE_PROGRAMMING` / `TYPE_SNAG` /
  `TYPE_COMMISSIONING`, collected in `TYPES` (exactly 6, **no `legacy`** — D-02).
- `STATUS_PLANNED` / `STATUS_COMPLETED` in `STATUSES`. No lifecycle states pre-built for Phase 46.
- `SOURCE_SITE_SURVEY` / `SOURCE_WORKSHEET` in `SOURCE_TYPES`.
- Casts: `scheduled_date => date`, `labour_resource_ids => array`, `is_backfilled => boolean`.
- `project(): BelongsTo` — the mandatory parent.
- `source(): ?Model` — a plain method, not a relation. Branches on the two hardcoded `SOURCE_*`
  constants with `default => null` (T-45-02-01: a class name is never resolved out of the column),
  and always uses `withTrashed()`. Returns null when the source was force-deleted.
- `isBackfilled(): bool`, `isSuperseded(): bool` (derived from the resolved source's `superseded_at`
  / `deleted_at` — never stored), `labourResources(): Collection`.
- No create/update helper, observer, event or route. No file under `app/Http/`, `routes/` or
  `resources/views/` was touched.

## Verification — actual runs

Migration, against a **temp sqlite file** via `Join-Path $env:TEMP`, with teardown. `--env=testing`
was never used anywhere in this plan:

```
2026_09_19_140000_create_visits_table ...... 67.16ms DONE
```

Unit tests, PowerShell + explicit Herd php84 binary:

```
Tests:    13 passed (44 assertions)
Duration: 6.14s
```

The 13 cases cover the six-type vocabulary and the absence of `legacy`, the two statuses, the source
constants, the factory default shape, `labour_resource_ids` round-tripping as two ints,
`labourResources()` resolution, the backfilled marker being queryable, a poisoned `source_type`
resolving to null, and the four load-bearing properties: soft-deleted survey source survives,
superseded survey source survives and is marked (with **no** `whereNull('superseded_at')` filter in
the query), force-deleted source still renders from `title`/`scheduled_date`, soft-deleted worksheet
source survives, and a duplicate `(source_type, source_id)` insert throws `QueryException`.

## Deviations from Plan

None affecting the contract. Two judgement calls recorded:

1. `labourResources()` returns `new Collection()` for an empty id list rather than issuing a
   `whereIn` against an empty array — same observable result, no query.
2. `isSuperseded()` returns **false** when the source has been force-deleted, because there is no
   source left to read `superseded_at`/`deleted_at` from. The plan's force-delete case asserts only
   that `source()` is null and the denormalised fields still read, which holds.

## Concurrency and boundaries

- `config/cockpit.php`, `resources/css/cav-tokens.css`, `resources/css/cockpit.css`,
  `vite.config.js` and `package.json` (45-03's files) were **not** touched. `package.json` shows as
  modified in the working tree from the concurrent sibling plan and was deliberately left unstaged.
- `app/Models/SiteSurvey.php` and `app/Models/Worksheet.php` were **not** modified — their
  security-re-audit `$fillable` omissions are intact. `SiteSurvey` has no factory, so test survey
  rows are built with `SiteSurvey::create([...])` rather than expanding the survey test
  infrastructure.
- `45-BASELINE.md` was not disturbed; no test in the D-06 gate subset was edited.
- `.planning/STATE.md` and `ROADMAP.md` were intentionally left to the orchestrator, since 45-03 is
  writing concurrently and two agents editing the same state file would clobber each other.

## Commits

| Commit | Scope |
|---|---|
| `188a3cb2` | `feat(45-02)`: visits migration + `Visit` model |
| `5261bda7` | `test(45-02)`: `VisitFactory` + `VisitTest` (13 passing) |

## Self-Check: PASSED

All four files exist on disk; both commits resolve in `git log`.
