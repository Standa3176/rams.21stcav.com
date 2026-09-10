---
quick_id: 260910-s2l
slug: state-face-fit-testing-wherever-ffp3-res
date: 2026-09-10
status: complete
---

# Quick Task 260910-s2l — State face-fit testing wherever FFP3 is specified in prose

Completes the second half of RULE-01. Phase 28 delivered the respirator-grade swap and shipped a
static ban to hold it; the face-fit clause was never held by anything.

---

## The scope was wider than reported

Phase 28's close-out flagged this as "two fallback strings in
`RamsComplianceUpgradeService`". A sweep of every FFP3 mention in live source showed that was
understated: **face-fit was stated in exactly one place** — `HazardTemplateSeeder.php:294` — while
**six** prose sites specified respiratory PPE with no face-fit clause at all.

`references/house-rules.md` §"Respiratory protection" says plainly: *"Specify face-fit testing."*
ROADMAP criterion 1 requires that face-fit "is stated". On a job whose hazard set did not include
the seeder's drilling hazard, the generated document specified FFP3 and never mentioned face-fit.

## Sites changed (6)

| File | Was |
|---|---|
| `app/Services/Rams/RamsComplianceUpgradeService.php:760` | ceiling-void control |
| `app/Services/Rams/RamsComplianceUpgradeService.php:809` | drilling/cutting control |
| `app/Services/RiskTemplateResolverService.php:521` | wall-chase hazard text |
| `app/Services/DocxBuilderService.php:2038` | COSHH boilerplate (**live DOCX renderer**) |
| `resources/views/pdf/rams.blade.php:1903` | same sentence, PDF |
| `resources/views/pdf/rams-v2.blade.php:1964` | same sentence, PDF v2 |

All aligned to the seeder's existing phrasing — *"All operatives face-fit tested."* — rather than
inventing a seventh wording.

`RiskMatrixService.php:133` is among these and is **dead code** (zero callers, confirmed twice
during Phase 28). Changed anyway so the new guard holds for the whole surface; no verification
effort spent on it.

## Deliberately NOT changed

- **`config/rams_tier1.php:152`.** The sweep flagged it, but it sits inside the
  **`Expanding Foam — cable-penetration fire-stop`** COSHH entry — the exact block **Phase 31
  owns** for RULE-11. Phase 28's D-09 moved that requirement out and recorded in three places that
  the entry is not to be edited before Phase 31. Excluded for that reason, not missed.
- **PPE pick-list labels** (`'Dust Mask (FFP3)'` in both controllers, `PpeVocabularyFoldMap`,
  `RiskTemplateResolverService:44/106/523`, `RamsComplianceUpgradeService:362/374`, the blades'
  `dust masks (FFP3)` line). These are vocabulary entries rendered as list items, not statements.
  Appending a testing regime to a dropdown label would be wrong.

## Guard added

`tests/Feature/Rams/FaceFitStatedWithFfp3GuardTest.php` — scans seven document-content source
files; any line mentioning FFP3 that is not an excluded label or GATE-06 error copy must state
face-fit on the same line.

**Fails toward flagging by design.** "Prose" is decided by exclusion, not by parsing, so a new
FFP3 sentence in an unanticipated shape trips the guard rather than slipping past. The cost is
that a genuinely new label form needs a marker added — a visible, reviewable edit.

**Verified non-vacuous:** reverting `RiskMatrixService.php` alone makes it fail; restoring makes
it pass.

A second test anchors the seeder's canonical phrasing, so a path typo in `SCANNED_FILES` that
silently stopped the scan finding anything would still fail loudly.

## Two things the existing guards caught during this work

1. **The guard's first run flagged two false positives** — fragments of GATE-06's own
   `RamsGenerationException` remediation text (*"replace it with the FFP3 equivalent before
   regenerating…"*). Operator-facing error copy, never document content. Added as an exclusion
   marker with the reason recorded.
2. **`FfpTwoBannedFromSourceTest` failed on the new guard's own docblock**, which referenced the
   banned token while explaining the history. Reworded to avoid the literal rather than widening
   the exclusion list — that list should hold only files that need the token as their *mechanism*.
   Phase 28's static ban doing exactly its job on a file written three weeks later.

## Stored-data note (deliberate, no migration)

Sites `:760` and `:809` are the two strings migration `2026_09_07_090000` wrote into 34 production
documents. Changing the source now means those stored controls carry the shorter sentence until a
regeneration refreshes them (tier 2, for controls nobody has reviewed).

**No migration shipped, deliberately.** A missing face-fit clause is an *incompleteness*, not a
contradiction — unlike the FFP2 text, which was a direct house-rule breach and therefore warranted
one. Documents also state face-fit via the seeder's hazard whenever the drilling hazard fires. If
a future audit disagrees, the fix is a third literal-replace migration in the shape of
`2026_09_07_090000`.

## Verification

| Suite | Result |
|---|---|
| `--filter=FaceFitStatedWithFfp3GuardTest` | 2 passed |
| `--filter=Rams` | **658 passed, 0 failed** (2555 assertions) |

Baseline entering this task was 656 passed / 0 failed.
