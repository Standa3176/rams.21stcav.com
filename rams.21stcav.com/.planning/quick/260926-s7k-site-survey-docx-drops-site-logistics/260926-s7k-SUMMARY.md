---
phase: quick
plan: 260926-s7k
subsystem: documents
tags: [defect-fix, content-gap, docx, phpword, site-survey, site-logistics, phase-46.2, safety]
requires:
  - "Phase 46.2-02 (wired site-surveys.docx — the route that gave SiteSurveyDocxService its first caller)"
  - "Phase 46.2-05 (put the five hand-typed free-text fields on the cockpit document form)"
  - "Phase 46-03 (SurveyCarryForward — the shared survey-findings derivation this fix reads through)"
  - "quick-260925-d65 (enabled PhpWord output escaping, so the new fields are escaped for free)"
provides:
  - "SiteSurveyDocxService::buildSiteLogistics() — a Site Logistics table carrying parking_restraints, site_access_notes, delivery_routes, comms_room_access_status + _notes and distance_from_base_miles + _notes"
  - "SiteSurveyDocxService::LOGISTICS_KEYS — the explicit parity set, as SurveyCarryForward::FIELDS keys"
  - "SiteSurveyDocxSiteLogisticsTest — 5 tests: presence, shared labels + order, comms vocabulary, empty-omission, escaped-exactly-once"
  - "A second reader of SurveyCarryForward::forSurvey() outside the engineer link, proving the derivation is reusable rather than link-specific"
affects:
  - app/Services/SiteSurveyDocxService.php
  - "the generated site-survey .docx — gains one table when any logistics column is filled, byte-identical output when none is"
tech-stack:
  added: []
  patterns:
    - "Read labels, render order, composed-pair joining and vocabulary maps through SurveyCarryForward::forSurvey() rather than copying them into a writer. The comms-room map now has FIVE copies in the codebase and this fix added none."
    - "Append survey-level blocks in build() AFTER the templated/programmatic if-else, so both branches are covered without extending the templated branch's unescaped string-replacement."
    - "A block that can vanish entirely gets its own heading + table, not extra rows on a fixed table — that is what makes empty-omission expressible."
key-files:
  created:
    - .planning/quick/260926-s7k-site-survey-docx-drops-site-logistics/PLAN.md
    - .planning/quick/260926-s7k-site-survey-docx-drops-site-logistics/260926-s7k-SUMMARY.md
    - tests/Feature/Documents/SiteSurveyDocxSiteLogisticsTest.php
  modified:
    - app/Services/SiteSurveyDocxService.php
decisions:
  - "Rows, labels, order and the comms vocabulary come from SurveyCarryForward::forSurvey(), not from a list written here. That single call supplies the field set, the labels an engineer already reads, the arrival-first order the two sibling DOCX writers already print, the ` — ` joining of the two composed pairs, AND the empty-field filtering. Writing any of those out by hand would have been a sixth spelling of the comms-room map, which is exactly how D-46.2-04-02 happened."
  - "The call lives in build() after the if/else, not inside buildCover(). buildCover() runs only on the programmatic arm; appending to $section after the branch covers BOTH arms while leaving the dead templated branch's raw string-replacement of general_notes completely untouched. Extending that replacement to four more fields would have planted an injection that fires the day someone adds resources/templates/."
  - "LOGISTICS_KEYS is an allow-list over FIELDS rather than all of FIELDS. FIELDS also carries access_constraints, site_risks and h_and_s_notes; the survey PDF renders none of those either, and the brief's target was parity with the PDF. Closing that wider gap is a content decision about the document's audience, not this defect — logged below with the one-line change that would do it."
  - "Own heading + own table rather than three more rows on the fixed Survey Details table. A row appended to that table cannot disappear; a table built only when $rows !== [] can, which is what 'empty fields must not produce empty labelled sections' requires."
  - "Values written raw. Settings::setOutputEscapingEnabled(true) is already the first statement of build() (d0b50a1c) and covers every addText(). A test asserts the single-escaped form IS present and &amp;amp; is NOT."
