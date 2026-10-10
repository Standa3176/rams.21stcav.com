# Quick Task 261010-foa: Room-name inclusion guard on `uploadPhoto` — Summary

One-liner: `uploadPhoto` now calls the existing `assertRoomNameIsOnTheWorksheet` helper in the same position `addAdditionalKit` uses it, closing the one write endpoint on `PublicWorksheetController` that never validated `room_name` against the worksheet's own room list.

## What changed

- `app/Http/Controllers/PublicWorksheetController.php` — in `uploadPhoto`, replaced `$roomName = $request->input('room_name');` with `$roomName = $this->assertRoomNameIsOnTheWorksheet($worksheet, $request->input('room_name'));`, in the same position (after the D-07 capture-lock check and after `validate()`, before the room name is used) that `addAdditionalKit` already uses it at `:523`. No new helper written; `assertRoomNameIsOnTheWorksheet` itself was not edited.
- `tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php` — new file, four tests (see RED/GREEN output below).
- `tests/Feature/Worksheets/EngineerLinkTabbedWalkthroughTest.php` — updated per the file's own embedded instruction (see Deviations).
- `tests/Feature/Worksheets/WorksheetPhotoBucketDefaultTest.php` — fixture fix (see Deviations).

## RED (before the controller edit)

Command: `powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter EngineerLinkPhotoRoomNameGuardTest`

```
FAIL  Tests\Feature\Worksheets\EngineerLinkPhotoRoomNameGuardTest
✓ a room name that is on the worksheet still succeeds and files the photo       27.42s
⨯ a room name not on the worksheet is refused and writes nothing                1.38s
⨯ a worksheet with no rooms is refused and does not 500                         0.12s
✓ a signed worksheet still refuses an upload with a valid room name             0.15s
────────────────────────────────────────────────────────────────────────────────
FAILED  ... a room name not on the worksheet is refused…
Expected response status code [422] but received 200.
Failed asserting that 200 is identical to 422.
  at tests\Feature\Worksheets\EngineerLinkPhotoRoomNameGuardTest.php:117

FAILED  ... a worksheet with no rooms is refused and do…
Expected response status code [422] but received 200.
Failed asserting that 200 is identical to 422.
  at tests\Feature\Worksheets\EngineerLinkPhotoRoomNameGuardTest.php:129

Tests:    2 failed, 2 passed (8 assertions)
Duration: 57.72s
```

Exactly as expected: Test 1 (valid room) and Test 4 (locked-worksheet ordering) already passed because they do not depend on the missing guard. Test 2 (forged room) and Test 3 (no-rooms edge case) failed because the endpoint accepted the forged room and wrote a row.

## GREEN (after the one-line controller fix)

Command: `powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter EngineerLinkPhotoRoomNameGuardTest`

```
PASS  Tests\Feature\Worksheets\EngineerLinkPhotoRoomNameGuardTest
✓ a room name that is on the worksheet still succeeds and files the photo       2.69s
✓ a room name not on the worksheet is refused and writes nothing                0.18s
✓ a worksheet with no rooms is refused and does not 500                        0.13s
✓ a signed worksheet still refuses an upload with a valid room name            0.09s

Tests:    4 passed (10 assertions)
Duration: 5.87s
```

## Deviations from Plan

### Auto-fixed Issues (Rule 1 — fixing tests broken by the correctness fix itself)

**1. [Rule 1] `EngineerLinkTabbedWalkthroughTest` pinned the exact defect this plan fixes, and already carried its own update instruction**

- **Found during:** foreground run of the full Worksheets suite after the GREEN gate, before touching anything beyond the planned two files.
- **Issue:** `test_a_whole_visit_walks_in_order_on_the_tabbed_page` (STEP 5) deliberately asserted the pre-fix behaviour of `uploadPhoto` — a forged `room_name` ("Server Room That Does Not Exist") was accepted (`assertOk()`) and a `WorksheetPhoto` row was written for it — with a comment block titled "THE KNOWN PRE-EXISTING GAP" and an assertion message reading, verbatim: *"If it now refuses a forged name that is an improvement, not a regression: delete this assertion and the DEFERRED note above it, and record the fix in the plan that made it. Do not weaken the assertion to keep it green."* My fix makes the assertion fail (expected 200, received 422) precisely as that note anticipated.
- **Fix:** Followed the file's own instruction. Replaced the "KNOWN PRE-EXISTING GAP" comment block with a short note recording that 261010-foa closed the gap. Replaced `$forgedUpload->assertOk()` + the "1 row written" assertion with `$this->assertForgedNameRefused($forgedUpload, 'uploadPhoto')` (the same helper every other forged-room assertion in this file already uses) and a "0 rows written" assertion. Left the `barButtonCount === 4` assertion in place — it was true before (a forged room never became a tab) and remains true now, so only its rationale comment needed rewording (from "the orphan photo grew the sticky bar" to "the refused forged upload must not change navigation").
- **Files modified:** `tests/Feature/Worksheets/EngineerLinkTabbedWalkthroughTest.php`
- **Commit:** see below (`test(261010-foa): ...`)

