---
quick_id: 260817-jsg
slug: rams-ai-edit-regen
date: 2026-08-17
status: complete
---

# Quick Task 260817-jsg — AI chat edits never reach the RAMS document — Summary

## What shipped

AI-chat edits to a RAMS now (a) reliably survive a regenerate, and (b) actually
trigger a regenerate — with the user's explicit consent, never automatically.
The two causes diagnosed in PLAN.md were fixed in the mandatory order: Task 1
(data durability) landed before Task 2 (the regen prompt), so the prompt never
had a window where confirming would silently destroy the user's edit.

## Tasks completed

**Task 1 — Route `update_project_field` to a regen-durable location**
(`app/Services/DocumentEdits/Adapters/RamsEditAdapter.php`, `app/Services/RamsBuilderService.php`)

- `RamsBuilderService::buildFromReview()` overwrites `generated_data` wholesale
  from `reviewed_data` on every regen. `update_project_field` was the only one
  of the 8 chat operations that wrote exclusively to `generated_data`, so any
  chat edit to a project field (name, ref, client, dates, PM, lead engineer,
  programmer, site contact, working hours, etc.) was silently discarded on the
  next regenerate. The other 7 operations already wrote to `reviewed_data` and
  were unaffected — confirmed by the write-target audit in PLAN.md and by the
  fact that `add_exclusion` needed no change here.
- Fix: `applyUpdateProjectField()` now mirrors the edit into
  `reviewed_data.project_field_overrides[$field]` (in addition to the existing
  immediate `generated_data` write, kept so the on-screen diff preview still
  shows the change right away). `RamsBuilderService::runFromReview()` re-applies
  that override map onto the freshly-assembled `project` block, **last** —
  after the compliance-upgrade and tier-1-defaults passes — so nothing built
  earlier in the pipeline can clobber a value the user explicitly set via chat.
- Corrected the stale doc comment that claimed regen "re-reads form_data" — it
  actually re-reads `reviewed_data`; that inaccuracy is what let this bug hide.

**Task 2 — Offer regeneration immediately after an edit is applied**
(`app/Services/DocumentEdits/Adapters/RamsEditAdapter.php`,
`app/Services/RamsBuilderService.php`, `app/Models/RamsDocument.php`,
`resources/views/components/document-edit-drawer.blade.php`)

