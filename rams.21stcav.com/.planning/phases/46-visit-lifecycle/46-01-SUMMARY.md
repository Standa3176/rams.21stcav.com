---
phase: 46-visit-lifecycle
plan: 01
subsystem: visit-lifecycle
tags: [schema, model, state-machine, derived-over-stored, d-05, d-06]
requires:
  - "visits table (Phase 45)"
  - "users table"
  - "SiteSurvey.submitted_at"
  - "Worksheet::latestSignoff() / WorksheetSignoff (append-only)"
provides:
  - "seven nullable lifecycle columns on visits"
  - "Visit::STATE_* derived vocabulary (six states) + Visit::STATES"
  - "Visit::state(), returnedAt(), isClosed(), isAwaitingReturn(), isLocked(), wasSentBack()"
  - "Visit::acceptedBy() / createdBy()"
  - "VisitFactory: planned/sent/returned/sentBack/accepted"
  - "VisitLifecycleTest — the state machine as executable fact"
affects:
  - "Plan 46-04 (create visit — writes created_by_user_id, rooms_in_scope)"
  - "Plan 46-05 (engineer link — writes sent_at, renders rooms_in_scope)"
  - "Plan 46-06 (the four PM actions — writes accepted_at/accepted_by_user_id/sent_back_at/send_back_reason)"
  - "CockpitModulePresenter::progress() (now asks isClosed())"
tech-stack:
  added: []
  patterns:
    - "Derived over stored: a fact is read from the record it is true of, never copied (same doctrine as Visit::isSuperseded())"
    - "A stored vocabulary is never grown when a derived layer will do — reconstructed history stays unrewritten"
    - "Comparative predicates instead of boolean flags (wasSentBack() compares against the derived return)"
key-files:
  created:
    - database/migrations/2026_09_20_120000_add_lifecycle_columns_to_visits_table.php
    - tests/Unit/Models/VisitLifecycleTest.php
  modified:
    - app/Models/Visit.php
    - database/factories/VisitFactory.php
    - app/Support/Cockpit/CockpitModulePresenter.php
    - resources/views/components/cockpit/visit-row.blade.php
decisions:
  - "Seven columns, all nullable, no default — the 24 backfilled live visits performed none of these acts"
  - "NO returned_at and NO scope_locked_at: a return is the engineer's own record's fact, and the lock is its consequence"
  - "STATUSES stays [planned, completed]; sent/returned/sent_back/accepted/closed are DERIVED"
  - "returnedAt() reads WorksheetSignoff.signed_at (the column signoffs() orders by), with created_at as fallback — plan said created_at"
  - "state() reads RETURNED, not CLOSED, for a backfilled visit whose worksheet carries a signoff — pinned in a test, with the consequence for 46-06 spelled out"
  - "CockpitSectionPresenter needed no edit — it never compared a visit status"
metrics:
  duration: ~50 min
  tasks: 3
  files_changed: 6
  completed: 2026-09-20
---

# Phase 46 Plan 01: Visit Lifecycle Columns and the Derived State Machine — Summary

`Visit` gained a lifecycle — sent, returned, sent back, accepted — as seven nullable columns for
the acts a human performed plus a six-state derived layer for everything the engineer's own record
already knows, with the stored `planned`/`completed` vocabulary left untouched so no reconstructed
row is rewritten.

## What was built

### Task 1 — seven columns, one per human act (`237b0540`)

`database/migrations/2026_09_20_120000_add_lifecycle_columns_to_visits_table.php`:

| Column | Type | Why STORED and not derived |
|---|---|---|
| `sent_at` | timestamp, nullable | A PM issued the engineer link. Nothing else in the system records that act — the link's existence is not timestamped anywhere a visit can read. |
| `accepted_at` | timestamp, nullable | A PM reviewed the return and closed it. A judgement made here, evidenced nowhere else. |
| `sent_back_at` | timestamp, nullable | A PM rejected the return. Same: an act performed in this app against no other record. |
| `send_back_reason` | text, nullable | The PM's own words. Not derivable from anything. |
| `accepted_by_user_id` | FK `users`, nullable, `nullOnDelete` | T-46-01-02: an accept with no actor is repudiable. `nullOnDelete` so deleting a staff login never deletes delivery history and "who" reads NULL rather than becoming somebody else. |
| `created_by_user_id` | FK `users`, nullable, `nullOnDelete` | Same repudiation argument for creation. NULL on every Phase 45 backfilled row — nobody created those, they were reconstructed. |
| `rooms_in_scope` | json, nullable | D-05: the rooms a visit covers, chosen by the PM. Captured and rendered on the engineer link (46-05) only. **It does NOT scope the RAMS or worksheet generators** — that half of ROADMAP criterion 2 is **VL-12, NOT DELIVERED**. |

