---
phase: quick-260503-tfb
plan: 01
subsystem: rams-generation
tags: [rams, site-survey, hazards, blade, additive]
requires:
  - "site_surveys.* + site_survey_rooms.* engineer-feedback columns from quick task 260503-rgg"
provides:
  - "RAMS PDF: Site Logistics & Access subsection in Section 4 (Scope of Works)"
  - "RAMS PDF: per-room Engineer Survey Findings subsection inside the room loop"
  - "Auto-classified hazards from engineer-feedback signals (working-at-height tier, wall prep, long cable pulls)"
  - "ProjectContext.site_logistics top-level key"
  - "ProjectContext.rooms[n].engineer_feedback nested per-room block"
  - "RamsData.site_logistics top-level key"
  - "SurveyToProjectContextMapper::mapWithModelRooms() — model-aware mapping path"
affects:
  - "RAMS PDF rendered output (Section 4 + per-room blocks + hazard register)"
tech-stack:
  added: []
  patterns:
    - "Model-aware mapper pattern (mapWithModelRooms vs map): keeps JSON-only contract for tests, adds model-aware path for full pipeline"
    - "Defensive empty-engineer_feedback no-op: every new branch guards on empty(), legacy surveys produce zero new output"
    - "Hazard tier encoding in description text (not separate templates): one 'Working at Height' template carries LOW/MEDIUM/HIGH tier via row description"
key-files:
  created: []
  modified:
    - "app/Services/ProjectContext/SurveyToProjectContextMapper.php"
    - "app/Services/ProjectContext/ProjectContextBuilder.php"
    - "app/Services/RamsDataBuilderService.php"
    - "app/Services/RamsBuilderService.php"
    - "app/Services/RiskTemplateResolverService.php"
    - "resources/views/pdf/rams.blade.php"
decisions:
  - "DocxBuilderService NOT modified in v1 — DOCX mirror deferred to clean follow-up quick task (file is 1175 lines, would push past 4-6 file cap). Blade-rendered PDF is the engineer's primary on-site reference, so the immediate need is fully met."
  - "No new HazardTemplate seeder entries — all 5 auto-classified hazards (Working at Height, Dust & Debris, Fixings/Substrate Failure, Hidden Services, Manual Handling) resolve to existing global library templates by name verbatim."
  - "Working-at-height tier (LOW/MED/HIGH) encoded in hazard ROW description text rather than three separate templates. Keeps seeder untouched. If future work wants tier-specific templates, that's a follow-up."
  - "site_logistics priority preserved: reviewed_data.site_logistics (manual review) wins over survey-derived ONLY when non-empty. Old code unconditionally clobbered with empty array — fixed."
  - "Cable category enum (ceiling_speakers, desk_cables, mic_cables, booking_panel_cables, screen_cables, rack_to_room, other) verified against SiteSurveyController validation. Plan's assumed enum was wrong — Blade labels use the real enum."
  - "RamsBuilderService added to file footprint (file #5) per plan revision note — the runFromReview() priority-preservation tweak for site_logistics."
metrics:
  duration: "~25 min"
  tasks: 3
  files_modified: 6
  commits: 3
  insertions: 550
  deletions: 3
  completed: 2026-05-03
---

# Quick Task 260503-tfb: Wire Site-Survey Engineer-Feedback Fields into RAMS Document Generator Summary

Pure additive integration of the 17 engineer-feedback fields captured by quick task 260503-rgg through the downstream RAMS document generation pipeline. Engineer-captured site logistics (parking, comms-room access, depot distance, delivery routes) and per-room mounting heights, work-at-height methods, planned cable routes, wall construction + prep flags, and brackets-to-source now appear in the rendered RAMS PDF AND influence hazard auto-classification (working-at-height tier, dust hazards from chase-out, fixings hazards from reinforcement, hidden-services hazards from conduit, manual-handling hazards from long cable pulls).

## Commits

| # | Hash | Message |
|---|------|---------|
| 1 | `c3581b5` | feat(quick-260503-tfb-01): surface engineer-feedback rooms + site_logistics through ProjectContext |
| 2 | `d6db22b` | feat(quick-260503-tfb-02): auto-classify engineer-feedback hazards |
| 3 | `1e6555e` | feat(quick-260503-tfb-03): render Site Logistics + per-room engineer findings in RAMS PDF |

## What Shipped

### Task 1 — Surface engineer-feedback through ProjectContext (commit `c3581b5`)