**2. [Rule 1] `WorksheetPhotoBucketDefaultTest`'s shared fixture had no rooms at all, so its one HTTP-upload test (unrelated to room-name — it tests bucket vocabulary) started failing with 422 instead of 200/422-on-bad-bucket**

- **Found during:** same full-suite run.
- **Issue:** `test_the_upload_endpoint_accepts_during_and_still_refuses_an_unknown_bucket` posts `room_name => 'Boardroom'` through the real `uploadPhoto` route, but the file's private `worksheet()` fixture used `Worksheet::factory()->create(...)` with no `generated_data` at all — so `generated_data['rooms']` was empty, and the new guard correctly refused with "Worksheet has no rooms" (422) on the first POST, which the test asserted `assertOk()`.
- **Fix:** Added `'generated_data' => ['rooms' => [['name' => 'Boardroom']]]` to the fixture's `Worksheet::factory()->create([...])` call. 'Boardroom' is the room name every fixture row and every upload call in this file already used, so this restores the test's actual intent (bucket-vocabulary validation) without touching what the test is checking.
- **Files modified:** `tests/Feature/Worksheets/WorksheetPhotoBucketDefaultTest.php`
- **Commit:** see below (`test(261010-foa): ...`)

No other deviations. The controller change itself is exactly the one line the plan specified, in the position specified, with no edit to the shared helper.

## Offline-drain behaviour (corrected citation, per the appended plan note)

A photo queued offline whose room is renamed before the queue drains is **not discarded**. The worksheet page's own drain loop — `resources/views/worksheets/public-show.blade.php:3434-3542` (NOT the surveys partial `_engineer-offline-capture.blade.php`, which only serves the survey link) — on a non-2xx response increments `attemptCount`, sets `lastError` from the server body, and calls `_updateRow(row)`, keeping the row. After three attempts a generic toast fires while the real "Unknown room name." message sits in the pending panel. This is retain-and-retry-with-a-buried-message, not discard, and it is the same behaviour `addAdditionalKit` already produces today — no new mechanism was introduced. `public-show.blade.php` itself was not touched; its pinned occurrence counts (`{!!` = 1, ` x-data` = 1, ` x-show` = 1, ` x-model` = 2, ` x-cloak` = 1) are unaffected.

## Verification

- Full `tests/Feature/Worksheets` suite (foreground, after both deviation fixes): **269 passed (2345 assertions)**, 0 failed. (The plan's cited "265 passed" figure predates both the new test file and the two deviation edits; 269 is the re-measured total: 265 + 4 new tests + the 2 previously-failing tests now passing again, net of no deletions.)
- D-06 baseline gate (`-Baseline`): **159 passed, 0 failed, 2 skipped** — meets `>= 159 passed AND 0 failed`.
- Protected-file hash gate (`-Hashes`): all three files byte-identical to `4abd2b24` —
  - `layouts/app.blade.php` → `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` (match)
  - `resources/css/app.css` → `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` (match)
  - `tailwind.config.js` → `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` (match)

## Self-Check

- `app/Http/Controllers/PublicWorksheetController.php` exists and contains the new call — FOUND.
- `tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php` exists — FOUND.
- `tests/Feature/Worksheets/EngineerLinkTabbedWalkthroughTest.php` and `tests/Feature/Worksheets/WorksheetPhotoBucketDefaultTest.php` modified — FOUND (git status).
- `resources/views/worksheets/public-show.blade.php` NOT modified — CONFIRMED (absent from `git status --short`).
- No migration file created — CONFIRMED (no new file under `database/migrations/`).

## Self-Check: PASSED

## What could not be delivered

Nothing was left undelivered. All plan tasks, the two in-scope test-fixture deviations (both explicitly licensed — one by the test file's own embedded instruction, one as a direct consequence of the fix under the shared deviation rules), the full Worksheets suite, the D-06 baseline gate, and the protected-file hash check all completed successfully.
