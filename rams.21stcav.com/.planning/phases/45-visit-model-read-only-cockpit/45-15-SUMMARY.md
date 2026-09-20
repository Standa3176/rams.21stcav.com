---
phase: 45-visit-model-read-only-cockpit
plan: 15
subsystem: cockpit-presentation
tags: [sketch-004, tokens, contrast-ledger, hues, vis-04, vis-10, read-only-fence]
status: COMPLETE — gates green, the human visual check remains the user's
requires:
  - "The rebuilt cockpit and its token set (Plans 45-09..45-13)"
  - "The measured D-06 baseline and the three pre-phase hashes (45-BASELINE.md)"
provides:
  - "Nine per-module hue pairs, six avatar fills and two surface tokens, every one with a computed contrast figure"
  - "One .cav-tile rule that serves nine module tiles, three KPI tiles and the panel header"
  - "A hue KEY on CockpitModulePresenter::MODULE_MAP, so Blade reads colour and never decides it"
  - "CockpitVisualTest — nine distinct hues, no colour literal in the rendered region, .cav-brand still alone, greyscale and anchor guarantees re-asserted"
affects:
  - "The user — the 'less colourful than my example' gap is what this plan closes; whether it is closed enough is their call"
  - "Phase 46 — inherits the tile, hue and avatar mechanism for any row it adds"
tech-stack:
  added: []
  patterns:
    - "a hue is IDENTITY, never state — status stays in a chip that carries a glyph shape and its words"
    - "two local custom properties (--cav-tile-bg/-fg) let ONE rule serve thirteen tinted tiles"
    - "assert over the presenter's map, never over the literal nine — D-16 already moved that number once"
key-files:
  created:
    - tests/Feature/Cockpit/CockpitVisualTest.php
    - .planning/phases/45-visit-model-read-only-cockpit/45-15-SUMMARY.md
  modified:
    - resources/css/cav-tokens.css
    - resources/css/cockpit.css
    - resources/views/components/cockpit/icon.blade.php
    - resources/views/components/cockpit/module-row.blade.php
    - resources/views/components/cockpit/kpi-card.blade.php
    - resources/views/components/cockpit/activity-row.blade.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - app/Support/Cockpit/CockpitModulePresenter.php
    - app/Support/Cockpit/CockpitPanelPresenter.php
decisions:
  - "The gap was a SPECIFICATION error, not an execution one — 45-09 was right to derive from the app's one-accent palette and right to ban hex in Blade; the design needs nine hues and none existed to reach for"
  - "Six avatar slots, not the plan's floor of four — the feed shows six entries at a time and `user_id % 4` collides visibly at that width"
  - "Avatar hue is keyed on user_id, NOT a name hash — a person must keep their colour when they are renamed"
  - "The three KPI tiles BORROW module tokens through four semantic aliases (ok/warn/date/doc) rather than declaring a parallel palette"
  - "Programming's tile is inverted (dark navy, light glyph) exactly as the design draws it; it is also the one module with no file store, so the list reading differently at that row is correct"
  - "The stage chip is tinted by the project status key the header presenter already returned — the page echoes a key, cockpit.css owns every value"
  - "The status chip was NOT retinted per state beyond what it already had: its three glyph SHAPES are the greyscale guarantee and colour stays the third channel"
metrics:
  duration: ~55 min
  completed: 2026-09-20
---

# Phase 45 Plan 15: Visual polish to match sketch 004 — Summary

Nine per-module hue pairs, six avatar fills and two surface tokens added to
`.cav-brand` with a full contrast ledger, applied through one `.cav-tile` rule
driven by a hue KEY from the presenter — closing the "a lot less colourful and
tier 1 than my example" gap without a single hex reaching a Blade file, without
touching the three sha-locked files, and without weakening the read-only fence.

## What changed

**Task 1 — the tokens** (`4f3cd379`). `--cav-*` count went 41 → 76. Nine
tint/ink pairs (`--cav-hue-{module}-bg` / `-fg`), six avatar fills plus a shared
white ink, `--cav-shadow` and `--cav-active-tint`. `.cav-brand` is still the
file's only selector.

**Task 2 — the application** (`cde510d9`). `CockpitModulePresenter::MODULE_MAP`
gained a `hue` key per module and `modules()` passes it through.
`icon.blade.php` gained an optional `tile` prop that wraps the glyph in a
`cav-tile cav-hue--{key}` span, so the module row, the three KPI cards and the
panel header all draw the same object from one rule instead of three copies of
a fixed teal square. The active row gained the design's faint tint and a 4px
spine; cards, the panel and the panel's inner cards gained the barely-there
elevation; the stage chip is tinted by its status key; activity avatars are
coloured by `user_id % 6`; the progress ring grew to 88px with its percentage
centred inside it and a bar beneath the sentence.

**Task 3 — the pin** (`69e1127e`). `CockpitVisualTest`, eight tests, 93
assertions.

## The contrast ledger

Every pairing computed (sRGB relative luminance, WCAG 2.1), none eyeballed. The
glyph on a tile is a non-text mark (3:1 floor); all of them clear the 4.5:1
text floor as well, which is deliberate headroom.

| Hue | Tint | Ink | Ratio |
|---|---|---|---|
| survey | `#DCE9FF` | `#1E5FE0` | 4.55:1 |
| worksheet | `#DCFCE7` | `#15803D` | 4.57:1 |
| programme | `#F3E8FF` | `#7E22CE` | 5.92:1 |
| rams | `#E0F2FE` | `#0369A1` | 5.17:1 |
| drawings | `#D1FAE5` | `#047857` | 4.84:1 |
| om | `#E0E7FF` | `#4338CA` | 6.41:1 |
| cable | `#FEF3C7` | `#92400E` | 6.37:1 |
| programming | `#0F172A` | `#DCE9FF` | 14.57:1 (inverted) |
| snagging | `#FFE4E6` | `#BE123C` | 5.24:1 |

