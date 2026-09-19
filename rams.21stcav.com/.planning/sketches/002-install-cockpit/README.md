# Sketch 002 — Install cockpit

**Date:** 2026-09-19 · **Status:** awaiting review · Supersedes much of sketch 001

## The change from 001

001 showed nine **peer** drawers. This shows a **hierarchy**: the install programme is the master,
and every trip to site is a *visit* nested under it — the SCC callout-drawer idea applied to a
project.

The unifying move: **one visit concept with a type** (site survey · first fix · install ·
programming · snag), and the type drives what the engineer link contains. Today RAMS has two
unrelated link systems (`/survey/{token}` and `/worksheet/{token}`); this collapses them into one
with type-driven content.

## Design decisions

**The spine is the hero.** An AV install *is* a programme of visits down a job, so the page is
built as a programme spine, not a card grid or a stat row. Teal spine, gold only where attention
is genuinely required. Spending the boldness in one place.

**Attention is folded in, not bolted on.** No separate alert panel duplicating the list below —
a single gold-ruled paragraph naming what is waiting, then the spine tells the rest.

**Returns become actions, visibly.** The survey visit shows three derived actions (part change,
client to-do, engineer-urgent). The install visit shows two items that feed the snag visit. This
is the thing the app does not do today — engineer input is currently inert text.

**Cost sits on the visit**, because that is where it is incurred, and it rolls up to the masthead.

**Programming carries a box, not a light** — nothing can evidence it, so a light would imply a
certainty we do not have.

## Brand

Brand palette, not the app's current blue: teal `#01889F` structure, gold `#D4AF37` accents,
Verdana headings, Poppins body, angled masthead via `clip-path` (the brand's own graphic device).

**Open question:** the live app currently uses `#1E5FE0` / Inter. Either the cockpit becomes the
start of a brand-aligned refresh, or it should be retokened to match the existing app. Worth
deciding before build — a half-branded app is worse than either.

## What this implies structurally

1. **`InstallProgramme` cannot be the master as it stands.** `archiveExisting()` archives the whole
   record and `createForProject()` makes a new one on every regenerate — visits, costs and history
   filed under it would be orphaned. Needs splitting: a durable install record (the drawer) versus
   a regenerable task list (inside it).
2. **A `Visit` entity does not exist.** Today there are `SiteSurvey` and `Worksheet` as separate
   things with separate links. This proposes one typed visit owning its own link.
3. **Visit costs do not exist** — no cost/rate field anywhere in `TimeEntry`. Net-new.
4. **Documents mostly exist** — `ProjectReferenceFile` already carries label/path/uploader, and
   engineers already receive them on the worksheet link. Needs surfacing, not building.
5. **Derived actions do not exist** in any form. Biggest new build, biggest payoff.

## Open questions

1. Does the typed-visit model hold for every type, or do survey and install diverge too much to
   share one record?
2. When the task list is rebuilt mid-job, do past visits stay on the spine? (Sketch assumes yes.)
3. Phone width — the spine is checked at 560px but not tested on a real handset.
4. Who accepts a visit, and does accepting all visits auto-close the deliverable?

## Not addressed

Client output — how the handover pack is assembled and sent. Still unsketched, still the other
half of the original question.
