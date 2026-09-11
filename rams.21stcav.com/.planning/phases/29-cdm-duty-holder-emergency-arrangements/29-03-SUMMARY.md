---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 03
subsystem: rams-cdm-and-emergency-pipeline
tags: [rams, rule-07, gate-11, gate-12, upgrade-pipeline, docx]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 02
    provides: "SiteEmergencyResolver::resolve()/classify() and the disarmed cdm_ae_gate_enabled config flag this plan calls and gates on"
provides:
  - "RamsComplianceUpgradeService::addCdmDutyHolders() — restated RULE-07 wording, unconditional, never the bare '[To be confirmed]' placeholder"
  - "RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE / DEFAULT_PRINCIPAL_CONTRACTOR_NOTE — public const strings shared by DocxBuilderService's fallback and consumable by Plan 29-05's backfill migration"
  - "RamsComplianceUpgradeService::enforceCdmGate() (GATE-11) and enforceEmergencyGate() (GATE-12) — both wired into upgrade() under one disarmed-by-default config check"
  - "RamsComplianceUpgradeService::resolveSiteEmergency() — writes $data['site_emergency_resolved'] unconditionally on every upgrade() call, for the five render sites (Plan 29-04) to consume"
affects: [29-04-PLAN, 29-05-PLAN]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate = auto-correct (restated wording, always applied) + independent throwing re-check (GATE-11/12), sharing one config('rams_tier1.cdm_ae_gate_enabled', false) block — mirrors GATE-06/07's shape verbatim"
    - "Shared literal constants (public const on the upgrade service) so two independent call sites — the pipeline default and DocxBuilderService's defence-in-depth fallback — never drift"

key-files:
  created:
    - tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php
    - tests/Unit/Services/Rams/CdmEmergencyGateTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - app/Services/DocxBuilderService.php

key-decisions:
  - "RULE-07's restated wording is applied UNCONDITIONALLY on every job, with no occupied-premises branch — RESEARCH.md Finding 6/Assumption A2 confirmed no deterministic 'occupied premises' signal exists anywhere in this codebase (it exists only as a tier-3 confirm:occupied_premises hazard requiring engineer confirmation, which addCdmDutyHolders() never reads). Inventing a new input signal or new UI to capture one was explicitly out of scope. Documented in the addCdmDutyHolders() docblock and here per Assumption A2's own instruction to state this choice rather than bury it."
  - "GATE-11/GATE-12 share ONE config('rams_tier1.cdm_ae_gate_enabled', false) check inside upgrade(), called back-to-back — mirrors how GATE-06/GATE-07 share one config check inside enforceFfp2AndConfinedSpaceGate(), per RESEARCH.md Open Question 1's recommendation."
  - "resolveSiteEmergency() runs unconditionally (outside the gate-flag check) immediately before addCdmDutyHolders() in the pipeline, so site_emergency_resolved is always available to render sites regardless of whether GATE-11/GATE-12 are armed."
  - "SiteEmergencyResolver is in the SAME namespace (App\\Services\\Rams) as RamsComplianceUpgradeService — no 'use' import statement was added (the plan's <action> text said to add one, but PHP does not require or permit an unnecessary same-namespace use statement here; the bare class name resolves correctly without it, verified by php -l and the full green test run)."

patterns-established: []

requirements-completed: []  # RULE-07 is implemented end-to-end by this plan (unconditional,
  # forbidden-statement-free) but GATE-11/GATE-12 and the DocxBuilderService fallback fix do
  # not yet reach the five legacy render sites (Plan 29-04, since RAMS_UNIFIED_COMPOSER=false
  # in production per 29-01-SUMMARY.md) or the 46/54 live rows carrying the old placeholder
  # (Plan 29-05 backfill). Per the plan's own success_criteria ("only mark a requirement
  # complete if this plan implements it end-to-end"), RULE-07/GATE-11/GATE-12 are left
  # "Pending" in REQUIREMENTS.md — Plan 29-04 is where RULE-07's live behaviour actually
  # changes for production documents.

# Metrics
duration: 35min
completed: 2026-09-11
---

# Phase 29 Plan 03: CDM Wording Restatement + GATE-11/GATE-12 Pipeline Core Summary

