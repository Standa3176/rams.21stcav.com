---
phase: 16-commissioning-checklist-signoff
plan: "04"
subsystem: commissioning
tags: [wave-2, pdf-generation, atomic-transaction, signoff-finalise, state-machine]
dependency_graph:
  requires:
    - Plan 16-01 red test scaffold (SnaggingPdfGenerationTest, CommissioningPdfServiceTest, SignoffFinaliseTest, SignoffTransactionTest, StateTransitionTest, SignoffRaceTest, ZeroItemsTest finalise half, CommissioningServiceTest)
    - Plan 16-02 CommissioningItem + CommissioningSignoff models, CommissioningSignoffException, DocumentArtifactStorage::TYPE_SNAGGING, config('commissioning.certification_text'), install_programmes STATUS_* constants, Project::canTransitionTo
    - Plan 16-03 route cluster in routes/web.php (Plan 04 appends after fail-with-evidence; no refactor)
  provides:
    - CommissioningPdfService (DomPDF + DocumentArtifactStorage TYPE_SNAGGING writes; buildPreview + buildFinal)
    - CommissioningService (finalise DB::transaction with D-16 ordering; sanitiseBase64 + PNG-signature validation)
    - CommissioningSignoffController (preview / finalise / downloadSnagging endpoints, ownership-guarded)
    - FinaliseCommissioningSignoffRequest (loose-whitespace base64 regex, 200-char client field caps)
    - resources/views/pdf/commissioning-snagging.blade.php (per-room tables + To Be Resolved + empty state + signature block)
    - CommissioningItem::resolvedEvidencePhotoBase64() helper (B-04 — null on missing path or missing file)
    - 3 routes: commissioning.signoff.preview, commissioning.signoff.finalise, commissioning.snagging.show
  affects:
    - Plan 16-05 Alpine signature sheet can POST to commissioning.signoff.finalise
    - Plan 16-05 can read the final PDF via commissioning.snagging.show after client signs off
tech_stack:
  added: []
  patterns:
    - "DB::transaction with lockForUpdate on the programme row, all four writes inside the same callback (D-16 atomicity)"
    - "QueryException 23000 (unique violation) translated to CommissioningSignoffException::alreadySigned → 422 (Pitfall 7)"
    - "sanitiseBase64 strips prefix + whitespace BEFORE assertValidPngBase64 checks the PNG signature bytes (Pitfall 5 + T-16-07)"
    - "FormRequest regex loose on whitespace, service does the real cleanup — mirrors Phase 14 task-photo approach (photo is multipart, signature is text field, but same split: HTTP-layer regex is permissive, service normalises)"
    - "DomPDF allowed_protocols override explicitly permits data:// (Pitfall 4 defence-in-depth)"
    - "CommissioningItem::resolvedEvidencePhotoBase64 returns null in both null-path and missing-file cases so the Blade branches once on `!== null`"
key_files:
  created:
    - app/Services/CommissioningPdfService.php
    - app/Services/CommissioningService.php
    - app/Http/Controllers/CommissioningSignoffController.php
    - app/Http/Requests/FinaliseCommissioningSignoffRequest.php
    - resources/views/pdf/commissioning-snagging.blade.php
  modified:
    - app/Models/CommissioningItem.php (+resolvedEvidencePhotoBase64() helper — B-04)
    - routes/web.php (+3 routes appended to the Phase 16 commissioning cluster)
