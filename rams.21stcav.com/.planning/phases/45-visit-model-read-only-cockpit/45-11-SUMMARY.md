---
phase: 45-visit-model-read-only-cockpit
plan: 11
subsystem: cockpit-presentation
tags: [blade, read-only, no-javascript, url-state, d-09, d-12, d-16, sketch-004]
requires:
  - "CockpitModulePresenter / CockpitHeaderPresenter (Plan 45-10)"
  - "The retargeted --cav-* token set (Plan 45-09)"
  - "CockpitSectionPresenter (Plan 45-07) — reached only through the module presenter"
provides:
  - "The sketch-004 delivery cockpit page: masthead, three KPI cards, one stage chip, nine module rows"
  - "A side panel whose open/closed and tab state lives entirely in the query string — no JavaScript"
  - "Four new Blade components: kpi-card, module-row, status-chip, panel (plus a shared icon map)"
  - "A controller that whitelists ?module= and ?tab= by membership against the presenter's own keys"
affects:
  - "Plan 45-12 — fills the Files and Notes tabs and the activity feed into the panel shell"
  - "Plan 45-13 — owns reconciling CockpitSpineTest, which still asserts the deleted accordion"
  - "Plan 45-14 — the human check against the design image"
tech-stack:
  added: []
  patterns:
    - "server-rendered panel state — a GET per open, bookmarkable, back-button correct, zero JS"
    - "whitelist by membership rather than validate() — a redirect + error bag is write-shaped"
    - "the fence is EXTENDED, never weakened: the bottom bracket was retargeted and re-proved"
key-files:
  created:
    - resources/views/components/cockpit/kpi-card.blade.php
    - resources/views/components/cockpit/module-row.blade.php
    - resources/views/components/cockpit/status-chip.blade.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/components/cockpit/icon.blade.php
    - .planning/phases/45-visit-model-read-only-cockpit/45-11-SUMMARY.md
  modified:
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/projects/cockpit.blade.php
    - resources/views/components/cockpit/masthead.blade.php
    - resources/css/cockpit.css
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  deleted:
    - resources/views/projects/_cockpit-drawer.blade.php
    - resources/views/components/cockpit/section-group.blade.php
    - resources/views/components/cockpit/drawer.blade.php
    - resources/views/components/cockpit/pip.blade.php
    - resources/views/components/cockpit/tick-box.blade.php
decisions:
  - "The panel is server-rendered from the query string; Alpine directives count as script inside the cockpit region for this phase and are not used"
  - "Closed at rest means ABSENT from the DOM, not hidden — a hidden panel would still be read out by a screen reader"
  - "Unknown ?module=/?tab= values fall back silently to the closed state and render 200; no validate(), because its redirect+error bag is write-shaped on a read-only page"
  - "The h1 falls back to the project name when the project has no ref — both are the project's own identity; a blank h1 would be the invention"
  - "A shared icon.blade.php was added (not in the plan's file list) so the module row and the panel cannot drift on the same module's glyph"
  - "The status chip is a NEW component (cav-schip), not a variant of the existing qualifier chip — progress states and provenance states must not share a variant list"
  - "CockpitSpineTest was left failing on purpose: it asserts the deleted accordion and 45-13 owns its reconciliation"
metrics:
  duration: ~75 min
  completed: 2026-09-20
  tasks: 3
  commits: 6
---

# Phase 45 Plan 11: The delivery cockpit page, rebuilt to sketch 004 — Summary

The accordion cockpit is replaced in one pass by the accepted design: a masthead, three KPI cards
whose documents denominator is counted from the rows rendered beside it, one stage chip, the nine
module rows, and a right-hand side panel that opens, switches tab and closes through plain
`<a href>` links with no JavaScript of any kind.

## Tasks

