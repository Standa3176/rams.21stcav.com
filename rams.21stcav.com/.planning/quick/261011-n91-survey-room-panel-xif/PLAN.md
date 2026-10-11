---
quick_id: 261011-n91
slug: survey-room-panel-xif
type: bugfix
status: ready
date: 2026-10-11
follows: 261011-lvf
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyRoomPanelGuardTest.php
---

# 261011-n91 — 109 console errors on the survey engineer link

## The defect

Loading the survey engineer link throws **109** `TypeError: Cannot read properties of undefined
(reading 'name')` / `(reading 'type')`. Found during the `261011-lvf` browser run, where the count
was **identical before and after** that fix — so it is independent and pre-existing.

**Cause.** The room section is wrapped at **`:717`**:

```
<div x-show="activeTab !== 'site' && currentRoomIdx !== null">
```

**`x-show` only toggles `display`. Alpine still evaluates every descendant binding.** On the Site
tab `currentRoomIdx` is `null`, so all **71** `rooms[currentRoomIdx]…` bindings evaluate
`rooms[null]` → `undefined` → reading `.name` throws.

**Harm.** Alpine catches each and continues, so the page works — this is noise, wasted evaluation,
and (the real cost) **109 errors that would bury a genuine one**. Treat it as hygiene on an
engineer-facing page, not an outage.

## Why the obvious fixes are wrong — checked, do not re-derive

- **Optional chaining everywhere: impossible.** Of the 71 bindings, **13 are `x-model`**, which
  needs a *settable* reference — `x-model="rooms[currentRoomIdx]?.name"` breaks assignment. (11 are
  already optional-chained, which is why the counts do not add up to a single sweep.)
- **`x-if` per room: wrong.** That would destroy and recreate the subtree on every room↔room switch.

## The fix

Convert the single container at `:717` from `x-show` to a **`<template x-if>`** wrapping the same
`<div>`, so the room subtree does not exist at all while the Site tab is active.

**Why this is the contained option — all verified by the orchestrator:**

- It is **one container** covering the whole room section.
- It mounts/unmounts only on **Site↔room** transitions. Between rooms `currentRoomIdx` stays
  non-null, so the subtree **stays mounted** and room↔room switching is unchanged.
- **Nothing imperative reaches into that subtree.** The only `getElementById` / `querySelector` calls
  in the file target the CSRF meta tag (in `<head>`) and `submit-form-top`. So no JS reference is
  orphaned.
- **No data lives in that DOM.** Room answers live in Alpine's `rooms` array — component state — so
  an unmount cannot lose them, and `x-model` re-reads state on remount.
- **No pinned directive counts** exist on this view. The only pins are `sv-spine`
  (`SurveyLinkBrandingTest:242` and `SurveyRoomCaptureRefinementsTest:42,283`, the latter
  `PRE_EXISTING_SV_SPINE_COUNT = 15`), and this change should move neither.

## Tasks

### Task 1 — Find the matching close, exactly

⚠ **This is the whole implementation risk.** `:717` opens a `<div>` whose matching `</div>` is
~1,100 lines later through deeply nested Blade. **Get it wrong and you silently re-parent half the
page** — the same class of damage the bare-directive-in-a-comment trap causes, with no error.

Establish the matching close **mechanically**, not by eye — and state in the SUMMARY which line you
determined it to be and how. Then wrap:

```
<template x-if="activeTab !== 'site' && currentRoomIdx !== null">
    <div>
        …unchanged…
    </div>
</template>
```

`x-if` requires **exactly one root element** inside the template — the existing `<div>` is it. Keep
that `<div>`'s own attributes except the now-redundant `x-show`.

### Task 2 — Prove it, RED first

**A PHPUnit test can assert the source contract**: the room panel is wrapped in `x-if`, the guard
expression is unchanged, and no `x-show` remains on that container. Prove RED and quote the real
failure.

**A PHPUnit test cannot prove the errors stopped.** Say so in the docblock, and do Task 3.

### Task 3 — Browser proof (mandatory)

**Puppeteer is in `node_modules`** and was used for this twice today — read
`.planning/quick/261011-lvf-survey-autosave-watch-paths/SUMMARY.md` for the exact method
(local render, local server, never the live site, never POST to a real survey).

Report, with numbers:

1. **`reading 'name'/'type'` errors on load: before vs after.** Before ≈109 (scales with rooms);
   after should be **0**.
2. **Room↔room switching does NOT remount** — switch between two rooms and show the subtree was not
   destroyed/recreated (e.g. tag a node on first mount and confirm it survives). This is the
   behaviour claim that justifies choosing `x-if` here, so prove it rather than asserting it.
3. **A Site→room→Site→room round trip preserves room answers** — type into a room field, go to Site,
   come back, and confirm the value is still there. This is the unmount-safety claim.
4. ⚠ **Today's autosave fix still works** (`261011-lvf`): type into a watched per-room field
   **without blurring**, wait past 600 ms, and show a `step-save` request still going out. **Do not
   regress it.**

If Puppeteer cannot be made to work, **say so plainly and report what you could not prove.** Do not
substitute a weaker assertion.

## Verification

Foreground, redirected to a file, **never piped**, **one suite per invocation**, **never
backgrounded** — a backgrounded gate silently hangs ~26 minutes and a killed stall reports exit 0.
This happened **three times today**; if it happens, kill it, clear stale `php.exe`, re-run clean.
PHP is not on the Bash PATH, so `php … | tail` exits 0 while running nothing.

The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test.**

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveyRoomPanelGuardTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Path tests/Feature/Surveys`, `-Filter SurveyLinkBrandingTest`,
`-Filter SurveyRoomCaptureRefinementsTest`, `-Filter SurveyAutosaveWatchPathsTest`, `-Baseline`,
`-Hashes`.

**Measured by the orchestrator in the foreground today — re-measure and compare, never inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Surveys` | **95 passed, 735 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**ZERO pre-existing failures. A failure you see is yours.**

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment.** It pairs with the next real
  closing directive and **silently deletes everything between, with no error** — it did exactly that
  **to this file** on 2026-10-10 (`6c58628b` is the repair). **Fired five times in a fortnight.**
  Write comments containing **no `@` character at all**.
- ⚠ Two `sv-spine` pins guard this view. **If a pin moves, STOP and report** — never adjust one.
- This view has **ZERO unescaped echoes** and must keep zero.
- Do not touch the `engineer-capture` IndexedDB store (schema-frozen at v1, real unsent photos on
  engineers' phones), the `svDraft_` / `svTab_` keys, or any `$watch` — `261011-lvf` fixed those
  **today** and they must stay bracketed.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `SiteSurvey::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
- Three files stay **byte-identical to `4abd2b24`**. **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted. NO MIGRATION.** If you conclude one is needed, **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing`; **never** `gsd-sdk query state.*` (**do not
  touch STATE.md**); **never** `git stash`; `git clean` is prohibited.
- **Do NOT push and do NOT deploy.**

⚠ **If the matching close cannot be established with confidence, STOP and report.** A wrong wrap is
far worse than 109 console warnings.

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
