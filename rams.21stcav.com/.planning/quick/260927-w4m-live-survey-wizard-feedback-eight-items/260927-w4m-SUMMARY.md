---
phase: quick
plan: 260927-w4m
subsystem: cockpit
tags: [cockpit, site-survey, wizard, fields, labels, radios, empty-state, tokens, carry-forward]
requires:
  - "46.5 D-02 / D-03 — the guided creation wizard and the comms-room ruling"
  - "46.2 D-03 — fields are discovered, not invented (every field names a consumer)"
provides:
  - "One date question on the site-survey wizard, writing both survey.survey_date and visit.scheduled_date"
  - "Parking as a three-option closed choice storing the sentence its four readers print"
  - "Delivery routes and distance-from-base off the OFFICE form only, still captured on site and still carried forward"
  - "The format question replaced by a line naming engineer link / Word / PDF"
  - "A --cav-go token pair for generate controls, contrast recorded"
affects:
  - app/Support/Cockpit/CockpitDocumentFormPresenter.php
  - app/Http/Controllers/ProjectCockpitDocumentController.php
  - app/Http/Requests/CockpitDocumentRequest.php
  - resources/views/components/cockpit/doc-form.blade.php
  - resources/css/cav-tokens.css
  - resources/css/cockpit.css
key-files:
  created:
    - tests/Feature/Cockpit/CockpitSurveyFeedbackFieldsTest.php
  modified:
    - app/Support/Cockpit/CockpitDocumentFormPresenter.php
    - app/Http/Controllers/ProjectCockpitDocumentController.php
    - app/Http/Requests/CockpitDocumentRequest.php
    - resources/views/components/cockpit/doc-form.blade.php
    - resources/css/cav-tokens.css
    - resources/css/cockpit.css
    - tests/Unit/Cockpit/CockpitDocumentFormPresenterTest.php
    - tests/Unit/Cockpit/CockpitWizardPresenterTest.php
    - tests/Feature/Cockpit/CockpitDocumentFormTest.php
    - tests/Feature/Cockpit/CockpitVisualTest.php
    - tests/Feature/Cockpit/CockpitWizardTest.php
    - tests/Feature/Cockpit/CockpitWizardEndToEndTest.php
    - tests/Feature/Cockpit/CockpitDocCreationEndToEndTest.php
    - tests/Feature/Cockpit/CockpitInlineDrawerEndToEndTest.php
    - tests/Feature/Cockpit/CockpitCombinedCreationTest.php
decisions:
  - "Visit date is the label that survived; a new also_target key carries the same value to survey.survey_date"
  - "No visit-time field: visits has no time column, site_surveys.visit_time has zero generator readers, and programme.planned_start_time belongs to the RAMS install"
  - "Parking stores the SENTENCE it shows, because its four readers print the column raw with no code-to-label map"
  - "Delivery routes and distance-from-base moved to a step => null group, not deleted — SurveyCarryForward hands them to the installing engineer"
  - "The format radios go and the outputs are NAMED; format stays required on the request and is defaulted from the map"
  - "Green is a sibling class .cav-qa__go, not a --control--go modifier, because a control count assertion greps the block name"
  - "The green hover is DARKER, against the teal precedent, because the lighter green measures 3.29:1 and fails AA"
---

# Quick Task 260927-w4m: eight pieces of live-survey-wizard feedback — Summary

All eight items shipped. Six are map rows, one is a request default, one is a
token pair. `CockpitCombinedCreator`, `CockpitWizardPresenter`'s slicing logic
and `VisitLinkIssuer` were **not** edited, and neither were the five files that
read the survey's site-logistics columns.

## 1. One date, two targets — `Visit date` survived

`Survey date` and `Visit date` sat side by side on step 1 and the user could not
tell them apart. For a creation that makes the survey and the visit in one action
they are the same day by construction.

**`Visit date` is the label that survived**, because it names the real-world event
the PM is arranging. The `survey_date` row left the map; the surviving row gained
two keys:

```php
'target'        => 'visit.scheduled_date',
'also_target'   => 'survey.survey_date',
'also_consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->survey_date'],
```

`persist()` reads `also_target` through the **same bucketing** every other target
uses, so a second target cannot reach a different persister than a first one
would. The creator still reads `target` for the visit — untouched.

`also_consumer` gets the **same grep gate** as `consumer`
(`test_every_also_consumer_symbol_is_found_in_its_named_generator`, pinned at
exactly one second target), and the write is proven end to end on a real
submission:

```php
$this->assertSame('2026-10-01', $survey->survey_date?->format('Y-m-d'));
```

