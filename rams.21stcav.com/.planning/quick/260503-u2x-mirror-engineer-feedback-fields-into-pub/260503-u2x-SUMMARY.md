---
phase: quick-260503-u2x
plan: 01
subsystem: site-survey
tags: [site-survey, engineer-feedback, public-wizard, alpine, blade, schema-additive]
requires:
  - "site_surveys.comms_room_access_status (260503-rgg)"
  - "site_survey_rooms.mounting_heights (260503-rgg)"
provides:
  - "Public wizard /survey/{token}: 5-field 'Site Logistics' card on rooms-list screen"
  - "Public wizard Step 4: 7 engineer-feedback sub-sections (mounting heights, WAH methods, cable routes, wall construction & prep, table info, floor box info, brackets)"
  - "Canonical survey_data shape: engineer_feedback_site (root) + engineer_feedback (per-room) blocks"
  - "stepSave step=0 site-level + step=4 per-room mirror to SiteSurvey/SiteSurveyRoom DB columns"
  - "completeRoom() canonical-JSON → DB-column second-mirror"
  - "save() + submit() defensive site-level $survey->update double-write"
affects:
  - "Public wizard surface — most engineers use this on tablets, not the internal admin form"
tech-stack:
  added: []
  patterns: ["Alpine x-data deep $watch for nested object autosave", "column-wins rehydration in buildAlpineRooms", "stepSave step=0 sentinel routing for site-level saves", "PublicSurveyController → SurveyController via app() to share writeEngineerFeedbackToColumns"]
key-files:
  created: []
  modified:
    - "app/Http/Controllers/SurveyController.php"
    - "app/Http/Controllers/PublicSurveyController.php"
    - "resources/views/surveys/show.blade.php"
decisions:
  - "Step 0 sentinel for site-level saves over a new dedicated route — keeps the controller surface tight, no new route registration, validation rule range widened from 1..8 to 0..8"
  - "Step 4 absorption over a new Step 4b — preserves the 8-step wizard contract, every existing 'Step X of 8' string + the validateStep switch + the buildStepPayload switch"
  - "Always-show Step 4 sub-sections regardless of work_type — engineer feedback applies to all rooms, not just new_install"
  - "Mirror to DB columns at every stepSave (not just completeRoom) — enables RAMS regen mid-survey if the office wants to preview the document while the engineer is still on site"
  - "completeRoom() is the SECOND mirror, not a replacement — both paths writing the same data is idempotent and safe; second mirror is the safety net for any stepSave race"
  - "Defensive double-write in save()/submit() because SurveyService::saveDraftPublic + submitPublic do NOT touch the new site-level columns (only handle the legacy 6 header fields)"
  - "Column wins over canonical JSON in buildAlpineRooms — column is the post-completion source of truth (set on submit + on each stepSave), canonical is in-flight fallback"
metrics:
  duration: "~25 min"
  tasks: 2
  files_modified: 3
  commits: 2
  insertions: 1131
  deletions: 11
  completed: 2026-05-03
---

# Quick Task 260503-u2x: Mirror Engineer-Feedback Fields into Public Engineer Survey Wizard Summary

Closes the most important data-capture gap on the platform: the on-site engineer-facing tablet view at `/survey/{token}` now captures the 17 engineer-feedback fields that 260503-rgg added to the internal admin form and 260503-tfb wired into the RAMS generator. Pure additive UI + canonical-shape extension + DB-column mirror plumbing across 3 files; the chain from on-site capture → DB columns → RAMS auto-classification is now end-to-end functional through the engineer's actual workflow (the public wizard URL).

## Commits

| # | Hash | Message |
|---|------|---------|
| 1 | `a8496d7` | feat(quick-260503-u2x-01): wire engineer-feedback into public survey wizard UI + canonical save |
| 2 | `bf40034` | feat(quick-260503-u2x-02): wire engineer-feedback into public legacy save endpoints + completeRoom DB-column mirror |

## What Shipped

### Task 1 — wizard UI + canonical save (commit `a8496d7`)

