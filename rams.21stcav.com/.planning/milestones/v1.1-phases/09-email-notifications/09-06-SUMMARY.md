---
phase: 09-email-notifications
plan: 06
subsystem: notifications
tags: [ops, postmark, dns, cutover, runbook, phase-09, notifications]

# Dependency graph
requires:
  - phase: 09-email-notifications
    plan: 01
    provides: ".env.example Phase-09 mail block (MAIL_MAILER, POSTMARK_API_KEY, MAIL_FROM_*, RAMS_NOTIFICATION_BCC)"
  - phase: 09-email-notifications
    plan: 05
    provides: "All dispatch wiring complete — Phase 09 code-ready for transport swap"
provides:
  - "POSTMARK-OPS-CHECKLIST.md — single runbook tracking the four operational steps code cannot perform"
  - "Documented correct env var name POSTMARK_API_KEY (not the older TOKEN-suffixed name) — mitigation for T-09-04"
  - "Staged DMARC rollout plan (p=none → p=quarantine after 14 days) — mitigation for T-09-01"
  - "30-second MAIL_MAILER=log rollback path requiring zero code change"
affects:
  - "Phase 09 closure — cutover is now the only gating item; decision recorded via checkpoint resume-signal"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Ops runbook pattern — checkbox-shaped markdown with decisions log table the user ticks as work completes (first instance in this repo's .planning tree)"

key-files:
  created:
    - ".planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md (115 lines — 7 sections covering account/DNS/env/smoke/rollback/decisions/deferred)"
  modified: []

key-decisions:
  - "Env var name explicitly documented as POSTMARK_API_KEY (NOT the older TOKEN-suffixed name that appears in some Laravel tutorials) — matches config/services.php:18 which reads env('POSTMARK_API_KEY')"
  - "DMARC rollout is staged: start at p=none for 14 days of observation, then promote to p=quarantine, optionally to p=reject after 30 days — minimises risk of legitimate mail being quarantined during cutover"
  - "From address fixed at rams@21stcav.com per D-17 — checklist instructs ops to verify either the sender signature OR (preferred) the whole 21stcav.com domain"
  - "Separate Postmark server recommended for staging (21cav-staging) so bounce/complaint reputation isolates from production"
  - "Rollback is env-only (MAIL_MAILER=log + config:cache + queue:restart) — no code change, no DB migration, no mail loss (send path try/catch + log)"
  - "Acceptance criterion for 'wrong env var absent' respected without confusing the reader — docs the correct name (POSTMARK_API_KEY) and warns against the older 'TOKEN'-suffixed tutorial variant without literally printing it"

patterns-established:
  - "Ops-checklist file lives in the phase directory (not repo root or docs/) — colocated with the plan that gated its creation so future-Claude reading 09-CONTEXT.md / 09-RESEARCH.md finds it naturally"

requirements-completed:
  - NOTF-05g     # production transport documented; cutover gate recorded

# Metrics
duration: ~12min
completed: 2026-04-19
---

# Phase 09 Plan 06: Postmark Operations Checklist Summary

**Authored the single operational runbook (`POSTMARK-OPS-CHECKLIST.md`) that documents the four production-cutover steps Claude cannot perform — Postmark account + sender-signature verification, DNS records on the `21stcav.com` zone (SPF / DKIM / DMARC), production `.env` values, and post-cutover smoke tests. Phase 09 is now code-complete; cutover is an env flip, not a code change.**

## Performance

- **Duration:** ~12 min
- **Completed:** 2026-04-19
- **Tasks:** 1 auto + 1 checkpoint (human-verify, auto-approved under `--auto`)
- **Files created:** 1
- **Files modified:** 0

## Task commits

1. `8d9975f` — **Task 1:** docs(09-06): add POSTMARK-OPS-CHECKLIST runbook for production cutover

## The checklist — what it covers

`POSTMARK-OPS-CHECKLIST.md` (115 lines, 7 sections):

| § | Section | What it gates |
|---|---|---|
| 1 | Postmark account + sender signature | Account exists, `rams@21stcav.com` (or whole `21stcav.com` domain) verified, `outbound` stream confirmed, Server API Token captured |
| 2 | DNS records on `21stcav.com` | DNS owner identified, SPF extended with `include:spf.mtasv.net` (not a second SPF record — RFC 7208 forbids), DKIM selector from Postmark UI, optional Return-Path CNAME, DMARC staged from `p=none` (first 14 days) to `p=quarantine` |
| 3 | Production `.env` | `MAIL_MAILER=postmark` + `POSTMARK_API_KEY` + `MAIL_FROM_ADDRESS=rams@21stcav.com` + `MAIL_FROM_NAME="RAMS Platform"` + `RAMS_NOTIFICATION_BCC=ops@21stcav.com`; `config:cache` + `queue:restart` after change; sanity-check via tinker |
| 4 | Post-cutover smoke tests | `check-auth@verifier.port25.com` for DKIM/SPF/DMARC `pass`, tinker `Mail::raw` transport sanity, trigger matrix covering all 6 mailable types (RAMS / OM / Worksheet / Cable / review-needed / failure), reply-inbox check, BCC-header check, 24h Postmark Activity watch |
| 5 | Rollback plan | 30-second `MAIL_MAILER=log` flip + `config:cache` + `queue:restart`; no code change needed (try/catch at every send site) |
| 6 | Decisions log | Dated rows for DNS provider, Postmark account, signature, DNS publish, DMARC promotion, cutover, 24h check |
| 7 | Deferred / blocker log | Dated rows for target revisit dates or blocking reasons |

## Acceptance check — all positive greps pass, negative grep passes

Run from repo root:

```bash
F=".planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md"
test -f "$F"                              # exists
wc -l "$F"                                # 115 lines (≥30 per plan)
grep -q "POSTMARK_API_KEY" "$F"           # correct env var name — present
grep -q "rams@21stcav.com" "$F"           # D-17 from address — present
grep -qE "spf\.mtasv\.net" "$F"           # SPF include directive — present
grep -q "_dmarc" "$F"                     # DMARC record — present
grep -q "config:cache" "$F"               # cache reset step — present
grep -q "queue:restart" "$F"              # worker restart step — present
! grep -q "POSTMARK_TOKEN" "$F"           # wrong env var name — ABSENT
```

All verified at commit time.

## Key design choice — "TOKEN"-absent phrasing

The plan's negative grep requires `! grep -q "POSTMARK_TOKEN"` — meaning the literal 14-byte string `POSTMARK_TOKEN` must not appear in the checklist. A naïve warning like *"use `POSTMARK_API_KEY`, NOT `POSTMARK_TOKEN`"* would fail that grep.

Resolution: the checklist documents the correct name explicitly (`POSTMARK_API_KEY`, reinforced with the `config/services.php:18` reference) and warns against *"the older 'TOKEN'-suffixed name that appears in some Laravel tutorials"* — gives the reader enough context to recognise a stale tutorial without literally emitting the wrong string. Verifies threat mitigation T-09-04 (secret misconfiguration leading to silent transport failure) without tripping the acceptance grep.

## Cutover decision

*This plan concludes at a `checkpoint:human-verify` gate. The phase is executing under `/gsd-execute-phase 09 --auto`, so the checkpoint auto-approves immediately after this SUMMARY commits. The resume-signal recorded below is the auto-approval equivalent for NOTF-05g.*

**Resume-signal captured: `cutover-noop` (auto-mode auto-approve)**

- The runbook is authored, committed, and ready.
- No production environment cutover is executed in this run — the ops steps (Postmark dashboard + DNS zone write access) are outside the dev worktree's reach.
- When the user is ready to go live, they open `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`, follow sections 1–4 in order, and record dates in section 6. Section 5 is the rollback if anything misbehaves.
- Phase 09 ships code-complete regardless of cutover timing. Every sender path already falls back to `MAIL_MAILER=log` safely (try/catch at every call site, Phase 09 plan 05 design).

## Deviations from Plan

### Minor wording adjustment (not a behavioural deviation)

**1. "TOKEN"-absent phrasing — see Key design choice above**

- **Found during:** Task 1 acceptance verification.
- **Issue:** First draft warned *"Env var name is `POSTMARK_API_KEY` (NOT `POSTMARK_TOKEN`)"* — the literal string `POSTMARK_TOKEN` tripped the plan's negative grep.
- **Fix:** Reworded to *"do NOT use the older 'TOKEN'-suffixed name that appears in some Laravel tutorials; Laravel 11+ / symfony/postmark-mailer expects the `_API_KEY` name"* — preserves the warning content without emitting the wrong literal.
- **Files modified:** `POSTMARK-OPS-CHECKLIST.md` line 55.
- **Committed in:** `8d9975f` (pre-commit edit).

No other deviations. Plan executed as written.

## Authentication gates

None — all work is documentation. No API keys were handled; the checklist just tells the human operator where to paste the Postmark Server API Token once they have it.

## Verification performed

- [x] File exists at `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`
- [x] 115 lines (≥30 per plan)
- [x] All six required positive greps pass (`POSTMARK_API_KEY`, `rams@21stcav.com`, `spf.mtasv.net`, `_dmarc`, `config:cache`, `queue:restart`)
- [x] Negative grep passes (`POSTMARK_TOKEN` string absent)
- [x] Checklist structure covers the seven sections listed in the Task-1 action template
- [x] Commit `8d9975f` exists in `git log`

## Success criteria

| Criterion | Status |
|---|---|
| Operational checklist exists at the documented path | PASS |
| Covers Postmark account, DNS, .env, smoke tests | PASS |
| Correct env var name documented (POSTMARK_API_KEY) | PASS |
| From address matches D-17 (rams@21stcav.com) | PASS |
| Cutover decision recorded (human-verify checkpoint) | PASS — `cutover-noop` under --auto mode |

## Self-Check

Files claimed to exist (verified via filesystem):

- FOUND: `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`

Commits claimed to exist (verified via `git log`):

- FOUND: `8d9975f` — docs(09-06): add POSTMARK-OPS-CHECKLIST runbook for production cutover

## Self-Check: PASSED

---
*Phase: 09-email-notifications*
*Plan: 06*
*Completed: 2026-04-19*
