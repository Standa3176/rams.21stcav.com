---
phase: 03
slug: survey-data-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-10
---

# Phase 03 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter Phase03` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter Phase03`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | SURV-01 | — | N/A | feature | `php artisan test --filter SiteSurveyMigrationTest` | ❌ W0 | ⬜ pending |
| 03-01-02 | 01 | 1 | SURV-01 | — | N/A | unit | `php artisan test --filter SiteSurveyModelTest` | ❌ W0 | ⬜ pending |
| 03-02-01 | 02 | 2 | SURV-03 | — | Survey resolve tier ordering | unit | `php artisan test --filter ProjectDataServiceTest` | ✅ | ⬜ pending |
| 03-02-02 | 02 | 2 | SURV-03 | — | Fuzzy room matching at threshold | unit | `php artisan test --filter SurveyRoomMatcherTest` | ❌ W0 | ⬜ pending |
| 03-03-01 | 03 | 3 | SURV-04 | — | UUID token gating — no auth | feature | `php artisan test --filter PublicSurveyControllerTest` | ✅ | ⬜ pending |
| 03-03-02 | 03 | 3 | SURV-02 | — | Per-room field capture | feature | `php artisan test --filter PublicSurveyControllerTest` | ✅ | ⬜ pending |
| 03-04-01 | 04 | 4 | SURV-05 | — | Draft save does not require submit | feature | `php artisan test --filter PublicSurveyDraftTest` | ❌ W0 | ⬜ pending |
| 03-04-02 | 04 | 4 | SURV-05 | — | Submit marks survey complete | feature | `php artisan test --filter PublicSurveyDraftTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/SiteSurveyMigrationTest.php` — stubs for SURV-01 schema changes
- [ ] `tests/Unit/SiteSurveyModelTest.php` — stubs for model fillable/relationship coverage
- [ ] `tests/Unit/SurveyRoomMatcherTest.php` — stubs for fuzzy matching service (SURV-03)
- [ ] `tests/Feature/PublicSurveyDraftTest.php` — stubs for draft/submit flow (SURV-05)

*Existing infrastructure: `ProjectDataServiceTest.php` and `PublicSurveyControllerTest.php` already exist — update stubs only.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Supersede confirmation UX — inline block renders correctly | SURV-01 | Visual confirmation of Blade UI component | Load a project with an existing survey, attempt to create a second survey, verify the inline `.alert-warning` block with "Archive existing and create new survey" / "Keep existing survey" buttons appears |
| Public survey UUID link rejects expired/unknown tokens | SURV-04 | Token validation UI feedback | Visit `/survey/invalid-token` — expect 404 or error view with "This survey link is not valid" message |
| Confirmation page loads after submit | SURV-04 | End-to-end browser flow | Submit a public survey — expect redirect to `/survey/{token}/confirmation` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