| # | Task | RED | GREEN |
|---|------|-----|-------|
| 1 | Controller reads validated `?module=` / `?tab=` | `8c046a4c` (7 failed) | `a90df2bb` (32 passed) |
| 2 | The page — masthead, KPIs, stage chip, module list | `61b1ecb8` (10 failed) | `1dbd388b` (34 passed) |
| 3 | The side panel shell and its Overview tab | `6df80786` (6 failed) | `37797a40` (36 passed) |

No REFACTOR commit: nothing needed cleanup after GREEN, and a no-op refactor commit would be noise
in the TDD gate sequence.

## The no-JavaScript panel, as built

    /projects/{project}/cockpit                      -> no panel element in the DOM at all
    /projects/{project}/cockpit?module=worksheet     -> panel open, Overview tab
    /projects/{project}/cockpit?module=worksheet&tab=files
    the close control is an <a href> back to the bare cockpit URL

Every tab anchor carries the current `module` forward, so a tab switch can never close the panel.
The active anchor carries `aria-current="page"` and nothing anywhere carries `aria-expanded` — the
tab strip is navigation between three URLs, not a disclosure widget.

`test_the_panel_carries_no_handler_attribute_no_control_and_no_deferred_write_copy()` asserts the
absence of `x-data`, `x-show`, `x-init`, `x-if`, `x-text`, `x-on:`, `@click`, `wire:` and `onclick`
inside the region, so the ruling is enforced rather than remembered. Plan 45-13 may promote those
strings into the fence's own constant.

## Input handling — threats T-45-11-01 and T-45-11-02

`?module=` and `?tab=` are the first user-supplied input this page accepts. Both are resolved by
**membership** — `array_key_exists($submitted, CockpitModulePresenter::moduleMap())` and
`in_array($submitted, self::TABS, true)` — against the presenter's own keys, never a hand-maintained
duplicate list that would drift the first time a module is added. `validate()` was deliberately not
used: a failed validate redirects with a session error bag, which is a write-shaped behaviour on a
read-only page.

An unrecognised value is treated as a stale bookmark: the page renders 200 with the panel closed and
the submitted value is **never echoed**. Asserted over five payloads including `<script>alert(1)</script>`,
a 5,000-character string, `../../etc/passwd` and the case-shifted `WORKSHEET`.

## Rendered honestly rather than as drawn

| Design element | What shipped |
|---|---|
| Two stage chips | **One.** A project has exactly one `Project::status`; the second chip has no source. |
| Site contact email | **Omitted.** `SiteSurvey` has a name and a phone only; `pm_email` is the PM's. A test asserts `Site contact:` and `@example.com` are absent when no survey exists. |
| "Proposed install date" | **"Planned start", or nothing.** A test asserts the phrase `Proposed install` appears nowhere. |
| Programming's "0 files" | **No count phrase.** A test asserts `0 files` is absent. |
| Actions ▾ / "…" overflow / Quick actions (Create visit · Add note · Upload files) | **Not rendered, not even disabled.** A disabled control is still an offer. Asserted absent by name on both the page and the panel. |
| "Documents 1 of 9 complete" | **Denominator counted in the DOM.** The test counts `cav-module` rows by XPath and then requires `\b\d+ of {rows} complete\b` in the same response — so a hardcoded 9 fails even while the presenter is right. |
| A 0% progress ring | **No ring at all** when `progress()` returned null. "Nothing done" and "nothing planned" are different facts, and the second is the true one. |

## The fence was extended, never weakened

Sketch 004 shortened the footer link from "Open the full project page" to **"Open full project"**.
That string is `CockpitReadOnlyFenceTest::cockpitRegion()`'s BOTTOM bracket, so the copy and the
bracket moved in the **same commit** (`1dbd388b`), and the bracket was then re-proved by the ritual
from 45-07: the extraction was deliberately truncated to 2,000 characters and **five** separate
assertions went red with "The extracted region stops before the end of the page shell", then green
again on restore. No ban was removed, no assertion was softened, and both brackets survive.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 3 — Blocking] A shared `icon.blade.php` was added, outside the plan's file list**

