---
phase: 29-cdm-duty-holder-emergency-arrangements
verified: 2026-09-12T00:00:00Z
status: gaps_found
score: 7/8 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 4/8
  gaps_closed:
    - "GATE-12's plausibility classifier never flags the D-05 hold-point line as a defect (CR-01)"
    - "The backfill never overwrites a row where an engineer already typed a real duty-holder name in reviewed_data['cdm'] (CR-02)"
    - "All five render sites read the SAME resolved value — rams.blade.php now self-sufficient (WR-01)"
  gaps_remaining:
    - "A live occupied-premises project regeneration is manually verified against production data by visual PDF/DOCX inspection (ROADMAP criterion 4)"
  regressions: []
gaps:
  - truth: "A live occupied-premises project regeneration is manually verified against production data, not just fixtures (ROADMAP criterion 4)"
    status: failed
    reason: "Deploy (429fdfd..38eb41d) and the Plan 29-05 backfill migration (46/54 rows, exactly matching the 29-01 measurement) both ran and were verified live on production 2026-09-11. The visual-inspection half — opening a regenerated live project's PDF and DOCX and confirming the CDM wording, Section 7.0 A&E row, and Welfare bullet pointer — has still NOT been performed. 29-06-SUMMARY.md and 29-MEASUREMENT.md both explicitly record this as outstanding, and none of Plans 29-07/29-08/29-09 (the gap-closure plans) touched this step — they fixed code defects (CR-01, CR-02, WR-01), not the live-verification task."
    artifacts: []
    missing:
      - "Open a regenerated live occupied-premises project's PDF and DOCX on rams.21stcav.com and visually confirm: (1) CDM 2015 Duty Holders never shows '[To be confirmed]' for PD/PC, (2) Section 7.0 A&E row shows a verified name+address or the exact hold-point line, never 'TBC' or the banned string, (3) the Welfare First Aid bullet points to Section 7.0"
human_verification:
  - test: "Open a regenerated live occupied-premises project's PDF and DOCX on rams.21stcav.com"
    expected: "CDM 2015 Duty Holders table states the anticipated-sole-contractor position (never '[To be confirmed]' for Principal Designer/Principal Contractor); Section 7.0's Nearest A&E row shows either a verified name+address+postcode or the exact hold-point line (never 'TBC', never 'to be identified at site induction'); the Welfare First Aid bullet points to Section 7.0 rather than repeating A&E text"
    why_human: "Requires opening a real rendered document on the live VPS and reading it — not verifiable by grep/test; this is ROADMAP Phase 29 success criterion 4's own explicit 'verified against production data, not just a fixture' requirement, and is recorded by the phase's own SUMMARY.md as not yet performed"
---

# Phase 29: CDM Duty-Holder & Emergency Arrangements Verification Report (Re-Verification)

**Phase Goal:** Replace the unconditional CDM duty-holder placeholder and the hardcoded "to be identified at site induction" A&E line with the settled positions, and ship GATE-11/GATE-12 so a RAMS can no longer go out the door with either placeholder.
**Verified:** 2026-09-12
**Status:** gaps_found
**Re-verification:** Yes — after gap closure (Plans 29-07, 29-08, 29-09)

## Goal Achievement

### Gap Closure — Direct Code Verification

All three code-defect gaps from the prior VERIFICATION.md were re-checked directly against the live codebase, not against SUMMARY.md claims.

**Gap 1 (CR-01, `SiteEmergencyResolver::classify()` false-positive on HOLD_POINT) — CLOSED, confirmed.**
`app/Services/Rams/SiteEmergencyResolver.php:108` now reads `if ($name === '' || $name === self::HOLD_POINT) { return null; }`. Direct `tinker` invocation performed independently of the test suite:

```
classify(['nearest_hospital' => HOLD_POINT literal, 'hospital_address' => ''])       → NULL
classify(['nearest_hospital' => 'St Thomas Urgent Care', 'hospital_address' => '1 Road']) → 'urgent_care_keyword'
classify(['nearest_hospital' => 'Real Hospital', 'hospital_address' => ''])           → 'missing_address_or_postcode'
```

