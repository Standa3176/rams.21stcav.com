---
phase: 29-cdm-duty-holder-emergency-arrangements
verified: 2026-09-12T00:00:00Z
status: gaps_found
score: 4/8 must-haves verified
overrides_applied: 0
gaps:
  - truth: "GATE-12's plausibility classifier never flags the D-05 hold-point line as a defect (D-08 conservative-by-construction)"
    status: failed
    reason: "SiteEmergencyResolver::classify() only short-circuits on a BLANK nearest_hospital. If nearest_hospital literally contains the HOLD_POINT sentence itself (a realistic trigger — that exact sentence is displayed verbatim on every generated PDF/DOCX, so a PM copy-pasting it back into the review form is plausible), classify() falls through to the address check and returns 'missing_address_or_postcode' instead of null. Confirmed live in this codebase via direct invocation: classify(['nearest_hospital' => HOLD_POINT text, 'hospital_address' => '']) returns 'missing_address_or_postcode', not null. This is CR-01 in 29-REVIEW.md, still unfixed (no commit after c7049bd touches this file). It will misfire the moment RAMS_CDM_AE_GATE is armed — the explicitly planned next deploy step — silently overwriting an engineer's/system's own sanctioned hold-point text.
    artifacts:
      - path: "app/Services/Rams/SiteEmergencyResolver.php"
        issue: "classify() lines 99-137: no check for $name === self::HOLD_POINT alongside the $name === '' short-circuit at line 108"
    missing:
      - "Add `|| $name === self::HOLD_POINT` (or route through resolve()'s verified flag) to classify()'s early clean-return check, matching the class's own documented guarantee"
      - "A regression test asserting classify() on the literal HOLD_POINT string (not just a blank string) returns null"
  - truth: "The backfill never overwrites a row where an engineer already typed a real duty-holder name (D-02: placeholder-equality guard, not a blanket overwrite)"
    status: failed
    reason: "The generated_data['cdm_duty_holders'] branch correctly uses an exact-literal guard, but the reviewed_data['cdm'] branch uses str_contains($name, 'To be confirmed') scoped only to PD/PC role — a genuine PM-authored note such as \"PD to be confirmed once client appoints one\" or \"Contact details to be confirmed — Alex Carter is acting PD\" would be entirely replaced by the generic boilerplate, discarding real project-specific text. This is CR-02 in 29-REVIEW.md, still unfixed in the migration file. Verification note per orchestrator context: production's actual run reported 0 reviewed_data.cdm replacements, so this is a LATENT code defect, not realised production data loss — but the defect is real and the migration file (which stays in the migrations table and could run again in another environment, e.g. staging refresh or a fresh seed) still carries it uncorrected."
    artifacts:
      - path: "database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php"
        issue: "fixCdmPlaceholder() reviewed_data['cdm'] branch (lines ~192-211) uses a loose substring guard instead of an exact-literal/enumerated-variant guard"
    missing:
      - "Tighten the reviewed_data['cdm'] guard to match only the bare literal 'To be confirmed' (or a small enumerated set of known seeded-default variants), not any string containing the phrase"
      - "A test proving a PM-typed sentence containing the substring 'To be confirmed' survives the migration unchanged"
  - truth: "All five render sites read the SAME resolved value from Plan 29-03/29-02 — none re-derives the verified-vs-hold-point branch independently (D-01 anti-pattern)"
    status: failed
    reason: "rams-v2.blade.php and DocxBuilderService correctly delegate. rams.blade.php does not call SiteEmergencyResolver at all — it reads a pre-computed $data['site_emergency_resolved']['text'] with a bare '?? \"\"' fallback (confirmed at rams.blade.php:1983), never invoking the resolver itself if that key is absent. This is currently masked because RamsController::downloadPdf() always calls upgrade() first, but RamsRegenerateSnapshotsCommand.php already renders pdf.rams directly via RamsDisplayPatchService::patch() without running upgrade() — a fixture lacking the key renders a BLANK A&E cell, which is worse than the hold-point line and worse than the pre-Phase-29 defect this phase exists to fix. This is WR-01 in 29-REVIEW.md; classified here as a failed truth (not merely a warning) because the must_have explicitly requires no independent re-derivation risk and this is a real, already-demonstrated blank-output failure mode outside the primary download path, not a theoretical one."
    artifacts:
      - path: "resources/views/pdf/rams.blade.php"
        issue: "Line 1983 reads $data['site_emergency_resolved']['text'] ?? '' with no resolver fallback, unlike rams-v2.blade.php which always has a populated DTO field"
    missing:
      - "Compute the fallback via SiteEmergencyResolver::resolve($siteEmerg) inline when site_emergency_resolved is absent, so a missing key degrades to the safe hold-point line, not blank output"
  - truth: "A live occupied-premises project regeneration is manually verified against production data, not just fixtures (ROADMAP criterion 4)"
    status: failed
    reason: "Confirmed via 29-06-SUMMARY.md and 29-MEASUREMENT.md: the deploy (429fdfd..38eb41d) and the Plan 29-05 backfill migration (46/54 rows, exactly matching the 29-01 measurement) were both run and verified live on production 2026-09-11. The visual inspection half — opening a regenerated live project's PDF and DOCX and confirming the CDM wording, Section 7.0 A&E row, and Welfare bullet pointer — was NOT performed. This is explicitly acknowledged as outstanding in both 29-06-SUMMARY.md and 29-MEASUREMENT.md; recording it here per the orchestrator's instruction to state the precise 46/54, 0-reviewed-data split rather than overstate what was verified."
    artifacts: []
    missing:
      - "Open a regenerated live occupied-premises project's PDF and DOCX on rams.21stcav.com and visually confirm: (1) CDM 2015 Duty Holders never shows '[To be confirmed]' for PD/PC, (2) Section 7.0 A&E row shows a verified name+address or the exact hold-point line, never 'TBC' or the banned string, (3) the Welfare First Aid bullet points to Section 7.0"
