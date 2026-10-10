---
phase: 261010-cdf
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/RiskTemplateResolverService.php
  - tests/Feature/Rams/RiskTemplateResolverServiceProjectContextTest.php
  - app/Services/RamsReviewDataService.php
  - app/Http/Controllers/RamsReviewController.php
  - app/Services/RamsBuilderService.php
  - resources/views/rams/quote-review.blade.php
  - tests/Unit/Services/RamsReviewDataServiceTest.php
  - tests/Unit/Services/RamsBuilderServiceTest.php
  - resources/views/site-survey/show.blade.php
  - tests/Feature/SiteSurvey/SiteSurveyShowWorkingHeightFlagTest.php
autonomous: true
requirements: [QUICK-261010-cdf]
must_haves:
  truths:
    - "A survey room recorded as 2–4m or 4m+ working height produces a 'Working at height' hazard, Hard Hat PPE, and access equipment in the generated RAMS — same as the already-working under_2m/over_2m cases, never silently empty."
    - "under_2m keeps producing exactly the existing low-level hazard + Hard Hat, unchanged (no regression, no widening into the full hazard)."
    - "An office reviewer can correct a room's working height on the RAMS review page (rams/quote-review) before Approve & Generate, without the survey being reopened or resubmitted by the engineer."
    - "An office admin reviewing a submitted survey (site-surveys.show) sees a visible flag on any room recorded above 2m, and a visible flag on any room where working height was never recorded — both without needing to expand the room."
  artifacts:
    - path: "app/Services/RiskTemplateResolverService.php"
      provides: "resolveFromProjectContext() treats over_2m, 2_to_4m and over_4m as the same high-tier hazard branch"
    - path: "app/Services/RamsReviewDataService.php"
      provides: "working_height_overrides normaliser, sanitised to the allowed vocabulary, additive to the existing schema"
    - path: "app/Services/RamsBuilderService.php"
      provides: "applyWorkingHeightOverrides() merges reviewer corrections into ProjectContext rooms before RAMS data assembly in runFromReview()"
    - path: "resources/views/rams/quote-review.blade.php"
      provides: "per-room Working Height override select, posted inside #review-form so Save and Approve both carry it"
    - path: "resources/views/site-survey/show.blade.php"
      provides: "per-room header badge for above-2m and for missing working height"
  key_links:
    - from: "app/Services/RamsBuilderService.php::runFromReview"
      to: "app/Services/RiskTemplateResolverService.php::resolveFromProjectContext"
      via: "RamsDataBuilderService::assemble($projectContext) after applyWorkingHeightOverrides() has rewritten $projectContext['rooms']"
      pattern: "applyWorkingHeightOverrides"
    - from: "resources/views/rams/quote-review.blade.php"
      to: "app/Http/Controllers/RamsReviewController.php::parseReviewPayload"
      via: "POST working_height_overrides[idx][room]/[working_height] inside #review-form (cloned verbatim into save-form/approve by existing submitFromReview() JS)"
      pattern: "working_height_overrides"
    - from: "resources/views/site-survey/show.blade.php"
      to: "$survey->survey_data[rooms][n][risks][0][working_height]"
      via: "existing per-room $wizardRisks extraction (already in the room loop)"
      pattern: "workingHeightAboveTwo|workingHeightMissing"
---

<objective>
Fix a live safety-content defect in RAMS generation: a survey room recorded as working at 2–4m or 4m+ currently produces NO working-at-height hazard, NO Hard Hat, and NO access equipment — only `under_2m` and the dead legacy value `over_2m` are handled. The more dangerous the recorded work, the less the RAMS says. This also makes the value correctable by the office before generation, and visible to the office admin reviewing the survey — both without reopening the survey to the engineer.

