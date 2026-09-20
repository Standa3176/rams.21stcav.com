---
phase: 46-visit-lifecycle
plan: 03
subsystem: visits
tags: [carry-forward, engineer-link, site-survey, client-facing, d-01]
requires:
  - app/Models/SiteSurvey.php
  - resources/views/worksheets/public-show.blade.php
provides:
  - App\Support\Visits\SurveyCarryForward
  - "the ten D-01 survey fields on /worksheet/{token}"
affects:
  - resources/views/worksheets/public-show.blade.php
tech-stack:
  added: []
  patterns:
    - "derivation in a final support class, printing in Blade (CockpitPanelPresenter shape)"
    - "read-live resolution: no cache, no copy, no column"
key-files:
  created:
    - app/Support/Visits/SurveyCarryForward.php
    - tests/Unit/Visits/SurveyCarryForwardTest.php
    - tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php
  modified:
    - resources/views/worksheets/public-show.blade.php
decisions:
  - "D-01 implemented by reading the survey record on every render — nothing is copied to the worksheet, the visit, or a cache"
  - "office_review_notes excluded BY NAME from FIELDS, asserted as data"
  - "$commsRoomLabels MOVED out of the Blade file into the support class, not copied"
  - "one drawer widened to ten fields; no second drawer, no second tap, summary copy unchanged"
metrics:
  duration: ~40 min
  completed: 2026-09-20
  tasks: 3
  commits: 5
---

# Phase 46 Plan 03: Survey → Install Carry-Forward Summary

**One-liner:** The install engineer's public link now shows all ten D-01 survey fields — including
the four safety ones (`access_constraints`, `site_risks`, `h_and_s_notes`, `general_notes`) that a
surveyor recorded and an installing engineer had never been shown — read live from the `SiteSurvey`
record on every render, never copied.

## What shipped

**`app/Support/Visits/SurveyCarryForward.php`** — a `final class`, pure reading.

- `FIELDS` is a const map of the nine driving columns → the label an engineer reads, in render
  order. Two rows compose from a pair of columns (`comms_room_access_status` + `_notes`;
  `distance_from_base_miles` + `_notes`), so ten D-01 fields resolve through nine keys.
- `forProject(?Project)` resolves the newest non-soft-deleted survey for the project by id — the
  same selection the page already used — and returns `['key','label','value']` rows for the fields
  that have a value, `[]` otherwise.
- `forSurvey(?SiteSurvey)` takes a survey the caller already loaded. The engineer link uses this so
  the page still makes exactly ONE survey query (T-46-03-05). It is still a read of the record on
  every request, not a copy of it.
- `COMMS_ROOM_LABELS` was **moved** here from the Blade file's `$commsRoomLabels`, not copied — two
  maps would disagree the first time a status is added. The Blade definition is gone, replaced by a
  comment pointing here.
- Values are returned RAW. Escaping happens at the render site; pre-escaping would show an engineer
  `&amp;` in a note about a contractor's name.
- The docblock quotes D-01 verbatim and states the two rules that follow: no caching across
  requests, and no column on `worksheets` ever holds one of these values.

**`resources/views/worksheets/public-show.blade.php`** — the existing
`📋 Site Logistics — Arrival Info` drawer, widened.

- The seven-key `$siteLogistics` array in the `@php` block became
  `$carryForward = SurveyCarryForward::forSurvey($survey)`.
- The drawer body's five hand-written `@if` blocks became one `@foreach` over `$carryForward`,
  printing `label` and `value` through an escaped echo with `white-space:pre-wrap` preserved.
- `$survey`, `$efByRoom`, `$photosByRoom` and `$roomsRequiringReview` are **untouched** — they drive
  the per-room Survey Reference drawer and the sign-off soft gate.
