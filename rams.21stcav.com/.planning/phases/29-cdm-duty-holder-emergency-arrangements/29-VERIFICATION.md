---
phase: 29-cdm-duty-holder-emergency-arrangements
verified: 2026-09-12T00:00:00Z
status: gaps_found
score: 7/8 must-haves verified (all 4 UAT gaps closed; ROADMAP criterion 4 still unmet)
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 7/8 (prior cycle) — this is the SECOND re-verification, following the UAT-driven gap-closure cycle (Plans 29-10..29-14)
  gaps_closed:
    - "UAT Gap 2 (WR-01 regression): DOCX carried no A&E info at all — closed by 29-12 (`ee31074`), new Section 7.0 block + Welfare bullet repointed"
    - "UAT Gap 4: Section 7.0 A&E row never rendered when site_emergency wholly empty (hold-point line unreachable) — closed by 29-11 (`d50b4bc`), row moved outside $hasSiteEmerg guard on both PDF blades"
    - "UAT Gap 5 (RULE-07): verbatim anticipated-sole-contractor sentence absent from rendered output — closed by 29-10 (`df4b31c`, constant) + 29-11 (`63d0f66`, PDF) + 29-12 (DOCX, folded into `ee31074`)"
    - "UAT Gap 6: DOCX used Word-default blue (2E74B5/DEEBF7/333333) instead of brand palette — closed by 29-13 (`0469c88`), both DocxBuilderService.php and config/rams_theme.php"
  gaps_remaining:
    - "ROADMAP criterion 4: a live occupied-premises project regeneration, verified against production data (not just a fixture), after THIS cycle's fixes are deployed — still not performed. This code has not even been deployed yet (local branch `feat/worksheet-classifier-universal` is 110 commits ahead of `origin/...`; `master` is untouched)."
    - "The Plan 29-10 contractor_note backfill migration (`2026_09_12_120000_backfill_cdm_contractor_note`) has not been run against production."
    - "The 29-UAT.md human_verification item (CDM table Client row — confirm client name shown, client not shown as Principal Designer) remains open; no plan in this cycle touched it."
  regressions: []
gaps:
  - truth: "A live occupied-premises project regeneration, reflecting THIS gap-closure cycle's fixes, is manually verified against production data (ROADMAP criterion 4)"
    status: failed
    reason: "The four UAT-reported defects are genuinely fixed in the codebase (confirmed by direct code inspection and independent full-suite test execution: 349/349 RAMS tests pass, 6/6 snapshot tests pass). But none of this has been deployed — `git branch -vv` shows the working branch is 110 commits ahead of its remote with no push, and `master` (the deploy source per this project's own conventions) is untouched. The original 2026-09-12 UAT was run against a PRE-fix production deploy, which is exactly what produced these four gaps. No plan in 29-10 through 29-14 performed a deploy or a fresh live-document visual check. Criterion 4's own wording — 'verified against production data, not just a fixture' — cannot be satisfied by local test-suite and code-reading evidence alone, however thorough."
    artifacts: []
    missing:
      - "Deploy this branch's commits (df4b31c..9f2194a) to production"
      - "Run `php artisan migrate --force` on the VPS for the 2026_09_12_120000_backfill_cdm_contractor_note migration"
      - "Regenerate a live occupied-premises RAMS (ideally the same RAMS 103 / 21CQ30949-01-OPS used in the original UAT) and open both the PDF and DOCX to confirm: Section 7.0 A&E row present with either a verified name+address or the exact hold-point line (never blank, never 'TBC', never the banned passive string); DOCX carries the same A&E info and a Section 7.0 block; DOCX uses brand teal/navy, not Word-default blue; CDM table carries the verbatim RULE-07 sentence"
      - "Resolve the still-open human_verification item: CDM table Client row shows the client's name and the client is not shown as Principal Designer"
