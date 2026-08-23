---
phase: 26
slug: hazard-library-structural-inversion
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-23
---

# Phase 26 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `26-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit via Laravel's testing layer (some tests use Pest-style syntax; both run under `phpunit.xml`) |
| **Config file** | `phpunit.xml` (repo root) — `Unit` suite → `tests/Unit`, `Feature` suite → `tests/Feature` |
| **Quick run command** | `php artisan test --filter=Hazard` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | Full suite ~2132 tests; baseline 2 pre-existing unrelated failures as of 2026-08-17 |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Hazard`
- **After every plan wave:** Run `php artisan test` — this phase touches shared services (`RamsBuilderService`, `RamsComplianceUpgradeService`'s call graph) consumed by many unrelated test files, so a hazard-scoped filter is not sufficient at wave boundaries
- **Before `/gsd:verify-work`:** Full suite green **plus** the manual 21CQ30960 spot-check
- **Max feedback latency:** quick filter target < 30s

---

## Per-Task Verification Map

*Populated during planning — one row per task once PLAN.md files exist.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | — | — | HAZ-01 | — | N/A | unit/feature | `php artisan test --filter=HazardTemplateSeeder` | ❌ W0 | ⬜ pending |
| TBD | — | — | HAZ-02 | — | N/A | feature | `php artisan test --filter=HazardIncludeWhen` | ❌ W0 | ⬜ pending |
| TBD | — | — | HAZ-02 | T-26-01 | `include_when` never settable on a non-global row | unit (structural) | `php artisan test --filter=HazardInjectionPathsRemoved` | ❌ W0 | ⬜ pending |
| TBD | — | — | HAZ-03 | — | N/A | feature | `php artisan test --filter=WorkingAtHeightResidualScore` | ❌ W0 | ⬜ pending |
| TBD | — | — | HAZ-04 | — | N/A | feature | `php artisan test --filter=HazardScoreEditableDefault` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Requirement → behaviour mapping (from research):**

| Req | Behaviour under test |
|-----|----------------------|
| HAZ-01 | All 18 hazards exist in `hazard_templates` after seeding, each with `include_when` set |
| HAZ-02 | A fresh RAMS with no matching structured signals shows **only** the 4 Always hazards (Slips/trips/falls, Low voltage AV connections, Fire and evacuation, COSHH substances) — never the old 11 config-baseline titles |
| HAZ-02 | All injection paths structurally dead — no live reference to `config('rams_tier1.baseline_hazards')` or `HazardLibraryService::MANDATORY_KEYWORDS` |
| HAZ-03 | Working at Height renders residual `1×4` (not `2×3`) in **both** DOCX and live PDF output |
| HAZ-04 | An un-reviewed default score is visibly distinguishable from a reviewed one, and the marker survives into `generated_data` alongside any unreviewed score |

---

## Wave 0 Requirements

- [ ] Rewrite `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` — test 1 asserts the exact behaviour this phase removes. Tests 2 and 3 look reusable; test 3 already models the target "flag off → empty" end state
- [ ] New test class — `HazardTemplateSeeder` 18-hazard **upsert-by-name idempotency** (the seeder claims idempotency today but nothing tests it)
- [ ] New test class — tiered include-when resolver, one case per tier: always / deterministic-match / deterministic-no-match / AI-judgement-matched / AI-judgement-flagged-unconfirmed (D-06)
- [ ] New structural guard test — zero live references to the removed config key and constant. Model on the `DeadPathRemovalGuardTest` precedent from Phase 22.1 (**locate and confirm its exact path during planning before citing it**)
- [ ] New test — `RA{NN}` refs in a freshly-generated doc with a variable-length (non-11) register still resolve 1:1 against the method statement's cited RA-IDs within the same generation. Extends the `MethodStatementAssociatedRisksTest` pattern from quick task 260817-r5e
- [ ] Framework install: **none** — PHPUnit/Laravel testing already configured

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Regenerating real project **21CQ30960** (VW Blakelands) shows only hazards its actual scope supports | HAZ-01..04 / Success Criterion 4 | Requires live production data and human judgement against the source quote. Per CONTEXT.md it "cannot be validated against the old fixed 11/7-item lists, which by construction cannot contain the answer" | Deploy to `rams.21stcav.com` (as `stcav`, not root), regenerate 21CQ30960, open the **DOCX** output — the live primary renderer — and compare each hazard row against the source quote's actual scope. Confirm Working at Height residual reads `1×4`. Confirm no hazard appears that the job's scope doesn't support. Any hazard flagged for confirmation (D-06) is expected, not a defect |
| New behaviour is revertible without redeploy | CONTEXT.md discretion constraint | Env-flag behaviour can only be proven against the deployed app | Toggle the phase's env flag off on live, regenerate, confirm prior behaviour returns; toggle back on |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s on the quick filter
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
