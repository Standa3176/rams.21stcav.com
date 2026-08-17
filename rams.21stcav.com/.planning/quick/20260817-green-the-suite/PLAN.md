---
quick_id: 260817-bxc
slug: green-the-suite
date: 2026-08-17
status: planned
---

# Quick Task 260817-bxc — Clear the last 5 full-suite failures

## Baseline

`php artisan test` (full project) → **2,103 passed, 5 failed, 6 skipped** (8,420 assertions, 331s).

All five have been individually reproduced and classified below. **Four are stale tests; one is flaky by construction.** None indicates a production defect.

## Why this is worth doing

This is the fourth distinct instance today of the same failure mode: **an intentional improvement obsoleted a test assertion, and the test was left failing rather than updated.** Earlier today: 11 survey tests (`access_token` guarded), 2 DrawIoSpike lock-tests (arity). Below: 3 more of the same shape.

The cost is compounding. A permanently-red suite is one a developer stops reading, and a real regression hides among the accepted failures — which is precisely how the 11 survey failures sat unnoticed since 2026-07-09.

## Item 1 — `DocumentArtifactStorageTest::types returns all four`

`DocumentArtifactStorage` defines **8** `TYPE_*` constants (`rams`, `om-manuals`, `worksheets`, `cable-schedules`, `snagging`, `drawings`, `site-surveys`, `reference-files`). The test asserts a shorter fixed ordered list. Its own comment shows it was already patched once (4 → 5 when `TYPE_SNAGGING` arrived) and not again as `drawings`, `site-surveys` and `reference-files` were added.

**Action:** Update the expected list to the current 8, keeping the existing ordering contract. Rename the method away from "all four" — the name has been wrong for two registry growths. Add a comment noting the list must be updated whenever a `TYPE_*` constant is added, and that the adjacent `test_types_array_includes_snagging` remains the must-contain guard.

**Acceptance:** passes; fails if a `TYPE_*` constant is added without updating it.

## Item 2 — `CableScheduleXlsxRegressionTest::xlsx byte identical for null and populated fks`

**This is the one that matters.** It guards Phase 22 **D-10**: the XLSX export must be byte-identical whether or not the port FK columns are populated — i.e. FK data must be invisible to the export.

It currently compares `hash_file('sha256', …)` of two full `.xlsx` files. An `.xlsx` is a zip whose `docProps/core.xml` carries `dcterms:created` / `dcterms:modified`. `CableScheduleXlsxService` never pins document properties, so PhpSpreadsheet stamps build time. The two builds take ~8s combined, so they routinely land in different seconds and the hashes differ **regardless of the FK invariant**.

So the test does not currently verify D-10 — it fails on volatile metadata before ever exercising the invariant. **The invariant is unverified, not merely red.**

**Action:** Compare the *content* rather than the container. Open both files as zips and compare only the entries that carry sheet data (e.g. `xl/worksheets/*.xml`, `xl/sharedStrings.xml`), skipping `docProps/*` and anything else containing a build timestamp. Assert those entries are byte-identical between the two renders.

Keep the existing failure message about the D-10 invariant — it is good and explains what a failure means.

**Do NOT:**
- delete or skip the test — that would drop coverage of a real invariant
- pin document properties in `CableScheduleXlsxService` as part of this task. Making exports reproducible may well be desirable, but it is a production change with its own consequences and belongs in its own task. Note it in the SUMMARY as a follow-up worth considering.

**Acceptance:**
- Passes reliably — run it at least 3 times to prove it is no longer timing-sensitive
- **Fails if the invariant is genuinely broken** — prove this by temporarily making the service emit an FK value into a cell, observing the failure, then reverting. This is the whole point; a test that cannot detect the leak is no better than the flaky one it replaces.

## Item 3 — `SnaggingPdfGenerationTest::pdf filename follows convention`

Actual: `snagging_programme_1_20260817_083244_879650_final.pdf`
Pattern: `/^snagging_programme_\d+_\d{8}_\d{6}_final\.pdf$/`

The extra `_879650` is microseconds, from the deliberate M-09 change (`Ymd_His` → `Ymd_His_u`) made so same-second retries cannot collide. Production is correct; the regex was not updated.

**Action:** Update the pattern to accept the microsecond segment. Add a comment citing M-09 so the reason is discoverable.

**Acceptance:** passes; still rejects a filename missing the timestamp entirely.

## Item 4 — `ExampleTest::the application returns a successful response`

Laravel's stock scaffold test asserts `GET /` returns 200. The application correctly redirects unauthenticated users to login (302).

**Action:** Update it to assert the real behaviour — a redirect to login for a guest — rather than deleting it, so the root route keeps a smoke test. If the project already has a fuller root/dashboard test that supersedes it, delete `ExampleTest` instead and say so in the SUMMARY.

**Acceptance:** passes and asserts something true about the app.

## Item 5 — `QueueRecoverCommandTest::unhealthy queue runs restart and drain plan`

Asserts `EXIT_RECOVERED` (0); gets 1. The test's own comment says that under the sync queue driver `queue:work --stop-when-empty` exits immediately, so the command should report recovered.

**Action:** Investigate before changing anything. Determine whether the command genuinely mis-reports under the sync driver (a real defect in `QueueRecoverCommand`) or whether the test's assumption about the driver is wrong.

- If the **test's** assumption is wrong → fix the test.
- If the **command** is wrong → **STOP and report**. Do not change production code in this task; open it as a finding in the SUMMARY.

**Do NOT force this one green by weakening the assertion.** Queue recovery is operational tooling; a wrong exit code there could mask a genuinely stuck queue in production.

**Acceptance:** either the test passes against correct behaviour, or the task reports a production finding and leaves the test failing with an explanatory comment.

## Constraints

- Prefer `tests/` only. Item 5 may reveal a production issue — report it, do not fix it here.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- No migration, no new packages.
- Nothing should need deploying unless Item 5 forces a production finding — in which case still do not change production code.
- Full re-run at the end: `php artisan test`. Target **0 failed** (or 1 remaining with a documented production finding from Item 5). Run it in the background; it takes ~5-6 minutes.
