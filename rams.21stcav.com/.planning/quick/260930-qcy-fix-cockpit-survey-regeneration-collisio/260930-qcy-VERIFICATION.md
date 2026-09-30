---
quick_id: 260930-qcy
verified: 2026-09-30T00:00:00Z
status: human_needed
score: 7/7 must-have truths verified in code; all 11 specifically-requested claims confirmed against the diffs
commits_checked:
  - 1129ab91
  - 3a906f48
  - d90508cf
  - 4cd2645c
human_verification:
  - test: "Click through the cockpit UI live: trigger the collision refusal on a project with an existing survey+visit, confirm the flashed link text is genuinely visible/selectable in the rendered browser DOM, then click 'Start a fresh survey' and 'Update document only' and confirm both behave as described."
    expected: "Refusal message + link render without JS; supersede form archives+redirects to confirm-rooms; regenerate button updates fields and redirects to Files tab with a success flash."
    why_human: "Static analysis confirms the code paths, blade markup and tests exist and are wired correctly, but visual rendering, real browser selectable-text behavior, and actual click-through flow were not exercised — PLAN.md itself states a live click-through is expected to be necessary."
  - test: "Run the three named suites plus the whole tests/Feature/Cockpit directory in the foreground (gate-46.ps1) to confirm the reported pass counts (24/13/38, 412 total) and the D-06 baseline (>=159 passed, 0 failed)."
    expected: "Counts match SUMMARY.md exactly."
    why_human: "PHP is not on this verifier's Bash PATH and a piped/backgrounded run would report a false exit 0 per repo convention — re-running the suites was explicitly out of scope for this verification. Grep-based sanity checks (test-method counts of 24/13/38 in the three files, matching exactly) are consistent with the claim, and a 406-vs-412 gap is explained by two real @dataProvider methods elsewhere in the directory, so the figures are internally plausible but not independently executed."
---

# Quick Task 260930-qcy Verification Report

**Task goal:** Fix the permanent `visits_source_unique` collision in cockpit survey regeneration via (1) pre-transaction refusal + visible existing link, (2) reachable supersede route, (3) document-only regenerate action, plus a non-vacuous regression test and a corrected docblock.

**Verified:** 2026-09-30
**Status:** human_needed (all code-level claims verified; a live click-through and a re-run of the test suites are the only items left for a human, consistent with the task's own framing)

## Verification Method

Read the actual diffs of all four commits (`1129ab91`, `3a906f48`, `d90508cf`, `4cd2645c`) on `feat/worksheet-classifier-universal`, not the SUMMARY's narration of them. Cross-checked `git diff` across the whole four-commit range (and against baseline `4abd2b24`) for files claimed untouched. Did not re-run PHPUnit (per this repo's PHP-not-on-Bash-PATH convention and the verification instructions); instead sanity-checked the reported figures against `grep -c "public function test_"` counts.

## Claims Checked