- **Found during:** Task 2.
- **Issue:** `CockpitModulePresenter` emits an icon KEY and leaves the glyph to Blade. Both the
  module row and the panel header render the same module's glyph, so the key-to-path map would have
  had to be duplicated in two components — and the first glyph change would have left them
  disagreeing about the same module.
- **Fix:** one `x-cockpit.icon` component holding the map, consumed by the row, the panel, the KPI
  cards and the masthead. Paths are data echoed through `{{ }}` onto the `d` attribute, so nothing
  is unescaped output, and an unknown key renders nothing rather than a fallback glyph.
- **Commit:** `1dbd388b`

**2. [Rule 1 — Bug] Two test assertions were wrong about their own subject, and were corrected rather than the code**

- `substr_count($html, 'Open drawer')` returned 18 for nine links, because each link carries the copy
  twice — once as text and once inside the `aria-label` that names which module it opens. Counting
  by class is the honest count; the aria-label stays, because nine identical "Open drawer" entries
  in a screen reader's link list are indistinguishable. (`1dbd388b`)
- The tab-href assertion compared `e(route(...))` against a subtree that `cockpitSubtree()` had
  already entity-decoded, so `&amp;` was being compared with `&` on a correct href. The `e()` was
  dropped with a comment saying why. (`37797a40`)

**3. [Cleanup carried out, flagged by 45-09 and 45-10] `cockpit.css` dead imports and superseded literals**

`@import '@fontsource/poppins/400.css'` and `600.css` downloaded a face no rule referenced after
D-08; both are gone, along with the stale Verdana-weights note above them. The superseded literals
at the old `:87-89`, `:197`, `:254`, `:264` and `:629` (`#016E82`, `#D4AF37`, `#FAFAFA`, `#fcfcfc`)
went with the accordion rules that carried them. `grep -nE '#[0-9A-Fa-f]{6}'` over the file now
returns only comment lines documenting the app's OWN current values as an audit trail.

### Not a deviation — the failing spine test

`CockpitSpineTest` has 15 failures. Every one asserts the accordion this plan deleted: `<details>`
drawers, `<summary>` rows, the traffic-light pip and the tick box. **Nothing was weakened or
deleted to make them pass** — the orchestrator's instruction and the phase plan both put that
reconciliation in **Plan 45-13**. The failures are listed below so 45-13 does not have to rediscover
them.

## Deferred Issues

