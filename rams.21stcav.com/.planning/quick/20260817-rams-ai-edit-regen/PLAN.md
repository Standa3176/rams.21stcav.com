---
quick_id: 260817-jsg
slug: rams-ai-edit-regen
date: 2026-08-17
status: planned
---

# Quick Task 260817-jsg — AI chat edits never reach the RAMS document

## User report

> "when i use ai chat and apply change, those changes are not applied when i regen. after change can a popup ask if i want to regen with ai change and yes means a new updated rams creates. at the moment i cannot get the app to create with the ai chat items"

The AI chat edit feature is effectively unusable end-to-end: a PM applies a change, the confirmation succeeds, and the downloadable document is unchanged.

## Diagnosis (traced, not inferred)

### Cause A — artifact regeneration is deliberately deferred

`app/Services/DocumentEdits/Adapters/RamsEditAdapter.php:10-11`:

> *"Pass-C RAMS edit adapter — data-only mutations against generated_data / reviewed_data. No direct DOCX edits. **Artifact regen is explicitly deferred.**"*

The edit persists to the database correctly. Nothing rebuilds the DOCX/PDF, so the artifact on disk still reflects the pre-edit state. **The data is right; the document is stale.** That is the whole of the user's symptom.

### Cause B — one operation is destroyed by regeneration

`app/Services/RamsBuilderService.php`:
- line 22 / 65 — `buildFromReview()` takes **`reviewed_data` exclusively** as its input
- line 281 — it **overwrites `generated_data`** wholesale

Write-target audit of the 8 adapter operations:

| Operation | Writes to | Survives regen? |
|---|---|---|
| `add_method_statement_note` (181) | `reviewed_data` | ✅ |
| `set_method_statement_notes` (200) | `reviewed_data` | ✅ |
| `add_exclusion` (225) | `reviewed_data` | ✅ |
| `remove_exclusion` (238) | `reviewed_data` | ✅ |
| `add_client_resp` (251) | `reviewed_data` | ✅ |
| `remove_client_resp` (268) | `reviewed_data` | ✅ |
| `remove_room` (292) | `reviewed_data` | ✅ |
| **`update_project_field` (160-161)** | **`generated_data`** | ❌ **destroyed** |

**Fixing Cause A without Cause B would make things worse**: today `update_project_field` produces a stale document; with a regen prompt added it would silently *lose the edit entirely*. B must land first or alongside.

Note the adapter's own comment at line 165 says regen "re-reads form_data" — that is itself inaccurate (it reads `reviewed_data`), though its conclusion about `generated_data` being discarded is correct. Fix the comment while there.

## Tasks

### Task 1 — Route `update_project_field` to a regen-durable location

**File:** `app/Services/DocumentEdits/Adapters/RamsEditAdapter.php` (~145-170)

**Action:** Change `applyUpdateProjectField()` so the edited value lands where `buildFromReview()` will read it — i.e. `reviewed_data`, consistent with the other seven operations. Keep writing the mirrored value into `generated_data` if that is what the current on-screen view reads, so the change still shows immediately — but `reviewed_data` must be the durable source.

Correct the stale `form_data` comment at line 165 to say `reviewed_data`.

**Acceptance criteria:**
- Applying `update_project_field`, then running a full regen, leaves the edited value present in the rebuilt `generated_data`
- A test asserts exactly that round-trip (apply → regen → value still correct). This is the regression guard; without it the bug silently returns.
- The other seven operations are unchanged in behaviour

### Task 2 — Offer regeneration immediately after an edit is applied

**Files:** the RAMS review/chat UI and its controller (locate the AI-chat apply endpoint first — start from `DocumentEditAdapterRegistry` callers)

**Action:** After a change set applies successfully, prompt the user: the document has changed and the PDF/DOCX is now out of date — regenerate it now? Confirming dispatches the existing RAMS build job (`BuildRamsDocumentJob`) and produces a new revision; declining leaves the data edited and the artifact stale.

Reuse existing patterns rather than inventing:
- The codebase already has a **stale-document** concept — `RamsDocument::isStale()` and the `<x-stale-banner>` component (from the 2026-07-09 Batch 11 work). If declining regeneration marks the document stale, the existing banner surfaces it for free and the PM cannot forget.
- Follow the established async-feedback conventions for a generating document (status pill, "taking longer than expected" handling).

**Do NOT** regenerate automatically without asking. Regeneration re-runs AI generation, costs credits, and produces a new revision — that must stay the user's explicit choice, which is what they asked for.

**Acceptance criteria:**
- Applying an AI chat change surfaces a regenerate prompt
- Confirming produces a rebuilt document containing the change
- Declining leaves data edited, artifact untouched, and the document visibly marked stale
- No automatic regeneration occurs without confirmation

### Task 3 — End-to-end proof

**Action:** A feature test covering the exact user journey: apply an AI chat edit → regenerate → assert the change is present in the rebuilt `generated_data`. Cover at least `update_project_field` (the Cause-B operation) and one `reviewed_data` operation such as `add_exclusion`.

**Acceptance criteria:** test fails if Task 1 is reverted.

## Constraints

- No migration unless Task 2 genuinely requires a stale flag that does not already exist — check `RamsDocument::isStale()` first, which is computed rather than stored.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Blade + Alpine for any UI. If touching an Alpine component, the `$root`-not-`$el` rule from 260815-ohw applies.
- Local-edit-then-upload (Phase 21 D-13) → `php artisan optimize:clear` after upload.
- Do not re-run AI generation during tests — mock it.

## Out of scope (separate task)

The RAMS generator content defects found in the Rev 1.0 review — duplicate "Associated Risks" lines, the equipment-schedule fallback bucket, podium contradiction, product-identifier fidelity in the method-statement prompt, and `config/rams_tier1.php` hazard-library changes. Those are queued separately; this task is solely about AI chat edits reaching the document.
