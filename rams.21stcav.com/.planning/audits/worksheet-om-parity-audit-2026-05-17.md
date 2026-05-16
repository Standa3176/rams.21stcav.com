# Worksheet & O&M Parity Audit vs Survey/RAMS Tightened Pipeline
Date: 2026-05-17
Auditor: comparative review post Phase 22.1 + today's sidebar fixes
Branch: feat/worksheet-classifier-universal

---

## IMMEDIATE TRIAGE

> [!CAUTION]
> **F-OM-01: O&M generation is broken on any project re-imported after Phase 22.1 shipped.**
>
> Reason: `OmManualGeneratorService::buildContextFromProjectData()` (`app/Core/Modules/OMManual/OmManualGeneratorService.php:274-280`) reads per-room narrative ONLY from `$package->extracted_data['room_overviews'][*]['description']`. Phase 22.1 D-01 deleted the `description` field from the canonical schema; `RamsReviewDataService::normaliseRoomOverviews()` (`app/Services/RamsReviewDataService.php:198-206`) now emits exactly 4 keys (`room` / `overview` / `works_summary` / `solution_type_id`) and `QuoteParserService:1919-1925` never wrote `description` in the first place. The OM validator at `app/Services/OmManualValidationService.php:73-75` then fails with "narrative for {room} is missing" because line 276 always returns `''`. **Net effect: any user clicking Generate from Project on an O&M for a freshly imported project gets a validation error and no document.**
>
> Older packages whose `extracted_data` still has populated `description` keys from before Phase 22.1 will continue to work. Recently re-extracted packages will not.
>
> Fix layer: one-line change in `OmManualGeneratorService:276` — fall back through `description` → `works_summary` → `overview` to match Phase 22.1's canonical chain, OR drop the legacy key entirely and read `overview` (mirrors RAMS' D-01 decision).

---

## 1. Reference baseline — what "tightened" means in survey/RAMS

After Phase 22.1 + today's sidebar fixes, the RAMS pipeline conforms to a five-part contract that no other generated-document pipeline currently matches:

1. **Canonical room schema.** Per-room narratives live in `reviewed_data.room_overviews[]` with exactly four keys: `room`, `overview`, `works_summary`, `solution_type_id`. Legacy `summary` / `description` / `scope` are deleted at normaliser-write time (`app/Services/RamsReviewDataService.php:186-207`). A structural guard test (`tests/Feature/Rams/Phase22_1InvariantGuardTest.php:72-88`) locks this shape.
2. **Display-time enrichment without persistence.** `RamsDisplayPatchService::patch()` (`app/Services/Rams/RamsDisplayPatchService.php:38-315`) re-resolves project_manager / doc_author / client_contact / rooms_text / scope_items every render from live Project + latestPackage data, applies the `HardwareClassificationService` filter (`app/Services/HardwareClassificationService.php`) so warranties/cables/services never leak into the kit list, and never persists the mutated state. The "Prepared By is the 21CAV PM, not the client SHIPEMAIL leak" fix lives here.
3. **Tier-1 compliance upgrade.** `RamsComplianceUpgradeService::upgrade()` (`app/Services/Rams/RamsComplianceUpgradeService.php:24-42`) deterministically adds 13 sections (PPE matrix, access equipment with PM-opt-out detection, fixings_control, supervision_and_qa, cdm_duty_holders, material_handling_derived with inch-size-aware team sizing, etc.). Read-through cache: if `scope_of_works_bullets` is persisted at approve-time, the heuristic short-circuits (D-06).
4. **Classifier honoured.** Both the package classifier (`ExtractQuoteJob::classifyItemType` → `hardware` / `consumable` / `professional_service`) and the today-shipped `HardwareClassificationService` partition equipment so warranties, delivery, programming days, and cable consumables never appear in scope_items.new_install.
5. **DOCX ↔ PDF parity locked by golden-file regression.** Today's `145c7a3 + da83d61 + 9a615b4` brought `DocxBuilderService` up to `resources/views/pdf/rams.blade.php` for cover personnel, MED-risk thresholds, CDM placement, dedup, and 9 missing sections. `RamsRenderRegressionTest` (referenced by `Phase22_1InvariantGuardTest:171-185`) holds at least three fixture-flavour byte-identity tests.