human_verification:
  - test: "Open a regenerated, POST-deploy live occupied-premises project's PDF and DOCX on rams.21stcav.com"
    expected: "Section 7.0 Nearest A&E row shows either a verified name+address+postcode or the exact hold-point line, in both PDF and DOCX, never 'TBC', never the banned passive string; DOCX Welfare First Aid bullet points to Section 7.0; DOCX uses 21CAV brand teal/navy, not Word-default blue; CDM 2015 Duty Holders section states the verbatim RULE-07 anticipated-sole-contractor sentence in both documents"
    why_human: "Requires opening a rendered document on the live VPS after deploy and reading it — ROADMAP Phase 29 criterion 4's own explicit 'verified against production data, not just a fixture' wording. Nothing in this gap-closure cycle deployed the fix or re-ran this check; local test-suite passes are necessary but not sufficient evidence for this criterion."
  - test: "CDM duty-holder table — Client row"
    expected: "The Client row shows the client's name (e.g. Gardner Leader LLP); the client is NOT shown as Principal Designer and the Client row is not blank"
    why_human: "Carried over unresolved from the original 29-UAT.md — `pdftotext -layout` scrambles the wrapped multi-line CDM cells so it cannot be settled programmatically. No plan in this gap-closure cycle (29-10..29-14) touched this item."
---

# Phase 29: CDM Duty-Holder & Emergency Arrangements Verification Report (Second Re-Verification, post-UAT gap closure)

**Phase Goal:** Replace the unconditional CDM duty-holder placeholder and the hardcoded "to be identified at site induction" A&E line with the settled positions, and ship GATE-11/GATE-12 so a RAMS can no longer go out the door with either placeholder.
**Verified:** 2026-09-12
**Status:** gaps_found
**Re-verification:** Yes — second cycle, after 29-UAT.md (4 gaps + 1 human_verification item found on a live production document) and Plans 29-10 through 29-14 (gap closure)

## Goal Achievement

### UAT Gap Closure — Direct Code Verification

All four UAT-reported code defects were re-checked directly against the live codebase, not against SUMMARY.md claims.

**UAT Gap 2 / prior VERIFICATION.md "Gap 1" (DOCX regression — no A&E info at all) — CLOSED, confirmed.**
`app/Services/DocxBuilderService.php:1544` `buildEmergencyProcedures()` now takes a `RamsDocument $record` parameter and renders a real "7.0 Site-Specific Emergency Details" block (line 1568 onward) with a "Nearest A&E Hospital" row (`:1592`) reading the same `SiteEmergencyResolver`-resolved value the PDF uses. The Welfare First Aid bullet (`:2216`) now reads "...Nearest A&E — see Section 7.0." — a real target section now exists.

**UAT Gap 4 / prior VERIFICATION.md "Gap 2" (hold-point line unreachable when site_emergency empty) — CLOSED, confirmed.**
`resources/views/pdf/rams.blade.php:1985-1990`: the Nearest A&E Hospital `<tr>` is now OUTSIDE the `@if($hasSiteEmerg)` gate (which starts at `:1993`), and reads `$data['site_emergency_resolved']['text'] ?? SiteEmergencyResolver::resolve($siteEmerg)['text']` unconditionally. `resources/views/pdf/rams-v2.blade.php:2067-2073` mirrors this exactly (A&E row before the `@if($hasSiteEmerg)` at `:2076`). Read the empty-state banner too: it was reworded from a red "TBC AT SITE INDUCTION — MUST BE COMPLETED..." hard-stop to an amber informational note naming only the genuinely-unconfirmed fields (fire assembly point, fire warden, etc.) — the literal "TBC" no longer appears in Section 7.0's banner.

**UAT Gap 5 / prior VERIFICATION.md "Gap 3" (RULE-07 verbatim sentence absent) — CLOSED, confirmed.**
`app/Services/Rams/RamsComplianceUpgradeService.php:1144-1146` — `public const DEFAULT_CONTRACTOR_NOTE` holds the sentence byte-for-byte matching `standards-and-legislation.md:23-28`: *"21CAV is currently anticipated to be the sole contractor for the AV installation scope. The client shall confirm whether the overall project involves, or is likely to involve, more than one contractor before works commence."* Both PDF blades render it (`rams.blade.php:1854`, `rams-v2.blade.php:1915`), with a `?? DEFAULT_CONTRACTOR_NOTE` fallback for older documents. DOCX renders it too (`DocxBuilderService.php:1783`, inside `buildCdmSection()`).

