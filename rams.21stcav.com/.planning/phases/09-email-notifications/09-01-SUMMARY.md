---
phase: 09-email-notifications
plan: 01
subsystem: database
tags: [laravel, migration, eloquent, mass-assignment, idempotency, phase-09, notifications]

# Dependency graph
requires:
  - phase: "08-enterprise-dashboard"
    provides: "working RamsDocument/OmManual/Worksheet/CableSchedule pipeline with status constants"
provides:
  - "completion_email_sent_at timestamp column on rams_documents, om_manuals, worksheets, cable_schedules"
  - "failed_email_sent_at timestamp column on the same 4 tables"
  - "review_needed_email_sent_at timestamp column on rams_documents (NOTF-03c)"
  - "cable_schedules.error_message string(1000) nullable column (RESEARCH Pitfall 3, required by NOTF-04c)"
  - "config('rams.notifications.bcc') config key bound to RAMS_NOTIFICATION_BCC env var"
  - ".env.example documented placeholders for POSTMARK_API_KEY, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, RAMS_NOTIFICATION_BCC"
  - "Eloquent mass-assignment wiring (fillable + datetime casts) for every new email-timestamp column"
  - "HasFactory trait on RamsDocument and CableSchedule (unblocks 09-02b Wave 2 factories)"
affects: [09-02, 09-02b, 09-03, 09-04, 09-05, 09-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pair-of-timestamps idempotency guard (completion_email_sent_at / failed_email_sent_at) as the single source of truth for 'has the notification fired?'"
    - "Nullable string(1000) error_message column convention for failure-path surfacing into email bodies"

key-files:
  created:
    - "database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php"
  modified:
    - "config/rams.php (+15 lines: notifications.bcc)"
    - ".env.example (+9 lines: Phase 09 mail block)"
    - "app/Models/RamsDocument.php (+11/-3 lines: HasFactory, 3 new fillables, 3 new casts)"
    - "app/Models/OmManual.php (+7/-1 lines: 2 fillables, 2 casts)"
    - "app/Models/Worksheet.php (+5/-1 lines: 2 fillables, 2 casts)"
    - "app/Models/CableSchedule.php (+15/-1 lines: HasFactory, 3 fillables, casts() method)"

key-decisions:
  - "Kept existing rams_documents.email_sent_at untouched; new completion_email_sent_at is the automated-trigger peer (D-13)"
  - "Never drop cable_schedules.error_message on rollback — preserves operational data captured between deploys (RESEARCH Example 4 footnote)"
  - "Used POSTMARK_API_KEY (not POSTMARK_TOKEN) to match existing config/services.php wiring (RESEARCH item 3)"
  - "Moved HasFactory trait additions from plan 09-02b into this plan (B-01) to avoid Wave 1/Wave 2 file collision on RamsDocument/CableSchedule"
  - "Guarded cable_schedules.error_message column add with Schema::hasColumn() so migration is idempotent on re-run"

patterns-established:
  - "Idempotency timestamp pairs per notifiable model: `{completion,failed}_email_sent_at` + optional `review_needed_email_sent_at` for models with a review phase"
  - "Shared notification BCC resolved at send time via `config('rams.notifications.bcc')` — avoids hard-coded addresses; null/empty = skip"

requirements-completed: [NOTF-01c, NOTF-03c, NOTF-04b, NOTF-04c, NOTF-05d]

# Metrics
duration: ~25min
completed: 2026-04-19
---

# Phase 09 Plan 01: Foundations Summary

**DB + config + model-fillable scaffolding for Phase 09 email notifications — 10 new timestamp/error columns across 4 tables, new `rams.notifications.bcc` config key, `.env.example` Postmark placeholders, and HasFactory trait on RamsDocument + CableSchedule so 09-02b's factories can resolve.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-04-19T17:12:20Z
- **Tasks:** 3 / 3
- **Files modified:** 6 (1 created, 5 edited)

## Accomplishments

- Shipped one multi-table migration that is fully reversible (email-timestamp columns drop on rollback; cable_schedules.error_message intentionally preserved).
- Added 9 email-timestamp columns + 1 cable_schedules.error_message column; verified every column is present via `Schema::hasColumn()` smoke checks.
- Wired every new column into the owning model's `$fillable` and `$casts` so Eloquent mass-assignment actually persists idempotency writes (otherwise `$record->update(['completion_email_sent_at' => now()])` would silently drop and T-09-05 cost runaway would hit — see threat register).
- Added `HasFactory` trait to `RamsDocument` and `CableSchedule` per B-01 checker fix, so plan 09-02b (moved to Wave 2) can ship factories without re-touching these model files in the same wave.
- Added `config('rams.notifications.bcc')` bound to `RAMS_NOTIFICATION_BCC` env var; `.env.example` documents the 4 new Phase 09 env vars (`POSTMARK_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `RAMS_NOTIFICATION_BCC`).

## Task Commits

1. **Task 1: Create the Phase 09 multi-table migration** — `2edea38` (feat)
2. **Task 2: Add notifications.bcc config key + document new env vars** — `5b50fc6` (feat)
3. **Task 3: Update model `$fillable` + `$casts` + add HasFactory trait to RamsDocument & CableSchedule** — `a6c5727` (feat)

## Files Created/Modified

- **Created:** `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php` — one multi-table migration adding 9 timestamp columns + 1 string column; reversible; rollback preserves `cable_schedules.error_message`.
- **Modified:** `config/rams.php` — appended `notifications` array with `bcc => env('RAMS_NOTIFICATION_BCC')`. All existing `company_*` keys untouched.
- **Modified:** `.env.example` — appended "Phase 09: Email Notifications" block with `POSTMARK_API_KEY=`, `MAIL_FROM_ADDRESS=rams@21stcav.com`, `MAIL_FROM_NAME="RAMS Platform"`, `RAMS_NOTIFICATION_BCC=`.
- **Modified:** `app/Models/RamsDocument.php` — added `use Illuminate\Database\Eloquent\Factories\HasFactory;` import, changed `use SoftDeletes;` → `use HasFactory, SoftDeletes;`; added three fillables (`completion_email_sent_at`, `failed_email_sent_at`, `review_needed_email_sent_at`); added three `'datetime'` casts.
- **Modified:** `app/Models/OmManual.php` — extended `$fillable` + `casts()` with `completion_email_sent_at` + `failed_email_sent_at`. HasFactory already present (no trait change).
- **Modified:** `app/Models/Worksheet.php` — extended `$fillable` + `casts()` with `completion_email_sent_at` + `failed_email_sent_at`. HasFactory already present (no trait change).
- **Modified:** `app/Models/CableSchedule.php` — added `use Illuminate\Database\Eloquent\Factories\HasFactory;` import, changed `use SoftDeletes;` → `use HasFactory, SoftDeletes;`; added three fillables (`completion_email_sent_at`, `failed_email_sent_at`, `error_message`); added a new `casts()` method mirroring the OmManual/Worksheet modern style.

## Migration Details

**Filename:** `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php`

**Columns added (up path):**

| Table | Column | Type | Nullable |
|-------|--------|------|----------|
| `rams_documents` | `completion_email_sent_at` | timestamp | YES |
| `rams_documents` | `failed_email_sent_at` | timestamp | YES |
| `rams_documents` | `review_needed_email_sent_at` | timestamp | YES |
| `om_manuals` | `completion_email_sent_at` | timestamp | YES |
| `om_manuals` | `failed_email_sent_at` | timestamp | YES |
| `worksheets` | `completion_email_sent_at` | timestamp | YES |
| `worksheets` | `failed_email_sent_at` | timestamp | YES |
| `cable_schedules` | `error_message` | string(1000) | YES (guarded by `Schema::hasColumn` for idempotency) |
| `cable_schedules` | `completion_email_sent_at` | timestamp | YES |
| `cable_schedules` | `failed_email_sent_at` | timestamp | YES |

**Rollback behaviour (down path):**
- Drops the 9 email-timestamp columns + `rams_documents.review_needed_email_sent_at`.
- Leaves `cable_schedules.error_message` in place (never dropped — footnote in RESEARCH Example 4).

**Verification performed:**
- `php artisan migrate --pretend --path=...` → correct SQL generated.
- `php artisan migrate --path=...` → runs clean (218ms).
- All 10 columns confirmed present via `Schema::hasColumn` round-trip.
- `php artisan migrate:rollback --path=...` → runs clean (219ms); `rams_documents.completion_email_sent_at` removed, `cable_schedules.error_message` preserved as designed.
- Re-ran migration to leave DB in migrated state for downstream plans.

## cable_schedules.error_message — Confirmation

- Added to the DB table by the migration (guarded by `Schema::hasColumn('cable_schedules', 'error_message')` so re-run is safe).
- Added to `CableSchedule::$fillable` so the 09-05 failure-alert path can write it via `$record->update(['error_message' => …])`.
- NOT added to `$casts` (it's a plain string — default cast behaviour is correct).
- NOT dropped on migration rollback (operational data preservation).

## HasFactory Trait — Confirmation (B-01 fix)

| Model | Before | After |
|-------|--------|-------|
| `RamsDocument` | `use SoftDeletes;` (HasFactory absent) | `use HasFactory, SoftDeletes;` + import added |
| `OmManual` | `use HasFactory, SoftDeletes;` | unchanged |
| `Worksheet` | `use HasFactory, SoftDeletes;` | unchanged |
| `CableSchedule` | `use SoftDeletes;` (HasFactory absent) | `use HasFactory, SoftDeletes;` + import added |

Grep confirms `use Illuminate\Database\Eloquent\Factories\HasFactory;` import and `use HasFactory, SoftDeletes;` trait line are present in both `RamsDocument.php` and `CableSchedule.php`. This unblocks plan 09-02b (now Wave 2) to ship factories without re-touching these files.

## Model Fillable + Casts — Verification

All 10 checks run via `artisan tinker` expressions returning single-word success codes:

| Check | Result |
|-------|--------|
| `isFillable('completion_email_sent_at')` × 4 models + `isFillable('review_needed_email_sent_at')` × RamsDocument + `isFillable('error_message')` × CableSchedule | `ALL_FILLABLE_OK` |
| `getCasts()['…'] === 'datetime'` for all 9 email-timestamp casts | `ALL_CASTS_OK` |

**Round-trip smoke (update-restore on existing record):**
The plan's fresh-`create([])` smoke was intentionally swapped for a non-destructive **update + refresh + restore** on an existing `rams_documents` row, per the plan's W-02 fallback. Reason: the dev DB enforces NOT-NULL on legacy columns outside this plan's scope (`ai_provider`, `form_data`, …), which would make a fresh-create test brittle across environments. The update-restore proof is stronger for the design under test (it exercises the exact Eloquent path plan 09-05 will use) and printed `PERSISTED_AND_CAST` — Carbon instances were present on read-back for all 3 columns.

**Unit test regression check:** `vendor/bin/phpunit --testsuite=Unit` → `360/360 passing` (12 pre-existing PHPUnit deprecations, unrelated to this plan).

## Config + Env — Verification

- `php artisan tinker → array_key_exists('notifications', config('rams'))` → `OK`
- `config('rams.notifications.bcc')` with env unset → `null` (correct dev default)
- `config('rams.company_short')` → `21CAV` (existing keys untouched)
- `.env.example` greps confirm: `RAMS_NOTIFICATION_BCC=`, `POSTMARK_API_KEY=`, `MAIL_FROM_ADDRESS=rams@21stcav.com`, `MAIL_FROM_NAME="RAMS Platform"`. `POSTMARK_TOKEN` NOT present (correctly).

## Decisions Made

| # | Decision | Rationale |
|---|---------|-----------|
| 1 | Migration filename kept as `2026_04_19_000001_…` per plan spec | Plan's `files_modified` pinned this exact name; recent migrations mix `_000001_` and `_HHMMSS_` styles so either is acceptable. |
| 2 | `cable_schedules.error_message` column-add wrapped in `Schema::hasColumn()` guard | Idempotent re-run safety; zero cost if column already exists. |
| 3 | Rollback does NOT drop `cable_schedules.error_message` | Operational-data preservation — losing captured error strings on a deploy-rollback would make incident postmortems harder. |
| 4 | Update-restore smoke instead of fresh-create | Dev DB has NOT-NULL constraints on legacy columns outside this plan's scope; update-restore exercises the exact idempotency path 09-05 will use, non-destructively. |

## Deviations from Plan

**1. [Rule 3 - Blocking Issue] Worktree base was ancestor of expected base**
- **Found during:** Pre-execution worktree_branch_check
- **Issue:** Worktree branch was based on commit `6f23f37` (an ancestor of the expected base `d34600d` by 5 commits). Planning files for phase 09 did not exist at `6f23f37`.
- **Fix:** Ran `git reset --hard d34600d833305cffa8d4c30a294706312938a6ac` per the worktree_branch_check protocol to align the working tree with the expected base.
- **Files modified:** None (git state change only)
- **Verification:** `git merge-base HEAD d34600d` → prints `d34600d`; planning files at `.planning/phases/09-email-notifications/` now present.
- **Committed in:** N/A (pre-execution setup, no file changes)

**2. [Rule 3 - Blocking Issue] Worktree had no vendor/ or .env**
- **Found during:** Task 1 verification (`php artisan migrate:status`)
- **Issue:** This is a git worktree spun up for parallel execution; it has no `composer install` and no `.env`, so artisan commands failed immediately.
- **Fix:** Symlinked `vendor/`, `.env`, and `database/database.sqlite` from the main repo checkout. All three paths are gitignored (`.gitignore` rules verified via `git check-ignore -v`), so they do not land in any commit.
- **Files modified:** None committed (symlinks are gitignored)
- **Verification:** `php artisan migrate:status` produced the expected output; target tables confirmed present in shared DB.
- **Committed in:** N/A (infra setup, no file changes)

**3. [Design choice — NOT a deviation] Round-trip smoke substitution**
- **Rationale:** The plan itself (task 3 acceptance criteria) explicitly permits this substitution under the W-02 fallback when fresh-create is brittle. Captured in the verification notes above. Not counted as a deviation — plan-authorised path taken.

**Total deviations:** 2 infra-setup auto-fixes (both Rule 3, both documented, neither touches code).
**Impact on plan:** Zero scope creep. Plan executed exactly as written; the two deviations are pure environment setup that did not change any file in the commit history.

## Issues Encountered

- `php artisan tinker --execute="…"` with multi-line scripts produced no output in this shell; worked around with single-expression tinker queries and a temporary `_smoke_phase_09_01.php` script (removed before commit, not tracked).

## User Setup Required

None — no external service configuration required for this plan. Runtime setup (Postmark API key, BCC address) is documented in `.env.example` but not required until later Phase 09 plans wire up the actual dispatcher.

## Next Phase Readiness

- Phase 09 Wave 1 companion plan (09-02) can proceed in parallel — it only touches composer dependencies + new service classes, no file overlap.
- Phase 09 Wave 2 plan (09-02b, factories) can now resolve `RamsDocument::factory()` and `CableSchedule::factory()` — HasFactory traits are in place.
- Phase 09 Wave 3+ plans (09-03, 09-04, 09-05, 09-06) can rely on:
  - `$record->completion_email_sent_at === null` idempotency guard (and its `failed_*` / `review_needed_*` peers).
  - `$record->update(['completion_email_sent_at' => now()])` actually persisting (fillable + datetime cast wired).
  - `config('rams.notifications.bcc')` as the single BCC resolution point.
  - `CableSchedule->error_message` being a real, mass-assignable column.

## Self-Check

Files exist (verified via filesystem):
- FOUND: `rams.21stcav.com/database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php`
- FOUND: `rams.21stcav.com/config/rams.php` (modified, `notifications.bcc` key present)
- FOUND: `rams.21stcav.com/.env.example` (modified, 4 Phase 09 env vars documented)
- FOUND: `rams.21stcav.com/app/Models/RamsDocument.php` (modified, HasFactory + 3 new fillables/casts)
- FOUND: `rams.21stcav.com/app/Models/OmManual.php` (modified, 2 new fillables/casts)
- FOUND: `rams.21stcav.com/app/Models/Worksheet.php` (modified, 2 new fillables/casts)
- FOUND: `rams.21stcav.com/app/Models/CableSchedule.php` (modified, HasFactory + 3 fillables + casts() method)

Commits exist (verified via `git log`):
- FOUND: `2edea38` — feat(09-01): add phase 09 email-timestamp columns migration
- FOUND: `5b50fc6` — feat(09-01): add notifications.bcc config key + document phase 09 env vars
- FOUND: `a6c5727` — feat(09-01): wire phase 09 email-timestamp columns into model fillable + casts

## Self-Check: PASSED

---
*Phase: 09-email-notifications*
*Plan: 01*
*Completed: 2026-04-19*