Plus the survey side (out-of-scope here but worth naming as the read-only reference at `SurveyController:320-470`): the survey wizard reads `reviewed_data.room_overviews` first, falls back to `extracted_data.room_overviews`, and walks the fallback chain `description → works_summary → overview → scope` for "Planned AV Works" — explicitly tolerating legacy data while preferring the canonical fields.

This audit checks how Worksheet and O&M Manual creation pipelines align with these five contracts.

---

## 2. Worksheet pipeline

### 2.1 Where it lives

| File | Role |
|------|------|
| `app/Http/Controllers/WorksheetController.php` | Thin controller — index/show/generate/status/download/destroy |
| `app/Jobs/BuildWorksheetJob.php` | Async pipeline — calls generator, persists generated_data, builds DOCX, sends notifications |
| `app/Services/WorksheetGeneratorService.php` | Per-room content builder — reads ProjectDataService + package room_overviews, runs AI per room, builds classified subsystems, blockers, commissioning checklist |
| `app/Services/Worksheet/WorksheetClassifier.php` | Deterministic tiered classifier (sku_map / manufacturer / keyword / mount-inherit / warranty) into 6 canonical categories |
| `app/Services/Worksheet/SafetyProfileService.php` | Per-room safety callouts |
| `app/Services/Worksheet/BlockerPromoter.php` | Pre-install-answer → blocker promotion |
| `app/Services/WorksheetDocxService.php` | DOCX builder — cover, room sections, blockers, warnings panel |
| `resources/views/worksheets/show.blade.php` | Web view (does NOT render works_summary_bullets / room_works_description) |

### 2.2 Comparison checklist

| Aspect | Survey/RAMS | Worksheet | Verdict |
|--------|-------------|-----------|---------|
| Canonical `room_overviews` read | Reviewed → Extracted, 4-key shape | Reviewed → Extracted, falls back `description → overview → scope` (legacy chain) at `WorksheetGeneratorService.php:105-107` | Partial — survives Phase 22.1 because it still falls back to `overview`, but reads `scope` which Phase 22.1 deleted |
| Owner/contact display-time patch | `RamsDisplayPatchService::patch()` re-resolves PM/author/contact every render | None — DOCX cover reads whatever `$project->user_id` and worksheet model columns hold; no equivalent `WorksheetDisplayPatchService` | Missing |
| Equipment classifier honoured | `HardwareClassificationService` + `ExtractQuoteJob` item_type | Own `WorksheetClassifier` reads `category` / `status` / `item_type === 'professional_service'` at `WorksheetGeneratorService.php:613` | Yes — different classifier but classifier-aware |
| Dead-path fields removed | `summary` / `description` / `scope` purged at normaliser | Reads `description ?? overview ?? scope` at `WorksheetGeneratorService.php:106` — `scope` and `description` are the dead fields | Partial — keeps reading deleted keys (harmless on new data, but reading a field Phase 22.1 deleted means worksheet behaviour will diverge from RAMS on the same project) |
| Structural guard test | `Phase22_1InvariantGuardTest` + `ReviewedDataStructuralDiffTest` | `WorksheetClassifierTest`, `WorksheetCategorySummaryTest`, `WorksheetPreInstallKeyingTest`, `WorksheetRegressionDifferTest` — all task-scoped, none locks the shape of inputs read from `room_overviews` | Partial — no schema-level guard |
| DOCX/PDF builder parity | DOCX brought up to PDF parity in today's `145c7a3` | Worksheet has DOCX only (no PDF), but DOCX-vs-show.blade is OUT of parity: DOCX renders `works_summary_bullets` + `room_works_description` (line 278-279) while show.blade renders neither | DOCX is canonical; web `show` view is degraded — user sees less than what they download |
| Tier-1 compliance upgrade | `RamsComplianceUpgradeService` 13 sections | None equivalent — `WorksheetGeneratorService` builds its own phased plan, commissioning checklist, safety callouts inline | N/A (different document type — compliance not required for worksheet) |
| Reads via canonical service | `RamsReviewDataService` normalises everything before consumers see it | `ProjectDataService::resolve()` at `WorksheetGeneratorService.php:76` — separate canonical service; no normaliser for room_overviews shape | Partial — has a canonical builder but room narratives bypass it |

