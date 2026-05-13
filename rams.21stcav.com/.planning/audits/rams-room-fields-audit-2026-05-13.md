# RAMS Room/Space/Scope Field Audit

**Date:** 2026-05-13
**Auditor:** Claude (read-only investigation)
**Repo:** `rams.21stcav.com` (branch `feat/worksheet-classifier-universal`)
**Scope:** Identify every field across the RAMS Platform that describes the project scope, the room/space, or the AV works narrative; map capture → storage → consumption; flag overlaps, dead paths and recommend a minimal data model.

---

## 1. Executive Summary

- **Five overlapping "scope/works/space narrative" fields exist** at three different granularities (project-wide, AI executive summary, per-room) and are all stored in different JSON keys with partly-different semantics — but the controllers, services and Blade templates fall back between them inconsistently, so any one field can end up rendering "from" any of the others.
- **`form_data.works_description` (single textarea on `create`) is the only project-wide scope field captured for the manual-form pipeline.** Once a quote PDF is uploaded, four AI-or-deterministic-derived scope fields (`scope_of_works`, `works_overview`, `works_bullets`, `scope_of_works_bullets`) are layered on top via `ExtractQuoteJob`, the package-review screen, and `RamsComplianceUpgradeService` — with no single canonical source of truth.
- **Per-room narrative is even noisier.** A single `room_overviews[]` row carries FOUR text fields (`overview`, `works_summary`, `summary`, `description`) — each populated by a different mechanism (PM-typed prose, AI bullet list, legacy AI summary, AI prose paragraph). The PDF template uses `works_summary` first and only falls back to `overview/description/scope`, leaving the other two dead in many code paths.
- **`reviewed_data.project.overview` is a captured-but-never-read field** (normalised in `RamsReviewDataService::normaliseProject()` line 113, but no consumer reads `project.overview`). The `extracted_data.overview` (raw QuoteWerks "Overview" PDF block) IS used but lands in two places (`project.overview` on review, and `works_description` on the Project model) — and they can diverge.
- **Cleanup effort: MEDIUM** — most consolidation is renames and routing tweaks (no schema changes; everything is in JSON columns) but it touches every doc generator (RAMS / Worksheet / Mini-OM / Survey / O&M) because they all share `room_overviews[]` and `scope_of_works*`. Affected: ~20 PHP files and ~5 Blade templates. Backward-compat shims required for already-generated `generated_data` JSON.

---

## 2. The 3-Stage RAMS Pipeline

The RAMS lifecycle stores data across **three JSON columns** on `rams_documents` (migration `2026_03_21_000001_add_review_columns_to_rams_documents_table.php`, migration `2026_03_04_000001_create_rams_documents_table.php`). All three are `array`-cast on the model (`app/Models/RamsDocument.php:58-69`).

| Column | Populated by | Source of truth for | Cited |
|---|---|---|---|
| `form_data` | `RamsController::store()` (manual form), `QuoteUploadController` (sets metadata only) | Manual-form RAMS generation; original user submission; personnel/programme fallback | `app/Http/Controllers/RamsController.php:181-195`; `app/Http/Requests/RamsFormRequest.php:14-86` |
| `extracted_data` | `ExtractRamsDraftJob` → `RamsExtractionDraftBuilderService::build()` (no AI). Also populated by `ProjectPackage::extracted_data` flowing through `ProjectPackageRamsReviewService::build()` | Machine-produced draft for review; **never directly read by the DOCX generators** (only by review UI and by the diff service) | `app/Jobs/ExtractRamsDraftJob.php:106-134`; `app/Services/RamsExtractionDraftBuilderService.php:45-67`; `app/Services/RamsReviewDataService.php:30-35` |
| `reviewed_data` | `RamsController::updateAndDownload()` (review form save), `RamsController::generateFromProject()` (seed from package), `RamsController::retryGeneration()` (refresh from package) | **Canonical input to DOCX generation.** Source of truth post-review | `app/Http/Controllers/RamsController.php:442-543`; `app/Http/Controllers/RamsController.php:255-291`; `app/Http/Controllers/RamsController.php:960-972` |
| `generated_data` | `RamsDataBuilderService::assemble()` (after method-statement AI call) → `RamsBuilderService::buildFromReview()` writes it | What the PDF/DOCX render. Stable shape contract for already-delivered documents | `app/Services/RamsDataBuilderService.php:54-112`; `app/Services/RamsBuilderService.php:257-263` |

### Dual entry points

- **Manual form (no quote PDF):** `RamsFormRequest` → `RamsController::store()` → `BuildRamsDocumentJob` → `RamsBuilderService::buildFromForm()` (uses `pipeline()` with confidence=1.0). `form_data` is the only input.  (`app/Http/Controllers/RamsController.php:178-227`, `app/Services/RamsBuilderService.php:108-123`).
- **Quote-import → review → generate (the primary pipeline):**
  1. `QuoteUploadController` saves PDF → `ExtractRamsDraftJob` populates `extracted_data`.
  2. User reviews via the **package-review** screen (`/project-packages/{id}/review`) — this writes back to `ProjectPackage.extracted_data` (NOT `rams_documents.extracted_data`).
  3. `RamsController::generateFromProject()` (`app/Http/Controllers/RamsController.php:233-297`) reads the **reviewed package**, runs it through `RamsReviewDataService::normalise()`, persists to `rams_documents.reviewed_data`, then dispatches `BuildRamsDocumentJob`.
  4. `BuildRamsDocumentJob` calls `RamsBuilderService::buildFromReview($reviewedData, $formData, $record)` — the AI is only called for the method statement; everything else is deterministic.
  5. `RamsDataBuilderService::assemble()` writes `generated_data`; `RamsDocumentRendererService::render()` writes the DOCX; downstream PDF rendering reads `generated_data` + (sometimes) `reviewed_data`.

### What enters at each stage

