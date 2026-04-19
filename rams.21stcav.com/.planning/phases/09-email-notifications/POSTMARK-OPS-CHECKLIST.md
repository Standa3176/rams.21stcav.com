# Phase 09 — Postmark Production Cutover Checklist

**Owner:** ops / DNS owner for `21stcav.com`
**Created by:** Phase 09 / NOTF-05g
**Status:** _pending_ | _in progress_ | _live since YYYY-MM-DD_ | _deferred to YYYY-MM-DD_

> This checklist covers the four things Claude / code cannot do: Postmark account
> state, DNS records, production secret rotation, post-cutover smoke tests. Tick
> each box as work lands. Fill in the Decisions log (section 6) with dates.
>
> The code is already complete after plan 09-05. Cutover is an environment flip,
> not a code change.

---

## 1. Postmark account + sender signature

- [ ] Confirm a Postmark account exists for 21st Century AV Ltd (or create one — postmarkapp.com). Record the Postmark server nickname used for `rams@21stcav.com` (e.g., `21cav-production`).
- [ ] Verify the sender signature for `rams@21stcav.com` (Postmark UI → Servers → {server} → Sender Signatures). You receive an email at `rams@21stcav.com` with a verification link; someone with access to that mailbox must click it.
      **OR** verify the entire `21stcav.com` domain (Postmark UI → Sender Signatures → Domains). Domain verification is preferred — it covers any future `*@21stcav.com` from-address without re-verifying each one.
- [ ] Confirm the message stream `outbound` exists (Postmark default; new accounts have it). The Laravel Postmark transport ships to this stream by default; no code pin is required.
- [ ] Note the **Server API Token** (UI → Servers → {server} → API Tokens tab). **This is NOT the account token** — account tokens can create/delete servers and are too privileged for app use.
- [ ] If staging will also go to Postmark, create a second server named `21cav-staging` with its own token; put that token in the staging `.env`. Separate servers isolate bounce/complaint reputation between environments.

## 2. DNS records on `21stcav.com`

- [ ] Identify the DNS provider for `21stcav.com` (Cloudflare? AWS Route53? Domain registrar? internal bind?). Record the provider + admin contact in section 6.
- [ ] Confirm write access to the `21stcav.com` zone is available to someone who can respond to urgent rollback requests (day-of cutover).
- [ ] **SPF**: extend the existing `TXT` record at the apex (`21stcav.com`) to include `include:spf.mtasv.net`. Example before/after:
      `v=spf1 include:_spf.google.com ~all`  →  `v=spf1 include:_spf.google.com include:spf.mtasv.net ~all`
      **⚠ Do NOT create a second SPF record — RFC 7208 forbids it.** Two SPF records = both fail.
- [ ] **DKIM**: in Postmark UI, Signatures / Domains → `21stcav.com` → DKIM tab generates a selector record. Copy the name and value verbatim and add as a `TXT` record. Name looks like `20240101._domainkey.21stcav.com`, value starts `k=rsa; p=MIGfMA0GCSq...`.
- [ ] **Return-Path** (recommended but optional): add a `CNAME` record `pm-bounces.21stcav.com` → `pm.mtasv.net`. This lets Postmark process bounces under your domain instead of theirs — improves deliverability reputation slightly.
- [ ] **DMARC — first 14 days**: add a `TXT` record at `_dmarc.21stcav.com` with
      `v=DMARC1; p=none; rua=mailto:dmarc@21stcav.com; pct=100`
      Policy `none` is an observation-only phase. Aggregate reports roll up to the rua inbox; confirm zero failing auth from `@21stcav.com`.
- [ ] **DMARC — after 14 days of clean reports**: promote the policy to `p=quarantine`.
      `v=DMARC1; p=quarantine; rua=mailto:dmarc@21stcav.com; pct=100`
- [ ] **DMARC — after 30 days of quarantine with no issues** (optional): promote to `p=reject` for strongest spoofing protection.
- [ ] Wait ≥ 1 hour after DNS publish before the first production send. DNS propagation plus Postmark's internal verification polling need that buffer; a premature send risks Postmark flagging the domain as "pending verification" and rejecting it.

## 3. Production `.env` values

Add to **production `.env` only** (never commit secrets to `.env.example` — see
`.env.example` lines 55–62 for the committed placeholder block):

```dotenv
MAIL_MAILER=postmark
POSTMARK_API_KEY=<paste from Postmark Server API Token>
MAIL_FROM_ADDRESS=rams@21stcav.com
MAIL_FROM_NAME="RAMS Platform"
RAMS_NOTIFICATION_BCC=ops@21stcav.com
```

