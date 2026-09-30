# Consolidation scope — decided 2026-09-30

Backup/recall point: tag `pre-consolidation-260930` + `~/stcav_rams-pre-consolidation-260930.sql.gz` (2.7 MB, verified).

## The decision, in the user's words

> "1. Only use cockpit for project docs , visit mgt and engineer links./mgt. 2 . delete. 3. unify"

## D-01 — THE COCKPIT OWNS THREE THINGS, AND ONLY THREE

**Project documents · visit management · engineer links and their management.**

⚠️ **This CANCELS the expensive half of the inventory's recommendation.** Steps 7, 8, 9 and 11 of
`H` — the cockpit absorbing room detail, the 13 RAMS field families, the O&M asset register, and
retiring the 2,293-line project page — are **NOT WANTED**. They were premised on the cockpit
replacing everything. It does not.

**Therefore these stay exactly where they are, permanently, not "for now":**
- `rams/{rams}/review` — sole capture point for 13 field families (permits, material handling, CDM,
  scope traceability, decommissioning, commissioning criteria, site emergency, waste, welfare,
  exclusions, client responsibilities, document_status, subtitle), all of which render in the
  delivered document.
- `site-surveys.edit` / `.update` — sole capture point for room detail and the ONLY home for
  `office_notes`.
- `om-manuals.edit-devices` — the asset register.
- `projects/show.blade.php` — the 9-tab project page. **It is the real front door and keeps that job.**

**The cockpit's Edit anchors pointing OUT to those screens (`CockpitPanelPresenter.php:99-140`) are
therefore CORRECT BY DESIGN, not a shortfall.** Earlier framing called the cockpit "a thin shell over
the old screens" as a criticism; under D-01 that is the intended shape.

## D-02 — THE GAP THIS OPENS

**The cockpit issues no engineer links today.** Links are minted when a survey or worksheet is
created and copied from `site-survey/show.blade.php:343` / `worksheets/show.blade.php:220` /
`projects/show.blade.php:1159,1488`; revoke lives at `worksheets.revoke-token`.

If link management is one of the cockpit's three jobs, the cockpit needs: **issue, copy, revoke, and
show link state** — for both token kinds. This is the one piece of NEW cockpit work D-01 implies.

## D-03 — NOBODY CAN REACH THE COCKPIT

Measured 2026-09-30: **no Blade outside `components/cockpit/*` and `projects/cockpit.blade.php`
links to `projects.cockpit`.** Not the nav, not the dashboard, not the project page. It is reachable
only by typing the URL. **Until that is fixed, no feedback about the cockpit is real feedback.**
⚠️ Confirm `COCKPIT_ENABLED` on live and whether `visits:backfill --apply` has run before relying on it.

## D-04 — DELETE (approved)

The confirmed-dead set from the inventory's `E`. Largest item: `resources/views/public-survey/show.blade.php`
(2,214 lines) — the OLD survey engineer link, unreachable because `survey/{token}` routes to
`SurveyController@show` → `surveys/show.blade.php`. **This is the "old SSV vs new" the user named.**
⚠️ Keep `PublicSurveyController` itself — `submit`, `confirmation`, photos, `answerQuestion`,
`completeRoom` and reference files are all still routed.

## D-05 — UNIFY PDF BRANDING (approved)

Three teals today: RAMS `#1B7A7A`, O&M `#01889F`, survey + snagging `#007B8A`, plus three status
palettes. ⚠️ The LIVE RAMS blade (`pdf/rams.blade.php`) does **not** read `RamsTheme` — only the
dormant `rams-v2` does. No data path; regenerate snapshot baselines.

## Order

1. **Delete** (D-04) — cheapest, zero data risk.
2. **Reach + links** (D-03, D-02) — a link to the cockpit, then link management in it.
3. **Unify branding** (D-05).
