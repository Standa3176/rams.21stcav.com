---
phase: quick
plan: 260920-rwc
subsystem: ui
tags: [cockpit, stretched-link, accessibility, read-only-fence, no-javascript, phase-45]
requires:
  - "Phase 45 cockpit (module row, cav-tokens.css, cockpit.css, CockpitReadOnlyFenceTest)"
provides:
  - "The whole module row is the click target for opening the side panel, via the stretched-link pattern and no JavaScript"
  - "Per-row chevron (x-cockpit.icon name=arrow) in place of the visible 'Open drawer' pill"
  - "Row-wide hover tint and row-wide keyboard focus ring, both token-driven"
  - "Two new tokens on .cav-brand: --cav-row-hover (#F8FAFC) and --cav-row-hover-active (#E8F1FF)"
  - "CockpitVisualTest::test_the_whole_module_row_is_the_click_target_without_javascript — asserts both halves of the stretched link, which are individually silent when broken"
  - "CockpitVisualTest::test_the_static_visit_row_still_advertises_nothing — pins the cursor:pointer ban for the rows that are genuinely inert"
affects:
  - resources/views/components/cockpit/module-row.blade.php
  - resources/css/cockpit.css module-row rules and its top-of-file read-only-fence doctrine
  - resources/css/cav-tokens.css
  - "any future phase that makes a cockpit row openable — the pattern and its trap are documented at the .cav-module rule"
tech-stack:
  added: []
  patterns:
    - "Stretched link: `.cav-module { position: relative }` + `.cav-module__open::after { content:''; position:absolute; inset:0 }`. One anchor, one GET, zero JavaScript, zero nested interactive elements."
    - "Focus ring relocated onto the overlay pseudo-element (`:focus-visible::after`) so the ring surrounds the ROW rather than the 28px chevron."
key-files:
  created:
    - .planning/quick/260920-rwc-whole-module-row-opens-the-side-panel/260920-rwc-SUMMARY.md
  modified:
    - resources/views/components/cockpit/module-row.blade.php
    - resources/css/cockpit.css
    - resources/css/cav-tokens.css
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitVisualTest.php
decisions:
  - "Accessible name dropped from 'Open drawer: {title}' to 'Open {title}'. The word 'drawer' was a reference to visible on-screen copy; with that copy replaced by a glyph it named nothing a user could see, so it had become jargon. The module title stays, which is the part that actually matters — it is what stops a screen reader's link list showing nine identical entries. The label is now the link's ONLY accessible name, since the chevron is aria-hidden."
  - "TWO hover tokens, not one. Reusing --cav-active-tint for hover would have made an inactive row hover into exactly the open row's colour, which reads as 'this row is open' and undoes the marker that tint exists to be. So inactive rows hover to a faint NEUTRAL (--cav-row-hover #F8FAFC) and the active row hovers one step deeper into its own blue (--cav-row-hover-active #E8F1FF). Contrast for all six text/tint pairings computed and recorded in cav-tokens.css rather than assumed — one pairing (--cav-light on #E8F1FF, 4.18:1) FAILS the text floor and is written down even though the active row already bans --cav-light outright."
  - "The read-only fence doctrine at the top of cockpit.css was AMENDED, not weakened. Its rule is 'a row that looks clickable but is not is worse than one that looks inert'. The module row is now genuinely a link over its whole area, so cursor:pointer and a hover tint are the rule being applied rather than broken. The ban survives untouched for .cav-visit and the unlinked .cav-file, and is now pinned by a test so the amendment cannot be read as blanket permission."
  - "Replaced the old `assertStringContainsString('Open drawer', $html)` assertions with something STRONGER rather than simply deleting them. Had the aria-label kept the word 'drawer', those assertions would have gone on passing off the label alone while saying nothing at all about the visible row — a green test covering nothing. They now assert the per-module accessible name AND assert the old copy absent, so a half-reverted change cannot pass."
  - "No JS of any kind. A click handler was never an option: x-on:/@click/onclick/wire: are asserted absent inside the region by CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES, <button> and <script> are banned by FORBIDDEN_MARKUP, and opening the panel is a GET carrying ?module={key}."
requirements-completed: [QUICK-rwc]
metrics:
  duration: "~50m"
  completed: "2026-09-20"
---

# Quick Task 260920-rwc: Whole module row opens the side panel Summary

The cockpit's nine module rows now open the side panel when clicked anywhere on the row, not only
on a pill at the right edge. The pill is gone; a chevron marks the affordance instead. Implemented
entirely in CSS with the stretched-link pattern — the same single `<a>`, the same single GET, and
no JavaScript.

## What the user asked for

> "rather than the open drawer button can the app open the side window when the drawer is clicked?"

