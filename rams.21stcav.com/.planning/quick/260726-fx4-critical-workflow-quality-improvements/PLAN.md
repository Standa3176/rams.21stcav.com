---
name: 260726-fx4-critical-workflow-quality-improvements
description: Ship the top-recommended fixes from the 2026-07-26 structural + content audit — as three bundled sub-plans (hf1 critical workflow, cd1 survey-context-into-prompts, cq1 om-scoping). 8 tasks, ~19h executor scope. Each task an atomic commit.
status: in-progress
tasks: 8
---

# Critical workflow + AI quality lift (audit action items)

## Why

The 2026-07-26 structural + content audit surfaced 25 findings. The top 6 (by PM impact × effort) collapse into 3 bundles worth shipping together:

**Bundle hf1 — critical workflow fixes** (4 tasks). Survey submits are silent to downstream doc staleness; cable schedule failures have no error message; worksheet sign-off doesn't notify office; the "Works: <first sentence>" fallback masquerades as AI output.

**Bundle cd1 — survey context into AI prompts** (2 tasks). MethodStatementPrompt + OmManualPrompt never see the engineer_feedback fields the survey wizard captures (mounting heights, wall construction, brackets, cable routes). That's the biggest single content-quality lever — the reason app-generated method statements can't match the Tilda-reference "Chief X-Large Fusion bracket / 3-person 98″ lift" specificity.

**Bundle cq1 — OM prompt scoping** (1 task). `OmManualPrompt::forContent` asks the model for 12 fields per equipment item; result is generic manufacturer marketing prose. Trim schema; let PMs enrich.

Total 7 code tasks + 1 docs task. Each independent + rollback-safe. All are code-only (1 tiny migration for cable schedule error_message).

## Global constraints

- `php -l` after every PHP edit.
- Existing `--filter "Docx|Rams|Worksheet|OmManual|Survey|CableSchedule|MethodStatement|RiskAssessment"` tests must stay green — or updated with justification.
- No new npm deps. No new composer packages.
- Commit prefixes: `feat(rams)` / `feat(survey)` / `feat(worksheet)` / `feat(cable)` / `feat(prompt)` / `fix(review)` / `docs(quick-…)` — as appropriate per task.
- Each task = 1 atomic commit. Do NOT batch.

## Bundle hf1 — critical workflow fixes

### Task 1 — Survey submit → downstream doc staleness

**Anchor:** `app/Core/Modules/Survey/SurveyService.php:515` (`submitPublic`).

**Files:**
- `app/Events/SurveySubmitted.php` — NEW. Simple event carrying the SiteSurvey model.
- `app/Core/Modules/Survey/SurveyService.php` — dispatch `event(new SurveySubmitted($survey))` at end of `submitPublic()`, right after the mailer stamp block (~line 594).
- `app/Models/Worksheet.php` (`isStale` at line 273-302), `app/Models/OmManual.php` (`isStale` at line 116-144), `app/Models/RamsDocument.php` (`isStale` at line 216-260) — extend to ALSO check `$this->project->latestSurvey?->submitted_at > $this->generated_at`. Preserve existing package-based checks.
- `app/Models/CableSchedule.php` — ADD `isStale()` method (currently missing entirely, per audit finding). Mirror the pattern from other models.
- Feature tests: assert isStale returns true after a survey submit stamps a submitted_at later than the doc's generated_at. Add 4 test cases (one per doc type).

**Non-goals:** do NOT auto-regenerate the docs. Just surface the stale banner. `_stale-banner.blade.php` already exists — will fire automatically once `isStale()` returns true.

**Commit:** `feat(survey): dispatch SurveySubmitted event + extend isStale on all 4 doc types (260726-fx4)`

### Task 2 — Cable schedule error_message

**Files:**
- Migration `database/migrations/2026_07_26_100000_add_error_message_to_cable_schedules.php` — `$table->text('error_message')->nullable()->after('status')`.
- `app/Models/CableSchedule.php` — add to `$fillable`.
- `app/Jobs/BuildCableScheduleJob.php` — in the `catch` block (~line 60-80), write `$cableSchedule->error_message = $exception->getMessage()` alongside `status=failed`. Remove the "no error_message column" comment at line 24-27.
- `resources/views/cable-schedule/*.blade.php` — surface `$cableSchedule->error_message` inline when status is failed. Add a `<details><summary>See why</summary>` pattern matching RAMS + Worksheet.
- Feature test: force a job exception, assert error_message is populated.

**Commit:** `feat(cable): add error_message column + surface in UI on failure (260726-fx4)`

### Task 3 — Public worksheet sign-off email

