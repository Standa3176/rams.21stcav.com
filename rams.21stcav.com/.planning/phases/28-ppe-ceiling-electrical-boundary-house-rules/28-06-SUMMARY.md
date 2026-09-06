# Plan 28-06 — Summary

**Completed:** 2026-09-06
**Requirements:** GATE-06, GATE-07 (and closes RULE-01, the last of a 3-plan sequence)
**Tasks:** 3/3

> **Note on authorship.** The executor agent for this plan was terminated by an account
> spend-limit (HTTP 429) *after* committing all three tasks but *before* writing this SUMMARY.
> The orchestrator verified the landed work directly — constraint-by-constraint against the
> source, then by running the tests — and wrote this file. Every figure below is from a real
> run performed after the agent stopped, not carried over from its partial output.

---

## Commits

| Commit | What |
|---|---|
| `f38b750` | feat(28-06): GATE-06/07 `enforceFfp2AndConfinedSpaceGate()` + config flag |
| `6d16764` | fix(28-06): `downloadPdf()` catches `RamsGenerationException` from `upgrade()` |
| `7c29aa9` | test(28-06): GATE-06/07 reflection, Save-Review, and dual-path coverage |
| `0d0812f` | fix(28-06): scope gate to ffp2/confined_space keys only; exclude gate source from static ban |

---

## What shipped

**`RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate()`** — the throwing half of
D-03's auto-correct-then-throw pair, mirroring `enforceDisplayLiftGate()`'s shape. It scans four
surfaces:

1. **`$data['hazards'][*]['hazard']` — the hazard's own NAME**, via
   `ControlTextRuleViolations::detect()`, `confined_space` key only (`:1304-1315`).
2. `$data['hazards'][*]['controls']` — via `detectAll()`, both keys.
3. `$data['ppe']` — literal `FFP2` token, raw check (closed vocabulary, not free text).
4. `$data['ppe_matrix'][*]['ppe']` — same, defence in depth.

**Config flag:** `config/rams_tier1.php:98` — `'ffp2_confined_space_gate_enabled' =>
env('RAMS_PPE_CEILING_ELECTRICAL_GATE', true)`. New and **independent** of
`RAMS_DISPLAY_LIFT_GATE`, so rolling back GATE-06/07 can never disarm GATE-09 (D-08).

**`RamsController::downloadPdf()`** now catches `RamsGenerationException` from `upgrade()` — the
one call site of five that previously had none.

---

## Surface 1 is the reason this plan was revised: the hazard-NAME gap

The plan-checker traced a reachable hole that **no mechanism in the original 8 plans covered**.
It is worth recording in full, because the fix looks like a one-liner and is not.

`resources/views/project-packages/review.blade.php:1875` / `:2484` and
`resources/views/rams/quote-review.blade.php:808` expose a **free-text** hazard-name input. An
engineer can type "Confined Space". That string:

- misses `LegacyHazardNameFoldMap`, which maps only the **exact plural** `'confined spaces'`;
- then misses the fuzzy tiers (zero shared significant words with "Restricted access and ceiling
  void working");
- returns a pseudo-template with `id => null`, so `RamsBuilderService::reviewedToRisk()`'s
  `if ($tpl !== null && ($tpl->id ?? null) !== null)` block is skipped **entirely** — meaning
  tier-1 `detectAll()` never runs for it;
- and survives verbatim into `generated_data`.

That directly contradicts ROADMAP criterion 2 ("No hazard **title** … labels a ceiling void …
'confined space[s]'") and criterion 4.

**Two design points, both deliberate:**

- The name check is **unconditional per hazard**, deliberately NOT nested inside any
  template-resolution branch. A check placed inside that branch would miss precisely the
  unmatched-name case that is the only one that matters.
- **The gate errors; it does not silently rename.** An unrecognised hazard name is the "cannot
  confidently classify, do not guess" case, and criterion 4 specifies erroring. Renaming an
  engineer's hazard row is a larger action than replacing a control line and is not taken.

---

## `upgrade()`'s five call sites — behaviour on a throw

| # | Call site | Behaviour |
|---|---|---|
| 1 | `RamsController.php:603` — Save Review (`updateAndDownload()`) | Pre-existing catch → redirect back with flash `error`. **Proven by feature test.** |
| 2 | `RamsController.php` ~`:701` — DOCX-rebuild-on-download fallback | Logs and continues; already surfaces its message. Unchanged. |
| 3 | `RamsController.php` ~`:852` — `downloadPdf()` | **Had no catch at all.** Task 2 added one, mirroring site 1. Kept separate from the existing PDF-rendering catch. |
| 4 | `RamsBuilderService.php:941` — `runPipeline()` | Job-wrapped; exception fails the job with `error_message` set. **Proven by dual-path test.** |
| 5 | `RamsBuilderService.php:296` — `runFromReview()` | Job-wrapped, same. **Proven by dual-path test.** |

---

## Verification — all figures from real runs

**Gate suites** — `artisan test --filter='Ffp2ConfinedSpaceGateTest|Ffp2ConfinedSpaceSaveReviewGateTest|Ffp2ConfinedSpaceDualPathGateTest'`
→ **19 passed, 39 assertions, exit 0** (54.42s).

Includes both dual-path cases (`gate throws via run pipeline`, `gate throws via run from review`),
the name-violation case on Save Review (`stale confined space hazard name is blocked on save
review`), the clean-hazard non-vacuity case, and the kill-switch case.

**Full Rams suite** — `artisan test --filter=Rams` → **644 passed, 2502 assertions, 1 failed**
(160.80s).

**Constraint checks run directly against source:**

| Constraint | Result |
|---|---|
| Gate scans hazard NAME, unconditionally | ✅ `:1304-1315`, outside any template branch |
| Classification routed through `ControlTextRuleViolations` | ✅ `grep -ci "confined space"` inside the gate method = **0** — no duplicated phrase list |
| Env flag new and independent | ✅ `config/rams_tier1.php:98` |
| Dual-path test uses the local non-global `HazardTemplate` workaround | ✅ `is_global => false` fixture present |
| `config/rams_tier1.php:286` fire-stop wording untouched | ✅ (Phase 31 owns it) |

---

## The one failing test is pre-existing — verified, not assumed

`RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`
(`:561`) fails. Plans 28-02, 28-03 and 28-04 each cited it as pre-existing; 28-02's evidence was a
*seeder* revert, which would not have isolated whether Plan 28-01's new detectors caused it.

**Re-verified properly:** `git checkout c27bfec -- app/Services/Rams/ControlTextRuleViolations.php
app/Services/RamsBuilderService.php` (both files back to the pre-phase-28 commit), then ran that
single test → **still fails**. Files restored immediately; source tree confirmed clean afterwards.

It is genuinely pre-existing and unrelated to Phase 28. Logged in `deferred-items.md`.

---

## Requirements

- **GATE-06 → Complete.** FFP2 anywhere now errors, across hazard controls, the PPE array and the
  PPE matrix, on every reachable path.
- **GATE-07 → Complete.** Affirmative confined-space labelling errors — in control text *and* in
  the hazard name, the surface nothing else in the phase scanned.
- **RULE-01 → Complete**, as the last of a three-plan sequence: 28-01 (detector), 28-04 (14 source
  sites + static ban), 28-06 (runtime gate). Three independent layers — static source ban,
  auto-correct-on-match, throw-on-survival.
- **RULE-09 stays Pending/PARTIAL** — 28-07's migration is still outstanding.

---

## Notes for the next plan

- 28-07's measure-first checkpoint is unaffected by this plan; the counts it gathers are still
  unmeasured.
- The gate is live-reversible via `RAMS_PPE_CEILING_ELECTRICAL_GATE=false` with no redeploy,
  which matters because this milestone is validated on live production data.