## 2. Time of visit — NO FIELD ADMITTED, and that is the finding

Three candidates grepped before answering:

| Candidate | Finding |
|---|---|
| a `visits` time column | **Does not exist.** `create_visits_table.php:70` is `$table->date('scheduled_date')`, cast `'date'` |
| `site_surveys.visit_time` | **Exists** (string 100) and was already on the map's REJECTED list. Its only readers are the legacy `site-survey/create|edit` Blades that WRITE it. No DOCX builder, no survey PDF Blade, no engineer link, no `SurveyCarryForward` |
| `programme.planned_start_time` | Mapped — but it is the RAMS **install** programme's "Start time on site" |

A field here would teach a PM to type into something no output renders.
`test_visit_time_is_not_a_field()` asserts the omission by name, with a
non-vacuity assertion that the column really does exist so the ruling describes a
real candidate. **Admitting it needs a consumer first** — a reader in the survey
PDF header or the engineer link — and that is separate work.

## 3. `Surveyor` → `Survey Engineer`

Label only. The key, the target and the consumer are unchanged and asserted so:
`test_the_surveyor_field_is_labelled_survey_engineer_and_nothing_else_moved()`.

## 4. The empty state now reads as the control's own reply

It was `.cav-qa__value` — 13px in `--cav-ink`, **byte-for-byte the styling of a
real answer** — inside a wrapping flex row, so it read as stray prose at the foot
of the group. Now `.cav-qa__empty`: `flex: 1 0 100%` so it claims its own row
directly under the legend it answers, muted `--cav-mid` at 12px.

**Nothing is hidden.** There genuinely are no active `LabourResource` rows on
live; that is a data state, not a defect, and hiding the control would say
engineers cannot be chosen here.

⚠️ This state was rendered by **no test** before today — every test in
`CockpitDocumentFormTest` called `resources()` first, which is exactly how it
shipped looking wrong. The new test asserts `LabourResource::count() === 0` first,
then renders, then calls `resources()` and re-renders for non-vacuity.

## 5. Parking — three radios, storing the sentence

```php
'type'    => self::TYPE_RADIO,
'options' => ['Parking onsite' => 'Parking onsite', 'No parking' => 'No parking', 'Unknown' => 'Unknown'],
'rules'   => ['nullable', 'string', 'in:Parking onsite,No parking,Unknown'],
```

**What it STORES is the label itself.** `parking_restraints` is a STRING column
and all four readers print it raw:

| Reader | Access | Still renders |
|---|---|---|
| Word document | `SiteSurveyDocxService::LOGISTICS_KEYS` | yes — a short human string, as before |
| Survey PDF | `_header-meta.blade.php:22` | yes |
| Engineer link | via `SurveyCarryForward` | yes |
| Carry-forward | `SurveyCarryForward::FIELDS` | yes |

None owns a code-to-label map, so `onsite` would have printed `onsite` to an
engineer on site. This is the **opposite** ruling to `comms_room_access_status`,
which has a validator (`in:yes,no,outsourced,unknown`) and label maps in two
generators — both rulings are now stated in the map beside each other.

Rows written before today hold free text: they match no radio, render unchanged
everywhere, and nothing rewrites them.

Radios, not a dropdown — `<select` stays `FORBIDDEN_MARKUP` entry 1, **fence
still 2**.

## 6 + 7. Delivery routes and distance from base — `step => null`, NOT deleted

⚠️ **This was the comms-room trap a second time.** The user's own reason — *"this
is a survey not install with kit delievery"* — is **exactly why the surveyor
records delivery routes**: for the install that follows. Measured before starting:

```
delivery_routes          docx:1  carry-forward:1  survey-pdf:1  engineer-link:2
distance_from_base_miles docx:1  carry-forward:4  survey-pdf:1  engineer-link:2
```

The three fields moved, byte-identical bar their group, into a new group:

```php
'legend' => 'For the install that follows',
'step'   => null,
```

with the reason written beside it in code, naming `SurveyCarryForward`, the
installing engineer, and the five files that must stay untouched — the same shape
the comms-room group already uses. `Access and logistics` keeps `site_access_notes`
and `parking_restraints`; its comment records that it was five fields and why
three left (a step is one key per GROUP, so a field that changes step changes
group).

**The five named files are untouched** — `git status` reports no modification to
any of:

```
app/Services/SiteSurveyDocxService.php
resources/views/pdf/site-survey/
app/Http/Controllers/PublicSurveyController.php
app/Http/Controllers/SurveyController.php
app/Support/Visits/SurveyCarryForward.php
```