requirements-completed: [QUICK-s7k]
metrics:
  duration: "~55m"
  completed: "2026-09-26"
---

# Quick Task 260926-s7k: Site-survey .docx drops the PM's site logistics (D-46.2-06-06) Summary

Four site-survey free-text fields were captured from a PM and silently dropped from the generated
Word document. Live on production since 46.2-02 wired the route and 46.2-05 put the fields on the
cockpit form. Flagged as "worth its own defect" in the 260925-d65 summary; this is it.

## The defect, measured

`grep -c` over `app/Services/SiteSurveyDocxService.php`:

| Column | Before | After |
|---|---|---|
| `general_notes` | 2 | 3 |
| `site_access_notes` | **0** | 1 |
| `parking_restraints` | **0** | 1 |
| `delivery_routes` | **0** | 1 |
| `comms_room_access_notes` | **0** | 0 — *rendered, but never named* |

That last row is the one to read carefully. `comms_room_access_notes` is composed by
`SurveyCarryForward::commsRoomAccess()` under its primary column `comms_room_access_status`, so the
writer never names it and `grep -c` still returns 0 — while the test proves the PM's text reaches
`word/document.xml`. A grep count is a proxy, not the assertion.

All four are on `SiteSurvey::$fillable`, all four are on the 46.2-05 cockpit document form, all four
persisted. None reached the `.docx`.

**Why it is a safety defect.** These are the same columns
`app/Support/Visits/SurveyCarryForward::FIELDS` exists to move onto the installing engineer's link.
The carry-forward was never broken and still works. But anyone reading the **document** rather than
the link — an office reviewer, a subcontractor sent the file, the PM's own record — learned nothing
about access constraints or parking.

## RED — verbatim

`powershell -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SiteSurveyDocxSiteLogisticsTest`

```
  ⨯ site survey docx renders the pm typed site logistics                        3.53s
  ⨯ site logistics labels and order come from survey carry forward              0.16s
  ⨯ comms room access status renders the shared label not the stored token      0.15s
  ✓ empty site logistics renders no heading and no rows                         0.18s
  ⨯ new site logistics fields are escaped exactly once                          0.18s
  ──────────────────────────────────────────────────────────────────
   FAILED  Tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest > site survey docx renders the pm typed site logi…
  Expected: <?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n
<w:document xmlns:ve="http://schemas.openxmlformats.org/markup-compatibility/2006" …

  To contain: Loading bay bay-7 only, cones out by 0700

  at tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest.php:71

   FAILED  Tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest > site logistics labels and order come from surve…
  word/document.xml never contains the label 'Parking arrangements' for parking_restraints. The writer must use SurveyCarryForward::FIELDS labels, not its own wording.
Failed asserting that false is not false.

   FAILED  Tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest > comms room access status renders the shared lab…
  To contain: Permission required — Comms room key held by Facilities, ask for Dee

   FAILED  Tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest > new site logistics fields are escaped exactly o…
  parking_restraints was not rendered at all.
Failed asserting that false is not false.

  Tests:    4 failed, 1 passed (41 assertions)
  Duration: 7.00s
```

(Two runs of the same RED, 49.75s on the first cold run and 7.00s on the re-capture with the fix
temporarily swapped out via a throwaway file copy — **not** `git stash`. Identical failures both
times. `Expected: <?xml …` is Pest's diff renderer dumping the whole of `word/document.xml`, which
is why the custom failure messages appear only on the `assertNotFalse` cases.)

The `1 passed` is `empty site logistics renders no heading and no rows` — it passes before the fix
because nothing renders, and it must still pass after. It is the guard against over-fixing, not
evidence of the defect.

The third failure dumped the whole of `word/document.xml`, which is the clearest possible statement
of the gap: between the Survey Details table's last row (`General Notes`) and the room block's
heading (`Boardroom`) the document contained exactly `<w:p/>` — one empty paragraph. No
`Parking arrangements`, no `Site access notes`, no `Delivery routes`, no `Comms room access`, no
`Distance from depot`, nowhere in the file.

