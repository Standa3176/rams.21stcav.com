# Phase 27 — Verification

**Verified:** 2026-08-26 (live, `rams.21stcav.com`, code `77f6e76`)
**Method:** real regeneration of 21CQ30960 (VW Blakelands) through `BuildRamsDocumentJob`
on production data — RAMS **100**, regenerated from source RAMS 99 (`21CQ30960-OPS`).
**Status:** ❌ **NOT PASSED — 1 of 4 ROADMAP success criteria unmet.** Do not close.
*(Updated 2026-08-26: Blocker 1 CLOSED by Plan 27-08, re-verified live on RAMS 102. Criterion 2 remains unmet — Blocker 2 is a quote-classification gap upstream of this phase.)*

---

## Criteria

| # | Criterion | Result |
|---|---|---|
| 1 | Display lift team size resolved from one shared source, producing RULE-02's bands; old ladder and aid-as-substitute wording gone | ✅ **PASS** |
| 2 | Where scope includes decommission/strip-out of a wall-mounted display, the removal-from-mount sequence is stated | ❌ **FAIL** (Blocker 2, still open) |
| 3 | GATE-09 errors on a non-conforming lift, proven by revert-and-restore | ✅ **PASS** (automated; see below for the live caveat) |
| 4 | Regenerating 21CQ30960 does not trip GATE-09 | ✅ **PASS** |

Also verified beyond the criteria: **RULE-12 confirmed fixed on the original defect.**

---

## What passed, with evidence

**Criterion 1.** Every display row resolved through `DisplayLiftPolicy`:

| Item | `min_persons` | `inches` |
|---|---|---|
| Sony 65″ FW-65BZ30L display | 2 | 65 |
| 75″ large format display — client supplied | 2 | 75 |
| 55″ large format display — client supplied | 2 | 55 |
| Sony 55″ FW-55BZ30L display | 2 | 55 |

Banned-wording scan across the whole `generated_data` payload: `minimum 4 persons`,
`minimum 4 operatives`, `persons recommended`, `panel-lift trolley`,
`Two-person lift mandatory` — all **clean**.

**Criterion 4.** `status = completed`, `error_message = null`. GATE-09 did not trip on a
real job.

**RULE-12 — the original 21CQ30960 defect, fixed.** The two rows the professional review
flagged now carry mount wording, not display wording:

- `Double-arm flat screen wall mount — 32–65″` → *"Single person lift for tilting/fixed wall mount."*
- `Tilting wall mount for 39–65″ screens` → *"Single person lift for tilting/fixed wall mount."*

Both contain a display size range and the word "screen", so both previously matched the
display branch and inherited a team-lift instruction meant for a panel.

---

## Blocker 1 — the hazard library never reaches a regenerated document (DIAGNOSIS — see closure below)

**Severity: high.** Affects RULE-02 and RULE-13 on the most common generation path.

The Manual Handling hazard in RAMS 100 rendered as:

> - Use mechanical aids (sack trucks, lifting trolleys) for items **over 20 kg**.
> - **Team lift required for screens and equipment over 40" — minimum two persons.**

That is neither the pre-Phase-27 seeded text (which said *"displays 55\" and above"*) nor
the corrected text now in `hazard_templates` (verified in the live DB the same day). It is
**stale `reviewed_data`, passed straight through.**

Cause: `RamsBuilderService::reviewedToRisk()` maps reviewed hazards 1:1 and calls
`resolveFromSeeds()` only to fill *missing* scores and controls. Plan 26-08 made it replace
controls on a **genuine rename** — but `Manual handling` already matches the canonical
library name, so no rename fires and the legacy controls survive intact.

**Consequences:**
- Every correction made to the seeded library on 2026-08-26 — the banned kg threshold, the
  corrected bands, the removed strip-out line — applies only to documents whose hazards
  resolve fresh from the library. Any RAMS regenerated from existing reviewed data keeps
  the old text. That is most real jobs.
- The surviving text is not merely stale, it **contradicts RULE-02**: *"screens and
  equipment over 40\" — minimum two persons"* is a size-conditional rule, and a 40″ display
  is a single-operative lift under the agreed bands.
- The surviving text also violates RULE-13 (`over 20 kg`), which is why the banned-wording
  scan reported `FOUND "over 20 kg"` despite all six code sites being corrected.

This is the same class of gap that reopened HAZ-02 twice in Phase 26 — a path that silently
bypasses the library. It was not caught by Phase 27's tests because every test seeds fresh
hazards rather than regenerating from legacy reviewed data.