**UAT Gap 6 / prior VERIFICATION.md "Gap 4" (DOCX used Word-default blue) — CLOSED, confirmed.**
`app/Services/DocxBuilderService.php:52-55`: `TEAL = '1B7A7A'`, `DARK_GREY = '1A1A2E'`, `ROW_ALT = 'F4FBFB'` — brand teal/navy/pale-teal, not the prior `2E74B5`/`333333`/`DEEBF7` Word defaults. `config/rams_theme.php:42-47` carries the identical corrected palette, so the dormant unified-composer path (`DocxBuilderServiceV2`) inherits the fix too, not just the legacy renderer.

### Regression Check

Full RAMS suite re-run directly (not taken from SUMMARY claims):

```
php artisan test tests/Unit/Support/Rams tests/Feature/Rams
Tests: 349 passed (1604 assertions), Duration: 76.69s
```

Snapshot group (`tilda-21cq29531` golden fixtures, regenerated by Plan 29-14 and reviewed line-by-line against a diff-first discipline per 29-14-SUMMARY.md):

```
php artisan test --group snapshot
Tests: 6 passed (25 assertions) — DocxSnapshotTest (3), PdfSnapshotTest (3)
```

Both figures match the SUMMARY.md claims exactly and were reproduced independently in this verification, not trusted from the summary. No regressions found.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | DOCX renderer conveys nearest-A&E information via a real Section 7.0 block, mirroring the PDF | ✓ VERIFIED | `DocxBuilderService.php:1544-1600` (`buildEmergencyProcedures()`), regression test `DocxEmergencySectionRegressionTest` (7/7 pass) |
| 2 | Section 7.0's Nearest A&E row renders the D-05 hold-point line unconditionally, even with a wholly empty `site_emergency` | ✓ VERIFIED | `rams.blade.php:1985-1990` and `rams-v2.blade.php:2067-2073` both moved the A&E row outside `$hasSiteEmerg`; `SiteEmergencyRenderSitesRegressionTest` wholly-empty presence tests added and passing |
| 3 | CDM duty-holder output states RULE-07's verbatim anticipated-sole-contractor sentence, in both PDF and DOCX | ✓ VERIFIED | `RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE` (`:1144`) is byte-identical to `standards-and-legislation.md:23-28`; rendered in `rams.blade.php:1854`, `rams-v2.blade.php:1915`, `DocxBuilderService.php:1783` |
| 4 | DOCX renderer uses 21CAV brand colours matching the PDF, not Word defaults | ✓ VERIFIED | `DocxBuilderService.php:52-55` (`1B7A7A`/`1A1A2E`/`F4FBFB`); `config/rams_theme.php:42-47` matches; `DocxBrandColourRegressionTest` asserts absence of `2E74B5`/`DEEBF7`/`333333` and presence of brand hex on a real built DOCX |
| 5 | GATE-11/GATE-12 remain correctly disarmed and structurally unaffected by the render-only fixes | ✓ VERIFIED | `config/rams_tier1.php:134` unchanged (`env('RAMS_CDM_AE_GATE', false)`); `CdmEmergencyGateTest` + `CdmEmergencyDualPathGateTest` 15/15 pass, 36 assertions |
| 6 | Full RAMS regression suite is green after all four gap-closure fixes, including the snapshot goldens | ✓ VERIFIED | Reproduced independently: 349/349 (1604 assertions), 6/6 snapshot tests (25 assertions) |
| 7 | The Plan 29-10 contractor_note backfill migration has been run against production, reaching the 46 pre-existing rows | ✗ FAILED | Migration file exists and is test-proven (6/6 in `BackfillCdmContractorNoteMigrationTest`) but 29-14-SUMMARY.md explicitly records it was NOT run against production, and no deploy occurred this cycle to make running it possible yet |
| 8 | A live occupied-premises project regeneration, reflecting this cycle's fixes, is manually verified against production data, not just a fixture (ROADMAP criterion 4) | ✗ FAILED | No deploy occurred this cycle (`git branch -vv`: working branch 110 commits ahead of remote, `master` untouched); the original UAT's live check was against the PRE-fix deploy. No fresh live visual check has been performed against the fixed code |