## GREEN — verbatim

```
   PASS  Tests\Feature\Documents\SiteSurveyDocxSiteLogisticsTest
  ✓ site survey docx renders the pm typed site logistics                        0.20s
  ✓ site logistics labels and order come from survey carry forward              0.16s
  ✓ comms room access status renders the shared label not the stored token      0.18s
  ✓ empty site logistics renders no heading and no rows                         0.16s
  ✓ new site logistics fields are escaped exactly once                          0.15s

  Tests:    18 passed (142 assertions)
  Duration: 8.21s
```

## What changed

`app/Services/SiteSurveyDocxService.php`, +84 lines, 0 deletions. One import, one const, one call,
one method. No existing line altered.

```php
+use App\Support\Visits\SurveyCarryForward;

+    private const LOGISTICS_KEYS = [
+        'parking_restraints',
+        'site_access_notes',
+        'delivery_routes',
+        'comms_room_access_status',
+        'distance_from_base_miles',
+    ];

     public function build(SiteSurvey $survey): string
         …
         }                                   // end of templated / programmatic if-else
+        $this->buildSiteLogistics($section, $survey);
         // ── Per-room tables ──

+    private function buildSiteLogistics(Section $section, SiteSurvey $survey): void
+    {
+        $rows = array_values(array_filter(
+            SurveyCarryForward::forSurvey($survey),
+            static fn (array $row): bool => in_array($row['key'], self::LOGISTICS_KEYS, true),
+        ));
+
+        if ($rows === []) {
+            return;                          // no heading, no rows, nothing
+        }
+
+        $this->sectionHeading($section, 'Site Logistics');
+        …same $lc/$vc/$lf/$vf table idiom as buildCover() and buildRoomBlock()…
+    }
```

## Fields added — label, order, and where each came from

| # | Column(s) | Label rendered | Source of the label |
|---|---|---|---|
| 1 | `parking_restraints` | **Parking arrangements** | `SurveyCarryForward::FIELDS`; PDF `_header-meta:47` says "Parking arrangement", cockpit form says "Parking arrangements" |
| 2 | `site_access_notes` | **Site access notes** | `FIELDS`; identical in PDF `_header-meta:88` and on the cockpit form |
| 3 | `delivery_routes` | **Delivery routes** | `FIELDS`; identical in PDF `_header-meta:92` and on the cockpit form |
| 4 | `comms_room_access_status` + `comms_room_access_notes` | **Comms room access** → `Permission required — {notes}` | `FIELDS` + `COMMS_ROOM_LABELS`; identical label in PDF `_header-meta:64` and on the cockpit form |
| 5 | `distance_from_base_miles` + `distance_from_base_notes` | **Distance from depot** → `42 miles from depot — {notes}` | `FIELDS`; PDF says "Distance from base" / "Travel notes" as two cells |

Nothing here was invented. Every label is read at runtime from
`SurveyCarryForward::FIELDS[$key]` — the test asserts that by indexing the same const rather than
comparing against string literals, so a future rename of a label cannot leave the test and the
writer agreeing with each other while both drift from every other reader.

**Order** is `FIELDS` order — parking, site access, delivery, comms, distance. Documented there as
"arrival-first: how do I park, how do I get in, what stops me, where do deliveries go". It is also
the order the two sibling DOCX writers already print (`WorksheetDocxService:160-…`,
`DocxBuilderService:701-…`), so the three Word documents now agree. It differs from
`_header-meta.blade.php`, which interleaves these rows with paper-form blank-line slots across two
`<h2>` sections (`Parking arrangement` sits under "Site Access & Safety", the other four under
"Site Logistics") — an order that exists to suit a clipboard, not a report. Taking the DOCX house
order over the paper-form order is recorded here as a deliberate choice, not an oversight.

Two fields beyond the four in the brief are included — `distance_from_base_miles` and
`distance_from_base_notes` — because the brief's stated target was **parity with what the PDF
already carries**, and `_header-meta:83-85` carries them. They were dropped by the .docx for the
same reason the other four were.

