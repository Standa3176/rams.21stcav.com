---
phase: 30-structural-validation-gates
verified: 2026-09-14T00:00:00Z
status: passed
score: 6/6 roadmap success criteria verified
overrides_applied: 0
re_verification: null
---

# Phase 30: Structural Validation Gates Verification Report

**Phase Goal:** Ship the five gates that check document-structural consistency — orphan controls, area/method-step coverage, residual-vs-initial scoring, hot-works contradiction, and missing risk references — against the finished Phase 26 hazard shape. GATE-13 ships built-whole but disarmed in this phase and is flipped on in Phase 31 (D-02) — it is not descoped.
**Verified:** 2026-09-14 (commands re-run directly by verifier, not taken from SUMMARY claims)
**Status:** passed
**Re-verification:** No — initial verification

## Method

This report is based on direct inspection of `app/Services/Rams/RamsComplianceUpgradeService.php`, `config/rams_tier1.php`, the actual `.env` file, `app/Http/Controllers/RamsController.php`, `app/Services/RamsBuilderService.php`, the test files themselves, and two live test-suite runs executed by the verifier via `powershell.exe -NoProfile -Command "php artisan test ..."` and `vendor\bin\phpunit --group snapshot` (PowerShell was reachable directly from the Bash tool on this machine and correctly resolved `php`; no dedicated PowerShell tool was needed). SUMMARY.md claims were used only to know where to look, never as evidence in themselves.

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | GATE-01 errors when a trigger phrase (e.g. "asbestos register") lacks EITHER a matching hazard row OR a matching client-responsibility entry | ✓ VERIFIED | `enforceOrphanControlGate()` at `RamsComplianceUpgradeService.php:2052` requires both `StructuralGateVocabulary::signalMatchesHazards()` and `::signalMatchesClientReqs()`, throwing when either is false (D-05 EITHER semantics, not AND). Dispatched at `:130-134` behind `structural_gates_enabled`. Proven against the canonical asbestos fixture in `tests/Unit/Services/Rams/StructuralGatesTest.php` and against the real 21CQ30960 fixture in `tests/Feature/Rams/StructuralGatesRealDocumentTest.php` (both pass on re-run — see suite results below). |
| 2 | GATE-02 errors when any area/room has zero method steps | ✓ VERIFIED | `enforceAreaCoverageGate()` at `:2196`, dispatched at `:132`. Reads areas via `StructuralGateVocabulary::flattenAreas()`, which resolves the gate-private `areas_for_gate` mirror (see Truth 4 below) — passes vacuously on zero areas by design (confirmed deliberate in code comment, not a hidden bug). |
| 3 | GATE-04 flags residual-severity-below-initial (warn) and errors on residual-score-exceeds-initial | ✓ VERIFIED | `enforceResidualScoreGate()` at `:2310`, dispatched at `:133`, confirmed to be the phase's only two-tier gate: collects all `post_severity < pre_severity` rows into `compliance_warnings`, then throws on the first `post_likelihood*post_severity > pre_likelihood*pre_severity` row. Non-vacuity proven against the real, committed Tilda golden fixture (all 3 hazard rows trip the warn branch per direct inspection recorded in 30-06-SUMMARY.md, independently re-derivable from `tests/Fixtures/rams/tilda-21cq29531/record.json`). |
| 4 | Running all five gates against a regenerated 21CQ30960 passes clean; proven by authored fixtures (defect-bearing + clean), not live-only UAT | ✓ VERIFIED (as amended) | The ROADMAP criterion 4 text was itself corrected by Plan 30-05 to explicitly accept fixture-based proof instead of a live regeneration ("proven by authored fixtures … not by a live-only UAT step"). Both fixtures exist on disk: `tests/Fixtures/rams/21cq30960/record.json` (clean) and `tests/Fixtures/rams/21cq30960-defects/record.json` (defect-bearing). They are wired into `tests/Feature/Rams/StructuralGatesRealDocumentTest.php` (8 tests, re-run green — see below) and the clean fixture is additionally wired as **hardcoded literal test methods** (not a data provider) in both `PdfSnapshotTest.php:259/267/282` and `DocxSnapshotTest.php:164/173/188`. See "Criterion 4 scrutiny" below for the honest caveat this does NOT cover. |
| 5 | GATE-13 errors on the hot-works contradiction, proven against a 21CQ30960-shaped fixture, and does NOT fire on `addPermitAndIsolation()`'s own conditional line; ships disarmed, flips in Phase 31 | ✓ VERIFIED | `enforceHotWorksGate()` at `:2449`, dispatched at `:166` behind `hot_works_gate_enabled` (default `false`, confirmed absent from `.env`). Verified `addPermitAndIsolation()` (`:999-1013`) unconditionally overwrites `permit_and_isolation` with fixed, conditionally-worded rules (rule 5: "Hot works permit required **if** soldering..."), so the permit half genuinely cannot fire through the real pipeline — this is an honestly-documented known limitation (see below), not silently hidden. `tests/Unit/Services/Rams/HotWorksGateTest.php` (8 tests) includes the direct-invocation regression proving `addPermitAndIsolation()`'s own output never trips the gate. |
| 6 | GATE-14 flags (warn, never blocks) a method step failing to cite an implied hazard, proven against the Step-4 defect fixture | ✓ VERIFIED | `enforceMissingRiskRefGate()` at `:2650`. Read the full method body directly — it only ever appends to a local `$warnings` array and returns `$data`; there is no `throw` statement anywhere in the method. Dispatched at `:150` behind `missing_risk_ref_gate_enabled` (default `false`, absent from `.env`). `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` (8 tests) and `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` (5 tests, proving at METHOD scope via reflection source-slicing that the gate never reads `$keywordRiskMap`) both re-run green. |