- `SurveyToProjectContextMapper`: new public `mapWithModelRooms($surveyData, $modelRooms)` method that merges per-room engineer-feedback blocks from `SiteSurveyRoom` model rows (column data) into the canonical rooms[] array. Existing `map()` becomes a thin wrapper preserving the legacy JSON-only contract for `buildFromPayload()` callers and tests.
- New private helper `buildEngineerFeedback($modelRoom)` extracts the 10 new column values with safe defaults (empty array, false, or null) for every nullable field. Includes derived `max_mounting_height_m` (max across screen/camera/booking_panel/speaker/other heights, null when none captured).
- Matching strategy: case-insensitive trimmed `room_name` lookup with positional-index fallback when names mismatch. Defensive: missing model row → `engineer_feedback = []` (key always present, value empty).
- `ProjectContextBuilder::build()` calls the new model-aware mapper with `$survey->rooms` and additionally attaches site-level engineer feedback as `$context['site_logistics']` (7 site-level columns: comms access status + notes, parking, depot distance + notes, site access notes, delivery routes).
- `RamsDataBuilderService::assemble()`: extracts `$siteLogistics` from `$projectContext`, exposes it as top-level `$data['site_logistics']`, and adds a normaliser block coercing all 7 fields to strict-typed scalars (string for text, scalar/null for numeric).
- `RamsBuilderService::runFromReview()` fix: previously `$data['site_logistics'] = (array) ($reviewedData['site_logistics'] ?? [])` unconditionally clobbered the survey-derived block with an empty array. Now reviewed-data wins ONLY when non-empty (preserves manual-review priority while lighting up the new survey path for projects without manual edits).

### Task 2 — Auto-classify engineer-feedback hazards (commit `d6db22b`)

`RiskTemplateResolverService::resolveFromProjectContext()` extended with four new inspection branches inside the existing per-room loop, all `if (empty($ef)) continue;` guarded so legacy surveys (no engineer_feedback) are a strict no-op.

Hazard auto-classification rules:

| Trigger | Hazard Title (existing template) | Tier / Description | PPE / Access added |
|---------|----------------------------------|--------------------|-----|
| `mewp` or `scaffold` in methods, OR `max_mounting_height_m > 4.0` | **Working at Height** | HIGH-tier | Hard Hat, Safety Harness; MEWP, Scaffold |
| `tower` in methods, OR `max_mounting_height_m > 2.0` (not HIGH) | **Working at Height** | MEDIUM-tier | Hard Hat; Access Tower |
| `ladder` or `podium` in methods, OR `0 < max_mounting_height_m ≤ 2.0` (not HIGH/MED) | **Working at Height** | LOW-tier | Hard Hat; Podium Steps |
| `wall_needs_chase_out = true` | **Dust & Debris (Including Drilling)** | Wall chasing dust | Dust Mask (FFP3) |
| `wall_needs_reinforcement = true` | **Fixings / Substrate Failure** | Wall reinforcement | — |
| `wall_needs_conduit = true` | **Hidden Services (Electrical, Plumbing, Gas)** | Conduit drilling | — |
| Any `cable_routes[].length_m > 30.0` | **Manual Handling** | Long cable pull | — |

Tier mutual exclusivity preserved (HIGH > MEDIUM > LOW). `na` alone does NOT trigger any working-at-height row.

`mergeHazard()` deduplicates by title (preserves first description, appends room name), so existing `primaryRisk['working_height']==='over_2m'` rows still win when both fire on the same room — only the room name is appended, no double-row pollution.

### Task 3 — Render Site Logistics + per-room engineer findings (commit `1e6555e`)

`resources/views/pdf/rams.blade.php` (single Blade view, 255 lines added):

**Site Logistics & Access subsection** (Section 4, Scope of Works):
- Inserted after the existing kv-block, before the scope-of-works-bullets block
- Whole section `@if($hasSiteLog)` guarded — surveys with no logistics data render nothing
- Friendly labels for `comms_room_access_status` enum (yes → "Permission required", no → "Free access", outsourced → "Outsourced facilities team", unknown → "Status unknown")
- Each row independently `@if(! empty(...))` guarded so partial data still renders cleanly

**Engineer Survey Findings per-room subsection** (inside `$roomOverviews` loop):
- Lookup table `$efByRoom` built from `$data['rooms'][].engineer_feedback` keyed by case-insensitive trimmed room name (joins ProjectContext-derived rooms to the reviewed_data-driven loop)
- Inserted at the END of each `@foreach($roomOverviews as $roomOv)` iteration, after the existing scope-paragraph render
- Whole section `@if($hasEF)` guarded — rooms with all-NULL engineer fields render nothing
- 7 sub-blocks, each independently guarded:
  1. **Installation heights** — Screen / Camera / Booking panel / Speaker fixed labels + freeform `other` rows with custom labels
  2. **Working at height — methods on site** — enum mapped to friendly labels (Ladder, Podium steps, Access tower, MEWP, Scaffold, Not required)
  3. **Cable routes planned** — bullet list with category label + from→to + length + notes (cable category enum verified against SiteSurveyController: ceiling_speakers, desk_cables, mic_cables, booking_panel_cables, screen_cables, rack_to_room, other)
  4. **Wall construction + Prep needed** — wall_construction enum mapped to friendly labels (Ply-lined, Solid wall, Plasterboard, Masonry / brick, Metal stud, Concrete) + 3 wall_needs_* flags
  5. **Brackets to source** — bullet list with equipment + model + pull-out toggle + notes
  6. **Table info** — grommet count + size + notes (single line)
  7. **Floor box info** — power outlets / data outlets / cable space + notes (single line)

