---
phase: 29
slug: cdm-duty-holder-emergency-arrangements
status: draft
nyquist_compliant: false
wave_0_complete: true
created: 2026-09-11
---

# Phase 29 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 (`phpunit/phpunit ^11.5.3`) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=<TestName>` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | **531.45s (~8m 51s) measured** — `php artisan test`, 2026-09-11, 2455 passed / 1 pre-existing unrelated failure (`QueueRecoverCommandTest`, see `29-MEASUREMENT.md`) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=<TestName>`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** **~1063s (~18 minutes)** — 2x the measured 531.45s full-suite
  runtime (worst case: one extra full run plus overhead), per 29-MEASUREMENT.md

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| *(planner fills — one row per task)* | | | | | | | | | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] Measure production row count carrying `[To be confirmed]` in `generated_data.cdm_duty_holders` (and `reviewed_data.cdm`) — read-only, per CONTEXT.md D-02 measure-first rule — 54 total, 46 with placeholder (85%), see `29-MEASUREMENT.md`
- [x] Confirm the effective `RAMS_UNIFIED_COMPOSER` value in production via `config()`, not by reading `.env` — research could not verify it — measured `false`, see `29-MEASUREMENT.md`
- [x] Confirm full-suite runtime to set the feedback-latency budget above — 531.45s measured, see `29-MEASUREMENT.md`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live occupied-premises project regenerates with a stated CDM position and no A&E placeholder | RULE-07, RULE-08 | ROADMAP criterion 4 requires verification against production data, not a fixture | Regenerate a live project on the VPS after the backfill runs; inspect the generated PDF and DOCX for the CDM table and Section 7.0 A&E row |
| Gate arming | GATE-11, GATE-12 | Per CONTEXT.md D-03 the gates ship disarmed; arming is a separate `.env` change made after the backfill verifies | Flip the new gate flag true in production `.env`, clear config cache, regenerate the same project, confirm no exception |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency budget set from measured runtime
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
