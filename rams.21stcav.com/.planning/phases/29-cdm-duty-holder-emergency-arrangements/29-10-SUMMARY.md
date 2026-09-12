---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 10
subsystem: rams-cdm-contractor-note
tags: [rams, gap-closure, rule-07, cdm, migration, tdd-adjacent]

# Dependency graph
requires: []
provides:
  - "app/Services/Rams/RamsComplianceUpgradeService.php::DEFAULT_CONTRACTOR_NOTE — public const carrying the verbatim RULE-07 anticipated-sole-contractor sentence, for Plan 29-11/29-12 render-site fallbacks and the backfill migration to reference"
  - "database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php — idempotent, guard-correct, NOT YET RUN backfill for the 46 pre-existing production rows whose generated_data.cdm_duty_holders predates the contractor_note key"
affects:
  - "Plan 29-11/29-12 (render-site fixes) — can now reference self::DEFAULT_CONTRACTOR_NOTE / RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE instead of duplicating the sentence"
  - "Plan 29-14 or a human — owns actually running the new migration against production after the render fix ships"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Named-constant extraction pattern, third instance: DEFAULT_CONTRACTOR_NOTE joins DEFAULT_PRINCIPAL_DESIGNER_NOTE / DEFAULT_PRINCIPAL_CONTRACTOR_NOTE as a public const on RamsComplianceUpgradeService, same docblock style, declared immediately after the existing pair."
    - "Migration mirrors 2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php's shape (chunked scan, echo audit summary, no-op down()) but with a presence guard (array_key_exists) instead of a value-equality guard (=== bare placeholder), because this backfill is closing a MISSING KEY gap, not a WRONG VALUE gap."

key-files:
  created:
    - database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php
    - tests/Feature/Rams/BackfillCdmContractorNoteMigrationTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php

key-decisions:
  - "Constant declared immediately after DEFAULT_PRINCIPAL_CONTRACTOR_NOTE (plan-mandated position), same public const visibility and docblock style as the two existing constants."
  - "addCdmDutyHolders()'s inline contractor_note string literal removed entirely and replaced with self::DEFAULT_CONTRACTOR_NOTE — exactly one literal now exists in the codebase, verified by grep count = 1."
  - "Migration guard uses array_key_exists('contractor_note', $cdm), never empty() or isset() — an engineer could have deliberately cleared contractor_note to '' through the review form, and that row must be left alone (isset() would wrongly treat null but not '' as missing; empty() would wrongly treat '' as missing and overwrite it). Proven by a dedicated test case."
  - "reviewed_data['cdm'] is explicitly NOT touched by this migration — that shape has no contractor-wide note field; contractor_note only exists on generated_data['cdm_duty_holders']."
  - "down() is a deliberate no-op for the same reason as the 2026-09-11 analog: the guard cannot distinguish a migration-added value from a hand-authored one, so reverting would destroy genuine data."
  - "Migration is code-complete but NOT invoked against any database by this plan — no php artisan migrate call anywhere in the task. Plan 29-14 or a human runs it after the render fix (Plan 29-11/29-12) ships, per the plan's explicit scope boundary."

patterns-established: []

requirements-completed: [RULE-07]  # DEFAULT_CONTRACTOR_NOTE constant now exists as the single
  # source of the RULE-07 verbatim sentence for addCdmDutyHolders() and all future render-site
  # fallbacks; the 46-already-patched-rows backfill gap identified in 29-UAT.md Gap 3 is now a
  # closed, code-complete, testable decision rather than a silent gap. Rendering the sentence in
  # blade/DOCX output remains Plan 29-11/29-12's scope; running the migration remains Plan 29-14's
  # (or a human's) scope.

# Metrics
metrics:
  duration: "~30 minutes"
  completed: "2026-09-12"
---

# Phase 29 Plan 10: DEFAULT_CONTRACTOR_NOTE extraction + contractor_note backfill migration Summary

Extracted the RULE-07 verbatim anticipated-sole-contractor sentence into a named public constant
(`RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE`), and wrote (but did not run) an idempotent
migration backfilling the `contractor_note` key into the 46 production rows whose
`generated_data.cdm_duty_holders` was patched by the 2026-09-11 backfill before `contractor_note`
existed as a key at all — closing the data half of 29-UAT.md Gap 3.

## What Was Built

