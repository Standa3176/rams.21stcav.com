---
phase: 14
slug: mobile-field-view
status: verified_with_open_items
threats_total: 25
threats_closed: 21
threats_open: 1
threats_accepted: 5
asvs_level: 2
block_on: "critical,high"
created: 2026-04-20
---

# Phase 14 — Security (Mobile Field View)

> Per-phase security contract. Verifies each threat from the five 14-0N-PLAN.md `<threat_model>` blocks against the implemented code. Cross-references the five REVIEW.md warnings (WR-02, WR-03, WR-04 are security-adjacent).
>
> Result: 21 CLOSED, 1 OPEN (T-14-21, new from WR-04), 5 ACCEPTED. No critical/high-severity open threats. Phase clears `block_on: critical,high` policy.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| HTTP body → controller `validate()` | All mutation endpoints run strict validation (Rule::in, mimetypes, max sizes, required_if) | Task status, notes, photo uploads, time-entry start/stop |
| Controller → Service | Services trust controller-validated inputs; never validate or authorise | InstallTask, InstallTaskPhoto, TimeEntry objects |
| UploadedFile → disk | Client-uploaded bytes; format-sniffed post-conversion, UUID-named, path-isolated per project+task | Image bytes (JPEG/PNG/WebP/HEIC) |
| Client `original_name` → DB | Display-only; sanitised (path traversal strip) | User-facing string (<=200 chars) |
| Concurrent clock-in requests | Race condition window — mitigated via `DB::transaction` + `lockForUpdate` | time_entries rows |
| Route model binding (`{task}`, `{photo}`, `{project}`) | Implicit Eloquent binding — non-existent IDs 404 before controller code runs | Primary-key integers |
| auth middleware → Phase 14 routes | All 9 routes inside `web`/`auth` group; unauthenticated → 302 /login | Session cookie + CSRF token |
| Server Blade → Alpine `@js(...)` | User-supplied strings rendered via `{{ }}` or `@js()`; no `{!!` or `x-html` | Task title, notes, room_name, caption |
| Private photo disk → `response()->file()` | Storage::disk('local') path; served via ownership-guarded route only | Binary image bytes |

---

## Threat Register

### Wave 0 — Test scaffold (14-01-PLAN.md)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-00a | Tampering | `tests/Fixtures/sample.heic`, `tests/Fixtures/sample.jpg` | accept (with mitigation) | Scaffold-only; fixtures must not contain EXIF/GPS/PII; magic-byte verified | 14-01-SUMMARY.md acceptance criteria + manual README review | CLOSED |
| T-14-00b | Tampering | Factories writing to DB during test | accept | `RefreshDatabase` + `:memory:` SQLite isolates tests | `phpunit.xml` database config | CLOSED |
| T-14-00c | Information Disclosure | Fixture README.md | accept (with mitigation) | README documents source only; no credentials/API keys/customer data | 14-01-SUMMARY.md — manual review gate | CLOSED |

### Wave 1 — Schema + models (14-02-PLAN.md)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-04 | Repudiation | install_tasks status change with no audit | mitigate | D-07 columns `status_changed_at` + `status_changed_by` added; controller sets from `auth()->id()`, never from request body | `database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php:26-31` + `app/Http/Controllers/TaskStatusController.php:66-67` | CLOSED |
| T-14-05 | Tampering | install_task_photos row inserted with fake install_task_id | mitigate | `foreignId('install_task_id')->constrained('install_tasks')->cascadeOnDelete()` — DB rejects invalid IDs | `database/migrations/2026_04_20_000001_create_install_task_photos_table.php` (foreign key constraint) + `app/Services/TaskPhotoService.php:56` (UUID-only filename, never client-derived) | CLOSED |
| T-14-06 | Information Disclosure | install_task_photos.filename stored as relative path | accept → mitigate (Wave 3) | Relative path only; absolute resolution server-side; served through ownership-guarded route | `app/Models/InstallTaskPhoto.php::storagePath()` + `app/Http/Controllers/TaskPhotoController.php:173-186` (show() with `authoriseTaskMutation`) | CLOSED |
| T-14-07 | Tampering | install_task_photos.mime_type client-controlled | mitigate (Wave 2) | `mime_type` set from `mime_content_type(absolutePath)` POST-conversion; never from `$file->getMimeType()` | `app/Services/TaskPhotoService.php:72` | CLOSED (see WR-03 gap below — marginal server-side sniff reuse issue, not a spoof of the stored mime_type column itself) |
| T-14-08 | Tampering | Concurrent double clock-in creates two open entries | mitigate | `DB::transaction` wraps check+insert; `lockForUpdate()` on open-entry query serialises parallel requests | `app/Services/TimeEntryService.php:40-46` + `tests/Feature/TimeEntries/TimeEntryTest.php:49` (`test_double_clock_in_rejected`) | CLOSED |

