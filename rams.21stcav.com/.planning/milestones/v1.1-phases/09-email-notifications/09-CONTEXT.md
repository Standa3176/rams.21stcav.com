# Phase 09: Email Notifications - Context

**Gathered:** 2026-04-19
**Status:** Ready for planning

> ⚠ ROADMAP.md and REQUIREMENTS.md gap: Phase 09 is listed in [ROADMAP.md](../../ROADMAP.md) (line 34) but has no detail block, and [REQUIREMENTS.md](../../REQUIREMENTS.md) has no `NOTF-XX` section. The planner should draft `NOTF-01` … `NOTF-05` requirements derived from the decisions below and add a Phase 09 detail block to ROADMAP.md as part of plan execution.

<domain>
## Phase Boundary

Trigger-based system emails for AV-operations events: **document generation completed**, **document generation failed**, **RAMS review needed**, and the existing **survey submitted** path. All triggers are server-side (job-completion or status-transition hooks). Production-ready transport (Postmark + DKIM on `rams@21stcav.com`) and queued delivery are in scope. Per-user preferences, in-app notifications, push notifications, multi-channel (Slack/Teams), and Bitrix24 sync are out of scope (Phase 11 covers BIT-01..04).

</domain>

<decisions>
## Implementation Decisions

### Trigger Inventory
- **D-01:** Generation-complete email fires for **all four document types** — RAMS, O&M Manual, Worksheet, Cable Schedule. Hook into `BuildRamsDocumentJob`, `BuildOmManualJob`, `BuildWorksheetDocxJob`, and the cable-schedule generator job at the success path (after the model status flips to `completed` / `final`).
- **D-02:** Manual regenerations do **not** fire a separate "regenerated" email — only the standard completion email when the new doc reaches `completed`. Old superseded versions stay silent.
- **D-03:** Generation-failed email fires when a `Build*Job` exhausts retries and the model status lands on `failed`. Goes only to admins (not the project owner). Use the existing `failed()` job hook so it triggers exactly once per failed run.
- **D-04:** Review-needed email fires when a `RamsDocument` transitions to `awaiting_review` (i.e., `ExtractRamsDraftJob` completes successfully). Single trigger, RAMS-only — other document types do not currently have a review state. **No** 7-day reminder in v1.1 (deferred).
- **D-05:** Survey-submitted trigger is unchanged — `SurveyService::submitPublic()` already sends `SurveySubmittedMail` ([app/Core/Modules/Survey/SurveyService.php:410](../../../app/Core/Modules/Survey/SurveyService.php#L410)). Phase 09 inherits it; template polish is allowed but the call site stays put. No new survey-related triggers (no link-generated email, no abandoned-survey reminder).

### Recipient Policy
- **D-06:** Default recipient for completion + review-needed = **project owner only** (`Project::user_id`). Falls back to first `User::where('is_admin', true)` when `project.user_id` is null or the user record is missing — same pattern as the existing `SurveyService::submitPublic()` fallback.
- **D-07:** Failure alerts go to **all users with `is_admin = true`**. Single query `User::where('is_admin', true)->pluck('email')`; no separate config table.
- **D-08:** No per-project subscriber list / no `project_notification_recipients` table. Project owner is the single source of truth. Avoids CRUD scope creep into Phase 09.
- **D-09:** Configurable global BCC for audit. Add env var `RAMS_NOTIFICATION_BCC` (default empty in dev/test, `ops@21stcav.com` in production). Every system email applied via a shared base mailable / `Mailable::bcc()` when the env var is non-empty. Disable in dev to keep MailHog inboxes clean.

### Channel & Delivery (Claude's Discretion)
- **D-10:** Stay on **Mailable pattern** (current convention — `App\Mail\RamsDocumentMail`, `App\Mail\SurveySubmittedMail`). Do **not** migrate to Laravel `Notification` framework in v1.1 — multi-channel future-proofing (Slack/Bitrix) belongs in Phase 11+ when it actually has a second channel.
- **D-11:** Mark all new system mailables `implements ShouldQueue` so they dispatch through the existing `database` queue. Document-generation jobs already run async, so adding mail to the queue is consistent and decouples mail send time from job-completion latency.
- **D-12:** Failure handling: every send wrapped in `try { } catch (\Throwable $e) { Log::warning(...) }` — same defensive pattern as `SurveyService::submitPublic()`. A failed mail must never roll back or break a document-generation job.

### Idempotency & Tracking (Claude's Discretion)
- **D-13:** Add `*_email_sent_at` timestamp columns to each notifiable model — `om_manuals.completion_email_sent_at`, `worksheets.completion_email_sent_at`, `cable_schedules.completion_email_sent_at`. `RamsDocument.email_sent_at` already exists but is currently set by the manual-send path; add a separate `RamsDocument.completion_email_sent_at` for the auto-trigger so manual sends and auto-completion notifications are tracked independently.
- **D-14:** Send-once guard: each completion notifier checks `$model->completion_email_sent_at === null` before sending; sets the timestamp inside the same transaction/update. A regenerated document gets a new model row, so its `completion_email_sent_at` starts null — the new build emails normally without conflicting with the old row's timestamp.
- **D-15:** Failure-alert idempotency: only send when `failed_email_sent_at` is null on that model row. The job's `failed()` hook can re-fire on retry exhaustion across different attempts; the column guard prevents duplicate alerts.

### Production Transport
- **D-16:** Mail driver = **Postmark**. Justification: transactional-only, strongest deliverability for system mail at this volume, simplest webhooks (not consumed in v1.1), `config/services.php` already has the placeholder. `MAIL_MAILER=postmark` + `POSTMARK_TOKEN` in production `.env`.
- **D-17:** From address = **`rams@21stcav.com`**. Branded, replies route to a real shared inbox. Requires one-time DNS work on the `21stcav.com` zone — SPF (`include:spf.mtasv.net`), DKIM (Postmark-issued selector record), DMARC (`p=quarantine; rua=mailto:dmarc@21stcav.com`). DNS work is a planning task, not a code task.
- **D-18:** From name = `RAMS Platform` (configurable via `MAIL_FROM_NAME`). Subject convention: `[Project Ref] Subject — Project Name` so PMs can scan inboxes (e.g. `[21CQ30017] RAMS ready — Acme Boardroom Refresh`).
- **D-19:** Bounce/complaint handling = **log only** in v1.1. Failures surface in `laravel.log` and the Postmark dashboard. No webhook ingestion, no `email_status` table, no recipient suppression list. Revisit if hard bounces become a real operational issue.
- **D-20:** Dev/test stays on `MAIL_MAILER=log`; CI sets `MAIL_MAILER=array` so feature tests can assert via `Mail::fake()`. Staging uses Postmark with a sandbox stream.

### Phase Boundary Enforcement (no scope creep)
- **D-21:** No notification preferences UI / no `notification_preferences` table in v1.1 — emails are always-on for the recipients defined in D-06/D-07. Per-user opt-out is deferred to v1.2+ if PMs request it.
- **D-22:** No in-app notification centre, no toasts, no read/unread inbox, no Slack/Teams/Bitrix channels — all deferred to later phases.

### Claude's Discretion
- Email content, Blade template structure, and visual style (plain text vs minimal branded HTML) — researcher to recommend; planner to lock.
- Whether to introduce a thin `App\Notifications\NotificationDispatcher` service for the recipient-resolution logic (project owner → admin fallback), or keep that logic inline in each Mailable's call site. Prefer extraction if it appears in 3+ trigger sites.
- Test strategy: feature tests using `Mail::fake()` per trigger, plus a unit test for the recipient-resolution helper. Smoke test with `MAIL_MAILER=log` in dev.

### Folded Todos
None — `gsd-tools todo match-phase 09` returned zero matches.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-level intent
- [.planning/PROJECT.md](../../PROJECT.md) §"v1.1 — Operations Dashboard & Notifications" — `NOTF-01` (generation-complete email) and `NOTF-02` (survey-submitted email) are the user-facing requirements this phase satisfies; planner should expand into NOTF-01..05 and copy back into REQUIREMENTS.md.
- [.planning/ROADMAP.md](../../ROADMAP.md) line 34 — Phase 09 one-liner: *"Email Notifications — Generation complete, survey submitted, review needed triggers"*. **No detail block exists** — planner should add one mirroring Phase 08's structure (Goal / Depends on / Requirements / Success Criteria / Plans).
- [.planning/REQUIREMENTS.md](../../REQUIREMENTS.md) — currently covers DASH-01 (Phase 08) and INST-01..05 only. **Planner must add a NOTF-01..NOTF-05 section** before writing plan files.

### Existing code to read (pattern + reuse)
- [app/Mail/RamsDocumentMail.php](../../../app/Mail/RamsDocumentMail.php) — Mailable convention with attachment via `DocumentArtifactStorage::readPath()`. Reuse the attachment idiom for completion emails that ship the document.
- [app/Mail/SurveySubmittedMail.php](../../../app/Mail/SurveySubmittedMail.php) — Class-level docblock documents the inline-send + try/catch + log-warning convention. New mailables follow this pattern.
- [app/Core/Modules/Survey/SurveyService.php:403-418](../../../app/Core/Modules/Survey/SurveyService.php#L403) — Reference call site for the "send outside the transaction, never roll back submission on mail failure" pattern. All Phase 09 trigger sites mirror this.
- [app/Http/Controllers/RamsController.php:785-805](../../../app/Http/Controllers/RamsController.php#L785) — `email()` action shows the manual-send path that already updates `email_sent_at`. Phase 09 must not collide with this column — that's why D-13 introduces `completion_email_sent_at` as a separate column.
- [app/Jobs/BuildRamsDocumentJob.php](../../../app/Jobs/BuildRamsDocumentJob.php) — Hook completion-email dispatch at the end of the success path, and use the `failed()` hook for failure alerts.
- [app/Models/User.php](../../../app/Models/User.php) — `is_admin` boolean already drives admin-only routes; reuse for the failure-alert recipient query (D-07).
- [app/Models/Project.php](../../../app/Models/Project.php) — `user_id` + `user()` belongs-to is the project-owner relation used in D-06.

### Configuration touchpoints
- [config/mail.php](../../../config/mail.php) — Currently configured with `MAIL_MAILER=log` in dev. Phase 09 needs `postmark` mailer activated for production via env, no code change required (`config/mail.php` already wires Symfony Postmark transport).
- [config/services.php](../../../config/services.php) — `postmark.token` placeholder exists; planner verifies and ensures `.env.example` documents `POSTMARK_TOKEN` and `MAIL_FROM_ADDRESS=rams@21stcav.com`.
- [config/rams.php](../../../config/rams.php) — Add `notifications.bcc` reading `env('RAMS_NOTIFICATION_BCC')` (D-09).

### External docs (one-time references for planner / ops)
- Postmark Laravel setup: <https://postmarkapp.com/developer/integrations/laravel> — confirms Symfony transport + `POSTMARK_TOKEN` flow.
- DNS records (SPF/DKIM/DMARC) for `21stcav.com` — DNS work is operational, not a code task; capture as a planning checklist item.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Mailable pattern** ([app/Mail/](../../../app/Mail/)) — `Illuminate\Mail\Mailable` with `Queueable` + `SerializesModels` traits, `envelope()` / `content()` / `attachments()` methods. Two existing Mailables to copy from.
- **`DocumentArtifactStorage::readPath()`** — Returns absolute path or null for any document artifact across the four document types. Use for attaching the generated doc to its completion email (and gracefully omit attachment when the file is missing — same as `RamsDocumentMail::attachments()` does).
- **Project-owner-with-admin-fallback recipient resolution** — Implemented inline in [SurveyService.php:406-411](../../../app/Core/Modules/Survey/SurveyService.php#L406). Lift into a shared `App\Services\NotificationRecipientResolver` if it appears 3+ times.
- **Mail dev mode** — `MAIL_MAILER=log` writes rendered messages to `storage/logs/laravel.log`; useful for verifying templates without sending.

### Established Patterns
- **Try/catch + Log::warning around every send** — every existing send path treats mail failure as non-fatal. Phase 09 must follow this pattern; never let mail failure abort document generation.
- **Send outside DB transaction** — `SurveyService::submitPublic()` does the DB transaction first, returns `$result`, then sends mail. Phase 09 trigger sites must do the same: transaction commits → status flips → mail dispatched.
- **Status-driven model lifecycle** — `RamsDocument` already has `STATUS_AWAITING_REVIEW`, `STATUS_GENERATING`, `STATUS_COMPLETED`, `STATUS_FAILED` constants. Hook trigger logic to those status transitions, not to job invocations.
- **Job retry shape** — All Build*Job classes have 2 retries, `failed()` hook for cleanup, status set to `failed` on exhaustion. Hook the failure-alert email into the existing `failed()` hook.
- **Class-prefixed log lines** — `Log::info('NotificationService: completion email sent', ['rams_id' => ...])` matches the project convention.

### Integration Points
- **Each Build*Job's success path** — final lines after status flip to `completed` / `final` (D-01).
- **Each Build*Job's `failed()` hook** — for the admin failure alert (D-03).
- **`ExtractRamsDraftJob` success path** — for review-needed email when status hits `awaiting_review` (D-04).
- **`SurveyService::submitPublic()`** — already wired (D-05).
- **`config/mail.php` + `.env`** — driver swap from `log` to `postmark` for production (D-16).
- **DNS zone for `21stcav.com`** — SPF/DKIM/DMARC records (D-17). Operational, not in repo.

### Known Constraints
- `MAIL_MAILER=log` in current `.env` means no Phase 09 work will visibly send mail in dev — verify via log inspection / `Mail::fake()` in tests.
- `RamsDocument.email_sent_at` is owned by the manual-send path ([RamsController.php:802](../../../app/Http/Controllers/RamsController.php#L802)). Do not overload it for the auto-trigger — add `completion_email_sent_at` (D-13).
- The cable-schedule generator path needs identifying — confirm whether it has a dedicated `Build*Job` or runs inline; trigger wiring depends on which.

</code_context>

<specifics>
## Specific Ideas

- Subject convention example: `[21CQ30017] RAMS ready — Acme Boardroom Refresh` — bracketed project ref first so PMs can sort/filter by ref.
- BCC env var name: `RAMS_NOTIFICATION_BCC` (matches `RAMS_*` env namespace already in use).
- New column naming: `completion_email_sent_at` and `failed_email_sent_at` on each notifiable model — consistent across the four document types.
- Email `From` should resolve from `MAIL_FROM_ADDRESS` (`rams@21stcav.com`) and `MAIL_FROM_NAME` (`RAMS Platform`) — Laravel defaults; no custom envelope override needed unless per-mailable branding is requested.

</specifics>

<deferred>
## Deferred Ideas

- **7-day RAMS review reminder** (came up in Trigger inventory discussion) — useful but needs a scheduled command and a "last reminded at" timestamp. Defer to a v1.1 quick task or fold into Phase 10 (Document Quality Scores) where engineer-action prompts naturally live.
- **Abandoned-survey reminder (14-day nudge)** — pairs nicely with DASH-01's red badge but adds a scheduled command and a `last_abandoned_email_sent_at` column. Defer to a v1.1 quick task.
- **Engineer survey-link confirmation email** — useful when a PM forwards a token; currently engineers get the link via PM email manually. Capture for backlog.
- **Per-user notification preferences** (opt-out, per-event subscription) — needs a `notification_preferences` table + settings UI. Revisit if PMs report email overload after Phase 09 ships.
- **Multi-channel Notification framework** (`Notifiable` trait + Slack/Teams channel drivers) — defer until there is a real second channel; aligns with Phase 11 (Bitrix24) potentially.
- **In-app notification centre / read-unread inbox** — UX scope, not v1.1.
- **Bounce/complaint webhook + email-status table** — only worth building if hard bounces become an operational pain point. Postmark dashboard covers the immediate observability need.
- **Per-project subscriber list (`project_notification_recipients`)** — UX nicety for client-side stakeholder CCs and holiday-cover scenarios. Capture for backlog if PMs ask.
- **Failure recipient policy = configurable list in `config/rams.php`** — discussed but rejected in favour of `is_admin = true` query. Revisit if admin role grows beyond ops people.
- **Migrate `MAIL_MAILER=array` for CI** — not strictly Phase 09 work; minor CI hygiene task.

### Reviewed Todos (not folded)
None.

</deferred>

---

*Phase: 09-email-notifications*
*Context gathered: 2026-04-19*
