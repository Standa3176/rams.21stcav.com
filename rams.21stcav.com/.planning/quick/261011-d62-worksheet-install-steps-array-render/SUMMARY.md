---
quick_id: 261011-d62
slug: worksheet-install-steps-array-render
type: bugfix
status: complete
date: 2026-10-11
branch: feat/worksheet-classifier-universal
severity: live-defect
commits:
  - 00029168 feat(261011-d62) — the WorksheetInstallSteps normaliser + 24 unit tests
  - 43e04bf1 test(261011-d62) — WorksheetInstallStepsShapeTest, proven RED first
  - 56cddc77 fix(261011-d62) — both views render all three shapes
  - 4fa97d58 test(261011-d62) — parity fixture carries a real install_steps shape
files_modified:
  - app/Support/WorksheetInstallSteps.php (new)
  - tests/Unit/Support/WorksheetInstallStepsTest.php (new)
  - tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php (new)
  - resources/views/worksheets/show.blade.php
  - resources/views/worksheets/public-show.blade.php
  - tests/Feature/Worksheets/WorksheetShowViewParityTest.php
---

# 261011-d62 — Worksheet install steps 500 on an array — SUMMARY

One shared read-path normaliser, `App\Support\WorksheetInstallSteps`, now stands between
`generated_data.rooms[n].install_steps` and both render surfaces, so all three live shapes render
instead of two of them returning 500.

## RED first, and the real failure output

`WorksheetInstallStepsShapeTest` was run against the **unfixed** views before either Blade file was
touched: **8 failed, 2 passed (34 assertions)**. Only the two shape-(a) cases passed — exactly the
one shape the views had learned.

**Office view** (`resources/views/worksheets/show.blade.php`), verbatim:

```
TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, array given
  in ...\vendor\laravel\framework\src\Illuminate\Support\helpers.php:137
Next Illuminate\View\ViewException: htmlspecialchars(): Argument #1 ($string) must be of
  type string, array given (View: ...\resources\views\worksheets\show.blade.php)
  in ...\vendor\laravel\framework\src\Illuminate\Support\helpers.php:137
...
Failed asserting that 500 is identical to 200.
```

**Engineer link** (`resources/views/worksheets/public-show.blade.php`), verbatim:

```
ErrorException: Array to string conversion
  in ...\storage\framework\views\67a754e15237d49bea86bcfcd1335850.php:1110
#0 ...\Illuminate\Foundation\Bootstrap\HandleExceptions.php(258):
   Illuminate\Foundation\Bootstrap\HandleExceptions->handleError(2, 'Array to string...', ..., 1110)
Next Illuminate\View\ViewException: Array to string conversion
  (View: ...\resources\views\worksheets\public-show.blade.php)
```

The orchestrator's ruling is confirmed independently: the engineer link does **not** quietly print
"Array". `handleError(2, 'Array to string...')` appears in the trace — the E_WARNING is promoted and
thrown, and the page is a 500.

One failure was a shape the plan did not name: `install_steps` as a **whitespace-only string**.
`! empty('   ')` is true, so the office view rendered an empty pre-wrap div instead of its
"Install steps being generated" chip. The `!== ''` guard fixes that too.

## What changed

**`app/Support/WorksheetInstallSteps.php`** (new) — `lines(mixed): array` and `text(mixed): string`.
The class docblock names and cites all three producers (`WorksheetPrompt.php:203`,
`WorksheetEditAdapter.php:256`/`:285`, `WorksheetGeneratorService.php:543` via `buildPhasedPlan()`)
and states why the read path is hardened rather than the write narrowed, so nobody simplifies it
back to a cast. 24 unit tests, 34 assertions: every shape, nested phases with missing/blank
title/items, numeric-string `step`, mixed shapes in one list, a doubly-wrapped flat array, and eight
degenerate inputs (`null`, `''`, whitespace, `[]`, all-blank array, `false`, `true`, object).

**`resources/views/worksheets/show.blade.php`** — `$installStepsText` added to the room's existing
php block (Laravel 12 — the short-form inline variant is gone, so no new block was introduced).
The guard became `$installStepsText !== ''` and the existing `white-space:pre-wrap` div echoes it.
The "Install steps being generated" chip is untouched.

**`resources/views/worksheets/public-show.blade.php`** — the inline cast + split + strip at
`:1190-1196` collapsed into **one** helper call. The drawer, its count and its `<ol class="steps">`
are unchanged.

