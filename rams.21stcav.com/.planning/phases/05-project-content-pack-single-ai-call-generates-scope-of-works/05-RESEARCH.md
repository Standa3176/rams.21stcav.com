# Phase 5: Project Content Pack — Research

**Researched:** 2026-04-11
**Domain:** AI content pre-generation — prompts, AJAX endpoints, review form, extraction job, O&M/method-statement consumers
**Confidence:** HIGH — all findings verified by reading source files directly

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- D-01: Add `room_overviews[].description` alongside existing `summary` — both from same overview text
- D-02: `summary` = key-value block (RAMS). `description` = prose paragraph 2-4 sentences (O&M room narratives)
- D-03: Both fields editable per room with individual regenerate button (AJAX, same endpoint pattern)
- D-04: Add `works_overview` — 2-3 sentence executive summary. Separate from `scope_of_works`
- D-05: `scope_of_works` (full paragraph) for RAMS body / O&M intro / method statement. `works_overview` (short) for worksheet covers / O&M cover header
- D-06: `works_overview` stored in `extracted_data` alongside `scope_of_works`. Both editable
- D-07: Auto-generate content pack at end of `ExtractQuoteJob::handle()` after room overviews parsed
- D-08: Manual regenerate available via review form AJAX buttons (extend existing endpoints)
- D-09: Auto-generation is best-effort — AI failure during extraction leaves fields empty, never fails the job
- D-10: Extend `RoomOverviewSummaryPrompt` to return both `summary` AND `description` per room in one combined call
- D-11: `works_overview` added to `ScopeOfWorksPrompt` as second output field (separate call from room prompt)
- D-12: `RoomOverviewSummaryService::summarize()` updated to return `description` per room
- D-13: `MethodStatementService::buildScope()` prefers `scope_of_works` from `parsedQuote` first
- D-14: Method statement room summary wiring unchanged
- D-15: O&M Pass 2 `generateContent()` receives `scope_of_works` and per-room `description` as additional context
- D-16: `OmManualPrompt::forContent()` includes scope + room descriptions in prompt body
- D-17: Room `description` editable inline per room; one regenerate button triggers combined summary+description
- D-18: `works_overview` and `scope_of_works` both in Project Info section; each has a Generate button
- D-19: New fields use textarea, same styling as existing `scope_of_works`
- D-20: `RamsReviewValidatorService` extended for `works_overview` (nullable, string, max:2000) and `room_overviews.*.description` (nullable, string, max:10000)

### Claude's Discretion
- Exact prose style / length of room `description` (~3 sentences covering room type, main AV solution, notable infrastructure)
- Whether `works_overview` and `scope_of_works` are generated in same call or separate (separate preferred per D-11)
- Cache key strategy for combined room prompt
- Whether to extract `works_overview` into new prompt class or add to `ScopeOfWorksPrompt` as second output field

### Deferred Ideas (OUT OF SCOPE)
- Phase 6: RAMS & document quality improvements using content pack
- Phase 7: Survey intelligence
- Phase 8: Cable schedule quality
- Per-room `works_overview` (room-level short summary)
</user_constraints>

---

## Summary

**Key findings in 5 bullet points:**

1. **`RoomOverviewSummaryPrompt` currently returns `{ "summaries": [{ "room": "...", "summary": "..." }] }` only.** The `summary` field is a single-line JSON string with `\n`-escaped line breaks between key-value pairs. Extending it to also return a `description` field inside each summaries entry is a contained prompt change with a matching service-layer change.

2. **The `generateRoomSummary` AJAX endpoint returns `{ works_summary: "..." }` only, and the JS writes this to the `works_summary` textarea.** The result is NOT auto-saved — the user must press Save to persist it. Extending it to also return `description` requires both the endpoint and the JS handler to be updated.

3. **`scope_of_works` is already a first-class field in the review schema** — normalised by `RamsReviewDataService::normalise()`, passed through `parseReviewPayload()` at line 800, and already injected into RAMS by `RamsBuilderService` (line ~189). The AJAX generate endpoint (`generateScopeOfWorks`) reads from `extracted_data`, calls `ScopeOfWorksPrompt`, and returns `{ scope_of_works: "..." }` which the JS puts into the textarea without auto-saving.

