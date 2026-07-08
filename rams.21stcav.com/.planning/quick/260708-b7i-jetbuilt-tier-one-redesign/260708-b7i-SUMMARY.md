---
quick_id: 260708-b7i
slug: jetbuilt-tier-one-redesign
title: Tier-one visual redesign — landed 2026-07-08
status: complete
started: 2026-07-08
completed: 2026-07-08
plans_completed: 1
tasks_completed: 5
files_modified: 17
commits:
  - 49587f1  feat(design) task 1/5 — foundation tokens + Inter self-hosted
  - b879ce7  feat(design) task 2/5 — layout shell + sidebar tier-one chrome
  - 108bda2  feat(design) task 3/5 — primitive Blade components on new tokens
  - 75d270c  feat(design) task 4/5 — dashboard tier-one polish
  - 93cc3c8  feat(design) task 5/5 — project detail tier-one polish
---

## Summary

Full tier-one visual redesign against the JetBuilt-inspired reference the
user shared. Kept every route, controller, service, JSON contract, and
Alpine.js component intact — only the visual layer moved. Landed in 5
atomic commits so any single step can be reverted in isolation.

The redesign is anchored on **cool slate neutrals + indigo brand +
semantic status**, with **Inter Variable self-hosted** and a **light
sidebar shell with a 2px indigo left-rail** on active items. Palette
retune uses CSS-variable indirection at `:root` in
`layouts/app.blade.php` so thousands of usages of `.card`, `.btn-primary`,
`.btn-teal`, `.badge`, `.data-table`, `.stat-*` across ~200 blade files
inherit the new tokens automatically — no class renames, no drive-by
find-replace.

## What shipped

### Task 1 — Foundation (`49587f1`)

- Tailwind theme extended with the tier-one token set — cool slate
  neutrals as semantic aliases (`ink`, `body`, `muted`, `hairline`,
  `canvas`, `card`, `sidebar`), indigo brand (50/100/500/600/700/800),
  restrained shadow ramp (card / card-hover / lift / popover /
  inset-focus), radii ramp (sm/md/lg/xl/2xl), letter-spacing scale
  (tight / tighter / tighter-plus).
- Inter Variable self-hosted via `@fontsource-variable/inter`
  (~48 kB latin subset, other subsets lazy). Legacy Figtree retired
  from the UI; still referenced by `resources/views/pdf/` where
  dompdf/mpdf don't read tailwind.config.js.
- Base CSS applies `bg-canvas text-body font-sans antialiased` on
  body with `font-feature-settings: 'cv11','ss01','ss03','zero'` for
  Inter's tier-one glyph opt-ins (open-loop g, alternate a, cursive
  6/9, slashed 0). Headings default to `text-ink` with -0.015em
  tracking and text-wrap: balance.

### Task 2 — Layout shell + sidebar chrome (`b879ce7`)

- Retuned the CSS custom properties at `:root` in
  `layouts/app.blade.php` so every screen inherits the new palette
  through the variable indirection.
- Ink scale swapped from warm SCC v2 tones to cool slate. Paper
  surfaces from `#F7F6F2` cream to `#F6F8FB` cool canvas. Brand
  aliased onto legacy `--teal-*` names so every `.btn-teal` /
  `.badge-teal` snaps to indigo. Gold aliased to indigo brand so
  any lingering `--gold-500` reference stays coherent.
- Sidebar tokens flipped from dark teal gradient to light chrome
  (`--sidebar-bg #FBFBFD`, `--sidebar-fg #334155`,
  `--sidebar-active-bg #EEF2FF`).
- Header logo panel: light bg + border-right + indigo gradient mark
  with inset highlight/shadow (was solid dark teal + gold tile).
- `.app-header` uses `color-mix()` against paper so the blur layer
  reads consistently.
- `navigation.blade.php` fully rewritten for tier-one: ⌘K search
  affordance at the top, 2px indigo left-rail on active items via
  `::before`, muted section labels, admin section flattened
  (no more gold tint — indigo palette throughout).

### Task 3 — Primitive Blade components (`108bda2`)

- The 5 Breeze-scaffolded primitives (primary-button, secondary-
  button, danger-button, text-input, nav-link, input-label) were
  still on the `laravel/breeze` init defaults — `bg-gray-800`,
  `text-indigo-500`, `border-gray-300`, `uppercase tracking-widest`.
  Retuned to use the new token classes: brand-600 solid,
  hairline-strong borders, tight tracking, tier-one focus ring via
  the new `shadow-inset-focus` token. Every component keeps its
  public API — `@props`, attribute merge shape — so no caller site
  breaks.

