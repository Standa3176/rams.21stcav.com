---
phase: 09
slug: email-notifications
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-04-19
populated: 2026-04-19
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
| 09-01-T1 | 09-01 | 1 | NOTF-01c, NOTF-04b, NOTF-04c | T-09-01, T-09-05 | Adds idempotency columns + cable error_message | migration smoke | `php artisan migrate --pretend \| grep -q completion_email_sent_at` | `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php` | ⬜ pending |
| 09-01-T2 | 09-01 | 1 | NOTF-05d | T-09-04 | Adds notifications.bcc config + .env.example placeholders | config presence | `php artisan tinker --execute="echo array_key_exists('notifications', config('rams'));"` returns truthy | `config/rams.php`, `.env.example` | ⬜ pending |
| 09-02-T1 | 09-02 | 1 | NOTF-05g | Supply chain (accept) | Installs symfony/postmark-mailer + http-client | composer | `composer show symfony/postmark-mailer` exits 0 | `composer.lock` | ⬜ pending |
| 09-02-T2 | 09-02 | 1 | NOTF-05a, NOTF-05b | T-09-02, T-09-06 | Centralised recipient resolver (locks `role='admin'` + `Project::owner`) | unit | `vendor/bin/phpunit tests/Unit/Services/NotificationRecipientResolverTest.php` | `app/Services/NotificationRecipientResolver.php`, `tests/Unit/Services/NotificationRecipientResolverTest.php` | ⬜ pending |
| 09-03-T1 | 09-03 | 2 | NOTF-01a, NOTF-01b, NOTF-01f, NOTF-05e, NOTF-05f | T-09-02, T-09-05 | 4 typed *ReadyMail classes (ShouldQueue + DocumentArtifactStorage attachments) | autoload smoke | `php -r "class_exists('App\\Mail\\RamsReadyMail') ?: exit(1);"` | `app/Mail/{Rams,OmManual,Worksheet,CableSchedule}ReadyMail.php` | ⬜ pending |
| 09-03-T2 | 09-03 | 2 | NOTF-01a, NOTF-01b, NOTF-01f | T-09-01 | 4 Blade templates rendering project ref / name / dashboard link | view render | `php artisan tinker --execute="echo view('emails.rams-ready', ['rams' => RamsDocument::factory()->make([...])])->render();" \| grep -q ref` | `resources/views/emails/{rams,om-manual,worksheet,cable-schedule}-ready.blade.php` | ⬜ pending |
| 09-04-T1 | 09-04 | 2 | NOTF-03, NOTF-03a, NOTF-03b, NOTF-05e, NOTF-05f | T-09-02 | RamsReviewNeededMail + Blade with route('rams.review') link | autoload smoke + grep | `grep -q "route('rams.review'" resources/views/emails/rams-review-needed.blade.php` | `app/Mail/RamsReviewNeededMail.php`, `resources/views/emails/rams-review-needed.blade.php` | ⬜ pending |
| 09-04-T2 | 09-04 | 2 | NOTF-04, NOTF-04a, NOTF-04c, NOTF-05e, NOTF-05f | T-09-01, T-09-02, T-09-04 | DocumentGenerationFailedMail polymorphic via primitives | autoload smoke + grep | `grep -q "string  \\$documentType" app/Mail/DocumentGenerationFailedMail.php` | `app/Mail/DocumentGenerationFailedMail.php`, `resources/views/emails/document-generation-failed.blade.php` | ⬜ pending |
| 09-04-T3 | 09-04 | 2 | NOTF-02, NOTF-02a, NOTF-05a | T-09-02 | Refactor SurveyService to use resolver — fixes latent `Project::with('user')` + `User::where('is_admin', ...)` bugs | feature regression | `vendor/bin/phpunit tests/Feature/PublicSurveyControllerTest.php` | `app/Core/Modules/Survey/SurveyService.php` | ⬜ pending |
| 09-05-T1 | 09-05 | 3 | NOTF-01a, NOTF-01b, NOTF-01d, NOTF-01e, NOTF-03a, NOTF-04a, NOTF-04b, NOTF-04c, NOTF-05d, NOTF-05h | T-09-02, T-09-03, T-09-05 | Wire all 5 jobs with idempotent send + try/catch + BCC at call site | parse + suite green | `php -l app/Jobs/BuildRamsDocumentJob.php` + `vendor/bin/phpunit --testsuite=Feature` | `app/Jobs/Build{Rams,OmManual,Worksheet,CableSchedule}Job.php`, `app/Jobs/ExtractRamsDraftJob.php` | ⬜ pending |
| 09-05-T2 | 09-05 | 3 | NOTF-01a, NOTF-01b, NOTF-01f, NOTF-02, NOTF-02a, NOTF-03a, NOTF-03b, NOTF-04a, NOTF-04c | T-09-02, T-09-05 | 7 trigger feature tests + extend PublicSurveyControllerTest | feature | `vendor/bin/phpunit --testsuite=Feature --filter=Notifications` | `tests/Feature/Notifications/{Rams,OmManual,Worksheet,CableSchedule}CompletionNotificationTest.php`, `tests/Feature/Notifications/RamsReviewNeededNotificationTest.php`, `tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php`, `tests/Feature/PublicSurveyControllerTest.php` | ⬜ pending |
| 09-05-T3 | 09-05 | 3 | NOTF-01d, NOTF-04b, NOTF-05d | T-09-03, T-09-05 | Idempotency + BCC behavior locked under retry / config | feature | `vendor/bin/phpunit --testsuite=Feature --filter='Idempotency\|Bcc'` | `tests/Feature/Notifications/IdempotencyTest.php`, `tests/Feature/Notifications/BccTest.php` | ⬜ pending |
| 09-06-T1 | 09-06 | 4 | NOTF-05g | T-09-04, T-09-01 (DNS spoofing) | Production cutover runbook (DNS + Postmark + .env) | doc presence | `grep -q "POSTMARK_API_KEY" .planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md` | `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md` | ⬜ pending |
| 09-06-T2 | 09-06 | 4 | NOTF-05g | T-09-04 | User decides: cutover-complete / -deferred / -blocked / -noop | checkpoint:human-verify | n/a (gated on user signal) | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Services/NotificationRecipientResolverTest.php` — unit tests for `resolveProjectRecipient()` (owner present → owner; owner null → first admin; no admin → null) — created in plan 09-02 Task 2
- [ ] `tests/Feature/Notifications/RamsCompletionNotificationTest.php` — `Mail::fake()` + dispatch `BuildRamsDocumentJob` end-state and assert `RamsReadyMail` sent to owner with attachment — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/OmManualCompletionNotificationTest.php` — same shape for O&M — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/WorksheetCompletionNotificationTest.php` — same shape for Worksheet — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/CableScheduleCompletionNotificationTest.php` — same shape for Cable (uses source_filename + dual MIME) — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/RamsReviewNeededNotificationTest.php` — assert `RamsReviewNeededMail` fires when `ExtractRamsDraftJob` flips status to `awaiting_review` — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php` — assert `DocumentGenerationFailedMail` fires from `Build*Job::failed()` to all admins; truncates error_message; cable falls back to `$exception->getMessage()` — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/IdempotencyTest.php` — second dispatch (e.g., job retry) does NOT re-send when `completion_email_sent_at` / `failed_email_sent_at` is set — created in plan 09-05 Task 3
- [ ] `tests/Feature/Notifications/BccTest.php` — when `RAMS_NOTIFICATION_BCC` is set, mail header includes Bcc; when empty, no Bcc header — created in plan 09-05 Task 3
- [ ] Existing `tests/Feature/PublicSurveyControllerTest.php` — extend to assert `SurveySubmittedMail` recipient resolution still works after `SurveyService` is refactored to use `NotificationRecipientResolver` (regression guard for NOTF-02a) — extended in plan 09-05 Task 2

*PHPUnit framework already installed; no Wave 0 framework install required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Postmark transport actually sends to inbox | NOTF-05g | Requires production `POSTMARK_API_KEY` + DNS setup; CI cannot verify deliverability | Set `MAIL_MAILER=postmark` + valid `POSTMARK_API_KEY` in staging `.env`; trigger one document generation; confirm receipt in `rams@21stcav.com` inbox (see plan 09-06 checklist §4) |
| DKIM / SPF / DMARC pass at the recipient | NOTF-05g | DNS work, not code | After DNS records are deployed, send a test email to `check-auth@verifier.port25.com`; expect `pass` for all three (see plan 09-06 checklist §4) |
| `MAIL_MAILER=log` rendered output is readable in dev | NOTF-05f | Visual check of the rendered email body | `MAIL_MAILER=log php artisan tinker` → `Mail::to('test@example.com')->send(new App\Mail\RamsReadyMail(RamsDocument::first()));` → `tail storage/logs/laravel.log` |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or are explicit `checkpoint:human-verify` (09-06-T2)
- [x] Sampling continuity: every wave terminates in a green-suite check (no 3 consecutive tasks without automated verify)
- [x] Wave 0 covers all MISSING references (10 test files / extensions enumerated above)
- [x] No watch-mode flags (every command is one-shot)
- [x] Feedback latency < 30s (full feature suite is ~5–15s; full project suite ~30s)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** ready for execution
