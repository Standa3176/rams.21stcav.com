# Phase 09: Email Notifications - Research

**Researched:** 2026-04-19
**Domain:** Laravel 12 transactional mail (Mailable + queue) wired into existing async document-generation jobs
**Confidence:** HIGH

## Summary

The codebase already has every primitive Phase 09 needs: a working `Mailable` convention (`RamsDocumentMail`, `SurveySubmittedMail`), a unified artifact-path service (`DocumentArtifactStorage`), four queued generator jobs that all share the same shape (handle / try-catch-status-flip-rethrow / `failed()` hook), and a `database` queue driver. The phase is therefore overwhelmingly **wiring work, not new infrastructure** — the only net-new pieces are four mailable classes, one recipient-resolver service, four pairs of timestamp columns, and the Postmark transport package.

Two **latent bugs in CONTEXT.md** must be flagged to the planner before locking decisions: (a) `User::where('is_admin', true)` does not work — the `users` table has no `is_admin` column; the existing code uses a `role` string with `'admin'` value (and the `User::isAdmin()` method tests `$this->role === 'admin'`). The existing `SurveyService` admin-fallback line silently returns null today. (b) `Project` has no `user()` relation — only `owner()` (FK `user_id`). Existing code in `SurveyService::submitPublic()` queries `Project::with('user')` which returns a null relation; the survey-submitted email therefore never finds a fallback admin recipient. Phase 09 must fix both, otherwise the new triggers will misbehave the same way.

**Primary recommendation:** Build a single `App\Services\NotificationRecipientResolver` (resolves project owner with `role = 'admin'` fallback), four typed `*ReadyMail` mailables + one `RamsReviewNeededMail` + one `DocumentGenerationFailedMail`, wire each `Build*Job` success path and `failed()` hook with the idempotency-guarded send pattern, add `completion_email_sent_at` + `failed_email_sent_at` columns via one consolidated migration, and refactor `SurveyService::submitPublic()` to use the new resolver — fixing the latent admin-fallback bug as a side effect. Install `symfony/postmark-mailer` and document the `POSTMARK_API_KEY` env var (NOT `POSTMARK_TOKEN` as CONTEXT.md says).

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Trigger Inventory**
- **D-01:** Generation-complete email fires for **all four document types** — RAMS, O&M Manual, Worksheet, Cable Schedule. Hook into `BuildRamsDocumentJob`, `BuildOmManualJob`, `BuildWorksheetDocxJob`, and the cable-schedule generator job at the success path (after the model status flips to `completed` / `final`).
- **D-02:** Manual regenerations do **not** fire a separate "regenerated" email — only the standard completion email when the new doc reaches `completed`. Old superseded versions stay silent.
- **D-03:** Generation-failed email fires when a `Build*Job` exhausts retries and the model status lands on `failed`. Goes only to admins (not the project owner). Use the existing `failed()` job hook so it triggers exactly once per failed run.
- **D-04:** Review-needed email fires when a `RamsDocument` transitions to `awaiting_review` (i.e., `ExtractRamsDraftJob` completes successfully). Single trigger, RAMS-only — other document types do not currently have a review state. **No** 7-day reminder in v1.1 (deferred).
- **D-05:** Survey-submitted trigger is unchanged — `SurveyService::submitPublic()` already sends `SurveySubmittedMail`. Phase 09 inherits it; template polish is allowed but the call site stays put. No new survey-related triggers.

**Recipient Policy**
- **D-06:** Default recipient for completion + review-needed = **project owner only** (`Project::user_id`). Falls back to first admin user when `project.user_id` is null or the user record is missing.
- **D-07:** Failure alerts go to **all admin users**. ⚠ See *Latent Bugs* below — `User::where('is_admin', true)` does not work; correct query is `User::where('role', 'admin')`.
- **D-08:** No per-project subscriber list / no `project_notification_recipients` table.
- **D-09:** Configurable global BCC for audit. Add env var `RAMS_NOTIFICATION_BCC`. Apply via shared base mailable / `Mailable::bcc()` when env var is non-empty.

**Channel & Delivery**
- **D-10:** Stay on **Mailable pattern** — do **not** migrate to `Notification` framework in v1.1.
- **D-11:** Mark all new system mailables `implements ShouldQueue` so they dispatch through the `database` queue.
- **D-12:** Failure handling: every send wrapped in `try { } catch (\Throwable $e) { Log::warning(...) }`. A failed mail must never roll back or break a document-generation job.

**Idempotency & Tracking**
- **D-13:** Add `*_email_sent_at` timestamp columns to each notifiable model. `RamsDocument.email_sent_at` already exists but is owned by the manual-send path; add a separate `RamsDocument.completion_email_sent_at` for the auto-trigger.
- **D-14:** Send-once guard: each completion notifier checks `$model->completion_email_sent_at === null` before sending; sets the timestamp inside the same `update()` call.
- **D-15:** Failure-alert idempotency: only send when `failed_email_sent_at` is null on that model row.

**Production Transport**
- **D-16:** Mail driver = **Postmark**. `MAIL_MAILER=postmark` + `POSTMARK_TOKEN` (⚠ should be `POSTMARK_API_KEY` — see *Latent Bugs*) in production `.env`.
- **D-17:** From address = **`rams@21stcav.com`**. Requires SPF / DKIM / DMARC DNS work on `21stcav.com`.
- **D-18:** From name = `RAMS Platform`. Subject convention: `[Project Ref] Subject — Project Name`.
- **D-19:** Bounce/complaint handling = **log only** in v1.1.
- **D-20:** Dev/test stays on `MAIL_MAILER=log`; CI sets `MAIL_MAILER=array`. ✅ already done — `phpunit.xml:28` already sets `MAIL_MAILER=array`.

**Phase Boundary Enforcement**
- **D-21:** No notification preferences UI / table in v1.1.
- **D-22:** No in-app notification centre, toasts, read/unread inbox, Slack/Teams/Bitrix channels.

### Claude's Discretion

- Email content, Blade template structure, visual style (plain text vs minimal branded HTML).
- Whether to introduce `App\Notifications\NotificationDispatcher` for recipient resolution. CONTEXT prefers extraction if it appears in 3+ trigger sites — Phase 09 has 6 trigger sites, so extraction is mandatory (recommended name: `App\Services\NotificationRecipientResolver` per NOTF-05a).
- Test strategy: feature tests using `Mail::fake()` per trigger, plus a unit test for the recipient-resolver helper.

### Deferred Ideas (OUT OF SCOPE)

