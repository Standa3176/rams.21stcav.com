---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 04
subsystem: rams-emergency-arrangements
tags: [rams, rule-08, render-sites, blades, docx, regression-test]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 02
    provides: "SiteEmergencyResolver::resolve() — the single shared A&E branch value this plan wires into all 5 render sites"
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 03
    provides: "RamsComplianceUpgradeService::resolveSiteEmergency() — writes $data['site_emergency_resolved'] every upgrade() call, which the legacy blade's Section 7.0 row reads"
provides:
  - "Five fixed render sites (rams.blade.php x2, rams-v2.blade.php x2, DocxBuilderService.php x1) — the banned passive string and the 'TBC' A&E fallback are structurally gone from every real document render path, closing RULE-08's live-facing half"
  - "SiteEmergencyRenderSitesRegressionTest — end-to-end proof (real upgrade()+patch()+compose() pipeline, both composer states, both data states, PDF + DOCX) that the fix holds"
affects: [29-05-PLAN, 29-06-PLAN]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Render sites read one already-resolved key (site_emergency_resolved / nearestHospitalResolvedText) rather than re-deriving branch logic — same 'read one resolved key' shape buildCdmSection() already used for cdm_duty_holders"

key-files:
  created:
    - tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php
  modified:
    - resources/views/pdf/rams.blade.php
    - resources/views/pdf/rams-v2.blade.php
    - app/Services/DocxBuilderService.php
    - tests/Feature/Rams/RamsSection70HeadingTest.php
    - tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php

key-decisions:
  - "DOCX Welfare bullet's Section 7.0 pointer text differs from the PDF blades' ('see CDM 2015 — Duty Holders section' vs 'see Section 7.0') because DOCX has no Section 7.0-equivalent A&E table anywhere in DocxBuilderService.php (confirmed by grep — zero nearest_hospital/site_emergency matches outside this one bullet) — documented inline per the plan's Task 2 instruction rather than inventing a DOCX A&E table out of scope"
  - "Two pre-existing tests (RamsSection70HeadingTest, Tier1SiteEmergencyFormAndRenderTest) rendered pdf.rams directly without running RamsComplianceUpgradeService::upgrade(), so they never had site_emergency_resolved populated — fixed by computing it inline via the same shared SiteEmergencyResolver::resolve() call the real pipeline makes, rather than pulling the full upgrade() pipeline into narrowly-scoped test files or re-deriving the branch logic a third time"
  - "Tilda fixture snapshot test failures (2 HTML + 2 DOCX, all under @group snapshot) are left unregenerated per the plan's explicit constraint — confirmed via diff inspection that all four are exactly this plan's intended content changes (Welfare bullet pointer text, Section 7.0 A&E resolved-value reads, DOCX pointer swap), not unrelated breakage"

patterns-established: []

requirements-completed: [RULE-08]

# Metrics
duration: 55min
completed: 2026-09-11
---

# Phase 29 Plan 04: Fix All Five A&E/Welfare Render Sites Summary

**Fixed all five render sites RESEARCH.md Finding 3 maps (both PDF blades' Welfare bullet + Section 7.0 A&E row, and DocxBuilderService's Welfare bullet) so every real generated document — legacy and unified-composer paths alike — reads the single resolved A&E value from Plans 29-02/29-03 instead of a hardcoded banned sentence or a `'TBC'` fallback, then proved it holds with an end-to-end regression test covering both composer states and both A&E data states.**

## Performance

- **Duration:** 55 min
- **Started:** 2026-09-11 (this session)
- **Completed:** 2026-09-11
- **Tasks:** 3/3 complete
- **Files modified:** 8 (1 created, 7 modified)

## Accomplishments

