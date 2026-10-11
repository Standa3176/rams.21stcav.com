---
quick_id: 261011-hfb
slug: signoff-cockpit-serials
type: feature
status: ready
date: 2026-10-11
files_modified:
  - resources/views/worksheets/public-show.blade.php
  - resources/views/projects/cockpit.blade.php
  - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  - tests/Feature/Worksheets/WorksheetSignoffPlacementTest.php
  - tests/Feature/Cockpit/CockpitProjectDataAndDocumentsTest.php
---

# 261011-hfb — Sign-off placement, cockpit surfacing, serial guidance

Three changes the user asked for on 2026-10-11, after looking at a live worksheet and the cockpit.
**Two of the three are discoverability, not missing features** — the things exist and work; nobody can
find them.

## Task A — The sign-off moves into the Site tab

**The user's choice**, made with the risk stated (an engineer who never opens Site won't see it).

Today there is exactly **one** signature pad and it is **not inside any room panel** — it is a
sibling of the tab panels (`sig-pad → sig-pad-wrap → form-group → signoff-form → card → wrap →
BODY`), so it renders below *whichever* tab is showing. That is why it reads as a per-room sign-off.
Functionally it has always been one signature for the whole worksheet.

Move the `Client Sign-Off` card **inside the Site tab panel** so it appears once, at the end of
site details.

### ⚠ THE TRAP THAT WILL SILENTLY BREAK CLIENT SIGNING — handle it explicitly

`resizeCanvas()` (`:2423`) sizes the pad from `canvas.getBoundingClientRect()`. **Inside a hidden
tab panel that rect is 0×0**, and the code's own `Math.max(1, …)` floors it to a **1×1 pixel
canvas**. The initial call is `requestAnimationFrame(resizeCanvas)` at `:2577`, which runs on load.

So: an engineer whose tab state restores to a **room** tab (tab persistence exists) loads the page
with the Site panel hidden → the pad initialises 1×1 → they later switch to Site → **the pad is
dead**. The only existing recovery is the `resize` listener, which the 260919-chb comment says is
deliberately gated to **genuine width changes** — switching tabs is not one, so it would never fire.

**Required:** call `resizeCanvas()` when the Site tab becomes visible, and make it idempotent /
safe to call repeatedly. **Preserve any already-drawn signature across that call** — `clearSignature`
and the restore path at `:2595-2601` both already re-draw from a snapshot, so follow that pattern
rather than inventing one.

**Prove it:** a test that renders with the restored tab set to a room, switches to Site, and asserts
the pad has real dimensions. If that cannot be asserted server-side, say so plainly and verify it in
a real browser at phone width, reporting what you saw. **Do not claim it works without evidence.**

### Make it findable

Because the card no longer sits under every tab, add a clear pointer so an engineer reaches it —
for example, on the last room, or in the existing ready-for-sign-off signal. Keep the existing
`signoff-block-top` banner working. Use your judgement, keep it small, and say what you chose.

## Task B — The cockpit surfaces project data and documents

Today the cockpit's only links are a small grey note at the very bottom, under the activity panel:
`Open full project · Edit AV Works Summary` (`cockpit.blade.php:275-280`). The second is
deep-linked to one section and is not what "edit project data" means to the user.

Surface, as real controls rather than a footnote:

1. **Project data** → `project-packages.review.show` (the page itself, **not** deep-linked to
   `#s-room-overviews`).
2. **The branded documents, which already exist and are already generated** — currently linked only
   from `projects/show.blade.php` and `site-survey/edit.blade.php`:

   | | Survey | Worksheet |
   |---|---|---|
   | PDF | `site-surveys.pdf` with `?internal=0` (client) and `?internal=1` (internal) | `worksheets.engineer-report-pdf` |
   | Word | `site-surveys.docx` | `worksheets.download` (DOCX) |
   | Other | `site-surveys.completed-report` + `.xlsx` | — |

   **Label them Internal vs Client-facing**, as `site-survey/edit.blade.php` already does. Only
   render a link when the underlying document actually exists — a dead download is worse than none.

### ⚠ THE COCKPIT READ-ONLY FENCE — this is the part that breaks builds

`tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` pins the cockpit's surface:

- `DEFERRED_AFFORDANCES` is pinned at **19** by a **multi-line** `assertCount` (a single-line grep
  will not find it) and carries its own history: `15 → 18 → 19 → 18 → 21 → 20 → 19`. Its message
  says **"Move this number BY NAME, never to a floor."**
- `FORBIDDEN_MARKUP` 2, `WRITE_SURFACE_TABLES` 13, `BANNED_HANDLER_ATTRIBUTES` 9, plus a judged-form
  count with the same BY-NAME rule.

