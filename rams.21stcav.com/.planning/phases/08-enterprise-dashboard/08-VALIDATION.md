---
phase: 08
slug: enterprise-dashboard
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-14
---

# Phase 08 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Dashboard` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Dashboard`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 08-01-01 | 01 | 1 | DASH-01a | — | N/A | feature | `php artisan test --filter=DashboardControllerTest` | ❌ W0 | ⬜ pending |
| 08-01-02 | 01 | 1 | DASH-01c | — | N/A | unit | `php artisan test --filter=ProjectHealthServiceTest` | ❌ W0 | ⬜ pending |
| 08-01-03 | 01 | 1 | DASH-01d | — | health red = blocked | unit | `php artisan test --filter=ProjectHealthServiceTest::test_red_engineering_no_approved_rams` | ❌ W0 | ⬜ pending |
| 08-01-04 | 01 | 1 | DASH-01e | — | overdue flag when >14 days | unit | `php artisan test --filter=ProjectHealthServiceTest::test_overdue_flag` | ❌ W0 | ⬜ pending |
| 08-01-05 | 01 | 1 | DASH-01h | — | graceful absent programme | unit | `php artisan test --filter=ProjectHealthServiceTest::test_no_programme_hidden` | ❌ W0 | ⬜ pending |
| 08-02-01 | 02 | 2 | DASH-01b | — | N/A | feature | `php artisan test --filter=DashboardControllerTest::test_all_projects_shown` | ❌ W0 | ⬜ pending |
| 08-02-02 | 02 | 2 | DASH-01f | — | N/A | feature | `php artisan test --filter=DashboardControllerTest::test_status_counts` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/DashboardControllerTest.php` — stubs for DASH-01a, DASH-01b, DASH-01f
- [ ] `tests/Unit/ProjectHealthServiceTest.php` — stubs for DASH-01c, DASH-01d, DASH-01e, DASH-01h

*Existing PHPUnit infrastructure covers all phase requirements.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Alpine.js status filter filters grid without page reload | DASH-01g | JavaScript DOM interaction | Open `/dashboard`, click a status chip, verify grid shows only matching projects in browser |
| Install programme % shown for installing project | DASH-01h | Requires real DB fixture | Create project in installing status with active programme, open dashboard, verify % appears |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
