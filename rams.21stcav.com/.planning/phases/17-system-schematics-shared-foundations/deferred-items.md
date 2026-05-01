# Phase 17 — Deferred Items

Items discovered during Phase 17 execution that are out of scope for v1.3
Phase 17 and were NOT auto-fixed.

## Pre-existing failing tests (NOT caused by Phase 17)

### `OmManualProjectLinkageTest::test_project_show_page_displays_om_manuals_section_for_project`

- **Discovered during:** Plan 17-03 Task 3 verification (`php artisan test --filter=OmManual`).
- **Failure:** assertion `assertSee('O&amp;M Manuals', false)` — fails before AND after Plan 17-03 changes (verified via `git stash` rollback).
- **Root cause:** the project show page tab strip uses the label `O&M` (in the tab list at line ~575), not `O&M Manuals`. Test was written against an older version of the show page.
- **Disposition:** out of scope for Phase 17. Either the test should be updated to match the current label, or the show page should restore the longer label.
- **Tracker:** to be filed as a separate `/gsd-quick` task before the Phase 18 wave starts.

## Notes

- Browsershot binary is not installed on this dev machine; `pdf:smoke-test --drawings` reaches PHP cleanly but the Node subprocess fails on `puppeteer-core` resolution. Not a Phase 17 regression — same as pre-Plan-03 state. Production AlmaLinux has the binary symlinked at `/home/stcav/chrome` per CHROME_PATH env var.
