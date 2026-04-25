---
status: partial
phase: 09-email-notifications
source: [09-VERIFICATION.md]
started: 2026-04-19T19:23:00Z
updated: 2026-04-19T19:23:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Dev smoke run under MAIL_MAILER=log for all 6 mailable types
expected: With `MAIL_MAILER=log` locally, exercise each trigger end-to-end and confirm log output contains the expected mailable (subject + recipient + attachment filename where applicable). Coverage: (a) RAMS completion via BuildRamsDocumentJob, (b) OM completion via BuildOmManualJob, (c) Worksheet completion via BuildWorksheetJob, (d) Cable completion via BuildCableScheduleJob, (e) RAMS review-needed via ExtractRamsDraftJob, (f) Document-generation-failed via any Build*Job::failed() hook. ~15 min manual run per instructions in 09-05-SUMMARY.md.
result: [pending]

### 2. Production Postmark cutover per POSTMARK-OPS-CHECKLIST.md
expected: Follow .planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md steps 1-7: (1) Postmark sender signature for rams@21stcav.com, (2) SPF/DKIM/DMARC DNS records on 21stcav.com zone, (3) production .env values (MAIL_MAILER=postmark, POSTMARK_API_KEY=<real>), (4) `php artisan config:cache` + `php artisan queue:restart`, (5) port25 DKIM verifier check, (6) one real trigger per mailable type in production, (7) 24h Postmark Activity watch. Currently deferred under --auto mode (cutover-noop) — no live sends have been verified.
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
