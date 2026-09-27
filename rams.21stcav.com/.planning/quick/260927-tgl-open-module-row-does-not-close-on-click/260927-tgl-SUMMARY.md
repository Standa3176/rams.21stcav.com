---
phase: quick
plan: 260927-tgl
subsystem: cockpit
tags: [cockpit, inline-drawer, toggle, stretched-link, accessibility, url-state]
requires:
  - "46.3 D-01 / D-02 — the inline drawer and collapse-away"
provides:
  - "The open module row closes its own drawer when clicked again — a third way back"
affects:
  - resources/views/components/cockpit/module-row.blade.php
  - tests/Feature/Cockpit/CockpitVisualTest.php
  - tests/Feature/Cockpit/CockpitPageTest.php
  - tests/Feature/Cockpit/CockpitInlineDrawerEndToEndTest.php
key-files:
  modified:
    - resources/views/components/cockpit/module-row.blade.php
    - tests/Feature/Cockpit/CockpitVisualTest.php
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitInlineDrawerEndToEndTest.php
decisions:
  - "The open row's anchor drops the module key for the bare cockpit URL — still query-string state, no JavaScript"
  - "The glyph is SWAPPED to close, never rotated: a transform on the anchor would collapse the stretched overlay"
  - "The accessible name moves with the state — Close {title} while open"
  - "No aria-expanded: no id to pair with aria-controls, and the anchor navigates rather than toggling in place"
  - "Back to all modules and the header close BOTH stay — this is a third way out, not a replacement"
---

# Quick Task 260927-tgl: the open module row now closes on click — Summary