4. **`ExtractQuoteJob::handle()` currently ends at the DB transaction block (lines 99–163), followed by a `Log::info` call and a `failed()` hook.** Content pack generation must be inserted AFTER the transaction that persists `extracted_data` (because the room overviews are saved inside the transaction), wrapped in a try/catch so AI failure does not propagate.

5. **`OmManualGeneratorService::generateContent()` calls `buildContentContext()` which builds a flat array of `{ project_name, project_ref, client_name, site_address, notes, rooms[] }`.** Neither `scope_of_works` nor room `description` fields are currently included. The context is passed directly to `OmManualPrompt::forContent()` which embeds it into the prompt body — adding new keys to the context and referencing them in the prompt body is a localised change.

**Primary recommendation:** Follow the decisions in CONTEXT.md exactly. All integration points are well-understood from source reading. No structural changes to any existing pipeline are needed — only targeted extensions at each identified point.

---

## Integration Points

### 1. RoomOverviewSummaryPrompt — extend JSON shape
- **File:** `app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php`
- **Current output:** `{ "summaries": [{ "room": "...", "summary": "..." }] }`
- **New output:** `{ "summaries": [{ "room": "...", "summary": "...", "description": "..." }] }`
- The `summary` field keeps its existing format (key-value, `\n`-escaped). The new `description` field is a prose paragraph.
- `CRITICAL JSON RULE` comment in the existing prompt applies equally to `description` — it must be a single-line JSON string with escaped newlines.
- `maxTokens` will likely need increasing from 1200 to ~2000 to accommodate the extra field per room.

### 2. RoomOverviewSummaryService::summarize() — extract description per room
- **File:** `app/Services/RoomOverviewSummaryService.php`
- **Current:** reads `$item['summary']` from AI result, merges back into rooms array
- **New:** also reads `$item['description']`, merges back alongside `summary`
- The filter `fn ($r) => trim((string) ($r['overview'] ?? '')) !== ''` (rooms with empty overview are skipped) must be preserved — rooms without overview get `description => ''`
- Fallback path (AI fails) currently sets `summary` from `fallbackSummary()`. Must also set `description => ''` on fallback.

### 3. generateRoomSummary AJAX endpoint — return description
- **File:** `app/Http/Controllers/ProjectPackageReviewController.php`, method `generateRoomSummary()` (line ~484)
- **Current response:** `['works_summary' => $worksSummary]`
- **New response:** `['works_summary' => $worksSummary, 'description' => $description]`
- The `$results` array from `$this->roomSummaryService->summarize(...)` will now also contain `description` for the single-room call. Extract it at line ~517.

