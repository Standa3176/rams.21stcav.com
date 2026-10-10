# Quick Task 261010-cdf: Working-at-height produces no RAMS hazard — Summary

Fixed a live safety-content defect where survey rooms recorded as working at 2–4m or 4m+ produced NO working-at-height hazard, PPE, or access equipment in generated RAMS; added an office correction path and a review-page visibility flag so this can never silently recur.

## What changed

**Part 1 — `app/Services/RiskTemplateResolverService.php`**
`resolveFromProjectContext()` only matched the legacy `over_2m` value at line 388. The current engineer-wizard values (`2_to_4m`, `over_4m`) fell through to no `elseif`, producing no hazard, no Hard Hat, and no access equipment — the more dangerous the recorded work, the less the RAMS said. Widened the condition to `in_array($workingHeight, ['over_2m', '2_to_4m', 'over_4m'], true)`. The `under_2m` branch was not touched. Docblock updated to state the real vocabulary.

**Part 2 — office correction before generation**
- `app/Services/RamsReviewDataService.php`: new `normaliseWorkingHeightOverrides()`, wired into `normalise()` as a new, independent top-level key `working_height_overrides`. The locked 4-key `room_overviews` schema is untouched.
- `app/Http/Controllers/RamsReviewController.php::parseReviewPayload()`: sanitises and re-indexes posted `working_height_overrides` rows (mirrors the existing `activities` loop pattern).
- `app/Services/RamsBuilderService.php`: new `applyWorkingHeightOverrides(array $rooms, array $roomOverrides): array`, called in `runFromReview()` immediately after `buildProjectContext()` and before `RamsDataBuilderService::assemble()`. Independently re-validates the allow-list since `$reviewedData` reaches this path RAW (never through `RamsReviewDataService::normalise()`).
- `resources/views/rams/quote-review.blade.php`: new "Working Height Corrections" table, same `$roomNames` loop as the existing Room/Space Overviews table, living inside `#review-form` so it is carried to both Save and Approve by the existing generic form-clone JS with zero JS changes.

**Part 3 — office review-page flag**
`resources/views/site-survey/show.blade.php`: per-room header now shows "Working height above 2m" (for `2_to_4m`/`over_4m`/legacy `over_2m`, unconditionally) or "Working height not recorded" (gated — see below). The existing `@if($hasRisk)` Risks detail table (~line 1066) is unchanged; this is purely an additive header badge. `resources/views/surveys/show.blade.php` (the separate, protected engineer wizard) was not touched.

## The two appended decisions

**(a) Missing-flag gate — chosen gate: `$hasData` (room has other captured data)**

I gated `$workingHeightMissing` on the SAME `$hasData` boolean the room header already computes for its own badge/border (`$room->av_requirements || $room->notes || $room->room_width_m || $room->ceiling_type`), rather than survey-wide `status === 'submitted'`.

Why: the bug this flag exists to catch is the room where "someone answered the other questions and skipped this one" — that is a per-room signal, not a survey-wide one. A room-level gate correctly flags a skipped room on a survey still in `draft` (mid-review, before the engineer formally submits), whereas a survey-wide `submitted` gate would hide the flag on every room until the whole survey flips status, even if an office reviewer is already looking at a room with real data and a gap. The room-level gate also requires no new query — it reuses a value already computed in this exact loop.

Tested both sides (`tests/Feature/SiteSurvey/SiteSurveyShowWorkingHeightFlagTest.php`):
- `test_started_room_with_working_height_skipped_shows_the_not_recorded_flag` — a room with `notes` set but no `risks` key at all → flag PRESENT.
- `test_genuinely_unstarted_room_shows_neither_flag` — a room with no `risks` key AND no other data at all → flag ABSENT.

The above-2m flag is NOT gated — it fires whenever the value exists, regardless of survey/room state, per the decision.

**(b) Contrast citation — corrected**

The plan's original citation (lines 84, 165, 313) was wrong: line 84 uses `var(--warning-light)` (not the literal hex), line 165 uses `#FDE68A` (a different amber). Only line 313 (`.status-pill--inprogress { background:#FEF3C7; color:#92400E; }`) matches, and the plan missed line 831 (`background:#FEF3C7;color:#92400E;` on the "Additional needed" power badge — an inline-style precedent that is the closer match to the new badge's own inline style).