The user could open a module on the live cockpit and then not shut it from the
same row: "in cockpt when i click to open something like site survey , ii
cannot click it again to close it (to see the other options again". The row is
now a toggle. One Blade file changed; no CSS, no JavaScript, no new glyph.

## The defect, exactly

`module-row.blade.php` built one href for both of the row's states:

```
href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key']]) }}"
```

While a row was OPEN that is the URL the browser was already on. The stretched
link fired, navigated to the current page, and re-rendered something identical
— indistinguishable from a dead control. Nothing was broken in the CSS, the
presenter or the controller; the anchor was aimed at the wrong place in one of
its two states.

## The change

**Before** (open row, Site Survey):

```
href="http://rams.21stcav.com.test/projects/1/cockpit?module=site-survey"
```

**After** (open row):

```
href="http://rams.21stcav.com.test/projects/1/cockpit"
```

Closed rows are untouched and still carry their own `?module={key}`. The open
row's destination is now identical to the one the drawer's `Back to all
modules` link and its header close control already used, so all three ways out
agree about what "closed" means — and so do the back button and a bookmark,
because this is still query-string state.

## The affordance, not just the href

Three channels moved together, because a row that closes must not still read as
"open this":

- **Glyph** — the open row draws the existing `close` mark instead of the
  right-pointing `arrow`; the same mark the drawer header already shows for the
  same destination. **Swapped, never rotated.** A `transform` on
  `.cav-module__open` would make the anchor a containing block and collapse the
  full-row overlay onto the 28px glyph, silently — the trap cockpit.css warns
  about in prose at four places. No rule on that anchor declares a `transform`
  or a `position`, and the stretched-link scan in `CockpitVisualTest` was run
  after every edit and stayed green throughout.
- **Accessible name** — `Open {title}` closed, `Close {title}` open. The glyph
  is `aria-hidden`, so this label is the link's ONLY name; leaving it reading
  "Open" would be the same lie in the screen-reader channel.
- **`aria-expanded` — considered and REFUSED.** There is no `id` on the drawer
  to pair one with through `aria-controls`, and this anchor NAVIGATES rather
  than toggling anything in place. Announcing a disclosure widget the page does
  not implement would be a worse claim than the name, which already states the
  state in words. Recorded here so the omission reads as a decision, not an
  oversight.

`.cav-module--active` keeps the meaning 46.3 wave 2 gave it — "this row owns
the drawer beneath it". Neither the tint nor the 4px spine moved.

## Assertions retired by name, and their successors

None was deleted to make a red test pass. Each successor asserts **both
halves** of the toggle, so a change making every row a toggle — or no row a
toggle — goes red.

**1. `CockpitVisualTest::test_each_module_row_holds_exactly_one_anchor_and_no_other_interactive_element`**
→ renamed to `test_a_closed_row_opens_its_module_and_the_open_row_returns_to_the_list`.

Retired half:

```php
$this->assertStringContainsString(
    'module='.$keyForTitle[$title],
    urldecode($anchor->getAttribute('href')),
    "The \"{$title}\" row's anchor opens a different module."
);
```

It was never wrong — it was VACUOUS. The test rendered only the CLOSED page, so
it could not see the open row at all, and the shipped defect lived only there.
Successor, over BOTH renders:

```php
if ($isOpenRow) {
    ++$openSeen;
    $this->assertSame($bare, $href, …);
    $this->assertStringNotContainsString('module=', urldecode($href), …);
    $this->assertSame('Close '.$title, $anchor->getAttribute('aria-label'), …);
} else {
    ++$closedSeen;
    $this->assertStringContainsString('module='.$keyForTitle[$title], urldecode($href), …);
    $this->assertSame('Open '.$title, $anchor->getAttribute('aria-label'), …);
}
…
$this->assertSame(count($map), $closedSeen, 'No closed row was measured — the opening half is vacuous.');
$this->assertSame(1, $openSeen, 'No open row was measured — the closing half is vacuous.');
```

The one-anchor, direct-child, no-button and no-tabindex checks all survive
unchanged and now run over both renders instead of one.

**2. `CockpitVisualTest::test_the_open_affordance_is_an_anchor_even_when_the_row_is_active`**
— narrowed per state. It asserted `aria-label="Open {title}"` in BOTH renders.
Now the right name is asserted PRESENT and the wrong one ABSENT in each state,
so a row naming both states at once, or reverting to one name in both, fails.

**3. `CockpitPageTest::test_opening_a_module_collapses_every_other_row_away`** —
the surviving row's `assertStringContainsString('module='.$key, $href)` became
`assertSame(route('projects.cockpit', $project), $href)` plus a
`module=`-absent check. The row's IDENTITY is not weakened: the whole-set title
comparison above it already pins which row survived. The collapse-away count
guard in that test is untouched.

**4. `CockpitInlineDrawerEndToEndTest::test_every_rendered_row_still_holds_exactly_one_whole_row_anchor`**
— the walk-wide `assertStringContainsString('module=', $anchors[0])` split by
state (`isset($query['module'])`), with `closedSeen` pinned to
`count(moduleMap())` and `openSeen` asserted non-zero.

## RED proven before GREEN

`$isOpenState` was temporarily pinned to `false` — which reproduces the shipped
behaviour byte for byte — and `CockpitVisualTest` went **`Tests: 2 failed, 9
passed`**: the renamed toggle test and the narrowed affordance test, and only
those two. The stretched-link test stayed green in that red run, which confirms
the two failures were the toggle property and not collateral. The line was then
restored and the same file returned **`Tests: 11 passed`**.

## Preserved

- `Back to all modules` and the header close control — both still rendered,
  both still asserted
  (`CockpitPageTest::test_the_open_drawer_carries_a_named_visible_way_back_above_its_tab_strip`
  pins the back link's href to the bare URL and its position above the tab
  strip; the header close keeps its own assertions in the same file). Whether
  the row now makes one of them redundant is the user's call at the 46.3
  checkpoint, not this task's.
- The collapse-away guard: `count(moduleMap())` rows closed, exactly 1 open,
  derived never literal.
- The iff count property — a row renders a count iff non-zero. No "exactly one
  count" assertion was added anywhere.
- Fence counts 2 / 21 / 9 / 13 — no fence constant was touched, and the new
  copy (`Close {title}`) is in no deferred-affordance list.
- Exactly one anchor per row, a direct child of it; no button, no tabindex, no
  handler attribute, no Alpine, no JavaScript.

## Deploy note

**`npm run build` is NOT needed.** `resources/css/cockpit.css` is a Vite entry
and this task did not touch it — no CSS file changed at all. The change is one
Blade template plus three test files, so a deploy is a checkout and a view-cache
clear.

## Gates

Each suite was run in its OWN invocation, in the foreground, redirected to a
file. **Every figure below comes from a run that printed its own `Tests:` line
with a `Duration:`** — none from a killed or stalled run.

| Gate | Entering | Result |
|---|---|---|
| `tests/Feature/Cockpit` | `Tests: 295 passed (6167 assertions)` / 178.47s | `Tests: 295 passed (6198 assertions)` / 133.18s |
| `tests/Unit/Cockpit` | `Tests: 92 passed (762 assertions)` / 11.88s | `Tests: 92 passed (762 assertions)` / 12.00s |
| **Cockpit total** | **387 / 0** | **387 / 0** — no regression, no net new test (one renamed, three narrowed; +31 assertions) |
| `tests/Feature/Worksheets` | — | `Tests: 177 passed (1652 assertions)` / 43.86s |
| D-06 baseline | — | `Tests: 2 skipped, 159 passed (396 assertions)` / 39.31s — **PASS** on `>= 159 passed AND 0 failed`; the 2 skips are the documented ext-imagick self-skips. Never compared to 161 |
| sha256 pins | — | All three byte-identical: `9ED63C4C…`, `EDAD1982…`, `73BB8AD6…` |

## Test-harness observation (not a code defect)

The 46.4-01 note is confirmed and extended. `gate-46.ps1 -Path
tests/Feature/Cockpit` hung once at the very start of this task — flat output
for 55 minutes, phpunit alive, no `Tests:` line. It was killed (⚠️ **that kill
reports "exit code 0"; no figure was quoted from it**) and the identical
invocation then completed in 178s. Re-running is the remedy.

Separately, and worth writing down: `artisan test` output carries ANSI colour
codes, so `grep '^ *Tests:'` finds nothing on a perfectly healthy run. Strip
the escapes before grepping, or a green run reads as a stall and gets killed.

## Self-Check: PASSED

- `resources/views/components/cockpit/module-row.blade.php` — FOUND, modified
- `tests/Feature/Cockpit/CockpitVisualTest.php` — FOUND, modified
- `tests/Feature/Cockpit/CockpitPageTest.php` — FOUND, modified
- `tests/Feature/Cockpit/CockpitInlineDrawerEndToEndTest.php` — FOUND, modified
- `.planning/quick/260927-tgl-open-module-row-does-not-close-on-click/PLAN.md` — FOUND
