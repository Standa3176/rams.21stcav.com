# Phase 28 — Deferred Items

Out-of-scope discoveries logged per executor scope-boundary rule (not fixed, not silently ignored).

## From Plan 28-02 (D-05 hazard title rename)

**`tests/Unit/Services/RamsBuilderServiceTest.php::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`**
fails independently of this plan's changes. The test mocks `HazardLibraryService::resolveFromSeeds()` to
return a "Working at height" template with `controls => ['Library control — should not be used']`, submits
a reviewed row with `control_measures => ['Engineer-entered control — must survive']`, and asserts the
engineer-entered control survives on a case-only/no-op rename. It currently fails with the library's mock
control overwriting the engineer's control instead. Confirmed pre-existing by temporarily reverting
`HazardTemplateSeeder.php` to its pre-28-02 content and re-running the same test method in isolation —
identical failure both before and after this plan's rename. Unrelated to the restricted-access hazard or
D-05; not touched. Needs its own investigation/fix in a future plan or quick task.