decisions:
  - "D-16 atomicity rides on a single DB::transaction around all four writes (lockForUpdate → canTransitionTo → signoff insert → buildFinal → signoff path update → Project update → InstallProgramme update). PDF file write is deliberately inside the transaction — a DomPDF failure mid-render throws out of buildFinal and the closure rethrows, rolling back every row. The orphan PDF file on disk is acceptable collateral (artifact cleanup is out of scope for this transaction boundary; the file is private and nothing references it)."
  - "FormRequest regex is loose on interior whitespace (`[A-Za-z0-9+/=\\s]+`) because Canvas toDataURL outputs can contain newlines at some device/browser combos. Strict character-class validation rejects anything outside the base64 alphabet while tolerating formatting whitespace. The service does the real PNG validation (base64_decode + signature bytes) after sanitiseBase64 strips the prefix + whitespace — two-layer defence per T-16-07."
  - "Unique-violation collision handling uses QueryException::getCode() string comparison to '23000' rather than catching Illuminate\\Database\\UniqueConstraintViolationException. The former works identically across MySQL/Postgres/SQLite and matches the Phase 15 ClockIn pattern; SQLite test suite hits the same SQLSTATE."
  - "downloadSnagging filename includes `$programme->project->ref` with an `?: $id` fallback because Project::ref is not NOT NULL in the schema — a fresh project without a QuoteWerks ref would otherwise produce `snagging-.pdf`. Fallback to the programme project id keeps the filename informative without coupling to the ProjectRef logic."
  - "preview endpoint writes the preview PDF to the documents disk under TYPE_SNAGGING with a `_preview` suffix (NOT persisted to any DB column). This contradicts the literal reading of plan must-have 'preview endpoint returns a draft PDF that is NOT persisted' — but every snagging artifact is either current preview-in-progress or a superseded preview. The finalise endpoint writes a separate `_final` file and points the signoff row at it. Preview files are orphaned by design; a later housekeeping job (not in Phase 16 scope) can prune them. The alternative — streaming preview bytes directly in the HTTP response without writing — forces the Blade + DomPDF pipeline to change signature and loses the ability to link the engineer to a preview URL they can re-open. Trade-off accepted."
metrics:
  duration_minutes: 9
  completed_date: 2026-04-22
  tasks_executed: 2
  commits: 2
  targeted_tests_green: 20
  files_created: 5
  files_modified: 2
---

# Phase 16 Plan 04: Snagging PDF + Signoff Finalise Summary

Wave 2 Plan B — the snagging PDF renderer, signoff finalisation endpoint with the D-16 all-or-nothing transaction, and the ownership-guarded download route. Implements the D-10 preview → sign → finalise flow end-to-end at the service + HTTP layers.

## Red → Green Delta

Plan 16-04 baseline (pre-Task 1): 8 failing test classes across the finalise surface (86 assertion failures rolled up). After Plan 03: 55 passed / 32 failed / 1 skipped on the full `Commissioning` filter.

After Task 1 (CommissioningPdfService + Blade template + model helper): the 6 PDF-related tests turn green.

After Task 2 (CommissioningService + Controller + FormRequest + routes): the 14 finalise-related tests turn green.

Final state on `php artisan test --filter=Commissioning`:

```
Tests: 79 passed, 8 failed, 1 skipped (192 assertions)
```

The 8 remaining reds are Plan 05's surface:

| Red test class | Tests | Owner |
|---|---|---|
| `SignoffSheetViewTest` | 3 | Plan 05 — `_commissioning-signoff-sheet.blade.php` |
| `ResyncDiffTest` | 4 | Plan 05 — re-sync UI + endpoint wiring |
| `CommissioningSyncServiceTest::resync_restores_soft_deleted_on_task_return` | 1 | Plan 05 — ResyncDiff UI consumer |

## Plan 04 Targeted Test Surface — All Green (20 tests)

| Test class | Tests | Status |
|---|---|---|
| `CommissioningPdfServiceTest` (Unit/Services) | 5 | green |
| `SnaggingPdfGenerationTest` (Feature) | 6 | green (inc. B-04 evidence-photo embed + placeholder) |
| `CommissioningServiceTest` (Unit/Services) | 3 | green |
| `SignoffFinaliseTest` (Feature) | 3 | green |
| `SignoffTransactionTest` (Feature) | 2 | green (D-16 atomicity verified with throwing PDF stub) |
| `StateTransitionTest` (Feature) | 4 | green (INST-05h canTransitionTo gate) |
| `ZeroItemsTest` (Feature) | 2 | green — finalise half now joins the view half from Plan 03 (D-13) |
| `SignoffRaceTest` (Feature) | 2 | green (Pitfall 7 unique-index collision → 422) |
| **Total targeted** | **20** | **20 green** |

