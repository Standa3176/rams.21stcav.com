---
phase: 15-time-tracking
plan: 03
subsystem: time-tracking-scheduler
tags: [time-tracking, artisan, scheduler, stale-close, phase-15, ops, cron]

# Dependency graph
requires:
  - phase: 15-time-tracking
    plan: 02
    provides: TimeEntryService::closeStaleSessions(int $staleAfterMinutes = 120) — per-row DB::transaction + lockForUpdate sweep, NULL heartbeat fallback, Log::warning per closure, returns count
provides:
  - Artisan command programme:close-stale-sessions (--minutes=120 default)
  - Scheduler entry: hourly cron with withoutOverlapping(30), runInBackground, onOneServer
  - storage/logs/stale-sessions.log output stream for ops review
affects: 15-05-dashboard-widget (entries closed here feed summaryForProject totals with closure_reason='stale_auto_close' for auto-vs-manual distinction)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Class-based thin-shell Artisan command — constructor-injects TimeEntryService, handle() delegates entirely; mirrors QueueHealthCheckCommand pattern"
    - "Laravel 12 auto-registration of app/Console/Commands — no $commands[] or $this->load() needed"
    - "Schedule::command() chain with withoutOverlapping + runInBackground + onOneServer — defensive for multi-server future without committing to one"
    - "appendOutputTo() on a per-task log — decouples ops inspection from laravel.log trawling"
    - "Feature test introspects Schedule::events() directly — asserts cron expression + withoutOverlapping flag rather than relying on schedule:list string parsing"

key-files:
  created:
    - app/Console/Commands/CloseStaleSessionsCommand.php
    - tests/Feature/Console/CloseStaleSessionsCommandTest.php
  modified:
    - routes/console.php

key-decisions:
  - "Thin command + fat service — all closure logic stays in TimeEntryService::closeStaleSessions (Plan 15-02). The command is ~20 lines: parse --minutes, guard <=0, delegate, print summary. Matches CLAUDE.md thin-controller ethos extended to console."
  - "--minutes=0 returns FAILURE (exit 1) — a zero threshold would close every open entry regardless of heartbeat age. Explicit rejection prevents foot-gun (e.g. accidental shell pipeline passing 0)."
  - "withoutOverlapping(30) cache lock duration — matches the pessimistic case of an hourly run taking <30 min. On a pathological DB, two hourly ticks can't collide; the stale lock self-releases so a wedged instance doesn't block the next hour indefinitely."
  - "runInBackground() + onOneServer() + appendOutputTo() all chained — future-proofs multi-server deployment (onOneServer requires a locking cache driver; database/redis/file all support it) without demanding one today."
  - "Output log at storage/logs/stale-sessions.log — ops can cat/tail one file to see closure counts over time, without laravel.log noise. The Log::warning per closure still fires inside the service (Plan 15-02) for structured PII-free audit surface."
  - "Feature test introspects Schedule::events() rather than running schedule:list — asserts the actual cron expression '0 * * * *' and withoutOverlapping flag. A test that greps schedule:list output would regress silently if Laravel changes the display format."
  - "NULL heartbeat fallback (clocked_in_at + 1min) is covered by an explicit test here even though the fallback lives in the service — catches any future service refactor that drops the fallback without the command noticing."

patterns-established:
  - "tests/Feature/Console/ directory bootstrapped for this phase — prior phases had none; future Artisan tests (e.g. queue:recover, ai:cache-prune) can move in here for organisation"
  - "Scheduler wiring tests via Schedule::events() introspection — reusable pattern for any future scheduled command (assert exact cron + overlap protection in one test without running schedule:list)"

requirements-completed: [INST-04e]

# Metrics
duration: 3m 21s
completed: 2026-04-21
---

# Phase 15 Plan 03: Stale-Session Auto-Close Scheduler Summary

