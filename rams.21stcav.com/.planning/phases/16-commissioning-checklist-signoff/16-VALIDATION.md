---
phase: 16
slug: commissioning-checklist-signoff
status: draft
nyquist_compliant: true
wave_0_complete: true
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
| 16-01-1 | 01 | 0 | INST-05a..i | T-16-TEST-01 | Wave 0 scaffold — 22 red tests + 2 factories | Feature/Unit | `php artisan test --filter=Commissioning` | ✅ | ⬜ |
| 16-01-2 | 01 | 0 | — | — | Validation map populated | docs | `grep -c '^\| 16-0' 16-VALIDATION.md` | ✅ | ⬜ |
| 16-01-3 | 01 | 0 | — | — | Flip Nyquist flags | docs | `grep -q 'nyquist_compliant: true' 16-VALIDATION.md` | ✅ | ⬜ |
| 16-02-1 | 02 | 1 | INST-05f stack | T-16-04 | Package install + TYPE_SNAGGING + DPI spike | contract | `composer show creagia/laravel-sign-pad`; `php artisan test --filter=DocumentArtifactStorageTest` | ✅ | ⬜ |
| 16-02-2 | 02 | 1 | INST-05a | T-16-01, T-16-05 | Schema migrations | integration | `php artisan test --filter=CommissioningSchemaTest` | ✅ | ⬜ |
| 16-02-3 | 02 | 1 | INST-05b, INST-05i | T-16-01 | Models + config + exception | unit | `php artisan test --filter=CommissioningItemTest --filter=CommissioningSignoffTest` | ✅ | ⬜ |
| 16-02-4 | 02 | 1 | INST-05b, D-03, D-04, D-07 | T-16-04 | Generator + Sync + Observer | unit/integration | `php artisan test --filter=CommissioningItemGeneratorTest --filter=CommissioningSyncServiceTest --filter=GenerationTriggerTest` | ✅ | ⬜ |
| 16-03-1 | 03 | 2 | INST-05c, INST-05i | T-16-01, T-16-03 | AJAX status/notes + immutability | integration | `php artisan test --filter=ItemStatusPatchTest --filter=ItemNotesPatchTest --filter=ImmutabilityAfterSignoffTest` | ✅ | ⬜ |
| 16-03-2 | 03 | 2 | INST-05d, D-14 | T-16-02, T-16-03 | Photo upload + HEIC + fail-requires-photo | integration | `php artisan test --filter=ItemPhotoUploadTest` | ✅ | ⬜ |
| 16-03-3 | 03 | 2 | INST-05, CONTEXT | T-16-03 | Checklist view + ownership | integration/e2e | `php artisan test --filter=OwnershipGuardTest` | ✅ | ⬜ |
| 16-04-1 | 04 | 2 | INST-05g | T-16-05, T-16-06, T-16-07 | PDF service + base64 embed + TYPE_SNAGGING | integration/unit | `php artisan test --filter=CommissioningPdfServiceTest --filter=SnaggingPdfGenerationTest` | ✅ | ⬜ |
| 16-04-2 | 04 | 2 | INST-05g, INST-05h, D-16 | T-16-04, T-16-05 | Finalise + state transition + atomicity | integration | `php artisan test --filter=SignoffFinaliseTest --filter=StateTransitionTest --filter=SignoffTransactionTest --filter=ZeroItemsTest --filter=SignoffRaceTest` | ✅ | ⬜ |
| 16-05-1 | 05 | 3 | INST-05f | T-16-01, T-16-07 | DPI canvas + signoff sheet view | integration | `php artisan test --filter=SignoffSheetViewTest` | ✅ | ⬜ |
| 16-05-2 | 05 | 3 | D-04 | T-16-03 | Re-sync diff UI | integration | `php artisan test --filter=ResyncDiffTest` | ✅ | ⬜ |
| 16-05-3 | 05 | 3 | CONTEXT D-13 | — | Zero-items empty state + checkpoint | human-verify | manual iOS Safari | ❌ | ⬜ |

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

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 90s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved (Wave 0 scaffold complete 2026-04-21)
