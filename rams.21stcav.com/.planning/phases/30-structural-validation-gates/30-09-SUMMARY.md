---
phase: 30-structural-validation-gates
plan: 09
subsystem: rams
tags: [laravel, php, validation-gates, rams-compliance, snapshot-testing, phase-gate]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 04
    provides: generated_data['compliance_warnings'] advisory channel, review-screen render surfaces, must-not-leak assertion mechanism
  - phase: 30-structural-validation-gates
    plan: 08
    provides: all five Phase 30 gate bodies shipped (GATE-01/02/04/13/14), the complete D-04 flag-independence matrix, StructuralGateVocabulary
provides:
  - tests/Fixtures/rams/21cq30960/record.json — hand-crafted CLEAN fixture (VW Blakelands, post-fix), passes all five armed Phase 30 gates with zero warnings on non-vacuous input
  - tests/Fixtures/rams/21cq30960-defects/record.json — hand-crafted DEFECT-bearing fixture (pre-fix RAMS 97 shape), fires GATE-13 (COSHH half) and GATE-14 (RA01)
  - tests/Feature/Rams/StructuralGatesRealDocumentTest.php — ROADMAP criterion 4 proven by fixture, 8 tests
  - Clean fixture wired into PdfSnapshotTest.php/DocxSnapshotTest.php with 4 new golden-render tests + 2 new leak-boundary tests
  - .planning/phases/30-structural-validation-gates/30-VALIDATION.md fully reconciled against actual wave 3-6 results (was reconciled only against the plan contract by Plan 30-05)
  - Full phase-gate verification recorded: 2670/2671 passing on the whole suite (one pre-existing unrelated failure), 12/12 on the snapshot suite, 873/873 on the RAMS filter
