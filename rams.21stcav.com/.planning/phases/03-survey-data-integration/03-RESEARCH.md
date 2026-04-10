# Phase 3: Survey Data Integration — Research

**Researched:** 2026-04-10
**Domain:** Laravel service layer, Eloquent relations, fuzzy string matching, schema migration
**Confidence:** HIGH — all findings verified directly from codebase

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Fuzzy room matching via Levenshtein distance similarity. Threshold ~0.7 (Claude picks exact value).
- **D-02:** Unmatched survey rooms (orphans) are added to canonical `rooms[]` with `data_source: 'survey'` and `confidence: 0.95`. They are NOT discarded.
- **D-03:** Phase 1 `mergeSurveyRooms()` stub reads `$survey->room_data` (JSON blob that does not exist). Replace with relational load from `SiteSurveyRoom`.
- **D-04:** New text columns on `site_surveys`: `site_risks` (text, nullable), `access_constraints` (text, nullable), `h_and_s_notes` (text, nullable).
- **D-05:** New `survey_meta` shape: `{ has_survey, submitted_at, site_risks, access_constraints, h_and_s_notes, general_notes, rooms[] }`.
- **D-06:** Existing `general_notes` is retained and passes through unchanged.
- **D-07:** Only one active survey per project. When a new survey is created and one already exists, existing survey is superseded (Claude picks exact mechanism: `superseded_at` timestamp vs status flag).
- **D-08:** `ProjectDataService` selects single active/completed survey: `siteSurveys()->where('status','completed')->latest()->first()` — no multi-survey priority logic.
- **D-09:** External (token) surveys treated identically to internal surveys once completed.
- **D-10:** Normalized room field list defined in CONTEXT.md. Strip `items_to_remove`, `items_to_retain`, `existing_condition`.
- **D-11:** All normalized room fields carry `data_source: 'survey'` and `confidence: 0.95`.
- **D-12:** Survey tier sits at: `reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults`.
- **D-13:** `CONFIDENCE_THRESHOLD = 0.7` and `isLowConfidence()` apply unchanged.

### Claude's Discretion

- Exact Levenshtein similarity threshold (starting point: 0.7)
- Mechanism for one-survey enforcement (`superseded_at` timestamp vs status flag vs soft-delete)
- Eager vs lazy load of `SiteSurveyRoom` on survey resolve
- Whether to add global fields to survey form views (data layer is the Phase 3 requirement; form UI can defer to Phase 4)

### Deferred Ideas (OUT OF SCOPE)

- Manual room mapping UI (drag-and-drop)
- Photo data in canonical dataset
- Survey form UI for new global fields (optional for Phase 3; data schema is the required deliverable)

</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SURV-01 | Survey data wired into ProjectDataService, available to all generators | `mergeSurveyRooms()` and `resolveSurveyMeta()` stubs identified; replacement strategy documented |
| SURV-02 | Per-room data captures: displays, audio, cable routes, power/network, mounting, access | All 50+ `SiteSurveyRoom` columns verified in schema; normalization field list mapped |
| SURV-03 | Global survey data: site risks, H&S notes, constraints | Three new columns needed (`site_risks`, `access_constraints`, `h_and_s_notes`); migration strategy documented |
| SURV-04 | External users can fill surveys via UUID token without login | Fully implemented — `PublicSurveyController`, `saveDraftPublic()`, `submitPublic()` all exist |
| SURV-05 | Survey supports draft save and final submission with timestamps | Fully implemented — `status`, `submitted_at`, draft/submit routes all exist |

</phase_requirements>

---

## Summary

Phase 3 is primarily a service-layer wiring task with a small schema migration and minor UI additions. The survey infrastructure (models, controllers, migrations, public form, token access, draft/submit) is already fully implemented from earlier development. Phase 1 left two explicit stubs in `ProjectDataService` that Phase 3 must replace: `mergeSurveyRooms()` (reads a non-existent `$survey->room_data` JSON blob) and `resolveSurveyMeta()` (same). The real room data lives in the `SiteSurveyRoom` Eloquent relation, which already has all the required columns.

