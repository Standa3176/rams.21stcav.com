---
quick_id: 261011-hfb
slug: signoff-cockpit-serials
type: feature
status: complete
date: 2026-10-11
branch: feat/worksheet-classifier-universal
commits:
  - 7ba2f9bc  feat(261011-hfb): sign-off on the Site tab, with the pad reflow that keeps it signable (tasks A, C)
  - 5ddfd4bd  feat(261011-hfb): the cockpit surfaces project data and the branded documents (task B)
files_modified:
  - resources/views/worksheets/public-show.blade.php
  - resources/views/projects/cockpit.blade.php
  - resources/css/cockpit.css
  - app/Http/Controllers/ProjectCockpitController.php
  - tests/Feature/Worksheets/WorksheetSignoffPlacementTest.php   (new)
  - tests/Feature/Cockpit/CockpitProjectDataAndDocumentsTest.php (new)
  - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  - tests/Feature/Cockpit/CockpitDocumentFormTest.php
  - tests/Feature/Cockpit/CockpitRamsWizardTest.php
---

# 261011-hfb — Sign-off placement, cockpit surfacing, serial guidance

Three things the user asked for on 2026-10-11 after reading a live worksheet and the cockpit.
**Two of the three were discoverability, not missing features.**

---

## Task A — the Client Sign-Off moved into the Site tab

The card was a **sibling** of the tab panels, so it rendered below whichever tab was showing.
There was only ever **one** signature pad and it was never inside a room panel — but appearing
under every room read as a sign-off *per room*. The user chose the Site tab with the risk stated.

**What shipped**

- The `Client Sign-Off` card (and the signed-record alert above it) now render at the end of the
  Site tab panel, inside the same `$captureLocked` fieldset span as before.
- The sign-off gate derivation (`$unreviewedRooms` / `$signOffBlocked` / `$firstUnreviewedSlug`)
  moved **above** the site panel, because the card reads it. Still derived exactly once; the
  `signoff-block-top` banner further down reads the same three variables and still works.
- The stale sticky-bar comment that claimed *"the sign-off card IS the wrap-up and is always
  visible below the panels"* was corrected rather than left to mislead the next reader.