affects: [31-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two-fixture proof for a 'regenerates clean' ROADMAP criterion: one fixture reproducing the real-world defect shape (proves the gates fire), one clean regeneration of the same project at a later point in time (proves no false positives) — converts a live-only UAT step into an automated, repeatable gate"
    - "A fixture's own _notes records not just what it models but which of the plan's own illustrative assumptions were verified-and-corrected during authoring (keyword-collision avoidance against crossReferenceMethodStatementRisks()'s $keywordRiskMap, signal-vocabulary phrase verification against config) rather than trusted from plan prose"
    - "A leak-boundary assertion belongs in the render/snapshot test file itself (grep the actual rendered artefact for every gate ID + the channel name), not only as a one-time manual review step in a checkpoint's <how-to-verify> block — makes the property permanent, not a point-in-time observation"

key-files:
  created:
    - tests/Fixtures/rams/21cq30960/record.json
    - tests/Fixtures/rams/21cq30960-defects/record.json
    - tests/Feature/Rams/StructuralGatesRealDocumentTest.php
    - tests/Fixtures/rams/21cq30960/expected-html-v1.html
    - tests/Fixtures/rams/21cq30960/expected-html-v2.html
    - tests/Fixtures/rams/21cq30960/expected-docx-v1.xml.norm
    - tests/Fixtures/rams/21cq30960/expected-docx-v2.xml.norm
  modified:
    - tests/Feature/Rams/Snapshot/PdfSnapshotTest.php
    - tests/Feature/Rams/Snapshot/DocxSnapshotTest.php
    - .planning/phases/30-structural-validation-gates/30-VALIDATION.md
    - .planning/REQUIREMENTS.md

key-decisions:
  - "GATE-13's permit half is architecturally UNREACHABLE through the real upgrade() pipeline — RamsComplianceUpgradeService::addPermitAndIsolation() unconditionally overwrites $data['permit_and_isolation'] with its own fixed, conditionally-worded rule set on every call, regardless of fixture input. This re-confirms (does not merely repeat) the existing D-02/config/rams_tier1.php:216-231 finding — HotWorksGateTest's own class docblock independently states the same fact. The defects fixture's _notes and StructuralGatesRealDocumentTest both record this explicitly with a dedicated test proving the permit half cannot fire; only the COSHH half can, in production, today."
  - "GATE-14's manual_handling coverage gap is recorded, not fixed. config('rams_tier1.missing_risk_implications') defines only two signals (mounting_above_reach, ceiling_void_access) — no manual_handling signal exists anywhere in StructuralGateVocabulary::SUPPORTED_SIGNALS or HazardIncludeWhenResolver's const maps. The defects fixture reproduces BOTH omissions from the canonical Step-4 defect (RA01 Working at Height, RA02 Manual Handling) faithfully, but GATE-14 can only fire on RA01. A dedicated test asserts exactly one GATE-14 warning and instructs a future editor to update the test alongside the SUMMARY if the gap is ever closed — per explicit user direction, the vocabulary is config data precisely so the 30-MEASUREMENT.md corpus pass can drive it with evidence, not guesswork."
  - "GATE-01/GATE-02/GATE-04 corrected from Complete back to Pending in REQUIREMENTS.md (both the itemised checklist and the traceability table), per an explicit coordinator instruction reproducing the project's own GATE-11 precedent (REQUIREMENTS.md:72) that a disarmed gate is not Complete regardless of code-completeness. All three still ship correctly disarmed (structural_gates_enabled/RAMS_STRUCTURAL_GATES defaults false); only the REQUIREMENTS.md status bookkeeping was wrong, not the shipped behaviour. requirements-completed is deliberately left empty in this SUMMARY's frontmatter for the same reason — following Plan 30-04's own precedent of not flipping a requirement to Complete on a disarmed gate."
  - "Only the CLEAN fixture is wired into the snapshot suite (T-30-20) — the defects fixture is a gate fixture only, fed directly to upgrade() by StructuralGatesRealDocumentTest, and is never rendered or snapshotted. Capturing a golden from deliberately-broken output would permanently encode that breakage as expected."
  - "Both snapshot test files gained a leak-boundary test (grepping the rendered HTML/DOCX for every Phase 30 gate ID plus 'compliance_warnings') rather than relying solely on the checkpoint's manual grep step — makes ROADMAP criterion 4's second half a permanent regression guard, not a one-time observation."

patterns-established:
  - "Fixture _notes recording a Rule-1 correction discovered during authoring (not merely a description of what was modelled) — the defects fixture's _notes states the permit-half-unreachable finding in full, so a future reader does not have to re-derive it from the code"

requirements-completed: []

# Metrics
duration: ~2h10min
completed: 2026-09-14
---

# Phase 30 Plan 09: 21CQ30960 Fixtures, Snapshot Wiring and the Phase Gate Summary

**Two hand-crafted 21CQ30960 fixtures (clean post-fix, defect-bearing pre-fix) prove ROADMAP criterion 4 by automated test rather than live-only UAT — all five Phase 30 gates pass clean on non-vacuous real-document-shaped input, the defect fixture fires GATE-13 (COSHH half only — the permit half is architecturally unreachable through the real pipeline) and GATE-14 (RA01 only — no manual_handling signal exists), and the full phase gate closes at 2670/2671 passing on the whole suite (one pre-existing unrelated failure), 12/12 on the snapshot suite, 873/873 on the RAMS filter.**

## Performance

- **Duration:** ~2h 10min (includes the ~605s full-suite run and the checkpoint round-trip)
- **Completed:** 2026-09-14
- **Tasks:** 3 (2 auto + 1 blocking checkpoint)
- **Files modified:** 11 (7 created, 4 modified)

## Accomplishments

- **Two fixtures authored** under `tests/Fixtures/rams/21cq30960/` (clean) and `tests/Fixtures/rams/21cq30960-defects/` (pre-fix), both following the `tilda-21cq29531` top-level shape exactly, each with a `_notes` field recording why it is hand-crafted, what it models, and (new for these two) which defects each deliberately carries or does not.
- **Clean fixture engineered for non-vacuity**: a real `areas_for_gate` entry genuinely covered by a method step (GATE-02), a fully-populated `client_responsibilities_expanded` plus a `client_responsibilities` entry supporting a real GATE-01 trigger phrase ("asbestos register") with a matching hazard row (GATE-01 walks its full match-and-pass logic, not a trivial pass on an absent trigger), and every hazard row carrying complete pre/post scores with `post_severity == pre_severity` throughout (GATE-04's warn tier genuinely never fires, not merely unpopulated).
- **Defects fixture reproduces both named defect classes**: GATE-13's "three sections disagreeing" (a "no hot works" absence assertion in a hazard name, plus Tin/Lead Solder and Rosin Flux in `coshh_baseline`) and GATE-14's canonical Step-4 citation gap (a "stepladder" phrase implying `mounting_above_reach`, resolved to an uncited "Working at height — installation above standing reach" hazard row, verified during authoring to share no `crossReferenceMethodStatementRisks()` `$keywordRiskMap` keyword with that phase's text, so the citation gap is genuine, not one the app's own auto-citation had already closed).
- **`StructuralGatesRealDocumentTest.php`** (8 tests, all passing on first run): clean fixture passes all five armed gates with zero warnings; clean fixture's non-vacuity inputs are themselves asserted non-empty (guards the first test from passing for the wrong reason); clean fixture also passes disarmed; defects fixture fires GATE-13 via the COSHH half when armed; a dedicated test proves the permit half cannot fire once COSHH is removed; defects fixture fires GATE-14 on RA01 only (asserted count of exactly 1, with a comment flagging the known RA02 gap); defects fixture passes disarmed; both fixtures pass silently with all three Phase 30 flags false.
- **Snapshot wiring** (Task 2): explicit new test methods added to both `PdfSnapshotTest.php` and `DocxSnapshotTest.php` for `21cq30960` (fixture names are hardcoded literals, no data provider), plus a new leak-boundary test in each file grepping the rendered HTML/DOCX for every Phase 30 gate ID and `compliance_warnings` — zero matches, confirmed. Four goldens captured via `php artisan rams:regenerate-snapshots 21cq30960 --force`, reviewed diff-first (content correctly names "VW Blakelands", "21CQ30960", "Volkswagen Group UK"; zero leak-text matches via direct grep), and the snapshot suite (12 tests) is green and byte-stable on a second run.
- **30-VALIDATION.md fully reconciled**: Plan 30-05's mid-phase reconciliation (before waves 3-5 executed) left every row for Plans 30-06 through 30-09 marked `⬜ pending`. This plan flipped every remaining row to `✅ green`, cross-checked against each plan's own SUMMARY, recorded the D-04 matrix as complete with its shipped commit hashes, ticked the remaining Wave 0 checkboxes with test-count annotations, and appended a dated reconciliation note distinguishing the contract-only pass from this results-verified pass.
- **Phase gate run from PowerShell** (never Bash, per this project's own tooling warning): `php artisan test` (full suite) — **2670 passed, 1 failed, 10330 assertions, 605.22s**; the one failure is `Tests\Feature\Queue\QueueRecoverCommandTest > unhealthy queue runs restart and drain plan`, confirmed by direct string match to be the exact pre-existing, pre-documented Phase 29 failure (not a Phase 30 regression). `vendor\bin\phpunit --group snapshot` — **12 passed, 71 assertions**, green and stable across two consecutive runs. `php artisan test --filter=Rams` — **873 passed, 0 failed** (baseline 865 at end of Plan 30-08 + 8 new tests).
- **All three Phase 30 flags confirmed still disarmed**: `config/rams_tier1.php` still reads `env('RAMS_STRUCTURAL_GATES', false)`, `env('RAMS_MISSING_RISK_REF_GATE', false)`, `env('RAMS_HOT_WORKS_GATE', false)`; direct grep of the actual `.env` file (not just `.env.example`) for all three flag names returned zero matches.

## Task Commits

Each task was committed atomically:

1. **Task 1: Author both 21CQ30960 fixtures and the real-document gate test** - `1941939` (test)
2. **Task 2: Wire the clean fixture into both snapshot test files and capture goldens** - `0af82ea` (feat)
3. **30-VALIDATION.md reconciliation (part of Task 3's close-out)** - `964ccba` (docs)
4. **REQUIREMENTS.md correction — GATE-01/02/04 back to Pending, per coordinator instruction** - `b66b7e5` (docs)

**Plan metadata:** *(this commit)* `docs: complete 30-09 plan`

## Files Created/Modified

- `tests/Fixtures/rams/21cq30960/record.json` - clean fixture, all five gates armed pass with zero warnings on non-vacuous input
- `tests/Fixtures/rams/21cq30960-defects/record.json` - defect-bearing fixture, fires GATE-13 (COSHH half) and GATE-14 (RA01)
- `tests/Feature/Rams/StructuralGatesRealDocumentTest.php` - 8 tests proving ROADMAP criterion 4 by fixture
- `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` - 2 new golden-render tests + 1 leak-boundary test for `21cq30960`
- `tests/Feature/Rams/Snapshot/DocxSnapshotTest.php` - 2 new golden-render tests + 1 leak-boundary test for `21cq30960`
- `tests/Fixtures/rams/21cq30960/expected-html-v1.html`, `expected-html-v2.html`, `expected-docx-v1.xml.norm`, `expected-docx-v2.xml.norm` - captured goldens, reviewed diff-first
- `.planning/phases/30-structural-validation-gates/30-VALIDATION.md` - reconciled against actual wave 3-6 results (previously reconciled only against the plan contract)
- `.planning/REQUIREMENTS.md` - GATE-01/GATE-02/GATE-04 corrected from Complete to Pending (checklist + traceability table)

## Decisions Made

- **GATE-13's permit half is unreachable through the real pipeline.** `RamsComplianceUpgradeService::addPermitAndIsolation()` unconditionally overwrites `$data['permit_and_isolation']` with its own fixed, conditionally-worded rule set on every `upgrade()` call, regardless of what any fixture supplies — re-confirmed (not merely assumed from the plan's illustrative prose) during this plan's authoring, and independently corroborated by `HotWorksGateTest`'s own class docblock ("the PERMIT half is live on ALL SIX sites"). **Only the COSHH half can fire in production today.** Phase 31 flips `RAMS_HOT_WORKS_GATE` on the strength of GATE-13's tested behaviour — it should know it is arming a gate whose permit half can never trigger through any of the six real call sites, only through a document carrying solder/flux in `coshh_baseline`.
- **GATE-14's `manual_handling` coverage gap is recorded, not fixed.** GATE-14 fires on RA01 (`mounting_above_reach` signal, phrase "stepladder") but cannot fire on RA02 (Manual Handling) because no `manual_handling` signal exists anywhere in `StructuralGateVocabulary::SUPPORTED_SIGNALS` or `HazardIncludeWhenResolver`'s const maps — `config('rams_tier1.missing_risk_implications')` defines only `mounting_above_reach` and `ceiling_void_access`. Per explicit user direction: this is deliberately left as config data so the `30-MEASUREMENT.md` corpus pass can drive an eventual extension with real evidence, rather than guessing at a `manual_handling` phrase vocabulary now. The defects fixture's `_notes` and `StructuralGatesRealDocumentTest`'s class docblock both state this in full.
- **GATE-01/GATE-02/GATE-04 corrected to Pending in REQUIREMENTS.md**, per explicit coordinator instruction. All three had been marked Complete despite shipping behind the disarmed `structural_gates_enabled`/`RAMS_STRUCTURAL_GATES` flag (`env(..., false)`, confirmed absent from the actual `.env`) — reproducing exactly the ambiguity `REQUIREMENTS.md:72`'s 2026-09-12 GATE-11 reconciliation already existed to prevent ("a disarmed gate is not Complete"). Both the itemised checklist and the traceability table were corrected to Pending, following the GATE-11/GATE-13/GATE-14 wording pattern: code-complete, tested, shipped disarmed, the flag name that arms it, and a pointer to `30-MEASUREMENT.md` as the runbook that must run first. This SUMMARY's own `requirements-completed` frontmatter field is left empty for the identical reason, following Plan 30-04's precedent of not flipping a requirement to Complete on a disarmed gate.
- **Only the clean fixture is wired into the snapshot suite** (T-30-20) — the defects fixture is a gate fixture only, consumed exclusively by `StructuralGatesRealDocumentTest` via direct `upgrade()` calls, never rendered or snapshotted. Capturing a golden from deliberately-broken output would permanently encode that breakage as expected.
- **A leak-boundary assertion was added to both snapshot test files themselves**, not left as a one-time manual grep during the checkpoint review — grepping the rendered HTML/DOCX for every Phase 30 gate ID (`GATE-01`/`GATE-02`/`GATE-04`/`GATE-13`/`GATE-14`) plus `compliance_warnings`, making ROADMAP criterion 4's second half a permanent regression guard.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan's illustrative GATE-13 behaviour claim did not match `addPermitAndIsolation()`'s actual overwrite semantics**
- **Found during:** Task 1, before authoring the defects fixture's permit-half scenario
- **Issue:** The plan's `<task>` behaviour block stated the defects fixture would throw a GATE-13 contradiction "naming both the permit requirement and the COSHH substance." Direct trace of `RamsComplianceUpgradeService::addPermitAndIsolation()` (lines ~999-1013) showed it unconditionally OVERWRITES `$data['permit_and_isolation']` with its own fixed, conditionally-worded rule set on every `upgrade()` call — so no fixture input to that key can ever survive to GATE-13's check. Writing a fixture assertion that expected the permit half to fire would have been a test built against code behaviour that cannot occur in production, and would have silently masked the real, already-documented D-02 limitation (`config/rams_tier1.php:216-231`, "the permit half is equally unready").
- **Fix:** Designed the defects fixture's GATE-13 scenario around the COSHH half only (the only half reachable through the real pipeline), and added a dedicated test (`test_defects_fixture_permit_half_cannot_fire_through_the_real_pipeline`) that removes the COSHH evidence and asserts `upgrade()` does NOT throw — proving the permit half's unreachability directly rather than assuming it. Recorded the finding in both the fixture's `_notes` and this file's Decisions Made section.
- **Files modified:** `tests/Fixtures/rams/21cq30960-defects/record.json`, `tests/Feature/Rams/StructuralGatesRealDocumentTest.php`
- **Verification:** `php artisan test --filter=StructuralGatesRealDocumentTest` — 8 passed, 0 failed (both the COSHH-firing test and the permit-unreachability test pass)
- **Committed in:** `1941939` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule-1, corrected a plan-text assumption against verified code behaviour before any fixture was authored against it)
**Impact on plan:** Strengthens the phase's own D-02 finding with an independently re-derived confirmation, rather than silently deviating from the plan's stated behaviour without comment. No production code was touched — this was a test/fixture-design correction only.

## Issues Encountered

- The full `php artisan test` run took ~605s; PowerShell's `$env:` variable expansion is silently stripped when the outer command is issued through the Bash tool's double-quoted string handling — required escaping (`\$env:TEMP`) to redirect output to a file for a long-running background PowerShell invocation. Not a code or test issue; a tooling note for future executors on this machine.

## User Setup Required

None — no external service configuration, no new env var, no migration. All three Phase 30 flags remain unset/false in `.env` (unchanged by this plan).

## Known Stubs

None — this plan touches only test fixtures, test files, and planning documentation; no UI or data-fetching production code was modified.

## Threat Flags

None — no new network endpoint, auth path, or schema surface was introduced. The two leak-boundary tests added to the snapshot suite are a strengthening of an existing mitigation (T-30-02), not a new threat surface.

## Phase 31 Inheritance — read before arming any Phase 30 flag

1. **`RAMS_HOT_WORKS_GATE`'s permit half cannot fire through the real pipeline.** `addPermitAndIsolation()` unconditionally overwrites `permit_and_isolation` with its own conditional wording on every `upgrade()` call, on all six production call sites. Arming this flag only ever exercises the COSHH half (solder/flux in `coshh_baseline`) in production. If Phase 31's RULE-05/GATE-10 COSHH job-conditioning work changes this calculus, re-verify against `HotWorksGateTest`'s own docblock before assuming the permit half has become reachable.
2. **GATE-14's `missing_risk_implications` map has a known `manual_handling` coverage gap.** Only `mounting_above_reach` and `ceiling_void_access` signals exist. A hazard implied by manual-handling language in a method step (e.g. "lift the display", team-lift instructions) will never be flagged as a missing citation. Deliberately left for the `30-MEASUREMENT.md` corpus pass to drive with real evidence rather than guessed at now.
3. **`RamsController::review()` does not call `upgrade()`.** A freshly-opened review of a document last saved before a gate's flag flips true shows no warnings until the next Save Review or regeneration persists the key.
4. **The Blade-side hot-works permit derivation is invisible to GATE-13.** `pdf/rams.blade.php:407-409` and `pdf/rams-v2.blade.php:463-465` derive a "Hot Works Permit" display row via a regex the gate never sees. A document can display a hot-works permit requirement this gate has no knowledge of.
5. **No backfill migration is needed for any Phase 30 gate.** Unlike Phase 29's GATE-11 (which policed persisted content and needed a migration), Phase 30's five gates police relationships recomputed on every `upgrade()` run — a corpus regeneration (`php artisan rams:refresh-compliance`, no `--dry-run`) IS the backfill, and is a hard precondition of arming `RAMS_STRUCTURAL_GATES` specifically (sites 4/5/6 inherit the Plan 30-02 mirrors only by persistence).

`30-MEASUREMENT.md` is the arming runbook Phase 31 inherits in full — read-only per-gate corpus measurement, then a corpus regeneration, then a live verify, then the `.env` flip, one flag at a time.

## Next Phase Readiness

- Phase 30 ships complete: all five gates (GATE-01/02/04/13/14) code-complete, tested (873 RAMS tests, 12 snapshot tests, 2670 full-suite tests apart from the one pre-existing unrelated failure), and correctly disarmed per D-03. `30-VALIDATION.md` is fully reconciled against actual results, not just the plan contract.
- REQUIREMENTS.md now accurately states Pending (not Complete) for all five gates with their disarming flag and the `30-MEASUREMENT.md` pointer — Phase 31's arming work has an honest starting state to read from.
- No blockers for Phase 31. The two inheritance items above (permit-half unreachability, `manual_handling` gap) are the load-bearing facts Phase 31 needs before flipping any flag.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-14*

## Self-Check: PASSED

All 10 files referenced by this plan verified present on disk (both fixtures, the real-document
test, the 4 captured goldens, the reconciled `30-VALIDATION.md`, the corrected `REQUIREMENTS.md`,
and this SUMMARY itself). All 4 commit hashes (`1941939`, `0af82ea`, `964ccba`, `b66b7e5`) confirmed
present in `git log --oneline --all`.
