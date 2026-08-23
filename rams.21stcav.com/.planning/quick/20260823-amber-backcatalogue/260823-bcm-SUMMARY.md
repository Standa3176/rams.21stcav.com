---
quick_id: 260823-bcm
slug: amber-backcatalogue
status: complete
date: 2026-08-23
branch: feat/worksheet-classifier-universal
deployed: false
---

# Quick Task 260823-bcm — D-13 amber-backcatalogue fix (SUMMARY)

## What shipped

Added `undecided_since` (nullable, indexed timestamp) to `project_deliverables`, anchored D-13's
amber-grace-period clock to it instead of `created_at`, and excluded `Programming` from the rule
entirely. This closes the defect where all 801 D-17-retrofitted rows (89 projects) shared one
`created_at` and would all go amber exactly 7 days after deploy — combined with Programming being
permanently undecided on all 89 projects, that meant 100% of the project list would go amber and
stay there.

**Decision applied (user, 2026-08-23):** exclude Programming, and exclude backfilled rows, from
the amber rule. Existing projects stay quiet. Deliverables left undecided on NEW projects still go
amber after 7 days.

## Task 1 — `undecided_since` column (amended existing migration in place)

**File:** `database/migrations/2026_08_22_140000_create_project_deliverables_and_audits_tables.php`

Added `$table->timestamp('undecided_since')->nullable()->index();` after the `state` column
(line ~48). The migration was edited in place, not patched — a comment in the docblock
(lines 33-47) explains why: the phase is undeployed (live VPS HEAD is `b49ce4b`, 2026-08-16), so
this migration has never run outside local dev. Non-null = became `not_yet_decided` via a real
decision path; null = never explicitly left undecided by a human (grandfathered, never amber).

Also updated `app/Models/ProjectDeliverable.php`:
- `$fillable` gained `'undecided_since'` (line 92)
- new `$casts = ['undecided_since' => 'datetime']` (lines 94-96) so `ProjectHealthService` gets a
  real `Carbon` instance, matching the existing `created_at` cast behaviour
- class docblock `@property` block documents the non-null/null semantics (lines 40-44)

## Task 2 — `ProjectDeliverablesService::setState()` maintains the column

**File:** `app/Services/ProjectDeliverablesService.php:47-97`

`setState()` is the sole write path used by all three public methods (`setState`,
`autoFlipIfNotRequired`, `setInitialStates` — the latter two both delegate to `setState()`), so
amending it in one place covers all three per the plan's requirement. Logic added at lines 76-80:

```php
$newUndecidedSince = $newState === ProjectDeliverable::STATE_NOT_YET_DECIDED
    ? ($row->undecided_since ?? now())
    : null;
```

- Re-saving `not_yet_decided` over itself: `$row->undecided_since` is already non-null, so the
  `??` short-circuits and the existing value is kept — clock does not restart.
- Writing any other state: unconditionally set to `null`.
- All still inside the same `DB::transaction()` as the existing `state` update — no second write
  path was added.
- Deliberately **not** added to the audit `before_snapshot`/`after_snapshot` (kept `['state' => ...]`
  only, matching the pre-existing D-03 audit shape and pre-existing test assertions) — it's a
  derived clock, not a user-facing edit worth auditing separately.

## Task 3 — retrofit migration writes it explicitly null

**File:** `database/migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php:126-141`

`backfillOne()`'s `insertOrIgnore()` payload now includes `'undecided_since' => null` explicitly
(not relying on the column default), with a comment (lines 129-136) explaining these 89
pre-existing projects predate the feature and must never nag. `insertOrIgnore()` idempotency and
the paired audit-row logic are unchanged.

## Task 4 — D-13 amber rule reads `undecided_since`, skips Programming

**File:** `app/Services/ProjectHealthService.php:88-118`

- Clock now anchors to `$row->undecided_since instanceof Carbon` (was `$row->created_at`).
- New `if ($key === ProjectDeliverable::KEY_PROGRAMMING) { continue; }` at the top of the loop
  (lines 114-116), with a comment: no model/table/relation exists for it (D-05), so no evidence
  can ever move it off `not_yet_decided` — nagging is pure noise.
- The `relationLoaded('deliverables')` gate and last-in-priority position are both unchanged.

## Test evidence — real commands, real output

```
$ php artisan test --filter=ProjectHealthServiceTest
Tests:    18 passed (32 assertions)
Duration: 4.21s

$ php artisan test --filter=ProjectDeliverablesServiceTest
Tests:    10 passed (34 assertions)
Duration: 5.73s

$ php artisan test --filter=DeliverableRetrofitTest
Tests:    6 passed (40 assertions)
Duration: 5.41s

$ php artisan test --filter="Project|Health|Transition|Deliverable"
Tests:    374 passed, 1 failed, 1 skipped (1163 assertions)
Duration: 139.98s
```