**Findability** (the stated risk of the user's own choice, answered): an always-visible block
between the site panel and the room loop — **outside every tab panel**, so it shows on whichever
tab is open — reads *"Sign-off is on the Site tab (first button in the bar below) — one signature
covers the whole worksheet, not one per space."* Asserted both ways: the copy is present, and it
is **not** inside any `ws-tab-panel`.

### The 1x1 trap — handled, and proved in a real browser

`resizeCanvas()` sizes the pad from `getBoundingClientRect()`; in a hidden panel that is 0x0 and
its own `Math.max(1, ...)` floors the backing store to **one pixel square**. The server activates
the first **incomplete** room, so on a normal install day the pad initialised hidden, and the
`260919-chb` resize listener is gated to genuine **width** changes — a tab switch is not one.

The resize body is now `reflowCanvas()`, exposed as `window.__resizeSignaturePad` and called from
`activate()` — the one chokepoint a bar tap, a hashchange from the banner's jump link, and the
post-upload session restore all go through. It bails while the pad has **no width**, which is the
half that matters most: `activate()` fires for room tabs too, and an unguarded reflow would floor
a *drawn* signature to 1x1 and snapshot that.

**This cannot be asserted in PHPUnit** — there is no layout engine, every rect is 0x0 there, and
the assertion would pass on the broken code too. Measured instead in real headless Chrome
(puppeteer, already in `node_modules`) at **390x844, DPR 3**, serving the test-rendered page over
http. The local dev sqlite is too stale to serve this route (`no such table:
worksheet_additional_kit`) and migrating it was out of scope, so the HTML came from the test
renderer via a throwaway test that was deleted before the commit.

| Run | On load | After tapping Site | Stroke drawn |
|---|---|---|---|
| **RED** — `__resizeSignaturePad` nulled before the tap (byte-for-byte the pre-fix page) | active tab `room-1`, canvas **1x1**, CSS 0x0 | active tab `site`, canvas **1x1**, CSS 249x220 | `signatureDrawn: true`, captured PNG **142 bytes** |
| **GREEN** — page as shipped | active tab `room-1`, canvas 1x1, CSS 0x0 | active tab `site`, canvas **746x660**, CSS 249x220 | `signatureDrawn: true`, captured PNG **31,386 bytes** |

**The RED case is worse than a visibly dead pad.** The stroke registers and the submit gate opens,
so the client appears to sign — and the office receives a **142-byte white pixel** on a signed,
capture-locked record. That is silent loss of the one artefact the whole page exists to produce.

Third run, the dangerous half: a signature drawn on Site survives **Site -> room -> Site** at
**27,078 bytes unchanged**, canvas still 746 wide. The zero-width bail works.

Also confirmed in the browser: `padCount: 1`, `formCount: 1`, `padInSitePanel: true`,
`padInRoomPanel: 0`.

---

## Task B — the cockpit surfaces project data and the documents

Nothing was missing. Every document already existed and was already generated; they were linked
only from `projects/show.blade.php` and `site-survey/edit.blade.php`, and never once from the page
a project is delivered from. The cockpit's only links were the grey footnote, whose second entry is
deep-linked to **one section** of the review page.

New `Project data and documents` section, derived in `ProjectCockpitController`
(`projectDataRoute()` / `projectDocumentGroups()`) on this page's standing rule — the controller
wires, the view draws.

| Group | Link | Renders when |
|---|---|---|
| Project data | **Edit details** -> `project-packages.review.show` (the page, **not** `#s-room-overviews`) | `latestPackage` exists |
| Site survey | Survey report (PDF) — Client-facing (`?internal=0`) | `latestSurvey` exists |
| Site survey | Survey report (PDF) — Internal (`?internal=1`) | `latestSurvey` exists |
| Site survey | Survey report (Word) — Internal | `latestSurvey` exists |
| Site survey | Client report (PDF) — Client-facing | `latestSurvey` exists |
| Site survey | Completed report (PDF) — Internal | `latestSurvey` exists |
| Site survey | Completed report (.xlsx) — Internal | `latestSurvey` exists |
| Worksheet | Engineer report (PDF) — Internal | `hasEngineerActivity()` |
| Worksheet | Worksheet (Word) — Internal | `filename` set **AND** the artifact is readable |

**A dead download is worse than no link**, so every condition mirrors what the controller actually
aborts on, and both sides of each are asserted. The six survey downloads are rendered **on demand**
from the `SiteSurvey` row (Browsershot / PhpSpreadsheet, no stored artifact to miss), so the row is
the honest condition. The worksheet pair is different: `worksheets.engineer-report-pdf` 404s unless
`hasEngineerActivity()`, and `worksheets.download` 404s unless the filename is set **and** the
artifact resolves through `DocumentArtifactStorage`. A half-finished generation leaves the first
true and the second false — that is the state a bare filename check would have shipped as a dead
link, and it is asserted as its own step.

The pre-existing `Edit AV Works Summary` deep link is **kept**: different destination,
`CockpitAvWorksSummaryLinkTest` pins it.

Still read-only: every control is an anchor GET, no form/control/script/Alpine directive, only
`--cav-*` tokens and no Tailwind utilities.

### The fence — one entry lifted, by name

`'Edit details' => 'Phase 49'` left `DEFERRED_AFFORDANCES` and the count moved **19 -> 18 in the
same commit that ships the control**. This is a **pull-forward of a deliberate deferral at the
user's explicit request on 2026-10-11**, recorded as exactly that in the test's own comment, with
what Phase 49 still owns spelled out. History is now
`15 -> 18 -> 19 -> 18 -> 21 -> 20 -> 19 -> 18`.

**The lift is paid for.** `populatedProject()` now carries a real `ProjectPackage`, because the
control renders only when there is a package to edit — without it the fence would have walked every
region and never seen the string it had just stopped banning. The bite is asserted alongside
`'Download all photos (ZIP)'` in `test_none_of_the_deferred_affordances_appears()`.

**Nothing else was lifted.** `Add document`, `Open register`, `Export CSV`, `Upload files`,
`Issue to client` and `Mark as sent` are untouched Phase 48 entries; the eight document links are
**new read-only surface**, not a lift, and moved no count. Their copy was substring-checked against
every remaining entry and both `FORBIDDEN_MARKUP` entries before use ('Download' is on no entry —
Plan 47-03 lifted it). `FORBIDDEN_MARKUP` 2, `WRITE_SURFACE_TABLES` 13,
`BANNED_HANDLER_ATTRIBUTES` 9 and the 6-form count are all unmoved.

### A fence lift is not local — and it bit, exactly as warned

Three sibling assertions re-take the fence totals by reflection and all three failed:

| File | Pin | Moved |
|---|---|---|
| `CockpitDocumentFormTest:406` | `assertCount(19, $deferred)` | 19 -> 18 |
| `CockpitDocumentFormTest:455` | `assertCount(19, $deferred)` | 19 -> 18 |
| `CockpitRamsWizardTest:646` | `assertCount(21, $banned)` (19 deferred + 2 markup) | 21 -> 20 |

All three moved **by name**, each message naming the lifted entry and why. The
`46.3-COUNT-LEDGER.md` C-2 row still reads 21: it is a point-in-time record of that phase and was
deliberately left alone, exactly as Plans 47-03 and 47-04 left it.

---

## Task C — serial-capture guidance (guidance only)

Capture is **not** missing: 45 Box Serial Label buttons on the live worksheet checked today,
3 of 4 kit rows on Board Room (the *Tilting Wall mount* correctly excluded as a mount). It is
inside the collapsed Kit List drawer, per hardware row.

Two pieces of copy, matching the page's existing instruction style:

1. In the always-visible block (outside every tab panel): *"**Serial numbers are required** for
   every piece of hardware you install. Open a space from the bar below, expand its
   **Kit List** drawer, and tap **Box Serial Label** on each hardware line... Cables, mounts
   and brackets do not need one."*
2. At the point of capture, inside the Kit List drawer body: *"Tap Box Serial Label on each
   hardware line below and photograph the serial / label on the box."* The block at the top is read
   once at the start of the day and forgotten by the fourth space.

**No count, no typed serial field, no new capture control** — all three were offered and declined,
and that is asserted (`name="serial_number"` / `id="serial_number"` / `name="serial"` all absent).

---

## RED first, and quoted

**Task A + C** — `gate-46.ps1 -Filter WorksheetSignoffPlacementTest`, before the view change:

```
Tests:    7 failed, 6 passed (43 assertions)
```
```
The Client Sign-Off form is not inside the site tab panel. The user asked for it there and only
there (261011-hfb Task A).
Failed asserting that 0 is identical to 1.
  at tests\Feature\Worksheets\WorksheetSignoffPlacementTest.php:221
```
```
The pad reflow is not exposed to the tab switcher. Switching from a room to Site would leave the
pad at the 1x1 size it initialised to while hidden, and the client could not sign.
Failed asserting that 0 is identical to 1.
```
```
reflowCanvas() is gone.
Failed asserting that false is of type int.
```

The six that passed RED are the ones that must: non-vacuity, "a room tab is active on load",
"not in any room panel", "exactly one pad", "no typed serial field", and the pinned counts.

**Task B** — `-Filter CockpitProjectDataAndDocumentsTest`, before the controller/view change:

```
Tests:    6 failed, 3 passed (49 assertions)
```
```
The cockpit does not link to the project-data review page itself. The pre-existing link is
deep-linked to #s-room-overviews, which is one section and is not what "edit project data" means.
Failed asserting that 0 is identical to 1.
```
```
The survey document link http://rams.21stcav.com.test/site-surveys/1/pdf?internal=0 does not
render exactly once.
Failed asserting that 0 is identical to 1.
```
```
The section this test judges does not exist, so every zero above is a zero over nothing.
Failed asserting that 0 is greater than 0.
```

**The fence lift** — `-Path tests/Feature/Cockpit`, after the lift and before the sibling fixes:

```
Tests:    3 failed, 468 passed (8820 assertions)
DEFERRED_AFFORDANCES is no longer 19 ... Failed asserting that actual size 18 matches expected size 19.
The fence is 19 deferred affordances ... Failed asserting that actual size 20 matches expected size 21.
```

---

## Gates — all foreground, redirected to a file, one suite per invocation

| Gate | Expected (orchestrator, 2026-10-11) | Measured now | |
|---|---|---|---|
| `tests/Feature/Worksheets` | 300 passed, 2561 assertions | **313 passed, 2616 assertions**, 0 failed | +13 / +55 = the new file exactly |
| `tests/Feature/Surveys` | 88 passed, 717 assertions | **88 passed, 717 assertions**, 0 failed | unmoved |
| `tests/Feature/Cockpit` | 462 passed | **471 passed, 9197 assertions**, 0 failed | +9 = the new file exactly |
| `-Filter CockpitReadOnlyFenceTest` | — | **14 passed, 1733 assertions** | |
| `-Filter WorksheetShowViewParityTest` | li pin at 2 | **5 passed, 18 assertions** | the pin did **not** move |
| `-Baseline` | >= 159 passed AND 0 failed | **159 passed, 0 failed, 2 skipped** | |
| `-Hashes` | all three MATCH `4abd2b24` | **all three MATCH** | |

Pinned occurrence counts in `public-show.blade.php`, before and after: raw echo 1 -> 1
(still `{!! $skipRestoreAttr !!}`, still on the tab-panel element), x-data **1 -> 1**,
x-show **1 -> 1**, x-model **2 -> 2**, x-cloak **1 -> 1**, the click handler **0 -> 0**. Every
comment added to a Blade file in this task contains **no at-sign at all**; the compiled view was
`php -l`'d clean (292,894 bytes) and the page was rendered in a real browser.

The IndexedDB offline queue was not touched (`DB_VERSION = 1`, `createObjectStore`, `keyPath: 'id'`
pins all green inside the Worksheets suite). The capture lock is intact and asserted: a signed
worksheet's sign-off form is still inside a disabled fieldset **and** inside the site panel.
No migration. Nothing deleted. Nothing pushed, nothing deployed.

---

## Deviations

1. **Tasks A and C shipped in one commit, not two.** The plan asks for atomic-per-task. The
   findability pointer (Task A) and the serial instruction (Task C) are two sentences in the *same*
   always-visible block, in the same file, and both are proved by the same new test file. Splitting
   them would have meant two commits editing the same lines for one decision.
2. **`populatedProject()` in the fence test grew a `ProjectPackage`.** Required, not optional: the
   lift would otherwise have been vacuous by that file's own standard. It moved no other count —
   the 6-form arithmetic, the csrf count and the module count are all unmoved (fence: 14 passed).
3. **The browser proof used the test renderer's HTML, not the local Herd site.** Herd was not
   running and the local dev sqlite is missing `worksheet_additional_kit`, which 500s this route.
   Migrating local dev data was out of scope, so a throwaway test dumped the identical rendered
   markup (all CSS and JS on this page is inline) and a node http server served it to puppeteer.
   The throwaway test and the proof script were removed before the commits.
4. **Local dev data touched and restored.** Worksheet 7's `generated_data` was temporarily given
   two rooms while the Herd route was being tried, then restored to NULL (its original value,
   snapshotted first). No token was minted, rotated or rendered.

## Before deploy

`resources/css/cockpit.css` is a Vite entry and `public/build` is gitignored (only
`manifest.json` is tracked), so the new `.cav-docs` rules will not reach `rams.21stcav.com` until
**`npm run build`** runs on the VPS. Without it the new section renders unstyled but fully
functional — every link still works. Not run here: this task does not push and does not deploy.