⚠ **`'Edit details' => 'Phase 49'` is an entry in `DEFERRED_AFFORDANCES`.** Project-data editing was
**deliberately deferred to Phase 49**, and this task pulls it forward **at the user's explicit
request on 2026-10-11**. Lift that entry **by name**, move the count 19 → 18 **in the same commit
that ships the control**, and record in the test's own comment that it was pulled forward and why.
Do not lift any other entry — `'Add document'`, `'Open register'` and `'Export CSV'` are Phase 48
and stay.

⚠ **A fence lift is NOT local.** Sibling cockpit test files carry hardcoded totals that depend on
these counts — that has bitten this project **twice**. After changing the fence, run the whole
`tests/Feature/Cockpit` path, not just the fence file.

If the document links do **not** correspond to an existing deferred entry, they are new surface
rather than a lift — say which, and do not invent a count move that isn't needed.

## Task C — Tell the engineer to capture serials

The capture works and is not missing: **45 "📷 Box Serial Label" buttons** on the live worksheet
checked today; on Board Room, 3 of 4 kit rows have one (the *Tilting Wall mount* is correctly
excluded as a mount). It is simply **inside the collapsed Kit List drawer, per kit row**.

**The user's own words:** *"Add clear instruction for engineer to remind them to take serials and
where the section is etc"*.

So: add on-page guidance that tells the engineer **(a)** that serial capture is expected, and
**(b)** where it is — inside Kit List, on each hardware row. Match the page's existing instruction
style (there is already guidance copy near the top of the engineer link). **Do not** add a count, a
typed serial field, or any new capture control — those were offered and not chosen.

## Verification

Foreground, redirected to a file, **never piped**, one suite per invocation, **never backgrounded**.
The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test** (`-File` is PowerShell's own switch for the `.ps1`).

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Path tests/Feature/Cockpit > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Path tests/Feature/Worksheets`, `-Path tests/Feature/Surveys`,
`-Filter CockpitReadOnlyFenceTest`, `-Filter WorksheetShowViewParityTest`, `-Baseline`, `-Hashes`.

**Measured by the orchestrator in the foreground on 2026-10-11 — re-measure and compare, never
inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Worksheets` | **300 passed, 2561 assertions** |
| `tests/Feature/Surveys` | **88 passed, 717 assertions** |
| `tests/Feature/Cockpit` | **462 passed** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**There are ZERO pre-existing failures. A failure you see is yours** — *"pre-existing and
unrelated"* is a claim to prove, not to assert.

## Non-vacuity

Prove RED first where a behaviour changes, and **quote the real failure output**. Assert both sides:

- the sign-off card **is** inside the Site panel and **is not** inside any room panel;
- the pad has **real dimensions** after switching to Site from a room tab (the 1×1 trap);
- there is still exactly **one** signature pad;
- the cockpit renders the project-data link and each document link **only when the document exists**;
- the fence count moved **by name**, with the lifted entry named.

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment.** It pairs with the next real
  closing directive and **silently deletes everything between, with no error** — it ate a card, two
  includes and most of another on 2026-10-10. **Fired five times in a fortnight.** The defence that
  worked twice since: **write comments containing no `@` character at all.**
- ⚠⚠ `resources/views/worksheets/public-show.blade.php` is a standalone ~5,440-line document with
  **pinned occurrence counts**: raw-echo opener exactly **1**, ` x-data` 1, ` x-show` 1, ` x-model`
  2, ` x-cloak` 1. Writing any of those literals in prose or a comment breaks the guard. **Add no
  second raw echo** — every echo stays escaped; a **client signs this page**.
- ⚠ Do not touch the `engineer-worksheet` IndexedDB offline queue (schema-frozen at v1, real unsent
  photos on engineers' phones). Moving the sign-off must not disturb the capture-lock behaviour:
  once a signoff row exists, every capture endpoint locks.
- ⚠ `WorksheetShowViewParityTest:186-190` pins `<li>` rows at `assertEquals(2, …)`. **If a pin
  moves, STOP and report.**
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `Worksheet::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
- Three files stay **byte-identical to `4abd2b24`**. **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted. NO MIGRATION.** If you conclude one is needed, **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing` — no `.env.testing`; it falls back to `.env`
  where `DB_DATABASE` is commented out and would **drop every table** in
  `database/database.sqlite`, real local dev data.
- **NEVER** `gsd-sdk query state.advance-plan`, `state.update-progress`, or
  `state.add-roadmap-evolution`. **Do not touch STATE.md.**
- **NEVER** `git stash`. `git clean` is prohibited.
- **Do NOT push and do NOT deploy.**

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