**Score:** 6/8 truths fully verified this cycle; the remaining 2 are the same class of gap as the prior cycle's criterion-4 finding — a live, deployed, human-observed check that no agent has yet performed.

### ROADMAP Success Criteria — Explicit Ruling

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | CDM duty-holder defaults state the settled sole-Contractor position on every job | ✓ MET | Unchanged from prior verification; RULE-07 sentence now also verified verbatim-exact at the constant level this cycle |
| 2 | Emergency Procedures A&E line replaced by verified-name-or-hold-point resolver, never a guessed name (restated per D-05/D-06) | ✓ MET | All five render sites (both PDF blades, DOCX legacy + unified paths) now confirmed rendering the resolver's two-branch value unconditionally; the DOCX regression (UAT Gap 2) that had reopened this criterion is closed |
| 3 | GATE-11 errors on `[To be confirmed]` surviving to an occupied-premises job; GATE-12 errors on an implausible named A&E | ✓ MET (code-complete, correctly disarmed) | Unchanged from prior verification — both gates implemented, tested, reachable, shipped disarmed by design (D-03); REQUIREMENTS.md already correctly shows both Pending |
| 4 | Regenerating a live occupied-premises project shows the stated CDM position and correct A&E, verified against production data, not just a fixture | ✗ NOT MET | The fixes are code-complete and test-proven locally, but this cycle did not deploy them or perform a fresh live-document check. The 2026-09-12 UAT that found these four gaps was itself the live check for the PRE-fix code — that check has not been repeated against the FIXED code |

