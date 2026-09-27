---
phase: quick
plan: 260927-tgl
type: defect
severity: live-on-production
subsystem: cockpit
autonomous: true
---

# Quick Task 260927-tgl: the open module row will not close on click

## Objective

The user, on the live cockpit:

> "in cockpt when i click to open something like site survey , ii cannot click
> it again to close it (to see the other options again"

Clicking a module row opens its inline drawer. Clicking **the same row again**
does nothing at all — no navigation, no feedback, nothing. The row must
TOGGLE: a second click closes the drawer and brings the other three rows back.

## Root cause

`resources/views/components/cockpit/module-row.blade.php` builds one href for
every row, open or closed:

```
href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key']]) }}"
```

While that row is the open one, that is **the URL the browser is already on**.
The stretched link is working perfectly; it is navigating to the current page,
which renders identically. Nothing is broken in the CSS, the presenter or the
controller — the anchor is simply pointing at the wrong place in one of its
two states.

`Back to all modules` and the header close control both work and both were
found by nobody: the user's instinct went to the row. The row should honour it.

## Approach — one href, two states, still no JavaScript

When `$active` is true the row's anchor points at the **bare cockpit URL**
(`route('projects.cockpit', $project)`), dropping `module=` exactly the way the
panel's own two close controls already do. Closed rows are unchanged.

This stays query-string state like everything else on this page, so the back
button and bookmarks keep working, and the read-only fence is untouched: still
exactly one `<a>` per row, still exactly one GET, still no handler attribute,
no `<button>`, no `tabindex`.

## Tasks

1. **[fix]** `module-row.blade.php` — the anchor's `href`, `aria-label` and
   glyph all branch on `$active`.
2. **[test]** Retire the single-state anchor assertions by name and replace
   them with the two-state toggle property.

## Decisions

- **The glyph changes with the state.** The open row's chevron becomes the
  existing `close` glyph (`x-cockpit.icon name="close"`), the same one the
  drawer header already uses for the same destination. A right-pointing arrow
  on a control that closes is a lie, and the arrow is the only thing most
  users will read. No new glyph, no CSS transform — a `transform` on
  `.cav-module__open` would make the anchor a containing block and collapse
  the stretched overlay onto the 28px chevron, which is the trap this file's
  own comments warn about four times.
- **The accessible name changes with the state**, from `Open {title}` to
  `Close {title}`. The glyph is `aria-hidden`, so this label is the link's ONLY
  accessible name; leaving it reading "Open" while the control closes would be
  the same lie in the screen-reader channel.
- **No `aria-expanded`.** It was considered and refused: there is no `id` on
  the drawer to pair it with via `aria-controls`, and this anchor NAVIGATES
  rather than toggling anything in place. Claiming a disclosure widget the
  page does not implement is worse than the accessible name, which already
  states the state truthfully and does so in words.
- **`cav-module--active` keeps its meaning** — "this row owns the drawer
  beneath it" (46.3 wave 2). The tint and spine are unchanged; only the
  anchor's destination, name and glyph move.
- **Neither way back is removed.** `Back to all modules` and the header close
  both stay, both stay asserted. This is a THIRD route out, not a replacement.
  Whether the row makes one of them redundant is the user's call at the 46.3
  checkpoint, not this task's.
- **No CSS file is touched**, so `resources/css/cockpit.css` does not change
  and **no `npm run build` is needed** to deploy this.

## The assertions retired, and what replaced them

Never deleted to make a red test pass — each is replaced by a STRONGER
property that asserts both halves, so a future change making every row a
toggle (or no row a toggle) goes red.

| Retired, by name | Why it can no longer hold | Successor |
|---|---|---|
| `CockpitVisualTest::test_each_module_row_holds_exactly_one_anchor_and_no_other_interactive_element` — the `module={key}` half | Rendered only the CLOSED page, so it could never see the open row at all. Vacuous for the new behaviour rather than wrong. | Same test, now rendering BOTH states: a closed row's anchor carries its own key; the open row's anchor is the bare cockpit URL and carries no `module=`. |
| `CockpitVisualTest::test_the_open_affordance_is_an_anchor_even_when_the_row_is_active` — `aria-label="Open {title}"` asserted in both states | The open row is now named `Close {title}`. | Per-state name: `Open {title}` closed, `Close {title}` open, each asserted ABSENT in the other state. |
| `CockpitPageTest::test_opening_a_module_collapses_every_other_row_away` — `module={key}` on the surviving row's anchor | That row is the OPEN one; its anchor is now the way out. | The surviving row's anchor equals the bare URL exactly, and carries no `module=`. The collapse-away count guard it sits in is untouched. |

## Preserved, deliberately

- The collapse-away guard: `count(moduleMap())` rows closed, exactly **1**
  open, derived never literal.
- The iff count property — a row renders a count **iff** non-zero. No test
  anywhere asserts "exactly one count".
- Exactly one `<a>` per row, a direct child of the row; no `<button>`, no
  `tabindex`; the stretched-link CSS pairing untouched in both directions.

## Gates

- `tests/Feature/Cockpit` and `tests/Unit/Cockpit` — run SEPARATELY, summing
  to 387 / 0 entering; expected to rise
- `tests/Feature/Worksheets`
- D-06 baseline `>= 159 passed AND 0 failed` — never equality against 161
- Three sha256 pins unchanged
