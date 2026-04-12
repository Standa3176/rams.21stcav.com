# Project State

## Current Position
- **Phase:** 6
- **Last completed:** quick task 260412-l6c — add-9-new-av-solution-types-with-survey-checklists

## Last Session
- **Stopped at:** Completed quick task 260412-l6c: add-9-new-av-solution-types-with-survey-checklists
- **Date:** 2026-04-12

## Key Decisions
- QuoteWerksRepository: DB::raw() removed — plain select([])/where()/orderByDesc() used throughout
- MethodStatementFallbackTest: 6-phase fallback confirmed; AI mock via container binding
- QuoteParserService dedup: keep first occurrence qty (not summed)
- approve action: saves data only, does not dispatch generation job
- Upload redirect: rams.processing (not projects.show)

## Known Deferred Items
- QuoteParserServiceTest::tagged_parser_handles_qtvend — prepared_by parsing failure (pre-existing)
- QuoteProjectResolutionTest::project_show_page_is_forbidden_for_another_user — auth policy returns 200 (pre-existing)