**The programme:close-stale-sessions Artisan command ships Phase 15's 2-hour safety net — a thin TimeEntryService::closeStaleSessions delegate, registered hourly in routes/console.php with overlap protection and a dedicated ops log, so a lost phone or dead battery can't leave open entries padding the dashboard widget indefinitely.**

## Performance

- **Duration:** 3 min 21 s (RED → GREEN, no refactor step needed)
- **Started:** 2026-04-21T14:25:49Z
- **Completed:** 2026-04-21T14:29:10Z
- **Tasks:** 1
- **Files created:** 2
- **Files modified:** 1
- **Commits:** 2 (1 RED, 1 GREEN)

## Accomplishments

- **`programme:close-stale-sessions` Artisan command** — thin class-based shell that injects `TimeEntryService` and delegates to the Plan 15-02 `closeStaleSessions(int)` method. All state-transition logic (per-row `DB::transaction + lockForUpdate`, NULL heartbeat fallback, `Log::warning` per closure, `closure_reason = 'stale_auto_close'`) lives in the service. The command adds only: option parsing, `<=0` guard, summary line.
- **Hourly scheduler registered in `routes/console.php`** beneath the existing `ai:cache-prune` block. The chain `->hourly()->withoutOverlapping(30)->runInBackground()->onOneServer()->appendOutputTo(storage/logs/stale-sessions.log)` covers overlap protection (30-min cache lock), non-blocking scheduler progression, multi-server safety, and ops-friendly output streaming.
- **`php artisan schedule:list` confirms** the entry as `0 * * * *  php artisan programme:close-stale-sessions ... Next Due: 31 minutes from now` — next hour tick runs it.
- **`php artisan list --raw` confirms** discoverability: `programme:close-stale-sessions   Auto-close time entries whose last heartbeat is older than --minutes (default 2h).`
- **8 feature tests green** covering: registration discovery, stale-entry closure with correct `clocked_out_at` + `closure_reason`, fresh-entry skip, exit-0 summary output, `--minutes=45` override, NULL heartbeat fallback (+1 min), `--minutes=0` FAILURE, and scheduler wiring (cron expression + `withoutOverlapping`).
- **53 pre-existing TimeEntry tests still pass** — zero regression touching the service layer, controllers, or routes from Phase 14 and Plan 15-02.

## Command Specification

| Attribute | Value |
|-----------|-------|
| Signature | `programme:close-stale-sessions {--minutes=120 : Sessions with last_heartbeat_at older than this are closed}` |
| Description | `Auto-close time entries whose last heartbeat is older than --minutes (default 2h).` |
| Default threshold | 120 min (2 h) per INST-04e |
| Exit 0 | Success (even when 0 entries closed) |
| Exit 1 | `--minutes=0` or negative (rejected with `--minutes must be a positive integer.`) |
| Side effects | `Log::warning` per closure (user_id, project_id, entry_id, last_heartbeat_at, closed_at, duration_minutes) — emitted by service, not command |
| Summary line | `Closed {N} stale time entries (threshold: {M} min).` |

## Scheduler Registration (routes/console.php)

```php
Schedule::command('programme:close-stale-sessions')
    ->hourly()                       // 0 * * * *
    ->withoutOverlapping(30)         // 30-min cache lock; stale locks self-release
    ->runInBackground()              // scheduler continues other tasks
    ->onOneServer()                  // multi-server-safe; requires locking cache driver
    ->appendOutputTo(storage_path('logs/stale-sessions.log'));
```

## Ops Runbook

**Local dev / single-server:**

```bash
# Either run a one-shot worker (recommended for quick dev)
php artisan schedule:work

# Or wire system cron to call schedule:run every minute
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**Production:** Cron entry above. Monitor `storage/logs/stale-sessions.log` for closure counts (grep for "Closed N stale time entries where N > 0" — high counts week-over-week signal connectivity issues in the field).

**Manual invocation:**

```bash
# Default 2h threshold
php artisan programme:close-stale-sessions

