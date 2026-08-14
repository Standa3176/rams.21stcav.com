---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 11
subsystem: drawings
tags: [laravel, phpunit, mxgraph, gap-closure]

# Dependency graph
requires:
  - phase: 24-05
    provides: DeviceStencilController::update() D-17 curated-artwork confirm-to-proceed guard (server + client halves)
provides:
  - Narrowed D-17 guard — fires only when source===engineer-curated AND the stencil already has saved ports, closing UAT Gap 2
  - Regression test proving the exact Gap 2 scenario (engineer-curated, zero ports, no confirm) now saves cleanly
affects: [24-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "D-17 guard predicate now checks prior-state existence (ports()->exists() evaluated before any mutation in the same request), not just a static source classification — a reusable pattern for any future guard that claims to protect 'content' but was actually keying off a coarser proxy field."

key-files:
  created: []
  modified:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - tests/Feature/Drawings/DeviceStencilEditTest.php

key-decisions:
  - "Implemented UAT Gap 2's candidate fix (a) verbatim — source===engineer-curated AND ports()->exists() — as specified by the plan, the smallest change directly matching D-17's original intent. Candidates (b) metadata-flag and (c) new has_custom_artwork column were not pursued (out of scope for this gap-closure plan; (c) also costs a migration this plan deliberately avoids)."
  - "Test 6's fixture change (adding a real prior port) was required, not optional decoration — without it the test would have continued to pass after this fix, but for the wrong reason (the guard never engaging at all rather than genuinely firing-then-being-bypassed). Left as a silent pass, it would have masked a future regression of the guard-then-bypass path."
  - "Accepted, documented as unchanged: an admin can save a curated stencil down to zero ports (UpdateDeviceStencilPortsRequest allows `ports => ['present','array']`, i.e. an empty array), after which the guard will not re-fire on that stencil's next edit even though it once had artwork. This is the plan's own threat register item T-24-11-02, disposition 'accept' — self-inflicted by an already-privileged admin, fully attributable via the unconditional per-save DeviceStencilAudit row (D-03). No code change was made to close this; it is a documented, accepted residual risk, not a bug."

requirements-completed: [DRAW-51]

# Metrics
duration: ~20min
completed: 2026-08-14
---

# Phase 24 Plan 11: D-17 Guard Narrowing (UAT Gap 2) Summary

**Guard predicate changed from `source===engineer-curated` (fired on 96/96 stencils) to `source===engineer-curated AND ports()->exists()` (fires on 5/96 — only the stencils that actually have artwork to protect).**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-14 (see git commit timestamps)
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 2

## Accomplishments

- `DeviceStencilController::update()`'s D-17 guard now requires the stencil's own `ports()->exists()` — evaluated before this request's mutation, so it reflects the stencil's saved state as of the PREVIOUS request — in addition to `source === SOURCE_ENGINEER_CURATED`. A zero-port `engineer-curated` stub (91 of the 96 real catalogue stencils) now saves in a single click with no `confirm_regenerate` and no warning flash.
- The 5 genuinely hand-curated stencils (ClickShare Bar Pro — 7 ports, Neat Bar Pro — 6, Netgear GS312TP — 14, Samsung QM65C-T — 9, Sennheiser TCC2 — 4; 40 ports total) all satisfy `ports()->exists()`, so the guard stays fully active for them — confirmed by the pre-existing Test 5 fixture, which was already shaped this way and required no change.
- Test 6's fixture was corrected to insert a real prior port before the request — under the narrowed predicate it would otherwise have passed with the guard never engaging, silently no longer proving the guard-then-bypass path.
- New regression test `test_update_against_engineer_curated_zero_port_stub_saves_without_confirm_regenerate` (Test 8) reproduces the exact Gap 2 defect shape and asserts a clean, unconfirmed save — this test fails against the pre-fix guard predicate (it would have hit the `warning` flash branch instead) and passes against the fix.
- Docblock above `update()` updated to document the narrowed predicate, its rationale (91 of 96 real stencils are bare stubs sharing `source` with the 5 that have artwork), and the known/intended consequence that a stub's SECOND edit (once it has ports) does trigger the guard, same as genuine curated artwork.

## Task Commits

Each task was committed atomically:

1. **Task 1: Narrow the D-17 guard to stencils that actually have ports** - `dea8981` (fix)
2. **Task 2: Fix the guard-bypass test fixture and add the real-bug regression test** - `b090e9b` (fix)

## Files Created/Modified

- `app/Http/Controllers/Admin/DeviceStencilController.php` — Guard predicate on line ~166 now `source === SOURCE_ENGINEER_CURATED && $deviceStencil->ports()->exists() && ! $request->boolean('confirm_regenerate')`. Docblock above `update()` expanded to document the narrowing and its rationale. No other logic in `update()` changed.
- `tests/Feature/Drawings/DeviceStencilEditTest.php` — Test 6 fixture now inserts a prior `DevicePort` before the request (previously had zero, which would have silently defeated the test's purpose under the new predicate). New Test 8 proves the exact Gap 2 scenario. Class docblock updated to reference Plan 24-11 and Test 8's purpose. Test 5 got a clarifying comment (no assertion change) noting its fixture now represents one of the 5 real-artwork stencils specifically, not "any engineer-curated stencil" generically.

## Decisions Made

See `key-decisions` in frontmatter. In short: implemented UAT's candidate fix (a) exactly as specified; treated Test 6's fixture correction as required (not optional) to keep the guard-then-bypass path genuinely tested; left the accepted zero-port-then-re-edit edge case (T-24-11-02) as documented-and-accepted per the plan's threat register, no code change.

## Deviations from Plan

None — plan executed exactly as written. No Rule 1/2/3 auto-fixes were needed; the plan's acceptance criteria, fixture changes, and docblock updates were followed verbatim.

## Issues Encountered

None. Both tasks proceeded without a checkpoint or blocker. `php artisan test --filter=DeviceStencilEditTest` returned 12/12 passing (the file's full test count — 4 batched-save tests, 3 D-17 guard tests including the new Test 8, 4 edit-screen view tests — not just the "9" the plan's acceptance criteria estimated, since that count referred only to Task 1's original D-17-guard-plus-batched-save scope; the file also carries Task 2 of Plan 24-05's 4 view-level tests, unaffected by this change).

## User Setup Required

None — no migration, no external service configuration. No DB schema change in this plan.

## Next Phase Readiness

- UAT Gap 2 is closed. Gap 1 (the `stencils:reapply-templates` D-08 eligibility mismatch) remains a separate, already-tracked item — this plan did not touch it (confirmed no overlap: this plan only edits the guard's confirm-requirement predicate inside `update()`, not `reapply-templates`'s eligibility scope).
- Plan 24-12 is untouched, as instructed — not started, not read beyond its existence being noted in `git log` context.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following file from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Http/Controllers/Admin/DeviceStencilController.php`

No migration in this plan. Test file (`tests/Feature/Drawings/DeviceStencilEditTest.php`) is not required on live — local/CI test suite only.

**Before/after guard-trigger count (96 seeded stencils):** 96 of 96 fired the D-17 confirm-required warning before this fix → **5 of 96** fire it after this fix (ClickShare Bar Pro, Neat Bar Pro, Netgear GS312TP, Samsung QM65C-T, Sennheiser TCC2 — the only 5 with saved `device_ports` rows / genuine hand-built artwork). The other 91 now save in a single click with zero added friction, restoring D-17's own "ordinary stub-curation path must stay a single-click save" requirement.

## Self-Check: PASSED

Both modified files verified present on disk (`app/Http/Controllers/Admin/DeviceStencilController.php`, `tests/Feature/Drawings/DeviceStencilEditTest.php`). Both task commit hashes (`dea8981`, `b090e9b`) verified present in `git log --oneline`. `php artisan test --filter=DeviceStencilEditTest` — 12/12 passed, including the new Gap 2 regression test and the corrected Test 6.

---
*Phase: 24-stencil-curation-ui-quote-import-auto-stub*
*Completed: 2026-08-14*