**SurveyController.php (the canonical shape extension):**
- `emptyCanonicalRoom()` seeds `engineer_feedback` canonical block per room (10 nested keys mirroring the SiteSurveyRoom DB columns)
- `initialPayload()` seeds `engineer_feedback_site` canonical block on payload root (7 site-level fields)
- `show()` now seeds Alpine state from SiteSurvey DB columns (`engineerFeedbackSite` view variable). Column wins over canonical JSON (column is the post-completion truth, canonical is the in-flight fallback)
- `buildAlpineRooms()` rehydrates `engineer_feedback` per room from DB columns. Deep merge on `mounting_heights` so a partial column save doesn't blank in-flight unsaved keys
- `enforceCanonicalShape()` now preserves `engineer_feedback` via the new `normalizeEngineerFeedback()` helper — strips unknown keys, enum-guards multi-selects (`work_at_height_methods`, `wall_construction`, cable-route `category`), normalizes booleans, drops empty multi-row entries
- `validateStep()` accepts new step=0 (site-level) + step=4 `engineer_feedback` presence check
- `stepSave()` rule range widened to `between:0,8`; step=0 routes to a site-level handler that writes `survey_data` + mirrors to `SiteSurvey` DB columns; step=4 mirrors per-room `engineer_feedback` to `SiteSurveyRoom` DB columns via the new public `writeEngineerFeedbackToColumns()` helper (called per save, not per keystroke — the autosave debounce already smooths this)
- 11 new private normalizers + 2 mirror helpers (one public — `writeEngineerFeedbackToColumns` — so PublicSurveyController can resolve via `app()` without widening its dependency surface)
- 3 "is*Empty" helpers prevent storing meaningless ghost objects (e.g. `{has_grommets:false, grommet_count:null,...}`) — column stays NULL when nothing was touched

**resources/views/surveys/show.blade.php (the wizard UI extension):**
- 🏢 **Site Logistics** card on rooms-list screen (after summary card, before readonly notice): `select[comms_room_access_status]` + `number[distance_from_base_miles]` + 5 textareas (`comms_room_access_notes`, `parking_restraints`, `distance_from_base_notes`, `site_access_notes`, `delivery_routes`) — auto-saves with ✓-saved badge + saving… amber pill
- 7 new sub-section cards inside Step 4 Infrastructure (after the existing infra panels, before `</x-survey.step-container>`):
  1. 📏 **Mounting Heights** — 4 fixed h_m number inputs + Alpine multi-row "other" rows (label + height_m + remove ✕ button)
  2. 🪜 **Working at Height Methods** — 6-button multi-select (ladder, podium, tower, mewp, scaffold, na)
  3. 🔌 **Cable Routes** — Alpine multi-row UI (category enum + length_m + from + to + notes) with +/- live row controls
  4. 🧱 **Wall Construction & Prep** — 6-button multi-select (ply_lined, solid, plasterboard, masonry, metal_stud, concrete) + 3 wall_needs_* boolean toggle rows
  5. 🪑 **Table Info** — has_grommets toggle + grommet_count + grommet_size enum + notes
  6. 🔋 **Floor Box Info** — has_floor_box toggle + power_outlets + data_outlets + cable_space enum + notes
  7. 🔩 **Brackets Required** — Alpine multi-row UI (equipment + model + pull_out toggle + notes) with add/remove
- All sub-sections render REGARDLESS of work_type (engineer feedback applies to all rooms, not just new_install) — placed inside the existing `<x-survey.step-container :step="4">`
- Alpine state extended: `engineerFeedbackSite`, `siteLogisticsSaving`, `siteLogisticsLastSaved`, `_siteAutosaveTimer`
- `init()` adds 7 site-level `$watch` hooks pointing at `debouncedAutosaveSite()` + per-room `$watch` on `engineer_feedback` with `{ deep: true }` so any nested change (toggle, row edit, etc.) triggers the existing `debouncedAutosave()`
- New autosave path: `debouncedAutosaveSite()` + `autosaveSite()` POSTs `{ room_index: 0, step: 0, data: { engineer_feedback_site } }` — independent timer so site-level edits never collide with per-room Step 4 saves
- `buildStepPayload()` case 4 includes `engineer_feedback` alongside `infrastructure` so they ride the same stepSave call
- 8 new helpers: `addMountingOther`, `removeMountingOther`, `addCableRoute`, `removeCableRoute`, `addBracket`, `removeBracket`, `toggleWahMethod`, `toggleWallConstruction`

### Task 2 — legacy endpoints + completeRoom mirror (commit `bf40034`)