**Restated `addCdmDutyHolders()`'s CDM duty-holder wording per RULE-07 unconditionally on every job (no occupied-premises signal exists in this codebase to gate it on), fixed the second `'[To be confirmed]'` placeholder origin in `DocxBuilderService::buildCdmSection()`'s fallback literals, and wired GATE-11 (CDM placeholder survival) + GATE-12 (A&E plausibility, delegating to `SiteEmergencyResolver`) into `upgrade()` behind one disarmed-by-default config flag — the pipeline core every render site (29-04) and the backfill migration (29-05) now build on.**

## Performance

- **Duration:** 35 min
- **Started:** 2026-09-11 (this session)
- **Completed:** 2026-09-11
- **Tasks:** 2/2 complete
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments

- `addCdmDutyHolders()`'s `principal_designer`/`principal_contractor` keys never emit the bare `'[To be confirmed]'` placeholder again — replaced with the restated, conditional wording from `standards-and-legislation.md:17-41`, applied on **every** call (no occupied-premises branch).
- New key `contractor_note` carries the anticipated-sole-contractor sentence **verbatim**; `notification` no longer asserts the Principal Contractor must notify HSE.
- Two new `public const` strings (`DEFAULT_PRINCIPAL_DESIGNER_NOTE`, `DEFAULT_PRINCIPAL_CONTRACTOR_NOTE`) on `RamsComplianceUpgradeService` are the single source of truth for this wording, referenced by both `addCdmDutyHolders()` and `DocxBuilderService::buildCdmSection()`'s defence-in-depth fallback.
- `enforceCdmGate()` (GATE-11) and `enforceEmergencyGate()` (GATE-12) are implemented and wired into `upgrade()` under one `config('rams_tier1.cdm_ae_gate_enabled', false)` check — ships disarmed, matching D-03.
- `resolveSiteEmergency()` writes `$data['site_emergency_resolved']` unconditionally on every `upgrade()` call, regardless of the gate flag, ready for Plan 29-04's five render sites to consume.
- 25 new tests across two files, all green; the broader `RamsComplianceUpgradeService` filter (16 tests) and the full `tests/Unit/Support/Rams tests/Feature/Rams` suite (307 tests) both pass with zero regressions.

## Task Commits

Each task was committed atomically:

1. **Task 1: Restate addCdmDutyHolders() (RULE-07), fix DocxBuilderService's CDM fallback** — `3b9325e` (feat). Note: this commit's diff also contains Task 2's `enforceCdmGate()`/`enforceEmergencyGate()`/`resolveSiteEmergency()` method bodies and the `upgrade()` wiring, because both edits to `RamsComplianceUpgradeService.php` were authored together before the first commit was made (see Deviations).
2. **Task 2: GATE-11/GATE-12 wired into upgrade(), disarmed by default** — `32262d1` (feat, test-only diff — `CdmEmergencyGateTest.php`).

## Files Created/Modified

- `app/Services/Rams/RamsComplianceUpgradeService.php` — restated `addCdmDutyHolders()`, two new `public const` wording strings, `enforceCdmGate()`, `enforceEmergencyGate()`, `resolveSiteEmergency()`, all wired into `upgrade()`
- `app/Services/DocxBuilderService.php` — `buildCdmSection()`'s `?? '[To be confirmed]'` fallbacks for `principal_designer`/`principal_contractor` replaced with the shared constants
- `tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php` — 13 tests covering every `<behavior>` bullet plus explicit negative assertions for all four RULE-07 forbidden statements
- `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` — 12 tests: the 9 plan-specified methods plus 3 extra proving the disarmed-inert / armed-throws behaviour through the real `upgrade()` entry point

## RULE-07 Applied Unconditionally — Stated Explicitly

Per the plan's `must_haves` and Assumption A2: **RULE-07's restated wording is applied on every job, with no occupied-premises conditional branch.** RESEARCH.md Finding 6 confirmed there is no deterministic "occupied premises" signal anywhere in this codebase — the closest thing is a tier-3 `confirm:occupied_premises` hazard requiring manual engineer confirmation, which `addCdmDutyHolders()` never reads and this plan does not wire in. ROADMAP criterion 1 and GATE-11's original framing both speak of "an occupied-premises job," but that condition cannot actually be evaluated with existing data. No new UI or input signal was invented to capture one — that would have been scope creep beyond this plan's `<behavior>`. This is recorded here, in the `addCdmDutyHolders()` docblock, and in the `key-decisions` frontmatter above so it is visible at verification time rather than buried.

