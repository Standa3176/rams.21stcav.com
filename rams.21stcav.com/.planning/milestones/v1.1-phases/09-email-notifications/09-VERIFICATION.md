---
phase: 09-email-notifications
verified: 2026-04-19T00:00:00Z
status: human_needed
score: 9/9 must-haves verified (automated); 2 items require human/ops verification
must_haves_verified: 9/9
requirement_coverage:
  NOTF-01: pass
  NOTF-01a: pass
  NOTF-01b: pass
  NOTF-01c: pass
  NOTF-01d: pass
  NOTF-01e: pass
  NOTF-01f: pass
  NOTF-02: pass
  NOTF-02a: pass
  NOTF-03: pass
  NOTF-03a: pass
  NOTF-03b: pass
  NOTF-03c: pass
  NOTF-04: pass
  NOTF-04a: pass
  NOTF-04b: pass
  NOTF-04c: pass
  NOTF-05: pass
  NOTF-05a: pass
  NOTF-05b: pass
  NOTF-05c: pass
  NOTF-05d: pass
  NOTF-05e: pass
  NOTF-05f: pass
  NOTF-05g: pass_operational_pending
  NOTF-05h: pass
overrides_applied: 0
human_verification:
  - test: "End-to-end dev smoke — MAIL_MAILER=log rendering"
    expected: "A real RAMS generation in dev produces a rendered RamsReadyMail body in storage/logs/laravel.log with correct subject ([ref] RAMS ready — {name}), recipient (project owner), and attachment metadata (filename). Same smoke for OmManualReadyMail, WorksheetReadyMail, CableScheduleReadyMail, RamsReviewNeededMail (via extract flow), and DocumentGenerationFailedMail (via tinker failed() call)."
    why_human: "Requires running real queue worker + triggering a real generation flow; can't be verified by grep. Automated feature tests prove the wiring via Mail::fake() but do not exercise the full log-mailer round-trip."
  - test: "Production Postmark cutover (NOTF-05g — operational)"
    expected: "MAIL_MAILER=postmark + valid POSTMARK_API_KEY in production .env; DNS records (SPF include:spf.mtasv.net, DKIM selector, DMARC) live on 21stcav.com; Postmark sender signature verified for rams@21stcav.com; first production send confirmed via Postmark Activity log 'Delivered'; BCC audit copy lands at ops@21stcav.com."
    why_human: "Production environment access, Postmark dashboard auth, DNS zone write access, and real-send verification cannot be performed by automated checks. Runbook documented at .planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md; resume-signal recorded as 'cutover-noop' (deferred) in 09-06-SUMMARY.md per --auto mode."
---

# Phase 9: Email Notifications Verification Report

**Phase Goal:** Add trigger-based system email notifications for AV-operations events — document generation completed (RAMS, O&M, Worksheet, Cable), document generation failed, RAMS review needed, and inherit the existing survey-submitted email. Wire each trigger into the relevant `Build*Job` success/`failed()` hook or status-transition path; queue mailables via `ShouldQueue`; configure Postmark transport with `rams@21stcav.com` sender for production.

**Verified:** 2026-04-19T00:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (derived from ROADMAP.md Success Criteria)