### 2.3 Specific gaps

**F-WS-01 (low severity, accuracy):** `WorksheetGeneratorService.php:105-107` reads `$ov['description'] ?? $ov['overview'] ?? $ov['scope']`. Phase 22.1 deleted both `description` and `scope` from the canonical shape. After 22.1 the only branch that ever fires is `overview`. Worth simplifying to `overview` outright and removing the dead branches — keeping them gives a false impression that those keys are still part of the contract.

**F-WS-02 (medium severity, UX inconsistency):** `resources/views/worksheets/show.blade.php` does NOT render `works_summary_bullets` or `room_works_description`. `WorksheetDocxService.php:278-279` does. A user looking at the web view sees a different worksheet content than the DOCX they download. Fix layer: blade template — add the bullet list and works description block under each room accordion.

**F-WS-03 (low severity, observability):** Worksheet writes summarised bullets back into `$package->{$sourceKey}` at `WorksheetGeneratorService.php:153-159` — but `$sourceKey` can be either `extracted_data` or `reviewed_data`. If the package was already reviewed (reviewed_data populated), the bullets land in reviewed_data — which is exactly what Phase 22.1 wanted. If the package wasn't reviewed yet, the bullets land in extracted_data, AND the OM pipeline reads `extracted_data['room_overviews'][*]['description']` — so worksheet-generated bullets are NOT visible to OM (different key entirely). Not a bug per se, but it's a silent divergence between the three downstream pipelines on the same project.

**F-WS-04 (low severity, defensive):** No owner/contact display-time patch (vs RamsDisplayPatchService). If a worksheet's `user_id` was the original creator but the project owner has since changed, the worksheet cover will show stale data. RAMS solved this via `RamsDisplayPatchService` — worksheet has no equivalent. The model columns (`project_name`, `project_ref`, `client_name`, `site_address`) are snapshotted at create-time and never refreshed. Recommended: add `WorksheetDisplayPatchService` mirroring sections 1-2 of `RamsDisplayPatchService` (live project sync + model-column fallback). Skip sections 3-6 (personnel resolution, scope rebuild) — worksheet has different cover semantics.

---

## 3. O&M Manual pipeline

### 3.1 Where it lives

| File | Role |
|------|------|
| `app/Http/Controllers/OmManualController.php` | Multi-action controller — store (PDF upload), storeFromProject, generateFromProject, status, edit, update, generate, downloadPdf, destroy |
| `app/Jobs/BuildOmManualJob.php` | Async generator (300s timeout) |
| `app/Core/Modules/OMManual/OmManualGeneratorService.php` | 1768-line generator. `buildContextFromProjectData()` is the canonical new path; legacy PDF-extract paths still present |
| `app/Services/OmManualService.php` | Legacy two-pass PDF→AI service (still wired but mostly superseded) |
| `app/Services/OmManualValidationService.php` | Tier-1 NO-TBC validator. Requires `narrative` per room (≥1 char) |
| `app/Services/OmManualDocxService.php` | DOCX builder |
| `resources/views/pdf/om-manual.blade.php` | PDF Blade template (Browsershot/Chromium → mPDF previously) |
| `app/Core/AI/Prompts/OmManualPrompt.php` | AI prompt for Pass 2 (generates operating procedures + system overviews) |

### 3.2 Comparison checklist

