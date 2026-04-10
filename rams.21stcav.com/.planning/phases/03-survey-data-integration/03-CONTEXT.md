# Phase 3: Survey Data Integration - Context

**Gathered:** 2026-04-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Wire `SiteSurvey` and `SiteSurveyRoom` relational data into `ProjectDataService` so all document generators receive enriched, merged project data without querying survey tables directly. Includes replacing the Phase 1 `room_data` JSON stub with real relational room loading, implementing fuzzy room matching, adding structured global survey fields, and enforcing one-survey-per-project.

This phase satisfies SURV-01 through SURV-03 and SURV-05. External survey access (SURV-04) and draft/submit UX (SURV-05 partial) are already implemented.

</domain>

<decisions>
## Implementation Decisions

### Room Matching Strategy
- **D-01:** Implement fuzzy room matching using Levenshtein distance similarity. Match survey rooms to quote-derived rooms by name at a configurable threshold (Claude picks exact value — ~0.7 is a reasonable starting point).
- **D-02:** Unmatched survey rooms (no quote room match above threshold) are added as orphan entries in the canonical `rooms[]` array with `data_source: 'survey'` and `confidence: 0.95`. They are NOT discarded.
- **D-03:** The Phase 1 `mergeSurveyRooms()` stub that reads `$survey->room_data` (JSON blob) must be replaced. Rooms are loaded from the `SiteSurveyRoom` Eloquent relation — not from any JSON field.

### Global Survey Data — Schema Extension
- **D-04:** Phase 3 adds three new text columns to `site_surveys` via migration: `site_risks` (text, nullable), `access_constraints` (text, nullable), `h_and_s_notes` (text, nullable). These satisfy SURV-03 (global survey data: site risks, H&S notes, constraints).
- **D-05:** These new fields feed into `ProjectDataService` under `survey_meta`: `{ has_survey, submitted_at, site_risks, access_constraints, h_and_s_notes, general_notes, rooms[] }`. Generators read from `survey_meta` — no direct survey table queries.
- **D-06:** The existing `general_notes` field is retained and continues to pass through. The new fields are additive, not replacements.

### One Survey Per Project — Enforced
- **D-07:** Only one active survey is allowed per project at a time. When a new survey is created for a project that already has one, the existing survey is soft-archived (or superseded) rather than coexisting. Claude's discretion on exact mechanism (new `superseded_at` column vs status flag).
- **D-08:** `ProjectDataService` selects the single active/completed survey per project — no multi-survey priority logic required. The query remains simple: `siteSurveys()->where('status', 'completed')->latest()->first()`, but the enforcement in Phase 3 ensures only one qualifies.
- **D-09:** External (client/subcontractor) surveys submitted via public token are treated identically to internal surveys once completed — `survey_type` is not a priority factor.

### Survey Room Normalization Shape
- **D-10:** When `SiteSurveyRoom` records are merged into canonical `rooms[]`, normalize to a curated subset of generator-relevant fields only. Claude defines the exact field list, but it should cover:
  - Identity: `room_name`, `room_ref`, `floor`, `area_type`, `space_type`
  - Dimensions: `room_width_m`, `room_depth_m`, `room_height_m`, `ceiling_type`, `ceiling_height_m`, `wall_material`, `floor_type`
  - AV scope: `av_requirements`, `av_equipment_list`
  - Services: `has_power`, `has_network`, `power_outlet_count`, `network_port_count`, `requires_additional_power`, `existing_cabling`
  - Infrastructure: `rack_unit_space`, `cable_route_desc`
  - Audio: `speaker_count`, `speaker_type`, `speaker_mounting`, `bg_noise_db`
  - Displays: `display_size_in`, `display_orient`, `display_mounting`
  - Access/notes: `access_notes`, `notes`
  - Completion: `is_completed`, `completed_at`
  - Exclude: `items_to_remove`, `items_to_retain`, `existing_condition` (strip-out info, not generator-relevant)
- **D-11:** All normalized room fields carry `data_source: 'survey'` and `confidence: 0.95` when survey data is present.

### Confidence and Merge Priority
- **D-12:** Survey data sits below `reviewed_data` in the merge priority chain (Phase 1 D-05): `reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults`. Survey enriches quote-derived rooms but does not override manually reviewed data.
- **D-13:** The existing `CONFIDENCE_THRESHOLD = 0.7` and `isLowConfidence()` method from Phase 1 apply without change.

