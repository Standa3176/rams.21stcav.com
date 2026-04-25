# Phase 09: Email Notifications - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in [09-CONTEXT.md](09-CONTEXT.md) — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 09-email-notifications
**Areas discussed:** Trigger inventory, Recipient policy, Production transport
**Areas skipped:** User preferences (defaulted to "always send, no opt-out in v1.1" — captured as D-21 in CONTEXT.md)

---

## Gray Area Selection

| Option | Description | Selected |
|--------|-------------|----------|
| Trigger inventory | Which events fire emails — RAMS-only minimum vs all four document types vs include failures | ✓ |
| Recipient policy | Project owner vs admins vs per-project subscribers vs shared ops mailbox | ✓ |
| User preferences | Always-send vs global on/off vs per-event subscription (would add `notification_preferences` table) | |
| Production transport | Postmark vs Resend vs SES vs M365 SMTP relay | ✓ |

**User skipped:** User preferences → recorded as Claude's discretion (no opt-out in v1.1, add later if needed).

---

## Trigger Inventory

### Q1 — Generation-complete: which document types fire a 'ready' email?

| Option | Description | Selected |
|--------|-------------|----------|
| RAMS only | Roadmap minimum. Hook into BuildRamsDocumentJob completion. | |
| All four document types | RAMS + O&M Manual + Worksheet + Cable Schedule. Same UX everywhere. | ✓ |
| All four + manual regenerations | Like above plus a fresh email when a doc supersedes a previous version. | |

**User's choice:** All four document types (recommended).
**Notes:** Drives D-01. Manual regenerations explicitly excluded → D-02 (only the new completed model row emails, old superseded row stays silent).

### Q2 — Generation-failed: do we email when a Build*Job exhausts retries?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, alert admins | On failed status, email admins (not the PM). Prevents silent queue-rotting. | ✓ |
| Yes, alert PM + admins | Project owner also notified. Higher noise, full transparency. | |
| No, log only | Keep current behavior. Errors only in laravel.log + RamsDocument.error_message. | |

**User's choice:** Yes, alert admins (recommended).
**Notes:** Drives D-03. Hooks into existing job `failed()` lifecycle — no new infrastructure needed. PMs explicitly excluded to avoid blame-routing on failed jobs that are operationally fixable.

### Q3 — Review-needed trigger: when should the email fire?

| Option | Description | Selected |
|--------|-------------|----------|
| RAMS hits awaiting_review | Single, clear trigger: ExtractRamsDraftJob finishes and RAMS transitions to awaiting_review. | ✓ |
| Any doc enters review state | No-op until other docs add review gates. | |
| RAMS awaiting_review + 7-day reminder | Initial email plus a nudge if doc still in awaiting_review after 7 days. Needs scheduled command. | |

**User's choice:** RAMS hits awaiting_review (recommended).
**Notes:** Drives D-04. 7-day reminder explicitly deferred to backlog (captured in deferred ideas).

### Q4 — Survey-related: what should the survey trigger cover beyond what already exists?

| Option | Description | Selected |
|--------|-------------|----------|
| No change — keep current send only | SurveySubmittedMail is already wired. Phase 09 inherits it; template polish only. | ✓ |
| Also email engineer when survey link is generated | Confirmation email to surveyor with the public link. | |
| Add abandoned-survey reminder | If SiteSurvey opened but not submitted after 14 days, email PM. | |

**User's choice:** No change — keep current send only (recommended).
**Notes:** Drives D-05. Both rejected alternatives captured in Deferred Ideas for future backlog grooming.

---

## Recipient Policy

### Q1 — Generation-complete & review-needed: who is the default recipient?

| Option | Description | Selected |
|--------|-------------|----------|
| Project owner only | Project.user_id — same pattern as existing SurveySubmittedMail. Falls back to first admin if owner is null. | ✓ |
| Project owner + all admins | Owner plus everyone with is_admin=true. Higher noise; admins get oversight. | |
| Project owner + assigned engineer (when known) | Owner plus install_tasks.assigned_user_id (Phase 13). Engineer-aware but adds a join. | |

**User's choice:** Project owner only (recommended).
**Notes:** Drives D-06. Mirrors existing SurveyService fallback chain — consistency over cleverness.

### Q2 — Failure alerts: which admins receive the email?

| Option | Description | Selected |
|--------|-------------|----------|
| All users with is_admin=true | Simple — `User::where('is_admin', true)->get()`. Matches existing admin role model. | ✓ |
| Configured list in config/rams.php | rams.failure_alert_recipients = [...]. Decoupled from user table; needs deploy to update. | |
| Single ops mailbox | One address (e.g., ops@21stcav.com) via env var. Simplest. | |