They then chose, explicitly: replace the button with a chevron, make the whole row clickable, tint
the row on hover.

## What changed

**Markup** (`module-row.blade.php`) — one line of substance. The anchor keeps its class, its
`href` to `?module={key}` and its `aria-label`; it loses its visible text `Open drawer` and gains
`<x-cockpit.icon name="arrow" />`. The glyph is decorative and `x-cockpit.icon` renders every glyph
`aria-hidden`, so the label is now the link's only accessible name:

    aria-label="Open {{ $module['title'] }}"      e.g. "Open O&M manual"

**CSS** (`cockpit.css`) — the row became a positioned ancestor and the anchor grew an overlay:

    .cav-cockpit .cav-module            { position: relative; }
    .cav-cockpit .cav-module__open::after { content:''; position:absolute; inset:0; }

plus `cursor: pointer` and `background: var(--cav-row-hover)` on `.cav-module:hover`, a deeper
`--cav-row-hover-active` on `.cav-module--active:hover`, a chevron that darkens on row hover, and
the focus ring moved off the chevron onto the overlay so it surrounds the whole row.

**Tokens** (`cav-tokens.css`) — `--cav-row-hover` and `--cav-row-hover-active`, both inside the
file's single `.cav-brand` selector, with their contrast arithmetic written out.

## The trap this leaves behind, and where it is written down

`inset: 0` resolves against `.cav-module` **only while `.cav-module__open` stays statically
positioned**. Giving the anchor a `position` of its own — an edit that looks entirely harmless —
collapses the overlay onto the 28px chevron and the row silently stops being clickable, with every
other test in the suite still green. So does removing `position: relative` from the row. Both are
now asserted by
`CockpitVisualTest::test_the_whole_module_row_is_the_click_target_without_javascript`, and both are
documented at the rules themselves, because a failure mode that is silent has to be caught by a
test rather than by review.

## Read-only fence

Intact, unweakened, 9 passed / 445 assertions. Nothing was removed from `FORBIDDEN_MARKUP`,
`DEFERRED_AFFORDANCES` or `BANNED_HANDLER_ATTRIBUTES`, and the enumeration counts (18 / 6 / 5 / 9)
are untouched. The change adds no `<button>`, no `<script>`, no handler attribute and no second
interactive element — it adds one CSS pseudo-element.

**The bottom bracket did NOT need retargeting.** `cockpitRegion()` brackets on `'Open full
project'`, the page-shell footnote link, which this change never touches. The string the change
removed — `'Open drawer'` — was module-row copy and was never a bracket.

It was re-proved live anyway, because the module rows sit *between* the two brackets and this
change rewrote them. Truncating the extracted region to 4,000 chars turned **8 of the 9 fence tests
red**, every one of them on the bottom bracket:

    The extracted region stops before the end of the page shell — it would leave the module rows unexamined.

failing `test_the_cockpit_region_contains_no_form_control_and_no_script`,
`test_none_of_the_deferred_affordances_appears`,
`test_rows_are_static_and_nothing_is_wired_to_a_handler`,
`test_the_one_permitted_navigation_affordance_is_present`,
`test_rendering_the_cockpit_changes_no_row_count`, `test_opening_a_panel_writes_nothing`,
`test_a_hostile_module_value_is_not_reflected` and
`test_repeated_renders_still_change_no_row_count`. Only
`test_the_fence_enumerates_the_whole_deferred_set` stayed green, correctly — it reads the constants,
not the page. Truncation restored; 9 passed; the fence file is byte-identical to its committed
state (`git diff` empty).

## Gates

| Gate | Result |
|---|---|
| Cockpit suite (`tests/Feature/Cockpit`) | `Tests: 102 passed (1508 assertions)` |
| D-06 baseline, 12 enumerated paths | `Tests: 2 skipped, 159 passed (396 assertions)` — `>= 159 passed`, `0 failed` |
| `layouts/app.blade.php` sha256 | PIN OK — byte-identical to `4abd2b24` |
| `resources/css/app.css` sha256 | PIN OK — byte-identical to `4abd2b24` |
| `tailwind.config.js` sha256 | PIN OK — byte-identical to `4abd2b24` |
| `php -l` over compiled views | 0 failures (after `view:clear` + `view:cache`) |
| `npm run build` | `cockpit-DIGtRAlg.css` 20.12 kB — built asset verified to carry `::after{inset:0}`, `cursor:pointer` and both hover tokens |
| No hex literal in any Blade | confirmed |
| `cav-tokens.css` selector count | still exactly one: `.cav-brand` |
| `.planning/STATE.md` | clean, untouched |

The 2 skips are the pre-existing environmental `ext-imagick` HEIC skips named in `45-BASELINE.md`.
Never asserted against a literal 161.
