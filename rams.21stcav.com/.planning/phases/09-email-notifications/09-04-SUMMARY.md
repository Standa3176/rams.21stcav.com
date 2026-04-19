---
phase: 09-email-notifications
plan: 04
subsystem: notifications
tags: [mailable, blade, polymorphic-mail, shouldqueue, bug-fix, recipient-resolver]
requirements: [NOTF-02, NOTF-02a, NOTF-03, NOTF-03a, NOTF-03b, NOTF-04, NOTF-04a, NOTF-04c, NOTF-05a, NOTF-05b, NOTF-05e, NOTF-05f, NOTF-05h]

dependency_graph:
  requires:
    - "App\\Models\\RamsDocument"
    - "App\\Services\\NotificationRecipientResolver (from 09-02)"
    - "route('rams.review', $rams) (existing)"
    - "resources/views/emails/rams-document.blade.php (canonical wrapper reference)"
  provides:
    - "App\\Mail\\RamsReviewNeededMail (dispatched from ExtractRamsDraftJob success in 09-05)"
    - "App\\Mail\\DocumentGenerationFailedMail (polymorphic; dispatched from each Build*Job::failed() in 09-05)"
    - "emails.rams-review-needed view"
    - "emails.document-generation-failed view"
  affects:
    - "Phase 09-05 (all 4 Build*Job trigger sites consume DocumentGenerationFailedMail; ExtractRamsDraftJob consumes RamsReviewNeededMail)"
    - "SurveyService::submitPublic() now uses central resolver — 2 latent bugs fixed as a side effect"
    - "SiteSurveyController::authorizeSurvey() now uses User::isAdmin() — 1 latent bug fixed as a side effect (B-02)"

tech_stack:
  added: []
  patterns:
    - "ShouldQueue on all non-trivial mailables (background dispatch, queue workers handle send)"
    - "Primitive-arg mailable for polymorphic failure alert (avoids SerializesModels quirks)"
    - "Mirrored HTML wrapper across mail templates for visual consistency (I-07)"
    - "Failure-red (#b91c1c) header for failure emails (I-01 opt-in)"

key_files:
  created:
    - "app/Mail/RamsReviewNeededMail.php (44 lines)"
    - "app/Mail/DocumentGenerationFailedMail.php (54 lines)"
    - "resources/views/emails/rams-review-needed.blade.php (80 lines)"
    - "resources/views/emails/document-generation-failed.blade.php (97 lines)"
  modified:
    - "app/Core/Modules/Survey/SurveyService.php (5-line diff: resolver swap inside submitPublic)"
    - "app/Http/Controllers/SiteSurveyController.php (1-line diff: is_admin → isAdmin())"
    - ".planning/phases/09-email-notifications/deferred-items.md (logged untracked storage/worker-heartbeat)"

decisions:
  - "I-01 opt-in: failure mail uses red header (#b91c1c) instead of teal; keeps canonical wrapper structure intact so the email still reads as part of the same product."
  - "DocumentGenerationFailedMail uses primitive constructor args (string documentType + 4 other scalars) rather than one subclass per doc type — one class covers RAMS/O&M/Worksheet/Cable polymorphically and avoids SerializesModels quirks per RESEARCH guidance."
  - "SurveyService refactor uses inline FQCN (`\\App\\Services\\NotificationRecipientResolver::class`) to keep the diff surgical (5 lines, no use-statement reorder)."
  - "SiteSurveyController fix is a single character change (`is_admin` → `isAdmin()`) using the already-present User::isAdmin() method — no User model edit required."

metrics:
  duration: "~12 minutes"
  completed_date: "2026-04-19"
  tasks_completed: 3
  tasks_total: 3
  commits: 3
---

# Phase 09 Plan 04: Non-Completion Mailables + Latent-Bug Refactor Summary

Built the two non-completion mailables (`RamsReviewNeededMail`, polymorphic `DocumentGenerationFailedMail`) and the associated Blade templates that mirror the canonical wrapper, then refactored `SurveyService::submitPublic()` and `SiteSurveyController::authorizeSurvey()` to use the centralized resolver / existing `User::isAdmin()` method — fixing three latent bugs (`Project::with('user')`, `User::where('is_admin', true)`, `->is_admin` accessor) from the `app/` codebase in one pass.

## What changed

### Task 1 — `RamsReviewNeededMail` + Blade template (commit `05a3982`)

