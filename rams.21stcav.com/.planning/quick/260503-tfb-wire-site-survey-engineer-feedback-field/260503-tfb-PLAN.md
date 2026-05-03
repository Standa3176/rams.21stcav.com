---
phase: quick-260503-tfb
plan: 01
type: execute
wave: 1
depends_on: []
mode: quick
subsystem: rams-generation
tags: [rams, site-survey, hazards, blade, docx, additive]
requires: ["site_survey_rooms.* engineer-feedback columns from quick task 260503-rgg"]
files_modified:
  - app/Services/ProjectContext/SurveyToProjectContextMapper.php
  - app/Services/RamsDataBuilderService.php
  - app/Services/RiskTemplateResolverService.php
  - resources/views/pdf/rams.blade.php
autonomous: true
requirements: [TFB-01, TFB-02, TFB-03, TFB-04]

must_haves:
  truths:
    - "When a user regenerates a RAMS for a project whose latest site survey has the new engineer-feedback fields populated, the new Site Logistics block appears in the rendered PDF."
    - "Each room block in the rendered RAMS PDF lists the per-room mounting heights, work-at-height methods, planned cable routes, wall construction + prep flags, and brackets-to-source — when those fields are populated."
    - "When the survey contains work_at_height_methods (or implicit max-mounting-height) signals, the matching tier of Working at Height hazard appears in the hazard register (resolved from the existing 'Working at Height' library template — no new template needed for v1)."
    - "When the survey contains wall_needs_chase_out / wall_needs_reinforcement / wall_needs_conduit, the matching wall-prep hazards are present in the hazard register (resolved by name to the closest existing library entries: Dust & Debris, Fixings/Substrate Failure, Hidden Services respectively)."
    - "Existing RAMS regenerations against surveys that have NULL in every new engineer-feedback field produce the SAME hazard register and same DOCX/PDF output as before this change (zero regression on null path)."
  artifacts:
    - path: "app/Services/ProjectContext/SurveyToProjectContextMapper.php"
      provides: "Per-room engineer-feedback block injected into ProjectContext rooms[] entries (read from $survey->rooms relation, fed in via context['survey_rooms_db'] OR new method buildFromModel())."
    - path: "app/Services/RamsDataBuilderService.php"
      provides: "site_logistics top-level data key + per-room engineer-feedback nested under rooms[]"
    - path: "app/Services/RiskTemplateResolverService.php"
      provides: "Auto-classify Working-at-Height tier + wall-prep hazards from the new survey signals"
    - path: "resources/views/pdf/rams.blade.php"
      provides: "Site Logistics block in Section 4 (Scope of Works area) + per-room engineer-feedback subsection inside the room loop"
  key_links:
    - from: "RamsBuilderService::buildProjectContext()"
      to: "ProjectContextBuilder::build($survey)"
      via: "Already wired — but mapper must now accept SiteSurvey model not just survey_data array, OR mapper must merge $survey->rooms() relation into the rooms list"
      pattern: "ProjectContextBuilder::build"
    - from: "RamsDataBuilderService::assemble()"
      to: "RiskTemplateResolverService::resolveFromProjectContext()"
      via: "Existing call inside assemble() when contextRooms is non-empty — survey-driven hazards merged into hazard register"
      pattern: "resolveFromProjectContext"
    - from: "RamsDataBuilderService::assemble()"
      to: "rams.blade.php $data['site_logistics'] + $data['rooms'][n]['engineer_feedback']"
      via: "Renderer passes $data through to Blade view + DocxBuilderService"
      pattern: "site_logistics|engineer_feedback"
---

<objective>
Wire the 17 engineer-feedback fields captured by the Site Survey form (quick task 260503-rgg) through the downstream RAMS document generation pipeline so they appear in the rendered RAMS PDF and influence hazard classification.

Purpose: Engineers are now capturing parking, comms-room access, mounting heights, cable routes, wall construction, brackets-required, and work-at-height methods on-site — but none of that data reaches the RAMS document. RAMS regenerations today still produce the same boilerplate output regardless of survey content. This task closes the loop by making the survey data flow into both the RAMS data context (visible sections in the PDF) and the hazard auto-classification logic (correct working-at-height tier + wall-prep hazards added to the register).

Output: 4 modified files. Zero new files (no migrations, no new services, no new tests). Existing RAMS regenerations against surveys with NULL in the new fields produce IDENTICAL output to today (pure additive guarantee).
</objective>

<context>
@CLAUDE.md
@.planning/STATE.md
@.planning/quick/260503-rgg-site-survey-form-enhancements-17-enginee/260503-rgg-SUMMARY.md
@app/Models/SiteSurvey.php
@app/Models/SiteSurveyRoom.php
@app/Services/ProjectContext/ProjectContextBuilder.php
@app/Services/ProjectContext/SurveyToProjectContextMapper.php
@app/Services/RamsBuilderService.php
@app/Services/RamsDataBuilderService.php
@app/Services/RiskTemplateResolverService.php
@app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php
@app/Models/HazardTemplate.php
@database/seeders/HazardTemplateSeeder.php
@resources/views/pdf/rams.blade.php

<interfaces>
<!-- Key types and contracts the executor needs. Extracted from codebase. -->
<!-- Executor should use these directly — no codebase exploration needed. -->

From app/Models/SiteSurvey.php (NEW columns from quick 260503-rgg):
```
$fillable additions (string/text/decimal — no casts needed):
  comms_room_access_status   (enum: yes|no|outsourced|unknown)
  comms_room_access_notes    (text)
  parking_restraints         (text)
  distance_from_base_miles   (decimal)
  distance_from_base_notes   (text)
  site_access_notes          (text)
  delivery_routes            (text)

Relation: $survey->rooms() returns HasMany<SiteSurveyRoom> ordered by sort_order.
```