## Verification

| Check | Result |
|-------|--------|
| `php -l app/Services/ProjectContext/SurveyToProjectContextMapper.php` | No syntax errors |
| `php -l app/Services/ProjectContext/ProjectContextBuilder.php` | No syntax errors |
| `php -l app/Services/RamsDataBuilderService.php` | No syntax errors |
| `php -l app/Services/RamsBuilderService.php` | No syntax errors |
| `php -l app/Services/RiskTemplateResolverService.php` | No syntax errors |
| `php artisan view:clear && php artisan view:cache` | Compiled views cleared, Blade templates cached successfully |
| `php artisan tinker --execute="echo class_exists('App\Services\RamsDataBuilderService') ? 'OK' : 'BROKEN';"` | `OK` |
| Render smoke-test: `view('pdf.rams', [...])->render()` on latest RAMS | `render-ok bytes=98735` (no exceptions) |
| Tinker spot-check: `ProjectContextBuilder::build($surveyWithRooms)` keys | `project_id, rooms, site_logistics` (all 3 present) |
| Tinker spot-check: `engineer_feedback` block on first room of empty survey | All defaults present (`max_mounting_height_m: null`, every array `[]`, every bool `false`) — defensive null path verified |
| Tinker spot-check: `resolveFromProjectContext()` on empty engineer feedback | `HAZARD COUNT=0` — zero spurious hazards added (no-regression confirmed) |
| Synthetic populated-survey test (3.5m screen + tower + chase-out + 35m cable run) | `HAZARD COUNT=3`: Working at Height + Dust & Debris (Including Drilling) + Manual Handling. PPE: Hard Hat, Dust Mask (FFP3). Access: Access Tower. **All match the manual verification checklist scenario.** |
| File footprint | exactly 6 files (within 4-6 cap) |
| Forbidden-file diff `git diff --stat HEAD~3 HEAD -- app/Services/MethodStatement* app/Core/Modules/Survey/SurveyService.php app/Services/InstallTaskGeneratorService.php app/Services/OmManualDocxService.php app/Services/RamsExtractionDraftBuilderService.php resources/views/site-survey/ resources/views/worksheets/ app/Http/Controllers/PublicWorksheetController.php app/Models/SiteSurvey.php app/Models/SiteSurveyRoom.php` | EMPTY — zero changes (constraint satisfied) |

## Hazard Template Resolution

All 5 auto-classified hazards resolve to existing global HazardTemplate entries (verified in `HazardTemplateSeeder.php`):

| Survey signal | Template name (verbatim) | Seeder line |
|---------------|--------------------------|-------------|
| work_at_height_methods (any tier) + max_mounting_height | `Working at Height` | 87 |
| wall_needs_chase_out | `Dust & Debris (Including Drilling)` | 144 |
| wall_needs_reinforcement | `Fixings / Substrate Failure` | 234 |
| wall_needs_conduit | `Hidden Services (Electrical, Plumbing, Gas)` | 162 |
| cable_routes[].length_m > 30 | `Manual Handling` | 51 |

**No template gaps.** Zero new seeder entries. Zero migrations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocker] Cable category enum mismatch** — Plan assumed cable_routes category values were `screen, camera, booking_panel, speaker, ceiling_speakers, floor_box, rack`. The actual enum (verified at `SiteSurveyController.php:544`) is `ceiling_speakers, desk_cables, mic_cables, booking_panel_cables, screen_cables, rack_to_room, other`. Used the real enum in `$cableCategoryLabels` in the Blade template. Without this fix, every cable route would have rendered as humanised-key fallback text (e.g. "Desk Cables") instead of the curated label ("Desk cables"). Files modified: `resources/views/pdf/rams.blade.php`. Commit: `1e6555e`.

**2. [Rule 2 - Correctness] Site Logistics priority preservation** — Plan called this out explicitly as a required tweak (line 285-298 of PLAN.md). Old `RamsBuilderService::runFromReview()` had `$data['site_logistics'] = (array) ($reviewedData['site_logistics'] ?? []);` unconditionally — when `reviewed_data.site_logistics` is absent, this clobbered the survey-derived block with `[]`, hiding the new engineer-feedback site logistics. Fixed: now only overwrites when reviewed data is non-empty. Files modified: `app/Services/RamsBuilderService.php`. Commit: `c3581b5`.