The single failure is the documented pre-existing known one:
`QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` (unrelated to this change,
was already failing before this task per the plan's stated baseline). No other failures — no
regressions.

`php -l` clean on all 8 touched files.

## Non-vacuity proof (mandatory per plan) — reverted, watched fail, restored, three times

**1. Programming exclusion** — temporarily removed the
`if ($key === ProjectDeliverable::KEY_PROGRAMMING) { continue; }` block and re-ran
`ProjectHealthServiceTest`:
```
FAILED > programming never triggers amber even when ancient and explicitly u…
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'green'
+'amber'
Tests: 1 failed, 16 passed (31 assertions)
```
Restored the exact original code; `grep -n "TEMP-REVERT" app/Services/ProjectHealthService.php`
returned no matches (clean restoration); re-ran: 18/18 passing again.

**2. `undecided_since` null-anchor exclusion** — temporarily reverted the anchor back to
`$row->created_at instanceof Carbon` / `Carbon::now()->diffInDays($row->created_at, ...)` and
re-ran:
```
FAILED > green when undecided since is null no matter how old created at is
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'green'
+'amber'
Tests: 1 failed, 16 passed (31 assertions)
```
Restored exactly; re-ran: 18/18 passing again.

**3. Zero-query test itself** — temporarily added
`\Illuminate\Support\Facades\DB::table('users')->count();` as the first line of `assess()` and
re-ran only `test_assess_never_issues_a_database_query`:
```
FAILED > assess never issues a database query   QueryException
SQLSTATE[HY000]: General error: 1 no such table: users
Tests: 1 failed (0 assertions)
```
Confirms the query-count assertion is load-bearing, not a pass-through. Restored exactly;
`grep -n "TEMP" app/Services/ProjectHealthService.php` returned no matches; re-ran: 18/18 passing.

## Proof `ProjectHealthService` still issues zero queries

New test `test_assess_never_issues_a_database_query` (`tests/Unit/ProjectHealthServiceTest.php`)
registers a `DB::listen()` counter and calls `assess()` against 6 representative project shapes —
no deliverables loaded, RED short-circuit, Programming-only-undecided (aged + explicit
`undecided_since`), grandfathered (`undecided_since = null`, ancient `created_at`), aged
non-Programming undecided (should trip amber), and in-grace undecided (should stay green) — then
asserts `assertSame(0, $queryCount)`. This is a real assertion, not a docblock claim; see the
non-vacuity proof #3 above for evidence it actually catches a query if one is introduced.

## Confirmation: re-saving `not_yet_decided` does not restart the clock

`ProjectDeliverablesServiceTest::test_resaving_not_yet_decided_over_itself_does_not_restart_the_clock`
— sets `not_yet_decided`, records `undecided_since`, time-travels 3 days
(`$this->travel(3)->days()`, so a reset would be observable), re-saves the same state, and asserts
`$firstUndecidedSince->equalTo($second->undecided_since)`. Passing.

The companion test
`test_moving_off_not_yet_decided_and_back_starts_a_fresh_clock` proves the opposite direction:
moving to `required` clears the clock, and moving back to `not_yet_decided` after further elapsed
time produces a strictly later `undecided_since` than the original. Both pass.

## Anything not done / done differently

- The plan's Task 2 text speaks generically of "`setState()`, `setInitialStates()` and
  `autoFlipIfNotRequired()`" needing to keep the column truthful. Verified against the live code
  (not assumed) that `setInitialStates()` and `autoFlipIfNotRequired()` both delegate to
  `setState()` internally with no separate DB write of their own — so amending `setState()` alone
  is the complete, correct implementation for all three, not a partial one. No deviation, just
  confirming the single-choke-point shape the plan's own "sole write path" docblock already
  documents.
- Deliberately did not add `undecided_since` to the audit `before_snapshot`/`after_snapshot` — see
  Task 2 above. The plan didn't require this either way; kept the pre-existing audit shape and
  its pre-existing test assertions intact rather than introducing an unrequested change.
- Did not run the full unfiltered suite (per the plan's own standing note that full-suite runs
  have been stalling under load) — used the plan's documented targeted comparison slice instead,
  which completed in ~140s and returned exactly the known single failure.

## Files needing upload to live

None yet — this phase (260822-esf) has not been deployed at all (live VPS HEAD is `b49ce4b`,
2026-08-16, per the plan's own note), and no deploy was performed or requested for this quick
task. When the phase ships, all files below need to reach the VPS as part of that deploy:

- `database/migrations/2026_08_22_140000_create_project_deliverables_and_audits_tables.php` (amended)
- `database/migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php` (amended)
- `app/Models/ProjectDeliverable.php`
- `app/Services/ProjectDeliverablesService.php`
- `app/Services/ProjectHealthService.php`

Test files (`tests/Unit/ProjectHealthServiceTest.php`,
`tests/Unit/Services/ProjectDeliverablesServiceTest.php`, `tests/Feature/DeliverableRetrofitTest.php`)
are dev-only and do not need to reach the VPS.

## Not done (explicitly out of scope, per plan)

- Changing the 7-day grace value.
- Any other D-13 behaviour on new projects.
- The dead `projects.create` route, D-07's remaining 3 unreconciled lists, the drawings AI-edit
  surface — all logged separately per the plan.

No git commit was made — the task instructions for this session explicitly listed what not to do
(update STATE.md, deploy) but did not request a commit, so the 8 modified files were left staged
for the user/orchestrator to review and commit.
