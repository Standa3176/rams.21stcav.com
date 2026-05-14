---
status: awaiting_human_verify
trigger: "Project package 124 review form Section 3 shows only 1 hardware row (should be 3) and the row's description has garbled QuoteWerks marker 'ARTOESCEND' leaking into it. Sidebar bug to today's e685c58 room_overviews fix."
created: 2026-05-14T20:30:00Z
updated: 2026-05-14T21:05:00Z
---

## Current Focus

hypothesis: CONFIRMED — third corruption family in QuoteWerks marker tokens. OCR garbled `PARTDESCEND` → `ARTOESCEND` (P dropped, D→O). The closing-marker garble causes the tuple regex on QuoteParserService.php:1695 to non-greedy-skip past the garbled closer and consume the NEXT tuple's content, merging two rows.
test: Normaliser extension shipped + 4 new regression tests pass. Existing parser suite unchanged (2 pre-existing failures, unrelated).
expecting: After live deploy + tinker re-extract on package 124, all 3 hardware rows surface separately with clean descriptions.
next_action: Human UAT on /project-packages/124/review post-deploy. Package likely needs re-extract (touch updated_at, or click "Re-extract Quote") to clear the previously-polluted extracted_data['equipment'].

## Symptoms

expected: |
  Section 3 "Equipment" shows three Hardware rows:
    - QTY 1, PART FW-85BZ40L, DESC "21st Engineering AV Team — Sony 85\" Anti Glare Display"
    - QTY 1, PART BT9910/B,   DESC "XL Heavy Duty Universal Flat Screen Wall Mount with Tilt"
    - QTY 1, PART PA20,       DESC "Yealink PA20 Wireless Sharing Dongle"
  Plus Service rows (DELIVERY, CONSUMABLES, SSVOTHER, RAMS, INSTALL2).

actual: |
  Hardware shows ONE row:
    - QTY 1, PART BT9910/B, DESC "XL Heavy Duty Universal Flat Screen Wall Mount with Tilt 5 ARTOESCEND 1.00 ~ PA20 Yealink PA20 Wireless Sharing Dongle"
  FW-85BZ40L row is MISSING entirely; BT9910/B and PA20 are MERGED.
  Substring "ARTOESCEND" looks like OCR-garbled "PARTDESCEND" marker.
  Services section: DELIVERY row has CATEGORY="Consumables" (mismatch).

errors: None visible.
reproduction: Visit https://rams.21stcav.com/project-packages/124/review, scroll to Section 3.
started: After deploy of e685c58 (branch switch to feat/worksheet-classifier-universal) earlier today.

## Eliminated

(none yet)

## Evidence

- timestamp: 2026-05-14T20:35:00Z
  checked: Read app/Http/Controllers/ProjectPackageReviewController.php lines 74-87.
  found: Controller prefers $raw['equipment'] (line 77) over $raw['equipment_list'] (79), $package->equipment_list (81), $raw['line_items'] (83), and $raw['items'] (85). If the parser populates extracted_data['equipment'] with merged/garbled rows, the cleaner AI line_items is never read.
  implication: Confirms the hypothesis chain. The render path is parser-first.

