---
phase: quick-260503-rgg
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php
  - app/Models/SiteSurvey.php
  - app/Models/SiteSurveyRoom.php
  - app/Http/Controllers/SiteSurveyController.php
  - app/Core/Modules/Survey/SurveyService.php
  - resources/views/site-survey/edit.blade.php
  - resources/views/site-survey/_room-form.blade.php
autonomous: true
requirements:
  - QT-260503-rgg-01  # Site-level: comms room access, parking, distance, site access notes, delivery routes
  - QT-260503-rgg-02  # Room-level: mounting heights, work-at-height methods, cable routes, wall construction
  - QT-260503-rgg-03  # Room-level: wall prep flags, table info, floor box info, brackets list
must_haves:
  truths:
    - "Engineer can record comms-room-access permission status (yes/no/outsourced + free-text notes) on the site-level form"
    - "Engineer can record parking restraints, distance from base (miles + notes), site-access notes, and delivery routes per site"
    - "Engineer can record mounting heights for screen / camera / booking-panel / speaker per room, plus arbitrary other-equipment height rows"
    - "Engineer can multi-select working-at-height methods (ladder / podium / tower / MEWP / scaffold / N/A) per room"
    - "Engineer can add multiple cable-route rows per room (category + from + to + length_m + notes), add and remove rows live"
    - "Engineer can multi-select wall construction types (ply_lined / solid / plasterboard / masonry / metal_stud / concrete) per room"
    - "Engineer can flag wall reinforcement / chase-out / conduit needed per room"
    - "Engineer can record table grommet info (presence, count, size, notes) and floor-box info (presence, power outlets, data outlets, cable space, notes) per room"
    - "Engineer can add multiple bracket rows per room (equipment + model + pull-out flag + notes), add and remove rows live"
    - "All new fields are nullable — engineer fills only what's relevant; existing surveys load + save unchanged"
    - "Room measurement label/placeholder copy is clearer so engineer knows W/D/H + ceiling-height capture is mandatory in practice (no schema change)"
  artifacts:
    - path: "database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php"
      provides: "Schema additions: 5 nullable site-level columns + 9 nullable room-level columns (3 booleans + 6 JSON)"
      contains: "Schema::table('site_surveys'"
    - path: "app/Models/SiteSurvey.php"
      provides: "Fillable + casts extended for new site-level columns"
      contains: "comms_room_access"
    - path: "app/Models/SiteSurveyRoom.php"
      provides: "Fillable + casts extended for new room-level columns (booleans + array casts on JSON columns)"
      contains: "mounting_heights"
    - path: "app/Http/Controllers/SiteSurveyController.php"
      provides: "validateSurvey() extended with nullable rules for every new site- and room-level field"
      contains: "comms_room_access_status"
    - path: "app/Core/Modules/Survey/SurveyService.php"
      provides: "update() persists new site-level fields; roomAttributes() persists new room-level fields"
      contains: "mounting_heights"
    - path: "resources/views/site-survey/edit.blade.php"
      provides: "New 'Site Logistics' .form-section card with 5 new site-level inputs, inserted after Project Manager fieldset"
      contains: "Site Logistics"
    - path: "resources/views/site-survey/_room-form.blade.php"
      provides: "New .form-section blocks for mounting heights, work-at-height methods, cable routes (Alpine multi-row), wall construction + prep flags, table info, floor box info, brackets (Alpine multi-row); refined labels/placeholders for existing room measurements"
      contains: "x-data"
  key_links:
    - from: "resources/views/site-survey/edit.blade.php"
      to: "SiteSurveyController::validateSurvey()"
      via: "form field name attributes match validation rule keys"
      pattern: "comms_room_access_status"
    - from: "resources/views/site-survey/_room-form.blade.php"
      to: "SurveyService::roomAttributes()"
      via: "rooms[N][field_name] inputs map to roomAttributes data keys"
      pattern: "mounting_heights"
    - from: "SurveyService::roomAttributes()"
      to: "SiteSurveyRoom $fillable + $casts"
      via: "Eloquent mass-assigns + casts JSON arrays / booleans on save and read"
      pattern: "'mounting_heights' => 'array'"
---

<objective>
Engineer feedback (post-survey debrief 2026-05-03) flagged 17 missing data-capture
fields needed for accurate RAMS / install-task / O&M generation. This quick task is
a pure additive expansion of the Site Survey form: 5 new site-level fields and
9 new room-level columns (3 booleans + 6 JSON). All fields are nullable; existing
surveys remain valid; downstream services (RamsBuilderService,
InstallTaskGeneratorService, ProjectDataService) are NOT touched and will pick up
the new data on their next regen cycle through the existing reviewed_data →
survey_data flow.

Purpose: Close the gap between what engineers ACTUALLY need to record on site
(parking, lift access, working-at-height method, cable lengths per route,
mounting heights, bracket models, wall construction) and what the form currently
captures. Today engineers either skip the form or scribble missing info in
free-text notes, which downstream documents can't parse reliably.

Output: Migration + model fillable/casts + controller validation + service
persistence + Blade form sections that let the engineer record every new field
with the same UX patterns already in place (.form-section cards with teal
.section-heading, soft-red empty-field highlight via :placeholder-shown,
collapsible Alpine multi-row UIs for cable_routes and brackets_required).

Constraints reminder: ≤7 files. No new partials. No new routes. No new JS
files (inline Alpine for multi-row UIs). No tests (pure schema-additive UI;
manually verifiable in browser). No changes to RamsBuilderService /
RamsDataBuilderService / ProjectDataService / InstallTaskGeneratorService.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md
@app/Models/SiteSurvey.php
@app/Models/SiteSurveyRoom.php
@app/Http/Controllers/SiteSurveyController.php
@app/Core/Modules/Survey/SurveyService.php
@resources/views/site-survey/edit.blade.php
@resources/views/site-survey/_room-form.blade.php
@database/migrations/2026_04_15_200000_add_wizard_fields_to_site_survey_rooms_table.php
@database/migrations/2026_04_11_200000_add_visit_and_pm_fields_to_site_surveys.php

<interfaces>
<!-- Existing canonical patterns the executor must follow -->

