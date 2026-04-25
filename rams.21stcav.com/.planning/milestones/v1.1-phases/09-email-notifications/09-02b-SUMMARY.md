---
phase: 09-email-notifications
plan: 02b
subsystem: testing
tags: [laravel, factories, phpunit, test-data]

# Dependency graph
requires:
  - phase: 09-email-notifications
    plan: 01
    provides: "HasFactory trait on RamsDocument + CableSchedule, and new email-timestamp columns (completion_email_sent_at, failed_email_sent_at, review_needed_email_sent_at)"
provides:
  - "RamsDocumentFactory (status defaults to awaiting_review)"
  - "OmManualFactory (status defaults to generating)"
  - "WorksheetFactory (status defaults to generating)"
  - "CableScheduleFactory (uses source_filename, status defaults to generating)"
affects: [09-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Model factories under database/factories/ extending Illuminate\\Database\\Eloquent\\Factories\\Factory"
    - "Lazy FK resolution via User::factory() + Project::factory() callables"
    - "Global fake() helper (Laravel 12 standard) instead of $this->faker"
    - "Status defaults pinned to pre-completion constants so feature tests can transition forward"
    - "Email-timestamp columns left null in factory to preserve 'not yet sent' -> 'sent' assertion semantics"

key-files:
  created:
    - "database/factories/RamsDocumentFactory.php"
    - "database/factories/OmManualFactory.php"
    - "database/factories/WorksheetFactory.php"
    - "database/factories/CableScheduleFactory.php"
  modified: []

key-decisions:
  - "Factories do NOT seed completion_email_sent_at / failed_email_sent_at / review_needed_email_sent_at — nulls are load-bearing for idempotency tests in 09-05"
  - "Factories do NOT seed error_message — null is the default for non-failed states"
  - "Factories do NOT touch email_sent_at (legacy manual-send column on RamsDocument owned by RamsController@email)"
  - "CableScheduleFactory uses source_filename (not filename) — CableSchedule fillable asymmetry documented in 09-RESEARCH"

patterns-established:
  - "Factory pinned to status constant (STATUS_AWAITING_REVIEW / STATUS_GENERATING) so constant renames fail loudly not silently"
  - "FK resolution via nested factories keeps tests composable: $project = Project::factory()->create(); RamsDocument::factory()->create(['project_id' => $project->id])"

requirements-completed: [NOTF-01a, NOTF-01b, NOTF-02a, NOTF-04a]

# Metrics
duration: 10min
completed: 2026-04-19
---

# Phase 09 Plan 02b: Model Factories for Notification Feature Tests Summary

**Four Eloquent factories (RamsDocument, OmManual, Worksheet, CableSchedule) with status defaults pinned to pre-completion constants, enabling 09-05 notification feature tests to assert email-flip transitions.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-04-19T17:21:24Z
- **Completed:** 2026-04-19T17:31:05Z
- **Tasks:** 1
- **Files created:** 4

## Accomplishments
- Created `RamsDocumentFactory` with `status = STATUS_AWAITING_REVIEW` so 09-05 review-needed tests can transition to STATUS_COMPLETED
- Created `OmManualFactory`, `WorksheetFactory`, `CableScheduleFactory` with `status = STATUS_GENERATING` so completion-flip tests can transition to STATUS_DRAFT
- All 4 factories use `User::factory()` / `Project::factory()` for lazy FK resolution — tests can override per-test with explicit IDs
- Zero model files touched — Wave 1 file collision (checker issue B-01) avoided entirely
- PHPUnit Unit suite still green (367/367 passing) after autoloader regeneration

## Task Commits

1. **Task 1: Create 4 model factories** — `e076689` (feat)

## Files Created/Modified

### Created
- `database/factories/RamsDocumentFactory.php` (30 lines) — user_id, project_id, project_ref, project_name, client_name, site_address, filename, status=awaiting_review
- `database/factories/OmManualFactory.php` (30 lines) — user_id, project_id, project_ref, project_name, client_name, site_address, filename, status=generating
- `database/factories/WorksheetFactory.php` (30 lines) — user_id, project_id, project_ref, project_name, client_name, site_address, filename, status=generating
- `database/factories/CableScheduleFactory.php` (29 lines) — user_id, project_id, project_ref, project_name, client_name, **source_filename** (not filename), status=generating

### Modified
- None — B-01 collision-avoidance honored. Any model file edits were pre-shipped by plan 09-01 Task 3 in Wave 1.

## Pre-flight (B-01 Enforcement)

Verified HasFactory trait present on all 4 target models **before** any factory file was created:

| Model | File | Line | Status |
|-------|------|------|--------|
| RamsDocument | `app/Models/RamsDocument.php` | 14 | `use HasFactory, SoftDeletes;` ✅ (added by 09-01 Task 3) |
| CableSchedule | `app/Models/CableSchedule.php` | 13 | `use HasFactory, SoftDeletes;` ✅ (added by 09-01 Task 3) |
| OmManual | `app/Models/OmManual.php` | 26 | `use HasFactory, SoftDeletes;` ✅ (pre-existing) |
| Worksheet | `app/Models/Worksheet.php` | 12 | `use HasFactory, SoftDeletes;` ✅ (pre-existing) |

Wave ordering correct: 09-01 (Wave 1) shipped HasFactory trait + new columns; this plan (Wave 2) shipped factories. Zero file overlap.

## Smoke Test Output (verified against main repo Laravel bootstrap)

The worktree does not have a local `vendor/` directory. To run the acceptance-criteria tinker smoke tests, the 4 factory files were temporarily copied into the main repo (`C:/Users/sonny.tanda/Documents/1 - Claude Projects/Rams2/rams.21stcav.com/`), `composer dump-autoload -o` regenerated, smoke tests run, then the temporary copies were **deleted** and the main-repo autoloader regenerated again (back to 8112 classes, matching pre-verification state). No pollution remained in the main repo (confirmed via `git status` — only pre-existing unrelated items).

### Factory resolves + project_ref/source_filename populated

```
RAMS:96Cr71626
OM:29Cf57134
WS:87Cu04369
CS:cable-schedule-85736a7c-91b8-309a-b1d5-4b0e1d3bc1f6.xlsx
```

### Status defaults correct

```
RAMS.status:awaiting_review   (RamsDocument::STATUS_AWAITING_REVIEW)
OM.status:generating          (OmManual::STATUS_GENERATING)
WS.status:generating          (Worksheet::STATUS_GENERATING)
CS.status:generating          (CableSchedule::STATUS_GENERATING)
```

### Email-timestamp columns NOT pre-seeded (idempotency invariant)

```
RAMS.completion:NULL_OK
RAMS.failed:NULL_OK
RAMS.review:NULL_OK
OM.completion:NULL_OK
WS.completion:NULL_OK
CS.completion:NULL_OK
```

### error_message null defaults

```
RAMS.err:NULL_OK
OM.err:NULL_OK
WS.err:NULL_OK
CS.err:NULL_OK
```

### Unit suite regression

```
PHPUnit 11.5.55
Tests: 367, Assertions: 862 — OK, but there were issues (12 deprecation warnings, pre-existing, unrelated)
```

Exit code 0 — no regressions introduced. The 12 deprecation warnings are pre-existing PHPUnit deprecations unrelated to the new factories.

## Decisions Made

1. **Factory temp-copy verification pattern** — Because the worktree lacks a local `vendor/` and creating a vendor junction to the main repo would risk polluting main (writes through the junction hit the main repo), I copied the 4 factory files into the main repo, ran tinker smoke tests + PHPUnit, then cleaned up with `rm` + `composer dump-autoload -o`. Verified via `git status` that no residue remained in the main repo. This is a verification-only pattern — the canonical copies live ONLY in the worktree commit (`e076689`).
2. **No `@extends Factory<Model>` phpdoc omitted** — ProjectFactory already uses this annotation; mirrored it for consistency on all 4 new factories for IDE support.
3. **No `site_address` on CableScheduleFactory** — CableSchedule `$fillable` does not include `site_address` (research "CableSchedule Asymmetry" in 09-RESEARCH); seeding it would throw a MassAssignmentException if strict mode were enabled.

## Deviations from Plan

None — plan executed exactly as written. All 4 factories match the spec in Task 1 action steps 1-4. No Rule 1/2/3 auto-fixes triggered. No Rule 4 escalations.

## Issues Encountered

- **Worktree lacks local vendor/** — worktrees share git branches but not composer-installed dependencies. Resolved by running tinker/PHPUnit in the main repo with temp-copied factories, then cleaning up (no residual files). PHP `-l` syntax check was run directly in the worktree first to catch any parse errors before the copy step.
- **`cmd //c mklink /J` path escaping** — First junction attempt produced malformed target `\C:\...`. Abandoned junction approach entirely (would have polluted main repo through the link anyway); used temp-copy pattern instead. Documented for future worktree executors.

## User Setup Required

None — no external service configuration required for factory files.

## Threat Flags

None — factories only use the global `fake()` helper (Faker — synthetic data) and lazy FK resolution via nested factories. No new trust-boundary surface introduced. See plan `<threat_model>` for full register.

## Next Phase Readiness

- **Plan 09-05 Task 2 unblocked** — feature tests can now compose owner/project/document relationship chains via `Model::factory()->create([...])` without inventing factory shapes mid-test.
- **No downstream blockers** from this plan.
- **Wave 2 parallelism preserved** — 09-03 (mailables in `app/Mail/`) and 09-04 (mail blade views in `resources/views/emails/`) touch disjoint file trees.

## Self-Check: PASSED

- ✅ `database/factories/RamsDocumentFactory.php` exists (verified via `test -f`)
- ✅ `database/factories/OmManualFactory.php` exists
- ✅ `database/factories/WorksheetFactory.php` exists
- ✅ `database/factories/CableScheduleFactory.php` exists
- ✅ All 4 pass `php -l` syntax check (verified in worktree before copy-out)
- ✅ Commit `e076689` exists (verified via `git log`)
- ✅ Status defaults match plan (awaiting_review / generating / generating / generating)
- ✅ CableScheduleFactory uses `source_filename`, NOT `filename` (positive + negative grep)
- ✅ Email-timestamp columns default to null (6 assertions passed)
- ✅ No model files modified (`git status --short` shows only 4 `??` new factory files)
- ✅ PHPUnit Unit suite passes (367/367) — no regression from the new factories

---
*Phase: 09-email-notifications*
*Plan: 02b*
*Completed: 2026-04-19*