| Field bucket | form_data | extracted_data | reviewed_data | generated_data |
|---|---|---|---|---|
| `project.*` (ref/name/client/site) | yes (top-level keys) | yes (nested under `project`) | yes (nested under `project`) | yes (re-resolved by `resolveProjectFields`) |
| `works_description` (project-wide scope text) | **yes — required, min:20** (`RamsFormRequest:25`) | only via legacy fallback (mapped from `parsed['overview']`) | **NOT in canonical review schema** — but RamsBuilderService line 220 reads it as a fallback | yes — `project.works_description` (RamsDataBuilderService:206) |
| `scope_of_works` (AI paragraph) | no | yes (set by `ExtractQuoteJob::generateContentPack`) | yes (`normalise()`:86) — editable on package-review form | yes (`buildFromReview()` line 218-225) — `data['scope_of_works']` top level |
| `works_overview` (AI 2-3 sentence executive summary) | no | yes (set by `ExtractQuoteJob::generateContentPack`) | yes (`normalise()`:86) — editable | only kept on `reviewed_data` — PDF reads from `reviewed_data['works_overview']` directly (rams.blade.php:389) |
| `works_bullets` (single-textarea install-action list — project-wide) | no | no | yes (`works_bullets` key — `parseReviewPayload`:988) | no consumer in RAMS — survey/worksheet only |
| `scope_of_works_bullets` (deterministic equipment-derived bullets) | no | no | no | yes — **AI-or-equipment-derived in `RamsComplianceUpgradeService::upgradeScopeOfWorks()`** (line 236) |
| `room_overviews[].overview` (PM prose narrative) | no | yes (placeholder from rooms) | yes (`normaliseRoomOverviews()`:191) | flows through into `data['rooms']`/`reviewed_data` |
| `room_overviews[].works_summary` (AI bullet checklist) | no | yes (seeded by `RoomOverviewSummaryService` in `ExtractQuoteJob`) | yes | rendered by `pdf/rams.blade.php:833` |
| `room_overviews[].summary` (AI legacy summary string) | no | yes | yes | not used by RAMS PDF; some fallback wiring |
| `room_overviews[].description` (AI 2-4 sentence prose paragraph) | no | yes (also AI-generated by `RoomOverviewSummaryService`) | yes | not used by RAMS PDF except as fallback chain |

The takeaway: **`extracted_data` and `reviewed_data` carry the same shape (canonical review schema). `generated_data` is a different shape entirely (DOCX-ready). The "project.works_description" lives in all three but with three different access paths.**

---

## 3. Field Inventory (Master Table)

### 3.1 Project-level scope/narrative fields

| Field | Captured at | Storage location (column / JSON path) | Consumed at | Used by AI? | Notes |
|---|---|---|---|---|---|
| `works_description` (textarea) | `resources/views/rams/create.blade.php:144-161` ("Works Description *") — RamsFormRequest:25 enforces required, min:20 | `rams_documents.form_data['works_description']`; AND `projects.works_description` (column) | `RamsBuilderService::buildFromForm()`:115 → `parsed['works_summary']`; `RamsDataBuilderService::resolveProjectFields()`:194 → `data['project']['works_description']`; `MiniOmBuilderService:136`; `WordDocumentService:211` (PDF "Scope"); `RamsComplianceUpgradeService:315,485` (used as hints for noPodium / groundLevel scope gates) | YES — passed as `scope` context to `RamsPrompt` (line 40) and indirectly to `MethodStatementPrompt` via `MethodStatementService::buildScope()` line 125-148 (treated as "PM INSTRUCTIONS") | The original "scope" field. Manual-form pipeline ONLY uses this. Quote-import pipeline mostly bypasses it. |
| `project.works_description` (canonical) | `RamsDataBuilderService::resolveProjectFields()`:194-220 — derived from `formData['works_description']` OR `parsed['works_summary']` | `generated_data['project']['works_description']` | `app/scripts/generate_rams_docx.js:217` (template variable); PDF "Scope" line on cover | no (output only) | Renamed/normalised version of the input, written into generated_data |
| `scope_of_works` (AI paragraph) | (1) `ProjectPackageReviewController::generateScopeOfWorks()`:406-483 (button in package review). (2) `ExtractQuoteJob::generateContentPack()`:214 (auto on upload) | `extracted_data['scope_of_works']`; `reviewed_data['scope_of_works']` (via `RamsReviewDataService::normalise()`:86); `generated_data['scope_of_works']` (via `RamsBuilderService::buildFromReview()`:218-225) | `resources/views/pdf/rams.blade.php:296,1035-1040` — printed as paragraphs in §4 Scope of Works when no per-room overviews exist; `RamsBuilderService:694` (`pipeline()` legacy fallback path); `MethodStatementService::buildScope()`:128 (highest-priority scope source); `RamsComplianceUpgradeService:316,486` (hint scanning) | YES — written by `ScopeOfWorksPrompt`; also FED INTO `MethodStatementPrompt` as `scope_summary` | The most common "what is this project's scope" field; AI-generated 4-7 sentence paragraph |
| `works_overview` (AI 2-3 sentence exec summary) | (1) `ProjectPackageReviewController::generateScopeOfWorks()`:481 (same AI call as scope_of_works — same prompt). (2) `ExtractQuoteJob`:215. (3) Editable manually on package-review form line 438 | `extracted_data['works_overview']`; `reviewed_data['works_overview']` | `resources/views/pdf/rams.blade.php:389` (when scanning for permit triggers — keyword search only); `MethodStatementService:53` → `MethodStatementPrompt:214` (passed as "Project overview" line to AI); `WorksheetGeneratorService:116,493` (worksheet cover/header); `WorksheetPrompt:137`; `PublicSurveyController:106-107`, `SurveyController:340-341` (fallback chain for survey display); `OmManualPrompt`; `SurveyQuestionsPrompt:66` | YES — written by `ScopeOfWorksPrompt`; consumed by `MethodStatementPrompt`, `WorksheetPrompt`, `SurveyQuestionsPrompt`, `OmManualPrompt` | "Short version" of scope_of_works. Used for cover pages and headers across all docs. Never rendered directly in the RAMS PDF body (only used as AI input + keyword-source). |
| `works_bullets` (single textarea — install-action list) | `resources/views/project-packages/review.blade.php:463-468` ("AV Works Bullets" textarea); generated by AI via `WorksBulletsPrompt` (Convert-to-bullets button) | `extracted_data['works_bullets']`; `reviewed_data['works_bullets']` (via `parseReviewPayload`:988) | `SurveyController:483` (passed to public survey); `resources/views/surveys/show.blade.php:317-570` (PLANNED AV WORKS drawer); NOT consumed by RAMS PDF/DOCX at all | YES — written by `WorksBulletsPrompt` | A SEPARATE bullets field from `scope_of_works_bullets`. This is the project-wide "AV WORKS Bullets" textarea the user mentioned. Used by SURVEY ONLY. |
| `scope_of_works_bullets` | Auto-generated by `RamsComplianceUpgradeService::upgradeScopeOfWorks()`:236 — built from ProjectContext rooms+activities OR quote line items, then appended with "Cabling/Mounting/Testing/Handover" defaults | `generated_data['scope_of_works_bullets']` (transient — written by upgrade service on every render) | `resources/views/pdf/rams.blade.php:772-778` ("Works Activities" bullet list in §4); `DocxBuilderService:326-337` (same place in DOCX) | NO — pure heuristic (keyword matching on equipment descriptions) | A DIFFERENT field from `works_bullets` despite the similar name. Renders inline above the per-room narrative. Always re-computed at render. |
| `method_statement_notes` (PM notes for the AI) | (1) `ProjectPackageReviewController::parseReviewPayload()`:982 (textarea); (2) `RamsExtractionDraftBuilderService::build()`:61 (seeded from `formData.works_description`) | `extracted_data['method_statement_notes']`; `reviewed_data['method_statement_notes']`; mirrored into `generated_data['method_statement_notes']` by `RamsBuilderService:241-243` | `MethodStatementService::buildScope()`:125-148 — appended as "PM INSTRUCTIONS" to AI prompt; `RamsComplianceUpgradeService` access-equipment gating line 314 + `addProjectSpecificRisks` line 484 — keyword-scanned for "no podium", "ground level", etc. to suppress boilerplate; ALSO synced back to `Project.works_description` (`ProjectPackageReviewController:348,393`) | YES — primary "instructions to override defaults" channel to the method-statement AI | Confusingly named — it's the **PM-curated project-wide scope/instructions**, not actually statement-only. Practically a renamed `works_description` for the review form. |
| `project.overview` (RamsReviewDataService canonical) | `RamsReviewDataService::normaliseProject()`:113 — copies through from any `data['project']['overview']` it sees | `extracted_data['project']['overview']`; `reviewed_data['project']['overview']` | **NOT READ by any consumer.** No grep hit for `$project['overview']`, `reviewed_data['project']['overview']`, or `generated_data['project']['overview']` (grep timed out only because of size; targeted patterns returned 0). The "raw QuoteWerks overview" text DOES get used — but as `extracted_data['overview']` (top level, not nested under project) on the `ExtractQuoteJob:124,133` path | NO | **DEAD FIELD.** Round-tripped by the normaliser but never displayed or used in generation. |
| `extracted_data['overview']` (raw QuoteWerks PDF overview block) | `QuoteParserService::parse()`:177 (`overview` key); used by `ExtractQuoteJob:124,133` to seed `projects.works_description` | `extracted_data['overview']` (top-level on `ProjectPackage`); copied to `Project.works_description` column | `RamsExtractionDraftBuilderService::buildProject()`:112 — stored under `extracted_data['project']['overview']` (different shape) | NO | The QuoteWerks "Overview" section is the original source. It then gets duplicated into 3 places: `Project.works_description`, `ProjectPackage.works_description`, `extracted_data['project']['overview']`. |

