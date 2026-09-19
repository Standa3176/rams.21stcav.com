# Phase 44: Labour Resources - Context

**Gathered:** 2026-09-19
**Status:** Ready for planning

<domain>
## Phase Boundary

One labour-resource record per person, with a role, maintained in admin, selectable by the PM
wherever work is assigned — and structurally incapable of leaking an engineer's contact details
into anything a client sees.

**In scope:** the table, admin CRUD, the PM-facing selector, and the privacy boundary.

**Out of scope:** costs and day rates (deferred by decision — see Deferred Ideas); engineer
logins; anything that changes how visits work (Phase 45+ owns the `visits` table). This phase adds
a resource anyone can select; it does not change what they are selected *for*.

</domain>

<decisions>
## Implementation Decisions

All taken interactively with the user on 2026-09-19.

### The record

- **D-01:** **One table with a role field**, not separate engineer and programmer tables. A person
  may hold more than one role rather than appearing as two rows. Roles at least: engineer,
  programmer, other.

- **D-02:** Admin can **add, edit and deactivate** — never hard-delete. A resource that has
  attended visits must keep displaying correctly on that history, so removal is deactivation.
  Deactivated resources disappear from selectors but remain on past records.

- **D-03:** The selector is **multi-select by name** — more than one person attends a visit
  (the sketch shows "Marcus Okafor, Priya Shah" on the 15 Sep install).

### The privacy boundary — the load-bearing decision

- **D-04:** **A client must never be given an engineer's phone or email. Name only.** Contact
  details exist on the record for the PM's use and are visible to PM and admin only.

  This is a hard rule, not a convention. The record will hold email and phone precisely so the PM
  can use them, which makes accidental exposure the obvious failure mode. It must be proven by a
  test asserting that no client-facing document or page renders a resource's email or phone.

  Same rule for programmers as for engineers.

- **D-05:** The selector is **for the PM only** — explicitly NOT on engineer-facing forms. The
  user corrected an earlier reading of this: engineers do not pick from the resource list on their
  visit link.

### Compatibility

- **D-06:** Engineer names recorded today as **free text must keep working**. `captured_by` on
  device label photos, worksheet sign-offs and survey returns are strings. This phase must not
  break or rewrite them. Linking historical free-text names to resource rows is **not** in scope —
  a later phase may, or may not.

### Claude's Discretion

- Whether a labour resource links to a `User` row. The app has users for staff; engineers are not
  users today. The planner should decide based on what admin already does, and say why. A nullable
  link is probably right, but it is not a decision the user expressed a view on.
- Table and column naming, admin route placement, and whether roles are an enum column or a
  pivot. D-01 requires only that one person is one row.

### Open questions for the planner (NOT decided)

1. **Where does admin live today, and what guards it?** The app is documented as "shared
   workspace: any authenticated user has full access" on several controllers. If admin is not
   actually restricted, D-04's privacy boundary is about *client-facing* surfaces (public tokenised
   links, generated documents), not about staff roles. Establish which, and scope the test to the
   real boundary.
2. **Which surfaces are genuinely client-facing?** At minimum: generated client documents, and
   anything rendered on a public tokenised route. The test in success criterion 4 is only as good
   as this list, so enumerate it from the routes rather than assuming.
3. Whether the selector needs to be reusable as a component now, or can be local to admin until
   Phase 46 gives it a real home.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Design source of truth
- `.planning/sketches/002-install-cockpit/README.md` — the cockpit design and the decisions behind it
- `.planning/sketches/002-install-cockpit/cockpit-sections.html` — shows where resources appear: assigned to visits ("Marcus Okafor, Priya Shah"), and in the prepare panel's "who and when" step
- `.planning/ROADMAP.md` § "v4.0 Project Cockpit" — the milestone this phase opens, and what is deliberately out of scope

### Existing code this phase must not break
- `app/Http/Controllers/PublicWorksheetController.php` — `captured_by` free-text engineer names
- `app/Models/DeviceLabelPhoto.php` — `captured_by` column
- Worksheet sign-off records — client name and signature, distinct from engineer identity

### Precedent for the privacy test
- Phase 30's `MissingRiskRefGateSourceGuardTest` — a static source-guard test asserting a thing is
  NOT referenced. The same shape suits "no client-facing surface renders a resource's email".

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable assets
- The app has an admin area already (device stencils admin at `/admin/device-stencils` with list,
  edit and audit rows) — established CRUD precedent worth following rather than inventing.
- `DeviceStencilAudit` is the codebase's precedent for audit-on-write inside a transaction, if
  resource changes need an audit trail.

### Established patterns
- Controllers in this app carry `abort_unless(auth()->check(), 403)` with a comment reading
  "Shared workspace: any authenticated user has full access." Do not assume role-based auth exists.
- Soft-delete versus a status flag: check what the codebase already does before choosing.

### Integration points
- Nothing consumes this yet. Phase 45's `visits` table is the first real consumer; Phase 46's
  prepare panel is the first UI. Building the selector as something Phase 46 can reuse is the
  point, but it must be useful standalone in admin now.

</code_context>

<specifics>
## Specific Ideas

- The sketch shows two engineers on one visit, so multi-select is real rather than theoretical.
- The user's phrase was "Client should only be given engineer name never phone or email — this is
  for PM only." Worth quoting in the test's docblock so the intent survives.

</specifics>

<deferred>
## Deferred Ideas

- **Costs and day rates** — the user said "do not build yet" on 2026-09-19. The roadmap records it
  in the v4.0 out-of-scope list and it belongs in the admin Hidden Functions register.
- **Linking historical free-text engineer names to resource rows** — possible later, explicitly not
  now (D-06).
- **Engineer logins** — resources are records, not accounts.

</deferred>

---

*Phase: 44-labour-resources*
*Context gathered: 2026-09-19*
