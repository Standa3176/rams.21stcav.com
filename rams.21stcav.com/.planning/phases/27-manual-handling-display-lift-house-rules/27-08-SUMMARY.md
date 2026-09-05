# Plan 27-08 — Summary

**Completed:** 2026-08-26
**Requirements:** RULE-02, RULE-13
**Status:** code complete, **live re-verification OUTSTANDING** (see below)

Closes Blocker 1 from `27-VERIFICATION.md`: the hazard library's control text never reached
a RAMS regenerated from existing reviewed data.

---

## What shipped

**Task 1 — `app/Services/Rams/ControlTextRuleViolations.php`.** A detector registry for
house-rule breaches in free-text control lines. Two detectors: `kg_threshold` (RULE-13) and
`size_conditional_lift` (RULE-02), with an ordered `DETECTORS` list as the extension point for
Phase 28's `ffp2` / `confined_space` and Phase 31's `coshh_wrong_category`.

**Task 2 — two-tier precedence in `reviewedToRisk()`**, plus a `controls_reviewed` marker
threaded through all five interfaces `score_reviewed` already travels
(`RamsReviewController`, `RamsDataBuilderService`, `RamsReviewDataService`,
`RamsExtractionDraftBuilderService`, and the review blade). Precedence, first match wins:

1. controls breach a known house rule → library wins, `controls_replaced_reason` recorded
2. `controls_reviewed !== true` → library wins (no human intent to protect)
3. otherwise → the engineer's text stands

The previous replace-on-genuine-rename rule (Plan 26-08) was **removed**, not left alongside:
it is subsumed by tier 2, since a renamed row has by definition never had its controls
reviewed under the canonical name. Its docblock was rewritten to match.

**Task 4 — `2026_08_26_170000_backfill_controls_reviewed_on_reviewed_hazards.php`.** Sets
`controls_reviewed = true` on every existing reviewed hazard lacking the key, chunked, with
audit output. Never overwrites an explicit value. `down()` is a documented no-op — it cannot
distinguish a row it marked from one marked later through the review form.

**Task 3 — tests.** `ControlTextRuleViolationsTest` (9 tests) and
`ReviewedControlRefreshTest` (7 tests, real seeded DB, real `HazardLibraryService`).

---

## Decisions and deviations

**The provenance mechanism mirrors `score_reviewed` rather than hashing.** The codebase had
already answered this exact question for scores — *"No human ever touched this score through
the numeric UI (score_reviewed false or absent) — the library's typical value wins outright."*
Controls now follow the identical rule. This avoids storing library-version hashes and keeps
one comprehensible convention.

**Legacy documents are backfilled to `true` (user decision, 2026-08-26).** Mirroring
`score_reviewed` naively would treat every pre-existing document as never-reviewed and replace
its controls wholesale on the next regeneration, discarding site-specific wording engineers
typed by hand. The backfill prevents that. **It does not weaken the fix** — the live 21CQ30960
defect is corrected by tier 1, which fires on a rule breach regardless of the marker. Rejected
alternative: no backfill (no stale text survives anywhere, at the cost of irreversible loss of
engineer controls per document).

**`DisplayLiftPolicySourceGuardTest`'s allow-list gained `ControlTextRuleViolations.php`
— deliberately.** The guard correctly flagged the new file for referencing
`DisplayLiftPolicy::`. That reference is the right behaviour:
`detectSizeConditionalLift()` decides whether a control line states a team size that
*disagrees with the policy*, so it must ask the policy. Re-encoding the bands to avoid
tripping the guard would be exactly the divergence the guard exists to prevent. The
allow-list entry carries that reasoning inline.

**The 27-06 free-text parsers now have exactly one implementation.**
`parseStatedTeamSize()` / `parseStatedInches()` moved onto `ControlTextRuleViolations`;
`RamsComplianceUpgradeService`'s versions became thin private forwarders, preserving its
call sites and visibility contract. Without this the gate and the detectors could have
drifted on what a control line says.

**Executor interruption.** The subagent assigned this plan was cut off mid-Task-1 by a spend
limit, leaving `ControlTextRuleViolations.php` uncommitted and Tasks 2–4 untouched. Its Task 1
output was reviewed against every acceptance criterion, verified, and committed by hand
(`dd3ca32`); Tasks 2–4 were completed directly.

---

## Verification

| Check | Result |
|---|---|
| `ControlTextRuleViolationsTest` | 9 passed, 32 assertions |
| `ReviewedControlRefreshTest` | 7 passed, 26 assertions |
| Self-check: 98 control lines across the 18 seeded hazards | 0 flagged |
| Self-check: every `DisplayLiftPolicy` sentence | 0 flagged |
| Tier 1 non-vacuity (disabled → restored) | **2 failed → 7 passed** |
| Tier 2 non-vacuity (disabled → restored) | **2 failed → 7 passed** |
| Migration audit output (fresh test DB) | `0 document(s), 0 hazard row(s)` — correct, nothing to backfill |

The self-checks are load-bearing, not decoration: a false positive silently overwrites an
engineer's deliberate wording on a live safety document (T-27-08-01, HIGH). If the detectors
can flag the app's own library output, they will flag correct engineer text too.

---

## ⚠ Live re-verification is OUTSTANDING

**This plan is not done and RULE-02/RULE-13 are not closed on the reviewed-data path until
21CQ30960 is regenerated on production and the Manual Handling hazard is confirmed to carry
library text.** HAZ-02 was closed prematurely twice in Phase 26 on automated evidence alone;
the same mistake is available here.

Post-deploy steps:

```bash
su - stcav
cd /home/stcav/rams.21stcav.com.git/rams.21stcav.com/
git pull origin feat/worksheet-classifier-universal
php artisan migrate --force          # the backfill; note its audit line
php artisan optimize:clear
php artisan tinker /tmp/verify27.php # regenerate 21CQ30960
```

Expected in the banned-wording scan: `clean "over 20 kg"` — it read `FOUND` on 2026-08-26.
The migration's audit line reports how many live documents were backfilled; record it.

---

## Files

- `app/Services/Rams/ControlTextRuleViolations.php` (new)
- `app/Services/RamsBuilderService.php`
- `app/Services/Rams/RamsComplianceUpgradeService.php` (parsers → forwarders)
- `app/Http/Controllers/RamsReviewController.php`
- `app/Services/RamsDataBuilderService.php`
- `app/Services/RamsReviewDataService.php`
- `app/Services/RamsExtractionDraftBuilderService.php`
- `resources/views/rams/quote-review.blade.php`
- `database/migrations/2026_08_26_170000_backfill_controls_reviewed_on_reviewed_hazards.php` (new)
- `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` (new)
- `tests/Feature/Rams/ReviewedControlRefreshTest.php` (new)
- `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php` (allow-list)
