# Phase 46: Visit Lifecycle - Context

**Gathered:** 2026-09-20
**Status:** Ready for planning

<domain>
## Phase Boundary

Turn the read-only cockpit into the page a project is delivered from. Each module drawer gains
the ability to create its document, set up engineer visits, and review and action what comes
back — and a survey's findings carry forward to the install so nothing an engineer learned on
site is lost.

**In scope:** creating a visit; generating the engineer link for it; the four PM actions on a
returned visit (accept, send back, add an office note, raise a snag); generating a module's
document from inside its drawer; and the survey→install carry-forward.

**Out of scope:** sending documents to the client and confirming sent (Phase 48); uploading
files (Phase 48); the full snag model with parts, outcomes and linked follow-ups (Phase 47 — this
phase raises a snag, it does not manage one); re-import and versioning (Phase 50); visit costs
(deferred for the whole milestone).

</domain>

<decisions>
## Implementation Decisions

Taken interactively with the user on 2026-09-20. The user's brief, verbatim:

> "ADD THE FUNCTIONALITY INTO EACH SIDE PANEL IE SITE SURVEY > CREATE/MANAGE SITE SURVEY
> DOC/LINKS, SETUP ENGINEER VISITS, REVIEW AND ACTION ENGINEER VISIT DATA AND NOTES, MOVE
> RELEVANT INFO TO 1ST FIX/INSTALL TAB SO IT IS NOT LOST ETC. WE ARE CREATING A CLEAN WORKFLOW
> THAT IS SIMPLE TO USE BUT HELPS KEEP PROJECT ON PLAN"

### The survey → install carry-forward

- **D-01:** A survey's site findings are **carried forward to the install visit automatically and
  shown read-only**. The install engineer's link shows the survey's access constraints, parking,
  comms-room access and notes, delivery routes, site access notes, site risks and H&S notes.

  **It reads from the survey record; it is never copied.** A copy can go stale and then contradict
  the survey it came from, and the PM would have no way to tell which is true. Reading through
  means the install link always shows what the survey actually says.

  Source fields verified present on `SiteSurvey`: `access_constraints`, `parking_restraints`,
  `comms_room_access_status`, `comms_room_access_notes`, `delivery_routes`, `site_access_notes`,
  `site_risks`, `h_and_s_notes`, `general_notes`, `distance_from_base_miles`.

  This is the milestone's core thesis in one feature: the app is good at collecting information
  from engineers and bad at turning it into work. Today this data sits in a survey record and the
  installing engineer never sees it.

### What the PM can do with a returned visit

- **D-02:** All four, and they are the phase's workflow spine:
  - **Accept** — marks the visit reviewed and complete; records who and when; locks scope.
  - **Send back** — rejects the return and reopens the engineer link for more information, rather
    than accepting something incomplete.
  - **Add an office note** — the PM annotates the return **without changing what the engineer
    said**. The engineer's record stays intact; the office view sits alongside it. Do NOT let an
    office note overwrite or edit engineer-captured data.
  - **Raise a snag** — turns something reported into a snag item.

- **D-03 (scope fence on snags):** raising a snag here creates a **minimal snag record linked to
  the visit it came from**. Parts, the three outcomes, and linked follow-up snags are **Phase 47**.
  Phase 46 must not build the snag lifecycle — it provides the entry point Phase 47 builds out.
  Design the record so Phase 47 extends it rather than replacing it.

### Documents

- **D-04:** A module's document is **generated from inside its drawer**, using the generators that
  already exist — do not write new document generators. **Sending to the client and confirming
  sent stays Phase 48**, so this phase does not grow a second half.

### Two decisions taken 2026-09-20, after planning surfaced them

- **D-05:** **Per-visit document scoping is captured but NOT generated in this phase.** ROADMAP
  criterion 2 asks that a visit's RAMS and worksheet cover only that visit's rooms and type. Both
  generators (`RamsController::generateFromProject()`, `WorksheetController::generateFromProject()`)
  take a `Project` and nothing else, and the RAMS is authored by an AI pipeline driven by the whole
  quote — nobody has decided how a room-scoped RAMS should be written.

  So this phase captures `rooms_in_scope` on the visit and renders it on the engineer's link, so an
  engineer knows their scope. **The generators stay project-wide.** This is recorded as requirement
  **VL-12, NOT DELIVERED** — an honest half, flagged in REQUIREMENTS.md and the ROADMAP rather than
  quietly skipped. Re-scoping the generators wants its own phase and its own thinking.

- **D-06:** **No "Edit visit" control. The visit row stays capped at four** — Accept, Send back, Add
  note, Raise a snag. ROADMAP criterion 3 says a visit "stays editable after sending"; delivering
  that literally would put a fifth control on every row and break the simplicity cap that the user's
  own brief demands ("simple to use", and earlier: "I want to make it look simple and less scary").

  A visit locks on return and says so visibly. A PM who needs a change **sends it back**, which
  reopens the engineer link — one workflow rather than two overlapping ones with different
  consequences for the engineer. Revisit if the lack actually bites in use.

### Claude's Discretion