human_verification:
  - test: "Open a regenerated live occupied-premises project's PDF and DOCX on rams.21stcav.com"
    expected: "CDM 2015 Duty Holders table states the anticipated-sole-contractor position (never '[To be confirmed]' for Principal Designer/Principal Contractor); Section 7.0's Nearest A&E row shows either a verified name+address+postcode or the exact hold-point line (never 'TBC', never 'to be identified at site induction'); the Welfare First Aid bullet points to Section 7.0 rather than repeating A&E text"
    why_human: "Requires opening a real rendered document on the live VPS and reading it — not verifiable by grep/test; this is ROADMAP Phase 29 success criterion 4's own explicit 'verified against production data, not just a fixture' requirement, and is recorded by the phase's own SUMMARY.md as not yet performed"
---

# Phase 29: CDM Duty-Holder & Emergency Arrangements Verification Report

**Phase Goal:** Replace the unconditional CDM duty-holder placeholder and the hardcoded "to be identified at site induction" A&E line with the settled positions, and ship GATE-11/GATE-12 so a RAMS can no longer go out the door with either placeholder.
**Verified:** 2026-09-12
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP + PLAN must-haves merged)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | RULE-08 restated (two-branch, verified-or-hold-point wording) is recorded in REQUIREMENTS.md with a RESTATED note, matching RULE-07's precedent style | ✓ VERIFIED | `.planning/REQUIREMENTS.md:88` carries the exact two-branch wording plus a `RESTATED 2026-09-11` parenthetical in RULE-07's style |
| 2 | ROADMAP Phase 29 criterion 2 no longer asserts a named A&E "by default" — states the D-05 verified-or-hold-point position | ✓ VERIFIED | `.planning/ROADMAP.md:189` reads the resolver-based two-branch text with the verbatim hold-point line; no "by default" phrase adjacent to "named A&E" |
| 3 | CDM duty-holder table replaces the unconditional `[To be confirmed]` for PD/PC with the restated, forbidden-statement-free wording on every job | ✓ VERIFIED | `RamsComplianceUpgradeService::addCdmDutyHolders()` (via `DEFAULT_PRINCIPAL_DESIGNER_NOTE`/`DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` constants, `:1121-1133`) applies unconditionally; `CdmDutyHolderWordingTest` (13 tests) proves none of the four forbidden statements appear; full suite green (325 tests) |
| 4 | The banned passive string and the `'TBC'` A&E fallback are structurally gone from every real render site (both blades, DOCX) | ✓ VERIFIED (with a reachability caveat — see gap #3) | `grep` across `rams.blade.php`, `rams-v2.blade.php`, `DocxBuilderService.php` returns 0 for the banned string; `'TBC'` is gone specifically from the A&E row in both blades (confirmed by direct grep); `SiteEmergencyRenderSitesRegressionTest` (6 tests, 36 assertions) passes for both composer states |
| 5 | GATE-12's plausibility classifier never flags the D-05 hold-point line as a defect (D-08 conservative-by-construction) | ✗ FAILED | `SiteEmergencyResolver::classify()` returns `'missing_address_or_postcode'`, not `null`, when `nearest_hospital` literally equals the HOLD_POINT string — confirmed by direct invocation against the live codebase. Matches 29-REVIEW.md CR-01, unfixed. |
| 6 | The backfill never overwrites a row where an engineer already typed a real duty-holder name (D-02: placeholder-equality guard, not a blanket overwrite) | ✗ FAILED | `reviewed_data['cdm']` branch uses a loose substring guard (`str_contains($name, 'To be confirmed')`), not an exact-literal match — a genuine PM note containing that phrase would be silently discarded. Matches 29-REVIEW.md CR-02, unfixed. Production run measured 0 reviewed_data replacements (no realised damage this run), but the code defect stands. |
| 7 | All five render sites read the SAME resolved value — none re-derives the verified-vs-hold-point branch independently (D-01 anti-pattern) | ✗ FAILED | `rams.blade.php` reads `$data['site_emergency_resolved']['text'] ?? ''` directly, never calling `SiteEmergencyResolver::resolve()` itself — unlike `rams-v2.blade.php`/`EmergencyComposer`. Already demonstrated to produce blank (not hold-point) output via `RamsRegenerateSnapshotsCommand`, which renders `pdf.rams` without running `upgrade()`. Matches 29-REVIEW.md WR-01. |
| 8 | Both gates disarmed by default; a live occupied-premises regeneration is manually verified against production data, not just a fixture (ROADMAP criterion 4) | ✗ FAILED (partially met) | Disarm confirmed: `config/rams_tier1.php:134` — `env('RAMS_CDM_AE_GATE', false)`. Deploy + backfill migration verified live 2026-09-11 (46/54 rows touched, matches the 29-01 measurement exactly, 0 reviewed_data.cdm replacements). Visual PDF/DOCX inspection of a live regenerated project — explicitly required by criterion 4's "not just a fixture" wording — was NOT performed; acknowledged in both 29-06-SUMMARY.md and 29-MEASUREMENT.md as outstanding. |

**Score:** 4/8 truths verified (2 restatement truths, wording truth, render-site-fix truth — all fully verified; 4 fail: GATE-12 false-positive bug, backfill guard looseness, render-site inconsistency, and the unfinished live-verification half of criterion 4)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Rams/SiteEmergencyResolver.php` | `resolve()`/`classify()` per D-05/D-08 | ⚠️ STUB-ADJACENT (functional but incorrect on one input) | Exists, wired, unit-tested (15 tests) — but `classify()` has an unhandled edge case (CR-01) that contradicts its own documented guarantee |
| `config/rams_tier1.php` | `cdm_ae_gate_enabled` disarmed, own env var | ✓ VERIFIED | `:134` — `env('RAMS_CDM_AE_GATE', false)`, independent of the other two gate flags |
| `app/Services/Rams/RamsComplianceUpgradeService.php` | Restated CDM wording, GATE-11/GATE-12 wired | ✓ VERIFIED | `addCdmDutyHolders()`, `enforceCdmGate()` (`:1193`), `enforceEmergencyGate()` (`:1219`), `resolveSiteEmergency()` (`:1244`), all called from `upgrade()` (`:83`, `:97-99`) |
| `resources/views/pdf/rams.blade.php` | Welfare pointer + resolved A&E row | ⚠️ ORPHANED-RESOLVER (reads a key, never falls back to the resolver) | Banned string/`'TBC'` gone from the A&E surface, but the read path bypasses `SiteEmergencyResolver` entirely if the upstream key is absent (WR-01) |
| `resources/views/pdf/rams-v2.blade.php` | Welfare pointer + `nearestHospitalResolvedText` | ✓ VERIFIED | `:2066` reads the DTO field, which `EmergencyComposer` always populates via the resolver |
| `app/Services/DocxBuilderService.php` | Welfare bullet fixed; CDM fallback | ✓ VERIFIED (banned string) / ⚠️ (fallback doesn't guard the literal value, WR-02) | Banned string gone (`grep` returns 0); `??` fallback only substitutes on a missing key, not the known-bad `'[To be confirmed]'` literal already persisted pre-Phase-29 |
| `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` | Idempotent, dual-column, placeholder-equality-guarded backfill | ⚠️ PARTIAL (generated_data branch correct; reviewed_data branch loosely guarded) | Ran live 2026-09-11, 46/54 rows touched, matches measurement; idempotency proven by test; `down()` documented no-op — but CR-02's substring-guard risk stands uncorrected in the file |
| `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` | Dual-path GATE-11/GATE-12 reachability proof | ✓ VERIFIED | 3 tests, 13 assertions, passing; correctly documents GATE-12 as dormant-at-build/live-at-review-download |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `EmergencyComposer::compose()` | `SiteEmergencyResolver::resolve()` | direct call | ✓ WIRED | Confirmed in Plan 29-02, unchanged since |
| `RamsComplianceUpgradeService::upgrade()` | `SiteEmergencyResolver::resolve()`/`classify()` | `resolveSiteEmergency()`/`enforceEmergencyGate()` | ✓ WIRED | Confirmed at `:83`, `:97-99`, `:1219-1244` |
| `rams.blade.php` | `RamsComplianceUpgradeService::resolveSiteEmergency()` | reads `$data['site_emergency_resolved']` | ⚠️ PARTIAL | Wired for the primary download path (which always calls `upgrade()` first) but NOT self-sufficient — no direct resolver fallback, so an alternate caller (already existing: `RamsRegenerateSnapshotsCommand`) that skips `upgrade()` gets blank output instead of the resolver's hold-point default |
| `database/migrations/...backfill_cdm...` | `RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_*_NOTE` | shared constants | ✓ WIRED | Migration references the constants directly, no re-typed prose |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| RULE-07 | 29-01, 29-03, 29-05 | CDM duty-holder anticipated-sole-contractor wording | ✓ SATISFIED | REQUIREMENTS.md marks `[x] Complete`; wording verified forbidden-statement-free; code + backfill both implemented and tested; production backfill run and verified (46/54) |
| RULE-08 | 29-01, 29-02, 29-04 | Two-branch verified-or-hold-point A&E wording | ⚠️ PARTIALLY SATISFIED | REQUIREMENTS.md marks `[x] Complete`, and the restated wording/render-site fixes are real — but the underlying `SiteEmergencyResolver::classify()` bug (CR-01) and the legacy blade's non-self-sufficiency (WR-01) mean the "single source of truth, never re-derived" design guarantee this rule's implementation rests on is not fully honoured. Recommend NOT closing RULE-08 as unconditionally safe until CR-01/WR-01 are fixed. |
| GATE-11 | 29-03, 29-06 | CDM placeholder-survival throwing re-check | ✓ SATISFIED (code-complete, disarmed) | REQUIREMENTS.md correctly leaves `[ ]` Pending — implemented, tested (dual-path proof), deployed, but D-03 arming gate blocked on the outstanding visual verification. This is accurate, not a gap in itself. |
| GATE-12 | 29-02, 29-03, 29-06 | A&E plausibility throwing re-check | ✗ BLOCKED | REQUIREMENTS.md correctly leaves `[ ]` Pending. Beyond the deploy-order reason already documented, GATE-12 has a live false-positive bug (CR-01) that would fire on its own sanctioned output the moment the gate is armed — this is a functional defect, not just an unfinished manual step, and must be fixed before GATE-12 can be safely armed. |

No orphaned requirement IDs found for this phase (RULE-07, RULE-08, GATE-11, GATE-12 all appear in at least one plan's `requirements:` field and in REQUIREMENTS.md's traceability table).

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Services/Rams/SiteEmergencyResolver.php` | 99-137 | `classify()` false-positive on its own sanctioned output (logic bug, not a debt marker) | 🛑 Blocker | Will misfire the moment the gate is armed (the explicitly planned next deploy step), silently overwriting sanctioned hold-point text |
| `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` | ~192-211 | Loose substring guard risks overwriting genuine engineer-typed text | 🛑 Blocker | Latent data-loss risk on any future/other-environment run; 0 realised damage this run per production measurement |
| `resources/views/pdf/rams.blade.php` | 1983 | Silent `?? ''` fallback instead of calling the resolver | ⚠️ Warning | Already demonstrated to degrade to blank output via an existing alternate caller (`RamsRegenerateSnapshotsCommand`) |
| `app/Services/DocxBuilderService.php` | 1706-1707 | `??` fallback doc-commented as "defence-in-depth" but does not guard the known-bad literal value | ⚠️ Warning | Documentation/behaviour mismatch; no active defect today (migration already ran) but would not catch a future regression |

No `TBD`/`FIXME`/`XXX` markers found in any file this phase modified.

### Data-Flow Trace (Level 4)

Not applicable in the SPA-dashboard sense — this phase is server-side template/service logic (PDF/DOCX generation), not a React data-fetch surface. The equivalent trace (resolved value → template render) is covered under Key Link Verification above.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `SiteEmergencyResolver::classify()` passes the hold-point line clean | Direct PHP invocation with `nearest_hospital` = HOLD_POINT literal, `hospital_address` = '' | Returned `'missing_address_or_postcode'`, not `null` | ✗ FAIL (confirms CR-01) |
| Banned string absent from all three render sites | `grep -c "to be identified at site induction"` across `rams.blade.php`, `rams-v2.blade.php`, `DocxBuilderService.php` | 0 in all three | ✓ PASS |
| GATE-11/GATE-12 disarmed by default | `grep "cdm_ae_gate_enabled" config/rams_tier1.php` | `env('RAMS_CDM_AE_GATE', false)` | ✓ PASS |
| Full RAMS test suite green | `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` | 325 passed (1502 assertions) | ✓ PASS |

### Probe Execution

No conventional `scripts/*/tests/probe-*.sh` probes found or declared for this phase; this is a Laravel PHP app, and PLAN/SUMMARY files declare `php artisan test` as the verification mechanism, which was run directly above (see Behavioral Spot-Checks). Step 7c: SKIPPED (no shell-script probes declared for this phase; test-suite execution substituted and performed directly).

### Human Verification Required

### 1. Live occupied-premises project — visual CDM/A&E check on rams.21stcav.com

**Test:** Regenerate a real occupied-premises project's RAMS (Save Review or full regenerate) on the live VPS and open both the PDF and DOCX downloads.
**Expected:** (1) CDM 2015 — Duty Holders states the anticipated-sole-contractor position, never "[To be confirmed]" for Principal Designer/Principal Contractor. (2) Section 7.0's Nearest A&E row shows either a verified name+address+postcode or the exact hold-point line, never "TBC", never "to be identified at site induction". (3) The Welfare Arrangements First Aid bullet points to Section 7.0 rather than repeating A&E text.
**Why human:** This is ROADMAP Phase 29 success criterion 4's own explicit "verified against production data, not just a fixture" requirement — it requires opening a rendered document and reading it, not something a grep or automated test can substitute for. The phase's own 29-06-SUMMARY.md and 29-MEASUREMENT.md both record this step as not yet performed.

### Gaps Summary

The phase delivered substantial, well-tested work: RULE-07's wording restatement is correct and forbidden-statement-free, RULE-08's two-branch wording is correctly restated in both REQUIREMENTS.md and ROADMAP.md, the production backfill ran and matched its own measurement exactly (46/54, 0 reviewed_data replacements), and the disarmed-gate posture (D-03) is honoured throughout. The 325-test RAMS suite is green.

However, the phase's own code review (29-REVIEW.md, produced the same day as the work) found two BLOCKER-level defects that remain unfixed in the codebase as of this verification, both confirmed directly against the live code:

1. **CR-01** — `SiteEmergencyResolver::classify()` does not actually pass its own sanctioned hold-point output clean when that exact string appears in the raw `nearest_hospital` field (only a blank field is handled). This will misfire the moment `RAMS_CDM_AE_GATE` is armed, which is the explicitly planned next step. Confirmed live: `classify()` on the HOLD_POINT literal returns `'missing_address_or_postcode'`, not `null`.
2. **CR-02** — the CDM backfill migration's `reviewed_data['cdm']` guard is a loose substring match, not an exact-literal match, and could silently discard a genuine engineer-typed note containing the phrase "To be confirmed." The already-executed production run reported 0 such replacements (no realised data loss this run, per the orchestrator's provided context), but the code defect remains uncorrected in the migration file, which is a latent risk for any future run (a fresh environment, a staging refresh, or a corrected/re-seeded row).