Purpose: Every RAMS document issued from a survey recording work above 2m must carry the working-at-height hazard and PPE it already carries for `under_2m` and `over_2m` — this is a correctness fix, not a content change. Office staff get a correction path and a visibility flag so a wrong or missing value never again silently ships a hazard-free document.
Output: Fixed resolver (Part 1), a working-height override control on the RAMS review page wired through to generation (Part 2), and an above-2m / missing-value flag on the survey office-review page (Part 3) — each with real, RED-proven tests.
</objective>

<execution_context>
@C:\Users\sonny.tanda\.claude\get-shit-done\workflows\execute-plan.md
@C:\Users\sonny.tanda\.claude\get-shit-done\templates\summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md
@.planning/phases/46.8-survey-capture-refinements/46.8-RESEARCH.md

<interfaces>
<!-- RiskTemplateResolverService::resolveFromProjectContext() — the defect site. -->
<!-- app/Services/RiskTemplateResolverService.php:367-523 -->
Input per room: `risks[0].working_height` is written by the engineer wizard
(`resources/views/surveys/show.blade.php:1706`) as EXACTLY one of three
strings: `under_2m`, `2_to_4m`, `over_4m` (also enforced server-side at
`app/Http/Controllers/SurveyController.php:710`: `$allowedWorkingHeight =
['under_2m', '2_to_4m', 'over_4m'];`). `over_2m` and `ground_level` are named
in this file's own docblock but are NEVER written by any current path —
legacy-only; they must keep behaving exactly as today, never be removed.

Current defect (line 388):
  if ($workingHeight === 'over_2m') { ... 'Working at height' hazard + Hard Hat
      + Podium Steps + Access Tower (if above 3 m) + MEWP (if required) ... }
  elseif ($workingHeight === 'under_2m') { ... 'Working at height (low level)'
      hazard + Hard Hat only ... }
  // no else — 2_to_4m and over_4m fall through to nothing.

Output shape (unchanged by this fix):
  { hazards: [{ title, description, rooms: string[] }], ppe: string[],
    access_equipment: string[] }
mergeHazard($hazardMap, $roomName, $title, $description) dedupes by title
across rooms, appending $roomName to .rooms. There is a SEPARATE, unrelated
'Working at Height' (capital H) tiered branch driven by `engineer_feedback`
further down the same method (lines ~517-554) — do not touch it; it already
handles its own tiers independently and must keep doing so.

<!-- RamsBuilderService — Part 2 wiring. app/Services/RamsBuilderService.php -->
runFromReview(array $reviewedData, array $formData, RamsDocument $record): string  (private, line 133)
  — the ONLY entry point that has human-edited $reviewedData in scope. Line
  ~158: `$projectContext = $this->buildProjectContext($record);` rebuilds
  ProjectContext FRESH from the survey every single generation/regeneration —
  there is currently no override layer at all. buildProjectContext() (private,
  line 1055) wraps everything in try/catch and returns {project_id, rooms:[]}
  on any failure, so it is always safe to call.
  $reviewedData is NOT run through RamsReviewDataService::normalise() on this
  path — Jobs/BuildRamsDocumentJob.php:118-123 passes $record->reviewed_data
  RAW (exactly what RamsReviewController::parseReviewPayload() saved). Any
  sanitisation this task needs at generation time must happen defensively
  inside RamsBuilderService itself, not rely on normalise() having run.
buildFromQuote() / runPipeline() (line 877, 911) is the OTHER caller of
  buildProjectContext() — it has no $reviewedData (first-generation/manual-form
  path) and is intentionally NOT touched by this plan: there is nothing for an
  office reviewer to have corrected yet on that path.