- **Who may accept a visit.** The app is a shared workspace: controllers carry
  `abort_unless(auth()->check(), 403)` with no role model beyond `EnsureUserIsAdmin`. Any
  authenticated staff user is the default unless research finds a reason otherwise. Record
  whichever is chosen.
- Whether accepting every visit in a module auto-closes that module's deliverable, or whether
  closing stays a separate deliberate act. Not discussed; the conservative reading is that they
  stay separate.
- Whether an engineer link can be reissued, and whether reissuing invalidates the first.
- The shape of the visit status model beyond what `Visit` already carries
  (`STATUS_PLANNED` / `STATUS_COMPLETED` exist today; accept and send-back imply more).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/sketches/004-delivery-cockpit/README.md` and `delivery-cockpit.png` — the ACCEPTED
  design. The write affordances this phase builds go in the panel's **Quick actions** area, which
  the design already reserves: Create visit · Add note · Upload files (the last is Phase 48).
- `.planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md` — D-01..D-16 from Phase 45,
  still binding. Especially D-02 (a backfilled visit is an inference) and D-04 (a visit survives
  its source being superseded).
- `.planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md` — the D-06 behaviour gate,
  `>= 159 passed AND 0 failed`. **Never equality against 161.** Still applies to every plan.
- `app/Models/Visit.php` — six types, `is_backfilled`, the `(source_type, source_id)` pair.
- `app/Support/Cockpit/CockpitPanelPresenter.php` — where the panel's content comes from.
- `app/Models/SiteSurvey.php` and `app/Models/Worksheet.php` — **deliberate `$fillable` omissions
  from a security re-audit** (`access_token`, `access_token_expires_at`,
  `submitted_notification_sent_at`). Preserve them. Phase 46 creates engineer links, so this is
  live: token generation must follow the existing pattern (direct property assignment in
  `boot::creating`, bypassing `$fillable`), never mass assignment.

</canonical_refs>

<code_context>
## Existing Code Insights

- **THE READ-ONLY FENCE MUST BE RETIRED DELIBERATELY AND PARTIALLY, NEVER DELETED.**
  `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` currently bans `<form>`, `<input>`,
  `<button>`, `<script>`, 18 affordance strings and 9 handler attributes inside the cockpit region.
  Phase 46 legitimately needs some of those. The fence's constants are enumerated data precisely
  so a phase can remove an entry **deliberately, in a commit that says why**.

  Keep banning what is still out of scope — "Upload files" and "Download" are Phase 48. Keep the
  row-count invariance tests for any GET. Keep both `cockpitRegion()` brackets, which stop a
  truncated extraction passing vacuously. **Do not delete the file and start again.**

- **Alpine is available and was banned by ruling, not by absence.** `layouts/app.blade.php` loads
  it globally. Phase 45 banned it so a read-only page could not acquire behaviour by accident.
  Phase 46 may lift that ban deliberately by editing `BANNED_HANDLER_ATTRIBUTES` — but consider
  whether the query-string pattern (which gives bookmarkable state and a working back button)
  still serves for anything that is not a genuine form submission.

- **The three protected files stay byte-identical to `4abd2b24`**:
  `resources/views/layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`.
  `FlagOffBehaviourUnchangedTest` asserts by sha256. A mismatch is a STOP, never a hash to refresh.

- **Tokens live on `.cav-brand` in `resources/css/cav-tokens.css`** (76 of them), never in the
  layout's `:root`. No hex literal may reach a Blade file — there are tests.

- `ProjectActivityLog` exists and the panel already renders a feed from it. Phase 46's actions are
  the first things that should genuinely write to it.

- **The stretched-link trap**: `.cav-module` has `position: relative` and `.cav-module__open::after`
  has `inset: 0`. Giving the anchor its own `position`, or removing the row's, silently collapses
  the click target onto the chevron with nothing else failing.

</code_context>

<specifics>
## Specific Ideas

- The user's framing is the acceptance test: **"a clean workflow that is simple to use but helps
  keep project on plan."** Simple to use is not a nice-to-have here — the user rejected an earlier
  design for being busy, with the words "I want to make it look simple and less scary". Every
  affordance added in this phase costs against that.
- The carry-forward is the feature that makes the cockpit worth having. Judge it by whether an
  install engineer opening their link learns something the survey already knew.

</specifics>

<deferred>
## Deferred Ideas

- Sending documents and confirming sent — Phase 48.
- The snag lifecycle: parts, the three outcomes, linked follow-up snags — Phase 47.
- Uploading drawings from StarDrawer — Phase 48.
- Re-import and data versioning — Phase 50.
- Visit costs — deferred for the whole milestone; belongs in the admin Hidden Functions register.
- **Backfilled visits on soft-deleted projects** — 13 of the 24 created on live sit on deleted
  projects, because `BackfillVisitsCommand` does not check whether the parent project is trashed.
  Harmless (the cockpit 404s exactly as the project page does) but it is dead data. Fix wants two
  parts: skip trashed projects on future runs, and a considered cleanup command for the existing
  rows. Not this phase unless it becomes convenient.

</deferred>

---

*Phase: 46-visit-lifecycle*
*Context gathered: 2026-09-20*
