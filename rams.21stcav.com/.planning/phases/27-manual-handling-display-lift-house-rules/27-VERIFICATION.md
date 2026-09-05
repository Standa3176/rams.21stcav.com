# Phase 27 — Verification

**Verified:** 2026-08-26 (live, `rams.21stcav.com`, code `77f6e76`)
**Method:** real regeneration of 21CQ30960 (VW Blakelands) through `BuildRamsDocumentJob`
on production data — RAMS **100**, regenerated from source RAMS 99 (`21CQ30960-OPS`).
**Status:** ✅ **PASSED — 4 of 4 ROADMAP success criteria met** (criterion 2 restated 2026-08-26).
*(Updated twice on 2026-08-26: Blocker 1 CLOSED by Plan 27-08, re-verified live on RAMS 102. Criterion 2 then RESTATED to the behaviour Phase 27 owns — the original wording made the phase's pass/fail depend on quote-package classification, which it does not build; that gap is now tracked as DATA-01.)*

---

## Criteria

| # | Criterion | Result |
|---|---|---|
| 1 | Display lift team size resolved from one shared source, producing RULE-02's bands; old ladder and aid-as-substitute wording gone | ✅ **PASS** |
| 2 | Where `scope_items.decommission` contains a wall-mounted display, the removal-from-mount sequence is stated — and does not appear on an installation-only job | ✅ **PASS** (criterion restated; see Blocker 2) |
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

## Blocker 2 — RESOLVED BY RESTATING CRITERION 2 (2026-08-26), gap raised as DATA-01

The original criterion made Phase 27's pass/fail depend on quote-package classification —
something the phase does not build and cannot fix. Restated to the behaviour Phase 27 owns:

> Where `scope_items.decommission` contains a wall-mounted display, the removal-from-mount
> sequence is stated — and does not appear on an installation-only job.

That is built, deployed and proven both directions:
`RamsComplianceUpgradeServiceDisplayLiftTest` covers *decommission display item gets wall
mount removal statement* and *decommission non-display item has no wall mount removal
statement*; the seeder test added during the skill re-sync proves the statement is absent from
the unconditional control list, so it cannot appear on an installation-only job.

**The underlying gap is real and is now tracked as DATA-01** in `REQUIREMENTS.md` Group E:
`scope_items.decommission` is never populated, so RULE-03's sequence — and the
*Decommissioning and WEEE* hazard's `signal:strip_out_or_decommission` include-when — can
never fire on a live job. That belongs with the quote-import work.

The original diagnosis is retained below.

### Original diagnosis (retained)

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

## Recommendation (final, 2026-08-26)

**Close Phase 27.** All four criteria are met, everything is deployed, and the milestone's
motivating defect — mount rows wearing a display's team-lift wording on 21CQ30960 — is proven
fixed against real production data.

Delivered: RULE-02 (banded team sizes from one shared source), RULE-03 (removal sequence,
conditional), RULE-12 (branch-order fix; weight-derivation clause explicitly deferred),
GATE-09 (all three generation entry points plus the live PDF render), and RULE-13 (kg
threshold), which was recovered mid-phase from the skill re-sync.

Carried forward, both tracked and neither blocking:

- **DATA-01** — `scope_items.decommission` is never populated, so RULE-03's sequence cannot
  fire on a live job. Quote-import work.
- **RULE-12's weight clause** — no live `weight_kg` data reaches the RAMS path.

**Artefacts:** RAMS **100, 101, 102** on production are verification documents and can be
deleted.

---

*Verified against live production data, not fixtures, per the milestone's stated posture.*
