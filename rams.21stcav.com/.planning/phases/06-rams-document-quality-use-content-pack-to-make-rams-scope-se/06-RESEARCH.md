# Phase 6: RAMS & Document Quality — Research

**Researched:** 2026-04-12
**Domain:** Laravel service layer, Blade PDF templates, AI prompt enrichment
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 — RAMS scope section: scope_of_works exclusive, notice when empty**
- When `$scopeOfWorks` is non-empty, display it as the sole scope paragraph. Remove the silent fallback to `$project['works_description']` or the generic "AV installation works as per quotation." string.
- When `$scopeOfWorks` is empty, display a visible notice: "Scope of works not generated — return to the review form and click Generate." This makes missing content obvious rather than hiding it with boilerplate.
- File: `resources/views/pdf/rams.blade.php` (line ~298)

**D-02 — RamsBuilderService: skip fresh AI summarize() when saved summaries exist**
- In `RamsBuilderService::build()`, before calling `$this->roomOverviewSummary->summarize()`, check if all rooms in `reviewed_data['room_overviews']` already have a non-empty `summary` field.
- If yes (all summaries populated): skip the `summarize()` call entirely. Use the reviewed_data room_overviews as-is.
- If no (any room has empty summary): call `summarize()` only for rooms with empty summaries, or fall back to calling `summarize()` for the full set as before.
- File: `app/Services/RamsBuilderService.php` (lines ~133–146)

**D-03 — Method statement prompt: enrich with scope + per-room descriptions**
- `MethodStatementPrompt::build()` receives additional context: `scope_of_works`, `works_overview`, and the array of `room_overviews` (each with `room` name and `description` prose paragraph).
- The prompt instructs the AI to reference actual rooms and AV solutions by name.
- The 6 fixed phase titles are retained — required for UK AV RAMS compliance.
- Files: `app/Core/AI/Prompts/MethodStatementPrompt.php`, `app/Services/MethodStatementService.php`

**D-04 — Worksheet prompt: pass room description + works_overview**
- `WorksheetPrompt::build()` receives two additional fields:
  - `description` (string) — the Phase 5 prose paragraph for this specific room
  - `works_overview` (string) — the project-level 2–3 sentence executive summary
- The constraint "base steps ONLY on equipment and survey data provided — do not invent items" remains in force.
- Files: `app/Core/AI/Prompts/WorksheetPrompt.php`, `app/Services/WorksheetGeneratorService.php`

### Claude's Discretion

