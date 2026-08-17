---
quick_id: 260817-bxc
slug: green-the-suite
status: complete
date: 2026-08-17
branch: feat/worksheet-classifier-universal
deployed: false
---

# Quick Task 260817-bxc — Clear the last 5 full-suite failures (SUMMARY)

## What shipped

Cleared 4 of the 5 remaining full-suite failures (all four were stale test
assertions, not production defects) and left the 5th failing deliberately
with a documented production finding, per the plan's explicit instruction
not to force it green.

**Baseline:** `php artisan test` → 2,103 passed, 5 failed, 6 skipped (8,420
assertions).

**After:** `php artisan test` → 2,108 passed, **1 failed** (Item 5,
intentional), 6 skipped, 8,436 assertions, ~359s. See
"Full re-run" below for the exact run.

All changes are **test-only**. No production code (`app/`, `resources/`,
`routes/`) was modified. Each item is its own commit so any can be reverted
independently.

## Item 1 — `DocumentArtifactStorageTest`

`DocumentArtifactStorage::types()` returns 8 `TYPE_*` constants; the test
(`test_types_returns_all_four`) asserted a stale 5-entry list left over from
the last registry growth. Renamed the method (`test_types_returns_the_full_registry`),
asserted the current 8-entry ordered list, and added a comment requiring the
list to be updated whenever a new `TYPE_*` constant is added.

- File: `tests/Unit/Services/DocumentArtifactStorageTest.php`
- Commit: `274f12a`

## Item 2 — `CableScheduleXlsxRegressionTest` (the one that matters)

This test guards Phase 22 D-10: the cable-schedule XLSX export must be
byte-identical whether or not the new port-FK columns are populated. It was
comparing whole-file `hash_file('sha256', …)`, but an `.xlsx` is a zip whose
`docProps/core.xml` carries build timestamps — `CableScheduleXlsxService`
never pins document properties, so the two ~3-9s renders routinely land in
different seconds and the hashes differed on **volatile metadata**, before
the D-10 invariant was ever actually exercised. The invariant was
unverified, not merely red.

**Fix:** replaced the whole-file hash comparison with a targeted zip-entry
comparison — opens both `.xlsx` files as zips and compares only
`xl/worksheets/*.xml` and `xl/sharedStrings.xml` (the entries that actually
carry cell data), explicitly skipping `docProps/*` and everything else.

**Stability proof:** ran `--filter=CableScheduleXlsxRegressionTest` 3
consecutive times — all green (previous version was timing-sensitive).

**Sensitivity proof (per plan requirement):** temporarily changed
`CableScheduleXlsxService::build()` to emit `source_device_id` into the
Status column (`I{$rowNum}`), re-ran the test — it **failed**, correctly
reporting `entry xl/worksheets/sheet1.xml differs` with the D-10 message.
Reverted the service change immediately after
(`git diff --stat app/Services/CableScheduleXlsxService.php` confirmed a
clean, empty diff before committing the test-only fix). The test can
genuinely detect the leak it exists to catch.

- File: `tests/Feature/Cable/CableScheduleXlsxRegressionTest.php`
- Commit: `82d9b9b`

**Follow-up worth considering (not done here, out of scope):** pinning
`docProps` (created/modified) in `CableScheduleXlsxService` would make the
raw export byte-reproducible across renders too, but that is a production
change with its own consequences (e.g. does "Generated: <date>" in the
project-info row still change per day?) and belongs in its own task.

## Item 3 — `SnaggingPdfGenerationTest::pdf filename follows convention`

The M-09 change added a microsecond segment to the snagging PDF filename
(`Ymd_His` → `Ymd_His_u`) so same-second retries can't collide, but the
regex asserting the filename convention was never updated. Production
behaviour was correct; the test assumption was stale. Widened the regex to
require the extra `\d{6}` microsecond segment (still rejects a filename
missing the timestamp entirely), with a comment citing M-09.

- File: `tests/Feature/Commissioning/SnaggingPdfGenerationTest.php`
- Commit: `50c228c`

## Item 4 — `ExampleTest::the application returns a successful response`

Laravel's stock scaffold test asserted `GET /` returns 200. This app has no
public marketing landing (see `routes/web.php`) — root correctly redirects
guests to `login`. No existing test covered the root-route redirect
specifically, so rather than deleting the smoke test, updated it to assert
the real behaviour (`assertRedirect(route('login'))`) and renamed it
accordingly.

