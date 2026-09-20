---
phase: 45-visit-model-read-only-cockpit
plan: 13
subsystem: cockpit-presentation
tags: [tests, read-only-fence, d-02, d-04, at-rest-disclosure, anti-rot, sketch-004]
requires:
  - "The rebuilt cockpit page and panel (Plans 45-11, 45-12)"
  - "CockpitModulePresenter / CockpitSectionPresenter (Plans 45-07, 45-10)"
  - "The measured D-06 baseline (45-BASELINE.md)"
provides:
  - "A fence that bans more than it did before the redesign — 18 affordances, 9 handler attributes, panel-open invariance, hostile input"
  - "CockpitSpineTest retargeted at the module rows and the panel, with its file name and git history intact"
  - "The at-rest D-02/D-04 disclosure RESTORED to the page — the module row's count phrase carries the qualifier again"
  - "The flag-off 404 proven over ?module= and ?tab="
affects:
  - "Plan 45-14 — the human check against the design image"
  - "Phase 46 — owns the deliberate retirement of the Alpine ban when it introduces writes"
tech-stack:
  added: []
  patterns:
    - "a guard nobody has watched fail is a guard nobody should trust — three deliberate breakages, recorded"
    - "retire an assertion only by rehoming its property, in a comment at the point of removal"
    - "assert comparatively when the property is 'X must not change Y', not against a literal"
key-files:
  created:
    - .planning/phases/45-visit-model-read-only-cockpit/45-13-SUMMARY.md
  modified:
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
    - tests/Feature/Cockpit/CockpitSpineTest.php
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitPanelTest.php
    - tests/Feature/Cockpit/FlagOffBehaviourUnchangedTest.php
    - tests/Unit/Cockpit/CockpitModulePresenterTest.php
    - app/Support/Cockpit/CockpitModulePresenter.php
    - app/Support/Cockpit/CockpitSectionPresenter.php
    - resources/css/cockpit.css
  deleted:
    - resources/views/components/cockpit/attention.blade.php
decisions:
  - "THE HEADLINE IS A CODE FIX, NOT A TEST FIX: the module row's count phrase had stopped disclosing reconstructed/superseded, so the page misled by omission at rest. Fixed in the presenter; the assertions were not softened."
  - "The qualifier wording lives in ONE method — CockpitSectionPresenter::visitQualifiers() is now public and both presenters read it, so the row and the panel cannot drift"
  - "Singular drops the numeral ('2 visits · superseded'), plural carries it ('3 visits · 2 superseded') — the convention the section count has used since 45-07"
  - "attention.blade.php DELETED with its .cav-attn CSS and its by-name exclusion: an unrendered Blade file using {!! !!} is a loaded gun for a later phase"
  - "'A qualifier never drives a module to attention' is asserted comparatively — two projects identical but for the qualifiers — because a literal status word could pass for an unrelated reason"
  - "CockpitSpineTest keeps its file name so git history stays attached to the assertions; only the class docblock was rewritten"
metrics:
  duration: ~55 min
  completed: 2026-09-20
  tasks: 3
  commits: 4
---

# Phase 45 Plan 13: Reconciling the cockpit tests with the sketch-004 rebuild — Summary

Fifteen red tests in `CockpitSpineTest` were not fifteen stale assertions. Thirteen of them were
protecting properties that still exist and simply moved; two were markup-only and were retired with
their real subjects rehomed. And one of them was not stale at all — it was **right, and the page was
wrong**.

## The regression this plan actually fixed

With the panel closed, a module row showed a chip and `1 visit`. The accordion summary it replaced
read `3 visits · 2 reconstructed`. **The at-rest disclosure had been lost in the rebuild.**

That is not decoration. D-02 marks a backfilled visit as an *inference* — a signed worksheet proves
someone attended, not what they did. D-04 keeps a superseded visit visible. The whole point of the
count slot was that **a PM who opens nothing still learns a visit is inferred or superseded**. A row
reading a bare `1 visit` presents an inference as recorded fact, which is the precise failure D-02
exists to prevent.

`CockpitModulePresenter` re-counted visits from scratch (`phrase($visits->count(), …)`) instead of
carrying the qualifier. Fixed at source:

| Situation | The module row's count phrase now reads |
|---|---|
| one visit, reconstructed | **`1 visit · reconstructed`** |
| three visits, two reconstructed | **`3 visits · 2 reconstructed`** |
| two visits, one superseded | **`2 visits · superseded`** |
| three visits, two superseded | **`3 visits · 2 superseded`** |
| one visit, both qualifiers | **`1 visit · reconstructed · superseded`** |