### The proof — `tests/Feature/Cockpit/CockpitSurveyFeedbackFieldsTest.php`

Six tests, and **every one pairs an absence with a presence**, because a test that
only proved the office form stopped asking would pass just as happily after
someone "finished the job" by deleting the columns.

The surveyor is still asked — the live page is **fetched and searched**:

```php
$html = $this->get(route('survey.show', ['token' => $survey->access_token]))
    ->assertOk()
    ->getContent();

foreach (self::MOVED_KEYS as $key) {
    $this->assertStringContainsString(
        'engineerFeedbackSite.'.$key,
        $html,
        "The SURVEYOR is no longer asked for [{$key}] on /survey/{token}. The office form stopped "
        .'asking; the field capture must NOT have. This is the safety regression the `step => null` '
        .'ruling exists to avoid.',
    );
}
```

…and the surveyor's POST still lands the values
(`test_the_public_save_still_accepts_all_three_from_the_surveyor`), and the
carry-forward still carries — **resolved through the real call**, not read off the
const:

```php
$rows   = SurveyCarryForward::forProject($project->fresh());
$values = array_column($rows, 'value', 'key');

$this->assertArrayHasKey(
    'delivery_routes',
    $values,
    'The carry-forward dropped `delivery_routes`. The installing engineer reads it — see the map.',
);
$this->assertSame('SENTINEL-ROUTES-TO-INSTALL', $values['delivery_routes']);
$this->assertStringContainsString('42 miles', $values['distance_from_base_miles']);
$this->assertStringContainsString('SENTINEL-TRAVEL-TO-INSTALL', $values['distance_from_base_miles']);
```

Plus `test_the_five_downstream_readers_still_read_these_columns` (10 reads across
5 files) and `test_no_step_number_returns_the_moved_group` (probed 0–9, so a
hand-typed step is not a way back).

`CockpitWizardPresenterTest` now pins the stepless set by **set equality** —
`STEPLESS_LEGENDS = ['Comms room', 'For the install that follows']` — so a third
group acquiring `step => null`, which is how a field silently stops being asked,
is a red test.

## 8. Format section removed; the outputs are NAMED

`grep cockpit_document_format` → two WRITES, **zero reads**. The radio's answer
travelled no further than a flash word, while one creation already produces the
document, the visit, the engineer link, the Word file and the PDF. The user was
right: a leftover.

The last step now renders one derived line:

```
Generating creates the engineer link, Word and PDF.     (site survey)
Generating creates Word and PDF.                        (RAMS, O&M — no link, D-04)
Generating creates the engineer link and Word.          (worksheet — no PDF, DC-07)
```

