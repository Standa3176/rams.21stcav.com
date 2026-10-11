---
quick_id: 261011-f9l
slug: install-step-headings-vs-actions
type: bugfix
status: ready
date: 2026-10-11
follows: 261011-d62
files_modified:
  - app/Support/WorksheetInstallSteps.php
  - app/Services/WorksheetDocxService.php
  - resources/views/worksheets/public-show.blade.php
  - resources/views/worksheets/show.blade.php
  - tests/Unit/Support/WorksheetInstallStepsTest.php
  - tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php
  - tests/Feature/Worksheets/WorksheetDocxInstallStepsShapeTest.php
---

# 261011-f9l — Phase headings must be distinguishable from actions

## One root cause, three symptoms

`App\Support\WorksheetInstallSteps::flattenPhase()` emits a phase title as a **plain string**
(`'PHASE 1 — Pre-Start & Access'`) into the same flat `list<string>` as the real actions. No
consumer can tell a heading from an action, so:

1. **The engineer-link drawer count is wrong.** `public-show.blade.php:2040` renders
   `✅ Install Steps ({{ count($stepLines) }})`, which counts headings — a 2-phase, 7-action room
   advertises **"Install Steps (9)"**.
2. **The headings get ordinal numbers.** `:2044-2046` puts every row into one `<ol class="steps">`,
   so an engineer's "step 7" no longer means the 7th action.
3. **The office view and the DOCX cannot number actions at all** without numbering headings too.
   That is why 261011-d62 stripped shape (a)'s own `1.`/`2.` prefixes from the office view and left
   it with **no numbering whatsoever** — a visible regression introduced yesterday.

All three verified by the orchestrator on 2026-10-11 against live code at `f41bceca`.

## Decisions

- **D-01:** The helper gains a **structured** return —
  `rows(mixed): list<array{kind: 'heading'|'step', text: string}>`. Every consumer renders by `kind`.
- **D-02:** **`lines()` and `text()` keep working**, redefined in terms of `rows()`. They are the
  current public API: both Blade files and `WorksheetDocxService` call them, and
  `tests/Unit/Support/WorksheetInstallStepsTest` asserts them. **Do not break a caller you did not
  update.**
- **D-03:** The engineer-link drawer count counts **only `kind === 'step'`**.
- **D-04:** Actions are numbered in **one continuous sequence across the whole room**, not restarting
  per phase — an engineer saying "step 7" means the 7th action of that room. Implement with a single
  `<ol>`: `list-style: none`, a CSS counter incremented **only** on action rows, printed via
  `li::before`. Heading rows do not increment it and carry no ordinal.
- **D-05:** The office view **numbers its actions for every shape**, headings unnumbered. This
  restores what shape (a) lost and makes all three shapes consistent — shapes (b) and (c) carry no
  inherent numbers, so the surface supplies the ordinals. ⚠ **Do not** restore the literal `1.` text
  for shape (a) only; that would leave the office view numbered for one producer and bare for the
  other two.
- **D-06:** The helper keeps stripping leading `1.` / `1)` / `-` / `•` from input lines, with the
  `/u` flag it already carries. Each surface supplies its own ordinals; the stored text never does.
- **D-07:** The DOCX makes the same heading/action distinction.
- **D-08:** The empty state is unchanged: the office view's `Install steps being generated…` chip and
  the engineer link's absent drawer. A heading whose phase has no items still contributes nothing
  (that rule already exists and is correct — a heading with its actions lost reads as an
  unfollowable instruction).

## Tasks

### Task 1 — `rows()` on the helper

Add `rows()`; redefine `lines()` and `text()` on top of it. The `PHASE n — Title` label stays the
heading's `text` (it is the rendered wording on all three surfaces today, and this task is not a
copy change).

Extend `tests/Unit/Support/WorksheetInstallStepsTest` — **only to assert more**. Cover: shape (c)
yields `heading` then `step` rows in order; shapes (a) and (b) yield **only** `step` rows; the
degenerate inputs (`null`, `''`, `[]`, missing/blank `title`, empty `items`) behave as before; and
`lines()`/`text()` still return exactly what they returned before this task.

### Task 2 — RED first, then the three surfaces

Extend the two feature guards (`WorksheetInstallStepsShapeTest`,
`WorksheetDocxInstallStepsShapeTest`) and **prove them RED before touching any consumer.** Quote
the real failure output in the SUMMARY.

**The assertions that must exist** — for a fixture with **2 phases and a known action count**:

- **The drawer count equals the ACTION count, not action+heading.** ⚠ **This must FAIL before the
  fix** — it is symptom 1. Show it failing.
