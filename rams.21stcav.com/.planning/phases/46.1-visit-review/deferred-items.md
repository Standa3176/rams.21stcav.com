## CockpitPageTest::test_the_cockpit_read_route_is_still_get_only_and_every_write_is_a_post

**Found:** 2026-09-21, during Plan 46.1-03 Task 1's gate run.
**Owner:** Plan 46.1-02 — NOT 46.1-03.

That test asserts every cockpit GET route is served by `ProjectCockpitController`.
Commit `2dfd2f35` (46.1-02) registered two new cockpit GETs on
`ProjectCockpitEvidenceController` — deliberately, and its own route comment says
so ("A THIRD controller on purpose"). The assertion needs widening to name the
read controller AND the evidence controller.

Out of scope for 46.1-03 under the executor's scope boundary: the failure is not
caused by this plan's changes (it is about route action names, and this plan adds
no route). Not fixed here.

---

## CLOSED BY MEASUREMENT — the CockpitPageTest GET-controller assertion

**Closed:** 2026-09-22, during Plan 46.1-06's Task 2 gate run.

`gate-46.ps1 -Path tests/Feature/Cockpit` reports `Tests: 260 passed (8033 assertions)`,
`CockpitPageTest` included and green. The assertion was widened by its owner
(Plan 46.1-02) after this entry was written. Recorded here rather than deleted:
a deferred item that was fixed elsewhere is worth saying so about.

---

## CARRIED FORWARD FROM PHASE 46 — still open, still NOT fixed

Both live in `.planning/phases/46-visit-lifecycle/deferred-items.md` and are
restated here so a reader of THIS phase does not have to know to look there.

- **`D-46-05-01` — live on the server right now.** `GET /worksheet/{token}`
  returns **500** for a worksheet whose `generated_data` has no `rooms`
  (`Undefined variable $signOffBlocked`). Plan 46.1-06's walk sets
  `generated_data.rooms` on its fixture for exactly this reason and says so at
  the helper. **Worth a quick task.**
- **`D-46-06-01`.** A send-back issued in the SAME CLOCK SECOND as a
  resubmission reads as "not sent back", so the engineer never sees the ask.
  One second wide; the fix is a Plan 46-01 decision to revisit deliberately.