**PublicSurveyController.php:**
- `validatePublicSurvey()` extended with 7 site-level rules + 36 room-level rules (mirrors `SiteSurveyController::validateSurvey` from 260503-rgg verbatim — same enums, same max lengths, same array-shape rules; defensive — accepts a flat-form rooms.* shape that the wizard never POSTs but external tools / future flat-form save endpoints will benefit from)
- `roomAttributesFromData()` returns 10 new keys: `mounting_heights`, `work_at_height_methods`, `cable_routes` (via `stripEmptyCableRoutes`), `wall_construction`, 3 `wall_needs_*` boolean casts, `table_info`, `floor_box_info`, `brackets_required` (via `stripEmptyBracketRows`)
- 2 new private helpers: `stripEmptyCableRoutes()` + `stripEmptyBracketRows()` drop fully-empty rows so the column stores `NULL` instead of `[]` when nothing was captured (matches SurveyService normaliser behaviour from 260503-rgg)
- `save()` + `submit()` now do a defensive `$survey->update($this->extractSiteEngineerFeedback($data))` BEFORE delegating to SurveyService — needed because SurveyService::saveDraftPublic + submitPublic do NOT touch the new site-level columns (verified via grep — they only handle the legacy 6 header fields: survey_date, surveyor_name, general_notes, site_risks, access_constraints, h_and_s_notes)
- New helper `extractSiteEngineerFeedback()` returns only the 7 site keys present in the validated payload (preserves "absent key = don't touch" semantics so an empty payload can't null out previously-saved values)
- `completeRoom()` now pulls `engineer_feedback` from `$survey->survey_data['rooms'][canonicalIdx]` when no flat-form rooms array was sent (the typical wizard mark-complete path), and calls `app(SurveyController::class)->writeEngineerFeedbackToColumns($room, $ef)` BEFORE flipping `is_completed`/`completed_at` — second mirror to the per-stepSave mirror (Task 1). Both writing the same data is idempotent and safe; this guards against any race where stepSave dropped a row before completeRoom fired
- Canonical-index resolution by `sort_order` matches `SurveyController::initialPayload` + `buildAlpineRooms` — the canonical `rooms` array is built in sort_order so `array_search($room->id, $survey->rooms->orderBy('sort_order')->pluck('id'))` is the right matcher

## Verification

| Check | Result |
|-------|--------|
| `php -l SurveyController.php` | ✓ No syntax errors detected |
| `php -l PublicSurveyController.php` | ✓ No syntax errors detected |
| `php artisan view:clear && view:cache` | ✓ Compiled views cleared / Blade templates cached successfully |
| `php artisan route:list --path=survey` | ✓ All 36 survey routes present (no regression — same count pre/post) |
| Tinker `emptyCanonicalRoom()` | ✓ returns array with `engineer_feedback` key + 10 expected sub-keys |
| Tinker `initialPayload()` | ✓ returns array with `engineer_feedback_site` key |
| Tinker `SurveyController::show()` direct call | ✓ Returns `Illuminate\View\View`; `engineerFeedbackSite` present in view data; first room `engineer_feedback` present with 10 expected keys |
| Tinker view `->render()` | ✓ Renders 195705 bytes; HTML contains "Site Logistics", "Engineer Build-out Detail", "engineerFeedbackSite", "Mounting Heights", "Brackets Required" |
| File footprint | ✓ Exactly 3 files: SurveyController.php + PublicSurveyController.php + surveys/show.blade.php |
| Forbidden-paths audit (`git diff --stat HEAD~2 HEAD -- app/Models/ app/Core/Modules/Survey/ app/Services/Rams* app/Services/RiskTemplateResolverService.php app/Services/ProjectContext/ resources/views/site-survey/ resources/views/pdf/ database/migrations/ app/Http/Controllers/PublicWorksheetController.php resources/views/worksheets/`) | ✓ EMPTY — zero changes (constraint satisfied) |

## Deviations from Plan

None — plan executed exactly as written. The plan's Note about the optional `_db_room_id` stamping for cheaper sort_order resolution at stepSave time was deferred per the plan's own "DEFERRED FOR FUTURE TASK" guidance — the current implementation does the small N+1 cost of `$survey->rooms()->orderBy('sort_order')->get()` per Step-4 save, which only fires once per debounce window (600ms) so the cost is bounded and acceptable for v1 ship.

The plan's defensive `$survey->update(extractSiteEngineerFeedback($data))` in `save()` and `submit()` was included (not skipped) because the grep against SurveyService confirmed `saveDraftPublic` + `submitPublic` do NOT touch the new site-level columns — they only handle the legacy 6 header fields (`survey_date`, `surveyor_name`, `general_notes`, `site_risks`, `access_constraints`, `h_and_s_notes`). Without the explicit double-write, a save() / submit() POST containing site-level fields would drop them silently.

## Files to upload to live (per local-edit-then-upload workflow)

- `app/Http/Controllers/SurveyController.php`
- `app/Http/Controllers/PublicSurveyController.php`
- `resources/views/surveys/show.blade.php`

Then on live:
```
php artisan view:clear
php artisan config:clear
php artisan route:clear
```

NO migration to run (260503-rgg already shipped the schema).

## Self-Check: PASSED

All claimed files exist on disk with the changes committed:
- `app/Http/Controllers/SurveyController.php` — FOUND (modified)
- `app/Http/Controllers/PublicSurveyController.php` — FOUND (modified)
- `resources/views/surveys/show.blade.php` — FOUND (modified)

Both commits exist in `git log`:
- `a8496d7` — FOUND
- `bf40034` — FOUND
