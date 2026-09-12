---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 09
subsystem: rams-pdf-legacy-blade-emergency-arrangements
tags: [rams, gap-closure, wr-01, site-emergency, tdd, safety-critical]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 02
    provides: "app/Services/Rams/SiteEmergencyResolver.php::resolve() — the shared two-branch (verified/hold-point) resolver"
provides:
  - "resources/views/pdf/rams.blade.php's Section 7.0 A&E cell never renders blank, regardless of caller — degrades to SiteEmergencyResolver::resolve()'s hold-point or verified text when site_emergency_resolved is absent — closes 29-VERIFICATION.md gap 3 / 29-REVIEW.md WR-01"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Blade Section 7.0 A&E cell now uses a two-level null-coalesce fallback: $data['site_emergency_resolved']['text'] ?? \\App\\Services\\Rams\\SiteEmergencyResolver::resolve($siteEmerg)['text'] — reuses the already-computed local $siteEmerg, never recomputes or writes it. Mirrors the existing fully-qualified-class-in-blade convention already used in DocxBuilderService.php (this blade has no use-import section)."

key-files:
  created: []
  modified:
    - resources/views/pdf/rams.blade.php
    - tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php

key-decisions:
  - "Fix is a single-line inline fallback, not a re-derivation — the blade never re-implements the verified/hold-point branch logic itself; it always defers to SiteEmergencyResolver::resolve(), consistent with D-05/D-06's 'no render site re-derives independently' rule."
  - "Fallback is strictly read-only: it reads $siteEmerg (already computed a few lines above) and writes nothing back into $siteEmerg['nearest_hospital'], $data, or $rams. Verified via grep for the write-back pattern (zero matches) as an acceptance criterion, not just code review."
  - "New regression tests reproduce the REAL bypass path, not a synthetic one: renderV1WithoutUpgrade() calls RamsDisplayPatchService::patch() only (mirroring RamsRegenerateSnapshotsCommand's actual render call), deliberately skips RamsComplianceUpgradeService::upgrade(), and asserts (as a test-setup invariant) that generated_data genuinely has no site_emergency_resolved key before rendering — otherwise the test would not actually exercise the fixed code path."
  - "Only rams.blade.php (v1/legacy) was touched. rams-v2.blade.php and DocxBuilderService.php already delegate correctly through the DTO/composer path and were left untouched, per the plan's explicit scope boundary."
  - "No fixture regeneration needed — the full RAMS suite (328 tests) passed unchanged; the fix only changes behavior on the previously-blank no-upgrade() path, which no existing fixture-based test exercised."

patterns-established: []

requirements-completed: [RULE-08]  # rams.blade.php's Section 7.0 A&E cell is now self-sufficient:
  # a missing site_emergency_resolved key degrades safely to the resolver's hold-point or verified
  # text, never blank, on any caller (including RamsRegenerateSnapshotsCommand's upgrade()-bypass
  # path). Closes 29-VERIFICATION.md gap 3 / 29-REVIEW.md WR-01.

# Metrics
metrics:
  duration: "~25 minutes"
  completed: "2026-09-12"
---

# Phase 29 Plan 09: Legacy blade A&E fallback (WR-01 gap closure) Summary

Fixed `resources/views/pdf/rams.blade.php:1983` so the Section 7.0 "Nearest A&E Hospital" cell
falls back to `SiteEmergencyResolver::resolve($siteEmerg)['text']` when `site_emergency_resolved`
is absent, instead of silently rendering an empty string — closing 29-VERIFICATION.md gap 3 /
29-REVIEW.md WR-01.

## What Was Built

**The defect:** `rams.blade.php` read only `$data['site_emergency_resolved']['text'] ?? ''`. That
key is populated by `RamsComplianceUpgradeService::upgrade()`, which always runs on the primary
download path (`RamsController::downloadPdf()`), masking the defect there. But
`RamsRegenerateSnapshotsCommand.php` renders `pdf.rams` via `RamsDisplayPatchService::patch()`
**without** calling `upgrade()` — so on that path the A&E cell rendered completely blank, which is
worse than the hold-point line and worse than the pre-Phase-29 defect this whole phase exists to
fix.

**The fix:** One line changed —

```php
{{ $data['site_emergency_resolved']['text'] ?? \App\Services\Rams\SiteEmergencyResolver::resolve($siteEmerg)['text'] }}
```

`$siteEmerg` is the local variable already computed a few lines above (canonical merge of
`$data['site_emergency']` / `reviewed_data['site_emergency']`) — it is reused, not recomputed. The
resolver is called fully-qualified (no `use` import section exists in this blade), matching the
existing convention in `DocxBuilderService.php`. The fallback is read-only: it never writes into
`$siteEmerg['nearest_hospital']`, `$data`, or persists anything back to the model — confirmed by a
zero-match grep for any write-back pattern.

**Regression coverage:** Two new tests were added to
`tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php`, both driven by a new
`renderV1WithoutUpgrade()` helper that mirrors `RamsRegenerateSnapshotsCommand`'s actual render
call — `RamsDisplayPatchService::patch()` only, deliberately never calling
`RamsComplianceUpgradeService::upgrade()`. The helper asserts as a setup invariant that
`generated_data` genuinely has no `site_emergency_resolved` key before rendering, so the tests
provably exercise the real bypass, not a synthetic stand-in:

- `test_no_upgrade_hold_point_state_shows_holdpoint_not_blank` — blank `nearest_hospital`/
  `hospital_address`, no `site_emergency_resolved` key at all → cell contains the HOLD_POINT text,
  not blank, not `'TBC'`.
- `test_no_upgrade_verified_state_shows_named_hospital_not_blank` — populated `nearest_hospital` +
  `hospital_address`, no `site_emergency_resolved` key → cell contains the resolved verified text
  (hospital name + address + "Route and travel time confirmed at induction."), not blank.

All six pre-existing tests in the file (which run the full `upgrade()` pipeline and so already had
`site_emergency_resolved` populated) pass unchanged, confirming the primary download path's
behavior is untouched.

## Deviations from Plan

None — plan executed exactly as written. Single task, single file changed in `resources/views/`,
tests added to the exact file named in the plan's frontmatter.

## Verification

- `grep -n "SiteEmergencyResolver::resolve" resources/views/pdf/rams.blade.php` — exactly one
  match, on the Section 7.0 A&E cell line (`:1983`).
- `grep -n "site_emergency\['nearest_hospital'\] =" resources/views/pdf/rams.blade.php` — zero
  matches (confirms no write-back was introduced).
- `php artisan test tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php` — 8/8 pass
  (6 pre-existing + 2 new), 50 assertions.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS surface) — 328/328 pass,
  1517 assertions, 64.57s. No fixture regeneration was needed — no `tests/Fixtures/rams/
  tilda-21cq29531/` output changed.

## Self-Check: PASSED

- FOUND: `resources/views/pdf/rams.blade.php` (modified, line 1983 fallback confirmed present)
- FOUND: `tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php` (modified, 2 new test
  methods confirmed present and passing)
- FOUND: commit `b6b334d` in `git log` (Rams2 repo root)