From app/Models/SiteSurveyRoom.php (NEW columns from quick 260503-rgg, all nullable):
```
$casts additions:
  mounting_heights         => 'array'   // {screen_h_m, camera_h_m, booking_panel_h_m, speaker_h_m, other:[{label,h_m}]}
  work_at_height_methods   => 'array'   // string[] enum: ladder|podium|tower|mewp|scaffold|na
  cable_routes             => 'array'   // [{category, length_m, from, to, notes}] — category enum (7 vals incl 'ceiling_speakers')
  wall_construction        => 'array'   // string[] enum: ply_lined|solid|plasterboard|masonry|metal_stud|concrete
  wall_needs_reinforcement => 'boolean'
  wall_needs_chase_out     => 'boolean'
  wall_needs_conduit       => 'boolean'
  table_info               => 'array'   // {has_grommets, grommet_count, grommet_size, notes}
  floor_box_info           => 'array'   // {has_floor_box, power_outlets, data_outlets, cable_space, notes}
  brackets_required        => 'array'   // [{equipment, model, pull_out, notes}]
```

From app/Services/ProjectContext/SurveyToProjectContextMapper.php:
```
public static function map(array $surveyData): array
  // Currently reads ONLY $surveyData['rooms'] (the JSON blob) — does NOT see SiteSurveyRoom model rows.
  // The new engineer-feedback fields live on SiteSurveyRoom rows, NOT in survey_data JSON.
  // CRITICAL: this gap is the central architectural decision in Task 1.
```

From app/Services/ProjectContext/ProjectContextBuilder.php:
```
public static function build(SiteSurvey $survey): array
  // Calls SurveyToProjectContextMapper::map($survey->survey_data) — JSON-only path.
  // Has access to the FULL SiteSurvey model (including ->rooms() relation).
  // This is the layer where we can merge SiteSurveyRoom row data into the mapped rooms[].
```

From app/Services/RamsDataBuilderService.php (entry point and existing flow):
```
public function assemble(
    array $parsed, array $classified, array $risk,
    array $methodStatement, array $formData,
    array $projectContext = [],
): array
  // Already integrates ProjectContext via mergeRiskData() + CableScheduleBuilderService.
  // Already exposes 'rooms' top-level key from $projectContext['rooms'].
  // We add: $data['site_logistics'] (top-level) + per-room engineer_feedback inside each rooms[n] entry.
  // We also pass survey-derived hazards via the same mergeRiskData() path that already exists.
```

From app/Services/RiskTemplateResolverService.php:
```
public function resolveFromProjectContext(array $context): array
  // Returns ['hazards' => [{title, description, rooms}], 'ppe' => [], 'access_equipment' => []].
  // Currently inspects: working_height (over_2m|under_2m|ground_level), out_of_hours,
  // permits_required, manual_handling_risk, activities[].
  // We extend it to ALSO inspect new room fields surfaced by the mapper:
  //   work_at_height_methods, mounting_heights (max derived), wall_needs_*, cable_routes (long pulls).
  // Existing logic is UNCHANGED — we only ADD new inspection branches that
  // emit additional hazard map entries.
```

From app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php:
```
Existing global library templates (verified in HazardTemplateSeeder.php) we resolve by name:
  - "Working at Height"                           ← used for ALL three tiers (low/medium/high)
  - "Dust & Debris (Including Drilling)"          ← chase-out wall prep
  - "Fixings / Substrate Failure"                 ← reinforcement wall prep
  - "Hidden Services (Electrical, Plumbing, Gas)" ← conduit/cable routes
  - "Manual Handling"                             ← long cable pulls (>30 m)

NOTE for v1: We resolve to ONE "Working at Height" template; the tier
distinction (LOW/MEDIUM/HIGH) is encoded in the hazard ROW description text
written by resolveFromProjectContext(), not in three separate templates.
This keeps the seeder untouched and avoids a new migration. If future work
wants three separate templates, that's a follow-up quick task.
```

