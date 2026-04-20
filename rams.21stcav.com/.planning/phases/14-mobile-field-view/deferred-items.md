# Phase 14 — Deferred Items

Out-of-scope findings surfaced during execution. Each entry lists the plan that
discovered it, the affected surface, and why it is deferred.

---

## Plan 14-04 — Wave 3 HTTP layer

### 1. Auth route rendering tests fail in worktree (pre-existing, infrastructure)

**Discovered:** Wave 3 full-suite regression check (after Task 3).

**Tests:**
- `Tests\Feature\Auth\AuthenticationTest::test_login_screen_can_be_rendered`
- `Tests\Feature\Auth\EmailVerificationTest::test_email_verification_screen_can_be_rendered`
- `Tests\Feature\Auth\PasswordConfirmationTest::test_confirm_password_screen_can_be_rendered`
- `Tests\Feature\Auth\PasswordResetTest::test_reset_password_link_screen_can_be_rendered`
- `Tests\Feature\Auth\PasswordResetTest::test_reset_password_screen_can_be_rendered`
- `Tests\Feature\Auth\RegistrationTest::test_registration_screen_can_be_rendered`

**Error:** `Vite manifest not found at: public/build/manifest.json`

**Why deferred:** These tests render the guest (Breeze) layout which uses the
`@vite(['resources/css/app.css', 'resources/js/app.js'])` directive. The
worktree has no built frontend assets — `public/build/manifest.json` is absent
because no `npm run build` has been run. This is infrastructure / dev-setup,
not a Phase 14 code issue. The authenticated `app.blade.php` layout (used by
the new field view) does NOT use `@vite` and so the field view tests all
pass.

**Recommendation:** orchestrator should run `npm install && npm run build`
before merging the final phase wave, or tag these tests with a Vite-manifest
guard.

### 2. QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan fails (pre-existing)

**Discovered:** Wave 3 full-suite regression check.

**Why deferred:** Phase 09/10 queue recovery command test; not touched by any
Phase 14 code. Pre-existing from the baseline. Suspect environment / Windows
process invocation.

### 3. FieldViewResponsivenessTest::test_view_contains_required_ui_spec_markers RED (expected)

**Discovered:** Wave 3 test run.

**Why deferred:** Intentional — Plan 14-04 ships a placeholder `field.blade.php`
to satisfy the view() call. Plan 14-05 delivers the full UI-SPEC markup
including `data-testid="task-row"`. Plan 04's validation contract explicitly
excludes this test from the must-green set (see 14-VALIDATION.md row
14-05-T2).

---
