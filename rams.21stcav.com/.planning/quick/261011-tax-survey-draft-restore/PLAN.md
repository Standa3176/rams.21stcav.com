---
quick_id: 261011-tax
slug: survey-draft-restore
type: bugfix
status: ready
date: 2026-10-11
severity: data-loss
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyDraftRestoreTest.php
---

# 261011-tax — An offline refresh looks like it loses everything, and can genuinely lose it

## Reported from the real airplane-mode walk, 2026-10-11

> *"in airplane mode, engineer can see the link if it was loaded before airplane mode, can fill in
> the fields but loses everything if they accidentally refresh"*

## Root cause

The survey's offline draft queue is a **send** queue with **no restore half**. Its own docblock says
it was *"Ported from the worksheet's DraftStore … BY SHAPE"* — and the shape that was ported is the
write-and-drain half only.

| Mechanism | Worksheet (`public-show.blade.php` `_notesInit`) | Survey (`surveys/show.blade.php` `init()`) |
|---|---|---|
| Restore a held draft **into the field** on load | ✅ `DraftStore.get(...)` → `el.value = held.value` | ❌ **missing** |
| **Drain on load** | ✅ `_notesDrainAll('load')` | ❌ **missing** |
| Drain on `online` event | ✅ | ✅ |
| **Retry timer** | ✅ `setInterval(..., NOTES_RETRY_MS)` | ❌ **missing** |

The worksheet even states the rule this fix needs, in its own comment:

> *"A draft held from the LAST session is newer than whatever the server rendered — it was typed
> after that render — so it goes back into the field, and the field says so."*

**Three consequences, in increasing severity:**

1. **What the user saw.** Refresh offline → the page re-renders from server data → fields look
   empty. The typing is **still in localStorage**, just invisible.
2. ⚠ **Silent permanent loss, not yet observed.** With no drain-on-load, an engineer who refreshes
   offline, closes the tab, and reopens later **already online** fires **no `online` event** — so
   the draft never sends. It sits in localStorage until it is overwritten or cleared.
3. ⚠ **Overwrite.** The queue is "latest wins" per room+step. After an offline refresh the engineer
   re-types into empty fields; the next failed autosave **overwrites the good draft** with the
   thinner one.

⚠ **This got more load-bearing today.** Quick task `261011-lvf` repaired 81 dead `$watch`
registrations, so per-room autosave now actually fires while typing — which means the draft queue is
being populated far more than it ever was before.

## Decisions

- **D-01:** ⚠ **DO NOT change the stored draft entry shape.** It is
  `{ room_index, step, data, queuedAt }` keyed `"<roomIndex>:<step>"` under `svDraft_<SURVEY_ID>`.
  **Engineers may have real queued drafts in localStorage right now**; a shape change strands them.
  Add behaviour around the existing shape, never a migration of it. (Same class of constraint as the
  frozen IndexedDB photo store — which this queue must continue never to touch.)
- **D-02:** Restore by **inverting `buildStepPayload`, explicitly per step.** The payload is not the
  room shape, so the mapping must be written out:
  - step 1 → `r.name`, `r.type`, `r._ui.work_type`
  - step 2 → `r._ui.quick_notes`, `power_available`, `network_available`, `access_issues`,
    `access_issues_notes`, `working_at_height`, `client_present`
  - step 4 → infrastructure + `engineer_feedback`
  - step 5 → equipment
  - step 6 → `r._ui.working_at_height`, `r.risks`
  - steps 3, 7, 8 → **nothing to restore** (3 is photos; 7 and 8 send `{}` — they are UI-only and
    discarded, which is a separate known issue being handled in its own phase)
  **Restore only keys the draft actually carries** — never clobber a field the draft does not
  mention.
- **D-03:** Restore **before Alpine's first paint**, inside `init()`. `<body>` carries `x-cloak`, so
  there is no flash. It must land before the `$watch` registrations, so restoring does not itself
  trigger an autosave storm.
- **D-04:** **Tell the engineer.** A silent restore is nearly as bad as a silent loss — they need to
  know the figures on screen are their unsent work, not the server's. Reuse the page's existing
  status wording; do not invent a new visual language.