The HOLD_POINT literal now passes clean, and — critically — no previously-flagged defect (urgent-care keyword, missing address) became a false negative as a side effect of widening the early-return. The fix is additive-only, as claimed.

**Gap 2 (CR-02, loose substring guard in migration) — CLOSED, confirmed.**
`database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` no longer contains `str_contains($name`. The `reviewed_data['cdm']` branch (`:210-215`) now uses `in_array($name, self::NAME_EXACT_VARIANTS, true)` against an enumerated allowlist (`'To be confirmed'`, `'[To be confirmed]'`, `:94-99`) — a genuine PM-authored sentence merely containing the substring (e.g. "PD to be confirmed once client appoints one") no longer matches. The `generated_data['cdm_duty_holders']` branch (already exact-literal) is untouched.

**Gap 3 (WR-01, `rams.blade.php` blank A&E fallback) — CLOSED, confirmed.**
`resources/views/pdf/rams.blade.php:1983` now reads:
```php
{{ $data['site_emergency_resolved']['text'] ?? \App\Services\Rams\SiteEmergencyResolver::resolve($siteEmerg)['text'] }}
```
A missing `site_emergency_resolved` key now degrades to the resolver's hold-point/verified text instead of blank output. `grep -n "site_emergency\['nearest_hospital'\] ="` against the file returns zero matches, confirming the fallback is read-only and does not write back into `$siteEmerg`, `$data`, or the model — matching the plan's stated acceptance criterion.

**Gap 4 (ROADMAP criterion 4, live visual PDF/DOCX inspection) — STILL NOT DONE.**
None of Plans 29-07/29-08/29-09 touched this item; all three were scoped as targeted code-defect fixes. `29-06-SUMMARY.md` and `29-MEASUREMENT.md` both still record the visual-inspection step as outstanding, and no later plan or summary in this phase claims it was performed. This gap **remains open** and is not marked passed.

### Regression Check