- [ ] Env var name is `POSTMARK_API_KEY`. `config/services.php:18` reads `env('POSTMARK_API_KEY')` — do NOT use the older "TOKEN"-suffixed name that appears in some Laravel tutorials; Laravel 11+ / `symfony/postmark-mailer` expects the `_API_KEY` name. A misnamed var gives a silent "missing key" failure at first send.
- [ ] Confirm `composer.json` / `composer.lock` carry `symfony/postmark-mailer` and `symfony/http-client`. Phase 09 plan 01 added them; if running a clean production deploy, `composer install --no-dev` must succeed before the transport can resolve. Without these, the first send throws `Unsupported transport scheme: postmark`.
- [ ] After updating `.env`, run `php artisan config:cache` on the production host. Laravel caches `.env` values — the new transport will not take effect until the cache is regenerated.
- [ ] Run `php artisan queue:restart` so workers pick up the new mailer. Queued `ShouldQueue` mailables (all six Phase 09 mailables implement it) dispatch through worker processes; long-running workers cached the old mailer until restarted.
- [ ] Sanity-check: `php artisan tinker` → `config('mail.default')` returns `"postmark"`, `config('services.postmark.key')` returns the token (not null).
- [ ] Keep `RAMS_NOTIFICATION_BCC=ops@21stcav.com` in production. In dev it should stay empty so MailHog inboxes do not get spammed. The BCC adds an audit copy of every system email to the ops inbox — required by the Phase 09 threat-model mitigation T-09-03.

## 4. Post-cutover smoke tests

Run **in order** — stop at the first failure.

- [ ] **DKIM / SPF / DMARC verification** — from staging or production, send a test email to `check-auth@verifier.port25.com`. Within ~1 minute you receive an automated reply showing SPF, DKIM, and DMARC results. Expect **`pass`** for all three. If any fail, do NOT proceed — fix the DNS record and wait for TTL before retrying.
- [ ] **Transport sanity** — in production tinker, run
      `Mail::raw('phase-09 smoke', fn($m) => $m->to('ops@21stcav.com')->subject('phase-09 smoke'));`
      Open the Postmark Activity feed; expect a `Delivered` event within ~10 seconds.
- [ ] **Trigger one of each mail type** from staging (or production, if already cut over) with the sandbox token. Verify each appears in Postmark Activity as `Delivered`:
    - [ ] RAMS ready — trigger by completing a `BuildRamsDocumentJob`
    - [ ] O&M ready — trigger by completing a `BuildOmManualJob`
    - [ ] Worksheet ready — trigger by completing a `BuildWorksheetJob`
    - [ ] Cable schedule ready — trigger by completing a `BuildCableScheduleJob`
    - [ ] RAMS review-needed — trigger by uploading a RAMS PDF (ExtractRamsDraftJob flips status to `awaiting_review`)
    - [ ] Document-generation failed — trigger via `BuildRamsDocumentJob::failed(new Exception('smoke'))` in tinker
- [ ] **Confirm receipt at the `rams@21stcav.com` reply inbox** — reply to one of the delivered emails. The reply must land in a real shared mailbox that ops reads (ticket, email, whatever). A dead from-address damages deliverability reputation long-term.
- [ ] **Confirm BCC behaviour** — the `ops@21stcav.com` inbox should receive an audit copy of each of the six smoke sends above. Check that recipient users do NOT see the BCC in their email headers (Postmark / RFC 5322 strip BCC from outgoing messages — recipients only see To + Cc).
- [ ] **Tail Postmark Activity for first 24 h** after production cutover: zero hard bounces, zero spam complaints. Soft bounces (e.g., "mailbox full") are fine; hard bounces indicate a bad recipient address in the pipeline that should be investigated.
- [ ] **Check Postmark's reputation indicator** (Servers → {server} → Overview). Stays "Great" throughout the first week.

## 5. Rollback plan

If Postmark cutover misbehaves (reputation drops, mails not delivering, DKIM
starts failing) — rollback is a 30-second env flip, no code change:

- [ ] Edit production `.env`: set `MAIL_MAILER=log`
- [ ] Run `php artisan config:cache && php artisan queue:restart`
- [ ] Mail will silently render into `storage/logs/laravel.log` instead of attempting delivery. Users won't get emails, but document generation continues uninterrupted (every call site wraps send in `try / catch Log::warning` — see Phase 09 plan 05 deviation log).
- [ ] Investigate root cause, fix, then re-cutover by setting `MAIL_MAILER=postmark` again and repeating section 4 smoke tests.

## 6. Decisions log

| Date       | Step                        | Decision / outcome                                       |
| ---------- | --------------------------- | -------------------------------------------------------- |
| YYYY-MM-DD | DNS provider + owner        | _e.g., Cloudflare account admin: ops@21stcav.com_        |
| YYYY-MM-DD | Postmark account created    | _server nickname: 21cav-production_                      |
| YYYY-MM-DD | Sender signature verified   | _signature / domain-verified / deferred because …_       |
| YYYY-MM-DD | DNS records published       | _SPF extended / DKIM selector XXX / DMARC p=none live_   |
| YYYY-MM-DD | DMARC promoted to quarantine| _after 14 days of clean rua reports_                     |
| YYYY-MM-DD | Production cutover          | _MAIL_MAILER=postmark live; smoke tests pass_            |
| YYYY-MM-DD | 24-hour reputation check    | _Postmark reputation stayed Great / zero hard bounces_   |

## 7. Deferred / blocker log (fill if cutover deferred)

| Date       | Reason                                                         |
| ---------- | -------------------------------------------------------------- |
| YYYY-MM-DD | _e.g., production environment not provisioned yet — revisit Qn_|
| YYYY-MM-DD | _e.g., DNS owner on leave — chase after MM-DD_                 |

---

*See `.planning/phases/09-email-notifications/09-RESEARCH.md` "Postmark Setup" for*
*the verified research the checklist is drawn from. See plan 09-06 for the gating*
*human-verify checkpoint that marks this phase done.*
