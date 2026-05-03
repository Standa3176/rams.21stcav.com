---
phase: quick-260503-rgg
plan: 01
subsystem: site-survey
tags: [site-survey, engineer-feedback, schema-additive, blade, alpine]
requires: []
provides:
  - "site_surveys.comms_room_access_status"
  - "site_surveys.comms_room_access_notes"
  - "site_surveys.parking_restraints"
  - "site_surveys.distance_from_base_miles"
  - "site_surveys.distance_from_base_notes"
  - "site_surveys.site_access_notes"
  - "site_surveys.delivery_routes"
  - "site_survey_rooms.mounting_heights (JSON)"
  - "site_survey_rooms.work_at_height_methods (JSON)"
  - "site_survey_rooms.cable_routes (JSON)"
  - "site_survey_rooms.wall_construction (JSON)"
  - "site_survey_rooms.wall_needs_reinforcement (bool)"
  - "site_survey_rooms.wall_needs_chase_out (bool)"
  - "site_survey_rooms.wall_needs_conduit (bool)"
  - "site_survey_rooms.table_info (JSON)"
  - "site_survey_rooms.floor_box_info (JSON)"
  - "site_survey_rooms.brackets_required (JSON)"
affects:
  - "Site Survey edit page UI (admin)"
tech-stack:
  added: []
  patterns: ["Eloquent array casts for JSON columns", "Alpine x-data multi-row UIs", "hidden-0 + checkbox-1 boolean idiom"]
key-files:
  created:
    - "database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php"
  modified:
    - "app/Models/SiteSurvey.php"
    - "app/Models/SiteSurveyRoom.php"
    - "app/Http/Controllers/SiteSurveyController.php"
    - "app/Core/Modules/Survey/SurveyService.php"
    - "resources/views/site-survey/edit.blade.php"
    - "resources/views/site-survey/_room-form.blade.php"
decisions:
  - "Cable routes is additive (new JSON column) — legacy single-row cable_route_desc/from/to columns left intact for backwards compat"
  - "All new fields nullable; existing surveys load + save unchanged"
  - "New rooms added in-session via JS roomCardHtml() do NOT include the new sections — they appear after first save when the partial re-renders. Trade-off avoids duplicating ~250 LOC in JS-string form."
metrics:
  duration: "~10 min"
  tasks: 3
  files_modified: 7
  commits: 3
  insertions: 743
  deletions: 4
  completed: 2026-05-03
---

# Quick Task 260503-rgg: Site Survey Form Enhancements (17 Engineer-Feedback Fields) Summary

Pure additive expansion of the Site Survey form to capture 17 missing data points engineers need on-site (parking, lift access, working-at-height method, cable lengths per route, mounting heights, bracket models, wall construction). Schema + controller validation + service persistence + Blade UI; downstream services (RamsBuilderService, RamsDataBuilderService, ProjectDataService, InstallTaskGeneratorService) intentionally untouched and will pick up the new data on their next regen cycle.

## Commits

| # | Hash | Message |
|---|------|---------|
| 1 | `83796c9` | feat(quick-260503-rgg-01): add engineer-feedback schema for site surveys |
| 2 | `1892796` | feat(quick-260503-rgg-02): wire engineer-feedback fields through controller + service |
| 3 | `5aef363` | feat(quick-260503-rgg-03): add Site Logistics + 7 room engineer-feedback sections |

## What Shipped

### Schema (Task 1, commit `83796c9`)

- 1 migration adds 7 nullable columns to `site_surveys` + 10 nullable columns to `site_survey_rooms` in a single up()/down() pair
- All site-level columns clustered with the other free-text site notes via `->after('general_notes')` chain
- All room-level columns positioned via `->after('display_mounting')` chain
- Down migration explicitly lists every column for safe rollback (verified round-trip on dev)
- `SiteSurvey::$fillable` extended with 7 new column names (no new casts — Laravel handles string/text/decimal natively)
- `SiteSurveyRoom::$fillable` + `$casts` extended (4 booleans + 6 array casts on JSON columns)

### Validation + persistence (Task 2, commit `1892796`)

- `SiteSurveyController::validateSurvey()` extended with 7 nullable site-level rules + 36 nullable room-level rules covering nested array shapes
- Enum constraints applied where applicable: `comms_room_access_status` (yes/no/outsourced/unknown), `work_at_height_methods` (ladder/podium/tower/mewp/scaffold/na), `wall_construction` (ply_lined/solid/plasterboard/masonry/metal_stud/concrete), `grommet_size` (small/standard/large), `cable_space` (tight/adequate/spacious), cable_routes `category` (7-value enum)
- `SurveyService::update()` persists 7 new site-level fields via `$survey->update()` additions
- `SurveyService::roomAttributes()` persists 10 new room-level fields with explicit `(bool)` casts on the 3 wall_needs_* booleans
- `normalizeCableRoutes()` + `normalizeBracketRows()` private helpers strip fully-empty rows from Alpine multi-row submissions; return `null` when no real rows remain so column stays NULL rather than storing `[]`