**`tests/Feature/Worksheets/WorksheetShowViewParityTest.php`** — the whitespace-filtering fixture's
`'install_steps' => ''` (the reason the pinned `<li>` count never caught this defect) now carries
the nested shape, plus one `assertSee` for its item. **The pin did not move**: still
`assertEquals(2, ...)`. Suite went 5 passed / 17 assertions to 5 passed / **18** assertions — same
five tests, one added assertion.

## Shape (c): how the titles render, and how it actually looked

Each phase contributes a heading line `PHASE {n} — {Title}` ahead of its items. The uppercase
`PHASE n —` token is what distinguishes a heading from an action inside a plain, **escaped** list —
no markup is emitted and **no second raw-echo directive was added**. A phase with no items
contributes nothing (a heading whose actions were lost would read as an instruction that cannot be
followed).

Both pages were rendered for real — booted on sqlite `:memory:` exactly as `phpunit.xml` does, with
an explicit refuse-to-run guard if the connection is not `:memory:`, so the real
`database/database.sqlite` was never opened. Both returned **HTTP 200**.

Office view, actual markup:

```html
<div class="room-section-hdr">Install Steps</div>
<div style="...white-space:pre-wrap;">PHASE 1 — Pre-Start &amp; Access
Confirm site access and working area availability for Boardroom.
Review RAMS and toolbox talk with all engineers.
Verify all equipment delivered and checked against schedule.
PHASE 2 — First Fix
Install display mounting brackets — verify wall substrate and use appropriate fixings.
PHASE 3 — Second Fix
Mount and secure 2x Sony FW-85BZ40H.
Mount and secure Yealink MeetingBar A30.</div>
```

Engineer link, actual markup:

```html
<details class="room-drawer amber">
  <summary><span>Install Steps (9)</span><span class="chev">v</span></summary>
  <div class="room-drawer-body">
    <ol class="steps">
      <li>PHASE 1 — Pre-Start &amp; Access</li>
      <li>Confirm site access and working area availability for Boardroom.</li>
      ...
      <li>PHASE 3 — Second Fix</li>
      <li>Mount and secure Yealink MeetingBar A30.</li>
    </ol>
  </div>
</details>
```

Read honestly at phone width: the heading does **not** read as just another action — the uppercase
`PHASE n —` marker carries it. But the `<ol>` gives each heading an ordinal of its own and the
drawer count (`9`) counts headings alongside actions, so the numbers no longer equal the number of
actions. Fixing that needs CSS counters plus a per-row class in the standalone `<style>` block of a
pinned 5,400-line document — materially larger than D-05's "smallest possible". Logged as
`D62-DEF-02`, readability not correctness.

## Blade safety checks (Task 3's three steps, per file)

1. **Comment scan.** My new comments contain **no `@` character at all** — the directive-in-prose
   trap cannot fire on them. The pre-existing directive tokens inside `//` comments at
   `public-show.blade.php:742-743, 936, 1164, 1306` and the Blade comment at `:968` are untouched
   and predate this task.
2. **`php -l` on the compiled views** after `view:clear` + recompile:
   `36b1fb29d0a052879e35f541ac6e469f.php` (show) and `67a754e15237d49bea86bcfcd1335850.php`
   (public-show) — *No syntax errors detected* for both. Compiled line counts 783 / 5083 against
   source 5401 for public-show: no silent swallow.
3. **Rendered both pages.** HTTP 200 both, markup quoted above.

### `public-show.blade.php` pinned occurrence counts — before and after

| Literal | Before | After |
|---|---|---|
| raw-echo opener | 1 | **1** |
| ` x-data` | 1 | **1** |
| ` x-show` | 1 | **1** |
| ` x-model` | 2 | **2** |
| ` x-cloak` | 1 | **1** |

No new unescaped echo anywhere; every echo on the client-signed page stays escaped (`&amp;` in the
rendered output above).

## Verification — re-measured in the foreground, one suite per invocation

