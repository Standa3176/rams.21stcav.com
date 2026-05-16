---
status: awaiting_human_verify
trigger: "Project package 124 review form Section 3 shows merged hardware row with 'ARTOESCEND' marker leak, missing FW-85BZ40L row, and DELIVERY mis-categorised as Consumables. Investigate which extraction/normalisation/dedup step is doing this and whether 1 or 3 root causes."
created: 2026-05-14T20:30:00Z
updated: 2026-05-14T21:15:00Z
---

## Current Focus

hypothesis: Defect 1 & 2 share a single root cause (third PARTDESC marker-corruption family) already fixed in working tree but uncommitted/unverified. Defect 3 is a separate code-path question: did this package's data come from ExtractQuoteJob (parser → classifyItemType prefix list includes DELIVERY → consumable) or from QuoteImportService::reimport (Claude AI → no category set → normaliseEquipmentCategory defaults to hardware)? Symptoms point to ExtractQuoteJob but earlier tinker keys point to reimport. Need fresh evidence before fixing Defect 3.
test: Run new PARTDESC normaliser tests + full invariant guard suite to confirm Defect 1/2 patch is safe.
expecting: New 4 tests pass; 17 v1.3 / Phase 23 guards pass; 2 pre-existing failures unchanged.
next_action: Wait for user confirmation on (a) commit Defect 1+2 fix now, (b) defer or fix Defect 3, (c) re-run tinker to capture current extracted_data state before deploying.

## Symptoms

expected: |
  Section 3 "Equipment" on /project-packages/124/review shows 8 rows:
    Hardware:
      - QTY 1, PART FW-85BZ40L, DESC "21st Engineering AV Team — Sony 85\" Anti Glare Display"
      - QTY 1, PART BT9910/B,   DESC "XL Heavy Duty Universal Flat Screen Wall Mount with Tilt"
      - QTY 1, PART PA20,       DESC "Yealink PA20 Wireless Sharing Dongle"
    Services:
      - DELIVERY (Hardware Delivery)
      - CONSUMABLES (Cables & Consumables)
      - SSVOTHER (Site Survey)
      - RAMS (RAMS)
      - INSTALL2 (Installation Labour)

actual: |
  Hardware shows ONE row:
    - QTY 1, PART BT9910/B,
      DESC "XL Heavy Duty Universal Flat Screen Wall Mount with Tilt 5 ARTOESCEND 1.00 ~ PA20 Yealink PA20 Wireless Sharing Dongle"
  FW-85BZ40L row is MISSING entirely; BT9910/B and PA20 are MERGED.
  Substring "ARTOESCEND" is OCR-garbled "PARTDESCEND" marker.

  Services section shows ONE row:
    - DELIVERY / "Hardware Delivery" with CATEGORY="Consumables" (mismatch).
  (Cables empty — correct.)

errors: None visible.
reproduction: Visit https://rams.21stcav.com/project-packages/124/review, scroll to Section 3.
started: After deploy of e685c58 (room_summaries→room_overviews seed) earlier today.

## Eliminated

- hypothesis: Controller `normaliseEquipmentCategory()` (ProjectPackageReviewController.php:1152-1188) mis-classifies DELIVERY as Consumables.
  evidence: Traced the function with name="Hardware Delivery", part_number="DELIVERY". $text="hardware delivery delivery". `consumable/fixing/.../tape/...` keywords do NOT match "hardware delivery". Falls through to default 'hardware'. Cannot produce category=consumables from controller alone.
  timestamp: 2026-05-14T21:05:00Z

- hypothesis: The AI extractor (QuoteExtractorService) emits a `category` field that mis-routes DELIVERY.
  evidence: `grep -n 'category' app/Core/AI/Prompts/QuoteExtractionPrompt.php` → no matches. The AI line_items do NOT carry a category. (Symptoms tinker dump confirms.)
  timestamp: 2026-05-14T21:06:00Z

- hypothesis: Defect 1 (merged hardware row + ARTOESCEND text) is caused by a controller bug.
  evidence: Controller's row projection (lines 95-120) iterates `$rawEquipment` element-by-element, no merge logic. The "5 ARTOESCEND 1.00 ~" pattern in the rendered description is verbatim QuoteParserService internal token output. Only QuoteParserService can produce that string in the data.
  timestamp: 2026-05-14T21:08:00Z

## Evidence