### 3.2 Per-room narrative fields (`room_overviews[]` rows)

`room_overviews` is an array of room objects living on `extracted_data` / `reviewed_data`. Per row, the canonical schema (per `RamsReviewDataService::normaliseRoomOverviews()`:181-198 and `ProjectPackageReviewController::parseReviewPayload()`:1042-1059) has SIX text-bearing fields:

| Sub-field | Captured at | Storage location | Consumed at | Used by AI? | Notes |
|---|---|---|---|---|---|
| `room` | `resources/views/project-packages/review.blade.php:506` (text input "Room / Space"); auto-seeded from `equipment.area` (`ExtractQuoteJob:292-303`) | `room_overviews[*].room` | Every consumer (rams.blade.php:832, etc.) | indirectly | The canonical key. Survey rooms also auto-feed in. |
| `overview` | `resources/views/rams/quote-review.blade.php:584-587` (per-room editable textarea "Phrased Overview — narrative, client-facing prose") and `project-packages/review.blade.php` | `room_overviews[*].overview` | `pdf/rams.blade.php:834` (fallback to bullets); `RamsComplianceUpgradeService::ensurePerRoomBullets()`:70 (input to AI bullet-conversion); `MethodStatementService::buildRoomOverviewSummary()`:227, `buildRoomList()`:299; `WorksheetGeneratorService` | YES — INPUT to `RoomOverviewSummaryPrompt`; ALSO part of `MethodStatementPrompt` via `room_overview_summaries` context | The PM's typed/sales-pasted prose. Original source for all per-room narratives. |
| `works_summary` | (1) AI: `RoomOverviewSummaryService::summarize()` returns a bullet list under `summary` key, then `RamsComplianceUpgradeService::ensurePerRoomBullets()` rewrites it into `room_overviews[*].works_summary` (line 122); (2) Manual edit via `resources/views/project-packages/review.blade.php` per-row textarea "AV Works Summary"; (3) Worksheet's "Convert to bullets" pipeline writes this back into reviewed_data (`WorksheetGeneratorService:149`) | `room_overviews[*].works_summary` (newline-separated `- ` bullets) | `pdf/rams.blade.php:833` (PRIMARY render path for per-room scope in RAMS §4); `MethodStatementGeneratorService::buildRoomOverviewSummary()`:290; `WorksheetGeneratorService:108`; `MiniOmBuilderService:313`; `InstallTaskGeneratorService:111`; `site-survey/show.blade.php:658` | YES — output of `RoomOverviewSummaryPrompt`; also consumed by `MethodStatementPrompt` as `room_overview_summaries` | The current "winning" per-room field. Bullet form. |
| `summary` | Legacy: `RoomOverviewSummaryPrompt` returned `summary` (bullet text) — same value historically lived under both keys. Currently `RamsReviewDataService::normaliseRoomOverviews()`:192 normalises both `summary` and `works_summary` independently | `room_overviews[*].summary` | `RamsReviewDataService::load()`:56 (backfill); `MethodStatementService:226` reads `$row['works_summary'] ?? $row['summary'] ?? ''`; `ProjectPackageReviewController:437` reads same fallback | indirectly | **DEPRECATED.** Identical semantics to `works_summary`. Older code wrote `summary`; newer code writes `works_summary`. Both still in the normaliser. |
| `description` | AI: `RoomOverviewSummaryPrompt` (line 79-87) returns a `description` field — "2–4 sentence prose paragraph". Saved by `RoomOverviewSummaryService` (via the same call). Editable nowhere directly | `room_overviews[*].description` | `MethodStatementService::buildRoomDescriptions()`:266-284 — newline-delimited "Room: prose" passed to `MethodStatementPrompt` as `room_descriptions` context; `pdf/rams.blade.php:834` as a fallback (after `overview`) | YES — output of `RoomOverviewSummaryPrompt` AND input to `MethodStatementPrompt` | A SECOND AI-produced narrative (alongside `works_summary` which is bullets). Used as AI scaffolding more than as direct render content. |
| `solution_type_id` | `resources/views/project-packages/review.blade.php` — dropdown per row, FK to `solution_types` | `room_overviews[*].solution_type_id` | `MethodStatementService::buildRoomOverviewSummary()`:212-249 (loads name + install_method into AI prompt); `MethodStatementGeneratorService::buildRoomOverviewSummary()`:282-313 | indirectly | The structured "what kind of room" link to the Solution Type library. Renders no text directly but shapes AI output. |

