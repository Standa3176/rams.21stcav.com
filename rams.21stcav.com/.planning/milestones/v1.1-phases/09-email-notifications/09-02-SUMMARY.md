---
phase: 09-email-notifications
plan: 02
subsystem: notifications
tags: [composer, postmark, mailer, recipient-resolver, tdd, unit-tests]
requirements: [NOTF-05a, NOTF-05b, NOTF-05g]

dependency_graph:
  requires:
    - "symfony/mailer (already installed via laravel/framework ^12)"
    - "App\\Models\\Project (owner relation)"
    - "App\\Models\\User (role column, isAdmin helper)"
  provides:
    - "symfony/postmark-mailer transport (unblocks MAIL_MAILER=postmark runtime)"
    - "App\\Services\\NotificationRecipientResolver (central recipient rule for 6 trigger sites)"
  affects:
    - "Phase 09-04 (SurveyService refactor — will replace latent bug idiom with resolver call)"
    - "Phase 09-03 / 09-05 (all mailable dispatch sites — will call resolver)"

tech_stack:
  added:
    - "symfony/postmark-mailer v8.0.4"
    - "symfony/http-client v8.0.8"
  patterns:
    - "TDD (RED commit then GREEN commit)"
    - "Single-responsibility service, no interface, no SP binding — container autowires"
    - "RefreshDatabase + SQLite in-memory for resolver unit tests"

key_files:
  created:
    - "app/Services/NotificationRecipientResolver.php (87 lines, 2 public methods)"
    - "tests/Unit/Services/NotificationRecipientResolverTest.php (180 lines, 7 tests)"
    - ".planning/phases/09-email-notifications/deferred-items.md (pre-existing Claude test failures)"
  modified:
    - "composer.json (added 2 symfony packages to require block)"
    - "composer.lock (pinned resolved versions + transitive deps)"

decisions:
  - "Resolver treats empty-string email as 'no email' (not just null) — users.email is NOT NULL at schema level so the real-world 'no email' state is an empty string. Prevents silent mail-send failures to ''."
  - "Test 'no-owner' cases use Project::factory()->make() (unsaved model) rather than user_id=null — projects.user_id is NOT NULL with cascadeOnDelete FK, so null user_id cannot be persisted. The in-memory model exactly mirrors the production 'job-failed alert without project context' code path."
  - "No container binding — Laravel autowires the zero-dep constructor via app(NotificationRecipientResolver::class). Same pattern used by HardwareClassificationService and others in this repo."

metrics:
  duration: "~14 minutes"
  completed_date: "2026-04-19"
  tasks_completed: 2
  tasks_total: 2
  commits: 3
---

# Phase 09 Plan 02: Postmark Transport + Recipient Resolver Summary

Installed Symfony Postmark mailer transport and created the centralized `NotificationRecipientResolver` service that every Phase 09 trigger site will call; 7 unit tests lock the canonical User->role / Project->owner names against the two latent bugs currently living in `SurveyService::submitPublic()`.

## What changed

### Task 1 — Composer package install (commit `77acbd1`)

Ran `composer require symfony/postmark-mailer symfony/http-client` at the repo root. Resolved versions (from `composer.lock`):

| Package | Version | Role |
|---|---|---|
| `symfony/postmark-mailer` | **v8.0.4** | Symfony mailer bridge for Postmark; unlocks `MAIL_MAILER=postmark` at runtime (NOTF-05g). |
| `symfony/http-client` | **v8.0.8** | HTTP transport required by postmark-mailer; explicit-added to keep the lockfile deterministic. |

Composer also pulled 134 transitive packages (most are dev-deps like phpunit, pint, breeze that were previously missing from `vendor/` in this worktree — no code impact). `config/mail.php` and `config/services.php` already had the `postmark` transport + `POSTMARK_API_KEY` wiring, so no edits were needed there. `.env` is untouched — env-var setup belongs to plan 09-06.

`php artisan config:clear` ran clean.

### Task 2 — NotificationRecipientResolver + unit tests (commits `27de1d6` RED, `477e196` GREEN)

`app/Services/NotificationRecipientResolver.php` (87 lines) exposes two public methods:

- `resolveProjectRecipient(?Project $project): ?User` — three-branch fallback: (1) project owner with non-empty email → (2) first admin by `role = 'admin'` with non-empty email, ordered by id → (3) null.
- `resolveAdminRecipients(): Collection<User>` — every admin with a non-empty email; used by NOTF-05b failure-alert broadcasts.

Both methods filter `whereNotNull('email')->where('email', '!=', '')` because the `users.email` column is `NOT NULL` at the schema level, so "no email" means an empty string in practice. A mailable send to `''` would hard-fail at runtime, so this filter is a correctness requirement (Rule 2 auto-add).

`tests/Unit/Services/NotificationRecipientResolverTest.php` (180 lines) — 7 tests, 16 assertions, all GREEN:

1. `test_returns_owner_when_project_has_owner_with_email`
2. `test_falls_back_to_first_admin_when_project_owner_is_null`
3. `test_returns_first_admin_when_project_argument_is_null`
4. `test_falls_back_to_admin_when_project_owner_has_no_email`
5. `test_returns_null_when_no_owner_and_no_admin`
6. `test_admin_lookup_uses_role_column_not_is_admin` — **locks RESEARCH Pitfall 1** (SurveyService bug)
7. `test_resolve_admin_recipients_returns_only_admins_with_email`

Uses `RefreshDatabase` + SQLite in-memory so real Eloquent queries exercise the schema; SurveyService's `User::where('is_admin', true)` would fail instantly against this schema, which is the whole point of the regression-lock tests.

## Bug-pattern regression locks