- timestamp: 2026-05-14T20:38:00Z
  checked: Read app/Services/QuoteParserService.php lines 1691-1873 (the tuple regex and equipment build), plus dedupeTaggedEquipment at line 3102.
  found: The tuple regex on line 1695:
    /PARTSTART\s*(.*?)\s*PARTEND[\s~]*PARTDESCSTART\s*(.*?)\s*p.?ARTDESCEND[\s~]*QTYSTART\s*(.*?)\s*(?:QTYEND|QTVEND)/is
    tolerates `paARTDESCEND` and `PARTDESCEND` only (the `p.?ARTDESCEND` part). It does NOT tolerate `ARTOESCEND` (P missing + D→O substitution). With the non-greedy `(.*?)` and `s` flag, when the FIRST `PARTDESCEND` is garbled, the regex keeps consuming until it finds the next CANONICAL `p.?ARTDESCEND` — swallowing one or more intermediate tuples into the previous row's description.
  implication: Reconstructed reproduction:
    Row 1 (FW-85BZ40L Sony): PARTDESCEND garbled → row's match fails, OR matches but consumes downstream
    Row 2 (BT9910/B Tilt):   PARTDESCEND garbled → captures spillover to PA20 row's closer
    Row 3 (PA20 Yealink):    PARTDESCEND clean   → terminates Row 2's match
    Result: FW-85BZ40L disappears (its tuple silently fails OR is consumed inside Row 2's expanded capture), and BT9910/B's description gets PA20's content merged in plus the garbled "ARTOESCEND 1.00 ~" debris.

- timestamp: 2026-05-14T20:40:00Z
  checked: git show 3e936b0 (OVERVIEW family fix) and 2c7d1fa (QUOTENUM family fix).
  found: Both prior fixes follow the same pattern — extend normaliseQuoteWerksMarkers() at parse() entry-point with a conservative anchored regex. The PARTDESCEND family fits the same shape: anchor on the canonical suffix (the `(START|END)` tail) and accept a short garbled prefix.
  implication: Same fix template applies. No risk to v1.3 invariants (this file is not on the invariant surface list).

## Resolution

root_cause: |
  PDF text extractors (smalot/pdfparser, pdftotext) occasionally garble the
  closing `PARTDESCEND` QuoteWerks marker — the Light Forms quote produced
  `ARTOESCEND` (leading P dropped, internal D→O substitution from font glyph
  subsetting). Because the tuple-extraction regex in QuoteParserService::
  parseTagBased() only tolerates the canonical form (and its known `paARTDESCEND`
  variant), the non-greedy capture group at the PARTDESC slot keeps consuming
  text until the NEXT canonical PARTDESCEND, which lives one or more rows away.
  Result: two adjacent equipment rows are merged into one mangled row, an
  earlier row vanishes from the extracted set, and the leaked marker glyph
  ("ARTOESCEND") visible in the merged description. ProjectPackageReviewController::
  show() prefers extracted_data['equipment'] over the cleaner Claude-vision
  extracted_data['line_items'], so the parser's mangled output renders.
fix: |
  Extended QuoteParserService::normaliseQuoteWerksMarkers() with a third
  conservative regex covering the PARTDESC marker family:

      /\bP?AR[Tt]?[DOdo]ESC(START|END)\b/i  →  PARTDESC$1

  Anchor: the canonical ESC(START|END) suffix — no English prose word ends
  in "OESCEND" or "DESCSTART", so the anchor is safe. Prefix accepts:
    - optional leading P (covers the observed P-dropped form)
    - literal AR (next two chars are stable across observed corruption)
    - optional T (defensive: AR(_)DESCEND variant not yet observed)
    - one of [DO] (canonical D or its O substitution)
  Idempotent for the canonical form: P? matches P, [Tt]? matches T, [DOdo]
  matches D — input PARTDESCEND rewrites to PARTDESCEND (no-op).

  Same surface and same fix template as today's earlier two fixes
  (3e936b0 OVERVIEW family, 2c7d1fa QUOTENUM family). All three normalisers
  run once at parse() entry so every downstream strip regex sees clean
  input — no need to touch the tuple-extraction regex on line 1695,
  the dedupe pass, or any of the other 11 sites that strip these tokens.

  Separately observed (NOT fixed in this commit): the symptoms note that
  the DELIVERY row renders with category="Consumables" not "Services".
  This is a deliberate ExtractQuoteJob::classifyItemType design decision
  (line 455: DELIVERY in $consumablePrefixes; line 413 docstring lists
  delivery as a consumable). Changing it would touch mini-OM, RAMS, and
  hardware_list/worksheet_items filters — out of scope for a sidebar fix.
  Flagged for separate user decision at the human-verify checkpoint.
verification: |
  - php -l clean on app/Services/QuoteParserService.php
  - 5 new regression tests pass in tests/Unit/Rams/QuoteParserServiceTest.php
    (4 added in this commit + the 1 existing P-dropped-only that lives
    alongside; together 17 assertions). Cases:
      * the observed 3-row Light Forms merge with literal ARTOESCEND garble
      * P-dropped-only variant ("ARTDESCEND")
      * D→O-only variant ("PARTOESCEND")
      * false-positive guard for legitimate prose ("descends", "ascend")
  - Full QuoteParserServiceTest.php: 84 pass, 2 pre-existing failures
    unchanged (qtvend/dedup — flagged unrelated in commit 3e936b0 message)
  - All 13 tests/Feature/ProjectPackages tests pass — review-form path
    has no regression
  - All 17 Phase23InvariantGuardTest + V13SurfacesUntouchedTest pass —
    no v1.3 / Phase 23 surface touched
files_changed:
  - app/Services/QuoteParserService.php (added PARTDESC normaliser, ~10 LOC + docblock)
  - tests/Unit/Rams/QuoteParserServiceTest.php (4 new tests, 17 assertions)
  - .planning/notes/2026-05-14-equipment-merged-rows-and-marker-leak.md (sidebar-fix note)