## Deviations from Plan

**1. [Process] `RamsComplianceUpgradeService.php`'s Task 1 and Task 2 edits landed in one commit, not two.**
- **What happened:** Both edits to `RamsComplianceUpgradeService.php` (the wording restatement + constants from Task 1, and `enforceCdmGate()`/`enforceEmergencyGate()`/`resolveSiteEmergency()` + `upgrade()` wiring from Task 2) were authored in sequence before the first `git add`/commit was run, so both landed in commit `3b9325e` (labelled Task 1). Commit `32262d1` (labelled Task 2) contains only the new test file `CdmEmergencyGateTest.php`.
- **Impact:** None on correctness or verifiability — every acceptance criterion for both tasks is independently testable and tested (`CdmDutyHolderWordingTest` for Task 1's behaviour, `CdmEmergencyGateTest` for Task 2's), and both test files pass green. The only effect is that the per-task commit boundary in git history does not exactly match the plan's task numbering for this one file; both commits are still individually revertable and both still carry clear, task-scoped messages documenting which behaviour they add.
- **Not fixed retroactively:** rewriting git history to split an already-tested, already-committed diff was judged higher-risk than documenting the deviation.

**2. [Rule 1 - correction to plan's literal instruction] Did not add a `use App\Services\Rams\SiteEmergencyResolver;` import.**
- **Found during:** Task 2.
- **Issue:** The plan's `<action>` text said "Import `App\Services\Rams\SiteEmergencyResolver` at the top of the file." `SiteEmergencyResolver` is in the exact same namespace (`App\Services\Rams`) as `RamsComplianceUpgradeService` — PHP resolves the bare class name automatically; adding a same-namespace `use` statement is unnecessary (and in some PHP configurations can even trigger a "class already in use" notice if the imported name were ever declared locally, though that is not the case here).
- **Fix:** Called `SiteEmergencyResolver::resolve()`/`::classify()` directly with the bare class name, no `use` statement added.
- **Verification:** `php -l` clean on both modified files; the full `CdmEmergencyGateTest` suite (which exercises both `SiteEmergencyResolver` calls through `resolveSiteEmergency()` and `enforceEmergencyGate()`) passes green.
- **Files modified:** `app/Services/Rams/RamsComplianceUpgradeService.php`
- **Commit:** `3b9325e`

No other deviations — the rest of the plan executed exactly as written.

## Issues Encountered

None.

## Verification

- `php artisan test --filter=CdmDutyHolderWordingTest` — 13 passed (22 assertions)
- `php artisan test --filter=CdmEmergencyGateTest` — 12 passed (23 assertions)
- `php artisan test --filter=RamsComplianceUpgradeService` (broader filter, checks for regressions to existing gates/steps) — 16 passed (30 assertions), zero regressions
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS composer/blade/DOCX surface, 307 tests) — all green, zero regressions from the `DocxBuilderService` fallback change or the CDM wording change
- `grep -n "DEFAULT_PRINCIPAL_DESIGNER_NOTE\|DEFAULT_PRINCIPAL_CONTRACTOR_NOTE" app/Services/DocxBuilderService.php` — both constants referenced in `buildCdmSection()`, confirmed

## User Setup Required

None — `RAMS_CDM_AE_GATE` needs no `.env` entry (defaults `false` when unset); no gate is armed by this plan.

## Next Phase Readiness

- Plan 29-04 can now fix the five legacy render sites (the ones that actually matter in production, per `RAMS_UNIFIED_COMPOSER=false` measured live in 29-01) by reading `$data['site_emergency_resolved']` and the restated `cdm_duty_holders` values this plan produces.
- Plan 29-05's backfill migration can reference `RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE`/`DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` directly rather than duplicating the restated prose.
- GATE-11/GATE-12 are fully implemented and tested but remain **disarmed** (`cdm_ae_gate_enabled` defaults `false`) — arming is a deliberate later `.env` change made only after the Plan 29-05 backfill and a live regeneration verify clean, per D-03. No task in this plan arms the gate.
- No blockers.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-11*
