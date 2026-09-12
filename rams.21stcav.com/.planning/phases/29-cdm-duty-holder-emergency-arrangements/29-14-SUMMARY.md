---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 14
subsystem: rams-snapshot-fixtures
tags: [rams, gap-closure, snapshot, closeout, cdm, docx, pdf]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-11's PDF blade Section 7.0 + contractor_note render fixes"
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-12's DOCX Section 7.0 block + Welfare bullet + contractor_note render fixes"
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-13's DOCX brand colour correction"
provides:
  - "tests/Fixtures/rams/tilda-21cq29531/{expected-html-v1.html,expected-html-v2.html,expected-docx-v1.xml.norm,expected-docx-v2.xml.norm} — regenerated goldens reflecting Plans 29-10 through 29-13"
  - "Full-suite proof (349/349 default scope, 2541/2542 whole-repo scope) for 29-VERIFICATION.md's next re-verification pass"
affects:
  - "29-VERIFICATION.md — this plan's full-suite run is the evidence base for the phase's next re-verification"
  - "A human — still owns running the Plan 29-10 backfill migration against production, and resolving the open 29-UAT.md human_verification item (CDM table Client row)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Diff-first fixture regeneration without a built-in diff mode: RamsRegenerateSnapshotsCommand only prompts a yes/no confirm, it does not print a diff. Backed up the fixture directory, ran --force, then used git diff/git show against the pre-existing golden to review the change — the practical mechanism for the plan's diff-first requirement given the command's actual behaviour."
    - "Text-level diff of <w:t>...</w:t> extracted values (not the raw minified single-line XML) is the only tractable way to review a DOCX .xml.norm diff — git's line-based diff is useless against a near-single-line document.xml."

key-files:
  created: []
  modified:
    - tests/Fixtures/rams/tilda-21cq29531/expected-html-v1.html
    - tests/Fixtures/rams/tilda-21cq29531/expected-html-v2.html
    - tests/Fixtures/rams/tilda-21cq29531/expected-docx-v1.xml.norm
    - tests/Fixtures/rams/tilda-21cq29531/expected-docx-v2.xml.norm

key-decisions:
  - "Every diff line was mapped to one of the plan's five expected-change categories before regenerating was accepted (T-29-14-01 mitigation) — see 'Diff Review' below for the full mapping."
  - "Discovered during the diff review: this fixture's generated_data has no cdm_duty_holders key (only reviewed_data.cdm, a differently-shaped array) — so the DOCX CDM 2015 section, and therefore the RULE-07 contractor_note paragraph, never renders in either DOCX golden, before or after this cycle. The contractor_note addition is visible only in the two HTML/PDF goldens for this fixture. This is not a regression (zero DOCX diff either way) but is a real gap in this fixture's coverage of the DOCX contractor_note path — flagged here rather than silently accepted, per the plan's diff-first discipline."
  - "Did not attempt to fix the DOCX contractor_note test-coverage gap noted above — out of this plan's scope (fixture regeneration + full-suite proof only); Plan 29-12's own DocxEmergencySectionRegressionTest already exercises that path directly against a fixture with cdm_duty_holders present, so RULE-07's DOCX rendering is independently proven elsewhere."

patterns-established: []

requirements-completed: [RULE-07, RULE-08, GATE-11, GATE-12]  # This plan's role is verification/closeout,
  # not new implementation — confirms the render-site fixes from 29-10 through 29-13 are captured in
  # the regression-guarding goldens and that GATE-11/GATE-12 remain correctly disarmed and unaffected.

# Metrics
metrics:
  duration: "~50 minutes"
  completed: "2026-09-12"
---

# Phase 29 Plan 14: Gap-Closure Cycle Closeout — Fixture Regeneration + Full-Suite Proof Summary

**Regenerated the four `tilda-21cq29531` golden snapshot fixtures after diff-reviewing every changed
line against Plans 29-10 through 29-13's stated changes, confirmed `php artisan test --group snapshot`
passes byte-for-byte against the new goldens, and ran the full RAMS suite plus the whole-repo suite to
confirm zero regressions — closing out the 29-UAT.md gap-closure cycle.**

## What Was Built

**Task 1 — Diff-first fixture regeneration.** `RamsRegenerateSnapshotsCommand` has no built-in diff
mode (only a yes/no overwrite confirmation), so the diff-first review was done by backing up the
fixture directory, running `php artisan rams:regenerate-snapshots tilda-21cq29531 --force`, then
reviewing `git diff` / `git show HEAD:...` against the previous goldens. `git diff --stat` confirmed
exactly the four expected fixture files changed (`record.json` untouched).

