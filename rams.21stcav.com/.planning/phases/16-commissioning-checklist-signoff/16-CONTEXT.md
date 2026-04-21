# Phase 16: Commissioning Checklist & Sign-off — Context

**Gathered:** 2026-04-21
**Status:** Ready for planning
**Requirements:** INST-05, INST-05a, INST-05b, INST-05c, INST-05d, INST-05e, INST-05f, INST-05g, INST-05h, INST-05i
**Depends on:** Phase 14 (HEIC converter, bottom-sheet pattern, per-item AJAX precedent), Phase 12 (install_tasks as equipment source), Phase 15 (audit-log + UTC/London patterns)

<domain>
## Phase Boundary

Deliver a per-equipment commissioning checklist against AVIXA categories, captured on an engineer's phone/tablet, with photo evidence per item (reusing Phase 14's HEIC converter), a client digital signature taken in-person at the end of the programme, and a generated snagging PDF that embeds the signature. On signature, `Project.status` auto-advances `STATUS_INSTALLING` → `STATUS_COMMISSIONING` via the existing state machine at [Project.php:44-46](app/Models/Project.php#L44-L46).

**In scope this phase:**
1. `commissioning_items` table per INST-05a
2. Generator service: install_tasks × AVIXA categories via static `config/commissioning.php` mapping
3. Mobile-optimised checklist page with per-item AJAX saves (status, photo, notes)
4. HEIC-safe photo evidence (reuses `App\Services\HeicImageConverter` from Phase 14)
5. `creagia/laravel-sign-pad` client signature canvas (DPI-corrected for iOS Retina)
6. Snagging PDF (DomPDF pipeline) with embedded signature
7. State-machine transition on programme completion

**Not in scope this phase (deferred — see `<deferred>`):**
- Emailed remote client-signature link
- Engineer PIN challenge for per-item sign-off
- Post-signature re-open (INST-05i locks items immutable once signed)
- AI-assisted AVIXA mapping / equipment description
- Admin UI for editing the AVIXA mapping config
- Part/brand master-data quality enforcement (wrong-project comment)

</domain>

<decisions>
## Implementation Decisions

### Item Generation

- **D-01** — **Static PHP config** drives the equipment-type → AVIXA-category mapping. New file `config/commissioning.php` holds an array of `category => [keyword, keyword, ...]` pairs (e.g., `'display_quality' => ['display', 'monitor', 'projector', 'videowall']`). No DB admin surface, no AI generation. Edits require a deploy — matches the "AI never invents scope" constraint in PROJECT.md.
- **D-02** — **Per equipment instance** grain. If a site has three identical 75" displays, three separate "Display – Power On" items are generated (one per unit). Matches `install_tasks` 1:1 and preserves per-unit audit trail for year-2 warranty claims.
- **D-03** — **Generation trigger = programme complete**. Items are created automatically when the last `install_tasks` row for the programme hits `STATUS_COMPLETE`. The commissioning view is empty before that. This ties generation to the workflow moment where equipment reality is final.
- **D-04** — **Re-sync preserves statuses**. When equipment changes post-generation (engineer swaps / adds / removes hardware), an explicit "Re-sync from programme" button: preserves existing `pass`/`fail`/`na`/`pending` status for unchanged items, adds new items for new equipment, soft-deletes items for removed equipment (never hard-delete — audit trail). Engineer sees a diff summary before confirming.
- **D-05** — **Data source = `install_tasks` rows** (Phase 12's denormalised room × equipment). Never `ProjectDataService::resolve()` or `project_packages.equipment_list` — those miss engineer-side swaps during install. Commissioning reflects what got physically installed.
- **D-06** — **Keyword matching = case-insensitive substring** on `install_tasks.equipment_name`. The config maps each AVIXA category to a list of substrings; match = any substring appears (lowercased) in the lowercased equipment name. No regex, no prefix-match on part number.
- **D-07** — **Unmatched equipment generates no items** (skip). Generic hardware (`'rack'`, `'cable tray'`, `'wall plate'`) with no keyword hit produces zero commissioning items. Avoids forcing "Display Quality" onto a cable tray.
- **D-08** — **Exactly the 7 AVIXA categories** from INST-05e: Power On / Display Quality / Audio Level / VTC Connectivity / Control System / Network / Cabling. No extensions (no "End-user Training Completed" for v1). Revisit post-v1.2 if engineers request.

### Signature Flow

- **D-09** — **In-person capture only** on the engineer's device. `creagia/laravel-sign-pad` canvas rendered with explicit `devicePixelRatio` scaling per INST-05f (prevents iOS Retina signature corruption — documented signature_pad library issue). No emailed remote-signing link; the client must be on-site.
- **D-10** — **Timing = after snagging PDF preview**. Engineer taps "Complete Commissioning" → server generates a **preview** snagging PDF → engineer + client review it together on the device → client signs → signed signature is embedded and the **final** PDF is regenerated. Client explicitly acknowledges the document they're signing — strongest audit defensibility.
- **D-11** — **Client metadata captured = Name + Role + Company**. Three freetext fields on the signature screen (above the canvas). Typed by engineer or by the client before they sign. Stored on a new `commissioning_signoffs` table (one row per programme) with `client_name`, `client_role`, `client_company`, `signature_png_base64`, `signed_at`, `install_programme_id`. Company NOT inferred from `project.client_id` — capture what the signing person states.
- **D-12** — **Fail items do not block sign-off**. Any mix of `pass` / `fail` / `na` unlocks the "Complete Commissioning" button (per INST-05g). Failed items roll into the snagging PDF as "To Be Resolved". Client signs acknowledging the snag list. `Project.status` advances to `STATUS_COMMISSIONING` after signature. Standard AV-industry handover practice — don't let perfect block handover.

### Claude's Discretion

Planner decides the following during research/plan; flagged here so the user can overrule before `/gsd-plan-phase 16`:

- **Engineer per-item sign-off**: INST-05i requires `signed_off_by` (engineer name). Plan to auto-fill from `auth()->user()->name` at the moment of pass/fail/na action. No separate PIN challenge for v1 — the session auth already owns identity. Revisit if compliance asks for stronger attestation.
- **Signature storage**: base64 PNG on the `commissioning_signoffs` row (per INST-05f "signature stored as base64 PNG"). Not a file on disk — too small to warrant it, and keeps the signed artefact co-located with the signoff record.
- **Certification text above canvas**: minimal legal statement generated from config (e.g., *"I confirm the commissioning items above reflect the system state handed over to 21st Century AV's client on {date}. Outstanding items listed as 'To Be Resolved' are acknowledged."*). Planner refines wording; consider asking for a sign-off from Sonny before shipping.
- **Snagging PDF layout**: reuses the existing DomPDF PdfService pattern from RAMS / Site Survey. Sections: project header → per-room tables of items with pass/fail/na icons + thumbnails of evidence photos → snag summary (just the fails) → client signature block. Reuse the RAMS Blade template aesthetic for consistency.
- **Photo evidence policy**: optional for `pass` and `na`; **required for `fail`** (audit defensibility — "why did this fail"). Planner wires validation into the per-item AJAX save endpoint, not a client-side check.
- **Fail-reason note**: required freetext on `fail` (mirror Phase 14's blocked-reason sheet pattern — bottom-sheet UI). Stored on `commissioning_items.notes`.
- **Commissioning_item_audits table**: not needed for v1. INST-05i makes items immutable once signed; pre-signature edits don't need retro-audit since nothing downstream depends on the state yet. Skip the audit table.
- **Bottom-sheet reuse**: copy `resources/views/install-programmes/_field-sheet.blade.php` (Phase 14 blocked-reason) into `_commissioning-fail-sheet.blade.php` for fail-reason capture, and `_commissioning-signoff-sheet.blade.php` for the Name/Role/Company + canvas step.
- **Item ordering on checklist**: group by room (matches Phase 14 field UX), then by equipment within room, then by category. Engineers keep the same room-scrollable mental model.
- **Re-sync diff UI**: simple list of "N added / M removed / K unchanged" above a confirm button. No per-item reveal unless the diff exceeds 5 items.

### Folded Todos
None — no pending todos surfaced.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements & Roadmap
- `.planning/REQUIREMENTS.md` §INST-05 (lines 170-182) — Full INST-05 + INST-05a–INST-05i spec (schema, generator rule, AJAX saves, HEIC, AVIXA categories, `creagia/laravel-sign-pad`, completion, state advance, audit trail)
- `.planning/REQUIREMENTS.md` "Technical Constraints" table (lines 194-197) — HEIC server-side mandatory, `devicePixelRatio` scaling, per-item AJAX saves
- `.planning/ROADMAP.md` "Phase 16: Commissioning Checklist & Sign-off" (lines 166-178) — Goal + 7 success criteria

### Prior-Phase Context (load decisions that cross over)
- `.planning/phases/14-mobile-field-view/14-CONTEXT.md` — D-09 photo-table pattern, D-11 HEIC fail-loud ethos, bottom-sheet Blade component (`_field-sheet.blade.php`), per-item AJAX pattern, ownership-guard pattern
- `.planning/phases/14-mobile-field-view/14-01-PLAN.md` + `14-01-SUMMARY.md` — `install_task_photos` migration (pattern to mirror for `commissioning_item_photos` if we add one)
- `.planning/phases/14-mobile-field-view/14-04-PLAN.md` + `14-04-SUMMARY.md` — `App\Services\HeicImageConverter` (the service we reuse for INST-05d)
- `.planning/phases/15-time-tracking/15-CONTEXT.md` — D-19 UTC storage + Europe/London display, bottom-sheet pattern reuse, audit-log shape (if needed here)
- `.planning/phases/12-install-task-generation/12-01-PLAN.md` + `12-01-SUMMARY.md` — `install_tasks` schema, `equipment_name` + `room_name` (the generator's input)

### Codebase Reuse Targets
- `app/Models/Project.php:19-90` — `STATUS_INSTALLING` / `STATUS_COMMISSIONING` constants + `$forwardTransitions` map (line 45) — transition already defined; call the existing state-machine helper rather than writing `->status = ...`
- `app/Models/InstallProgramme.php` — Programme lifecycle; commissioning block activates at `complete`
- `app/Models/InstallTask.php:32-54` — `STATUS_COMPLETE` constant + `equipment_name` / `room_name` / `equipment_category` fields (note: `equipment_category` is 'hardware'-style, NOT AVIXA — doesn't drive the mapping)
- `database/migrations/2026_04_14_000002_create_install_tasks_table.php` — Authoritative columns the generator reads
- `app/Services/HeicImageConverter.php` — **Reuse verbatim** for INST-05d photo-evidence upload
- `resources/views/install-programmes/field.blade.php` — Existing mobile field view; new commissioning view should mirror its Alpine + Tailwind patterns
- `resources/views/install-programmes/_field-sheet.blade.php` + `_field-category-sheet.blade.php` + `_field-note-sheet.blade.php` — Bottom-sheet templates to fork
- `app/Http/Controllers/InstallProgrammeController.php` — Ownership-guard pattern: `abort_if($project->user_id !== auth()->id() && ! auth()->user()->isAdmin(), 403)`
- `app/Services/PdfService.php` (existing RAMS / Site Survey PDF generator) — DomPDF pattern to reuse for snagging PDF
- `resources/views/pdf/*.blade.php` — RAMS / Site Survey PDF Blade templates for aesthetic alignment
- `composer.json:10-14` — `barryvdh/laravel-dompdf` ^3.1, `dompdf/dompdf` ^3.1, `intervention/image` 3 already installed; `creagia/laravel-sign-pad` NOT yet installed (planner runs `composer require`)

### External Packages & Docs (add to composer during plan)
- `creagia/laravel-sign-pad` — Client signature canvas. Read package README before planning the blade/JS wiring. Must configure `devicePixelRatio` scaling explicitly.
- signature_pad GitHub issues #71 / #153 / #200 / #362 — documented Retina/iOS DPI corruption symptom. The DPI-correction snippet in those issues is the exact pattern to use.

### Planning Context
- `CLAUDE.md` — Thin-controller + service-layer pattern, queue-based async, Blade + Tailwind + Alpine, fetch()+CSRF (not Axios), UUID-named files on private disk, `DocumentArtifactStorage` for any generated document artefacts (H-07 convention applies to snagging PDF)
- `.planning/PROJECT.md` — "AI usage" and "Data integrity" constraints (fail-loud, no silent degradation)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`App\Services\HeicImageConverter`** (Phase 14) — Direct reuse for INST-05d. Inject into the commissioning photo service. Fail-loud behaviour is exactly what INST-05d needs.
- **`_field-sheet.blade.php`** bottom-sheet — Fork into `_commissioning-fail-sheet.blade.php` (required reason on fail) and `_commissioning-signoff-sheet.blade.php` (Name/Role/Company + canvas).
- **`InstallProgrammeController` ownership guard** — Reuse verbatim for commissioning route authorisation.
- **`PdfService` + `barryvdh/laravel-dompdf`** — Already in the stack for RAMS and Site Survey PDFs. Snagging PDF becomes a new method on PdfService (or a dedicated `CommissioningPdfService` extending the same pattern).
- **`Project` state machine** (forward transitions map) — Call the existing helper to advance `STATUS_INSTALLING → STATUS_COMMISSIONING`. Do NOT bypass with a direct assignment.
- **Phase 14 AJAX JSON shape** `{ id, filename, original_name, caption, url }` — Reuse for commissioning photo upload responses.
- **Phase 15 bottom-sheet + localStorage pattern** (category pre-select) — Similar shape works for remembering a client-sign-off session if needed.

### Established Patterns
- Blade + Tailwind + Alpine.js, server-rendered, no SPA
- `fetch()` + manual CSRF meta header — NOT Axios for form/save traffic
- UUID-named files on `storage/app/private/` via `Storage::disk('local')`, served through signed routes
- Status enums stored as `varchar` with PHP constants on the model class (e.g., `CommissioningItem::STATUS_PASS`)
- JSON error responses via domain exceptions (mirror `ClockInBlockedException` → 422)
- Controllers thin → services do the work; services in `app/Services/` flat namespace
- H-07 convention: generated documents go through `DocumentArtifactStorage` — plan a `TYPE_SNAGGING` constant or reuse an existing type
- Log::info / Log::warning with structured context arrays; class name prefix in message

### Integration Points
- `routes/web.php` — Add `/projects/{project}/commissioning` (GET view), plus per-item PATCH (`/commissioning-items/{item}/status`, `.../photo`, `.../notes`), plus `/install-programmes/{programme}/commissioning/signoff` (POST — captures signature + generates final PDF + advances state)
- New controller (`CommissioningController`) or new action on `InstallProgrammeController` — planner decides based on file size
- New models: `CommissioningItem`, `CommissioningSignoff` (and possibly `CommissioningItemPhoto` if we follow the Phase 14 photo-table pattern — likely yes, for consistency)
- New migration: `commissioning_items` (schema from INST-05a — add the columns explicitly listed), `commissioning_signoffs`, optional `commissioning_item_photos`
- New config: `config/commissioning.php` — AVIXA keyword→category map + certification-text template
- New service: `CommissioningItemGenerator` (install_tasks → items), `CommissioningPdfService` or extension of PdfService
- `composer require creagia/laravel-sign-pad` — publish config + run package migrations if any
- H-07 disk: snagging PDFs write through `DocumentArtifactStorage` under a new `TYPE_SNAGGING` constant

### Gaps the Scout Surfaced
- No existing AVIXA category vocabulary in the codebase — `config/commissioning.php` is a greenfield artefact
- No snagging-PDF template exists — new Blade template under `resources/views/pdf/commissioning-snagging.blade.php`
- `InstallTask.equipment_category` ('hardware' style) does NOT map to AVIXA — don't reuse it; map on `equipment_name` string content instead
- No signature-capture code anywhere — Phase 16 is the first signature feature; set the library + DPI pattern carefully so Phase 22 (Client Portal document access) can reuse it

</code_context>

<specifics>
## Specific Ideas

- **Engineer UX parity with Phase 14** — Same mobile-first mental model (room-grouped scrollable list, tap-to-advance via pass/fail buttons per item). Fewer re-training costs for field engineers already trained on the field view.
- **Fail-loud on signature generation** — If `creagia/laravel-sign-pad` fails to render, if the base64 PNG decode fails, if DomPDF can't embed the signature — raise visibly, don't ship a "signature-less" PDF silently. Matches Phase 14's HEIC fail-loud pattern (PROJECT.md data-integrity rule).
- **In-person only for v1** — Most 21CAV commissioning handovers happen with the client on-site; remote sign-off is an edge case that can ship as a follow-up if demand emerges.
- **Snagging = part of the handover** — Client signs with full awareness of snag items. Do not hide fails under a collapse; the PDF preview step exists so the client reads them explicitly.
- **State-machine respect** — Call the existing Project state helper, don't assign `->status` directly. Any validation failure (e.g., project not in `STATUS_INSTALLING`) should 422 with a clear message (`ClockInBlockedException` precedent).

</specifics>

<deferred>
## Deferred Ideas

Noted for future phases; not in scope for Phase 16.

- **Emailed remote client-sign-off link** — Tokenised URL, client signs on their own device. Adds email delivery, token expiry, reminder logic. Revisit if remote handovers become common.
- **Engineer per-item PIN / 2FA challenge for sign-off** — Stronger attestation on `signed_off_by`. Current auth already identifies the engineer; add only if compliance demands it.
- **Re-open completed commissioning** — INST-05i locks signed items immutable. Admin override for genuine mistakes (wrong client signed, wrong project) would need a new workflow. Post-v1.2 concern.
- **AI-suggested AVIXA category mapping** — Violates PROJECT.md "AI never invents scope" for a compliance document. Rejected; revisit only if the constraint is relaxed.
- **DB-editable AVIXA mapping admin UI** — Static config is sufficient for current engineer count. Revisit if mapping churn becomes a pain.
- **8th "End-user Training Completed" AVIXA category** — Natural extension for AV handover. Not in INST-05e; revisit with engineer feedback.
- **Multi-day / per-room client signature sessions** — Current spec is single-signature-at-programme-complete. If phased handovers (e.g., boardroom signed off week 1, huddle rooms week 2) become a need, model as `commissioning_signoffs` with a `scope` column (`programme` vs. `room`).
- **Commissioning_item_audits retro-edit table** — Only meaningful if we allow post-signature edits. INST-05i prohibits them in v1, so not needed.
- **Part/brand TBC flagging + uppercase + AI-one-sentence description** — User flagged this was for a different project; ignore here.
- **Project status → STATUS_HANDOVER auto-advance** — Phase 16 only advances to `STATUS_COMMISSIONING`. The `STATUS_COMMISSIONING → STATUS_HANDOVER` transition will be handled by a later phase (Phase 22 Client Portal or a dedicated handover phase).
- **Re-generating snagging PDF after signature** — Per D-10, the final PDF is generated once with the signature embedded. If items need to change post-signature, INST-05i's immutability rule kicks in. No re-generation flow for v1.
- **Backfill HEIC handling for survey photos** — Carried from Phase 14 deferred list; still out of scope here.

### Reviewed Todos (not folded)
None — no pending todos surfaced.

</deferred>

---

*Phase: 16-commissioning-checklist-signoff*
*Context gathered: 2026-04-21*