Avatar initials are small TEXT, so the 4.5:1 floor applies, not 3:1. White on
`#1E5FE0` 5.57 · `#7E22CE` 6.98 · `#047857` 5.48 · `#B45309` 5.02 · `#BE123C`
6.29 · `#4338CA` 7.90.

**One pairing FAILED and is recorded in the token file rather than quietly
avoided.** `--cav-light` `#64748B` on the new active-row tint `#F2F7FF` is
**4.42:1** — under the 4.5:1 text floor. Nothing inside an active row may use
it; the description and count phrase are `--cav-mid` (9.63:1), which is what
they already were. This is the third instance of the same class of mistake in
this phase (the 4.09:1 chip text, and the `#767676`-at-0.7 blend that produced
the no-opacity-on-small-text rule), so it is written beside the token with the
number, not left to be rediscovered.

## What did NOT change, on purpose

- **The three sha-locked files.** `layouts/app.blade.php`, `resources/css/app.css`
  and `tailwind.config.js` re-hashed on the working tree with the baseline's own
  `Get-FileHash` command: `9ED63C4C…0557`, `EDAD1982…2133`, `73BB8AD6…74BB` —
  all three identical to `4abd2b24`.
- **The read-only fence.** No `<button>`, `<form>`, `<input>`, `<select>`,
  `<textarea>` or `<script>` entered the region. "Open drawer" is still an `<a>`
  even when the active row styles it as a filled dark button, and
  `CockpitVisualTest` now asserts that in BOTH states because a filled button is
  exactly what tempts someone to change the element.
- **No JavaScript, no Alpine.** The ring is an inline SVG with a CSS-centred
  number; the bars are spans, never `<progress>`.
- **Quick actions, the Actions dropdown, the "…" overflow.** Phase 46/48. The
  design shows them; this plan did not build them.
- **The status chip's three glyph shapes.** The greyscale guarantee. Colour
  remains the third channel behind shape and words.

## Deviations from Plan

### Auto-fixed / extended

**1. [Rule 2 — missing critical functionality] `CockpitPanelPresenter` was
edited although the plan's `files_modified` did not list it.**
- **Found during:** Task 2.
- **Issue:** The plan requires avatar hues "cycled deterministically by user id
  so a person keeps their colour", but the activity feed's rows are built in
  `CockpitPanelPresenter::activity()`, which the plan did not list. The only way
  to honour "by user id" without it was to hash something Blade could see —
  the initials or the name — which changes the moment a person is renamed, and
  which would have put a derivation in a template.
- **Fix:** Added `AVATAR_HUES = 6` and a documented `avatarHue(?int)` returning
  a SLOT, never a colour. A null user (the log's "System" actor) takes slot 0.
- **Files modified:** `app/Support/Cockpit/CockpitPanelPresenter.php`.
- **Commit:** `cde510d9`.

**2. [Rule 2] Six avatar slots where the plan's floor was four.** The feed
renders six entries at a time; `% 4` collides visibly at that width. Six fills,
all still clearing 4.5:1 with white.

**3. [Rule 1 — bug, caught before commit] Two Blade docblock additions landed
AFTER the `--}}` terminator** in `kpi-card` and `activity-row`, which would have
printed comment prose onto the page. Caught by re-reading the terminators, fixed
before any test ran. `php -l` over all compiled views after `view:clear`: 0
failures.

### Out of scope, not touched

The design's second stage chip still has no source (one project, one status),
the site-contact email still does not exist on `SiteSurvey`, and "Planned start"
is still not "Proposed install date". All three were settled in 45-10 and remain
honest rather than drawn.

## Gates

| Gate | Result |
|---|---|
| Cockpit suite (`tests/Feature/Cockpit`, `tests/Unit/Cockpit`, `CockpitFlagDefaultTest`) | `Tests: 167 passed (1746 assertions)` |
| `CockpitVisualTest` alone | `Tests: 8 passed (93 assertions)` |
| D-06 12-path baseline (character-identical command) | `Tests: 2 skipped, 159 passed (396 assertions)` — `>= 159 passed AND 0 failed` ✓ |
| Three sha256 pins, working-tree `Get-FileHash` | all three identical to `4abd2b24` ✓ |
| `php -l` over compiled views after `view:clear` | 0 failures |
| `npm run build` | `assets/cockpit-CPMPr8MD.css` 19.89 kB, built in 27.23s |

The two D-06 skips are the pre-existing `ext-imagick` HEIC skips named in
`45-BASELINE.md`. Equality against 161 was NOT asserted.

## What still differs from the design image

Stated plainly, because the accepted standard is the image:

1. **Quick actions, the Actions dropdown and the row "…" menus are absent.**
   Deliberate — all writes, all Phase 46/48.
2. **One stage chip, not two.** A project has one status; the second has no
   source.
3. **The active row's filled button is the app accent `#1E5FE0`, not the
   design's darker navy.** Adding a navy-button token for one element was not
   worth a fourteenth colour; white on the accent is 5.57:1 and reads as filled
   and dark against the outlined siblings.
4. **No screenshot comparison was made.** No browser tool was available in this
   session, so every claim above is from the markup, the CSS and the tests —
   not from looking at the rendered page. The seeded fixture (project 3,
   `cockpit-check@local.test`) exists precisely so the user can do that, and
   the phase checkpoint that asks them to is still open.

## Self-Check: PASSED

All created and modified files verified present on disk; all three commits
verified in `git log`.