| #   | Truth                                                                                                                                                                 | Status       | Evidence                                                                                                                                                                                                                                                                                                                                                                          |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | RamsDocument STATUS_COMPLETED via BuildRamsDocumentJob → project owner gets email with DOCX attached + completion_email_sent_at set                                     | ✓ VERIFIED   | `app/Jobs/BuildRamsDocumentJob.php:147-178` — idempotent block after STATUS_COMPLETED flip, `->update(['completion_email_sent_at' => now()])` BEFORE send, `new RamsReadyMail($record)` with attachment via `DocumentArtifactStorage::readPath(TYPE_RAMS, ...)` in `app/Mail/RamsReadyMail.php:49-70`. Locked by `RamsCompletionNotificationTest` (3 tests).                      |
| 2   | Same pattern for OmManual, Worksheet, CableSchedule — each has completion_email_sent_at + idempotency guard                                                           | ✓ VERIFIED   | `BuildOmManualJob.php:95-125` (STATUS_DRAFT), `BuildWorksheetJob.php:116-146` (STATUS_DRAFT), `BuildCableScheduleJob.php:114-144` (STATUS_DRAFT). All three fire their typed `*ReadyMail` with idempotency column check. Cable uses `source_filename` + dual MIME (`CableScheduleReadyMail.php:65-67`). Locked by `OmManualCompletionNotificationTest`, `WorksheetCompletionNotificationTest`, `CableScheduleCompletionNotificationTest`. |
| 3   | Build*Job exhausts retries → STATUS_FAILED → every User::where('role', 'admin') gets failure alert once (failed_email_sent_at guard) — role='admin' string, NOT is_admin boolean | ✓ VERIFIED   | `NotificationRecipientResolver.php:64,82` uses `User::where('role', 'admin')`. `resolveAdminRecipients()` returns Collection. All 4 `Build*Job::failed()` hooks iterate admins. `is_admin` column/accessor absent from `app/` — `Grep is_admin app/` → 0 matches. Locked by `DocumentGenerationFailedNotificationTest` (4 tests) + `NotificationRecipientResolverTest::test_admin_lookup_uses_role_column_not_is_admin`. |
| 4   | ExtractRamsDraftJob finishes + RamsDocument.status → awaiting_review → project owner (or admin fallback) gets review-needed email                                    | ✓ VERIFIED   | `ExtractRamsDraftJob.php:144` calls `dispatchReviewNeededEmail($record)` after STATUS_AWAITING_REVIEW flip. Helper at lines 176-210 sets `review_needed_email_sent_at = now()` BEFORE sending `new RamsReviewNeededMail($record)` with body linking to `route('rams.review', $rams)` (Blade line 47). Recipient via resolver (owner + admin fallback). Locked by `RamsReviewNeededNotificationTest` (3 tests). |
| 5   | Existing survey-submitted send path in SurveyService::submitPublic() continues to work unchanged (no regression)                                                      | ✓ VERIFIED   | `app/Core/Modules/Survey/SurveyService.php:406-411` — now uses `app(NotificationRecipientResolver::class)->resolveProjectRecipient($project)` (latent bugs `Project::with('user')` + `User::where('is_admin', true)` removed). `PublicSurveyControllerTest::test_survey_submission_dispatches_SurveySubmittedMail_to_owner` locks the behavior end-to-end via HTTP POST (5 tests green in that file). |
| 6   | MAIL_MAILER=postmark + valid POSTMARK_API_KEY → mailables dispatch via database queue (implements ShouldQueue) and arrive                                            | ✓ VERIFIED (code) / ? HUMAN (operational) | `composer.json:21` has `"symfony/postmark-mailer": "^8.0"`. `config/services.php:18` reads `env('POSTMARK_API_KEY')`. `config/mail.php:56-62` wires the postmark transport. All 6 mailables `implements ShouldQueue`. Tests use `Mail::assertQueued` confirming queue dispatch path. Real production cutover gated by `POSTMARK-OPS-CHECKLIST.md` (human — see human_verification items). |
| 7   | RAMS_NOTIFICATION_BCC env var non-empty → every system email BCCs that address; empty → no BCC                                                                       | ✓ VERIFIED   | `config/rams.php:35-37` `notifications.bcc => env('RAMS_NOTIFICATION_BCC')`. All 9 call sites (4 completion + 4 failure + 1 review-needed) read `config('rams.notifications.bcc')` and chain `$pending->bcc(trim($bcc))` when non-empty (27 total `bcc` occurrences across 5 jobs). `BccTest` (4 tests) locks presence-when-set and absence-when-empty including review-needed path. |
| 8   | Mail send failure (caught Throwable → Log::warning) never rolls back the underlying generation job or aborts status transition                                        | ✓ VERIFIED   | Every dispatch site wrapped in `try { ... } catch (\Throwable $mailErr) { Log::warning('{JobName}: ... email send failed', [...]); }` — 9 catch blocks across 5 jobs. Comments explicitly note "Do NOT clear {column} — timestamp set in same update as dispatch so the queue cannot double-send" (NOTF-05h / D-12 / D-14). |
| 9   | Feature tests using Mail::fake() assert each trigger fires correct mailable to correct recipient with correct attachment shape                                        | ✓ VERIFIED   | 8 test files under `tests/Feature/Notifications/` (Rams/OmManual/Worksheet/CableSchedule Completion + RamsReviewNeeded + DocumentGenerationFailed + Idempotency + Bcc) — 24 tests total per 09-05 SUMMARY. Plus extended `PublicSurveyControllerTest::test_survey_submission_dispatches_SurveySubmittedMail_to_owner`. Mailables asserted via `Mail::assertQueued(Class, callback)` or `Mail::assertSent` for sync paths. |

