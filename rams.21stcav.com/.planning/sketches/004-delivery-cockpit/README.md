# Sketch 004 — Delivery cockpit (ACCEPTED, supersedes 002)

**Date:** 2026-09-20 · **Status:** ACCEPTED as the v4.0 design contract
**Source:** design supplied by the user (screenshot, 2026-09-20)
**Supersedes:** sketch 002 (install cockpit) and `45-UI-SPEC-v1-superseded.md`

## What the user asked for, in their words

> "replace with this design … drawers are still in place … create doc and manage visits etc.
> On the right under docs for example a user can see all created project docs in one place
> (ie click and view etc). Create this as the one page a project is delivered from — from doc
> mgt to engineer visits of any type to managing engineer links etc — everything within this
> layout."

**This is the page a project is delivered from.** Not a summary, not a dashboard. Everything a
PM does to deliver a job happens here or in its side panel.

## The layout

```
┌──────────┬────────────────────────────────────────┬──────────────────────┐
│          │  search · notifications · user         │                      │
│  app     ├────────────────────────────────────────┤   SIDE PANEL         │
│  nav     │  breadcrumb                            │   (the drawer)       │
│  (left,  │  PROJECT REF            Actions ▾      │                      │
│  exists  │  site address                          │   module name        │
│  today)  │  site contact · proposed install date  │   project ref        │
│          │                                        │   one-line purpose   │
│          │  ┌─────────┬─────────┬─────────┐       │                      │
│          │  │ Overall │ Next    │ Docs    │       │   Overview│Files│Notes│
│          │  │ status  │ visit   │ x of 9  │       │  ─────────           │
│          │  └─────────┴─────────┴─────────┘       │                      │
│          │  [stage chips]                         │   Progress ring      │
│          │                                        │   n of m visits      │
│          │  Project modules                       │                      │
│          │  ┌──────────────────────────────────┐  │   Recent activity    │
│          │  │ icon  name          status  n    │  │   (avatar · who ·    │
│          │  │       description        [Open]  │  │    what · when)      │
│          │  ├──────────────────────────────────┤  │                      │
│          │  │ … one row per module …           │  │   Quick actions      │
│          │  └──────────────────────────────────┘  │   (PHASE 46+)        │
└──────────┴────────────────────────────────────────┴──────────────────────┘
```

**The drawer is a right-hand side panel**, not an inline accordion. Opening a module row slides
it in. The module list stays visible behind it, so a PM never loses their place.

## Locked decisions from this design

- **D-08 (REVERSES D-07):** the cockpit uses the **app's existing blue and Inter**, inside the
  existing left nav chrome — not the 21CAV teal/Verdana/Poppins refresh. The design the user
  accepted is rendered in the app's own palette, and it sits inside the real application shell.
  The `--cav-*` token set built in Plan 45-03 is **superseded for this page**. See "What happens
  to the teal tokens" below.
- **D-09:** the drawer is a **right side panel with tabs** — `Overview` / `Files` / `Notes`.
  Not an inline `<details>` accordion. The "closed at rest" principle survives: nothing is open
  until the PM opens it, and the page at rest is a quiet list of module rows.
- **D-10:** module rows show a **status chip and a count**, not a traffic-light pip.
  Observed states: `Not started` (grey), `In progress` (blue), `On file` (blue). Counts read
  `0 visits` / `1 visit` / `1 document` / `0 tasks` / `0 files`.
- **D-11 (AMENDED by D-16):** modules in this order: Site survey · First fix and install ·
  Programme and commissioning · RAMS · Drawings · O&M manual · Cable schedule · Programming.
  Commissioning is folded into "Programme and commissioning" rather than standing alone.
  ⚠️ Phase 51 assumed commissioning becomes its own visit type — reconcile before planning 51.

