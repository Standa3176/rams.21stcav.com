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
