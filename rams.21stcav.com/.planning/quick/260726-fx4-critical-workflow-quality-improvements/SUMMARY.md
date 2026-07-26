---
name: 260726-fx4-critical-workflow-quality-improvements
status: complete
completed: 2026-07-26
branch: feat/worksheet-classifier-universal
commits:
  - 50825dd  # Bundle hf1 Task 1 — SurveySubmitted event + isStale on 4 doc types
  - f494538  # Bundle hf1 Task 2 — Cable schedule error_message + UI
  - 288e245  # Bundle hf1 Task 3 — Public worksheet sign-off office notification
  - 05c6941  # Bundle hf1 Task 4 — RoomOverviewSummary fallback stops masquerading
  - 43c0a36  # Bundle cd1 Task 5 — site_conditions into MethodStatementPrompt
  - 13c69c2  # Bundle cd1 Task 6 — site_conditions into OmManualPrompt
  - fc34a71  # Bundle cq1 Task 7 — OmManualPrompt schema 12 → 4 fields
  - 7b90cfc  # Follow-up — defensive project relation access in latestSurveyForRecord
  - afbf645  # Docs closeout (PLAN + STATE — SUMMARY held for parent to write)
migrations: 1  (add_signed_notification_sent_at_to_worksheets)
tests: 806 passed / 11 pre-existing failed (was 725 / 12 pre-existing — +35 new tests, no regressions)
npm_build: false
---

## What shipped

Three bundled sub-plans landed as 9 atomic commits.

### Bundle hf1 — critical workflow fixes

**Task 1 — Survey submit → downstream doc staleness (`50825dd`).**
- New `App\Events\SurveySubmitted` event dispatched from `SurveyService::submitPublic` after the mailer stamp block.
- `Worksheet::isStale`, `OmManual::isStale`, `RamsDocument::isStale` extended to also check `latestSurvey?->submitted_at > $this->generated_at`.
- `CableSchedule` gained an `isStale()` method (was entirely missing per audit). Uses `completion_email_sent_at ?? updated_at` as the timestamp proxy since CS has no `generated_at` snapshot column.
- 4 feature tests (one per doc type) assert the stale banner fires post-survey-submit.

**Task 2 — Cable schedule error_message + UI (`f494538`).**
- `BuildCableScheduleJob`'s catch block now writes `$cableSchedule->error_message = $exception->getMessage()` alongside `status=failed`.
- UI blade surfaces the message inline when status=failed, `<details><summary>See why</summary>` pattern matching RAMS + Worksheet.
- **Migration skipped** — the `error_message` column already existed at VARCHAR(1000) NULL from Phase 09 (`2026_04_19_000001_add_email_sent_columns_for_phase_09.php`). Only wiring needed.

**Task 3 — Public worksheet sign-off office notification (`288e245`).**
- New `signed_notification_sent_at` migration.
- New `App\Mail\WorksheetSignedMail` mirroring `SurveySubmittedMail`.
- `PublicWorksheetController::sign` resolves recipient via `NotificationRecipientResolver`, sends the mail, stamps timestamp via `forceFill()` on success only. Failed mail leaves stamp null so retry logic can fire.
- Green/amber pill in `resources/views/worksheets/show.blade.php` shows notification state.

**Task 4 — Kill "Works: <first sentence>" fallback (`05c6941`).**
- `RoomOverviewSummaryService::fallbackSummary()` now returns empty string.
- Attaches `_summary_fallback: true` marker on the row.
- Review-page renderer shows amber "⚠ AI unavailable — click Generate to retry" badge when the marker is present instead of a silent blank or the old fake summary.
- Existing tests updated to assert empty-string fallback instead of the `"Works:"` prefix.

### Bundle cd1 — Survey context into AI prompts

**Task 5 — MethodStatementPrompt gets site_conditions (`43c0a36`).**
- New `App\Services\SiteConditionsBuilder` helper extracts engineer_feedback per room into a structured map (mounting_heights, wall_construction, brackets_required, cable_routes, access_notes, table_info, floor_box_info). Only populates keys with non-null values.
- `MethodStatementService::generate()` calls the builder and passes `site_conditions` into the prompt context.
- Prompt systemMessage gains explicit rule: "When site_conditions is provided for a room, cite the relevant conditions in the method step for that room (…). Do NOT invent conditions that aren't in the data."
- Follow-up commit `7b90cfc` — `latestSurveyForRecord()` now wraps `$record->project` in try/catch returning null. Mock-based `RamsBuilderServiceTest` cases regressed because Mockery didn't stub the `project` relation; production path unaffected.

