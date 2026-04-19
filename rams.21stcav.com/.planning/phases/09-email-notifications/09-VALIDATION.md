---
phase: 09
slug: email-notifications
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-19
---

# Phase 09 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5.3 |
| **Config file** | `phpunit.xml` (`MAIL_MAILER=array` for `Mail::fake()` assertions) |
| **Quick run command** | `vendor/bin/phpunit --testsuite=Feature --filter=Notification` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds (existing suite baseline) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite=Feature --filter=<NewTestClass>` (the test for the just-completed task)
- **After every plan wave:** Run `vendor/bin/phpunit --testsuite=Feature` (all feature tests — catches cross-wave regressions in `PublicSurveyControllerTest` etc.)
- **Before `/gsd-verify-work`:** `vendor/bin/phpunit` (full suite green) + `MAIL_MAILER=log php artisan tinker` smoke check (manually trigger one mailable, tail `storage/logs/laravel.log`)
- **Max feedback latency:** 30 seconds (full suite)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| _Populated by planner after PLAN.md files exist_ | | | | | | | | | |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Services/NotificationRecipientResolverTest.php` — unit tests for `resolveProjectRecipient()` (owner present → owner; owner null → first admin; no admin → null)
- [ ] `tests/Feature/Notifications/RamsCompletionNotificationTest.php` — `Mail::fake()` + dispatch `BuildRamsDocumentJob` end-state and assert `RamsReadyMail` sent to owner with attachment + correct BCC
- [ ] `tests/Feature/Notifications/OmManualCompletionNotificationTest.php` — same shape for O&M
- [ ] `tests/Feature/Notifications/WorksheetCompletionNotificationTest.php` — same shape for Worksheet
- [ ] `tests/Feature/Notifications/CableScheduleCompletionNotificationTest.php` — same shape for Cable
- [ ] `tests/Feature/Notifications/RamsReviewNeededNotificationTest.php` — assert `RamsReviewNeededMail` fires when `ExtractRamsDraftJob` flips status to `awaiting_review`
- [ ] `tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php` — assert `DocumentGenerationFailedMail` fires from `Build*Job::failed()` to all admins
- [ ] `tests/Feature/Notifications/IdempotencyTest.php` — second dispatch (e.g., job retry) does NOT re-send when `completion_email_sent_at` is set
- [ ] `tests/Feature/Notifications/BccTest.php` — when `RAMS_NOTIFICATION_BCC` is set, mail header includes Bcc; when empty, no Bcc header
- [ ] Existing `tests/Feature/PublicSurveyControllerTest.php` — extend to assert `SurveySubmittedMail` recipient resolution still works after `SurveyService` is refactored to use `NotificationRecipientResolver` (regression guard for NOTF-02a)

*PHPUnit framework already installed; no Wave 0 framework install required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Postmark transport actually sends to inbox | NOTF-05g | Requires production `POSTMARK_API_KEY` + DNS setup; CI cannot verify deliverability | Set `MAIL_MAILER=postmark` + valid `POSTMARK_API_KEY` in staging `.env`; trigger one document generation; confirm receipt in `rams@21stcav.com` inbox |
| DKIM / SPF / DMARC pass at the recipient | NOTF-05g | DNS work, not code | After DNS records are deployed, send a test email to `check-auth@verifier.port25.com`; expect `pass` for all three |
| `MAIL_MAILER=log` rendered output is readable in dev | NOTF-05f | Visual check of the rendered email body | `MAIL_MAILER=log php artisan tinker` → `Mail::to('test@example.com')->send(new App\Mail\RamsReadyMail(RamsDocument::first()));` → `tail storage/logs/laravel.log` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