- 7-day RAMS review reminder (scheduled command — defer).
- Abandoned-survey reminder (14-day nudge).
- Engineer survey-link confirmation email.
- Per-user notification preferences (opt-out, per-event subscription).
- Multi-channel `Notification` framework (Slack/Teams/Bitrix channels).
- In-app notification centre / read-unread inbox.
- Bounce/complaint webhook + email-status table.
- Per-project subscriber list (`project_notification_recipients`).
- Failure-recipient policy via `config/rams.php` admin override list.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| NOTF-01 | Document generation completion notifications (all 4 types) | All 4 jobs located, share identical handle / failed() shape — see *Build Job Inventory* table |
| NOTF-01a | Mailable dispatched from `BuildRamsDocumentJob` after `STATUS_COMPLETED` flip | Hook point: `BuildRamsDocumentJob.php:131-140` (the `STATUS_COMPLETED` update inside `handle()`) |
| NOTF-01b | Same hook in `BuildOmManualJob` (`STATUS_DRAFT`), `BuildWorksheetJob` (`STATUS_DRAFT`), `BuildCableScheduleJob` (`STATUS_DRAFT`) | All four hook points identified — see *Build Job Inventory*. ⚠ NOTF-01b says O&M flips to `final`, but the actual code flips to `STATUS_DRAFT` (see CONTEXT.md drift below) |
| NOTF-01c | New `completion_email_sent_at` column on each of 4 tables | One consolidated migration — see *Migration Strategy* |
| NOTF-01d | Send-once guard via `completion_email_sent_at === null` check | Pattern documented — see *Idempotency Pattern* |
| NOTF-01e | Manual regeneration creates new row → naturally re-fires | Verified via `RamsController::regenerate()` line 811 — creates fresh `RamsDocument` row with no `completion_email_sent_at` |
| NOTF-01f | Attach generated artifact via `DocumentArtifactStorage::readPath()`, omit gracefully when missing | Existing `RamsDocumentMail::attachments()` at `app/Mail/RamsDocumentMail.php:38-59` is the canonical pattern; cable schedules need conditional MIME (`xlsx` or `csv` fallback) — see *Mailable Class Structure* |
| NOTF-02 | Survey-submitted notification (inherited) | Already implemented at `SurveyService.php:403-418` — trigger stays put |
| NOTF-02a | Refactor `SurveyService` to use `NotificationRecipientResolver` for consistency | Refactor is small (4 lines) AND fixes the latent admin-fallback bug — see *Latent Bugs* |
| NOTF-03 | RAMS review-needed notification | Hook point: `ExtractRamsDraftJob.php:130-134` (the `STATUS_AWAITING_REVIEW` update) |
| NOTF-03a | `RamsReviewNeededMail` dispatched from job (not observer) | Dispatch from job is correct per D-04 — observers fire on raw `update()` calls everywhere (regen path, controller saves) and would cause spurious emails |
| NOTF-03b | Recipient = project owner with admin fallback; body links to `route('rams.review', $rams)` | Route exists at `routes/web.php:162` — `name('rams.review')` |
| NOTF-04 | Document generation failure alert | Hook point: each `Build*Job::failed()` hook (already exists in all 4 jobs) |
| NOTF-04a | `DocumentGenerationFailedMail` dispatched from `failed()` to all admins | `failed()` fires exactly once per retry exhaustion — Laravel 12 verified |
| NOTF-04b | `failed_email_sent_at` column + same-update guard | One migration adds both columns to all 4 tables — see *Migration Strategy* |
| NOTF-04c | Failure email body includes project ref/name, doc type, truncated `error_message` (500 chars), link to detail page | All four models already store `project_ref` + `project_name`; ⚠ `cable_schedules` table has NO `error_message` column (see *CableSchedule Asymmetry* below) |
| NOTF-05 | Shared recipient/transport/failure-handling rules across all triggers | Centralised in `NotificationRecipientResolver` + base mailable / shared trait |
| NOTF-05a | `NotificationRecipientResolver::resolveProjectRecipient(Project $project): ?User` | Recommended location: `app/Services/NotificationRecipientResolver.php` |
| NOTF-05b | Failure-alert recipients = all admins via role query | Correct query: `User::where('role', 'admin')->get()` — NOT `is_admin = true` |
| NOTF-05c | No per-project subscriber table | Out of scope |
| NOTF-05d | `RAMS_NOTIFICATION_BCC` env → `config('rams.notifications.bcc')` → applied via shared trait | Add `notifications.bcc => env('RAMS_NOTIFICATION_BCC')` to `config/rams.php` (which currently has only `company_*` keys) |
| NOTF-05e | All system mailables remain `Illuminate\Mail\Mailable` subclasses | Out-of-the-box — no migration needed |
| NOTF-05f | All system mailables `implements ShouldQueue` | Existing 2 mailables don't (only `Queueable` trait) — D-11 applies only to NEW Phase 09 mailables; existing 2 stay sync (no behavior change) |
| NOTF-05g | Production transport = Postmark; `MAIL_MAILER=postmark` + `POSTMARK_API_KEY` (⚠ NOT `POSTMARK_TOKEN`); from = `rams@21stcav.com` / `RAMS Platform`; SPF/DKIM/DMARC DNS work | See *Postmark Setup* — `symfony/postmark-mailer` package install required |
| NOTF-05h | try/catch + `Log::warning` per send; never roll back | Pattern proven at `SurveyService.php:412-417` |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 (already installed) | Mailable, queue, mail transports | Project core |
| `symfony/postmark-mailer` | ^7.2 (NEEDS INSTALL) | Postmark HTTP API transport for Symfony Mailer | `[CITED: Laravel 12 docs]` Required by Laravel for `'transport' => 'postmark'` to work; currently only listed as "suggest" in `symfony/mailer/composer.json` |
| `symfony/http-client` | ^7.2 (auto-pulled by postmark-mailer) | HTTP transport used by Postmark client | Hard dep of postmark-mailer |
| `phpunit/phpunit` | ^11.5.3 (already installed) | Feature tests with `Mail::fake()` | Project standard |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Illuminate\Support\Facades\Mail` | (Laravel) | `Mail::to(...)->send(...)`, `Mail::fake()`, `Mail::assertSent()` | All trigger sites + every feature test |
| `Illuminate\Mail\Mailables\Attachment::fromPath` | (Laravel) | Attaching DOCX/XLSX/CSV from absolute paths | All four `*ReadyMail::attachments()` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `Mailable` | `Illuminate\Notifications\Notification` framework | D-10 rejects this — multi-channel future-proofing belongs in Phase 11+ when Slack/Bitrix channels exist |
| One polymorphic `DocumentReadyMail` | Four typed `*ReadyMail` classes | Recommended four-class — see *Mailable Class Structure* |

**Installation:**
```bash
composer require symfony/postmark-mailer symfony/http-client
```

**Version verification:** `[CITED: Laravel 12 mail docs]` confirms `composer require symfony/postmark-mailer symfony/http-client` is the canonical install for Laravel 12 Postmark transport. `[VERIFIED: composer.lock]` shows `symfony/mailer ^7.4.6` is already pulled in by Laravel 12 and `symfony/postmark-mailer ^7.2.0` is listed as a "suggest" dependency — confirming no installed version yet.

## Architecture Patterns

### Recommended Project Structure
```
app/
├── Mail/                              # All mailables (existing convention)
│   ├── RamsDocumentMail.php           # EXISTING — manual send, do not touch
│   ├── SurveySubmittedMail.php        # EXISTING — inherited, do not touch
│   ├── RamsReadyMail.php              # NEW — auto-fired completion email
│   ├── OmManualReadyMail.php          # NEW
│   ├── WorksheetReadyMail.php         # NEW
│   ├── CableScheduleReadyMail.php     # NEW
│   ├── RamsReviewNeededMail.php       # NEW
│   ├── DocumentGenerationFailedMail.php  # NEW (used by all 4 jobs)
│   └── Concerns/
│       └── AppliesGlobalBcc.php       # NEW trait — D-09 BCC enforcement
├── Services/
│   └── NotificationRecipientResolver.php  # NEW — D-06 / NOTF-05a
└── Jobs/                              # NO new files; modifications only
    ├── BuildRamsDocumentJob.php       # +completion email + failed() failure email
    ├── BuildOmManualJob.php           # +completion email + failed() failure email
    ├── BuildWorksheetJob.php          # +completion email + failed() failure email
    ├── BuildCableScheduleJob.php      # +completion email + failed() failure email
    └── ExtractRamsDraftJob.php        # +review-needed email after STATUS_AWAITING_REVIEW

config/
└── rams.php                           # +'notifications.bcc' key

resources/views/emails/                # All email Blade templates
├── rams-document.blade.php            # EXISTING — manual send
├── survey-submitted.blade.php         # EXISTING
├── rams-ready.blade.php               # NEW
├── om-manual-ready.blade.php          # NEW
├── worksheet-ready.blade.php          # NEW
├── cable-schedule-ready.blade.php     # NEW
├── rams-review-needed.blade.php       # NEW
└── document-generation-failed.blade.php  # NEW

database/migrations/
└── 2026_04_19_000001_add_email_sent_columns_for_phase_09.php  # NEW — single multi-table migration
```

### Pattern 1: Idempotent Send Inside Job Success Path
**What:** After the model status flips to terminal "ready" state, atomically set the timestamp and dispatch the mailable; refresh the model first to avoid stale state across job retries.
**When to use:** All four `Build*Job` success paths (NOTF-01) and `ExtractRamsDraftJob` (NOTF-03).
**Example:**
```php
// Source: project pattern (SurveyService.php:404-418 idiom + D-14 guard)
// Inside BuildRamsDocumentJob::handle() — replaces the closing log line.

$record->refresh();   // ensure we see latest completion_email_sent_at
if ($record->status === RamsDocument::STATUS_COMPLETED
    && $record->completion_email_sent_at === null) {

    // Atomic set: timestamp first so a concurrent retry sees it.
    $record->update(['completion_email_sent_at' => now()]);

    try {
        $resolver  = app(NotificationRecipientResolver::class);
        $recipient = $resolver->resolveProjectRecipient($record->project);
        if ($recipient?->email) {
            Mail::to($recipient->email)->send(new RamsReadyMail($record));
        }
    } catch (\Throwable $e) {
        Log::warning('BuildRamsDocumentJob: completion email send failed', [
            'rams_document_id' => $record->id,
            'error'            => $e->getMessage(),
        ]);
        // Do NOT clear the timestamp — D-14 says "set inside same update()
        // as dispatch" so the queue cannot double-send. We accept "sent
        // timestamp set but mail vanished" over "double mail to PM".
    }
}
```

### Pattern 2: Idempotent Failure Alert in `failed()` Hook
**What:** Mirror Pattern 1 in the job's `failed()` hook, gated on `failed_email_sent_at`.
**When to use:** All four `Build*Job::failed()` (NOTF-04).
**Example:**
```php
// Inside BuildRamsDocumentJob::failed() — appended to existing logic.