**Score:** 9/9 truths verified by automated checks. Truth #6 additionally requires operational verification (human).

### Required Artifacts

| Artifact                                                                            | Expected                                                | Status     | Details                                                                                                        |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------- |
| `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php`     | Multi-table migration for 9 email-timestamp columns + cable error_message | ✓ VERIFIED | Verified; 95 lines, reversible, guarded `Schema::hasColumn` for `cable_schedules.error_message` re-run safety. |
| `config/rams.php`                                                                   | `notifications.bcc => env('RAMS_NOTIFICATION_BCC')`      | ✓ VERIFIED | Lines 35-37 confirmed; key resolvable.                                                                          |
| `.env.example`                                                                      | POSTMARK_API_KEY, MAIL_FROM_ADDRESS=rams@21stcav.com, MAIL_FROM_NAME, RAMS_NOTIFICATION_BCC placeholders | ✓ VERIFIED | Lines 55-62 confirmed with all four variables. No POSTMARK_TOKEN.                                               |
| `app/Models/RamsDocument.php`                                                       | 3 new fillables (`completion`, `failed`, `review_needed`) + casts + HasFactory | ✓ VERIFIED | Lines 50,52,64,66 confirmed; `use HasFactory, SoftDeletes;` present.                                            |
| `app/Models/OmManual.php`                                                           | 2 new fillables + casts                                 | ✓ VERIFIED | Lines 38,47 confirmed.                                                                                          |
| `app/Models/Worksheet.php`                                                          | 2 new fillables + casts                                 | ✓ VERIFIED | Lines 49,59 confirmed.                                                                                          |
| `app/Models/CableSchedule.php`                                                      | 3 fillables (`completion`, `failed`, `error_message`) + casts + HasFactory | ✓ VERIFIED | Lines 31,40 confirmed; `use HasFactory, SoftDeletes;` present.                                                  |
| `app/Services/NotificationRecipientResolver.php`                                    | `resolveProjectRecipient` + `resolveAdminRecipients`    | ✓ VERIFIED | 87 lines; uses `where('role', 'admin')` (not is_admin) + `loadMissing('owner')` (not user).                     |
| `app/Mail/RamsReadyMail.php`                                                        | ShouldQueue, attachment via DocumentArtifactStorage     | ✓ VERIFIED | 71 lines; TYPE_RAMS + DOCX MIME.                                                                                |
| `app/Mail/OmManualReadyMail.php`                                                    | ShouldQueue, attachment via DocumentArtifactStorage     | ✓ VERIFIED | 65 lines; TYPE_OM + DOCX MIME.                                                                                  |
| `app/Mail/WorksheetReadyMail.php`                                                   | ShouldQueue, attachment via DocumentArtifactStorage     | ✓ VERIFIED | 65 lines; TYPE_WORKSHEET + DOCX MIME.                                                                           |
| `app/Mail/CableScheduleReadyMail.php`                                               | ShouldQueue, source_filename + dual MIME                | ✓ VERIFIED | 75 lines; TYPE_CABLE + `.csv ? text/csv : xlsx`.                                                                |
| `app/Mail/RamsReviewNeededMail.php`                                                 | ShouldQueue, no attachment, body links to rams.review   | ✓ VERIFIED | 44 lines; subject `[ref] RAMS ready for review — {name}`.                                                       |
| `app/Mail/DocumentGenerationFailedMail.php`                                         | ShouldQueue, primitive args, polymorphic                | ✓ VERIFIED | 54 lines; 5 primitive ctor args; no model imports — polymorphic across 4 doc types.                              |
| `resources/views/emails/*-ready.blade.php` (4 files)                                | Each ≥ 30 lines; mirror canonical wrapper; brand header | ✓ VERIFIED | All 4 at 73 lines; `<!DOCTYPE html>`, `config('rams.company_name')`, correct route per type.                    |
| `resources/views/emails/rams-review-needed.blade.php`                               | Mirror wrapper; link to rams.review                     | ✓ VERIFIED | 80 lines; 2 matches of `route('rams.review', $rams)`.                                                           |
| `resources/views/emails/document-generation-failed.blade.php`                       | Mirror wrapper; documentType, errorMessage, detailUrl   | ✓ VERIFIED | 97 lines; all four NOTF-04c fields rendered; red-#b91c1c failure header (I-01 opt-in).                          |
| `database/factories/{Rams,OmManual,Worksheet,CableSchedule}Factory.php` (4 files)   | Factories for notifiable models                         | ✓ VERIFIED | All 4 present; RamsDocumentFactory additionally seeds `ai_provider`/`ai_model`/`form_data` (NOT NULL fix).      |
| `app/Jobs/BuildRamsDocumentJob.php`                                                 | Completion + failure dispatch wired                     | ✓ VERIFIED | Handle() block lines 147-178; failed() block lines 219-257. Uses STATUS_COMPLETED anchor.                       |
| `app/Jobs/BuildOmManualJob.php`                                                     | Completion + failure dispatch wired (STATUS_DRAFT)      | ✓ VERIFIED | Handle() block lines 95-125; failed() block lines 178-215.                                                      |
| `app/Jobs/BuildWorksheetJob.php`                                                    | Completion + failure dispatch wired (STATUS_DRAFT)      | ✓ VERIFIED | Handle() block lines 116-146; failed() block lines 182-219.                                                     |
| `app/Jobs/BuildCableScheduleJob.php`                                                | Completion + failure dispatch wired (STATUS_DRAFT; source_filename) | ✓ VERIFIED | Handle() block lines 114-144; failed() block lines 245-285. Cable fallback to exception message when column null. |
| `app/Jobs/ExtractRamsDraftJob.php`                                                  | Review-needed dispatch + idempotency via review_needed_email_sent_at | ✓ VERIFIED | Handler line 144; helper method `dispatchReviewNeededEmail()` at lines 176-210. Timestamp set BEFORE send.       |
| `app/Core/Modules/Survey/SurveyService.php`                                         | Refactored to use NotificationRecipientResolver         | ✓ VERIFIED | Lines 406-411; no `is_admin`, no `Project::with('user')`.                                                       |
| `app/Http/Controllers/SiteSurveyController.php`                                     | `auth()->user()?->isAdmin()` (B-02 fix)                 | ✓ VERIFIED | Line 449 uses `isAdmin()`. No `is_admin` string in file.                                                         |
| `tests/Feature/Notifications/` (8 files)                                            | Mail::fake() + assertQueued coverage                    | ✓ VERIFIED | All 8 files present (2 completion types × 4 + Review + Failed + Idempotency + Bcc).                              |
| `tests/Feature/PublicSurveyControllerTest.php`                                      | Extended with NOTF-02a regression guard                 | ✓ VERIFIED | `test_survey_submission_dispatches_SurveySubmittedMail_to_owner` added at line 172.                              |
| `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`                 | Production-cutover runbook (≥ 30 lines, Postmark + DNS + .env + smoke) | ✓ VERIFIED | 115 lines; all required positive/negative greps pass per 09-06-SUMMARY.                                         |