- Exact wording of the "scope not generated" notice in the RAMS PDF
- Whether the empty-summary check in D-02 uses "all non-empty" or "any non-empty" as the threshold — "all non-empty" is preferred (skip only if all rooms have summaries)
- Whether to add `works_overview` as a cover-page subtitle in the RAMS PDF (cover subtitle is implementer's call, not a user-locked requirement)
- How `description` is retrieved for each room in WorksheetGeneratorService (from ProjectDataService resolved rooms, or from the package's reviewed_data directly)

### Deferred Ideas (OUT OF SCOPE)

- Phase 7: Survey intelligence — Pre-populating survey rooms from quote, solution-type-aware survey prompts, cable run length capture
- Phase 8: Cable schedule quality — Description-based cable type inference, survey-length-aware schedule generation
- RAMS cover page `works_overview` subtitle — nice-to-have; implementer may add if clean, not required
- O&M document changes — Phase 5 already enriched O&M; any further O&M improvements are deferred to Phase 7 or beyond
</user_constraints>

---

## Summary

Phase 6 is a targeted enrichment of four files across two concerns: (1) the RAMS PDF scope rendering and (2) the AI prompt context passed to MethodStatementPrompt and WorksheetPrompt. All content changes consume fields that Phase 5 already generates and stores — no new AI calls, no new data fields, no new migrations.

The work is read-code-then-change: each decision maps to a precisely identified code location with known input/output shapes. The two service changes (D-02, D-03) are surgical edits to `RamsBuilderService::runFromReview()` and `MethodStatementService::generate()`. The two prompt changes (D-03, D-04) add clearly labelled context blocks to `MethodStatementPrompt::build()` and `WorksheetPrompt::build()`. The Blade change (D-01) is a single conditional block swap at line 298.

**Primary recommendation:** Implement in order D-01 → D-02 → D-03 → D-04. Each decision is independent; earlier decisions do not block later ones. D-02 reduces AI cost per RAMS generation and should land early.

---

## Project Constraints (from CLAUDE.md)

| Directive | Applies to this phase |
|-----------|----------------------|
| AI only for formatting/method statement structuring — never inventing scope, equipment, or design | D-03 and D-04 prompt instructions must reinforce this; "description" is framing context only |
| All document content traces to quote data, survey data, or reviewed inputs | D-01: scope_of_works must be human-reviewed before showing; notice when absent is correct |
| Must not break existing RAMS pipeline (extracted/reviewed/generated data flow, queue-based generation) | D-02 skip logic must preserve existing summarize() call path for any partially-populated room set |
| Laravel service-based, thin controllers, shared data services, safe migrations | D-03/D-04: pass new context keys through service-to-prompt path, not through controllers |
| PSR-12 style with Laravel Pint; ASCII art comment dividers for service sections | All edits must follow existing code style |
| Log info/error/warning with class name prefix and structured context array | Any new conditional path in RamsBuilderService must log skip decision |

---

## Standard Stack

No additional packages required. All changes use existing in-codebase tools:

| Tool | Purpose | Why standard |
|------|---------|--------------|
| Blade `@if` / `@php` | RAMS PDF conditional rendering (D-01) | Already used throughout rams.blade.php |
| `array_filter` + `array_column` | All-non-empty summary check (D-02) | PHP built-ins; matches existing patterns in RamsBuilderService |
| `BasePrompt::build()` pattern | Context injection into MethodStatementPrompt and WorksheetPrompt | Established pattern; `storedContext` merged with `$context` in build() |
| `WorksheetPrompt::forRoom()` static factory | Room + meta injection into worksheet prompt | Existing named constructor; extend parameters or supplement via `room` array key |

**No `npm install` or `composer require` needed.** [VERIFIED: reading all 6 files — no missing dependencies]

---

## Architecture Patterns

### D-01: RAMS Blade scope section (line 298)

**Current code (exact):**
```php
{{ $scopeOfWorks ?: ($project['works_description'] ?? $formData['works_description'] ?? 'AV installation works as per quotation.') }}
```

**Target pattern:**
```blade
@if($scopeOfWorks)
    {{ $scopeOfWorks }}
@else
    <span style="color:#CC0000; font-style:italic;">
        Scope of works not generated — return to the review form and click Generate.
    </span>
@endif
```

Context: `$scopeOfWorks` is assigned at line 198 from `$data['scope_of_works'] ?? ''`. It is injected into `$data` at line 189 of `RamsBuilderService::runFromReview()` as `trim((string) ($reviewedData['scope_of_works'] ?? ''))`. The variable is reliably a string; an empty-string check is sufficient.

### D-02: RamsBuilderService summarize() skip logic

**Current code block (lines 134–146):**
```php
if (! empty($reviewedData['room_overviews'])) {
    $reviewedData['room_overviews'] = $this->roomOverviewSummary->summarize(
        (array) $reviewedData['room_overviews']
    );
    $record->update([...]);
    $parsedQuote['room_overviews'] = $reviewedData['room_overviews'];
    $parsedQuote['rooms'] = array_values(array_map(...));
}
```

**Target pattern — insert before the summarize() call:**
```php
if (! empty($reviewedData['room_overviews'])) {
    $allSummariesPopulated = ! in_array(
        true,
        array_map(
            fn ($r) => trim((string) ($r['summary'] ?? '')) === '',
            $reviewedData['room_overviews']
        ),
        true,
    );

    if (! $allSummariesPopulated) {
        Log::info('RamsBuilderService::buildFromReview: regenerating room summaries (some empty)', [
            'record_id' => $record->id,
        ]);
        $reviewedData['room_overviews'] = $this->roomOverviewSummary->summarize(
            (array) $reviewedData['room_overviews']
        );
        $record->update([
            'reviewed_data' => array_merge($record->reviewed_data ?? [], [
                'room_overviews' => $reviewedData['room_overviews'],
            ]),
        ]);
    } else {
        Log::info('RamsBuilderService::buildFromReview: all room summaries populated, skipping summarize()', [
            'record_id' => $record->id,
        ]);
    }

    $parsedQuote['room_overviews'] = $reviewedData['room_overviews'];
    $parsedQuote['rooms'] = array_values(array_map(
        fn ($r) => (string) ($r['room'] ?? ''),
        $reviewedData['room_overviews'],
    ));
}
```

Key behaviours to preserve:
- After this block `$parsedQuote['room_overviews']` and `$parsedQuote['rooms']` are always set (both paths)
- `$record->update()` is only called when `summarize()` ran (avoid needless writes when skipping)
- `RoomOverviewSummaryService::summarize()` already returns both `summary` AND `description` per room (confirmed: lines 63–67 of `RoomOverviewSummaryService.php`)

### D-03: MethodStatementService context building

**Current `generate()` method context array (lines 45–53):**
```php
$context = [
    'site_address'            => $parsedQuote['site']      ?? 'the site',
    'scope_summary'           => $this->buildScope($parsedQuote, $classified),
    'activities'              => $classified['activities'] ?? [],
    'rooms'                   => $this->buildRoomList($parsedQuote),
    'equipment_summary'       => $equipmentSummary,
    'hazard_summary'          => $hazardSummary,
    'room_overview_summaries' => $roomSummary,
];
```

**Target: add three new keys:**
```php
$context = [
    // ... existing keys ...
    'works_overview'          => trim((string) ($parsedQuote['works_overview'] ?? '')),
    'scope_of_works'          => trim((string) ($parsedQuote['scope_of_works'] ?? '')),
    'room_descriptions'       => $this->buildRoomDescriptions($parsedQuote),
];
```

New private helper `buildRoomDescriptions()` — analogous to `buildRoomOverviewSummary()`:
```php
private function buildRoomDescriptions(array $parsed): string
{
    $rows = array_filter(
        (array) ($parsed['room_overviews'] ?? []),
        static fn ($r): bool => is_array($r)
            && trim((string) ($r['room'] ?? '')) !== ''
            && trim((string) ($r['description'] ?? '')) !== ''
    );

    $parts = [];
    foreach ($rows as $row) {
        $room        = trim((string) ($row['room']        ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        if ($room !== '' && $description !== '') {
            $parts[] = "{$room}: {$description}";
        }
    }

    return $parts ? implode("\n", $parts) : '';
}
```

`parsedQuote` comes from `reviewedToParsed($reviewedData)`. That method does NOT currently pass `scope_of_works`, `works_overview`, or `room_overviews[].description` into the returned array. Two options:

**Option A (preferred):** Extend `reviewedToParsed()` to include those three fields:
```php
// In reviewedToParsed():
'scope_of_works' => trim((string) ($rd['scope_of_works'] ?? '')),
'works_overview' => trim((string) ($rd['works_overview']  ?? '')),
// room_overviews already included at line 258
```
The `description` field is already on each room overview entry from Phase 5 (`RoomOverviewSummaryService` returns it). No data migration needed.

**Option B:** Pass the fields directly in `runFromReview()` before the `methodStatementGen->generate()` call. This is less clean but avoids touching `reviewedToParsed()`.

Option A is preferred — single source of truth for the parsedQuote shape.

**MethodStatementPrompt additions:**

New context keys accepted by `build()`:
- `works_overview` — string (project-level 2–3 sentence summary)
- `scope_of_works` — string (full scope paragraph, alternative/complement to `scope_summary`)
- `room_descriptions` — string (newline-delimited "Room: prose" entries)

The `scope_summary` key is currently populated from `buildScope()`. After Phase 5, `buildScope()` already prefers `scope_of_works` at priority 1. So `scope_summary` already carries `scope_of_works` when it is populated. The new `scope_of_works` key in the prompt context is redundant if `scope_summary` is already correct — the useful additions are `works_overview` and `room_descriptions`.

New lines to add to the prompt body (inside `build()`):
```php
$worksOverviewLine   = $worksOverview   ? "\nProject overview: {$worksOverview}"    : '';
$roomDescLine        = $roomDescriptions ? "\nRoom descriptions:\n{$roomDescriptions}" : '';
```

And in the prompt template, insert after `$roomSummaryLine`:
```
{$worksOverviewLine}{$roomDescLine}
```

Also update Phase 4 instruction to reference room descriptions explicitly: "Focus installation steps on the rooms and their AV solutions as described in the Room descriptions section above."

### D-04: WorksheetGeneratorService + WorksheetPrompt enrichment

**How `description` reaches WorksheetGeneratorService:**

`ProjectDataService::resolveRooms()` reads from `$source['rooms']` which is `reviewed_data['rooms']` (or groups/rooms from extracted_data). This is the quote rooms array — it does NOT include `room_overviews`. The `description` field lives in `reviewed_data['room_overviews']`, not in `reviewed_data['rooms']`.

Therefore `description` is NOT automatically on the `$room` passed to `WorksheetPrompt::forRoom()`. Two retrieval paths:

**Path A — from ProjectDataService (requires extending resolve()):**
Add `room_overviews` as a new key on the resolve() return. Then in `WorksheetGeneratorService::buildRooms()`, look up `description` by room name match.

**Path B — from the package's reviewed_data directly (simpler, no PDS change):**
In `WorksheetGeneratorService::generateContent()`, after resolving `$data = $this->projectDataService->resolve($project)`, also load the package's `reviewed_data` and extract `room_overviews` keyed by room name. Pass as a lookup map into `buildRooms()`. Since `ProjectDataService` is read-only and this is supplementary context (not canonical data), a direct package lookup is acceptable here.

**Path B is recommended** for this phase — it avoids modifying `ProjectDataService` and is consistent with the discretion note: "from the package's reviewed_data directly."

Implementation sketch for `generateContent()`:
```php
// After: $data = $this->projectDataService->resolve($project);
$package = $project->latestPackage;
$roomDescriptions = [];
if ($package) {
    $overviews = (array) ($package->reviewed_data['room_overviews'] ?? []);
    foreach ($overviews as $ov) {
        $name = trim((string) ($ov['room'] ?? ''));
        $desc = trim((string) ($ov['description'] ?? ''));
        if ($name !== '' && $desc !== '') {
            $roomDescriptions[$name] = $desc;
        }
    }
}
$worksOverview = trim((string) ($package?->reviewed_data['works_overview'] ?? ''));
$rooms = $this->buildRooms($data['rooms'], $data['project'], $roomDescriptions, $worksOverview);
```

Update `buildRooms()` signature:
```php
private function buildRooms(array $quoteRooms, array $projectMeta, array $roomDescriptions = [], string $worksOverview = ''): array
```

Inside the loop, look up description by exact or fuzzy match:
```php
$description = $roomDescriptions[$roomName] ?? '';
$roomForPrompt['description']   = $description;
$roomForPrompt['works_overview'] = $worksOverview;
```

`WorksheetPrompt::build()` additions — insert after the `$surveyBlock`:
```php
// ── Room description (from content pack) ─────────────────────────────
$roomDescription = trim((string) ($room['description'] ?? ''));
$worksOverview   = trim((string) ($room['works_overview'] ?? ''));

$descriptionBlock = $roomDescription
    ? "\nROOM DESCRIPTION (use for context only):\n  {$roomDescription}"
    : '';
$overviewBlock = $worksOverview
    ? "\nPROJECT OVERVIEW (use for context only):\n  {$worksOverview}"
    : '';
```

And in the prompt body, insert before INSTRUCTIONS:
```
{$descriptionBlock}{$overviewBlock}
```

The INSTRUCTIONS section's constraint "Base steps ONLY on the equipment and survey data provided above — do not invent items" must remain unchanged to preserve the no-invention rule.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead |
|---------|-------------|-------------|
| AI context enrichment | Custom serialisers or new DTO classes | Extend existing `$context` array passed to `BasePrompt::build()` |
| Room name lookup | Fuzzy search infrastructure | Simple `$descriptions[$roomName] ?? ''` exact match; fuzzy match already in `ProjectDataService::mergeSurveyRooms()` if needed later |
| Summary skip check | Complex state machine | Single `array_map` + `in_array` check — PHP built-ins |
| Blade notice styling | New CSS class | Inline style on the span (dompdf has limited CSS support; inline is safer) |

---

## Common Pitfalls

### Pitfall 1: dompdf CSS limitations for the notice

**What goes wrong:** Adding a CSS class for the "scope not generated" notice may not render in dompdf. Custom classes outside inline styles are unreliable for dynamic content.
**Why it happens:** dompdf supports a subset of CSS; the existing stylesheet is already tightly scoped.
**How to avoid:** Use inline `style="color:#CC0000; font-style:italic;"` on the `<span>` tag. The existing `note-text` class uses `font-style:italic; color:#666666` — the notice should visually differ (red) to make missing content obvious.
**Warning signs:** Preview the PDF; check that the notice text is visible and styled correctly.

### Pitfall 2: RamsBuilderService skip logic breaks when room_overviews has mixed types

**What goes wrong:** Some room entries in `reviewed_data['room_overviews']` may not be arrays (e.g., null or string entries from malformed saves). The `array_map` over non-array values throws a type error.
**Why it happens:** `reviewedToParsed()` already guards with `array_filter(..., fn($r) => is_array($r))`, but the raw `$reviewedData['room_overviews']` is used before that in the skip check.
**How to avoid:** Apply the same `is_array($r)` guard inside the skip check's `array_map`:
```php
fn ($r) => !is_array($r) || trim((string) ($r['summary'] ?? '')) === ''
```
This treats non-array entries as "empty summary" and triggers summarize().

### Pitfall 3: description not found by room name key

**What goes wrong:** `$roomDescriptions[$roomName]` returns empty string for rooms whose name in `reviewed_data['room_overviews']` differs slightly from the name in the quote rooms array (e.g., trailing spaces, case differences).
**Why it happens:** Quote room names come from `room_name` key; overview names come from `room` key. These may differ.
**How to avoid:** Normalize keys to lowercase trimmed string before building the lookup map:
```php
$roomDescriptions[strtolower(trim($name))] = $desc;
// Lookup:
$description = $roomDescriptions[strtolower(trim($roomName))] ?? '';
```

### Pitfall 4: MethodStatementService builds parsedQuote before D-02's description data is available

**What goes wrong:** `reviewedToParsed()` is called before the summarize() skip block runs. If the skip occurs, the room overviews on `$parsedQuote` are set from the pre-skip snapshot. The `description` fields are on `$reviewedData['room_overviews']` which is unchanged in the skip path.
**Why it happens:** The flow in `runFromReview()` is: `reviewedToParsed()` → conditional summarize() block → `methodStatementGen->generate($parsedQuote)`. After the skip block, `$parsedQuote['room_overviews']` is reassigned at line 146 (`$parsedQuote['room_overviews'] = $reviewedData['room_overviews']`). This reassignment already happens in both the skip and non-skip paths (it must be preserved outside the `if (! $allSummariesPopulated)` branch).
**How to avoid:** Ensure `$parsedQuote['room_overviews'] = $reviewedData['room_overviews']` and `$parsedQuote['rooms'] = ...` assignments remain OUTSIDE and AFTER the skip/summarize conditional block, not inside only one branch.

### Pitfall 5: scope_summary already carries scope_of_works — double injection

**What goes wrong:** `MethodStatementService::buildScope()` already checks `$parsed['scope_of_works']` at priority 1 and returns it as `scope_summary`. Adding a separate `scope_of_works` context key to the prompt may result in the same text appearing twice in the prompt body.
**Why it happens:** Both `scope_summary` (in the "Scope:" line) and a new `scope_of_works` line would carry the same content.
**How to avoid:** For D-03, do NOT add a separate `scope_of_works` prompt line — `scope_summary` already carries it. The genuinely new additions are `works_overview` (project overview) and `room_descriptions` (per-room description prose). This keeps the prompt compact and avoids token waste.

---

## Code Examples

### Exact location of D-01 change

```blade
{{-- FILE: resources/views/pdf/rams.blade.php, line 297-299 --}}
{{-- ═══ 1. SCOPE OF WORKS ═══════════════════════════════════════════════════ --}}
<div class="sec-heading">1. Scope of Works</div>
<p style="margin-bottom:8px;">
    {{-- CURRENT (to replace): --}}
    {{ $scopeOfWorks ?: ($project['works_description'] ?? $formData['works_description'] ?? 'AV installation works as per quotation.') }}

    {{-- REPLACEMENT: --}}
    @if($scopeOfWorks)
        {{ $scopeOfWorks }}
    @else
        <span style="color:#CC0000; font-style:italic;">Scope of works not generated — return to the review form and click Generate.</span>
    @endif
</p>
```

### Exact location of D-02 change

```php
// FILE: app/Services/RamsBuilderService.php, ~line 134
// Replace the entire existing if(!empty($reviewedData['room_overviews'])) block
// with the new skip-logic version shown in Architecture Patterns > D-02 above.
```

### Exact location of D-03 changes

```
FILE 1: app/Services/MethodStatementService.php
  - generate(): add 'works_overview', 'room_descriptions' to $context
  - add private buildRoomDescriptions() method
  - extend reviewedToParsed() call-chain: need scope_of_works + works_overview on $parsedQuote

FILE 2: app/Core/AI/Prompts/MethodStatementPrompt.php
  - build(): add resolveWorksOverview(), resolveRoomDescriptions() helpers
  - build(): add $worksOverviewLine, $roomDescLine optional lines to prompt body
  - Update Phase 4 instruction to reference room descriptions
```

### Exact location of D-04 changes

```
FILE 1: app/Services/WorksheetGeneratorService.php
  - generateContent(): load room description lookup and works_overview from package
  - buildRooms(): accept $roomDescriptions and $worksOverview parameters
  - Inside buildRooms() loop: set $roomForPrompt['description'] and $roomForPrompt['works_overview']

FILE 2: app/Core/AI/Prompts/WorksheetPrompt.php
  - build(): extract $roomDescription and $worksOverview from $room
  - build(): build $descriptionBlock and $overviewBlock optional sections
  - Insert both blocks before INSTRUCTIONS section in prompt template
```

---

## State of the Art

| What existed before Phase 5 | What Phase 5 delivered | What Phase 6 uses |
|----------------------------|------------------------|-------------------|
| `scope_of_works` on `reviewed_data`, not auto-generated | Auto-generated at ExtractQuoteJob; stored in `extracted_data` + reviewable | D-01 relies on this being populated to show in PDF |
| `room_overviews[].summary` (key-value block) only | Added `room_overviews[].description` (prose paragraph) | D-03 passes description into method statement prompt |
| `works_overview` did not exist | Added as project-level field in reviewed_data | D-03 and D-04 pass it as framing context |
| RAMS PDF fell back to generic text silently | No change yet — Phase 6 is the change | D-01 removes the silent fallback |
| summarize() always called at generation time | No change yet — Phase 6 is the change | D-02 adds the skip guard |

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter="RamsBuilderService\|MethodStatement\|WorksheetGenerator\|WorksheetPrompt\|MethodStatementPrompt"` |
| Full suite command | `php artisan test` |

### Phase Requirements — Test Map

| Decision | Behaviour | Test Type | Automated Command | File Exists? |
|----------|-----------|-----------|-------------------|-------------|
| D-01 | When scope_of_works empty, notice text renders instead of fallback | Unit (View render / string assertion) | `php artisan test --filter=RamsPdfScopeTest` | Wave 0 |
| D-01 | When scope_of_works non-empty, scope text renders with no fallback | Unit | same | Wave 0 |
| D-02 | When all summaries populated, summarize() is not called | Unit (mock RoomOverviewSummaryService) | `php artisan test --filter=RamsBuilderServiceTest` | Wave 0 |
| D-02 | When any summary is empty, summarize() is called for full set | Unit | same | Wave 0 |
| D-03 | MethodStatementService passes works_overview and room_descriptions to prompt | Unit (inspect context array) | `php artisan test --filter=MethodStatementServiceTest` | Wave 0 |
| D-03 | MethodStatementPrompt includes room descriptions in built prompt string | Unit | `php artisan test --filter=MethodStatementPromptTest` | Wave 0 |
| D-04 | WorksheetGeneratorService passes description and works_overview to WorksheetPrompt | Unit (mock AIManager) | `php artisan test --filter=WorksheetGeneratorServiceTest` | Wave 0 |
| D-04 | WorksheetPrompt includes description and overview blocks in built prompt | Unit | `php artisan test --filter=WorksheetPromptTest` | Wave 0 |

### Sampling Rate
- Per task commit: `php artisan test --filter=RamsBuilderService\|MethodStatement\|WorksheetGenerator`
- Per wave merge: `php artisan test`
- Phase gate: Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/RamsPdfScopeTest.php` — D-01 scope/notice rendering
- [ ] `tests/Unit/RamsBuilderServiceTest.php` — D-02 skip guard
- [ ] `tests/Unit/MethodStatementServiceTest.php` — D-03 context keys
- [ ] `tests/Unit/MethodStatementPromptTest.php` — D-03 prompt output
- [ ] `tests/Unit/WorksheetGeneratorServiceTest.php` — D-04 description propagation
- [ ] `tests/Unit/WorksheetPromptTest.php` — D-04 prompt block inclusion

---

## Environment Availability

Step 2.6: SKIPPED — Phase 6 is entirely code/config changes with no external dependencies beyond the existing Laravel/PHP/AI stack already verified by prior phases.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `reviewed_data['room_overviews']` entries contain a `description` field populated by Phase 5 | D-03 and D-04 retrieval | If Phase 5 did not correctly store description, the new context keys will be empty strings — prompt enrichment silently degrades to current behaviour (no crash) |
| A2 | `reviewed_data['works_overview']` is a top-level key on the package's reviewed_data | D-04 retrieval from package | If stored elsewhere, WorksheetGeneratorService will pass empty string — gracefully degrades |
| A3 | `$package->latestPackage` is accessible in WorksheetGeneratorService via `$project->latestPackage` | D-04 implementation | If relationship not loaded, null-safe operator `$package?->reviewed_data` returns null and both fields degrade to empty string |

**Low risk overall:** all three assumptions degrade gracefully to empty-string (no AI context enrichment) rather than throwing an exception.

---

## Open Questions

1. **Where exactly is `works_overview` stored in reviewed_data?**
   - What we know: Phase 5 CONTEXT.md says "stored in `extracted_data` alongside `scope_of_works`" (D-06). Phase 5 also says it is editable in the review form and validated by `RamsReviewValidatorService`.
   - What's unclear: Whether it is stored under `reviewed_data['works_overview']` or under `reviewed_data['project']['works_overview']` or some nested key. Phase 5 implementation determines this.
   - Recommendation: Read the Phase 5 migration/save path before writing the D-04 retrieval. If `scope_of_works` is at `reviewed_data['scope_of_works']` (top-level, confirmed by RamsBuilderService line 189), `works_overview` is likely also top-level.

2. **Does `reviewedToParsed()` need updating for D-03, or is a direct field pass sufficient?**
   - What we know: `parsedQuote` is built from `reviewed_data` and passed to `methodStatementGen->generate()`. The `scope_of_works` field is NOT currently on `parsedQuote` — `buildScope()` reads `$parsed['scope_of_works']` but `reviewedToParsed()` does not set it.
   - What's unclear: Was this always a no-op (since `buildScope()` also checks `$parsed['tasks']` as a fallback), or is `scope_of_works` currently missing from the parsedQuote shape?
   - Recommendation: Verify that `reviewed_data['scope_of_works']` is NOT already being injected into parsedQuote via another path. If not, add it to `reviewedToParsed()` return. [ASSUMED: currently missing — `reviewedToParsed()` return shape at lines 250–261 does not include it]

---

## Sources

### Primary (HIGH confidence)
- Direct file read: `resources/views/pdf/rams.blade.php` — exact line 298 confirmed [VERIFIED]
- Direct file read: `app/Services/RamsBuilderService.php` — lines 134–146 block confirmed [VERIFIED]
- Direct file read: `app/Services/MethodStatementService.php` — full file including `buildScope()` priority chain [VERIFIED]
- Direct file read: `app/Core/AI/Prompts/MethodStatementPrompt.php` — full prompt structure and context keys [VERIFIED]
- Direct file read: `app/Core/AI/Prompts/WorksheetPrompt.php` — full prompt structure [VERIFIED]
- Direct file read: `app/Services/WorksheetGeneratorService.php` — `forRoom()` call site and room data flow [VERIFIED]
- Direct file read: `app/Services/RoomOverviewSummaryService.php` — confirmed both `summary` and `description` returned [VERIFIED]
- Direct file read: `app/Core/Modules/Projects/ProjectDataService.php` — confirmed `description` not in resolved rooms [VERIFIED]
- Direct file read: `.planning/phases/06-rams-document-quality.../06-CONTEXT.md` — locked decisions [VERIFIED]
- Direct file read: `.planning/phases/05-project-content-pack.../05-CONTEXT.md` — Phase 5 data contracts [VERIFIED]

### Secondary (MEDIUM confidence)
- None required — all research is from direct codebase reads.

### Tertiary (LOW confidence)
- None.

---

## Metadata

**Confidence breakdown:**
- D-01 RAMS Blade change: HIGH — exact line confirmed, pattern is straightforward Blade conditional
- D-02 RamsBuilderService skip: HIGH — exact block lines confirmed, PHP logic is simple
- D-03 MethodStatementService/Prompt: HIGH — existing context key shapes confirmed; description field confirmed present in RoomOverviewSummaryService
- D-04 WorksheetGeneratorService/Prompt: MEDIUM — description retrieval path requires Path B (direct package access) which is a new pattern in that service; `works_overview` storage location has one open question

**Research date:** 2026-04-12
**Valid until:** 2026-05-12 (stable codebase, no upstream packages changing)
