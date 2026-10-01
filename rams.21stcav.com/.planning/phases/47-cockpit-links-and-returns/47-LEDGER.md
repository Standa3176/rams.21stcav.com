# Phase 47 Ledger — Cockpit: Links, Visits and Returns

**Plan:** 47-05 (Task 2). Written after running every gate listed below for real,
foreground, redirected to a file, one suite per invocation — none of the numbers
below are inherited from a prior plan's SUMMARY.md.

Status: **Tasks 1 and 2 of 47-05 are done. Task 3 (the blocking human checkpoint)
is OUTSTANDING.** This phase is NOT complete.

---

## Gate results, measured 2026-10-01

| Gate | Command | Result |
|---|---|---|
| Cockpit feature suite | `gate-46.ps1 -Path tests/Feature/Cockpit` | **460 passed, 0 failed, 9164 assertions** (was 446/0/9064 before this plan's Task 1; +14 tests, +100 assertions — exactly `CockpitLinksAndReturnsEndToEndTest`) |
| Cockpit unit suite | `gate-46.ps1 -Path tests/Unit/Cockpit` | **114 passed, 0 failed, 1203 assertions** — unchanged; this plan edits no presenter |
| Worksheets feature suite | `gate-46.ps1 -Path tests/Feature/Worksheets` | **265 passed, 0 failed, 2329 assertions** — unchanged since Plan 47-02 |
| D-06 baseline | `gate-46.ps1 -Baseline` | **159 passed, 2 skipped (pre-existing, missing ext-imagick), 0 failed, 396 assertions** — meets `>= 159 passed AND 0 failed`, never equality against 161 |
| Protected-file hashes | `gate-46.ps1 -Hashes` | **All three byte-identical to `4abd2b24`**: `layouts/app.blade.php` `9ED63C4C...`, `resources/css/app.css` `EDAD1982...`, `tailwind.config.js` `73BB8AD6...` — exact match, confirmed by `Get-FileHash` against working-tree bytes, not `git show` |
| This plan's own test, scoped | `gate-46.ps1 -Path tests/Feature/Cockpit/CockpitLinksAndReturnsEndToEndTest.php` | **14 passed, 0 failed, 100 assertions** |

No suite failed. No suite was skipped except the two pre-existing `ext-imagick`
skips the baseline gate's own header names as not defects.

---

## Requirement ledger

Columns: **Status** | **Evidence** (named test methods, never "tested") | **For the checkpoint**.

| ID | Status | Evidence | For the checkpoint |
|---|---|---|---|
| LNK-01 | DELIVERED | `CockpitLinkCardTest` (16 tests, Plan 47-01) renders all four link states (survey-with-link, worksheet-with-link-and-revoke, no-document, rams/om-null); `CockpitLinksAndReturnsEndToEndTest::test_walk_a_the_survey_link_is_visible_selectable_text_with_its_state`, `::test_walk_a_the_worksheet_link_is_visible_with_a_working_revoke_and_old_token_dies`, `::test_walk_a_rams_and_om_render_no_link_card_at_all` re-prove it through real HTTP on the Overview tab | Q1 in `how-to-verify`: can you select/copy it and would you send it right now? |
| LNK-02 | DELIVERED | `CockpitLinkPresenter`'s `stateForSurvey()`/`stateForWorksheet()`, unit-covered inside `CockpitLinkCardTest`; `CockpitLinksAndReturnsEndToEndTest::test_walk_a_the_survey_link_is_visible_selectable_text_with_its_state` asserts the literal state sentence ("Issued — awaiting the engineer") renders beside the link | Q2: does the state sentence tell you what you'd want to know before sending it? |
| LNK-03 | DELIVERED | `CockpitLinkCardTest`'s revoke-through-HTTP test (Plan 47-01); `CockpitLinksAndReturnsEndToEndTest::test_walk_a_the_worksheet_link_is_visible_with_a_working_revoke_and_old_token_dies` proves the OLD token is gone, a DIFFERENT new one shown, and the `worksheets` row count unchanged (update, not a second create) | Q3: press it on a real/test worksheet — old link dies, new one appears |
| LNK-04 | DELIVERED | `CockpitLinkCardTest::test_opening_site_survey_with_a_live_survey_shows_the_link_above_visits` (Plan 47-01) proves BOTH halves — the "no way to revoke" sentence present AND no revoke `<form>` for that module; `CockpitLinksAndReturnsEndToEndTest::test_walk_a_the_survey_link_is_visible_selectable_text_with_its_state` re-proves the sentence through HTTP | Q4: is the stated absence of a survey revoke acceptable? |
| LNK-05 | DELIVERED | `PublicWorksheetRoomCompleteLeakTest::test_room_complete_audit_stamp_never_renders_for_realistic_shape` + `::test_room_complete_audit_stamp_never_renders_for_legacy_shape` (Plan 47-02); `CockpitLinksAndReturnsEndToEndTest::test_walk_d_the_room_complete_audit_stamp_never_renders_on_the_public_engineer_link` re-proves it on a THIRD, freshly-seeded fixture, non-vacuously (`assertSame($completedBy, $worksheet->roomCompletedBy(...))` before the render) | none — fixed and re-proven; not itself a checkpoint question |
| RV-01 | DELIVERED | `CockpitReturnedTabTest` (19 tests, Plan 47-03), specifically `::test_the_calm_order_renders_room_answers_gallery_serials_and_signoff_for_one_visit` and `::test_the_calm_order_renders_the_survey_room_its_answer_and_before_bucket`; `CockpitLinksAndReturnsEndToEndTest::test_walk_b_the_worksheet_returned_tab_shows_the_calm_order_and_never_the_three_banned_columns` and `::test_walk_b_the_survey_returned_tab_shows_room_cards_with_notes_and_answers` re-prove both sourced shapes through HTTP | Q5 (RV-08, below) covers the same tab |
| RV-02 | DELIVERED | `CockpitReturnedTabTest::test_evidence_reads_live_so_an_edited_room_note_shows_on_the_next_call` (Plan 47-03) edits the engineer's own record between two calls and asserts the second call changed; not re-walked in this plan's Task 1 (the plan's own task list names this as 47-03's proof, not 47-05's) | none |
| RV-03 | DELIVERED | `CockpitReturnedTabTest::test_no_capture_address_or_client_agent_is_ever_rendered` (Plan 47-03); `CockpitLinksAndReturnsEndToEndTest::test_walk_b_the_worksheet_returned_tab_shows_the_calm_order_and_never_the_three_banned_columns` seeds a THIRD realistic value for all three banned columns (`device_label_photos.captured_by`, `worksheet_signoffs.ip_address`, `::user_agent`) and asserts via `assertSame`/`assertStringContainsString` on the raw model that each is genuinely present before asserting its absence from the render | none |
| RV-04 | DELIVERED | `CockpitReadOnlyFenceTest::test_opening_the_returned_tab_and_following_its_handoff_link_writes_nothing` (Plan 47-03); `CockpitLinksAndReturnsEndToEndTest::test_walk_b_the_zip_link_opens_contains_room_bucket_entries_and_writes_nothing` opens a REAL `ZipArchive` on the downloaded bytes, asserts a `Boardroom/(after|label)/` entry exists, and re-counts all 13 `WRITE_SURFACE_TABLES` rows before/after | none |
| RV-05 | DELIVERED | `CockpitVisitActionsTest::test_the_returned_tabs_four_control_cap_holds_through_http_per_visit`, `::test_the_same_visit_offers_four_controls_on_returned_and_zero_on_overview` (Plan 47-04); `CockpitLinksAndReturnsEndToEndTest::test_walk_c_returned_visit_offers_all_four_capped_at_four` and `::test_walk_c_accepting_lands_back_on_returned_and_add_note_now_renders` re-prove render + a real Accept POST landing back on the Returned tab | Q6: accept a visit, confirm you land back on Returned not Overview |
| RV-06 | DELIVERED | `CockpitVisitActionsTest::test_a_reconstructed_visit_offers_zero_controls_on_the_returned_tab` (Plan 47-04); `CockpitLinksAndReturnsEndToEndTest::test_walk_c_a_reconstructed_visit_offers_zero_controls_but_its_evidence_still_renders` builds a fresh backfilled-from-worksheet fixture with a real photo and sign-off and asserts the evidence renders while `cav-visit__control`/`cav-visit__control--quiet` count is exactly 0 | Q7: find/seed a reconstructed visit — evidence shows, nothing to press |
| RV-07 | DELIVERED | `CockpitVisitActionsTest`'s cap-sweep tests (Plan 47-04) plus `CockpitReadOnlyFenceTest`'s GET row-count invariance tests; `CockpitLinksAndReturnsEndToEndTest::test_walk_b_the_zip_link_opens_contains_room_bucket_entries_and_writes_nothing` and `::test_walk_c_returned_visit_offers_all_four_capped_at_four` re-prove no edit affordance exists and the cap holds at exactly 4, never a 5th | none |
| RV-08 | **DELIVERED BUT UNSETTLED** | Structural half asserted: `returned-tab.blade.php`'s docblock-pinned order (state → ZIP link → rooms → gallery → serials → sign-off) proven in sequence by `CockpitLinksAndReturnsEndToEndTest::test_walk_b_the_worksheet_returned_tab_shows_the_calm_order_and_never_the_three_banned_columns` (`strpos` ordering assertions); one hand-off link, controls only at the bottom via `visit-row`, no lightbox/no per-photo chrome/no `<script>` — proven by `CockpitReadOnlyFenceTest`'s whole-region no-`<script>`/no-handler-attribute sweep, which covers the Returned tab render since Plan 47-03 widened `TABS`. The "does it FEEL calm" half is explicitly not something any assertion here can settle (same treatment VL-11 and DC-08 already got) | **Q5, the actual question — is it calm? One link at top, one control area at bottom, nothing crowded. Say which part feels busy if any does.** |
| VL-05 | DELIVERED | Pre-existing `CockpitVisitActionsTest::test_accepting_a_returned_visit_records_who_and_when` etc. (Phase 46); re-surfaced via the Returned tab by Plan 47-04 and re-proven reachable by `CockpitLinksAndReturnsEndToEndTest::test_walk_c_accepting_lands_back_on_returned_and_add_note_now_renders` | Q6 (shared with RV-05) |
| VL-06 | DELIVERED | Pre-existing `CockpitVisitActionsTest::test_sending_back_records_the_reason_and_when` etc. (Phase 46); the disclosure link re-surfaced on Returned by Plan 47-04, proven linked by `CockpitReadOnlyFenceTest::test_create_visit_stays_unsurfaced_while_the_four_visit_acts_and_evidence_gets_are_linked_on_returned`'s per-act section | none — the POST mechanics are unchanged by this phase |
| VL-07 | DELIVERED | `CockpitOfficeNoteAndSnagTest` (Phase 46 write-path coverage); disclosure re-surfaced and proven linked by `CockpitReadOnlyFenceTest::test_create_visit_stays_unsurfaced_while_the_four_visit_acts_and_evidence_gets_are_linked_on_returned` ('note' act, Plan 47-04) | none |
| VL-08 | DELIVERED | `CockpitOfficeNoteAndSnagTest` (Phase 46 write-path coverage); disclosure re-surfaced and proven linked by the same `CockpitReadOnlyFenceTest` section ('snag' act, Plan 47-04) | none |
| VL-11 | DELIVERED | `CockpitVisitActionsTest::test_the_returned_tabs_four_control_cap_holds_through_http_per_visit` and `::test_the_four_control_cap_is_judged_per_row_not_per_page` (Plan 47-04, judged per-row across the full type×state×backfilled matrix); `CockpitLinksAndReturnsEndToEndTest::test_walk_c_returned_visit_offers_all_four_capped_at_four` re-proves the cap is exactly 4, never a 5th, through this plan's own fresh HTTP walk | Q6 again, informally — the cap is what keeps the row simple |

**17 of 17 requirement IDs reconciled. None recorded NOT DELIVERED.** Every one
of them traces to at least one named test that actually executes; RV-08 alone
carries the DELIVERED BUT UNSETTLED qualifier, for the reason stated in its row.

---

## The fence/count ledger

Read directly off the live files in this repo on 2026-10-01 — not inherited from
any plan's SUMMARY.md.

| Count | Before (start of 47-05) | After | Moved? | Reason |
|---|---|---|---|---|
| `CockpitReadOnlyFenceTest::FORBIDDEN_MARKUP` | 2 | **2** | No | Unchanged all phase — `'<select'` and `'<script'` are rulings, not phase-owned entries |
| `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES` | 19 | **19** | No | Counted by hand against the live array: 14 phase-owned entries (`Book another survey`…`Export CSV`) + `Create visit` (stays Unsurfaced) + `Upload files` + `Assign parts` + `Close snag` + `Mark as sent` = 19. `'Download'` (47-03) and `'Add note'` (47-04) already left this list before 47-05 started; this plan lifts nothing further |
| `CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES` | 9 | **9** | No | Unchanged all phase — no JavaScript shipped |
| `CockpitReadOnlyFenceTest::WRITE_SURFACE_TABLES` | 13 | **13** | No | Unchanged all phase; re-counted in this plan's own `test_walk_b_the_zip_link_opens_contains_room_bucket_entries_and_writes_nothing` (`assertCount(13, $tables)`) against the literal list this ledger also enumerates above |
| `ProjectCockpitController::TABS` | 4 | **4** | No (confirmed, not moved) | `['overview', 'files', 'notes', 'returned']` — grown to 4 by Plan 47-03; re-confirmed by reading the constant directly in `CockpitLinksAndReturnsEndToEndTest::test_the_fence_numbers_this_plan_depends_on_are_what_the_plan_says` |
| `ProjectCockpitController::ACTIONS` | 4 | **4** | No (confirmed, not moved) | `['generate', 'send-back', 'note', 'snag']` — grown to 4 by Plan 47-04; re-confirmed the same way |
| Judged document-form count (`CockpitReadOnlyFenceTest::test_every_form_in_the_region_carries_a_csrf_token`) | 6 | **6** | No | Unchanged — the worksheet revoke form (Plan 47-01) is the 6th and last form this phase adds; re-proven green in the full `tests/Feature/Cockpit` run above |

Every number above was read from the working file, not copied from a prior
plan's prose — the one exception being the judged-form count, whose assertion
(`assertSame(6, $checked)`) is confirmed passing in the full-suite gate run
rather than re-counted by hand, since the fence file itself states the
arithmetic (one per module + survey's supersede form + worksheet's revoke)
and re-deriving it independently would just repeat that same arithmetic.

---

## Hidden fence coupling swept for

Per the plan's own warning (both 47-03 and 47-04 found sibling test files with
hardcoded totals): `CockpitDocumentFormTest`, `CockpitRamsWizardTest`,
`CockpitTabPreservationTest`, `CockpitVisitActionsTest` were the four files
47-03/47-04 already found and fixed. The full `tests/Feature/Cockpit` run above
(460 passed, 0 failed) includes all four, unedited by this plan, and green — no
further hidden coupling was found. No other `assertCount`/`assertSame` against
`DEFERRED_AFFORDANCES`'s total, `FORBIDDEN_MARKUP`'s total, `TABS`, or `ACTIONS`
exists outside the files 47-03/47-04 already corrected (confirmed by the green
suite; a stale pin anywhere in that suite would show as a failure, not a pass).

---

## Deploy note

**`resources/css/cockpit.css` was NOT touched by any plan in this phase** — Plan
47-01 confirmed "No new CSS" for the link card, Plan 47-03 confirmed the Returned
tab reuses the pre-existing `.cav-returned__*` block (never deleted by 46.2-03),
and Plan 47-04 confirmed the four controls reuse `visit-row.blade.php`'s existing
`.cav-visit__actions`/`.cav-visit__control` rules from Phase 46. **No `npm run
build` is required for this phase's changes on deploy.**

No migration was needed or written anywhere in this phase (confirmed again by
this plan — nothing in Task 1's walk required one).

This plan makes no push and no deploy. It ends at local commits on
`feat/worksheet-classifier-universal`.