### Key Link Verification

| From                                              | To                                                     | Via                                                                            | Status | Details                                                                                                        |
| ------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------ | ------ | -------------------------------------------------------------------------------------------------------------- |
| `BuildRamsDocumentJob` handle()                    | `RamsReadyMail` dispatch                               | `new \App\Mail\RamsReadyMail($record)` after STATUS_COMPLETED + idempotency    | ✓ WIRED | Anchored grep: `completion_email_sent_at === null` + `STATUS_COMPLETED` within 2 lines; `RamsReadyMail` at line 164. |
| `BuildOmManualJob` handle()                        | `OmManualReadyMail` dispatch                           | `new \App\Mail\OmManualReadyMail($manual)` after STATUS_DRAFT + idempotency    | ✓ WIRED | Line 112.                                                                                                      |
| `BuildWorksheetJob` handle()                       | `WorksheetReadyMail` dispatch                          | `new \App\Mail\WorksheetReadyMail($worksheet)` after STATUS_DRAFT + idempotency | ✓ WIRED | Line 133.                                                                                                      |
| `BuildCableScheduleJob` handle()                   | `CableScheduleReadyMail` dispatch                      | `new \App\Mail\CableScheduleReadyMail($schedule)` after STATUS_DRAFT + idempotency | ✓ WIRED | Line 131; attachment uses `source_filename`.                                                                    |
| Each `Build*Job::failed()`                         | `DocumentGenerationFailedMail` dispatch (all admins)   | `resolver->resolveAdminRecipients()->each(...)` + 500-char truncation + detailUrl | ✓ WIRED | 4 jobs × foreach block; `substr($rawError, 0, 500)` in all 4.                                                    |
| `ExtractRamsDraftJob` handle() success             | `RamsReviewNeededMail` dispatch                        | `$this->dispatchReviewNeededEmail($record)` public seam after STATUS_AWAITING_REVIEW | ✓ WIRED | Helper method at 176-210 sets timestamp BEFORE send; gated on review_needed_email_sent_at.                       |
| Every dispatch site                                | Global BCC                                             | `config('rams.notifications.bcc')` + `$pending->bcc(trim($bcc))` when non-empty | ✓ WIRED | 27 `bcc` occurrences across 5 jobs; BccTest locks presence-when-set and absence-when-empty.                      |
| `SurveyService::submitPublic`                       | `SurveySubmittedMail` (existing)                       | `app(NotificationRecipientResolver::class)->resolveProjectRecipient($project)` | ✓ WIRED | Line 407-408.                                                                                                  |
| Laravel mail config                                | `symfony/postmark-mailer` transport                    | `config/mail.php` `postmark` transport + installed package                      | ✓ WIRED | `composer.json:21` declares `symfony/postmark-mailer ^8.0`.                                                     |
| All 6 mailables                                    | Attachment source                                      | `DocumentArtifactStorage::readPath(TYPE_X, basename($filename))`                | ✓ WIRED | H-07 convention; returns null → `attachments()` returns `[]` (graceful miss — no exception).                    |

