---
phase: 14
slug: mobile-field-view
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-19
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Phase14` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~45 seconds (quick) / ~120 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Phase14`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD — filled by planner | TBD | TBD | INST-03a..h | TBD | TBD | TBD | TBD | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/FieldPageTest.php` — GET /projects/{project}/programme ownership + admin
- [ ] `tests/Feature/InstallTaskStatusUpdateTest.php` — status transition matrix
- [ ] `tests/Feature/InstallTaskPhotoUploadTest.php` — HEIC → JPEG conversion with fixture
- [ ] `tests/Feature/InstallTaskNotesTest.php` — notes blur-save
- [ ] `tests/Feature/TimeEntryTest.php` — start/stop + one-open-entry guard
- [ ] `tests/Unit/Services/HeicImageConverterTest.php` — happy path + Imagick-missing error path
- [ ] `tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php` — column shape assertion
- [ ] `tests/Fixtures/sample.heic` — tiny (~100 KB) real HEIC test fixture
- [ ] `InstallTaskFactory` + `InstallTaskPhotoFactory` + `TimeEntryFactory` — test setup

*Wave 0 validates tests exist and fail meaningfully before implementation lands.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| 375px viewport renders without horizontal scroll | SC-1 / INST-03a | Dusk overkill for a single visual check | Open `/projects/{id}/programme` in Chrome DevTools iPhone SE emulator (375×667); confirm no horizontal scrollbar; confirm all interactive elements reachable with thumb |
| HEIC from a real iOS device | INST-03e | Cannot simulate full iOS camera pipeline in CI | Upload a photo from iOS Safari on an iPhone; confirm JPEG file appears on disk and thumbnail renders |
| Inline save visual (checkmark pulse, colour change) | INST-03c / D-08 | Animation timing requires human eye | Tap a task status; confirm colour change + checkmark pulse without page reload |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