### 3.3 Survey-room fields (parallel data captured separately on `SiteSurveyRoom`)

| Field | Captured at | Storage | Consumed at | Notes |
|---|---|---|---|---|
| `av_requirements` (textarea) | `resources/views/site-survey/_room-form.blade.php:238-239` | `site_survey_rooms.av_requirements` column | `resources/views/site-survey/show.blade.php:680`; `surveys/show.blade.php` — surfaced to the public survey link as "PLANNED AV WORKS prose" fallback | Survey-only mirror of the same idea. Engineer-facing scope per room. |
| `av_equipment_list` | `_room-form.blade.php:510-511` | `site_survey_rooms.av_equipment_list` | `show.blade.php:683`; surveys public link | Listed existing equipment in the room (NOT the same as planned scope). |
| `space_type` / `area_type` | survey forms — selects | columns | survey display, conditional renders | Not narrative — typed metadata. |
| Survey "ProjectContext" room rows | `ProjectContextResolver::__invoke()` → `app/Services/ProjectContext/ProjectContextBuilder::build()` reads `SiteSurvey.survey_data` JSON | (transient) `projectContext['rooms']` | `RamsBuilderService::buildProjectContext()` → `RamsDataBuilderService::assemble()` writes `data['rooms']` | The survey's per-room engineer feedback (heights, cable routes, wall construction) flows into RAMS as `data['rooms'][n]['engineer_feedback']` — completely independent of `room_overviews[]`. |

### 3.4 Field-discovery summary

| Field name | Origin | Lives on | Renders to |
|---|---|---|---|
| `works_description` | manual form / project model | `form_data`, `projects.works_description`, `project_packages.works_description` | RAMS DOCX cover ("Scope"), survey general_notes seed, method-statement AI prompt |
| `scope_of_works` (paragraph) | AI from room overviews | `extracted_data`, `reviewed_data`, `generated_data` | RAMS §4 paragraph (fallback when no per-room overviews) |
| `works_overview` (2-3 sentence) | AI (same call) | `extracted_data`, `reviewed_data` | Worksheet cover, O&M header, method-statement AI prompt, keyword-scanning for permits/access |
| `works_bullets` (project-wide list) | AI from prose | `reviewed_data` | Survey "Planned AV Works" drawer only |
| `scope_of_works_bullets` (project-wide list) | Heuristic from equipment | `generated_data` (transient) | RAMS §4 "Works Activities" bullet list |
| `method_statement_notes` | PM textarea | `reviewed_data`, syncs to `Project.works_description` | AI "PM INSTRUCTIONS" override + scope-gate keyword scanning |
| `room_overviews[].overview` (per-room prose) | PM typing / sales paste | `extracted_data`, `reviewed_data` | RAMS §4 per-room paragraph (when no bullets), AI input |
| `room_overviews[].works_summary` (per-room bullets) | AI from overview | `extracted_data`, `reviewed_data` | RAMS §4 per-room bullet list (PRIMARY), Worksheet, Mini-OM, Survey |
| `room_overviews[].summary` (per-room legacy bullets) | AI (older code) | `extracted_data`, `reviewed_data` | RAMS — never; fallback chain only |
| `room_overviews[].description` (per-room prose paragraph) | AI from overview | `extracted_data`, `reviewed_data` | Method-statement AI prompt, RAMS fallback chain |
| `project.overview` (canonical normaliser) | round-tripped from `parsed['overview']` | `extracted_data`, `reviewed_data` | NOWHERE |
| `extracted_data.overview` (top-level, raw) | QuoteParserService PDF overview block | `extracted_data` (top-level), `Project.works_description`, `ProjectPackage.works_description` | RAMS DOCX cover via `Project.works_description` chain |
| `av_requirements` (survey) | survey room form | `site_survey_rooms.av_requirements` | Survey show, public survey, survey DOCX |

---

## 4. Overlap & Contradiction Matrix

### Pair A: `works_description` vs `method_statement_notes`
- **Same intent:** "project-wide PM-authored scope and instructions"
- **Captured at:** different forms (manual RAMS create vs project-package review)
- **Stored at:** different columns (`form_data.works_description` vs `reviewed_data.method_statement_notes`)
- **Consumed at:** BOTH end up in `RamsDataBuilderService::resolveProjectFields()` as `project.works_description`, and BOTH end up in `MethodStatementService::buildScope()` (lines 125, 128) where `works_summary` is treated as "PM notes" (which is `formData.works_description` rewritten in `RamsBuilderService::buildFromForm():115`)
- **Contradiction:** `ProjectPackageReviewController::approve()` line 393 SYNCS `method_statement_notes` → `Project.works_description`. So editing one of them silently changes the other across both pipelines. But `RamsController::generateFromProject()` line 284 also writes `method_statement_notes` into `form_data.works_description` — a third copy.
- **Result:** A single edit to "scope" can live in up to 5 places at once: `form_data.works_description`, `reviewed_data.method_statement_notes`, `reviewed_data.project.works_description`, `Project.works_description`, `ProjectPackage.works_description`.