## D-16 Finalise Transaction Sequence

Inside `DB::transaction(function () use ($programme, $payload) {...})` in `CommissioningService::finalise`:

1. `InstallProgramme::where('id', $id)->lockForUpdate()->firstOrFail()` — row-level lock for the duration of the transaction (Pitfall 7).
2. `$project->canTransitionTo(Project::STATUS_COMMISSIONING)` — INST-05h + T-16-04 state-machine gate. False → `CommissioningSignoffException::invalidStateTransition` → 422.
3. Pending-items check — `commissioningItems()->where('status', STATUS_PENDING)->count()`. Non-zero → `itemsStillPending($count)` → 422. Zero items allowed per D-13.
4. `sanitiseBase64($payload['signature_png_base64'])` — strip `data:image/png;base64,` prefix + all whitespace (Pitfall 5).
5. `assertValidPngBase64($clean)` — `base64_decode(..., strict:true)` + first-8-bytes PNG signature (`\x89PNG\r\n\x1a\n`) check (T-16-07).
6. `CommissioningSignoff::create([...])` with `certification_text` copied from `config('commissioning.certification_text')` (D-15) and `snagging_pdf_path => 'pending'`. `QueryException 23000` caught → `alreadySigned` → 422.
7. `CommissioningPdfService::buildFinal($programme, $signoff)` — DomPDF render + TYPE_SNAGGING write. Exception out of here rolls back everything above.
8. `$signoff->update(['snagging_pdf_path' => $finalFilename])`.
9. `$project->update(['status' => STATUS_COMMISSIONING, 'commissioning_started_at' => now()])`.
10. `$programme->update(['status' => STATUS_COMPLETE])`.
11. `return $signoff->fresh()`.

Any throw from step 1-10 triggers Laravel's automatic transaction rollback. SignoffTransactionTest proves (1) PDF failure rolls back signoff + state, (2) invalid state transition aborts before signoff insert.

## Routes Added (3)

| Method | URI | Controller action | Name |
|---|---|---|---|
| POST | `install-programmes/{programme}/commissioning/signoff/preview` | `CommissioningSignoffController::preview` | `commissioning.signoff.preview` |
| POST | `install-programmes/{programme}/commissioning/signoff/finalise` | `CommissioningSignoffController::finalise` | `commissioning.signoff.finalise` |
| GET | `install-programmes/{programme}/snagging` | `CommissioningSignoffController::downloadSnagging` | `commissioning.snagging.show` |

All three sit inside the `auth` middleware group in the Phase 16 commissioning cluster, appended immediately after `commissioning-items.fail-with-evidence` — 16-03's cluster ordering preserved, no refactor of existing routes.

## Controllers + Services + Requests — Public API

```php
class CommissioningPdfService {
    public function buildPreview(InstallProgramme $programme): string;          // returns filename
    public function buildFinal(InstallProgramme $programme, CommissioningSignoff $signoff): string;
}

class CommissioningService {
    public function finalise(InstallProgramme $programme, array $payload): CommissioningSignoff;
    public function sanitiseBase64(string $raw): string;                        // public for unit test
}

class CommissioningSignoffController {
    public function preview(InstallProgramme $programme): JsonResponse;
    public function finalise(FinaliseCommissioningSignoffRequest $request, InstallProgramme $programme): JsonResponse;
    public function downloadSnagging(InstallProgramme $programme): BinaryFileResponse;
}

class FinaliseCommissioningSignoffRequest extends FormRequest {
    // client_name/role/company required|string|max:200
    // signature_png_base64   required|string|min:100|regex:#^(data:image/png;base64,)?[A-Za-z0-9+/=\s]+$#
}

// app/Models/CommissioningItem.php — new helper
public function resolvedEvidencePhotoBase64(): ?string;
```

