---
quick_id: 260817-w4k
slug: doc-edit-authz
date: 2026-08-17
status: planned
---

# Quick Task 260817-w4k — AI-edit endpoints never consult a policy + the test that should have caught it

## How this surfaced

`CableScheduleRegenerationTest::regenerate_button_hidden_when_user_lacks_update_permission` started failing. Investigating it turned up a latent authorization gap.

**Attribution, stated plainly:** the test failure was introduced by **this morning's task 260817-jsg**, not by 260817-r5e. The r5e executor compared against baseline `1b0fc1a` — which already contained the jsg work — so from where it stood the failure genuinely looked pre-existing. It isn't. The two JS strings at `document-edit-drawer.blade.php:397,413` were added by jsg.

## Finding 1 — the failing test is asserting the wrong thing (test defect)

`tests/Feature/Cable/CableScheduleRegenerationTest.php:93` asserts the rendered HTML does not contain the bare string `'↻ Regenerate'`.

**The button itself is correctly gated** — `resources/views/cable-schedule/edit.blade.php:25` wraps the retry-generation form in `@if(auth()->user()?->can('update', $schedule))`, and that gate works.

What now also matches is **JavaScript copy** in `components/document-edit-drawer.blade.php` (included at `edit.blade.php:46`), which mentions the button by name in user-facing messages:
- `:397` — *"…use the "↻ Regenerate" button to rebuild the document."*
- `:413` — *"Not regenerated… use "↻ Regenerate" above…"*

So the test matches a **display string** rather than the control. It would also have passed for the wrong reason if the button's label ever changed.

### Task 1 — assert the control, not the copy

**File:** `tests/Feature/Cable/CableScheduleRegenerationTest.php:93`

**Action:** Assert the absence of the actual regenerate **form/action** — e.g. the `cable-schedules.retry-generation` route URL for this schedule — rather than a visible label. Keep the existing explanatory comment about `Gate::before(fn () => false)`; it is good and explains the test's intent.

**Acceptance criteria:**
- Passes with the gate denying
- **Fails if the `@if(...can('update'...))` wrapper at `edit.blade.php:25` is removed** — verify by removing it, observing the failure, restoring. This is the whole point of the test; it must detect an ungated button.
- Not satisfied by merely changing the button's label text

## Finding 2 — `DocumentEditController` never consults a policy (production gap)

`app/Http/Controllers/DocumentEditController.php:516-529`, called from **seven** route handlers (`:54, 89, 159, 253, 310, 456, 477`):

```php
private function authorizeDocument(Request $request, string $type, int $id): ?JsonResponse
{
    $user = $request->user();
    if ($user === null) { return $this->jsonError('unauthenticated', ..., 401); }

    $ownerId = $this->ownerIdFor($type, $id);
    if ($ownerId === null) { return $this->jsonError('document_not_found', ..., 404); }

    return null;
}
```

It checks **authenticated** and **exists**. It resolves `$ownerId` and then **discards it** — a strong signal an ownership/policy check was intended and never completed. No `can()`, no `authorize()`, no `Gate::`.

### Is this exploitable today? No — and that is exactly why it needs fixing now

Every policy in this app currently returns `true` for any authenticated user (shared workspace). So today the effective behaviour matches the intent, and **there is no live privilege escalation**.

The gap is latent. `CableSchedulePolicy`'s own docblock states the enforcement point exists *"so any future per-user rule lands in one file."* `DocumentEditController` bypasses that file entirely, across **all five document types**. The first time someone adds a genuine per-user rule to a policy, the AI-edit surface — which mutates documents — will silently ignore it. The bypass would be invisible precisely because the policy looks like it is being enforced.

### ⚠️ The trap — do not add `can('update', ...)` naively

Policy registration is **incomplete**. `AppServiceProvider.php:122-129` registers `RamsDocument`, `OmManual`, `Worksheet`, `CableSchedule`, plus `ProjectDrawing`/`Project`.

**`SiteSurvey` has no policy** — no `SiteSurveyPolicy.php` exists and nothing is registered for it.

Laravel **denies** an ability with no policy or gate behind it. So dropping `$user->can('update', $doc)` into `authorizeDocument()` would **break survey AI-edits outright** — a working feature, broken in the name of security hardening. Verify this claim yourself before building on it.

### Task 2a — create and register `SiteSurveyPolicy`

**Action:** Create `app/Policies/SiteSurveyPolicy.php` mirroring `CableSchedulePolicy` exactly — `view` / `update` / `delete`, each returning `true` for the shared workspace, with a docblock recording that it exists as an enforcement point rather than a restriction. Register it in `AppServiceProvider::boot()` beside the others.

Check whether any **other** model reachable through `ownerIdFor()` lacks a policy and treat it the same way. Do not assume the five listed are the only ones.

**Acceptance criteria:**
- `Gate::allows('update', $survey)` is true for an authenticated user
- Existing survey tests unaffected

### Task 2b — make `authorizeDocument()` enforce the policy

**File:** `app/Http/Controllers/DocumentEditController.php:516-529`

**Action:** After resolving the document, authorize the `update` ability against the **model instance**. Return the existing 403 JSON error shape on denial — match how the controller's other failures are returned; do not throw a bare `AuthorizationException` that would render an HTML error page into a JSON client.

`ownerIdFor()` currently returns an id. If resolving the model instance is cleaner, refactor it — but keep the 404-on-missing behaviour and its current ordering (missing → 404 before any authz outcome), since changing that ordering leaks existence information.

Behaviour must be **unchanged today**: all policies return true, so every currently-working edit must keep working. This is about routing the decision through the policy, not restricting anything.

**Acceptance criteria:**
- All five document types can still apply an AI edit as an authenticated user — assert per type, since survey is the one at risk
- Unauthenticated → 401 as now; missing document → 404 as now
- **A denied policy produces 403 JSON** — prove it with `Gate::before(fn () => false)`, the pattern the cable test already uses
- A test fails if the policy check is removed again

## Constraints

- PHPUnit 11, NOT Pest. Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Blade changes (if any) need `blade.compiler->compileString()` verification, not just `php -l`
- No migration. No new packages.
- **Do not tighten any policy's rules.** Every policy stays permissive; this task only ensures the policy is *consulted*. Changing who may edit what is a product decision the user has not made.
- Do not touch `config/rams_tier1.php` (awaiting H&S sign-off).
- Local-edit-then-upload → `php artisan optimize:clear` after upload.

## Explicitly out of scope

- The two known failures unrelated to this: `QueueRecoverCommandTest` (documented production finding, `20260817-green-the-suite` Item 5).
- Any change to what the policies permit.