- `resources/views/pdf/rams.blade.php`: Welfare bullet now reads "Nearest A&amp;E — see Section 7.0." (banned string gone); Section 7.0 A&E row reads `$data['site_emergency_resolved']['text'] ?? ''` (the `'TBC'` fallback is gone).
- `resources/views/pdf/rams-v2.blade.php`: identical Welfare bullet fix; Section 7.0 A&E row reads `$emergencyDto->nearestHospitalResolvedText` (the v2 blade's own independent `'TBC'` fallback — not named in CONTEXT.md's D-01 table, found by 29-RESEARCH.md Pitfall 3 — is gone).
- `app/Services/DocxBuilderService.php::buildWelfareArrangements()`: First Aid item text drops the banned sentence, points to "CDM 2015 — Duty Holders section" instead of "Section 7.0" (DOCX has no A&E table equivalent to point to), with an inline comment explaining the divergence.
- New `SiteEmergencyRenderSitesRegressionTest` (6 tests, 36 assertions) drives the real `RamsDisplayPatchService::patch()` → `RamsComplianceUpgradeService::upgrade()` → `RamsDocumentComposer::compose()` pipeline, renders both blades and a real DOCX build, and proves the banned string / `'TBC'` A&E fallback are structurally absent for both the hold-point and verified data states, under both `RAMS_UNIFIED_COMPOSER` states.
- Two pre-existing render tests (`RamsSection70HeadingTest`, `Tier1SiteEmergencyFormAndRenderTest`) that rendered `pdf.rams` directly without running `upgrade()` were fixed to compute `site_emergency_resolved` via the same shared resolver, matching real pipeline behaviour.

## Task Commits

Each task was committed atomically:

1. **Task 1: Fix both blades — Welfare bullet pointer + Section 7.0 A&E row, both branches** — `d481a43` (fix)
2. **Task 2: Fix DocxBuilderService::buildWelfareArrangements() (D-01 site #5)** — `85b75f2` (fix)
3. **Task 3: Regression test proving the banned string and 'TBC' are structurally gone** — `e9933aa` (test, includes the two collateral test fixes)

## Files Created/Modified

- `resources/views/pdf/rams.blade.php` — Welfare bullet pointer, Section 7.0 A&E row reads resolved key
- `resources/views/pdf/rams-v2.blade.php` — Welfare bullet pointer, Section 7.0 A&E row reads `nearestHospitalResolvedText`
- `app/Services/DocxBuilderService.php` — Welfare bullet drops banned sentence, points to CDM 2015 section
- `tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php` — new end-to-end regression test (6 tests)
- `tests/Feature/Rams/RamsSection70HeadingTest.php` — collateral fix (compute `site_emergency_resolved` in test render helper)
- `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php` — collateral fix (same pattern)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Two pre-existing tests broke because they rendered `pdf.rams` directly without running `upgrade()`**
- **Found during:** Task 3, running the full `tests/Unit/Support/Rams tests/Feature/Rams` suite after Tasks 1-2 landed.
- **Issue:** `RamsSection70HeadingTest::test_heading_renders_when_emergency_data_populated` and `Tier1SiteEmergencyFormAndRenderTest::test_pdf_renders_populated_site_emergency_table` both render `pdf.rams` directly with a hand-built `$data` array, bypassing `RamsComplianceUpgradeService::upgrade()` entirely. Before this plan, the Section 7.0 A&E row read `$siteEmerg['nearest_hospital']` directly, so these tests' raw fixture data was visible in the output. After Task 1's fix, the row reads `$data['site_emergency_resolved']['text']` — a key only `upgrade()`'s `resolveSiteEmergency()` step populates — so both tests' hand-built fixtures rendered a blank A&E cell instead of "Royal Berkshire Hospital".
- **Fix:** Each test's render helper now computes `$data['site_emergency_resolved'] = SiteEmergencyResolver::resolve($data['site_emergency'] ?? [])` inline before rendering — replicating just the one `upgrade()` step these narrowly-scoped tests need, via the same shared resolver, rather than pulling in the rest of `upgrade()`'s unrelated Tier-1 steps (which could introduce other side effects into files whose purpose is unrelated to CDM/A&E) or re-deriving the branch logic a third time.
- **Verification:** Both test files pass green individually and as part of the full 313-test suite.
- **Files modified:** `tests/Feature/Rams/RamsSection70HeadingTest.php`, `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php`
- **Commit:** `e9933aa` (bundled with Task 3's new test file, since both fixes were needed to get the full suite green after Task 3's own test proved the render-site change correct)

No other deviations — Tasks 1 and 2 executed exactly as written.

## Non-Vacuity Proof (Task 3)

Per the task's TDD discipline: temporarily reverted `rams.blade.php`'s Section 7.0 A&E row fix back to `{{ ($siteEmerg['nearest_hospital'] ?? '') ?: 'TBC' }}`, re-ran `SiteEmergencyRenderSitesRegressionTest` — 2 of 6 tests failed exactly as expected (the two `test_verified_state_*`/`test_hold_point_state_v1_*` tests asserting the resolved value appears), confirming the tests are not vacuously passing. Restored the real fix with `git checkout -- resources/views/pdf/rams.blade.php` (targeted single-file restore, not a blanket reset) and confirmed all 6 tests pass again.

## Issues Encountered

None beyond the two collateral test fixes documented above.

## Verification

- `php artisan test --filter=SiteEmergencyRenderSitesRegressionTest` — 6 passed (36 assertions)
- `php artisan test --filter=RamsSection70HeadingTest` — 3 passed (10 assertions)
- `php artisan test --filter=Tier1SiteEmergencyFormAndRenderTest` — 3 passed (15 assertions)
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS composer/blade/DOCX surface, 313 tests) — all green, 0 regressions
- Acceptance-criteria greps (all confirmed 0 matches on the banned string in all 3 modified render-site files; 'TBC' gone from the A&E row specifically in both blades; the new resolved-key reads present)