- **D-05:** Add **drain-on-load** and a **retry timer**, mirroring the worksheet. Guard both with the
  existing `readonly` check and the `_draftProbe()` storage probe, exactly as `_draftDrain` already
  does.
- **D-06:** Also **check the worksheet for the same gap outside room notes.** `_notesInit` restores
  the notes textareas; establish whether any other worksheet field group queues drafts without a
  restore. **If one exists, report it — do not fix it here**, it needs its own RED proof.

## Tasks

### Task 1 — Prove it RED

A feature test can assert the **source contract**: `init()` restores drafts, drains on load, and
registers a retry timer; and the per-step inverse mapping exists for the steps that carry data.
Prove RED and quote the real failure.

⚠ **A source test cannot prove an engineer keeps their typing.** Say so in the docblock, and do
Task 3.

### Task 2 — Implement

Restore, drain-on-load, retry timer. Follow the worksheet's shapes rather than inventing new ones —
it is the reference implementation and the original port source.

### Task 3 — Browser proof (mandatory)

Puppeteer is in `node_modules` and has been used for exactly this three times today — read
`.planning/quick/261011-n91-survey-room-panel-xif/SUMMARY.md` for the method (local render, local
server, **never the live site, never POST to a real survey**).

Prove, with numbers, **before and after**:

1. **The reported bug.** Go offline, type into a room field, **reload**, and show the typed value
   is **back in the field**. Before: gone. After: restored.
2. ⚠ **The silent-loss path.** Go offline, type, reload, then come back **already online** with no
   `online` event fired — show the draft **drains on load** and reaches the server. Before: never
   sends. After: sends. **This is the severe one and it is not what the user reported** — prove it
   explicitly.
3. **No overwrite.** After an offline reload, confirm the restored draft is not replaced by a
   thinner one.
4. **No autosave storm on restore** — restoring must not trigger a flood of saves (D-03 ordering).

If Puppeteer cannot be made to work, **say so plainly and report what you could not prove.**

## Verification

Foreground, redirected to a file, **never piped**, **one suite per invocation**, **never
backgrounded** — a backgrounded gate silently hangs and the killed run **reports exit 0**. This bit
**six times today**. If it happens: kill it, clear stale `php.exe`, re-run clean.

The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test.**

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveyDraftRestoreTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Path tests/Feature/Surveys`,
`-Filter SurveyAutosaveWatchPathsTest`, `-Filter SurveyRoomPanelGuardTest`,
`-Filter SurveyLinkBrandingTest`, `-Filter SurveyRoomCaptureRefinementsTest`, `-Baseline`,
`-Hashes`.

**Measured by the orchestrator in the foreground today — re-measure and compare, never inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Surveys` | **102 passed, 753 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**ZERO pre-existing failures. A failure you see is yours.**

## Constraints

- ⚠⚠ **NEVER write a bare Blade directive name as prose in a comment** — it pairs with the next real
  closing directive and **silently deletes everything between, with no error**. It did exactly that
  **to this file** on 2026-10-10 (`6c58628b` is the repair). Write comments with **no `@` character
  at all**.
- ⚠ **Never open the shared engineer offline-photo IndexedDB store.** It is schema-frozen at v1 and
  holds real unsent photos on engineers' phones. This queue is localStorage only and must stay that
  way — the existing docblock is emphatic about it, and it is right.
- ⚠ **Do not change the draft entry shape or its key** (D-01) — real queued drafts may exist.
- ⚠ Do not touch the `$watch` registrations (`261011-lvf`) or the room-panel `x-if` guard
  (`261011-n91`), both shipped today. Both must still work — assert it.
- Two `sv-spine` pins guard this view (`SurveyLinkBrandingTest:242`,
  `SurveyRoomCaptureRefinementsTest:42,283`). **If a pin moves, STOP and report.**
- This view has **ZERO unescaped echoes** and must keep zero.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `SiteSurvey::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
- Three files stay **byte-identical to `4abd2b24`**. **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted. NO MIGRATION.** If you conclude one is needed, **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing`; **never** `gsd-sdk query state.*` (**do not
  touch STATE.md**); **never** `git stash`; `git clean` is prohibited.
- **Do NOT push and do NOT deploy.**

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
