---
phase: 260426-gvm-quick
plan: 01
subsystem: worksheets
tags: [worksheets, public-link, signoff, docx, mobile]
requires: []
provides:
  - "UUID-gated public worksheet sign-off link"
  - "Append-only worksheet_signoffs audit table"
  - "Latest signature embedded in regenerated worksheet DOCX"
affects:
  - "app/Models/Worksheet.php (boot/relationships/helpers)"
  - "app/Services/WorksheetDocxService.php (addEngineerSignOff branch)"
  - "resources/views/worksheets/show.blade.php (Client Link card)"
tech_stack:
  added: []
  patterns:
    - "UUID public-token gate (mirrors site-survey access_token pattern)"
    - "Append-only signoff audit log (no unique, no softDeletes)"
    - "Vanilla canvas signature pad (no npm dependency)"
    - "PhpWord addImage() with try/finally tmp-file cleanup"
key_files:
  created:
    - "database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php"
    - "database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php"
    - "app/Models/WorksheetSignoff.php"
    - "app/Http/Controllers/PublicWorksheetController.php"
    - "resources/views/worksheets/public-show.blade.php"
    - "tests/Feature/Worksheet/PublicWorksheetSignoffTest.php"
    - "tests/Fixtures/1x1.png"
  modified:
    - "app/Models/Worksheet.php"
    - "routes/web.php"
    - "resources/views/worksheets/show.blade.php"
    - "app/Services/WorksheetDocxService.php"
decisions:
  - "Append-only signoffs (no unique on worksheet_id) — preserves the snag-list resignoff workflow"
  - "Tiebreak signoffs by id desc as well as signed_at desc — deterministic latest under sub-second double-submit"
  - "Sign-off page stays accessible after sign — engineers continue updating notes/photos via admin"
  - "Vanilla canvas signature pad over signature_pad npm dep — keeps front-end footprint small (project convention)"
  - "Per-room ENGINEER SIGN-OFF block embeds the same latest signature — matches existing per-room sectioning"
metrics:
  duration: "~45 min"
  completed: 2026-04-25
  tasks_completed: 3
  files_changed: 11
  tests_added: 12
---

# 260426-gvm Public Worksheet Sign-Off Link Summary

**One-liner:** Public UUID-gated client sign-off page for worksheets, with mobile signature pad, append-only audit log, and signature embedded into the regenerated DOCX — mirrors the site-survey link pattern with the locked design choices the plan called out (no expiry, no overwrite, page remains live post-sign).

## Tasks completed

| Task | Description | Commit | Tests |
| ---- | ----------- | ------ | ----- |
| 1 | DB schema + models (access_token + worksheet_signoffs + relationships/helpers) | `3cce4e8` | 3 |
| 2 | Public controller + routes + read-only view + admin Client-Link card | `1dc0b0d` | 8 |
| 3 | DOCX `ENGINEER SIGN-OFF` embeds latest signature PNG + comments | `e31f58d` | 1 |

## Migrations applied

```
2026_04_26_000001_add_access_token_to_worksheets_table   ... DONE (23.38ms)
2026_04_26_000002_create_worksheet_signoffs_table        ... DONE (26.36ms)
```

`worksheets` gains a nullable unique `access_token UUID`. `worksheet_signoffs` is created with the locked schema: `worksheet_id` FK (no unique constraint), `client_name`, `signature_png_base64` (longText, raw base64 — no `data:` prefix), `signed_with_comments`, `comments`, `signed_at`, `ip_address`, `user_agent`, timestamps. **No softDeletes** — this is the permanent acceptance audit trail.

## Files created

- `database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php`
- `database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php`
- `app/Models/WorksheetSignoff.php`
- `app/Http/Controllers/PublicWorksheetController.php`
- `resources/views/worksheets/public-show.blade.php`
- `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php`
- `tests/Fixtures/1x1.png`

## Files modified

- `app/Models/Worksheet.php` — adds `access_token` to `$fillable`, `boot()` UUID generator, `signoffs()` hasMany with `signed_at desc, id desc` ordering, `latestSignoff()`, `isSigned()`, `publicUrl()`.
- `routes/web.php` — adds `use App\Http\Controllers\PublicWorksheetController;` and the new public block (`GET /worksheet/{token}` + `POST /worksheet/{token}/sign` with `throttle:10,1`) immediately after the public survey routes block, **outside** the `auth` middleware group.
- `resources/views/worksheets/show.blade.php` — adds the **Client Sign-Off Link** card directly after the Status bar, with `x-data` copy-to-clipboard, "Open ↗" external link, and a green `✓ Signed by …` line that appears once `Worksheet::isSigned()` returns true.
- `app/Services/WorksheetDocxService.php` — refactors `buildRoom()` to call a new `addEngineerSignOff(Section, Worksheet, &tmpPaths)` method. When `latestSignoff()` returns null, the original 6-row empty form table is preserved verbatim (regression guard). When signed, the table renders Client Name / Signed At / Signed With Notes / Outstanding Items / Client Signature, with the signature PNG embedded via `addImage()` from a tmp file written under `sys_get_temp_dir()`. `build()` collects all tmp paths in a `try/finally` so they're cleaned up after `$writer->save()`.

## Test coverage

12 feature tests in `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php`:

| # | Test | Target |
| - | ---- | ------ |
| 1 | `test_worksheet_gets_uuid_access_token_on_create` | Schema |
| 2 | `test_worksheet_helpers_signoffs_isSigned_latestSignoff_publicUrl` | Schema |
| 3 | `test_worksheet_signoff_signature_data_uri_accessor` | Schema |
| 4 | `test_show_returns_200_with_valid_token_and_renders_project_name` | Controller |
| 5 | `test_show_returns_404_with_unknown_token` | Controller |
| 6 | `test_sign_persists_worksheet_signoff_with_correct_fields_including_stripped_base64` | Controller |
| 7 | `test_sign_with_missing_signature_or_name_returns_422` | Validation |
| 8 | `test_sign_with_signed_with_comments_true_but_empty_comments_returns_validation_error` | Validation |
| 9 | `test_resubmit_appends_a_second_signoff_and_does_not_overwrite_the_first` | Append-only behaviour |
| 10 | `test_sign_route_is_throttled_to_10_per_minute` | Middleware |
| 11 | `test_admin_worksheet_show_page_exposes_public_link` | Admin UI |
| 12 | `test_docx_regeneration_after_signoff_embeds_signature_png_bytes` | DOCX |

```
Tests:    12 passed (50 assertions)
```

Full Worksheet feature suite remains green: **121 tests pass**.

Full project suite: **867 passed, 4 skipped, 1 failed** (the failure is the pre-existing flaky `Tests\Feature\Queue\QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` — present before this task and not caused by it).

## Deviations from plan

### Auto-fixed issues

**1. [Rule 1 — Bug] Sub-second resignoff race in `Worksheet::signoffs()` ordering**

- **Found during:** Task 2 (test `test_resubmit_appends_a_second_signoff_and_does_not_overwrite_the_first`)
- **Issue:** When two sign-offs are created within the same wall-clock second (test scenario; also a real risk if a client double-taps the submit button), `orderBy('signed_at', 'desc')` is non-deterministic — the test was getting "Sig One" when "Sig Two" was the real latest.
- **Fix:** Added `orderBy('id', 'desc')` as a tiebreaker on the `signoffs()` relationship. Append-only auto-increment id is monotonically newer than any prior row, so this resolves any sub-second tie deterministically.
- **Files modified:** `app/Models/Worksheet.php`
- **Commit:** `1dc0b0d`

**2. [Rule 2 — Missing critical functionality] PNG tmp-file cleanup**

- **Found during:** Task 3 implementation
- **Issue:** Plan called for tmp-file PNG writing but did not explicitly say to clean up on success. Without cleanup, every regeneration would leak ~1KB of PNG data into the temp dir.
- **Fix:** `build()` now collects tmp paths in `$tmpSignaturePaths` and a `try/finally` block deletes them after `$writer->save()` flushes the docx zip. Also added defensive `Log::warning` + textual fallback when `addImage()` throws on a malformed PNG, so a corrupt signature never crashes regeneration.
- **Files modified:** `app/Services/WorksheetDocxService.php`
- **Commit:** `e31f58d`

### Authentication gates

None — the entire feature is no-auth by design (public UUID-gated link).

## Manual smoke-test result

Skipped per quick-task constraint (`Run php artisan test after each task and verify zero new failures`). Automated coverage:

- **Public-show route gate:** `test_show_returns_200_with_valid_token_and_renders_project_name` + `test_show_returns_404_with_unknown_token`
- **Sign happy-path + base64 strip:** `test_sign_persists_worksheet_signoff_with_correct_fields_including_stripped_base64`
- **Append-on-resubmit:** `test_resubmit_appends_a_second_signoff_and_does_not_overwrite_the_first`
- **Admin link card:** `test_admin_worksheet_show_page_exposes_public_link`
- **DOCX embed:** `test_docx_regeneration_after_signoff_embeds_signature_png_bytes`

The user can perform the in-browser smoke tests listed in the plan's `<verification>` block once they pull these commits — the routes are wired, the canvas pad is functional (vanilla pointer/touch events, no external lib), and the signature PNG flows end-to-end into the DOCX.

## Known stubs

None. Every UI element renders real data:
- Public page shows real `generated_data['rooms']` (Equipment, Install Steps, Cable Routes, Power & Network) — copies the rendering logic from the existing admin `worksheets/show.blade.php`.
- Signed banner pulls real `latestSignoff()` data including comments.
- Admin Client Link card pulls `Worksheet::publicUrl()` and the live signed-line.
- DOCX renders real signoff fields + the actual PNG signature bytes.

## Self-Check: PASSED

- `database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php` — FOUND
- `database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php` — FOUND
- `app/Models/WorksheetSignoff.php` — FOUND
- `app/Http/Controllers/PublicWorksheetController.php` — FOUND
- `resources/views/worksheets/public-show.blade.php` — FOUND
- `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php` — FOUND
- `tests/Fixtures/1x1.png` — FOUND
- Commit `3cce4e8` (Task 1) — FOUND on `feat/worksheet-classifier-universal`
- Commit `1dc0b0d` (Task 2) — FOUND on `feat/worksheet-classifier-universal`
- Commit `e31f58d` (Task 3) — FOUND on `feat/worksheet-classifier-universal`
