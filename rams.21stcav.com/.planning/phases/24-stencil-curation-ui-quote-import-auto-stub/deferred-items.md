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

**RESOLVED by quick task 260816-t5c (2026-08-16):** Both lock-tests were
rewritten to assert the required dependency *types* are present in the
constructor (`DrawIoBuilderService` + `DrawingService`) rather than counting
parameters. `DrawIoSpikeController.php` was NOT modified — the production
code was always correct; the third parameter (`SvgSanitizerService`, added
legitimately by `9a6837c`) was a good change that an arity-based test
couldn't tolerate. Renamed:
- `DrawIoBuilderServiceTest::d08_spike_controller_constructor_has_two_parameters` → `test_d08_spike_controller_still_injects_drawing_service`
- `V13SurfacesUntouchedTest::draw_io_spike_controller_constructor_has_two_parameters` → `test_draw_io_spike_controller_still_injects_builder_and_drawing_service`

Verified the new tests actually detect a regression: temporarily removed
`DrawingService` from the controller's constructor, confirmed both tests
failed, then restored it and confirmed both passed again (diff against the
pre-edit file was empty after restore).