**Files:**
- Migration `database/migrations/2026_07_26_101000_add_signed_notification_sent_at_to_worksheets.php` — `$table->timestamp('signed_notification_sent_at')->nullable()`.
- `app/Models/Worksheet.php` — add to `$guarded` (NOT `$fillable` — per 260709 audit pattern for auth-tokened flags, prevent mass-assignment).
- `app/Mail/WorksheetSignedMail.php` — NEW. Mirror `SurveySubmittedMail` structure. View at `resources/views/emails/worksheet-signed.blade.php`.
- `app/Http/Controllers/PublicWorksheetController.php` — in `sign()` after the DB write (~line 340), resolve recipient via `NotificationRecipientResolver::resolveProjectRecipient($worksheet->project)`; if resolved, send `WorksheetSignedMail` + stamp `signed_notification_sent_at` via `forceFill()` (mirroring `SurveyService::submitPublic` line 589-594).
- UI badge in `resources/views/worksheets/show.blade.php` — green pill "Office notified {{ diffForHumans }}" when timestamp set, amber "Office not notified" when null.
- Feature test: sign as public engineer, assert mail was sent + timestamp stamped.

**Commit:** `feat(worksheet): office notification on public sign-off (260726-fx4)`

### Task 4 — Kill the "Works: <first sentence>" fallback masquerade

**Files:**
- `app/Services/RoomOverviewSummaryService.php` (line 93-111) — `fallbackSummary()` returns empty string. Add a `_summary_fallback = true` marker on the row (so downstream consumers can differentiate "AI didn't run" from "AI ran and returned empty").
- `app/Services/WorksheetGeneratorService.php` (line 130-166) — the caller's `starts_with('- ')` guard is fine but the empty fallback needs handling: if `$summary` is empty AND `$row['_summary_fallback']` is true, log at info level and mark the works_summary field with a flag rather than blanking it.
- `resources/views/project-packages/review.blade.php` — where per-room `works_summary` is rendered, if `_summary_fallback === true` OR the field is empty AND the room's overview is non-empty, show a "⚠ AI unavailable — click Generate to retry" badge next to the field instead of a silent blank.
- Unit test: assert `fallbackSummary()` returns `''` not `"Works: ..."`. Update any existing tests that asserted the old "Works:" prefix.

**Commit:** `fix(review): AI room-summary fallback stops masquerading as generated content (260726-fx4)`

## Bundle cd1 — Survey context into AI prompts

### Task 5 — Feed engineer_feedback into MethodStatementPrompt

**Anchor:** `app/Services/MethodStatementService.php:45-64` (context assembly).

**Files:**
- `app/Services/MethodStatementService.php` — extend `generate()` context to extract engineer_feedback per room. Build a `site_conditions` structure:
  ```php
  'site_conditions' => [
    'Oregano' => [
      'mounting_heights' => ['display' => 1900, 'occupancy_sensor' => 2800],
      'wall_construction' => 'Plasterboard on metal stud',
      'wall_needs_reinforcement' => true,
      'wall_needs_conduit' => false,
      'brackets_required' => [['type' => 'Chief tilting wall mount', 'notes' => '...']],
      'cable_routes' => 'floor void → riser → false ceiling',
      'table_info' => 'circular meeting table, boxed floor grommet',
      'floor_box_info' => '2× 4-way sockets in floor box',
      'access_notes' => 'ceiling grid 600×600, no asbestos flag',
    ],
    'Cinnamon' => [ ... ],
  ]
  ```
  Source: read from `$survey->rooms` (SiteSurveyRoom columns per the 260503 migration). Only populate keys with non-null/non-empty values.
- `app/Core/AI/Prompts/MethodStatementPrompt.php` — update `systemMessage()`: add a new rule "When site_conditions is provided for a room, cite the relevant conditions in the method step for that room (e.g. wall_construction → 'in the plasterboard partition wall'; brackets_required → name the specific bracket model). Do NOT invent conditions that aren't in the data." Update `build()` to embed the site_conditions block into the JSON context sent to the AI.
- Feature test: build a fixture with engineer_feedback populated, assert the AI prompt context contains the site_conditions block. Prompt unit test: assert the systemMessage phrases "site_conditions", "cite the relevant conditions", "Do NOT invent".

**Commit:** `feat(prompt): feed engineer_feedback (site_conditions) into MethodStatementPrompt (260726-fx4)`

### Task 6 — Feed engineer_feedback into OmManualPrompt

**Anchor:** `app/Core/Modules/OMManual/OmManualGeneratorService.php:285` (per-room description assembly).