Singular drops the numeral and plural carries it — the convention `CockpitSectionPresenter` has used
since 45-07, and the one the retained spine assertion `1 visit · superseded` has always asserted.

**The wording is not written twice.** `CockpitSectionPresenter::visitQualifiers()` is now public, and
both presenters read it, so the row and the panel can never disagree about the same visits. The
module presenter's own rule — *it translates, it does not form a second opinion* — is preserved, and
a new unit test asserts the row's phrase is identical to its section's.

**Proved to bite.** With the presenter reverted to its pre-fix behaviour, **five** tests go red:
the three disclosure tests, the both-chips test and the qualifier/attention test. Restored, all pass.

## Tasks

| # | Task | Commit | Result |
|---|------|--------|--------|
| 1 | Extend the fence | `507d3edb` | 9 passed (445 assertions) |
| — | The at-rest regression (deviation, Rule 1) | `33c41a4e` | 65 passed (unit) |
| 2 | Migrate the spine and page tests; delete `attention` | `55e3f16b` | 156 passed |
| 3 | Flag-off `?module=`/`?tab=`; the D-06 gate | `920e01c4` | 12 passed; baseline PASS |

## The fence is stronger, not weaker

| Change | Before | After |
|---|---|---|
| `DEFERRED_AFFORDANCES` | 15 | **18** — `Create visit` (Phase 46), `Add note` (Phase 46), `Upload files` (Phase 48). The count assertion **moved deliberately** and carries a comment saying it is never to be deleted to make a change fit. |
| Handler ban | 7 strings asserted inline | **`BANNED_HANDLER_ATTRIBUTES`, 9 strings, as data** — `onclick`, `wire:`, `x-on:`, `@click` plus `x-data`, `x-show`, `x-init`, `x-if`, `x-text`, and counted like the others |
| Where the ban runs | the bare page | the bare page **and every one of the nine open panels** |
| Panel-open invariance | not covered | **`test_opening_a_panel_writes_nothing`** — every module × every tab, five write-surface tables unchanged |
| Hostile input | not covered in the fence | **`test_a_hostile_module_value_is_not_reflected`** — a script payload and a 5,000-character value; asserted on the RAW body, and the region must still be bracket-valid |
| `cockpitRegion()` brackets | both | **both**, unchanged: masthead project name (top), "Open full project" (bottom) |

**The Alpine ruling is now enforced rather than remembered.** Recorded above the constant: Alpine is
loaded globally by the layout and is therefore *available* — it is not absent, it is **banned**,
because Phase 45 ships no JavaScript and the panel is URL state. Phase 46 may retire the ban
deliberately, by an owner, by editing that list.

## Re-proving the fence can fail — the 45-07 ritual, three ways

| # | The break | What went red |
|---|---|---|
| 1 | The XPath class changed to `cav-cockpit-does-not-exist` | **8 of 9 tests**, all on `The cav-cockpit root element was not found — the fence would pass vacuously.` Only the pure-data enumeration test stayed green, correctly — it reads no HTML. |
| 2 | `$region = substr($region, 0, 2000)` after extraction | **8 of 9 tests**, all on the BOTTOM bracket: `The extracted region stops before the end of the page shell — it would leave the module rows unexamined.` The top bracket was still satisfied, which is exactly why the bottom one exists. |
| 3 | `<button type="button" onclick="alert(1)">Create visit</button>` injected into `module-row.blade.php` | **3 tests**: the `<button` markup ban, the **new** `Create visit` affordance entry, and the **new** `onclick` handler entry. Both of this plan's additions bit on their first real test. |

All three were reverted; the fence is back to **9 passed (445 assertions)**.

## The per-test disposition, carried out

### `CockpitSpineTest` — 15 tests, 13 retargeted, 2 retired with their subjects rehomed