## `comms_room_access_status` vocabulary — reused, not forked

The stored vocabulary is `yes,no,outsourced,unknown`. The label map already existed in **five**
places before this task:

| Location | Kind |
|---|---|
| `app/Support/Visits/SurveyCarryForward.php:94` `COMMS_ROOM_LABELS` | **shared public const — the canonical one** |
| `app/Services/WorksheetDocxService.php:157` | local copy |
| `app/Services/DocxBuilderService.php:696` | local copy |
| `app/Support/Cockpit/CockpitDocumentFormPresenter.php:306` | local copy (radio options) |
| `resources/views/pdf/rams-v2.blade.php:867`, `rams.blade.php:805` | local copies |

**It IS already in a shared place** — `SurveyCarryForward::COMMS_ROOM_LABELS`, whose own docblock
records that it was *moved* there from a Blade rather than copied, "because two maps would disagree
the first time a status is added". So there was nothing to decide about where to put it: this fix
reads through `SurveyCarryForward::forSurvey()`, which applies that const itself. **No sixth copy
was created, and the count stayed at five.** The test asserts against
`COMMS_ROOM_LABELS['yes']` rather than the string `'Permission required'`, so it cannot silently
pass against a fork.

The four local copies are pre-existing and were left alone — see below.

## Empty fields render no heading — confirmed

`test_empty_site_logistics_renders_no_heading_and_no_rows` builds a survey with every logistics
column NULL and asserts `word/document.xml` contains neither the string `Site Logistics` nor any of
the five labels, while still containing `general_notes` (the control, so it is not asserting an
empty document). `forSurvey()` skips every field whose value trims to `''`, so `$rows === []` and
`buildSiteLogistics()` returns before adding the heading. For a survey with no logistics the
generated document is byte-identical to the pre-change output.

## No double escaping, and the dead templated branch untouched

- **Not double-escaped.** `Settings::setOutputEscapingEnabled(true)` remains the single call it was,
  first statement of `build()` (line 43, from `d0b50a1c`). No second call was added and nothing is
  escaped by hand — values go to `addText()` raw.
  `test_new_site_logistics_fields_are_escaped_exactly_once` stores
  `Parking & <access> under review`, asserts `Parking &amp; &lt;access&gt; under review` IS present
  and `&amp;amp;` is NOT. It carries the same `setOutputEscapingEnabled(false)` vacuity defence
  documented in `SiteSurveyDocxOutputEscapingTest`, so it measures this writer rather than a
  sibling's call. `SiteSurveyDocxOutputEscapingTest` stays green (both its tests in the 18 above).
- **The dead templated branch was not extended and not touched.** `build()`'s
  `DocumentTemplateService::exists('site-survey')` arm string-replaces `general_notes` straight into
  document XML, which output escaping does not cover; `resources/templates/` does not exist on this
  checkout so it never runs. `buildSiteLogistics()` is called *after* the if/else and appends to
  `$section`, so **both** arms gain the block with **zero** new unescaped replacements. The
  templated arm's `$this->templates->load(...)` array is character-for-character unchanged.

## A field the PDF also drops — second finding

**`resources/views/pdf/site-survey/summary.blade.php` drops all four fields too.** Only
`_header-meta.blade.php` renders them, and that partial is included by exactly two views —
`blank.blade.php` (`'survey' => null`, the empty paper form) and `field-form.blade.php` (the
pre-populated paper form an engineer carries on site). Neither is the report.

`summary.blade.php` is the post-survey summary PDF, internal *and* client variants, and
`grep -n` over it finds `general_notes` (`:74`, `:76`), `project_ref` (`:57`) and **nothing else**
from the set — no `parking_restraints`, no `site_access_notes`, no `delivery_routes`, no
`comms_room_access_*`, no `distance_from_base_*`. So after this fix the **.docx carries MORE site
logistics than the summary PDF does.** Parity with the PDF was achieved against the only PDF that
ever rendered these fields; the summary PDF has the identical gap and is not fixed here.

