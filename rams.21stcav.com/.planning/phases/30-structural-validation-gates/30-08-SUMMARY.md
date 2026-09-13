---
phase: 30-structural-validation-gates
plan: 08
subsystem: rams
tags: [laravel, php, validation-gates, rams-compliance]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 01
    provides: missing_risk_ref_gate_enabled flag (RAMS_MISSING_RISK_REF_GATE, defaults false), missing_risk_implications config vocabulary, StructuralGateVocabulary shared matcher, compliance_warnings channel
  - phase: 30-structural-validation-gates
    plan: 07
    provides: StructuralGatesDisarmedTest with the GATE-13 half of the D-04 matrix, and the scope note handing off the GATE-14 half to this plan
provides:
  - RamsComplianceUpgradeService::enforceMissingRiskRefGate() (GATE-14) — WARN-TIER, never throws — plus its own RAMS_MISSING_RISK_REF_GATE dispatch block in upgrade()
  - findHazardIndexForSignal() — the gate's private hazard-side resolver, built on StructuralGateVocabulary::phrasesForSignal(), never $keywordRiskMap
  - MissingRiskRefGateTest.php — the phase's first "assert on compliance_warnings contents" test file (no throw/no-throw analog exists for this gate)
  - MissingRiskRefGateSourceGuardTest.php — method-scoped (not just file-scoped) static proof that GATE-14 never re-derives $keywordRiskMap
  - StructuralGatesDisarmedTest extended with the GATE-14 half of the D-04 flag-independence matrix — the matrix is now COMPLETE (all three Phase 30 flags proven independent of each other)
