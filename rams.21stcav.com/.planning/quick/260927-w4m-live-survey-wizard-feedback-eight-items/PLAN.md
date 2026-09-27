---
phase: quick
plan: 260927-w4m
type: feedback
severity: live-usability
subsystem: cockpit
autonomous: true
---

# Quick Task 260927-w4m: eight pieces of live-survey-wizard feedback

## Objective

The user walked the live site-survey creation wizard and gave eight pieces of
feedback in one message. All eight are **fields, labels, one control type, one
empty state and one colour** — the wizard's stepping, the combined creation, the
abandoned-wizard rule, RAMS's flow and the photo buckets are NOT reopened.

Verbatim:

> "on site survey> create , what is the diff between survey datea and visit
> date. Also , change Surveyor to Survey Engineer and why is the below text at
> the bottom of this section : Engineer / Nobody active is on file for this. Add
> people under Labour resources. on 2nd page : replace parking arrangements with
> tick boxs (Parking Onsite/ No Parking/Unknown) .Remove Delivery routes as this
> is a survey not install with kit deleivery. Remove "distance from base" and
> travel notes. Add time of visit to page 1 neat visit date. On 3rd page ,
> remove format section and out put as engineer link / word/pdf . Colour all
> generate buttons Green"

## The eight, and the ruling on each

| # | Asked | Ruling |
|---|---|---|
| 1 | Two dates, indistinguishable | ONE question. **`Visit date` is the label that survives**; `also_target` carries the same value to `survey.survey_date` so the Word document keeps its date |
| 2 | Time of visit beside it | **NO FIELD ADMITTED.** No consumer exists — reported, not invented |
| 3 | `Surveyor` → `Survey Engineer` | Label only |
| 4 | "Nobody active…" reads as stray text | Its own `.cav-qa__empty` class. **Neither it nor the control is hidden** — there really are no active `LabourResource` rows on live |
| 5 | Parking free text → 3 choices | `TYPE_RADIO`, and the stored value is the SENTENCE, because four readers print the column raw |
| 6 | Remove Delivery routes | `step => null` **on the office form only** |
| 7 | Remove Distance from base + travel notes | `step => null` **on the office form only** |
| 8 | Remove Format, output engineer link / Word / PDF | Radios out, outputs NAMED. No format invented |
| 9 | Green generate buttons | `--cav-go` / `--cav-go-dark` token pair, contrast recorded |

## ⚠ Items 6 and 7 are the comms-room trap again — REMOVE FROM THE FORM, NOT THE APP

Measured before starting:

```
delivery_routes          docx:1  carry-forward:1  survey-pdf:1  engineer-link:2
distance_from_base_miles docx:1  carry-forward:4  survey-pdf:1  engineer-link:2
```

Both are read by **`SurveyCarryForward`**, which is how an **installing
engineer** sees what the surveyor found. The user's own reason — *"this is a
survey not install with kit delievery"* — is **exactly why the SURVEYOR records
delivery routes**: for the install that follows. An office PM at creation time
does not know them.

So: set the group's `step` to `null` (the established mechanism, as Comms room
already does), write the reason beside it in code, and leave these five files
**untouched**:

- `app/Services/SiteSurveyDocxService.php`
- `resources/views/pdf/site-survey/*`
- `app/Http/Controllers/PublicSurveyController.php`
- `app/Http/Controllers/SurveyController.php`
- `app/Support/Visits/SurveyCarryForward.php`

**Proof required**: a test that the surveyor is still asked for both on the
public survey page, and that the carry-forward still carries them.

## Parking — what it must STORE

`parking_restraints` is a STRING column with FOUR readers, none of which owns a
code-to-label map:

| Reader | Access |
|---|---|
| Word document | `SiteSurveyDocxService::LOGISTICS_KEYS` |
| Survey PDF | `_header-meta.blade.php:22` `$survey?->parking_restraints` |
| Engineer link | via `SurveyCarryForward` |
| Carry-forward | `SurveyCarryForward::FIELDS['parking_restraints']` |

Storing `onsite` / `none` / `unknown` would print those words to an engineer on
site. **The option value is therefore the sentence**: `Parking onsite` /
`No parking` / `Unknown`. This is the OPPOSITE ruling to
`comms_room_access_status`, which has a validator and label maps in two
generators — deliberately so, and stated in the map beside both.

Radios, not a dropdown: `<select` is `FORBIDDEN_MARKUP` entry 1 and stays banned.

## Item 2 — the grep that refused the field

Three candidates, all rejected before answering:

| Candidate | Finding |
|---|---|
| `visits.scheduled_time` | **Does not exist.** `2026_09_19_140000_create_visits_table.php:70` is `$table->date('scheduled_date')`, cast `'date'`. No time column on the row at all |
| `site_surveys.visit_time` | **Exists** (string 100) — and is already on the map's REJECTED list. Only readers are the legacy `site-survey/create|edit` Blades that WRITE it. 0 generator hits |
| `programme.planned_start_time` | Mapped already, but it is the RAMS **install** programme's "Start time on site", not this visit's |

A field here would write into something no output renders — the exact D-03
failure the map exists to prevent. **Reported, not admitted**, with
`test_visit_time_is_not_a_field()` asserting the omission by name and a
non-vacuity assertion that the column really does exist.

## Item 8 — what the format radios actually did

`grep cockpit_document_format` → two WRITES
(`ProjectCockpitDocumentController:222` and `:558`) and **zero reads**. The
radio's answer travelled no further than a flash word. One creation already
produces the document, the visit, the engineer link, the Word file and the PDF.

So the radios go and the last step NAMES the outputs, built from the map's own
`formats` plus `CockpitCombinedCreator::handles()`. `DocumentFormatInventoryTest`
asserts the missing set is exactly `['worksheet.pdf']` — the map is untouched and
that test stays green. **No format was invented.**

`format` itself stays on the request: still `required` on a creation, still
membership-checked against that document's offered keys, now DEFAULTED from the
map when absent. A posted `format=pdf` on the worksheet is still a validation
error.

No download buttons: `Download` is `DEFERRED_AFFORDANCES` copy and a fence entry
is never lifted for a label.

## Constraints honoured

- Blade names no document and switches on TYPE (eight types, closed)
- `<select` banned; fence counts **2 / 21 / 9 / 13** unchanged
- ONE creation → ONE worksheet → ONE build job; `successMessage()` null when
  incomplete; the abandoned-wizard six-table assertion — all untouched
- Alpine pins on `worksheets/public-show.blade.php` unchanged: ` x-data` 1,
  ` x-show` 1, ` x-model` 2, ` x-cloak` 1, `{!!` 1
- `CockpitCombinedCreator`, `CockpitWizardPresenter`'s slicing and
  `VisitLinkIssuer` NOT edited
- The three sha256 pins byte-identical to `4abd2b24`
- The stretched-link trap: the new CSS rule sets `background` and
  `border-color` only — no `position`, no `transform`, asserted by name
