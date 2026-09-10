---
quick_id: 260910-qip
slug: close-phase-28-loose-ends-ai-prompt-conf
date: 2026-09-10
type: quick
---

# Quick Task 260910-qip — Close two Phase 28 loose ends

Both items were diagnosed before this task was raised; neither needs investigation.

---

## Task 1 — Stop the AI extraction prompt minting a hazard GATE-07 rejects

**Problem.** `app/Services/PromptBuilderService.php:152` (`buildFromFiles()`) tells the model to
look for *"working at height, **confined spaces**, floor penetrations, ceiling voids…"*.

Phase 28 armed GATE-07, which throws on a hazard **named** with "confined space". Phase 28
cleaned the stored corpus and the generated output, but never the upstream source that creates
*new* occurrences. A fresh extraction can therefore mint exactly the hazard name the gate then
refuses — failing the RAMS build on a brand-new project.

`28-RESEARCH.md` §Q4 flagged this file as "a live, non-legacy source of this defect" and
`28-01-PLAN.md` put a prompt-derived string in the detector's proof corpus, but no plan fixed
the prompt itself.

**Fix.** Replace `confined spaces` in the example list with `restricted access`, and add an
explicit negative instruction so the model is steered rather than merely denied a keyword —
`references/house-rules.md` §"Ceiling work" is the authority:

> Ceiling voids and comms rooms are **not** confined spaces under the Confined Spaces
> Regulations 1997. Title the hazard "Restricted access and ceiling void working". […] do not
> cite the confined-spaces ACOP (L101) for this work.

`ceiling voids` already appears in the list and stays. Do not cite L101.

**Acceptance:** `grep -ci "confined space" app/Services/PromptBuilderService.php` returns 0 for
affirmative usage; the prompt names the canonical hazard title instead.

---

## Task 2 — Retire a stale Plan 26-08 test contract

**Problem.** `tests/Unit/Services/RamsBuilderServiceTest.php::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`
has been red since before Phase 28. Plans 28-02/03/04 each correctly identified it as
pre-existing and unrelated; Phase 28's close-out re-verified that by reverting 28-01+28-03
source to `c27bfec` and re-running — it still failed there.

**It is a stale test, not a live bug.** The test asserts Plan 26-08's rule (*"controls are
gap-filled-only, not replaced, when the match is not a genuine rename"*). Plan 27-08 deliberately
**superseded** that rule — its own comment in `RamsBuilderService.php:521-525` says it
"Replaces the previous replace-on-genuine-rename rule from Plan 26-08, which is subsumed by
tier 2". The current 3-tier precedence is:

| Tier | Condition | Outcome |
|---|---|---|
| 1 | controls breach a settled house rule (`ControlTextRuleViolations::detectAll()`) | replace **regardless of author** — a house rule is a safety position, not a preference |
| 2 | `$controlsReviewed !== true \|\| empty($controls)` | replace — nobody ever edited these, the library has moved on |
| 3 | engineer edited them and they breach nothing | **their text stands** |

The fixture sets `score_reviewed => true` but **not** `controls_reviewed`, so tier 2 fires and
the library text correctly wins. Confirmed by the failure diff:

```
- 0 => 'Engineer-entered control — must survive'
+ 0 => 'Library control — should not be used'
```

Engineer controls are genuinely protected in production, because Plan 27-08 shipped the migration
that backfilled 438 hazard rows to `controls_reviewed = true`. That is why this never produced a
real complaint.

**Fix.** Split into two tests that pin the *actual* 27-08 contract, keeping the case-only rename
assertion in both:

1. `controls_reviewed => true`, controls breach nothing → engineer control **survives** (tier 3)
2. `controls_reviewed` absent → library text **wins** (tier 2)

Rewrite the docblock to cite 27-08's precedence rather than 26-08's superseded rule.

**Acceptance:** both new tests pass; the full suite's failure count drops from 2 to 1.

---

## Constraints

- **Do not touch `config/rams_tier1.php:286`** — Phase 31 owns its fire-stop wording (D-09).
- Do not change `RamsBuilderService.php`. Task 2 is a **test** fix; the production behaviour is
  correct as designed.
- Baseline before this task: **2442 passed / 2 failed**. The other failure
  (`QueueRecoverCommandTest:163`) is unrelated and explicitly out of scope — Phase 28 touched zero
  queue files.
- Push to remote `live` when done.
