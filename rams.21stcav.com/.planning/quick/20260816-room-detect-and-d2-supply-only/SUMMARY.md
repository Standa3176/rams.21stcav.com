---
quick_id: 260816-rdz
slug: room-detect-and-d2-supply-only
date: 2026-08-16
status: complete
---

# Quick Task 260816-rdz Summary — Room-detection prose fragments + D2 schematic supply-only leak

Two independent, pre-traced defects found while investigating a blank schematic on project
21CQ30698 (package 147). Both were reproduced exactly against real production data before this
task started, so no re-investigation was performed — only the fix, tests, and verification below.

## Defect A — Room detection harvested prose fragments as room names

**File:** `app/Services/QuoteParserService.php`
**Commit:** `efe275b`

`extractRooms()`'s keyword-scan fallback captures up to 50 chars before a room keyword and up to
20 after, with no word-boundary awareness — guarded only by a 60-char length cap that short
fragments sail under. Package 147 stored `["Boardroom Rack Reconfiguratio", "s Boardroom
currently contains"]` as its only two "rooms" (29/30 chars, both mid-word), so no equipment
matched a room and the schematic rendered blank.

**A1 — trim captures to whole words.** After the capture, drop a trailing partial word when the
character immediately after the captured span still continues a word (the {0,20} window ran out
mid-word), and drop a leading partial word when the character immediately before the span is
alphanumeric or an apostrophe (a non-class character like an apostrophe in "client's" broke the
run, leaving a fragment such as "s" at the start).

**A2 — reject prose fragments.** After A1's trim, reject the candidate if: it contains a
whole-word (case-insensitive) match against a fixed stop-list (currently, contains, existing,
should, will, would, there, these, their, which, that, been, have, requires, required); it starts
with a 1-2 character lowercase token (the signature of a capture that began mid-word); or it runs
long.

**Deviation from the plan's literal wording:** the plan specified a ">5 tokens" word-count
threshold. Implementing it literally broke an existing, currently-passing fixture —
`test_extracts_boardroom_from_line` parses "Works to be carried out in the Boardroom area" (9
tokens) end-to-end into a room today, and the plan's own risk guidance says to narrow an
over-aggressive rule rather than update/break a fixture that currently works. The word-count
threshold was narrowed to ">9 tokens". This doesn't weaken the fix against the actual evidence —
both package-147 fragments are independently caught by the stop-word check and the
short-leading-token check, not by word count. The word-count rule remains as a backstop against
future long fragments that dodge both other checks.

**A3 — regression tests.** Added to `tests/Unit/Rams/QuoteParserServiceTest.php`:
- test_package_147_truncated_trailing_fragment_is_trimmed_to_whole_words
- test_package_147_apostrophe_leading_fragment_is_rejected
- test_prose_fragment_with_leading_mid_word_token_is_rejected
- test_prose_fragment_with_sentence_stopwords_is_rejected
- test_legitimate_short_room_names_still_accepted (plan's positive cases: Boardroom, Meeting
  Room 1, Digital Production Studio, Boardroom Rack)
- test_existing_multi_word_sentence_room_capture_still_works (guards the narrowed threshold)

**Verification:** `php artisan test --filter="QuoteParser"` — 115 passed to 121 passed (6 net new
tests, all green), 0 failures, 0 newly-risky tests, same 2 pre-existing deprecation notices as
baseline (unrelated `trim(): Passing null` warnings in the short-tag-variant fixtures).

## Defect B — Supply-only hardware still appeared in the D2 schematic

**File:** `app/Services/Drawings/DrawingDataResolverService.php`
**Commit:** `eb1ecd0`

`EXCLUDED_CATEGORIES` is an inclusive-by-default allowlist (cables, consumables, services,
option). `hardware_supply_only` (added by quick task 260815-sup, user decision locked
2026-08-15: supply-only kit appears in O&M only, not RAMS/drawings/surveys) was absent, so
supply-only kit still rendered as a device node in the D2 schematic. Every other consumer filters
exclusively (`!== 'hardware'`), so the new category value was excluded there by construction —
the 260815-sup audit grepped for `=== 'hardware'` and never matched this file's `in_array(...)`
shape. The Phase 23 XTEN-AV renderer (Project::devicesWithStencils()) already excluded it
correctly; only this older D2 path leaked.

**B1 — fix.** Added `hardware_supply_only` to `EXCLUDED_CATEGORIES`, with a comment recording
the 260815-sup decision and flagging the list as inclusive-by-default so future categories must
be added explicitly.

**Observation (not acted on):** `customer_supplied` and `service_contracts` are also absent from
`EXCLUDED_CATEGORIES` and may be the same class of oversight. This was NOT changed — whether
those categories should be excluded from drawings is a separate product decision the user hasn't
made.

**Verification:**
- Reflection-based direct call to `filterHardware()` with one `hardware` item and one
  `hardware_supply_only` item confirmed only the `hardware` item survives.
- `php artisan test --filter="Drawing|Schematic"` — 310 passed, 2 skipped, 2 failed. The 2
  failures are DrawIoSpikeController constructor-arity tests
  (Tests\Feature\Drawings\DrawIoBuilderServiceTest and
  Tests\Feature\Drawings\V13SurfacesUntouchedTest), both asserting the constructor has exactly
  2 parameters when it currently has 3 — pre-existing drift unrelated to this change, explicitly
  called out in the plan's verification gates as known-not-a-regression. No other test failed or
  changed status from baseline.

## Deviations from Plan

### Auto-fixed / adjusted

**1. [Task risk guidance] Narrowed the A2 word-count threshold from 5 to 9 tokens**
- **Found during:** Task A2 implementation, before committing
- **Issue:** The plan-specified ">5 tokens" rule rejected a legitimate, currently-passing
  fixture ("Works to be carried out in the Boardroom area", 9 tokens)
- **Fix:** Raised the threshold to ">9 tokens"; verified the two package-147 evidence strings are
  still rejected via the stop-word and short-leading-token checks, independent of word count
- **Files modified:** `app/Services/QuoteParserService.php`,
  `tests/Unit/Rams/QuoteParserServiceTest.php`
- **Commit:** `efe275b`

No other deviations. Both tasks implemented as specified otherwise.

## Self-Check

- `app/Services/QuoteParserService.php` — FOUND, modified, lints clean
- `tests/Unit/Rams/QuoteParserServiceTest.php` — FOUND, modified, lints clean
- `app/Services/Drawings/DrawingDataResolverService.php` — FOUND, modified, lints clean
- Commit `efe275b` — FOUND in `git log`
- Commit `eb1ecd0` — FOUND in `git log`

## Self-Check: PASSED

## 🚨 Files to upload to live

- `app/Services/QuoteParserService.php`
- `app/Services/Drawings/DrawingDataResolverService.php`

(`tests/Unit/Rams/QuoteParserServiceTest.php` is test-only, not required in production, but
harmless to upload if convenient.)

No migration. After uploading, run `php artisan optimize:clear`.

## Explicitly out of scope (not done, by design)

- Repairing package 147's stored data (the two junk room strings) — that's a user-side data fix
  on the review screen (replace the two junk rooms, assign areas to the 14 equipment lines).
- Any "no equipment matched a room" warning on the drawings screen.
- Deciding whether `customer_supplied` / `service_contracts` should also be excluded from
  drawings — flagged above as an observation only.