| Original | Disposition |
|---|---|
| `all_nine_sections_render` | → `every_module_renders_a_row`, counted against `CockpitModulePresenter::moduleMap()`, never a literal |
| `a_not_required_section_still_renders` | → `a_not_required_module_still_renders_its_row` (+ its row reads `Not required`) |
| `no_drawer_ships_the_open_attribute` | **RETIRED** (markup-only: no `<details>` exists) → `no_panel_element_exists_in_the_dom_at_rest`, the stricter form D-09 asks for, plus a positive check that the panel *does* open |
| `a_reconstructed_visit_is_disclosed_in_the_closed_summary` | → `…_in_the_module_row_count_phrase` |
| `two_reconstructed_visits_are_counted_in_the_summary` | → `…_in_the_module_row_count_phrase` |
| `a_worksheet_derived_row_carries_all_four_reconstructed_treatments` | → the panel, `?module=worksheet`; all four assertions unchanged |
| `a_survey_derived_row_gets_its_own_copy_and_no_dotted_type_word` | → the panel, `?module=site_survey` |
| `a_superseded_visit_is_disclosed_in_the_closed_summary` | → the module row count phrase |
| `a_superseded_visit_keeps_its_place_in_date_order` | → the panel |
| `superseded_strikethrough_is_scoped_to_the_name_and_carries_no_opacity` | → the panel |
| `a_row_may_carry_both_chips_reconstructed_first` | → the panel, **plus** the at-rest phrase `1 visit · reconstructed · superseded` |
| `a_qualifier_never_drives_a_drawer_to_attention` | → `…_a_module_to_attention`, rewritten **comparatively** (below) |
| `programming_renders_the_unticked_box_and_not_marked` | **RETIRED** (the tick box is deleted) → `programming_claims_no_completion_it_cannot_evidence` |
| `an_empty_project_still_renders_nine_waiting_drawers` | → `…_every_module_row_waiting`: rows, chips and counts all against the presenter |
| `a_force_deleted_source_still_renders_the_visit` | → the panel, **plus** the row still counts it at rest |

**No real assertion was lost.** Each of the two retirements carries an in-file comment at the point
of removal naming what it protected and where that property now lives, so a reader can tell a
deliberate retirement from a deletion of convenience.

**The qualifier/attention test is the one that got stronger.** It used to assert a literal
`Nothing needs you on this job`. It now builds two projects that are identical except that one's
visits carry qualifiers, and asserts the module row's chip **and** the Overall status KPI card are
the same in both — while the two count phrases *differ*. That is the real property: a qualifier
changes what the page **discloses** and never what it **asks for**, and it cannot be satisfied by a
page that happens to be quiet for an unrelated reason.

### `CockpitPageTest` — the rewrites were already done, two retirements now documented

Plans 45-11 and 45-12 had already rewritten every row in the REWRITE column (masthead, health
degradation, the KPI card copy, the module rows, the "Open full project" link). This plan:

- replaced the last literal module count (`assertSame(9, …)` in the health-failure test) with
  `count(CockpitModulePresenter::moduleMap())`;
- recorded the **pip** retirement above `test_status_chip_renders_its_three_variants_with_a_shape_channel`
  — its real subject was *state survives greyscale because it is carried by shape and an accessible
  name*, and that is what the status chip test asserts;
- recorded the **tick box** retirement above `test_the_programming_row_renders_its_chip_and_no_count_phrase`
  and strengthened it (`cav-tick` and `Marked done by hand` now asserted absent) — its real subject
  was *nothing claims a completion this phase cannot evidence*;
- added `attention.blade.php` to the deleted-components list.

### `FlagOffBehaviourUnchangedTest` — one case added, nothing else touched

`test_the_flag_off_404_holds_for_the_panel_query_string_too` covers `?module=`, `?tab=`, both
together and a script payload: all 404, all emit no cockpit footprint. **The three
`PRE_PHASE_HASHES` constants are unchanged** — `git diff` on the file shows no hash line at all —
and all three files still hash to their `45-BASELINE.md` values.

## The `attention.blade.php` decision: DELETED

**Deleted**, along with its four `.cav-attn` rules in `cockpit.css` and the by-name exclusion in the
unescaped-output guard.

Why delete rather than keep:

1. **It is unreachable.** 45-11 moved the health summary into the Overall status KPI card. Nothing
   renders it and nothing has since.
2. **It was the one cockpit view still using `{!! !!}`.** An unrendered Blade file with unescaped
   output is a loaded gun for a later phase: the next agent who needs an attention line would find
   it, render it, and inherit the unescaped echo with it. Its own escaping is careful today — that
   is exactly the kind of care that does not survive an edit by someone who did not write it.
3. **It is the same argument that deleted the drawer, the pip and the tick box** in 45-11. A dead
   component left on disk is an invitation to render it beside the new design.
4. **The guard is now unconditional.** `test_no_cockpit_view_uses_unescaped_output()` has no
   exclusion list at all, so it covers every remaining file with no carve-out to widen.