- timestamp: 2026-05-14T20:38:00Z
  checked: Read app/Services/QuoteParserService.php lines 1691-1873 (tuple regex + equipment build).
  found: |
    Tuple regex (line 1695):
      /PARTSTART\s*(.*?)\s*PARTEND[\s~]*PARTDESCSTART\s*(.*?)\s*p.?ARTDESCEND[\s~]*QTYSTART\s*(.*?)\s*(?:QTYEND|QTVEND)/is
    Only tolerates canonical PARTDESCEND or paARTDESCEND. With s flag + non-greedy `(.*?)`, when the FIRST closer is garbled (e.g. "ARTOESCEND"), capture keeps consuming text until the next canonical closer, swallowing intermediate tuples.
  implication: |
    Reconstructed scenario for package 124:
      Row 1 (FW-85BZ40L Sony):   closer garbled → match fails OR is consumed into Row 2 capture
      Row 2 (BT9910/B Tilt):     closer garbled → captures spill-over to PA20 row's canonical closer
      Row 3 (PA20 Yealink):      closer canonical → terminates Row 2's expanded match
    Result: FW-85BZ40L disappears, BT9910/B description contains PA20 content + "ARTOESCEND 1.00 ~" debris.

- timestamp: 2026-05-14T20:40:00Z
  checked: git show 3e936b0 (OVERVIEW family fix) and 2c7d1fa (QUOTENUM family fix).
  found: Both prior fixes follow the same pattern — extend `normaliseQuoteWerksMarkers()` at parse() entry-point with a conservative anchored regex. The PARTDESC family fits the same shape: anchor on `(START|END)` suffix and accept a short garbled prefix.
  implication: Same fix template applies. No risk to v1.3 invariants.

- timestamp: 2026-05-14T21:00:00Z
  checked: git diff app/Services/QuoteParserService.php tests/Unit/Rams/QuoteParserServiceTest.php
  found: |
    A prior session has already implemented the fix:
      1. Lines 1576-1580 of QuoteParserService.php — new PARTDESC regex in normaliseQuoteWerksMarkers():
           /\bP?AR[Tt]?[DOdo]ESC(START|END)\b/i  →  PARTDESC$1
         Accepts P-dropped form, inner-D→O substitution, and the canonical token.
      2. Four new regression tests in QuoteParserServiceTest.php (lines 1105-1232):
           - test_garbled_partdescend_artoescend_does_not_merge_rows
           - test_partdescend_pdropped_only_form_artdescend_is_normalised
           - test_partdescend_donly_substitution_partoescend_is_normalised
           - test_partdesc_normalisation_preserves_legitimate_prose
    The changes are uncommitted (not yet on `live`).
  implication: Defect 1 and Defect 2 fix is ready. Just needs verification + commit + deploy.

- timestamp: 2026-05-14T21:08:00Z
  checked: |
    php -l app/Services/QuoteParserService.php
    php -l tests/Unit/Rams/QuoteParserServiceTest.php
    phpunit --filter (4 new PARTDESC tests)
    phpunit tests/Unit/Rams/QuoteParserServiceTest.php (full suite)
    phpunit tests/Feature/Drawings/Phase23InvariantGuardTest.php tests/Feature/Drawings/V13SurfacesUntouchedTest.php
  found: |
    - php -l clean on both files.
    - 4 new PARTDESC tests: PASS (4 tests, 27 assertions).
    - Full QuoteParserServiceTest: 84 pass, 2 FAIL.
    - 17 v1.3 / Phase 23 invariant guards: ALL PASS.
    - Stashed the change and re-ran the 2 failing tests against baseline e685c58: SAME 2 FAILURES.
      Pre-existing failures, NOT regressions from the PARTDESC fix:
        * test_tagged_parser_handles_qtvend_variant_and_rejects_time_like_part_number
        * test_tagged_equipment_deduplicates_same_area_and_part_number
  implication: PARTDESC fix is safe. v1.3 / Phase 23 surfaces untouched. Pre-existing test failures are not caused by this work and are out of scope.

- timestamp: 2026-05-14T21:10:00Z
  checked: app/Jobs/ExtractQuoteJob.php classifyItemType() at lines 420-509
  found: |
    `consumablePrefixes` array at line 454-458 contains the literal string 'DELIVERY' as a startsWith prefix match. Part numbers starting with "DELIVERY" are classified as `consumable` and the merge at line 349-355 maps that to category='consumables'. This category value is then written directly into `extracted_data['equipment_list']`, `equipment`, and `line_items`.
  implication: |
    Defect 3 (DELIVERY → Consumables) is a deliberate classification rule in ExtractQuoteJob, NOT a bug in the review controller. Fix options:
      A) Move 'DELIVERY' from consumablePrefixes → servicePrefixes (matches user expectation; simple one-line change).
      B) Leave classifier alone, override at controller's normaliseEquipmentCategory level.
      C) Defer — minor cosmetic bug; PM can change the dropdown manually before approval.

