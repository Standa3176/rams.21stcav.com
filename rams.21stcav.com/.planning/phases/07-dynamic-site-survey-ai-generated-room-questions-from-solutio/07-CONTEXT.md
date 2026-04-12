---
phase: 07-dynamic-site-survey
gathered: 2026-04-12
status: Ready for planning
---

# Phase 7: Dynamic Site Survey — AI-generated room questions

**Gathered:** 2026-04-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Generate a tailored set of pre-install check questions per survey room at survey creation time. Questions are driven by each room's solution type (e.g. Conferencing, BYOD, Split Room), the room's associated equipment list, and the project scope/works summaries. Engineers answer these during the site visit to confirm conditions are met before installation begins.

This phase adds:
- New `site_survey_room_questions` table to store generated questions and answers per room
- A background job (`GenerateSurveyQuestionsJob`) dispatched per room when a survey is created from a project
- A new AI prompt class (`SurveyQuestionsPrompt`) to generate room-specific questions
- UI additions to the room card (collapsible panel) in both the internal and public survey forms
- Room completion enforcement — all generated questions must be answered before a room can be marked complete

Out of scope: surveys created without a project, question regeneration after creation, question type variety beyond yes/no/other.

</domain>

<decisions>
## Implementation Decisions

### Equipment Scoping — Per Room
- **D-01:** Each room already has its associated equipment in `room_overviews` within `ProjectPackage.reviewed_data`. The AI prompt for each room receives only that room's equipment — no cross-room noise, no matching logic required.
- **D-02:** The `works_overview` and per-room `description`/`summary` fields (added in Phase 5) are also passed as context to the prompt. The combination of solution type + room equipment + scope summaries drives question generation.

### Question Format — Yes / No / Other
- **D-03:** Each generated question has three answer options: **Yes**, **No**, **Other**. Selecting "Other" reveals a text explanation field beneath the question. This is the only question type — no pure checkbox, no free-text-only questions.
- **D-04:** The `site_survey_room_questions` table stores: `room_id`, `question` (text), `sort_order`, `answer` (enum: yes/no/other/null), `other_text` (text, nullable). A null answer means unanswered.

### Completion Gate — All Questions Mandatory
- **D-05:** A room cannot be marked complete until all its generated questions have a non-null answer (yes, no, or other with text). The existing "Mark Complete" action must enforce this — if any question is unanswered, it is blocked with a visible message.
- **D-06:** Rooms with no generated questions (job not yet complete, or no project context) are unaffected — the existing completion logic applies unchanged.

### UI Placement — Collapsible Panel (Kit Drawer Pattern)
- **D-07:** Generated questions appear in a collapsible panel at the top of each room card, following the same drawer mechanics as the existing kit list drawer (`_room-form.blade.php`). The panel is labelled "Pre-Install Checks".
- **D-08:** The panel is only rendered if the room has at least one question record. While the generation job is running (no questions yet), the panel is simply absent — no placeholder, no spinner, no "generating..." message.
- **D-09:** Both the internal survey form and the public (token-gated) survey form render the panel identically. The engineer-facing public form is the primary use case.

### Generation Timing — Async Job Per Room
- **D-10:** When `SurveyService::createFromProject()` creates a survey, it dispatches one `GenerateSurveyQuestionsJob` per room immediately after room creation. The survey is immediately usable — questions arrive in the background.
- **D-11:** If the job fails, the room simply has no questions. No error surfaces to the engineer; no retry beyond Laravel's standard job retry config. Silent failure is acceptable — questions are additive, not blocking for the survey itself.

### Survey Scope — Project-Linked Only
- **D-12:** This feature only applies to surveys created from a project (`createFromProject()`). Surveys created manually without a project link are not supported and will not receive generated questions. The requirement to always create surveys from a project is enforced by the existing flow.

### AI Prompt Contract
- **D-13:** The `SurveyQuestionsPrompt` receives per-room context: solution type slug + survey checklist text, room equipment list (from `room_overviews`), `works_overview`, and room `description`/`summary`. It returns a JSON array of question strings. The planner decides exact JSON shape and prompt wording.
- **D-14:** AI usage is consistent with project constraints — structured JSON output only, no invented scope. Questions must be pre-install verification checks (e.g., "Is there a power outlet within 1m of the display position?"), not open-ended design questions.