### Requirements Coverage

All 26 NOTF-* IDs from phase plans are accounted for against `.planning/REQUIREMENTS.md`.

| Requirement | Source Plan        | Description (abridged)                                                           | Status | Evidence                                                                                                                                                          |
| ----------- | ------------------ | -------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| NOTF-01     | 09-03, 09-05       | Dispatch email on RAMS completion (+ the other 3 doc types)                      | ✓ PASS | 4 mailables + 4 wired Build*Jobs.                                                                                                                                  |
| NOTF-01a    | 09-03, 09-05, 09-02b | RamsDocumentReadyMail/shared, dispatched on STATUS_COMPLETED                    | ✓ PASS | `RamsReadyMail` + `BuildRamsDocumentJob.php:149`.                                                                                                                    |
| NOTF-01b    | 09-03, 09-05, 09-02b | Same for OM/Worksheet/Cable (STATUS_DRAFT/FINAL per type)                       | ✓ PASS | All three fire on STATUS_DRAFT (per RESEARCH Pitfall 4); typed mailables.                                                                                          |
| NOTF-01c    | 09-01, 09-05       | `completion_email_sent_at` timestamp on all 4 models                             | ✓ PASS | Migration + fillable + cast on each; email_sent_at (legacy manual path) preserved.                                                                                 |
| NOTF-01d    | 09-05              | Send-once guard: `=== null` check + update in same call as dispatch              | ✓ PASS | 4 completion sites + IdempotencyTest.                                                                                                                              |
| NOTF-01e    | 09-03              | Manual regenerations do not fire fresh "regenerated" email                       | ✓ PASS | Implicit — each new RamsDocument row is a new record with its own null timestamp; superseded rows keep their existing timestamps. No regeneration-specific code path. |
| NOTF-01f    | 09-03, 09-05       | Subject `[ref] {DocType} ready — {project_name}`; attachment via DocumentArtifactStorage | ✓ PASS | Envelope methods in all 4 *ReadyMail classes; attachments() uses `DocumentArtifactStorage::readPath()`.                                                            |
| NOTF-02     | 09-04, 09-05       | Survey submission notification (inherited path continues to work)                | ✓ PASS | `SurveyService::submitPublic()` still dispatches `SurveySubmittedMail`; feature test locks it.                                                                    |
| NOTF-02a    | 09-02, 09-04, 09-05, 09-02b | Post-refactor: resolver-based lookup; no regression                       | ✓ PASS | Resolver wired; latent bugs removed codebase-wide; PublicSurveyControllerTest extended.                                                                            |
| NOTF-03     | 09-04, 09-05       | RamsReviewNeededMail on ExtractRamsDraftJob STATUS_AWAITING_REVIEW               | ✓ PASS | Class + Blade + dispatch helper wired.                                                                                                                             |
| NOTF-03a    | 09-04, 09-05       | Dispatched from job (not model observer), ShouldQueue                            | ✓ PASS | `ExtractRamsDraftJob::dispatchReviewNeededEmail()` + `RamsReviewNeededMail implements ShouldQueue`.                                                                |
| NOTF-03b    | 09-04, 09-05       | Link to `route('rams.review', $rams)` in body                                    | ✓ PASS | `rams-review-needed.blade.php:47,55`.                                                                                                                              |
| NOTF-03c    | 09-01, 09-05       | `review_needed_email_sent_at` idempotency column + guard                         | ✓ PASS | Column in migration + fillable + cast on RamsDocument; guard at `ExtractRamsDraftJob.php:181`; `IdempotencyTest::test_review_needed_email_not_resent_...`.           |
| NOTF-04     | 09-04, 09-05       | DocumentGenerationFailedMail from each Build*Job::failed()                       | ✓ PASS | 4 failed() hooks wired.                                                                                                                                            |
| NOTF-04a    | 09-04, 09-05, 09-02b | Recipients = all admins                                                      | ✓ PASS | `resolveAdminRecipients()` returns Collection; foreach loops in all 4 jobs.                                                                                         |
| NOTF-04b    | 09-01, 09-05       | `failed_email_sent_at` column + update-in-same-call                              | ✓ PASS | Column on all 4 models; timestamp set before send; IdempotencyTest locks it.                                                                                        |
| NOTF-04c    | 09-01, 09-04, 09-05 | Failure email includes project_ref, project_name, documentType, truncated(500) error_message, detailUrl | ✓ PASS | All 5 fields in Blade; `substr($rawError, 0, 500)` in all 4 jobs; `cable_schedules.error_message` column added + fillable.                                  |
| NOTF-05     | 09-05              | Recipients + transport + ops                                                     | ✓ PASS | Collective — covered by NOTF-05a..h.                                                                                                                                |
| NOTF-05a    | 09-02, 09-04, 09-05 | NotificationRecipientResolver::resolveProjectRecipient centralised             | ✓ PASS | Resolver + 7 call sites + unit tests.                                                                                                                              |
| NOTF-05b    | 09-02, 09-04, 09-05 | Failure = `User::where('role', 'admin')->get()` (not is_admin)                 | ✓ PASS | Resolver + NotificationRecipientResolverTest::test_admin_lookup_uses_role_column_not_is_admin.                                                                      |
| NOTF-05c    | —                  | No per-project subscriber UI in v1.1 (deferred)                                  | ✓ PASS | Scope exclusion — correctly NOT implemented.                                                                                                                       |
| NOTF-05d    | 09-01, 09-05       | RAMS_NOTIFICATION_BCC env + call-site application                                | ✓ PASS | Config key + 9 call sites + BccTest (4 tests).                                                                                                                     |
| NOTF-05e    | 09-03, 09-04       | All Illuminate\Mail\Mailable subclasses (no Notification framework)             | ✓ PASS | All 6 mailables `extends Mailable` (no `Notification`).                                                                                                             |
| NOTF-05f    | 09-03, 09-04, 09-05 | All mailables `implements ShouldQueue`                                         | ✓ PASS | All 6 verified by grep: `implements ShouldQueue`.                                                                                                                   |
| NOTF-05g    | 09-02, 09-06       | Production transport = Postmark; composer + env + DNS prep                       | ⚠ PASS (code) / ? HUMAN (operational) | symfony/postmark-mailer installed; POSTMARK-OPS-CHECKLIST.md authored; cutover resume-signal: `cutover-noop` (deferred under --auto). Real production cutover is the human-verify checkpoint. |
| NOTF-05h    | 09-05              | try/catch + Log::warning at every call site                                      | ✓ PASS | 9 catch blocks across 5 jobs; SurveyService block at 413-418.                                                                                                      |

