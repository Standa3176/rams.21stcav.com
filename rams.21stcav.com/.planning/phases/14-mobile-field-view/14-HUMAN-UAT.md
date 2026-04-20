---
status: partial
phase: 14-mobile-field-view
source: [14-VERIFICATION.md]
started: "2026-04-20T12:10:00Z"
updated: "2026-04-20T12:10:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. 375px viewport render
expected: Opening `/projects/{project}/programme` in Chrome DevTools iPhone SE emulator (375×667) renders without horizontal scrollbar; all interactive elements thumb-reachable.
result: [pending]

### 2. iOS HEIC upload end-to-end
expected: From iOS Safari on a real iPhone, selecting a camera photo via the photo-upload component causes server to convert HEIC→JPEG, stores at `storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg`, thumbnail renders in strip, `install_task_photos.mime_type` is `image/jpeg`.
result: [pending]

### 3. Inline save animation timing (SC-2)
expected: Tapping a pending task row transitions colour (pending-gray → amber → green) with 400ms ring-green pulse, no page reload. Room counter and programme progress bar both tick up visually.
result: [pending]

### 4. Clock in/out chip interaction (SC-5)
expected: Tapping "Clock in" chip in sticky bar turns it teal (#178A95) with "On the clock · 0:00". Second tap clocks out and chip returns to white. Attempting double clock-in while already open surfaces inline 422 message. 30s setInterval updates H:MM display.
result: [pending]

## Summary

total: 4
passed: 0
issues: 0
pending: 4
skipped: 0
blocked: 0

## Gaps
