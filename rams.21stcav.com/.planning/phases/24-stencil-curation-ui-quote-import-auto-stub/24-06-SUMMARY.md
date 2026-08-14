---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 06
subsystem: drawings
tags: [laravel, blade, svg-sanitisation, file-upload]

# Dependency graph
requires:
  - phase: 24-01
    provides: "logo_path nullable string column on device_stencils (D-15)"
  - phase: 24-05
    provides: "admin/device-stencils/edit.blade.php two-column edit screen (sticky preview card in the right column)"
provides:
  - "UploadDeviceStencilLogoRequest — mimes:svg,png,jpg,jpeg + max:2048 (2MB) validation"
  - "DeviceStencilController::uploadLogo() — SVG mandatorily sanitised via SvgSanitizerService before persist; PNG/JPG stored as-is"
  - "admin.device-stencils.upload-logo POST route"
  - "Manufacturer Logo upload widget on the edit screen, with ManufacturerLogoResolver fallback hint"
affects: [24-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fixed filename per stencil (device-stencils/{id}/logo.{ext}), not a UUID — deliberate divergence from DeviceLabelPhotoService's UUID-per-photo convention, because each stencil has at most one current logo and an overwrite on re-upload is the correct behaviour (there is no history/gallery requirement here, unlike device label photos)."
    - "SVG sanitisation happens BEFORE the Storage::disk('public')->put() write, never after — SvgSanitizerService::sanitize() is called on the raw uploaded bytes, and its return value (or nothing, on '' failure) is what reaches disk. No code path writes unsanitised SVG bytes to storage, even transiently."
    - "Laravel's mimes: rule (not the image: rule) is what makes PNG/SVG dual-format upload actually work — image: does not reliably admit .svg. This was flagged explicitly in the plan's interfaces section and confirmed correct against the installed Laravel 12 mimes-validation behaviour (content-type guessed via Symfony's MimeTypes registry, not extension-trusted)."

key-files:
  created:
    - app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php
    - tests/Feature/Drawings/DeviceStencilLogoUploadTest.php
  modified:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - routes/web.php
    - resources/views/admin/device-stencils/edit.blade.php

key-decisions:
  - "The logo-upload widget was added as a THIRD region inside the existing right-column `.card.stc-edit-preview` (below the Live Preview pane, divided by a border-top rule), rather than as a wholly separate `.card`. The plan sanctioned either option ('a small .card widget below the preview pane (or as a third region within the right column ... — do not restructure that grid)'); the third-region choice keeps the right column's vertical rhythm (single card, no extra gap) and avoids a second `.card` fighting for space in the sticky column on shorter viewports."
  - "The list view's Logo thumbnail column (`resources/views/admin/device-stencils/index.blade.php:181-184`) already existed from Plan 24-03/24-05 work and already reads `asset($stencil->logo_path)` — no change was needed there. Confirmed the exact string this plan's `uploadLogo()` writes (`/storage/device-stencils/{id}/logo.{ext}`) round-trips correctly through Laravel's `asset()` helper (leading slash is trimmed, not doubled)."
  - "MIME-spoof rejection (T-24-16) is tested via `UploadedFile::fake()->create('malware.png', 10)->mimeType('application/x-msdownload')` rather than crafting raw exe bytes under a .png name. Laravel's `Illuminate\\Http\\Testing\\File` fake wrapper reports `getMimeType()` from the FILENAME's extension by default (not real content-sniffing) unless `->mimeType()` is explicitly overridden — so an .exe-content-under-.png-name scenario can only be faithfully simulated in a fake-upload test by declaring a mismatched MIME type explicitly. This is the standard Laravel testing idiom for this exact scenario, not a workaround."

patterns-established:
  - "Per-stencil single-current-file upload with a fixed, predictable storage path (device-stencils/{id}/logo.{ext}) is the pattern any future single-artifact-per-parent upload in this admin area should follow — contrast with DeviceLabelPhotoService's UUID-per-capture pattern, which is correct there because label photos ARE a history/gallery, not a single current file."

requirements-completed: [DRAW-52]

# Metrics
duration: ~50min
completed: 2026-08-14
---

# Phase 24 Plan 06: Manufacturer Logo Upload Summary

**Per-stencil PNG/SVG manufacturer logo upload — every SVG mandatorily routed through the existing `SvgSanitizerService` before it ever touches disk (D-12), stored as a file at `logo_path` (D-15, sibling to the untouched legacy `logo_svg` inline-text column) — closing DRAW-52.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-14
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments

- `UploadDeviceStencilLogoRequest` validates `logo` with `['required', 'file', 'mimes:svg,png,jpg,jpeg', 'max:2048']` — `mimes:` (not `image:`) is deliberate, since `image:` does not reliably admit `.svg`; `max:2048` (2MB) matches the UI-SPEC helper text verbatim and rejects oversized uploads BEFORE any disk write (T-24-15). `authorize()` mirrors `DeviceCableRuleRequest`'s `auth()->user()?->isAdmin() ?? false` convention as defence-in-depth behind the route-level `admin` middleware group.
- `DeviceStencilController::uploadLogo()`: extension is read from `getClientOriginalExtension()` (lowercased). SVG uploads are read raw and passed through `SvgSanitizerService::sanitize()` — an empty return (unparseable/malicious input) is rejected as a 422 validation error and NEVER written to disk; the sanitised string is what actually reaches `Storage::disk('public')->put(...)`. PNG/JPG/JPEG uploads carry no script-execution surface and are stored as-is via `Storage::disk('public')->putFileAs(...)`, mirroring `DeviceLabelPhotoService`'s pattern but with a FIXED filename (`logo.{ext}`) rather than a UUID — each stencil has at most one current logo, so overwrite-on-reupload is correct. Both branches set `logo_path` to the public `/storage/device-stencils/{id}/logo.{ext}` URL and log via `Log::info`.
- `admin.device-stencils.upload-logo` POST route added inside the existing `admin` middleware group, as a suffix segment after `{deviceStencil}` (same no-collision pattern already established by `preview`/`edit`/`update`).
- `edit.blade.php` gains a "Manufacturer Logo" region (third region inside the existing sticky preview `.card`, not a layout restructure): a 64px `<img>` preview when `logo_path` is set; else, when `ManufacturerLogoResolver::resolveAssetPath($stencil->manufacturer)` resolves one of the 20 built-in brand assets, a greyed-out fallback hint with the manufacturer name substituted ("Using the built-in Netgear wordmark until a custom logo is uploaded."); else nothing. A multipart upload form posts to the new route with the exact UI-SPEC helper text verbatim and inline `$errors->first('logo')` surfacing.
- 9 feature tests in `DeviceStencilLogoUploadTest`: valid-PNG upload sets `logo_path` + file exists on disk; malicious SVG (`<script>` + `onload`) is stripped before persist — the DRAW-52 threat model made concrete; unparseable SVG rejected as a validation error, never persisted; oversized upload → 422, never a 500; MIME-type-spoofed upload rejected by the `mimes:` rule; non-admin blocked (403) by the `admin` middleware; plus 3 view-level tests for Task 2 (widget renders without breaking the existing layout, fallback hint renders with the correct manufacturer name, no hint when the manufacturer doesn't resolve).

## Task Commits

Each task was committed atomically:

1. **Task 1: UploadDeviceStencilLogoRequest + uploadLogo() action + route + tests** - `a0670c4` (feat)
2. **Task 2: Logo upload widget on the edit screen** - `38aecb6` (feat)

_The full 9-test file was written and verified in Task 1's commit (mirroring 24-05-SUMMARY.md's own precedent — "Task 1 is `tdd=\"true\"` in name only"), since the Task 2 view-level tests (widget rendering, fallback hint, helper text) needed the request/controller/route from Task 1 to already exist to be runnable; Task 2's commit is the view file only._

## Files Created/Modified

- `app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php` — `mimes:svg,png,jpg,jpeg` + `max:2048` validation, admin-only `authorize()`.
- `app/Http/Controllers/Admin/DeviceStencilController.php` — Adds `uploadLogo()`. `index()`/`edit()`/`update()`/`preview()` untouched.
- `routes/web.php` — Adds `admin.device-stencils.upload-logo` (POST) inside the existing admin group.
- `resources/views/admin/device-stencils/edit.blade.php` — Adds the "Manufacturer Logo" region to the existing sticky preview card.
- `tests/Feature/Drawings/DeviceStencilLogoUploadTest.php` — 9 tests covering both tasks.

## Decisions Made

- **Third-region-in-existing-card, not a separate `.card`** — see `key-decisions` above.
- **Fixed filename per stencil (`logo.{ext}`), not a UUID** — each stencil has at most one current logo; an overwrite on re-upload is correct, unlike `DeviceLabelPhotoService`'s photo-history use case.
- **MIME-spoof test uses `->mimeType()` override on a fake upload** — the faithful way to simulate a declared-vs-actual MIME mismatch under Laravel's `UploadedFile::fake()` testing wrapper, which otherwise derives its fake `getMimeType()` from the filename extension rather than real content.

## Deviations from Plan

### Auto-fixed Issues

None — the plan's action text was followed as written; `SvgSanitizerService` and `Storage::disk('public')` were reused exactly as specified, with method-injection (`SvgSanitizerService $svgSanitizer` as a controller-action parameter) chosen over the plan's illustrative `app(SvgSanitizerService::class)` call to match this same controller's existing `preview()` action's method-injection convention (`AutoGenericStencilGenerator $generator, StencilXmlToSvgRenderer $renderer` are both method-injected there) — a stylistic consistency choice, not a behavioural deviation.

**Total deviations:** 0.
**Impact on plan:** None — plan executed as written.

## Issues Encountered

None. No checkpoints, no blockers. All 9 new tests passed on first run.

## User Setup Required

**Verify `php artisan storage:link` exists on live.** `Storage::disk('public')->put(...)` / `->putFileAs(...)` write to `storage/app/public/device-stencils/{id}/logo.{ext}`, which is only web-reachable at `/storage/device-stencils/{id}/logo.{ext}` if the `public/storage` symlink (created by `php artisan storage:link`, per `config/filesystems.php`'s `links` array) already exists on the Hostinger VPS. This is a **genuinely new** live-server dependency for Phase 24 specifically — Phase 21's 20 built-in manufacturer wordmarks are served directly from `public/img/manufacturers/`, NOT through the storage symlink, so their presence on live proves nothing about `storage:link`. `DeviceLabelPhotoService` (an existing, presumably-working production feature) already depends on the same symlink via the identical `Storage::disk('public')->putFileAs(...)` call, which is reassuring precedent that it likely already exists — but this was **not directly confirmed** against the live server in this session (no SSH access from this executor). Run `php artisan storage:link` on live (idempotent — safe to re-run if already linked) as a pre-flight step before this plan's files are deployed, or confirm `public/storage` already exists as a symlink to `storage/app/public`.

No migration in this plan — `logo_path` was already added by Plan 24-01's migration.

## Next Phase Readiness

- **DRAW-52 is now COMPLETE** — `REQUIREMENTS.md` and `ROADMAP.md` updated accordingly. Verified against every other 24-0x plan's frontmatter `requirements:` field that DRAW-52 is not claimed anywhere else — this plan is its sole source.
- **Plan 24-07** (`StencilPromotionValidator` + Promote/Discard actions, D-04/D-03, Wave 6) is now unblocked — depends on 24-06 per its frontmatter. It can reuse the same `$stencil`/`$isCurated` variables already in scope in `edit.blade.php`, and the footer-button placeholder Plan 24-05 left commented ("Promote to Engineer-Curated / Discard & Regenerate ship in Plan 24-07 — same footer row, same controller class") is unaffected by this plan's changes (the logo widget lives in the right column, the footer buttons live in the left-column form).
- The logo-upload widget's fallback-hint copy references `ManufacturerLogoResolver::resolveAssetPath()` — Plan 24-07's promote-readiness soft-warn banner ("This stencil has no manufacturer logo — promotion will proceed without one.", per UI-SPEC) is a SEPARATE, independent check against `logo_path` being null; this plan does not implement that banner (it belongs to 24-07's Promote action), it only implements the upload mechanism the banner will eventually reference.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php`
- `app/Http/Controllers/Admin/DeviceStencilController.php`
- `routes/web.php`
- `resources/views/admin/device-stencils/edit.blade.php`

**No migration in this plan** — `logo_path` was already added by Plan 24-01's migration (must already be applied on live, per every prior plan's summary in this phase).

**⚠ Live pre-flight step (not a file upload, a server-side command):** confirm `php artisan storage:link` has been run on live so `public/storage` resolves to `storage/app/public` — otherwise uploaded logos will save successfully server-side but return 404 when the browser requests them at `/storage/device-stencils/{id}/logo.{ext}`. See "User Setup Required" above.

Test file (`tests/Feature/Drawings/DeviceStencilLogoUploadTest.php`) is not required on live — local/CI test suite only.

## Self-Check: PASSED

All 5 `key-files` (2 created, 3 modified) verified present on disk. Both task commit hashes (`a0670c4`, `38aecb6`) verified present in `git log`. `php artisan test --filter=DeviceStencilLogoUploadTest` — 9/9 passed. Broader `tests/Feature/Drawings` regression run — 235 passed, 2 failed (both the pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md`, predating Phase 24, out of scope), 2 skipped (D2 binary unavailable in this environment, unrelated).

---
*Phase: 24-stencil-curation-ui-quote-import-auto-stub*
*Completed: 2026-08-14*