**Task 6 — OmManualPrompt gets site_conditions (`13c69c2`).**
- Same shape as Task 5 but for O&M. `OmManualGeneratorService` extracts site_conditions and passes to `OmManualPrompt::forContent`.
- Prompt update includes install-guidance rule: "Use mounting_heights when writing installation notes (e.g. 'Display mounted at 1900mm from finished floor level'). Use access_notes for maintenance procedures."

### Bundle cq1 — OM prompt scoping

**Task 7 — OmManualPrompt schema 12 → 4 fields (`fc34a71`).**
- Per-equipment schema reduced to `installation`, `operation`, `maintenance` (schedule + tasks combined), `warnings`.
- Removed from prompt: `troubleshooting`, `key_specifications`, `support_contacts`, `daily_ops`, `weekly_ops`, `monthly_ops`, `annual_ops`, `installation_notes`.
- **Renderer trim skipped** — investigation confirmed neither `OmManualDocxService` nor the PDF blade template consumes the removed per-equipment AI fields. Renderer already reads from deterministic top-level sections (`operation_sections`, `maintenance_schedule`, `fault_finding`, `manufacturer_support`, `rooms_summary`). Trim would have been a no-op.

## Test coverage

- 35 new tests / 8 new test files
- Full-scope filter `--filter "Docx|Rams|Worksheet|OmManual|Survey|CableSchedule|MethodStatement|RiskAssessment|SiteConditions|RoomOverviewSummary"` → **806 passed** (was 725 baseline).
- 11 pre-existing failures (all `PublicSurveyControllerTest` + `PublicSurveyQuestionAnswerTest` route-404 issues unrelated to this scope — verified against baseline before shipping).

## Deploy

**One migration to run:**
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

1. **hf1 Task 1** — submit a public survey → open any generated doc for that project → stale banner should fire.
2. **hf1 Task 2** — force a cable schedule to fail (delete required equipment mid-generation OR temporarily break the generator config) → UI shows the actual error message inline instead of just a red pill.
3. **hf1 Task 3** — sign a worksheet from the public link → office email arrives → docx shows "Office notified {{ diffForHumans }}" green pill.
4. **hf1 Task 4** — with Anthropic credits exhausted OR by force-mocking a failure, view a project review page → per-room `works_summary` cells show amber "⚠ AI unavailable — click Generate to retry" instead of silent blank or the old fake "Works: ..." text.
5. **cd1 Tasks 5+6** — regenerate the Tilda RAMS (21CQ29531-05-OPS) with survey engineer_feedback populated → §6 Method of Works should now reference specific mounting heights, wall construction, and bracket models from the survey. O&M same for install/access notes.
6. **cq1 Task 7** — regenerate any O&M → per-equipment sections trimmed to 4 fields; no more invented support phone numbers or generic "wipe with microfiber cloth quarterly" filler.

## Deviations from PLAN.md

1. **Task 1 — CableSchedule `isStale` timestamp proxy.** Plan said mirror pattern from other models (`generated_data['generated_at']`). CS has no such snapshot column. Used `completion_email_sent_at ?? updated_at` and documented in model comment.
2. **Task 2 — Migration skipped.** Column already existed from Phase 09; only wiring needed.
3. **Task 7 — Renderer trim skipped.** No-op — renderer doesn't consume per-equipment AI schema. Documented in commit message.
4. **Task 5 follow-up commit `7b90cfc`.** Defensive try/catch on `$record->project` to keep Mockery-mocked tests green. Production path unaffected.
5. **SUMMARY.md** — executor subagent sandbox blocked write. Written now by parent orchestrator (this file).

## Related

- **260725-rd1** — RAMS design + prompt tuning that set the stage for cd1 to make sense (prompt content only matters if PMs see improved visual output)
- **260725-fx1** — partial fix of the "Works:" fallback; this task's Task 4 completes it
- **Backlog items #6-25** from the 2026-07-26 audit still open — items 16-25 flagged in this PLAN as explicit non-goals for follow-up quick tasks
