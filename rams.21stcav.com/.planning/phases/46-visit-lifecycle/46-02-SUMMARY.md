---
phase: 46-visit-lifecycle
plan: 02
subsystem: snags
tags: [schema, model, scope-fence, d-03]
requires:
  - "projects table"
  - "visits table (Phase 45)"
  - "users table"
provides:
  - "snags table (9 columns)"
  - "App\Models\Snag"
  - "Database\Factories\SnagFactory"
  - "SnagTest — the Phase 47 six-column scope fence"
affects:
  - "Plan 46-05 / 46-07 (the only writer: the PM raise-a-snag action)"
  - "Phase 47 (extends this table; does not replace it)"
tech-stack:
  added: []
  patterns:
    - "Enumerated scope fence asserted as data (same discipline as CockpitReadOnlyFenceTest)"
    - "Divergent FK dispositions documented at the definition site (cascade vs nullOnDelete)"
key-files:
  created:
    - database/migrations/2026_09_20_130000_create_snags_table.php
    - app/Models/Snag.php
    - database/factories/SnagFactory.php
    - tests/Unit/Models/SnagTest.php
  modified: []
decisions:
  - "visit_id is nullable and nullOnDelete — a snag outlives the visit it came from, and Phase 47 needs snags with no visit at all"
  - "project_id cascades — a snag has no meaning without its project"
  - "Snag::STATUSES has exactly one entry ('open'); the three outcomes are Phase 47"
  - "No raised_at and no is_open flag — derived over stored (created_at, status)"
  - "VL-08 NOT marked complete: it also needs Plan 46-07's HTTP boundary"
metrics:
  duration: ~35 min
  completed: 2026-09-20
---

# Phase 46 Plan 02: Minimal Snag Record Summary

A `snags` table of exactly nine columns plus a `Snag` model with one status, fenced against
Phase 47 by an enumerated six-column test that fails if the snag lifecycle starts leaking backwards.

## What was built

| Column | Why it exists |
|---|---|
| `id` | key |
| `project_id` | the mandatory parent — **cascadeOnDelete**; a snag has no meaning without its project |
| `visit_id` | the visit it came from (D-03's "linked to the visit it came from") — **nullable, nullOnDelete** |
| `title` | what the PM saw, NOT NULL — the one thing a raised snag always has |
| `detail` | optional free text |
| `room_name` | where on site; a plain string, not a room FK, because snags are often raised about somewhere the room list does not yet name |
| `raised_by_user_id` | who raised it — nullOnDelete, the report outlives the account |
| `status` | `open` by default; the single honest state of a raised snag |
| `created_at` / `updated_at` | `created_at` **is** the raised-at time — derived over stored |

Indexes: `(project_id, status)` (a project's open snags) and `visit_id` (the snags of one visit —
Phase 47's one-visit-many-snags read).

## What was deliberately NOT built (D-03)

| Not built | Phase 47 owner |
|---|---|
| `outcome` — fixed / not fixed / deferred | criterion 2 |
| `parts` | criterion 4 (parts tracked per snag — belongs in its own `snag_parts` table) |
| `parent_snag_id` — linked follow-up chain | criterion 3 |
| `resolved_at` | criterion 2/3 (resolution is a lifecycle event, not a raise) |
| `assigned_to` | criterion 5 (a snag sitting with a client or third party) |
| `cost` | deferred for the whole v4.0 milestone (visit costs) |
| route / controller / policy / service / view | Plan 46-05 + 46-07 |

The six columns are asserted absent by `test_the_snags_table_carries_no_phase_47_column()`, whose
failure message names Phase 47 and D-03. A companion
`test_the_snags_table_carries_exactly_the_nine_permitted_columns()` stops the fence passing
vacuously against a table that does not exist, and makes a tenth column a deliberate act.

## Survival, and the FK choice

`visits` has no `softDeletes()`, so deleting a visit is a HARD delete. `snags.visit_id` is a real FK
with **`nullOnDelete`**: the snag row survives with its pointer nulled. A cascade would destroy a
real finding — the "un-happening" D-04 forbids. Contrast `visits.source_id`, which carries no FK at
all because a visit must survive with its pointer INTACT; a snag has no denormalised fallback to
preserve and does not need one. Both reasons are written at their definition sites so neither is
"harmonised" later.

`test_a_snag_survives_its_visits_source_being_force_deleted()` covers the deeper case: after the
survey behind the visit is force-deleted, `Visit::source()` goes null while the snag still resolves
both its visit and its project.

## Phase 47 shapes already supported without alteration

- **Snag with no visit** (criteria 1 and 5): `visit_id` is nullable today, proven by
  `test_a_snag_may_exist_with_no_visit_attached()`. This is the one place the schema anticipates
  Phase 47, and it does so by being LESS strict, never by adding a column nobody writes.
- **One visit resolving several snags** (criterion 1): the link lives on the snag as a plain
  `belongsTo`, so N snags already point at one visit — `test_one_visit_may_carry_several_snags()`.
- The model docblock lists the three extension points by name, which is what makes Phase 47 an
  extension rather than a rewrite.

## Deviations from Plan

**1. [Rule 3 - Blocking] `SiteSurvey` has no factory**
- **Found during:** Task 2
- **Issue:** the force-delete survival test needed a `SiteSurvey`; `database/factories/` has no
  `SiteSurveyFactory`.
- **Fix:** mirrored the private `makeSurvey()` helper from `tests/Unit/Models/VisitTest.php:212`
  rather than minting a new factory (a factory would be a file outside this plan's authoritative
  `files_modified` list).
- **Files modified:** `tests/Unit/Models/SnagTest.php`
- **Commit:** `8dca37a6`

**2. [Scope, deliberate] VL-08 left as Planned, not Complete**
- `REQUIREMENTS.md:193` assigns VL-08 to **Plans 46-02 + 46-07**. This plan delivers the schema
  half only; the HTTP boundary is 46-07. Marking it complete now would overstate delivery, so
  `requirements.mark-complete` was NOT run.

**3. [Process] `state.advance-plan` / `state.update-progress` not run**
- Prohibited for this phase: they corrupted `.planning/STATE.md` five times in Phase 45.
  STATE.md is untouched and clean.

## Verification

Own suite (`gate-46.ps1 -Filter SnagTest`):

```
  Tests:    8 passed (20 assertions)
  Duration: 5.15s
```

D-06 baseline (`gate-46.ps1 -Baseline`) — gate is `>= 159 passed AND 0 failed`, never equality
against 161:

```
  Tests:    2 skipped, 159 passed (396 assertions)
  Duration: 31.07s
```

Protected-file hashes (`gate-46.ps1 -Hashes`): all three match `4abd2b24` exactly
(`9ED63C4C…`, `EDAD1982…`, `73BB8AD6…`).

`grep -rn "snags" routes/ app/Http/` returns nothing — this plan adds no route, controller or
policy.

## Known Stubs

None. Every column written is read by a test; nothing is a placeholder awaiting wiring. The
deliberate absences above are scope, not stubs.

## Threat Flags

None. No new network endpoint, auth path, file access or trust-boundary schema was introduced —
`snags` is a staff-only table with no reader in this phase. T-46-02-01 (stored markup) transfers to
Plan 46-05, which is the first renderer.

## Self-Check: PASSED

All four created files exist on disk; all three task commits (`d1eee34a`, `797bb5b8`, `8dca37a6`)
resolve in `git log`.