Existing site_surveys columns (ordering for Schema::table()->after()):
  user_id, project_id, project_name, project_ref, client_name, site_address,
  survey_date, surveyor_name, site_contact_name, site_contact_phone, visit_time,
  pm_name, pm_phone, pm_email, general_notes, site_risks, access_constraints,
  h_and_s_notes, status, filename, access_token, expires_at, submitted_at,
  superseded_at, survey_type, survey_data, deleted_at

Existing site_survey_rooms columns (ordering reference):
  site_survey_id, room_name, room_ref, floor, area_type, space_type,
  room_width_m, room_depth_m, room_height_m, ceiling_type, ceiling_height_m,
  wall_material, floor_type, av_requirements, av_equipment_list,
  has_power, has_network, power_outlet_count, network_port_count,
  network_ssid, network_vlan, network_switch_port, existing_cabling,
  requires_additional_power, access_notes, notes, sort_order,
  is_completed, completed_at, speaker_count, speaker_type, speaker_mounting,
  bg_noise_db, display_size_in, display_orient, display_mounting,
  rack_unit_space, cable_route_desc, cable_route_from, cable_route_to,
  is_rack_room, projection_throw_m, viewing_distance_m, existing_condition,
  items_to_remove, items_to_retain, engineer_confirmed, engineer_signature_name,
  work_type, access_issues, working_at_height, client_present, hs_flags,
  constraints_data

Existing JSON-column cast pattern (from SiteSurveyRoom):
  protected $casts = [
      ...
      'hs_flags'         => 'array',
      'constraints_data' => 'array',
      ...
  ];