- **D-16 (2026-09-20, user ruling):** **NINE modules — a Snagging row is added** to the eight in
  the design image. The design showed eight rows but a "1 of 9 complete" count; the unaccounted
  ninth is snagging, and `ProjectDeliverable::ALL_KEYS` has nine entries.

  The deciding argument was not the arithmetic. `Visit::TYPE_SNAG` already exists, so with eight
  rows a snag visit would sit in the database and appear on no screen — the exact "collected but
  never turned into work" failure this milestone exists to fix. Nine rows also preserve the
  invariant that **every visit type reaches exactly one module row**, and give Phase 47 a home to
  build into rather than forcing it to reopen this decision.

  **The denominator must equal the rendered module count** — never a hardcoded 9.
- **D-12:** three KPI cards above the module list: **Overall status** (+ stage), **Next visit**
  (+ type), **Documents n of 9 complete** (+ progress bar and percentage).
- **D-13:** the panel's **Files tab is the project's document library** — every created document
  in one place, clickable and viewable. This is the user's explicit example of what the panel is
  for.
- **D-14:** a **Recent activity** feed in the panel — who did what, when, with avatars.
- **D-15:** **Quick actions** in the panel — Create visit · Add note · Upload files. These are
  WRITES and belong to Phase 46 (visits), Phase 47 (snags) and Phase 48 (documents). They are
  **not** built in Phase 45.

## Phase boundaries against this design

Phase 45 is **read-only** by ROADMAP criterion 5, and a test fails if a `<form>` or `<button>`
appears in the cockpit region. So:

| Element | Phase |
|---|---|
| Layout, nav chrome, masthead, KPI cards, stage chips | 45 (read-only) |
| Module rows with status chip + count | 45 (read-only) |
| Side panel shell, tab strip, Overview content | 45 (read-only) |
| Progress ring, visits-completed count | 45 (read-only, derived) |
| Files tab listing existing documents (view only) | 45 read-only listing; upload in 48 |
| Recent activity feed | 45 if an activity source already exists; otherwise 46 |
| **Create visit** | **46** |
| **Add note** | **46** |
| **Upload files** | **48** |
| **Manage engineer links** | **46** |
| Task editing ("updated a task") | 46 |

## What happens to the teal tokens

`resources/css/cav-tokens.css` and `resources/css/cockpit.css` shipped to production in Plan
45-03 and are live but inert (the page they style is being replaced). Options, to decide at
planning time:

1. Retarget `cav-tokens.css` to hold the app-blue cockpit tokens (keeps the scoping mechanism,
   changes the values).
2. Delete both and style the cockpit with the app's existing token layer.

Option 1 keeps the one genuinely valuable thing from 45-03: **tokens scoped to a class, never
added to `layouts/app.blade.php`'s `:root`**, which is what stops the cockpit retoning the whole
app. Whatever is chosen, that constraint stands — `layouts/app.blade.php`, `resources/css/app.css`
and `tailwind.config.js` must stay byte-identical, and there is a test asserting it by sha256.

## Still true from the earlier work — do not re-derive

- `--teal-*` in this app **already means blue** (`layouts/app.blade.php:65-71` aliases it to the
  `#1E5FE0` navy/accent family). Tailwind `brand.teal` is `#2E7BFF`. Any new token needs a fresh
  prefix.
- There is **no token file** in the app; tokens live inline in a 2,154-line layout with `:root`
  at line 33. Use the `@stack('styles')` seam at `:1322`.
- Generic class names collide: `.btn` has 4 rules and `.card` 2 in the layout.
- The cockpit is a **staff-auth** surface and must NOT be added to
  `LabourResourceClientSurfacePrivacyTest` (its docblock at `:55-61` forbids staff-auth surfaces).

## Open questions for planning

1. ~~Does the activity feed have a source today?~~ **ANSWERED 2026-09-20.** `ProjectActivityLog`
   already carries `project_id`, `user_id`, `action`, `description`, `metadata` and `created_at` —
   everything the feed renders. It is buildable read-only in Phase 45.
2. ~~"Documents 1 of 9 complete" — nine of what?~~ **ANSWERED 2026-09-20 by D-16.** Nine module
   rows including Snagging; the denominator is the rendered module count, computed not hardcoded.
3. Does "Open full project" keep the existing eleven-tab page permanently, or is it a transitional
   escape hatch that disappears when the cockpit is complete?
4. Stage chips ("Survey Pending", "Installation phase") — derived from what? No current field
   obviously provides them.