Verified via negative greps on the resolver source:

| Forbidden pattern | Check | Result |
|---|---|---|
| `is_admin` (wrong column) | `! grep -q 'is_admin' app/Services/NotificationRecipientResolver.php` | PASS (0 matches) |
| `->user` (wrong relation) | `! grep -qF -- '->user' app/Services/NotificationRecipientResolver.php` | PASS (0 matches) |

Positive asserts:

| Required pattern | Check | Result |
|---|---|---|
| `where('role', 'admin')` | `grep -q "where('role', 'admin')" app/Services/NotificationRecipientResolver.php` | PASS (2 matches, lines 64 + 82) |
| `loadMissing('owner')` | `grep -qF -- "loadMissing('owner')" app/Services/NotificationRecipientResolver.php` | PASS |
| `class NotificationRecipientResolver` | `grep -q "class NotificationRecipientResolver" app/Services/NotificationRecipientResolver.php` | PASS |

Both `deferred-items.md` and `09-RESEARCH.md` reference the SurveyService idiom explicitly so the 09-04 refactor agent has clear context on what to replace.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing critical functionality] Filter empty-string emails from admin query**

- **Found during:** Task 2 TDD RED → GREEN iteration (first test run revealed `users.email` is NOT NULL at schema level).
- **Issue:** The plan's Example 1 body used `whereNotNull('email')` only. But because the column is NOT NULL, the "no email" state a mailable would actually encounter is an empty string, not null. Querying `whereNotNull('email')` would happily return a User with `email = ''`, which would then crash the mailer with `InvalidAddressException: "An email must have a "@" and a domain."`.
- **Fix:** Added `->where('email', '!=', '')` to both `resolveProjectRecipient` and `resolveAdminRecipients`. Resolver now returns only users whose email is a plausible send target.
- **Files modified:** `app/Services/NotificationRecipientResolver.php` (lines 65, 83), `tests/Unit/Services/NotificationRecipientResolverTest.php` (tests 4 + 7 rewritten to use empty-string).
- **Commit:** `477e196` (bundled with GREEN).

**2. [Rule 3 — Blocking] Replaced user_id=null tests with Project::factory()->make() (in-memory)**

- **Found during:** Task 2 test run — two tests errored with `NOT NULL constraint failed: projects.user_id`.
- **Issue:** Plan's `<behavior>` section described tests creating "Project with user_id = null". But `projects.user_id` is `foreignId()->constrained()->cascadeOnDelete()` in the create migration — NOT NULL with FK enforcement. The `->create(['user_id' => null])` idiom cannot be persisted.
- **Fix:** Tests 2 and 5 now use `Project::factory()->make()` + `$project->user_id = null` (unsaved model, no DB row). Functionally identical to a project whose owner row was removed — `loadMissing('owner')` returns null on the unsaved model exactly as it would for an orphaned-FK scenario. Mirrors the real-world code path where a `JobFailed` event handler alerts admins without a project context.
- **Files modified:** `tests/Unit/Services/NotificationRecipientResolverTest.php` (tests 2 + 5).
- **Commit:** `477e196`.

### Deferred to separate task (out of scope)

**Pre-existing `MethodStatementFallbackTest` failures** — documented in `.planning/phases/09-email-notifications/deferred-items.md`. Verified these 4 errors exist on base commit `d34600d` via `git stash` + re-run, so they are not caused by this plan's work. Unrelated to email notifications.

## Authentication gates

None — all work in this plan was code-only, no external auth or API calls.

## Verification

- [x] `composer show symfony/postmark-mailer` → v8.0.4 metadata printed
- [x] `composer show symfony/http-client` → v8.0.8 metadata printed
- [x] `grep -q "symfony/postmark-mailer" composer.json` → pass
- [x] `grep -q "symfony/postmark-mailer" composer.lock` → pass
- [x] `grep -q "symfony/http-client" composer.lock` → pass
- [x] `php artisan config:clear` → "Configuration cache cleared successfully"
- [x] `vendor/bin/phpunit tests/Unit/Services/NotificationRecipientResolverTest.php` → 7 passing, 16 assertions
- [x] `vendor/bin/phpunit --testsuite=Unit` → 367 tests, 832 assertions, 4 pre-existing errors (all in `tests/Unit/Rams/MethodStatementFallbackTest.php`, unrelated to this plan — see deferred-items.md)
- [x] All negative greps pass: `is_admin` and `->user` absent from resolver source
- [x] `class NotificationRecipientResolver`, `where('role', 'admin')`, `loadMissing('owner')` all present

## Success criteria (from plan)

| Criterion | Status |
|---|---|
| Postmark transport package installed; `MAIL_MAILER=postmark` would no longer throw "Unsupported transport scheme" | ✅ |
| `NotificationRecipientResolver` exists and is unit-tested | ✅ (7 tests) |
| `SurveyService` refactor (NOTF-02a) in plan 09-04 can now `app(NotificationRecipientResolver::class)` to fix the latent admin-fallback bug | ✅ (service + tests ready; 09-04 will consume) |
| All downstream Phase 09 trigger sites have a single, tested recipient resolution path | ✅ |

## Self-Check: PASSED

**Files claimed to exist:**

- `app/Services/NotificationRecipientResolver.php` — FOUND
- `tests/Unit/Services/NotificationRecipientResolverTest.php` — FOUND
- `.planning/phases/09-email-notifications/deferred-items.md` — FOUND

**Commits claimed to exist:**

- `77acbd1` (Task 1 install) — FOUND in `git log`
- `27de1d6` (Task 2 RED) — FOUND in `git log`
- `477e196` (Task 2 GREEN) — FOUND in `git log`

No missing items.
