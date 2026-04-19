---
phase: 09-email-notifications
reviewed: 2026-04-19T00:00:00Z
depth: standard
files_reviewed: 41
files_reviewed_list:
  - app/Core/Modules/Survey/SurveyService.php
  - app/Http/Controllers/SiteSurveyController.php
  - app/Jobs/BuildCableScheduleJob.php
  - app/Jobs/BuildOmManualJob.php
  - app/Jobs/BuildRamsDocumentJob.php
  - app/Jobs/BuildWorksheetJob.php
  - app/Jobs/ExtractRamsDraftJob.php
  - app/Mail/CableScheduleReadyMail.php
  - app/Mail/DocumentGenerationFailedMail.php
  - app/Mail/OmManualReadyMail.php
  - app/Mail/RamsReadyMail.php
  - app/Mail/RamsReviewNeededMail.php
  - app/Mail/WorksheetReadyMail.php
  - app/Models/CableSchedule.php
  - app/Models/OmManual.php
  - app/Models/RamsDocument.php
  - app/Models/Worksheet.php
  - app/Services/NotificationRecipientResolver.php
  - config/rams.php
  - database/factories/CableScheduleFactory.php
  - database/factories/OmManualFactory.php
  - database/factories/RamsDocumentFactory.php
  - database/factories/WorksheetFactory.php
  - database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php
  - resources/views/emails/cable-schedule-ready.blade.php
  - resources/views/emails/document-generation-failed.blade.php
  - resources/views/emails/om-manual-ready.blade.php
  - resources/views/emails/rams-ready.blade.php
  - resources/views/emails/rams-review-needed.blade.php
  - resources/views/emails/worksheet-ready.blade.php
  - tests/Feature/Notifications/BccTest.php
  - tests/Feature/Notifications/CableScheduleCompletionNotificationTest.php
  - tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php
  - tests/Feature/Notifications/IdempotencyTest.php
  - tests/Feature/Notifications/OmManualCompletionNotificationTest.php
  - tests/Feature/Notifications/RamsCompletionNotificationTest.php
  - tests/Feature/Notifications/RamsReviewNeededNotificationTest.php
  - tests/Feature/Notifications/WorksheetCompletionNotificationTest.php
  - tests/Feature/PublicSurveyControllerTest.php
  - tests/Unit/Services/NotificationRecipientResolverTest.php
findings:
  critical: 0
  warning: 4
  info: 6
  total: 10
status: issues_found
---

# Phase 09: Code Review Report

**Reviewed:** 2026-04-19T00:00:00Z
**Depth:** standard
**Files Reviewed:** 41
**Status:** issues_found

## Summary

Phase 09 implements the email notification pipeline across four document types
(RAMS, O&M, Worksheet, Cable Schedule) plus RAMS review-needed and survey
submission paths. The design is solid:

- The `NotificationRecipientResolver` centralises the owner/admin fallback
  logic and correctly uses `role='admin'` and the `Project::owner` relation
  — deliberately avoiding the two latent bugs (`is_admin` / `->user`)
  called out in 09-RESEARCH.md Pitfalls 1 + 2. Regression locks are in place
  via `NotificationRecipientResolverTest`.
- Idempotency follows the "timestamp-before-send" pattern consistently in
  all six dispatch sites; the `IdempotencyTest` class pins the contract.
- Mailable attachments uniformly go through `DocumentArtifactStorage::readPath()`
  per the H-07 convention — no hand-built `storage_path('app/rams/...')`
  regression.
- BCC handling is correctly applied at the call site (Approach B), honouring
  `config('rams.notifications.bcc')` and skipping when empty/null.
- No reintroduction of `Project::with('user')` or `User->is_admin`.

No Critical issues were found. Four Warnings relate to inconsistencies in the
failed-hook safety pattern between the four Build jobs, one of which could
mask the admin failure-alert dispatch in a narrow edge case. Info items are
minor cleanups and documentation gaps.

## Warnings

### WR-01: BuildWorksheetJob writes untruncated $e->getMessage() to string(1000) column

**File:** `app/Jobs/BuildWorksheetJob.php:156-159`
**Issue:** The catch block stores the raw exception message in
`worksheets.error_message`, which is declared `string(1000)` in
`2026_04_11_000001_create_worksheets_table.php:37`. If an exception message
exceeds 1000 bytes (AI provider error bodies and stack traces often do), the
`$worksheet->update()` call will either truncate (strict=off) or throw a
`QueryException` (strict=on). A throw here would mask the original exception
and the re-`throw $e;` would never fire, leaving the worker to log a schema
error rather than the real failure. `BuildOmManualJob.php:140` correctly
truncates to 500 chars — this site should match.
**Fix:**
```php
$worksheet->update([
    'status'        => Worksheet::STATUS_FAILED,
    'error_message' => substr($e->getMessage(), 0, 500),
]);
```

### WR-02: BuildRamsDocumentJob::failed and BuildWorksheetJob::failed status updates not in try/catch — can short-circuit the admin alert

