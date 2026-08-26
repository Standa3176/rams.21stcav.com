---
phase: 27
slug: manual-handling-display-lift-house-rules
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-25
updated: 2026-08-26
---

# Phase 27 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.3 (`composer.json`), Laravel test base classes |
| **Config file** | `phpunit.xml` (testsuites: `Unit` -> `tests/Unit`, `Feature` -> `tests/Feature`) |
| **Quick run command** | `php artisan test --filter=DisplayLift` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~90-120 seconds (baseline 2265+ tests per 26-VERIFICATION.md) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=<TouchedClass>Test` (e.g. `--filter=DisplayLiftPolicyTest`, `--filter=RamsComplianceUpgradeServiceDisplayLiftTest`, `--filter=DisplayLiftGateTest`, `--filter=SafetyProfileServiceTest`, `--filter=MethodStatementServiceTest`)
- **After every plan wave:** `php artisan test` (full suite)
- **Before `/gsd:verify-work`:** Full suite must be green, PLUS the live 21CQ30960 regeneration proof (ROADMAP success criterion 4) — this cannot be automated-test-only, per the milestone's standing live-validation posture (Phase 26 precedent, carried into `27-CONTEXT.md` `<specifics>`)
- **Max feedback latency:** ~120 seconds (full suite)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 27-01-01 | 01 | 1 | RULE-02 | T-27-01 | Deterministic band resolution, no AI, no regex re-parse inside policy | unit | `php artisan test --filter=DisplayLiftPolicyTest` | ❌ W0 | ⬜ pending |
| 27-01-02 | 01 | 1 | RULE-02, RULE-03 | T-27-01 | Bands/statement introspectable, provenance documented | unit | `php artisan test --filter=DisplayLiftPolicyTest` | ❌ W0 | ⬜ pending |
| 27-02-01 | 02 | 2 | RULE-02, RULE-12 | T-27-02 | Old ladder removed, branch order fixed, structured min_persons/inches stored | unit | `php artisan test --filter=RamsComplianceUpgradeServiceDisplayLiftTest` | ❌ W0 | ⬜ pending |
| 27-02-02 | 02 | 2 | RULE-03 | T-27-02 | Decommission-scope scan produces explicit wall-mount statement; seeder sourced from DisplayLiftPolicy | unit | `php artisan test --filter=RamsComplianceUpgradeServiceDisplayLiftTest` | ❌ W0 | ⬜ pending |
| 27-02-03 | 02 | 2 | RULE-02, RULE-03, RULE-12 | T-27-02 | Non-vacuity: assertions fail against pre-fix behaviour, pass after | unit | `php artisan test --filter=RamsComplianceUpgradeServiceDisplayLiftTest` | ❌ W0 | ⬜ pending |
| 27-03-01 | 03 | 3 | GATE-09 | T-27-03, T-27-04 | Gate throws `RamsGenerationException`, gated behind `RAMS_DISPLAY_LIFT_GATE` | unit | `php artisan test --filter=DisplayLiftGateTest` | ❌ W0 | ⬜ pending |
| 27-03-02 | 03 | 3 | GATE-09 | T-27-03 | Throw/no-throw boundary proof, revert-and-restore non-vacuity | unit | `php artisan test --filter=DisplayLiftGateTest` | ❌ W0 | ⬜ pending |
| 27-03-03 | 03 | 3 | GATE-09 | T-27-03 | Both `runFromReview()` and `runPipeline()` fire identically; structural guard on `DisplayLiftPolicy::` callers | feature | `php artisan test --filter=DisplayLiftDualPathTest` and `--filter=DisplayLiftPolicySourceGuardTest` | ❌ W0 | ⬜ pending |
| 27-04-01 | 04 | 2 | RULE-02 | T-27-05 | Worksheet parity: general inch regex, `DisplayLiftPolicy`-sourced wording, no divergent numbers | unit | `php artisan test --filter=SafetyProfileServiceTest` | ⚠️ extend existing | ⬜ pending |
| 27-04-02 | 04 | 2 | RULE-02 | T-27-05 | Method-statement fallback string reads `DisplayLiftPolicy`, no hardcoded "two-person" claim | unit | `php artisan test --filter=MethodStatementServiceTest` | ⚠️ extend existing | ⬜ pending |
| 27-05-01 | 05 | 4 | GATE-09 | T-27-03 | Live deploy as `stcav` + reseed | manual (checkpoint) | n/a — live deployment | n/a | ⬜ pending |
| 27-05-02 | 05 | 4 | GATE-09 | T-27-03 | 21CQ30960 regenerated on live; gate does not block a real strip-out job; §6.7 table and Manual Handling row inspected | manual (checkpoint:human-verify, blocking) | n/a — live verification | n/a | ⬜ pending |
| 27-05-03 | 05 | 4 | GATE-09 | T-27-04 | `RAMS_DISPLAY_LIFT_GATE=false` proven a genuine rollback, then restored | manual (checkpoint) | n/a — live flag smoke test | n/a | ⬜ pending |

*Status: pending until tasks execute.*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Services/Rams/DisplayLiftPolicyTest.php` — new, covers the policy class itself (no-row exclusion, band boundaries, never-1-at-55+/never-4 guard via `violatesPolicy()`, D-05 unresolvable-size floor)
- [ ] `tests/Unit/Services/Rams/RamsComplianceUpgradeServiceDisplayLiftTest.php` — new, sibling to `RamsComplianceUpgradeServiceCacheTest.php`, reflection-invoke pattern (mirrors `ProjectSpecificRisksGatedTest.php`) — asserts the old ladder strings are gone, RULE-12 branch order, and the decommission-scan RULE-03 statement
- [ ] `tests/Unit/Services/Rams/DisplayLiftGateTest.php` — new, GATE-09 throw/no-throw behaviour including the revert-and-restore non-vacuity proof
- [ ] `tests/Feature/Rams/DisplayLiftDualPathTest.php` — new, dual entry-point proof modeled on `ReviewedHazardTieringTest.php` + `ManualRamsCreationTest.php`
- [ ] `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php` — new, allow-list file scan modeled on `HazardResolutionPathGuardTest.php`
- [ ] Extend `tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php` (existing) — revisit `test_small_display_does_not_fire_two_person_lift`, add band-boundary cases
- [ ] Extend `tests/Unit/Services/MethodStatementServiceTest.php` (existing) — new test method for the fallback-phase team-size string
- [ ] No test framework install needed — PHPUnit already fully configured

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|--------------------|
| Regenerating 21CQ30960 on `rams.21stcav.com` does not trip GATE-09, and the §6.7 table / Manual Handling hazard row read the corrected bands | GATE-09, RULE-02, RULE-03, RULE-12 | Milestone-standing decision: validated on live production data, not a fixture (Phase 26 precedent) | See Plan 27-05, Task 1: deploy as `stcav`, reseed `HazardTemplateSeeder`, regenerate RAMS 98/99, inspect §6.7 + Manual Handling hazard row in the downloaded DOCX, confirm `RamsDocument.status` is not `FAILED` |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies. The manual exceptions are **all three Wave 4 tasks** — `27-05-01` (live deploy), `27-05-02` (blocking human-verify of 21CQ30960 on live), `27-05-03` (rollback-flag smoke test). All are `checkpoint:*` types and none is automatable: ROADMAP success criterion 4 requires proof against real live data, which by construction cannot be a fixture. Waves 1–3 are fully automated.
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 120s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-08-26 (planner sign-off; execution will populate per-task Status column)