## Known Fixture Drift (expected, NOT regenerated — left for Plan 29-06)

`vendor/bin/phpunit --group snapshot` now shows **4 failures**, all in the `tilda-21cq29531` golden fixtures, all confirmed to be exactly this plan's intended content changes (not unrelated breakage):

- `PdfSnapshotTest::test_legacy_pdf_rams_blade_matches_golden_for_tilda` — Welfare bullet text changed to the Section 7.0 pointer; the legacy blade's A&E cell now renders blank because the tilda fixture's `generated_data` has no `site_emergency` key (only `reviewed_data` does — `PdfSnapshotTest` doesn't run `upgrade()`, so `site_emergency_resolved` is never populated for this fixture). This is expected: the fixture itself predates the mirroring behaviour `RamsController::updateAndDownload()` already performs on every real save (`generated_data['site_emergency'] = reviewed_data['site_emergency']`), and 29-06 is scoped to regenerate/reconcile the fixture set.
- `PdfSnapshotTest::test_unified_pdf_rams_v2_blade_matches_golden_for_tilda` — Welfare bullet text changed; the v2 blade's A&E cell now correctly shows the resolved verified value ("Queen's Hospital, Romford, Rom Valley Way, Romford RM7 0AG. Route and travel time confirmed at induction.") instead of the bare hospital name, because `EmergencyComposer` reads `reviewed_data` independently of `upgrade()`.
- `DocxSnapshotTest::test_legacy_docx_renderer_matches_golden_for_tilda` and `test_unified_docx_renderer_matches_golden_for_tilda` — both show the Welfare bullet's banned-string-to-pointer swap in the DOCX XML.

Per this plan's explicit constraint: **not regenerated here.** `tests/Fixtures/rams/tilda-21cq29531/expected-html-v1.html`, `expected-html-v2.html`, `expected-docx-v1.xml.norm`, `expected-docx-v2.xml.norm` are Plan 29-06's responsibility.

## User Setup Required

None — no config flags touched by this plan (GATE-11/GATE-12 remain disarmed from Plan 29-03; this plan only edits render templates and a service's item array, all unconditional).

## Next Phase Readiness

- Plan 29-05's backfill migration and Plan 29-06's fixture regeneration sweep can now proceed against render sites that read one consistent resolved value everywhere — no more per-site re-derivation to reconcile.
- RULE-08 is now implemented end-to-end for every real render site (legacy and composer), including production's actual `RAMS_UNIFIED_COMPOSER=false` path (29-01's measured fact) — marked complete in `.planning/REQUIREMENTS.md`.
- GATE-12 remains disarmed (`cdm_ae_gate_enabled` defaults `false`) — arming is Plan 29-05/29-06's job after the CDM backfill and a live regeneration verify clean, per D-03. No task in this plan armed it.
- No blockers.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-11*
