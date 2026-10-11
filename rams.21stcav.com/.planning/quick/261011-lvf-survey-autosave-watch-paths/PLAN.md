---
quick_id: 261011-lvf
slug: survey-autosave-watch-paths
type: bugfix
status: ready
date: 2026-10-11
severity: live-defect
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyAutosaveWatchPathsTest.php
---

# 261011-lvf — Per-room autosave is dead on the survey engineer link

## The defect, found on the live site 2026-10-11

Loading `https://rams.21stcav.com/survey/{token}` throws **81 `Uncaught SyntaxError: Unexpected
number`**, plus matching `Alpine Expression Error`s. The failing expression is
`rooms.0._ui.work_type`.

**`rooms.0._ui.x` is not valid JavaScript.** A numeric property after a dot is a syntax error; it
must be `rooms[0]`. Verified in the live page console: the dotted form throws
`SyntaxError: Unexpected number`, the bracket form is valid.

**Three `$watch` registrations are affected, all in `resources/views/surveys/show.blade.php`:**

| Line | Expression | Fires |
|---|---|---|
| `:2076` | `` `rooms.${idx}._ui.${f}` `` (step-1 fields) | `debouncedAutosave(1)` |
| `:2079` | `` `rooms.${idx}._ui.${f}` `` (step-2 fields) | `debouncedAutosave(2)` |
| `:2100` | `` `rooms.${idx}.engineer_feedback` `` (deep) | `debouncedAutosave(4)` |

The sibling at **`:2091`** — `` `engineerFeedbackSite.${f}` `` — carries **no numeric index**, is
valid, and works. That contrast is the proof of diagnosis: only the per-room watches fail.

**Cost:** every per-room **debounced autosave** (the save that fires while an engineer is typing)
has never run. The deep watch on `engineer_feedback` — multi-select chips, cable-route rows — has
never run either.

**Not total data loss:** each of the 9 sections carries `@focusout="autosave(N)"` and those work, so
leaving a field still saves. What is missing is the safety net for an engineer who types a long note
and whose phone dies, or who closes the tab, before blurring — and chip toggles, which may not blur
anything.

**Pre-existing, not from recent work.** Last touched by **46.8-05** (`704f571d`).

## Decisions

- **D-01:** Fix the three paths to bracket notation — `` `rooms[${idx}]._ui.${f}` `` and
  `` `rooms[${idx}].engineer_feedback` ``. **Change nothing else.** `:2091` is already correct and
  must stay as it is.
- **D-02:** ⚠ **These 81 watches have NEVER fired in production.** Enabling dead code on a live page
  is the real risk here, not the typo. The blast radius has been checked and is bounded — but
  **verify it rather than trusting this note**:
  - `debouncedAutosave(step)` (`:2264`) keeps **one timer per step** (`_autosaveTimers[step]`) with a
    **600 ms** debounce, so all rooms × fields collapse to at most **3** in-flight saves (steps 1, 2,
    4), not 81.
  - It is gated on `this.activeTab !== 'site' && this.currentRoom && !this.readonly`.
  - `rooms` is **server-seeded into `x-data`** before `init()` registers the watches, and Alpine's
    `$watch` does not fire on registration — so there is no load-time storm.
  **If measurement contradicts any of that, STOP and report** rather than shipping.
- **D-03:** A draft restore that writes into these fields will now trigger a save. That is correct
  behaviour (a restored draft should persist) and is explicitly accepted, not an oversight.

## Tasks

### Task 1 — Prove it RED

PHPUnit cannot execute Alpine, so a feature test can only assert the **source contract**: that no
`$watch` expression in this view uses dot-then-digit, and that the three paths use bracket notation.
Write `tests/Feature/Surveys/SurveyAutosaveWatchPathsTest.php` and **prove it RED first** — quote
the real failure.

Make the assertion **general, not literal**: a regex that fails on *any* `$watch` whose expression
would put a digit straight after a dot (so a future `rooms.${i}` anywhere in this view fails too),
plus a positive assertion that `:2091`'s valid site-level watch is untouched.

⚠ **A source-contract test cannot prove the autosave now fires.** Say so in the test's own docblock
rather than implying coverage you do not have — and do Task 3.

### Task 2 — Fix the three paths

Three string changes. Nothing else.

### Task 3 — Prove it in a real browser, because Task 1 cannot

PHPUnit has no layout engine and no Alpine. **Puppeteer is already in `node_modules`** — it was used
successfully for exactly this purpose earlier today (quick task `261011-hfb`).

Drive a real headless Chrome at phone size and report, with numbers:

1. **Console errors before and after.** Before: ~81 `Unexpected number`. After: **0** from this
   cause. Quote both counts.
2. **The autosave actually fires on typing.** Change a watched per-room field *without blurring*,
   wait past the 600 ms debounce, and show a `step-save` request going out. Before the fix there
   must be **none**; after, there must be one. **This is the assertion that proves the defect is
   really fixed** — the console count alone only proves the error stopped.
3. **No storm.** Type across several fields and confirm the in-flight saves stay bounded (one per
   step), not one per field.

Do **not** point the browser at the live site — use the local render or a local server, as
`261011-hfb` did. **Never POST to a real survey.**

If Puppeteer genuinely cannot be made to work, **say so plainly and report what you could not
prove** — do not substitute a weaker assertion and call it done.

## Verification

Foreground, redirected to a file, **never piped**, **one suite per invocation**, **never
backgrounded** (a backgrounded gate silently hangs ~26 minutes and a killed stall reports exit 0;
this happened twice today, once from putting two invocations in one call). PHP is not on the Bash
PATH, so `php … | tail` exits 0 while running nothing.

The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test.**

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveyAutosaveWatchPathsTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then, each in its own invocation: `-Path tests/Feature/Surveys`, `-Filter SurveyLinkBrandingTest`,
`-Filter SurveyRoomCaptureRefinementsTest`, `-Baseline`, `-Hashes`.

**Measured by the orchestrator in the foreground today — re-measure and compare, never inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Surveys` | **88 passed, 717 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**ZERO pre-existing failures. A failure you see is yours.**

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment** — it pairs with the next real
  closing directive and **silently deletes everything between, with no error**. It did exactly that
  to **this file** on 2026-10-10, and commit `6c58628b` is the repair. **Fired five times in a
  fortnight.** Write comments containing **no `@` character at all**.
- ⚠ `resources/views/surveys/show.blade.php` carries **two** `sv-spine` pins:
  `SurveyLinkBrandingTest:242` and `SurveyRoomCaptureRefinementsTest:42,283`
  (`PRE_EXISTING_SV_SPINE_COUNT = 15`). **If a pin moves, STOP and report** — never adjust a pinned
  number. This change should move neither.
- This view has **ZERO unescaped echoes** and must keep zero.
- Do not touch the `engineer-capture` IndexedDB store (schema-frozen at v1, real unsent photos on
  engineers' phones), the `svDraft_` / `svTab_` localStorage keys, or any other `$watch`.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `SiteSurvey::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
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