Existing controller validation pattern (SiteSurveyController::validateSurvey):
  $request->validate([
      'rooms.*.cable_route_desc' => ['nullable', 'string', 'max:3000'],
      ...
  ]);
  -- Add ALL new fields with `nullable` rule (engineer fills what's relevant).
  -- For JSON arrays: `'rooms.*.work_at_height_methods' => ['nullable', 'array']`
  --                  `'rooms.*.work_at_height_methods.*' => ['string', 'in:ladder,podium,tower,mewp,scaffold,na']`

Existing SurveyService persistence pattern (roomAttributes):
  return [
      'cable_route_desc' => $data['cable_route_desc'] ?? null,
      ...
  ];
  -- For JSON arrays from form: `$data['work_at_height_methods'] ?? null`
  -- (Eloquent's array cast handles the encode/decode automatically.)

Existing form-section card pattern (from edit.blade.php):
  <div class="form-section">
      <div class="form-section__header">
          <h2 class="section-heading">Title Goes Here</h2>
      </div>
      <div class="form-section__body">
          <div class="form-grid-2">
              <div class="form-group">
                  <label class="form-label" for="x">Label</label>
                  <input type="text" id="x" name="x" class="form-control" placeholder=" ">
              </div>
          </div>
      </div>
  </div>

Existing Alpine.js usage on this codebase (from _room-form.blade.php pre-install
checks panel):
  <div x-data="{ checksOpen: false }">
      <button @click="checksOpen = !checksOpen" :aria-expanded="checksOpen.toString()">
      <div x-show="checksOpen" x-transition:enter="...">
  -- Alpine is available everywhere via layouts/app.blade.php; no imports needed.

Empty-field highlight rule (from quick task 260503-ipc — already shipping):
  Inputs the engineer SHOULD fill get `placeholder=" "` so :placeholder-shown
  fires the soft-red highlight CSS rule.
  Inputs that are genuinely optional get `data-optional` attribute (CSS opt-out).
  -- New text fields where the engineer should fill: use `placeholder=" "`.
  -- New genuinely-optional fields (e.g. comms-room-access notes box): use
     `data-optional`.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Migration + model fillable/casts (schema layer)</name>
  <files>
    database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php,
    app/Models/SiteSurvey.php,
    app/Models/SiteSurveyRoom.php
  </files>
  <action>
Create ONE migration file at
`database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php`
that adds BOTH site-level and room-level columns in a single up()/down() (two
Schema::table() calls inside the same migration class — Laravel supports this
fine and keeps the engineer-feedback batch atomic).

── site_surveys table (5 nullable columns) ──

Use Schema::table('site_surveys', function (Blueprint $table) { ... }) and add
each column with `->after('general_notes')` so they cluster with the other
free-text site notes:

  $table->string('comms_room_access_status', 20)->nullable()
        ->after('general_notes')
        ->comment('Enum: yes | no | outsourced | unknown — does engineer need permission for the comms room?');
  $table->text('comms_room_access_notes')->nullable()
        ->after('comms_room_access_status');
  $table->text('parking_restraints')->nullable()
        ->after('comms_room_access_notes')
        ->comment('Free-text — e.g. no on-street parking, must use NCP £18/day');
  $table->decimal('distance_from_base_miles', 6, 1)->nullable()
        ->after('parking_restraints');
  $table->text('distance_from_base_notes')->nullable()
        ->after('distance_from_base_miles')
        ->comment('Route notes — e.g. M25 J7 then 12mi A23');
  $table->text('site_access_notes')->nullable()
        ->after('distance_from_base_notes')
        ->comment('Loading bay, lift size, security pass needed, etc.');
  $table->text('delivery_routes')->nullable()
        ->after('site_access_notes')
        ->comment('Where deliveries can drop, hours, contact');

(That's 7 site-level columns to cover the 5 site-level requirement items —
comms-room-access splits into status+notes, distance splits into miles+notes.)

── site_survey_rooms table (9 nullable columns: 3 boolean + 6 JSON) ──

Use a SECOND Schema::table('site_survey_rooms', function (Blueprint $table) { ... })
inside the same up() method and add each column with explicit `->after()`
positioning so the schema stays readable:

  $table->json('mounting_heights')->nullable()
        ->after('display_mounting')
        ->comment('JSON: {screen_h_m, camera_h_m, booking_panel_h_m, speaker_h_m, other:[{label,height_m}]}');
  $table->json('work_at_height_methods')->nullable()
        ->after('mounting_heights')
        ->comment('JSON array: ladder|podium|tower|mewp|scaffold|na — drives RAMS height-risk classification');
  $table->json('cable_routes')->nullable()
        ->after('work_at_height_methods')
        ->comment('JSON array of {category,from,to,length_m,notes} — replaces the legacy single-row cable_route_* fields for new surveys');
  $table->json('wall_construction')->nullable()
        ->after('cable_routes')
        ->comment('JSON array: ply_lined|solid|plasterboard|masonry|metal_stud|concrete');
  $table->boolean('wall_needs_reinforcement')->nullable()
        ->after('wall_construction');
  $table->boolean('wall_needs_chase_out')->nullable()
        ->after('wall_needs_reinforcement');
  $table->boolean('wall_needs_conduit')->nullable()
        ->after('wall_needs_chase_out');
  $table->json('table_info')->nullable()
        ->after('wall_needs_conduit')
        ->comment('JSON: {has_grommets,grommet_count,grommet_size,notes}');
  $table->json('floor_box_info')->nullable()
        ->after('table_info')
        ->comment('JSON: {has_floor_box,power_outlets,data_outlets,cable_space,notes}');
  $table->json('brackets_required')->nullable()
        ->after('floor_box_info')
        ->comment('JSON array of {equipment,model,pull_out,notes}');

(That's 10 room-level columns — 7 JSON + 3 boolean — to cover items 6, 7, 8, 9,
10, 11, 12, 13 from the engineer feedback. Item 8 cable_routes is intentionally
ADDITIVE — the legacy cable_route_desc / cable_route_from / cable_route_to
columns remain untouched per the "pure additive — DO NOT change existing fields"
constraint, so old surveys still display their data and the new JSON column is
the going-forward capture path.)

── Down migration ──

Mirror with two Schema::table() blocks calling
$table->dropColumn([...full list...]) — must be reversible. List EVERY column
explicitly (no implicit name guessing). Verify with `php artisan migrate:rollback --step=1`
followed by `php artisan migrate` round-trip during testing.

── app/Models/SiteSurvey.php ──

Append the 7 new column names to $fillable in the same aligned-column style as
the existing entries. Add NO new $casts entries (all 7 are string/text/decimal
which Laravel handles natively; decimal('distance_from_base_miles', 6, 1) will
return as a string by default which is fine for display — no need to cast).

── app/Models/SiteSurveyRoom.php ──

Append the 10 new column names to $fillable in the same aligned-column style.
Add to $casts:

  'mounting_heights'            => 'array',
  'work_at_height_methods'      => 'array',
  'cable_routes'                => 'array',
  'wall_construction'           => 'array',
  'wall_needs_reinforcement'    => 'boolean',
  'wall_needs_chase_out'        => 'boolean',
  'wall_needs_conduit'          => 'boolean',
  'table_info'                  => 'array',
  'floor_box_info'              => 'array',
  'brackets_required'           => 'array',

(JSON columns cast as 'array' → Eloquent encodes/decodes transparently. Booleans
nullable in DB but cast to bool — null becomes false on read, which is the
desired form-default behaviour.)

NO changes to model relationships, helpers, or boot() — pure additive.
  </action>
  <verify>
    <automated>php artisan migrate &amp;&amp; php artisan migrate:rollback --step=1 &amp;&amp; php artisan migrate</automated>
  </verify>
  <done>
    - Migration file exists with both Schema::table() blocks (site_surveys + site_survey_rooms)
    - `php artisan migrate` runs cleanly; `migrate:rollback --step=1` cleanly reverses; re-running `migrate` re-applies
    - `php artisan tinker --execute="echo App\Models\SiteSurvey::first()?->comms_room_access_status ?? 'null-ok';"` returns without error
    - `php artisan tinker --execute="\$r = App\Models\SiteSurveyRoom::first(); var_export(\$r?->mounting_heights);"` returns null or array (not string), confirming cast works
    - SiteSurvey $fillable contains all 7 new column names
    - SiteSurveyRoom $fillable contains all 10 new column names; $casts contains all 4 boolean/array entries
  </done>
</task>

<task type="auto">
  <name>Task 2: Controller validation + SurveyService persistence (data layer)</name>
  <files>
    app/Http/Controllers/SiteSurveyController.php,
    app/Core/Modules/Survey/SurveyService.php
  </files>
  <action>
── app/Http/Controllers/SiteSurveyController.php ──

Extend the existing inline `validateSurvey()` method (around line 458) — DO NOT
create a new FormRequest class (none exists; pattern is inline). Add nullable
rules for every new field. Keep the same alignment style as the existing block.

Add these rules to the site-level section (after the existing 'survey_type'
rule):

  'comms_room_access_status'   => ['nullable', 'string', 'in:yes,no,outsourced,unknown'],
  'comms_room_access_notes'    => ['nullable', 'string', 'max:2000'],
  'parking_restraints'         => ['nullable', 'string', 'max:2000'],
  'distance_from_base_miles'   => ['nullable', 'numeric', 'min:0', 'max:9999'],
  'distance_from_base_notes'   => ['nullable', 'string', 'max:2000'],
  'site_access_notes'          => ['nullable', 'string', 'max:3000'],
  'delivery_routes'            => ['nullable', 'string', 'max:3000'],

Add these rules to the room-level section (after the existing
'rooms.*.items_to_retain' rule):

  // Engineer-feedback room-level additions (quick task 260503-rgg)
  'rooms.*.mounting_heights'                  => ['nullable', 'array'],
  'rooms.*.mounting_heights.screen_h_m'       => ['nullable', 'numeric', 'min:0', 'max:99'],
  'rooms.*.mounting_heights.camera_h_m'       => ['nullable', 'numeric', 'min:0', 'max:99'],
  'rooms.*.mounting_heights.booking_panel_h_m'=> ['nullable', 'numeric', 'min:0', 'max:99'],
  'rooms.*.mounting_heights.speaker_h_m'      => ['nullable', 'numeric', 'min:0', 'max:99'],
  'rooms.*.mounting_heights.other'            => ['nullable', 'array'],
  'rooms.*.mounting_heights.other.*.label'    => ['nullable', 'string', 'max:150'],
  'rooms.*.mounting_heights.other.*.height_m' => ['nullable', 'numeric', 'min:0', 'max:99'],

  'rooms.*.work_at_height_methods'            => ['nullable', 'array'],
  'rooms.*.work_at_height_methods.*'          => ['string', 'in:ladder,podium,tower,mewp,scaffold,na'],

  'rooms.*.cable_routes'                      => ['nullable', 'array'],
  'rooms.*.cable_routes.*.category'           => ['nullable', 'string', 'in:ceiling_speakers,desk_cables,mic_cables,booking_panel_cables,screen_cables,rack_to_room,other'],
  'rooms.*.cable_routes.*.from'               => ['nullable', 'string', 'max:255'],
  'rooms.*.cable_routes.*.to'                 => ['nullable', 'string', 'max:255'],
  'rooms.*.cable_routes.*.length_m'           => ['nullable', 'numeric', 'min:0', 'max:9999'],
  'rooms.*.cable_routes.*.notes'              => ['nullable', 'string', 'max:500'],

  'rooms.*.wall_construction'                 => ['nullable', 'array'],
  'rooms.*.wall_construction.*'               => ['string', 'in:ply_lined,solid,plasterboard,masonry,metal_stud,concrete'],
  'rooms.*.wall_needs_reinforcement'          => ['nullable', 'boolean'],
  'rooms.*.wall_needs_chase_out'              => ['nullable', 'boolean'],
  'rooms.*.wall_needs_conduit'                => ['nullable', 'boolean'],

  'rooms.*.table_info'                        => ['nullable', 'array'],
  'rooms.*.table_info.has_grommets'           => ['nullable', 'boolean'],
  'rooms.*.table_info.grommet_count'          => ['nullable', 'integer', 'min:0', 'max:99'],
  'rooms.*.table_info.grommet_size'           => ['nullable', 'string', 'in:small,standard,large'],
  'rooms.*.table_info.notes'                  => ['nullable', 'string', 'max:500'],

  'rooms.*.floor_box_info'                    => ['nullable', 'array'],
  'rooms.*.floor_box_info.has_floor_box'      => ['nullable', 'boolean'],
  'rooms.*.floor_box_info.power_outlets'      => ['nullable', 'integer', 'min:0', 'max:99'],
  'rooms.*.floor_box_info.data_outlets'       => ['nullable', 'integer', 'min:0', 'max:99'],
  'rooms.*.floor_box_info.cable_space'        => ['nullable', 'string', 'in:tight,adequate,spacious'],
  'rooms.*.floor_box_info.notes'              => ['nullable', 'string', 'max:500'],

  'rooms.*.brackets_required'                 => ['nullable', 'array'],
  'rooms.*.brackets_required.*.equipment'     => ['nullable', 'string', 'max:255'],
  'rooms.*.brackets_required.*.model'         => ['nullable', 'string', 'max:255'],
  'rooms.*.brackets_required.*.pull_out'      => ['nullable', 'boolean'],
  'rooms.*.brackets_required.*.notes'         => ['nullable', 'string', 'max:500'],

NO other changes to the controller — store(), update(), edit() etc. continue
to call validateSurvey() and pass the validated data through to SurveyService
unchanged. The new fields just flow through naturally.

── app/Core/Modules/Survey/SurveyService.php ──

Extend `update()` (around line 246) — in the `$survey->update([...])` array,
ADD the 7 new site-level fields after the existing 'survey_type' line, in the
same `$data['x'] ?? null` style:

  'comms_room_access_status'   => $data['comms_room_access_status']   ?? null,
  'comms_room_access_notes'    => $data['comms_room_access_notes']    ?? null,
  'parking_restraints'         => $data['parking_restraints']         ?? null,
  'distance_from_base_miles'   => $data['distance_from_base_miles']   ?? null,
  'distance_from_base_notes'   => $data['distance_from_base_notes']   ?? null,
  'site_access_notes'          => $data['site_access_notes']          ?? null,
  'delivery_routes'            => $data['delivery_routes']            ?? null,

Extend `roomAttributes()` (around line 540) — add these entries (preserving
the existing aligned-column style) at the END of the return array, just before
the closing `];`:

  // Engineer-feedback additions (quick task 260503-rgg)
  'mounting_heights'         => $data['mounting_heights']         ?? null,
  'work_at_height_methods'   => $data['work_at_height_methods']   ?? null,
  'cable_routes'             => $this->normalizeCableRoutes($data['cable_routes'] ?? null),
  'wall_construction'        => $data['wall_construction']        ?? null,
  'wall_needs_reinforcement' => isset($data['wall_needs_reinforcement']) ? (bool) $data['wall_needs_reinforcement'] : null,
  'wall_needs_chase_out'     => isset($data['wall_needs_chase_out'])     ? (bool) $data['wall_needs_chase_out']     : null,
  'wall_needs_conduit'       => isset($data['wall_needs_conduit'])       ? (bool) $data['wall_needs_conduit']       : null,
  'table_info'               => $data['table_info']               ?? null,
  'floor_box_info'           => $data['floor_box_info']           ?? null,
  'brackets_required'        => $this->normalizeBracketRows($data['brackets_required'] ?? null),

Add TWO small private helper methods at the bottom of the SurveyService class
(after isNonPhysicalRoomLabel(), before the closing `}`):

  /**
   * Drop fully-empty rows from the cable_routes JSON array submitted from the
   * dynamic Alpine row-add UI. A row is considered empty when category, from,
   * to, length_m AND notes are all blank/null. Returns null if no real rows
   * remain so the column stays NULL rather than storing an empty array.
   */
  private function normalizeCableRoutes(?array $rows): ?array
  {
      if ($rows === null) {
          return null;
      }
      $clean = array_values(array_filter($rows, function ($r) {
          if (! is_array($r)) return false;
          $hasContent = trim((string) ($r['category'] ?? '')) !== ''
              || trim((string) ($r['from']     ?? '')) !== ''
              || trim((string) ($r['to']       ?? '')) !== ''
              || trim((string) ($r['notes']    ?? '')) !== ''
              || ($r['length_m'] ?? null) !== null && $r['length_m'] !== '';
          return $hasContent;
      }));
      return $clean === [] ? null : $clean;
  }

  /**
   * Same empty-row-strip logic for brackets_required. A row is empty when
   * equipment, model AND notes are all blank.
   */
  private function normalizeBracketRows(?array $rows): ?array
  {
      if ($rows === null) {
          return null;
      }
      $clean = array_values(array_filter($rows, function ($r) {
          if (! is_array($r)) return false;
          return trim((string) ($r['equipment'] ?? '')) !== ''
              || trim((string) ($r['model']     ?? '')) !== ''
              || trim((string) ($r['notes']     ?? '')) !== '';
      }));
      return $clean === [] ? null : $clean;
  }

NO changes to create() / createFromProject() / saveDraftPublic() / complete() /
deleteRoomPhotos() — those continue to work unchanged. New fields default to
null on creation (Eloquent skips them when not in the input array, which is
exactly the desired behaviour for old code paths).
  </action>
  <verify>
    <automated>php artisan route:list --path=site-surveys &gt; /dev/null &amp;&amp; php -l app/Http/Controllers/SiteSurveyController.php &amp;&amp; php -l app/Core/Modules/Survey/SurveyService.php</automated>
  </verify>
  <done>
    - SiteSurveyController::validateSurvey() contains all 7 new site-level rules + the room-level rule block (count of new rule keys ≥ 30)
    - SurveyService::update() $survey->update() array includes all 7 new site-level keys
    - SurveyService::roomAttributes() return array includes all 10 new room-level keys
    - normalizeCableRoutes() and normalizeBracketRows() helpers exist as private methods
    - `php -l` reports "No syntax errors" on both modified files
    - Manual smoke: visit /site-surveys/{id}/edit, view source — no PHP error; submit form unchanged → no validation regression
  </done>
</task>

<task type="auto">
  <name>Task 3: Blade form sections — site-level + room-level (UI layer)</name>
  <files>
    resources/views/site-survey/edit.blade.php,
    resources/views/site-survey/_room-form.blade.php
  </files>
  <action>
── resources/views/site-survey/edit.blade.php ──

INSERT a new `.form-section` card titled "Site Logistics" IMMEDIATELY AFTER
the existing Project Manager `<fieldset>` block (after line 128) and BEFORE
the `<div class="form-group">` that holds site_address (line 130). The card
must use the established pattern from the existing Project Details card:

  <div class="form-section">
      <div class="form-section__header">
          <h2 class="section-heading">Site Logistics</h2>
      </div>
      <div class="form-section__body">
          <div class="form-grid-2">
              <div class="form-group">
                  <label class="form-label" for="comms_room_access_status">Comms Room Access</label>
                  <select id="comms_room_access_status" name="comms_room_access_status" class="form-control" data-optional>
                      <option value="">— Select —</option>
                      @php $cras = old('comms_room_access_status', $survey->comms_room_access_status); @endphp
                      <option value="yes"        @selected($cras === 'yes')>Yes — engineer needs permission</option>
                      <option value="no"         @selected($cras === 'no')>No — open access</option>
                      <option value="outsourced" @selected($cras === 'outsourced')>Outsourced (third-party manages)</option>
                      <option value="unknown"    @selected($cras === 'unknown')>Unknown</option>
                  </select>
              </div>
              <div class="form-group">
                  <label class="form-label" for="distance_from_base_miles">Distance from Base (miles)</label>
                  <input type="number" id="distance_from_base_miles" name="distance_from_base_miles" class="form-control"
                         value="{{ old('distance_from_base_miles', $survey->distance_from_base_miles) }}"
                         min="0" max="9999" step="0.1" placeholder="e.g. 47.5" data-optional>
              </div>
          </div>

          <div class="form-group">
              <label class="form-label" for="comms_room_access_notes">Comms Room Access — Notes</label>
              <textarea id="comms_room_access_notes" name="comms_room_access_notes" class="form-control"
                        rows="2" maxlength="2000" data-optional
                        placeholder="e.g. Permit required 48h notice; key from FM desk Mon-Fri 9-5">{{ old('comms_room_access_notes', $survey->comms_room_access_notes) }}</textarea>
          </div>

          <div class="form-group">
              <label class="form-label" for="distance_from_base_notes">Route / Travel Notes</label>
              <textarea id="distance_from_base_notes" name="distance_from_base_notes" class="form-control"
                        rows="2" maxlength="2000" data-optional
                        placeholder="e.g. M25 J7 then 12mi A23; allow 2h in rush hour">{{ old('distance_from_base_notes', $survey->distance_from_base_notes) }}</textarea>
          </div>

          <div class="form-group">
              <label class="form-label" for="parking_restraints">Parking Restraints</label>
              <textarea id="parking_restraints" name="parking_restraints" class="form-control"
                        rows="2" maxlength="2000" data-optional
                        placeholder="e.g. No on-street parking, must use NCP £18/day; loading bay 8am-10am only">{{ old('parking_restraints', $survey->parking_restraints) }}</textarea>
          </div>

          <div class="form-group">
              <label class="form-label" for="site_access_notes">Site Access Notes</label>
              <textarea id="site_access_notes" name="site_access_notes" class="form-control"
                        rows="3" maxlength="3000" data-optional
                        placeholder="e.g. Loading bay south side; goods lift 1.8m × 1.4m × 2.2m, 500kg; security pass collected from reception">{{ old('site_access_notes', $survey->site_access_notes) }}</textarea>
          </div>

          <div class="form-group">
              <label class="form-label" for="delivery_routes">Delivery Routes</label>
              <textarea id="delivery_routes" name="delivery_routes" class="form-control"
                        rows="3" maxlength="3000" data-optional
                        placeholder="e.g. Deliveries to bay 4 between 7am-11am; contact Site Manager 0207 xxx xxxx 1h before arrival">{{ old('delivery_routes', $survey->delivery_routes) }}</textarea>
          </div>
      </div>
  </div>

NO other changes to edit.blade.php. The roomCardHtml() JS template (lines
327–438) is the new-room template used when the user clicks "Add Room" — it
DOES NOT need new fields added because the partial _room-form.blade.php is the
canonical render path used both for existing rooms AND (after the next "Save
Changes") for newly-added rooms once they have an ID. New rooms added in the
same session won't show the engineer-feedback fields until first save —
acceptable trade-off to avoid duplicating the new fields in JS-string form.
Add a one-line HTML comment inside roomCardHtml's body explaining this:

  <!-- New rooms get the engineer-feedback fields after first save (re-render via partial). -->

── resources/views/site-survey/_room-form.blade.php ──

(1) **Refine existing measurement labels** — change the four existing labels
inside the .infra-panel block (around lines 257-287) to make their importance
explicit. Replace label text only (do NOT touch the input attributes):

  - "Width (m)"          → "Width (m) — required for layout"
  - "Depth (m)"          → "Depth (m) — required for layout"
  - "Height (m)"         → "Height (m) — finished floor to underside of slab"
  - "Ceiling Height (m)" → "Ceiling Height (m) — finished floor to ceiling tile"

(2) **Add new sections AFTER the existing infra-panel and BEFORE the engineer
sign-off block** (insert between the infra-panel closing `</div>` near line
401 and the "Engineer Sign-off" `<div>` near line 404). All new sections wrap
in `.form-section` cards using the established pattern:

  <div class="form-section">
      <div class="form-section__header">
          <h2 class="section-heading">Section Title</h2>
      </div>
      <div class="form-section__body">
          ... fields ...
      </div>
  </div>

Helper PHP at the top of each new section's body (use `@php ... @endphp`):

  @php
      $heights = $val('mounting_heights') ?: [];
      // For the multi-row sections, decode the existing JSON for re-render
      $cableRows   = $val('cable_routes') ?: [];
      $bracketRows = $val('brackets_required') ?: [];
      $wahMethods  = $val('work_at_height_methods') ?: [];
      $wallConstr  = $val('wall_construction') ?: [];
      $tableInfo   = $val('table_info') ?: [];
      $floorBox    = $val('floor_box_info') ?: [];
  @endphp

Sections to add IN ORDER:

(a) **Mounting Heights** — `.form-section` card with .form-grid-2 of four
    number inputs (screen_h_m, camera_h_m, booking_panel_h_m, speaker_h_m,
    each `min=0 max=99 step=0.01 placeholder=" "`). Below the grid, an
    Alpine multi-row block for the `other` array:

    <div x-data="{ rows: @js(is_array($heights['other'] ?? null) ? array_values($heights['other']) : []) }">
        <label class="form-label">Other Mounting Heights</label>
        <template x-for="(row, i) in rows" :key="i">
            <div class="form-grid-2" style="margin-bottom:.5rem;align-items:end;">
                <div class="form-group" style="margin-bottom:0;">
                    <input type="text" :name="`rooms[{{ $ri }}][mounting_heights][other][${i}][label]`"
                           x-model="row.label" class="form-control" maxlength="150"
                           placeholder="e.g. Mic boom" data-optional>
                </div>
                <div class="form-group" style="margin-bottom:0;display:flex;gap:.5rem;">
                    <input type="number" :name="`rooms[{{ $ri }}][mounting_heights][other][${i}][height_m]`"
                           x-model="row.height_m" class="form-control" min="0" max="99" step="0.01"
                           placeholder="height (m)" data-optional>
                    <button type="button" @click="rows.splice(i,1)"
                            style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:0 .65rem;cursor:pointer;">&#10005;</button>
                </div>
            </div>
        </template>
        <button type="button" @click="rows.push({label:'',height_m:''})"
                class="btn btn-outline btn-sm">+ Add Mounting Height</button>
    </div>

    Field names for the four fixed heights:
      rooms[{{ $ri }}][mounting_heights][screen_h_m]
      rooms[{{ $ri }}][mounting_heights][camera_h_m]
      rooms[{{ $ri }}][mounting_heights][booking_panel_h_m]
      rooms[{{ $ri }}][mounting_heights][speaker_h_m]

    Pre-fill values: `value="{{ $heights['screen_h_m'] ?? '' }}"` etc.

(b) **Working at Height Methods** — `.form-section` card with a checkbox
    group rendered as a flex-wrap of `.check-item` labels. Each checkbox uses
    the array-name pattern:

    <input type="checkbox" name="rooms[{{ $ri }}][work_at_height_methods][]"
           value="ladder" {{ in_array('ladder', $wahMethods) ? 'checked' : '' }}>

    Methods + display labels:
      ladder    → Ladder
      podium    → Podium step
      tower     → Mobile tower
      mewp      → MEWP / cherry picker
      scaffold  → Scaffold
      na        → N/A — ground level only

    NO hidden zero-value input needed (multi-select is genuinely "absence ==
    none selected").

(c) **Cable Routes** — `.form-section` card with an Alpine multi-row UI
    matching the Mounting Heights "Other" pattern but with 5 columns per row
    (category select + from text + to text + length_m number + notes text).
    Same +/− row controls. Initialize:

    <div x-data="{ rows: @js(array_values($cableRows)) }">
        <template x-for="(row, i) in rows" :key="i">
            <div style="border:1px solid #e0e0e0;border-radius:6px;padding:.6rem;margin-bottom:.5rem;background:#fff;">
                <div class="form-grid-2">
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">Category</label>
                        <select :name="`rooms[{{ $ri }}][cable_routes][${i}][category]`" x-model="row.category" class="form-control" data-optional>
                            <option value="">— Select —</option>
                            <option value="ceiling_speakers">Ceiling speakers</option>
                            <option value="desk_cables">Desk cables</option>
                            <option value="mic_cables">Mic cables</option>
                            <option value="booking_panel_cables">Booking panel cables</option>
                            <option value="screen_cables">Screen cables</option>
                            <option value="rack_to_room">Rack to room</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">Length (m)</label>
                        <input type="number" :name="`rooms[{{ $ri }}][cable_routes][${i}][length_m]`" x-model="row.length_m" class="form-control" min="0" max="9999" step="0.1" placeholder=" ">
                    </div>
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">From</label>
                        <input type="text" :name="`rooms[{{ $ri }}][cable_routes][${i}][from]`" x-model="row.from" class="form-control" maxlength="255" placeholder="e.g. Rack room A">
                    </div>
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">To</label>
                        <input type="text" :name="`rooms[{{ $ri }}][cable_routes][${i}][to]`" x-model="row.to" class="form-control" maxlength="255" placeholder="e.g. Display position">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Notes</label>
                    <input type="text" :name="`rooms[{{ $ri }}][cable_routes][${i}][notes]`" x-model="row.notes" class="form-control" maxlength="500" placeholder="e.g. Through ceiling void, drop into floor box" data-optional>
                </div>
                <div style="text-align:right;margin-top:.4rem;">
                    <button type="button" @click="rows.splice(i,1)" style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.2rem .65rem;cursor:pointer;font-size:.78rem;">Remove route</button>
                </div>
            </div>
        </template>
        <button type="button" @click="rows.push({category:'',from:'',to:'',length_m:'',notes:''})"
                class="btn btn-outline btn-sm">+ Add Cable Route</button>
        <p style="font-size:.78rem;color:#6B7280;margin:.5rem 0 0;">
            Add one row per cable category. Length captures total cable run including slack.
        </p>
    </div>

(d) **Wall Construction + Prep Flags** — `.form-section` card with two
    sub-blocks:

    Sub-block 1: Wall construction multi-select checkboxes (same pattern as
    work_at_height_methods):
      ply_lined     → Ply-lined
      solid         → Solid
      plasterboard  → Plasterboard
      masonry       → Masonry
      metal_stud    → Metal stud
      concrete      → Concrete

    Sub-block 2: Three boolean flags as `.check-item` labels (use the same
    hidden-0 + checkbox-1 pattern as `has_power` for proper false-on-uncheck):

    <label class="check-item" style="cursor:pointer;">
        <input type="hidden" name="rooms[{{ $ri }}][wall_needs_reinforcement]" value="0">
        <input type="checkbox" name="rooms[{{ $ri }}][wall_needs_reinforcement]" value="1" {{ $chk('wall_needs_reinforcement') ? 'checked' : '' }}>
        <span>Wall needs reinforcement</span>
    </label>

    Same pattern for `wall_needs_chase_out` ("Wall needs chase-out / chasing")
    and `wall_needs_conduit` ("Wall needs conduit run").

(e) **Table Info** — `.form-section` card with a 2-column grid:
      [hidden+checkbox] has_grommets ("Table has grommets") |
      [number 0–99] grommet_count ("Grommet count")
      [select small|standard|large] grommet_size ("Grommet size") |
      [text max 500] notes ("Table notes")

    Field names: `rooms[{{ $ri }}][table_info][has_grommets]`,
    `rooms[{{ $ri }}][table_info][grommet_count]`, etc. Pre-fill from
    `$tableInfo['has_grommets'] ?? false` etc. The boolean uses the
    hidden-0 + checkbox-1 pattern; the count input is `<input type="number">`
    with min=0 max=99; size is a `<select>` with the three enum values.

(f) **Floor Box Info** — `.form-section` card mirroring Table Info but with:
      [hidden+checkbox] has_floor_box ("Room has floor box") |
      [number 0–99] power_outlets ("Power outlets")
      [number 0–99] data_outlets ("Data outlets") |
      [select tight|adequate|spacious] cable_space ("Cable space")
      [text max 500] notes ("Floor box notes") (full-width, grid-column:1/-1)

    Field names: `rooms[{{ $ri }}][floor_box_info][has_floor_box]` etc.

(g) **Brackets Required** — `.form-section` card with Alpine multi-row UI
    (same pattern as Cable Routes but 4 columns per row):

    <div x-data="{ rows: @js(array_values($bracketRows)) }">
        <template x-for="(row, i) in rows" :key="i">
            <div style="border:1px solid #e0e0e0;border-radius:6px;padding:.6rem;margin-bottom:.5rem;background:#fff;">
                <div class="form-grid-2">
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">Equipment</label>
                        <input type="text" :name="`rooms[{{ $ri }}][brackets_required][${i}][equipment]`" x-model="row.equipment" class="form-control" maxlength="255" placeholder="e.g. 75&quot; display">
                    </div>
                    <div class="form-group" style="margin-bottom:.5rem;">
                        <label class="form-label">Bracket Model</label>
                        <input type="text" :name="`rooms[{{ $ri }}][brackets_required][${i}][model]`" x-model="row.model" class="form-control" maxlength="255" placeholder="e.g. Vogels PFW6880">
                    </div>
                </div>
                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
                    <label class="check-item" style="cursor:pointer;">
                        <input type="hidden" :name="`rooms[{{ $ri }}][brackets_required][${i}][pull_out]`" value="0">
                        <input type="checkbox" :name="`rooms[{{ $ri }}][brackets_required][${i}][pull_out]`" value="1" :checked="row.pull_out == 1 || row.pull_out === true" @change="row.pull_out = $event.target.checked ? 1 : 0">
                        <span>Pull-out / articulating bracket</span>
                    </label>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Notes</label>
                    <input type="text" :name="`rooms[{{ $ri }}][brackets_required][${i}][notes]`" x-model="row.notes" class="form-control" maxlength="500" placeholder="e.g. VESA 600×400; check wall plate weight rating" data-optional>
                </div>
                <div style="text-align:right;margin-top:.4rem;">
                    <button type="button" @click="rows.splice(i,1)" style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.2rem .65rem;cursor:pointer;font-size:.78rem;">Remove bracket</button>
                </div>
            </div>
        </template>
        <button type="button" @click="rows.push({equipment:'',model:'',pull_out:0,notes:''})"
                class="btn btn-outline btn-sm">+ Add Bracket</button>
    </div>

NO new partial files. All new markup goes inline in _room-form.blade.php.
The Alpine multi-row blocks all use isolated `x-data` scopes, so they don't
collide with the existing pre-install-checks panel's Alpine scope.

NO changes to the Engineer Sign-off block, the PA / Signage / Upgrade
type-panels, or the Other Notes textarea — those continue to render as-is
after the new sections.
  </action>
  <verify>
    <automated>php artisan view:clear &amp;&amp; php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app-&gt;loadEnvironmentFrom('.env'); \$app-&gt;make('Illuminate\Contracts\Console\Kernel')-&gt;bootstrap(); \$rendered = view('site-survey._room-form', ['ri'=&gt;0,'room'=&gt;new App\Models\SiteSurveyRoom(),'isNew'=&gt;true,'surveyType'=&gt;'general','kitItems'=&gt;[]])-&gt;render(); echo strlen(\$rendered) &gt; 5000 ? 'OK '.strlen(\$rendered).' bytes' : 'FAIL too small';"</automated>
  </verify>
  <done>
    - edit.blade.php contains a `<h2 class="section-heading">Site Logistics</h2>` block immediately after the Project Manager fieldset
    - edit.blade.php contains exactly 7 new form input controls (1 select + 1 number + 5 textareas) bound to the 7 new site-level field names
    - _room-form.blade.php contains 7 new `<h2 class="section-heading">` blocks (Mounting Heights, Working at Height Methods, Cable Routes, Wall Construction, Table Info, Floor Box Info, Brackets Required)
    - _room-form.blade.php contains the four refined measurement labels with " — required for layout" / " — finished floor to ..." suffixes
    - `php artisan view:clear` runs cleanly; rendering the partial through tinker returns &gt;5000 bytes (proves no Blade compile error)
    - Browser smoke-test: load /site-surveys/{id}/edit on an existing survey → no console errors → all 7 new room sections render → +/− row buttons work for cable_routes / brackets_required / mounting_heights.other → submit form → page reloads → all values round-trip
  </done>
</task>

</tasks>

<verification>
End-to-end manual smoke test (browser, after all 3 tasks land):

1. **Migration round-trip:**
   `php artisan migrate:rollback --step=1 && php artisan migrate`
   → both run cleanly, no errors.

2. **Existing-survey edit (regression check):**
   - Open an existing site survey edit page
   - All previously-existing fields render with their saved values
   - New "Site Logistics" card appears between Project Manager and Site Address
   - All 7 room sections appear in each room card between the Measurements panel
     and the Engineer Sign-off block
   - All new fields are EMPTY (showing soft-red highlight via :placeholder-shown
     for text inputs, except those with `data-optional`)
   - Click "Save Changes" with NO new fields filled → redirects to show page,
     no errors, no validation failures (everything is `nullable`)

3. **Fill-in test (positive path):**
   - Edit the same survey
   - Fill site-level: comms_room_access_status="yes", comms_room_access_notes="permit
     required", parking_restraints="NCP £18", distance_from_base_miles=42.5,
     distance_from_base_notes="M25 J7", site_access_notes="loading bay south",
     delivery_routes="bay 4, 7am-11am"
   - On the first room: fill mounting_heights screen_h_m=2.4, add 1 "Other"
     row (mic boom, 2.1m); tick work_at_height_methods ladder + tower;
     add 2 cable_routes rows (ceiling_speakers + screen_cables); tick
     wall_construction plasterboard + masonry; tick wall_needs_chase_out;
     fill table_info has_grommets=true, grommet_count=4, grommet_size=standard;
     fill floor_box_info has_floor_box=true, power_outlets=4, data_outlets=2;
     add 1 bracket row (75" display, Vogels PFW6880, pull_out=true)
   - Save → redirect to show page → no errors
   - Re-open edit page → ALL values round-trip correctly (multi-row sections
     re-render the saved rows, multi-select checkboxes show ticked state,
     boolean flags persisted)

4. **DB verification:**
   `php artisan tinker --execute="\$s = App\Models\SiteSurvey::find(X); var_export(\$s->only(['comms_room_access_status','distance_from_base_miles','site_access_notes'])); var_export(\$s->rooms->first()->only(['mounting_heights','cable_routes','brackets_required','wall_construction']));"`
   → site-level fields persist as scalars, room-level JSON columns persist as
   PHP arrays (Eloquent cast), boolean flags persist as 0/1.

5. **Empty-row strip:**
   - Add 2 cable_route rows, fill only the first, leave second empty
   - Save → re-open edit → only the filled row appears (normalizeCableRoutes
     dropped the empty row)
   - Same for brackets_required.

6. **Old surveys (zero-touch regression):**
   - Open a survey created BEFORE this migration ran (no new field values)
   - All new sections render with empty placeholders
   - Save without touching any new field → no validation errors → DB still
     has NULLs for all new columns

7. **Downstream zero-impact:**
   - Trigger RAMS regen / O&M regen / install-task regen on a project that
     has the upgraded survey
   - Documents generate as before (services don't read new fields yet — that's
     a future task)
   - No PHP errors / warnings about unknown columns
</verification>

<success_criteria>
- Migration adds 7 site_surveys columns + 10 site_survey_rooms columns; rollback cleanly reverses
- SiteSurvey + SiteSurveyRoom $fillable cover every new column; SiteSurveyRoom $casts handles 4 booleans + 6 array-cast JSON columns correctly
- SiteSurveyController::validateSurvey() passes ALL new fields through with `nullable` rules
- SurveyService::update() persists site-level additions; SurveyService::roomAttributes() persists room-level additions; empty cable_route + bracket rows are stripped to NULL
- edit.blade.php "Site Logistics" .form-section card renders after Project Manager
- _room-form.blade.php renders 7 new .form-section cards between Measurements and Engineer Sign-off blocks
- Multi-row UIs (mounting_heights.other, cable_routes, brackets_required) support add / remove via Alpine x-data with no JS file changes
- Existing surveys load + save without regression; downstream services (RamsBuilderService, etc.) are unchanged and unaffected
- File footprint: exactly 7 files modified (1 migration + 2 models + 2 views + 1 controller + 1 service)
- Manual browser smoke-test passes on a real survey (per verification step 3)
</success_criteria>

<output>
After completion, the engineer-feedback fields are live on the Site Survey
form. Note in commit message that downstream services were intentionally
NOT modified — they will pick up the new data on their next regen cycle
through reviewed_data → survey_data flow once those services are extended in
a future quick task / phase.

NO SUMMARY file required for quick tasks — STATE.md Quick Tasks table gets
the new row via the orchestrator's wrap-up step.

Files to upload to live (per local-edit-then-upload workflow):
- database/migrations/2026_05_03_120000_add_engineer_feedback_fields_to_site_surveys_and_rooms.php
- app/Models/SiteSurvey.php
- app/Models/SiteSurveyRoom.php
- app/Http/Controllers/SiteSurveyController.php
- app/Core/Modules/Survey/SurveyService.php
- resources/views/site-survey/edit.blade.php
- resources/views/site-survey/_room-form.blade.php

Then on live: `php artisan migrate` + `php artisan view:clear` + `php artisan config:clear`.
</output>