| # | Claim | Result | Evidence |
|---|-------|--------|----------|
| 1 | Collision refused pre-transaction, no misleading "try again" wording reachable | ✓ VERIFIED | `1129ab91` diff: the `visitAlreadyClaimsSurvey()` check and `return back()...` sit BEFORE the `try { $outcome = $this->creator->create(...) }` block in `ProjectCockpitDocumentController::createCombined()` — it never enters the transaction at all. Message text: "This project already has a survey visit and an engineer link. You can regenerate the document without a new visit, or start a genuinely fresh survey." — no "rolled back", no "try again". Confirmed by test assertions (`assertStringNotContainsString('try again', ...)` and `'rolled back'`) in `CockpitCombinedCreationTest::test_a_retry_when_a_visit_already_claims_the_live_survey_is_refused_not_rolled_back`. |
| 2 | Existing engineer link rendered as plain selectable text, no JS/clipboard, no token minted/rotated/mass-assigned, `$fillable` still omits token fields | ✓ VERIFIED | `doc-form.blade.php` diff (`1129ab91`) adds `<p class="cav-qa__note">Existing engineer link: <a href="{{ session('cockpit_existing_link') }}">{{ session('cockpit_existing_link') }}</a></p>` — escaped via `{{ }}` only, no JS, no clipboard API. `CockpitCombinedCreator::liveSurvey()` is a thin passthrough to `VisitLinkIssuer::liveSurveyFor()` — no write. `SiteSurvey::$fillable` (read directly) does NOT include `access_token`, `access_token_expires_at`, or `submitted_notification_sent_at`. |
| 3 | Supersede reachable, not reimplemented, posts to named route | ✓ VERIFIED | `3a906f48` diff adds a `<form method="POST" action="{{ route('site-surveys.supersede-from-project', $project) }}">` — no new controller/service code touched. Driven test `test_the_supersede_form_reaches_its_route_and_archives_the_old_survey` posts to the real route and asserts `superseded_at` set + redirect to `site-surveys.confirm-rooms`. |
| 4 | `regenerateDocumentOnly()` never calls `CockpitCombinedCreator::create()` or `VisitLinkIssuer::issue()`, never touches Visit; `VisitLinkIssuer.php` unmodified across all four commits | ✓ VERIFIED | `d90508cf` diff: `regenerateDocumentOnly()` calls only `$this->activeSurvey($project)` (read) and `$this->persist($project, $module, $validated)` — no creator, no issuer, no `Visit::` reference at all. `git diff 3580ed14 4cd2645c -- app/Support/Visits/VisitLinkIssuer.php` returned empty — confirmed byte-identical across the whole task. |
| 5 | `$wantsEveryGroup` correctly scoped; `$isCreate` itself untouched | ✓ VERIFIED | `d90508cf` diff: new local `$wantsEveryGroup = $isCreate \|\| $this->intent() === self::INTENT_REGENERATE;` used only at the `groupsToValidate($module, $wantsEveryGroup)` call site. Both `format`-rule uses of `$isCreate` are untouched in the diff (no `-`/`+` lines touching them). |
| 6 | Non-vacuous Task 1 + Task 3 tests | ✓ VERIFIED | Task 1: message assertion at the exact quoted text is present and matches the reported pre-fix failure output. Task 3 Test A (`test_regenerate_document_only_persists_an_earlier_step_field_and_creates_no_visit`) asserts `general_notes` (step 2, an EARLIER step than the submitting step 3) changed on the SAME row after refresh — genuinely tests the `groupsToValidate()` widening, not merely a submitting-step field. |
| 7 | Fence counts: 2/21/9/13 unchanged, multiline `assertCount(21, DEFERRED_AFFORDANCES)` intact, form count 4→5 and INTENTS 5→6 moved by name alongside their controls, form-count move earned by a real `SiteSurvey` row | ✓ VERIFIED | `git diff 3580ed14 4cd2645c -- tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` shows zero lines touching `FORBIDDEN_MARKUP`, `DEFERRED_AFFORDANCES`, `BANNED_HANDLER_ATTRIBUTES`, or any `assertCount` call — confirmed by explicit grep, the `assertCount(21, self::DEFERRED_AFFORDANCES, ...)` block at line 982 is untouched. The form-count `assertSame(4→5, $checked, ...)` is renamed by name with an inline history comment in `3a906f48`, and `populatedProject()` was extended with a real `SiteSurvey::create(...)` row in the same commit — the new supersede form genuinely renders for this fixture, so the count move is earned, not vacuous. `INTENTS` 5→6 moved by name in `4cd2645c` with the renamed test `test_the_intent_set_is_exactly_six_and_rubbish_is_still_refused`. |
| 8 | No migration, `visits_source_unique` untouched | ✓ VERIFIED | `git diff 3580ed14 4cd2645c -- database/migrations` returned empty. |
| 9 | Three protected files byte-identical to `4abd2b24` | ✓ VERIFIED (via git diff, not hash) | `git diff 4abd2b24 4cd2645c -- resources/views/layouts/app.blade.php resources/css/app.css tailwind.config.js` returned empty; also confirmed empty across the task's own four-commit range. Used `git diff` per the allowed fallback (PowerShell `Get-FileHash` not independently re-run). |
| 10 | `cockpit.css` untouched | ✓ VERIFIED | Included in the same empty-diff check above (`resources/css/cockpit.css` was in the diff path list, returned empty). |
| 11 | Docblock correction, not deletion, no new false claim | ✓ VERIFIED | `1129ab91` diff adds a new paragraph immediately after the existing overstatement, narrowing it precisely: "Adoption is safe for the SURVEY, but was NOT safe for the VISIT... refused BEFORE the transaction opens by `visitAlreadyClaimsSurvey()`, never attempted and rolled back." The original sentence is left in place with the correction appended, not rewritten to assert something new. |