**Orphaned requirements:** None. All 26 NOTF-* IDs from REQUIREMENTS.md map to at least one of the phase plans.

### Anti-Patterns Found

Note: cross-referenced with `09-REVIEW.md` which already documented 0 critical + 4 warnings + 6 info items.

| File                          | Line | Pattern                                              | Severity | Impact                                                                                                                                    |
| ----------------------------- | ---- | ---------------------------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `BuildWorksheetJob.php`       | 156-159 | Untruncated `$e->getMessage()` into `string(1000)` column (WR-01 from 09-REVIEW) | ⚠ Warning | Inconsistent with OmManual's `substr(0, 500)` pattern; could throw on overflow and mask real exception. Does not block goal.                |
| `BuildRamsDocumentJob::failed`, `BuildWorksheetJob::failed` | 213-217 / 176-180 | Status update not in try/catch — can short-circuit admin alert (WR-02) | ⚠ Warning | If the `->update(...)` throws (DB transient / column overflow), `failed()` re-throws before hitting the `failed_email_sent_at` guard → admin alert dropped for this exhaustion. OmManual + Cable both correctly wrap. Does not block goal — edge-case only. |
| 4 Build jobs                  | various | Inconsistent error_message truncation policy (WR-03) | ⚠ Warning | Standardise on 500 chars / extract constant. Does not block goal.                                                                         |
| `BuildCableScheduleJob::failed` | 234, 248 | Re-fetches same CableSchedule record twice (WR-04)   | ⚠ Warning | Minor — two DB round-trips. Does not block goal.                                                                                          |
| `BuildRamsDocumentJob::handle` | 137-139 | Manual-form path lands in STATUS_FOR_REVIEW and never emails (IN-01) | ℹ Info | Likely intentional; add inline comment for future readers. Does not block goal.                                                            |
| 4 failed() hooks              | `$e?->getMessage()` null-safe | Redundant ?-> (Laravel guarantees non-null `$e`) (IN-02) | ℹ Info | Cleanup. Does not block goal.                                                                                                              |
| `SurveyService::submitPublic`  | 403-419 | SurveySubmittedMail sent synchronously, not ShouldQueue (IN-03) | ℹ Info | Documented design choice — inline send so survey-submit flow doesn't require queue worker. Inconsistent with the 6 new mailables but intentional. |
| 4 failed() hooks              | 4 × BCC snippet duplication (IN-04) | Tech debt — extract helper  | ℹ Info | Ship-safe. Flagged for follow-up.                                                                                                          |
| `IdempotencyTest`             | 68 | Second handle() call causes status oscillation (IN-05) | ℹ Info | Test artefact; locks the intended invariant correctly regardless.                                                                           |
| 4 *-ready Blade templates      | 41-45 | Body conditional says "attached" but `attachments()` may return `[]` if readPath null (IN-06) | ℹ Info | Edge case when artifact deleted from disk but filename still set. Normal lifecycle is safe. Does not block goal.                             |

