---
quick_id: 261011-f9l
slug: install-step-headings-vs-actions
type: bugfix
status: complete
date: 2026-10-11
branch: feat/worksheet-classifier-universal
follows: 261011-d62
commits:
  - c372275c feat(261011-f9l) rows() tags a phase heading apart from an action
  - 40bddf6d test(261011-f9l) headings-vs-actions guards, proven RED first
  - c9dfe160 fix(261011-f9l) all three surfaces tell a heading from an action
files_modified:
  - app/Support/WorksheetInstallSteps.php
  - app/Services/WorksheetDocxService.php
  - resources/views/worksheets/public-show.blade.php
  - resources/views/worksheets/show.blade.php
  - tests/Unit/Support/WorksheetInstallStepsTest.php
  - tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php
  - tests/Feature/Worksheets/WorksheetDocxInstallStepsShapeTest.php
---

# 261011-f9l — Phase headings are distinguishable from actions — SUMMARY

`WorksheetInstallSteps::rows()` now tags every row `heading` or `step`, and all three surfaces
count and number by kind. The 2-phase / 7-action Boardroom that advertised **"Install Steps (9)"**
says **(7)**, the headings carry no ordinal, and the action ordinals run **1..N in one continuous
sequence across the whole room** on the engineer link, the office view and the DOCX — so "step 7"
means the 7th thing an engineer does, on a phone and on paper.

## RED first, and the real failure output

Both feature guards were extended and run against the **unfixed** consumers (commit `40bddf6d`
holds the tests, `c9dfe160` the fix).

`WorksheetInstallStepsShapeTest` — **9 failed, 10 passed (114 assertions)**.

**Symptom 1, the drawer count, verbatim** — the assertion the plan requires to fail pre-fix:

```
 FAILED  Tests\Feature\Worksheets\WorksheetInstallStepsShapeTest > the engineer drawer count is the action count n…
  Expected: <!DOCTYPE html>\n
...
                                <span>✅ Install Steps (5)</span>\n
...
  To contain: Install Steps (3)

  at tests\Feature\Worksheets\WorksheetInstallStepsShapeTest.php:324
  ➜ 324▕         $response->assertSee('Install Steps (' . self::SHAPE_C_ACTION_COUNT . ')');
    325▕         $response->assertDontSee('Install Steps (5)');
```

The rendered page is quoted inside the failure itself: `✅ Install Steps (5)` for a room with
**three** install steps.

**Symptom 2, the ordinals**, verbatim:

```
 FAILED  Tests\Feature\Worksheets\WorksheetInstallStepsShapeTest > engineer ordinals number actions only and run c…
  every <li> in the step list must declare its kind as class="phase" or class="step"
Failed asserting that 0 is identical to 5.
  at tests\Feature\Worksheets\WorksheetInstallStepsShapeTest.php:300
```

**Symptom 3, the office view** — `Failed asserting that two arrays are identical`, expected
`[[phase,null],[step,1],[step,2],[phase,null],[step,3]]`, actual `[]`: 261011-d62 had left the
office view with no numbering at all.

`WorksheetDocxInstallStepsShapeTest` — **1 failed, 6 passed (50 assertions)**. The failure dumped
the real `word/document.xml`, which contained:

```
1. PHASE 1 — Pre-Start &amp; Access
2. Confirm site access and working area availability for Boardroom.
3. PHASE 2 — Second Fix
4. Terminate and test all structured cabling.

To contain: 1. Confirm site access and working area availability for Boardroom.
```

So on paper, step 2 was the first thing the engineer actually does.

## What changed

**`app/Support/WorksheetInstallSteps.php`** — `rows(mixed): list<array{kind,text}>` with
`KIND_HEADING` / `KIND_STEP`, plus `stepsOnly(array)` for "how many install steps are there".
`flatten()` and `flattenPhase()` now build rows; the phase label is the only `heading` producer.
**`lines()` and `text()` are kept and redefined on top of `rows()`** — `lines()` is literally the
text column of `rows()` — and every pre-existing unit assertion on them is untouched and green, so
no caller was broken. Ordinals are never stored in the text (D-06 unchanged).

**`resources/views/worksheets/public-show.blade.php`** — `rows()` plus `stepsOnly()`; the drawer
guard became `$stepCount > 0`; heading rows render as `<li class="phase">`, action rows as
`<li class="step" value="N">`. New CSS sits in the standalone `<style>` block beside the existing
rules at `:426-434`.

**`resources/views/worksheets/show.blade.php`** — numbered action rows and unnumbered heading rows,
rendered as `<div data-install-step="N">` / `<div data-install-phase>`. Empty chip untouched.

