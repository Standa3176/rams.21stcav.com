---
quick_id: 260817-w4k
slug: doc-edit-authz
date: 2026-08-17
status: complete
commits: bda9166, 5230d36, 7c671d7
---

# Summary — AI-edit endpoints now consult the policy

## Origin, corrected

`CableScheduleRegenerationTest::regenerate_button_hidden_when_user_lacks_update_permission` began failing. **It was introduced by 260817-jsg** (this morning's AI-regen work), not by 260817-r5e — r5e's baseline `1b0fc1a` already contained jsg, so from there it looked pre-existing. The `↻ Regenerate` string now also appears in JS copy at `document-edit-drawer.blade.php:397,413`.

Chasing it surfaced Finding 2, which was worth considerably more than the test.

## Task 1 — assert the control, not its label (`bda9166`)

`tests/Feature/Cable/CableScheduleRegenerationTest.php:101,109` — asserts absence of the `cable-schedules.retry-generation` form action URL and the button's `data-confirm` hook, instead of the visible string `↻ Regenerate`. Template untouched.

The button was always correctly gated (`cable-schedule/edit.blade.php:25`). The test was matching a **display string**, so it would equally have passed for the wrong reason had the label changed.

**Revert proof:** removed the `@if(...can('update'...))` wrapper → test failed, output showing the leaked `action="…/retry-generation"` and the `data-confirm` attribute. Restored; template `git diff` empty and recompiles via `blade.compiler->compileString()`.

## Task 2a — `SiteSurveyPolicy` (`5230d36`)

New `app/Policies/SiteSurveyPolicy.php`, registered `AppServiceProvider.php:137`. Mirrors `CableSchedulePolicy` — `view`/`update`/`delete` all return `true`. **No restriction added.**

Laravel 11 auto-discovery resolves it from the filename alone (verified: 5/5 pass with the registration commented out). The explicit `Gate::policy` line is kept to match the existing block's stated "for clarity" convention.

## Task 2b — `authorizeDocument()` enforces the policy (`7c671d7`)

`DocumentEditController.php:529` — previously checked authenticated + exists only, resolving `$ownerId` and **discarding it**. Called from 7 route handlers across all document types.

Now: 401 unauthenticated → 404 missing → **403 forbidden** via `$user->cannot('update', $document)`. `ownerIdFor()` replaced by `documentFor():562` returning the instance the policy needs.

Returns `jsonError('forbidden', …, 403)` rather than `$this->authorize()` — an `AuthorizationException` would render Laravel's HTML error page into a `fetch()` client. 404-before-403 ordering preserved so existence isn't leaked.

## Why this mattered despite being unexploitable

Every policy returns `true` for any authenticated user, so **there was no live privilege escalation and behaviour is unchanged today**.

The gap was latent. `CableSchedulePolicy`'s docblock states the policy exists "so any future per-user rule lands in one file" — and the AI-edit surface, which mutates documents, bypassed that file entirely. The first genuine per-user rule would have been silently ignored, invisibly, because the policy would *look* enforced.

**Known consequence:** the new 403 branch is currently unreachable in production and exercised only by tests. That is the intent — an enforcement point, not a restriction — but the guard has no live effect until someone makes a policy non-permissive.

## Verification

Claims checked empirically before any change, not inferred: gate results per type were `survey=false`, `rams/worksheet/om/cable=true` — confirming a naive `can('update')` would have broken survey edits outright. Also confirmed no `Gate::before`/`Gate::define` in `app/` could mask the missing policy.

**All 5 types still apply an AI edit end-to-end** (createThread → postMessage with a real adapter op → apply → `status=applied`): `rams` (`add_exclusion`), **`survey` (`update_room_dimensions`)**, `worksheet` (`add_blocker`), `om` (`add_contact`), `cable` (`add_cable_item`).

**Revert proofs:**
- Policy check commented out → **7 failures** (all five types on thread-create expecting 403 got 201; plus apply and revisions-list)
- `SiteSurveyPolicy` file removed → survey apply **403s** while the other four pass — the trap demonstrated, not asserted

New: `tests/Feature/DocumentEdits/DocumentEditPolicyEnforcementTest.php` (17 tests). Affected suites 155 tests / 620 assertions green; survey-wide 128 green.

## Findings the plan didn't anticipate

**A 6th document type exists and is dead.** `DocumentEditAdapterRegistry` maps `drawing → DrawingEditAdapter`, but `ownerIdFor()` never listed it because `ProjectDrawing` has no `user_id` (it hangs off `project_id`). So `type=drawing` already 404s on all 7 handlers — the AI-edit surface for drawings is non-functional today. Left exactly as-is rather than switching on an untested surface, and pinned with `test_drawing_type_is_404_unchanged_by_this_task`. `ProjectDrawingPolicy` already exists if it's ever wired up deliberately. **Open question for the user: should drawings have AI edit at all?**

**Survey's exposure was live, not theoretical.** `site-survey/show.blade.php:397` links "Revision history" → `revisionsView()` → `authorizeDocument()`. Without Task 2a, Task 2b would have broken that visible link. (Survey has no edit *drawer* — that's cable/om/rams/worksheet only.)

**Executor caught itself on hazard 1.** It first asserted `error=unauthenticated` on the 401 — but these routes sit inside the `auth` group (`routes/web.php:790`), so middleware answers `{"message":"Unauthenticated."}` and the controller's 401 branch is never reached over HTTP. It was validating against a response that could not contain that key. Corrected to assert the middleware's real body, plus a separate reflection test exercising the controller's own 401 branch so that defence-in-depth path stays covered.

## Full suite

**2169 tests, 1 failure** — `QueueRecoverCommandTest::unhealthy queue runs restart and drain plan`, out of scope. Not taken on trust: passes in isolation both with and without these changes, fails only in the full run, matching `20260817-green-the-suite` Item 5 exactly (`queue:work` inherits the 128MB default; `Worker::memoryExceeded()` reads whole-process memory, so ~2000 prior tests in one PHPUnit process trip it). Pre-existing and unrelated.

## Files to upload to live

```
app/Http/Controllers/DocumentEditController.php   (modified)
app/Policies/SiteSurveyPolicy.php                 (NEW)
app/Providers/AppServiceProvider.php              (modified)
```

Then `php artisan optimize:clear` — `AppServiceProvider` is a boot-path file, so this matters here. No migration, no new packages, no Blade changed, `config/rams_tier1.php` untouched.

## Not done

- Policies remain permissive — deciding who may edit what is a product decision, not taken here
- `type=drawing` left 404ing (above)
- `QueueRecoverCommandTest` (out of scope)