Every column is nullable with no default, and `up()` back-fills nothing: the 24 backfilled visits
on live performed none of these acts, and a default would assert something nobody measured.
`down()` drops the two constrained FKs before the columns, because sqlite and MySQL differ there.
No existing column, default, index or the `visits_source_unique` constraint was touched.

### Task 2 — the state machine (`93f4e56b`)

**No `returned_at`. No `scope_locked_at`.** Both are derived:

- **"returned"** is `Visit::returnedAt()`, read through the existing `source()`: a survey's
  `submitted_at`, or the most recent `WorksheetSignoff` on the worksheet. Sign-off is append-only,
  so a re-signoff after remedials produces a newer row and `returnedAt()` follows it. A stored copy
  would still point at the first sign-off and would then contradict the worksheet it wraps. NULL
  when there is no source, the source was force-deleted, or nothing has come back. It never reads
  a column on `visits`. This is the doctrine `Visit::isSuperseded()` already set, and D-01's
  carry-forward-by-reading.
- **"locked"** is `Visit::isLocked()` — `returnedAt() !== null`, nothing more. ROADMAP criterion 3:
  scope stays editable after sending and locks once a return arrives. The lock is a *consequence*
  of the return; storing it would let it contradict its own cause. D-06: a PM who needs a change on
  a locked visit sends it back, so there is nothing to unlock.

Derived vocabulary `STATE_PLANNED / SENT / RETURNED / SENT_BACK / ACCEPTED / CLOSED` in
`Visit::STATES`, resolved by `state()` in the documented order (accepted → sent back → returned →
sent → stored `completed` → planned). Predicates: `isClosed()` (accepted **or** stored
`completed` — the only closed test in the codebase), `isAwaitingReturn()`, `isLocked()`,
`wasSentBack()` (comparative against `returnedAt()`, not a flag, so a visit returned again is no
longer outstanding). Relations `acceptedBy()` / `createdBy()`. Casts: three datetimes plus
`rooms_in_scope` to `array`. `$fillable` gained the seven columns and nothing token-like.

`CockpitModulePresenter::progress()` now filters on `$visit->isClosed()` instead of comparing
`Visit::STATUS_COMPLETED`, and `components/cockpit/visit-row.blade.php` derives its status label
the same way — so accepting a visit can never make the progress ring go backwards while the panel
says "accepted". Nothing renders differently yet: no column is written by this plan.

`VisitFactory` gained `planned()`, `sent()`, `returned()`, `sentBack()`, `accepted(?User)`.
`returned()` writes the return on the **source** record (submits the survey / creates a
`WorksheetSignoff`), never on the visit — there is no column to write it to, and inventing one in
a factory would model a shape the schema deliberately does not have.

### Task 3 — proving Phase 45 is undisturbed

Anti-rot tests (`test_the_stored_status_vocabulary_did_not_grow`,
`test_every_derived_state_is_reachable_from_a_factory_state`) plus the Phase 45 regression: a
backfilled visit built exactly as `CockpitReadOnlyFenceTest::populatedProject()` builds one still
reads `isBackfilled()`, `status === 'completed'`, `isClosed()`, `state() === STATE_CLOSED`, and its
module count phrase is still `reconstructed` via `CockpitSectionPresenter::visitQualifiers()`.

## Verification

```
VisitLifecycleTest:              Tests:    22 passed (92 assertions)
tests/Feature/Cockpit + Unit/Cockpit: Tests:    167 passed (1761 assertions)
VisitTest:                       Tests:    13 passed (44 assertions)
BackfillVisitsCommandTest:       Tests:    19 passed (72 assertions)
D-06 baseline (gate-46.ps1 -Baseline):
                                 Tests:    2 skipped, 159 passed (396 assertions)
```

Baseline gate: **159 passed ≥ 159, 0 failed, 0 errors** — the 2 skips are the pre-existing
`ext-imagick` self-skips named in `45-BASELINE.md`. Never compared against 161.

