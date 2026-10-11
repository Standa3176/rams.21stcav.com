---
quick_id: 261011-d62
slug: worksheet-install-steps-array-render
type: bugfix
status: ready
date: 2026-10-11
severity: live-defect
files_modified:
  - app/Support/WorksheetInstallSteps.php
  - resources/views/worksheets/show.blade.php
  - resources/views/worksheets/public-show.blade.php
  - tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php
---

# 261011-d62 — Worksheet install steps 500 on an array

## The defect, verified by the orchestrator on 2026-10-11

`generated_data.rooms[n].install_steps` can legitimately hold **three different shapes**, and the
two render paths only ever learned one. **Both surfaces hard-500 on the other two.**

**Settled, do not re-investigate:** the engineer link does **not** quietly print the word "Array".
`HandleExceptions.php:47` calls `error_reporting(-1)` and `:75` throws `ErrorException` for any
reported non-deprecation level, so PHP's "Array to string conversion" **E_WARNING becomes an
exception**. Both views return **500**.

### The write

`app/Services/WorksheetGeneratorService.php:543` — `$installSteps = $phasedPlan;` in the
"Fallback steps if AI empty" branch (`:538-545`), which fires when the install-step AI call returns
nothing **and** `$classified['install_hardware']` is non-empty. `buildPhasedPlan()` is `: array`
(`:772`) and returns a **nested phase structure**: a list of
`['step' => 1, 'title' => 'Pre-Start & Access', 'items' => ['Confirm site access…', …]]`.

So the defect fires **exactly when the AI is unavailable** — the degraded path that is supposed to
keep working.

### The two broken reads

- **`resources/views/worksheets/show.blade.php:587`** (office) — `{{ $room['install_steps'] }}`.
  `e(array)` throws `TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string,
  array given`. The `@if(! empty(...))` guard at `:586` does not help: a non-empty array passes
  `empty()` and reaches the echo.
- **`resources/views/worksheets/public-show.blade.php:1190`** (**the engineer link — the surface
  that matters**) — `trim((string) ($room['install_steps'] ?? ''))`, which becomes an
  `ErrorException` per above.

### The three shapes that must render

| | Shape | Producer |
|---|---|---|
| **a** | newline-delimited **string** | the AI path — `app/Core/AI/Prompts/WorksheetPrompt.php:203` `{ "install_steps": "1. Step one\n2. Step two" }` |
| **b** | **flat array** of step strings | `WorksheetEditAdapter` `append_install_step` / `replace_install_step`, writing `array_values($steps)` at `:256` and `:285` |
| **c** | **nested** `{step, title, items}` list | `WorksheetGeneratorService::buildPhasedPlan()` via the `:543` fallback |

## Decisions

- **D-01:** ⚠ **Do NOT "fix" this by making the service stop writing an array.**
  `WorksheetEditAdapter.php:249` documents *"install_steps may be a string (legacy) or array
  (post-Pass B worksheets)"*, and two live adapter operations write arrays. **An array is a
  supported shape. The render path is the defect.**
- **D-02:** The fix **hardens the read path**, because `generated_data` is a persisted JSON column —
  worksheets on live may already carry shape (c) and may already be 500ing. A write-side-only change
  would leave those broken forever.
- **D-03:** One shared helper, `app/Support/WorksheetInstallSteps.php`, with a single static entry
  point returning `array` of clean step lines from **any** of the three shapes (plus `null`, `''`,
  `[]`, and a nested shape with missing/blank `title` or `items`). `App\Support\SurveyPdfHelpers` is
  the house precedent for exactly this kind of shared render helper. Follow the existing idea in
  `WorksheetEditAdapter::stepsAsArray()` (string → `preg_split('/\r?\n/')`, array → `array_values`),
  which handles (a) and (b) but **not** (c).
- **D-04:** Shape (c) **flattens carrying its `title`**, with the phase's `items` following it.
  Titles must not be silently dropped — an engineer needs to know "Pre-Start & Access" from
  "Second Fix". ⚠ **Render it and confirm it reads sensibly at phone width**; if an undistinguished
  title line mixed into the action list reads as just another action, say so and propose how you
  distinguished it — but **do not add a new `{!! ` to do it** (see constraints).
- **D-05:** Each Blade edit is the **smallest possible**. The engineer link already splits lines and
  strips leading numbering/bullets at `:1191-1196`, so it needs the value normalised **before** that
  — ideally replacing those lines with one helper call. The office view keeps its existing
  `white-space:pre-wrap` div and echoes the joined lines. **No new unescaped echo anywhere.**
- **D-06:** The **empty state is preserved**: with no install steps, the office view must still show
  its existing `Install steps being generated…` chip (`:588-591`), and the engineer link its existing
  empty behaviour. A normaliser that turns "nothing" into one blank bullet is a regression.
