---
phase: 261010-foa
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/PublicWorksheetController.php
  - tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php
autonomous: true
requirements: []
must_haves:
  truths:
    - "Uploading a photo with a room_name that IS on the worksheet still succeeds — the photo is created and filed under that room, exactly as before this fix."
    - "Uploading a photo with a room_name that is NOT on the worksheet is refused (422) and no WorksheetPhoto row is ever written."
    - "A worksheet with no rooms at all refuses every room_name it is offered — 422, never a 500."
    - "A signed (locked) worksheet refuses an uploadPhoto POST — even one carrying a perfectly valid room_name — before the room guard could run, proving D-07's lock-before-validation ordering is unchanged."
  artifacts:
    - path: "app/Http/Controllers/PublicWorksheetController.php"
      provides: "uploadPhoto now calls the existing private helper assertRoomNameIsOnTheWorksheet($worksheet, ...) instead of trusting $request->input('room_name') directly — same helper addAdditionalKit already calls at :523"
    - path: "tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php"
      provides: "RED-then-GREEN proof: valid room succeeds, forged room is refused and writes nothing, roomless worksheet refuses without a 500, and a signed worksheet refuses a valid-room upload (lock ordering intact)"
  key_links:
    - from: "app/Http/Controllers/PublicWorksheetController.php::uploadPhoto"
      to: "app/Http/Controllers/PublicWorksheetController.php::assertRoomNameIsOnTheWorksheet"
      via: "direct method call replacing the raw $request->input('room_name') read, inserted AFTER validate() and the capture-lock check — same position addAdditionalKit uses"
      pattern: "assertRoomNameIsOnTheWorksheet"
---