| Aspect | Survey/RAMS | O&M | Verdict |
|--------|-------------|-----|---------|
| Canonical `room_overviews` read | 4-key shape, `description` deleted | Reads ONLY `extracted_data['room_overviews'][*]['description']` at `OmManualGeneratorService.php:274-280` — never reads `works_summary` / `overview` | **Broken on post-22.1 data** (see F-OM-01 above) |
| Owner/contact display-time patch | `RamsDisplayPatchService::patch()` | None — uses values stored on `$omManual` columns + AI-generated `project` block (with fallback at line 400-412) | Missing |
| Equipment classifier honoured | Yes — full pipeline | Yes — `filterHardwareItems()` honours `item_type === 'professional_service' / 'consumable'` at `OmManualGeneratorService.php:1306-1313` with legacy keyword fallback | Yes |
| Dead-path fields removed | `summary` / `description` / `scope` deleted | Still reads `description` as the SOLE narrative source. `summary` and `scope` not referenced | **Breaks on new data** |
| Structural guard test | `Phase22_1InvariantGuardTest`, `ReviewedDataStructuralDiffTest` | `OmManualProjectLinkageTest` only checks linkage, not data shape. No invariant guard on `narrative` provenance | Missing |
| DOCX/PDF builder parity | DOCX brought to parity today | **DOCX does not render `narrative` at all** (zero matches for `narrative` in `OmManualDocxService.php`). PDF blade renders narratives at lines 639-644 of `pdf/om-manual.blade.php` | **Asymmetric** — DOCX is missing per-room narrative section the PDF renders |
| Validator gate | RAMS has implicit fallbacks | `OmManualValidationService.php:73-75` rejects with "narrative for {room}" if blank → fails for every post-22.1 project, see F-OM-01 | Broken |
| Reads via canonical service | `RamsReviewDataService` | `ProjectDataService::resolve()` is called at `OmManualGeneratorService.php:262`, but room narratives bypass it and read raw `extracted_data` directly at line 274 | Partial — bypassed for the field that matters |

### 3.3 Specific gaps

**F-OM-01 (CRITICAL — production-affecting, listed at top):** Per-room narrative read at `OmManualGeneratorService.php:274-280` uses dead `description` field exclusively. Phase 22.1 D-01 deleted this field from the canonical schema. Result: validator at `OmManualValidationService.php:73-75` rejects all newly-extracted projects. Mini-fix: change line 276 from `$ro['description']` to a fallback chain matching what RAMS reads — `$ro['overview'] ?? $ro['works_summary'] ?? $ro['description']` — and update the surrounding comment.

**F-OM-02 (high severity, DOCX/PDF parity):** `OmManualDocxService.php` renders zero per-room narrative. Search for `narrative` returns no hits. The PDF blade at `resources/views/pdf/om-manual.blade.php:301-316` builds `$narrativesByRoom` from `$data['system_overviews']` (AI-generated) then falls back to `$r['narrative']` / `$r['description']`, and renders the result at lines 639-644. A user who downloads the DOCX gets a markedly different document from the PDF — no per-room overview section. Fix layer: `OmManualDocxService` needs a "Per-Room System Overview" section mirroring the PDF's lines 639-655.

**F-OM-03 (medium severity, observability):** No structural guard test for OM extracted_data shape. `OmManualProjectLinkageTest` only validates project linkage, not data shape. F-OM-01 would have been caught at CI time if the same `Phase22_1InvariantGuardTest`-style invariant existed for OM. Recommended: add `tests/Feature/OmManual/OmManualNarrativeProvenanceTest.php` that locks the read path to the canonical chain.

**F-OM-04 (medium severity, drift):** `OmManualGeneratorService::buildContextFromProjectData()` line 280 stops only at `$desc !== ''`, never logs which key was used. When this field eventually breaks again (e.g. someone renames `overview` to `prose`), the failure mode is silent — the validator catches it but the operator doesn't know which upstream key got dropped. RAMS doesn't have this problem because `RamsReviewDataService::normaliseRoomOverviews` is the single chokepoint. Recommended: thread room narrative reads through a small helper like `RoomOverviewNarrativeResolver` that logs the source key.

**F-OM-05 (low severity, dead code):** `app/Services/OmManualService.php` (the original two-pass PDF→AI service, 144 lines) is still autoloadable and still injected into the legacy `store()` flow. Per Phase 22.1's dead-path removal mindset, this service should be reviewed for whether it's still reachable — `OmManualController::store()` does call into it indirectly via `OmManualGeneratorService::extractFromPdf()`. The actual `OmManualService::extractFromQuote()` / `OmManualService::generateContent()` methods don't appear to be called by controllers. Deferred deep-dive: 30 min check; if confirmed dead, delete with a `Phase22_1InvariantGuardTest::test_sc3`-style guard.