### Wave 2 — Services (14-03-PLAN.md)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-01 | Tampering | HEIC payload decoding | mitigate | `HeicImageConverter::requireImagick()` throws `RuntimeException` if ext-imagick missing (D-11 fail-loud); decode errors re-thrown with libheif remediation message; `AppServiceProvider::boot()` logs non-blocking warning if libheif delegate missing | `app/Services/HeicImageConverter.php:104-120` (requireImagick) + `app/Services/HeicImageConverter.php:67-80` (decode try/catch) + `app/Providers/AppServiceProvider.php:76-90` (boot warning) + `tests/Unit/Services/HeicImageConverterTest.php` | CLOSED |
| T-14-02 | Tampering | UploadedFile::getClientOriginalName() path traversal (e.g. `../../etc/passwd.jpg`) | mitigate | `TaskPhotoService::sanitiseOriginalName()` strips `/`, `\`, control chars via two regexes; UUID-only `filename` is ever used as a filesystem path | `app/Services/TaskPhotoService.php:137-145` + `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php:146` (`test_original_filename_with_traversal_is_sanitised`) | CLOSED |

### Wave 3 — Controllers + routes (14-04-PLAN.md)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-03 | Elevation of Privilege | Any mutation endpoint | mitigate | Uniform `authoriseTaskMutation` / `authoriseProjectAccess` — project owner OR admin OR assigned engineer | `app/Http/Controllers/TaskStatusController.php:138-145` + `app/Http/Controllers/TaskPhotoController.php:195-202` + `app/Http/Controllers/TimeEntryController.php:112-126` + `tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php:121` (`test_unrelated_user_gets_403`) + `tests/Feature/InstallTasks/InstallTaskNotesTest.php:60` + `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php:121,136` + `tests/Feature/TimeEntries/TimeEntryTest.php:81` | CLOSED |
| T-14-03a | Elevation of Privilege | Engineer mutates task assigned to someone else | mitigate | `authoriseTaskMutation` requires `assigned_to === auth()->id()` for non-owner non-admin users — task-level guard, not just project-level | `app/Http/Controllers/TaskStatusController.php:142` (`$isAssigned = $task->assigned_to === $user->id`) + `app/Http/Controllers/TaskPhotoController.php:199` | CLOSED |
| T-14-09 | DoS | Upload spam / massive photo fills disk | mitigate | `max:20480` (20 MB) validation + `throttle:60,1` on store route | `app/Http/Controllers/TaskPhotoController.php:66` + `routes/web.php:310` | CLOSED |
| T-14-10 | DoS | Time-entry endpoint spam | mitigate | `throttle:30,1` on both start + stop | `routes/web.php:327,332` | CLOSED (see IN-01 — rate limits missing on status/notes/photo-update/destroy; advisory only, under ASVS 2 the 20 MB cap + session auth is sufficient) |
| T-14-11 | Spoofing | CSRF on AJAX mutation endpoints | mitigate | All routes inside `web` middleware group; `VerifyCsrfToken` enforced; fetch() client sends `X-CSRF-TOKEN` meta on every non-GET call | `routes/web.php:294-332` (inside `auth` group) + `resources/views/install-programmes/field.blade.php:229,302,413,437` | CLOSED |
| T-14-12 | Information Disclosure | IDOR — user reads another project's photos via sequential ID | mitigate | `TaskPhotoController::show()` loads `photo->task->programme->project` and runs `authoriseTaskMutation` before streaming bytes | `app/Http/Controllers/TaskPhotoController.php:173-186` + `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php:136` (`test_unrelated_user_cannot_view_photo`) | CLOSED |
| T-14-13 | Input validation bypass | status field accepts arbitrary strings | mitigate | `Rule::in([STATUS_PENDING, STATUS_IN_PROGRESS, STATUS_COMPLETE, STATUS_BLOCKED, STATUS_SKIPPED])` — 422 otherwise | `app/Http/Controllers/TaskStatusController.php:47-53` | CLOSED |
| T-14-14 | Business logic bypass | blocked/skipped set without reason | mitigate | `required_if:status,blocked` + `required_if:status,skipped` — 422 if missing | `app/Http/Controllers/TaskStatusController.php:56-57` | CLOSED |

### Wave 4 — Blade + Alpine (14-05-PLAN.md)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-15 | Tampering (XSS) | task title / notes / caption XSS | mitigate | Zero `{!! !!}` or `x-html` in Phase 14 blades; all dynamic output uses Blade `{{ }}` or Alpine `x-text` / `x-model` | `Grep "x-html\|{!!"` across `resources/views/install-programmes/` and `resources/views/components/install-task/` returns 0 matches | CLOSED (see WR-02 gap below — adjacent injection site in `_field-room.blade.php:17` Alpine `:aria-label` binding; theoretical not exploitable in current data model but should be fixed) |
| T-14-16 | CSRF | fetch() state-mutating calls | mitigate | Every fetch with method != GET includes `X-CSRF-TOKEN` from `<meta name="csrf-token">`; no middleware exemption introduced | `resources/views/install-programmes/field.blade.php:185` (csrf() helper) + lines 229, 302, 413, 437 (header injection) | CLOSED |
| T-14-17 | Clickjacking | field page inside iframe | accept | Existing Laravel app sets X-Frame-Options globally; no Phase-14 change needed | Global Laravel middleware — not phase-scoped | CLOSED (accepted) |
| T-14-18 | Information Disclosure | task data in Alpine `x-data` | accept | Values injected via `@js()` are only readable by a logged-in, ownership-authorised user (render-time gate in `InstallProgrammeController::field()`) | `app/Http/Controllers/InstallProgrammeController.php::field()` ownership guard at entry | CLOSED (accepted) |
| T-14-19 | Input validation bypass | client-side "happy path" while server validates | mitigate | Server `required_if:status,blocked` + `Rule::in` enforces; client-side check is UX only | `app/Http/Controllers/TaskStatusController.php:47-57` | CLOSED |
| T-14-20 | DoS | runaway setInterval after navigation | mitigate | Clock ticker stored in `clock._tickHandle`; `stopClockTicker()` calls `clearInterval` on navigation/unmount | `resources/views/install-programmes/field.blade.php:194` (`_tickHandle`), `:258` (setInterval), `:260-261` (stopClockTicker + clearInterval) | CLOSED |

### New threats surfaced from REVIEW.md (not in original register)

| Threat ID | Category | Component | Disposition | Mitigation | Evidence | Status |
|-----------|----------|-----------|-------------|------------|----------|--------|
| T-14-21 | Information Disclosure | Shared-device browser cache leak — photo response `Cache-Control: private, max-age=3600` means a subsequent user logged into the same tablet sees the previous user's cached photo (cache keyed by URL, not cookie) | mitigate (proposed) | Change header to `private, no-cache, must-revalidate` with `Pragma: no-cache` — browser re-validates every request so the 403 gate in `show()` re-runs | `app/Http/Controllers/TaskPhotoController.php:184` — current header is `Cache-Control: private, max-age=3600` | **OPEN** |

---

## Review Findings Cross-Reference (REVIEW.md — advisory)

| Finding | Threat Mapping | Status | Notes |
|---------|----------------|--------|-------|
| WR-01 | Not security — UX mismatch (scope=all shows photo thumbnails that 403) | — | Deferred; advisory. Scope=all engineer sees broken thumbnails for non-assigned tasks. No data disclosure — guard correctly rejects. Fix is tighten Blade or broaden view-only guard. |
| WR-02 | Adjacent to T-14-15 (XSS) | Not blocking — logged below | `_field-room.blade.php:17` interpolates `{{ $roomName }}` inside Alpine `:aria-label` expression. HTML-encoded apostrophe silently breaks Alpine's JS parser (unterminated string → no aria-label). Theoretical XSS only if `room_name` contained attacker-controlled JS; in practice `room_name` comes from quote/survey data under owner/admin control. Fix: use `@js($roomName)` pattern. Does NOT open T-14-15 — XSS is not reachable without prior compromise, but the robustness fix is recommended. |
| WR-03 | Adjacent to T-14-07 | Not blocking — logged below | `TaskPhotoController::store` only content-sniffs when extension is NOT in allow-list. A file named `payload.jpg` with HTML content could pass `mimetypes:` (octet-stream accepted) and skip the extension-mismatch branch. Downstream `TaskPhotoService` then sets `mime_type` from `mime_content_type()` of the now-stored bytes — so serving via `response()->file()` would use `text/html` as Content-Type → stored XSS against the viewer. T-14-07 is CLOSED because the *stored* mime_type is not client-supplied, but WR-03 is a real hardening gap in the controller's sniff flow. Fix: always content-sniff regardless of extension; assert `str_starts_with($finfo, 'image/')`. Severity: MEDIUM (mitigated in part because delivery path requires an authenticated attacker with upload rights on a project they have access to). |
| WR-04 | NEW — T-14-21 (see Threat Register) | **OPEN** | Elevated to T-14-21 — see register. Represents real privacy exposure on shared field tablets. |
| WR-05 | Tampering / data integrity (not originally in register) | Accepted — see Accepted Risks Log (ACC-14-01) | File orphaning on DB insert failure after successful disk write. Low probability; logged here to preserve audit trail. |

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| ACC-14-00a | T-14-00a | Test-scaffold binary fixtures — documented mitigation (EXIF scrub, size cap) lives in 14-01-SUMMARY.md | GSD plan-approver | 2026-04-20 |
| ACC-14-00b | T-14-00b | Factory rows isolated to `:memory:` SQLite via `RefreshDatabase` — zero production data exposure | GSD plan-approver | 2026-04-20 |
| ACC-14-00c | T-14-00c | Fixture README audited for PII/credentials before commit | GSD plan-approver | 2026-04-20 |
| ACC-14-17 | T-14-17 | Clickjacking — existing Laravel X-Frame-Options middleware covers; no Phase-14 change | GSD plan-approver | 2026-04-20 |
| ACC-14-18 | T-14-18 | `@js()`-injected task data only readable by logged-in, ownership-authorised users (render-time gate) | GSD plan-approver | 2026-04-20 |
| ACC-14-WR05 | (new) | Orphaned photo files on DB insert failure after disk write — probability low (DB insert fails after successful disk write is a rare race); Phase 15/16 cleanup job will reap orphans. Documented in REVIEW.md WR-05. | GSD review-approver | 2026-04-20 |

*All accepted risks above carry documented mitigations or compensating controls. None resurface in future audit runs.*

---

## Open Threats

### T-14-21 — Shared-device browser cache leak (OPEN)

**File:** `app/Http/Controllers/TaskPhotoController.php:181-185`

**Issue:** Photo responses set `Cache-Control: private, max-age=3600`. Disk cache is URL-keyed — a subsequent user logged into the same browser sees cached images without re-auth. Realistic on field tablets passed between engineers. Photos can contain client site details (racks, cable runs, whiteboards with network diagrams).

**Severity:** MEDIUM (confidentiality — requires specific shared-device workflow). Not classified as HIGH because it requires physical device sharing and a prior authenticated session; does not cross the auth boundary remotely.

**Remediation (one-line change):**

```php
return response()->file($path, [
    'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
    'Content-Disposition' => 'inline; filename="'.$photo->original_name.'"',
    'Cache-Control'       => 'private, no-cache, must-revalidate',
    'Pragma'              => 'no-cache',
]);
```

With `no-cache, must-revalidate` the browser always re-hits the server and the `authoriseTaskMutation` guard runs on every fetch — same-session thumbnails still serve fast (304 on second load) but cross-session leakage is prevented.

**Recommended follow-up actions (advisory, non-blocking for Phase 14 sign-off under `block_on: critical,high`):**
1. Apply the one-line cache header fix to `TaskPhotoController::show()`.
2. (Optional) Apply WR-03 hardening — always content-sniff uploaded photos, assert `str_starts_with($finfo, 'image/')`.
3. (Optional) Apply WR-02 fix — `_field-room.blade.php:17` use `@js($roomName)` pattern for the aria-label binding.

All three fixes are <30 LOC combined and could land in a single follow-up commit. Neither WR-02 nor WR-03 re-opens an existing threat (both are defence-in-depth); T-14-21 (WR-04) is the only new OPEN threat.

---

## Unregistered Flags

SUMMARY.md `## Threat Flags` sections reviewed across 14-01 through 14-05: none report unregistered threats. All flags map to the IDs above.

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-04-20 | 25 | 21 | 1 (+3 accepted-from-review) | gsd-secure-phase (Claude) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [ ] `threats_open: 0` — **NOT YET** — T-14-21 remains OPEN (MEDIUM severity; not blocked under `block_on: critical,high`)
- [x] Cross-referenced REVIEW.md findings WR-02/WR-03/WR-04/WR-05
- [x] ASVS Level 2 controls verified: session auth, CSRF, ownership guards, input validation, rate limiting (partial), server-side MIME detection, UUID filenames, path isolation, transaction-safe race guard
- [x] `status: verified_with_open_items` set in frontmatter

**Approval:** verified with open items 2026-04-20 (clears `block_on: critical,high` policy; T-14-21 noted as MEDIUM follow-up)