### UI (Task 3, commit `5aef363`)

- `edit.blade.php`: new "Site Logistics" `.form-section` card inserted after Project Manager fieldset (1 select + 1 number + 5 textareas — all 7 site-level fields)
- `edit.blade.php`: `roomCardHtml()` JS template gets a one-line comment explaining new rooms get the engineer-feedback fields after first save (re-render via partial)
- `_room-form.blade.php`: 4 measurement labels refined (no schema change) — clearer copy on Width/Depth/Height/Ceiling-Height
- `_room-form.blade.php`: 7 new `.form-section` cards inserted between Measurements infra-panel and Engineer Sign-off block:
  1. **Mounting Heights** — 4 fixed h_m number inputs (screen/camera/booking_panel/speaker) + Alpine multi-row "other" rows
  2. **Working at Height Methods** — 6-checkbox multi-select
  3. **Cable Routes** — Alpine multi-row UI (category enum + length_m + from + to + notes) with +/- live row controls
  4. **Wall Construction & Prep** — 6-checkbox multi-select + 3 wall_needs_* boolean flags
  5. **Table Info** — has_grommets + grommet_count + grommet_size enum + notes
  6. **Floor Box Info** — has_floor_box + power_outlets + data_outlets + cable_space enum + full-width notes
  7. **Brackets Required** — Alpine multi-row UI (equipment + model + pull_out toggle + notes)
- All Alpine `x-data` scopes are isolated per-section per-room — no collision with the existing pre-install-checks panel scope
- All inputs that engineer should fill use `placeholder=" "`; genuinely-optional inputs use `data-optional` (matches the 260503-ipc empty-field-highlight CSS rule)
- Defensive `json_decode` fallback in the `@php` setup block guards against string-typed JSON columns from legacy SQLite drivers

## Verification

| Check | Result |
|-------|--------|
| `php artisan migrate --pretend` | OK — shows all 7 site + 10 room columns |
| `php artisan migrate` | OK (190ms) |
| `php artisan migrate:rollback --step=1` | OK (368ms) |
| `php artisan migrate` (re-apply) | OK (178ms) — round-trip clean |
| `php artisan tinker -e "SiteSurvey::first()?->comms_room_access_status"` | returns `null-ok` |
| `php artisan tinker -e "var_export(SiteSurveyRoom::first()?->mounting_heights)"` | returns `NULL` (cast working) |
| `php -l SiteSurvey.php` | No syntax errors |
| `php -l SiteSurveyRoom.php` | No syntax errors |
| `php -l SiteSurveyController.php` | No syntax errors |
| `php -l SurveyService.php` | No syntax errors |
| `php artisan view:clear && view:cache` | All Blade templates cached successfully |
| Render `_room-form` partial | 47,982 bytes; all 7 section-headings present; all 4 refined labels present; all field name patterns present |
| `php artisan route:list --path=site-surveys` | All 19 routes register cleanly |
| Untouched-services audit (`git diff --stat HEAD~3 HEAD -- app/Services/{Rams*,Project,InstallTaskGenerator}*`) | EMPTY — zero changes (constraint satisfied) |
| File footprint | exactly 7 files (1 migration + 2 models + 1 controller + 1 service + 2 views) |

## Deviations from Plan

None — plan executed exactly as written. The only minor enrichment over the plan spec was a defensive `json_decode` fallback inside the `@php` block of `_room-form.blade.php` to guard against legacy SQLite drivers that return JSON columns as strings rather than auto-decoded arrays. This is a Rule 2 (correctness) addition — without it, `@js(array_values($cableRows))` would throw on string-typed values. Costs ~7 lines, prevents a class of latent runtime errors.

## Files to upload to live (per local-edit-then-upload workflow)

- `database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php`
- `app/Models/SiteSurvey.php`
- `app/Models/SiteSurveyRoom.php`
- `app/Http/Controllers/SiteSurveyController.php`
- `app/Core/Modules/Survey/SurveyService.php`
- `resources/views/site-survey/edit.blade.php`
- `resources/views/site-survey/_room-form.blade.php`

Then on live:
```
php artisan migrate
php artisan view:clear
php artisan config:clear
```

## Self-Check: PASSED

All claimed files exist on disk:
- `database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php` — FOUND
- `app/Models/SiteSurvey.php` — FOUND (modified)
- `app/Models/SiteSurveyRoom.php` — FOUND (modified)
- `app/Http/Controllers/SiteSurveyController.php` — FOUND (modified)
- `app/Core/Modules/Survey/SurveyService.php` — FOUND (modified)
- `resources/views/site-survey/edit.blade.php` — FOUND (modified)
- `resources/views/site-survey/_room-form.blade.php` — FOUND (modified)

All commits exist in `git log`:
- `83796c9` — FOUND
- `1892796` — FOUND
- `5aef363` — FOUND
