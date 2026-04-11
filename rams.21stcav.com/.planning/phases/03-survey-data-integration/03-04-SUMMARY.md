---
phase: 03-survey-data-integration
plan: 04
subsystem: survey
tags: [public-survey, site-conditions, global-fields, confirmation-page]
dependency_graph:
  requires: [03-01, 03-03]
  provides: [global-fields-ui, confirmation-flow]
  affects: [SurveyService, PublicSurveyController, public-survey/show, site-survey/show]
tech_stack:
  added: []
  patterns: [amber-section-block, syncHeaderFields-js-sync, confirmation-redirect]
key_files:
  created:
    - resources/views/public-survey/confirmation.blade.php
  modified:
    - app/Core/Modules/Survey/SurveyService.php
    - app/Http/Controllers/PublicSurveyController.php
    - routes/web.php
    - resources/views/public-survey/show.blade.php
    - resources/views/site-survey/show.blade.php
decisions:
  - "Global fields (site_risks, access_constraints, h_and_s_notes) placed in a top-level amber survey-section--conditions block between survey details card and rooms, not inside individual room cards"
  - "syncHeaderFields() refactored from three explicit getElementById calls to a forEach loop over field names array — cleaner and less error-prone as field list grows"
  - "confirmation.blade.php uses self-contained CSS matching show.blade.php header/card pattern — no shared layout dependency"
  - "Added .text-muted { color:var(--text-muted) } utility class to site-survey/show.blade.php styles block to satisfy no-inline-style requirement for Not provided fallback"
metrics:
  duration_minutes: ~25
  completed_date: "2026-04-10"
  tasks_completed: 2
  files_changed: 6
requirements: [SURV-02, SURV-03, SURV-04, SURV-05]
---

# Phase 03 Plan 04: Global Survey Fields — Public Form, Confirmation, Internal Show

**One-liner:** Three global site condition fields wired end-to-end: public form amber section, JS sync, service persistence, confirmation redirect, and internal read-only display.

## What Was Built

Plan 03 added the DB columns. This plan wires those columns through every surface the engineer and project manager interact with.

### Task 1 — Service + Controller + Route + Confirmation Page

**SurveyService** (`saveDraftPublic` and `submitPublic`): Both update payloads extended with `site_risks`, `access_constraints`, and `h_and_s_notes` using the existing `?? $survey->field` fallback pattern.

**PublicSurveyController**:
- `validatePublicSurvey()` — three new `nullable|string|max:3000` rules added after `general_notes`, matching the existing pattern and threat model mitigation T-03-08.
- `submit()` — redirect changed from `survey.show` (with flash) to `survey.confirmation` (no flash needed — the page is the success message).
- `confirmation()` method added — resolves survey by token, returns `public-survey.confirmation` view.

**routes/web.php**: `GET survey/{token}/confirmation` registered before `survey/{token}` to avoid route shadowing. Named `survey.confirmation`.

**confirmation.blade.php**: Self-contained HTML (no `@extends`), matching the show.blade.php header bar pattern. Contains "Survey Submitted" heading, thank-you body copy, project/site meta block, and link back to submitted survey.

### Task 2 — Public Form View + Internal Show View

**public-survey/show.blade.php**:
- New amber `survey-section survey-section--conditions` block inserted between survey details card and rooms list. Shows three textareas in editable mode, pre-populated via `old()` / `$survey->field`. Hidden in readonly mode with display of saved values.
- Three `hf_*` hidden inputs added to `survey-form` so values are captured in Save Draft POST.
- `syncHeaderFields()` refactored to loop over `['survey_date','surveyor_name','general_notes','site_risks','access_constraints','h_and_s_notes']` — both the main sync and the `completeRoom` AJAX inline copy updated.

**site-survey/show.blade.php**:
- `.text-muted` utility class added to `@push('styles')` block: `color:var(--text-muted); font-style:italic`.
- New `section-block` titled "Site Conditions" inserted after Survey Details block, guarded by `@if` so it is hidden when all three fields are null (pre-Phase-3 surveys display cleanly).
- Three `meta-label`/`meta-value` pairs using `{!! e($value) !!}` for content, `<span class="text-muted">Not provided</span>` for null fallback. No inline `style="color:..."` attributes.

## Acceptance Criteria Check

| Criterion | Status |
|-----------|--------|
| SurveyService saveDraftPublic contains site_risks | PASS |
| SurveyService submitPublic contains site_risks | PASS |
| validatePublicSurvey contains site_risks | PASS |
| validatePublicSurvey contains access_constraints | PASS |
| validatePublicSurvey contains h_and_s_notes | PASS |
| confirmation() method exists | PASS |
| submit() redirects to survey.confirmation | PASS |
| routes/web.php contains survey.confirmation | PASS |
| confirmation.blade.php exists with "Survey Submitted" | PASS |
| confirmation.blade.php has thank-you body copy | PASS |
| public show has site_risks textarea | PASS |
| public show has access_constraints textarea | PASS |
| public show has h_and_s_notes textarea | PASS |
| public show has survey-section--conditions block | PASS |
| syncHeaderFields includes all three new fields | PASS |
| hidden input hf_site_risks exists | PASS |
| internal show has "Site Conditions" section | PASS |
| internal show references site_risks | PASS |
| internal show has "Not provided" fallback | PASS |
| internal show has NO inline color style | PASS |

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as written with two minor implementation adaptations:

**1. [Adaptation] syncHeaderFields() refactored to loop**
- The plan showed the function as three explicit getElementById calls.
- Implemented as a `forEach` loop over the field names array (same array pattern already used in `completeRoom`).
- Outcome identical, cleaner code.

**2. [Adaptation] .text-muted utility class added to site-survey/show.blade.php styles**
- The plan required a CSS class for the "Not provided" muted text.
- No `.text-muted` utility class existed in the codebase (only `var(--text-muted)` in CSS rules).
- Added `.text-muted { color:var(--text-muted); font-style:italic; }` to the file's `@push('styles')` block.
- This satisfies the "no inline style attribute" constraint without introducing a global utility class.

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| Task 1 | d6f8725 | feat(03-04): wire global survey fields through public form, validation, service methods, and confirmation page |
| Task 2 | 52bc3dd | feat(03-04): display global survey fields on internal site survey show view |

## Known Stubs

None — all three fields are wired from DB through service through controller through view. No placeholder data.

## Self-Check: PASSED

- `resources/views/public-survey/confirmation.blade.php` — exists (created in Task 1 commit d6f8725)
- `resources/views/public-survey/show.blade.php` — modified (Task 1 commit d6f8725)
- `resources/views/site-survey/show.blade.php` — modified (Task 2 commit 52bc3dd)
- All 20 acceptance criteria verified via static grep checks — all PASS