<objective>
`PublicWorksheetController::uploadPhoto` validates `room_name` as `['required','string','max:200']` and then uses it verbatim — it never checks the room is actually on that worksheet. Every other room-scoped write endpoint on this controller already guards this: `addAdditionalKit` calls the private helper `assertRoomNameIsOnTheWorksheet($worksheet, ...)` at line 523; `markRoomComplete`, `markSurveyReviewed` and `saveRoomNotes` each carry their own deliberately-not-shared copy of the same inclusion-list check (the controller's own docblock at line 723-729 explains why those three stay separate: "three call sites that are free to differ are better than three forced to move together"). `modifyAdditionalKit` is the fourth sibling and takes no `room_name` at all, because a row cannot change rooms.

`uploadPhoto` is simply the one write endpoint on this unauthenticated, token-only page that never got the guard its siblings all have. The exposure: anyone holding the public worksheet link can POST an arbitrary `room_name` and file a photo against a room that was never on that worksheet, polluting the install record and the per-room photo ZIP the office hands to Bitrix. It is a write-side defect only — the worksheet itself still resolves correctly from the token (per `resolveWorksheet`), so there is no cross-worksheet read involved.

Purpose: close the one unvalidated write path on this controller using the machinery that already exists — no new helper, no new validation rule, no schema change.
Output: `uploadPhoto` calls `assertRoomNameIsOnTheWorksheet` in the same position (`addAdditionalKit`) uses it — after the capture-lock check and after `validate()`, before the room name is used for anything. A new test file proves both sides (valid room still works, forged room is refused) plus the no-rooms edge case and the signed/locked ordering, RED before the fix and GREEN after.

── THE OFFLINE-DRAIN DECISION (made here, not deferred) ─────────────────────

A photo queued on a phone (`resources/views/partials/_engineer-offline-capture.blade.php`, IndexedDB store `pending_uploads`) carries its `room_name` from the device and may drain hours later. If a room is renamed between capture and drain, the new guard will refuse that queued photo with 422.

**Decision: accept the refusal — do not special-case it.** Two things make this the obviously-correct call rather than a new risk being introduced:

1. This is not a new failure mode this plan invents. The three sibling guarded endpoints (`addAdditionalKit`, `markRoomComplete`/`saveRoomNotes` via their own inline copies) already refuse a renamed room with the identical 422, and have since phase 46.4/46.7. `uploadPhoto` is the outlier being brought into line with established behaviour, not a new precedent being set.
2. The offline queue's own drain loop (`_engineer-offline-capture.blade.php:278-283`) already treats HTTP 422 as **terminal** — "the server will never accept this payload, so discard it instead of retrying forever" — and surfaces `onFailure(row, ...)` to the engineer via the existing toast/queue-panel UI. A renamed-room photo drains into exactly the same already-built refusal path a locked-worksheet photo takes today. No JS change, no IndexedDB schema change, nothing to migrate.

This plan therefore touches zero frontend/queue files. `DB_VERSION`, `keyPath` and the index in the offline queue are untouched.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@app/Http/Controllers/PublicWorksheetController.php
@app/Support/Worksheets/WorksheetCaptureLock.php
@tests/Feature/Worksheets/EngineerLinkAdditionalKitTest.php
@tests/Feature/Worksheets/WorksheetPhotoBucketCaptureTest.php
</context>

<interfaces>
<!-- Read directly from the live file — no further exploration needed. -->

`app/Http/Controllers/PublicWorksheetController.php`, `uploadPhoto` as it stands today (lines 107-141, trimmed):

```php
public function uploadPhoto(Request $request, string $token): \Illuminate\Http\JsonResponse
{
    $worksheet = $this->resolveWorksheet($token);

    // D-07 — BEFORE VALIDATION, on purpose. ...
    if (WorksheetCaptureLock::isLocked($worksheet)) {
        return response()->json(['message' => WorksheetCaptureLock::MESSAGE], 422);
    }

    $request->validate([
        'room_name' => ['required', 'string', 'max:200'],
        'photo'     => ['required', 'file', 'image', 'max:10240'],
        'caption'   => ['nullable', 'string', 'max:200'],
        'bucket'    => ['sometimes', 'string', \Illuminate\Validation\Rule::in(\App\Models\WorksheetPhoto::BUCKETS)],
    ]);

    $roomName = $request->input('room_name');   // <-- THE DEFECT: never checked against the worksheet
    $bucket   = $request->input('bucket') ?: \App\Models\WorksheetPhoto::BUCKET_COMPLETION;
    // ... uses $roomName from here down (sort_order query, photo create)
}
```

The helper to call, already private on the same class (lines 731-747), already used by `addAdditionalKit` at line 523 in exactly this position (after `validate()`, before the room name is used):

```php
private function assertRoomNameIsOnTheWorksheet(Worksheet $worksheet, string $roomName): string
{
    $validRoomNames = collect((array) ($worksheet->generated_data['rooms'] ?? []))
        ->pluck('name')->filter()->values()->all();

    abort_if(empty($validRoomNames), 422, 'Worksheet has no rooms — cannot record additional kit.');

    if (! in_array($roomName, $validRoomNames, true)) {
        abort(422, 'Unknown room name.');
    }

    return $roomName;
}
```

Note the abort_if message is worded for the kit endpoint ("cannot record additional kit") — leave it as-is. Do not edit the shared helper's wording; it is reused verbatim by this fix, matching the instruction to use the existing helper rather than write a second one or fork its text.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write the failing room-name guard test for uploadPhoto (RED), then apply the one-line fix (GREEN)</name>
  <files>tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php, app/Http/Controllers/PublicWorksheetController.php</files>
  <behavior>
    - Test 1 (non-vacuity, positive side) — a `room_name` that IS on the worksheet: POST to `public-worksheet.photos.upload` with `room_name` set to a room actually present in `generated_data['rooms']` and a fake image file. Assert 200/OK, assert exactly one `WorksheetPhoto` row exists for the worksheet, and assert its `room_name` matches what was posted.
    - Test 2 (the defect itself) — a `room_name` NOT on the worksheet: same POST but with a room name that does not appear in `generated_data['rooms']`. Before the fix this currently returns 200 and creates a row (the defect). After the fix it must return 422 and create NOTHING. Assert `WorksheetPhoto::where('worksheet_id', ...)->count() === 0`.
    - Test 3 (edge case, mirrors `test_a_worksheet_with_no_rooms_is_refused_and_does_not_500` in `EngineerLinkAdditionalKitTest`) — a worksheet whose `generated_data['rooms']` is empty: POST with any room name must refuse 422, never 500, and write nothing.
    - Test 4 (ordering proof) — sign the worksheet (create a real `WorksheetSignoff` row, same fixture pattern as `EngineerLinkAdditionalKitTest::sign()`), then POST with a room name that IS valid. Must still refuse 422 with body `{"message": WorksheetCaptureLock::MESSAGE}` and write nothing — proving the capture lock still fires before the new room guard could ever run, i.e. the fix did not disturb D-07's ordering.
    Use `Storage::fake('local')` in `setUp()` (same as `WorksheetPhotoBucketCaptureTest`) since `uploadPhoto` writes the file to disk on success.
  </behavior>
  <action>
    First write `tests/Feature/Worksheets/EngineerLinkPhotoRoomNameGuardTest.php` with the four tests above, copying the `worksheet()`/`sign()` fixture helpers verbatim from `EngineerLinkAdditionalKitTest.php` (two rooms via `generated_data`, `Worksheet::create` not the factory, `STATUS_FINAL`) and the `upload()` POST helper shape from `WorksheetPhotoBucketCaptureTest.php` (`route('public-worksheet.photos.upload', ...)`, `UploadedFile::fake()->image(...)`, `Accept: application/json`).

    Run the gate filtered to this new class BEFORE touching the controller and capture the actual failure output verbatim — Test 2 and Test 3 are expected to fail (currently 200 instead of 422, row created instead of none); Test 1 and Test 4 are expected to already pass (they do not depend on the missing guard). Quote this RED output in the SUMMARY, not an assumed one.

    Then apply the fix: in `PublicWorksheetController::uploadPhoto`, replace the line `$roomName = $request->input('room_name');` with `$roomName = $this->assertRoomNameIsOnTheWorksheet($worksheet, $request->input('room_name'));` — same position `addAdditionalKit` uses it (after `validate()`, before the room name is used for the sort-order query or the photo create). Do not move the capture-lock check, do not touch `validate()`'s rules, do not edit `assertRoomNameIsOnTheWorksheet` itself. Add a one-line comment above the call noting 261010-foa and that this mirrors `addAdditionalKit` (:523), so a future reader sees this was a deliberate parity fix and not an accidental move.

    Re-run the same filtered gate and capture the GREEN output — all four tests pass, with Test 2/3's failure reasons from the RED run now resolved.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter EngineerLinkPhotoRoomNameGuardTest > "$env:TEMP/g1.txt" 2>&1; Get-Content "$env:TEMP/g1.txt" -Tail 30</automated>
  </verify>
  <done>EngineerLinkPhotoRoomNameGuardTest exists with all four tests described above. The RED run (captured before the controller edit) shows Test 2 and Test 3 failing for the expected reason (200 + row created instead of 422 + no row). The GREEN run (captured after the one-line controller change) shows all four tests passing. Both outputs are quoted verbatim in the SUMMARY.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Engineer/anyone holding the link (unauthenticated, token-gated) → `PublicWorksheetController::uploadPhoto` | The UUID access token is the only credential; the request body (`room_name`, `photo`, `caption`, `bucket`) is fully untrusted |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-foa-01 | Tampering | `uploadPhoto` — `room_name` accepted as free text with no membership check against the worksheet's own room list | mitigate | Call the existing `assertRoomNameIsOnTheWorksheet` helper (already proven by `addAdditionalKit`) immediately after `validate()`; Test 2 proves a forged room name is refused (422) and writes nothing |
| T-foa-02 | Tampering | A room renamed between offline capture and queue drain causes a legitimately-captured photo to be refused post-fix | accept | Matches existing behaviour on every other guarded write endpoint (`addAdditionalKit`, `markRoomComplete`, `saveRoomNotes`) since phase 46.4/46.7; the offline queue already treats 422 as terminal-discard with an engineer-visible failure notification — no new frontend risk introduced |
| T-foa-03 | Tampering | npm/pip/cargo installs | n/a | No package-manager install in this plan |
</threat_model>

<verification>
Run Task 1's own `<verify>` command twice — once before the controller edit (RED) and once after (GREEN) — each redirected to its own temp file, never piped. Then run the full Worksheets suite and the D-06 baseline gate to confirm no regression, measured in the foreground, not inherited from the constraints block:

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Path tests/Feature/Worksheets > "$env:TEMP/gworksheets.txt" 2>&1; Get-Content "$env:TEMP/gworksheets.txt" -Tail 10
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Baseline > "$env:TEMP/gbaseline.txt" 2>&1; Get-Content "$env:TEMP/gbaseline.txt" -Tail 10
```

D-06 baseline gate is `>= 159 passed AND 0 failed` — never equality against 161. Re-measure the Worksheets suite total from this foreground run rather than trusting the 265-passed figure cited in planning without re-running it here, after the change.
</verification>

<success_criteria>
- `uploadPhoto` refuses a `room_name` not present in the worksheet's own `generated_data['rooms']` with 422 and writes no `WorksheetPhoto` row.
- `uploadPhoto` still succeeds for a `room_name` that IS on the worksheet — the positive case is proven, not assumed.
- A worksheet with no rooms refuses every upload with 422, never a 500.
- A signed worksheet still refuses an upload carrying a valid room name — the capture-lock ordering (D-07) is unchanged.
- No new helper was written; `assertRoomNameIsOnTheWorksheet` is reused verbatim, unedited.
- No file under `resources/views/worksheets/public-show.blade.php` or `resources/views/partials/_engineer-offline-capture.blade.php` was touched — pinned occurrence counts and the IndexedDB queue schema are both untouched.
- No migration was written.
- `tests/Feature/Worksheets` and the D-06 baseline (`>= 159 passed AND 0 failed`) both pass when re-measured in the foreground after this change.
- Three protected files remain byte-identical to `4abd2b24`.
</success_criteria>

<output>
Create `.planning/quick/261010-foa-add-the-missing-room-name-inclusion-guar/261010-foa-SUMMARY.md` when done, quoting the RED failure output (before the controller edit) and the GREEN pass output (after), plus the foreground Worksheets-suite and baseline counts actually measured.
</output>