**Task 1 — the constant.** `addCdmDutyHolders()` (`app/Services/Rams/RamsComplianceUpgradeService.php`)
already computed the correct RULE-07 sentence inline. Added `public const DEFAULT_CONTRACTOR_NOTE`
immediately after `DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` (`:1131`), same visibility and docblock style
as the two existing constants, stating it is verbatim from `standards-and-legislation.md:23-28` and
shared between `addCdmDutyHolders()`, the Plan 29-11/29-12 render-site fallbacks, and this plan's
migration. Replaced the inline string literal in `addCdmDutyHolders()`'s `contractor_note` key with
`self::DEFAULT_CONTRACTOR_NOTE` — the redundant inline copy was removed entirely, so exactly one
literal now exists in the codebase (`grep -c` confirms 1). The string content is byte-identical to
what it replaced, so the existing `CdmDutyHolderWordingTest::test_contractor_note_carries_the_verbatim_anticipated_sole_contractor_sentence()`
passes unchanged.

**Task 2 — the backfill migration.** Created
`database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php`, structurally mirroring
`2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` (chunked `chunkById(100, ...)` scan,
`echo` audit summary, no-op `down()`), with a class docblock explaining the exact decision 29-UAT.md
Gap 3 required: the 46 rows the 2026-09-11 backfill already patched were patched before
`contractor_note` existed as a key at all, so their `generated_data['cdm_duty_holders']` array has
no `contractor_note` key whatsoever — a completed document that never regenerates again would never
pick up the sentence without this migration, mirroring D-02's reasoning for the original CDM
backfill.

The guard differs deliberately from the 2026-09-11 analog: that migration used value-equality
(`=== '[To be confirmed]'`) because it was replacing a known-wrong value; this migration uses
`array_key_exists('contractor_note', $cdm)` because it is closing a missing-key gap, and an
engineer-cleared empty string (`''`) is a legitimate, deliberate value that must survive untouched
— `empty()` would have wrongly treated it as missing.

`tests/Feature/Rams/BackfillCdmContractorNoteMigrationTest.php` (6 tests, mirroring the analog's
`require`-the-migration-file-directly pattern) proves:

1. A row with `cdm_duty_holders` present but no `contractor_note` key gets the constant added.
2. A row with a hand-edited `contractor_note` value is left byte-identical.
3. A row with `contractor_note` already `''` is left untouched, not treated as missing.
4. Running `up()` twice produces zero additional changes (idempotency).
5. A row with no `cdm_duty_holders` key at all is skipped without error.
6. `down()` is a documented no-op (no `->update(` call in its body).

The migration is code-complete but was not run against any database — no `php artisan migrate`
invocation appears anywhere in this plan's execution, per the explicit scope boundary (Plan 29-14 or
a human owns running it after Plan 29-11/29-12 ship the render fix).

## Deviations from Plan

None — plan executed exactly as written. Two tasks, three files, matching the plan's
`files_modified` frontmatter exactly.

## Verification

- `grep -n "public const DEFAULT_CONTRACTOR_NOTE" app/Services/Rams/RamsComplianceUpgradeService.php`
  — exactly 1 match.
- `grep -c "'21CAV is currently anticipated to be the sole contractor"
  app/Services/Rams/RamsComplianceUpgradeService.php` — exactly 1 (the constant declaration only).
- `grep -n "array_key_exists('contractor_note'"
  database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php` — 1 match (exact-key-presence
  guard confirmed, not an `empty()`-based guard).
- `php artisan test tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php` — 13/13 pass, 22
  assertions.
- `php artisan test tests/Feature/Rams/BackfillCdmContractorNoteMigrationTest.php` — 6/6 pass.
- `php artisan test tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php tests/Feature/Rams/BackfillCdmContractorNoteMigrationTest.php tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php`
  — 24/24 pass, 42 assertions, no regression to the existing backfill test.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS surface) — 334/334 pass,
  1523 assertions, 131.91s. No failures; the migration was not run against any database during this
  suite (fixtures use `RefreshDatabase` + factory-created rows only, matching the analog test's own
  pattern).

## Self-Check: PASSED

- FOUND: `app/Services/Rams/RamsComplianceUpgradeService.php` (modified, `DEFAULT_CONTRACTOR_NOTE`
  constant confirmed present at line 1144)
- FOUND: `database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php` (created)
- FOUND: `tests/Feature/Rams/BackfillCdmContractorNoteMigrationTest.php` (created, 6 test methods
  confirmed present and passing)
- FOUND: commit `df4b31c` (Task 1) in `git log` (Rams2 repo root)
- FOUND: commit `f3c29f2` (Task 2) in `git log` (Rams2 repo root)
