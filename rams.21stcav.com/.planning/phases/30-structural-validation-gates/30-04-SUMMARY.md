---
phase: 30-structural-validation-gates
plan: 04
subsystem: rams
tags: [laravel, blade, php, validation-gates, rams-compliance, ui]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 01
    provides: generated_data['compliance_warnings'] channel, written unconditionally and wholesale on every RamsComplianceUpgradeService::upgrade() run
provides:
  - Surface 1 — warnings summary panel on resources/views/rams/review.blade.php (.alert.alert-warning, role=status), reading generated_data['compliance_warnings'] wholesale; renders nothing when the array is empty or absent
  - Surface 2 — .gate-flagged hazard-row CSS rule (reuses .diff-modified's exact 3px rail + 6% tint) plus a decorative aria-hidden ⚠ glyph, matched to a row strictly by hazard_index
  - tests/Feature/Rams/ComplianceWarningsRenderTest.php — 12 tests covering empty state, populated state (singular/plural, row matching, null hazard_index), XSS escaping, and the must-not-leak boundary against both PDF blades and both DOCX builders
affects: [30-06, 30-08, 30-09, 31-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Advisory-only render surface reading a single data-array source of truth for both a summary panel and an inline row marker, never a second computation in the blade — precedent for GATE-05 (Phase 31, warn-only)"
    - "Early-exit-by-emptiness pattern (no outer @if wrapper needed by callers) mirrored from components/stale-banner.blade.php, applied inline via @if (!empty(...)) rather than as a separate Blade component since the panel needs $rams-scoped data already in view scope"
    - "A CSS rule that is unconditionally always-present in a page's own <style> block (mirroring .diff-modified/.diff-added) must never carry example markup or gate-ID names in its own comment — the comment text itself renders into the page HTML and is therefore part of the same leak/coverage-claim surface as the visible copy"

key-files:
  created:
    - tests/Feature/Rams/ComplianceWarningsRenderTest.php
  modified:
    - resources/views/rams/review.blade.php

key-decisions:
  - "GATE-04/GATE-14 NOT marked complete in REQUIREMENTS.md by this plan. This plan ships only the render channel (30-UI-SPEC.md's Surface 1 + 2); the actual GATE-04 residual-score check and GATE-14 missing-risk-reference check ship in Plans 30-06 and 30-08 respectively. Matches the project's own precedent: Plan 30-01 listed all five Phase 30 gates in its frontmatter despite being foundation-only work, but REQUIREMENTS.md's traceability table did not flip GATE-01/GATE-02 to Complete until Plan 30-03 shipped their actual gate bodies. This SUMMARY's requirements-completed is therefore empty."
  - "Panel markup written inline in review.blade.php (not extracted to a Blade component) because it needs no reusable API beyond this one page and the UI-SPEC's own precedent (stale-banner) is a component only because it is genuinely shared across RAMS/O&M/worksheet — this warning channel is RAMS-review-only"
  - "Row-matching uses strict === comparison between the warning's hazard_index and the loop's $hIdx (both plain ints from a sequential array) — never a loose/string comparison, so a null hazard_index can never accidentally match index 0"
  - "When a row is both diff-modified and gate-flagged, .gate-flagged is declared after .diff-modified in the page's own <style> block so source order resolves the shared !important collision in the gate-flag's favour (safety signal wins over diff signal) — documented as an explicit comment, not left implicit"

requirements-completed: []

duration: ~35min
completed: 2026-09-13
---

# Phase 30 Plan 04: Review-Screen Warning Surface Summary

**Two render surfaces — a `.alert-warning` summary panel and a `.gate-flagged` hazard-row rail with an `⚠` glyph — both reading the single `generated_data['compliance_warnings']` array on the RAMS review screen, plus a security-critical assertion that the warning text can never reach a generated PDF or DOCX.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-13T20:05:00+01:00 (approx.)
- **Completed:** 2026-09-13T20:43:26+01:00
- **Tasks:** 2
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments

- **Surface 1 (summary panel):** inserted immediately after the existing `session('error')` flash block and before the hidden regen form, above the tab wrapper so it is visible regardless of which tab is active. Reuses `.alert.alert-warning` (not `.alert-banner--warning`, which carries a dismiss control this warning must never have). Pluralises `⚠ {N} item(s) flagged for review`; renders one `<li>` per warning as `{gate} — {hazard/step}: {message}`. Renders nothing at all when `compliance_warnings` is empty or absent — verified byte-identical-shape by dedicated tests.
- **Surface 2 (hazard-row marker):** added a `.gate-flagged` sibling rule in the page's own `<style>` block, reusing `.diff-modified`'s exact 3px left rail and 6% `color-mix` tint. Because the two rails are pixel-identical, an `aria-hidden="true"` `⚠` glyph is rendered immediately before the hazard name on flagged rows only — the actual differentiator. A `title="{gate} — {message}"` attribute carries the reason for assistive tech and mouse-hover. Matching is strictly by `hazard_index === $hIdx`; a warning with a `null` hazard_index produces a panel entry and deliberately never guesses a row.
- **Precedence rule made explicit and commented:** when a row is both diff-modified and gate-flagged, `.gate-flagged` is declared after `.diff-modified` so the shared `!important` collision resolves to the safety signal, not by accident of source order.
- **Partially-armed copy discipline:** the panel never claims "all checks passed" and never names a gate that did not fire in a given render — each warning names only its own gate, per 30-UI-SPEC.md's "no coverage claims" requirement.
- **The must-not-leak boundary is now asserted, not assumed:** `ComplianceWarningsRenderTest` places a distinctive sentinel string in a fixture warning's `message` and proves its absence from `pdf.rams`, `pdf.rams-v2`, `DocxBuilderService::build()`, and `DocxBuilderServiceV2::build()` output (via direct blade render / `ZipArchive` extraction of `word/document.xml`, mirroring `CdmContractorNoteRenderRegressionTest` and `DocxBrandColourRegressionTest`).
- **XSS escaping proven:** a warning `hazard` containing a literal `<script>` tag and a `message` containing `< & "` render Blade-escaped (`{{ }}`), never as raw markup — the test fails if the markup is ever "improved" to `{!! !!}` to render the glyph.

## Task Commits

Each task was committed atomically:

1. **Task 1: Warnings summary panel and hazard-row marker** - `6f346f8` (feat)
2. **Task 2: ComplianceWarningsRenderTest — empty state, populated state, and the leak assertion** - `92ad29b` (test)

_Both tasks were `tdd="true"`; tests were written and run to confirm behaviour before each commit, per this plan's autonomous execution mode. Task 1's commit lands the render implementation once the (then-uncommitted) test file was green against it; Task 2 commits the finished test file itself, having driven two rounds of fix-and-rerun against the Task 1 implementation (see Deviations)._

## Files Created/Modified

- `resources/views/rams/review.blade.php` - Surface 1 warnings panel (immediately after the `session('error')` flash block) + Surface 2 `.gate-flagged` CSS rule and hazard-row `⚠`/class/title wiring in the Hazard Register table
- `tests/Feature/Rams/ComplianceWarningsRenderTest.php` - 12 tests: empty/absent state (2), populated state incl. singular/plural/row-matching/null-index (4), partially-armed copy discipline (1), XSS escaping (1), must-not-leak across both PDF blades and both DOCX builders (4)

## Decisions Made

- GATE-04/GATE-14 requirements left `Pending` in REQUIREMENTS.md — this plan ships the render channel only; see key-decisions above for the Plan 30-01/30-03 precedent this follows
- Panel markup inlined in `review.blade.php` rather than extracted to a Blade component (no cross-document reuse need, unlike `stale-banner`)
- Strict `===` index matching so a `null` hazard_index can never coincide with row index `0`
- `.gate-flagged` declared after `.diff-modified` in source order, with an explicit comment recording that this is what resolves the `!important` precedence, not an accident

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Own CSS comment leaked a specific gate ID into every render**
- **Found during:** Task 2 first full test run (`php artisan test --filter=ComplianceWarningsRenderTest`)
- **Issue:** The `.gate-flagged` CSS rule's explanatory comment (inside the page's own `<style>` block, which is real HTML output, not a stripped `{{-- --}}` Blade comment) originally read "Phase 30 GATE-04/GATE-14 — Surface 2 ...". Because CSS `/* */` comments inside `<style>` render literally into the page, this put the literal string `GATE-14` on every review-screen render regardless of whether GATE-14 ever fired — violating the UI-SPEC's "never name a gate that did not run" rule at the letter, and tripping the corresponding test assertion.
- **Fix:** Reworded the comment to describe the surface generically ("Phase 30 structural-gate advisory warnings") with no specific gate ID, and added an explicit note that the stylesheet itself must never be readable as a coverage claim.
- **Files modified:** `resources/views/rams/review.blade.php`
- **Verification:** Re-ran `ComplianceWarningsRenderTest` — the "never names a non-firing gate" test passed after the fix.
- **Committed in:** `6f346f8` (Task 1 commit — the comment was authored and fixed before Task 1 was committed)

**2. [Rule 1 - Bug] Test design didn't distinguish the always-present CSS selector from the applied row class**
- **Found during:** Task 2 first full test run
- **Issue:** `.gate-flagged` as a CSS *rule* is always present in the page's `<style>` block (mirroring `.diff-modified`/`.diff-added`, which are likewise always present regardless of whether any diff exists). Three test assertions originally checked for the bare substring `gate-flagged` anywhere in the page (`assertDontSee`, `assertSame(1, substr_count(...))`, `assertStringNotContainsString`), which is always true because the CSS selector text itself contains that substring — these assertions could never distinguish "no row is flagged" from "a row is flagged."
- **Fix:** Narrowed all three assertions to the actual applied attribute, `class="gate-flagged"`, which appears in rendered HTML only on a `<tr>` that matched a warning's `hazard_index` — not in the stylesheet.
- **Files modified:** `tests/Feature/Rams/ComplianceWarningsRenderTest.php`
- **Verification:** Full 12-test file green after the fix (`php artisan test --filter=ComplianceWarningsRenderTest` — 12 passed, 40 assertions).
- **Committed in:** `92ad29b` (Task 2 commit)

**3. [Rule 1 - Bug] Second round: the explanatory-comment fix itself echoed the literal class-attribute string**
- **Found during:** Task 2 second full test run, after fix #1 and #2 above
- **Issue:** The reworded CSS comment from fix #1 explained the row-vs-selector distinction using the literal prose `` `class="gate-flagged"` `` — which, because it sits inside the same always-rendered `<style>` block, put the literal substring `class="gate-flagged"` on every page render regardless of any actual warning, defeating the very assertions fix #2 had just narrowed to.
- **Fix:** Reworded the comment a second time to describe the distinction ("this class actually being applied to a `<tr>` element, not the selector's mere presence in this stylesheet") without ever spelling out the literal `class="..."` attribute string.
- **Files modified:** `resources/views/rams/review.blade.php`
- **Verification:** Full 12-test file green (`php artisan test --filter=ComplianceWarningsRenderTest` — 12 passed, 40 assertions, 8.54s actual test runtime).
- **Committed in:** `6f346f8` (Task 1 commit — both comment fixes were folded into the single Task 1 implementation commit, since neither test file existed as committed content yet at either fix point)

---

**Total deviations:** 3 auto-fixed (all Rule-1 bugs, self-contained to this plan's own new content — 2 in the blade's CSS comment text, 1 in the test's own assertion design). No scope creep; no other file touched.

## Issues Encountered

- `php artisan test --filter=...` on this project has a large (~1-4 minute) fixed startup cost from PHPUnit's full-suite attribute/doc-comment discovery scan before the filter narrows execution, even though actual test execution is consistently under 10 seconds once running (confirmed via `Get-Process` CPU-time polling during the run). This is pre-existing project behaviour, not something this plan introduced or should fix (out of scope per the plan's file list).
- Confirmed the two pre-existing `{!! !!}` occurrences in `review.blade.php` (lines ~1284/1292, `$diffHint()` calls) are unrelated to this plan's new markup, which uses `{{ }}` exclusively throughout — verified by grep restricted to the new sections.

## User Setup Required

None. No new config, no new env var, no new migration. Purely a Blade view change plus a new test file.

## Verification Results

- `php artisan test --filter=ComplianceWarningsRenderTest` — **12 passed, 40 assertions** (PowerShell/Herd)
- `php artisan test --filter=Rams` (full RAMS suite, before/after) — **before this plan: 799 passed** (per Plan 30-03's SUMMARY, last recorded full-suite baseline); **after this plan: 811 passed** (2 pre-existing deprecated-metadata warnings, unrelated), 3060 assertions — net +12 tests, zero regressions
- Grep of `resources/views/rams/review.blade.php`'s new markup: no `{!! !!}` introduced (the two pre-existing occurrences elsewhere in the file are unrelated to this plan)
- Grep of `resources/views/pdf/` and `app/Services/DocxBuilderService*.php` for `compliance_warnings`: **zero occurrences** — the leak boundary holds by construction, and is now also proven by assertion in `ComplianceWarningsRenderTest`

## Next Phase Readiness

- Plans 30-06 (GATE-04) and 30-08 (GATE-14) can now push real warnings into `generated_data['compliance_warnings']` and have them render correctly on the review screen with no further UI work
- Plan 31's GATE-05 (warn-only) is unblocked — it can reuse this same channel/render pattern rather than inventing a second advisory surface
- Known limitation carried forward unchanged from 30-UI-SPEC.md: `RamsController::review()` does not call `upgrade()`, so a freshly-opened review of a document last saved before the relevant gate's flag flips true will show no warnings until the next Save Review or regenerate persists the key. This is an accepted, documented limitation, not a defect of this plan.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-13*

## Self-Check: PASSED

Both files created/modified by this plan verified present on disk
(`resources/views/rams/review.blade.php`, `tests/Feature/Rams/ComplianceWarningsRenderTest.php`).
Both commit hashes (`6f346f8`, `92ad29b`) confirmed present in `git log --oneline --all`.