# Force tighter sweep (e.g. post-incident cleanup)
php artisan programme:close-stale-sessions --minutes=30
```

**Structured log surface** (the service emits these per closure):

```
[2026-04-21 14:29:10] local.WARNING: TimeEntryService: stale session auto-closed
{"entry_id":42,"user_id":7,"project_id":15,"last_heartbeat_at":"2026-04-21T11:00:00+00:00","closed_at":"2026-04-21T11:00:00+00:00","duration_minutes":360}
```

IDs only — no names, emails, or notes. Matches T-15-03-03 accept stance.

## Threat Mitigation Verification

| Threat | Disposition | Status |
|--------|-------------|--------|
| T-15-03-01 (overlapping invocations) | mitigate | `withoutOverlapping(30)` on Schedule chain **+** `lockForUpdate` inside service per-row transaction. Two-layer defence: cache lock blocks 2nd invocation at scheduler level; if it somehow fires, the row lock causes the loser to see `clocked_out_at` already set and skip. Covered by scheduler test + Plan 15-02 service tests. |
| T-15-03-02 (DoS via many rows) | accept | `runInBackground()` keeps scheduler responsive; realistic upper bound (~50 open entries) keeps latency sub-second. No `take(500)` cap needed in v1. |
| T-15-03-03 (PII in log) | accept | Service log writes IDs only (user_id, project_id, entry_id, ISO timestamps, duration). No names/emails/notes. File in `storage/logs/` which sits outside webroot. |
| T-15-03-04 (CLI privilege escalation) | accept | CLI access already implies DB + filesystem credentials; artisan scope is an infra concern. |
| T-15-03-05 (repudiation) | mitigate | Both the row (`closure_reason = 'stale_auto_close'`) **and** the log line encode auto-closure. Dashboard Plan 15-05 can surface the distinction visually if ops request it. |

## Acceptance Criteria — Evidence

- `grep "class CloseStaleSessionsCommand extends Command" app/Console/Commands/CloseStaleSessionsCommand.php` → match ✓
- `grep "programme:close-stale-sessions" app/Console/Commands/CloseStaleSessionsCommand.php` → match (signature) ✓
- `grep "closeStaleSessions" app/Console/Commands/CloseStaleSessionsCommand.php` → match (service delegation) ✓
- `grep "programme:close-stale-sessions" routes/console.php` → match (scheduler) ✓
- `grep "->hourly()" routes/console.php` → match (within Phase 15 block) ✓
- `grep "withoutOverlapping" routes/console.php` → match ✓
- `php artisan list --raw | grep "programme:close-stale-sessions"` → command shown ✓
- `php artisan schedule:list | grep programme:close-stale-sessions` → hourly entry shown ✓
- `php artisan test --filter=CloseStaleSessionsCommandTest` → **8/8 passing** ✓

## Task Commits

1. **Task 1 RED:** `a2fed79` test(15-03): add failing tests for programme:close-stale-sessions command — 8 feature tests, all failing with CommandNotFoundException + scheduler-absent assertion
2. **Task 1 GREEN:** `76e15fe` feat(15-03): add programme:close-stale-sessions command + hourly schedule — thin command + routes/console.php scheduler entry; 8/8 tests green + 53 pre-existing TimeEntry tests still green

## Files Created / Modified

**Created (2):**
- `app/Console/Commands/CloseStaleSessionsCommand.php` — 44 lines, thin-shell pattern
- `tests/Feature/Console/CloseStaleSessionsCommandTest.php` — 8 feature tests, Carbon::setTestNow for deterministic time control

**Modified (1):**
- `routes/console.php` — appended 13-line Phase 15 block (10 SLOC of scheduler chain + 3 lines of explanatory comment) beneath the existing `ai:cache-prune` dailyAt('03:00') entry

## Decisions Made

- **`--minutes=0` rejected with FAILURE.** A zero threshold would auto-close every open entry regardless of liveness. Explicit rejection prevents accidental misuse (e.g. a shell pipeline passing an unset variable). The error message `--minutes must be a positive integer.` gives ops a clear recovery path.
- **`withoutOverlapping(30)` with 30-min cache lock.** Matches the pessimistic case of an hourly run taking <30 min on a pathological DB. If a wedged invocation holds the lock, it self-releases after 30 min so the next hour isn't blocked indefinitely.
- **`runInBackground()` + `onOneServer()` + `appendOutputTo()` all chained.** Defensive for future multi-server deployment without committing to one. `onOneServer()` requires a locking cache driver — database/redis/file all qualify; the current `database` cache driver satisfies this silently.
- **Dedicated output log `storage/logs/stale-sessions.log`.** Ops can `cat` or `tail` a single file to see closure counts over time without trawling `laravel.log`. The structured `Log::warning` inside the service still writes to the default log channel for full audit trail; this second stream is purely operational convenience.
- **Feature test introspects `Schedule::events()` rather than parsing `schedule:list` output.** Direct object inspection (`$event->expression`, `$event->withoutOverlapping`) is robust against Laravel display-format changes. A string-grep against `schedule:list` would silently pass if Laravel changed the column layout.
- **NULL heartbeat fallback test even though the fallback logic is in the service.** Catches any future service refactor that drops the `clocked_in_at + 1min` fallback — this command's behaviour contract depends on it per D-11.
- **No email/push notification on auto-close** — D-18 explicit ("Log-only — ops reviews logs periodically"). If ops flag noise or demand proactive engineer notification, revisit in a separate plan.

## Deviations from Plan

None. Plan executed exactly as written:
- All 8 tests from the plan's `<behavior>` block implemented verbatim (7 in the plan text plus the explicit `test_invalid_minutes_option_returns_failure` which was in the plan's `<action>` test-file block).
- Command signature, description, handler body, and `--minutes` option validation match the plan's code block word-for-word.
- Scheduler chain in `routes/console.php` matches the plan's code block verbatim.

## Authentication Gates

None. The command is CLI-only — no HTTP surface, no auth layer.

## Issues Encountered

None.

- **PHP via Herd (`/c/Users/sonny.tanda/.config/herd/bin/php.bat`).** A harmless `ext\php_intl.dll` load warning appears on every invocation but doesn't affect test results (the test runner ignores stderr load warnings). Ops to suppress via Herd config if desired; not blocking.
- **`--minutes=0` shell exit code confusion on Git Bash.** The batch wrapper reports `exit=0` at the shell even when PHP returns 1. The PHPUnit test asserts `assertExitCode(1)` against the actual command object (not the shell), so the contract holds. Real cron running `php.exe` directly sees exit 1 correctly.

Both items are dev-environment quirks, not product defects.

## Self-Check: PASSED

**Files created (2):**
- `app/Console/Commands/CloseStaleSessionsCommand.php` — FOUND
- `tests/Feature/Console/CloseStaleSessionsCommandTest.php` — FOUND

**Files modified (1):**
- `routes/console.php` — MODIFIED (Schedule::command('programme:close-stale-sessions')... block added)

**Commits exist:**
- `a2fed79` — FOUND (Task 1 RED)
- `76e15fe` — FOUND (Task 1 GREEN)

**Test evidence:**
- `php artisan test --filter=CloseStaleSessionsCommandTest` → 8/8 passed (22 assertions, 5.23s)
- `php artisan test --filter=TimeEntry` → 53/53 passed (102 assertions, 8.45s) — no regression
- `php artisan list --raw | grep programme:close-stale-sessions` → command visible
- `php artisan schedule:list | grep programme:close-stale-sessions` → `0 * * * *  php artisan programme:close-stale-sessions` scheduled hourly

---
*Phase: 15-time-tracking*
*Plan: 03*
*Completed: 2026-04-21*