**`app/Services/WorksheetDocxService.php`** — phase titles become their own bold, unnumbered
paragraphs; the counter increments only on action rows.

### The numbering mechanism, and why it deviates from the letter of D-04

D-04 specified a CSS counter. Shipped instead: `ol.steps { list-style: none; }` with the ordinal
printed by `ol.steps li.step::before { content: attr(value) "."; }` from the value attribute on the
row itself. Same observable behaviour — single `<ol>`, no native marker, ordinal via `li::before`,
headings unnumbered and non-incrementing — but **one source of truth**: the number an engineer
reads is literally the number the test asserts in the markup. A CSS counter would have displayed a
number no test could see, so any drift between the asserted value and the displayed counter would
have made the numbering guard vacuous — the exact failure mode this task was warned about. The
value attribute is also the correct `<ol>` semantic and survives with CSS off and in print.

`right: 100%; padding-right: .3rem` rather than a fixed-width box, so a two- or three-digit ordinal
grows leftwards into the existing drawer padding instead of overflowing. A 12-step room is
ordinary, and it is now a test case.

### `ul.actions` — unchanged, verified

`.room-drawer-body ol.steps, ul.actions { margin:0; padding-left:1.3rem }` and
`.room-drawer-body ol.steps li, ul.actions li { … }` at `:426-434` are **byte-identical**. The diff
of `public-show.blade.php` against `f41bceca` contains exactly one line mentioning `actions`, and it
is a line of my own comment prose stating that those shared rules are deliberately not touched.
Every new rule is prefixed `.room-drawer-body ol.steps`, so `ul.actions` is still bulleted. It is
asserted too: `test_the_engineer_numbering_css_is_present_and_targets_action_rows_only` fails if
`ul.actions li.step::before` or a `list-style: none` on `ul.actions` ever appears.

## Non-vacuity

Every numbering assertion is against **markup**, parsed row by row:

- `engineerStepRows()` extracts `<li class="(phase|step)"( value="N")?>` from the one
  `<ol class="steps">` and compares the whole sequence, e.g.
  `[[phase,null],[step,1],[step,2],[phase,null],[step,3]]`. A test that only asserted the heading
  text was present — which is what 261011-d62 had — passes happily on the defect.
- `engineerStepsList()` asserts **exactly one** `<ol class="steps">` per room, so the sequence
  cannot be restarted by a second list appearing.
- Every `<li>` must declare its kind, so an unclassed row fails rather than being skipped.
- `test_ordinals_pass_nine_and_never_restart_at_a_phase_boundary` uses 7 + 5 actions and asserts
  8,9,10,11,12 after the second heading, plus the literal
  `<li class="step" value="10">Second fix action 3</li>`.
- Shapes (a) and (b) assert `[[step,1],[step,2]]` / three rows and the drawer count, on both the
  engineer link and the office view — they carry no headings and still number 1..N.
- The empty state asserts both sides: the office chip plus **zero** `data-install-*` rows, and no
  `<li class="step"` or `<li class="phase"` anywhere on the engineer link.
- The heading text is still asserted present **and escaped**:
  `<li class="phase">PHASE 1 — Pre-Start &amp; Access</li>`.
- DOCX: reads `word/document.xml` back out of the zip and asserts `1. Confirm site access…` and
  `2. Terminate and test…`, **and** that the document nowhere contains `. PHASE` — so an ordinal
  can never be glued to a phase label again — plus that `2. Confirm site access` is absent, which
  is precisely the pre-fix output.

### One pinned number was changed, upward in strength

`WorksheetInstallStepsShapeTest` asserted `Install Steps (5)` with the comment
"2 phase headings + 3 items = 5 lines in the drawer". That was the **defect recorded as if it were
the spec** — it is symptom 1. It now asserts the action count through a named
`SHAPE_C_ACTION_COUNT = 3`, with an `assertDontSee` on `Install Steps (5)` added alongside. No
assertion was weakened or deleted anywhere; the other nine 261011-d62 cases are untouched and green.

**`WorksheetShowViewParityTest:186-190` did not move.** Still `assertEquals(2, …)` on
`<li style="margin-bottom:.25rem;">`, and the office view was deliberately rendered as `<div>` rows
rather than `<li>` rows so that pin **cannot** be disturbed by this change. Suite: **5 passed, 18
assertions**, identical to before.

## Blade safety checks, per file

1. **Comment scan.** Every comment added by this task contains **no at-sign at all** — the
   directive-in-prose trap cannot fire on them. Verified mechanically: the added lines of the diff
   that contain an at-sign are **15 lines, all of them real paired directives**
   (if / else / endif, foreach / endforeach, php / endphp) and not one comment.