**F-OM-06 (low severity, equipment-list compliance):** OM does honour the classifier (line 1306-1313), but the legacy keyword fallback at lines 1319-1336 includes `'option'` as a non-hardware category — `ExtractQuoteJob` may set `category` to `'options'` (plural). One-line off-by-pluralisation. Worth verifying with `ExtractQuoteJob::classifyItemType` source.

---

## 4. MINI-O&M

### 4.1 Where it lives

| File | Role |
|------|------|
| `app/Http/Controllers/MiniOmController.php` | Single-action controller — GET → PDF, no DB write |
| `app/Services/MiniOmBuilderService.php` | Pure aggregator — bundles confirmed device labels + quoted hardware + photos + signoffs |
| `resources/views/pdf/mini-om.blade.php` | Blade template |

The Mini O&M is **a separate, lightweight pipeline**, distinct from the heavyweight `OmManual` flow. It writes nothing to the `om_manuals` table; it renders a fresh PDF on each `GET /projects/{project}/mini-om/pdf`. Filename prefixed `mini-om-`.

### 4.2 Comparison checklist

| Aspect | Survey/RAMS | Mini-O&M | Verdict |
|--------|-------------|----------|---------|
| Canonical `room_overviews` read | 4-key shape | Reads `extracted_data['room_overviews']` first then falls back to `rooms[*].overview → rooms[*].works_summary` at `MiniOmBuilderService.php:285-320`. Tolerates string-keyed and array-shaped variants | **Best-in-class fallback chain** — explicitly handles both shapes, reads canonical `overview` + `works_summary` |
| Owner/contact display-time patch | `RamsDisplayPatchService::patch()` | Reads `$project` columns directly + `$project->owner?->name` at `MiniOmBuilderService.php:132-158`. Lighter than RamsDisplayPatchService but compatible | Partial — minimal personnel handling, no SHIPEMAIL leak guard |
| Equipment classifier honoured | Yes | Filters `$line['category'] === 'hardware'` at `MiniOmBuilderService.php:377-378` and `:587`. Honours ExtractQuoteJob output | Yes |
| Dead-path fields removed | yes | Reads `room_overviews` as a string-keyed map (`'Meeting Room' => 'overview text'`) at line 294-300, plus the array-shape fallback. The string-keyed map shape doesn't appear anywhere else in the codebase — likely dead. Worth a deferred deep-dive | Partial — primary path may target a never-emitted shape |
| Structural guard test | yes | None found via `grep MiniOm tests/` | Missing |
| DOCX/PDF parity | DOCX brought to parity | N/A — PDF only, no DOCX path | N/A |

### 4.3 Specific gaps

**F-MINI-01 (low severity, suspect dead path):** `MiniOmBuilderService::scopeSentenceForRoom()` lines 294-300 iterate `$extracted['room_overviews']` as a string-keyed map (`foreach ($extracted['room_overviews'] as $key => $val) { if (lower($key) === $needle) return $val; }`). The actual emitter (`QuoteParserService:1919-1925` and `ExtractQuoteJob:308-310`) writes `room_overviews` as an indexed list of `{room, overview, works_summary, solution_type_id, summary}` objects — never as a name-keyed string map. So this primary branch likely never fires, and Mini-OM always falls through to the secondary branch (which DOES work). Recommend: delete the primary branch, or convert it to read the canonical indexed shape.

**F-MINI-02 (low severity, no guard test):** No tests at `tests/**/*MiniOm*`. The pipeline ships any new failure to production. Phase 22.1's guard pattern suggests adding `MiniOmBuilderRoomNarrativeTest` that locks scope sentence resolution.

**F-MINI-03 (passing observation, no fix needed):** Mini-O&M's `worksDescription` resolution at `MiniOmBuilderService.php:136-138` reads `$project->works_description ?? $package?->works_description`. Phase 22.1 D-02 deprecated `ProjectPackage.works_description` (audit Cluster 3) but left it for a future phase. Mini-OM is the last reader of that column. When 22.2 ships, this line needs updating.

---

## 5. Summary

**Worksheet parity score: 5 of 8 fully aligned / 2 partial / 1 missing.** Worksheet is generally healthy. Owner/contact display-time refresh is missing (will diverge from RAMS on stale projects). DOCX/web parity is asymmetric — show blade is missing fields the DOCX renders. Reads `scope` and `description` as legacy fallback (dead after 22.1, but harmless on new data because `overview` catches everything).

