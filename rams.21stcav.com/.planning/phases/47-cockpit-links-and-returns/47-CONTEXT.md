# Phase 47: Cockpit — Links, Visits and Returns - Context

**Gathered:** 2026-09-30
**Status:** Ready for planning

⚠️ **This REPLACES the old Phase 47 (Snagging) as the next phase.** Snagging was blocked because
46.2 unsurfaced the visit workflow and its premise — "snags are resolved through visits" — assumed
visits had a screen. **This phase gives them one.** Snagging moves after it.

<domain>
## Phase Boundary

The cockpit stops hiding what it already does. It becomes the place an engineer link is **created,
seen, copied and revoked**; where a visit is **managed** (accept · send back · office note · raise
snag); and where **what came back** is reviewed — photos, room answers, captured serials, client
sign-off, and the per-room photo ZIP.

**In scope:** surfacing the engineer link and its state; link copy and revoke; re-surfacing visit
management; re-surfacing the returned-evidence review and the photo ZIP; all in the cockpit's drawers.

**Out of scope:** the snag lifecycle (the phase after this); absorbing room detail or the 13 RAMS
field families (SCOPE.md D-01 — those screens keep their data permanently); PDF branding (separate);
deleting any old screen.

</domain>

<decisions>
## Implementation Decisions

From `.planning/consolidation/SCOPE.md` D-01b and D-02. The user, verbatim:

> "Engineer link creation/mgt will be done fron the cockpit with dropdown drawers incl review of
> returned/completed links , creation of internal and external docs etc"

- **D-01:** **The engineer link must be VISIBLE and COPYABLE.** ⚠️ Measured 2026-09-30: the cockpit
  **already issues links** — `CockpitCombinedCreator` calls `VisitLinkIssuer` inside one transaction —
  and then **never displays them**. A grep over `resources/views/components/cockpit/` finds no
  `access_token`, no `survey.show`, no `public-worksheet.show`. **It does the hard part and hides the
  result.** Today a link can only be copied from `site-survey/show.blade.php:343`,
  `worksheets/show.blade.php:220` or `projects/show.blade.php:1159,1488`.

- **D-02:** **Link management: copy, revoke, and state at a glance.** Revoke exists for worksheets
  (`worksheets.revoke-token`) and is the only way to kill a client sign-off link. ⚠️ **Check whether
  surveys have an equivalent; if not, say so rather than inventing one.**

- **D-03:** **Re-surface visit management** — accept, send back, office note, raise a snag.
  **Seven routes are still registered** and `panel.blade.php` / `visit-row.blade.php` still reference
  them. Phase 46.2 D-02 removed the controls, not the code. ⚠️ **Phase 46's VL-11 cap still binds: a
  visit row renders AT MOST FOUR controls, never disabled, and a reconstructed visit offers ZERO.**

- **D-04:** **Re-surface the returned-evidence review**, built by Phase 46.1: `VisitEvidence`,
  `CockpitEvidencePresenter`, `VisitPhotoZipBuilder` — returned photos, per-room answers, captured
  serials, client sign-off, and a **per-room photo ZIP** for the Bitrix hand-off. Routes still live.
  ⚠️ **RV-08 was never answered and can be answered now** — "is the tab calm?" became unanswerable
  the moment 46.2 unsurfaced the tab it referred to.

- **D-05:** **Nothing is deleted.** The old screens keep working; this phase only stops the cockpit
  being the lesser place to do the same job.

### Claude's Discretion

- Drawer shape: one "Engineer link" drawer per module, or link state on the module row with a drawer
  for the detail.
- Whether returned evidence is its own drawer or a section of the visit's.
- What "state at a glance" shows — issued / opened / returned / signed / revoked.

</decisions>

<canonical_refs>
## Canonical References

- `.planning/consolidation/SCOPE.md` — D-01, D-01b, D-02, D-03.
- `.planning/phases/46.1-visit-review/46.1-CONTEXT.md` — what the review was, and **RV-08**.
- `.planning/phases/46.2-doc-creation-cockpit/46.2-CONTEXT.md` — **D-02, which unsurfaced it**, and
  the retirement ledger beside it.
- `app/Support/Visits/VisitLinkIssuer.php` — ⚠️ `surveyFor()` **adopts**; `worksheetFor()` **always
  creates and dispatches a build**. That asymmetry already caused a duplicate-worksheet trap in 46.5.
- `app/Support/Cockpit/VisitEvidence.php`, `CockpitEvidencePresenter.php`, `VisitPhotoZipBuilder.php`.
- `.planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md` — the D-06 gate,
  `>= 159 passed AND 0 failed`. **Never equality against 161.**

</canonical_refs>

<code_context>
## Existing Code Insights

- ⚠️ **The read-only fence still stands at 2 / 21 / 9 / 13.** `DEFERRED_AFFORDANCES` holds 21 entries
  **including `Download`** — lifted once for 46.1's ZIP and **RETURNED by 46.2 D-02** — plus
  `Create visit` and `Add note`, also returned. **This phase needs several of them back: lift each BY
  NAME in the commit that ships its control, never by deletion**, exactly as 46-04 and 46.1-04 did.
- ⚠️ **Guard tests match COMMENTS.** Ten near-misses so far, and the `@php`-inside-a-Blade-comment
  trap **fired for real on 2026-09-30**, compiling and swallowing the entire nav below Projects.
- **No JavaScript, no Alpine** in the cockpit region; state is query-string.
- ⚠️ **The stretched-link trap**: `.cav-module` has `position: relative`, `.cav-module__open::after`
  has `inset: 0`. A `position` **or a `transform`** on that anchor collapses the whole-row click
  target onto the 28px glyph **with nothing else failing**.
- **`device_label_photos.captured_by`, `worksheet_signoffs.ip_address` and `user_agent` must NEVER be
  rendered.** ⚠️ **NEW AND UNFIXED — `F-46.7-04-01`: `pre_install_confirmations.room_complete.{room}.completed_by`
  holds `ip:{addr}|actor:{hash}` and the ENGINEER LINK renders it twice** (`public-show.blade.php`
  ~`:1328` title attribute and ~`:1655` visible text, via `Worksheet::roomCompletedBy()` which returns
  it raw). A fourth column of the same shape, not on the banned list — which is why every sweep missed
  it. **This phase renders returned evidence. Do not repeat it, and consider fixing it here.**
- The three sha256-pinned files stay **BYTE-IDENTICAL to `4abd2b24`**; a mismatch is a STOP.
- `resources/css/cockpit.css` is a Vite entry — touching it means `npm run build` on deploy.

</code_context>

<specifics>
## Specific Ideas

- **The acceptance test is a link reaching an engineer.** If a PM cannot copy a link from the cockpit
  and send it, the cockpit is not yet the place work happens — it is a nicer-looking detour.
- The user's standing criterion, every time: **"simple to use"**, and an earlier design of this page
  was rejected as **"scary"**. VL-11's four-control cap exists for exactly that reason.
- The cockpit became the project landing page on 2026-09-30, **knowingly incomplete** — the user said
  "switch regardless". This phase is what makes that switch pay off.

</specifics>

<deferred>
## Deferred Ideas

- The snag lifecycle (next phase).
- `site-surveys.client-report` — the only client-facing survey PDF, currently reachable from nothing.
  **Surface it; never delete it.**
- `F-46.7-04-01` if not taken here.
- `uploadPhoto` has no room-name inclusion guard, unlike its two siblings.

</deferred>

---

*Phase: 47-cockpit-links-and-returns*
*Context gathered: 2026-09-30*