**User's choice:** All users with is_admin=true (recommended).
**Notes:** Drives D-07. Configurable list captured in Deferred Ideas in case admin role grows beyond ops.

### Q3 — Per-project subscribers: extra notification recipient list?

| Option | Description | Selected |
|--------|-------------|----------|
| No — owner-driven only | Skip the table. Project owner is sole source of truth. | ✓ |
| Yes — project_notification_recipients table | Per-project list of extra emails. Adds settings panel; useful for holiday cover and client CCs. | |

**User's choice:** No — owner-driven only (recommended).
**Notes:** Drives D-08. Avoids CRUD scope creep into Phase 09. Captured for backlog if PMs ask.

### Q4 — Global audit BCC: should every system email BCC a shared mailbox?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, BCC ops@21stcav.com (configurable) | RAMS_NOTIFICATION_BCC env var; if non-empty, every system email is BCC'd. | ✓ |
| No BCC | Trust laravel.log + email_sent_at columns as audit trail. | |
| BCC only on failure alerts | Successful 'ready' emails clean; only failure alerts get the shared BCC. | |

**User's choice:** Yes, BCC ops@21stcav.com (configurable) (recommended).
**Notes:** Drives D-09. Disabled in dev/test by leaving env var empty; populated in production.

---

## Production Transport

### Q1 — Which mail driver for production?

| Option | Description | Selected |
|--------|-------------|----------|
| Postmark | Transactional-only — best deliverability, simple webhooks, ~$15/mo. | ✓ |
| Resend | Modern Stripe-style API, generous free tier, newer track record. | |
| Amazon SES | Cheapest at scale (~$0.10/1000). Sandbox lift, more IAM/region config. | |
| SMTP relay (Microsoft 365) | Send through 21st Century AV's M365 tenant. Throttled limits; DKIM tied to tenant. | |

**User's choice:** Postmark (recommended).
**Notes:** Drives D-16. config/services.php placeholder already exists; Symfony Postmark transport built into Laravel.

### Q2 — From address: what should system emails come from?

| Option | Description | Selected |
|--------|-------------|----------|
| rams@21stcav.com | Branded, identifiable, replies to a real inbox. SPF/DKIM/DMARC on 21stcav.com. | ✓ |
| noreply@21stcav.com | Standard 'do not reply'. Loses conversation thread. | |
| noreply@notifications.21stcav.com | Subdomain isolation. More DNS setup. | |

**User's choice:** rams@21stcav.com (recommended).
**Notes:** Drives D-17. DNS work captured as a planning checklist item (operational, not in repo).

### Q3 — Queue strategy for production sending?

| Option | Description | Selected |
|--------|-------------|----------|
| Queued via Mailable's ShouldQueue | All Mailables marked ShouldQueue; sends process on existing database queue. | ✓ |
| Sync (current pattern) | Mail::to(...)->send(...) blocks the request like SurveyService does today. | |

**User's choice:** Queued via Mailable's ShouldQueue (recommended).
**Notes:** Drives D-11. Decouples mail send time from job-completion latency.

### Q4 — Bounce/complaint handling depth?

| Option | Description | Selected |
|--------|-------------|----------|
| Log only — use provider dashboard for follow-up | Failures caught, logged via Log::warning. No webhook ingestion in v1.1. | ✓ |
| Webhook ingestion + email_status table | Provider posts bounce/complaint events to /webhooks/mail; mark recipient bad and stop sending. | |

**User's choice:** Log only — use provider dashboard for follow-up (recommended).
**Notes:** Drives D-19. Webhook ingestion captured in Deferred Ideas for future operational need.

---

## Claude's Discretion (areas not asked)

Documented in CONTEXT.md `<decisions>` → "Claude's Discretion":
- Email template structure (plain text vs minimal branded HTML).
- Whether to extract `NotificationRecipientResolver` service or keep recipient logic inline (rule: extract if used 3+ times).
- Test strategy: `Mail::fake()` per trigger, unit test for recipient helper.
- Whether NOTF requirement IDs use NOTF-01..NOTF-05 or a different breakdown (planner's call).

---

## Deferred Ideas

Captured in CONTEXT.md `<deferred>` section:

1. 7-day RAMS review reminder (Trigger inventory discussion).
2. 14-day abandoned-survey reminder (Survey trigger discussion).
3. Engineer survey-link confirmation email (Survey trigger discussion).
4. Per-user notification preferences / opt-out (skipped area).
5. Multi-channel Notification framework migration (Channel architecture not selected).
6. In-app notification centre / read-unread inbox.
7. Bounce/complaint webhook + email-status table (Bounces question).
8. Per-project subscriber list / `project_notification_recipients` table (Recipients question).
9. Failure recipient policy as configurable list (Recipients question).
10. CI mail driver hygiene (`MAIL_MAILER=array`).