**Blockers:** 0

### Behavioral Spot-Checks

Limited because phase is Laravel-server-side and requires `vendor/` + DB + mail fake infrastructure. Key greps (all executed during verification) act as behavioral equivalents:

| Behavior                                                                     | Check                                                                                       | Result                          | Status       |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------- | ------------ |
| Symfony Postmark transport installed                                          | `composer.json` declares `symfony/postmark-mailer ^8.0`                                      | Found at line 21               | ✓ PASS       |
| Idempotency column wired in all 4 notifiable models                           | `grep -r completion_email_sent_at app/Models` → 8 matches (4 fillable + 4 cast)             | 8 matches                       | ✓ PASS       |
| NOTF-03c column added + wired                                                 | `grep -r review_needed_email_sent_at app/` → 7 matches (job + helper + fillable + cast + comment) | 7 matches                       | ✓ PASS       |
| `is_admin` bug pattern absent codebase-wide                                    | `grep -r is_admin app/ --include='*.php'` → 0 matches                                        | 0 matches                       | ✓ PASS       |
| `Project::with('user')` bug pattern absent codebase-wide                      | `grep -r Project::with\('user'\) app/` → 0 matches                                           | 0 matches                       | ✓ PASS       |
| All 6 mailables implement ShouldQueue                                         | `grep -c 'implements ShouldQueue' app/Mail/*.php` → 6 hits across 6 new files                | 6 hits                          | ✓ PASS       |
| All 9 dispatch call sites apply BCC                                           | `grep -c 'bcc' app/Jobs/*.php` → 27 occurrences across 5 jobs                                | 27 occurrences                  | ✓ PASS       |
| Error message truncation at 500 chars                                         | `grep -r 'substr.*0, 500' app/Jobs` → 4 matches (one per Build*Job)                          | 4 matches                       | ✓ PASS       |
| PHPUnit feature+unit suites green per 09-05 SUMMARY                           | 367 Unit + 24 Notifications + 5 PublicSurvey = green; 6 pre-existing Vite/Auth failures deferred | Per 09-05-SUMMARY metrics      | ✓ PASS (reported) |