### Pair B: `scope_of_works` (paragraph) vs `works_overview` (executive summary)
- **Same intent:** AI-generated narrative of the project
- **Captured at:** `ScopeOfWorksPrompt` returns BOTH in a single JSON response (line 63). Same generate button.
- **Stored at:** parallel keys at the same level — `extracted_data['scope_of_works']` and `extracted_data['works_overview']`
- **Consumed at:** `scope_of_works` is the primary BODY paragraph in RAMS §4. `works_overview` is the cover/header version on Worksheets/O&M.
- **Contradiction:** `MethodStatementService::generate()` line 47 passes BOTH `scope_summary` (built from `scope_of_works`/`tasks`/`equipment`) AND `works_overview` to the prompt — so AI sees two narratives of the same project. They are AI-generated from the SAME room overviews via the same prompt, so they're usually consistent — but if a user edits one and not the other (both are editable on the review page), they drift apart and end up in different sections of the same document.
- **Result:** Cover blurb vs body paragraph can describe the project differently after manual edits.

### Pair C: `scope_of_works_bullets` (RAMS upgrade-derived) vs `works_bullets` (review-form / survey)
- **Same name shape, different lifecycle.**
- `scope_of_works_bullets` is built fresh by `RamsComplianceUpgradeService::upgradeScopeOfWorks()` (line 133-240) every render — equipment-keyword-driven. NEVER persisted to `reviewed_data`. Lives only on `generated_data`.
- `works_bullets` is a single textarea on the package-review screen (`project-packages/review.blade.php:463`). Editable. AI-converted from `scope_of_works` / `works_overview` prose via `WorksBulletsPrompt`. Persisted on `reviewed_data.works_bullets`. Read ONLY by surveys (`SurveyController:483`).
- **Contradiction:** Both labelled "bullets" or "Activities" to the user but they are completely independent. The RAMS PDF shows `scope_of_works_bullets`; the survey link shows `works_bullets`. PM can edit `works_bullets` and never see the change in the RAMS.
- **Cited:** `resources/views/pdf/rams.blade.php:772-778` vs `resources/views/surveys/show.blade.php:317`.

### Pair D: per-room `overview` vs `works_summary` vs `summary` vs `description`
- ALL FOUR live on `room_overviews[*]`. The PDF priority (rams.blade.php:833-834):
  ```
  $rvBullets = trim((string) ($roomOv['works_summary'] ?? ''));    // primary
  $rvDesc    = $roomOv['overview']  ?? ($roomOv['description'] ?? ($roomOv['scope'] ?? ''));  // fallback
  ```
- `works_summary` wins if non-empty. `overview` is the only one rendered as prose. `description` is only used as a tail fallback. `summary` is never used by the RAMS PDF (only by deeper fallback chains in MethodStatementService:226 etc).
- **Origin contradiction:** `RoomOverviewSummaryPrompt` writes both `summary` (bullets) and `description` (prose). The cache-stored AI response on older records has `summary` populated. The `RamsComplianceUpgradeService::ensurePerRoomBullets()` line 122 writes the SAME bullets under `works_summary` on newer records. Both keys can hold the same content on the same row.
- **Result:** the truth of "what's the per-room scope" depends on which path populated which key first, and the four-way fallback chain in rams.blade.php hides this from the user — until they edit one and don't see it reflected because the other key takes priority.

### Pair E: `extracted_data['overview']` (QuoteWerks PDF prose) vs `Project.works_description`
- `QuoteParserService::parse()` extracts the "Overview" section of QuoteWerks PDFs → `parsed['overview']`. `ExtractQuoteJob:124,133` copies it into both `Project.works_description` and `ProjectPackage.works_description`. Then `RamsExtractionDraftBuilderService:112` puts the SAME value (or its form-supplied override) at `extracted_data['project']['overview']`.
- **Result:** the same raw quote-overview text exists at three locations and none of them have a clear "source of truth" rule. The `Project.works_description` column is read directly by the manual RAMS create form (line 153) as a default — meaning the original quote overview can leak directly into the manual form as scope text years later, even after the user has rewritten everything via the review flow.

### Pair F: Survey `av_requirements` vs RAMS `room_overviews[*].overview`
- Different tables, similar content. The user describes "what AV is going in this room" in both places. There is no bidirectional sync. The PublicSurveyController:124 and SurveyController:357 chain falls back `description → works_summary → overview → scope` — pulling RAMS data into survey display — but no reverse propagation.
- **Result:** A survey-rewritten room scope doesn't update the RAMS room_overview; a RAMS-edited room overview doesn't update the survey av_requirements.

---

## 5. Dead Paths

| Path | Status | Citation |
|---|---|---|
| `reviewed_data.project.overview` | Captured/normalised but no consumer reads `project.overview` from the project sub-object. The value only ever survives the round-trip; PDF/DOCX read `reviewed_data.scope_of_works` or `reviewed_data.works_overview` instead. | `RamsReviewDataService::normaliseProject()`:113; no grep hits for `['project']['overview']` consumer in app/ |
| `room_overviews[*].summary` | Legacy field with identical semantics to `works_summary`. Only kept alive by the `?? $row['summary']` fallback in `MethodStatementService:226` and `MethodStatementGeneratorService:290`. New writes go to `works_summary`. | `app/Services/RamsReviewDataService.php:192`; `app/Services/MethodStatementService.php:226` |
| `room_overviews[*].scope` | Referenced ONLY in the `??` fallback chain in `RamsComplianceUpgradeService:71` and `pdf/rams.blade.php:834`. Never written by any service in the codebase — search for assignment returned no hits. | `app/Services/Rams/RamsComplianceUpgradeService.php:71`; `resources/views/pdf/rams.blade.php:834` |
| `room_overviews[*].description` (RAMS render) | Written by `RoomOverviewSummaryPrompt`. Used as input to the method-statement prompt (`MethodStatementService:267`). NOT rendered directly in the RAMS PDF body except as a 3rd-position fallback after `overview` (rams.blade.php:834). On the typical happy path it's invisible to the engineer. | `resources/views/pdf/rams.blade.php:834` |
| `RamsPrompt` (whole-document AI prompt) | The prompt class still exists with full RAMS-generation schema (controls, hazards, method_statement, etc.) but is NOT called by any active pipeline — `MethodStatementPrompt` is the only RAMS-related prompt actually invoked. Reverse-grep shows `RamsPrompt::class` only referenced in `app/Services/RamsGeneratorService.php` (a legacy file with `'works_description' => 'AV installation works.'` hardcoded — see line 252). | `app/Core/AI/Prompts/RamsPrompt.php`; `app/Services/RamsGeneratorService.php:252` |
| `RamsGeneratorService` | Likely-legacy alternate generator class kept alongside the active `RamsBuilderService`. Hard-codes `works_description` to `'AV installation works.'` (line 252). | `app/Services/RamsGeneratorService.php` |
| Permit auto-derive scans `works_summary` | `pdf/rams.blade.php:387-391` scans `reviewed_data['scope_of_works'] + works_overview + method_statement_notes` (lowercased + concatenated) for "ceiling void"/"electrical"/"solder"/etc. keywords. The same scan is done in `RamsComplianceUpgradeService:313-322` and `:483-488`. If the user only edits `room_overviews[*].works_summary` (the per-room bullets), the keyword scan misses it — permits are NOT auto-derived from per-room bullets. So a project with all of its scope in the per-room bullets and an empty top-level `scope_of_works` produces an empty Permits section. | `resources/views/pdf/rams.blade.php:386-410` |
| `works_bullets` in RAMS rendering | Captured on the package-review form, persisted on `reviewed_data.works_bullets`, but no RAMS service reads it. Only `SurveyController:483` (survey display). | `resources/views/project-packages/review.blade.php:463-468`; `app/Http/Controllers/SurveyController.php:483` |
| `H-07 DocumentArtifactStorage` compliance | RAMS pipeline IS compliant — `RamsController::forceDestroy()`:735 and `resolveRamsDocxPath()`:1072 use `DocumentArtifactStorage`. No hand-built `storage_path('app/rams/...')` references found in active code paths. | `app/Http/Controllers/RamsController.php:734-737,1072-1074` — confirmed |

