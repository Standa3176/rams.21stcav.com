---
quick_id: 260823-bcm
slug: amber-backcatalogue
date: 2026-08-23
status: planned
---

# Quick Task 260823-bcm — D-13 amber would turn the entire dashboard amber on day 7

## Evidence — real production numbers, pulled 2026-08-23

```
projects: 89
with rams: 60      -> 29 projects backfill to not_yet_decided
with survey: 13    -> 76 projects backfill to not_yet_decided
programming: 0/89  -> 89 projects, permanently (no model exists to infer from)
```

## The defect

`ProjectHealthService:94-115` (D-13) turns a project amber when any deliverable has
sat on `not_yet_decided` longer than `DELIVERABLE_DECISION_GRACE_DAYS = 7`. The clock
is anchored to `$row->created_at`.

The D-17 retrofit migration writes all 801 rows (89 projects x 9 deliverables) in one
pass, so **every row shares a created_at** and they all age together. Combined with
Programming being permanently undecided on all 89 projects, **100% of the project list
goes amber exactly 7 days after deploy** and stays there.

That destroys the signal this phase exists to create. It is the same failure argued
against during planning ("turns the entire project list amber, which is how people
learn to ignore the colour") — designed out of day one, reintroduced on day seven.

No plan misbehaved. D-13 simply does not survive contact with 89 real projects.

## Decision (user, 2026-08-23)

**Exclude Programming, and exclude backfilled rows, from the amber rule.**
Existing projects stay quiet. Deliverables left undecided on NEW projects still go amber.

## Why a marker column is required

`ProjectHealthService` carries an explicit **MUST NOT query** contract — the D-13 block is
already gated on `relationLoaded('deliverables')` and skips entirely rather than issuing a
query. So the rule cannot distinguish a backfilled row by consulting
`project_deliverable_audits` (`action = 'backfill'`); that would need a query it is not
allowed to make. The distinction has to be readable from the deliverable row itself.

## Task 1 — add `undecided_since` to `project_deliverables`

**File:** `database/migrations/2026_08_22_140000_create_project_deliverables_and_audits_tables.php`

**Edit the existing migration in place — do NOT add a patch migration.** This migration has
never run outside local dev (live HEAD is `b49ce4b`, 2026-08-16; the phase is undeployed),
so amending it is clean and avoids shipping a column plus an immediate alter. Tests use
`RefreshDatabase` and will pick it up. State this reasoning in a comment so a later reader
does not think an amendment was smuggled in.

Add: `$table->timestamp('undecided_since')->nullable()->index();`

Semantics — document these in the migration and on the model:
- **non-null** = this deliverable became `not_yet_decided` at that moment, through a real
  decision path (import interstitial, manual edit). The amber clock runs from it.
- **NULL** = never explicitly left undecided by a human. Grandfathered. Never goes amber.

## Task 2 — maintain the column in the service

**File:** `app/Services/ProjectDeliverablesService.php`

`setState()`, `setInitialStates()` and `autoFlipIfNotRequired()` must keep it truthful:
- writing state `not_yet_decided` -> set `undecided_since = now()` **only if currently null**
  (re-saving the same state must not restart the clock)
- writing any other state -> set `undecided_since = null`

All writes stay inside the existing audited transaction. Do not add a second write path.

## Task 3 — retrofit leaves it NULL

**File:** `database/migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php`

Insert `undecided_since => null` explicitly rather than relying on the default, and comment
why: these 89 projects predate the feature and must never nag. Idempotency
(`insertOrIgnore` on the unique index) and the audit row must be unchanged.

## Task 4 — amber rule reads the new column and skips Programming

**File:** `app/Services/ProjectHealthService.php` (~`:94-115`)

- Anchor the clock to `undecided_since`, not `created_at`
- Skip any row where `undecided_since` is null
- Skip `ProjectDeliverable::KEY_PROGRAMMING` entirely, with a comment: no model, table or
  relation exists for it (D-05), so no evidence can ever move it off `not_yet_decided`.
  Nagging about it is pure noise.
- Preserve the `relationLoaded('deliverables')` gate and the rule's last-in-priority
  position — it must never mask a higher-priority red/amber above it.

## Acceptance criteria

- A project whose rows came from the retrofit **never** goes amber under D-13, at any age
- A NEW project that leaves a deliverable undecided **does** go amber after 7 days
- Programming never triggers amber under any circumstance
- Re-saving `not_yet_decided` over itself does not reset the clock
- Moving off `not_yet_decided` and back starts a fresh clock
- `ProjectHealthService` still issues **zero queries** — verify with `DB::listen` or
  `assertNoQueriesExecuted`-style assertion, not by eye
- Existing D-12/D-13 tests still pass; the retrofit's idempotency proof still passes

**Non-vacuity is mandatory:** prove the new exclusion is load-bearing by reverting it,
observing the test fail, and restoring. A test that passes without exercising its subject
is worse than a red one.

## Constraints

- PHPUnit 11, **NOT Pest**. PHP: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe"`
- Lint every touched PHP file. No Blade in scope.
- Additive and non-destructive. Do not tighten any policy. Do not touch `config/rams_tier1.php`.
- Suite baseline: **2222 passed, 1 failed** — `QueueRecoverCommandTest` only (documented,
  passes in isolation). Any other failure is a regression. Never extend the known-failures list.
- Full-suite runs have been stalling under load; targeted `--filter=` runs are acceptable,
  but say plainly if a run stalls rather than reporting an unverified pass.

## Out of scope

- Changing the 7-day value
- Any other D-13 behaviour on new projects
- The dead `projects.create` route, D-07's remaining 3 unreconciled lists, and the
  drawings AI-edit surface — all logged separately