<!-- RamsReviewDataService — existing LOCKED schema, do not touch. -->
normalise() (line 75) returns a fixed top-level key set: project, equipment,
activities, hazards, ppe, access, method_statement_notes, scope_of_works,
works_overview, room_overviews, meta, programme, site_logistics.
normaliseRoomOverviews() (line 224) is LOCKED to EXACTLY 4 keys — room,
overview, works_summary, solution_type_id — enforced by
tests/Unit/Services/RamsReviewDataServiceTest.php::
test_normaliseRoomOverviews_output_contains_only_canonical_keys (Phase 22.1
D-02/D-07/D-08). Do NOT add a 5th key to this schema — it will fail that
locked test and reverses a previous decision. Add working_height_overrides as
a NEW, independent top-level key instead (precedent: RamsEditAdapter already
uses a same-shaped `reviewed_data.project_field_overrides` key for an
unrelated correction, so a `_overrides` suffix key is an established pattern
here, not a new one).

<!-- RamsReviewController — Part 2 posting. app/Http/Controllers/RamsReviewController.php -->
parseReviewPayload(Request $request): array (private, line 175)
  `$raw = $request->except(['_token','_method','_action']);` then mutates
  known keys (equipment, activities, hazards, ...) in place; any OTHER posted
  key passes through untouched into $payload, which update()/approve() persist
  directly to $rams->reviewed_data — RamsReviewValidatorService::validate()
  only checks the keys it has rules for and does not reject unknown keys.
  Follow the existing per-row sanitise-and-reindex pattern used for
  `activities` (lines ~197-209) for the new `working_height_overrides` key:
  trim, drop rows with an empty room name, re-index.

<!-- quote-review.blade.php — Part 2 UI. The main form is #review-form -->
<!-- (line 334, action=rams.approve); save uses a SEPARATE hidden #save-form -->
<!-- (line 1000) that the JS submitFromReview() (line ~1144) populates by -->
<!-- cloning EVERY FormData entry from #review-form — no field allow-list, so -->
<!-- any new named input inside #review-form automatically reaches both Save -->
<!-- and Approve with zero JS changes. -->
Existing Room / Space Overviews table (lines ~566-608) loops `$roomNames`
(array_keys of equipment areas + room_overviews room names, sorted) and
`$roomOverviewMap[$roomName]`. Do not add a column to that table (schema is
locked, see above) — add a new, separate small section immediately after it,
same $roomNames loop, reading `$reviewPayload['working_height_overrides']`
keyed by room name (mirror `$roomOverviewMap` construction).