Third finding, same shape: **`access_constraints`, `site_risks` and `h_and_s_notes` reach neither
document.** All three are on `$fillable`, all three are in `SurveyCarryForward::FIELDS` (so the
installing engineer sees them on the link), and neither `_header-meta.blade.php`,
`summary.blade.php` nor the .docx renders any of them — including after this fix, because
`LOGISTICS_KEYS` is a parity allow-list. Two of the three are safety fields. Adding them to the
.docx is now a one-line change (three entries into `LOGISTICS_KEYS`); it was left out because it is
a decision about what the document should say, not a fix for what it was asked to say.

## Gates

| Gate | Result |
|---|---|
| `tests/Feature/Documents` | `Tests: 18 passed (142 assertions)` — the 13 entering plus these 5, 0 failed |
| Cockpit suite (`tests/Feature/Cockpit` + `tests/Unit/Cockpit`) | `Tests: 361 passed (5743 assertions)`, 0 failed |
| D-06 baseline | `Tests: 2 skipped, 159 passed (396 assertions)` — meets `>= 159 AND 0 failed` |
| `layouts/app.blade.php` | `9ED63C4C…0557` — matches |
| `resources/css/app.css` | `EDAD1982…2133` — matches |
| `tailwind.config.js` | `73BB8AD6…74BB` — matches |

The cockpit suite measured 361 passed, identical to the figure 260925-d65 recorded; the brief's
"361 passed entering" is confirmed rather than assumed.

## Test hygiene

`SiteSurveyDocxService` writes to `storage_path('app/site-surveys')` directly, bypassing
`DocumentArtifactStorage`, so `Storage::fake()` does not contain it. Every one of the five tests
goes through the shared `buildAndReadDocumentXml()` helper, which `finally`-unlinks the file it
generated and — in the same `finally`, so it holds even when the content assertions fail — asserts
the directory's `.docx` count is identical before and after.

## Noticed and left alone

1. **`SiteSurveyDocxService::build()` WRITES during a GET** — `$survey->update(['filename' => …])`
   at `:128`. Pre-existing, explicitly out of scope, and not made worse: `buildSiteLogistics()`
   only reads.
2. **The four local copies of the comms-room label map** (`WorksheetDocxService:157`,
   `DocxBuilderService:696`, `CockpitDocumentFormPresenter:306`, `pdf/rams*.blade.php`) could all
   read `SurveyCarryForward::COMMS_ROOM_LABELS` instead. All four currently agree with it, so
   there is no live bug — but five copies is five chances to disagree the next time a status is
   added. A mechanical de-duplication, not this defect.
3. **`resources/templates/` does not exist**, so the templated branch of `build()` is dead on this
   checkout and its unescaped string-replacement of `general_notes` remains unexercised. Left
   exactly as found, deliberately.
4. **`DocumentFormatInventoryTest` leaks two `.docx` files per run** into
   `storage/app/site-surveys/` — 10 files when d65 recorded it, 12 now after one full
   `tests/Feature/Documents` run here. Not mine (this task's tests unlink and assert count parity)
   and untracked. Left as found; d65 already logged it.
5. **`summary.blade.php` and the three `FIELDS` columns** — both written up above as findings
   rather than quietly designed around.
6. **`.planning/STATE.md` untouched.** No `state.advance-plan` / `state.update-progress` was run.
   No `git stash` was used at any point; `git stash list` is empty.

## Self-Check: PASSED

- `app/Services/SiteSurveyDocxService.php` — FOUND, modified, `php -l` clean, CRLF line endings
  preserved (`file` reports "with CRLF line terminators"), diff is +84/-0
- `tests/Feature/Documents/SiteSurveyDocxSiteLogisticsTest.php` — FOUND, `php -l` clean
- `.planning/quick/260926-s7k-site-survey-docx-drops-site-logistics/PLAN.md` — FOUND
- Three pinned files — all three hashes match the 45-BASELINE values
- `git status --short` shows only the two code files and this plan directory as changed/new; the
  ~110 untracked `.png` screenshots were already there
