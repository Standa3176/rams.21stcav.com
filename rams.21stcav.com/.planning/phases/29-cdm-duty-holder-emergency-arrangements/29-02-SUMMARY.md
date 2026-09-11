---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 02
subsystem: rams-emergency-arrangements
tags: [rams, rule-08, gate-12, resolver, composer, config-flag]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 01
    provides: "measured production facts (RAMS_UNIFIED_COMPOSER=false live, 46/54 CDM placeholder rows) and the RULE-08 two-branch restatement (D-06) this plan implements"
provides:
  - "App\\Services\\Rams\\SiteEmergencyResolver::resolve()/classify() — the single shared A&E branch decision + GATE-12 plausibility classifier, consumable by Plan 29-03 (gate wiring) and Plan 29-04 (render-site fixes) without re-derivation"
  - "config/rams_tier1.php cdm_ae_gate_enabled — disarmed (false) kill-switch, own env var RAMS_CDM_AE_GATE, ready for Plan 29-03 to gate enforceCdmGate()/enforceEmergencyGate()"
  - "EmergencySectionDto.nearestHospitalVerified/nearestHospitalResolvedText — resolved A&E branch available to the unified-composer render path"
affects: [29-03-PLAN, 29-04-PLAN]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Static-utility resolver/classifier pair mirroring ControlTextRuleViolations' shape (no constructor, all public static methods, conservative-by-construction default)"
    - "Per-gate env kill-switch with an explicitly documented false-default divergence from the true-default precedents (D-03/Pitfall 5)"

key-files:
  created:
    - app/Services/Rams/SiteEmergencyResolver.php
    - tests/Unit/Services/Rams/SiteEmergencyResolverTest.php
    - tests/Unit/Support/Rams/EmergencyComposerSiteEmergencyResolvedTest.php
  modified:
    - config/rams_tier1.php
    - app/Support/Rams/SectionComposers/EmergencyComposer.php
    - app/Support/Rams/Sections/EmergencySectionDto.php

key-decisions:
  - "classify()'s ordering intentionally diverges from a naive delegation to resolve(): the plan's 'missing_address_or_postcode' behaviour (named hospital, blank address) is itself one of resolve()'s two hold-point trigger conditions, so classify() cannot simply branch on resolve()['verified'] — it re-checks name-presence first, then banned-string/keyword/address independently, so a named-but-unaddressed A&E is flagged rather than silently passing as 'clean hold-point'"
  - "utc keyword uses a dedicated preg_match word-boundary check, kept out of the URGENT_CARE_KEYWORDS stripos array — proven with a negative test (Baseline Health Centre) and a positive test (Willow UTC) per the plan's explicit acceptance bullet"
  - "cdm_ae_gate_enabled config doc-comment names both future gate methods (enforceCdmGate/enforceEmergencyGate, Plan 29-03) even though neither exists yet, matching the two existing gate blocks' pattern of documenting the consumer before it lands"

patterns-established:
  - "SiteEmergencyResolver as the second static resolver+classifier pair in app/Services/Rams (after ControlTextRuleViolations) — future per-field plausibility checks should follow this shape rather than inlining logic in the gate method"

requirements-completed: []  # RULE-08 and GATE-12 are NOT marked complete by this plan. This
  # plan builds only the shared resolver, the disarmed config flag, and the
  # composer/DTO wiring — the throwing gate itself lands in Plan 29-03, and
  # the five legacy render sites (which is where RULE-08's actual live
  # behaviour change happens, since RAMS_UNIFIED_COMPOSER=false in
  # production per 29-MEASUREMENT.md) land in Plan 29-04. Both requirements
  # stay "Pending" in REQUIREMENTS.md until 29-04 lands.

# Metrics
duration: 45min
completed: 2026-09-11
---

# Phase 29 Plan 02: SiteEmergencyResolver + Disarmed Gate Config + Composer Wiring Summary

**Built the one genuinely new piece of architecture Phase 29 needs — a shared, stateless `SiteEmergencyResolver` that decides the RULE-08 verified-name-vs-D-05-hold-point A&E branch and doubles as GATE-12's D-08 plausibility classifier — then wired it into `EmergencyComposer`/`EmergencySectionDto` and added the disarmed `cdm_ae_gate_enabled` kill-switch, so Plans 29-03 (gate wiring) and 29-04 (render-site fixes) both read one already-tested value instead of re-deriving the branch logic themselves.**

## Performance

- **Duration:** 45 min
- **Started:** 2026-09-11 (this session)
- **Completed:** 2026-09-11T20:23:01Z
- **Tasks:** 2/2 complete
- **Files modified:** 7 (3 created, 4 modified)

## Accomplishments