Both new badges cite and reuse lines 313 and 831 verbatim. Measured contrast ratio: **6.37:1**, comfortably past WCAG AA's 4.5:1 for normal text.

## Per-suite test results (foreground, real runs)

| Suite | RED (before fix) | GREEN (after fix) |
|---|---|---|
| `RiskTemplateResolverServiceProjectContextTest` | 2 failed, 2 passed (12 assertions) | 4 passed (22 assertions) |
| `RamsReviewDataServiceTest` | 1 failed, 10 passed (32 assertions) | 11 passed (35 assertions) |
| `RamsBuilderServiceTest` | 4 failed, 17 passed (41 assertions) | 21 passed (45 assertions) |
| `SiteSurveyShowWorkingHeightFlagTest` | 3 failed, 2 passed (12 assertions) | 5 passed (12 assertions) |
| D-06 baseline gate | — | **159 passed, 0 failed, 2 skipped** (396 assertions) — meets `>= 159 passed AND 0 failed` |
| Protected-file hash check (`-Hashes`) | — | All 3 files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) byte-identical to `4abd2b24` — no mismatch |

### RED failure quotes

**Task 1** (`2_to_4m` and `over_4m`, before the `in_array` widening):
```
Expected a "Working at height" hazard for 2_to_4m.
Failed asserting that null is not null.
```
(identical message for `over_4m`). Tests 3 (`over_2m`) and 4 (`under_2m`) were already GREEN both before and after — confirmed as the non-regression baseline.

**Task 2** (`RamsReviewDataServiceTest`, before the normaliser existed):
```
Undefined array key "working_height_overrides"
```
**Task 2** (`RamsBuilderServiceTest`, before the method existed, all 4 new tests):
```
Method App\Services\RamsBuilderService::applyWorkingHeightOverrides() does not exist
```

**Task 3** (`SiteSurveyShowWorkingHeightFlagTest`, before the blade badges existed — 3 of 5 tests failed, the 2 `assertDontSee` tests passed trivially since no markup existed at all):
```
Failed asserting that 'Working height not recorded' is contained ... [full page body]
```

## Override reaches generation — proof

`test_applyWorkingHeightOverrides_applies_a_valid_override` (Reflection-invoked, `tests/Unit/Services/RamsBuilderServiceTest.php`) proves a room's `risks[0]['working_height']` is actually rewritten from `under_2m` to `2_to_4m` by `applyWorkingHeightOverrides()` — the exact array `runFromReview()` passes forward into `RamsDataBuilderService::assemble()` (confirmed by code inspection: `$projectContext['rooms'] = $this->applyWorkingHeightOverrides(...)` runs immediately after `buildProjectContext()` and before the summary/assemble path). Three further tests prove the defensive edges: a blank override is a no-op (keeps the survey value), an override for a non-matching room name is a no-op for every room, and an invalid vocabulary value (`'eiffel_tower'`) is rejected even though this path reads `$reviewedData` RAW.

## Deviations from Plan

None beyond the two appended decisions, which were followed as written. No Rule 1-4 auto-fixes were needed — the existing codebase patterns (activities-loop sanitisation, Reflection test invocation, `$hasData` badge computation) were directly reusable.

## Open question for the user (NOT decided here)

Per the plan's output instruction: **should `over_4m` make MEWP mandatory** (rather than the current "MEWP (if required)")? This plan deliberately widened only the *condition* that triggers the existing hazard/PPE/access-equipment branch — it did not change the branch's content, because making MEWP mandatory for `over_4m` specifically is a safety-content judgement call the user has not approved. Left as-is; flagging for a decision.

## Protected files

All three (`resources/views/layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) confirmed byte-identical to `4abd2b24` via `gate-46.ps1 -Hashes` — no incidental change.

## Not delivered

Nothing from the plan's three tasks was left undone. All `must_haves.truths`, `artifacts`, and `key_links` in the plan frontmatter are satisfied by the three commits below.

## Commits

- `483f06c1` — fix(rams): produce working-at-height hazard for 2_to_4m and over_4m
- `2b2b4f21` — feat(rams): let office correct working height before RAMS generation
- `6f290670` — feat(rams): flag above-2m and missing working height on survey review

No push, no deploy — local commits only, as instructed.