**File:** `app/Jobs/BuildRamsDocumentJob.php:213-217`, `app/Jobs/BuildWorksheetJob.php:176-180`
**Issue:** The `failed()` hook in both jobs performs an unwrapped
`->update(['status' => FAILED, 'error_message' => ...])` as the first thing
it does. If that update throws (transient DB connection, column overflow for
WR-01, etc.) the `failed()` method re-throws before reaching the
`failed_email_sent_at` idempotency block — so the admin failure-alert email
never dispatches for this retry exhaustion. `BuildCableScheduleJob::failed`
(lines 233-243) and `BuildOmManualJob::failed` (lines 165-176) both wrap this
update in an inner `try { } catch (\Throwable $dbErr) { Log::critical(...) }`.
RAMS and Worksheet should match to guarantee the alert always fires.

A throwing `failed()` is also the pattern CLAUDE.md's execution-flow checklist
flags as dangerous ("failed() hook safety — must not throw (would trigger
infinite retry loops)"). In practice Laravel's queue system calls `failed()`
at most once per job after retries are exhausted, so an infinite loop is
unlikely, but the dropped alert is a real regression risk.
**Fix (apply to both files):**
```php
try {
    RamsDocument::find($this->ramsDocumentId)?->update([
        'status'        => RamsDocument::STATUS_FAILED,
        'error_message' => substr($e->getMessage(), 0, 500),
    ]);
} catch (\Throwable $dbErr) {
    Log::critical('BuildRamsDocumentJob::failed: could not set failed status', [
        'record_id' => $this->ramsDocumentId,
        'db_error'  => $dbErr->getMessage(),
    ]);
}
```

### WR-03: Error-message truncation inconsistent across the four Build jobs

**File:** `app/Jobs/BuildRamsDocumentJob.php:190`, `app/Jobs/BuildWorksheetJob.php:158`, `app/Jobs/BuildOmManualJob.php:140`, `app/Jobs/BuildCableScheduleJob.php` (no write)
**Issue:** The handle() catch blocks treat `error_message` inconsistently:

| Job | handle() catch truncation | failed() truncation |
| --- | --- | --- |
| OM Manual | `substr(..., 0, 500)` | `'All retries exhausted: ' . substr(..., 0, 400)` |
| Worksheet | none (WR-01) | none |
| RAMS | none (TEXT column, so safe) | none |
| Cable | no column write | no column write |

Even though `rams_documents.error_message` is TEXT (unbounded), a uniform
truncation policy protects log volume, email bodies, and downstream consumers
that may assume a reasonable bound. Pick one cap (500 appears to be the
convention in NOTF-04c and OmManual) and apply it everywhere.
**Fix:** Standardise all four jobs on `substr($e->getMessage(), 0, 500)` in
both `handle()` catch blocks and `failed()` hooks. Add a helper constant
e.g. `private const ERROR_MESSAGE_MAX = 500;` if the value is reused across
jobs.

### WR-04: BuildCableScheduleJob::failed re-fetches the same CableSchedule record twice

**File:** `app/Jobs/BuildCableScheduleJob.php:234, 248`
**Issue:** The failed() hook does `CableSchedule::find($this->cableScheduleId)`
on line 234 (inside the status-update try block) and then does
`$record = CableSchedule::find($this->cableScheduleId)` again on line 248.
This is two DB round-trips for the same record in the same method — minor,
but since the status update on line 234 already produced an updated model
instance, it should be reused. The second lookup also loses the safety of
the earlier try/catch.

More importantly, if the first `find()` returned null (record deleted
between retries) the `?->update(...)` silently no-ops, but the second
`find()` is performed anyway and hits the same null result — the subsequent
`if ($record && ...)` branch is skipped, but we've paid for two queries to
discover the same missing record.
**Fix:**
```php
$record = CableSchedule::find($this->cableScheduleId);

try {
    $record?->update(['status' => CableSchedule::STATUS_FAILED]);
} catch (\Throwable $dbErr) {
    Log::critical('BuildCableScheduleJob::failed: could not set failed status', [
        'cable_schedule_id' => $this->cableScheduleId,
        'db_error'          => $dbErr->getMessage(),
    ]);
}

if ($record && $record->failed_email_sent_at === null) {
    // … existing dispatch block
}
```

## Info

### IN-01: Manual-form RAMS generation never dispatches a completion email

**File:** `app/Jobs/BuildRamsDocumentJob.php:137-139, 149-150`
**Issue:** When `$isManualFormGeneration` is true the job sets status to
`STATUS_FOR_REVIEW` (not `STATUS_COMPLETED`). The completion-email guard on
line 149 only fires on `STATUS_COMPLETED`, so manual-form submissions never
receive the `RamsReadyMail`. This may be intentional (manual form is a
pre-review state), but the behaviour is not documented in the class docblock
or inline and a future reader will likely ask whether it is a bug.
**Fix:** Add a comment next to the guard clarifying this is by design, e.g.
`// Manual-form path lands in STATUS_FOR_REVIEW and does not trigger a
completion email — the user is still actively reviewing at that stage.`

### IN-02: Redundant null-safe operator on $e in failed() hooks