**Not spot-checked (requires live vendor + DB):** Actual `php artisan migrate`, `vendor/bin/phpunit` run. Verification relies on 09-05-SUMMARY + 09-REVIEW reported PHPUnit outcomes + static greps on committed code.

### Human Verification Required

Two items require human/operational verification before phase is fully closed:

#### 1. Dev smoke — MAIL_MAILER=log rendering

**Test:** Run a real RAMS document generation locally with `MAIL_MAILER=log` (the dev default). Verify `storage/logs/laravel.log` contains a rendered `RamsReadyMail` body. Repeat for each of the other 5 mailables per the 09-05 SUMMARY smoke instructions (including `ExtractRamsDraftJob` success flipping status to awaiting_review + triggering `RamsReviewNeededMail`, and a tinker-driven `(new BuildRamsDocumentJob($id))->failed(new Exception('smoke'))` for `DocumentGenerationFailedMail`).

**Expected:** Each rendered email carries the correct subject pattern (`[ref] {DocType} ready — {name}`), correct recipient (project owner or admin fallback), correct attachment (for completion mails), and links to the right dashboard route. Each idempotency column on the corresponding model advances from NULL → timestamp on first dispatch and stays set on repeat runs.

**Why human:** Can't be verified by automated grep. Requires running the real queue worker against a dev DB with a real quote PDF and confirming log output. Feature tests prove wiring via `Mail::fake()` but don't exercise the full log-mailer round-trip.

#### 2. Production Postmark cutover (NOTF-05g operational)

**Test:** Follow `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md` sections 1–4:
1. Verify Postmark sender signature for `rams@21stcav.com` (or domain verify `21stcav.com`)
2. Publish SPF (+`include:spf.mtasv.net`), DKIM selector, DMARC (`p=none` → `p=quarantine` after 14d) on `21stcav.com` zone
3. Set `MAIL_MAILER=postmark` + `POSTMARK_API_KEY` in production `.env`; run `config:cache` + `queue:restart`
4. Post-cutover: verify `check-auth@verifier.port25.com` returns pass for SPF/DKIM/DMARC, trigger one of each mailable type, confirm `Delivered` in Postmark Activity, confirm BCC audit copy reaches `ops@21stcav.com`.

**Expected:** First production send appears as `Delivered` in Postmark Activity; reputation stays "Great"; zero hard bounces for 24h; BCC inbox receives audit copies.

**Why human:** Production env access, Postmark dashboard auth, DNS zone write access, and real send/receive verification cannot be performed by automated checks. Phase 09 code is complete and safe to deploy under `MAIL_MAILER=log` (dev) or `MAIL_MAILER=postmark` (production) — cutover is an env flip, not a code change. Resume-signal recorded as `cutover-noop` (deferred) in 09-06-SUMMARY.md per `--auto` execution mode.

### Gaps Summary

No blocking gaps found. All 9 observable truths verified by automated evidence; all 26 NOTF-* requirement IDs covered; all key links wired; all artifacts present.

Phase 09 is **code-complete**. Two items require human verification before the phase is operationally closed:
1. Dev smoke under `MAIL_MAILER=log` to confirm real mail rendering works end-to-end (quick, ~15 min).
2. Production Postmark cutover per `POSTMARK-OPS-CHECKLIST.md` — explicitly deferred by plan 09-06's human-verify checkpoint (`cutover-noop` resume-signal under `--auto` mode); not a code gap, purely operational.

The four Warning-level items from `09-REVIEW.md` (WR-01..WR-04) are real quality issues (inconsistent error truncation, narrow edge cases where a DB write in `failed()` could drop the admin alert) but they do not block the phase goal. They are follow-up polish, appropriate as a short separate plan (e.g., `09-07-failed-hook-hardening`) or folded into Phase 10 housekeeping.

---

_Verified: 2026-04-19T00:00:00Z_
_Verifier: Claude (gsd-verifier)_