<!-- site-survey/show.blade.php — Part 3. Per-room loop starts @foreach ($rooms as $room) line 618. -->
Inside that loop (lines ~622-634) the wizard payload for THIS room is already
extracted by index alignment: `$wizard = $survey->survey_data['rooms']
[$loop->index] ?? [];` ... `$wizardRisks = $wizard['risks'] ?? [];`. The room
header (always visible, collapsed or not) runs lines ~656-689, ending with a
$badgeClass badge and an optional T1 badge — append the new flag badge there,
inside the header, so it is visible without expanding the room. The existing
detail table lower down (lines ~1066-1091, gated by `@if($hasRisk)`) must be
left exactly as-is — the new badge does NOT replace or duplicate it.
This file is `site-survey/show.blade.php` (office review of a submitted
survey, `@extends('layouts.app')`, route site-surveys.show, any authenticated
user per authorizeSurvey() at line 760) — NOT the engineer-facing standalone
`surveys/show.blade.php` wizard. Do not touch `surveys/show.blade.php`.
Existing warning colour token already used 3x in THIS SAME FILE (lines 84,
165, 313): `background:#FEF3C7; color:#92400E;` — reuse it verbatim for both
new badges; this is not a new, unverified contrast pairing.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Fix resolveFromProjectContext() to produce the above-2m hazard for 2_to_4m and over_4m (Part 1)</name>
  <files>app/Services/RiskTemplateResolverService.php, tests/Feature/Rams/RiskTemplateResolverServiceProjectContextTest.php</files>
  <behavior>
    New test file, 4 test methods, each calling `app(RiskTemplateResolverService::class)->resolveFromProjectContext(['rooms' => [[ 'name' => 'Room A', 'risks' => [['working_height' => '<value>']] ]]])`:
    - Test 1 (`2_to_4m`): asserts `hazards` contains a row with `title === 'Working at height'` and `rooms === ['Room A']`; asserts `ppe` contains `'Hard Hat'`; asserts `access_equipment` contains `'Podium Steps'`, `'Access Tower (if above 3 m)'`, and `'MEWP (if required)'`. MUST fail RED against current code (falls through to nothing) before the fix.
    - Test 2 (`over_4m`): identical assertions to Test 1 — same hazard, same PPE, same access equipment. MUST fail RED before the fix.
    - Test 3 (`over_2m`, the legacy value): identical assertions to Test 1 — proves the pre-existing branch still works unchanged. MUST already pass (GREEN) both before and after the fix — this is the non-regression proof, run it first and confirm it is already green before touching production code.
    - Test 4 (`under_2m`): asserts `hazards` contains a row with `title === 'Working at height (low level)'`; asserts `ppe` contains `'Hard Hat'`; asserts `access_equipment` is empty (`[]`) — proves the low-level case is untouched and NOT widened into the full hazard. MUST already pass (GREEN) both before and after the fix.
  </behavior>
  <action>
    Run the 4 new tests first and paste/quote the RED failure output for Tests 1 and 2 (Tests 3 and 4 must already be green — quote that too, as the non-regression baseline). Then fix `app/Services/RiskTemplateResolverService.php:388`: widen `if ($workingHeight === 'over_2m')` to `if (in_array($workingHeight, ['over_2m', '2_to_4m', 'over_4m'], true))`. Do not touch the hazard title, description text, PPE list, or access-equipment list inside that branch — this is a condition-widening fix only, per the plan's minimal-fix constraint (do not reword hazard text, do not make MEWP mandatory for over_4m — raise that as a question in the final summary instead of deciding it here). Leave the `elseif ($workingHeight === 'under_2m')` branch untouched. Update the docblock at lines 335 and 356-357 to state the real vocabulary: `working_height` is one of `under_2m | 2_to_4m | over_4m` (current engineer wizard output, enforced server-side in SurveyController) or the legacy `over_2m` (no longer written, kept for backward compatibility with historical data) — and that `2_to_4m` and `over_4m` both resolve to the same high-tier branch as `over_2m`. Do not touch the separate `engineer_feedback`-driven 'Working at Height' (capital H) tiered branch later in the same method. Re-run all 4 tests and confirm all GREEN.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter RiskTemplateResolverServiceProjectContextTest > "$env:TEMP/g1.txt" 2>&1; Get-Content "$env:TEMP/g1.txt" -Tail 20</automated>
  </verify>
  <done>All 4 tests pass; Tests 1 and 2 are proven to have failed before the one-line condition change; the only production diff is the widened `in_array` condition plus the docblock correction — no hazard text, PPE list, or access-equipment list changed.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Let the office correct working height on the RAMS review page before generation (Part 2)</name>
  <files>app/Services/RamsReviewDataService.php, app/Http/Controllers/RamsReviewController.php, app/Services/RamsBuilderService.php, resources/views/rams/quote-review.blade.php, tests/Unit/Services/RamsReviewDataServiceTest.php, tests/Unit/Services/RamsBuilderServiceTest.php</files>
  <behavior>
    - `RamsReviewDataServiceTest`: new test asserting `normalise(['working_height_overrides' => [['room' => 'Board Room', 'working_height' => '2_to_4m'], ['room' => 'Bad Room', 'working_height' => 'not_a_real_value'], ['room' => '', 'working_height' => 'over_4m']]])['working_height_overrides']` keeps the valid row verbatim (`['room' => 'Board Room', 'working_height' => '2_to_4m']`), sanitises the invalid vocabulary value to `''` rather than dropping or passing it through raw, and keeps rows even when `working_height` is blank/invalid but the room name differs — i.e. sanitisation never silently fabricates a real value out of garbage input. MUST fail RED (key does not exist yet) before the service change.
    - `RamsBuilderServiceTest`: new test(s) invoking a new private `applyWorkingHeightOverrides(array $rooms, array $roomOverrides): array` via Reflection (same pattern as `invokeRunFromReview`/`invokeReviewedToRisk` already in this file):
      1. A room named `'Board Room'` with `risks[0].working_height === 'under_2m'` plus an override row `['room' => 'Board Room', 'working_height' => '2_to_4m']` → returned room's `risks[0].working_height === '2_to_4m'`.
      2. The same room with an override row carrying a BLANK `working_height` (`''`) → room's original `'under_2m'` is left untouched (blank override means "no correction, keep the survey value").
      3. An override row for a room name that does not match any room (`'Nonexistent Room'`) → all rooms returned completely unchanged.
      4. An override row carrying an invalid value not in `['under_2m','2_to_4m','over_4m']` (e.g. `'eiffel_tower'`) → ignored, original value untouched — this is the defensive generation-time check, since $reviewedData reaches this method RAW, never through RamsReviewDataService::normalise().
    All 4 MUST fail RED (method does not exist) before the RamsBuilderService change.
  </behavior>
  <action>
    In `RamsReviewDataService`: add `private function normaliseWorkingHeightOverrides(mixed $raw): array` following the exact pattern of `normaliseStringArray`/`normaliseRoomOverviews` — for each row, `room = trim((string)($r['room'] ?? ''))`, `wh = trim((string)($r['working_height'] ?? ''))`, keep the row (even with an empty room, matching existing per-row leniency elsewhere) but sanitise `working_height` to `''` unless it is exactly one of `under_2m`, `2_to_4m`, `over_4m`. Wire it into `normalise()` as a new top-level key `working_height_overrides` — do NOT touch `normaliseRoomOverviews()` or its locked 4-key output.
    In `RamsReviewController::parseReviewPayload()`: add a `working_height_overrides` block mirroring the existing `activities` loop (lines ~197-209) — iterate `$raw['working_height_overrides'] ?? []`, trim `room`/`working_height`, skip rows with an empty room name, re-index, assign back to `$raw['working_height_overrides']`.
    In `RamsBuilderService`: add the private `applyWorkingHeightOverrides(array $rooms, array $roomOverrides): array` method — build a `room name => sanitised working_height` map from `$roomOverrides` (same allow-list as above, applied defensively here too since this path bypasses `normalise()`), skip blank/invalid overrides, then `array_map` over `$rooms`, and for any room whose trimmed `name` has a non-blank valid override, set `risks[0]['working_height']` to the override (preserving all other keys of that risks[0] row and of the room). Call it inside `runFromReview()` immediately after `$projectContext = $this->buildProjectContext($record);` (line ~158): `$projectContext['rooms'] = $this->applyWorkingHeightOverrides($projectContext['rooms'] ?? [], $reviewedData['working_height_overrides'] ?? []);`. Do not touch `buildFromQuote()`/`runPipeline()` — no `$reviewedData` exists on that path.
    In `resources/views/rams/quote-review.blade.php`: immediately after the existing Room / Space Overviews table (closes ~line 608), add a new small table titled "Working Height Corrections" reusing the same `$roomNames` loop and the same room-name lookup pattern as `$roomOverviewMap` (build a parallel `$whOverrideMap` keyed by room name from `$reviewPayload['working_height_overrides'] ?? []`). For each room render a hidden `working_height_overrides[{{ $roomIdx }}][room]` input (value = room name) and a `<select name="working_height_overrides[{{ $roomIdx }}][working_height]">` with options: blank (label "— as surveyed —", selected when no override saved), `under_2m` ("Under 2m"), `2_to_4m` ("2–4m"), `over_4m` ("4m+") — selected value from `old("working_height_overrides.{$roomIdx}.working_height", $whOverrideMap[$roomName]['working_height'] ?? '')`. This section lives inside `#review-form` so the existing `submitFromReview()` JS carries it to both Save and Approve with no JS change.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter RamsReviewDataServiceTest > "$env:TEMP/g2.txt" 2>&1; Get-Content "$env:TEMP/g2.txt" -Tail 20; powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter RamsBuilderServiceTest > "$env:TEMP/g3.txt" 2>&1; Get-Content "$env:TEMP/g3.txt" -Tail 20    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter RamsReviewDataServiceTest > "$env:TEMP/g2.txt" 2>&1; Get-Content "$env:TEMP/g2.txt" -Tail 20; powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter RamsBuilderServiceTest > "$env:TEMP/g3.txt" 2>&1; Get-Content "$env:TEMP/g3.txt" -Tail 20</automated>
  </verify>
  <done>working_height_overrides is a new, independent reviewed_data key (room_overviews' locked 4-key schema is untouched and its existing lock test still passes); applyWorkingHeightOverrides() is unit-proven for override-applied, blank-override-noop, unmatched-room-noop, and invalid-value-noop; runFromReview() calls it on the real $projectContext before assemble(); the review page shows a per-room Working Height select that round-trips through Save and Approve via the existing generic form-clone JS.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Flag above-2m and missing working height on the office survey review page (Part 3)</name>
  <files>resources/views/site-survey/show.blade.php, tests/Feature/SiteSurvey/SiteSurveyShowWorkingHeightFlagTest.php</files>
  <behavior>
    New Feature test, RefreshDatabase, following the SurveyPdfModesTest::makeSurveyWithFullEngineerData() construction pattern (User::factory, SiteSurvey::create with survey_data, matching SiteSurveyRoom rows aligned by the same array order): a survey with 4 rooms —
      - Room "High Room": survey_data.rooms[0].risks[0].working_height = '2_to_4m'.
      - Room "Legacy Room": survey_data.rooms[1].risks[0].working_height = 'over_2m'.
      - Room "Low Room": survey_data.rooms[2].risks[0].working_height = 'under_2m'.
      - Room "Blank Room": survey_data.rooms[3] has NO risks key at all (nothing else set on the risk either — manual_handling_risk/out_of_hours/permits_required all absent, so today's $hasRisk is false and the whole Risks detail block is hidden for this room).
    $this->actingAs($user)->get(route('site-surveys.show', $survey)) then:
      - assertSee('Working height above 2m') for the High Room and Legacy Room cases (one assertion against the full response body is fine; the point is the string appears at all).
      - assertSee('Working height not recorded') (the Blank Room case).
      - Assert the response does NOT associate the "above 2m" flag with Low Room — e.g. render a SECOND survey containing only Low Room (and no other room) and assert that response does NOT contain "Working height above 2m" and does NOT contain "Working height not recorded" (the recorded-and-low case must trigger neither flag).
    All assertions MUST fail RED (none of this markup exists yet) before the blade change.
  </behavior>
  <action>
    In resources/views/site-survey/show.blade.php, inside the existing per-room @foreach ($rooms as $room) block, right where $wizardRisks is already extracted (~line 629), add two computed booleans reading the SAME $wizardRisks[0]['working_height'] the existing detail table already reads: $workingHeightRaw = strtolower(trim((string) ($wizardRisks[0]['working_height'] ?? ''))); $workingHeightAboveTwo = in_array($workingHeightRaw, ['2_to_4m', 'over_4m', 'over_2m'], true); $workingHeightMissing = $workingHeightRaw === ''. In the room header block (the always-visible part, after the existing $t1Room badge markup, before the header closing </div>, ~line 689), add: when $workingHeightAboveTwo, a badge reusing the existing background:#FEF3C7;color:#92400E; token with text "Working height above 2m"; else when $workingHeightMissing, the same token with text "Working height not recorded". Do not alter the existing @if($hasRisk) detail table (~line 1066-1091) — this is purely an additive header badge. Do not touch resources/views/surveys/show.blade.php (the separate, protected standalone engineer wizard).
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SiteSurveyShowWorkingHeightFlagTest > "$env:TEMP/g4.txt" 2>&1; Get-Content "$env:TEMP/g4.txt" -Tail 20</automated>
  </verify>
  <done>Office review page flags above-2m rooms (including the legacy over_2m value) and rooms with no recorded working height, visibly in the room header without expanding the room; under_2m rooms trigger neither flag; the existing risk detail table is unchanged.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|--------------|
| Office reviewer -> RAMS review form | Office-entered working_height_overrides[idx][working_height] is untrusted browser input that flows into the generated safety document |
| Engineer wizard -> survey_data JSON -> office review page | Survey-captured risk data is rendered back to an office admin; display-only, no write path from this page |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|------------------|
| T-261010-cdf-01 | Tampering | RamsReviewController::parseReviewPayload / RamsBuilderService::applyWorkingHeightOverrides | mitigate | Both the controller-side normaliser and the generation-time applyWorkingHeightOverrides() independently reject any working_height value outside the fixed 3-item allow-list (under_2m/2_to_4m/over_4m) — defence in depth, since the generation path reads raw reviewed_data and cannot rely on the controller having run |
| T-261010-cdf-02 | Tampering | RiskTemplateResolverService::resolveFromProjectContext | accept | Input already passes through SurveyController's server-side allow-list before ever reaching this method; this plan only widens which already-valid values trigger the hazard branch, it does not relax input validation |
| T-261010-cdf-03 | Repudiation/Integrity | Generated RAMS document | mitigate | This whole plan IS the mitigation — a wrong/missing working height no longer silently produces a hazard-free document; Part 3's flag plus Part 2's correction path close the gap that caused the live defect |
| T-261010-cdf-04 | Information Disclosure | site-survey/show.blade.php new badge | accept | The badge renders only the already-displayed working_height value's severity tier and a "not recorded" state — no new data surfaced that the existing detail table on the same page doesn't already show when present |
</threat_model>

<verification>
Run all automated gates listed in the tasks above (filters RiskTemplateResolverServiceProjectContextTest, RamsReviewDataServiceTest, RamsBuilderServiceTest, SiteSurveyShowWorkingHeightFlagTest), then run the D-06 baseline gate to prove no regression:
  powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Baseline > "$env:TEMP/gbase.txt" 2>&1; Get-Content "$env:TEMP/gbase.txt" -Tail 10
Gate is >= 159 passed AND 0 failed (2 pre-existing ext-imagick skips allowed) — never equality against 161.
Also run the protected-file hash check (none of this plan's files are in that list, but confirm no incidental change):
  powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Hashes
</verification>

<success_criteria>
- resolveFromProjectContext() produces the full working-at-height hazard + Hard Hat + access equipment for 2_to_4m, over_4m, and the legacy over_2m; under_2m is unchanged.
- An office reviewer can set a per-room working-height override on the RAMS review page that survives Save and is honoured by runFromReview() at generation time, without touching the survey or the engineer.
- The office survey review page visibly flags any room above 2m and any room with no recorded working height, in the always-visible room header.
- All new tests pass; the D-06 baseline gate still reports >= 159 passed, 0 failed; the three protected files are still byte-identical to 4abd2b24.
- No stored survey value was rewritten or migrated; no room_overviews schema key was added; surveys/show.blade.php was not touched.
</success_criteria>

<output>
Create `.planning/quick/261010-cdf-working-at-height-produces-no-rams-hazar/261010-cdf-SUMMARY.md` when done. In it, explicitly raise as an open question for the user (not decided here): whether over_4m should make MEWP mandatory rather than "if required" — this plan deliberately left that safety-content judgement call alone.
</output>