No surprises beyond these two — both already documented in the plan.

## Manual Verification Checklist (constraint requirement)

To be performed by the user after upload to live (per local-edit-then-upload workflow):

- [ ] Pick a project with both completed site survey AND generated RAMS
- [ ] Edit the survey — fill in: parking_restraints (e.g. "Loading bay 3, max 30 min"), screen mounting height = 3.5 m, tick wall_needs_chase_out, add cable route (category=screen_cables, length=35m, from=rack, to=display)
- [ ] Regenerate the RAMS for that project (queue: `php artisan queue:work --once`)
- [ ] Download the RAMS PDF and verify:
  - [ ] Section 4 (Scope of Works) shows "Site Logistics & Access (from site survey)" subsection containing "Loading bay 3, max 30 min" under "Parking arrangements"
  - [ ] The room block shows "Engineer Survey Findings — {room name}" subsection
  - [ ] Within that: "Installation heights: Screen: 3.5 m"
  - [ ] "Cable routes planned" lists "Screen / display cables — rack → display — 35 m"
  - [ ] "Wall construction" or "Prep needed" mentions "Chase-out required"
  - [ ] Section 5 (Risk Assessment) hazard table contains: Working at Height (MEDIUM tier — > 2 m), Dust & Debris (Including Drilling) (chase-out), Manual Handling (35 m > 30 m)
- [ ] Negative regression test on a project with NULL in all new fields:
  - [ ] No "Site Logistics & Access" subsection appears
  - [ ] No "Engineer Survey Findings" subsection appears in any room block
  - [ ] Hazard table identical to pre-change output (no spurious "Working at Height" auto-additions)

## Files to upload to live (per local-edit-then-upload workflow)

- `app/Services/ProjectContext/SurveyToProjectContextMapper.php`
- `app/Services/ProjectContext/ProjectContextBuilder.php`
- `app/Services/RamsDataBuilderService.php`
- `app/Services/RamsBuilderService.php`
- `app/Services/RiskTemplateResolverService.php`
- `resources/views/pdf/rams.blade.php`

Then on live:
```
php artisan view:clear
php artisan config:clear
```

(No migrations to run — zero schema changes in this quick task. The schema work was done in 260503-rgg which has its own upload list.)

## Forbidden-file Audit (proving constraint satisfaction)

`git diff --stat HEAD~3 HEAD --` against each forbidden path returned EMPTY for ALL of:

- `app/Services/MethodStatement*` — empty
- `app/Core/Modules/Survey/SurveyService.php` — empty
- `app/Services/InstallTaskGeneratorService.php` — empty
- `app/Services/OmManualDocxService.php` — empty
- `app/Services/RamsExtractionDraftBuilderService.php` — empty
- `resources/views/site-survey/` — empty
- `resources/views/worksheets/` — empty
- `app/Http/Controllers/PublicWorksheetController.php` — empty
- `app/Models/SiteSurvey.php` — empty
- `app/Models/SiteSurveyRoom.php` — empty

All 10 forbidden paths confirmed unchanged across all 3 commits.

## Deferred Follow-up Quick Tasks

1. **`260504-xxx-mirror-engineer-feedback-in-docx`** — Mirror the Blade rendering work in `app/Services/DocxBuilderService.php`. Two new section-builders matching the Blade structure (Site Logistics block + per-room Engineer Survey Findings block). Estimated 2-3 commits, ~150 LOC into the existing 1175-line builder. Required for DOCX downloads to match the PDF.

2. **(Optional) `260504-yyy-three-tier-working-at-height-templates`** — Split the single "Working at Height" template into "Working at Height (LOW)", "Working at Height (MEDIUM)", "Working at Height (HIGH)" so the hazard register row title itself encodes the tier (instead of the description text). Requires a small migration + seeder addendum + `mergeHazard` call-site updates.

## Self-Check: PASSED

All claimed files exist on disk:
- `app/Services/ProjectContext/SurveyToProjectContextMapper.php` — FOUND (modified)
- `app/Services/ProjectContext/ProjectContextBuilder.php` — FOUND (modified)
- `app/Services/RamsDataBuilderService.php` — FOUND (modified)
- `app/Services/RamsBuilderService.php` — FOUND (modified)
- `app/Services/RiskTemplateResolverService.php` — FOUND (modified)
- `resources/views/pdf/rams.blade.php` — FOUND (modified)

All commits exist in `git log`:
- `c3581b5` — FOUND
- `d6db22b` — FOUND
- `1e6555e` — FOUND
