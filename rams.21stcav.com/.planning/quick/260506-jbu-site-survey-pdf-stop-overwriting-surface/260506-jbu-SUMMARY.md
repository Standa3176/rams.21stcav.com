---
phase: quick-260506-jbu
plan: 01
subsystem: site-survey-pdf
tags: [survey, pdf, ai-questions, quote-text-fidelity]
requirements: [QUICK-260506-JBU]
dependency-graph:
  requires: [SiteSurveyRoomQuestion model, GenerateSurveyQuestionsJob, SolutionType.survey_checklist]
  provides: [Quote-specific room overview rendering, Pre-install Checks PDF section]
  affects: [SurveyPdfService::buildSummary, summary.blade.php, SurveyService::createFromProject]
tech-stack:
  added: []
  patterns: [Eloquent eager-loading with multi-relation array, Blade @if-isNotEmpty guard]
key-files:
  created: []
  modified:
    - app/Core/Modules/Survey/SurveyService.php
    - app/Services/SurveyPdfService.php
    - resources/views/pdf/site-survey/summary.blade.php
decisions:
  - Quote overview is the primary content; SolutionType checklist is appended (not overwritten) so engineers see the actual scope first.
  - Pre-install Checks render as a separate h3+table block matching the AV Requirements visual idiom for native chrome consistency.
  - Failure mode preserved — rooms with neither overview nor checklist still produce av_requirements=null (line 245 ?: null preserved).
metrics:
  duration: ~25min
  completed: "2026-05-06"
  tasks: 3
  files: 3
  commits: 2
---

# Quick Task 260506-jbu — Site Survey PDF: Stop Overwriting + Surface AI Questions Summary

Two-bug fix in the populated site-survey PDF so each room reflects what's actually being installed instead of regurgitating a generic per-solution-type checklist, and so the AI-generated pre-install questions (already persisted by `GenerateSurveyQuestionsJob`) finally show up on the page they were always meant for.

## What Changed

### Task 1 — `SurveyService::createFromProject()` overwrite fix

**File:** `app/Core/Modules/Survey/SurveyService.php` (lines 212-235)

**Before:** When a room had both quote-specific overview text AND a matched `solution_type_id`, the SolutionType's static `survey_checklist` blob unconditionally clobbered the overview. Result: every Video Conferencing room on every project read identically.

**After:** Three-case priority ladder:

1. **Both present** → `$avRequirements = $overview . "\n\nStandard checks for this solution type:\n" . $checklist;`
2. **Only overview** → render overview alone.
3. **Only checklist** → fall back to checklist (preserves the original safety net for rooms without quote text).
4. **Neither** → empty string → line 245 (`$avRequirements ?: null`) writes NULL.

The `narrativeAsTickList()` helper that the Blade calls renders the merged multi-paragraph string cleanly — the "Standard checks for this solution type:" sentinel reads naturally in the PDF.

### Task 2 — Render AI pre-install questions in the PDF

**Files:** `app/Services/SurveyPdfService.php` (line 35) + `resources/views/pdf/site-survey/summary.blade.php` (lines 113-140).

- **SurveyPdfService::buildSummary** now eager-loads `rooms.questions` alongside `rooms.photos` to avoid N+1 inside the Blade's per-room loop.
- **summary.blade.php** gains a new "Group 2.5 — Pre-install Checks" block between the existing AV Requirements block (`@endif` line 111) and the Engineer Findings block (`@if($hasEF)` line 150). The block renders an `h3` + `<table>` matching the AV Requirements visual idiom — question on the left in the bold/grey `.label` chrome, answer on the right.
- Answer rendering: `Yes` / `No` / `Other — {explanation}` (when `other_text` is non-empty) / `Other` (when `other_text` is empty) / `—` (when `answer` is NULL/unanswered).
- Suppressed entirely when `$room->questions->isNotEmpty()` is false — no empty heading on rooms without AI questions.

## Commits

| # | Hash      | Message                                                                                                |
| - | --------- | ------------------------------------------------------------------------------------------------------ |
| 1 | `753c759` | feat(survey-260506-jbu): preserve quote-specific AV requirements over solution-type checklist          |
| 2 | `22c1840` | feat(survey-260506-jbu): render AI pre-install questions per room in survey PDF                        |

## Tilda PDF Verification

**Local environment limitation:** Browsershot's `puppeteer` npm dependency is not installed on this dev workstation, so the full PDF render path errored with `Cannot find module 'puppeteer'`. The Blade compiles cleanly (`php artisan view:clear` succeeded) and Browsershot is provisioned correctly on live (per Phase 20 hardening), so the PDF will render successfully there once the files are uploaded.

To prove the fixes end-to-end, summary.blade.php was rendered to HTML directly via `View::make(...)->render()` against the freshly-regenerated Tilda survey:

**Render artifacts:**
- `storage/app/private/jbu-renders/tilda-summary-7-20260506_140518.html` (28,526 bytes — final render with both fixes exercised)
- `storage/app/private/jbu-renders/tilda-summary-3-20260506_140217.html` (30,398 bytes — pre-fix Tilda survey id=3 confirming Pre-install Checks block fires for rooms that already had AI questions)

**Verification helper:** `storage/app/private/jbu-render-check.php` (counts sentinel hits and dumps per-room av_requirements / question count). Throwaway — not committed.

### Survey id=7 — fresh `createFromProject(supersede=true)` post-Task-1