- **`app/Mail/RamsReviewNeededMail.php` (44 lines).** `extends Mailable implements ShouldQueue`. Single constructor arg `RamsDocument $rams`. Subject `"[{ref}] RAMS ready for review — {project_name}"` (brackets omitted when no ref). No `attachments()` method — the awaiting-review stage is pre-generation so no artifact exists yet.
- **`resources/views/emails/rams-review-needed.blade.php` (80 lines).** Mirrors the canonical wrapper from `rams-document.blade.php` verbatim: DOCTYPE / html / head / body / outer `<table width="100%">` / inner `<table width="600">` / brand-coloured `#007B8A` header with `config('rams.company_name')` and sub-line "RAMS Awaiting Review". Body contains the literal `route('rams.review', $rams)` call (satisfies NOTF-03b grep-lock).

### Task 2 — `DocumentGenerationFailedMail` (polymorphic) + Blade template (commit `a444e88`)

- **`app/Mail/DocumentGenerationFailedMail.php` (54 lines).** `extends Mailable implements ShouldQueue`. Primitive constructor args: `string $documentType`, `?string $projectRef`, `string $projectName`, `?string $errorMessage`, `string $detailUrl`. No Eloquent model import — intentionally polymorphic across the four Build*Job pipelines without SerializesModels hazards (RESEARCH "Recommended Class Structure" guidance). Subject `"[FAILED] [{ref}] {documentType} generation failed — {projectName}"` — the `[FAILED]` prefix simplifies admin inbox filtering.
- **`resources/views/emails/document-generation-failed.blade.php` (97 lines).** Same canonical wrapper as Task 1 but with **I-01 opt-in**: header + section accents in failure-red `#b91c1c` instead of teal. Body renders all four NOTF-04c-required fields: `documentType`, `projectRef`, `projectName`, `errorMessage` (escaped via `{{ }}` inside a `<pre>` block), and `detailUrl` (rendered as both a call-to-action button and a fallback plain-text link). Error-message fallback text `"(no error message captured — see laravel.log for stack trace)"` handles the null case.

### Task 3 — SurveyService + SiteSurveyController refactor (commit `8d32b61`)

**Edit 1 — `app/Core/Modules/Survey/SurveyService.php` (lines 404–411, 5-line diff)** — NOTF-02a

```diff
-                $project   = Project::with('user')->find($result->project_id);
-                $recipient = $project?->user ?? User::where('is_admin', true)->first();
+                $project   = Project::find($result->project_id);
+                $recipient = app(\App\Services\NotificationRecipientResolver::class)
+                    ->resolveProjectRecipient($project);
                 if ($recipient?->email) {
                     $completed = $result->rooms()->where('is_completed', true)->count();
                     Mail::to($recipient->email)->send(new SurveySubmittedMail($result, $completed));
                 }
```

Both latent bugs removed in one stroke: the non-existent `Project::user()` relation (only `owner()` exists) and the non-existent `is_admin` column (only `role` exists). The resolver's admin-fallback uses `where('role', 'admin')` per plan 09-02's unit tests.

**Edit 2 — `app/Http/Controllers/SiteSurveyController.php` (line 449, 1-line diff)** — B-02

```diff
-        abort_unless($survey->user_id === auth()->id() || auth()->user()?->is_admin, 403);
+        abort_unless($survey->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);
```

`is_admin` is not a column on the `users` table and is not a magic accessor on the User model — the previous expression silently evaluated to `null`, making the OR clause always fall through and silently denying admins access to colleagues' site surveys (availability issue, not escalation). `isAdmin()` is the canonical method defined at `User.php:45` that returns `$this->role === 'admin'`.

## Bug-pattern regression locks (codebase-wide)

After both Task 3 edits, the three latent bug patterns are now absent from the entire `app/` codebase:

| Forbidden pattern | Codebase-wide check | Result |
|---|---|---|
| `is_admin` (wrong column/accessor) | `! grep -rE "is_admin" app/ --include='*.php'` | PASS (0 matches) |
| `Project::with('user')` (wrong eager-load relation) | `! grep -rE "Project::with\('user'\)" app/ --include='*.php'` | PASS (0 matches) |
| `$project->user()` literal (wrong relation method) | `! grep -rEn '\$project->user\(\)' app/ --include='*.php'` | PASS (0 matches) |