From resources/views/pdf/rams.blade.php (insertion points):
```
Line ~702: <div class="sec-heading page-break">4. &nbsp;Scope of Works</div>
Line ~712: closing </div> of the kv-block
  ↳ INSERT new "Site Logistics" subsection IMMEDIATELY AFTER the kv-block (before the
    "Works Activities" / scope-of-works-bullets block at line ~715).

Line ~724: @if(! empty($roomOverviews) && is_array($roomOverviews))
Line ~730: @foreach($roomOverviews as $roomOv)
  ↳ INSIDE the foreach, AFTER the existing per-room scope paragraph block,
    INSERT a new per-room "Engineer Survey Findings" subsection sourced from
    $data['rooms'] indexed by room name (case-insensitive trim match).
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Surface engineer-feedback room data + site logistics through ProjectContext + RamsDataBuilder</name>
  <files>
    app/Services/ProjectContext/SurveyToProjectContextMapper.php,
    app/Services/RamsDataBuilderService.php
  </files>
  <action>
    GOAL: Make the new engineer-feedback fields reachable in the $data array that flows to the RAMS Blade template and DocxBuilderService — without breaking the existing JSON-payload-driven flow.

    ── ARCHITECTURAL DECISION (locked) ─────────────────────────────────
    The new engineer-feedback fields live on SiteSurveyRoom MODEL ROWS (table columns).
    They do NOT live in the SiteSurvey::survey_data JSON blob that
    SurveyToProjectContextMapper currently consumes.

    Solution: Modify ProjectContextBuilder::build() (already takes a SiteSurvey
    model) to ALSO collect the per-room engineer-feedback blocks from
    $survey->rooms relation, then pass them as a second argument to the mapper.
    The mapper merges them into the existing rooms[] output, indexed by room name
    (case-insensitive trim match — fall back to positional index if names empty).

    This keeps the mapper's existing pure-data signature intact for tests that use
    buildFromPayload(), and makes the model-aware path additive.

    ── 1A. SurveyToProjectContextMapper.php ─────────────────────────────
    A. Add new public static method signature:
         public static function mapWithModelRooms(array $surveyData, $modelRooms = null): array
       Where $modelRooms is a Collection<SiteSurveyRoom>|null. When null,
       behaviour is identical to map() (back-compat).
    B. Refactor: existing map() now becomes a thin wrapper:
         public static function map(array $surveyData): array {
           return self::mapWithModelRooms($surveyData, null);
         }
    C. Inside mapWithModelRooms(), after building the rooms[] array via the
       existing logic, if $modelRooms is not null, build a name-keyed lookup
       (lowercased, trimmed) of model rows. Then for each mapped room, find the
       matching model row and attach a new key 'engineer_feedback' with this shape:
       {
         mounting_heights: array (raw column value, default []),
         work_at_height_methods: array (raw, default []),
         cable_routes: array (raw, default []),
         wall_construction: array (raw, default []),
         wall_needs_reinforcement: bool (default false),
         wall_needs_chase_out: bool (default false),
         wall_needs_conduit: bool (default false),
         table_info: array (raw, default []),
         floor_box_info: array (raw, default []),
         brackets_required: array (raw, default []),
         max_mounting_height_m: float|null  ← derived: max of screen_h_m, camera_h_m, booking_panel_h_m, speaker_h_m, and any other[].h_m. NULL if no heights captured.
       }
       Defensive: if no matching model row is found (e.g. survey_data has
       rooms not yet persisted), the room's 'engineer_feedback' key is set to an
       empty array []. Do NOT omit the key — downstream code checks for !empty().
    D. PHP requirement: the helper that derives max_mounting_height_m must
       handle every value being null/missing → return null. Cast partial values
       via (float) safely. Skip non-numeric "other" rows.

    ── 1B. ProjectContextBuilder.php (lightweight wiring change) ────────
    Modify build() to call the new model-aware mapper method:
      $modelRooms = $survey->rooms; // HasMany — may be empty Collection
      $context = SurveyToProjectContextMapper::mapWithModelRooms($surveyData, $modelRooms);
    Also collect site-level engineer-feedback into a top-level context key
    'site_logistics' (read directly from $survey attributes, not from JSON):
      $context['site_logistics'] = [
        'comms_room_access_status' => (string) ($survey->comms_room_access_status ?? ''),
        'comms_room_access_notes'  => (string) ($survey->comms_room_access_notes  ?? ''),
        'parking_restraints'       => (string) ($survey->parking_restraints       ?? ''),
        'distance_from_base_miles' => $survey->distance_from_base_miles, // may be null
        'distance_from_base_notes' => (string) ($survey->distance_from_base_notes ?? ''),
        'site_access_notes'        => (string) ($survey->site_access_notes        ?? ''),
        'delivery_routes'          => (string) ($survey->delivery_routes          ?? ''),
      ];
    NOTE: ProjectContextBuilder.php is one of the 4 files we're allowed to
    touch — it's NOT in files_modified frontmatter because the change is so
    surgical (3 lines added). UPDATE files_modified to include it if the
    diff exceeds 5 lines. Most likely it stays at exactly 3 lines.
    ─── REVISION ─── ProjectContextBuilder.php IS modified by this task.
        Add it to your final upload list even though it's not in frontmatter
        — the constraint says 4-6 files modified, and this is file #5.

    ── 1C. RamsDataBuilderService.php ───────────────────────────────────
    A. Inside assemble(), after the existing $contextRooms = ... line, also
       extract:
         $siteLogistics = (array) ($projectContext['site_logistics'] ?? []);
    B. Add to the $data array literal (alongside 'rooms' on line ~86):
         'site_logistics' => $siteLogistics,
    C. NORMALISE: in normalise(), add a new section after the 'rooms' normaliser:
         // ── site_logistics ───────────────────────────────────────────
         $sl = is_array($data['site_logistics'] ?? null) ? $data['site_logistics'] : [];
         $data['site_logistics'] = [
             'comms_room_access_status' => (string) ($sl['comms_room_access_status'] ?? ''),
             'comms_room_access_notes'  => (string) ($sl['comms_room_access_notes']  ?? ''),
             'parking_restraints'       => (string) ($sl['parking_restraints']       ?? ''),
             'distance_from_base_miles' => $sl['distance_from_base_miles'] ?? null, // keep null/scalar
             'distance_from_base_notes' => (string) ($sl['distance_from_base_notes'] ?? ''),
             'site_access_notes'        => (string) ($sl['site_access_notes']        ?? ''),
             'delivery_routes'          => (string) ($sl['delivery_routes']          ?? ''),
         ];
    D. The 'rooms' key is already pass-through. Each mapped room now carries
       the 'engineer_feedback' nested array (from Task 1A). No further work
       needed in dataBuilder for the per-room engineer-feedback block — the
       Blade template (Task 3) reads $data['rooms'][n]['engineer_feedback']
       directly.
    E. CRITICAL — buildFromReview() in RamsBuilderService already injects a
       $data['site_logistics'] = (array) ($reviewedData['site_logistics'] ?? []) line at
       ~226. Our new normaliser MUST run BEFORE that injection clobbers it.
       Look at runFromReview() flow: assemble() runs FIRST, then buildFromReview()
       overwrites $data['site_logistics']. So the priority is:
         reviewed_data.site_logistics  (if non-empty array)  >  survey-derived site_logistics
       This is correct behaviour — manually-reviewed data should win. To make
       this explicit, MODIFY runFromReview() at line ~226 to be:
         $reviewedSiteLogistics = (array) ($reviewedData['site_logistics'] ?? []);
         if (! empty($reviewedSiteLogistics)) {
             $data['site_logistics'] = $reviewedSiteLogistics;
         }
         // else: keep the survey-derived $data['site_logistics'] from assemble()
       This preserves existing behaviour for projects with reviewed_data.site_logistics
       set, AND lights up the new survey path for projects without it.

    ── DEFENSIVE: BACKWARDS COMPATIBILITY ───────────────────────────────
    If a SiteSurveyRoom row exists but every new column is NULL, the
    'engineer_feedback' block is built but every value is empty/false/null.
    The Blade template (Task 3) MUST check for any populated value before
    rendering the section.

    If $modelRooms is empty/null (project has survey_data JSON but no DB rows
    — legacy surveys), the mapper falls back to the existing JSON-only path
    and 'engineer_feedback' key is empty []. Same Blade-side guard handles this.

    No try/catch needed in the mapper — failures here would point to
    misconfigured Eloquent relations and SHOULD bubble up.
  </action>
  <verify>
    <automated>php -l app/Services/ProjectContext/SurveyToProjectContextMapper.php &amp;&amp; php -l app/Services/ProjectContext/ProjectContextBuilder.php &amp;&amp; php -l app/Services/RamsDataBuilderService.php &amp;&amp; php -l app/Services/RamsBuilderService.php</automated>
    Manual tinker check (run after task completes):
      `php artisan tinker --execute="\$s = \App\Models\SiteSurvey::has('rooms')->latest()->first(); \$ctx = \App\Services\ProjectContext\ProjectContextBuilder::build(\$s); var_export(array_keys(\$ctx)); var_export(\$ctx['site_logistics'] ?? 'MISSING'); var_export(\$ctx['rooms'][0]['engineer_feedback'] ?? 'NO_EF');"`
    Expected: keys include 'site_logistics' AND first room has 'engineer_feedback' key (may be []).
  </verify>
  <done>
    1. `php -l` passes on all 4 PHP files touched.
    2. SurveyToProjectContextMapper exposes both map() (legacy, JSON-only) and mapWithModelRooms() (new, model-aware).
    3. ProjectContextBuilder::build() now calls mapWithModelRooms() with $survey->rooms and attaches 'site_logistics' to the returned context.
    4. RamsDataBuilderService::assemble() exposes $data['site_logistics'] and per-room $data['rooms'][n]['engineer_feedback'].
    5. RamsBuilderService::buildFromReview() preserves reviewed_data.site_logistics priority over survey-derived (no regression).
    6. Tinker spot-check confirms data flows end-to-end.
  </done>
</task>

<task type="auto">
  <name>Task 2: Auto-classify engineer-feedback hazards in RiskTemplateResolverService</name>
  <files>
    app/Services/RiskTemplateResolverService.php
  </files>
  <action>
    GOAL: When a project's survey contains engineer-feedback signals (work-at-height methods, mounting heights, wall-prep flags, long cable routes), additional hazards are merged into the RAMS hazard register automatically — using EXISTING global library templates, no seeder changes.

    ── HAZARD TEMPLATE INVENTORY (verified against HazardTemplateSeeder.php) ─
    These templates exist globally and we resolve to them by name (the
    mergeHazard() helper in resolveFromProjectContext keys by title — we
    use template names verbatim so DocxBuilder/Blade can match them):
      ✓ "Working at Height"                           — used for ALL 3 tiers (low/med/high)
      ✓ "Dust & Debris (Including Drilling)"          — chase-out hazard
      ✓ "Fixings / Substrate Failure"                 — wall reinforcement hazard
      ✓ "Hidden Services (Electrical, Plumbing, Gas)" — conduit installation hazard
      ✓ "Manual Handling"                             — long cable pulls (>30 m)
    NO new templates needed for v1. The TIER (LOW/MED/HIGH) is encoded in the
    hazard description text written into the merged hazard map — not in three
    separate templates. This is documented in the task summary.

    ── EXTEND resolveFromProjectContext() ───────────────────────────────
    Inside the existing foreach ($rooms as $room) loop, AFTER the existing
    permitsRequired branch, ADD the following inspection branches. All branches
    are DEFENSIVE: missing or empty 'engineer_feedback' → no hazards added.

    A. Extract engineer feedback block (defensive):
       $ef = (array) ($room['engineer_feedback'] ?? []);
       if (empty($ef)) continue; // no engineer data → skip new branches entirely

    B. Working at height tier (HIGH > MEDIUM > LOW, mutually exclusive):
       $methods = array_map('strtolower', (array) ($ef['work_at_height_methods'] ?? []));
       $maxH    = $ef['max_mounting_height_m'] ?? null; // float|null

       $isHigh   = in_array('mewp', $methods, true) || in_array('scaffold', $methods, true)
                   || ($maxH !== null && $maxH > 4.0);
       $isMedium = !$isHigh && (in_array('tower', $methods, true)
                   || ($maxH !== null && $maxH > 2.0));
       $isLow    = !$isHigh && !$isMedium && (in_array('ladder', $methods, true)
                   || in_array('podium', $methods, true)
                   || ($maxH !== null && $maxH <= 2.0 && $maxH > 0));

       // 'na' alone (or empty) → no working-at-height hazard auto-added.
       if ($isHigh) {
           $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
               'HIGH-tier working at height: MEWP or scaffold required, OR mounting points above 4 m. ' .
               'Use only trained operators for MEWP. Erect rescue plan; barrier exclusion zone below works. ' .
               'Maintain three-point contact and harness where required.');
           $allPpe[]         = 'Hard Hat';
           $allPpe[]         = 'Safety Harness';
           $allAccessEquip[] = 'MEWP (operator certified)';
           $allAccessEquip[] = 'Scaffold (if specified)';
       } elseif ($isMedium) {
           $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
               'MEDIUM-tier working at height: access tower OR mounting points 2 m – 4 m. ' .
               'Tower must be erected by competent person. Barrier zone below works.');
           $allPpe[]         = 'Hard Hat';
           $allAccessEquip[] = 'Access Tower';
       } elseif ($isLow) {
           $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
               'LOW-tier working at height: ladder, podium, or mounting at or below 2 m. ' .
               'Maintain three points of contact; do not over-reach.');
           $allPpe[]         = 'Hard Hat';
           $allAccessEquip[] = 'Podium Steps';
       }
       // 'na' or no signal → no override (existing under_2m / over_2m logic from
       // primaryRisk still runs above and may add its own row).

    C. Wall prep hazards (each independent):
       if (! empty($ef['wall_needs_chase_out'])) {
           $this->mergeHazard($hazardMap, $roomName, 'Dust & Debris (Including Drilling)',
               'Wall chasing required for cable conduit. High dust generation. ' .
               'Use FFP3 dust mask, on-tool extraction, and seal off occupied areas.');
           $allPpe[] = 'Dust Mask (FFP3)';
       }
       if (! empty($ef['wall_needs_reinforcement'])) {
           $this->mergeHazard($hazardMap, $roomName, 'Fixings / Substrate Failure',
               'Wall reinforcement required to safely carry mounted equipment load. ' .
               'Confirm structural fixings rated for full bracket + display weight; CAT-and-Genny scan before drilling.');
       }
       if (! empty($ef['wall_needs_conduit'])) {
           $this->mergeHazard($hazardMap, $roomName, 'Hidden Services (Electrical, Plumbing, Gas)',
               'Conduit installation requires drilling through wall structure. ' .
               'Mandatory CAT-and-Genny scan; obtain services drawings before any penetration.');
       }

    D. Long cable pulls (manual handling):
       $cableRoutes = (array) ($ef['cable_routes'] ?? []);
       $hasLongPull = false;
       foreach ($cableRoutes as $cr) {
           $len = (float) (is_array($cr) ? ($cr['length_m'] ?? 0) : 0);
           if ($len > 30.0) { $hasLongPull = true; break; }
       }
       if ($hasLongPull) {
           $this->mergeHazard($hazardMap, $roomName, 'Manual Handling',
               'Long cable pull (>30 m) creates manual handling risk. ' .
               'Two-person team; use cable rollers / pulling lubricant; take rest breaks.');
       }

    ── DEDUPLICATION ─────────────────────────────────────────────────────
    The existing mergeHazard() helper already merges duplicate titles (it appends
    the room name to the rooms[] list when the title already exists). So if a
    room triggers both "Working at Height" via primaryRisk['working_height']==='over_2m'
    AND via $ef['max_mounting_height_m']>4, only ONE hazard row results — but the
    LATER call's description text wins. To preserve the first description but
    still register the room, the new branches should ALWAYS run AFTER the existing
    primaryRisk branches. (They already do — the foreach order is preserved
    because we add new branches at the END of the loop body.) Only ADD new
    description text if the title was NOT already in the map:
      ── REVISION ── To keep behaviour predictable, mergeHazard() already
      preserves the FIRST description (it only appends the new room name on
      duplicate-title calls). So the existing helper is fine — the new branches
      just call mergeHazard() and let it dedupe by title.

    ── DOWNSTREAM FLOW ───────────────────────────────────────────────────
    These hazards flow through:
      RiskTemplateResolverService::resolveFromProjectContext()
        → returns to RamsDataBuilderService::assemble() (existing call site)
        → mergeRiskData() converts {title,description,rooms} to RAMS hazard rows
        → $data['hazards'] picked up by Blade + DocxBuilder
    No change needed in mergeRiskData() — its existing conversion handles the
    new hazard entries identically to the old ones (default L=3, S=3, post=2/2;
    description becomes the first control measure).

    ── CONSTRAINT: REVERSIBILITY ─────────────────────────────────────────
    The new branches are guarded by `if (empty($ef)) continue;`. Surveys
    captured BEFORE quick task 260503-rgg landed have no engineer_feedback
    block (Task 1 guarantees `[]` in that case). So the auto-classification is
    a strict no-op on legacy surveys.
  </action>
  <verify>
    <automated>php -l app/Services/RiskTemplateResolverService.php</automated>
    Manual tinker (run after task complete):
      `php artisan tinker --execute="\$s = \App\Models\SiteSurvey::has('rooms')->latest()->first(); \$ctx = \App\Services\ProjectContext\ProjectContextBuilder::build(\$s); \$r = app(\App\Services\RiskTemplateResolverService::class)->resolveFromProjectContext(\$ctx); var_export(array_column(\$r['hazards'], 'title'));"`
    Expected: list includes "Working at Height" if any room has work_at_height_methods or mounting heights set; includes wall-prep hazard names if those flags are true.
  </verify>
  <done>
    1. `php -l` passes.
    2. resolveFromProjectContext() now inspects $room['engineer_feedback'] for working-at-height tier, wall-prep flags, and long cable pulls.
    3. The four resolved hazards use existing global template names verbatim (Working at Height / Dust &amp; Debris / Fixings / Hidden Services / Manual Handling).
    4. Surveys without engineer_feedback (legacy) are a no-op — no new hazards added.
    5. Tinker spot-check confirms hazards appear when survey data is present.
  </done>
</task>

<task type="auto">
  <name>Task 3: Render Site Logistics block + per-room Engineer Survey Findings in rams.blade.php</name>
  <files>
    resources/views/pdf/rams.blade.php
  </files>
  <action>
    GOAL: Display the new survey-captured data in the rendered RAMS PDF in two places:
      A) A "Site Logistics" subsection inside Section 4 (Scope of Works) — site-level fields
      B) A "Engineer Survey Findings" subsection inside each room's existing block — per-room fields

    Note: We are intentionally NOT touching DocxBuilderService.php in this v1.
    Reason: DocxBuilder is 1175 lines and adding two new section builders would push
    this task past the 4-6 file footprint cap. The Blade template renders the PDF
    download (which is the engineer's primary on-site reference). DOCX rendering of
    the same data is a clean follow-up quick task that can mirror the Blade structure.
    Document this in the task summary as an explicit deferred-to-follow-up item.

    ── 3A. SITE LOGISTICS BLOCK (insert after line ~712, BEFORE scope-of-works-bullets) ──
    Locate the closing </div> of the kv-block (after the Working Hours / Waste Removal lines).
    Insert this block IMMEDIATELY after that closing </div> and BEFORE the
    `@if(! empty($data['scope_of_works_bullets']))` block at line ~715:

    ```blade
    @php
        $siteLog = $data['site_logistics'] ?? [];
        $hasSiteLog = is_array($siteLog) && (
            ! empty($siteLog['comms_room_access_status']) ||
            ! empty($siteLog['comms_room_access_notes']) ||
            ! empty($siteLog['parking_restraints']) ||
            ! empty($siteLog['distance_from_base_miles']) ||
            ! empty($siteLog['distance_from_base_notes']) ||
            ! empty($siteLog['site_access_notes']) ||
            ! empty($siteLog['delivery_routes'])
        );
        $commsLabels = [
            'yes'         => 'Permission required',
            'no'          => 'Free access',
            'outsourced'  => 'Outsourced facilities team',
            'unknown'     => 'Status unknown',
        ];
    @endphp
    @if($hasSiteLog)
    <div class="sec-subheading" style="margin-top:8pt;">Site Logistics &amp; Access (from site survey)</div>
    <table class="std-table">
        <tbody>
            @if(! empty($siteLog['parking_restraints']))
                <tr><td class="lbl" style="width:30%;">Parking arrangements:</td>
                    <td>{{ $siteLog['parking_restraints'] }}</td></tr>
            @endif
            @if(! empty($siteLog['site_access_notes']))
                <tr><td class="lbl">Site access notes:</td>
                    <td>{{ $siteLog['site_access_notes'] }}</td></tr>
            @endif
            @if(! empty($siteLog['delivery_routes']))
                <tr><td class="lbl">Delivery routes:</td>
                    <td>{{ $siteLog['delivery_routes'] }}</td></tr>
            @endif
            @if(! empty($siteLog['comms_room_access_status']) || ! empty($siteLog['comms_room_access_notes']))
                @php
                    $commsStatus = $commsLabels[$siteLog['comms_room_access_status'] ?? ''] ?? '';
                    $commsParts = array_filter([$commsStatus, $siteLog['comms_room_access_notes'] ?? '']);
                @endphp
                <tr><td class="lbl">Comms room access:</td>
                    <td>{{ implode(' — ', $commsParts) }}</td></tr>
            @endif
            @if(! empty($siteLog['distance_from_base_miles']) || ! empty($siteLog['distance_from_base_notes']))
                @php
                    $distParts = array_filter([
                        ! empty($siteLog['distance_from_base_miles']) ? $siteLog['distance_from_base_miles'] . ' miles from depot' : '',
                        $siteLog['distance_from_base_notes'] ?? '',
                    ]);
                @endphp
                <tr><td class="lbl">Distance from depot:</td>
                    <td>{{ implode(' — ', $distParts) }}</td></tr>
            @endif
        </tbody>
    </table>
    @endif
    ```

    ── 3B. PER-ROOM ENGINEER FINDINGS — build lookup ─────────────────────
    Inside the $data['rooms'] indexing for the per-room block:
    The existing room loop (line ~730) iterates `$roomOverviews` (from
    reviewed_data), NOT $data['rooms'] (from ProjectContext). We need a
    separate, room-name-indexed lookup so we can find the engineer_feedback
    block for each $roomOverviews iteration.

    Add this @php block IMMEDIATELY BEFORE the `@if(! empty($roomOverviews) && is_array($roomOverviews))` line at ~724:

    ```blade
    @php
        // Build a lookup from room name → engineer_feedback block (from ProjectContext)
        $efByRoom = [];
        foreach ((array) ($data['rooms'] ?? []) as $ctxRoom) {
            $key = strtolower(trim((string) ($ctxRoom['name'] ?? '')));
            $ef  = (array) ($ctxRoom['engineer_feedback'] ?? []);
            if ($key !== '' && ! empty($ef)) {
                $efByRoom[$key] = $ef;
            }
        }
        $methodLabels = [
            'ladder'   => 'Ladder',
            'podium'   => 'Podium steps',
            'tower'    => 'Access tower',
            'mewp'     => 'MEWP',
            'scaffold' => 'Scaffold',
            'na'       => 'Not required',
        ];
        $wallConstructionLabels = [
            'ply_lined'    => 'Ply-lined',
            'solid'        => 'Solid wall',
            'plasterboard' => 'Plasterboard',
            'masonry'      => 'Masonry / brick',
            'metal_stud'   => 'Metal stud',
            'concrete'     => 'Concrete',
        ];
        $cableCategoryLabels = [
            'screen'           => 'Screen / display',
            'camera'           => 'Camera',
            'booking_panel'    => 'Booking panel',
            'speaker'          => 'Speaker',
            'ceiling_speakers' => 'Ceiling speakers',
            'floor_box'        => 'Floor box',
            'rack'             => 'Rack',
        ];
    @endphp
    ```
    NOTE: cableCategoryLabels covers the 7 enum values from the SiteSurveyController
    validation. Verify the exact 7 strings against SiteSurveyController::validateSurvey()
    when implementing — fall back to humanising unknown keys via
    `ucwords(str_replace('_',' ', $key))`.

    ── 3C. PER-ROOM ENGINEER FINDINGS — render block ─────────────────────
    Inside the existing `@foreach($roomOverviews as $roomOv)` loop (starts ~730),
    AFTER the existing scope-paragraph render (look for the closing of the
    bullets-vs-prose render block), INSERT the engineer findings block.
    Find the right insertion point by locating the `@endforeach` for $roomOverviews
    (likely around line ~880 — verify by reading rams.blade.php in that range
    before editing). Place the new block JUST BEFORE the @endforeach.

    Render template:
    ```blade
    @php
        $rvKey = strtolower(trim((string) ($rvName ?? '')));
        $ef = $efByRoom[$rvKey] ?? [];
        $hasEF = ! empty($ef) && (
            ! empty($ef['mounting_heights']) ||
            ! empty($ef['work_at_height_methods']) ||
            ! empty($ef['cable_routes']) ||
            ! empty($ef['wall_construction']) ||
            ! empty($ef['wall_needs_reinforcement']) ||
            ! empty($ef['wall_needs_chase_out']) ||
            ! empty($ef['wall_needs_conduit']) ||
            ! empty($ef['brackets_required']) ||
            ! empty($ef['table_info']) ||
            ! empty($ef['floor_box_info'])
        );
    @endphp
    @if($hasEF)
        <div class="sec-subheading" style="margin-top:6pt;">Engineer Survey Findings — {{ $rvName }}</div>

        {{-- Mounting heights --}}
        @php
            $mh    = (array) ($ef['mounting_heights'] ?? []);
            $heightRows = [];
            foreach (['screen_h_m' => 'Screen', 'camera_h_m' => 'Camera',
                      'booking_panel_h_m' => 'Booking panel', 'speaker_h_m' => 'Speaker'] as $k => $lbl) {
                if (! empty($mh[$k])) { $heightRows[] = $lbl . ': ' . $mh[$k] . ' m'; }
            }
            foreach ((array) ($mh['other'] ?? []) as $other) {
                $oLbl = trim((string) ($other['label'] ?? ''));
                $oH   = $other['h_m'] ?? null;
                if ($oLbl !== '' && $oH !== null) { $heightRows[] = $oLbl . ': ' . $oH . ' m'; }
            }
        @endphp
        @if(! empty($heightRows))
            <p class="body-para"><strong>Installation heights:</strong> {{ implode(' • ', $heightRows) }}</p>
        @endif

        {{-- Working at height methods --}}
        @php
            $wahLabels = array_values(array_filter(array_map(
                fn ($m) => $methodLabels[$m] ?? ucfirst($m),
                (array) ($ef['work_at_height_methods'] ?? [])
            )));
        @endphp
        @if(! empty($wahLabels))
            <p class="body-para"><strong>Working at height — methods on site:</strong> {{ implode(', ', $wahLabels) }}</p>
        @endif

        {{-- Cable routes --}}
        @php $cableRoutes = (array) ($ef['cable_routes'] ?? []); @endphp
        @if(! empty($cableRoutes))
            <p class="body-para" style="margin-bottom:2pt;"><strong>Cable routes planned:</strong></p>
            <ul class="blist">
                @foreach($cableRoutes as $cr)
                    @php
                        $cat = $cableCategoryLabels[$cr['category'] ?? ''] ?? ucwords(str_replace('_', ' ', (string) ($cr['category'] ?? '')));
                        $len = ! empty($cr['length_m']) ? ($cr['length_m'] . ' m') : '';
                        $from = trim((string) ($cr['from'] ?? ''));
                        $to   = trim((string) ($cr['to']   ?? ''));
                        $route = ($from && $to) ? ($from . ' → ' . $to) : ($from ?: $to);
                        $note = trim((string) ($cr['notes'] ?? ''));
                        $parts = array_filter([$cat, $route, $len, $note]);
                    @endphp
                    <li>{{ implode(' — ', $parts) }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Wall construction & prep --}}
        @php
            $wcLabels = array_values(array_filter(array_map(
                fn ($w) => $wallConstructionLabels[$w] ?? ucwords(str_replace('_', ' ', (string) $w)),
                (array) ($ef['wall_construction'] ?? [])
            )));
            $prepFlags = [];
            if (! empty($ef['wall_needs_reinforcement'])) $prepFlags[] = 'Reinforcement required';
            if (! empty($ef['wall_needs_chase_out']))     $prepFlags[] = 'Chase-out required';
            if (! empty($ef['wall_needs_conduit']))       $prepFlags[] = 'Conduit installation required';
        @endphp
        @if(! empty($wcLabels) || ! empty($prepFlags))
            <p class="body-para">
                <strong>Wall construction:</strong>
                {{ ! empty($wcLabels) ? implode(', ', $wcLabels) : '—' }}
                @if(! empty($prepFlags))
                    <br><strong>Prep needed:</strong> {{ implode(', ', $prepFlags) }}
                @endif
            </p>
        @endif

        {{-- Brackets to source --}}
        @php $brackets = (array) ($ef['brackets_required'] ?? []); @endphp
        @if(! empty($brackets))
            <p class="body-para" style="margin-bottom:2pt;"><strong>Brackets to source:</strong></p>
            <ul class="blist">
                @foreach($brackets as $b)
                    @php
                        $eq = trim((string) ($b['equipment'] ?? ''));
                        $mod= trim((string) ($b['model']     ?? ''));
                        $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                        $note = trim((string) ($b['notes'] ?? ''));
                        $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                        if ($note !== '') $line .= ' — ' . $note;
                    @endphp
                    @if($line !== '')
                        <li>{{ $line }}</li>
                    @endif
                @endforeach
            </ul>
        @endif

        {{-- Table & floor box info (compact, single line each if present) --}}
        @php
            $ti = (array) ($ef['table_info'] ?? []);
            $hasTable = ! empty($ti) && (! empty($ti['has_grommets']) || ! empty($ti['notes']));
            $fb = (array) ($ef['floor_box_info'] ?? []);
            $hasFb = ! empty($fb) && (! empty($fb['has_floor_box']) || ! empty($fb['notes']));
        @endphp
        @if($hasTable)
            @php
                $grommetSizeMap = ['small' => 'small', 'standard' => 'standard', 'large' => 'large'];
                $tParts = [];
                if (! empty($ti['has_grommets'])) {
                    $tParts[] = ($ti['grommet_count'] ?? '?') . '× ' . ($grommetSizeMap[$ti['grommet_size'] ?? ''] ?? '') . ' grommets';
                }
                if (! empty($ti['notes'])) $tParts[] = $ti['notes'];
            @endphp
            <p class="body-para"><strong>Table:</strong> {{ implode(' — ', $tParts) }}</p>
        @endif
        @if($hasFb)
            @php
                $cableSpaceMap = ['tight' => 'tight', 'adequate' => 'adequate', 'spacious' => 'spacious'];
                $fParts = [];
                if (! empty($fb['has_floor_box'])) {
                    $fParts[] = ($fb['power_outlets'] ?? 0) . ' power, ' . ($fb['data_outlets'] ?? 0) . ' data';
                    if (! empty($fb['cable_space'])) $fParts[] = ($cableSpaceMap[$fb['cable_space']] ?? '') . ' cable space';
                }
                if (! empty($fb['notes'])) $fParts[] = $fb['notes'];
            @endphp
            <p class="body-para"><strong>Floor box:</strong> {{ implode(' — ', $fParts) }}</p>
        @endif
    @endif
    ```

    ── DEFENSIVE NULL HANDLING (constraint requirement) ─────────────────
    - Every block guarded by `! empty()` — the entire "Engineer Survey Findings"
      header is omitted when no fields populated for that room.
    - Same for the site-level Site Logistics block — header hidden when no
      fields present.
    - No "—" or "N/A" placeholders for the engineer feedback blocks (only used
      where the existing template already does, e.g., site_address fallbacks).
    - Existing scope/programme rendering UNCHANGED — the new blocks are
      wedged between existing blocks without modifying any existing markup.

    ── VERIFY INSERTION POINTS BEFORE EDITING ────────────────────────────
    Before writing the edits, READ rams.blade.php lines 700-880 to confirm:
      - exact location of the kv-block closing </div>
      - exact location of the @foreach($roomOverviews) loop and its @endforeach
      - the variable name $rvName (NOT $rv_name or $roomName) is the loop variable
        used for the room name in the existing template (verified at line 733).
  </action>
  <verify>
    <automated>php artisan view:clear &amp;&amp; php artisan view:cache</automated>
    Render-smoke (after task complete):
      `php artisan tinker --execute="\$r = \App\Models\RamsDocument::latest()->first(); var_export(view('pdf.rams', ['rams' => \$r, 'data' => \$r->generated_data ?? []])->render() ? 'render-ok' : 'render-fail');"`
    Expected: 'render-ok'. View cache compiles cleanly with no Blade syntax errors.
  </verify>
  <done>
    1. Blade view caches successfully (no syntax errors).
    2. Site Logistics subsection inserted in Section 4 (Scope of Works), guarded by $hasSiteLog.
    3. Engineer Survey Findings subsection inserted inside the per-room loop, keyed by case-insensitive room name match.
    4. All sub-blocks (heights / methods / cable routes / wall / brackets / table / floor box) guarded by individual @if(! empty()) checks.
    5. Render smoke-test on latest RAMS produces output (no exceptions).
    6. Existing template markup UNCHANGED — only insertion of new blocks.
  </done>
</task>

</tasks>

<verification>
## Pure Additive Audit

Before declaring the task complete, run these greps to confirm we haven't accidentally modified anything we shouldn't have:

```bash
# Confirm forbidden services UNCHANGED
git diff --stat HEAD -- app/Services/MethodStatementGeneratorService.php
git diff --stat HEAD -- app/Core/Modules/Survey/SurveyService.php
git diff --stat HEAD -- app/Services/InstallTaskGeneratorService.php
git diff --stat HEAD -- app/Services/OmManualDocxService.php
git diff --stat HEAD -- app/Services/RamsExtractionDraftBuilderService.php
git diff --stat HEAD -- resources/views/site-survey/
git diff --stat HEAD -- resources/views/worksheets/
git diff --stat HEAD -- app/Http/Controllers/PublicWorksheetController.php
# All five lines above MUST output empty (or no such file).