**Files:**
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — same shape as Task 5 but for O&M. Extract engineer_feedback per room into a `site_conditions` block; pass to `OmManualPrompt::forContent`. Focus fields: `mounting_heights` (drives per-equipment install instructions), `wall_construction` + `brackets_required` (drives maintenance access notes), `access_notes` (drives ceiling-void / floor-box access sections).
- `app/Core/AI/Prompts/OmManualPrompt.php` — `forContent()` prompt update: same rule shape as Task 5, plus specifically instruct the model to use mounting_heights when writing installation notes ("Display mounted at 1900mm from finished floor level") and access_notes for maintenance procedures.
- Feature test: parallel to Task 5.

**Commit:** `feat(prompt): feed engineer_feedback (site_conditions) into OmManualPrompt (260726-fx4)`

## Bundle cq1 — OM prompt scoping

### Task 7 — Trim OmManualPrompt::forContent equipment schema

**Anchor:** `app/Core/AI/Prompts/OmManualPrompt.php` — `forContent()`.

Current per-equipment schema asks the model for 12+ fields: `installation`, `operation`, `maintenance_schedule`, `troubleshooting[{symptom,solution}]`, `key_specifications`, `support_contacts`, `warnings`, `installation_notes`, `daily_ops`, `weekly_ops`, `monthly_ops`, `annual_ops`. The 12-field ask forces the model to hallucinate specifics it doesn't have — support contacts get invented, daily/weekly/monthly ops become templated "wipe with microfiber cloth" filler.

**Files:**
- `app/Core/AI/Prompts/OmManualPrompt.php` — reduce per-equipment fields to **4 canonical ones**: `installation` (physical mounting + electrical + network), `operation` (day-to-day user actions), `maintenance` (schedule + tasks — combined from prior 5 fields), `warnings` (safety / limits / known-issues). Remove `troubleshooting`, `key_specifications`, `support_contacts`, `daily_ops` / `weekly_ops` / `monthly_ops` / `annual_ops`, `installation_notes`. Total collapse from 12 → 4 fields per item.
- `app/Services/OmManualDocxService.php` — update renderer to only emit the 4 kept fields per equipment section. Deleted-field code paths go entirely.
- Blade template `resources/views/pdf/om-manual/*.blade.php` — same trim.
- Unit test on the prompt: assert the systemMessage no longer mentions `troubleshooting`, `support_contacts`, `key_specifications`.

**Non-goal:** do NOT delete data from existing O&M records. Downstream renderer just stops emitting those fields going forward. Old O&M PDFs on disk are untouched.

**Commit:** `feat(prompt): trim OmManualPrompt per-equipment schema 12 → 4 fields to reduce hallucination (260726-fx4)`

## Task 8 — Docs + push

Standard closeout.

**Files:**
- `.planning/STATE.md` — new row above the `260725-rd1` row from earlier today.
- `.planning/quick/260726-fx4-critical-workflow-quality-improvements/SUMMARY.md`.
- Push to `live` + `origin`.

**Commit:** `docs(quick-260726-fx4): PLAN + SUMMARY + STATE for critical workflow + AI quality fixes`

## Explicit non-goals (deferred to backlog items #16-25)

- Cross-doc "current revision" concept for Worksheet/OmManual/CableSchedule
- Auto-regen on survey submit (this task only fires the STALE signal, not the regen)
- Per-token throttles on public routes
- Access-token expiry policy
- Retro-run `cables:reimport-shorttag-quotes --commit`
- Deleting the dead `SurveyPrompt` class
- MethodStatementPrompt cache hash extension
- Sentinel-wrap gaps on 4 prompts
- `office_review_notes` flowing to downstream generators

All the above are individually small — treat them as follow-up quick tasks after 260726-fx4 lands.

## Deploy

**One migration** (Task 2 + Task 3):
```bash
sudo -u stcav bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

No npm build. No `.env` changes.

## Sanity checks after deploy

1. **Bundle hf1**: submit a public survey → open any generated doc for that project → stale banner should fire.
2. **Bundle hf1**: force-fail a cable schedule (e.g. delete equipment mid-generation) → UI shows the actual error message.
3. **Bundle hf1**: sign a public worksheet → office receives email.
4. **Bundle hf1**: without AI credits, view a project's review page → no "Works: <first sentence>" text; instead "⚠ AI unavailable — click Generate to retry" badge on empty room summaries.
5. **Bundle cd1**: regenerate the Tilda RAMS with survey data populated → §6 Method of Works should now reference specific mounting heights + wall construction + bracket models from the survey.
6. **Bundle cq1**: regenerate any O&M → per-equipment sections trimmed to 4 fields; no more invented support phone numbers or generic "wipe with microfiber cloth quarterly" filler.