**O&M parity score: 1 of 8 fully aligned / 3 partial / 4 broken or missing.** O&M is in the worst state of the three:
- **F-OM-01 is production-blocking** for any project re-imported after Phase 22.1 shipped.
- F-OM-02 makes the DOCX a markedly worse document than the PDF for the same data.
- No structural guard test means future schema changes have no safety net.
- The legacy `OmManualService` is parallel-shadow code worth auditing for removal.

**Mini-O&M parity score: 3 of 6 aligned / 3 minor or N/A.** Mini-OM has the best fallback chain of the three — explicitly handles legacy and canonical shapes. The string-keyed-map primary branch is likely dead and the pipeline lacks any test coverage.

### Top 3 highest-leverage findings

1. **F-OM-01 — production fix.** One-line change in `OmManualGeneratorService.php:276`. Unblocks O&M generation on every post-22.1 project. **Critical, ship today.**
2. **F-OM-02 — DOCX/PDF narrative parity.** ~30-60 lines in `OmManualDocxService` to add the Per-Room System Overview section. Mirrors today's RAMS DOCX-to-PDF parity work conceptually.
3. **F-WS-02 — worksheet web/DOCX parity.** Blade-only fix in `resources/views/worksheets/show.blade.php` to render `works_summary_bullets` and `room_works_description`. Users currently see a degraded version of the worksheet on the web view.

### Estimated total work to bring all pipelines to parity

- F-OM-01: 15 min (one-line fix + smoke test)
- F-OM-02: 1-2 hours (DOCX section ports + verification)
- F-OM-03: 30 min (test scaffolding)
- F-OM-04: 1 hour (resolver helper)
- F-OM-05: 30 min (dead-code audit)
- F-OM-06: 15 min (off-by-pluralisation)
- F-WS-01: 15 min (dead-branch removal)
- F-WS-02: 1 hour (show blade)
- F-WS-03: deferred — overlaps with Phase 22.2 cross-document propagation
- F-WS-04: 1-2 hours (WorksheetDisplayPatchService)
- F-MINI-01: 15 min (dead-branch removal)
- F-MINI-02: 30-45 min (guard test)

**Total: 6-9 hours** to bring Worksheet and O&M to Phase 22.1 parity. Roughly half of that (F-OM-01 + F-OM-02 + F-WS-02) gets the biggest impact and could ship as a single follow-up phase.

---

## 6. Out of scope / future work

- **`OmManualService.php` (the old PDF→AI service) is probably dead.** Verify and delete with a guard test in the same style as `Phase22_1InvariantGuardTest::test_sc3_dead_paths_removed`.
- **`ProjectPackage.works_description` column deprecation** carries through to Mini-OM (F-MINI-03) — already on the Phase 22.2 roadmap.
- **Worksheet does NOT consume `ExtractQuoteJob`'s `professional_service` classifier in the bullet conversion path** — `WorksheetGeneratorService::generateContent()` reads room narratives independently of equipment classification. Not a defect, but a parallel concern worth noting.
- **Three pipelines, three different read paths for the same data.** Mini-OM reads `extracted_data['room_overviews']` as a map, OmManual reads it as an indexed list keyed on `description`, Worksheet reads it as an indexed list keyed on `description → overview → scope`, RAMS reads it through a single normaliser. A future phase consolidating these read paths into one `RoomNarrativeResolver` service would eliminate an entire class of "this pipeline drifted" bugs.
- **`MiniOmBuilderService` and `WorksheetGeneratorService` both backfill bullets and write back to `$package` — without coordination.** A user generating worksheet + mini-OM in quick succession could race the writes. Low probability but worth a future Phase 22.2 lock note.
- **Engineer feedback round-trip.** `WorksheetDocxService` reads engineer feedback per room at `WorksheetDocxService.php:59-65` (`loadEngineerFeedbackByRoom`). OmManual doesn't pick up engineer feedback at all — Phase 4 / Phase 6 of OM doesn't mention it. Not a Phase 22.1 parity issue; flag for a future OM polish phase.