- timestamp: 2026-05-14T21:12:00Z
  checked: Symptoms tinker dump `array_keys($p->extracted_data)` returned `qw_number, client_name, ..., line_items, room_summaries, ..., equipment_list, cable_hints, quote_reference`. NOTABLY ABSENT: `equipment`, `hardware_list`, `worksheet_items`, `meta` — all keys that ExtractQuoteJob::mergeParsedQuoteData() writes.
  found: |
    The tinker output corresponds to QuoteImportService::reimport (Claude PDF-vision direct), NOT ExtractQuoteJob (parser path).
    BUT the rendered screenshot shows "ARTOESCEND" garble + DELIVERY=Consumables — these are ONLY produced by QuoteParserService + ExtractQuoteJob::classifyItemType().
  implication: |
    Two state mismatch. Either:
      (i)  The user uploaded the quote a second time via /quote-import (ExtractQuoteJob path) AFTER the tinker dump → extracted_data now contains parser-shape data.
      (ii) The screenshot was taken before the tinker dump and the data was later re-extracted via reimport.
      (iii) Two different packages or revisions are being compared.
    We need a fresh tinker dump to confirm which state is live before applying Defect 3 fix.

## Resolution

root_cause: |
  PRIMARY (Defects 1 + 2 — merged hardware row + missing FW-85BZ40L + "ARTOESCEND" leak):
    Third corruption family of QuoteWerks marker tokens. PDF text extractors
    (smalot/pdfparser, pdftotext) occasionally garble the closing PARTDESCEND
    token to "ARTOESCEND" (leading P dropped + internal D→O substitution from
    font glyph subsetting). The tuple-extraction regex in QuoteParserService::
    parseTagBased() (line 1695) only tolerates the canonical form and its known
    paARTDESCEND variant — so the non-greedy capture for the description block
    keeps consuming text until the NEXT canonical PARTDESCEND, merging two or
    more adjacent equipment rows into one mangled row and silently dropping
    any rows in between. Today's two prior commits (3e936b0 OVERVIEW family,
    2c7d1fa QUOTENUM family) follow the same fix template but did not cover
    PART-family markers.

  SECONDARY (Defect 3 — DELIVERY classified as Consumables):
    ExtractQuoteJob::classifyItemType() (app/Jobs/ExtractQuoteJob.php:455) lists
    'DELIVERY' as a consumable prefix. When that path runs, the resulting
    equipment row carries category='consumables' which the review form renders
    verbatim. The controller's own normaliseEquipmentCategory() would default
    DELIVERY to 'hardware' (still wrong per user) — but it's bypassed because
    ExtractQuoteJob explicitly sets the category.

fix: |
  IMPLEMENTED (uncommitted in working tree):
    - app/Services/QuoteParserService.php — add PARTDESC normaliser to
      `normaliseQuoteWerksMarkers()`. Conservative regex:
        /\bP?AR[Tt]?[DOdo]ESC(START|END)\b/i  →  PARTDESC$1
      Anchors on canonical (START|END) suffix so legitimate prose words
      ("descend", "ascend", "transcend") never match.
    - tests/Unit/Rams/QuoteParserServiceTest.php — 4 new regression tests
      (ARTOESCEND merge, ARTDESCEND P-dropped, PARTOESCEND D-substituted,
      legitimate-prose preservation).

  PENDING USER DECISION:
    Defect 3 (DELIVERY classification) — see Evidence 2026-05-14T21:10:00Z
    for fix options. Recommended: Option A (move 'DELIVERY' from
    `consumablePrefixes` to `servicePrefixes` in ExtractQuoteJob.php) IF
    Defect 3 is in scope. Add unit test asserting `classifyItemType('DELIVERY',
    'Hardware Delivery') === 'professional_service'`. Two-line code change,
    minimal blast radius (one classifier function, no v1.3 surfaces).

verification: |
  - php -l clean on both modified files.
  - 4 new PARTDESC regression tests pass (27 assertions).
  - 17 v1.3 / Phase 23 invariant guard tests pass — no v1.3 surfaces touched.
  - 2 pre-existing test failures in QuoteParserServiceTest are NOT regressions
    (confirmed by stash-and-re-run against e685c58 baseline).

files_changed:
  - app/Services/QuoteParserService.php (PARTDESC normaliser, +18 LOC)
  - tests/Unit/Rams/QuoteParserServiceTest.php (4 new tests, +130 LOC)
  - .planning/debug/equipment-merge-and-missing-rows.md (this file)