**3 of 4 ROADMAP criteria are met. Criterion 4 remains unmet for the second consecutive verification cycle** — not because the underlying defects persist (they don't — all four UAT gaps are genuinely closed in the codebase), but because "verified against production data, not just a fixture" requires a deploy + a human eyeball that has still not happened.

### Requirement-Status Ruling (carried forward)

The prior verification's correction to REQUIREMENTS.md — GATE-11 changed from `[x]` Complete to `[ ]` Pending to match GATE-12 (both gated by the identical `cdm_ae_gate_enabled` flag, which defaults `false`) — is already in place and unchanged by this cycle's plans (confirmed: `git log --oneline -3 -- config/rams_tier1.php` shows the last touch was `90967f4`, Plan 29-02, predating this gap-closure cycle). No further correction needed; the ruling stands.

RULE-07/RULE-08 remain `[x]` Complete (REQUIREMENTS.md `:87-88`) — correctly so. Both are wording requirements, independent of gate-arming state, and this cycle's fixes made their "single source of truth, never re-derived" design guarantee hold across all five render sites for the first time (the DOCX renderer previously had no Section 7.0 at all).

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/DocxBuilderService.php` | Section 7.0 block, Welfare bullet pointer, contractor_note paragraph, brand colours | ✓ VERIFIED | All four confirmed present at cited line numbers; `buildEmergencyProcedures()` signature change (4th param `$record`) confirmed wired through the one call site (`:356`) |
| `resources/views/pdf/rams.blade.php` | A&E row unconditional, contractor_note paragraph | ✓ VERIFIED | `:1854` (contractor_note), `:1985-1990` (unconditional A&E row) |
| `resources/views/pdf/rams-v2.blade.php` | Same two fixes, mirrored via DTO | ✓ VERIFIED | `:1915` (contractor_note), `:2067-2073` (unconditional A&E row) |
| `app/Services/Rams/RamsComplianceUpgradeService.php` | `DEFAULT_CONTRACTOR_NOTE` constant, verbatim RULE-07 sentence | ✓ VERIFIED | `:1144-1146`, byte-identical to source doc |
| `config/rams_theme.php` | Brand palette (not Word defaults) | ✓ VERIFIED | `:42-47` |
| `database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php` | Idempotent, exact-key-presence-guarded backfill | ✓ VERIFIED (code) / ✗ NOT RUN (production) | 6/6 tests pass; confirmed NOT executed against production per 29-14-SUMMARY.md and this verification's own inability to find any deploy/migrate evidence |
| `tests/Fixtures/rams/tilda-21cq29531/*` (4 golden files) | Regenerated to reflect all four fixes | ✓ VERIFIED | Diff reviewed line-by-line per 29-14-SUMMARY.md's stated methodology; snapshot suite passes byte-for-byte against the new goldens |

### Requirements Coverage

| Requirement | Description | Status | Evidence |
|-------------|-------------|--------|----------|
| RULE-07 | CDM duty-holder anticipated-sole-contractor wording | ✓ SATISFIED | Verbatim sentence now confirmed rendering in both PDF blades and DOCX; previously only partially reaching output (PDF empty-data path was fine, but the sentence's exact wording had not been independently verified byte-for-byte until this cycle) |
| RULE-08 | Two-branch verified-or-hold-point A&E wording | ✓ SATISFIED | Now holds across ALL FIVE render sites including DOCX, closing the exact regression (DOCX Gap) that the prior verification's clean pass had not caught because it predated the live UAT |
| GATE-11 | CDM placeholder-survival throwing re-check | ✗ BLOCKED (disarmed by design) | Unchanged — code-complete, tested, deployed-to-local-test-DB only, disarmed pending criterion 4 |
| GATE-12 | A&E plausibility throwing re-check | ✗ BLOCKED (disarmed by design) | Unchanged — same status as GATE-11 |

### Anti-Patterns Found

No `TBD`/`FIXME`/`XXX` markers found in any file touched by Plans 29-10 through 29-14. No new anti-patterns introduced. One pre-existing WARNING carried forward unchanged and out of this cycle's scope:

| File | Line | Pattern | Severity | Status |
|------|------|---------|----------|--------|
| `app/Services/DocxBuilderService.php` | 1706-1707 | `??` fallback doc-commented as "defence-in-depth" but does not guard the known-bad `'[To be confirmed]'` literal already persisted pre-Phase-29 (WR-02) | ⚠️ Warning | Unchanged — not part of this cycle's four UAT gaps |

One documented (not fixed, correctly out-of-scope) coverage gap: the `tilda-21cq29531` fixture's `generated_data` has no `cdm_duty_holders` key, so the DOCX contractor_note paragraph never renders for that specific golden — 29-14-SUMMARY.md flagged this transparently rather than silently accepting it, and correctly notes `DocxEmergencySectionRegressionTest` independently proves the DOCX contractor_note path elsewhere. Confirmed by inspecting the test: it builds a real document with `cdm_duty_holders` populated and asserts the sentence renders. Not a gap.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| DOCX Section 7.0 block exists in source | `grep -n "Site-Specific Emergency" app/Services/DocxBuilderService.php` | Match at `:1568` | ✓ PASS |
| Welfare bullet points to Section 7.0, not CDM section | `grep -n "see Section 7.0" app/Services/DocxBuilderService.php` | Match at `:2216` | ✓ PASS |
| A&E row outside `$hasSiteEmerg` guard on both PDF blades | Manual read of `rams.blade.php:1976-1993`, `rams-v2.blade.php:2048-2076` | Row precedes `@if($hasSiteEmerg)` in both | ✓ PASS |
| RULE-07 constant byte-matches source doc | Manual diff of `DEFAULT_CONTRACTOR_NOTE` vs `standards-and-legislation.md:23-28` | Identical | ✓ PASS |
| DOCX brand hex present, Word-default hex absent | `grep -n "TEAL\s*=\|DARK_GREY\s*=\|ROW_ALT\s*=" app/Services/DocxBuilderService.php` | `1B7A7A`/`1A1A2E`/`F4FBFB` | ✓ PASS |
| Full RAMS test suite green | `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` | 349 passed, 1604 assertions, 76.69s | ✓ PASS |
| Snapshot fixtures regenerated and passing | `php artisan test --group snapshot` | 6 passed, 25 assertions | ✓ PASS |
| Code deployed to production | `git branch -vv` | Working branch 110 commits ahead of remote; `master` untouched | ✗ FAIL (not deployed) |
| Contractor-note backfill run against production | Search for any `migrate --force` invocation or VPS session log in this cycle's SUMMARY.md files | None found; 29-14-SUMMARY.md explicitly states it was not run | ✗ FAIL (not run) |

### Probe Execution

No conventional `scripts/*/tests/probe-*.sh` probes declared for this phase. Test-suite + snapshot-suite execution (above) substitutes, run directly by this verifier, not taken from SUMMARY claims.

### Human Verification Required

### 1. Live, POST-DEPLOY occupied-premises project — visual CDM/A&E/DOCX-colour check on rams.21stcav.com

**Test:** Deploy this cycle's commits, run the contractor_note backfill migration, then regenerate a real occupied-premises project's RAMS on the live VPS and open both PDF and DOCX downloads.
**Expected:** Section 7.0 Nearest A&E row present in BOTH documents with a verified name+address or the exact hold-point line — never blank, never "TBC", never the banned passive string. DOCX Welfare bullet points to Section 7.0. DOCX uses brand teal/navy, not Word-default blue. CDM 2015 Duty Holders states the verbatim RULE-07 sentence in both documents.
**Why human:** ROADMAP Phase 29 criterion 4's own explicit "verified against production data, not just a fixture" wording. This exact check (against the pre-fix code) is what produced the four UAT gaps closed in this cycle — it has not been repeated against the fixed code, because the fixed code has not been deployed.

### 2. CDM duty-holder table — Client row

**Test:** Open the rendered PDF's CDM 2015 Duty Holders table and read the Client row.
**Expected:** Shows the client's name (e.g. Gardner Leader LLP); the client is NOT shown as Principal Designer; the Client row is not blank.
**Why human:** Carried over unresolved from 29-UAT.md — `pdftotext -layout` scrambles the wrapped multi-line CDM cells so it cannot be settled programmatically. No plan in this gap-closure cycle touched it.

### Gaps Summary

All four UAT-reported defects (DOCX A&E regression, PDF hold-point unreachable, RULE-07 sentence absent, DOCX Word-blue colours) are genuinely CLOSED — confirmed by direct inspection of the current codebase (not SUMMARY.md claims) and by independently re-running both the full 349-test RAMS suite and the 6-test snapshot suite, both green. This is real, substantive progress: the phase's core defects (unconditional CDM placeholder, hardcoded A&E line) no longer exist anywhere in the render pipeline, across all five render sites, in either format.

However, ROADMAP criterion 4 — "verified against production data, not just a fixture" — is UNMET for the second consecutive verification cycle, and for a materially different reason than before: this cycle did not deploy the fixes at all. The working branch is 110 commits ahead of its remote with no push, `master` is untouched, the contractor_note backfill migration has not been run against production, and no live document has been regenerated or read since these fixes landed. The phase goal ("...so a RAMS can no longer go out the door with either placeholder") cannot be considered achieved in production until the fix is actually in production and a human has confirmed it there — the exact discipline that surfaced these four gaps in the first place.

The one pre-existing human_verification item (CDM table Client row) also remains open; no plan in this cycle addressed it.

**Recommendation:** This phase cannot be closed until (1) this branch's commits are deployed to production, (2) the contractor_note backfill migration is run against production, (3) a human regenerates a real occupied-premises project and visually confirms all four fixes on the live PDF and DOCX, and (4) the Client-row human_verification item is resolved. Once all four pass, `RAMS_CDM_AE_GATE=true` can be armed per D-03, and GATE-11/GATE-12 can both be marked Complete in REQUIREMENTS.md together.

---

_Verified: 2026-09-12_
_Verifier: Claude (gsd-verifier)_
