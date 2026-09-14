---
phase: 30
slug: structural-validation-gates
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-09-13
reconciled: 2026-09-14
---

# Phase 30 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `30-RESEARCH.md` § Validation Architecture (`:513`).
> Reconciled 2026-09-13 (Plan 30-05, mid-phase — against the plan *contract*) and again 2026-09-14
> (Plan 30-09, phase-gate close-out — against actual full-suite results). See "Reconciliation notes"
> at the bottom for what changed and why at each pass.

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

Reconciled against the nine plans. Task IDs follow `{plan}-T{n}`, matching each plan's own
numbered `<task>` blocks.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 30-03-T1 | 30-03 | 2 | GATE-01 | — | Throws on a trigger phrase with no hazard row (canonical asbestos-orphan) | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-03-T1 | 30-03 | 2 | GATE-01 | — | Throws when the hazard row exists but the client-responsibility entry does not (D-05 "either") | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-02-T2 | 30-02 | 1 | GATE-01 | — | Sees a non-empty `client_responsibilities_expanded` on the Save-Review path (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ✅ | ✅ green |
| 30-03-T2 | 30-03 | 2 | GATE-02 | — | Throws when an area name matches no phase title / method step | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-03-T2 | 30-03 | 2 | GATE-02 | — | **Passes vacuously** on zero areas (manual / form-only RAMS) | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-02-T2 | 30-02 | 1 | GATE-02 | — | Sees a non-empty area list on all real entry points (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ✅ | ✅ green |
| 30-02-T2 | 30-02 | 1 | GATE-01/02 | T-30-25 | Site 5 (`downloadPdf()`) inherits both mirrors **only by persistence**, asserted explicitly (mirror-liveness) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ✅ | ✅ green |
| 30-03-T3 | 30-03 | 2 | GATE-01/02 | — | An armed gate surfaces as a friendly redirect (real HTTP POST through Save Review), not a 500 | feature | `php artisan test --filter=StructuralGatesSaveReviewGateTest` | ✅ | ✅ green |
| 30-06-T1 | 30-06 | 3 | GATE-04 | — | Throws when `post_l*post_s > pre_l*pre_s`; error names `RA##` by row index | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-06-T1 | 30-06 | 3 | GATE-04 | — | **Warns, never throws**, on `post_severity < pre_severity` — committed Tilda hazard 0 (3×4 → 1×3) | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-06-T1 | 30-06 | 3 | GATE-04 | — | Missing `pre_*` keys skip the row rather than tripping the `?? 1` default | unit | `php artisan test --filter=StructuralGatesTest` | ✅ | ✅ green |
| 30-07-T2 | 30-07 | 4 | GATE-13 | — | Throws on assert-no-hot-works + unconditional permit requirement | unit | `php artisan test --filter=HotWorksGateTest` | ✅ | ✅ green |
| 30-07-T2 | 30-07 | 4 | GATE-13 | — | Throws on assert-no-hot-works + solder/flux in COSHH | unit | `php artisan test --filter=HotWorksGateTest` | ✅ | ✅ green |
| 30-07-T2 | 30-07 | 4 | GATE-13 | — | Does **NOT** throw when the only permit reference is `addPermitAndIsolation()`'s `:939` line | unit | `php artisan test --filter=HotWorksGateTest` | ✅ | ✅ green |
| 30-08-T1 | 30-08 | 5 | GATE-14 | — | Fires on the canonical Step-4 defect (cites RA11/12/13/21, omits RA01/RA02) | unit | `php artisan test --filter=MissingRiskRefGateTest` | ✅ | ✅ green |
| 30-08-T1 | 30-08 | 5 | GATE-14 | — | Does **not** fire when the implied hazard is absent from the register | unit | `php artisan test --filter=MissingRiskRefGateTest` | ✅ | ✅ green |
| 30-08-T2 | 30-08 | 5 | GATE-14 | T-30-01 | Does not reuse `$keywordRiskMap` (source guard; `DisplayLiftPolicySourceGuardTest` precedent) | feature | `php artisan test --filter=MissingRiskRefGateSourceGuardTest` | ✅ | ✅ green |
| 30-06-T2 | 30-06 | 3 | D-03 (`RAMS_STRUCTURAL_GATES`) | — | With `RAMS_STRUCTURAL_GATES` false, `upgrade()` output is byte-identical to pre-Phase-30 (compliance_warnings key excepted) | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-07-T2 | 30-07 | 4 | D-03 (`RAMS_HOT_WORKS_GATE`) | — | With `RAMS_HOT_WORKS_GATE` false, `upgrade()` output is byte-identical to pre-Phase-30 | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-08-T1 | 30-08 | 5 | D-03 (`RAMS_MISSING_RISK_REF_GATE`) | — | With `RAMS_MISSING_RISK_REF_GATE` false, `upgrade()` output is byte-identical to pre-Phase-30 | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-06-T2 | 30-06 | 3 | D-04 (`RAMS_STRUCTURAL_GATES` independence) | — | Disarming the structural trio leaves `RAMS_MISSING_RISK_REF_GATE`/`RAMS_HOT_WORKS_GATE` armed and vice versa | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-07-T2 | 30-07 | 4 | D-04 (`RAMS_HOT_WORKS_GATE` independence) | — | Disarming GATE-13 alone leaves the structural trio and GATE-14 armed | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-08-T1 | 30-08 | 5 | D-04 (`RAMS_MISSING_RISK_REF_GATE` independence) | — | Disarming GATE-14 alone leaves the structural trio and GATE-13 armed | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ✅ | ✅ green |
| 30-01-T3 | 30-01 | 1 | UI-SPEC | — | `compliance_warnings` overwritten wholesale (a stale entry clears) | feature | `php artisan test --filter=ComplianceWarningsChannelTest` | ✅ | ✅ green |
| 30-04-T2 | 30-04 | 2 | UI-SPEC | — | Warnings panel renders nothing when the key is empty/absent | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ✅ | ✅ green |
| 30-04-T2 | 30-04 | 2 | UI-SPEC | T-30-02 | **No warning text in a regenerated PDF or DOCX** — assert, do not assume | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ✅ | ✅ green |
| 30-09-T1 | 30-09 | 6 | ROADMAP crit. 4 | — | A defect-bearing 21CQ30960 fixture makes GATE-13 and GATE-14 fire on the real-world document shape | feature | `php artisan test --filter=StructuralGatesRealDocumentTest` | ✅ | ✅ green |
| 30-09-T1/T2 | 30-09 | 6 | ROADMAP crit. 4 | — | A clean 21CQ30960 fixture regenerates and passes all five gates armed — no false positives, no warning text in the PDF/DOCX | feature/snapshot | `vendor/bin/phpunit --group snapshot` + `php artisan test --filter=StructuralGatesRealDocumentTest` | ✅ | ✅ green |
| 30-09-T3 | 30-09 | 6 | Phase gate | — | Full RAMS suite + snapshot suite green, human-checkpoint golden review (autonomous: false) | feature/snapshot | `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` + `vendor/bin/phpunit --group snapshot` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*"File Exists ✅ / Status ✅ green" rows now cover ALL nine plans (30-01 through 30-09), reconciled
2026-09-14 as part of Plan 30-09's phase-gate close-out — see `30-01-SUMMARY.md` through
`30-09-SUMMARY.md` for the passing runs each row reflects. This table was reconciled by Plan 30-05
(2026-09-13) while Plans 30-06 through 30-09 were still `⬜ pending` (waves 3-5 had not executed);
Plan 30-09 (wave 6) re-reconciled every remaining row against the actual full-suite run recorded in
this plan's own SUMMARY (811 → 834 → 850 → 865 → 873 passing across waves 3-6) rather than trusting
the earlier draft's placeholder state. Treat this file's dated reconciliation note (below) as the
authoritative "as of" marker for any future reader.

---

## D-04 mapping — corrected to three rows (was one row pointing at the wrong test file)

**This is the correction this reconciliation exists to make.** The pre-reconciliation table mapped
"flag independence" to a single `StructuralGatesTest` row. That test class is a **unit** test
reaching gate methods via reflection — it has no mechanism to prove independence between three
`.env`-controlled flags, which is inherently a feature-level, real-config concern. The actual proof
lives in `StructuralGatesDisarmedTest`, and it is built incrementally across three plans, one flag
per wave, because each flag's gate body does not exist until its own plan ships:

| Flag | Owning plan | Wave | What the plan's half proves |
|------|-------------|------|------------------------------|
| `RAMS_STRUCTURAL_GATES` | **30-06** (shipped, `8901a3d`/`00b1433`) | 3 | Created `StructuralGatesDisarmedTest`; proved the structural trio disarmed leaves output byte-identical AND that disarming it does not touch the other two flags |
| `RAMS_HOT_WORKS_GATE` | **30-07** (shipped, `a820f0c`/`172e4a1`) | 4 | Extended `StructuralGatesDisarmedTest`; proved GATE-13 disarmed alone leaves the structural trio's armed/disarmed state unaffected |
| `RAMS_MISSING_RISK_REF_GATE` | **30-08** (shipped, `107998b`/`ccefef8`/`2353d79`) | 5 | Extended `StructuralGatesDisarmedTest`; proved GATE-14 disarmed alone leaves the other two flag groups unaffected, completing the 3×3 independence matrix (16 tests total in the final file) |

The matrix is now COMPLETE — all three flags proven independent of each other in both directions,
confirmed by `StructuralGatesDisarmedTest`'s 16 passing tests as of Plan 30-08's close-out and
re-confirmed unchanged by Plan 30-09's full-suite phase-gate run.

This mis-mapping — a single vague "D-04" row citing a unit test with no config-flipping mechanism
— is precisely how an incomplete D-04 proof could have survived plan review once already: reading
the old table, nothing forces a check that `StructuralGatesTest` actually contains a flag-flipping
assertion (it does not; that was never its job). The three-row form above makes each flag's
ownership and wave explicit, so a reviewer can check `30-06-SUMMARY.md`/`30-07-SUMMARY.md`/
`30-08-SUMMARY.md` for the matching proof directly instead of trusting a paraphrase.

---

## Wave 0 Requirements

**Reconciliation note:** Phase 30 has **no separate Wave 0 scaffolding plan.** Every test file
listed below was created by the tdd-style task inside the plan that also implements the behaviour
it tests — e.g. `StructuralGatesTest.php` was created by Plan 30-03's Task 1/2, not by an
upfront-only scaffolding step. `wave_0_complete: true` in this file's frontmatter records that
property explicitly, so it is read as "Wave 0's concerns are covered, by design, inside the
consuming plans" and not as a missed step. This mirrors Plan 30-01/30-02's own foundation-and-
mirror sequencing, which functions as this phase's Wave 0 in substance even though no plan is
labelled "Wave 0".

- [x] `tests/Unit/Services/Rams/StructuralGatesTest.php` — GATE-01/02 created by Plan 30-03; GATE-04 rows added by Plan 30-06 (26 tests total, all shipped and green) (reflection pattern from `CdmEmergencyGateTest`)
- [x] `tests/Unit/Services/Rams/HotWorksGateTest.php` — GATE-13 both halves + the `:939` non-firing regression — Plan 30-07 (8 tests)
- [x] `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` — GATE-14 — Plan 30-08 (8 tests)
- [x] `tests/Feature/Rams/StructuralGatesDualPathTest.php` — mirror reachability across entry points — Plan 30-02 (models: `CdmEmergencyDualPathGateTest`, `DisplayLiftSaveReviewGateTest`)
- [x] `tests/Feature/Rams/StructuralGatesSaveReviewGateTest.php` — armed-gate HTTP surfacing — Plan 30-03
- [x] `tests/Feature/Rams/StructuralGatesDisarmedTest.php` — byte-identical-when-false proof, created by Plan 30-06 and extended by Plans 30-07/30-08 to a complete 16-test D-04 matrix (see D-04 mapping above)
- [x] `tests/Feature/Rams/ComplianceWarningsChannelTest.php` — Plan 30-01
- [x] `tests/Feature/Rams/ComplianceWarningsRenderTest.php` — warn channel render + the must-not-leak assertion — Plan 30-04
- [x] `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` — static source guard that GATE-14 does not re-derive from `$keywordRiskMap` — Plan 30-08 (5 tests)
- [x] `tests/Feature/Rams/StructuralGatesRealDocumentTest.php` + `tests/Fixtures/rams/21cq30960/record.json` + `tests/Fixtures/rams/21cq30960-defects/record.json` — Plan 30-09 (8 tests); the clean fixture is also wired into `PdfSnapshotTest.php`/`DocxSnapshotTest.php` with 4 new golden-render + leak-boundary tests
- Framework install: **none required** — PHPUnit already configured.

**21CQ30960 fixture decision — RESOLVED, no longer open.** Plan 30-09 authors two fixtures under
`tests/Fixtures/rams/`: a defect-bearing `21cq30960-defects/record.json` reproducing the RA18/§6.8/
COSHH contradiction and the Step 4 citation omission, and a clean `21cq30960/record.json` proving
all five gates pass with no false positives. Both wire into the snapshot suite (`PdfSnapshotTest`,
`DocxSnapshotTest`) per Plan 30-09's Task 2. The live/UAT alternative this Wave-0 item originally
flagged as an open choice was **not** taken.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live false-positive rate per gate across the production corpus | D-03 arming | Not measurable from this machine — needs the live DB | Run the `30-MEASUREMENT.md` read-only pass on the VPS before flipping any flag |
| Flag flip takes effect on the VPS | D-03 / D-04 | Live config cache behaviour; research assumption **A4 (unverified)** is that `config:cache` is active, so an `.env` edit alone may not apply | After each `.env` flip, clear the config cache and re-verify a regeneration — full sequence in `30-MEASUREMENT.md` |
| A corpus regeneration precedes any `RAMS_STRUCTURAL_GATES` flip | 30-MEASUREMENT.md hard precondition | Sites 4/5/6 inherit the Plan 30-02 mirrors only by persistence; a legacy document produces both failure directions at once if armed pre-regeneration | `php artisan rams:refresh-compliance` (no `--dry-run`) on the VPS, then re-verify |
| ROADMAP criterion 4 — 21CQ30960 regenerates clean | GATE-01/02/04/13/14 | **No longer manual** — Plan 30-09 automates this via the two-fixture snapshot approach (see Wave 0 Requirements above). Retained here only as a fallback if Plan 30-09's fixture authoring is later found to diverge materially from the real document. | Regenerate 21CQ30960 on `rams.21stcav.com`, confirm no gate fires and no warning text appears in the PDF/DOCX |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies — every task across all nine plans carries an `<automated>` PowerShell/`php artisan test` verify command in its own PLAN.md
- [x] Sampling continuity: no 3 consecutive tasks without automated verify — every task in every plan (30-01 through 30-09) has its own automated verify block
- [x] Wave 0 covers all MISSING references — see "Wave 0 Requirements" reconciliation note above: no separate Wave 0 plan exists, but every test file it would have scaffolded is created by its consuming plan's own task, which is the pattern this phase actually used
- [x] No watch-mode flags — every command in this file is a one-shot `php artisan test` / `vendor/bin/phpunit` invocation, never `--watch`
- [x] Feedback latency < 80s (RAMS suite) — measured at Phase 29 closeout and unchanged by this phase's file set (`29-MEASUREMENT.md` local suite runtime measurement covers the same suite)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved (2026-09-13, Plan 30-05 reconciliation)

---

## Reconciliation notes (2026-09-13, Plan 30-05)

This file was `status: draft`, `nyquist_compliant: false`, with every Task ID/Plan/Wave column
marked "to be determined" in every Task ID/Plan/Wave column because it was written before any of the nine plans existed. Plan 30-05 reconciles it now
that Plans 30-01 through 30-04 have shipped and Plans 30-06 through 30-09 are fully specified
(their PLAN.md files exist with concrete task breakdowns, even though they have not executed yet).

Changes made:
1. Filled in Task ID / Plan / Wave for every row from the nine plans' own frontmatter and task
   lists.
2. Corrected the single D-04 "flag independence" row (previously mapped to `StructuralGatesTest`,
   a unit test with no `.env`-flipping mechanism) into three rows naming
   `StructuralGatesDisarmedTest` and the three plans (30-06/30-07/30-08) that each own one flag's
   half of the proof — see "D-04 mapping" section above.
3. Added rows for `StructuralGatesSaveReviewGateTest` (Plan 30-03, armed-gate HTTP surfacing) and
   the mirror-liveness assertion on site 5 (part of `StructuralGatesDualPathTest`, Plan 30-02) —
   both existed in the shipped code/tests but had no row in the original table.
4. Resolved the Wave-0 "21CQ30960 fixture decision" — Plan 30-09's two-fixture approach answers it;
   removed the "planner MUST choose" language and marked it decided.
5. Set `wave_0_complete: true` — Phase 30 has no dedicated Wave 0 scaffolding plan; the test files
   Wave 0 would have created are instead created by the tdd tasks that consume them, one plan at a
   time (30-01 through 30-09). This is stated explicitly, per plan instruction, so the flag is not
   misread as a missed step.
6. Set `status: approved` and `nyquist_compliant: true`, and ticked the Validation Sign-Off boxes
   that are now demonstrably true against the reconciled table.

**What remained genuinely pending as of 2026-09-13:** rows for Plans 30-06 through 30-09 were marked
`⬜ pending` because those plans had not executed yet at this reconciliation. This reconciliation
corrected the *contract* — what would be tested, by which file, in which wave — not the *results*,
which did not exist until those plans ran.

---

## Reconciliation notes (2026-09-14, Plan 30-09 — phase-gate close-out)

Plan 30-05 (above) reconciled this file mid-phase, before waves 3, 4 and 5 executed. By the time
Plan 30-09 (wave 6) ran, the suite had gone 811 (end of wave 2) → 834 (wave 3, Plan 30-06) → 850
(wave 4, Plan 30-07) → 865 (wave 5, Plan 30-08) → 873 (wave 6, Plan 30-09) — every row this file
still showed `⬜ W3/W4/W5/W6 pending` had a real, green test behind it. This reconciliation:

1. Flipped every remaining `⬜ W{n} pending` row in the Per-Task Verification Map to `✅ green`,
   cross-checked against each plan's own SUMMARY.md (`30-06-SUMMARY.md` through `30-09-SUMMARY.md`)
   rather than assumed from the plan text.
2. Updated the D-04 mapping table to record the actual shipped commit hashes for all three flags'
   halves and stated explicitly that the 3×3 independence matrix is now COMPLETE (16 tests in the
   final `StructuralGatesDisarmedTest.php`).
3. Ticked every remaining Wave 0 Requirements checkbox, with test-count annotations sourced from
   each plan's SUMMARY rather than left as bare checkmarks.
4. Ran the full phase gate from PowerShell (`php artisan test`, `vendor/bin/phpunit --group
   snapshot`, `php artisan test --filter=Rams`) and recorded the actual counts in
   `30-09-SUMMARY.md` — this file's `✅ green` marks are now accurate for all nine plans as of
   2026-09-14, not merely for the contract Plan 30-05 established.

No row in this file was marked green without a corresponding passing test run observed directly by
this plan's own execution — the discipline Plan 30-05's closing note asked a future reconciler to
follow.