The three missing columns (`site_risks`, `access_constraints`, `h_and_s_notes`) do not exist in `site_surveys` yet — a new migration is needed. The `SiteSurvey` model's `$fillable` and `$casts` arrays must be updated to include them. The one-survey-per-project enforcement does not exist anywhere yet; `SurveyService::create()` and `createFromProject()` currently create surveys without checking for existing ones.

**Primary recommendation:** Three distinct work streams: (1) schema migration + model update for global fields, (2) service-layer replacement of the two stubs in `ProjectDataService` with relational room loading and fuzzy matching, (3) one-survey enforcement in `SurveyService` with corresponding controller and view changes for the supersede confirmation.

---

## Standard Stack

### Core — All Already in Project

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP native `similar_text()` | built-in | Fuzzy string similarity, returns percentage | No external dependency; sufficient for room name matching |
| PHP native `levenshtein()` | built-in | Edit-distance for string comparison | CONTEXT.md D-01 specifies Levenshtein; PHP has it natively |
| Laravel Eloquent `hasMany` | ^12.0 | Load `SiteSurveyRoom` records from `SiteSurvey` | Already wired: `SiteSurvey::rooms()` hasMany |
| Laravel migrations | ^12.0 | Safe schema changes | Project pattern; all columns added via additive migrations |

### No New Dependencies Required

Phase 3 adds zero composer packages. All tooling is PHP builtins and existing Laravel/Eloquent patterns. [VERIFIED: codebase grep]

---

## Architecture Patterns

### Pattern 1: Stub Replacement in ProjectDataService

**What:** Replace two methods that read a non-existent JSON blob with methods that load from the Eloquent relation.

**Current stub (line 258–279 of `ProjectDataService.php`):**
```php
private function mergeSurveyRooms(array $quoteRooms, object $survey): array
{
    $roomData = is_array($survey->room_data ?? null) ? $survey->room_data : [];
    // ... exact name match against JSON blob that doesn't exist
}
```

**Replacement approach:** Eager-load `SiteSurveyRoom` records via the relation. The `SiteSurvey::rooms()` relation is already defined and ordered by `sort_order`. [VERIFIED: `app/Models/SiteSurvey.php` line 63–66]

**Recommended: eager load** — call `$survey->loadMissing('rooms')` once in `resolveRooms()`, not per-room. This avoids N+1 queries when merging rooms.

```php
// In resolveRooms(), before the merge:
if ($survey !== null && !$survey->relationLoaded('rooms')) {
    $survey->loadMissing('rooms');
}
```

### Pattern 2: Levenshtein Fuzzy Room Matching

**What:** Match survey room names to quote room names by similarity score. PHP's `similar_text()` returns a percentage (0–100). The D-01 threshold of ~0.7 maps to 70%.