- **`CockpitSpineTest` — 15 failures, all structural, all owned by 45-13.** They are:
  `all_nine_sections_render`, `a_not_required_section_still_renders`, `no_drawer_ships_the_open_attribute`,
  `a_reconstructed_visit_is_disclosed_in_the_closed_summary`, `two_reconstructed_visits_are_counted_in_the_summary`,
  `a_worksheet_derived_row_carries_all_four_reconstructed_treatments`, `a_survey_derived_row_gets_its_own_copy_and_no_dotted_type_word`,
  `a_superseded_visit_is_disclosed_in_the_closed_summary`, `a_superseded_visit_keeps_its_place_in_date_order`,
  `superseded_strikethrough_is_scoped_to_the_name_and_carries_no_opacity`, `a_row_may_carry_both_chips_reconstructed_first`,
  `a_qualifier_never_drives_a_drawer_to_attention`, `programming_renders_the_unticked_box_and_not_marked`,
  `an_empty_project_still_renders_nine_waiting_drawers`, `a_force_deleted_source_still_renders_the_visit`.
  **The D-02 and D-04 disclosures they protect are still REQUIRED** — `visit-row` was kept
  deliberately and still carries all four reconstructed treatments and the superseded treatment;
  what changed is *where* a visit is disclosed (the panel's Overview tab, not a drawer summary).
  45-13 must re-point those assertions at the panel, not delete them. The at-rest disclosure
  question is a real one for 45-13 to answer: with the panel closed, a module's row shows a chip and
  a count, so a reconstructed visit is no longer disclosed without opening anything.
- **`attention.blade.php` and the `.cav-attn` rules are now unrendered.** The health summary moved
  into the Overall status KPI card. The component was NOT deleted: the plan enumerated five files to
  delete and this was not among them, and deleting an un-enumerated component is the kind of
  unilateral tidy that this phase's deletion list exists to prevent. 45-13 should decide.
- **`VIS-04`, `VIS-06` and `VIS-10` were deliberately NOT marked complete**, on the precedent 45-09
  and 45-10 set: the page now renders, but 45-12 still fills the panel body and 45-14 is the human
  check against the design. Marking them here would claim a page that is not finished.

## Verification

| Check | Result |
|---|---|
| `artisan test tests/Feature/Cockpit/CockpitPageTest.php` | **`36 passed (351 assertions)`** |
| `artisan test .../CockpitPageTest .../CockpitReadOnlyFenceTest .../FlagOffBehaviourUnchangedTest tests/Unit/Cockpit` | **`90 passed (703 assertions)`** |
| `artisan test tests/Feature/Cockpit tests/Unit/Cockpit` | `15 failed, 90 passed` — all 15 in `CockpitSpineTest`, owned by 45-13 |
| **D-06 baseline** (the 12-path enumerated command, character-identical) | **`2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed` **PASS** |
| `artisan route:list --name=projects.cockpit` | `GET|HEAD`, **`Showing [1] routes`** — PASS |
| `php -l` over every compiled view after `view:clear` | **14 checked, 0 failures** — no `@php` short-form or glued-`@if` trap |
| `Select-String resources/views/components/cockpit/*.blade.php -Pattern '#[0-9A-Fa-f]{6}'` | **no match — PASS** |
| `git diff --name-only 4abd2b24 -- layouts/app.blade.php app.css tailwind.config.js` | **empty — PASS** |
| `Get-FileHash -Algorithm SHA256` on the three protected files | **all three match `45-BASELINE.md` exactly** |
| `.planning/STATE.md` | **clean** — neither `state.advance-plan` nor `state.update-progress` was run |

Protected-file hashes (working-tree bytes, compared like with like per the baseline's CRLF warning):

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

No migration was run. No package was installed. `migrate:fresh --env=testing` was never invoked.

## TDD Gate Compliance

Three tasks, each RED → GREEN with the gates as separate commits, and no test passed unexpectedly
during any RED phase:

| Task | RED (`test(...)`) | GREEN (`feat(...)`) |
|---|---|---|
| 1 | `8c046a4c` — 7 failed on undefined view-data keys | `a90df2bb` — 32 passed |
| 2 | `61b1ecb8` — 10 failed | `1dbd388b` — 34 passed |
| 3 | `6df80786` — 6 failed | `37797a40` — 36 passed |

## Known Stubs

| Stub | File | Why, and who resolves it |
|---|---|---|
| The Files and Notes tabs render the single line "Files and notes arrive in the next plan." | `resources/views/components/cockpit/panel.blade.php` | Intentional and scoped by the plan: this plan ships the panel shell and Overview only. **Plan 45-12** fills both tabs and the Recent activity feed. It is one honest sentence, not "coming soon" furniture, and the tab body is never empty. |

Nothing else is stubbed. The values the design asked for that have no source are omitted and
asserted absent, which is the opposite of a stub.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: new-input-surface | `app/Http/Controllers/ProjectCockpitController.php` | `?module=` and `?tab=` are the cockpit's first user-supplied input. Both are whitelisted by membership and neither is echoed; `?module=` selects a presentation slice of the SAME project the route already authorised and takes no id, so it cannot address another project's records (T-45-11-03). Recorded here because the plan's threat register anticipated it, not because anything is unmitigated. |

## Self-Check: PASSED

All five created files exist on disk; all five deleted files are gone. All six commits
(`8c046a4c`, `a90df2bb`, `61b1ecb8`, `1dbd388b`, `6df80786`, `37797a40`) are present in
`git log --all`. The only tracked files deleted are the five superseded accordion components the
plan instructed be removed.
