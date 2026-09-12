---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 13
subsystem: rams-docx-rendering
tags: [rams, docx, branding, colours, gap-closure]
dependency-graph:
  requires: ["29-12"]
  provides: ["29-13-docx-brand-colours"]
  affects: ["app/Services/DocxBuilderService.php", "config/rams_theme.php"]
tech-stack:
  added: []
  patterns: ["named colour constants instead of scattered hex literals"]
key-files:
  created:
    - tests/Feature/Rams/DocxBrandColourRegressionTest.php
  modified:
    - app/Services/DocxBuilderService.php
    - config/rams_theme.php
    - app/Support/Rams/RamsTheme.php
    - tests/Unit/Support/Rams/RamsThemeTest.php
    - tests/Feature/Rams/DocxBuilderPaletteFontTest.php
decisions:
  - "Collapsed rams_theme.php's brand_blue_dark to the same 1A1A2E navy as dark_text — the PDF's actual palette has no intermediate brand shade between teal and navy, matching this plan's explicit 'dark headers→1A1A2E' instruction."
  - "Left the historical 260725-rd1 code comment's factual record intact but corrected its framing: it no longer claims 2E74B5 IS the brand colour, and now states the 2026-07-25 shift was itself the defect the 2026-09-12 UAT found."
metrics:
  duration: "~35 min"
  completed: 2026-09-12
---

# Phase 29 Plan 13: DOCX Brand Colour Correction Summary

Corrected `DocxBuilderService`'s DOCX renderer from Microsoft Word's stock "Blue, Accent 1"
defaults (`2E74B5`/`DEEBF7`/`333333`) to 21CAV's actual brand palette (`#1B7A7A` teal /
`#F4FBFB` pale-teal tint / `#1A1A2E` navy), matching the PDF renderer, and fixed the same
stale palette in the shared `config/rams_theme.php` theme config so the dormant
`DocxBuilderServiceV2`/`rams-v2.blade.php` unified-composer path inherits the fix too.

## What Was Built

**Task 1 — Corrected brand colour constants:**
- `app/Services/DocxBuilderService.php`: `TEAL` → `1B7A7A`, `ROW_ALT` → `F4FBFB`, `DARK_GREY`
  → `1A1A2E`. Deleted the three dead `BRAND_BLUE`/`BRAND_BLUE_DARK`/`BRAND_BLUE_TINT` constants
  (confirmed zero call-site usages by grep before removal). Rewrote the `260725-rd1` block
  comment to record that the 2026-07-25 "brand blue" shift was itself the defect 29-UAT.md
  Gap 4 found, rather than claiming `2E74B5` is the real brand colour.
- `config/rams_theme.php`: `brand_blue` → `1B7A7A`, `brand_blue_dark` → `1A1A2E`,
  `brand_blue_tint` / `alt_row` → `F4FBFB`, `dark_text` → `1A1A2E`. All other palette keys
  (white, text_muted, border, risk bands) untouched.
- `tests/Unit/Support/Rams/RamsThemeTest.php`: updated the one test asserting the old hex
  literals to assert the corrected values.

**Task 2 — Regression test proving a real DOCX build carries brand hex:**
- New `tests/Feature/Rams/DocxBrandColourRegressionTest.php`: builds a real
  `DocxBuilderService::build()` document exercising project/team/hazards/method_statement/
  `cdm_duty_holders`/`site_emergency`, extracts `word/document.xml` from the zip, and asserts
  four negative (no `2E74B5`/`DEEBF7`/`1F4D78`/`333333`) plus two positive (`1B7A7A` and
  `F4FBFB` present) assertions.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated `DocxBuilderPaletteFontTest` — a pre-existing test that guarded the defect itself**
- **Found during:** full-suite verification after Task 1 (`php artisan test tests/Unit/Support/Rams tests/Feature/Rams`)
- **Issue:** This test (from the original 260725-rd1 quick task) asserted `2E74B5`/`DEEBF7`
  were the EXPECTED palette and `007B8A`/`F0FBFC` were the legacy values to avoid — i.e. it
  was a regression guard for the very defect this plan fixes. After the Task 1 colour
  correction it failed as expected (`palette uses brand blue not legacy teal`,
  `alt row shading uses light blue not light teal`).
- **Fix:** Renamed the two assertions and updated them to guard the corrected brand values
  (`1B7A7A` present / `2E74B5` absent; `F4FBFB` present / `DEEBF7` absent). Updated the class
  docblock to record that the 260725-rd1 shift was itself the defect 29-UAT.md Gap 4/6 found.
  Left `test_body_font_is_poppins_not_arial` untouched (unrelated to the colour defect).
- **Files modified:** `tests/Feature/Rams/DocxBuilderPaletteFontTest.php`
- **Commit:** `e6852ea`

Or otherwise: no other deviations — plan executed as written.

## Test Results

- `php artisan test tests/Unit/Support/Rams/RamsThemeTest.php` — 12 passed.
- `php artisan test tests/Feature/Rams/DocxBrandColourRegressionTest.php` — 1 passed (9 assertions).
- `php artisan test tests/Feature/Rams/DocxBuilderPaletteFontTest.php` — 3 passed (post-fix).
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full scope, default non-snapshot
  group) — 349 passed, 0 failed (1613 assertions, after the deviation fix; the pre-fix run was
  347 passed / 2 failed, both in `DocxBuilderPaletteFontTest`). The pre-existing unrelated
  `QueueRecoverCommandTest` failure noted in the working-directory brief did not appear in this
  scoped run (it lives outside `tests/Unit/Support/Rams tests/Feature/Rams`).

## Known / Deferred: DOCX Snapshot Fixture

Per this plan's explicit instruction, `tests/Fixtures/rams/tilda-21cq29531/expected-docx-*.xml.norm`
was **not** regenerated. `tests/Feature/Rams/Snapshot/DocxSnapshotTest.php` is tagged `snapshot`
and excluded from the default `phpunit.xml` run (confirmed: `php artisan test tests/Feature/Rams`
does not execute it; `No tests found` when targeted directly without `--group=snapshot`). The
colour changes in this plan will almost certainly change the DOCX snapshot's byte output (the
fixture was captured against the pre-29-13 Word-blue palette) — this diff is left for Plan 29-14,
which owns the diff-first fixture-regeneration step, per this plan's explicit scope boundary.
An attempt to run the snapshot group directly (`--group=snapshot`) did not complete within the
session's available time; it is not required for this plan's success criteria and is left
unexamined for 29-14 to pick up alongside the expected fixture diff.

## Self-Check: PASSED

- FOUND: `app/Services/DocxBuilderService.php` (modified, contains `1B7A7A`/`F4FBFB`/`1A1A2E`)
- FOUND: `config/rams_theme.php` (modified, palette corrected)
- FOUND: `tests/Feature/Rams/DocxBrandColourRegressionTest.php` (created)
- FOUND commit `0469c88`
- FOUND commit `e6852ea`
- FOUND commit `6795270`
