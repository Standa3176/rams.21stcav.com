---
quick_id: 260910-qip
slug: close-phase-28-loose-ends-ai-prompt-conf
date: 2026-09-10
status: complete
---

# Quick Task 260910-qip — Summary

Closed the two loose ends left open when Phase 28 shipped. Both were diagnosed before the task
was raised; neither needed investigation.

---

## Task 1 — AI extraction prompt no longer offers "confined spaces"

`app/Services/PromptBuilderService.php` `buildFromFiles()` told the model to look for
*"working at height, **confined spaces**, floor penetrations, ceiling voids…"*.

Phase 28 armed GATE-07, which throws on a hazard **named** with "confined space". Phase 28 fixed
the stored corpus and the generated output but never this — the upstream source that mints *new*
occurrences. A fresh extraction could therefore produce exactly the hazard name the gate then
refuses, failing the RAMS build on a brand-new project.

**Changed:** `confined spaces` → `restricted access` in the example list, plus an explicit
positive instruction:

> Ceiling voids, comms rooms and risers are NOT confined spaces under the Confined Spaces
> Regulations 1997 — never label them as such, and never cite the confined-spaces ACOP. Where
> access is restricted, title the hazard "Restricted access and ceiling void working".

**Why steer rather than just delete the keyword.** Removing "confined spaces" alone would leave
the model free to invent the label from a drawing anyway — the phrase is standard construction
vocabulary. Stating 21CAV's position is what actually prevents it, and it mirrors what
`ControlTextRuleViolations::detectConfinedSpace()` does. The two remaining occurrences of the
phrase in that file are both **negations**, which the detector is built to treat as clean, so the
prompt cannot trip its own gate.

`references/house-rules.md` §"Ceiling work" is the authority for the wording. No test asserts the
prompt's text, so nothing was coupled to the old string.

---

## Task 2 — Retired a stale Plan 26-08 test contract

`RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`
had been red since before Phase 28. Plans 28-02/03/04 each identified it as pre-existing;
Phase 28's close-out re-verified that by reverting 28-01+28-03 source to `c27bfec` and re-running.

**It was a stale test, not a live bug.** It asserted Plan 26-08's rule (*"controls are
gap-filled-only, not replaced"*), which Plan 27-08 deliberately superseded — its own comment at
`RamsBuilderService.php:521-525` says it "Replaces the previous replace-on-genuine-rename rule
from Plan 26-08, which is subsumed by tier 2". The fixture set `score_reviewed` but not
`controls_reviewed`, so tier 2 correctly fired and the library text won:

```
- 0 => 'Engineer-entered control — must survive'
+ 0 => 'Library control — should not be used'
```

Engineer controls are genuinely protected in production, because Plan 27-08 shipped the migration
backfilling 438 hazard rows to `controls_reviewed = true` — real edited rows carry the marker and
land in tier 3.

**Changed:** split into two tests pinning both halves of the 27-08 boundary, sharing one fixture
helper, with the case-only rename assertion kept in both:

| Test | Fixture | Asserts |
|---|---|---|
| `..._keeps_reviewed_controls` | `controls_reviewed => true` | tier 3 — engineer text stands |
| `..._refreshes_unreviewed_controls_from_library` | marker absent | tier 2 — library refreshes |

The second uses the *exact* fixture the old test used and asserts the opposite outcome, which is
the point: the contract changed, and now that change is recorded rather than sitting red.

Docblock rewritten to cite 27-08's 3-tier precedence and to explain why the 26-08 assertion was
abandoned, so this cannot silently rot again.

---

## Verification

| Suite | Before | After |
|---|---|---|
| `--filter=RamsBuilderServiceTest` | 16 passed, **1 failed** | **17 passed**, 0 failed |
| `--filter=Rams` | 649 passed, 1 failed | **656 passed, 0 failed** (2546 assertions) |

**On `QueueRecoverCommandTest`** — recorded as the "other" full-suite failure at Phase 28
close-out. It **passes in isolation** (4 passed, 11 assertions). It is therefore
order/environment-dependent, hitting a memory-limit path only under full-suite conditions, not a
hard failure; its own inline comment documents that (`EXIT_MEMORY_LIMIT` conflated with
`EXIT_RECOVERED`, deferred by an earlier quick task). Unrelated to this work either way, and the
Phase 28 characterisation of it as a flat failure is corrected here.

A full unfiltered suite run was started but ran concurrently with the `--filter=Rams` run above;
both use `RefreshDatabase` against the same test database, so its result is not trustworthy and
is not quoted. The two targeted runs above are the evidence.

---

## Not touched

- `config/rams_tier1.php:286` — Phase 31 owns its fire-stop wording (D-09).
- `RamsBuilderService.php` — Task 2 was a **test** fix; the production behaviour is correct as
  Plan 27-08 designed it.
- Face-fit wording on `RamsComplianceUpgradeService.php:760`/`:809` — still open, still a content
  decision rather than a defect (RULE-01 is satisfied via the library path).