Full RAMS suite re-run directly (not taken from SUMMARY claims): `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` → **328 passed, 1517 assertions, 63.87s**. Matches 29-09-SUMMARY.md's reported count exactly. No regressions found.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | RULE-08 restated (two-branch, verified-or-hold-point wording) recorded in REQUIREMENTS.md with a RESTATED note | ✓ VERIFIED | `.planning/REQUIREMENTS.md:88` — unchanged from prior verification, re-confirmed present |
| 2 | ROADMAP Phase 29 criterion 2 states the D-05 verified-or-hold-point position, no "by default" named-A&E claim | ✓ VERIFIED | `.planning/ROADMAP.md:189` — unchanged, re-confirmed present |
| 3 | CDM duty-holder table replaces the unconditional `[To be confirmed]` for PD/PC with restated wording on every job | ✓ VERIFIED | `RamsComplianceUpgradeService::addCdmDutyHolders()` unchanged since prior verification; full suite green |
| 4 | The banned passive string and the `'TBC'` A&E fallback are structurally gone from every real render site, and no render site independently re-derives the branch (D-01) | ✓ VERIFIED (caveat resolved) | Banned string/`'TBC'` confirmed gone (unchanged). The prior reachability caveat (rams.blade.php's blank-fallback bypass via `RamsRegenerateSnapshotsCommand`) is now closed by Gap 3's fix — the blade always resolves through `SiteEmergencyResolver`, directly or via the upstream key |
| 5 | GATE-12's plausibility classifier never flags the D-05 hold-point line as a defect (D-08 conservative-by-construction) | ✓ VERIFIED | Direct `tinker` invocation confirms `classify()` on the HOLD_POINT literal returns `null`; regression tests added; no side-effect false negatives introduced |
| 6 | The backfill never overwrites a row where an engineer already typed a real duty-holder name (D-02) | ✓ VERIFIED | `reviewed_data['cdm']` branch confirmed using exact-literal `in_array()` against an enumerated allowlist, not `str_contains()` |
| 7 | All five render sites read the SAME resolved value — none re-derives the verified-vs-hold-point branch independently (D-01) | ✓ VERIFIED | `rams.blade.php` now falls back to `SiteEmergencyResolver::resolve()` directly rather than blank; `rams-v2.blade.php`/`DocxBuilderService` unchanged and already correct; fallback confirmed read-only (no write-back) |
| 8 | Both gates disarmed by default; a live occupied-premises regeneration is manually verified against production data, not just a fixture (ROADMAP criterion 4) | ✗ FAILED (partially met) | Disarm confirmed: `config/rams_tier1.php:134` — `env('RAMS_CDM_AE_GATE', false)`. Deploy + backfill verified live 2026-09-11. Visual PDF/DOCX inspection — explicitly required by criterion 4's "not just a fixture" wording — still NOT performed |

**Score:** 7/8 truths verified (up from 4/8). Only the live visual-verification half of criterion 4 remains outstanding.

### ROADMAP Success Criteria — Explicit Ruling

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | CDM duty-holder defaults state the settled sole-Contractor position on every job | ✓ MET | `addCdmDutyHolders()` unconditional application, forbidden-statement-free wording, 13-test `CdmDutyHolderWordingTest` green |
| 2 | Emergency Procedures A&E line replaced by verified-name-or-hold-point resolver, never a guessed name (restated per D-05/D-06) | ✓ MET | `SiteEmergencyResolver::resolve()` two-branch logic; all five render sites confirmed delegating (Gap 3 closure removes the last independent-derivation risk); verified against the **restated** wording, per the orchestrator's instruction that criterion 2 was deliberately rewritten this phase |
| 3 | GATE-11 errors on `[To be confirmed]` surviving to an occupied-premises job; GATE-12 errors on an implausible named A&E | ✓ MET (code-complete, correctly disarmed) | `enforceCdmGate()`/`enforceEmergencyGate()` both implemented, both reachable on the real Save-Review → download path (`CdmEmergencyDualPathGateTest`, 3 tests/13 assertions), CR-01's false-positive risk now closed so arming will not misfire on the gate's own sanctioned output. Shipping disarmed is this phase's own intended end-state (D-03) — "errors when armed" is proven by test, not by production enforcement, which is deliberately deferred |
| 4 | Regenerating a live occupied-premises project shows the stated CDM position and correct A&E, verified against production data, not just a fixture | ✗ NOT MET | Deploy + backfill (the data half) verified live 2026-09-11, exactly matching the 46/54 measurement. The visual open-and-read half of this criterion has not been performed by anyone in this phase to date |

**3 of 4 ROADMAP criteria are fully met. Criterion 4 remains unmet** — this is a real, unresolved gap, not a documentation lag; it requires a human to open documents on the live VPS, something no agent in this phase's history has yet done.

### Requirement-Status Inconsistency — Ruling

**Question:** GATE-11 was marked `[x]` Complete in REQUIREMENTS.md (by 29-08's executor) while GATE-12 was left `[ ]` Pending (by 29-07's executor, reasoning arming is still outstanding). Both gates are implemented, tested, and shipped disarmed. Which is correct?

**Finding:** `RamsComplianceUpgradeService::upgrade()` gates BOTH `enforceCdmGate()` (GATE-11) and `enforceEmergencyGate()` (GATE-12) behind the **exact same single config flag** — `config('rams_tier1.cdm_ae_gate_enabled', false)` (`:97-99`). There is no way for GATE-11 to be in a more "armed" or "complete" state than GATE-12; they are switched by one boolean, together, and that boolean defaults `false`.

**Precedent check:** GATE-06/07 (`ffp2_confined_space_gate_enabled`) and GATE-09 (`display_lift_gate_enabled`) were marked `[x]` Complete in REQUIREMENTS.md, and their flags default `true` (`config/rams_tier1.php:74,98`) — i.e., this project's own established convention is that a gate earns "Complete" status once it is **armed and enforcing in production**, not merely code-complete-and-tested-but-disarmed. GATE-11/GATE-12's shared flag (`:134`) defaults `false` — deliberately, per D-03, pending the still-outstanding visual verification.

**Ruling: GATE-12's `[ ]` Pending status is correct. GATE-11's `[x]` Complete status was wrong** — it does not match this project's own precedent for what "Complete" means for a gate requirement, and it cannot be more complete than GATE-12 given they share one flag. This is not a case where "ship disarmed" satisfies the requirement's own "errors when..." wording for GATE-11 but not GATE-12 — the wording and the flag are identical for both.

**Correction applied to REQUIREMENTS.md** (this verification, both files):
- Line 72: GATE-11 checkbox changed `[x]` → `[ ]`, with an inline note explaining the correction, the shared-flag finding, and the precedent reasoning.
- Line 164 (traceability table): GATE-11 status changed `Complete` → `Pending (corrected 2026-09-12 — code-complete, disarmed; shares `cdm_ae_gate_enabled` flag with GATE-12)`.

RULE-07/RULE-08 (`[x]` Complete, lines 87-88, 174-175) are unaffected by this ruling — they are wording requirements verified structurally in the rendered output regardless of gate arming state, and were not part of the requirement-status question.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Rams/SiteEmergencyResolver.php` | `resolve()`/`classify()` per D-05/D-08, no false-positive on own output | ✓ VERIFIED | CR-01 fix confirmed live via direct invocation; 17 unit tests including 2 new HOLD_POINT-literal regression cases |
| `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` | Idempotent, dual-column, exact-literal-guarded backfill | ✓ VERIFIED | CR-02 fix confirmed — `reviewed_data['cdm']` now exact-literal-enumerated, not substring; `generated_data` branch unchanged (already correct); already ran live 2026-09-11 (46/54 rows, matches measurement) |
| `resources/views/pdf/rams.blade.php` | Self-sufficient A&E cell, never blank, never independently re-derived | ✓ VERIFIED | WR-01 fix confirmed — fallback to `SiteEmergencyResolver::resolve()` on missing upstream key, read-only, no write-back |
| `config/rams_tier1.php` | `cdm_ae_gate_enabled` disarmed, own flag, shared by GATE-11+GATE-12 | ✓ VERIFIED | `:134` — `env('RAMS_CDM_AE_GATE', false)`; confirmed both `enforceCdmGate()` and `enforceEmergencyGate()` gated by this one key |
| `.planning/REQUIREMENTS.md` | Internally consistent GATE-11/GATE-12 status | ✓ CORRECTED THIS VERIFICATION | GATE-11 changed from `[x]` Complete to `[ ]` Pending in both the checklist (line 72) and traceability table (line 164), reconciling it with GATE-12's already-correct Pending status |

### Requirements Coverage

| Requirement | Description | Status | Evidence |
|-------------|-------------|--------|----------|
| RULE-07 | CDM duty-holder anticipated-sole-contractor wording | ✓ SATISFIED | Unchanged from prior verification — wording forbidden-statement-free, code + backfill implemented, tested, deployed |
| RULE-08 | Two-branch verified-or-hold-point A&E wording | ✓ SATISFIED (upgraded from PARTIAL) | Prior verification recommended not closing RULE-08 unconditionally until CR-01/WR-01 fixed — both are now fixed and confirmed; the "single source of truth, never re-derived" design guarantee now holds structurally |
| GATE-11 | CDM placeholder-survival throwing re-check | ✗ BLOCKED (status corrected this verification) | Code-complete, tested, deployed, but disarmed — see Requirement-Status Inconsistency ruling above. REQUIREMENTS.md corrected from `[x]` to `[ ]` to match |
| GATE-12 | A&E plausibility throwing re-check | ✗ BLOCKED | Code-complete, tested, deployed, disarmed; CR-01's false-positive risk (which would have misfired the moment the gate is armed) is now closed |

### Anti-Patterns Found

No new anti-patterns introduced by Plans 29-07/29-08/29-09. The two prior BLOCKER-level anti-patterns (CR-01, CR-02) are resolved. The two prior WARNING-level items:

| File | Line | Pattern | Severity | Status |
|------|------|---------|----------|--------|
| `app/Services/DocxBuilderService.php` | 1706-1707 | `??` fallback doc-commented as "defence-in-depth" but does not guard the known-bad `'[To be confirmed]'` literal already persisted pre-Phase-29 (WR-02) | ⚠️ Warning | Unchanged — out of scope for Plans 29-07/29-08/29-09, not part of the four re-verified gaps, no active defect today since the backfill migration already ran live |

No `TBD`/`FIXME`/`XXX` markers found in any file touched by the gap-closure plans.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `classify()` passes the HOLD_POINT literal clean (blank address) | Direct `tinker` invocation | `NULL` | ✓ PASS |
| `classify()` still flags urgent-care keyword (no regression) | Direct `tinker` invocation | `'urgent_care_keyword'` | ✓ PASS |
| `classify()` still flags missing address for a genuine hospital (no regression) | Direct `tinker` invocation | `'missing_address_or_postcode'` | ✓ PASS |
| Migration's `reviewed_data['cdm']` guard is exact-literal, not substring | `grep -n "str_contains(\$name\|NAME_EXACT_VARIANTS\|in_array(trim"` | 0 `str_contains($name` matches; `NAME_EXACT_VARIANTS`/`in_array` present | ✓ PASS |
| `rams.blade.php` A&E cell has no write-back into `$siteEmerg` | `grep -n "site_emergency\['nearest_hospital'\] ="` | 0 matches | ✓ PASS |
| Full RAMS test suite green after all three gap-closure fixes | `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` | 328 passed, 1517 assertions | ✓ PASS |

### Probe Execution

No conventional `scripts/*/tests/probe-*.sh` probes declared for this phase. Test-suite execution (above) substitutes, run directly by this verifier, not taken from SUMMARY claims.

### Human Verification Required

### 1. Live occupied-premises project — visual CDM/A&E check on rams.21stcav.com

**Test:** Regenerate a real occupied-premises project's RAMS (Save Review or full regenerate) on the live VPS and open both the PDF and DOCX downloads.
**Expected:** (1) CDM 2015 — Duty Holders states the anticipated-sole-contractor position, never "[To be confirmed]" for Principal Designer/Principal Contractor. (2) Section 7.0's Nearest A&E row shows either a verified name+address+postcode or the exact hold-point line, never "TBC", never "to be identified at site induction". (3) The Welfare Arrangements First Aid bullet points to Section 7.0 rather than repeating A&E text.
**Why human:** This is ROADMAP Phase 29 success criterion 4's own explicit "verified against production data, not just a fixture" requirement — it requires opening a rendered document and reading it, not something a grep or automated test can substitute for. This step has still not been performed by any plan in this phase, gap-closure included.

### Gaps Summary

All three code-review-identified BLOCKER defects (CR-01, CR-02, WR-01) from 29-REVIEW.md, and the corresponding gaps in the prior VERIFICATION.md, are now confirmed CLOSED by direct inspection of the live codebase and independent re-execution of both targeted checks (tinker invocation, grep) and the full 328-test RAMS suite — not by trusting SUMMARY.md's claims. No regressions were introduced by the fixes.

3 of 4 ROADMAP success criteria are now fully met (criteria 1-3). The 4th — a live, human, visual inspection of a regenerated occupied-premises project's PDF and DOCX — remains genuinely outstanding. This is not a documentation gap; no agent in this phase's full history (Plans 29-01 through 29-09) has performed it. It requires a human to open rams.21stcav.com, regenerate a real project, and read the output.

Separately, this verification found and corrected a requirement-status inconsistency: GATE-11 was marked Complete in REQUIREMENTS.md despite sharing its enforcement flag, byte-for-byte, with GATE-12 (marked Pending) — both gates are switched by the identical `cdm_ae_gate_enabled` config key, which defaults `false`. Per this project's own established precedent (GATE-06/07/09 earn "Complete" only once armed by default), GATE-11 cannot be more complete than GATE-12. REQUIREMENTS.md has been corrected so GATE-11 now reads `[ ]` Pending, consistent with GATE-12 and with the project's precedent.

**Recommendation:** This phase cannot be closed until the outstanding human visual verification (Task 3 of Plan 29-06, restated above) is performed. Once it passes, `RAMS_CDM_AE_GATE=true` can be armed in production per D-03, and GATE-11/GATE-12 can both then be marked Complete in REQUIREMENTS.md together.

---

_Verified: 2026-09-12_
_Verifier: Claude (gsd-verifier)_
