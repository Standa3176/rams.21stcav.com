---
phase: 7
slug: 07-dynamic-site-survey
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-12
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=SurveyQuestion` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=SurveyQuestion`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 7-01-01 | 01 | 0 | D-04 | — | N/A | unit | `php artisan test --filter=SurveyRoomQuestionModelTest` | ❌ W0 | ⬜ pending |
| 7-01-02 | 01 | 1 | D-04 | — | N/A | migration | `php artisan migrate --pretend` | ✅ | ⬜ pending |
| 7-02-01 | 02 | 0 | D-13/D-14 | — | N/A | unit | `php artisan test --filter=SurveyQuestionsPromptTest` | ❌ W0 | ⬜ pending |
| 7-02-02 | 02 | 2 | D-10/D-11 | — | Silent failure, no data leak | feature | `php artisan test --filter=GenerateSurveyQuestionsJobTest` | ❌ W0 | ⬜ pending |
| 7-03-01 | 03 | 3 | D-07/D-08/D-09 | — | N/A | browser | Manual — render check | ✅ | ⬜ pending |
| 7-03-02 | 03 | 3 | D-03 | — | N/A | feature | `php artisan test --filter=PublicSurveyQuestionAnswerTest` | ❌ W0 | ⬜ pending |
| 7-04-01 | 04 | 4 | D-05/D-06 | — | Unanswered questions block completion | feature | `php artisan test --filter=PublicSurveyControllerTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Models/SurveyRoomQuestionModelTest.php` — stubs for D-04 (model relationships, casts, fillable)
- [ ] `tests/Unit/Prompts/SurveyQuestionsPromptTest.php` — stubs for D-13 (prompt builds correct JSON structure)
- [ ] `tests/Feature/Jobs/GenerateSurveyQuestionsJobTest.php` — stubs for D-10/D-11 (dispatch on survey creation, silent failure)
- [ ] `tests/Feature/PublicSurveyQuestionAnswerTest.php` — stubs for D-03 (answer save endpoint: yes/no/other+text)
- [ ] `tests/Feature/PublicSurveyControllerTest.php` — extend with completion gate (D-05: blocked when unanswered, D-06: unaffected with no questions)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Collapsible panel renders in room card | D-07 | UI/visual check | Load internal survey form for a room with questions; verify panel is present and collapses/expands |
| Panel absent when no questions yet | D-08 | UI/visual check | Load room card immediately after survey creation (before job runs); verify no questions section rendered |
| Public form renders identically | D-09 | UI/visual check | Open public survey via token; verify "Pre-Install Checks" panel matches internal form |
| "Other" reveals text field | D-03 | Alpine.js interaction | Select "Other" on a question; verify text explanation field appears below |
| Questions dispatched per room on createFromProject | D-10 | Integration smoke | Create survey from project with 3 rooms; verify 3 jobs queued (Queue::fake() in test) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