$record = RamsDocument::find($this->ramsDocumentId);
if ($record && $record->failed_email_sent_at === null) {
    $record->update(['failed_email_sent_at' => now()]);

    try {
        $admins = User::where('role', 'admin')->get();   // ← role, NOT is_admin
        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(
                    new DocumentGenerationFailedMail(
                        documentType: 'RAMS',
                        projectRef:   $record->project_ref,
                        projectName:  $record->project_name,
                        errorMessage: substr((string) $record->error_message, 0, 500),
                        detailUrl:    route('rams.show', $record),
                    )
                );
            }
        }
    } catch (\Throwable $e) {
        Log::warning('BuildRamsDocumentJob: failure-alert email send failed', [
            'rams_document_id' => $this->ramsDocumentId,
            'error'            => $e->getMessage(),
        ]);
    }
}
```

### Pattern 3: Shared BCC via Trait
**What:** A single trait that every system mailable uses to apply the configured BCC at envelope build time.
**When to use:** All NEW Phase 09 mailables.
**Example:**
```php
// app/Mail/Concerns/AppliesGlobalBcc.php
namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Envelope;

trait AppliesGlobalBcc
{
    /**
     * Apply config('rams.notifications.bcc') to an envelope when set.
     * Returns the envelope unchanged when the config is empty.
     */
    protected function withGlobalBcc(Envelope $envelope): Envelope
    {
        $bcc = config('rams.notifications.bcc');
        if (! is_string($bcc) || trim($bcc) === '') {
            return $envelope;
        }
        // Envelope::bcc is read-only after construction in Laravel 12; rebuild.
        return new Envelope(
            from:    $envelope->from,
            to:      $envelope->to,
            cc:      $envelope->cc,
            bcc:     array_filter(array_merge($envelope->bcc ?? [], [trim($bcc)])),
            replyTo: $envelope->replyTo,
            subject: $envelope->subject,
            tags:    $envelope->tags,
            metadata: $envelope->metadata,
            using:   $envelope->using,
        );
    }
}
```

Then in each mailable:
```php
public function envelope(): Envelope
{
    return $this->withGlobalBcc(new Envelope(
        subject: "[{$this->ref()}] RAMS ready — {$this->rams->project_name}",
    ));
}
```

⚠ **Alternative — simpler:** apply `->bcc(config('rams.notifications.bcc'))` at the **call site** (in the job) rather than in the mailable. This keeps mailables ignorant of global config and is more testable. Recommend this — see *BCC Implementation Pattern* below.

### Anti-Patterns to Avoid
- **Inline `User::where('is_admin', true)` queries.** ⚠ The column does not exist. Use `User::where('role', 'admin')` or `User::all()->filter->isAdmin()` (less efficient). Centralise both in `NotificationRecipientResolver` so the bug is fixable in one place.
- **Hooking the review-needed email into a `RamsDocument::saved()` model observer.** Other paths (`RamsController::approve`, `regenerate`, `updateStatus`) call `update()` and would spuriously fire emails. D-04 / NOTF-03a explicitly mandates dispatch from the job for test mocking simplicity AND correctness.
- **Reusing `RamsDocument.email_sent_at`.** That column belongs to the manual-send path (`RamsController::email`, line 802). Adding a separate `completion_email_sent_at` per D-13 is the correct call.
- **Hand-building artifact paths.** Always use `DocumentArtifactStorage::readPath()` per H-07 convention — handles legacy locations transparently.
- **Setting `->subject()` AND `Envelope(subject:)`.** Pick one (Laravel 12 mailable convention is `Envelope`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Mail transport | Custom HTTP client to Postmark API | `symfony/postmark-mailer` package | Handles auth, error mapping, retries — D-16 already chose Postmark |
| Recipient resolution | Inline `Project::with('user')->find(...)` per call site | `NotificationRecipientResolver` service | 6 trigger sites; centralised fix for the latent admin-fallback bug; testable in isolation |
| Idempotency tracking | A new `email_log` table | `*_email_sent_at` columns per D-13/D-14 | Two columns per model is cheap, scoped, and the model row IS the audit trail |
| Job retry/backoff | Custom retry counter | Laravel `$tries = 2` already set on every job | Existing convention |
| Test asserting send | Mock `Mail` facade by hand | `Mail::fake()` + `Mail::assertSent(...)` | Standard Laravel testing |
| Attachment MIME detection | `mime_content_type()` calls | `Attachment::fromPath()->withMime(...)` with explicit constants per type | Cable schedule has dual-format (xlsx/csv) — explicit per-mailable constants make this safe |

**Key insight:** The hardest part of mail systems (delivery reliability, bounce tracking, IP reputation) is fully delegated to Postmark. The hardest part of THIS phase (idempotency under retry) is solved by D-14 / D-15's column guards. There is no novel infrastructure to invent.

## Runtime State Inventory

> Phase 09 is greenfield wiring (new mailables, new columns, new trigger calls in existing jobs). It is NOT a rename / refactor / migration phase. The only renamings are SurveyService's local variable structure when it is refactored to use `NotificationRecipientResolver` (NOTF-02a) — no stored data, service config, OS state, secrets, or build artifacts reference any string that Phase 09 changes.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — verified by grep for `email_sent_at` (only the existing `RamsDocument.email_sent_at` is used, by the manual-send path; not touched by Phase 09) | None |
| Live service config | Postmark account on Postmark dashboard (account exists per project; sender signature for `rams@21stcav.com` must be created) | One-time Postmark dashboard task — approve sender signature, create message stream |
| OS-registered state | None — the queue worker is the existing `php artisan queue:listen` process; no scheduled commands added in v1.1 | None |
| Secrets/env vars | New env vars: `POSTMARK_API_KEY`, `MAIL_FROM_ADDRESS=rams@21stcav.com`, `MAIL_FROM_NAME="RAMS Platform"`, `RAMS_NOTIFICATION_BCC` | Add to `.env` (production), `.env.example` (documented), and document in PROJECT.md if relevant |
| Build artifacts / installed packages | `symfony/postmark-mailer` + `symfony/http-client` will appear in `composer.lock` after install — no stale artifacts to clean | Run `composer install` post-pull on production; ensure `php artisan config:cache` is re-run after `.env` updates |

## Common Pitfalls

### Pitfall 1: `User::where('is_admin', true)` returns empty (THE BUG IN CONTEXT.md)
**What goes wrong:** CONTEXT.md D-06/D-07 and the existing `SurveyService.php:407` call `User::where('is_admin', true)`. The `users` table has no `is_admin` column. The query silently returns no rows; the admin fallback never triggers.
**Why it happens:** The codebase uses `users.role` (string, `'user'|'admin'`) plus a `User::isAdmin(): bool` method that tests `$this->role === 'admin'`. A boolean `is_admin` column was likely planned but never added.
**How to avoid:** All Phase 09 admin queries MUST be `User::where('role', 'admin')` (efficient) or `User::all()->filter->isAdmin()` (more correct if `role` semantics change). Centralise in `NotificationRecipientResolver` so any future role-system change is one-line. Also fix the latent SurveyService bug as part of NOTF-02a.
**Warning signs:** Tests that "look correct" but `Mail::assertSent` fails because no recipient was found. Run `User::where('role','admin')->count()` in tinker on dev DB to confirm at least one admin exists before testing.

### Pitfall 2: `Project::with('user')` returns null relation (CONFIRMED LATENT BUG)
**What goes wrong:** `Project` model defines `owner()` (FK `user_id`) but NOT `user()`. `SurveyService.php:406` calls `Project::with('user')->find(...)` and then accesses `$project->user` — both return null. The project-owner branch of the recipient resolver therefore always falls through to admin fallback, which then ALSO fails (Pitfall 1). The survey-submitted email currently sends to nobody.
**Why it happens:** Two relation names co-existed during Phase 03 development; one was settled on (`owner`), the other never refactored.
**How to avoid:** `NotificationRecipientResolver::resolveProjectRecipient()` must use `Project::with('owner')->find(...)` and `$project->owner`. Refactoring SurveyService (NOTF-02a) to call this resolver fixes the bug as a side effect — and the survey-submitted feature test (`PublicSurveyControllerTest.php`) should be extended to assert the email is actually queued.
**Warning signs:** Today, no error is logged because `?->email` short-circuits null. The bug is only visible when you check whether emails actually arrived. After Phase 09 adds `Mail::fake()` assertions to the survey-submitted path, this bug will be caught.

### Pitfall 3: `cable_schedules` table has NO `error_message` column
**What goes wrong:** NOTF-04c says the failure email body includes `error_message` truncated to 500 chars. `BuildCableScheduleJob` lines 113-138 explicitly comment "NOTE: no `error_message` column on cable_schedules — log only". The column genuinely does not exist on the `cable_schedules` table (verified in `2026_03_09_000002_create_cable_schedules_table.php`).
**Why it happens:** Cable-schedule generation has no AI calls, so historically failures were always config/data issues caught at runtime — there was no need for a stored error message.
**How to avoid:** Three options for the planner:
  1. **Add `error_message` to `cable_schedules`** in the same Phase 09 migration. Cheapest; aligns with the other 3 tables.
  2. **Make `DocumentGenerationFailedMail` accept null `errorMessage`** and render "(see logs)" placeholder. Doesn't help admins act on the alert.
  3. **Pass `$exception->getMessage()` directly from the `failed()` hook** (the `\Throwable $e` parameter is in scope) instead of reading from the model column. Works without schema change.
**Recommend:** Option 1 + Option 3 combined — add the column AND pass the exception message. The column also benefits the dashboard health badges (DASH-01d cable-schedule-failed shows just a red badge; an `error_message` would let it show the reason like RAMS does).
**Warning signs:** Failure email lands in admin inbox with empty error context.

### Pitfall 4: O&M / Worksheet status terminology drift (NOTF-01b says "final", code uses "draft")
**What goes wrong:** NOTF-01b text says "status → `final` or `STATUS_FINAL`" for O&M Manual generation completion. The actual `BuildOmManualJob.php:81` flips status to `OmManual::STATUS_DRAFT` (string `'draft'`), not `STATUS_FINAL`. `STATUS_FINAL` exists as a constant but is reserved for human-approved final versions (set elsewhere by an explicit "approve" action). Same applies to `BuildWorksheetJob.php:99` — flips to `STATUS_DRAFT`.
**Why it happens:** REQUIREMENTS.md was drafted from CONTEXT.md without checking the actual job code. The "draft" naming is a deliberate two-step approval flow (machine-generated draft → human-approved final).
**How to avoid:** Trigger completion email on `STATUS_DRAFT` (not `STATUS_FINAL`) for O&M and Worksheet. The PM wants to know "the document was generated and is ready for review/download" — that's the `STATUS_DRAFT` event, not the later human-approval step. Update NOTF-01b to clarify per-type terminal statuses:
  - RAMS: `STATUS_COMPLETED`
  - O&M: `STATUS_DRAFT` (machine done; awaits optional human approval to `STATUS_FINAL`)
  - Worksheet: `STATUS_DRAFT` (same)
  - Cable: `STATUS_DRAFT` (same)
**Warning signs:** Manual O&M generation completes and queue worker logs success but no email arrives — because the trigger is gated on a status the job never sets.

### Pitfall 5: Job retries can resurrect a dead row mid-flight
**What goes wrong:** Between attempt 1 setting `STATUS_FAILED` (then rethrowing) and attempt 2 starting, attempt 2 might overwrite `STATUS_FAILED` → `STATUS_GENERATING` → `STATUS_DRAFT` (success on retry). If the failure-email idempotency guard fired on the failed-then-rethrow path of attempt 1 (it shouldn't — the `failed()` hook only fires after exhaustion), no double-send. But if a developer naively moves the failure email into the `try/catch` block of `handle()` instead of `failed()`, it WILL fire on every individual attempt failure.
**Why it happens:** Confusing `handle()` catch-and-rethrow (per-attempt) with `failed()` (post-exhaustion).
**How to avoid:** Strict separation:
  - Completion email → end of `handle()` success path (after status flip), guarded by `completion_email_sent_at`.
  - Failure email → `failed()` hook ONLY, guarded by `failed_email_sent_at`.
**Warning signs:** Admin inbox gets 2 failure alerts per failed job (one per attempt).

### Pitfall 6: Mailable `__construct` with full Eloquent model hits queue serialization quirks
**What goes wrong:** `Queueable` + `SerializesModels` traits store the model id and re-fetch on dequeue. If the row was soft-deleted between dispatch and worker pickup, `find()` returns null and `Mail` errors. Long-attached collections (`->load('items')`) also serialise full payload to the jobs table.
**Why it happens:** Default Laravel behavior. Acceptable for most cases; problematic when models are bulk-deleted shortly before send.
**How to avoid:** For Phase 09, this is fine — completion mail is dispatched milliseconds after the model was just updated; soft-delete window is microseconds. Only concern: do NOT `$mail->with(['items' => $schedule->items->load(...)])` — let the mailable's `content()` re-fetch on render so we don't pickle a thousand cable items into the payload.
**Warning signs:** `jobs` table rows >100KB; or `ModelNotFoundException` in worker log when sending mail for a model that was deleted in flight.

## Code Examples

Verified patterns from the codebase + Laravel 12 docs.

### Example 1: Recipient resolver service
```php
// Source: NEW — app/Services/NotificationRecipientResolver.php
// Satisfies: NOTF-05a, D-06, D-07. Fixes Pitfalls 1 + 2.