- `RamsEditAdapter::commitChanges()` stamps `reviewed_data._pending_regen_since`
  on the **first** unregenerated chat edit (subsequent edits before a regen
  don't reset the clock). `RamsBuilderService::runFromReview()` clears the flag
  once a build **fully succeeds** (after DOCX render, not before — a failed
  build must keep the document marked stale).
- Extended `RamsDocument::isStale()` / `staleSince()` with that flag. This
  reuses the existing computed staleness concept from Batch 11 (no migration —
  confirmed `generated_data.generated_at`, the pre-existing signal's source
  field, is never actually populated anywhere in the RAMS pipeline today, so
  the pre-existing package/survey-drift check was effectively dead for RAMS;
  the new flag is checked first and doesn't depend on that field). The
  **already-mounted** `<x-stale-banner>` on `resources/views/rams/review.blade.php`
  now surfaces "declined regen" for free — no new UI component was built.
- The chat drawer (`document-edit-drawer.blade.php`, shared across
  rams/worksheet/om/survey/cable) now checks, RAMS-only, whether the apply
  response's `artifact_filename` is null (meaning regen was deferred). If so,
  it no longer claims "Applied — regenerating" (a lie for RAMS) and instead
  calls `window.appConfirm()` — the **same** confirm helper the manual
  "Save changes" flow on the RAMS review page already uses — asking whether to
  regenerate now. Confirming submits the **same** pre-existing hidden
  `#rams-regen-after-save` form (posts to `rams.regenerate` →
  `BuildRamsDocumentJob`, creates a new revision, supersedes the old
  `RamsDocument`). Declining leaves the data edited and the artifact
  untouched; the stale banner keeps reminding the user. Worksheet/O&M/Cable
  (which already rebuild synchronously) and Survey (no artifact at all) are
  untouched — the branch only fires for `documentType === 'rams'`.
- Regeneration is never automatic. No code path calls `regenForm.submit()`
  without a prior `appConfirm()` resolving `true`.

**Task 3 — End-to-end proof**
(`tests/Feature/DocumentEdits/RamsEditAdapterRegenRoundTripTest.php`, new file)

- `test_update_project_field_survives_full_regen`: applies a chat edit via
  `RamsEditAdapter`, runs the **real** `BuildRamsDocumentJob::handle()`
  (AI mocked via `Http::fake()`, no live calls), and asserts the edited value
  is present in the **rebuilt** `generated_data`. Also asserts the stale flag
  is set immediately after apply and cleared after a successful regen.
- `test_add_exclusion_survives_full_regen`: same round trip for a
  `reviewed_data`-only op, asserted against `reviewed_data.exclusions` — not
  `generated_data`. `generated_data` never carries an `exclusions` key at all
  (confirmed by grepping `RamsBuilderService.php` / `RamsDataBuilderService.php`
  — no hits); `ExclusionsComposer` reads `reviewed_data.exclusions` directly at
  render time. Asserting the "already durable" op against `generated_data`
  would only have proven a location the app never reads.
- **Regression guard verified directly**, not just claimed: `git stash push`
  on the two Task 1 files, re-ran the suite — `test_update_project_field_survives_full_regen`
  failed with `chat edit was not mirrored into reviewed_data.project_field_overrides`
  — then `git stash pop` restored the fix and both tests passed again.

## Deviations from Plan

**1. [Rule 1 — pre-existing dead staleness check]** `RamsDocument::isStale()`'s
original package/survey-drift signal reads `generated_data.generated_at`, which
is never written anywhere in the RAMS build pipeline (`RamsBuilderService.php` /
`RamsDataBuilderService.php` — confirmed by grep). That branch has effectively
always returned `false` for real RAMS documents. Not fixed (out of scope — a
pre-existing, unrelated bug), but the new `_pending_regen_since` check was
placed *before* it and does not depend on it, so Task 2's staleness signal
works regardless. Documented rather than silently worked around, per Batch 11
UX-09 context.

**2. [Scope note, not a deviation]** The plan's Task 1 file list named only
`RamsEditAdapter.php`; fixing the durability bug also required a matching
re-apply step in `RamsBuilderService.php` (the file the diagnosis itself
identifies as Cause B, lines 22/65/281). Both files are the minimal change
needed for the fix to actually work — an adapter-only fix would write data
nothing ever reads back.

No other deviations. The 7 non-`update_project_field` operations were left
unchanged, as instructed.

## Browser verification

**NOT PERFORMED.** No browser/Playwright tool was available in this session
(only Read/Write/Edit/Bash/Grep/Glob). The confirm-dialog appearing after
an apply, and the regenerate happening on confirm, have **not** been visually
verified in a real browser — a structural/JS-syntax check cannot prove an
Alpine confirmation flow actually works end-to-end in the DOM. A human should
verify at `/rams/{id}/review?chat=1` on a completed RAMS: apply a chat edit,
confirm the "Regenerate RAMS?" prompt appears, confirm accepting it navigates
to the project page with a queued regeneration, and confirm declining leaves
the amber stale banner visible on a page reload.

## Verification

- Lint: `php -l` clean on all touched PHP files. `document-edit-drawer.blade.php`
  additionally compiled through Laravel's Blade compiler and PHP-linted the
  compiled output — this caught a real bug (see below) that plain `php -l`
  on the blade source could not have caught (blade files without embedded
  `<?php ?>` tags trivially pass `php -l` regardless of Blade-directive errors).
- **Bug found and fixed during Task 2, before commit:** a JS comment in the new
  `_promptRamsRegen()` helper referenced `<x-stale-banner>` in prose — Blade's
  compiler doesn't know about JS comments and parsed it as a real component
  tag, breaking compilation of the *entire* file (`unexpected end of file,
  expecting endif`) for every document type using the shared drawer
  (rams/worksheet/om/survey/cable), not just RAMS. Caught before commit via a
  `blade.compiler->compileString()` + `php -l` check; reworded the comment to
  avoid angle brackets.
- Tests: PHPUnit 11 (not Pest), `php artisan test --filter="Rams|DocumentEdit"`
  — **533 passed** (1973 assertions), 2 pre-existing PHPUnit-metadata
  deprecation warnings unrelated to this change. Before this task (531 tests,
  i.e. the same suite minus the 2 new tests added here) the suite was green;
  no regressions introduced.
- `php artisan test --filter="RamsEditAdapterRegenRoundTripTest|RamsEditAdapterTest|StaleDocsAfterSurveySubmitTest"` —
  13 passed (36 assertions), run again after the temporary revert/restore used
  to prove the regression guard, to confirm the final committed state is clean.
- No migration run — none required (see Deviation 1: the staleness signal is
  computed from the existing `reviewed_data` JSON column, not a new column).
- Full-repo `php artisan test` was **not** run, per constraints.

## Known Stubs

None.

## Threat Flags

None. No new endpoints, no new auth paths, no schema change. The change adds
a JSON key (`_pending_regen_since`, `project_field_overrides`) inside the
existing `reviewed_data` column on a model already gated by the existing
per-document authorization in `DocumentEditController::authorizeDocument()`,
and reuses an existing regenerate route (`rams.regenerate`) that was already
reachable from the same review page via a visible button.

## Self-Check: PASSED

Files verified to exist:
- `app/Services/DocumentEdits/Adapters/RamsEditAdapter.php` — FOUND
- `app/Services/RamsBuilderService.php` — FOUND
- `app/Models/RamsDocument.php` — FOUND
- `resources/views/components/document-edit-drawer.blade.php` — FOUND
- `tests/Feature/DocumentEdits/RamsEditAdapterRegenRoundTripTest.php` — FOUND

Commits verified in `git log --oneline`:
- `8e4acfb` fix(rams): make update_project_field chat edits survive regen — FOUND
- `a241aa4` feat(rams): prompt to regenerate after an AI-chat edit, mark stale until confirmed — FOUND
- `82174a9` test(rams): prove AI-chat edits survive a full RAMS regen — FOUND

## 🚨 Files to upload to live

- `app/Services/DocumentEdits/Adapters/RamsEditAdapter.php`
- `app/Services/RamsBuilderService.php`
- `app/Models/RamsDocument.php`
- `resources/views/components/document-edit-drawer.blade.php`

**No migration needed.** The staleness signal lives inside the existing
`reviewed_data` JSON column — `RamsDocument::isStale()` is computed, not
stored. After upload, run `php artisan optimize:clear` (Blade view cache must
be cleared — the drawer component's compiled view will otherwise keep serving
the old, pre-fix template).

Test file (`tests/Feature/DocumentEdits/RamsEditAdapterRegenRoundTripTest.php`)
does not need to ship to the live server — dev/CI only.

**Before/alongside deploy:** perform the browser verification that could not
be done in this session (see "Browser verification" above) — confirm the
regenerate prompt appears after an AI-chat edit and that confirming it
actually rebuilds the document.
