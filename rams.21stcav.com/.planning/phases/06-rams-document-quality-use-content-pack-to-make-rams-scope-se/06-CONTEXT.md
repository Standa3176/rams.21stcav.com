---
phase: 06-rams-document-quality
gathered: 2026-04-12
status: Ready for planning
---

# Phase 6: RAMS & Document Quality — Context

**Gathered:** 2026-04-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Use the pre-generated content pack fields (`scope_of_works`, `works_overview`, per-room `description`) built in Phase 5 to eliminate generic boilerplate from RAMS PDFs, method statements, and Worksheets — making all final documents project-specific without additional AI calls.

**What this phase changes:**
- RAMS PDF scope section: shows `scope_of_works` exclusively when populated; shows a notice when empty (no silent fallback to raw quote text)
- `RamsBuilderService`: skips fresh AI `summarize()` call when saved summaries already exist in `reviewed_data`
- `MethodStatementPrompt`: enriched with `scope_of_works`, `works_overview`, and per-room `description` so method statement steps reference actual rooms and AV solutions
- `WorksheetPrompt`: enriched with room `description` and project-level `works_overview` for more specific install step generation

**What this phase does NOT include:**
- Phase 7: Survey intelligence (pre-populating survey rooms, solution-aware prompts)
- Phase 8: Cable schedule quality (description-based inference, survey lengths)
- Changes to the O&M document — Phase 5 already enriched `OmManualGeneratorService` and `OmManualPrompt` with scope + room descriptions; Phase 6 does not revisit O&M

</domain>

<decisions>
## Implementation Decisions

### D-01 — RAMS scope section: scope_of_works exclusive, notice when empty

- When `$scopeOfWorks` is non-empty, display it as the sole scope paragraph in the RAMS PDF. Remove the silent fallback to `$project['works_description']` or the generic "AV installation works as per quotation." string.
- When `$scopeOfWorks` is empty, display a visible notice: "Scope of works not generated — return to the review form and click Generate." This makes missing content obvious rather than hiding it with boilerplate.
- File to change: `resources/views/pdf/rams.blade.php` (line ~298)

### D-02 — RamsBuilderService: skip fresh AI summarize() when saved summaries exist

- In `RamsBuilderService::build()`, before calling `$this->roomOverviewSummary->summarize()`, check if all rooms in `reviewed_data['room_overviews']` already have a non-empty `summary` field.
- If yes (all summaries populated from review): skip the `summarize()` call entirely. Use the reviewed_data room_overviews as-is.
- If no (any room has empty summary): call `summarize()` only for rooms with empty summaries, or fall back to calling `summarize()` for the full set as before.
- This preserves the user-reviewed text and avoids wasted AI tokens at generation time.
- File to change: `app/Services/RamsBuilderService.php` (line ~133–146)

### D-03 — Method statement prompt: enrich with scope + per-room descriptions

- `MethodStatementPrompt::build()` receives additional context: `scope_of_works`, `works_overview`, and the array of `room_overviews` (each with `room` name and `description` prose paragraph).
- The prompt instructs the AI to write steps that reference the actual rooms and their AV solutions by name (e.g. "Mount the Neat Bar Pro below the display in the Board Room" rather than "Install AV equipment").
- The 6 fixed phase titles are retained — they are required for UK AV RAMS compliance.
- `MethodStatementService::buildRoomOverviewSummary()` already passes room overviews; extend it (or a new context key) to also pass `description` per room.
- File to change: `app/Core/AI/Prompts/MethodStatementPrompt.php` and `app/Services/MethodStatementService.php`

### D-04 — Worksheet prompt: pass room description + works_overview

- `WorksheetPrompt::build()` receives two additional fields:
  - `description` (string) — the Phase 5 prose paragraph for this specific room
  - `works_overview` (string) — the project-level 2–3 sentence executive summary
- These are included in the prompt body as additional context sections, clearly labelled so the AI can reference them without being required to use them if the equipment/survey data already covers the point.
- The constraint "base steps ONLY on equipment and survey data provided — do not invent items" remains in force; the description is framing context, not a license to invent.
- `WorksheetGeneratorService` (or wherever `WorksheetPrompt::forRoom()` is called) must retrieve `description` from the room entry and `works_overview` from `reviewed_data` / `ProjectDataService`.
- Files to change: `app/Core/AI/Prompts/WorksheetPrompt.php`, `app/Services/WorksheetGeneratorService.php`

### Claude's Discretion

- Exact wording of the "scope not generated" notice in the RAMS PDF
- Whether the empty-summary check in D-02 uses "all non-empty" or "any non-empty" as the threshold for skipping the `summarize()` call — "all non-empty" is preferred (skip only if all rooms have summaries)
- Whether to add `works_overview` as a cover-page subtitle in the RAMS PDF (D-01 only asked about scope body text — cover subtitle is implementer's call)
- How `description` is retrieved for each room in WorksheetGeneratorService (from ProjectDataService resolved rooms, or from the package's reviewed_data directly)

</decisions>

<specifics>
## Specific Notes

- `RamsBuilderService::build()` calls `$this->roomOverviewSummary->summarize($reviewedData['room_overviews'])` at line ~135 and then overwrites `$reviewedData['room_overviews']` with the result (line ~135–146). The skip logic in D-02 must be inserted before this call.
- `resources/views/pdf/rams.blade.php` line ~298 currently reads: `{{ $scopeOfWorks ?: ($project['works_description'] ?? $formData['works_description'] ?? 'AV installation works as per quotation.') }}` — this is the line to change for D-01.
- `MethodStatementPrompt::build()` already receives `scope_summary` (the plain-English scope passed from `MethodStatementService::buildScope()`). Phase 6 adds room summaries and descriptions directly into the prompt body.
- `WorksheetGeneratorService` calls `WorksheetPrompt::forRoom($room, $projectMeta)` — `$room` comes from `ProjectDataService::resolve()['rooms']`. The Phase 5 `description` field should already be on each room entry if `RamsReviewDataService` normalised it correctly.

</specifics>

<canonical_refs>
## Canonical References

Downstream agents MUST read these before planning or implementing.

### RAMS PDF rendering
- `resources/views/pdf/rams.blade.php` — scope section to update (D-01)

### Service layer
- `app/Services/RamsBuilderService.php` — summarize() skip logic (D-02)
- `app/Services/MethodStatementService.php` — context building for method statement prompt (D-03)
- `app/Services/WorksheetGeneratorService.php` — forRoom() call site (D-04)

### AI prompts
- `app/Core/AI/Prompts/MethodStatementPrompt.php` — prompt to enrich with room descriptions (D-03)
- `app/Core/AI/Prompts/WorksheetPrompt.php` — prompt to enrich with description + works_overview (D-04)

### Prior phase context
- `.planning/phases/05-project-content-pack-single-ai-call-generates-scope-of-works/05-CONTEXT.md` — decisions that established the content pack fields and their sources

### No external specs
- Requirements are fully captured in the decisions above.

</canonical_refs>

<deferred>
## Deferred Ideas

- **Phase 7: Survey intelligence** — Pre-populating survey rooms from quote data, solution-type-aware survey prompts, cable run length capture.
- **Phase 8: Cable schedule quality** — Description-based cable type inference, survey-length-aware schedule generation.
- RAMS cover page `works_overview` subtitle — nice-to-have; implementer may add if clean, not required by user decision.
- O&M document changes — Phase 5 already enriched O&M; any further O&M quality improvements are deferred to Phase 7 or beyond.

</deferred>

---

*Phase: 06-rams-document-quality*
*Context gathered: 2026-04-12*