namespace App\Services;

use App\Models\Project;
use App\Models\User;

class NotificationRecipientResolver
{
    /**
     * Resolve the project's primary notification recipient.
     *
     * Order:
     *   1. Project owner (Project.user_id → User)
     *   2. First admin (User where role='admin')
     *   3. null (no recipient — caller logs and skips)
     *
     * Note: uses Project->owner relation (NOT ->user — that relation
     * does not exist on the Project model).
     */
    public function resolveProjectRecipient(?Project $project): ?User
    {
        if ($project) {
            $project->loadMissing('owner');
            if ($project->owner instanceof User && $project->owner->email) {
                return $project->owner;
            }
        }

        return User::where('role', 'admin')->orderBy('id')->first();
    }

    /**
     * All admin recipients for failure alerts (NOTF-05b).
     * Returns a Collection of User. Empty when no admins exist.
     */
    public function resolveAdminRecipients(): \Illuminate\Support\Collection
    {
        return User::where('role', 'admin')->whereNotNull('email')->get();
    }
}
```

### Example 2: Typed completion mailable
```php
// Source: NEW — app/Mail/RamsReadyMail.php
// Satisfies: NOTF-01a, NOTF-01f, NOTF-05f, D-11, D-18.

namespace App\Mail;

use App\Models\RamsDocument;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RamsReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RamsDocument $rams) {}

    public function envelope(): Envelope
    {
        $ref = $this->rams->project_ref ?: '';
        $bracket = $ref !== '' ? "[{$ref}] " : '';
        return new Envelope(
            subject: "{$bracket}RAMS ready — {$this->rams->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rams-ready');
    }

    public function attachments(): array
    {
        $filename = (string) $this->rams->filename;
        if ($filename === '') return [];

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_RAMS, basename($filename));

        if ($path === null) return [];

        return [
            Attachment::fromPath($path)
                ->as(basename($filename))
                ->withMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ];
    }
}
```

### Example 3: Cable-schedule mailable with dual MIME (xlsx/csv)
```php
// Source: NEW — app/Mail/CableScheduleReadyMail.php
// Notable: cable schedules can be xlsx OR csv depending on whether
// PhpSpreadsheet is installed (BuildCableScheduleJob:67 + 148-187).

public function attachments(): array
{
    $filename = (string) ($this->schedule->source_filename ?? '');
    if ($filename === '') return [];

    $path = app(DocumentArtifactStorage::class)
        ->readPath(DocumentArtifactStorage::TYPE_CABLE, basename($filename));
    if ($path === null) return [];

    $mime = str_ends_with(strtolower($filename), '.csv')
        ? 'text/csv'
        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    return [
        Attachment::fromPath($path)->as(basename($filename))->withMime($mime),
    ];
}
```

### Example 4: Single multi-table migration
```php
// Source: NEW — database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php
// Satisfies: NOTF-01c, NOTF-04b, D-13, D-15. Plus error_message on cable_schedules (Pitfall 3).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('email_sent_at');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });

        Schema::table('om_manuals', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('filename');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });

        Schema::table('worksheets', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('filename');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });

        Schema::table('cable_schedules', function (Blueprint $t) {
            // Add error_message column (missing — Pitfall 3) for NOTF-04c body content.
            if (! Schema::hasColumn('cable_schedules', 'error_message')) {
                $t->string('error_message', 1000)->nullable()->after('status');
            }
            $t->timestamp('completion_email_sent_at')->nullable()->after('status');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', fn(Blueprint $t) =>
            $t->dropColumn(['completion_email_sent_at', 'failed_email_sent_at']));
        Schema::table('om_manuals', fn(Blueprint $t) =>
            $t->dropColumn(['completion_email_sent_at', 'failed_email_sent_at']));
        Schema::table('worksheets', fn(Blueprint $t) =>
            $t->dropColumn(['completion_email_sent_at', 'failed_email_sent_at']));
        Schema::table('cable_schedules', fn(Blueprint $t) =>
            $t->dropColumn(['completion_email_sent_at', 'failed_email_sent_at']));
        // NOTE: leave cable_schedules.error_message in place on rollback —
        // dropping it would silently lose error data captured between deploys.
    }
};
```

### Example 5: Feature test for RAMS completion trigger
```php
// Source: NEW — tests/Feature/Notifications/RamsCompletionEmailTest.php
// Satisfies: NOTF-01a, NOTF-01d, NOTF-01f, NOTF-05d.