- `App\Services\Rams\SiteEmergencyResolver::resolve()` returns the D-05 two-branch value — the verbatim house-rule hold-point line when either `nearest_hospital` or `hospital_address` is blank, or `"{name}, {address}. Route and travel time confirmed at induction."` when both are present.
- `SiteEmergencyResolver::classify()` implements GATE-12's D-08 plausibility check: flags the banned passive string, six urgent-care/minor-injury/walk-in keywords plus a word-boundary-safe `UTC` check, and a named-but-unaddressed A&E — while always passing the hold-point branch clean (conservative-by-construction, inherited Phase 28 D-01).
- `config/rams_tier1.php` gained `cdm_ae_gate_enabled` (`RAMS_CDM_AE_GATE`, default `false`) — its own flag, independent of `RAMS_DISPLAY_LIFT_GATE` and `RAMS_PPE_CEILING_ELECTRICAL_GATE`, with the `false`-default divergence documented inline per D-03/Pitfall 5.
- `EmergencySectionDto` gained `nearestHospitalVerified`/`nearestHospitalResolvedText`; `EmergencyComposer::compose()` now calls `SiteEmergencyResolver::resolve()` once and feeds both fields, without touching the existing `nearestHospital` raw field.

## Task Commits

Each task was committed atomically:

1. **Task 1: SiteEmergencyResolver — RULE-08 branch resolution + GATE-12 plausibility classifier** - `c7049bd` (feat)
2. **Task 2: Config flag block + wire the resolver into EmergencyComposer/EmergencySectionDto** - `90967f4` (feat)

**Plan metadata:** committed with this SUMMARY (see final commit below).

## Files Created/Modified

- `app/Services/Rams/SiteEmergencyResolver.php` - new static resolver+classifier, `resolve()`/`classify()`, no constructor
- `tests/Unit/Services/Rams/SiteEmergencyResolverTest.php` - 15 tests covering every `<behavior>` bullet, including the UTC word-boundary false-positive proof
- `config/rams_tier1.php` - new `cdm_ae_gate_enabled` block after `ffp2_confined_space_gate_enabled`, disarmed
- `app/Support/Rams/SectionComposers/EmergencyComposer.php` - calls `SiteEmergencyResolver::resolve()` once, feeds the two new DTO keys
- `app/Support/Rams/Sections/EmergencySectionDto.php` - two new readonly fields (`nearestHospitalVerified`, `nearestHospitalResolvedText`), `fromArray()` updated
- `tests/Unit/Support/Rams/EmergencyComposerSiteEmergencyResolvedTest.php` - 2 tests proving both branches compose correctly through the real DTO/composer path

## Decisions Made

- `classify()` re-checks name-presence, banned-string, keyword, and address independently rather than delegating to `resolve()['verified']` — because `resolve()`'s hold-point branch fires on EITHER field being blank, but the plan requires a named-but-unaddressed A&E to be flagged (`missing_address_or_postcode`), not silently treated as the always-clean hold-point case. Naive delegation would have swallowed that acceptance criterion.
- Kept the `UTC` keyword out of the `URGENT_CARE_KEYWORDS` static array and handled it via a dedicated `preg_match('/\butc\b/i', ...)` — documented inline why it needs different matching than the other five (bare substring match would false-positive on any hospital name merely containing "utc").
- `cdm_ae_gate_enabled`'s doc-comment names the two Plan 29-03 gate methods (`enforceCdmGate()`/`enforceEmergencyGate()`) even though neither exists yet, matching the existing two gate-flag blocks' pattern of documenting the future consumer up front.

## Deviations from Plan

None — plan executed exactly as written. One implementation-detail correction made during Task 1 build (not a deviation from the plan's stated `<behavior>`, but from the skeleton's naive `classify()` shape suggested in `29-PATTERNS.md`): the skeleton implied `classify()` could short-circuit on `resolve()['verified'] === false`, but that would have made a named-but-unaddressed A&E (which also trips `resolve()`'s hold-point branch, since `hospital_address` is blank) incorrectly classify as clean instead of `missing_address_or_postcode`. Fixed inline before committing — verified against every `<behavior>` bullet and covered by `test_classify_flags_missing_address`.

## Issues Encountered

None.

## User Setup Required

None — `RAMS_CDM_AE_GATE` needs no `.env` entry (defaults `false` when unset), and no gate is armed by this plan.

## Next Phase Readiness

- Plan 29-03 can call `SiteEmergencyResolver::resolve()`/`classify()` directly from `enforceCdmGate()`/`enforceEmergencyGate()`, gated by `config('rams_tier1.cdm_ae_gate_enabled')` — both already exist and are fully tested.
- Plan 29-04 can call the same resolver from the five legacy render sites (the ones that actually matter in production, since `RAMS_UNIFIED_COMPOSER=false` live) without re-deriving branch logic.
- `EmergencySectionDto`'s new fields are dormant on the composer path until `RAMS_UNIFIED_COMPOSER` flips true in production (per 29-01's measured fact) — this is expected insurance, not a bug.
- No blockers.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-11*
