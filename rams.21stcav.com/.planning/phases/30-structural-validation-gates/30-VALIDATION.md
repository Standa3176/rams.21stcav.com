---
phase: 30
slug: structural-validation-gates
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-09-13
---

# Phase 30 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `30-RESEARCH.md` § Validation Architecture (`:513`).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit via Laravel `php artisan test` (`phpunit.xml` at repo root) |
| **Config file** | `phpunit.xml` — two suites (`tests/Unit`, `tests/Feature`); `<groups><exclude><group>snapshot</group>` at `:21-25` |
| **Quick run command** | `php artisan test --filter=StructuralGate` |
| **Full suite command** | `php artisan test` (RAMS subset: `php artisan test tests/Unit/Support/Rams tests/Feature/Rams`) |
| **Estimated runtime** | RAMS subset ~80s / 349 tests; full suite ~576s / 2,455+ tests |
| **Snapshot suite** | `vendor/bin/phpunit --group snapshot` (excluded from the default run) |

> ⚠️ **`php` is NOT on the Bash tool's PATH on this machine.** Running `php … | tail` from Bash
> exits 0 while executing nothing — a silently faked green gate. **Every command in this file must
> be run through PowerShell / Herd.** See `.planning/` memory note and `29-MEASUREMENT.md`.

> ⚠️ **Known pre-existing failure:** `QueueRecoverCommandTest` fails on the full suite and is
> unrelated to this phase (documented at Phase 29 closeout). It is not a Phase 30 regression.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=<that gate's own test class>`
- **After every plan wave:** `php artisan test --filter=Rams` (349 tests / 1,604 assertions baseline)
- **Before `/gsd:verify-work`:** full `php artisan test` green (modulo `QueueRecoverCommandTest`)
  **plus** `vendor/bin/phpunit --group snapshot` green
- **Max feedback latency:** ~15s for a single `--filter` class; ~80s for the RAMS suite

---

## Per-Task Verification Map

Task IDs are assigned by the planner. Rows are keyed by requirement until plans exist.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | 0 | GATE-01 | — | Throws on a trigger phrase with no hazard row (canonical asbestos-orphan) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-01 | — | Throws when the hazard row exists but the client-responsibility entry does not (D-05 "either") | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-01 | — | Sees a non-empty `client_responsibilities_expanded` on the Save-Review path (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-02 | — | Throws when an area name matches no phase title / method step | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-02 | — | **Passes vacuously** on zero areas (manual / form-only RAMS) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-02 | — | Sees a non-empty area list on all real entry points (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-04 | — | Throws when `post_l*post_s > pre_l*pre_s`; error names `RA##` by row index | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-04 | — | **Warns, never throws**, on `post_severity < pre_severity` — committed Tilda hazard 0 (3×4 → 1×3) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-04 | — | Missing `pre_*` keys skip the row rather than tripping the `?? 1` default | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-13 | — | Throws on assert-no-hot-works + unconditional permit requirement | unit | `php artisan test --filter=HotWorksGateTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-13 | — | Throws on assert-no-hot-works + solder/flux in COSHH | unit | `php artisan test --filter=HotWorksGateTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-13 | — | Does **NOT** throw when the only permit reference is `addPermitAndIsolation()`'s `:939` line | unit | `php artisan test --filter=HotWorksGateTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-14 | — | Fires on the canonical Step-4 defect (cites RA11/12/13/21, omits RA01/RA02) | unit | `php artisan test --filter=MissingRiskRefGateTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-14 | — | Does **not** fire when the implied hazard is absent from the register | unit | `php artisan test --filter=MissingRiskRefGateTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | GATE-14 | T-30-01 | Does not reuse `$keywordRiskMap` (source guard; `DisplayLiftPolicySourceGuardTest` precedent) | feature | `php artisan test --filter=MissingRiskRefGateSourceGuardTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | D-03 (all) | — | With all three flags false, `upgrade()` output is byte-identical to pre-Phase-30 | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | D-04 | — | Each flag is independent — disarming one leaves the others armed | unit | `php artisan test --filter=StructuralGatesTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | UI-SPEC | — | `compliance_warnings` overwritten wholesale (a stale entry clears) | unit | `php artisan test --filter=ComplianceWarningsChannelTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | UI-SPEC | — | Warnings panel renders nothing when the key is empty/absent | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | 0 | UI-SPEC | T-30-02 | **No warning text in a regenerated PDF or DOCX** — assert, do not assume | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | ROADMAP crit. 4 | — | A real regenerated project passes all five gates clean | feature/snapshot | `vendor/bin/phpunit --group snapshot` + RAMS suite | ⚠️ partial | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Services/Rams/StructuralGatesTest.php` — GATE-01 / 02 / 04 (reflection pattern from `CdmEmergencyGateTest`)
- [ ] `tests/Unit/Services/Rams/HotWorksGateTest.php` — GATE-13 both halves + the `:939` non-firing regression
- [ ] `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` — GATE-14
- [ ] `tests/Feature/Rams/StructuralGatesDualPathTest.php` — mirror reachability across entry points (models: `CdmEmergencyDualPathGateTest`, `DisplayLiftSaveReviewGateTest`)
- [ ] `tests/Feature/Rams/StructuralGatesDisarmedTest.php` — byte-identical-when-false proof
- [ ] `tests/Feature/Rams/ComplianceWarningsChannelTest.php` + `ComplianceWarningsRenderTest.php` — warn channel + the must-not-leak assertion
- [ ] `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` — static source guard that GATE-14 does not re-derive from `$keywordRiskMap`
- [ ] **21CQ30960 fixture decision** — no such fixture exists today (grep finds only docblock mentions). The planner MUST choose explicitly: author one under `tests/Fixtures/rams/` following the Tilda precedent, or record ROADMAP criterion 4 as a human/UAT step against the live document.
- [ ] Framework install: **none required** — PHPUnit already configured.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live false-positive rate per gate across the production corpus | D-03 arming | Not measurable from this machine — needs the live DB | Run the `30-MEASUREMENT.md` read-only pass on the VPS before flipping any flag |
| Flag flip takes effect on the VPS | D-03 / D-04 | Live config cache behaviour; research assumption **A4 (unverified)** is that `config:cache` is active, so an `.env` edit alone may not apply | After each `.env` flip, clear the config cache and re-verify a regeneration |
| ROADMAP criterion 4 — 21CQ30960 regenerates clean | GATE-01/02/04/13/14 | Only if the planner chooses the UAT route over authoring a fixture | Regenerate 21CQ30960 on `rams.21stcav.com`, confirm no gate fires and no warning text appears in the PDF/DOCX |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 80s (RAMS suite)
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