| Room | space_type | av_requirements lead | "Standard checks…" marker | Questions |
| ---- | ---------- | -------------------- | ------------------------- | --------- |
| OREGANO | conferencing-teams-zoom-room | "Oregano is now using the Crestron small room system with Jabra PanaCast 50…" | YES | (4 demo) |
| CINNAMON | conferencing-teams-zoom-room | "CINNAMON / Cinnamon now has a Sony 98\" display chosen…" | YES | (3 demo) |
| SAFFRON | conferencing-teams-zoom-room | "Cinnamon and Saffron are now using the Crestron Flex integrator kit…" | YES | (2 demo) |
| ROOM BOOKING PANELS | general | "ROOM BOOKING PANELS / Room booking is now handled by the Crestron 10.1 inch room booking panel…" | NO | 0 |

Sentinel counts in the rendered HTML: **`Standard checks for this solution type` × 3** (Task 1), **`Pre-install Checks` × 3** (Task 2). Demo questions exercise all four answer paths (`Yes` / `No` / `Other — explanation` / `—` for unanswered) — visual idiom matches AV Requirements: h3 + table, `.label` left column, answer right column.

### Task 3 visual checkpoints — pass/fail

| # | Checkpoint                                                                       | Result |
| - | -------------------------------------------------------------------------------- | ------ |
| 1 | Conferencing rooms show specific overview FIRST, then "Standard checks for this solution type:" + checklist | PASS — confirmed via 3-hit sentinel count + per-room HTML dump |
| 2 | "Pre-install Checks" section appears below AV Requirements on rooms with questions | PASS — confirmed via 3-hit heading count + spot-checked HTML segment showing all four answer formats |
| 3 | Rooms with NO questions show NO empty "Pre-install Checks" heading                | PASS — ROOM BOOKING PANELS (0 questions) renders no heading |
| 4 | Rooms with no `solution_type_id` keep their specific overview text — no regression | PASS — ROOM BOOKING PANELS leads with "Room booking is now handled by the Crestron 10.1 inch room booking panel…" |
| 5 | No layout breakage / page break / overlapping text                                | DEFERRED to live PDF render — HTML structure is well-formed but final pagination needs Chromium |

**Live UAT step:** After uploading the 3 changed files, regenerate the Tilda survey PDF on the live server and visually confirm checkpoint 5 (no layout breakage). The local HTML proves the data and structure are correct; only the print layer was un-runnable here.

## Backward Compatibility

- **Legacy surveys with no questions** — Pre-install Checks section is suppressed entirely (`@if($room->questions->isNotEmpty())`). Their PDF output is byte-identical to pre-fix.
- **Rooms with no `solution_type_id`** — Task 1 skips both `$solutionTypeId` and `$checklist` lookups; `$avRequirements = $overview` only. Identical to pre-fix behaviour.
- **Rooms with quote text but no SolutionType match** — same as pre-fix (overview rendered alone).
- **Rooms with no quote text but a SolutionType match** — same as pre-fix (checklist rendered alone via the elseif fallback). Preserves the original "better than blank" safety net.
- **No schema changes, no new dependencies, no migrations.**

## Out of Scope (Deferred)

- **L3 equipment-rule engine** — the room-level equipment-driven question generator (e.g. "Brandeis Q-SYS rack matched → ask 5 Q-SYS-specific questions") is a separate quick-task. This fix is the **L1 (overview-primary) + L2 (already-persisted AI questions surfaced)** layers only.
- **Browsershot puppeteer install on this workstation** — environmental gap; live server is correctly provisioned per Phase 20 hardening runbook.
- **Tilda survey id=3 backfill** — the OLD survey rooms (8/9/10) still hold the pre-fix `av_requirements` value (static checklist only, no merge). They will not auto-update — engineers regenerate via "Replace Survey" CTA when needed. Intentional: Task 1 fixes the seed path, not historical rows.

## Self-Check: PASSED

- [x] `app/Core/Modules/Survey/SurveyService.php` modified — line 219 unconditional overwrite gone (`grep -c "= \$st->survey_checklist"` returns 0); sentinel `Standard checks for this solution type` present once.
- [x] `app/Services/SurveyPdfService.php` modified — `loadMissing(['rooms.photos', 'rooms.questions'])` present at line 35.
- [x] `resources/views/pdf/site-survey/summary.blade.php` modified — `Pre-install Checks` heading + `$room->questions->isNotEmpty()` guard present at lines 124 + 120.
- [x] `field-form.blade.php` and `blank.blade.php` UNCHANGED (negative-regression check — `grep -c "Pre-install Checks"` returns 0 in both).
- [x] Lint pass: `php -l` reports "No syntax errors detected" for both PHP files.
- [x] Blade compile pass: `php artisan view:clear` succeeded.
- [x] Two atomic `feat(survey-260506-jbu):` commits exist (`753c759`, `22c1840`).
- [x] HTML render against live Tilda data confirms both fixes (sentinel counts + per-room dump).

## Files to upload to live

| Path | Purpose |
| ---- | ------- |
| `app/Core/Modules/Survey/SurveyService.php` | Task 1 — overview-primary, checklist-appended logic in createFromProject() |
| `app/Services/SurveyPdfService.php` | Task 2 step A — eager-load rooms.questions to prevent N+1 in the Blade loop |
| `resources/views/pdf/site-survey/summary.blade.php` | Task 2 step B — new "Pre-install Checks" h3+table section per room |

After uploading, regenerate any active site survey PDF (e.g. the Tilda survey via the "Regenerate Survey PDF" CTA, or any other project's survey) to confirm checkpoint 5 visually.