Because the two `.xml.norm` DOCX goldens are single near-unbroken lines, a raw line diff is
unreadable; extracted every `<w:t>...</w:t>` text value from old vs. new and diffed those instead —
this gave a clean, reviewable text-level diff.

**Diff Review — every line mapped:**

| File | Change | Category | Plan |
|---|---|---|---|
| html-v1, html-v2 | New `<p class="body-para">21CAV is currently anticipated to be the sole contractor...</p>` after the CDM table | (c) contractor_note paragraph | 29-11 |
| html-v1 | A&E table cell now shows resolved value "Queen's Hospital, Romford, Rom Valley Way, Romford RM7 0AG. Route and travel time confirmed at induction." instead of blank (plus whitespace/indentation from the row moving outside the old `@if` gate) | (a) Section 7.0 A&E row content | 29-11 |
| html-v2 | Same A&E row: only whitespace/indentation changed — the resolved value was already present pre-fix (v2's unified composer already read the resolver correctly; the gap was v1-only) | (a) Section 7.0 A&E row (structural, no content delta) | 29-11 |
| html-v2 | `--palette-brand-blue: #2E74B5→#1B7A7A`, `--palette-brand-blue-dark: #1F4D78→#1A1A2E`, `--palette-brand-blue-tint`/`--palette-alt-row: #DEEBF7→#F4FBFB`, `--palette-dark-text: #333333→#1A1A2E` | (d) hex colour changes (v2's CSS custom-property mirror of the same palette fix) | 29-13 |
| docx-v1, docx-v2 | Welfare First Aid bullet: "...see CDM 2015 — Duty Holders section." → "...see Section 7.0." | (e) Welfare bullet text change | 29-12 |
| docx-v1, docx-v2 | New "7.0 Site-Specific Emergency Details" block: Nearest A&E Hospital (resolved value + address sub-line), Fire Assembly Point, Fire Warden, First Aider, Nearest Defibrillator | (a) Section 7.0 A&E row / new DOCX block | 29-12 |
| docx-v1, docx-v2 | Full palette replace: 98× `2E74B5`→`1B7A7A`, 47× `DEEBF7`→`F4FBFB`, 320/316× `333333`→`1A1A2E` (verified by exact hex-occurrence counts, old vs. new) | (d) hex colour changes | 29-13 |

No diff line failed to map to one of the five expected categories — nothing was halted.

**Discovered gap (documented, not fixed — out of scope):** this fixture's `generated_data` has no
`cdm_duty_holders` key (only `reviewed_data.cdm`, the differently-shaped legacy array).
`DocxBuilderService::buildCdmSection()` gates the entire CDM 2015 section — including the RULE-07
`contractor_note` paragraph — behind `$data['cdm_duty_holders']` being non-empty, so that whole
section is absent from both DOCX goldens, before and after this cycle (confirmed: zero occurrences
of "2015", "Duty Holders", or the contractor_note sentence in either `.xml.norm` file, old or new).
The PDF blades, by contrast, render the CDM 2015 section unconditionally from `reviewed_data.cdm`
with a `?? DEFAULT_CONTRACTOR_NOTE` fallback, which is why the contractor_note paragraph appears in
the HTML goldens but not the DOCX goldens for this specific fixture. This is not a regression (the
DOCX diff for this section is empty both directions) — Plan 29-12's own
`DocxEmergencySectionRegressionTest` independently proves the DOCX contractor_note path against a
fixture that does have `cdm_duty_holders` populated, so RULE-07 DOCX coverage exists, just not via
this particular golden fixture.

`php artisan test --group snapshot` — 6/6 pass (byte-equality assertions, not the first-run-captures
skip path):
- `DocxSnapshotTest`: legacy renderer matches golden, unified renderer matches golden, v1/v2 bounded delta
- `PdfSnapshotTest`: legacy blade matches golden, unified blade matches golden, v1/v2 comparable output

**Task 2 — Full suite + gate-disarm confirmation.**

- `config/rams_tier1.php:134` — confirmed byte-identical (`'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false)`); `git log` shows the last touch to this file was `90967f4` (Plan 29-02), untouched by any of Plans 29-10 through 29-14.
- `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` + `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` — 15/15 pass, 36 assertions. Both gates' throw/no-throw coverage is unaffected by the render-only changes in this cycle.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (the plan's specified full-RAMS-surface scope) — **349 passed, 1604 assertions, 78.02s, zero failures.** Same 349-test count as Plan 29-13's post-fix run (which reported 1613 assertions); the small assertion-count delta (1604 vs. 1613) could not be attributed to any change made in this plan — no test outside `tests/Feature/Rams/Snapshot/` references the `tilda-21cq29531` fixture (confirmed by grep), and this run excludes the snapshot group entirely, so the fixture regeneration in Task 1 cannot be the cause. Recorded here for honest accounting rather than silently reconciled; zero test failures either way.
- `php artisan test` (whole-repo suite, ~9.8 min) — **2541 passed, 1 failed, 6 skipped, 2 deprecated, 10 warnings, 9907 assertions, 588.25s.** The 1 failure is the pre-existing `Tests\Feature\Queue\QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` (`assertSame(QueueRecoverCommand::EXIT_RECOVERED, $exit)` — `1` vs `0`), which is explicitly documented as a known, unrelated memory-threshold interaction in the test file's own comment block (lines 159-162) and is out of this working directory's brief entirely. No other failures, no regressions to anything passing before this gap-closure cycle began.

## Deviations from Plan

None — plan executed exactly as written. No architectural changes, no bugs found, no missing
functionality discovered that required a fix in this plan's scope. The one notable discovery (DOCX
contractor_note absent from this fixture's goldens) is a documentation-only observation, not a defect
this plan is responsible for fixing — see key-decisions above.

## Verification

- `git diff --stat tests/Fixtures/rams/tilda-21cq29531/` — exactly 4 files changed (`expected-html-v1.html`, `expected-html-v2.html`, `expected-docx-v1.xml.norm`, `expected-docx-v2.xml.norm`); `record.json` untouched.
- `php artisan test --group snapshot` — 6/6 pass.
- `grep -n "cdm_ae_gate_enabled" config/rams_tier1.php` — line 134, `env('RAMS_CDM_AE_GATE', false)`, unchanged.
- `git log --oneline -3 -- config/rams_tier1.php` — last touch `90967f4` (Plan 29-02), predates this gap-closure cycle entirely.
- `php artisan test tests/Unit/Services/Rams/CdmEmergencyGateTest.php tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` — 15/15 pass, 36 assertions.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` — 349/349 pass, 1604 assertions, 78.02s.
- `php artisan test` (whole repo) — 2541 passed / 1 pre-existing unrelated failure / 6 skipped, 9907 assertions, 588.25s.

## Human/Production Actions Still Outstanding (explicitly NOT done by this plan)

- **`database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php` (Plan 29-10) has NOT been run against production.** It is code-complete and test-proven (6/6 tests in `BackfillCdmContractorNoteMigrationTest`), but no `php artisan migrate` invocation occurred anywhere in this plan or the ones before it. A human must run `php artisan migrate --force` on the VPS after this render-fix cycle deploys, per Plan 29-10's explicit scope boundary.
- **The 29-UAT.md `human_verification` item (CDM table Client row) remains open and unresolved.** `pdftotext -layout` scrambles the wrapped multi-line CDM cells, so it cannot be settled programmatically; a human must open the rendered PDF and confirm the Client row shows the client's name (Gardner Leader LLP) and that the client is not shown as Principal Designer. This plan did not and could not close that item.
- No production DB or VPS was touched by this plan. No deploy was performed.

## Self-Check: PASSED

- FOUND: `tests/Fixtures/rams/tilda-21cq29531/expected-html-v1.html` (modified, contractor_note paragraph + resolved A&E value present)
- FOUND: `tests/Fixtures/rams/tilda-21cq29531/expected-html-v2.html` (modified, brand colour custom properties + contractor_note paragraph present)
- FOUND: `tests/Fixtures/rams/tilda-21cq29531/expected-docx-v1.xml.norm` (modified, Section 7.0 block + reworded Welfare bullet + brand hex present, verified via `<w:t>` text extraction)
- FOUND: `tests/Fixtures/rams/tilda-21cq29531/expected-docx-v2.xml.norm` (modified, same as v1)
- FOUND: commit `346306c` (`test(29-14): regenerate tilda-21cq29531 golden snapshots for gap-closure cycle`) in `git log`

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-12*