---

## 6. Recommendations

### Cluster 1: Project-wide narrative consolidation

**Status quo:** 5 keys (`works_description`, `scope_of_works`, `works_overview`, `works_bullets`, `method_statement_notes`) describe overlapping but slightly different things.

| Field | Recommendation | Rationale |
|---|---|---|
| `works_description` (form_data column / Project column) | **KEEP as the "raw scope" input on `Project` model only.** Drop from `form_data` in new RAMS records — the review form should write directly to `reviewed_data.scope_of_works`. | Project-level column is needed for cross-document propagation (Mini-OM, Survey, Cable Schedule all read it). But persisting THREE copies (form_data, Project, ProjectPackage) is the bug — they go out of sync. Migrate the Project column to be the single source for "raw quote/PM-typed prose" and treat `reviewed_data.scope_of_works` as the "AI-refined" version. |
| `method_statement_notes` | **MERGE into `reviewed_data.scope_of_works`.** The "PM INSTRUCTIONS" treatment in `MethodStatementService:144` is already a separator-suffix; the section in the prompt can simply read from `scope_of_works` (which already gets passed to the AI). | Two names for one thing. The current `ProjectPackageReviewController:348` already maps `method_statement_notes` → `Project.works_description`. |
| `scope_of_works` (paragraph) | **KEEP as the canonical project-wide scope text.** Make it the only place the PDF body reads from. | Already the de-facto winner for the PDF body. Rename clarification: this is the "human-readable scope paragraph". |
| `works_overview` (2-3 sentence) | **KEEP but explicitly DERIVE** from `scope_of_works` at generation time when missing. Stop running it as a parallel AI call; have one call return both. | Already the case — `ScopeOfWorksPrompt:63` returns both in one JSON. The two fields just shouldn't be re-fetched independently. |
| `works_bullets` (project-wide, single textarea) | **DEPRECATE.** No RAMS consumer reads it. Move the survey's "Planned AV Works" drawer to derive bullets from `reviewed_data.scope_of_works_bullets` (computed) or from the union of `room_overviews[*].works_summary` (already bullets). | Saves a textarea on the busy review screen and removes one AI prompt (`WorksBulletsPrompt`). |
| `scope_of_works_bullets` | **KEEP** but stop computing it at every render via `RamsComplianceUpgradeService::upgradeScopeOfWorks()`. Compute once at approve time and persist on `reviewed_data`. | Currently transient on `generated_data`; means every regenerate re-runs the heuristic and can drift if equipment list changes mid-pipeline. |

**Change footprint:**
- Migration: no schema change (all in JSON columns)
- Controllers: `ProjectPackageReviewController` (drop `method_statement_notes` form mapping), `RamsController` (drop `form_data.works_description` write path)
- Services: `MethodStatementService::buildScope()` (read only `scope_of_works`); `RamsBuilderService::buildFromReview()` (drop the 3-way fallback at line 218-225)
- Prompts: drop `WorksBulletsPrompt`
- Blade: drop "AV Works Bullets" textarea on `project-packages/review.blade.php:449-469`; update survey blade fallback chain
- **Breaking for already-generated docs?** No — `generated_data` keeps the same shape; we're only removing INPUT-side duplication. Existing PDFs render unchanged.

### Cluster 2: Per-room narrative consolidation

**Status quo:** 4 text fields per room (`overview`, `works_summary`, `summary`, `description`), 6 if you count `room` and `solution_type_id`.

| Field | Recommendation | Rationale |
|---|---|---|
| `room_overviews[*].room` | **KEEP** — primary key. | Used by every consumer. |
| `room_overviews[*].overview` | **KEEP** — the engineer/sales-typed prose source. | Single source of truth for human-written narrative. |
| `room_overviews[*].works_summary` | **KEEP** as the canonical AI bullet output. Rename internally to clarify it's bullets (the field name doesn't suggest that). | Already the winning field for the PDF body. Used by RAMS/Worksheet/Mini-OM/Survey. |
| `room_overviews[*].summary` | **DEPRECATE.** Add a one-time migration that copies any non-empty `summary` into empty `works_summary` slots. | Identical semantics to `works_summary`. Only legacy records use it. |
| `room_overviews[*].description` | **MERGE into a new `room_overviews[*].prose` OR keep — but make it visible.** Currently invisible to the engineer despite being AI-written; only used as method-statement prompt input. Either expose it in the review form OR drop it and use `overview` as the description source (since `overview` is the typed prose). | The AI is producing data that the engineer cannot edit or even see. That violates the project's constraint that AI output must be reviewable. |
| `room_overviews[*].scope` | **DEPRECATE.** No code path writes it. | Pure dead fallback. |
| `room_overviews[*].solution_type_id` | **KEEP** — structured link to Solution Type library. | Working as intended. |