### Task 4 — Dashboard (`75d270c`)

- KPI stat card colours moved from SCC-teal palette to tier-one
  semantic tokens (brand / info / violet / success). Icon strokes
  tightened to 1.75px, sized down to 18px.
- Quick-link inline `style="background:…"` attrs replaced with named
  `.dql-i-*` modifier classes so the palette lives with the styles.
- Every hardcoded hex in the dashboard's `<style>` block swapped for
  token variables. Health-row hover uses `color-mix()` against
  `--teal-100`. Progress bar fill: solid → gradient with subtle glow
  (matches the delivery-stepper design from the preview).
- Chip active state softened (hover uses `color-mix()` at 8% for a
  "warming up" feel before commit).
- `dashboard/stat-card.blade.php` internal `<style>` wrapped in
  `@once` (so N stat cards emit CSS once, not N times). Value
  colour flipped from accent hex to `--ink-900` — the tier-one
  pattern is accent in the icon tile / trend line, ink in the raw
  stat.

### Task 5 — Project detail (`93cc3c8`)

- The busiest screen in the app got a surgical polish. Its
  `.psv-*` custom CSS block already flowed through `--teal` /
  `--success` / `--surface` etc., so most of the palette shift
  landed for free.
- `.page-header .page-title` — display-font 500 → Inter 700 with
  -0.025em tracking. The busiest screen needed a proper anchor.
- `.psv__main .section-card__title` — 600 display-font → 700 Inter
  15px -0.015em. Matches the KPI labels visually.
- `.psv-step.is-current` — solid teal → indigo linear gradient
  (500→700) + 3px pulse ring via box-shadow (mirrors the delivery-
  progress stepper on the v2 preview).
- `.psv-step.is-done` — hard `#166534` override → `var(--success)`
  with translucent success-border via `color-mix()`.
- `.psv-progress__fill` — solid → indigo gradient with glow.
- `.psv-tab.is-active .psv-tab-count` — solid teal + white → soft
  teal-100 with teal-700 text (same for the .ws-tab counterpart).

## Reference visuals

- Interactive tier-one preview (live):
  https://claude.ai/code/artifact/687370c1-160a-4b79-a957-b21a1b949b92
- JetBuilt reference screenshot: `jetbuilt-01-landing.png` in this
  task directory.
- User-provided design mockup: original attachment in the session
  transcript (the 3-panel showcase that anchored task 2's shell +
  sidebar work).

## Verification

- `npm run build` clean at every task boundary. Final gzipped CSS
  ~12.4 kB (was ~10 kB pre-session — the 2.4 kB delta covers the
  Inter Variable font subsets, expanded palette, and new component
  states).
- `DashboardControllerTest` 6/6 pass (14 assertions).
- 5 pre-existing `ActualHoursWidgetTest` failures **verified as
  unrelated to the redesign** — the same 5 tests fail on the
  pre-session commit `c3e129e` (session-save 2026-07-08 before task
  1). Same pattern as the earlier `PublicWorksheetSignoffTest`
  drift closed in commit `31a4d52`. Worth a separate cleanup pass;
  not blocking here.

## What was intentionally NOT done

- No sidebar structure changes beyond chrome (nav item list intact,
  same routes, same order, same Alpine hooks).
- No route or controller changes.
- No JS behaviour changes (Alpine components untouched).
- No RAMS review, O&M edit, surveys, worksheets, or cable schedules
  screens fully rebuilt. They inherit the token retune and primitive
  polish automatically but not a fresh hierarchy pass — queued for a
  follow-up sweep.
- Dark mode not shipped (only the artifact preview has it; the
  Laravel app is light-only for now).

## Follow-up backlog

- Sweep remaining screens (RAMS index/review, O&M index/edit,
  surveys index/show, worksheets, cable schedules, admin) into the
  tier-one hierarchy pattern.
- Extract more repeated markup into components (`x-card`,
  `x-stat-card`, `x-toolbar`, `x-radial-progress`).
- Consider a Storybook-lite gallery page under `/design` for
  internal reference.
- Optional: retune the DOCX / PDF templates in
  `resources/views/pdf/` to align with the new indigo brand (still
  teal since dompdf/mpdf don't read the token system).
- Wire live global search behind the ⌘K affordance in the sidebar.
- Retire the 5 `ActualHoursWidgetTest` failures as a separate
  quick-task (out of scope for this design pass).