- **D-07:** Whether `WorksheetGeneratorService:543` *should* be writing a nested structure into a key
  whose other producers write flat/string is a **separate question**. Say what you decided and why,
  but the read path is hardened **regardless** — do not make the read fix conditional on changing the
  write.

## Tasks

### Task 1 — The helper, with unit coverage

Create `app/Support/WorksheetInstallSteps.php`. Cover every shape and every degenerate input named in
D-03. Document in the class docblock **why** it exists (three live producers, one of them nested) and
cite them, so nobody "simplifies" it back to a cast.

### Task 2 — RED first, both views

Write `tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php` and **prove it RED before
touching the views**. **Quote the real failure output** in the SUMMARY — the actual `TypeError` text
for the office view and the actual `ErrorException` text for the engineer link.

**Six cases minimum** — three shapes × two views — plus the two empty-state cases from D-06:

1. shape (a) renders its steps on the office view, and on the engineer link
2. shape (b) renders every step on both
3. shape (c) renders its items **and its titles** on both
4. empty → office view shows `Install steps being generated…`; engineer link shows its empty state

⚠ **Asserting only that the page returns 200 would not prove an engineer can read the steps, which
is the entire point.** Assert the actual step text appears.

### Task 3 — Fix the two views

Apply D-05. Then, for **each** Blade file edited:

1. `grep` its comments for stray Blade directive tokens,
2. run `php -l` on the **compiled** view,
3. **render the page.**

## Verification

Foreground, redirected to a file, **never piped**, one suite per invocation, **never backgrounded**.
The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test** (`-File` is PowerShell's own switch for the `.ps1`).

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter WorksheetInstallStepsShapeTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Filter WorksheetShowViewParityTest`,
`-Path tests/Feature/Worksheets`, `-Path tests/Feature/Surveys`, `-Baseline`, `-Hashes`.

**Figures measured by the orchestrator in the foreground on 2026-10-10 — re-measure and compare,
never inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Worksheets` | **273 passed, 2367 assertions** |
| `tests/Feature/Surveys` | **88 passed, 717 assertions** |
| `WorksheetShowViewParityTest` | **5 passed, 17 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — gate is `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**There are ZERO pre-existing failures. A failure you see is yours** — and *"pre-existing and
unrelated"* is a claim to prove, not to assert. On 2026-10-10 an agent made that claim about three
failures it had caused with a Blade-comment accident.

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment.** A comment containing a bare
  PHP-block directive pairs with the next real closing directive and **silently deletes everything
  between, with no error** — on 2026-10-10 it ate a whole card, two includes and most of another.
  **Fired five times in a fortnight, every time on an agent warned about it in the same prompt.**
  Both files in this task are Blade. Follow Task 3's three-step check after each edit.
- ⚠⚠ `resources/views/worksheets/public-show.blade.php` is a **standalone document** (~5,400 lines,
  no layout, no bundler) carrying **pinned occurrence counts** that guard tests assert and that match
  comments: `{!! ` pinned at exactly **one** occurrence, and the dead-but-pinned Alpine directives at
  ` x-data` 1, ` x-show` 1, ` x-model` 2, ` x-cloak` 1. **Writing any of those literals in prose or a
  comment breaks the guard** — it has nearly happened seven times and did happen twice. **The fix
  must not add a second `{!! `.**
- ⚠ `tests/Feature/Worksheets/WorksheetShowViewParityTest:186-190` counts rendered
  `works_summary_bullets` `<li>` rows with `assertEquals(2, …)` — **the highest-risk pin here.** It
  never caught this defect because it hand-writes `'install_steps' => ''`. **Strengthening it is
  welcome; moving a pinned number is not.** A pin moves only BY NAME in the commit shipping the
  control that justifies it. **If a pin moves, STOP and report.**
- AI-generated and engineer-typed text is untrusted input on a page a **client signs** — every echo
  stays **escaped**.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `Worksheet::$fillable`'s deliberate omissions (`access_token`,
  `access_token_expires_at`, `submitted_notification_sent_at`). Never mint, rotate, mass-assign or
  render a token.
- Do not touch the `engineer-worksheet` IndexedDB offline queue — schema-frozen at v1, holding real
  unsent photos on engineers' phones.
- Three files stay **byte-identical to `4abd2b24`** (`resources/views/layouts/app.blade.php`,
  `resources/css/app.css`, `tailwind.config.js`). **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted.**
- **NO MIGRATION.** If you conclude one is needed, **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing` — there is no `.env.testing`; it falls back to
  `.env` where `DB_DATABASE` is commented out and would **drop every table** in
  `database/database.sqlite`, real local dev data.
- **NEVER** `gsd-sdk query state.advance-plan`, `state.update-progress`, or
  `state.add-roadmap-evolution` — all three corrupt `.planning/STATE.md`. **Do not touch STATE.md.**
- **NEVER** `git stash`. `git clean` is prohibited.
- **Do NOT push and do NOT deploy.** Report and stop.

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
