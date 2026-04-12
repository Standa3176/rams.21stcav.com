---
phase: 05-project-content-pack
gathered: 2026-04-11
status: Ready for planning
---

# Phase 5: Project Content Pack — Context

**Gathered:** 2026-04-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Generate a "project content pack" — a set of pre-computed, AI-generated content fields stored in `extracted_data` / `ProjectPackage` — so RAMS, O&M Manuals, and Worksheets all read from a shared, reviewed content source instead of making separate AI calls with thin context at document-generation time.

**Fields added by this phase:**
- `room_overviews[].description` — prose paragraph per room (new, for O&M narrative sections)
- `works_overview` — 2–3 sentence executive summary (new, for worksheet covers / O&M intro)
- `scope_of_works` — full scope paragraph (ALREADY EXISTS; phase wires it into method statement and enriches it auto-generation path)
- `room_overviews[].summary` — key-value block (ALREADY EXISTS; combined call now also returns `description`)

**What this phase does NOT include:**
- Phase 6 (RAMS/O&M document quality improvements using the content pack) — separate phase
- Phase 7 (survey intelligence) — separate phase
- Phase 8 (cable schedule quality) — separate phase

</domain>

<decisions>
## Implementation Decisions

### Per-room description field

- **D-01** — Add `room_overviews[].description` as a new field alongside the existing `summary` (key-value block). Both come from the same room `overview` text.
- **D-02** — `summary` (key-value: "Room Type: ...\nDisplay: ...\nVC System: ...") is kept for RAMS structured sections. `description` is a clean prose paragraph (2–4 sentences) for O&M room narrative sections.
- **D-03** — Both fields are editable in the review form per room, each with its own regenerate button (AJAX, same endpoint pattern as existing summary button).

### Works overview field

- **D-04** — Add `works_overview` as a new project-level field: 2–3 sentence executive summary of the overall project. Separate from the existing `scope_of_works` paragraph.
- **D-05** — `scope_of_works` (full paragraph, already exists) is used in RAMS body sections, O&M introduction body, and method statement context. `works_overview` (short summary) is used on worksheet cover pages and O&M cover/intro header.
- **D-06** — `works_overview` is stored in `extracted_data` alongside `scope_of_works`. Both editable in the Project Info section of the review form.

### Generation trigger

- **D-07** — Content pack auto-generates at the end of `ExtractQuoteJob` (after room overviews are parsed). This gives RAMS/O&M useful content from day one without the user needing to click Generate.
- **D-08** — Manual regenerate available in the review form: per-room regenerate button (description + summary together), and project-level regenerate button (scope_of_works + works_overview). These call the existing AJAX endpoints, extended to handle the new fields.
- **D-09** — Auto-generation is best-effort: if AI fails during extraction, fields are left empty and user can regenerate manually from the review form. Extraction job does not fail due to content pack AI errors.

### AI call strategy

- **D-10** — Extend `RoomOverviewSummaryPrompt` to return both `summary` (key-value, existing) AND `description` (prose paragraph, new) per room in one combined JSON response. Single AI call, single cache key — no extra cost.
- **D-11** — Add `works_overview` and `scope_of_works` generation to the same combined call OR generate via the existing `ScopeOfWorksPrompt` in a second call immediately after. **Preference: second call** — scope generation already exists as a separate prompt and route, keeping concerns separate. Both calls happen sequentially during extraction.
- **D-12** — `RoomOverviewSummaryService::summarize()` updated to return `description` per room alongside existing `summary`.

### Method statement wiring

- **D-13** — `MethodStatementService::buildScope()` is updated to prefer `scope_of_works` from `reviewed_data` / `parsedQuote` when it is non-empty, before falling back to the current equipment-list-based scope. Zero extra AI cost — uses the already-saved, human-reviewed text.
- **D-14** — The method statement prompt already receives room summaries via `buildRoomOverviewSummary()`. No changes needed to that wiring.

### O&M enrichment

- **D-15** — O&M Pass 2 (`OmManualGeneratorService::generateContent()`) receives `scope_of_works` and per-room `description` fields from `ProjectDataService` (or directly from `extracted_data`) as additional context alongside the equipment list. This gives the AI richer grounding for operating procedures without changing the output schema.
- **D-16** — `OmManualPrompt::forContent()` updated to include scope + room descriptions in the prompt body where available.

### Review form UX

- **D-17** — Room descriptions editable inline per room in the Room / Space Overviews section (alongside existing overview and summary fields). One regenerate button per room triggers a combined summary + description regeneration.
- **D-18** — `works_overview` and `scope_of_works` editable in the Project Info section. Both have their own "Generate" / "Regenerate" buttons. `scope_of_works` already has this button — extend it. `works_overview` gets a new adjacent button.
- **D-19** — New fields display with a textarea input, same styling as existing `scope_of_works` field.

### Validator updates

- **D-20** — `RamsReviewValidatorService` extended to accept `works_overview` (nullable, string, max:2000) and `room_overviews.*.description` (nullable, string, max:10000).

### Claude's Discretion