### 4. generateScopeOfWorks AJAX endpoint — return works_overview
- **File:** `app/Http/Controllers/ProjectPackageReviewController.php`, method `generateScopeOfWorks()` (line ~405)
- **Current:** calls `ScopeOfWorksPrompt`, returns `['scope_of_works' => $text]`
- **New:** also returns `['scope_of_works' => $text, 'works_overview' => $worksOverview]`
- Extend `ScopeOfWorksPrompt` to return `{ "scope_of_works": "...", "works_overview": "..." }` in the same AI call (D-11 prefers separate call but same prompt is acceptable per Claude's Discretion)

### 5. ExtractQuoteJob — insert content pack generation
- **File:** `app/Jobs/ExtractQuoteJob.php`
- **Insert after:** the DB transaction block closes at line ~163 (the `});` at end of `DB::transaction(...)`)
- **Insert before:** `Log::info('ExtractQuoteJob: extraction complete', ...)`
- Content pack generation must re-read `$this->package->fresh()->extracted_data` (or use `$extracted` array) to get `room_overviews` that were just saved
- Wrap entirely in `try/catch (\Throwable)` — log warning on failure, do NOT rethrow (D-09)
- After generating, call `$this->package->update(['extracted_data' => $updatedExtracted])` to merge the new fields in

### 6. MethodStatementService::buildScope() — prefer scope_of_works
- **File:** `app/Services/MethodStatementService.php`, method `buildScope()` (line ~107)
- **Current chain:** tasks → classifier summary → equipment summary → fallback string
- **New chain:** `$parsed['scope_of_works']` (non-empty) → tasks → classifier summary → equipment summary → fallback
- `$parsedQuote` is the first argument to `generate()`, passed through to `buildScope()`. The `scope_of_works` field from `extracted_data` / `reviewed_data` ends up in `parsedQuote` when called from RamsBuilderService context.

### 7. OmManualGeneratorService::generateContent() — enrich context
- **File:** `app/Core/Modules/OMManual/OmManualGeneratorService.php`, method `buildContentContext()` (line ~369)
- **Current context keys:** `project_name, project_ref, client_name, site_address, notes, rooms[]`
- **Add:** `scope_of_works` from `extracted_data['scope_of_works']` and per-room `description` appended to each room entry
- The "new shape" branch (line 374: `isset($extractedData['rooms'])`) is the ProjectDataService path. The "legacy shape" branch is the PDF-uploaded path. Both need enrichment, but `scope_of_works` and `description` will only be available in the new shape (project-linked O&Ms).

### 8. OmManualPrompt::forContent() — include new context keys
- **File:** `app/Core/AI/Prompts/OmManualPrompt.php`, method `buildContentPrompt()` (line ~109)
- **Current:** reads `project_name, client_name, site_address, project_ref, rooms, notes`
- **Add:** render `scope_of_works` as a "Project Scope" block above the rooms JSON; per-room `description` injected into each room's entry or as a preamble above the equipment list
- Safe to add conditionally (`if (!empty($context['scope_of_works']))`) — no breakage if field absent

---

## Prompt Extension Strategy

### RoomOverviewSummaryPrompt changes

The prompt currently demands:
```
{ "summaries": [{ "room": "Room Name", "summary": "Room Type: ...\\nDisplay: ..." }] }
```

Extend to:
```
{
  "summaries": [
    {
      "room": "Room Name",
      "summary": "Room Type: ...\\nDisplay: ...",
      "description": "This boardroom receives a 75-inch interactive display wall-mounted above the presentation wall, together with a fully integrated video conferencing system. AV signal is distributed wirelessly via USB-C inputs supplemented by a wall plate. New power and data points are required at the display and rack locations."
    }
  ]
}
```

**Rules to add to systemMessage():**
- `description` = 2-4 sentence prose paragraph. Plain English. British spelling.
- Must describe: room type/purpose, main AV solution installed, any notable infrastructure note (power, data, cabling, access constraints).
- Do NOT use bullet points, field labels, or markdown in `description`.
- Same "CRITICAL JSON RULE: single-line string, escaped `\n` for line breaks" applies.

**`maxTokens` increase:** 1200 → 2000 (approximately 800 extra tokens across typically 3-8 rooms, each adding ~50-80 tokens for description).

### ScopeOfWorksPrompt changes

Current return: `{ "scope_of_works": "..." }`

Extend to: `{ "scope_of_works": "...", "works_overview": "..." }`

Add to `build()`:
- `works_overview`: 2-3 sentence executive summary. Shorter than `scope_of_works`. Suitable for a cover page or heading intro. No bullet points.

Add to system message: instructions for the `works_overview` field.

`maxTokens` increase: 600 → 900 (small — works_overview is short).

### Cache key strategy (Claude's Discretion)

The AICache uses SHA-256 of the built prompt text as the key. Adding new output fields to the prompt text will change the SHA-256 hash, which means:
- **Existing cache entries for `RoomOverviewSummaryPrompt` are automatically invalidated** when the prompt text changes. This is correct behaviour — we want the new response shape, not the old cached one.
- **Existing cache entries for `ScopeOfWorksPrompt` are similarly invalidated.**
- No manual cache busting is needed. The cache TTL is 30 days by default (`AI_CACHE_TTL_DAYS`).

This is the expected and safe path. No special cache handling required.

---

## AJAX / Save Flow

### scope_of_works (existing)

1. User clicks "✨ Generate" button (`btn-gen-scope`)
2. JS calls `generateScopeOfWorks()` which POSTs to `route("project-packages.scope-of-works", $package)` with empty body
3. Controller reads `$package->extracted_data['room_overviews']`, builds room lines, calls `ScopeOfWorksPrompt` via `AIManager::run()`
4. Returns `{ scope_of_works: "..." }`
5. **JS puts value into textarea only (`field.value = data.scope_of_works`)**
6. **NOT auto-saved — user must click Save (main form POST)**
7. On form POST: `parseReviewPayload()` captures `scope_of_works` at line 800 (`$raw['scope_of_works'] = trim(...)`)
8. `package->update(['extracted_data' => $merged])` saves it into `extracted_data`

### works_overview (new — same pattern)

New flow is identical to `scope_of_works`:
- Same "✨ Generate" button in Project Info section, different ID (e.g. `btn-gen-overview`)
- Same AJAX endpoint (`generateScopeOfWorks` extended to also return `works_overview`)
- JS puts value into new `works_overview` textarea (`id="works-overview-field"`)
- NOT auto-saved — persisted on form POST
- `parseReviewPayload()` must capture `works_overview` (add line analogous to line 800)

### room description (new — same pattern as works_summary)

1. User clicks "✨ Generate" on a room row
2. JS calls `generateRoomSummary(btn)` (existing function)
3. **Currently:** sends `{ room, overview, solution_type_id }`, receives `{ works_summary }`, writes to `works_summary` textarea
4. **Extended:** receives `{ works_summary, description }`, also writes `description` to new `description` textarea in same row
5. NOT auto-saved — persisted on main form POST
6. `parseReviewPayload()` must capture `room_overviews[*][description]` in the room overviews loop (line ~854)
7. `RamsReviewDataService::normaliseRoomOverviews()` must include `description` in its output shape

**Critical detail:** `summary` is currently stored as a hidden input (`<input type="hidden" name="room_overviews[{{ $ri }}][summary]">`). The `works_summary` textarea is the user-editable version. `description` will be an editable textarea (per D-17, D-19), NOT a hidden field.

---

## O&M Enrichment Approach

### buildContentContext() — where to inject

The new-shape path in `buildContentContext()` (line ~374) currently builds rooms from `$extractedData['rooms']`. However, for project-linked O&Ms (the main path via `buildContextFromProjectData()`), the `extracted_data['rooms']` array is built from `ProjectDataService` output — it does not contain per-room `description` fields from the content pack.

**The right injection point is `buildContextFromProjectData()`** (line ~205), which also builds the rooms array. At this point the method has access to `$project`, so it can load the linked `ProjectPackage` and read `extracted_data['room_overviews']` to match `description` fields by room name.

Alternative: pass the package directly to `buildContextFromProjectData()` as an optional second argument, or look it up via `$project->projectPackages()->latest()->first()`.

**Recommended approach:**
```php
// Inside buildContextFromProjectData(): after building $rooms, enrich with descriptions
$package = $project->projectPackages()->where('status', 'reviewed')->latest()->first()
    ?? $project->projectPackages()->latest()->first();

$descriptionsByRoom = [];
if ($package) {
    foreach ($package->extracted_data['room_overviews'] ?? [] as $ro) {
        $name = trim((string) ($ro['room'] ?? ''));
        $desc = trim((string) ($ro['description'] ?? ''));
        if ($name !== '' && $desc !== '') {
            $descriptionsByRoom[$name] = $desc;
        }
    }
}

// Merge into each room
foreach ($rooms as &$room) {
    $room['description'] = $descriptionsByRoom[$room['name']] ?? '';
}
```

### OmManualPrompt::forContent() — where to render

In `buildContentPrompt()`, after the "Project Ref" line and before the "Installed Equipment" block, add:

```
PROJECT SCOPE
-------------
{$scopeOfWorks}
```

For per-room descriptions, when encoding `$rooms` as JSON the description field will be present in the array — the prompt instructions can reference it: "For each room, a `description` field is provided with the installed AV solution narrative; use this to inform the system description and operating procedures for that room."

This avoids restructuring the INSTALLED EQUIPMENT JSON block, which the AI schema expects as an array.

---

## Method Statement Fix

### Exact change needed in `MethodStatementService::buildScope()`

**Current** (line ~108):
```php
private function buildScope(array $parsed, array $classified): string
{
    // Prefer tasks extracted from the quote (most specific)
    if (! empty($parsed['tasks'])) {
        $tasks = array_slice($parsed['tasks'], 0, 5);
        return implode('; ', $tasks);
    }

    // Fall back to the classifier's human-readable equipment summary
    if (! empty($classified['summary'])) {
        return $classified['summary'];
    }

    $equip = $this->buildEquipmentSummary($parsed);
    if ($equip !== '') {
        return $equip;
    }

    return 'AV installation works as per quotation';
}
```

**After Phase 5** (prepend one guard at the top):
```php
private function buildScope(array $parsed, array $classified): string
{
    // Prefer the human-reviewed scope_of_works paragraph when available.
    $scope = trim((string) ($parsed['scope_of_works'] ?? ''));
    if ($scope !== '') {
        return $scope;
    }

    // [rest unchanged]
    if (! empty($parsed['tasks'])) { ... }
    ...
}
```

### How `scope_of_works` reaches `$parsed` in MethodStatementService

`MethodStatementService::generate($parsedQuote, $classified)` is called from `MethodStatementGeneratorService`. The `$parsedQuote` is the `reviewed_data` / `extracted_data` array for the package. `scope_of_works` is already a top-level key in `extracted_data` (saved via `parseReviewPayload()` and persisted to `extracted_data`). No additional plumbing is needed — the field is already accessible as `$parsedQuote['scope_of_works']`.

---

## Review Form Changes

### Project Info section — add `works_overview` field

**Location in view:** after the existing `scope_of_works` textarea block (lines ~369-390), before the closing `</div>` of the Project Info card.

**Add:**
```blade
{{-- Works Overview --}}
<div class="form-group" style="margin-top:1rem;margin-bottom:0;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
        <label class="form-label" style="margin:0;">
            Works Overview
            <span style="color:var(--text-muted);font-weight:400;">(2–3 sentence executive summary — used on worksheet covers and O&amp;M header)</span>
        </label>
        <button type="button"
                id="btn-gen-overview"
                class="btn btn-outline btn-sm"
                onclick="generateWorksOverview()"
                title="Generate from room overviews">
            ✨ Generate
        </button>
    </div>
    <textarea id="works-overview-field"
              name="works_overview"
              class="form-control"
              rows="3"
              maxlength="2000"
              placeholder="Click ✨ Generate or type a short executive summary here.">{{ old('works_overview', $reviewPayload['works_overview'] ?? '') }}</textarea>
</div>
```

**JS to add:**
```javascript
function generateWorksOverview() {
    // Calls the same endpoint as generateScopeOfWorks().
    // Endpoint now returns both scope_of_works AND works_overview.
    const btn   = document.getElementById('btn-gen-overview');
    const field = document.getElementById('works-overview-field');
    // ... same fetch pattern, reads data.works_overview from response
}
```

**Alternative:** Extend `generateScopeOfWorks()` JS function to also populate `works-overview-field` when `data.works_overview` is returned. This avoids a separate fetch.

### Room overviews table — add `description` column

**Current columns:** Room / Space | Solution Type | Phrased Overview | AV Works Summary

**New column:** add "Room Description (O&M)" as a 5th column between "Phrased Overview" and "AV Works Summary" OR below the works_summary textarea in the same cell (to avoid widening the table too much).

**Recommended:** Add `description` as a new textarea below `works_summary` in the same table cell (no new column needed, matching D-17 "editable inline per room"):

```blade
{{-- In the AV Works Summary td, below the existing works_summary textarea --}}
<label style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem;display:block;">
    Room Description (O&amp;M Prose)
</label>
<textarea name="room_overviews[{{ $ri }}][description]"
          rows="3"
          class="av-room-description-textarea"
          placeholder="2–4 sentence prose paragraph for O&M room narrative…">{{ old("room_overviews.{$ri}.description", $ro['description'] ?? '') }}</textarea>
```

The existing "✨ Generate" button already triggers `generateRoomSummary(this)` — extend the JS handler to also populate this `description` textarea when `data.description` is returned.

### parseReviewPayload() — new fields to capture

1. After line 800 (`$raw['scope_of_works'] = trim(...)`), add:
   ```php
   $raw['works_overview'] = trim((string) ($raw['works_overview'] ?? ''));
   ```

2. In the room overviews loop (line ~854), add `description` to the room array:
   ```php
   $roomOverviews[] = [
       'room'             => $room,
       'overview'         => trim((string) ($ro['overview']      ?? '')),
       'works_summary'    => trim((string) ($ro['works_summary'] ?? '')),
       'summary'          => trim((string) ($ro['summary']       ?? '')),
       'description'      => trim((string) ($ro['description']   ?? '')),  // NEW
       'solution_type_id' => $solutionTypeId,
   ];
   ```

### RamsReviewDataService::normaliseRoomOverviews() — add description

In the `array_map` closure (line ~183), add:
```php
'description' => (string) ($r['description'] ?? ''),
```

### RamsReviewDataService::normalise() — add works_overview

In the main normalise output array (line ~74), add:
```php
'works_overview' => (string) ($data['works_overview'] ?? ''),
```

### show() method — room_overviews array built in controller

In `show()` at line ~234, the `room_overviews` array is built from `$savedOverviewsByRoom`. Add `description` to the built array:
```php
$raw['room_overviews'] = array_map(function (string $roomName) use ($savedOverviewsByRoom): array {
    $saved = $savedOverviewsByRoom[$roomName] ?? [];
    return [
        'room'             => $roomName,
        'overview'         => (string) ($saved['overview']         ?? ''),
        'works_summary'    => (string) ($saved['works_summary']    ?? ''),
        'summary'          => (string) ($saved['summary']          ?? ''),
        'description'      => (string) ($saved['description']      ?? ''),  // NEW
        'solution_type_id' => (int)    ($saved['solution_type_id'] ?? 0) ?: null,
    ];
}, $allRoomNames);
```

Also add `description` to the `generateSurveyRooms()` room expansion at line ~621 to prevent it being dropped on regeneration.

---

## Risks / Gotchas

### 1. AI cache invalidation (intentional but worth flagging)

When `RoomOverviewSummaryPrompt::build()` changes to request `description` in the JSON output, every existing cached room summary response becomes stale and will not be returned from cache (the SHA-256 key will differ). All rooms will need a fresh AI call on the next generation. This is correct and expected behaviour — the old response shape lacks the `description` field. **Not a bug, but burns API tokens on first use per project.**

### 2. Backward compatibility of room_overviews arrays

Existing `extracted_data` in the database for packages already saved do NOT have a `description` field in their `room_overviews` entries. The code in every consumer that reads `$ro['description']` must use `($ro['description'] ?? '')` defensively. This applies to:
- `show()` in the controller (handled by the `$saved['description'] ?? ''` pattern above)
- `normaliseRoomOverviews()` in `RamsReviewDataService`
- `buildContentContext()` in `OmManualGeneratorService`
- `buildContextFromProjectData()` in `OmManualGeneratorService`
- The prompt builder for O&M

All of these already use `?? ''` defaults throughout — the pattern is established. Just be consistent.

### 3. `generateSurveyRooms` drops unknown fields

At line ~621, the room expansion loop builds new room entries explicitly:
```php
$roomOverviews[] = [
    'room'          => ...,
    'overview'      => ...,
    'works_summary' => ...,
    'summary'       => '',
    'solution_type_id' => ...,
];
```
`description` is not included here. When a PM clicks "Generate Rooms", the description for source rooms is silently dropped. Must add `'description' => $sourceDescription` to this entry. Need to extract `$sourceDescription` from `$savedOverviewsByRoom` alongside `$sourceOverview` and `$sourceWorksSummary`.

### 4. `parseReviewPayload()` category normaliser rejects unknown categories

The `normaliseEquipmentCategory` function only allows `['hardware', 'cables', 'consumables', 'services', 'option']`. The `works_overview` and `description` fields are not in the equipment block so this does not affect them, but the review payload parser is strict — any new top-level field not explicitly captured will silently survive because `$raw = $request->except(['_token', '_method', '_action'])` captures everything first, and the method only overwrites specific keys. So `works_overview` will pass through if added at line 800.

### 5. `scope_of_works` currently not in `reviewed_data` save for the RAMS path

`RamsBuilderService` reads `scope_of_works` from `$reviewedData` (line ~189). The `reviewed_data` for a `RamsDocument` is a different path from the `ProjectPackage::extracted_data`. When `MethodStatementService::buildScope()` is called, the `$parsedQuote` it receives is whatever is passed by `MethodStatementGeneratorService` — verify that it passes the full `reviewed_data` array including `scope_of_works`.

### 6. `works_overview` field is entirely new — not yet in any schema

`works_overview` does not appear anywhere in the existing codebase. Every layer that needs to output or pass it must be explicitly added:
- `RamsReviewDataService::normalise()` output array
- `parseReviewPayload()` capture
- `generateScopeOfWorks()` response
- `OmManualPrompt::forContent()` context
- `RamsReviewValidatorService` validation rules (D-20)
- The view's `$reviewPayload['works_overview'] ?? ''` reference

### 7. The `scope_of_works` auto-generation at extract time needs room_overviews populated first

`ExtractQuoteJob` populates `room_overviews` in `mergeParsedQuoteData()` from `$parsed['room_overviews']`. The content pack generation step (after the transaction) can use `$extracted['room_overviews']` from the already-merged array — **no need to re-read from DB**. The extracted array is in scope at the end of `handle()`.

However, room overviews at extraction time typically have `overview: ""` (the phrased overview text comes from the PDF `OVERVIEWTITLE`/`TXT` tags). If the PDF contains room overview text in these tags, they will be populated. If not, both `summarize()` and `ScopeOfWorksPrompt` will have little to work with — this is expected and the fields will be empty or minimal. User can regenerate after adding overview text.

### 8. `RamsReviewDataService::normalise()` does not include `works_overview`

Currently `normalise()` returns a fixed-key array (line ~74). Adding `works_overview` to the array is required or the review form will always show blank. The `show()` method in the controller calls `$this->reviewDataService->normalise($raw)` and passes the result to the view as `$reviewPayload`. If `works_overview` is not in `normalise()`'s output array, the view's `$reviewPayload['works_overview']` will throw an undefined key error (or return null with `??`).

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `scope_of_works` reaches `MethodStatementService::buildScope()` via `$parsedQuote` array directly from `extracted_data` / `reviewed_data` | Method Statement Fix | If the path through `MethodStatementGeneratorService` does not pass the full data array, the field lookup will always miss |
| A2 | `ProjectPackage::projectPackages()` relationship exists on `Project` model for the O&M enrichment lookup | O&M Enrichment | If relationship is named differently, the lookup will fail at runtime |

---

## Open Questions

1. **Does `MethodStatementGeneratorService` pass `scope_of_works` through to `MethodStatementService`?**
   - What we know: `MethodStatementService::generate($parsedQuote, ...)` — `$parsedQuote` is caller-supplied
   - What's unclear: what `$parsedQuote` contains when called from the RAMS generation pipeline
   - Recommendation: Read `MethodStatementGeneratorService` before writing the plan task for D-13 to confirm the field is present

2. **How does the O&M generator get the ProjectPackage when called via `BuildOmManualJob`?**
   - What we know: `OmManualGeneratorService::buildContextFromProjectData()` receives `$project` only
   - What's unclear: whether `Project` model has a `projectPackages()` relationship or if the package must be passed differently
   - Recommendation: Check `Project` model relationships before writing the O&M enrichment task

---

## Sources

All findings are `[VERIFIED]` — read directly from source files in this session:

- `app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php` — prompt structure, output shape, token budget
- `app/Services/RoomOverviewSummaryService.php` — summarize() logic, fallback, return shape
- `app/Core/AI/Prompts/ScopeOfWorksPrompt.php` — build(), current return shape
- `app/Http/Controllers/ProjectPackageReviewController.php` — all AJAX endpoints, parseReviewPayload(), show(), update()
- `app/Jobs/ExtractQuoteJob.php` — handle() flow, transaction structure, insertion point
- `app/Services/MethodStatementService.php` — buildScope() current chain
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — generateContent(), buildContentContext(), buildContextFromProjectData()
- `app/Core/AI/Prompts/OmManualPrompt.php` — buildContentPrompt(), context keys consumed
- `app/Services/RamsReviewValidatorService.php` — current validation rules
- `app/Services/RamsReviewDataService.php` — normalise(), normaliseRoomOverviews(), scope_of_works in schema
- `resources/views/project-packages/review.blade.php` — scope_of_works field location, room overview table, JS functions

**Research date:** 2026-04-11
**Valid until:** 2026-05-11 (stable codebase, no fast-moving external dependencies)