**File:** `app/Jobs/BuildCableScheduleJob.php:257`, `app/Jobs/BuildOmManualJob.php:187`, `app/Jobs/BuildRamsDocumentJob.php:229`, `app/Jobs/BuildWorksheetJob.php:191`
**Issue:** Expression `$e?->getMessage()` uses the null-safe operator, but
Laravel's queue contract guarantees `failed()` is always called with a
non-null `Throwable`. The `?` is redundant and slightly misleading (implies
`$e` could be null). Low priority cleanup.
**Fix:** Change to `$e->getMessage()`.

### IN-03: SurveyService::submitPublic dispatches mail synchronously in request thread

**File:** `app/Core/Modules/Survey/SurveyService.php:403-419`
**Issue:** `SurveySubmittedMail` does NOT implement `ShouldQueue`, so the
`Mail::to(...)->send(...)` call blocks the HTTP request until the SMTP
provider returns. The try/catch correctly prevents a mail failure from
rolling back the transaction (which already committed), but the user may
experience a slow redirect if the mail provider is degraded. Consider making
`SurveySubmittedMail` queueable (ShouldQueue) for consistency with the
other six Phase 09 mailables — or explicitly document why inline send is
preferred for this path (the docblock says "Sent inline (not queued) so a
queue worker is not required" which is a valid reason but is now the only
mailable breaking the pattern).
**Fix:** Either add `ShouldQueue` to the mailable or leave a cross-reference
comment near the `Mail::to(...)->send(...)` call pointing to the docblock
rationale so future reviewers don't "fix" it accidentally.

### IN-04: DocumentGenerationFailedMail BCC helper extraction opportunity

**File:** `app/Jobs/BuildCableScheduleJob.php:261-270`, `app/Jobs/BuildOmManualJob.php:191-200`, `app/Jobs/BuildRamsDocumentJob.php:233-242`, `app/Jobs/BuildWorksheetJob.php:195-204`
**Issue:** Each of the four failed() hooks duplicates the same 4-line BCC
resolution snippet (`$bcc = config('rams.notifications.bcc'); … if (is_string
&& trim !== '') $pending->bcc(trim($bcc));`). The same is true of the
completion-email blocks. Consider extracting a thin helper e.g.
`NotificationBccHelper::apply(PendingMail $m): PendingMail` or a trait on the
jobs to keep the BCC rule in one place. A drift here (e.g. a typo in one job
site) would be a silent email-delivery bug.
**Fix:** Extract to a single helper method/trait, for example:
```php
// App\Services\NotificationRecipientResolver (or new helper)
public function applyBcc(\Illuminate\Mail\PendingMail $pending): \Illuminate\Mail\PendingMail
{
    $bcc = config('rams.notifications.bcc');
    return (is_string($bcc) && trim($bcc) !== '')
        ? $pending->bcc(trim($bcc))
        : $pending;
}
```
Not blocking for this phase — phase targets feature delivery — but worth
flagging for tech debt.

### IN-05: IdempotencyTest second handle() call produces an unnecessary status oscillation

**File:** `tests/Feature/Notifications/IdempotencyTest.php:68`
**Issue:** The test calls `handle()` twice on the same job instance. Because
handle() unconditionally writes `status=STATUS_GENERATING` on line
`BuildRamsDocumentJob.php:105`, the second call reverts a COMPLETED record
back to GENERATING, then back to COMPLETED. This doesn't affect the
idempotency assertion (which is what the test cares about), but it does
exercise a path — status-regression from COMPLETED — that would never
happen in production because the queue never re-runs a successful job.
A tighter simulation of the $tries=2 retry is to throw on the first call and
re-run only the dispatch block. Consider adding a comment in the test
docblock noting this is a simulation of "job handler re-entry" rather than
"true Laravel queue retry".
**Fix:** Add a docblock note, e.g. `// Simulates handler re-entry (not a
true queue retry) — the important assertion is that the idempotency column
is honoured on the second pass regardless of how we got there.`

### IN-06: Mailable view templates use `$schedule->source_filename` check but attach based on `readPath` lookup

**File:** `resources/views/emails/cable-schedule-ready.blade.php:41-45`, `resources/views/emails/om-manual-ready.blade.php:41-45`, `resources/views/emails/rams-ready.blade.php:41-45`, `resources/views/emails/worksheet-ready.blade.php:41-45`
**Issue:** The email body branches on `@if($schedule->source_filename)` to
say "attached to this email" vs "download from the dashboard". The Mailable
however will *silently return `[]`* from `attachments()` when
`DocumentArtifactStorage::readPath()` returns null (file missing from disk
but filename still set in DB — e.g. manually deleted artifact). In that
edge case the email body promises an attachment but none is delivered.

Low priority because normal lifecycle keeps filename and artifact in sync,
but worth aligning: either make the Blade condition mirror the attachment
lookup, or have the Mailable expose an `hasAttachment()` accessor.
**Fix:** In each mailable expose:
```php
public function hasAttachment(): bool
{
    return ! empty($this->attachments());
}
```
And switch the Blade conditional:
```blade
@if($schedule->hasAttachment())
    <p>The Cable Schedule is attached to this email.</p>
@else
    <p>Download from the dashboard:</p>
@endif
```

---

_Reviewed: 2026-04-19T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