- Exact prose style / length of room `description` (target ~3 sentences covering room type, main AV solution, any notable infrastructure detail)
- Whether `works_overview` and `scope_of_works` are generated in the same call or separate — separate preferred per D-11 but implementer may combine if cleaner
- Cache key strategy for the new combined room prompt
- Whether to extract `works_overview` generation into a new prompt class or add to `ScopeOfWorksPrompt` as a second output field

</decisions>

<specifics>
## Specific Notes

- The `scope_of_works` field and its AJAX generate endpoint (`POST /project-packages/{package}/generate-scope`) already exist and are wired in `ProjectPackageReviewController`. Phase 5 extends the auto-generation path and adds `works_overview` alongside it — it does not replace the existing button.
- The existing `RoomOverviewSummaryService::summarize()` already skips rooms with empty overview text — this behaviour must be preserved for the new `description` field.
- `RamsBuilderService` already reads `scope_of_works` from `reviewedData` and injects it into the RAMS data bag (line ~189). No change needed there — the value just needs to be populated earlier (via auto-generation at extract time).
- `MethodStatementService::buildScope()` falls back through: tasks → classifier summary → equipment summary → "AV installation works as per quotation". After Phase 5, the chain becomes: `scope_of_works` (from reviewed_data) → tasks → classifier summary → equipment summary → fallback.

</specifics>

<canonical_refs>
## Canonical References

Downstream agents MUST read these before planning or implementing.

### Content pack source — room overviews
- `app/Services/RoomOverviewSummaryService.php` — existing summarize() to extend with description field
- `app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php` — prompt to extend with prose description output
- `app/Http/Controllers/ProjectPackageReviewController.php` — AJAX endpoints: generateScopeOfWorks(), generateRoomSummary() — extend these, do not duplicate

### Content pack source — scope and overview
- `app/Core/AI/Prompts/ScopeOfWorksPrompt.php` — existing scope prompt (extend to also return works_overview, or create separate prompt)
- `app/Jobs/ExtractQuoteJob.php` — extraction job (add content pack generation here, after room overviews parsed)

### Consumers — RAMS
- `app/Services/RamsBuilderService.php` — already reads scope_of_works; no change needed here
- `app/Services/MethodStatementService.php` — buildScope() to be updated (D-13)
- `app/Services/RamsReviewValidatorService.php` — add works_overview and description validation

### Consumers — O&M Manual
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — generateContent() to receive enriched context (D-15)
- `app/Core/AI/Prompts/OmManualPrompt.php` — forContent() to include scope + descriptions (D-16)

### Consumers — Worksheets
- `app/Services/WorksheetGeneratorService.php` — room AI steps already use ProjectDataService; may receive description as additional context
- `app/Core/AI/Prompts/WorksheetPrompt.php` — forRoom() prompt builder

### Review form
- `resources/views/project-packages/review.blade.php` — add works_overview field to project info section; add description textarea per room in room overviews section

### Prior phase context
- `.planning/phases/04-document-generators/04-CONTEXT.md` — O&M and worksheet pipeline decisions (D-07 through D-16)

### No external specs
- Requirements are fully captured in the decisions above. No external ADRs or design docs for this phase.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RoomOverviewSummaryService::summarize()` — already called during RAMS build and per-room AJAX. Phase 5 extends the return shape, not the call pattern.
- `ScopeOfWorksPrompt` + `generateScopeOfWorks()` AJAX endpoint — already exists and wired. Extend to also return `works_overview` in the same response.
- `AICacheService` — already used by `MethodStatementGeneratorService`. Use same pattern for combined room prompt to avoid re-generating on identical input.

### Established Patterns
- AI calls in this codebase return typed arrays, never raw strings. Prompt classes extend `BasePrompt`, return JSON, parsed by `AIManager::run()`.
- AJAX endpoints in `ProjectPackageReviewController` return `JsonResponse` with a single data key. Follow this for new/extended endpoints.
- Review form saves via POST to `project-packages/{package}/review` — all new fields must be included in the review save payload and validated in `RamsReviewValidatorService`.

### Integration Points
- `ExtractQuoteJob::handle()` — add content pack generation after `mergeParsedQuoteData()` completes (line ~96-163). Content pack is best-effort; wrap in try/catch so extraction succeeds even if AI fails.
- `MethodStatementService::buildScope()` — single method to update. Add `scope_of_works` from `$parsedQuote` as first-preference source.
- `OmManualGeneratorService::generateContent()` — pass `scope_of_works` and room `description` fields into the AI context array.

</code_context>

<deferred>
## Deferred Ideas

- **Phase 6: RAMS & document quality** — Using the content pack to make RAMS scope sections, method statements, and O&M operating procedures project-specific. Phase 5 populates the data; Phase 6 consumes it fully across all document templates.
- **Phase 7: Survey intelligence** — Pre-populating survey rooms from quote, solution-type-aware survey prompts, cable run length capture.
- **Phase 8: Cable schedule quality** — Description-based cable type inference, survey-length-aware schedule generation.
- Per-room works_overview (room-level short summary) — discussed but deferred; project-level works_overview covers the immediate need.

</deferred>

---

*Phase: 05-project-content-pack*
*Context gathered: 2026-04-11*
