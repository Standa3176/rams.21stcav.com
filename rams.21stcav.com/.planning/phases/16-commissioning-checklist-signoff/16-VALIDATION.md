---
phase: 16
slug: commissioning-checklist-signoff
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-22
---

# Phase 16 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Planner fills the Per-Task Verification Map after plans are generated.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (existing — `phpunit.xml`) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `vendor/bin/phpunit --testsuite=Unit --filter=Commissioning` |
| **Full suite command** | `vendor/bin/phpunit --testsuite=Feature` |
| **Estimated runtime** | ~90 seconds (full suite); ~8 seconds (commissioning-only) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --filter=<TaskName>` (scoped to the new/modified test)
- **After every plan wave:** Run `vendor/bin/phpunit --testsuite=Feature --filter=Commissioning`
- **Before `/gsd-verify-work`:** Full `vendor/bin/phpunit` suite must be green
- **Max feedback latency:** 90 seconds

---

## Per-Task Verification Map

*Planner populates this table after PLAN.md files are generated. One row per task referencing its REQ-ID, Test Type, and Automated Command.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| (populated by planner) | | | | | | | | | |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Planner derives from task list. Expected Wave 0 scaffolds (from RESEARCH.md §Validation Architecture):

- [ ] `tests/Unit/Services/CommissioningItemGeneratorTest.php` — keyword match, per-instance grain, skip-unmatched (REQ-INST-05b, INST-05e)
- [ ] `tests/Unit/Services/CommissioningSyncServiceTest.php` — re-sync diff + soft-delete (REQ-INST-05b, D-04)
- [ ] `tests/Unit/Services/CommissioningPdfServiceTest.php` — preview vs final PDF, base64 signature embedding (REQ-INST-05g, D-10)
- [ ] `tests/Feature/Commissioning/PerItemAjaxTest.php` — PATCH status + photo + notes per item, 422 on fail-without-photo (REQ-INST-05c, INST-05d, D-14)
- [ ] `tests/Feature/Commissioning/SignoffTransactionTest.php` — atomic: signoff row + PDF write + Project.STATUS_COMMISSIONING + InstallProgramme.STATUS_COMPLETE in one transaction (REQ-INST-05g, INST-05h, D-16)
- [ ] `tests/Feature/Commissioning/ImmutabilityGuardTest.php` — post-signature edits 422 (REQ-INST-05i)
- [ ] `tests/Feature/Commissioning/ZeroItemsTest.php` — empty programme unlocks immediately (D-13)
- [ ] `tests/Unit/Services/DocumentArtifactStorageTest.php` — extend existing test with `TYPE_SNAGGING` (RESEARCH §H-07)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| iOS Retina DPI signature clarity | INST-05f | Requires physical iOS device; cannot be asserted from PHPUnit | Test on iPhone 13+ Safari: open signoff sheet, sign with finger, verify no pixelation on the canvas preview OR on the generated PDF |
| Engineer UX on 320px-wide phones | CONTEXT.md mobile-first | Visual regression not covered by feature tests | Chrome DevTools device emulation — verify item rows, bottom-sheets, signature canvas fit without horizontal scroll |
| Snagging PDF visual correctness | INST-05g | DomPDF rendering diffs not easily asserted | Generate PDF for a seeded programme with mix of pass/fail/na; eyeball alignment with RAMS PDF aesthetic |
| Client signature legal defensibility | INST-05f, D-15 | Legal review, not technical | Counsel / ops review of certification_text wording before production ship |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 90s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