Protected-file hashes (`gate-46.ps1 -Hashes`) — all three **match** `45-BASELINE.md` exactly:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…0557` ✅ |
| `resources/css/app.css` | `EDAD1982…2133` ✅ |
| `tailwind.config.js` | `73BB8AD6…74BB` ✅ |

## Deviations from Plan

**1. [Rule 1 — Bug] `returnedAt()` reads `WorksheetSignoff.signed_at`, not `created_at`**
- **Found during:** Task 2
- **Issue:** The plan specified "the created_at of a worksheet's `latestSignoff()`". But
  `Worksheet::signoffs()` orders by `signed_at desc` (id desc as tie-breaker), so `signed_at` is
  what "latest" *means* here. Using `created_at` would have returned a timestamp unrelated to the
  ordering that selected the row — e.g. a back-dated sign-off imported later would report the
  import time as the moment the work came back.
- **Fix:** `return $signoff->signed_at ?? $signoff->created_at;` — `created_at` kept only as the
  fallback for a row somehow written without a `signed_at`. Documented at the call site.
- **Files:** `app/Models/Visit.php`
- **Commit:** `93f4e56b`

**2. [Rule 3 — Blocking] `CockpitSectionPresenter` needed no edit**
- The plan listed it in `files_modified` and said "update BOTH cockpit presenters".
  `grep -rn "STATUS_COMPLETED" app/ resources/` found exactly one visit-status read in the Cockpit
  support layer — `CockpitModulePresenter:268`. `CockpitSectionPresenter` references only
  `Visit::TYPE_*` and never compares a visit status. It was left untouched, which also protects
  `visitQualifiers()` (the 45-13 at-rest-disclosure fix).
- A second read site the plan did not name **was** found and changed:
  `resources/views/components/cockpit/visit-row.blade.php:66`, which derived its "Completed /
  Planned" label from the stored column. Left alone it would have labelled an accepted visit
  "Planned" while the ring counted it done.

**3. [Rule 3 — Blocking] `VisitFactory::planned()` added**
- The plan listed four factory states. `test_every_derived_state_is_reachable_from_a_factory_state`
  needs six, and `STATE_PLANNED` had no producer (the factory default is a *completed* install
  visit, which produces `STATE_CLOSED`). Added `planned()` — one state, no new concept.

**4. [Process] The two Task 3 anti-rot tests landed in the Task 2 commit**
- They live in the same file as the state machine they guard and were written alongside it.
  Task 3's own work — running the baseline and the hash gate — produced no code change, so it has
  no separate code commit. Both gates are recorded above.

## Findings for later plans

**`state()` reads `RETURNED`, not `CLOSED`, for a backfilled visit whose worksheet carries a
sign-off.** This falls straight out of the plan's mandated resolution order (a derived return
outranks a stored `completed`), and the order was kept because it *is* the decision. It is correct
in the narrow sense — the work did come back, years ago — and harmless for completion, because the
progress ring asks `isClosed()`, which reads the stored status and still returns true.

**But it is a live trap for Plan 46-06.** A "returned, awaiting review" queue built on
`state() === STATE_RETURNED` would surface the 24 reconstructed visits on live as phantom review
items for work finished years ago. **46-06 must filter that queue by `! isClosed()`** (or by
`! isBackfilled()`). This is pinned as an assertion in
`test_a_backfilled_worksheet_visit_with_a_signoff_is_locked()` so it cannot be discovered late.

## Requirements

VL-01, VL-05, VL-06 and VL-11 are **not** marked complete: this plan delivers only their schema and
model half. The PM-facing halves land in 46-04 / 46-05 / 46-06, and `REQUIREMENTS.md` already
records them as spanning those plans. VL-12 remains the recorded GAP, restated at the definition
site in the migration docblock.

## Known Stubs

None. This plan adds vocabulary, not behaviour — no placeholder data reaches any view, and no
column it adds is written by any code path yet (by design; the writers are 46-04 through 46-06).

## Threat Flags

None. No new network endpoint, auth path, file access pattern or trust-boundary schema change.
The two new FKs are internal and the plan installed no package (`composer.json` and `package.json`
untouched — T-46-01-SC).

## Self-Check: PASSED

- `database/migrations/2026_09_20_120000_add_lifecycle_columns_to_visits_table.php` — FOUND
- `tests/Unit/Models/VisitLifecycleTest.php` — FOUND
- `app/Models/Visit.php` contains `public const STATE_RETURNED` — FOUND
- `app/Support/Cockpit/CockpitModulePresenter.php` matches `isClosed\(` — FOUND
- `app/Models/Visit.php` matches `returnedAt` — FOUND
- Commit `237b0540` — FOUND
- Commit `93f4e56b` — FOUND
