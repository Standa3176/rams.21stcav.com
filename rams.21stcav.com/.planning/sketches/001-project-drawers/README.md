# Sketch 001 — Project drawers

**Date:** 2026-09-19
**Status:** awaiting review — no winner picked yet

## Design question

Can the project page become a single page of **drawers** — one per required deliverable, each
holding its own visits, photos and data — with traffic lights showing status, the way SCC files
visits under a PMV or callout?

## Decisions already locked before sketching

| Decision | Choice |
|---|---|
| Drawer interaction | **Expands in place** (accordion), not a side panel or sub-page |
| What leads the page | **What's blocking / needs me**, not overall progress |
| Worksheet green | Requires an **office accept step** — which does not exist yet |
| Programming | A **tick, not a light** — nothing in the system can evidence it |
| Not-required deliverables | Muted, still visible |

## Variants

| File | Approach |
|---|---|
| `variant-a-attention-band.html` | A **"Needs you" band** at the top repeating the blocked/waiting items with direct actions, then all drawers below in lifecycle order |
| `variant-b-urgency-sorted.html` | **No band.** One list, grouped Blocked → Waiting on someone → In progress → Done → Not required, with a coloured left rail and a one-line roll-up |

### The real tension

Whether blockers get **stated twice** (A) or the single list simply **reorders** (B).

- **A** is louder and gives one obvious place to look; costs duplication, and the band grows
  unbounded on a messy project.
- **B** never repeats itself and stays calm; relies on the reader trusting the ordering, and
  "what needs me" is less immediately shouted.

A third option not sketched: band that appears **only** above a threshold (say 2+ blockers),
collapsing to B's behaviour on a healthy project. Worth considering if A wins but feels noisy.

## Grounding

Both use the live app tokens so they read as this app, not a generic mockup —
`--paper #F7F9FC`, `--surface #FFFFFF`, `--border #E2E8F0`, `--accent-700 #1E5FE0`, Inter,
28/15/13px type, and the shipped `.alert-warning` / `.alert-error` colour pairs from
`layouts/app.blade.php`.

Data is modelled on a real install shape: 2 engineer visits, 41 photos, 18 serials, 2 outstanding
items flowing into snagging, 18 devices feeding the O&M.

## What the sketches deliberately show

- **Worksheet drawer contains both install visits** — this is the "drawer files several visits"
  idea made concrete. Today each worksheet is a flat record with no parent.
- **The Accept button** — the step that doesn't exist yet, and without which the worksheet can
  never go green.
- **Outstanding items appearing in two drawers** — raised on the worksheet sign-off, surfacing in
  snagging. Today that link does not exist; the engineer's text is inert.
- **"18 serials from site"** on the O&M drawer — this one is real today, via
  `Device` rows → `$project->devices()`.

## Open questions for review

1. Band or no band (A vs B)?
2. Does grouping by *urgency* (B) beat grouping by *lifecycle stage*? B currently mixes both.
3. On a phone, is an accordion of 9 drawers too much scrolling? Neither variant has been checked
   at 400px width.
4. Should a drawer with nothing in it yet be collapsible at all, or shown as a flat row?

## Not addressed

Client output. Neither variant shows how a handover pack gets assembled or sent — that is the
other half of the original question and needs its own sketch.
