# Phase 24 — Deferred Items

Out-of-scope discoveries logged during plan execution (SCOPE BOUNDARY rule —
only auto-fix issues directly caused by the current task's changes).

## Plan 24-01 (2026-08-14)

**Pre-existing test failures, unrelated to 24-01's changes:**

- `Tests\Feature\Drawings\DrawIoBuilderServiceTest::d08_spike_controller_constructor_has_two_parameters`
- `Tests\Feature\Drawings\V13SurfacesUntouchedTest::draw_io_spike_controller_constructor_has_two_parameters`

Both assert `App\Http\Controllers\Admin\DrawIoSpikeController`'s constructor
has exactly 2 parameters; it currently has 3. `DrawIoSpikeController.php` was
last modified in commit `9a6837c` ("security(WR-03/4/5): MIME gate + SVG
sanitiser + survey re-parent guard") — unrelated to Phase 24 and not touched
by Plan 24-01. Confirmed pre-existing by `git log` on the file; Plan 24-01
never reads or writes `DrawIoSpikeController.php`.

Not fixed here — out of scope for Plan 24-01's `files_modified` list. Flag
for a future plan/session to investigate (likely a 21-03/WR-03 constructor
signature drift that the two lock-tests weren't updated for).