**Open question for the fix:** whether reviewed controls should be replaced when the
library's text has changed conflicts with the codebase's "engineer values always win"
convention (HAZ-04's `score_reviewed` precedent). A house rule is not an engineer
preference, but replacing engineer-edited controls wholesale is not obviously right either.
Needs a decision before a plan.

---

## Blocker 1 — CLOSED 2026-08-26 (live, RAMS 102)

Plan 27-08 shipped and was re-verified on production against real data.

| Check | Before (RAMS 100) | After (RAMS 102) |
|---|---|---|
| `over 20 kg` in `generated_data` | `FOUND` | **`clean`** |
| Manual Handling controls | stale reviewed text | **current library text** |
| Backfill migration | — | **60 documents, 438 hazard rows** |

The Manual Handling hazard now renders the library's corrected controls, including
*"There is no fixed safe lifting weight in law — team size and aids follow the assessment,
not a kg threshold"* and the full band statement.

**Which tier fired matters, and it is the right one.** The migration backfilled those 438
rows to `controls_reviewed = true`, so tier 2 deliberately left them alone as engineer-owned.
**Tier 1** replaced the Manual Handling controls, because they breached RULE-13 (`over 20 kg`)
and RULE-02 (`screens and equipment over 40"`). That is the exact behaviour the two-tier
policy was chosen for: engineer text preserved wherever it is clean, corrected only where it
breaches a settled safety position. Both halves proven on production data rather than
fixtures.

Note the first attempt at this verification (also 2026-08-26) ran as `root`, so `git pull`
failed on dubious ownership, `migrate` reported "Nothing to migrate", and the regeneration
tested pre-27-08 code — reproducing the bug rather than testing the fix. Recorded because the
output looked like a failed verification and was not one.

---

## Blocker 2 — RULE-03's removal sequence never fires (criterion 2)

**Severity: medium.** The implementation is correct; its input is empty.

The scan for `highest-risk lift` across `generated_data` came back **clean**, while §6.6
step 3 of the same document reads *"the decommissioned 55-inch display from the former
Nadin open space"*. The job plainly has strip-out scope; the sequence is absent.

Diagnosis on RAMS 100:

```
scope_items keys: decommission, retained, new_install
  decommission: 0 item(s)
  retained:     0 item(s)
  new_install:  24 item(s)
```

Everything classified as `new_install`. `deriveMaterialHandling()`'s
`scope_items['decommission']` scan (Plan 27-02) is therefore iterating an empty array — it
is working correctly and has nothing to work on.

`_display_patched_at` is present, so `RamsDisplayPatchService` ran;
`:307-311` writes `scope_items['decommission']` only when the package's own classification
produced decommission items, and it produced none. **The gap is in quote-package
classification, upstream of everything Phase 27 touched.**

Consequence: ROADMAP criterion 2 cannot pass on 21CQ30960 — or on any job — until
`scope_items.decommission` is populated. Phase 27's RULE-03 work is not wrong, it is
unreachable.

---

## Live caveat on criterion 3

GATE-09's throw/no-throw behaviour is proven by 47 automated tests including unmocked
dual-path and Save-Review coverage, with revert-and-restore non-vacuity. It has **not** been
observed firing on live, because doing so requires deliberately entering a non-conforming
team size on a real RAMS. Criterion 3's revert-on-a-fixture proof is satisfied; a live bite
test remains available if wanted (type `Team lift — minimum 4 persons` into a scratch RAMS's
material-handling row and Save Review).

---

## Recommendation (updated 2026-08-26)

Keep Phase 27 **open on criterion 2 only**.

Criteria 1, 3 and 4 are met. RULE-12 is proven fixed against the exact defect that motivated
the milestone. Blocker 1 is closed on live evidence (RAMS 102). All of it is deployed.

**Remaining work is Blocker 2, and it is arguably not this phase's.** `scope_items.decommission`
is empty because the quote package classifies everything as `new_install`; Phase 27's RULE-03
implementation is correct and unreachable. Two honest options:

1. **Move criterion 2 out of Phase 27** into the quote-import/classification work where the
   gap actually lives, and close Phase 27 on the three criteria it owns. Preferred — leaving a
   criterion failing against code that is not at fault misrepresents both.
2. Open a plan here to populate `scope_items.decommission` from the quote package, accepting
   that it is quote-classification work wearing a Phase 27 label.

Either way this is a scope call, not an implementation one.

**Artefacts:** RAMS **100, 101, 102** on production are verification documents and can be
deleted. 101 and 102 were created by re-runs of the verification script, which sources from
the newest matching document.

---

*Verified against live production data, not fixtures, per the milestone's stated posture.*