### Claude's Discretion
- Exact JSON shape returned by `SurveyQuestionsPrompt` (array of strings vs array of objects with metadata)
- Whether to batch all rooms into one AI call or one call per room
- Exact wording of the "Pre-Install Checks" panel header and empty/blocked states
- Alpine.js implementation of the collapsible panel (can reuse kit drawer x-data pattern)
- Whether `other_text` is required (non-empty) when answer is "other", or can be blank

</decisions>

<specifics>
## Specific Ideas

- "Follow the collapsible kit drawer logic" — the panel should feel native to the existing room card, not a foreign widget
- "Form just works normally with no questions section until the job completes" — no loading states, no placeholders. The section appears when it's ready, silently
- "Surveys should be done from a project being created" — no standalone manual surveys; project linkage is the prerequisite for this feature
- "For yes/no questions add other for text explanation. Questions need to be answered" — three-state answer (yes/no/other+text), all required before room completion

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Survey Models and Service
- `app/Models/SiteSurvey.php` — survey header, relationships, status, access_token
- `app/Models/SiteSurveyRoom.php` — per-room data, completion status, the model that will gain a `questions()` HasMany relation
- `app/Core/Modules/Survey/SurveyService.php` — `createFromProject()` is the dispatch point for `GenerateSurveyQuestionsJob`

### Survey Forms (UI targets)
- `resources/views/site-survey/_room-form.blade.php` — room card with kit drawer; new questions panel follows this pattern
- `resources/views/survey/` — public (token-gated) survey form; same panel must appear here

### Project Data (question generation inputs)
- `app/Models/ProjectPackage.php` — `reviewed_data` contains `room_overviews` (per-room: equipment, solution_type_id, description, summary) and `works_overview`
- `app/Models/SolutionType.php` — `survey_checklist` (newline-separated checks) and `slug` fed to the AI prompt

### AI Pattern (follow existing implementation)
- `app/Core/AI/Prompts/BasePrompt.php` — base class all prompts extend
- `app/Core/AI/Prompts/WorksheetPrompt.php` — closest existing example: per-room context, structured JSON output
- `app/Core/Modules/KnowledgeLibrary/` — example of how domain data feeds AI prompts

### Job Pattern (follow existing implementation)
- `app/Jobs/BuildRamsDocumentJob.php` — queue job pattern: status updates, retry config, failed() hook
- `app/Jobs/BuildOmManualJob.php` — similar async job structure

### Phase 5 Context (content pack fields used as AI input)
- `.planning/phases/05-project-content-pack-single-ai-call-generates-scope-of-works/05-CONTEXT.md` — defines `scope_of_works`, `works_overview`, per-room `description` fields that feed this phase

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- Kit drawer in `_room-form.blade.php` — Alpine.js collapsible with x-show/x-transition; questions panel reuses this exact pattern
- `AIManager::run(BasePrompt, context)` — existing AI abstraction; `SurveyQuestionsPrompt` plugs in here
- `SurveyService::createFromProject()` lines 105–181 — dispatch point; rooms are created in a loop, job dispatched after each room

### Established Patterns
- Async job per document: `BuildRamsDocumentJob`, `BuildOmManualJob` — same pattern for `GenerateSurveyQuestionsJob`
- Structured JSON AI output: all prompts return JSON, parsed by AIManager — `SurveyQuestionsPrompt` follows this
- Alpine.js for interactivity: all form interactions use Alpine; the "Other → reveal text field" behavior is standard Alpine x-show

### Integration Points
- `SurveyService::createFromProject()` — add job dispatch after room loop
- `SiteSurveyRoom` — add `questions()` HasMany to new `SiteSurveyRoomQuestion` model
- `_room-form.blade.php` — add questions panel after kit drawer, before dimension fields
- Public survey save endpoint — must persist question answers alongside room fields

</code_context>

<deferred>
## Deferred Ideas

- Question regeneration after scope changes — out of scope; questions are fixed at creation
- Standalone surveys without a project — not supported; all surveys must come from a project
- Question types beyond yes/no/other (measurements, photo attachments) — future phase
- Reporting/analytics on check pass rates across projects — future phase

</deferred>

---

*Phase: 07-dynamic-site-survey*
*Context gathered: 2026-04-12*