The test that policed the exclusion was **not** deleted: it was retargeted to
`test_the_legacy_attention_component_is_deleted_and_referenced_by_nothing()`, which asserts the file
is gone, that no view references `x-cockpit.attention`, and that `cav-attn` no longer appears in
`cockpit.css` — a rule with no markup being the other half of the same loaded gun.

## Verification

| Check | Result |
|---|---|
| `artisan test tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` | **`Tests: 9 passed (445 assertions)`** |
| `artisan test tests/Feature/Cockpit/CockpitSpineTest.php` | **`Tests: 15 passed (119 assertions)`** |
| `artisan test tests/Unit/Cockpit` | **`Tests: 65 passed (253 assertions)`** |
| `artisan test tests/Feature/Cockpit tests/Unit/Cockpit` | **`Tests: 157 passed (1646 assertions)`** — zero failures |
| **D-06 baseline** (the 12-path enumerated command, character-identical) | **`Tests: 2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed` **PASS** |
| `git diff --name-only 4abd2b24 -- layouts/app.blade.php app.css tailwind.config.js` | **empty — PASS** |
| `Get-FileHash -Algorithm SHA256` on the three protected files | **all three match `45-BASELINE.md`** |
| `git diff` on `FlagOffBehaviourUnchangedTest.php` inside `PRE_PHASE_HASHES` | **no change** |
| The three deliberate fence breaks | **all three went red for the expected reason; all three reverted** |
| `.planning/STATE.md` | **clean** — neither `state.advance-plan` nor `state.update-progress` was run |

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

No migration was run. No package was installed. `migrate:fresh --env=testing` was never invoked.

## Deviations from Plan

### 1. [Rule 1 — Bug, and the most important thing in this plan] The presenter, not the test

The plan's `<interfaces>` anticipated this exactly: *"If the count phrase does not disclose
reconstructed and superseded counts, that is a defect in Plan 45-10's output to be fixed there — not
an assertion to drop."* It did not, and it was. `CockpitModulePresenter::visitPhrase()` replaced the
bare re-count, reading `CockpitSectionPresenter::visitQualifiers()` so the words exist once.
Five unit tests and five feature tests pin it. Files outside this plan's `files_modified` list were
therefore edited — `CockpitModulePresenter.php`, `CockpitSectionPresenter.php` and
`CockpitModulePresenterTest.php` — deliberately, because the alternative was to soften an assertion
that was protecting a real property.

### 2. [Rule 1 — a test expectation was wrong, not the code] `2 visits · 1 superseded`

The orchestrator's brief gave `2 visits · 1 superseded` as an example while also stating that the
singular drops the numeral. The repository's settled convention — `CockpitSectionPresenter` since
45-07, and the pre-existing spine assertion `1 visit · superseded` — drops the numeral for a lone
qualifier. The test was corrected to `2 visits · superseded`, **and a second case was added** so the
plural form is pinned too: `3 visits · 2 superseded`. Both readings are now proven rather than
argued.

### 3. [Rule 3 — dead code] `visitsFromSection()` removed

It became unreachable when `visitPhrase()` replaced it. A private helper with no caller is the kind
of thing a later agent re-wires.

### 4. [Owned decision, assigned by 45-11 and 45-12] `attention.blade.php` deleted

Documented in full above.

## Deferred Issues

None. `CockpitSpineTest`'s fifteen failures — the one item 45-11 and 45-12 both handed forward — are
resolved.

## Requirements

`VIS-03`, `VIS-05`, `VIS-06`, `VIS-08` and `VIS-09` were deliberately **not** marked complete, on
the precedent 45-09 through 45-12 set: **45-14 is the human check against the design image**, and
marking a requirement complete before a human has looked at the page would claim a milestone this
plan cannot evidence — the same discipline this plan just enforced on the Programming row.

## Known Stubs

None.

## Threat Flags

None. This plan added no rendering surface. The one input surface it touched — `?module=` — gained
assertions rather than behaviour.

## TDD Gate Compliance

This plan is test reconciliation, not feature work, so the RED/GREEN sequence applies only to the
one behaviour change it made: the at-rest disclosure. That change was proven RED first, by the five
assertions that fail against the pre-fix presenter (measured, recorded above), and GREEN after.

## Self-Check: PASSED

`45-13-SUMMARY.md` exists. `resources/views/components/cockpit/attention.blade.php` is gone and
`cav-attn` appears nowhere in `resources/` or `app/`. All four commits are present in `git log`.
The only tracked file deleted is the one this plan deliberately removed.
