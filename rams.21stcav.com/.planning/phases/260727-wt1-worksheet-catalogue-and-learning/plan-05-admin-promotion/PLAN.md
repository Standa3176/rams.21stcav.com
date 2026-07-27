---
plan: plan-05-admin-promotion
status: pending
depends_on: plan-04
scope: Admin resource lists source='learned' rows with promote/correct/delete actions. Audit trail. STATE + SUMMARY + kill switch flipped on globally.
estimated: 0.5 day + 1 week soak
---

## Objective

Prevent a wrong PM guess from polluting future projects silently. Admin
periodically reviews learned mappings and promotes / corrects / deletes.

## Tasks

### Task 1 — Admin resource

Two paths depending on Filament state in the app:

**Option A (if Filament is already present):**
`app/Filament/Resources/ProductTaxonomyResource.php`:
- Table lists all rows with filters on `source`, `manufacturer`,
  `worksheet_category`.
- Row actions: **Promote** (source → 'admin', stamp promoted_by +
  promoted_at), **Correct** (edit category or description_pattern),
  **Delete** (soft delete — add `deleted_at` to migration if not
  already there).
- Default filter: `source='learned' AND promoted_at IS NULL` — the
  review queue.
- Bulk action: promote-selected.

**Option B (no Filament — likely case per rf3 audit):**
`resources/views/admin/product-taxonomy/index.blade.php` +
`ProductTaxonomyController` under `App\Http\Controllers\Admin`:
- Same functionality via plain Blade + Alpine.js — mirror the
  existing admin pages under `app/Http/Controllers/Admin/`.
- Route `Route::resource('admin/product-taxonomy', ProductTaxonomyController::class)` — admin-middleware gated.
- Nav link under Admin dropdown: "Product Taxonomy".

### Task 2 — Audit trail

Every promote / correct / delete writes an entry to `activity_log` (if
present) or a new `product_taxonomy_audits` table:
- action ('promoted', 'corrected', 'deleted')
- actor_id
- taxonomy_id
- before / after JSON diff
- timestamp

Enables the "who changed the classification of Crestron TSW-1070 from
control to video_conferencing last Tuesday" question.

### Task 3 — Kill switch flip

`.env.example` gains `WORKSHEET_TAXONOMY_DB=true`. Live `.env` gets
the flag flipped in the deploy step. Sanity checks:
1. Regenerate a known-good worksheet (Tilda 14) — output identical.
2. Regenerate a project with a novel Crestron SKU — classifies via
   learned rows from Plan 04.
3. Delete a learned row → next regen falls back to Unclassified —
   correct behaviour.

### Task 4 — Docs

`.planning/phases/260727-wt1.../HOW-TO-CHANGE-TAXONOMY.md`:
- "PM sees an Unclassified item in a worksheet" → 3-step review-page fix.
- "Admin wants to promote a learned mapping" → 2-step Admin path.
- "Admin wants to add a new device family without waiting for a PM
  to classify one" → direct DB insert via admin resource.
- "Devs want to change the 6 canonical worksheet categories" →
  requires migration + code change (rare).
- Link to `ProductTaxonomyRepository` + `LearnedTaxonomyWriter`.

### Task 5 — STATE + SUMMARY + kill switch removal task stub

- STATE row for phase completion.
- Phase-level SUMMARY.md.
- Stub quick task `.planning/quick/260803-wt1-remove-config-fallback/`
  with a note: after 1-week soak (2026-08-03), delete
  `ConfigTaxonomySource`, remove kill switch, demote
  `config/worksheet_taxonomy.php` to a comment-only reference doc.

## Constraints

- Admin actions are audited.
- Live worksheet output unchanged when flag is flipped (parity test
  from Plan 02 still passes).
- Existing tests green.

## Commits (target)

1. `feat(admin): product taxonomy admin resource (plan-05)`
2. `feat(admin): audit trail for taxonomy changes (plan-05)`
3. `feat(worksheet): flip WORKSHEET_TAXONOMY_DB default to true (plan-05)`
4. `docs(worksheet): HOW-TO-CHANGE-TAXONOMY + phase SUMMARY + STATE row (plan-05)`

## Deliverable check

At plan close:
- Admin can list, promote, correct, delete learned rows.
- Every action leaves an audit trail row.
- Flag flipped on live; worksheet outputs stable.
- Stub follow-up task exists for post-soak cleanup.

## Post-phase (deferred)

- **After 1-week live soak**: quick task
  `260803-wt1-remove-config-fallback`.
- Delete `ConfigTaxonomySource`.
- Remove kill switch config + branch.
- Demote `config/worksheet_taxonomy.php` to a documentation-only
  reference (or delete entirely if seed data is committed).