Built from `$offered` (the map's own `formats`) plus
`CockpitCombinedCreator::handles()` — read, never re-decided — so it cannot
promise a file with no route. The worksheet's `PDF is not available for this
document (DC-07).` note is unchanged and still SAID.

**No format was invented. `DocumentFormatInventoryTest` is green**
(`Tests: 5 passed (15 assertions)`), and the map's `formats` are byte-unchanged.

`format` stays on the request: still `required` on a creation, still
membership-checked against that document's offered keys, now **defaulted from the
map** in `prepareForValidation()` when absent. A PRESENT but unoffered one is
still rejected — `format=pdf` on the worksheet is still a validation error, and
that boundary is asserted.

No download buttons were added: `Download` is `DEFERRED_AFFORDANCES` copy, and a
fence entry is never lifted for a label.

## 9. Green generate buttons — `--cav-go` / `--cav-go-dark`

No hex reaches a Blade or `cockpit.css`. Two new tokens on `.cav-brand` in
`resources/css/cav-tokens.css`, with their contrast figures recorded as that
file's docblock requires (white 13px/600 button text):

| Token | Value | White on it |
|---|---|---|
| `--cav-go` | `#15803D` | **5.01:1** — PASSES AA |
| `--cav-go-dark` | `#166534` | **7.12:1** — PASSES AA and AAA |

⚠️ **The hover goes DARKER, the opposite of the teal pair** (`--cav-teal-dark`
hovering to the lighter `--cav-teal`). That is the measurement, not an
inconsistency: the obvious lighter green `#16A34A` is **3.29:1** and FAILS. A
hover that drops below AA hides the label. Recorded in the token file so nobody
"tidies" it back.

The class is `.cav-qa__go`, a **sibling, not a `--control--go` modifier** —
`CockpitDocumentFormTest` counts controls with
`substr_count($block, 'cav-qa__control')` and asserts ONE, so a modifier carrying
the block name would double every count and turn a real guard into a number
nobody could read.

Green is on the two GENERATE controls (the closed opener and the create submit)
and on neither Next nor Back, asserted across **8 open states over 4 documents**.

⚠️ **Stretched-link trap re-checked by name.** The rule sets `background` and
`border-color` only. `CockpitVisualTest` now scans both `.cav-qa__go` rules for
`position:` and `transform:` and asserts it scanned exactly 2 — because either
property on an anchor inside `.cav-module` collapses the whole-row click target
onto the 28px glyph **with nothing failing**.

## ⚠️ `npm run build` IS REQUIRED for deploy

`resources/css/cockpit.css` is a Vite entry, and `resources/css/cav-tokens.css`
is compiled with it. **Neither change reaches the browser without a build.** The
green buttons and the repositioned empty state will not appear on live from a
code-only deploy. Nothing was pushed and nothing was deployed.

## Counts measured

| Fence | Entering | Now |
|---|---|---|
| `FORBIDDEN_MARKUP` | 2 | **2** |
| `DEFERRED_AFFORDANCES` | 21 | **21** |
| `BANNED_HANDLER_ATTRIBUTES` | 9 | **9** |
| `WRITE_SURFACE_TABLES` | 13 | **13** |

Nothing lifted. Three new strings were checked against all 21 entries and both
markup entries before use and collide with none: `Generating creates the engineer
link, Word and PDF.`, the three parking option words, and `Survey Engineer`.

Alpine pins on `resources/views/worksheets/public-show.blade.php` — **file not
touched**, counted anyway:

| Directive | Pin | Measured |
|---|---|---|
| ` x-data` | 1 | **1** |
| ` x-show` | 1 | **1** |
| ` x-model` | 2 | **2** |
| ` x-cloak` | 1 | **1** |
| `{!!` | 1 | **1** |

## Gates — ONE SUITE PER INVOCATION, each quoted with its `Duration:`

None of these came from a stalled or killed run: every line below is paired with
a real `Duration:` and a `0`/`2` exit that phpunit itself produced. (A killed
stall reports "exit code 0" with **no** `Tests:` line; and `artisan test` emits
ANSI colour, so the escapes were stripped before grepping.)

| Gate | Entering | Result |
|---|---|---|
| `tests/Feature/Cockpit` | `Tests: 365 passed (7537 assertions)` / 150.97s | `Tests: 375 passed (7678 assertions)` / 154.48s |
| `tests/Unit/Cockpit` | `Tests: 108 passed (939 assertions)` / 13.07s | `Tests: 114 passed (1203 assertions)` / 13.15s |
| **Cockpit total** | **473 / 0** | **489 / 0** — +16 net new tests, 0 failed |
| `tests/Feature/Worksheets` | 183 | `Tests: 183 passed (1687 assertions)` / 31.48s |
| `tests/Feature/Documents` | 18 | `Tests: 18 passed (142 assertions)` / 5.90s |
| `DocumentFormatInventoryTest` | — | `Tests: 5 passed (15 assertions)` / 6.19s |
| `tests/Feature/Visits` | — | `Tests: 27 passed (248 assertions)` / 7.30s |
| `-Filter Survey` | — | `Tests: 238 passed (1100 assertions)` / 67.95s |
| D-06 baseline | `>= 159 passed AND 0 failed` | `Tests: 2 skipped, 159 passed (396 assertions)` / 38.98s — **PASS**. The 2 skips are the documented ext-imagick self-skips. Never compared to 161 |
| sha256 pins | `4abd2b24` bytes | All three byte-identical: `9ED63C4C…`, `EDAD1982…`, `73BB8AD6…` |

## Self-Check: PASSED

- `app/Support/Cockpit/CockpitDocumentFormPresenter.php` — FOUND, modified
- `app/Http/Controllers/ProjectCockpitDocumentController.php` — FOUND, modified
- `app/Http/Requests/CockpitDocumentRequest.php` — FOUND, modified
- `resources/views/components/cockpit/doc-form.blade.php` — FOUND, modified
- `resources/css/cav-tokens.css` — FOUND, modified
- `resources/css/cockpit.css` — FOUND, modified
- `tests/Feature/Cockpit/CockpitSurveyFeedbackFieldsTest.php` — FOUND, created
- `.planning/quick/260927-w4m-live-survey-wizard-feedback-eight-items/PLAN.md` — FOUND
- `app/Support/Cockpit/CockpitCombinedCreator.php` — UNMODIFIED, as required
- `app/Support/Cockpit/CockpitWizardPresenter.php` — UNMODIFIED, as required
- The five downstream readers — UNMODIFIED, as required
