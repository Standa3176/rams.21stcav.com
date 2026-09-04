# Phase 27 — Verification

**Verified:** 2026-08-26 (live, `rams.21stcav.com`, code `77f6e76`)
**Method:** real regeneration of 21CQ30960 (VW Blakelands) through `BuildRamsDocumentJob`
on production data — RAMS **100**, regenerated from source RAMS 99 (`21CQ30960-OPS`).
**Status:** ❌ **NOT PASSED — 2 of 4 ROADMAP success criteria unmet.** Do not close.

---

## Criteria

| # | Criterion | Result |
|---|---|---|
| 1 | Display lift team size resolved from one shared source, producing RULE-02's bands; old ladder and aid-as-substitute wording gone | ✅ **PASS** |
| 2 | Where scope includes decommission/strip-out of a wall-mounted display, the removal-from-mount sequence is stated | ❌ **FAIL** |
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

## Blocker 1 — the hazard library never reaches a regenerated document

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

## Recommendation

Keep Phase 27 **open**. Criteria 1, 3 and 4 are met and the RULE-12 fix is proven against
the exact defect that motivated the milestone — that is real progress and it is deployed.
But closing now would record criterion 2 as met when it has never once fired, and would
leave the library-bypass hidden behind a green test suite.

Two follow-on plans, in this order:

1. **27-08 — reviewed-data control refresh.** Decide and implement when library controls
   supersede reviewed ones. Blocker 1 is the higher severity: it silently defeats every
   house-rule correction on the common path, and it will defeat Phases 28 and 31's
   corrections too, so fixing it before those phases ship is worth more than fixing it after.
2. **27-09 (or its own phase) — decommission classification.** Populate
   `scope_items.decommission` from the quote package. Likely belongs with the quote-import
   work rather than here; if so, criterion 2 should be moved out of Phase 27 explicitly
   rather than left failing.

**Artefact:** RAMS **100** on production is a verification document and can be deleted.

---

*Verified against live production data, not fixtures, per the milestone's stated posture.*