**Grep refinement note:** The plan's third check used `\\\$project->user\\(\\)` which, after shell escape collapse, became `\$project->user\(\)` — this regex matches `->user()` on ANY left-hand-side variable (e.g., `$request->user()`, `auth()->user()`), producing a long list of legitimate false-positives. Tightening to the literal `$project->user()` (using `grep -rEn '\$project->user\(\)'`) yields the intended negative check with zero matches. The resolver unit test in 09-02 Task 2 (`test_admin_lookup_uses_role_column_not_is_admin`) already locks the canonical pattern, so this refinement is just a cleaner CI check.

Positive grep confirming the SiteSurveyController fix landed:

```
$ grep -n "isAdmin()" app/Http/Controllers/SiteSurveyController.php | grep "449"
449:        abort_unless($survey->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);
```

## Threat model verification

All threat-register mitigations in the plan's `<threat_model>` are honored:

- **T-09-01 (tampering / info disclosure via errorMessage):** The failure Blade renders `{{ $errorMessage }}` with default Blade escape inside a `<pre>` block — HTML-escaped, never raw. Caller (plan 09-05) owns the 500-char truncation.
- **T-09-02 (info disclosure via review link):** `route('rams.review', $rams)` is gated by `RamsDocumentPolicy` + auth middleware at the controller; the URL alone leaks no content.
- **T-09-02 (SurveyService recipient):** Inline latent-bug lookup replaced by resolver that uses canonical `Project->owner` + `User::where('role', 'admin')` paths.
- **T-09-07 (SiteSurveyController auth):** `isAdmin()` method swap restores the intended admin-access rule (was silently denying admins; no escalation existed).
- **T-09-06 (polymorphic failure-mail constructor):** All construction sites are server-side trigger hooks in plan 09-05 — no user input reaches the constructor; inputs are model fields validated on intake.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `composer install` + `.env` setup in fresh worktree**

- **Found during:** Task 1 verification (`php -r "require 'vendor/autoload.php'"` errored — no `vendor/` directory in this worktree).
- **Issue:** The worktree was spawned from the shared merge base without vendor deps, so autoload / Tinker / phpunit could not run.
- **Fix:** `composer install --no-interaction --prefer-dist` (installed 134 packages including the Phase 09-02 Symfony postmark bundle); `cp .env.example .env` + `php artisan key:generate`.
- **Files modified:** None committed — `vendor/` and `.env` are gitignored; the install is a local build artifact. No source-file change was necessary.
- **Commit:** N/A (no source change).

**2. [Note — Acceptance-criterion wording adjusted] Removed model names from mailable docblock**

- **Found during:** Task 2 acceptance grep (`! grep -q "RamsDocument\|OmManual\|Worksheet\|CableSchedule" app/Mail/DocumentGenerationFailedMail.php`).
- **Issue:** The class docblock initially listed valid `$documentType` labels `'RAMS' | 'O&M Manual' | 'Worksheet' | 'Cable Schedule'` and mentioned "the four Build*Job hooks (RAMS / O&M / Worksheet / Cable)" — these comment tokens triggered the grep that was meant to catch *class imports*.
- **Fix:** Rewrote the two offending doc lines to generic phrasing ("across the four document pipelines", "human-readable doc-type label"). Runtime behaviour unchanged.
- **Files modified:** `app/Mail/DocumentGenerationFailedMail.php` (2 comment lines rephrased).
- **Commit:** `a444e88` (folded into Task 2 commit).

### Deferred to separate task (out of scope)

- **`storage/worker-heartbeat` untracked runtime file** — appeared during PHPUnit run; unrelated to Phase 09. Logged in `.planning/phases/09-email-notifications/deferred-items.md` for a future housekeeping pass (add to `.gitignore`).
- **Pre-existing `MethodStatementFallbackTest` failures** — documented in 09-02 plan summary; unchanged by this plan's work (no edits near `ClaudeProvider`).

## Authentication gates

None — all work was code-only. No external auth or API calls.

## Verification