- **Rendered ordinals run 1..N over actions only, and no heading carries an ordinal.** Assert
  against markup, not just text presence.
- **The heading text is still present, and still escaped** (`Pre-Start &amp; Access`).
- **Shapes (a) and (b) — which have no headings — still number 1..N** exactly as before.
- **The empty state** still shows the office chip / no drawer, and no empty numbered row.
- **The DOCX** carries the same distinction: actions numbered, headings not.

⚠ **A test that only checks "the heading is present" would not catch the numbering bug at all** —
that is the entire point of this task.

### Task 3 — Fix the consumers

1. **Engineer link** (`public-show.blade.php`): count by `kind`; render heading rows as
   `<li class="phase">`. Add the counter CSS to the **`<style>` block** beside the existing rules at
   `:426-432`. ⚠ **`.room-drawer-body ol.steps li` currently shares its rule with
   `ul.actions li` — scope the new rules to `ol.steps` only and leave `ul.actions` bulleted and
   untouched.** `ol.steps` has `padding-left: 1.3rem`; keep the ordinal sitting where the marker was.
2. **Office view** (`show.blade.php`): number the actions, leave headings unnumbered, keep the
   existing empty chip.
3. **DOCX** (`WorksheetDocxService`): same distinction.

Then, for **each** Blade file edited: grep its comments for stray Blade directive tokens, run
`php -l` on the **compiled** view, and **render the page**. Report the engineer link at **phone
width** and say what you actually saw.

## Verification

Foreground, redirected to a file, **never piped**, one suite per invocation, **never backgrounded**.
The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test** (`-File` is PowerShell's own switch for the `.ps1`).

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter WorksheetInstallStepsShapeTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Filter WorksheetInstallStepsTest`,
`-Filter WorksheetDocxInstallStepsShapeTest`, `-Filter WorksheetShowViewParityTest`,
`-Path tests/Feature/Worksheets`, `-Path tests/Feature/Surveys`, `-Baseline`, `-Hashes`.

**Measured by the orchestrator in the foreground on 2026-10-11 — re-measure and compare, never
inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Worksheets` | **287 passed, 2458 assertions** |
| `tests/Feature/Surveys` | **88 passed, 717 assertions** |
| `WorksheetShowViewParityTest` | **5 passed, 18 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — gate is `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**There are ZERO pre-existing failures. A failure you see is yours** — and *"pre-existing and
unrelated"* is a claim to prove, not to assert.

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment.** A comment containing a bare
  PHP-block directive pairs with the next real closing directive and **silently deletes everything
  between, with no error** — on 2026-10-10 it ate a whole card, two includes and most of another,
  and the agent then called the resulting failures "pre-existing". **Fired five times in a
  fortnight, every time on an agent warned in the same prompt.** The defence that worked on
  2026-10-11: **write comments containing no `@` character at all.**
- ⚠⚠ `resources/views/worksheets/public-show.blade.php` is a **standalone ~5,400-line document**
  whose `<style>` block is the only place its CSS can live. It carries **pinned occurrence counts**:
  the raw-echo opener at exactly **one**, and ` x-data` 1, ` x-show` 1, ` x-model` 2, ` x-cloak` 1 —
  all currently correct. **Writing any of those literals in prose or a comment breaks the guard.**
  **Add no second raw echo** — every echo stays escaped; this page is signed by a client and the
  text is AI-generated.
- ⚠ `WorksheetShowViewParityTest:186-190` pins `works_summary_bullets` `<li>` rows at
  `assertEquals(2, …)`. That is a **different list** from the one being changed. **If it moves, STOP
  and report** — never adjust a pinned number.
- The three 261011-d62 guards shipped **yesterday** and are green. Changing the markup around them is
  expected; **a guard may only be changed to assert something STRONGER**, never weakened or deleted.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `Worksheet::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
- Do not touch the `engineer-worksheet` IndexedDB offline queue — schema-frozen at v1, real unsent
  photos on engineers' phones.
- Three files stay **byte-identical to `4abd2b24`**. **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted. NO MIGRATION** — this is presentation only. If you conclude one is needed,
  **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing` — no `.env.testing` exists; it falls back to
  `.env` where `DB_DATABASE` is commented out and would **drop every table** in
  `database/database.sqlite`, real local dev data.
- **NEVER** `gsd-sdk query state.advance-plan`, `state.update-progress`, or
  `state.add-roadmap-evolution`. **Do not touch STATE.md.**
- **NEVER** `git stash`. `git clean` is prohibited.
- **Do NOT push and do NOT deploy.** The orchestrator handles the deploy.

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