**Change footprint:**
- Migration: one-off backfill of `summary` → `works_summary`, drop `summary` from `RamsReviewDataService::normaliseRoomOverviews()`
- Services: `MethodStatementService:226`, `MethodStatementGeneratorService:290` (drop the `?? $row['summary']` fallback), `ProjectPackageReviewController:437` (drop same)
- Blade: `pdf/rams.blade.php:834` simplify the fallback chain to just `works_summary` (bullets) → `overview` (prose). Drop `description` and `scope` from the chain.
- **Breaking for already-generated docs?** No — `generated_data` doesn't store per-room text directly (it lives on `reviewed_data` and is read at PDF-render time). But there IS a risk for already-stored `reviewed_data` records where only `summary` (not `works_summary`) is populated — the backfill is essential.

### Cluster 3: Raw-quote overview → Project.works_description chain

| Field | Recommendation | Rationale |
|---|---|---|
| `extracted_data['overview']` (top-level, raw QuoteWerks block) | **KEEP** as the as-imported text, but do NOT auto-write it into `Project.works_description`. Use it only as the seed value the PM sees on the review page in the `method_statement_notes` (soon to be `scope_of_works`) textarea. | Currently `ExtractQuoteJob:124,133` writes it directly to `Project.works_description` which then leaks into the manual RAMS create form as a default. Sales prose like "Cinnamon now has a Sony 98 inch display chosen — other larger sizes are available…" gets dropped into a method-statement field unfiltered. Violates the "AI is only for formatting" constraint by extension — sales-style hedging ends up in compliance documents. |
| `extracted_data['project']['overview']` | **DEPRECATE.** No reader. | `RamsReviewDataService::normaliseProject()` round-trips it but no consumer reads it. |
| `ProjectPackage.works_description` column | **DEPRECATE in favour of joining `extracted_data['overview']` when needed.** | Duplicate of the JSON value with no transformation. |
| `Project.works_description` column | **KEEP** but treat as "what the user explicitly typed/approved" — only written via `ProjectPackageReviewController::approve()` (which already does the right thing) and the manual project edit form. NOT auto-populated from raw quote text. | Otherwise the chain is unauditable. |

**Change footprint:**
- `ExtractQuoteJob`:124,133 — remove the `works_description => $extracted['overview']` assignments
- Migration to mark `project_packages.works_description` column for deprecation (no drop yet)
- Document the new "writer rule" clearly in `app/Models/Project.php`

### Cluster 4: Survey ↔ RAMS room narrative sync

| Field | Recommendation | Rationale |
|---|---|---|
| `site_survey_rooms.av_requirements` ↔ `room_overviews[*].overview` | **DERIVE one from the other** — pick a direction. Suggestion: survey is upstream (PM enters AV requirements during survey planning), then `av_requirements` seeds `room_overviews[*].overview` on first quote-import for that project. Subsequent edits on the package review screen are the source of truth. | Currently two separate text fields, no sync. Engineers see different copies of "what's going in this room" depending on which app section they look at. |

**Change footprint:**
- New service `RoomScopeSyncService` (one-way: survey → package on import only)
- Touches `ExtractQuoteJob`, `SiteSurveyController::store/update`
- **Breaking?** Yes — surveys already in flight where the engineer expects to keep typing into `av_requirements` need a grace period. Recommend a feature flag.

### Cluster 5: Dead-paths to remove (low-risk, do first)

- Drop `room_overviews[*].scope` from the fallback chain in `pdf/rams.blade.php:834` and `RamsComplianceUpgradeService:71` — nothing writes it.
- Drop `project.overview` from `RamsReviewDataService::normaliseProject()`:113 — nothing reads it.
- Delete `app/Services/RamsGeneratorService.php` (and `app/Core/AI/Prompts/RamsPrompt.php` if confirmed unused) after grep-verifying no test invokes them.

**Change footprint:** ~5 lines of PHP, no schema, no migration, no DOCX change.

### AI-safety flags (per CLAUDE.md constraint "AI is ONLY for formatting and method statement structuring")

- **`ScopeOfWorksPrompt`** — generates a Scope of Works PARAGRAPH from room-overview text the PM wrote. This is on the borderline: room-overview text is human input, but the AI invents the connecting prose. The prompt explicitly says "Do not invent equipment or details not present in the room breakdown above." Verification: the prompt does NOT have access to the equipment list or QuoteWerks PDF directly — only the rooms+overviews JSON. **PASSES the constraint as long as room_overviews are human-curated.**
- **`WorksBulletsPrompt`** — recommended for **deprecation** in Cluster 1; if kept, it's also on the borderline. Generates install-action bullets from prose. Constraint passes for same reason (prose is human-authored).
- **`RoomOverviewSummaryPrompt`** — generates both bullets (`summary`/`works_summary`) and a prose paragraph (`description`) from the engineer's typed `overview`. Same constraint pass.
- **`MethodStatementPrompt`** — this is the explicit AI call for "method statement structuring". **PASSES** the constraint by design.
- **`RamsPrompt`** (the legacy whole-document prompt) — would VIOLATE the constraint if called: the prompt schema includes `hazards.controls` AI-generated, `regulations` AI-generated, etc. Recommendation: confirm dead and delete. The `MethodStatementService::generate()` doesn't call it.

---

## 7. Suggested Next Steps

### Questions for the user (sign-off required before Phase XX)

1. **Cluster 1 — `works_description` cleanup:** Is it safe to stop auto-seeding `Project.works_description` from the QuoteWerks PDF overview text? Current behaviour leaks sales-style prose into compliance fields. (Recommendation: stop the auto-seed; still show the text in the review form as a starting suggestion.)
2. **Cluster 2 — per-room `summary` field:** OK to deprecate `room_overviews[*].summary` in favour of `works_summary`? A backfill migration is included.
3. **Cluster 2 — per-room `description` field:** Should the AI-generated prose paragraph (`description`) be surfaced in the review UI so PMs can edit it? Currently invisible — violates the "all AI output must be reviewable" principle even though it isn't displayed.
4. **Cluster 3 — Survey ↔ RAMS sync:** Direction preference? Survey → RAMS one-way (recommended) vs bidirectional vs no sync (status quo)?
5. **Cluster 5 — Delete `RamsGeneratorService` and `RamsPrompt`?** These appear to be dead but they're legacy and may be referenced by an out-of-tree test or by the `app/scripts/generate_rams_docx.js` Node script (which is also likely dead).
6. **Rename `method_statement_notes`?** If we keep it as a separate field (Cluster 1 recommends merge), at minimum rename to `scope_instructions` so reviewers don't confuse it with the AI-generated method statement output.

