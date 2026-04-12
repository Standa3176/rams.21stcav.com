# Project State

**Last updated:** 2026-04-12

## Current Phase

None — all phases complete. Ready for Phase 6 planning.

## Completed Phases

| Phase | Name | Status |
|-------|------|--------|
| 01 | Project Layer & Data Foundation | Complete (gaps resolved 2026-04-11) |
| 02 | QuoteWerks SQL Import | Complete |
| 03 | Survey Data Integration | Complete |
| 04 | Document Generators | Complete 2026-04-11 |
| 05 | Project Content Pack | Complete 2026-04-12 |

## Quick Tasks Completed

| ID | Description | Date |
|----|-------------|------|
| 260411-9ov | Fix Phase 1 gaps — confirm all 7 gaps resolved, fix auto-advance backward regression | 2026-04-11 |
| 260411-en6 | Remove manual project creation — redirect to quote import | 2026-04-11 |
| 260411-f5v | Restore original quote parser (2903) — remove hybrid AI extraction pipeline | 2026-04-11 |
| 260411-fct | Fix quote pipeline review issues (H-02,H-03,H-04,L-02,M-01,M-04) + part numbers with # and . | 2026-04-11 |
| 260411-g5w | Wire QuoteExtractorService as primary PDF extraction path — replace local parser with Claude document vision | 2026-04-11 |

## Accumulated Context

### Roadmap Evolution

- Phase 1 added: Project Layer & Data Foundation
- Phase 2 added: QuoteWerks SQL Import
- Phase 3 added: Survey Data Integration
- Phase 4 added: Document generators — Worksheet, O&M Manual, and Cable Schedule generated from ProjectDataService canonical data
- Phase 5 added: Project Content Pack — single AI call generates scope of works, works overview, and per-room prose descriptions stored in extracted_data for all documents to consume
- Phase 6 added: RAMS & Document Quality — use content pack to make RAMS scope sections, method statements, and O&M operating procedures fully project-specific

### Key Decisions (Cross-Phase)

- AI is only used for method statement structuring — never invents scope, equipment, or design
- All document content traces to quote data, survey data, or reviewed inputs
- Merge priority: reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults
- One survey per project (enforced via superseded_at)
- ProjectDataService is the single canonical data source for all generators
- Cable type inference is deterministic keyword matching — no AI (04-03)
- CableScheduleXlsxService::build() updates filename but not status — BuildCableScheduleJob sets STATUS_DRAFT explicitly after build() (04-03)
- Route ordering: literal segments (generate-from-project) registered before Route::resource wildcard to prevent capture (04-03)
- Three-state generate button (Generate → Generating spinner → Download) driven by $entry['generate_route'] presence; legacy GET-link branch retained for RAMS and Survey (04-04)
- Content pack auto-generates at ExtractQuoteJob completion (best-effort try/catch): room description + summary from RoomOverviewSummaryPrompt, works_overview + scope_of_works from ScopeOfWorksPrompt (05)
- MethodStatementService::buildScope() prefers scope_of_works from reviewed_data before falling back to tasks/classifier/equipment chain (05-04)
- All AI prompts enforce British English spelling (05-03)