- File: `tests/Feature/ExampleTest.php`
- Commit: `0dd6bf9`

## Item 5 — `QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` — PRODUCTION FINDING, left failing on purpose

**This one is not fixed.** Per the plan's instruction, do not force it green
and do not touch production code in this task.

**Investigation:** the test asserts `QueueRecoverCommand::EXIT_RECOVERED`
(0) but gets `EXIT_RECOVERY_FAILED` (1) — **only** when run as part of the
full `php artisan test` suite; it passed reliably in isolation and in every
smaller combination tried (`tests/Feature/Queue` alone, 3x repeats of the
single test, combined with `QueueHealthCheckCommandTest`).

**Root cause, confirmed experimentally:** `QueueRecoverCommand`'s internal
drain step calls `$this->call('queue:work', ['--stop-when-empty' => true,
'--tries' => 2, '--timeout' => 300, '--sleep' => 3])` — no `--memory`
override, so it inherits `queue:work`'s 128MB default.
`Illuminate\Queue\Worker::memoryExceeded()` checks `memory_get_usage(true)`
— the **whole PHP process's** real memory, not memory consumed since
`queue:work` started. I reproduced the exact failure in isolation by
artificially inflating `memory_get_usage(true)` past 128MB immediately
before the `Artisan::call('queue:recover')` line (temporary debug-only edit,
reverted — `git diff` confirmed empty before the final commit): the assert
failed identically (`exit=1`). In the full suite, ~2,000+ prior tests share
one long-lived PHPUnit process and accumulate enough real memory that this
test — which happens to run very late in file-discovery order — inherits
that footprint and trips the ceiling.

**Why this is a production finding, not just a test artifact:** the
command's restart+drain control flow is correct (both plan steps run, the
drain genuinely completes). But `Worker::stopIfNecessary()` returns
`EXIT_MEMORY_LIMIT` (12) whenever whole-process memory exceeds 128MB for
*any* reason, and `QueueRecoverCommand` maps that straight to
`EXIT_RECOVERY_FAILED` (1) — conflating "the drain genuinely failed" with
"this process happened to be over an unrelated memory ceiling." In
production `queue:recover` normally runs as a fresh, short-lived CLI
invocation per cron tick, so the test-harness trigger (2,000+ prior tests in
one process) doesn't apply directly — but the same code path is reachable
any time `queue:recover` runs in a longer-lived process, or when the drain
itself processes enough memory-heavy document-generation jobs (RAMS/O&M
PDFs, PhpSpreadsheet XLSX builds) to approach 128MB within its own
invocation — exactly the "unhealthy" scenario that triggered recovery in
the first place. A false `EXIT_RECOVERY_FAILED` in that scenario would look
like recovery is broken when it actually drained correctly.

**Action taken:** left the assertion (`assertSame(EXIT_RECOVERED, $exit)`)
completely intact — did not weaken it. Added a long explanatory comment
above it documenting the root cause and the production risk, so this
doesn't quietly go stale like the other 4 items did. No `app/` file was
touched (`git status --short app/Console/Commands/QueueRecoverCommand.php`
is empty).

**Suggested follow-up (not done here):** either (a) pass an explicit,
higher `--memory` to the internal `queue:work` call, or (b) stop mapping
`EXIT_MEMORY_LIMIT` to `EXIT_RECOVERY_FAILED` — treat a memory-ceiling stop
as a distinct, retryable outcome rather than a hard failure, since the drain
may have made real progress.

- File: `tests/Feature/Queue/QueueRecoverCommandTest.php` (comment only)
- Commit: `d88f298`

## Verification

- Lint: `php84/php.exe -l` clean on every touched file (5/5).
- `CableScheduleXlsxRegressionTest` (Item 2): 3 consecutive green runs +
  sensitivity proof (see Item 2 above).
- Full re-run: `php artisan test` → **2,108 passed, 1 failed (Item 5,
  intentional), 6 skipped, 8,436 assertions**, ~359s. The only failure is
  the documented Item 5 production finding.
- `git status --short` on `app/`, `resources/`, `routes/` — clean; no
  production files were modified by this task.

## 🚨 Files to upload to live

None. This task changed test files only (plus this SUMMARY and
STATE.md/PLAN.md bookkeeping). Nothing to deploy.