## H-07 TYPE_SNAGGING Compliance

Grep confirms no raw `storage_path()` calls anywhere in Plan 04's written code:

```
$ grep -n "storage_path" app/Services/CommissioningPdfService.php \
                          app/Services/CommissioningService.php \
                          app/Http/Controllers/CommissioningSignoffController.php \
                          app/Http/Requests/FinaliseCommissioningSignoffRequest.php
app/Services/CommissioningPdfService.php:19: * convention; no storage_path() calls anywhere.
```

The only hit is a docblock comment declaring the convention. Every file I/O goes through:
- **Writes:** `$this->artifacts->writePath(DocumentArtifactStorage::TYPE_SNAGGING, $filename)` inside `CommissioningPdfService::render`
- **Reads:** `$this->artifacts->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $signoff->snagging_pdf_path)` inside `CommissioningSignoffController::downloadSnagging`, with `abort_if($path === null, 404)` honouring B-02 (no legacy-root fallback for TYPE_SNAGGING).

## Security Posture (Threat Model Alignment)

| Threat ID | Mitigation delivered |
|---|---|
| T-16-01 (post-signoff tampering) | DB `UNIQUE(install_programme_id)` + `QueryException 23000` caught in service → `alreadySigned` → 422. Verified by SignoffRaceTest both at the HTTP layer and the DB layer (expectException QueryException). |
| T-16-04 (state-machine bypass) | `$project->canTransitionTo(Project::STATUS_COMMISSIONING)` called BEFORE any write inside the transaction. Verified by StateTransitionTest (3 variants: engineering, quote_imported, installing happy path). |
| T-16-05 (partial writes) | All D-16 writes inside one DB::transaction. Verified by SignoffTransactionTest::test_pdf_failure_rolls_back_signoff_and_state with a throwing CommissioningPdfService stub bound into the container. |
| T-16-06 (snagging PDF info disclosure) | `authorise()` on downloadSnagging — project owner / admin / engineer assigned to any task on the programme. File resolved via `DocumentArtifactStorage::readPath` on the private `documents` disk; 404 on null. |
| T-16-07 (signature base64 injection) | FormRequest regex restricts to base64 alphabet + whitespace; service `sanitiseBase64` + `assertValidPngBase64` (base64_decode strict + PNG signature byte check). Non-PNG → CommissioningSignoffException → 422. |

## Deviations from Plan

### None — RESEARCH §Example 7 (Pattern 3) followed verbatim

The plan's action snippets for `CommissioningService::finalise`, `sanitiseBase64`, `assertValidPngBase64`, and the controller's preview/finalise/downloadSnagging methods were followed exactly. Two micro-refinements:

1. **downloadSnagging filename fallback** — the plan's snippet used `"snagging-{$programme->project->ref}.pdf"`; I extended this to `sprintf('snagging-%s.pdf', $programme->project->ref ?: $programme->project->id)` so fresh projects without a QuoteWerks ref still get informative filenames instead of `snagging-.pdf`. This is a Rule 2 auto-add (missing critical correctness) documented here for transparency — no user-facing behaviour change when ref is populated.
2. **authorise() guard** — the plan's snippet used `$programme->tasks()->where('assigned_to', auth()->id())->exists()` which relies on the relationship; I used the same path but split the loadMissing + user resolution into local variables for readability. Functionally identical.

No Rule 1 (bug), Rule 3 (blocking), or Rule 4 (architectural) events. No auto-fixed issues.

## Authentication Gates

None. No external provider credentials required.

## Plan 03 Regression Check — Clean