Two WARNING-level gaps (WR-01, WR-02) also remain: the legacy `rams.blade.php` does not independently fall back to the resolver (already demonstrated to produce blank output via an existing alternate render path), and `DocxBuilderService`'s CDM fallback comment overstates what the `??` operator actually guards against.

Separately, and independently of the code-review findings, **ROADMAP criterion 4 is not met**: the deploy and backfill halves are verified live, but the visual PDF/DOCX inspection of a regenerated live project has not been performed, as the phase's own SUMMARY explicitly and honestly records.

None of these four failing truths are deferred to a later phase — Phase 30 (Structural Validation Gates) and Phase 31 (Standards/COSHH Scoping) do not mention CDM/A&E in their goals or success criteria, so no deferral applies.

**Recommendation:** Before this phase is considered closed, fix CR-01 (the classify() false-positive) and CR-02 (the migration's substring guard) — both are small, targeted code fixes with the exact remediation already specified in 29-REVIEW.md — then complete the outstanding visual verification (Task 3 of Plan 29-06) before arming `RAMS_CDM_AE_GATE`. WR-01/WR-02 are lower priority but should be tracked; arming the gate without fixing CR-01 first would ship a gate that can throw on its own correct output.

---

_Verified: 2026-09-12_
_Verifier: Claude (gsd-verifier)_
