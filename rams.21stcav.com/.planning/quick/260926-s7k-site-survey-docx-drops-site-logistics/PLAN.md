---
phase: quick
plan: 260926-s7k
type: defect
defect: D-46.2-06-06
severity: live-on-production
subsystem: documents
autonomous: true
---

# Quick Task 260926-s7k: Site-survey .docx drops the PM's site logistics (D-46.2-06-06)

## Objective

Four site-survey free-text fields are captured from a PM and then silently
dropped from the generated Word document. Deployed to production yesterday
(46.2-02 wired the route, 46.2-05 put the fields on the cockpit form).

`grep -c` over `app/Services/SiteSurveyDocxService.php` returned **2** for
`general_notes` and **0** for each of:

| Column | On `$fillable` | On the 46.2-05 cockpit form | Persists | Reaches the .docx |
|---|---|---|---|---|
| `site_access_notes` | yes | yes (`Site access notes`) | yes | **no** |
| `parking_restraints` | yes | yes (`Parking arrangements`) | yes | **no** |
| `delivery_routes` | yes | yes (`Delivery routes`) | yes | **no** |
| `comms_room_access_notes` | yes | yes (`Comms room notes`) | yes | **no** |

A PM types site access and parking information into the live cockpit, sees it
save, and gets a Word document that does not contain it.

## Why this is a safety defect, not tidiness

These are the same columns the survey → install carry-forward exists to move:
`app/Support/Visits/SurveyCarryForward::FIELDS` reads them LIVE onto the
installing engineer's link. **The carry-forward is unaffected and still works.**
But anyone reading the *document* rather than the link — an office reviewer, a
subcontractor sent the .docx, the PM's own record — learns nothing about access
constraints or parking.

## Root cause

`SiteSurveyDocxService::buildCover()` renders a fixed six-row Survey Details
table (`project_name`, `client_name`, `site_address`, `surveyor_name`,
`survey_date`, `general_notes`) and `buildRoomBlock()` renders per-room columns.
No code path reads any survey-level site-logistics column. It is an omission,
not a broken condition: nothing to fix, a block to add.

## Approach — read through the shared derivation, do not re-derive

`SurveyCarryForward::forSurvey($survey)` already returns
`[{key, label, value}]` for exactly these columns, already:

- **labelled** — `FIELDS` maps column → the label an engineer reads
- **ordered** — arrival-first, the same order `WorksheetDocxService:160` and
  `DocxBuilderService:701` already print
- **composed** — `comms_room_access_status` + `_notes` joined by ` — `, and
  `distance_from_base_miles` + `_notes`
- **empty-filtered** — a field with no value is not in the list at all

So the writer gets the field set, the labels, the order, the vocabulary map and
the empty-omission behaviour from ONE existing place. A sixth hand-rolled copy of
the `yes|no|outsourced|unknown` label map is precisely how `D-46.2-04-02`
happened — the PDF compared against `permission|outsourced|free`, a vocabulary
that never existed, so two of three boxes could never tick.

## Tasks

1. **[test]** `tests/Feature/Documents/SiteSurveyDocxSiteLogisticsTest.php` —
   RED first, built through the real route (`GET site-surveys.docx`) exactly as
   `SiteSurveyDocxOutputEscapingTest` and `DocumentFormatInventoryTest` do:
   all five free-text fields present in `word/document.xml`; labels and order
   read from `SurveyCarryForward::FIELDS` rather than hard-coded; stored `yes`
   renders as the shared `COMMS_ROOM_LABELS['yes']`; an unfilled survey emits no
   heading and no labels; new-field text escaped exactly once (no `&amp;amp;`).
2. **[fix]** `SiteSurveyDocxService` — `LOGISTICS_KEYS` allow-list plus
   `buildSiteLogistics()`, called from `build()` **after** the templated/
   programmatic `if/else` so both branches get it.

## Decisions

- **Call site is `build()`, not `buildCover()`.** `buildCover()` runs only in the
  `else` (programmatic) arm. Appending to `$section` after the branch covers both
  arms *without* extending the dead templated branch's raw string-replacement of
  `general_notes` into document XML — which `Settings::setOutputEscapingEnabled`
  does **not** cover. Four more unescaped replacements would plant an injection
  waiting for someone to add `resources/templates/`.
- **Allow-list, not all of `FIELDS`.** `FIELDS` also carries
  `access_constraints`, `site_risks` and `h_and_s_notes`. The survey PDF
  (`_header-meta.blade.php`) does not render those either, and the target here is
  parity with the PDF. Logged as a separate gap rather than folded in.
- **Values written raw.** Output escaping landed yesterday (`d0b50a1c`) as the
  first statement of `build()`. No second call, no hand-escaping.
- **New table, not new rows on Survey Details.** Its own `Site Logistics`
  heading + table is what lets the whole block disappear when every column is
  empty; a row appended to the fixed table could not.

## Out of scope (logged, not fixed)

- `SiteSurveyDocxService::build()` WRITES during a GET
  (`$survey->update(['filename' => …])`). Pre-existing.
- The dead templated branch gated on `DocumentTemplateService::exists()`.
- `summary.blade.php` — the *summary* PDF drops all four fields too.
- `access_constraints` / `site_risks` / `h_and_s_notes` reach neither document.

## Gates

- `tests/Feature/Documents` — 13 passed entering, must not regress
- Cockpit suite (`tests/Feature/Cockpit` + `tests/Unit/Cockpit`) — 0 failed
- D-06 baseline `>= 159 passed AND 0 failed` — never equality against 161
- Three sha256 pins unchanged