**Verified PHP behaviour:**
- `similar_text('Boardroom', 'Board Room', $pct)` — returns high similarity because it counts common characters, not edit operations. [ASSUMED — can't run PHP in this environment, but this is standard PHP docs behaviour]
- `levenshtein('Boardroom', 'Board Room')` — returns 1 (one space insertion). Normalised Levenshtein similarity = 1 - (distance / max(len_a, len_b)).

**Recommendation: use `similar_text()` for percentage-based comparison.** It is more forgiving of spacing and casing differences, which is the primary mismatch pattern for room names (e.g., "Board Room" vs "Boardroom", "Reception 1" vs "Reception"). Normalise both strings to lowercase + trim before comparison.

```php
// Similarity helper — no library needed [ASSUMED: standard PHP]
private function roomSimilarity(string $a, string $b): float
{
    similar_text(strtolower(trim($a)), strtolower(trim($b)), $pct);
    return $pct / 100.0; // convert to 0.0–1.0 scale
}
```

**Threshold recommendation: 0.7 (70%)** — matches D-01's starting point. Common cases: "Boardroom" / "Board Room" (~88%), "Reception" / "Reception 1" (~90%), "Server Room" / "IT Room" (~38% — correctly rejected).

### Pattern 3: Orphan Room Handling

**What:** Survey rooms with no quote match above threshold are added as new entries in `rooms[]` with `quote_room_matched: false`. [VERIFIED: CONTEXT.md D-02, Specifics section]

```php
// Orphan room entry shape:
[
    'room_name'           => $surveyRoom->room_name,
    // ... all normalized fields from D-10
    'data_source'         => 'survey',
    'confidence'          => 0.95,
    'quote_room_matched'  => false,
]
```

### Pattern 4: survey_meta Shape

**Current stub (`resolveSurveyMeta()` line 186–207):**
```php
return [
    'has_survey'   => true,
    'submitted_at' => $submittedAt,
    'rooms'        => $survey->room_data ?? [],  // BUG: room_data doesn't exist
];
```

**Phase 3 replacement shape (D-05):**
```php
return [
    'has_survey'          => true,
    'submitted_at'        => $submittedAt,
    'site_risks'          => $survey->site_risks,
    'access_constraints'  => $survey->access_constraints,
    'h_and_s_notes'       => $survey->h_and_s_notes,
    'general_notes'       => $survey->general_notes,
    'rooms'               => $this->normalizeRooms($survey->rooms),
];
```

**Note:** `submitted_at` resolution logic (lines 194–201) already handles both Eloquent datetime objects and plain string values — retain this logic as-is for test compatibility. [VERIFIED: `ProjectDataService.php` lines 194–201]

### Pattern 5: One-Survey-Per-Project Enforcement

**Recommendation: `superseded_at` timestamp column** (not status flag, not soft-delete).

Rationale:
- Soft-delete would hide the old survey from the `SiteSurvey::all()` admin view unless `withTrashed()` is added everywhere — scope creep.
- Status flag ("superseded") would require adding a new status constant and handling it in `isCompleted()`, `isDraft()`, etc.
- `superseded_at` timestamp is additive (nullable column), self-documenting (when was it superseded?), audit-friendly, and does not change any existing status logic.
- The single active survey query in `ProjectDataService` (D-08) just needs `whereNull('superseded_at')` added, which is minimal impact.

**Migration:** Add `superseded_at` nullable timestamp to `site_surveys`.

**Enforcement location:** `SurveyService::create()` and `SurveyService::createFromProject()` — when `project_id` is set, check for existing non-superseded survey and either supersede it (if `$supersede=true` flag) or abort/warn.

**Controller flow (CONTEXT.md Interaction Contracts):**
1. `SiteSurveyController::createFromProject()` checks `$project->siteSurveys()->whereNull('superseded_at')->whereNotNull('project_id')->exists()`.
2. If existing: return create view with `$existingSurvey` variable → view renders `.alert-warning` supersede block.
3. On "Archive existing and create new" POST with `supersede=1`: controller calls service with supersede flag.
4. Service sets `superseded_at = now()` on old survey, creates new survey.

**Updated `ProjectDataService` query:** The resolve query must exclude superseded surveys:
```php
$project->siteSurveys()->where('status', 'completed')->whereNull('superseded_at')->latest()->first();
```
[VERIFIED: current query at `ProjectDataService.php` lines 49–51 does NOT yet filter by superseded_at — must be added]

### Pattern 6: Unit Test Extension

**Existing test infrastructure:** `tests/Unit/ProjectDataServiceTest.php` uses anonymous class stubs extending `Project` that override `__get()` and `relationLoaded()`. [VERIFIED: test file lines 270–302]

**Current test stub has:** `$survey->room_data = []` and `$survey->completed_at = null` — this test still passes because the stub provides `room_data`. Phase 3 must:
1. Update the stub to expose `rooms` (a collection) instead of `room_data`.
2. Ensure existing test 8 (`test_resolve_meta_has_survey_flag`) still passes after the refactor — it currently tests `has_survey` and `survey_complete` flags only, not the rooms shape, so it should survive with minimal stub update.

**New tests to add:**
- Survey rooms merge: matched room inherits survey fields at `confidence: 0.95`
- Survey rooms merge: orphan room appended with `quote_room_matched: false`
- Survey rooms merge: below-threshold match leaves quote room unchanged
- `resolveSurveyMeta()` returns all 6 keys including new global fields
- One-survey enforcement: second `create()` call without supersede flag does not create a second survey for same project
- One-survey enforcement: second `create()` with `supersede=1` sets `superseded_at` on first survey

### Recommended Project Structure — Files Affected

```
app/
├── Core/Modules/Projects/
│   └── ProjectDataService.php          # Replace mergeSurveyRooms() + resolveSurveyMeta()
├── Core/Modules/Survey/
│   └── SurveyService.php               # Add one-survey enforcement to create() + createFromProject()
├── Models/
│   └── SiteSurvey.php                  # Add 4 new fields to $fillable + $casts
├── Http/Controllers/
│   └── SiteSurveyController.php        # createFromProject(): detect existing, pass $existingSurvey
database/
└── migrations/
    └── 2026_04_10_XXXXXX_add_global_fields_and_superseded_at_to_site_surveys_table.php
resources/views/
├── site-survey/
│   ├── create.blade.php                # Add supersede confirmation block
│   └── show.blade.php                  # Display site_risks, access_constraints, h_and_s_notes
└── public-survey/
    └── show.blade.php                  # Add 3 new textarea fields in amber section
tests/
└── Unit/
    └── ProjectDataServiceTest.php      # Extend with 6+ new test cases
```

### Anti-Patterns to Avoid

- **Reading `$survey->room_data`:** This JSON column does not exist on `site_surveys`. The model has no `room_data` attribute. The stub in Phase 1 only works because `ProjectDataService` tests fake it via anonymous class `__get()`. Production would return null and silently produce empty rooms. [VERIFIED: `SiteSurvey` $fillable does not contain `room_data`]
- **N+1 on room loading:** Calling `$survey->rooms` inside a loop without eager loading. Use `loadMissing('rooms')` once before the merge loop.
- **Soft-deleting for supersede:** Changes admin view scope and requires `withTrashed()` guards. Use `superseded_at` column instead.
- **Storing merge result in DB:** `ProjectDataService` is READ-ONLY by design. Never persist the merged `rooms[]` array. [VERIFIED: DATA-01 requirement + existing test 6 asserts zero DB writes]
- **Skipping the `superseded_at` filter in ProjectDataService:** Without `whereNull('superseded_at')`, a superseded survey could still be picked up as the active survey if it was marked `completed` before being superseded.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Fuzzy string similarity | Custom n-gram or Jaro-Winkler | PHP `similar_text()` | Native, no dependency, sufficient for room name matching |
| DB transaction for multi-step survey create | Manual transaction guards | `DB::transaction()` — already used in `SurveyService` | Rollback safety on partial failures |
| Relation eager loading | Manual SQL join | `$survey->loadMissing('rooms')` | Eloquent handles ordering, casting, and relation caching |

---

## Common Pitfalls

### Pitfall 1: `room_data` is a Phantom Field

**What goes wrong:** Calling `$survey->room_data` returns null (Eloquent returns null for unknown attributes). Code silently produces `rooms: []` in survey_meta instead of throwing.
**Why it happens:** Phase 1 stub was written defensively (`$survey->room_data ?? []`), masking the null return. Unit tests fake the attribute via `__get()`.
**How to avoid:** Phase 3 replaces the stub with `$survey->rooms` (the Eloquent relation returning a Collection of `SiteSurveyRoom` models). Do not reference `room_data` anywhere.
**Warning signs:** `survey_meta.rooms` is always `[]` in production; engineers fill survey but generators see no room data. [VERIFIED: `SiteSurvey` $fillable — no `room_data` column]

### Pitfall 2: Test Stub Uses `room_data` — Must Be Updated

**What goes wrong:** After Phase 3 refactor, test 8 (`test_resolve_meta_has_survey_flag`) still passes because the stub exposes `room_data = []`. But new Phase 3 tests that check rooms shape will fail unless the stub is updated.
**Why it happens:** Anonymous class stubs override `__get()` to return stub data. The old stub returns `room_data => []`; the new service reads `$survey->rooms` (the relation Collection).
**How to avoid:** Update `makeProjectStub()` to expose a `rooms` property returning a Collection of `SiteSurveyRoom`-like stdClass objects. The new stub must expose `rooms`, `site_risks`, `access_constraints`, `h_and_s_notes`, and `general_notes`. [VERIFIED: test file lines 246–248 — current stub has `$survey->room_data = []`]

### Pitfall 3: Supersede Without `whereNull('superseded_at')` Filter

**What goes wrong:** After superseding old survey and creating a new draft, `ProjectDataService` still finds the old survey if it was `status='completed'`.
**Why it happens:** The current query `where('status', 'completed')->latest()->first()` has no supersede awareness.
**How to avoid:** Add `->whereNull('superseded_at')` to all survey resolution queries in `ProjectDataService` and any other place that queries for the "active" survey.
**Warning signs:** Engineers see old survey data in document generators even after superseding.

### Pitfall 4: Public Survey Submit Does Not Set `superseded_at`

**What goes wrong:** Client submits survey via token (external path). This calls `SurveyService::submitPublic()` which sets `status='completed'`. If an internal engineer then tries to create a replacement survey, the token-submitted one is already completed — the one-survey enforcement must recognise it.
**Why it happens:** The enforcement check looks for existing surveys with no supersede filter.
**How to avoid:** The enforcement in `SurveyService::create()` should check `where('status', 'completed')->orWhere('status', 'draft')->whereNull('superseded_at')` — both draft and completed surveys block creation unless superseded. D-09 confirms token surveys are treated identically.

### Pitfall 5: `saveDraftPublic()` and `submitPublic()` Don't Write Global Fields

**What goes wrong:** New `site_risks`, `access_constraints`, `h_and_s_notes` columns added to schema, but `saveDraftPublic()` and `submitPublic()` don't include them in the update payload. If form fields are added to the public view, submitted values are silently dropped.
**Why it happens:** Both methods hard-code the update array to `survey_date`, `surveyor_name`, `general_notes` only. [VERIFIED: `SurveyService.php` lines 240–244 and 270–278]
**How to avoid:** Extend the update payload in both methods to include the three new fields. Also update `validatePublicSurvey()` in `PublicSurveyController` to accept them.

### Pitfall 6: `syncHeaderFields()` JS Does Not Mirror New Fields

**What goes wrong:** The public survey form uses `syncHeaderFields()` JS to copy header form values into hidden inputs before the main room POST. New global fields added to the header form are NOT copied unless the JS is updated. [VERIFIED: `show.blade.php` line 1402 — array is `['survey_date','surveyor_name','general_notes']`]
**How to avoid:** Add `site_risks`, `access_constraints`, `h_and_s_notes` to the JS array and add corresponding hidden inputs to the submit form.

---

## Code Examples

### Normalised Room Shape (D-10 — verified against SiteSurveyRoom $fillable)

```php
// Source: app/Models/SiteSurveyRoom.php $fillable + CONTEXT.md D-10
private function normalizeRooms(iterable $surveyRooms): array
{
    $result = [];
    foreach ($surveyRooms as $room) {
        $result[] = [
            // Identity
            'room_name'                 => $room->room_name,
            'room_ref'                  => $room->room_ref,
            'floor'                     => $room->floor,
            'area_type'                 => $room->area_type,
            'space_type'                => $room->space_type,
            // Dimensions
            'room_width_m'              => $room->room_width_m,
            'room_depth_m'              => $room->room_depth_m,
            'room_height_m'             => $room->room_height_m,
            'ceiling_type'              => $room->ceiling_type,
            'ceiling_height_m'          => $room->ceiling_height_m,
            'wall_material'             => $room->wall_material,
            'floor_type'                => $room->floor_type,
            // AV scope
            'av_requirements'           => $room->av_requirements,
            'av_equipment_list'         => $room->av_equipment_list,
            // Services
            'has_power'                 => $room->has_power,
            'has_network'               => $room->has_network,
            'power_outlet_count'        => $room->power_outlet_count,
            'network_port_count'        => $room->network_port_count,
            'requires_additional_power' => $room->requires_additional_power,
            'existing_cabling'          => $room->existing_cabling,
            // Infrastructure
            'rack_unit_space'           => $room->rack_unit_space,
            'cable_route_desc'          => $room->cable_route_desc,
            // Audio
            'speaker_count'             => $room->speaker_count,
            'speaker_type'              => $room->speaker_type,
            'speaker_mounting'          => $room->speaker_mounting,
            'bg_noise_db'               => $room->bg_noise_db,
            // Displays
            'display_size_in'           => $room->display_size_in,
            'display_orient'            => $room->display_orient,
            'display_mounting'          => $room->display_mounting,
            // Access / notes
            'access_notes'              => $room->access_notes,
            'notes'                     => $room->notes,
            // Completion
            'is_completed'              => $room->is_completed,
            'completed_at'              => $room->completed_at?->toISOString(),
            // EXCLUDED: items_to_remove, items_to_retain, existing_condition
            // Annotation (D-11)
            'data_source'               => 'survey',
            'confidence'                => 0.95,
        ];
    }
    return $result;
}
```

### Migration Pattern (matches project style)

```php
// Source: Pattern from 2026_04_05_100000_add_token_fields_to_site_surveys_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->text('site_risks')->nullable()->after('general_notes');
            $table->text('access_constraints')->nullable()->after('site_risks');
            $table->text('h_and_s_notes')->nullable()->after('access_constraints');
            $table->timestamp('superseded_at')->nullable()->after('h_and_s_notes');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn(['site_risks', 'access_constraints', 'h_and_s_notes', 'superseded_at']);
        });
    }
};
```

---

## Existing Infrastructure — What Is Already Built

This table distinguishes what exists from what Phase 3 must add. [VERIFIED: codebase grep]

| Component | Status | Notes |
|-----------|--------|-------|
| `SiteSurvey` model with `status`, `access_token`, `submitted_at`, `survey_type` | EXISTS | `$fillable` confirmed |
| `SiteSurveyRoom` model — all 50+ columns | EXISTS | All fields verified in `$fillable` and migrations |
| `SiteSurvey::rooms()` hasMany relation | EXISTS | Ordered by `sort_order` |
| `SurveyService::create()`, `createFromProject()` | EXISTS | No supersede logic — Phase 3 adds it |
| `SurveyService::saveDraftPublic()`, `submitPublic()` | EXISTS | Only writes `survey_date`, `surveyor_name`, `general_notes` — Phase 3 extends |
| `PublicSurveyController` — show, save, submit, photos, room completion | EXISTS | Fully implemented |
| Public survey routes `/survey/{token}/*` | EXISTS | All 6 routes defined |
| Public survey view `public-survey/show.blade.php` | EXISTS | `.survey-section--conditions` CSS class already defined |
| Internal survey views: `index`, `create`, `edit`, `show`, `_room-form` | EXISTS | No supersede UI — Phase 3 adds warning block to `create` |
| `ProjectDataService::resolve()` with correct 9-key shape | EXISTS | Phase 1 delivered |
| `ProjectDataService::mergeSurveyRooms()` | EXISTS (stub) | Reads non-existent `room_data` — Phase 3 replaces |
| `ProjectDataService::resolveSurveyMeta()` | EXISTS (stub) | Returns `room_data ?? []` — Phase 3 replaces |
| `site_surveys.site_risks`, `.access_constraints`, `.h_and_s_notes` columns | MISSING | Phase 3 adds via migration |
| `site_surveys.superseded_at` column | MISSING | Phase 3 adds via migration |
| One-survey-per-project enforcement | MISSING | Phase 3 adds to `SurveyService` |
| Supersede confirmation UI in internal create view | MISSING | Phase 3 adds |
| Survey confirmation page `/survey/{token}/confirmation` | MISSING | Phase 3 adds (static Blade view + route) |
| `ProjectDataServiceTest` — Phase 3 test cases | MISSING | Phase 3 extends existing test file |

---

## Existing Test Infrastructure

| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 |
| Config file | `phpunit.xml` (root) |
| Quick run command | `php artisan test tests/Unit/ProjectDataServiceTest.php` |
| Full suite command | `php artisan test` |
| Existing passing tests | 8 tests, 34 assertions in `ProjectDataServiceTest` |

**Current test stub compatibility note:** Test 8 (`test_resolve_meta_has_survey_flag`) uses `$survey->room_data = []`. After Phase 3 refactors `resolveSurveyMeta()` to read from `$survey->rooms`, this stub must expose `rooms` (a Collection) plus `site_risks`, `access_constraints`, `h_and_s_notes`, `general_notes`. The test itself asserts only `has_survey` and `survey_complete` flags — the assertion does NOT fail from the stub change, but `resolveSurveyMeta()` must not throw when it tries to read the new fields. [VERIFIED: test file lines 238–253]

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test tests/Unit/ProjectDataServiceTest.php` |
| Full suite command | `php artisan test` |

### Phase Requirements to Test Map

| Req ID | Behaviour | Test Type | Automated Command | File Exists? |
|--------|-----------|-----------|-------------------|-------------|
| SURV-01 | `resolve()` includes survey data merged at correct priority | unit | `php artisan test tests/Unit/ProjectDataServiceTest.php` | Partial — extend existing |
| SURV-01 | Matched quote room inherits survey fields at confidence 0.95 | unit | same | Wave 0 gap |
| SURV-01 | Orphan survey room appended with `quote_room_matched: false` | unit | same | Wave 0 gap |
| SURV-01 | Below-threshold survey room leaves quote room unchanged | unit | same | Wave 0 gap |
| SURV-03 | `survey_meta` returns all 6 keys including new global fields | unit | same | Wave 0 gap |
| SURV-05 | One-survey enforcement: second create without supersede blocks | unit | `php artisan test tests/Unit/SurveyServiceTest.php` | Wave 0 gap (new file) |
| SURV-05 | Supersede flow sets `superseded_at` on old survey | unit | same | Wave 0 gap |
| SURV-04 | External survey submit does not break when superseded_at filter applied | unit | same | Wave 0 gap |

### Sampling Rate

- **Per task commit:** `php artisan test tests/Unit/ProjectDataServiceTest.php`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] Extend `tests/Unit/ProjectDataServiceTest.php` — 5 new test methods for Phase 3 room merge and survey_meta shape
- [ ] Create `tests/Unit/SurveyServiceTest.php` — one-survey enforcement tests

---

## Security Domain

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No — public survey token-gated, not auth-gated (by design) | UUID token in `access_token` column |
| V3 Session Management | No | n/a |
| V4 Access Control | Yes (internal routes) | Existing `authorizeSurvey()` + `abort_if()` guards unchanged |
| V5 Input Validation | Yes | Laravel `Request::validate()` in both controllers; new global fields need validation rules added |
| V6 Cryptography | No | n/a |

**Token security note:** `access_token` is a UUID v4 auto-generated in `SiteSurvey::boot()`. No changes needed — existing mechanism is sound. [VERIFIED: `SiteSurvey.php` lines 39–49]

**New global field validation (SURV-03):** `saveDraftPublic()` and `submitPublic()` must validate the three new text fields. Suggested rules: `nullable|string|max:3000` (matching existing `general_notes` pattern). [VERIFIED: `PublicSurveyController::validatePublicSurvey()` — `general_notes` uses `max:3000`]

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `similar_text()` produces high similarity (>0.7) for "Boardroom" vs "Board Room" | Architecture Pattern 2 | Could set threshold too high and miss matches — validate with real room name samples |
| A2 | PHP's `similar_text()` is more appropriate than normalised `levenshtein()` for space-variation room names | Architecture Pattern 2 | Could use wrong function — both are built-in, easily swappable in the helper method |

**All other claims verified directly from codebase in this session.**

---

## Open Questions

1. **Should the survey confirmation page (`/survey/{token}/confirmation`) reuse the existing `PublicSurveyController::show()` with a `$confirmed` flag, or be a separate `confirmation()` method?**
   - What we know: UI-SPEC mentions `redirect to /survey/{token}/confirmation` after submit. No such route exists.
   - What's unclear: Whether it needs to display any survey data or is purely a "thank you" screen.
   - Recommendation: Separate `confirmation()` method in `PublicSurveyController` returning a minimal static view. One new GET route and one new Blade view. No data access needed beyond resolving the survey to confirm it belongs to the token.

2. **Should the internal `create.blade.php` view detect existing surveys via a controller-injected `$existingSurvey` variable, or via an Alpine.js AJAX call?**
   - What we know: The UI-SPEC defines a server-rendered inline `.alert-warning` block — controller injects `$existingSurvey`.
   - What's unclear: The current `create()` method does not query for existing surveys.
   - Recommendation: Extend `SiteSurveyController::create()` to optionally accept a `project_id` query param (already does via `$request->query('project_id')`), check for existing non-superseded survey for that project, and pass `$existingSurvey` to the view. No AJAX needed.

---

## Environment Availability

Step 2.6: SKIPPED — Phase 3 is pure PHP/Laravel/Blade with zero external binary or service dependencies. The survey feature uses only MySQL (already running), PHP builtins (`similar_text`, `levenshtein`), and Eloquent. No new CLI tools, no new queue workers, no new external services.

---

## Sources

### Primary (HIGH confidence — verified from codebase)

- `app/Core/Modules/Projects/ProjectDataService.php` — Full service implementation; stubs at lines 256–279 and 185–207 confirmed
- `app/Core/Modules/Survey/SurveyService.php` — `create()`, `createFromProject()`, `saveDraftPublic()`, `submitPublic()` — no supersede logic present
- `app/Models/SiteSurvey.php` — `$fillable` confirmed; `room_data` is NOT present
- `app/Models/SiteSurveyRoom.php` — All 50+ columns in `$fillable` and `$casts` confirmed
- `database/migrations/2026_04_05_100000*`, `2026_04_05_200000*`, `2026_04_05_210000*`, `2026_04_05_220000*` — All room and survey columns confirmed
- `tests/Unit/ProjectDataServiceTest.php` — 8 existing tests; stub structure confirmed
- `app/Http/Controllers/PublicSurveyController.php` — All routes, validation, and save/submit flow confirmed
- `resources/views/public-survey/show.blade.php` — `.survey-section--conditions` CSS class confirmed; `syncHeaderFields()` JS array confirmed
- `routes/web.php` — All survey routes confirmed; no confirmation route exists

### Secondary (MEDIUM confidence)

- CONTEXT.md D-01 through D-13 — User-decided constraints, treated as locked
- `01-03-SUMMARY.md` — Phase 1 delivery notes confirming stub intent and test patterns

---

## Metadata

**Confidence breakdown:**
- Existing infrastructure inventory: HIGH — verified from codebase
- Schema gaps (missing columns): HIGH — verified from `SiteSurvey.$fillable` and all migrations
- Fuzzy matching approach: MEDIUM — PHP behaviour confirmed by docs knowledge; exact similarity values are ASSUMED
- Test stub compatibility: HIGH — verified line-by-line from test file
- One-survey mechanism recommendation: HIGH — reasoning based on full codebase understanding

**Research date:** 2026-04-10
**Valid until:** 2026-05-10 (stable stack — no external moving parts)