### Claude's Discretion
- Exact Levenshtein threshold (starting point: 0.7 similarity)
- Mechanism for enforcing one-survey-per-project (superseded_at timestamp vs status flag vs soft-delete)
- Curated field list refinements beyond the D-10 guidance
- Whether to eagerly load `SiteSurveyRoom` on the survey resolve or lazy-load per room
- Whether to add survey global fields to the survey form views (can be deferred to Phase 4 UI pass if needed — data layer is the Phase 3 requirement)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Core Service — Phase 3 Target
- `app/Core/Modules/Projects/ProjectDataService.php` — existing service; `mergeSurveyRooms()` and `resolveSurveyMeta()` are the primary targets for replacement
- `app/Services/ProjectContextResolver.php` — legacy resolver (context only, not modified in Phase 3)

### Survey Models
- `app/Models/SiteSurvey.php` — survey header, global fields, status, access_token, survey_type
- `app/Models/SiteSurveyRoom.php` — per-room data (50+ columns) — this is what Phase 3 loads
- `app/Models/SiteSurveyPhoto.php` — photos (not merged into canonical data, out of scope)

### Survey Service
- `app/Core/Modules/Survey/SurveyService.php` — create, update, complete, submitPublic — enforce one-survey-per-project here on create

### Migrations (Schema Reference)
- `database/migrations/2026_03_09_000003_create_site_surveys_table.php` — base schema
- `database/migrations/2026_03_14_000030_add_infrastructure_to_site_survey_rooms_table.php` — extended room columns

### Prior Phase Context
- `.planning/phases/01-project-layer-data-foundation/01-CONTEXT.md` — D-26 (fuzzy merge deferred here), D-05 (merge priority), D-04 (confidence tracking)
- `.planning/phases/01-project-layer-data-foundation/01-03-SUMMARY.md` — ProjectDataService structure, `survey_meta` key shape, stub notes

### Tests
- `tests/Unit/ProjectDataServiceTest.php` — existing unit tests; Phase 3 must extend, not break them

### Requirements
- `.planning/REQUIREMENTS.md` — SURV-01, SURV-02, SURV-03, SURV-05 are Phase 3 targets

</canonical_refs>

<code_context>
## Existing Code Insights

### What Phase 1 Left as Stubs (Phase 3 Replaces)
- `ProjectDataService::mergeSurveyRooms()` — reads `$survey->room_data` (JSON blob that doesn't exist). Replace with relational load from `SiteSurveyRoom`.
- `ProjectDataService::resolveSurveyMeta()` — also reads `$survey->room_data`. Replace with normalized room array from relation.
- Both methods exist and have the right signature — Phase 3 replaces their internals.

### Established Patterns
- `ProjectDataService` uses anonymous class stubs for unit tests (allows test isolation without DB). Phase 3 room normalization must follow the same pattern — stub must expose the right room shape.
- All canonical data items carry `data_source` + `confidence` keys — Phase 3 must annotate survey rooms consistently.
- `SurveyService` handles all survey mutations — one-survey enforcement belongs here, not in controllers.

### Integration Points
- `Project::siteSurveys()` hasMany — already exists; Phase 3 queries this for the active survey
- `SiteSurveyRoom::survey()` belongsTo — already exists
- `SurveyService::create()` and `createFromProject()` — add one-survey enforcement here
- `SiteSurvey::$fillable` and `$casts` — extend for new global columns
- Survey form views (internal + public) — may need new fields for site_risks, access_constraints, h_and_s_notes; this is optional in Phase 3 if data layer is the primary deliverable

</code_context>

<specifics>
## Specific Ideas

- Levenshtein fuzzy matching can use PHP's native `similar_text()` or `levenshtein()` — no external library needed.
- The "one survey per project" enforcement should also be reflected in the project dashboard (Phase 1 D-06: document cards always visible). A second "create survey" attempt should visually offer to supersede the existing one, not silently create a parallel one.
- Survey rooms that are orphaned (no quote match) still represent real physical spaces — they should appear in canonical `rooms[]` with a `quote_room_matched: false` flag so generators can handle them explicitly.
- The `survey_type` field already exists on `SiteSurvey` — useful for display/audit but not for priority decisions.

</specifics>

<deferred>
## Deferred Ideas

- Manual room mapping UI (drag-and-drop survey room → quote room) — would require Phase 4 UI work; fuzzy auto-match covers the majority case
- Photo data in canonical dataset — `SiteSurveyPhoto` exists but photos are not generator inputs yet; defer to when a generator needs them
- Survey form UI for new global fields (site_risks, access_constraints, h_and_s_notes) — data schema is Phase 3; form UI can be a Phase 4 polish task if not in scope

</deferred>

---

*Phase: 03-survey-data-integration*
*Context gathered: 2026-04-10*