2. **`php -l` on the compiled views** after `view:clear` and recompile — *No syntax errors detected*
   for every file in `storage/framework/views`. Line counts: `worksheets.show` src 799 to compiled
   800; `worksheets.public-show` src **5440** to compiled **5122**, against src 5402 / compiled 5083
   before this task: +38 source, +39 compiled. **No silent swallow.**
3. **Rendered both pages for real**, booted on sqlite `:memory:` exactly as `phpunit.xml` does, with
   an explicit refuse-to-run guard if the connection is not `:memory:`, so
   `database/database.sqlite` was never opened. **HTTP 200 both.**

### `public-show.blade.php` pinned occurrence counts — before and after

| Literal | Before | After |
|---|---|---|
| raw-echo opener | 1 | **1** |
| ` x-data` | 1 | **1** |
| ` x-show` | 1 | **1** |
| ` x-model` | 2 | **2** |
| ` x-cloak` | 1 | **1** |

No second raw echo was added; every echo on this client-signed page stays escaped — note the
`&amp;` in the rendered markup below.

## What it actually looks like

**Engineer link**, actual rendered markup — the exact 2-phase / 7-action Boardroom from the brief:

```html
<details class="room-drawer amber">
  <summary><span>✅ Install Steps (7)</span><span class="chev">▾</span></summary>
  <div class="room-drawer-body">
    <ol class="steps">
      <li class="phase">PHASE 1 — Pre-Start &amp; Access</li>
      <li class="step" value="1">Confirm site access and working area availability for Boardroom.</li>
      <li class="step" value="2">Review RAMS and toolbox talk with all engineers.</li>
      <li class="step" value="3">Verify all equipment delivered and checked against schedule.</li>
      <li class="phase">PHASE 2 — Second Fix</li>
      <li class="step" value="4">Install display mounting brackets - verify wall substrate.</li>
      <li class="step" value="5">Mount and secure 2x Sony FW-85BZ40H.</li>
      <li class="step" value="6">Mount and secure Yealink MeetingBar A30.</li>
      <li class="step" value="7">Terminate and test all structured cabling.</li>
    </ol>
  </div>
</details>
```

**Read honestly at phone width** — root `17px`, drawer body padding `.85rem 1rem`, `ol.steps`
padding-left `1.3rem`: at ~390px the drawer leaves the text column ~332px. The heading row pulls
back to the left edge of the drawer body (`margin-left:-1.3rem`) and renders uppercase at
`.7rem / 800` in amber `#92400E` with `.06em` tracking, so it reads as a *label*, not as another
action — and the ordinal gutter beside it is empty. Each action row sits in the text column with a
grey `#9CA3AF` bold tabular ordinal in the 1.3rem gutter, right-aligned `.3rem` clear of the text,
exactly where the native `<ol>` marker used to sit, so the step text did not shift position. Action
lines wrap across the full column with no hanging-indent fight, because the ordinal is absolutely
positioned out of flow. 1. through 7. with the two headings interleaved; 10. to 12. grow leftwards
into the remaining 1rem of body padding and are not clipped.

**One honest limitation:** that reading is from the real rendered markup and the real CSS, not from
a screenshot. There is no Playwright in this repo and none was installed. The rendered HTML for both
pages was saved to the session scratchpad if you want to open it in a browser.

**Office view**, actual rendered markup, abridged:

```html
<div class="room-section-hdr">Install Steps</div>
<div style="font-size:.875rem;line-height:1.6;color:var(--text);">
  <div data-install-phase style="…text-transform:uppercase;color:#92400E;">PHASE 1 — Pre-Start &amp; Access</div>
  <div data-install-step="1" style="display:flex;gap:.45rem;align-items:baseline;…"><span style="min-width:1.4rem;text-align:right;…">1.</span><span style="white-space:pre-wrap;">Confirm site access and working area availability for Boardroom.</span></div>
  …
  <div data-install-phase style="…">PHASE 2 — Second Fix</div>
  <div data-install-step="4" …><span …>4.</span><span …>Install display mounting brackets - verify wall substrate.</span></div>
  … up to data-install-step="7"
</div>
```

The numbering 261011-d62 removed is back, for all three shapes, and the headings are amber uppercase
labels with no number.

**DOCX**, text runs read back out of `word/document.xml`, in order:

```
PHASE 1 — Pre-Start &amp; Access
1. Confirm site access and working area availability for Boardroom.
2. Review RAMS and toolbox talk with all engineers.
PHASE 2 — Second Fix
3. Mount and secure 2x Sony FW-85BZ40H.
4. Terminate and test all structured cabling.
```