use App\Jobs\BuildRamsDocumentJob;
use App\Mail\RamsReadyMail;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);   // pest syntax — adapt to phpunit class style if needed

it('sends RamsReadyMail to the project owner with attachment when generation completes', function () {
    Mail::fake();
    config(['rams.notifications.bcc' => 'audit@21stcav.com']);

    $owner = User::factory()->create(['email' => 'pm@example.com']);
    $project = Project::factory()->create(['user_id' => $owner->id, 'ref' => '21CQ30017']);
    $rams = RamsDocument::factory()->create([
        'project_id' => $project->id,
        'project_ref' => '21CQ30017',
        'project_name' => 'Acme Boardroom Refresh',
        'status' => RamsDocument::STATUS_GENERATING,
        'reviewed_data' => ['/* sample */],
        'approved_at' => now(),
        'filename' => 'rams_test.docx',
        'completion_email_sent_at' => null,
    ]);

    // Stage a real artifact for the attachment.
    Storage::fake('documents');
    Storage::disk('documents')->put('rams/rams_test.docx', 'fake docx bytes');

    // Run the job synchronously.
    (new BuildRamsDocumentJob($rams->id))->handle(app(\App\Services\RamsBuilderService::class));

    Mail::assertSent(RamsReadyMail::class, function ($mail) use ($owner) {
        return $mail->hasTo('pm@example.com')
            && $mail->hasBcc('audit@21stcav.com')
            && str_contains($mail->envelope()->subject, '[21CQ30017] RAMS ready')
            && count($mail->attachments()) === 1;
    });

    expect($rams->fresh()->completion_email_sent_at)->not->toBeNull();
});

it('does not double-send when the timestamp is already set', function () {
    Mail::fake();
    $rams = RamsDocument::factory()->create([
        'status' => RamsDocument::STATUS_COMPLETED,
        'completion_email_sent_at' => now()->subMinute(),
    ]);

    // Manually invoke the post-success email block (or run the job again).
    // Should be a no-op.

    Mail::assertNothingSent();
});
```

### Example 6: BCC at call site (simpler alternative to trait)
```php
// Source: NEW — preferred over the trait pattern in this codebase.
// Inside the job's success path, after status flip:

$bcc = config('rams.notifications.bcc');
$mail = Mail::to($recipient->email);
if (is_string($bcc) && trim($bcc) !== '') {
    $mail->bcc(trim($bcc));
}
$mail->send(new RamsReadyMail($record));
```
**Recommendation:** use this call-site pattern. Mailables stay pure (no global config dependency); easier to test (mailable doesn't read `config()`); BCC behaviour is visible at the trigger site — matches the codebase preference for explicit code over magic.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `$message->subject(...)` setters in `build()` method (Laravel 8 mailables) | `Envelope` / `Content` / `attachments()` methods (Laravel 9+) | Laravel 9 (2022) | Both existing mailables already use the modern pattern — keep using it |
| `Notification` framework with multiple channels | `Mailable` for single-channel | Per D-10 | Defer Notification migration to Phase 11+ |
| `services.postmark.token` (older docs) | `services.postmark.key` (Laravel 12 official) | Laravel 11+ | `[CITED: Laravel 12 mail docs]` — services.php key MUST be `key`, env var should be `POSTMARK_API_KEY`. CONTEXT.md `POSTMARK_TOKEN` is wrong. Existing `config/services.php:18` already uses the correct `key` shape. |
| `Mailable` send synchronous | `Mailable implements ShouldQueue` for system mail | Industry standard | All NEW Phase 09 mailables get `ShouldQueue`; existing 2 stay sync (no behaviour change) |

**Deprecated/outdated:**
- `Mail::raw()` for transactional mail — use `Mailable` classes for templating
- Inline `$message->from(...)` per send — use global `MAIL_FROM_ADDRESS` env

## Build Job Inventory

| Job | File | Tries | Timeout | Success status flip (line) | failed() hook (line) | Model | Table |
|-----|------|-------|---------|----------------------------|-----------------------|-------|-------|
| `BuildRamsDocumentJob` | `app/Jobs/BuildRamsDocumentJob.php` | 2 | 180s | `STATUS_COMPLETED` set at line 138 (or already-set guard at 131-134) | line 173 | `RamsDocument` | `rams_documents` |
| `BuildOmManualJob` | `app/Jobs/BuildOmManualJob.php` | 2 | 300s | `STATUS_DRAFT` set at line 81 | line 125 | `OmManual` | `om_manuals` |
| `BuildWorksheetJob` | `app/Jobs/BuildWorksheetJob.php` | 2 | 300s | `STATUS_DRAFT` set at line 99 | line 137 | `Worksheet` | `worksheets` |
| `BuildCableScheduleJob` | `app/Jobs/BuildCableScheduleJob.php` | 2 | 120s | `STATUS_DRAFT` set at line 102-104 | line 193 | `CableSchedule` | `cable_schedules` |
| `ExtractRamsDraftJob` | `app/Jobs/ExtractRamsDraftJob.php` | 2 | 600s | `STATUS_AWAITING_REVIEW` set at line 130-134 | line 161 (only used for review trigger; NOT for failure email — only `Build*Job` failures alert admins per D-03) | `RamsDocument` | `rams_documents` |

**All five jobs share the identical shape:**
- `implements ShouldQueue`, `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels`
- Constructor takes `public readonly int $modelId`
- `handle()` method: `find($id)` → guard → try { do work; status flip; log } catch (\Throwable) { update status=failed; rethrow }
- `failed(\Throwable $e)` hook: re-find model and force `STATUS_FAILED` + (where the column exists) `error_message`

**Class name correction:** `BuildWorksheetJob` (not `BuildWorksheetDocxJob` as CONTEXT.md/REQUIREMENTS.md mention). Phase 09 must reference the actual class name.

## CableSchedule Asymmetry

`CableSchedule` differs from the other three notifiable models in three ways. The planner must address each:

1. **No `error_message` column.** Add it in the Phase 09 migration (Pitfall 3 — recommended) OR pass `\Throwable::getMessage()` directly to `DocumentGenerationFailedMail` from the `failed()` hook. Recommend both.
2. **No `site_address` column.** `cable_schedules` migration (line 11-21 of `2026_03_09_000002_create_cable_schedules_table.php`) does not include `site_address`. The completion email body should not reference site address for cable schedules — or should fall through to `$schedule->project->site_address` (requires the join).
3. **No `filename` column at top of fillable; uses `source_filename`.** The cable-schedule attachment lookup uses `source_filename` (set by `BuildCableScheduleJob:186` for CSV path; `xlsx` path stores it elsewhere — verify). The mailable attachment must read `source_filename`, not `filename`.

## Postmark Setup

`[VERIFIED: Laravel 12 docs + composer.lock grep]`

### Step 1: Composer install
```bash
composer require symfony/postmark-mailer symfony/http-client
```

### Step 2: `config/services.php`
**Already correct** — `services.postmark.key` at line 18 already reads `env('POSTMARK_API_KEY')`. ✅ No change needed.

### Step 3: `config/mail.php` mailers block
**Already correct** — `mailers.postmark` at lines 56-62 already wires `'transport' => 'postmark'`. ✅ No change needed.

### Step 4: Production `.env`
```dotenv
MAIL_MAILER=postmark
POSTMARK_API_KEY=<from Postmark dashboard — Server API Token>
MAIL_FROM_ADDRESS=rams@21stcav.com
MAIL_FROM_NAME="RAMS Platform"
RAMS_NOTIFICATION_BCC=ops@21stcav.com   # leave empty in dev/staging
# Optional — pin a stream so reputation isolates from any future broadcast traffic:
# POSTMARK_MESSAGE_STREAM_ID=outbound
```

### Step 5: `.env.example` (committed)
Add the four new keys with empty values + comments explaining each.

### Step 6: DNS (operational, NOT a code task)
On the `21stcav.com` zone (managed externally — Cloudflare? Check with DNS owner before scheduling):
- **SPF:** add `include:spf.mtasv.net` to existing TXT record (do NOT create a second SPF record — RFC 7208 forbids it)
- **DKIM:** Postmark UI generates a selector record like `20240101._domainkey.21stcav.com` with TXT body — copy verbatim
- **Return-Path (optional but recommended):** CNAME `pm-bounces.21stcav.com → pm.mtasv.net` for bounce processing
- **DMARC:** TXT record at `_dmarc.21stcav.com` with `v=DMARC1; p=quarantine; rua=mailto:dmarc@21stcav.com; pct=100`. Start at `p=none` for the first 14 days to validate, then promote to `p=quarantine`.

### Step 7: Postmark dashboard one-time setup
- Verify sender signature `rams@21stcav.com` (Postmark requires explicit per-address verification by default)
- Or verify whole `21stcav.com` domain (preferred — covers any future from-address)
- Create a transactional message stream named `outbound` (Postmark default; new accounts have it)
- Note the **Server API Token** (NOT account token) → goes in `POSTMARK_API_KEY`

**⚠ Watch out:** Postmark blocks "test" sends until DKIM/SPF verify in their dashboard turn green. Plan a 1-hour buffer between DNS publish and first production send for propagation.

### Laravel 12 / Symfony Mailer gotcha
`[CITED: Laravel 12 mail docs + composer.lock]` — `symfony/postmark-mailer` is listed under `symfony/mailer.composer.json` `suggest` (not `require`), so `composer install` does NOT pull it automatically. Skipping the install causes a runtime error at first send: *"Unsupported transport scheme: postmark"*.

## Recommended Class Structure: 4 typed mailables (NOT 1 polymorphic)

CONTEXT.md leaves this to Claude's discretion. **Recommend four typed `*ReadyMail` classes** + one `RamsReviewNeededMail` + one `DocumentGenerationFailedMail`. Reasoning:

| Concern | Polymorphic `DocumentReadyMail` | Four typed mailables |
|---------|--------------------------------|----------------------|
| Subject line | Switch on type inside envelope() | Each class owns its subject — easy to grep |
| Attachment MIME | Switch on type inside attachments() | Each class hard-codes its MIME — explicit, safe |
| Blade template | One template with `@if($type === ...)` branching | Four templates, each scoped to one document type — easier to design |
| Test discoverability | `Mail::assertSent(DocumentReadyMail::class, fn($m) => $m->type === 'rams')` | `Mail::assertSent(RamsReadyMail::class)` — cleaner |
| Future per-type variation (e.g., O&M wants extra contents page link, RAMS wants risk count) | Polymorphic class grows complex switches | Each class evolves independently |
| Constructor signature | Has to accept any of 4 model types — uses `Model` base class or `mixed` | Each class types its model strictly — IDE autocomplete works |
| Class count | 1 file | 4 files (boilerplate is minimal — ~60 lines each) |

Trade-off accepted: 4 thin Blade templates instead of 1 fat one. Total LOC is comparable; clarity favors the typed approach.

**Final classes:**
- `App\Mail\RamsReadyMail(RamsDocument $rams)` — subject `[ref] RAMS ready — Project Name`
- `App\Mail\OmManualReadyMail(OmManual $manual)` — subject `[ref] O&M Manual ready — Project Name`
- `App\Mail\WorksheetReadyMail(Worksheet $worksheet)` — subject `[ref] Worksheet ready — Project Name`
- `App\Mail\CableScheduleReadyMail(CableSchedule $schedule)` — subject `[ref] Cable Schedule ready — Project Name`
- `App\Mail\RamsReviewNeededMail(RamsDocument $rams)` — subject `[ref] RAMS ready for review — Project Name`
- `App\Mail\DocumentGenerationFailedMail(string $documentType, ?string $projectRef, string $projectName, ?string $errorMessage, string $detailUrl)` — subject `[FAILED] [ref] {DocType} generation failed — Project Name`. Pass primitives so it does NOT need `SerializesModels` on a single model type — it's polymorphic across types via primitive args.

## NotificationRecipientResolver Service Design

**Location:** `app/Services/NotificationRecipientResolver.php`

**Public surface (per NOTF-05a):**
```php
class NotificationRecipientResolver
{
    public function resolveProjectRecipient(?Project $project): ?User;
    public function resolveAdminRecipients(): \Illuminate\Support\Collection;  // Collection<User>
}
```

**Why these two methods (not a single one):**
- `resolveProjectRecipient` is for completion + review-needed (D-06): owner → admin fallback → null
- `resolveAdminRecipients` is for failure alerts (D-07): all admins
- Two distinct intents; do NOT collapse to one method with a flag

**Refactor cost in SurveyService (NOTF-02a):** ~5 lines.
```diff
- $project   = Project::with('user')->find($result->project_id);
- $recipient = $project?->user ?? User::where('is_admin', true)->first();
+ $project   = Project::find($result->project_id);
+ $recipient = app(NotificationRecipientResolver::class)
+     ->resolveProjectRecipient($project);
  if ($recipient?->email) { ... }
```
This refactor also fixes the latent bug (Pitfall 1 + 2). Existing `PublicSurveyControllerTest.php` should be extended with `Mail::fake()` + `Mail::assertSent(SurveySubmittedMail::class)` to lock the fix.

**Unit test (`tests/Unit/Services/NotificationRecipientResolverTest.php`):**
- Returns owner when project has owner with email — assert it's the same User instance
- Returns first admin when project has no owner
- Returns first admin when project is null
- Returns first admin when project owner has no email (treats as missing)
- Returns null when no admins exist and no owner
- Admin lookup uses `role = 'admin'` (assert by creating users with both roles and verifying only admins returned)

## BCC Implementation Pattern

**Two viable approaches** — recommend the second.

### Approach A: Trait (`AppliesGlobalBcc`) — applied inside each mailable's envelope()
- Pro: BCC behaviour is mailable-scoped; can vary per mailable in future
- Pro: Adding a new mailable can't forget BCC if it uses the trait
- Con: Mailable reads `config()` (couples mailable to global config — fights testability)
- Con: Existing 2 mailables (`RamsDocumentMail`, `SurveySubmittedMail`) won't get the trait; if BCC is intended to apply to manual sends too, this surfaces an inconsistency
- Con: Re-constructing an `Envelope` to add BCC is verbose

### Approach B: Apply BCC at call site (in the job, just before send)
- Pro: Mailable stays pure — easier unit tests
- Pro: BCC is visible at every trigger site (no hidden behavior)
- Pro: Manual `RamsController@email` can opt OUT of BCC (D-09 implies "every system email" — manual sends arguably aren't "system" emails)
- Con: Six trigger sites need the same `if ($bcc) ->bcc($bcc)` snippet — risk of forgetting one
- Mitigation: Wrap the snippet in a single helper method on `NotificationRecipientResolver` or a new `MailDispatcher`:
  ```php
  // Add to NotificationRecipientResolver:
  public function send(Mailable $mail, User $recipient): void
  {
      try {
          $pendingMail = Mail::to($recipient->email);
          $bcc = config('rams.notifications.bcc');
          if (is_string($bcc) && trim($bcc) !== '') {
              $pendingMail->bcc(trim($bcc));
          }
          $pendingMail->send($mail);
      } catch (\Throwable $e) {
          Log::warning('NotificationRecipientResolver: send failed', [
              'mailable'  => get_class($mail),
              'recipient' => $recipient->email,
              'error'     => $e->getMessage(),
          ]);
      }
  }
  ```
  Then trigger sites become a single call:
  ```php
  $resolver = app(NotificationRecipientResolver::class);
  if ($recipient = $resolver->resolveProjectRecipient($record->project)) {
      $resolver->send(new RamsReadyMail($record), $recipient);
  }
  ```
  This collapses BCC + try/catch + log into one call per trigger site. **Strong recommendation.**

**Add to `config/rams.php`:**
```php
return [
    // existing company_* keys...
    'notifications' => [
        'bcc' => env('RAMS_NOTIFICATION_BCC'),   // null/empty = no BCC applied
    ],
];
```

## Idempotency Pattern (NOTF-01d / NOTF-04b)

**Order of operations matters:** set the timestamp first, then send. If you send first and then set the timestamp, a worker crash between the two writes a row with mail-sent-but-timestamp-empty → next retry double-sends.

```php
// CORRECT — timestamp first
if ($record->completion_email_sent_at === null) {
    $record->update(['completion_email_sent_at' => now()]);
    // ...send mail (failure → log only, do NOT clear timestamp)
}

// WRONG — race window between send and timestamp write
if ($record->completion_email_sent_at === null) {
    Mail::to(...)->send(...);  // crash here = timestamp never set, retry doubles
    $record->update(['completion_email_sent_at' => now()]);
}
```

**`lockForUpdate()` consideration:** at this volume (handful of generations per day per PM), DB row contention is non-existent. The check-then-update is not atomic but the worst case is a 2-attempt double-send if the queue worker is parallelised AND attempts overlap to the millisecond — vanishingly unlikely with `database` driver and a single worker. **Skip `lockForUpdate()`.** If parallel workers are added later (Phase 14+), revisit with a single-row update-with-where-clause:
```php
$updated = DB::table('rams_documents')
    ->where('id', $id)
    ->whereNull('completion_email_sent_at')
    ->update(['completion_email_sent_at' => now()]);
if ($updated === 1) {
    // we won the race — send mail
}
```

## `failed()` Hook Lifecycle (Laravel 12)

`[CITED: Laravel 12 queues docs]`

| Event | When fires | What to do here |
|-------|-----------|-----------------|
| `handle()` throws `\Throwable` | Per attempt | Catch + log + status=failed + rethrow (existing pattern). Do NOT send email here. |
| Job released back to queue | After `handle()` rethrow, if `attempts < $tries` | Nothing — Laravel handles |
| `$this->fail($e)` called | Immediately, in any attempt | Triggers `failed()` immediately, no further retries |
| All `$tries` exhausted (last attempt threw) | After final attempt | `failed(\Throwable $e)` fires **once**. Send failure email here, guarded by `failed_email_sent_at`. |
| Worker crash mid-attempt | Restart picks up next attempt | Same retry logic; `failed()` still only fires after final exhaustion |

**Implication for NOTF-04b:** `failed_email_sent_at` guard is technically unnecessary at single-worker scale because `failed()` already fires exactly once per failed run. But:
- Defensive: if the model is manually re-failed via tinker or admin tool, the guard prevents a re-alert.
- If future code adds `$this->fail($e)` calls inside `handle()`, the guard prevents a second alert when a retry exhausts naturally.
- Cheap (one column read, one update) — keep the guard per D-15.

## Project Constraints (from CLAUDE.md)

| Constraint | Phase 09 alignment |
|------------|---------------------|
| AI usage ONLY for formatting / method statement structuring | ✅ Phase 09 makes ZERO AI calls — pure mail wiring. No AI infrastructure touched. |
| Data integrity — content traces to quote/survey/reviewed inputs | ✅ Email content is just project metadata + status; no content invention. |
| Existing pipeline — must not break | ✅ Phase 09 only ADDS dispatch lines after status flips; existing logic untouched. Idempotency guard prevents double-send on retries. |
| Architecture — Laravel service-based, thin controllers, shared data services, safe migrations, queue-compatible | ✅ `NotificationRecipientResolver` is a service; mailable attachment uses `DocumentArtifactStorage`; migration is additive (nullable columns); mailables are `ShouldQueue`. |
| SQL security — read-only QuoteWerks, .env, no frontend exposure | N/A — no SQL connections involved in Phase 09. |
| Output formats — RAMS/Worksheets/O&M as DOCX, Cable as XLSX | ✅ Mailables attach the correct MIME per type. |
| Generated-document convention (H-07) — always use `DocumentArtifactStorage` | ✅ All four `*ReadyMail::attachments()` use `DocumentArtifactStorage::readPath()`. |
| GSD workflow enforcement | ✅ Phase 09 work proceeds via `/gsd-execute-phase`. |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Postmark sender signature for `rams@21stcav.com` can be created (the mailbox or a verified domain exists) | Postmark Setup §6 | Production sends will fail with HTTP 422 from Postmark. Operational, not code. Verify with sender of the mailbox before scheduling DNS work. |
| A2 | DNS for `21stcav.com` is editable by ops team (DNS provider unknown) | Postmark Setup §6 | DNS work blocks production cutover. Identify DNS owner during planning. |
| A3 | DASH-01 dashboard linkability — `route('rams.show', $rams)`, `route('om-manuals.edit', $manual)`, `route('worksheets.show', $worksheet)`, `route('cable-schedules.edit', $cableSchedule)` are all valid for the failure-email "drill into details" link | Pattern 2 | If a route doesn't exist, the email sends with a broken link. All four route names verified in `routes/web.php` (lines 158, 240, 257, 208). ✅ Likely correct but verify each at plan time. |
| A4 | The `cable_schedules.error_message` column addition is acceptable scope creep for Phase 09 (rather than a separate Phase 10 task) | Pitfall 3 | Planner could descope to Option 3 (pass throwable directly) — works without schema change. |
| A5 | Existing `MAIL_MAILER=log` dev environment will accept new mailables transparently (Laravel just renders to log) | Validation Architecture | True — `log` transport ignores attachments size. Verified by inspecting `config/mail.php:73-76`. |
| A6 | Project owner (`Project::user_id`) is the appropriate recipient for completion emails — the column is reliably populated for current production projects | NotificationRecipientResolver | If many projects have null `user_id`, every notification routes to the single first admin. Acceptable v1.1 fallback per D-06. |
| A7 | The `failed()` hook in Laravel 12 fires exactly once per retry exhaustion (not per attempt failure) | failed() Lifecycle | `[CITED: Laravel 12 queues docs]` — confirmed. Low risk. |

## Open Questions

1. **Should the manual `RamsController@email` path also apply BCC?**
   - What we know: D-09 says "every system email applied via shared base mailable / `Mailable::bcc()`"
   - What's unclear: is a PM-initiated send a "system email"? Strict reading says yes; spirit (audit) says yes too
   - Recommendation: apply BCC there as well — change is one line: `Mail::to(...)->bcc(config('rams.notifications.bcc'))->send(...)`. Or refactor to use the new `NotificationRecipientResolver::send()` helper.

2. **Should `RamsDocument.email_sent_at` be renamed to `manual_email_sent_at` for clarity?**
   - What we know: D-13 says don't reuse the column; add `completion_email_sent_at` separately
   - What's unclear: leaving `email_sent_at` ambiguously named invites future confusion
   - Recommendation: defer rename to a v1.2 cleanup; out of Phase 09 scope. The new column `completion_email_sent_at` is unambiguous.

3. **Should `WORK-04` (worksheet completion notification) be considered separately from RAMS?**
   - Worksheet generation is the most recently added pipeline (Phase 12, completed 2026-04-13). Confirm with PM that WORK completion deserves a PM email at the same fidelity as RAMS — answer is "yes" per NOTF-01a/b but worth confirming during plan checkpoint.

4. **Postmark message stream — single `outbound` or split per trigger type?**
   - Single stream simplifies setup; split streams give per-trigger reputation tracking. Postmark's free tier is plenty for v1.1 volume.
   - Recommendation: single `outbound` stream for v1.1; revisit if specific triggers (e.g., failure alerts at high volume) cause reputation issues.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | Project core | ✓ (assumed — repo runs) | 8.2+ | — |
| Laravel 12 | Mailable + queue + Postmark transport | ✓ | ^12.0 (composer.json) | — |
| `database` queue driver | `ShouldQueue` mailable dispatch | ✓ | — | none — required |
| `symfony/mailer` | Underlying mail abstraction | ✓ | ^7.4.6 (composer.lock) | — |
| `symfony/postmark-mailer` | Postmark transport | ✗ | — | **MUST install** via `composer require symfony/postmark-mailer symfony/http-client`. No fallback — without this, `MAIL_MAILER=postmark` errors at runtime. |
| `symfony/http-client` | Postmark HTTP transport | ✗ | — | Auto-pulled by `symfony/postmark-mailer` |
| Postmark account + API token | Production transport | ❓ ops-confirmed | — | Defer Postmark cutover until token issued; dev/staging can use `log`/`array` indefinitely. |
| DNS write access for `21stcav.com` | SPF/DKIM/DMARC | ❓ ops-confirmed | — | Without DKIM, Postmark works but messages may land in spam. Operational fallback: send via `MAIL_MAILER=log` and hand-deliver until DNS lands. |
| Mailbox `rams@21stcav.com` | Reply destination | ❓ ops-confirmed | — | Use existing `info@21stcenturyav.com` (in `config/rams.php`) until `rams@` mailbox is provisioned. |

**Missing dependencies with no fallback:**
- `symfony/postmark-mailer` package — composer install is the only path

**Missing dependencies with fallback:**
- Postmark API token / DNS — staged rollout possible (dev with `log`, staging with sandbox stream, production once DNS verified)

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 (`composer.json`) |
| Config file | `phpunit.xml` (root) — already sets `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite :memory:` for tests |
| Quick run command | `vendor/bin/phpunit --testsuite=Unit --filter NotificationRecipientResolver` |
| Full suite command | `composer test` (which runs `php artisan config:clear --ansi && php artisan test`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| NOTF-01a | RAMS completion → `RamsReadyMail` to owner with attachment | feature | `vendor/bin/phpunit tests/Feature/Notifications/RamsCompletionEmailTest.php` | ❌ Wave 0 |
| NOTF-01b | O&M / Worksheet / Cable completion mailables fire on respective `STATUS_DRAFT` | feature | `vendor/bin/phpunit tests/Feature/Notifications/{OmManual,Worksheet,CableSchedule}CompletionEmailTest.php` | ❌ Wave 0 |
| NOTF-01c | Migration adds 4 × `completion_email_sent_at` columns | feature (migration smoke) | `vendor/bin/phpunit tests/Feature/Notifications/Phase09MigrationTest.php` (asserts `Schema::hasColumn` on each table) | ❌ Wave 0 |
| NOTF-01d | Send-once guard — second job run with timestamp set does not re-send | feature | `vendor/bin/phpunit tests/Feature/Notifications/RamsCompletionEmailTest.php::test_does_not_double_send` | ❌ Wave 0 |
| NOTF-01e | Regenerated RAMS (new row) re-fires email | feature | `vendor/bin/phpunit tests/Feature/Notifications/RamsRegenerationEmailTest.php` | ❌ Wave 0 |
| NOTF-01f | Attachment present + correct MIME; missing artifact → no attachment, no error | feature | Asserted within RamsCompletionEmailTest (two scenarios) | ❌ Wave 0 |
| NOTF-02a | Survey-submitted send still works after refactor; uses resolver | feature (regression) | `vendor/bin/phpunit tests/Feature/PublicSurveyControllerTest.php::test_survey_submission_sends_pm_email` (extend existing test) | ⚠️ partial — file exists, no Mail assertions yet |
| NOTF-03a | `RamsReviewNeededMail` fires on `STATUS_AWAITING_REVIEW` | feature | `vendor/bin/phpunit tests/Feature/Notifications/RamsReviewNeededEmailTest.php` | ❌ Wave 0 |
| NOTF-03b | Review email contains `route('rams.review', $rams)` | feature (assert body via rendered content) | Inside RamsReviewNeededEmailTest | ❌ Wave 0 |
| NOTF-04a | `failed()` hook fires `DocumentGenerationFailedMail` to all admins | feature | `vendor/bin/phpunit tests/Feature/Notifications/JobFailureAlertTest.php` (parameterised over 4 jobs) | ❌ Wave 0 |
| NOTF-04b | `failed_email_sent_at` guard prevents duplicate alerts | feature | Within JobFailureAlertTest | ❌ Wave 0 |
| NOTF-04c | Failure email body includes truncated error_message + detail link | feature (rendered content assertion) | Within JobFailureAlertTest | ❌ Wave 0 |
| NOTF-05a | Resolver returns owner > admin > null in correct order | unit | `vendor/bin/phpunit tests/Unit/Services/NotificationRecipientResolverTest.php` | ❌ Wave 0 |
| NOTF-05b | Admin lookup uses `role = 'admin'` (not `is_admin`) | unit | Within NotificationRecipientResolverTest | ❌ Wave 0 |
| NOTF-05d | When `RAMS_NOTIFICATION_BCC` is set, mail has BCC; when empty, no BCC | feature | Within RamsCompletionEmailTest (two scenarios with `config()` overrides) | ❌ Wave 0 |
| NOTF-05e | Mailables extend `Illuminate\Mail\Mailable` (not Notification) | static (PHPUnit reflection) | `vendor/bin/phpunit tests/Unit/Mail/MailableContractTest.php` | ❌ Wave 0 |
| NOTF-05f | All NEW mailables `implements ShouldQueue` | static (PHPUnit reflection) | Within MailableContractTest | ❌ Wave 0 |
| NOTF-05g | Postmark transport configured correctly — defer to manual smoke (not testable in CI) | manual smoke | Set staging `MAIL_MAILER=postmark` + sandbox token; trigger one RAMS completion; verify Postmark dashboard logs the send | n/a |
| NOTF-05h | try/catch swallows mail exceptions and logs | feature | `vendor/bin/phpunit tests/Feature/Notifications/MailFailureIsolationTest.php` (mock Mail to throw, assert job still completes) | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --filter Notifications` (runs only Phase 09 tests, < 5s)
- **Per wave merge:** `composer test` (full suite — currently passing per Phase 08 completion)
- **Phase gate:** `composer test` green AND manual Postmark smoke on staging documented before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Notifications/` directory + namespace setup
- [ ] `tests/Unit/Services/NotificationRecipientResolverTest.php`
- [ ] `tests/Unit/Mail/MailableContractTest.php`
- [ ] Extend `tests/Feature/PublicSurveyControllerTest.php` to assert `Mail::assertSent(SurveySubmittedMail::class)` (regression-locks NOTF-02a fix)
- [ ] No factory exists for `Worksheet` and `CableSchedule` (verify — Phase 12 may have added Worksheet factory). If missing, add minimal factories for test setup.
- [ ] No framework install needed — PHPUnit + Mockery already installed; `Mail::fake()` is built into Laravel.

### Manual Smoke Tests (NOT in CI)
1. **Dev log smoke:** with `MAIL_MAILER=log`, generate one RAMS in dev → tail `storage/logs/laravel.log` → verify a rendered email body appears with correct subject + recipient + attachment metadata.
2. **Staging Postmark smoke:** with sandbox `POSTMARK_API_KEY` + verified domain, trigger each of the 6 mail types once → check Postmark Activity log shows `Delivered` for each → check inbox.
3. **DKIM/SPF verification:** Postmark dashboard "Sending" tab → DKIM/SPF lights green for `21stcav.com`.
4. **DMARC monitoring:** after first 24h of production sends, check `dmarc@21stcav.com` for aggregate reports → ensure no failures.

### What's NOT testable in CI
- Postmark API authentication (requires real token + network)
- DKIM/SPF/DMARC DNS verification (operational, external to repo)
- Mailbox delivery (depends on recipient inbox / spam filter)
- BCC delivery to actual `ops@21stcav.com` mailbox (CI tests assert envelope BCC; delivery is operational)

## Sources

### Primary (HIGH confidence)
- `[VERIFIED: codebase grep]` — `app/Jobs/{BuildRamsDocumentJob,BuildOmManualJob,BuildWorksheetJob,BuildCableScheduleJob,ExtractRamsDraftJob}.php` — exact line numbers cited
- `[VERIFIED: codebase grep]` — `app/Mail/{RamsDocumentMail,SurveySubmittedMail}.php` — existing patterns
- `[VERIFIED: codebase grep]` — `app/Models/{Project,User,RamsDocument,OmManual,Worksheet,CableSchedule}.php` — relations + status constants
- `[VERIFIED: codebase grep]` — `app/Core/Modules/Survey/SurveyService.php:403-418` — survey-submitted send pattern
- `[VERIFIED: codebase grep]` — `app/Http/Controllers/RamsController.php:785-805` — manual-send `email_sent_at` ownership
- `[VERIFIED: codebase grep]` — `app/Services/DocumentArtifactStorage.php:1-80` — H-07 convention
- `[VERIFIED: codebase grep]` — `database/migrations/*` — table + column inventory
- `[VERIFIED: composer.lock]` — `symfony/mailer ^7.4.6` installed; `symfony/postmark-mailer` only listed as suggestion
- `[VERIFIED: phpunit.xml]` — `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` already set for tests (D-20 already done)
- `[VERIFIED: routes/web.php]` — route names for `rams.review`, `rams.show`, `om-manuals.edit`, `worksheets.show`, `cable-schedules.edit`
- `[CITED: https://laravel.com/docs/12.x/mail]` — Postmark setup canonical instructions for Laravel 12
- `[CITED: https://laravel.com/docs/12.x/queues]` — `failed()` hook fires once after retry exhaustion (not per attempt)

### Secondary (MEDIUM confidence)
- Postmark dashboard one-time setup steps (sender verification, message streams) — based on Postmark public docs convention; no live tool verification

### Tertiary (LOW confidence)
- DNS provider for `21stcav.com` (could be Cloudflare, Route53, or registrar default) — open question A2; needs ops confirmation

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — Laravel 12 + Symfony Postmark transport are battle-tested; only thing unverified is the actual `composer require` succeeding (assume HIGH)
- Architecture: HIGH — every existing primitive (Mailable, queue, DocumentArtifactStorage, jobs) is already in the codebase and consistent
- Pitfalls: HIGH — both latent bugs (Pitfalls 1 + 2) verified by grep; CableSchedule asymmetry (Pitfall 3) verified by reading job + migration; status drift (Pitfall 4) verified against actual job code
- Postmark transport details: HIGH — Laravel 12 official docs cited
- Production cutover: MEDIUM — depends on operational readiness (DNS, mailbox, Postmark account) — non-code risks

**Research date:** 2026-04-19
**Valid until:** 2026-07-19 (~90 days — Laravel 12 mail subsystem is stable; Postmark transport API hasn't changed in 2+ years; only watchout is Symfony Mailer major version bumps)