**Score:** 6/6 truths verified (criterion 4 verified against its own amended, fixture-based wording — see caveat below)

### Criterion 4 — honest scrutiny

ROADMAP criterion 4 as originally conceived (implicit in the phase goal) wanted a **live regeneration** of the real 21CQ30960 project to pass all five gates clean. That live pass has **not** been run — `30-MEASUREMENT.md` is written as a procedure to run post-deploy, not as a completed measurement, and no evidence in the codebase or git history shows it has been executed against production. Plan 30-05 recognised this gap mid-phase and **rewrote criterion 4's own text** to require fixture-based proof instead ("proven by authored fixtures … not by a live-only UAT step"), which is a legitimate scope decision recorded with rationale, not a silent lowering of the bar. Judged against the roadmap text as it now reads, criterion 4 is satisfied. Judged against a stricter "real production data" reading, it is **partially met, pending a live pass** — this is not a new finding; it is called out explicitly in the phase's own "Phase 31 Inheritance" section of `30-09-SUMMARY.md` and in `30-MEASUREMENT.md` itself. Reporting this as a caveat, not a gap, because the roadmap document — the actual contract — was deliberately and transparently amended to accept the fixture-based standard.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Rams/RamsComplianceUpgradeService.php::enforceOrphanControlGate()` | GATE-01 body | ✓ VERIFIED | `:2052`, dispatched `:131` |
| `...::enforceAreaCoverageGate()` | GATE-02 body | ✓ VERIFIED | `:2196`, dispatched `:132` |
| `...::enforceResidualScoreGate()` | GATE-04 body | ✓ VERIFIED | `:2310`, dispatched `:133` |
| `...::enforceHotWorksGate()` | GATE-13 body | ✓ VERIFIED | `:2449`, dispatched `:166` |
| `...::enforceMissingRiskRefGate()` | GATE-14 body | ✓ VERIFIED | `:2650`, dispatched `:150`, confirmed never throws |
| `app/Services/Rams/StructuralGateVocabulary.php` | shared signal-matching helper | ✓ VERIFIED | present, used by all five gates (D-07/D-08) |
| `config/rams_tier1.php` (3 flags) | `structural_gates_enabled`, `missing_risk_ref_gate_enabled`, `hot_works_gate_enabled` | ✓ VERIFIED | lines 167/195/234, all `env(..., false)` |
| `.env` | must contain none of the 3 flags | ✓ VERIFIED | `grep` of the three flag names against the real `.env` returned zero matches |
| `RamsController.php` / `RamsBuilderService.php` mirrors | `areas_for_gate` + `client_responsibilities_expanded` at all 3 real entry points | ✓ VERIFIED | grep confirms both keys written at `RamsController.php` (Save Review path), `runFromReview()`, and `runPipeline()` |
| `tests/Feature/Rams/StructuralGatesDualPathTest.php` | non-vacuity proof for the mirrors | ✓ VERIFIED | exists, docblock documents a genuine delete-one-line/fail/restore procedure per entry point, not merely asserted |
| `tests/Fixtures/rams/21cq30960/record.json` (clean) | fixture #1 | ✓ VERIFIED | present, wired into snapshot tests as hardcoded literal test methods |
| `tests/Fixtures/rams/21cq30960-defects/record.json` (defect) | fixture #2 | ✓ VERIFIED | present, consumed by `StructuralGatesRealDocumentTest.php` only (never rendered — correct, per SUMMARY's own reasoning that a golden must never encode broken output) |
| `resources/views/rams/review.blade.php` warning panel | Surface 1/2 per 30-UI-SPEC | ✓ VERIFIED | `.alert.alert-warning` panel + `.gate-flagged` row marker present; leak-boundary grep against all four render targets (both PDF blades, both DOCX builders) returns zero occurrences of `compliance_warnings` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `upgrade()` dispatch block | `enforceOrphanControlGate`/`enforceAreaCoverageGate`/`enforceResidualScoreGate` | `config('rams_tier1.structural_gates_enabled')` | ✓ WIRED | confirmed at `:130-134` |
| `upgrade()` dispatch block | `enforceMissingRiskRefGate` | `config('rams_tier1.missing_risk_ref_gate_enabled')` | ✓ WIRED | confirmed at `:150-152` |
| `upgrade()` dispatch block | `enforceHotWorksGate` | `config('rams_tier1.hot_works_gate_enabled')` | ✓ WIRED | confirmed at `:166-168` |
| `RamsController::updateAndDownload()` | `StructuralGateVocabulary::flattenAreas/flattenClientResponsibilities` | `areas_for_gate`/`client_responsibilities_expanded` mirror written immediately before `upgrade()` | ✓ WIRED | grep-confirmed; this is the vacuity-prevention link the phase exists to guarantee |
| `RamsBuilderService::runFromReview()` | same mirrors | same pattern | ✓ WIRED | grep-confirmed |
| `RamsBuilderService::runPipeline()` | same mirrors | same pattern, sourced from `$record->reviewed_data` (no `$formData` equivalent exists on this path) | ✓ WIRED | grep-confirmed |
| `generated_data['compliance_warnings']` | `resources/views/rams/review.blade.php` | Blade read of the array | ✓ WIRED | panel + row marker present |
| `generated_data['compliance_warnings']` | `pdf/rams.blade.php`, `pdf/rams-v2.blade.php`, both DOCX builders | — (must be absent) | ✓ NOT WIRED (correctly) | zero grep matches in all four files; also asserted by automated tests in `ComplianceWarningsRenderTest.php` and both snapshot test files |

### Behavioral Spot-Checks / Suite Runs (executed by verifier, not trusted from SUMMARY)

| Command | Result | Status |
|---------|--------|--------|
| `powershell.exe -NoProfile -Command "php artisan test --filter=Rams"` | **873 passed, 2 deprecated, 0 failed**, 3221 assertions, 118.54s | ✓ PASS — matches claimed figure exactly |
| `powershell.exe -NoProfile -Command "vendor\bin\phpunit --group snapshot"` | **12 passed, 71 assertions**, "OK, but there were issues" (117 PHPUnit deprecation notices only, zero test failures) | ✓ PASS — matches claimed figure exactly |
| `.env` grep for `RAMS_STRUCTURAL_GATES`/`RAMS_MISSING_RISK_REF_GATE`/`RAMS_HOT_WORKS_GATE` | zero matches | ✓ PASS — nothing armed |
| `grep -rn compliance_warnings` across both PDF blades + both DOCX builders | zero matches | ✓ PASS — leak boundary holds |

The full whole-repo suite (`php artisan test`, expected 2670/2671) was not independently re-run in full during this verification pass — the RAMS-filtered suite (873/873, the superset relevant to this phase) and the snapshot group (12/12) were run directly and both match the SUMMARY's claimed numbers exactly, which is considered sufficient direct evidence for this phase's scope. If independent confirmation of the one pre-existing unrelated `QueueRecoverCommandTest` failure is required, that would need a full-suite re-run (~10 minutes).

### Requirements Coverage

| Requirement | Source Plan | Status | Evidence |
|-------------|------------|--------|----------|
| GATE-01 | 30-03 | Pending (correctly, per D-03) | Code-complete, tested, dispatched behind disarmed flag; REQUIREMENTS.md `:154` correctly reads Pending (verified current file state, not a stale claim) |
| GATE-02 | 30-03 | Pending (correctly) | REQUIREMENTS.md `:155` |
| GATE-04 | 30-06 | Pending (correctly) | REQUIREMENTS.md `:157` |
| GATE-13 | 30-07 | Pending (correctly, built-whole-disarmed per D-02) | REQUIREMENTS.md `:166` |
| GATE-14 | 30-08 | Pending (correctly, warn-tier-disarmed per D-03) | REQUIREMENTS.md `:167` |

No orphaned requirements found for this phase — GATE-01/02/04/13/14 are the full requirement set named in ROADMAP for Phase 30, and all five appear in plan frontmatter.

### Anti-Patterns Found

None found in the Phase 30 surface. No `TBD`/`FIXME`/`XXX` markers, no stub returns, no hardcoded empty-array render paths were found in the gate methods, the vocabulary helper, or the review-screen render surface. The three "known limitations" below were checked against the adversarial-stance requirement (not simply accepted from SUMMARY prose) and are genuinely, verifiably true in the code, not narrative cover for missing work.

### Known Limitations (verified honest, not new findings)

1. **GATE-13's permit half is architecturally unreachable in production** — confirmed directly: `addPermitAndIsolation()` (`:999-1013`) unconditionally overwrites `permit_and_isolation` with a fixed rule set whose hot-works line is conditionally worded ("...required **if** soldering..."), so no fixture or reviewed input can ever survive to trigger GATE-13's permit check. Only the COSHH half (solder/flux literal match) can fire. This is stated plainly in `HotWorksGateTest`'s docblock, `30-CONTEXT.md` D-02, and `30-09-SUMMARY.md`'s "Phase 31 Inheritance" section — consistently and without spin.
2. **GATE-14 has no `manual_handling` signal** — confirmed directly: `config('rams_tier1.missing_risk_implications')` (checked in `config/rams_tier1.php`) defines only `mounting_above_reach` and `ceiling_void_access`; no `manual_handling` key exists anywhere in `StructuralGateVocabulary::SUPPORTED_SIGNALS` or `HazardIncludeWhenResolver`'s const maps. The defects fixture and `StructuralGatesRealDocumentTest` both assert exactly 1 GATE-14 warning (RA01 only), not 2, with an explicit code comment flagging the gap. Honestly documented, not silently narrowed.
3. **All five gates ship disarmed** — confirmed: all three flags default `false` in `config/rams_tier1.php`, none present in `.env`. REQUIREMENTS.md correctly records Pending (not Complete) for all five, matching the GATE-11 precedent at `:72`. This was actively corrected by Plan 30-09 (GATE-01/02/04 had briefly drifted to "Complete" and were reverted to Pending) — the correction itself is now verified to be the current, accurate state of the file.

### Human Verification Required

None identified. This phase ships entirely disarmed, backend-only logic plus a review-screen advisory panel already covered by automated render/leak tests. No visual judgment call, external service, or real-time behavior is load-bearing to the phase goal as scoped (a live production regeneration is explicitly deferred to Phase 31's arming runbook, not part of this phase's success criteria as amended).

### Gaps Summary

No blocking gaps found. All five gates exist, are correctly flag-gated, default disarmed, are absent from `.env`, are individually independent per the D-04 matrix (verified present in `StructuralGatesDisarmedTest.php`, 16 tests spanning all three flags in both directions), and are proven non-vacuous by genuine data-reachability mirrors at all three real generation entry points. GATE-14 was directly read and confirmed to contain no throw statement. The leak boundary between `compliance_warnings` and any rendered PDF/DOCX output was confirmed both by direct grep (zero occurrences) and by dedicated automated tests. Both required 21CQ30960 fixtures exist and are wired as hardcoded test methods, not a data-provider abstraction that could silently no-op. Both suite commands specified in the verification brief were re-run directly by this verifier and matched the claimed pass counts exactly (873/873 RAMS filter, 12/12 snapshot group). The only caveat — the live corpus measurement against production 21CQ30960 remaining unrun — is explicitly and honestly scoped out of this phase's amended criterion 4 and handed to Phase 31 as a precondition, not hidden or misrepresented.

---

_Verified: 2026-09-14_
_Verifier: Claude (gsd-verifier)_