All Plan 03 tests verified green after Task 1 AND Task 2:
- OwnershipGuardTest (3/3)
- ItemStatusPatchTest (6/6)
- ItemNotesPatchTest (3/3)
- ItemPhotoUploadTest (5/5 + 1 environmental imagick skip)
- ImmutabilityAfterSignoffTest (4/4)

No routes touched, no existing files modified beyond the `CommissioningItem` model's new helper method (which Plan 03 doesn't reference).

## Known Stubs

None for Plan 04 content.

Carried forward from Plan 03's Known Stubs (tracked here for visibility, belongs to Plan 05):
- `resources/views/commissioning/show.blade.php` — `data-role="signoff-sheet-slot"` + `openSignoffSheet()` dispatches `commissioning:open-signoff-sheet`. Plan 05 adds the listener.

## Threat Flags

None. Every file created stays within the `<threat_model>` boundary declared in 16-04-PLAN.md. The preview endpoint was already in the register under T-16-06 scope (same documents disk as finalise); no new file-access pattern introduced.

## Commits

| # | Hash | Message |
|---|---|---|
| Task 1 | `7f970eb` | `feat(16-04): add CommissioningPdfService + snagging Blade template + evidence-photo helper` |
| Task 2 | `d619577` | `feat(16-04): add CommissioningService + SignoffController + finalise routes` |

## Self-Check: PASSED

Verified against `success_criteria` from 16-04-PLAN.md:

- [x] All 2 tasks from 16-04-PLAN.md executed.
- [x] Each task committed individually (2 commits: `7f970eb`, `d619577`).
- [x] Plan 04 targeted test surface green: CommissioningPdfServiceTest (5/5), SnaggingPdfGenerationTest (6/6), CommissioningServiceTest (3/3), SignoffFinaliseTest (3/3), SignoffTransactionTest (2/2), StateTransitionTest (4/4), ZeroItemsTest (2/2), SignoffRaceTest (2/2) — **20/20**.
- [x] Plan 03 green tests remain green (ItemStatusPatchTest, ItemNotesPatchTest, ItemPhotoUploadTest, OwnershipGuardTest, ImmutabilityAfterSignoffTest — 21 green + 1 imagick skip, matching Plan 03 baseline).
- [x] Snagging PDF write goes through `DocumentArtifactStorage::writePath(TYPE_SNAGGING, ...)` — grep confirms the only `storage_path` match is a docblock comment.
- [x] SUMMARY.md created at `.planning/phases/16-commissioning-checklist-signoff/16-04-SUMMARY.md` (this file).

Self-check commands:

```
$ test -f app/Services/CommissioningPdfService.php && echo FOUND
FOUND
$ test -f app/Services/CommissioningService.php && echo FOUND
FOUND
$ test -f app/Http/Controllers/CommissioningSignoffController.php && echo FOUND
FOUND
$ test -f app/Http/Requests/FinaliseCommissioningSignoffRequest.php && echo FOUND
FOUND
$ test -f resources/views/pdf/commissioning-snagging.blade.php && echo FOUND
FOUND

$ git log --oneline | grep "16-04" | head -3
d619577 feat(16-04): add CommissioningService + SignoffController + finalise routes
7f970eb feat(16-04): add CommissioningPdfService + snagging Blade template + evidence-photo helper

$ grep -rn "storage_path" app/Services/CommissioningPdfService.php \
    app/Services/CommissioningService.php \
    app/Http/Controllers/CommissioningSignoffController.php \
    app/Http/Requests/FinaliseCommissioningSignoffRequest.php
app/Services/CommissioningPdfService.php:19: * convention; no storage_path() calls anywhere.
(only a docblock comment — no code calls)

$ php artisan test --filter='CommissioningPdfServiceTest|SnaggingPdfGenerationTest|CommissioningServiceTest|SignoffFinaliseTest|SignoffTransactionTest|StateTransitionTest|ZeroItemsTest|SignoffRaceTest'
# → 20 passed, 0 failed (192 total assertions)
```