- [x] `vendor/bin/phpunit tests/Feature/PublicSurveyControllerTest.php` → 4 tests passed, 10 assertions (NOTF-02 regression guard)
- [x] `vendor/bin/phpunit --testsuite=Feature --filter=Survey` → 27 tests passed, 64 assertions (broader survey suite)
- [x] `vendor/bin/phpunit --filter=SiteSurvey` → 15 tests passed, 68 assertions (SiteSurveyController narrow filter)
- [x] `class_exists('App\\Mail\\RamsReviewNeededMail')` → true
- [x] `class_exists('App\\Mail\\DocumentGenerationFailedMail')` → true
- [x] `new DocumentGenerationFailedMail('RAMS', '21CQ30017', 'Acme', 'oops', 'https://...')` → instantiates
- [x] `view('emails.rams-review-needed', ['rams'=>$r])->render()` → contains `21CQ30017`
- [x] `view('emails.document-generation-failed', [...])->render()` → contains `boom` (error-message rendering verified)
- [x] `grep -c "implements ShouldQueue"` in each mailable → 1
- [x] `grep -c "<!DOCTYPE html>"` in each Blade → 1 (wrapper present per I-07)
- [x] `grep -c "config('rams.company_name')"` in each Blade → ≥1 (brand header carried)
- [x] Line count ≥ 30 for each Blade (actual: 80, 97) — proves wrapper inclusion, not a stub
- [x] `grep -c "route('rams.review'"` in review-needed Blade → 2 (button + fallback link — NOTF-03b lock)
- [x] `grep -c "documentType|errorMessage|detailUrl"` in failure Blade → all ≥1 (NOTF-04c fields)
- [x] `grep -cE "#b91c1c|#a52a2a"` in failure Blade → 2 (I-01 opt-in honored)
- [x] `! grep -q "RamsDocument\|OmManual\|Worksheet\|CableSchedule" app/Mail/DocumentGenerationFailedMail.php` → 0 matches (no model imports)
- [x] `grep -q "NotificationRecipientResolver" app/Core/Modules/Survey/SurveyService.php` → pass
- [x] `! grep -q "Project::with('user')" app/Core/Modules/Survey/SurveyService.php` → pass
- [x] `! grep -q "User::where('is_admin'" app/Core/Modules/Survey/SurveyService.php` → pass
- [x] `grep -q "resolveProjectRecipient" app/Core/Modules/Survey/SurveyService.php` → pass
- [x] `grep -q "isAdmin()" app/Http/Controllers/SiteSurveyController.php` → pass (8 matches; line 449 fix included)
- [x] `! grep -q "is_admin" app/Http/Controllers/SiteSurveyController.php` → pass (0 matches)
- [x] `grep -q "function isAdmin" app/Models/User.php` → pass (line 45, unchanged)
- [x] `! grep -rE "is_admin" app/ --include='*.php'` → 0 matches codebase-wide (I-06 + B-02)
- [x] `! grep -rE "Project::with\('user'\)" app/ --include='*.php'` → 0 matches codebase-wide
- [x] `! grep -rEn '\$project->user\(\)' app/ --include='*.php'` → 0 matches codebase-wide (literal grep; see Grep refinement note)

## Success criteria (from plan)

| Criterion | Status |
|---|---|
| Plan 09-05 can dispatch `RamsReviewNeededMail` from `ExtractRamsDraftJob` | PASS — class autoloads, ShouldQueue, takes `RamsDocument`, view renders |
| Plan 09-05 can dispatch `DocumentGenerationFailedMail` from each `Build*Job::failed()` | PASS — polymorphic primitive-arg mailable; smoke-instantiated for RAMS case |
| `SurveyService::submitPublic()` survey-submitted path no longer has 2 latent bugs | PASS — resolver replaces both broken idioms |
| `SiteSurveyController::authorizeSurvey()` correctly allows admins via `User::isAdmin()` | PASS — 1-line diff at line 449 |
| `is_admin`, `Project::with('user')`, `$project->user()` absent codebase-wide | PASS — 0 matches for all three (I-06 + B-02) |
| All existing tests still pass — no regression | PASS — 46 tests across PublicSurvey + SiteSurvey filters all green |

## Self-Check: PASSED

**Files claimed to exist:**

- `app/Mail/RamsReviewNeededMail.php` — FOUND (44 lines)
- `app/Mail/DocumentGenerationFailedMail.php` — FOUND (54 lines)
- `resources/views/emails/rams-review-needed.blade.php` — FOUND (80 lines)
- `resources/views/emails/document-generation-failed.blade.php` — FOUND (97 lines)
- `app/Core/Modules/Survey/SurveyService.php` — MODIFIED (lines 406–408)
- `app/Http/Controllers/SiteSurveyController.php` — MODIFIED (line 449)
- `.planning/phases/09-email-notifications/deferred-items.md` — MODIFIED (appended worker-heartbeat note)

**Commits claimed to exist:**

- `05a3982` (Task 1) — FOUND in `git log`
- `a444e88` (Task 2) — FOUND in `git log`
- `8d32b61` (Task 3) — FOUND in `git log`

No missing items.