- Summary copy is unchanged, so no existing assertion named it (`grep -rn "Site Logistics" tests/
  resources/` confirmed nothing asserts on the worksheet drawer's summary).
- No JavaScript added (`git diff | grep -c "^+.*<script"` → `0`).

## Answering the plan's questions

**All ten fields on an unauthenticated engineer link:** yes —
`test_all_ten_fields_render_on_an_unauthenticated_engineer_link` does an unauthenticated
`GET /worksheet/{token}`, calls `assertGuest()`, and asserts all ten labels plus one distinct
sentinel per field so a failure names the missing field.

**The read-live proof** (`test_editing_the_survey_changes_what_the_link_shows`):

```php
$before = $this->get_link($worksheet);
$before->assertSee('OLD-RISK-ASBESTOS-IN-CEILING-VOID', escape: false);
$before->assertSee('OLD-CONSTRAINT-GOODS-LIFT-ONLY', escape: false);

$survey->update([
    'site_risks'         => 'NEW-RISK-LIVE-OVERHEAD-CABLES',
    'access_constraints' => 'NEW-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR',
]);

$body = $this->get_link($worksheet)->getContent();
$this->assertStringContainsString('NEW-RISK-LIVE-OVERHEAD-CABLES', $body);
$this->assertStringContainsString('NEW-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR', $body);
$this->assertStringNotContainsString('OLD-RISK-ASBESTOS-IN-CEILING-VOID', $body);
$this->assertStringNotContainsString('OLD-CONSTRAINT-GOODS-LIFT-ONLY', $body);
```

**`office_review_notes` excluded, and why:** it is the office's own internal commentary on the
survey, and `/worksheet/{token}` is an unauthenticated page the CLIENT signs. Carrying it would put
office-only remarks in front of the customer they are about (T-46-03-01). It is absent from `FIELDS`
with the reason recorded at the definition site, and two tests read the const as data plus assert
the string never reaches the rendered body.

**No second drawer:** `test_all_ten_render_inside_a_single_drawer` asserts
`substr_count($body, 'Site Logistics — Arrival Info') === 1`.

## Gate results (verbatim)

| Gate | Result |
|------|--------|
| `-Filter SurveyCarryForwardTest` | `Tests:    12 passed (32 assertions)` |
| `-Filter SurveyCarryForwardOnEngineerLinkTest` | `Tests:    11 passed (56 assertions)` |
| `-Path tests/Feature/Worksheets` | `Tests:    33 passed (123 assertions)` |
| `-Filter LabourResourceClientSurfacePrivacyTest` | `Tests:    8 passed (41 assertions)` |
| `-Baseline` (D-06) | `Tests:    2 skipped, 159 passed (396 assertions)` — `>= 159 passed AND 0 failed`, gate met |
| `php -l` over compiled views | `compiled views linted: 252, failures: 0` |
| `-Hashes` | all three protected files byte-identical to `4abd2b24` |

The two baseline skips are the documented ext-imagick self-skips, not defects. The baseline was
read as `>= 159 passed AND 0 failed`, never as equality against 161.

`LabourResourceClientSurfacePrivacyTest.php` was NOT edited — `git log -1` on it still shows
`04e7dc15 test(44-04)`, and `git status` shows it unmodified.

## Success criteria

- [x] All ten D-01 fields render on `/worksheet/{token}`, in one drawer, with no JavaScript added.
- [x] Editing the survey changes what the link shows, proven at HTTP level.
- [x] `grep -rn "site_risks\|h_and_s_notes\|access_constraints" database/migrations/*worksheet*`
      returns nothing — no value was copied onto a worksheet column.
- [x] A survey-less project renders exactly as it does today (no drawer, no placeholder).

## Deviations from Plan

**1. [Rule 1 - Bug] Label assertion had to allow HTML escaping**

- **Found during:** Task 2
- **Issue:** `"Surveyor's notes"` renders as `Surveyor&#039;s notes` through the escaped echo, so
  `assertSee(..., escape: false)` failed on a correctly-escaped page.
- **Fix:** the label loop asserts with escaping on (PHPUnit escapes the expected string the same way
  Blade does). The sentinel VALUE assertions stay `escape: false` and the XSS test still asserts on
  the raw body.
- **Files modified:** `tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php`
- **Commit:** f395fd13

**2. [Rule 1 - Bug] A Blade comment tripped the raw-echo guard**

- **Found during:** Task 2
- **Issue:** the new drawer comment originally contained the literal raw-echo token while saying it
  was forbidden, which took the file's raw-echo count from 1 to 2 and failed the guard.
- **Fix:** the comment says "unescaped raw echo" in words instead. The guard is stronger for it: the
  count is pinned at exactly one.
- **Files modified:** `resources/views/worksheets/public-show.blade.php`
- **Commit:** f395fd13

**3. [Plan adaptation] The raw-echo guard pins a count rather than asserting absence**

The plan says `{!! !!}` is forbidden in this file. The file already contained ONE, predating this
plan: `{!! $skipRestoreAttr !!}` at line ~893, which echoes a hard-coded
`data-skip-restore="1"` or the empty string and never touches user input. Removing it is outside
this plan's scope boundary, so the test pins the count at one and asserts that one is the
`$skipRestoreAttr` literal. Any new raw echo — including one over a carry-forward value — fails red.

**4. [Plan adaptation] `FIELDS` has nine keys for ten fields**

The plan's own field list composes `comms_room_access_status` with `comms_room_access_notes`, and
`distance_from_base_miles` with `distance_from_base_notes`, into two labelled rows. `FIELDS` is
therefore keyed by the nine driving columns; all ten D-01 columns are read and rendered. The unit
test pins the nine keys and asserts both composed values.

## Threat Flags

None. This plan adds no network endpoint, no auth path, no file access and no schema change. The
only new surface is additional survey free text on a route already in `CLIENT_FACING_PATHS`, which
is exactly what T-46-03-01 and T-46-03-02 cover.

## Known Stubs

None.

## TDD Gate Compliance

Both tasks followed RED → GREEN with the failing test committed first:

- Task 1: `7255053a test(46-03)` (12 failed) → `af26fc56 feat(46-03)` (12 passed)
- Task 2: `7c8a7e61 test(46-03)` (4 failed, 7 passed) → `f395fd13 feat(46-03)` (11 passed)

No REFACTOR commit was needed.

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 (RED) | `7255053a` | failing unit test for the ten D-01 fields |
| 1 (GREEN) | `af26fc56` | `SurveyCarryForward` resolves the ten fields live |
| 2 (RED) | `7c8a7e61` | failing engineer-link test |
| 2 (GREEN) | `f395fd13` | drawer widened to all ten; support class wired in |
| 3 | this commit | gate results recorded |

## Self-Check: PASSED

All four created files exist on disk and all four task commits resolve in `git log`.
