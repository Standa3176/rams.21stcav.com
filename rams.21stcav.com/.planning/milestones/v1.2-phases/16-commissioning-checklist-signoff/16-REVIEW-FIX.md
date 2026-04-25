---
phase: 16-commissioning-checklist-signoff
fixed_at: 2026-04-22T00:00:00Z
review_path: .planning/phases/16-commissioning-checklist-signoff/16-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 16: Code Review Fix Report

**Fixed at:** 2026-04-22T00:00:00Z
**Source review:** .planning/phases/16-commissioning-checklist-signoff/16-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 4 (Critical + Warning only; 6 Info findings excluded per scope)
- Fixed: 4
- Skipped: 0

## Fixed Issues

### WR-01: Preview snagging PDF URL cannot be served — `downloadSnagging` has no preview branch

**Files modified:** `app/Http/Controllers/CommissioningSignoffController.php`, `routes/web.php`
**Commit:** c37326e
**Applied fix:** Added a dedicated `commissioning.snagging.preview` route bound to a new `streamPreview()` controller action. The route regex pins `{file}` to the `snagging_programme_{id}_{ts}_preview.pdf` naming convention, and the controller additionally asserts `str_starts_with(snagging_programme_{programme->id}_)` as defence-in-depth so a user with access to programme A cannot view a preview generated for programme B. `preview()` now builds the URL against the new route name instead of the finalise-only `commissioning.snagging.show` route, which was returning 404 before a signoff existed (the core D-10 "review before sign" journey was broken). Dead `v=preview` query param removed from the JSON response as a consequence (IN-06 incidentally resolved).

### WR-02: `failWithEvidence` rollback path permanently destroys the previously attached evidence photo

**Files modified:** `app/Services/CommissioningPhotoService.php`
**Commit:** 62b46ba
**Applied fix:** Wrapped the old-photo `@unlink` in `DB::afterCommit(...)` so the destructive filesystem mutation only runs AFTER the caller's DB transaction has committed. Previously the old file was wiped inline inside the transaction, so if `$item->save()` then threw the rollback left `evidence_photo_path` pointing at a file that had already been deleted out-of-band. `DB::afterCommit` queues on the active transaction when one exists (atomic failWithEvidence path) and runs immediately when no transaction is active (plain storePhoto path) — both code paths are covered. Added `Illuminate\Support\Facades\DB` use statement.

### WR-03: Signature PNG payload has no upper-bound — DoS / log-spam risk

**Files modified:** `app/Http/Requests/FinaliseCommissioningSignoffRequest.php`
**Commit:** 1ad06db
**Applied fix:** Added `max:5242880` (5 MB base64, ~3.7 MB decoded PNG) to the `signature_png_base64` rule. Per the migration docblock, real iPad Retina signatures are 30-60 KB, so this cap is comfortably above the realistic ceiling while protecting the host from a single-request memory blow-up when the payload is base64_decoded + preg_replaced + inlined as a data URI on every snagging PDF render.

### WR-04: Notes handling diverges between the two fail paths — data loss through the atomic endpoint

**Files modified:** `app/Http/Controllers/CommissioningItemController.php`
**Commit:** 7848904
**Applied fix:** Changed `failWithEvidence` notes handling from overwrite (`$item->notes = $note;`) to the same append logic used by `updateStatus` in the two-step W-10 path: preserves any pre-existing `$item->notes`, appends the fail reason with a `\n\n[Fail reason] ` separator, and falls through to the plain note when the existing notes are empty. Since the Alpine factory routes all fail clicks through the atomic `/fail-with-evidence` endpoint, this closes the silent-data-loss path where engineers lost any context notes they had typed before tapping Fail.

---

_Fixed: 2026-04-22T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