| Gate | Expected | Measured | Result |
|---|---|---|---|
| `-Filter WorksheetInstallStepsTest` (unit) | n/a (new) | **24 passed, 34 assertions** | PASS |
| `-Filter WorksheetInstallStepsShapeTest` RED | must fail | **8 failed, 2 passed, 34 assertions** | PASS |
| `-Filter WorksheetInstallStepsShapeTest` GREEN | n/a (new) | **10 passed, 64 assertions** | PASS |
| `-Filter WorksheetShowViewParityTest` | 5 passed, 17 assertions | **5 passed, 18 assertions** | PASS, pin intact |
| `-Path tests/Feature/Worksheets` | 273 passed, 2367 assertions | **283 passed, 2432 assertions** | PASS |
| `-Path tests/Feature/Surveys` | 88 passed, 717 assertions | **88 passed, 717 assertions** | PASS, exact |
| `-Baseline` | >= 159 passed AND 0 failed | **159 passed, 0 failed, 2 skipped** | PASS |
| `-Hashes` | all three MATCH 4abd2b24 | **all three MATCH** (run before AND after) | PASS |

The Worksheets delta reconciles exactly: 273 + 10 new tests = 283; 2367 + 64 (new file) + 1 (parity
`assertSee`) = 2432. **Zero failures anywhere.**

## D-07 — the `WorksheetGeneratorService:543` write

**Left alone, deliberately.** `buildPhasedPlan()`'s nested `{step, title, items}` structure carries
real information the flat shapes cannot — phase grouping — and it is the only producer that has it.
Narrowing the write would also be worthless on its own: `generated_data` is a persisted JSON column,
so every worksheet already saved with shape (b) or (c) would stay broken. And `WorksheetEditAdapter`
writes arrays from two live operations and documents the array shape at `:249`, so "install_steps is
always a string" is not a true invariant and cannot be restored by editing one assignment.

The honest criticism of `:543` is narrower than the plan's framing: it is not that an array is
wrong, it is that the generator writes a shape **no other producer and no consumer understood**, and
it does so on the path taken when the AI is unavailable — so the least-tested shape landed on the
least-attended code path. The normaliser removes the consequence. If a future phase wants one
canonical shape, the right move is a dedicated backfill plus a write-side flatten, as its own
planned task with its own RED proof — not a one-line change smuggled into a read-path fix.

## Deviations

1. **[Rule 1 — Bug] The bullet regex had no `/u`.** The inline strip the engineer link used was
   byte-oriented, so the 3-byte bullet glyph matched a single byte and a bulleted AI line rendered
   as mojibake. The helper's version carries `/u` (and an ASCII fallback for invalid UTF-8, which
   keeps the line rather than dropping a step). Covered by
   `test_shape_a_handles_crlf_bullets_and_blank_lines`.
2. **[Consequence, reported not hidden] The office view no longer shows shape (a)'s literal
   `1.` / `2.` prefixes.** The helper strips list numbering so both surfaces show the same text and
   so the engineer link's `<ol>` does not double-number — which is what D-05 asks for ("echoes the
   joined lines"). Rendered and checked: the lines stay in order, one per line, in the existing
   pre-wrap div. Information lost is the ordinals only.
3. **[Scope boundary] `WorksheetDocxService.php:295` carries the identical defect and was NOT
   fixed.** See `deferred-items.md` -> `D62-DEF-01`. It is a pre-existing failure on a surface
   PLAN.md's `files_modified` does not name, not a regression from this task, so it is logged rather
   than fixed. It is the sharpest finding here and is called out in the handback.

## Prohibitions honoured

No migration. No `migrate:fresh`. No `git stash`, no `git clean`, nothing deleted. No
`gsd-sdk query state.*`; `STATE.md` untouched. Not pushed, not deployed. No branch created or
switched — four commits on `feat/worksheet-classifier-universal`. No token minted, rotated,
mass-assigned or rendered (fixtures use `Worksheet::create` so the creating hook mints by direct
assignment, preserving the model's `$fillable` omissions). No `device_label_photos.captured_by`,
`worksheet_signoffs.ip_address`/`user_agent` or `room_complete.*.completed_by` rendered. The
`engineer-worksheet` IndexedDB queue untouched. Three baseline files byte-identical.

## Self-Check: PASSED

- `app/Support/WorksheetInstallSteps.php` — FOUND
- `tests/Unit/Support/WorksheetInstallStepsTest.php` — FOUND
- `tests/Feature/Worksheets/WorksheetInstallStepsShapeTest.php` — FOUND
- Commits `00029168`, `43e04bf1`, `56cddc77`, `4fa97d58` — all present in `git log`
- Working tree clean of task changes; only pre-existing untracked PNG clutter remains