The ordinals continue across the phase boundary — 3 follows 2 — so the document, the office view
and the engineer link all agree on what step 3 is.

## Verification — re-measured in the foreground, one suite per invocation, redirected, never piped

| Gate | Expected | Measured | Result |
|---|---|---|---|
| `-Filter WorksheetInstallStepsTest` (unit) | 24 / 34 pre-task | **41 passed, 64 assertions** | PASS |
| `-Filter WorksheetInstallStepsShapeTest` **RED** | must fail | **9 failed, 10 passed, 114 assertions** | PASS, failed as required |
| `-Filter WorksheetDocxInstallStepsShapeTest` **RED** | must fail | **1 failed, 6 passed, 50 assertions** | PASS, failed as required |
| `-Filter WorksheetInstallStepsShapeTest` GREEN | n/a | **20 passed, 140 assertions** | PASS |
| `-Filter WorksheetDocxInstallStepsShapeTest` GREEN | n/a | **7 passed, 53 assertions** | PASS |
| `-Filter WorksheetShowViewParityTest` | 5 passed, 18 assertions | **5 passed, 18 assertions** | PASS, pin intact at 2 |
| `-Path tests/Feature/Worksheets` | 287 passed, 2458 assertions | **300 passed, 2561 assertions** | PASS |
| `-Path tests/Feature/Surveys` | 88 passed, 717 assertions | **88 passed, 717 assertions** | PASS, exact |
| `-Baseline` | >= 159 passed AND 0 failed | **159 passed, 0 failed, 2 skipped** | PASS |
| `-Hashes` | all three MATCH 4abd2b24 | **all three MATCH** | PASS |

The Worksheets delta reconciles exactly: 287 + 10 new shape tests + 3 new DOCX tests = **300**;
2458 + 76 (shape: 64 to 140) + 27 (DOCX: 26 to 53) = **2561**. **Zero failures anywhere, and no
failure was explained away as pre-existing.**

## Deviations

1. **[D-04, mechanism]** The CSS counter was replaced by `content: attr(value) "."` reading the
   value attribute on the row. Behaviour is identical; rationale above — a counter would have put
   the displayed ordinal beyond the reach of any assertion, making the numbering guard vacuous,
   which is the precise failure mode this task warns about.
2. **[Rule 2 — correctness]** The ordinal gutter was first written as a fixed `width: 1.1rem` box,
   which a two-digit ordinal overflows at phone width. Changed to `right: 100%` before the commit,
   and a 12-action regression test was added. A 12-step room is the ordinary case, not an edge case.
3. **[Strengthened pin]** `Install Steps (5)` became the action count plus an `assertDontSee` on the
   old value. It was the defect written down as spec, and symptom 1 could not be guarded while it
   stood. Nothing was weakened.
4. **[Reported, not hidden]** No screenshot of the engineer link at phone width: no Playwright in
   this repo, and installing one was out of scope. The reading is from real rendered markup and real
   CSS, and the HTML is saved if you want to eyeball it.

## Prohibitions honoured

No migration, and none was needed — presentation only. No `migrate:fresh`; both render scripts
refuse to run unless the connection is sqlite `:memory:`, so `database/database.sqlite` was never
opened. No `git stash`, no `git clean`, nothing deleted. No `gsd-sdk query state.*`; **STATE.md
untouched**. Not pushed, not deployed. No branch created or switched — three commits on
`feat/worksheet-classifier-universal`. No token minted, rotated, mass-assigned or rendered: the
fixtures use `Worksheet::create`, so the creating hook mints by direct assignment and the omissions
in the model fillable list are preserved. No `device_label_photos.captured_by`,
`worksheet_signoffs.ip_address`, `worksheet_signoffs.user_agent` or `room_complete.*.completed_by`
rendered; LR-04 intact — nothing was added to the client-signed page but install-step text. The
`engineer-worksheet` IndexedDB offline queue untouched. The three baseline files byte-identical.

## Self-Check: PASSED

- `app/Support/WorksheetInstallSteps.php` — FOUND
- `app/Services/WorksheetDocxService.php` — FOUND
- `resources/views/worksheets/public-show.blade.php` — FOUND
- `resources/views/worksheets/show.blade.php` — FOUND
- `tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php` — FOUND
- `tests/Feature/Worksheets/WorksheetDocxInstallStepsShapeTest.php` — FOUND
- `tests/Unit/Support/WorksheetInstallStepsTest.php` — FOUND
- Commits `c372275c`, `40bddf6d`, `c9dfe160` — all present in `git log`
- Working tree clean of task changes; only the pre-existing untracked PNG clutter remains