affects: [30-09, 31-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A gate whose violation is a defect in the app's OWN prior derivation (not something an engineer can fix from any UI field) ships WARN tier with no throw statement at all, rather than error tier with an unactionable message — a new variant of the existing throw-on-first-violation gate shape, alongside GATE-04's two-tier (warn+error) shape"
    - "Independence-from-a-shared-derivation is asserted at METHOD scope via reflection (ReflectionMethod::getStartLine()/getEndLine() source-slice extraction), not just file scope, when the sanctioned definition and the independent re-check live in the SAME file — a file-scoped grep allow-list cannot distinguish the two"
    - "A D-04 flag-independence matrix leg for a WARN-tier gate is proven differently from a throw-tier gate's leg: 'killable without disarming the others' means 'produces a warning and throws nothing, with the other gates' warnings/throws absent' rather than 'throws only its own message'"

key-files:
  created:
    - tests/Unit/Services/Rams/MissingRiskRefGateTest.php
    - tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - tests/Feature/Rams/StructuralGatesDisarmedTest.php

key-decisions:
  - "The canonical Step-4 defect's two implied hazards were represented in tests as 'Working at height (high level mount)' and 'Confined space — ceiling void access' rather than literally 'Working at Height'/'Manual Handling' — the shipped config/rams_tier1.php missing_risk_implications map (Plan 30-01) only defines two signals (mounting_above_reach, ceiling_void_access), neither of which is a manual_handling signal (no such key exists in StructuralGateVocabulary::SUPPORTED_SIGNALS or HazardIncludeWhenResolver's const maps). RESEARCH.md Finding 7's own worked example ('lift the display' => manual_handling) was illustrative, not literally shipped by Plan 30-01. Building GATE-14 against a manual_handling signal that does not exist would require inventing a new signal outside this plan's file list (a D-07 violation) or extending config/rams_tier1.php beyond what Plan 30-01 shipped (out of this plan's stated scope). The test fixtures instead use the two signals the shipped config actually provides, with hazard names chosen to match StructuralGateVocabulary::phrasesForSignal()'s literal phrase vocabulary for each — the RA01/RA02 row-position numbering in the plan's canonical example is preserved exactly (two implied-but-uncited hazards at register indices 0 and 1)."
  - "GATE-14's hazard-side match requires the hazard row's OWN TEXT to contain one of StructuralGateVocabulary::phrasesForSignal(signal)'s literal phrases (e.g. 'high level mount', 'ceiling void') — the same substring-matching mechanism enforceOrphanControlGate() (GATE-01) already uses via signalMatchesHazards(). This was verified against the shipped HazardIncludeWhenResolver::TIER2_KEYWORD_SIGNALS/TIER2_ACTIVITY_SIGNALS maps before writing any fixture — a hazard named literally 'Working at height' (the template library's own display name) does NOT contain any of mounting_above_reach's phrases and would never match, a real corpus-fidelity gap recorded here for Phase 31/measurement to consider, not fixed by this plan (D-07 forbids inventing a new signal; RESEARCH.md's own recommendation was to add config rows, not change the hazard-matching mechanism)."
  - "Duplicate-warning suppression: a phase whose text matches TWO DIFFERENT missing_risk_implications rows that both resolve to the SAME hazard (e.g. 'wall mount' and 'above 2m', both signal mounting_above_reach) produces exactly ONE warning for that hazard, via a per-phase $warnedHazardIndexes de-dupe set — proven by a dedicated test, since the plan's own worked example (multiple wall-mount-adjacent phrases sharing one signal) makes this collision the common case, not an edge case."
  - "The D-04 matrix's GATE-14 leg required extending allFiveViolatingDocument() with a NEW hazard row and a NEW method-statement phase (rather than modifying existing rows/phases), specifically chosen so crossReferenceMethodStatementRisks()'s own $keywordRiskMap — which runs unconditionally on this fixture regardless of any Phase 30 flag — does not auto-cite the new hazard before GATE-14 ever runs. Verified by direct trace of $keywordRiskMap's keyword groups against the new phase/hazard text before writing the fixture, documented inline in the fixture's own comment."

patterns-established:
  - "When a plan's illustrative RESEARCH example ('manual_handling' signal) does not correspond to a signal the shipped foundation config actually defines, the gate is built against what IS shipped, with the discrepancy recorded as a key-decision and a corpus-fidelity note for the next measurement pass — not silently substituted without comment, and not blocked on redefining the foundation (out of this plan's file list)."

requirements-completed: [GATE-14]

duration: ~55min
completed: 2026-09-13
---

# Phase 30 Plan 08: GATE-14 Missing-Risk-Reference Gate Summary

**Built GATE-14 — a WARN-TIER (never throws) independent re-check that a method step cites the hazards its own text implies, using the config-resident `missing_risk_implications` map (never `crossReferenceMethodStatementRisks()`'s `$keywordRiskMap`, the intersection-based code responsible for the defect it exists to catch) — ships disarmed behind its own `RAMS_MISSING_RISK_REF_GATE` flag (default false), completing the D-04 flag-independence matrix across all three Phase 30 flags.**

## Performance

- **Duration:** ~55 min
- **Completed:** 2026-09-13
- **Tasks:** 2
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments

- `RamsComplianceUpgradeService::enforceMissingRiskRefGate()` (GATE-14): for each method-statement phase, scans the combined title+steps text against `config('rams_tier1.missing_risk_implications', [])`. For each matching phrase, resolves the row's `signal` to a hazard row via the new private `findHazardIndexForSignal()` helper (built on `StructuralGateVocabulary::phrasesForSignal()` — never `$keywordRiskMap`). Warns via `compliance_warnings` (never throws) only when the implied hazard EXISTS in the register AND is not already in `$phase['associated_risks']`; de-duplicates so one phase matching multiple phrases for the same hazard produces exactly one warning.
- `findHazardIndexForSignal()` — the gate's hazard-side resolver, mirroring `hazardRowText()`'s substring-matching mechanism but reading `StructuralGateVocabulary`'s phrase vocabulary, never a second parallel one (D-07).
- GATE-14's own dispatch block added to `upgrade()`, gated on `rams_tier1.missing_risk_ref_gate_enabled` (already shipped `false` by Plan 30-01), placed after the structural trio's dispatch and after `crossReferenceMethodStatementRisks()` (whose `associated_risks` output this gate reads), before GATE-13's dispatch and `cleanTextArtifacts()`.
- `MissingRiskRefGateTest.php` (new, 8 tests): the canonical two-implied-hazard warning case (RA01/RA02 by row position), the duplicate-warning-suppression case, the implied-hazard-absent no-fire case, the already-cited no-fire case, a never-throws battery across 8 malformed/empty inputs, the clean-document-leaves-warnings-unchanged case, and two dispatch-flag tests through the real `upgrade()` entry point.
- `MissingRiskRefGateSourceGuardTest.php` (new, 5 tests): file-scoped proof `keywordRiskMap` appears only in its one sanctioned definition file, plus the load-bearing METHOD-scoped proof (via `ReflectionMethod::getStartLine()`/`getEndLine()` source-slice extraction) that `enforceMissingRiskRefGate()`'s own source references neither `keywordRiskMap` nor `crossReferenceMethodStatementRisks()` — necessary because both methods live in the same file, so a file-level allow-list alone cannot distinguish them.
- `StructuralGatesDisarmedTest.php` extended with the GATE-14 half of the D-04 flag-independence matrix, completing it: `allFiveViolatingDocument()` gained a genuine GATE-14 citation gap (verified not to be auto-cited by `crossReferenceMethodStatementRisks()`'s own unconditional pre-pass); arming `RAMS_MISSING_RISK_REF_GATE` alone now warns GATE-14 and throws nothing, with the structural trio and GATE-13 confirmed dormant; arming the structural trio alone throws a structural message and writes no GATE-14 warning. Plan 30-06's originally-vacuous "does not throw a GATE-14 message" bullet is now load-bearing, confirmed by re-running it against the extended fixture.
- Full Rams test suite: 865 passed, 0 failed (was 850 at the end of Plan 30-07; 850 + 8 + 5 + 2 new tests = 865, confirmed exact match).

## Task Commits

Each task was committed atomically:

1. **Task 1: GATE-14 — enforceMissingRiskRefGate(), warn tier** — `107998b` (feat)
2. **Task 1 continued: D-04 matrix completion (StructuralGatesDisarmedTest)** — `ccefef8` (test)
3. **Task 2: MissingRiskRefGateSourceGuardTest — GATE-14 independence proof** — `2353d79` (test)

_Note: both tasks were `tdd="true"`; per this plan's autonomous execution mode, each test file was written and run to confirm the intended behaviour before being committed alongside the (already-existing or newly-written) implementation, matching Plans 30-01/30-06/30-07's established convention. The D-04 matrix extension (part of Task 1's action block) was committed as a separate atomic commit from the gate body itself, since it touches a different file with its own independent verification._

## Files Created/Modified

- `app/Services/Rams/RamsComplianceUpgradeService.php` — added `enforceMissingRiskRefGate()`, `findHazardIndexForSignal()`, the `missing_risk_ref_gate_enabled` dispatch block in `upgrade()`, and updated the Plan 30-08 placement note in the Section 13 banner
- `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` — new, 8 tests covering the gate's warn/no-fire boundaries and dispatch-flag wiring
- `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` — new, 5 tests proving GATE-14's independence from `$keywordRiskMap` at method scope
- `tests/Feature/Rams/StructuralGatesDisarmedTest.php` — extended with the GATE-14 half of the D-04 flag-independence matrix (2 new tests, plus an updated fixture and scope-note docblock)

## Decisions Made

- Test fixtures use the two signals (`mounting_above_reach`, `ceiling_void_access`) the shipped `missing_risk_implications` config actually defines, rather than RESEARCH.md's illustrative `manual_handling` example, which corresponds to no signal Plan 30-01 shipped — documented as a key-decision, not silently substituted
- Hazard-side matching requires the hazard row's own text to literally contain one of `StructuralGateVocabulary::phrasesForSignal()`'s phrases — verified this does NOT match a hazard named plainly "Working at height" (the template library's own display name), a corpus-fidelity gap recorded for Phase 31/measurement, not fixed here
- Per-phase de-duplication of warnings when multiple implication phrases resolve to the same hazard
- The D-04 matrix fixture addition was verified in advance (by tracing `$keywordRiskMap`'s keyword groups) to never be auto-cited by the app's own unconditional `crossReferenceMethodStatementRisks()` pass, so the GATE-14 leg tests a genuine citation gap, not one the app already closed
- Flag ships at its already-shipped default `false` (Plan 30-01/D-03) — nothing in this plan arms `RAMS_MISSING_RISK_REF_GATE`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Own test's "never throws" battery asserted a key that the method's own early-return convention does not guarantee**
- **Found during:** Task 1 GREEN verification
- **Issue:** The `test_never_throws_on_a_battery_of_malformed_and_empty_inputs` test originally asserted `compliance_warnings` was present in the result for every input, including genuinely empty/malformed ones. `enforceMissingRiskRefGate()`'s early return (`if (empty($hazards) || empty($phases) || empty($implications)) { return $data; }`) returns `$data` UNCHANGED on that path — matching the established convention `enforceResidualScoreGate()` already uses (`if (empty($hazards)) { return $data; }`, also without touching `compliance_warnings`). The test's own assumption, not the implementation, was wrong.
- **Fix:** Removed the incorrect assertion; kept the `assertIsArray()`/never-throws assertion, with a comment explaining the established early-return convention this matches.
- **Files modified:** `tests/Unit/Services/Rams/MissingRiskRefGateTest.php`
- **Verification:** `php artisan test --filter=MissingRiskRefGateTest` — 8 passed, 0 failed
- **Committed in:** `107998b` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule-1 bug, confined to this plan's own new test file — no production-code or scope change)
**Impact on plan:** None on the shipped gate body. Corrected this plan's own test assertion against the established, already-shipped convention before committing.

## Issues Encountered

None beyond the self-caught test correction documented above. The `manual_handling`-signal / RESEARCH-example discrepancy (see key-decisions) was investigated and resolved as a design decision before any test was written, not discovered as a failure.

## User Setup Required

None — no external service configuration, no new env var, no migration. `RAMS_MISSING_RISK_REF_GATE` remains unset/false in `.env` (shipped disarmed by Plan 30-01; this plan builds the gate body behind it, arms nothing).

## Verification Results

- `php artisan test --filter=MissingRiskRefGateTest` — 8 passed, 0 failed (PowerShell/Herd)
- `php artisan test --filter=MissingRiskRefGateSourceGuardTest` — 5 passed, 0 failed
- `php artisan test --filter=StructuralGatesDisarmedTest` — 16 passed, 0 failed (matrix complete)
- `php artisan test --filter=MethodStatementAssociatedRisksTest` — 5 passed, 0 failed (existing derivation untouched)
- `php artisan test --filter=Rams` (full suite, before/after) — before this plan: 850 passed (Plan 30-07's recorded baseline); after this plan: **865 passed, 0 failed** — net +15 tests (8 + 5 + 2), zero regressions
- `config('rams_tier1.missing_risk_ref_gate_enabled')` confirmed defaulting `false`; no `.env` entry added; `.env.example:69` already carried `RAMS_MISSING_RISK_REF_GATE=false` from Plan 30-01

## Next Phase Readiness

- Plan 30-09's snapshot fixtures and phase gate can reference all five Phase 30 gates (GATE-01/02/04/13/14) as shipped, tested, and disarmed
- The D-04 flag-independence matrix is now COMPLETE across all three Phase 30 flags — no remaining scope-note gaps for a future plan to close
- A corpus-fidelity gap is recorded for Phase 31/a future measurement pass: `missing_risk_implications`'s two signals (`mounting_above_reach`, `ceiling_void_access`) only match hazard names that literally contain one of `StructuralGateVocabulary`'s phrase-vocabulary substrings (e.g. "high level mount", "ceiling void") — a hazard named plainly "Working at height" (the template library's own display name) does not match either signal today, so GATE-14's real-corpus hit rate against `HazardTemplateSeeder`-derived hazard names should be measured before arming
- No blockers. Full Rams suite: 865 passed, 0 failed.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-13*

## Self-Check: PASSED

Verified on disk: `app/Services/Rams/RamsComplianceUpgradeService.php` contains `enforceMissingRiskRefGate`/`findHazardIndexForSignal` (confirmed via grep); `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` exists with 8 tests, all passing; `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` exists with 5 tests, all passing; `tests/Feature/Rams/StructuralGatesDisarmedTest.php` extended to 16 tests, all passing. All three commit hashes (`107998b`, `ccefef8`, `2353d79`) confirmed present in `git log --oneline -6`. Full Rams suite run: 865 passed, 0 failed. `config('rams_tier1.missing_risk_ref_gate_enabled')` confirmed defaulting `false`; no `.env` change.