### Claude's discretion (no sign-off needed)

- Removing the `?? $row['summary']` and `?? $row['scope']` fallbacks once the user signs off Cluster 2.
- Switching `pdf/rams.blade.php` permit-keyword scan (line 386-391) to also scan `room_overviews[*].works_summary` so permits are correctly auto-derived when only per-room scope is filled in.
- Adding code-level docblocks to the canonical fields to discourage future drift.

### Proposed phase boundary

**Phase 22.1: RAMS scope/room-data consolidation** — bundles all Cluster 5 dead-path removals plus the Cluster 1 + Cluster 2 deprecation backfill. Estimated work:
- Wave 1 (parallel): Dead-path removal (5 files), `RamsReviewDataService` schema trim, backfill migration `summary → works_summary`.
- Wave 2 (sequential after wave 1): `ProjectPackageReviewController` form-field consolidation, `MethodStatementService::buildScope()` simplification.
- Wave 3 (sequential): Blade template updates — `pdf/rams.blade.php`, `DocxBuilderService::sectionScope`, `project-packages/review.blade.php`.
- Verification: regression-test that existing `reviewed_data` records still render to the same PDF byte-output (golden-file test in `tests/Feature/RamsRenderRegressionTest.php` recommended).

**Phase 22.2 (separate, lower priority): Cluster 3 / Cluster 4 sync** — the cross-document `Project.works_description` rules and survey ↔ RAMS one-way sync. Requires a feature flag because in-flight surveys may rely on current behaviour.

---

## Appendix A — Files inspected (whole file unless noted)

- `app/Models/RamsDocument.php`
- `app/Models/Project.php`
- `app/Models/ProjectPackage.php`
- `app/Models/SiteSurvey.php`
- `app/Models/SiteSurveyRoom.php`
- `app/Http/Controllers/RamsController.php`
- `app/Http/Controllers/ProjectPackageReviewController.php` (key methods)
- `app/Http/Requests/RamsFormRequest.php`
- `app/Http/Requests/RamsUploadRequest.php`
- `app/Services/RamsBuilderService.php`
- `app/Services/RamsDataBuilderService.php`
- `app/Services/RamsReviewDataService.php`
- `app/Services/RamsExtractionDraftBuilderService.php`
- `app/Services/RamsReviewValidatorService.php`
- `app/Services/MethodStatementService.php`
- `app/Services/MethodStatementGeneratorService.php` (key methods)
- `app/Services/QuoteParserService.php` (key sections — output shape)
- `app/Services/Rams/RamsComplianceUpgradeService.php` (key methods)
- `app/Services/DocxBuilderService.php` (sectionScope only)
- `app/Jobs/ExtractRamsDraftJob.php`
- `app/Jobs/BuildRamsDocumentJob.php`
- `app/Jobs/ExtractQuoteJob.php`
- `app/Core/AI/Prompts/RamsPrompt.php`
- `app/Core/AI/Prompts/MethodStatementPrompt.php`
- `app/Core/AI/Prompts/ScopeOfWorksPrompt.php`
- `app/Core/AI/Prompts/WorksBulletsPrompt.php`
- `app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php`
- `resources/views/rams/create.blade.php` (relevant sections)
- `resources/views/rams/review.blade.php` (header)
- `resources/views/rams/quote-review.blade.php` (room-overview region)
- `resources/views/pdf/rams.blade.php` (cover + §4 Scope of Works)
- `resources/views/project-packages/review.blade.php` (scope/works/bullets region)
- `resources/views/site-survey/_room-form.blade.php` (av_requirements region)
- All migrations under `database/migrations/*rams*`, `*project*`, `*room*`, `*survey*`

## Appendix B — Cross-document propagation map

```
       QuoteWerks PDF
            │
            ▼
   QuoteParserService.parse()
         └── parsed['overview']
            │
            ▼
   ExtractQuoteJob (writes 3 places):
     - ProjectPackage.extracted_data['overview']
     - ProjectPackage.works_description (column)
     - Project.works_description (column)
            │
            ├──> manual RAMS create form (read as default)
            │       → form_data['works_description']
            │           → RamsDataBuilderService
            │               → generated_data['project']['works_description']
            │                   → DOCX cover "Scope"
            │
            └──> generateContentPack() AI call
                   └── ScopeOfWorksPrompt
                        ├── scope_of_works
                        │       ├── extracted_data['scope_of_works']
                        │       │       → review form (editable)
                        │       │           → reviewed_data['scope_of_works']
                        │       │               → RAMS PDF §4 body paragraph
                        │       │               → MethodStatementPrompt scope_summary
                        │       │
                        │       └── used by RamsComplianceUpgrade scope-gate scan
                        │
                        └── works_overview
                                ├── extracted_data['works_overview']
                                │       → review form (editable)
                                │           → reviewed_data['works_overview']
                                │               → Worksheet cover/header
                                │               → O&M header
                                │               → MethodStatementPrompt "Project overview"
                                │               → permit-keyword scan in PDF & Upgrade service
                                │
                                └── never directly displayed in RAMS PDF body
```

```
       Room overviews
            │
   (1) Package-review form per-row:
     - overview (PM prose)         ─┐
     - works_summary (manual edit) ─┤
     - solution_type_id            ─┤
            │                       │
   (2) AI: RoomOverviewSummaryPrompt│ (input: overview)
            ├── summary (bullets)   │  (writes back into row)
            └── description (prose) │
            │                       │
   (3) AI: RamsComplianceUpgrade::ensurePerRoomBullets (re-runs same prompt)
            └── overwrites works_summary with fresh bullets if missing
            │
            ▼
   reviewed_data['room_overviews'][*]
       ├── room
       ├── overview          ←→ render: §4 fallback prose (when no bullets)
       ├── works_summary     ←→ render: §4 primary bullet list
       ├── summary           ←→ DEAD on RAMS PDF (fallback only in services)
       ├── description       ←→ MethodStatementPrompt only (not rendered)
       └── solution_type_id  ←→ MethodStatementPrompt + AI prompt label
```