## Stated Figures Sanity Check

| Figure | Consistency check | Verdict |
|---|---|---|
| CockpitCombinedCreationTest 24 passed | `grep -c "public function test_"` on the file = 24 | Plausible/consistent |
| CockpitReadOnlyFenceTest 13 passed | grep count = 13 | Plausible/consistent |
| CockpitDocumentFormTest 38 passed | grep count = 38 | Plausible/consistent |
| Whole `tests/Feature/Cockpit` 412 passed | grep count of all `test_` methods in the directory = 406; two files use `@dataProvider` (`CockpitEvidenceDownloadTest`, `FlagOffBehaviourUnchangedTest`), which would inflate PHPUnit's per-dataset count above the raw method count | The 406→412 gap (+6) is explained by data providers and is not an impossible or suspicious figure — but this verifier did NOT execute the suite, so it is sanity-checked, not confirmed |
| D-06 baseline 159 passed, 0 failed, 2 skipped | Stated correctly against the gate rule ("`>= 159 passed AND 0 failed`, never equality against 161") — the SUMMARY does not claim 161, consistent with the plan's own warning | Correctly framed, not independently re-run |

None of the stated figures are internally inconsistent or implausible. None could be independently executed in this verification pass (PHP not on this environment's Bash PATH; re-running was explicitly out of scope per the task instructions).

## Anti-Patterns / Red Flags

None found. No TBD/FIXME/XXX markers introduced. No stub handlers. No unescaped Blade output. The one genuinely risky pattern in the diffs — a blade comment that literally spelled out the banned `{!! !!}` token and tripped `CockpitPanelTest`'s unescaped-output fence — was caught by the executor's own full-suite pass and fixed in `4cd2645c`, which is the correct behavior (Rule 1 in the SUMMARY), not a gap.

## Gaps

None found at the code level. Every must-have truth in PLAN.md's frontmatter and every one of the 11 specifically-requested claims is backed by real diff evidence, not merely SUMMARY narration.

## Why status is `human_needed`, not `passed`

Two items cannot be closed by static code reading alone:

1. **Live click-through.** The PLAN itself and the task framing both anticipate this — rendering behavior (is the link genuinely visible/selectable in a real browser, does the supersede form's confirmation-free destructive action feel right in practice, does the regenerate button really land the PM back on the Files tab with a sane success message) needs a human to load the actual page.
2. **Test suite re-execution.** This verifier did not and should not attempt to run PHPUnit here (PHP not on PATH in this environment, and the repo's own convention warns that a piped/backgrounded run reports false success). The reported figures are internally plausible (grep-based test-method counts match exactly for the three named suites) but were not independently executed by this verification pass. A human with a working Herd PHP shell should run the three `gate-46.ps1` invocations plus the full `tests/Feature/Cockpit` directory to confirm 24/13/38/412 passed and the D-06 baseline, exactly as the task's own `<verification>` block specifies.

Both of these were foreseeable and named by the plan itself, not discovered gaps — this is the expected "human checks the last mile" outcome for a plan of this shape, not a sign anything is broken.

---

_Verified: 2026-09-30_
_Verifier: Claude (gsd-verifier)_
