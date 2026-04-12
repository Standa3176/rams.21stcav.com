---
phase: 07-dynamic-site-survey
plan: "06"
subsystem: controller+views
tags: [public-survey, completion-gate, pre-install-checks, ajax, wave-4]
dependency_graph:
  requires:
    - 07-02 (SiteSurveyRoom.questions() relationship)
    - 07-04 (SiteSurveyRoomQuestion answer field + answerQuestion endpoint)
  provides:
    - completeRoom() guard in PublicSurveyController — 422 before save logic
    - 422 amber blocked message in completeRoom() JS (show.blade.php)
  affects:
    - Engineers cannot mark a room complete until all pre-install check questions are answered
    - Rooms with zero questions are unaffected (D-06 preserved)
tech_stack:
  added: []
  patterns:
    - Server-side 422 gate before validatePublicSurvey() — client cannot bypass
    - Fetch status-aware pattern: response.json().then(data => ({ status, data }))
    - Amber palette (#FFFBEB/#FCD34D/#92400E) for blocked message — consistent with Pre-Install panel
    - role=alert on blocked message div for accessibility
    - .complete-blocked-msg CSS class for targeted querySelector lookup
key_files:
  created: []
  modified:
    - app/Http/Controllers/PublicSurveyController.php
    - resources/views/public-survey/show.blade.php
decisions:
  - "Guard inserted AFTER abort_if(isSubmitted) and BEFORE validatePublicSurvey() — correct position per plan spec"
  - "Fetch chain changed from r.ok check to status-aware pattern — JS fetch does not reject on 4xx, status must be read explicitly"
  - "Button re-enabled at top of .then() handler (before any branch) — ensures re-enable on both 422 and success paths"
  - "areaEl variable declared twice in success path (blocked check and clear check) — scoped separately in each if block to avoid const re-declaration error"
requirements-completed: []

# Metrics
duration: 30min
completed: 2026-04-12
tasks_completed: 2
tasks_total: 2
files_created: 0
files_modified: 2
---

# Phase 07 Plan 06: Completion Gate — 422 Guard and JS Blocked Handler Summary

**One-liner:** Server-side 422 gate in completeRoom() blocks room completion when pre-install check questions are unanswered, with amber blocked message in the public survey JS.

## What Was Built

**Task 1 — PublicSurveyController completeRoom() guard:**
- `app/Http/Controllers/PublicSurveyController.php`: Inserted D-05 guard block after `abort_if($survey->isSubmitted(), 403)` and before `$data = $this->validatePublicSurvey($request)`.
- Guard: `$room->questions()->whereNull('answer')->count()` — dynamic query (not cached relation).
- Returns `422 JSON { completed:false, blocked:true, message:"Please answer all N pre-install check question(s) before marking this room complete." }`.
- Singular: "1 pre-install check question" / Plural: "N pre-install check questions".
- Rooms with zero questions: count = 0, guard skipped, existing flow unaffected (D-06).
- All 4 `PublicSurveyControllerTest` tests GREEN.

**Task 2 — completeRoom() JS 422 handler in show.blade.php:**
- `resources/views/public-survey/show.blade.php`: Changed fetch chain from `r.ok ? r.json() : Promise.reject(r)` to status-aware pattern capturing `{ status, data }`.
- Button re-enabled at start of `.then()` handler (regardless of outcome).
- On 422 + `data.blocked`: creates/reuses `.complete-blocked-msg` div with amber palette styles and `role="alert"`, inserted before first child of `complete-area-{roomId}`, displays `data.message`. Returns early — no success UI runs.
- On success: clears any `.complete-blocked-msg` before updating header/badge/buttons/collapse.
- All existing success-path logic preserved unchanged.

## Test Results

- `PublicSurveyControllerTest`: 4 passed (all GREEN — was RED before this plan)
- Full suite: 316 passed, 6 failed (pre-existing Auth `MissingAppKeyException` failures — same as Plan 05, no regressions)

## Deviations from Plan

**1. [Rule 3 - Blocking] vendor/autoload.php and .env missing in worktree**
- **Found during:** Task 1 test run
- **Issue:** Worktree missing vendor/autoload.php (composer dump-autoload needed) and .env (APP_KEY missing).
- **Fix:** Ran `composer dump-autoload` then copied `.env` from main project.
- **Files modified:** None (infrastructure only)

## Known Stubs

None — guard uses live DB query (`$room->questions()->whereNull('answer')->count()`). JS handler displays server-provided message text. No hardcoded placeholders.

## Threat Flags

No new threat surface beyond the plan's threat model:
- T-07-06-01 (Tampering — client bypasses 422): Guard is server-side. Client JS change cannot bypass it.
- T-07-06-02 (Race condition): Accepted per plan — count checked at request time.
- T-07-06-03 (Spoofing — wrong room): `abort_unless($room->site_survey_id === $survey->id, 403)` remains before the guard.

## Commits

| Task | Hash    | Message |
|------|---------|---------|
| 1    | 39e6adc | feat(07-06): completeRoom() guard — 422 when unanswered pre-install questions |
| 2    | aad8fa8 | feat(07-06): completeRoom() JS — 422 blocked handler with amber message |

## Self-Check: PASSED

Files confirmed to exist:
- `app/Http/Controllers/PublicSurveyController.php` — FOUND (modified)
- `resources/views/public-survey/show.blade.php` — FOUND (modified)
- `.planning/phases/07-.../07-06-SUMMARY.md` — FOUND (this file)

Commits confirmed:
- 39e6adc — feat(07-06): completeRoom() guard
- aad8fa8 — feat(07-06): completeRoom() JS