# Confirm we touched only the expected files (4 in frontmatter + ProjectContextBuilder = 5 max)
git diff --name-only HEAD
# Expected output (any subset):
#   app/Services/ProjectContext/SurveyToProjectContextMapper.php
#   app/Services/ProjectContext/ProjectContextBuilder.php   ← added during Task 1B
#   app/Services/RamsDataBuilderService.php
#   app/Services/RamsBuilderService.php                     ← added during Task 1C step E
#   app/Services/RiskTemplateResolverService.php
#   resources/views/pdf/rams.blade.php
# That's 6 files maximum — within 4-6 cap.

# PHP lint everything we touched
php -l app/Services/ProjectContext/SurveyToProjectContextMapper.php
php -l app/Services/ProjectContext/ProjectContextBuilder.php
php -l app/Services/RamsDataBuilderService.php
php -l app/Services/RamsBuilderService.php
php -l app/Services/RiskTemplateResolverService.php
php artisan view:clear && php artisan view:cache
```

## Manual Verification Checklist (constraint requirement)

To be performed by the user after the task ships locally:

1. **Pick a project that has a completed site survey AND a generated RAMS.** Easiest path: open `/projects` in the app, find one with both icons (survey complete + RAMS generated).
2. **Edit the survey, fill in engineer-feedback fields:** open the site survey edit page, scroll to "Site Logistics" — fill in parking_restraints (e.g. "Loading bay 3, max 30 min"). Open one room, scroll to "Mounting Heights" — set Screen height = 3.5 m. Tick wall_needs_chase_out. Scroll to "Cable Routes" — add one row: category=screen, length_m=35, from=rack, to=display. Save.
3. **Regenerate the RAMS for that project** (via the RAMS show page → Regenerate button, OR via the project page if available).
4. **Wait for the queue to process** the BuildRamsDocumentJob (check `php artisan queue:work` is running locally, or run `php artisan queue:work --once`).
5. **Download the RAMS PDF** and verify:
   - [ ] Section 4 (Scope of Works) shows a "Site Logistics & Access" subsection containing the parking text.
   - [ ] The room block for the room you edited shows an "Engineer Survey Findings" subsection.
   - [ ] Within that, "Installation heights" lists "Screen: 3.5 m".
   - [ ] "Cable routes planned" lists the screen route from rack → display, 35 m.
   - [ ] "Wall construction" or "Prep needed" mentions "Chase-out required".
   - [ ] Section 5 (Risk Assessment) hazard table now contains:
     - "Working at Height" row (because mounting > 2 m → MEDIUM tier auto-classified)
     - "Dust & Debris (Including Drilling)" row (because chase-out flag is true)
     - "Manual Handling" row (because cable length 35 m > 30 m threshold)
6. **Negative regression test** — pick a different project whose latest survey has NULL in all the new fields (any project from before quick task 260503-rgg landed). Regenerate that RAMS. Verify:
   - [ ] No "Site Logistics & Access" subsection appears.
   - [ ] No "Engineer Survey Findings" subsection appears in any room block.
   - [ ] Hazard table is identical to what it produced before this change (no spurious "Working at Height" auto-additions).

## What Was Deliberately NOT Done (deferred)

- **DocxBuilderService.php NOT modified.** The DOCX rendering of these new sections is a clean follow-up quick task (call it `260504-xxx-mirror-engineer-feedback-in-docx`). The DOCX builder is 1175 lines with mature section-builders; adding two new ones is straightforward but pushes this task past the 4-6 file cap. The Blade-rendered PDF is the engineer's primary on-site reference, so the immediate engineer-feedback need is met.
- **No new HazardTemplate seeder entries.** All 5 auto-classified hazards resolve to existing global library templates by name. If the team wants three SEPARATE Working at Height templates (LOW / MEDIUM / HIGH), that's a separate quick task that introduces a small migration + seeder addendum.
- **No tests.** Per constraint: "skip — pure additive integration work, manually verifiable."
</verification>

<success_criteria>
- All 5 PHP files pass `php -l`
- `php artisan view:cache` compiles rams.blade.php without Blade syntax errors
- `git diff --stat` against forbidden files (MethodStatement, Survey, InstallTask, OmManual, RamsExtractionDraft, site-survey views, worksheets, PublicWorksheetController) returns EMPTY
- Touched file count is ≤ 6 (within 4-6 cap with one explicit overshoot for the small RamsBuilderService priority-preservation tweak)
- Tinker spot-check: `ProjectContextBuilder::build($surveyWithRoomData)` returns context with `site_logistics` key populated and `rooms[0].engineer_feedback` non-empty
- Tinker spot-check: `RiskTemplateResolverService::resolveFromProjectContext($context)` returns hazards with titles like "Working at Height", "Dust & Debris (Including Drilling)" when the survey has those signals
- Render smoke-test passes: `view('pdf.rams', [...])->render()` does not throw on a latest RamsDocument
- Manual verification checklist (6 items above) all green when run by user
- Negative regression test passes: a project with no engineer-feedback data renders an unchanged RAMS
</success_criteria>

<output>
After completion, create `.planning/quick/260503-tfb-wire-site-survey-engineer-feedback-field/260503-tfb-SUMMARY.md` documenting:
- Commit hashes (one per task)
- Final file diff stats
- Manual verification checklist results
- Files-to-upload-to-live list (per local-edit-then-upload memory)
- Explicit list of forbidden-file diff outputs (proving constraint satisfaction)
- The deferred follow-up quick task name for DocxBuilderService mirror
</output>
