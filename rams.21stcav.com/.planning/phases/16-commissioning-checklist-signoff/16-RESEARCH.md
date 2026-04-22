# Phase 16: Commissioning Checklist & Sign-off — Research

**Researched:** 2026-04-21
**Domain:** Laravel 12 commissioning workflow — AVIXA-categorised checklist, iPad/iPhone digital signature capture, snagging PDF with embedded signature, state-machine auto-advance
**Confidence:** HIGH (codebase reuse paths verified) · MEDIUM (creagia/laravel-sign-pad integration gotchas) · HIGH (DomPDF base64 support post-3.1.5)

## Summary

Phase 16 layers a commissioning module on top of Phase 12's `install_tasks` and Phase 14's mobile field infrastructure. The work is mostly **mechanical glue** — new tables, a keyword-map-driven generator, per-item AJAX endpoints cloned from Phase 14's shape, a DomPDF Blade template modelled on `resources/views/pdf/rams.blade.php`, and a `CommissioningController` that calls the already-registered `STATUS_INSTALLING → STATUS_COMMISSIONING` transition. No new foundational patterns are invented.

Two risks dominate. First, `creagia/laravel-sign-pad` v3.0.1 is **tightly coupled** to a model-trait + auto-route flow that does not match CONTEXT.md D-10 (preview → sign → regenerate final PDF). Using the bundled Blade component is viable only if we treat the package as a **canvas shell**: keep `<x-creagia-signature-pad/>`, ignore the submit button, wire our own JS to the `sign-pad.min.js` instance and extract `toDataURL()` base64, POST to our own `signoff` endpoint. The package's `RequiresSignature` trait and `CanBeSigned` contract should NOT be adopted. Second, `signature_pad` itself has a documented Retina/iOS DPI bug (issues #71/#153/#200/#362) — `resizeCanvas()` with `window.devicePixelRatio` multiplication is the required fix; creagia's bundled asset does NOT apply it, so we must add a small init script ourselves.

The DomPDF signature-embed path is well-supported: project is on `dompdf/dompdf v3.1.5` which fixed the 3.1.0 regression (`data://` is in default `allowed_protocols`, no config change needed), and `isRemoteEnabled=false` (current PdfService setting) does NOT block data URIs — only remote http(s). The seven AVIXA categories are finite and the keyword-map is small enough to hand-author; `config/worksheet_taxonomy.php` is a direct precedent for the shape.

**Primary recommendation:** Build `config/commissioning.php` with a category → keyword-list map; add `CommissioningItemGenerator`, `CommissioningService`, `CommissioningPdfService`, `CommissioningController` plus `InstallTaskObserver::saved` trigger; extend `DocumentArtifactStorage` with `TYPE_SNAGGING`; reuse creagia's Blade component as a canvas shell only and apply DPI scaling in an inline Alpine.js snippet.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (copy verbatim from 16-CONTEXT.md §decisions)

**Item generation**

- **D-01** — Static PHP config drives the equipment-type → AVIXA-category mapping. New file `config/commissioning.php` holds an array of `category => [keyword, keyword, ...]` pairs (e.g., `'display_quality' => ['display', 'monitor', 'projector', 'videowall']`). No DB admin surface, no AI generation. Edits require a deploy.
- **D-02** — Per equipment instance grain. If a site has three identical 75" displays, three separate "Display – Power On" items are generated (one per unit). Matches `install_tasks` 1:1 and preserves per-unit audit trail for year-2 warranty claims.
- **D-03** — Generation trigger = programme complete. Items are created automatically when the last `install_tasks` row for the programme hits `STATUS_COMPLETE`. The commissioning view is empty before that.
- **D-04** — Re-sync preserves statuses. Explicit "Re-sync from programme" button: preserves existing `pass`/`fail`/`na`/`pending` for unchanged items, adds new items for new equipment, soft-deletes items for removed equipment (never hard-delete). Engineer sees a diff summary before confirming.
- **D-05** — Data source = `install_tasks` rows (Phase 12). Never `ProjectDataService::resolve()` or `project_packages.equipment_list` — those miss engineer-side swaps.
- **D-06** — Keyword matching = case-insensitive substring on `install_tasks.equipment_name`. No regex, no prefix-match on part number.
- **D-07** — Unmatched equipment generates no items (skip). Generic hardware with no keyword hit produces zero commissioning items.
- **D-08** — Exactly the 7 AVIXA categories from INST-05e: Power On / Display Quality / Audio Level / VTC Connectivity / Control System / Network / Cabling. No extensions.

**Signature flow**

- **D-09** — In-person capture only on the engineer's device. `creagia/laravel-sign-pad` canvas with explicit `devicePixelRatio` scaling. No emailed remote-signing link.
- **D-10** — Timing = after snagging PDF preview. Engineer taps "Complete Commissioning" → server generates a preview snagging PDF → engineer + client review on device → client signs → signed signature is embedded and the final PDF is regenerated.
- **D-11** — Client metadata captured = Name + Role + Company. Three freetext fields. Stored on a new `commissioning_signoffs` table (one row per programme) with `client_name`, `client_role`, `client_company`, `signature_png_base64`, `signed_at`, `install_programme_id`. Company NOT inferred from `project.client_id` — capture what the signing person states.
- **D-12** — Fail items do not block sign-off. Any mix of `pass` / `fail` / `na` unlocks the "Complete Commissioning" button. Failed items roll into the snagging PDF as "To Be Resolved". Client signs acknowledging the snag list. `Project.status` advances to `STATUS_COMMISSIONING` after signature.

### Claude's Discretion (planner to decide; research informs)

- Engineer per-item sign-off: auto-fill `signed_off_by` from `auth()->user()->name` at pass/fail/na action. No PIN challenge for v1.
- Signature storage: base64 PNG on the `commissioning_signoffs` row (per INST-05f). Not a file on disk.
- Certification text above canvas: config-driven minimal legal statement.
- Snagging PDF layout: reuse DomPDF PdfService pattern. Sections: project header → per-room tables of items with pass/fail/na icons + thumbnails → snag summary → client signature block.
- Photo evidence policy: optional for `pass`/`na`; **required for `fail`**. Validation in the AJAX save endpoint, not client-side.
- Fail-reason note: required on `fail` (bottom-sheet UI). Stored on `commissioning_items.notes`.
- No `commissioning_item_audits` table for v1. INST-05i makes items immutable post-signature.
- Bottom-sheet reuse: fork `_field-sheet.blade.php` into `_commissioning-fail-sheet.blade.php` and `_commissioning-signoff-sheet.blade.php`.
- Item ordering: group by room, then equipment within room, then category.
- Re-sync diff UI: "N added / M removed / K unchanged" summary above confirm button; per-item reveal only when diff > 5.

### Deferred Ideas (OUT OF SCOPE — do not research alternatives)

- Emailed remote client-sign-off link
- Engineer per-item PIN / 2FA challenge
- Re-open completed commissioning (admin override)
- AI-suggested AVIXA category mapping (violates PROJECT.md "AI never invents scope")
- DB-editable AVIXA mapping admin UI
- 8th "End-user Training Completed" AVIXA category
- Multi-day / per-room signature sessions
- `commissioning_item_audits` retro-edit table
- Part/brand TBC flagging + AI description (wrong project)
- `STATUS_COMMISSIONING → STATUS_HANDOVER` auto-advance (deferred to Phase 22 / dedicated handover phase)
- Regenerating snagging PDF after signature (INST-05i immutability)
- Backfill HEIC handling for survey photos (carried from Phase 14)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INST-05 | Per-equipment commissioning checklist with photo evidence and client signature; triggers project state transition | §Standard Stack, §Architecture Patterns |
| INST-05a | `commissioning_items` table with columns `id`, `install_programme_id`, `equipment_name`, `room_name`, `category` (power/display/audio/vtc/control/network/cabling), `status` (pending/pass/fail/na), `evidence_photo_path`, `notes`, `signed_off_by`, `signed_off_at`, timestamps | §Schema Design → commissioning_items migration |
| INST-05b | Commissioning items generated from programme equipment list — one item per equipment × AVIXA category where applicable | §Code Examples → CommissioningItemGenerator; §Keyword Map Shape |
| INST-05c | Per-item AJAX save — each status update, photo upload, or note is a separate AJAX request | §Architecture Patterns → Per-item AJAX endpoints (cloned from Phase 14 TaskStatusController / TaskPhotoController) |
| INST-05d | Photo evidence upload per item: same HEIC protection as INST-03e | §Standard Stack → Reuse `App\Services\HeicImageConverter` verbatim |
| INST-05e | AVIXA checklist categories: Power On / Display Quality / Audio Level / VTC Connectivity / Control System / Network / Cabling | §Keyword Map Shape (config/commissioning.php) |
| INST-05f | Client signature: `creagia/laravel-sign-pad`; canvas with explicit `devicePixelRatio` scaling; signature stored as base64 PNG | §Standard Stack → creagia/laravel-sign-pad §Signature Capture Pattern |
| INST-05g | Commissioning completion: all items pass/fail/na → "Complete Commissioning" unlocked; generates PDF snagging report (DomPDF, embeds signature image) | §Architecture Patterns → Signoff flow; §Code Examples → base64 in DomPDF |
| INST-05h | Auto-advance `Project.status` from `STATUS_INSTALLING` to `STATUS_COMMISSIONING` via existing state machine | §State Machine Call-site (Project::canTransitionTo + update) |
| INST-05i | Audit trail: `signed_off_by`, `signed_off_at`; immutable once signed | §Immutability Enforcement Pattern |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

These directives from `./CLAUDE.md` have the same authority as locked decisions — the planner must produce plans that honour them.

| Directive | How Phase 16 Must Comply |
|-----------|-------------------------|
| AI only for formatting / method statement — never for inventing scope, equipment, or design | D-01 already forbids AI for AVIXA mapping. The snagging PDF is fully data-driven — no AI at any point. |
| Data integrity — all document content must trace back to quote/survey/reviewed inputs | `commissioning_items` source is `install_tasks` (D-05). Snagging PDF only renders what's in `commissioning_items` + `commissioning_signoffs`. |
| Must not break existing RAMS pipeline / extracted/reviewed/generated flow / queue-based generation | Phase 16 adds new models + new routes. Zero mutations to `RamsDocument`, `OmManual`, `ProjectPackage`, `ExtractRamsDraftJob`, `BuildRamsDocumentJob`. |
| Laravel service-based, thin controllers, shared data services, safe migrations, queue-compatible | Controllers delegate to `CommissioningService`. Generator and PDF builder are services in `app/Services/` flat namespace. All new tables + columns additive (no destructive migrations). |
| H-07 generated-document convention | Snagging PDF writes through `DocumentArtifactStorage`. Add new `TYPE_SNAGGING` constant. Never use `storage_path('app/snagging/…')` directly. |
| Thin-controller + service layer | `CommissioningController` validates input + authorises; `CommissioningService`/`CommissioningPdfService` do the work. Mirrors `TimeEntryController → TimeEntryService` split. |
| Fetch + CSRF meta (NOT Axios) for form/save traffic | All INST-05c endpoints use `fetch()` with `X-CSRF-TOKEN` header, matching Phase 14 field.blade.php JS idiom. |
| UUID-named files on private storage | Evidence photos use `commissioning-evidence/{project_id}/{item_id}/{uuid}.jpg` pattern (mirrors task-photos/). |
| Status enums as `varchar` with PHP constants on model | `CommissioningItem::STATUS_PENDING`, `STATUS_PASS`, `STATUS_FAIL`, `STATUS_NA`. |
| JSON error responses via domain exceptions | `CommissioningSignoffException` (mirrors `ClockInBlockedException`) for not-ready / already-signed / state-machine-refused cases → 422. |
| Laravel Pint PSR-12 | Planner plans use 4-space indent, aligned `$fillable` columns. |
| Log message class-name prefix + structured context arrays | `Log::info('CommissioningService: item generated', [...])` — mirrors Phase 14 / 15. |

## Standard Stack

### Core — Already Installed (no `composer require` needed)

| Library | Version (verified in composer.lock) | Purpose | Why Standard |
|---------|-------------------------------------|---------|--------------|
| `barryvdh/laravel-dompdf` | v3.1.1 [VERIFIED: composer.lock] | Blade → HTML → PDF wrapper | Existing RAMS + Site Survey PDFs use it. Zero integration surface. |
| `dompdf/dompdf` | v3.1.5 [VERIFIED: composer.lock] | Core PDF engine | v3.1.5 fixes the data-URI base64 regression introduced in 3.1.0 [CITED: github.com/dompdf/dompdf PR #3710/#3653]. `data://` is in default `allowed_protocols` [CITED: github.com/dompdf/dompdf src/Options.php v3.1.5]. |
| `intervention/image` | ^3 [VERIFIED: composer.json line 14] | Image decoding for HEIC → JPEG | Already wraps Imagick via `HeicImageConverter`. |
| `laravel/framework` | ^12.0 [VERIFIED: composer.json line 15] | Framework | Targets creagia/laravel-sign-pad v3 constraint `^11.0|^12.0|^13.0`. |

### New — One `composer require` line

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `creagia/laravel-sign-pad` | 3.0.1 [CITED: packagist.org/packages/creagia/laravel-sign-pad, released 2026-03-15] | Signature canvas Blade component + bundled `sign-pad.min.js` (wraps `szimek/signature_pad`) | Locked by INST-05f. Constraint `^8.2` PHP + `^11.0\|^12.0\|^13.0` Laravel → matches project [VERIFIED: packagist composer.json]. |

**Installation:**

```bash
composer require creagia/laravel-sign-pad:^3.0
php artisan sign-pad:install     # publishes config + migration
php artisan vendor:publish --tag=sign-pad-assets   # copies public/vendor/sign-pad/sign-pad.min.js
php artisan migrate
```

**CRITICAL — package integration caveat (see §Common Pitfalls #2):** creagia's `php artisan sign-pad:install` publishes a `signatures` migration expecting callers to wire up the `RequiresSignature` trait + `CanBeSigned` contract on a model, with automatic route registration posting to `getSignatureRoute()`. Phase 16 does NOT use any of that — we only consume the Blade component as a canvas shell and the bundled `sign-pad.min.js` JS (which instantiates `SignaturePad` from szimek/signature_pad on `canvas.e-signpad canvas` elements). The published migration table is harmless once `php artisan migrate` creates it, but we never insert into it. Alternative: run `sign-pad:install` to get `config/sign-pad.php` + the published JS, then manually delete the auto-generated migration file before first `migrate` if we want to skip the unused table. Planner decides; leaving the empty table is fine and matches "don't fight the package".

### Alternatives Considered (locked — no alternatives)

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| creagia/laravel-sign-pad | szimek/signature_pad via CDN directly (v5.1.3) [CITED: github.com/szimek/signature_pad] | REJECTED per INST-05f (package name locked). Using szimek directly would let us skip the unused `signatures` table but diverge from the explicit requirement. |
| DomPDF for snagging PDF | mpdf/mpdf (already installed for O&M) | REJECTED — D-10 signoff flow already specifies DomPDF and matches existing PdfService pattern. mpdf would duplicate the wheel. |
| `commissioning_item_photos` join table | `evidence_photo_path` column on `commissioning_items` (per INST-05a) | **Use INST-05a verbatim**: one photo per item on the row. Multi-photo was considered but INST-05a explicitly specifies `evidence_photo_path` (singular). Follow the spec. |

### Keyword Map Shape — `config/commissioning.php`

Reference shape, not locked wording. `config/worksheet_taxonomy.php` is the nearest precedent in the codebase [VERIFIED: read config/worksheet_taxonomy.php]. Keyword seed list below is derived from real AV vocabulary — planner should cross-check against known QuoteWerks line items during implementation.

```php
<?php

return [
    // Seven locked categories per INST-05e / D-08.
    'categories' => [
        'power'    => 'Power On',
        'display'  => 'Display Quality',
        'audio'    => 'Audio Level',
        'vtc'      => 'VTC Connectivity',
        'control'  => 'Control System',
        'network'  => 'Network',
        'cabling'  => 'Cabling',
    ],

    // category => [keyword, ...] — case-insensitive substring match on
    // install_tasks.equipment_name (D-06). Multiple categories may match
    // one equipment name (a VC bar gets display + audio + vtc + power).
    'keyword_map' => [
        'power'   => ['display', 'monitor', 'projector', 'videowall', 'videobar',
                      'amplifier', 'dsp', 'codec', 'pdu', 'ups', 'switch',
                      'processor', 'mixer', 'rack', 'pc', 'mini pc', 'nuc'],
        'display' => ['display', 'monitor', 'projector', 'videowall', 'screen',
                      'tv', 'oled', 'lcd', 'ledwall', 'confidence monitor'],
        'audio'   => ['microphone', 'mic', 'ceiling mic', 'mxa', 'speaker',
                      'soundbar', 'amplifier', 'amp', 'dsp', 'tesira', 'q-sys',
                      'biamp', 'shure', 'videobar', 'bose', 'mixer'],
        'vtc'     => ['codec', 'videobar', 'teams room', 'mtr', 'zoom room',
                      'logitech rally', 'poly studio', 'cisco room', 'vc bar',
                      'webex', 'bluejeans', 'neat'],
        'control' => ['crestron', 'extron', 'amx', 'control processor', 'cp3',
                      'cp4', 'touch panel', 'tsw', 'ipcp', 'keypad',
                      'occupancy sensor', 'button panel', 'control system'],
        'network' => ['switch', 'router', 'access point', 'wap', 'poe switch',
                      'netgear', 'cisco catalyst', 'unifi', 'meraki', 'firewall'],
        'cabling' => [],   // D-07: unmatched generates no items. Cables
                           //       themselves are excluded from install_tasks
                           //       by InstallTaskGeneratorService (category
                           //       'cables' is in EXCLUDED_CATEGORIES).
                           //       Cabling commissioning is implicit in
                           //       other category checks — leave empty so
                           //       no dedicated "Cabling" items are generated
                           //       unless the engineer's equipment name
                           //       explicitly matches. Planner may add
                           //       'patch panel', 'cable tray', 'floor box'
                           //       if feedback warrants.
    ],

    // Certification text shown above the signature canvas (Claude's Discretion).
    // Planner refines wording before first deploy.
    'certification_text' => 'I confirm the commissioning items above reflect the '
        .'system state handed over to 21st Century AV Ltd\'s client on the date '
        .'shown below. Outstanding items listed as "To Be Resolved" are acknowledged.',
];
```

**Edge cases from real AV vocabulary:**

- **DSP** (Biamp Tesira, Q-SYS Core) → matches `audio` AND `power`. Both items generated.
- **VC bar** (Poly Studio X70, Logitech Rally Bar, Neat Bar) → matches `display`, `audio`, `vtc`, `power`. Four items generated — correct per D-02 (per-instance grain).
- **Network switch** → matches `network` AND `power`. Two items, correct.
- **75" LG display** → matches `display` AND `power`. Two items.
- **Crestron CP3** → matches `control` AND `power`. Two items.

Confidence: **MEDIUM** — the keyword list is hand-authored against plausible AV vocabulary. The user should review/refine during Plan 01 before it's locked into config/. [ASSUMED: real QuoteWerks `equipment_name` strings follow these patterns — A1 in Assumptions Log.]

## Architecture Patterns

### Recommended File Layout

```
app/
├── Http/
│   └── Controllers/
│       └── CommissioningController.php      # new thin controller
├── Models/
│   ├── CommissioningItem.php                # new
│   └── CommissioningSignoff.php             # new
├── Observers/
│   └── InstallTaskObserver.php              # new — D-03 generation trigger
├── Services/
│   ├── CommissioningItemGenerator.php       # install_tasks → items (D-01, D-02, D-06, D-07)
│   ├── CommissioningService.php             # orchestration (signoff flow, state transition)
│   ├── CommissioningPdfService.php          # snagging PDF via DomPDF
│   ├── CommissioningSyncService.php         # D-04 re-sync (preserves statuses)
│   └── DocumentArtifactStorage.php          # EXTEND — add TYPE_SNAGGING
├── Exceptions/
│   └── CommissioningSignoffException.php    # new — mirrors ClockInBlockedException
config/
└── commissioning.php                        # new — AVIXA keyword map + certification text
database/migrations/
├── 2026_04_21_000003_create_commissioning_items_table.php
├── 2026_04_21_000004_create_commissioning_signoffs_table.php
resources/views/
├── commissioning/
│   ├── show.blade.php                       # engineer checklist page (mobile-first)
│   ├── _item-row.blade.php
│   ├── _commissioning-fail-sheet.blade.php  # fork of _field-sheet
│   ├── _commissioning-signoff-sheet.blade.php
│   └── _resync-diff.blade.php
└── pdf/
    └── commissioning-snagging.blade.php     # DomPDF Blade template
routes/web.php                               # EXTEND — new routes (see below)
```

### Pattern 1: State-driven item generation (D-03) via Model Observer

**What:** When an `InstallTask` is saved with `status = 'complete'`, the observer checks whether this was the last pending task in the programme. If yes, it dispatches generation synchronously.

**When to use:** Trigger auto-creation of `commissioning_items` without adding explicit calls throughout the codebase. Observer is the minimum-surface hook — the existing `TaskStatusController::update()` calls `$task->update(['status' => ...])` once; the observer fires transparently.

**Example:**

```php
// app/Observers/InstallTaskObserver.php
namespace App\Observers;

use App\Models\InstallTask;
use App\Services\CommissioningItemGenerator;
use Illuminate\Support\Facades\Log;

class InstallTaskObserver
{
    public function __construct(
        private readonly CommissioningItemGenerator $generator,
    ) {}

    /**
     * After a task's status changes to complete, check if the programme is
     * now fully complete. If yes, generate commissioning_items (idempotent —
     * does nothing if items already exist for this programme).
     *
     * D-03: Generation trigger = programme complete.
     * Idempotent: CommissioningItemGenerator::generate() short-circuits if
     * the programme already has any (non-soft-deleted) items.
     */
    public function saved(InstallTask $task): void
    {
        if ($task->status !== InstallTask::STATUS_COMPLETE) {
            return;
        }

        if (! $task->wasChanged('status')) {
            return;   // not a status flip; skip the programme-complete check
        }

        $programme = $task->programme;

        // Any pending/in_progress/blocked tasks in the programme?
        $remaining = $programme->tasks()
            ->whereIn('status', [
                InstallTask::STATUS_PENDING,
                InstallTask::STATUS_IN_PROGRESS,
                InstallTask::STATUS_BLOCKED,
            ])
            ->count();

        if ($remaining > 0) {
            return;
        }

        try {
            $this->generator->generate($programme);
        } catch (\Throwable $e) {
            Log::error('InstallTaskObserver: commissioning generation failed', [
                'programme_id' => $programme->id,
                'task_id'      => $task->id,
                'error'        => $e->getMessage(),
            ]);
            // Do not rethrow — do not fail the task-complete save just because
            // commissioning generation errored. Engineer can hit "Re-sync"
            // button (D-04) to retry.
        }
    }
}
```

Registered in `app/Providers/AppServiceProvider::boot()`:

```php
InstallTask::observe(InstallTaskObserver::class);
```

Confidence: **HIGH** — direct pattern from Laravel docs [CITED: laravel.com/docs/12.x/eloquent#observers].

### Pattern 2: Per-item AJAX endpoints (INST-05c) — clone Phase 14

Four endpoints, all thin controllers → `CommissioningService`:

| Method | Route | Body | Success Response |
|--------|-------|------|------------------|
| `PATCH` | `/commissioning-items/{item}/status` | `{status: pass\|fail\|na\|pending, note?: string}` (note REQUIRED when status=fail) | 200 `{id, status, notes, signed_off_by, signed_off_at, counters: {programme: {complete, total, unlocked: bool}}}` |
| `POST` | `/commissioning-items/{item}/photo` | multipart `photo` file | 201 `{id, evidence_photo_path, url}` |
| `DELETE` | `/commissioning-items/{item}/photo` | — | 204 |
| `PATCH` | `/commissioning-items/{item}/notes` | `{notes: string|null, max 2000}` | 200 `{id, notes}` |

**INST-05i immutability guard** is a private method on the controller (or domain exception thrown from the service):

```php
private function assertMutable(CommissioningItem $item): void
{
    $signoff = $item->programme->commissioningSignoff;
    if ($signoff !== null) {
        throw CommissioningSignoffException::itemsImmutable($item->id);
    }
}
```

Every PATCH/POST/DELETE action calls `assertMutable()` before any mutation. Signoff = lock.

### Pattern 3: Signoff flow (D-10) — two-step PDF regeneration

```
Engineer taps "Complete Commissioning"
  └─▶ POST /install-programmes/{programme}/commissioning/signoff/preview
      └─▶ CommissioningPdfService::buildPreview($programme)
          • Renders commissioning-snagging.blade.php with $signoff=null
          • DocumentArtifactStorage::writePath(TYPE_SNAGGING, "snagging_{programme}_{date}_preview.pdf")
          • Returns { preview_url: '/commissioning/{programme}/snagging/preview' }

Client reviews on device
  └─▶ Engineer opens signoff bottom-sheet
      (Name/Role/Company inputs + signature canvas)

Client signs
  └─▶ Alpine: signaturePad.toDataURL('image/png') → POST
      POST /install-programmes/{programme}/commissioning/signoff/finalise
      { client_name, client_role, client_company, signature_png_base64 }
      └─▶ CommissioningService::finalise($programme, $payload)
          • DB::transaction:
            - Create CommissioningSignoff row (D-11 columns)
            - CommissioningPdfService::buildFinal($programme, $signoff) — regenerates with signature block
            - Project::update(status → STATUS_COMMISSIONING) guarded by canTransitionTo()
          • Returns { final_pdf_url: '/commissioning/{programme}/snagging', project_status: 'commissioning' }
```

**Confidence:** HIGH — mirrors the `BuildRamsDocumentJob` pattern but synchronous (PDF generation is < 2s for expected item counts, so no queue).

### Pattern 4: Re-sync (D-04) — additive, never destructive

`CommissioningSyncService::resync($programme)`:

1. Recompute expected items via `CommissioningItemGenerator::expectedItems($programme)` (pure function — returns array of `[equipment_name, room_name, category, install_task_id]` tuples; no DB writes).
2. Fetch existing items: `$existing = $programme->commissioningItems()->withTrashed()->get();`
3. Compute diff:
   - **Unchanged**: `(install_task_id, category)` present in both → preserve status.
   - **Added**: present in expected but not existing → `create([…, 'status' => 'pending'])`.
   - **Removed**: present in existing but not expected → `soft-delete` (keep audit).
   - **Restored**: soft-deleted but now expected again → `restore()` + reset status to `pending`.
4. Return diff summary `{added: int, removed: int, unchanged: int, restored: int}` for the UI confirm screen.

### Anti-Patterns to Avoid

- **Don't directly assign `$project->status = Project::STATUS_COMMISSIONING`.** Always gate through `canTransitionTo()`:
  ```php
  if (! $project->canTransitionTo(Project::STATUS_COMMISSIONING)) {
      throw CommissioningSignoffException::invalidStateTransition($project->status);
  }
  $project->update(['status' => Project::STATUS_COMMISSIONING, 'commissioning_started_at' => now()]);
  ```
  See `app/Models/Project.php:41-50`, `237-256`.
- **Don't use `ProjectDataService::resolve()` or `project_packages` as the generator input.** D-05 is explicit: source is `install_tasks`.
- **Don't hand-roll a signature canvas with plain `<canvas>` + drawing code.** Use creagia's Blade component + bundled JS. Add only the DPI-scaling snippet (§Code Examples).
- **Don't build paths like `storage_path('app/snagging/...')`.** Extend `DocumentArtifactStorage` with `TYPE_SNAGGING` per H-07.
- **Don't re-insert `commissioning_items` on the D-03 trigger if rows already exist.** Observer must be idempotent — check row count first.
- **Don't allow any mutation to a `commissioning_items` row after `commissioning_signoffs` exists.** Every mutating endpoint must call `assertMutable()` (INST-05i).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Signature canvas + drawing logic | Hand-written canvas event listeners | creagia/laravel-sign-pad (INST-05f locked) | DPI handling, stroke smoothing, touch events, pressure — szimek/signature_pad (bundled) has solved this for 8+ years. |
| HEIC → JPEG for evidence photos | Second HEIC converter | Reuse `App\Services\HeicImageConverter` (Phase 14) | Already tested, fail-loud, handles iOS `application/octet-stream` MIME edge case. |
| PDF generation | Custom HTML-to-PDF, TCPDF, FPDF wrapper | `PdfService` + `barryvdh/laravel-dompdf` (existing) | Matches RAMS + Site Survey aesthetic; `isRemoteEnabled=false` pattern already established. |
| Base64 PNG embedding in PDF | Manual base64 decode + image file write + img reference | Inline `<img src="data:image/png;base64,{{ $signoff->signature_png_base64 }}">` | DomPDF 3.1.5 handles data URIs natively with zero config [CITED: dompdf Options.php v3.1.5 defaults]. |
| Bottom-sheet UI | Custom modal | Fork `_field-sheet.blade.php` (Phase 14) | Consistent mobile UX; Alpine transitions already tuned for motion-safe + reduced-motion. |
| File path conventions | Inline `storage_path()` | `DocumentArtifactStorage` with new `TYPE_SNAGGING` | H-07 convention + legacy-path fallback for readers. |
| Per-item AJAX scaffolding | New fetch idiom | Copy Phase 14 `fieldTaskRow` Alpine factory shape verbatim | Keeps mental model consistent for engineers + for future maintenance. |
| State-machine transition | `$project->status = ...` | `$project->canTransitionTo() + update()` | Project model already enforces the lifecycle map at lines 41-50. |
| Domain-exception → HTTP 422 | Custom try/catch in controller | `CommissioningSignoffException` (mirror `ClockInBlockedException`) + `render()` in `bootstrap/app.php` | Existing pattern — the exception handler already translates domain exceptions. |

**Key insight:** Phase 16 is a composition phase. Every new capability composes existing project services. The only truly new code is the `commissioning.php` keyword map, the generator service, and the snagging PDF Blade template. Everything else — HEIC, PDF, bottom-sheet, AJAX pattern, ownership guard, state-machine — is copy-or-call from Phase 14 / 15 / existing PdfService.

## Common Pitfalls

### Pitfall 1: creagia/laravel-sign-pad's package model doesn't match D-10

**What goes wrong:** Following the creagia README literally will push you to add the `CanBeSigned` interface + `RequiresSignature` trait to a Laravel model, configure `getSignatureRoute()` on the model, and let the package auto-submit the signature to its own controller. But D-10 requires a preview-then-finalise flow where signature data hits OUR endpoint (not the package's), so we can regenerate the PDF with the signature embedded and transition project state in the same transaction.

**Why it happens:** creagia's public API is designed for a one-shot "show user a signed document, save signature" workflow. Phase 16 is "show unsigned snagging PDF, capture signature, regenerate with signature embedded, advance state" — a superset.

**How to avoid:**
- Use `<x-creagia-signature-pad />` Blade component for the canvas only.
- Ignore the component's submit button (`sign-pad-button-submit`). Hide it via CSS or don't render it.
- Post via our own fetch() from the Alpine signoff-sheet factory to `/install-programmes/{programme}/commissioning/signoff/finalise`.
- The bundled `sign-pad.min.js` will still attach a `SignaturePad` instance to the canvas at DOMContentLoaded, writing to hidden `input[name="sign"]` on submit. We read `inputSign.value` (base64 PNG) from our Alpine factory instead of submitting the form.
- Skip the `RequiresSignature` trait. Skip the `CanBeSigned` contract. Skip the package's `signatures` migration (or let it land empty — no rows written).

**Warning signs:** Planner adds `use RequiresSignature;` to `CommissioningSignoff`. Controller inherits from or calls `SignableDocumentController`. Reject both at review.

Confidence: HIGH [VERIFIED: read creagia Blade component source verbatim].

### Pitfall 2: signature_pad + iOS Retina produces blank/distorted signatures without DPI scaling

**What goes wrong:** On iPhone/iPad Retina screens, `window.devicePixelRatio` is 2 or 3. A `<canvas width="400" height="200">` renders to 400×200 CSS pixels but only 400×200 physical pixels on screen — so the signature looks blurry and half-resolution. Worse, touch coordinates get mapped to the wrong pixel offsets, making strokes jittery or invisible in parts of the canvas. The generated `toDataURL()` PNG is the 400×200 low-res version — embedded in a PDF, it renders as a faint smudge.

**Why it happens:** `signature_pad` expects the canvas's `width`/`height` attributes (backing-store size) to be multiplied by `devicePixelRatio`, and `ctx.scale(ratio, ratio)` applied so input coordinates still match CSS pixel coordinates [CITED: github.com/szimek/signature_pad#handling-high-dpi-screens].

**How to avoid:** Inline this snippet after the creagia component renders (inside the signoff-sheet Alpine factory's init):

```javascript
// Source: github.com/szimek/signature_pad#handling-high-dpi-screens
const canvas = this.$refs.signatureCanvas;
const resizeCanvas = () => {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width = canvas.offsetWidth * ratio;
    canvas.height = canvas.offsetHeight * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    // Access the package's SignaturePad instance (creagia attaches it after
    // DOMContentLoaded). The instance is not exposed on a global, so we
    // re-instantiate via the globally-exported SignaturePad constructor from
    // sign-pad.min.js (which re-exports szimek's SignaturePad as window.SignaturePad).
    this.signaturePad = new window.SignaturePad(canvas);
    this.signaturePad.clear();   // otherwise isEmpty() returns wrong value
};
resizeCanvas();
window.addEventListener('resize', resizeCanvas);
window.addEventListener('orientationchange', resizeCanvas);
```

**⚠ Verify during plan 01:** The line `new window.SignaturePad(canvas)` assumes creagia's `sign-pad.min.js` exports `SignaturePad` to the window. If it does not (it may internally import and not re-export), the planner's Plan 01 must either:
- (a) load szimek/signature_pad@5.1.3 UMD from CDN in addition to creagia's script,
- (b) or extract the SignaturePad instance creagia has attached, typically via `canvas.__signaturePad` if creagia sets a data property, or via DevTools inspection during Plan 01.

Mark this as a **Plan 01 spike task**: "Open published `vendor/sign-pad/sign-pad.min.js`, confirm how the instance is reachable."

**Warning signs:** Client signs on iPad, preview PDF shows signature stretched or missing lower half. Reproduce at Plan implementation time by testing on an actual iOS device — emulator does not always reproduce Retina issues.

Confidence: HIGH for the fix pattern [CITED: szimek/signature_pad README]. MEDIUM for the exact JS integration hook (depends on creagia's internal implementation).

### Pitfall 3: DomPDF 3.1.0 regression on data-URI base64 images

**What goes wrong:** In DomPDF 3.1.0, `<img src="data:image/png;base64,...">` rendered as a broken-image placeholder (square with cross). This regression bit many projects [CITED: github.com/dompdf/dompdf issues #3580, #3643].

**Why it happens:** 3.1.0 added stricter data-URI protocol validation (PR #3492) that incorrectly rejected some base64 strings.

**How to avoid:** **We're fine** — `composer.lock` shows `dompdf/dompdf v3.1.5`, which includes PR #3710 (data URI → blob URI fix) and PR #3653 (parsing of non-encoded data URIs) [CITED: github.com/dompdf/dompdf/releases]. Default `allowed_protocols` includes `data://` [CITED: github.com/dompdf/dompdf/blob/v3.1.5/src/Options.php]. No config change needed in `PdfService` / `CommissioningPdfService`.

**Planner must verify in Plan 03 (PDF generation plan):** add a test that renders a known base64 PNG into a throwaway PDF and asserts non-zero bytes + that the PDF contains the image stream (PdfParser round-trip, or byte-signature check). Lock the DomPDF version in `composer.json` if a follow-up upgrade would regress (optional — current `^3.1` is fine).

Confidence: HIGH.

### Pitfall 4: Empty `allowed_protocols` list accidentally blocks data URIs

**What goes wrong:** Someone overrides `$options->set('allowed_protocols', ['file://'])` to lock down the PDF (defence-in-depth), breaks the signature image.

**Why it happens:** The security-minded override replaces the default array wholesale.

**How to avoid:** If setting `allowed_protocols` at all in `CommissioningPdfService`, explicitly include `'data://'`:

```php
$options->set('allowed_protocols', [
    'data://' => ['rules' => []],
    'file://' => ['rules' => []],
]);
```

Test coverage: one unit test in Plan 03 should set an unusual option override and confirm data URIs still render.

Confidence: HIGH.

### Pitfall 5: `base64-encoded` payload whitespace / line breaks in Blade template

**What goes wrong:** Some DomPDF base64 failures trace to PHP adding line breaks to long strings via line wrapping, or to Blade-injected whitespace within the base64 data. DomPDF is strict about data URI format [CITED: github.com/dompdf/dompdf issue #1016].

**Why it happens:** `chunk_split()`, `base64_encode()` on wrapped input, or accidental HTML-entity-encoding.

**How to avoid:**
- Use `{!! $signoff->signature_png_base64 !!}` (unescaped) in the Blade PDF template, NOT `{{ $signoff->signature_png_base64 }}` — the latter triggers HTML entity encoding, which will not break base64 (`=` becomes `&#61;` which IS broken). Actually — the correct guidance is use `{{ }}` but ensure the stored string contains ZERO whitespace/linebreaks.
- When storing signature: `signature_png_base64 = str_replace(["\r", "\n", " "], '', $base64)` as a defensive sanitiser.
- Strip the data-URI prefix in storage to save bytes, re-attach in the Blade: store just the base64 body, render `<img src="data:image/png;base64,{{ $signoff->signature_png_base64 }}">`.
- OR store the full data URI including `data:image/png;base64,` prefix and render `<img src="{{ $signoff->signature_data_uri }}">` — pick one convention and document it.

Confidence: HIGH.

### Pitfall 6: D-03 observer fires for wrong task events

**What goes wrong:** Without the `wasChanged('status')` guard, the observer's `saved` hook fires on every save — including notes updates, photo uploads, and audit-column writes. Each fires a "is programme complete?" query, then no-ops. Log noise + wasted DB queries.

**Why it happens:** Eloquent's `saved` event fires for any model save, not just status transitions.

**How to avoid:** Use `$task->wasChanged('status')` guard (shown in Pattern 1 above). Alternative: use the model's `updated` event with the specific-attribute check.

Confidence: HIGH [CITED: laravel.com/docs/12.x/eloquent#model-events].

### Pitfall 7: Two-signature race — engineer double-taps "Complete" and two signoffs are attempted

**What goes wrong:** Two rapid POSTs to `/signoff/finalise` both try to create `commissioning_signoffs` + advance project state. Without a race guard, two rows are written (or second fails at unique FK constraint), project state advances twice (second blocked by `canTransitionTo`).

**Why it happens:** Mobile touches debounce poorly on slow networks.

**How to avoid:**
- `commissioning_signoffs.install_programme_id` must have a `UNIQUE` index (one signoff per programme).
- Controller uses `DB::transaction` + `Programme::lockForUpdate()` (matches `TimeEntryService::start()` pattern).
- First POST commits; second POST fails the unique constraint → domain exception → 422.

Confidence: HIGH [VERIFIED: TimeEntryService lockForUpdate pattern in codebase].

### Pitfall 8: Evidence photo path vs. `commissioning_item_photos` table confusion

**What goes wrong:** INST-05a says `evidence_photo_path` column (singular). Phase 14 uses `install_task_photos` table (multi-photo). If the planner starts from Phase 14's pattern without reading INST-05a, they'll introduce a `commissioning_item_photos` table that contradicts the spec.

**Why it happens:** Phase 14's pattern is closer-at-hand; INST-05a's singular column is further away.

**How to avoid:** Follow INST-05a verbatim — single `evidence_photo_path` column on `commissioning_items`. One photo per item is sufficient for AVIXA sign-off (one "proof photo" per test). If ops feedback later requests multi-photo, add a `commissioning_item_photos` table as a non-destructive extension.

Confidence: HIGH.

### Pitfall 9: Large PDF payload — snagging report with 50+ items × inline JPEGs + base64 signature blows past DomPDF memory

**What goes wrong:** A full 20-room project could yield 300 commissioning items each with an inline thumbnail JPEG + a 400×200 base64 signature. DomPDF renders all images in memory; OOM at 128M-256M heap.

**Why it happens:** DomPDF is not streaming.

**How to avoid:**
- Thumbnail size: resize evidence photos to max 150×150 for the snagging PDF (via Intervention Image before embedding, or at upload time store a thumbnail alongside the original).
- Signature size: szimek/signature_pad default canvas is 300×150 — keep it that size; typical base64 PNG is 5-15KB.
- Set `memory_limit` to 512M for the PDF job if needed. Document in deployment notes.
- Consider per-room pagination breaks to help DomPDF release buffers.

Confidence: MEDIUM — no measurement yet; flag for Plan 03 load test.

### Pitfall 10: Generator runs before `install_tasks` has any rows

**What goes wrong:** Engineer generates an empty install programme (no tasks), then someone "completes" it (edge case — no tasks to flip). Observer's "remaining tasks = 0" fires immediately → generator runs → creates zero items. Engineer opens commissioning page → empty list.

**Why it happens:** Edge case in the D-03 trigger.

**How to avoid:** Generator short-circuits if `$programme->tasks()->count() === 0` — logs a warning, returns early. Engineer must generate real install tasks first. Observer also checks `$task->programme->tasks()->whereNotNull('status')->count() > 0` as a sanity check.

Confidence: HIGH.

## Code Examples

### Example 1: CommissioningItemGenerator core loop (D-02, D-06, D-07)

```php
// app/Services/CommissioningItemGenerator.php
namespace App\Services;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissioningItemGenerator
{
    public function __construct(
        // No constructor deps — config is read via config() helper
    ) {}

    public function generate(InstallProgramme $programme): int
    {
        // Idempotent: skip if items already exist (D-03 trigger may fire multiple times)
        if ($programme->commissioningItems()->exists()) {
            return 0;
        }

        $keywordMap  = config('commissioning.keyword_map');   // category => [keywords]
        $categories  = array_keys($keywordMap);                // power, display, audio, vtc, control, network, cabling
        $tasks       = $programme->tasks()->orderBy('sort_order')->get();
        $created     = 0;

        DB::transaction(function () use ($programme, $tasks, $keywordMap, $categories, &$created) {
            foreach ($tasks as $task) {
                $nameLower = mb_strtolower($task->equipment_name);

                foreach ($categories as $category) {
                    $keywords = $keywordMap[$category];

                    // D-06: case-insensitive substring on any keyword
                    $matched = false;
                    foreach ($keywords as $kw) {
                        if (str_contains($nameLower, mb_strtolower($kw))) {
                            $matched = true;
                            break;
                        }
                    }

                    if (! $matched) {
                        continue;   // D-07: unmatched generates no item
                    }

                    CommissioningItem::create([
                        'install_programme_id' => $programme->id,
                        'install_task_id'      => $task->id,
                        'equipment_name'       => $task->equipment_name,
                        'room_name'            => $task->room_name,
                        'category'             => $category,
                        'status'               => CommissioningItem::STATUS_PENDING,
                    ]);
                    $created++;
                }
            }
        });

        Log::info('CommissioningItemGenerator: items generated', [
            'programme_id' => $programme->id,
            'project_id'   => $programme->project_id,
            'task_count'   => $tasks->count(),
            'item_count'   => $created,
        ]);

        return $created;
    }
}
```

### Example 2: Base64 PNG in DomPDF Blade template (the signature block)

```blade
{{-- resources/views/pdf/commissioning-snagging.blade.php (excerpt) --}}
@if (! empty($signoff))
<section class="signoff-block">
    <h2 class="sec-heading">Client Sign-off</h2>

    <table class="std-table">
        <tr>
            <td class="lbl">Client name</td>
            <td class="val">{{ $signoff->client_name }}</td>
        </tr>
        <tr>
            <td class="lbl">Role</td>
            <td class="val">{{ $signoff->client_role }}</td>
        </tr>
        <tr>
            <td class="lbl">Company</td>
            <td class="val">{{ $signoff->client_company }}</td>
        </tr>
        <tr>
            <td class="lbl">Signed at</td>
            <td class="val">
                {{ \Carbon\Carbon::parse($signoff->signed_at)
                     ->setTimezone('Europe/London')
                     ->format('d F Y H:i T') }}
            </td>
        </tr>
    </table>

    <p class="certification-text">{{ config('commissioning.certification_text') }}</p>

    {{-- DomPDF 3.1.5 renders data: URIs natively — no options change needed.
         Store signature as clean base64 (no whitespace/linebreaks) so the img
         src round-trips cleanly. --}}
    <div class="signature-box">
        <img src="data:image/png;base64,{{ $signoff->signature_png_base64 }}"
             alt="Client signature"
             style="max-width: 300px; max-height: 150px; border-bottom: 0.5pt solid #333;">
    </div>
</section>
@endif
```

### Example 3: Schema — `commissioning_items` migration (INST-05a columns + planner extensions)

```php
// database/migrations/2026_04_21_000003_create_commissioning_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('install_programme_id')
                  ->constrained('install_programmes')
                  ->cascadeOnDelete();

            // Traceability link to the source install_task (per D-05).
            // Nullable in case a task is hard-deleted (SoftDeletes should
            // prevent this, but defensive).
            $table->foreignId('install_task_id')
                  ->nullable()
                  ->constrained('install_tasks')
                  ->nullOnDelete();

            // INST-05a required columns
            $table->string('equipment_name', 300);
            $table->string('room_name', 200);

            $table->string('category', 20);
            // Enum values: power | display | audio | vtc | control | network | cabling
            // Stored as varchar with PHP constants per codebase convention.

            $table->string('status', 20)->default('pending');
            // Enum: pending | pass | fail | na

            $table->string('evidence_photo_path', 500)->nullable();
            // INST-05a — singular. Relative path under disk root:
            // commissioning-evidence/{project_id}/{item_id}/{uuid}.jpg

            $table->text('notes')->nullable();
            // INST-05i audit columns
            $table->string('signed_off_by', 255)->nullable();
            // auth()->user()->name snapshot at time of pass/fail/na

            $table->timestamp('signed_off_at')->nullable();

            $table->timestamps();
            $table->softDeletes();    // D-04 re-sync preserves audit trail

            $table->index('install_programme_id');
            $table->index(['install_programme_id', 'status']);
            $table->index(['install_programme_id', 'room_name']);
            $table->index('install_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_items');
    }
};
```

### Example 4: Schema — `commissioning_signoffs` migration (D-11)

```php
// database/migrations/2026_04_21_000004_create_commissioning_signoffs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_signoffs', function (Blueprint $table) {
            $table->id();

            // One signoff per programme (Pitfall 7 race guard)
            $table->foreignId('install_programme_id')
                  ->unique()
                  ->constrained('install_programmes')
                  ->cascadeOnDelete();

            $table->string('client_name',    200);
            $table->string('client_role',    200);
            $table->string('client_company', 200);

            $table->longText('signature_png_base64');
            // INST-05f: "signature stored as base64 PNG". longText because a
            // 300x150 PNG is typically 5-15KB but Retina/iPad can push to 40-60KB.
            // Sanitised at storage time (strip whitespace/linebreaks).

            $table->string('snagging_pdf_path', 500);
            // DocumentArtifactStorage filename; resolved via readPath(TYPE_SNAGGING, …)

            $table->timestamp('signed_at');
            $table->foreignId('signed_off_engineer_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            // The engineer operating the device — for dual-attestation audit
            // (client name in client_name, engineer identity via FK).

            $table->timestamps();
            // No softDeletes — signoff is permanent (INST-05i immutability)

            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_signoffs');
    }
};
```

### Example 5: Extend DocumentArtifactStorage with TYPE_SNAGGING

```php
// app/Services/DocumentArtifactStorage.php (additions only)
class DocumentArtifactStorage
{
    public const TYPE_RAMS      = 'rams';
    public const TYPE_OM        = 'om-manuals';
    public const TYPE_WORKSHEET = 'worksheets';
    public const TYPE_CABLE     = 'cable-schedules';
    public const TYPE_SNAGGING  = 'snagging';   // Phase 16 addition

    // Legacy roots — Phase 16 is greenfield so no legacy fallback needed.
    // But the LEGACY_ROOTS map must at least contain an entry for TYPE_SNAGGING
    // (can be any harmless path that no files will ever exist at) so the
    // readPath() fallback code doesn't throw.
    private const LEGACY_ROOTS = [
        self::TYPE_RAMS      => 'app/rams',
        self::TYPE_OM        => 'app/om-manuals',
        self::TYPE_WORKSHEET => 'app/private/worksheets',
        self::TYPE_CABLE     => 'app/private/cable-schedules',
        self::TYPE_SNAGGING  => 'app/private/snagging',   // never populated — defensive
    ];

    public function types(): array
    {
        return [
            self::TYPE_RAMS,
            self::TYPE_OM,
            self::TYPE_WORKSHEET,
            self::TYPE_CABLE,
            self::TYPE_SNAGGING,
        ];
    }
}
```

Existing `tests/Unit/Services/DocumentArtifactStorageTest.php` [VERIFIED: ls tests/Unit/Services/] must be extended to cover `TYPE_SNAGGING`.

### Example 6: Snagging PDF service with H-07 convention

```php
// app/Services/CommissioningPdfService.php
namespace App\Services;

use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use Dompdf\Dompdf;
use Dompdf\Options;

class CommissioningPdfService
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    /**
     * Build a preview snagging PDF with no signature block (pre-sign review).
     */
    public function buildPreview(InstallProgramme $programme): string
    {
        return $this->render($programme, signoff: null, suffix: 'preview');
    }

    /**
     * Build the final snagging PDF with embedded signature.
     */
    public function buildFinal(InstallProgramme $programme, CommissioningSignoff $signoff): string
    {
        return $this->render($programme, signoff: $signoff, suffix: 'final');
    }

    private function render(
        InstallProgramme $programme,
        ?CommissioningSignoff $signoff,
        string $suffix,
    ): string {
        $programme->load(['project', 'commissioningItems']);

        $html = view('pdf.commissioning-snagging', [
            'programme' => $programme,
            'project'   => $programme->project,
            'items'     => $programme->commissioningItems()
                             ->orderBy('room_name')
                             ->orderBy('equipment_name')
                             ->orderBy('category')
                             ->get(),
            'fails'     => $programme->commissioningItems()
                             ->where('status', 'fail')
                             ->orderBy('room_name')
                             ->get(),
            'signoff'   => $signoff,
        ])->render();

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        // allowed_protocols default includes data:// — no override needed.

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf(
            'snagging_programme_%d_%s_%s.pdf',
            $programme->id,
            now()->format('Ymd_His'),
            $suffix,
        );

        $absolutePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);
        file_put_contents($absolutePath, $dompdf->output());

        return $filename;   // caller stores in commissioning_signoffs.snagging_pdf_path
    }
}
```

### Example 7: State-machine call from signoff finalise

```php
// app/Services/CommissioningService.php (excerpt)
public function finalise(
    InstallProgramme $programme,
    array $payload,   // validated FormRequest output
): CommissioningSignoff {
    return DB::transaction(function () use ($programme, $payload) {
        $programme = InstallProgramme::where('id', $programme->id)
            ->lockForUpdate()
            ->firstOrFail();
        $project = $programme->project;

        if (! $project->canTransitionTo(Project::STATUS_COMMISSIONING)) {
            throw CommissioningSignoffException::invalidStateTransition(
                current: $project->status,
                desired: Project::STATUS_COMMISSIONING,
            );
        }

        // INST-05g: all items must be pass/fail/na
        $pendingCount = $programme->commissioningItems()
            ->whereIn('status', [CommissioningItem::STATUS_PENDING])
            ->count();
        if ($pendingCount > 0) {
            throw CommissioningSignoffException::itemsStillPending($pendingCount);
        }

        // Create signoff (UNIQUE FK protects against race)
        $signoff = CommissioningSignoff::create([
            'install_programme_id'    => $programme->id,
            'client_name'             => $payload['client_name'],
            'client_role'             => $payload['client_role'],
            'client_company'          => $payload['client_company'],
            'signature_png_base64'    => $this->sanitiseBase64($payload['signature_png_base64']),
            'signed_at'               => now(),
            'signed_off_engineer_id'  => auth()->id(),
            'snagging_pdf_path'       => 'pending',   // updated below after buildFinal
        ]);

        // Regenerate snagging PDF with signature embedded (D-10)
        $finalFilename = $this->pdfService->buildFinal($programme, $signoff);
        $signoff->update(['snagging_pdf_path' => $finalFilename]);

        // Advance state
        $project->update([
            'status'                   => Project::STATUS_COMMISSIONING,
            'commissioning_started_at' => now(),
        ]);

        Log::info('CommissioningService: programme signed off', [
            'programme_id' => $programme->id,
            'project_id'   => $project->id,
            'signoff_id'   => $signoff->id,
            'engineer_id'  => auth()->id(),
            'client_name'  => $payload['client_name'],
        ]);

        return $signoff;
    });
}

private function sanitiseBase64(string $raw): string
{
    // Strip data-URI prefix if present
    $raw = preg_replace('#^data:image/png;base64,#', '', $raw);
    // Strip whitespace/linebreaks (Pitfall 5)
    return preg_replace('/\s+/', '', $raw) ?? '';
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| DomPDF data-URI rejected without remote enabled | Data URIs always allowed via `allowed_protocols['data://']` (default) | dompdf 3.1.0 (Jan 2025), fixed through 3.1.5 | No config change needed in existing `PdfService` pattern |
| signature_pad canvas sizing relied on CSS only | Canvas backing store must be multiplied by `devicePixelRatio`; context scaled | signature_pad 2.x (~2018) — still the pattern in 5.1.3 | Required on iOS Retina — locked by INST-05f + Technical Constraints |
| creagia/laravel-sign-pad v1-2 | v3.0.1 with Laravel 11/12/13, PHP 8.2+ | 2026-03-15 | Safe for this project |

**Deprecated/outdated:**
- Any pre-v3 creagia examples in Stack Overflow / Laracasts: API shape changed. Read v3 README only.
- DomPDF < 3.1.1 base64 SVG/PNG bugs: irrelevant — project is on 3.1.5.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Real `install_tasks.equipment_name` values follow the AV vocabulary patterns in the keyword map (e.g., "LG 75NANO" contains "display" keyword via classification upstream; actual strings may be terser part numbers like "75UH5F" that match nothing) | §Keyword Map Shape | First engineer-review of generated items surfaces zero items for real rooms → trigger urgent config/commissioning.php keyword additions. Mitigated by D-04 re-sync button — engineer can add keywords and hit re-sync without losing work. |
| A2 | creagia's bundled `sign-pad.min.js` exposes the `SignaturePad` constructor on the global `window` namespace so our DPI-resize snippet can re-instantiate after resize | §Pitfall 2 | If not exposed: our resize will silently stop working after first orientation change. Mitigation: Plan 01 spike task to inspect the published file and confirm integration hook. Fallback: load szimek/signature_pad@5.1.3 UMD from CDN in parallel. |
| A3 | The "Complete Commissioning" button must fully unlock when no items are `pending` (all are `pass`/`fail`/`na`) — empty state where a programme has zero commissioning items at all (D-07 — everything unmatched) also unlocks | §Signoff flow | If empty = locked: programmes with no applicable AVIXA items block project advancement forever. Recommended resolution: treat empty `commissioning_items` as "no items applicable" → button unlocked with zero-item snagging PDF. Flag for user confirmation in discuss-phase. |
| A4 | Snagging PDF memory footprint fits in default PHP 128M at typical project scale (50-100 items + thumbnails + signature) | §Pitfall 9 | OOM in production. Mitigation: Plan 03 load test with a 300-item fixture. |
| A5 | `HeicImageConverter` is thread-safe / instance-shareable when the DI container injects the same instance across controllers | §Architecture Patterns | No leakage expected — service has no mutable state beyond a lazily-initialized ImageManager (private to the instance). Confidence high; noting for planner. |

## Open Questions

1. **Empty-state unlock behaviour** (see A3 above)
   - What we know: D-07 means some programmes will have zero commissioning items if no equipment matches any keyword.
   - What's unclear: does "Complete Commissioning" unlock in that case?
   - Recommendation: Treat zero items as "all done" → unlock. User confirms in discuss-phase or planner locks in Plan 02.

2. **Evidence-photo requirement on `fail`** — Claude's Discretion says REQUIRED. But should photo also be required on `pass` for specific AVIXA categories (e.g., Power On always wants a photo of the illuminated device)?
   - What we know: D-12 says fail-items don't block sign-off and must roll into the snagging PDF — photos make that defensible.
   - What's unclear: is pass-with-photo also a real requirement?
   - Recommendation: Plan 02 makes photo required on `fail` only; leave `pass`/`na` photos optional. Revisit after field feedback.

3. **Certification text versioning** — if the legal wording in `config/commissioning.php` changes, older signoffs still reference the old wording at the PDF level, but the config change affects future renders. Should the signed text be stored on the `commissioning_signoffs` row for immutability?
   - Recommendation: Yes — add `certification_text` longText column to `commissioning_signoffs` and snapshot at signoff time. Cheap audit defensibility. Plan 01 adds the column.

4. **Phase 16's relationship to `InstallProgramme.status = complete`** — when do we set the programme's `status` to `complete`? On generator fire? On signoff? Neither?
   - What we know: `InstallProgramme` has a `STATUS_COMPLETE` constant but the lifecycle isn't wired to an automatic trigger yet.
   - Recommendation: Advance `InstallProgramme.status` to `complete` in the same transaction as the signoff finalise (immediately after `Project.status` transition). Add to Plan 04 service.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Framework | ✓ | 8.2+ (composer.json line 9) [VERIFIED: composer.json] | — |
| `ext-imagick` | HEIC conversion (reused from Phase 14) | ✓ | (Phase 14 runtime check) | Fail-loud per D-11 of Phase 14 |
| `dompdf/dompdf` | Snagging PDF | ✓ | v3.1.5 [VERIFIED: composer.lock] | — |
| `barryvdh/laravel-dompdf` | Blade → PDF wrapper | ✓ | v3.1.1 [VERIFIED: composer.lock] | — |
| `intervention/image` v3 | Phase 14 `HeicImageConverter` | ✓ | ^3 [VERIFIED: composer.json] | — |
| `creagia/laravel-sign-pad` | Signature canvas | ✗ | — (needs `composer require`) | No fallback — locked by INST-05f |
| MySQL 8+ | `longText` column + FK constraints | ✓ | (assumed — project config points to `127.0.0.1:3306`) | [ASSUMED] — flagged in A-env-1 |
| Node + Vite | Build-time JS asset compilation | ✓ | (existing) | — |

**Missing dependencies with no fallback:**
- `creagia/laravel-sign-pad` — Plan 01 runs `composer require creagia/laravel-sign-pad:^3.0 && php artisan sign-pad:install && php artisan vendor:publish --tag=sign-pad-assets && php artisan migrate`.

**Missing dependencies with fallback:**
- None.

## Validation Architecture

> Phase targets `workflow.nyquist_validation` — include the section. Project tests are PHPUnit 11.5.3 [VERIFIED: composer.json], SQLite in-memory DB for feature tests [VERIFIED: phpunit.xml].

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 [VERIFIED: composer.json line 32] |
| Config file | `phpunit.xml` [VERIFIED: repo root] |
| Quick run command | `php artisan test --filter=Commissioning` |
| Full suite command | `php artisan test` (or `composer test` — runs `config:clear` then `artisan test`) |
| DB | `sqlite :memory:` [VERIFIED: phpunit.xml] |

### Phase Requirements → Test Map

| Req ID | Behaviour | Test Type | Automated Command | File Exists? |
|--------|-----------|-----------|-------------------|--------------|
| INST-05a | `commissioning_items` schema matches spec | integration | `php artisan test tests/Feature/Commissioning/CommissioningSchemaTest.php -x` | ❌ Wave 0 |
| INST-05b | Generator creates items per equipment × AVIXA category via keyword match | unit | `php artisan test tests/Unit/Services/CommissioningItemGeneratorTest.php -x` | ❌ Wave 0 |
| INST-05b + D-03 | Generation trigger fires when last install_task flips to complete | integration | `php artisan test tests/Feature/Commissioning/GenerationTriggerTest.php -x` | ❌ Wave 0 |
| INST-05b + D-04 | Re-sync preserves existing statuses, soft-deletes removed, adds new | unit | `php artisan test tests/Unit/Services/CommissioningSyncServiceTest.php -x` | ❌ Wave 0 |
| INST-05c | Per-item AJAX: status PATCH returns counters, respects immutability | integration | `php artisan test tests/Feature/Commissioning/ItemStatusPatchTest.php -x` | ❌ Wave 0 |
| INST-05c | Per-item AJAX: notes PATCH | integration | `php artisan test tests/Feature/Commissioning/ItemNotesPatchTest.php -x` | ❌ Wave 0 |
| INST-05d | HEIC photo conversion produces JPEG on disk | integration | `php artisan test tests/Feature/Commissioning/ItemPhotoUploadTest.php -x` | ❌ Wave 0 |
| INST-05e | All seven AVIXA categories accepted; eighth rejected | unit | `php artisan test tests/Unit/Models/CommissioningItemTest.php -x` | ❌ Wave 0 |
| INST-05f | Signature base64 PNG round-trip — store sanitised, retrieve, embed in PDF | integration | `php artisan test tests/Feature/Commissioning/SignatureEmbedTest.php -x` | ❌ Wave 0 |
| INST-05f | DPI-scaling snippet included in signoff sheet view | integration | `php artisan test tests/Feature/Commissioning/SignoffSheetViewTest.php -x` (assertSee snippet marker) | ❌ Wave 0 |
| INST-05g | "Complete Commissioning" POST only succeeds with zero pending items | integration | `php artisan test tests/Feature/Commissioning/SignoffFinaliseTest.php -x` | ❌ Wave 0 |
| INST-05g | Snagging PDF generates with non-zero bytes and embeds signature image | integration | `php artisan test tests/Feature/Commissioning/SnaggingPdfGenerationTest.php -x` | ❌ Wave 0 |
| INST-05h | State transition `STATUS_INSTALLING → STATUS_COMMISSIONING` fires and is guarded | integration | `php artisan test tests/Feature/Commissioning/StateTransitionTest.php -x` | ❌ Wave 0 |
| INST-05i | Mutating endpoints return 422 after signoff | integration | `php artisan test tests/Feature/Commissioning/ImmutabilityAfterSignoffTest.php -x` | ❌ Wave 0 |
| Race | Double signoff POST → second fails at UNIQUE constraint → 422 | integration | `php artisan test tests/Feature/Commissioning/SignoffRaceTest.php -x` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=Commissioning` (~20-30 tests in scope)
- **Per wave merge:** `php artisan test --testsuite=Feature,Unit` (full)
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/Commissioning/` directory — doesn't exist yet
- [ ] `tests/Unit/Services/CommissioningItemGeneratorTest.php`
- [ ] `tests/Unit/Services/CommissioningSyncServiceTest.php`
- [ ] `tests/Unit/Services/CommissioningPdfServiceTest.php`
- [ ] `tests/Unit/Models/CommissioningItemTest.php`
- [ ] `tests/Unit/Models/CommissioningSignoffTest.php`
- [ ] Extension to `tests/Unit/Services/DocumentArtifactStorageTest.php` to cover `TYPE_SNAGGING`
- [ ] Test fixture for `creagia/laravel-sign-pad` — likely unused at the test layer since we mock the Blade output; planner confirms
- [ ] Shared factories: `CommissioningItemFactory`, `CommissioningSignoffFactory`

### Five-Tier Validation Dimensions (for VALIDATION.md)

| Tier | Scope | Example tests for Phase 16 |
|------|-------|----------------------------|
| **1 — Unit** | Pure logic on services + models | Keyword-map matching (`CommissioningItemGenerator::expectedItems`), signature base64 sanitiser, re-sync diff computation, enum validation on status transitions |
| **2 — Integration** | Services + DB + controllers stitched via routes | Full signoff flow (POST → signoff row → PDF write → state transition), per-item AJAX endpoints with Sanctum-less session, H-07 path round-trip for `TYPE_SNAGGING` |
| **3 — E2E** | HTTP layer including view rendering + auth + ownership guard | `FieldPageTest`-style feature test covering the `/projects/{project}/commissioning` GET — visible only with correct ownership, empty/non-empty states, re-sync diff rendering |
| **4 — Contract** | External-library assumptions locked in | DomPDF renders data-URI PNG to non-zero bytes (asserts our DomPDF version still supports base64 img), creagia vendor-published JS file exists on disk after `vendor:publish --tag=sign-pad-assets` (Plan 01 install test), HEIC fail-loud under missing imagick (existing HeicImageConverterTest extension) |
| **5 — Perf (optional)** | Memory + runtime on large fixtures | Snagging PDF with 300 items + 300 thumbnail JPEGs stays under 256M memory; generator runs in <1s for a 500-task programme |

## Sources

### Primary (HIGH confidence)

- `app/Models/Project.php` (read lines 1-293) — state-machine transitions at 41-50, `canTransitionTo()` at 237-256
- `app/Models/InstallTask.php` (read full) — `STATUS_COMPLETE` constant at line 34
- `app/Models/InstallProgramme.php` (read full)
- `app/Services/HeicImageConverter.php` (read full) — fail-loud pattern
- `app/Services/TaskPhotoService.php` (read full) — photo upload service pattern
- `app/Services/DocumentArtifactStorage.php` (read full) — H-07 convention + `types()` / `writePath()` / `readPath()`
- `app/Services/PdfService.php` (read full) — existing DomPDF wrapper pattern
- `app/Services/InstallTaskGeneratorService.php` (read full) — pattern mirror for the CommissioningItemGenerator
- `app/Http/Controllers/TaskStatusController.php` (read full) — ownership guard pattern
- `app/Http/Controllers/TaskPhotoController.php` (read full) — photo upload controller pattern
- `app/Exceptions/ClockInBlockedException.php` (read full) — domain exception pattern
- `resources/views/install-programmes/field.blade.php` (read full) — Alpine + fetch + CSRF pattern
- `resources/views/install-programmes/_field-sheet.blade.php` (read full) — bottom-sheet to fork
- `resources/views/pdf/rams.blade.php` (read lines 1-230) — DomPDF Blade CSS patterns for snagging PDF
- `config/worksheet_taxonomy.php` (read partial) — shape precedent for `config/commissioning.php`
- `database/migrations/2026_04_14_000002_create_install_tasks_table.php` (read full) — schema conventions
- `database/migrations/2026_04_20_000001_create_install_task_photos_table.php` (read full)
- `composer.json` + `composer.lock` — version verification
- `phpunit.xml` (read partial) — test runner config
- `.planning/REQUIREMENTS.md` §INST-05 (lines 170-182) + Technical Constraints (lines 194-197)
- `.planning/phases/16-commissioning-checklist-signoff/16-CONTEXT.md` — locked decisions + canonical refs
- `.planning/phases/14-mobile-field-view/14-CONTEXT.md` — HEIC + bottom-sheet patterns

### Secondary (MEDIUM confidence)

- [szimek/signature_pad README — Handling High-DPI Screens](https://github.com/szimek/signature_pad#handling-high-dpi-screens) — canonical `resizeCanvas` snippet
- [dompdf/dompdf Options.php v3.1.5 defaults](https://raw.githubusercontent.com/dompdf/dompdf/v3.1.5/src/Options.php) — confirmed `data://` in default `allowed_protocols`
- [creagia/laravel-sign-pad on Packagist](https://packagist.org/packages/creagia/laravel-sign-pad) — v3.0.1 Laravel + PHP constraints
- [creagia/laravel-sign-pad Blade component source](https://github.com/creagia/laravel-sign-pad/blob/main/resources/views/components/signature-pad.blade.php) — verified component has no DPI scaling; has `<input name="sign">` hidden field
- [dompdf releases page](https://github.com/dompdf/dompdf/releases) — 3.1.1 / 3.1.5 data-URI regression fix commits

### Tertiary (LOW confidence — flagged for validation)

- WebSearch around signature_pad DPI issues — individual Stack Overflow / Laracasts threads not cross-checked beyond the szimek README; the README is authoritative so this is low-risk.
- [dev.to DomPDF base64 guide](https://dev.to/bhaidar/efficiently-rendering-base64-images-in-laravel-pdfs-with-dompdf-16pk) — third-party guide; used for confirmation only, superseded by reading the dompdf source.

## Metadata

**Confidence breakdown:**

- Schema / migrations / service-layer structure: **HIGH** — direct clones of Phase 12 / 14 / 15 patterns.
- D-03 observer trigger: **HIGH** — Laravel Eloquent observer is canonical.
- H-07 `TYPE_SNAGGING` extension: **HIGH** — straightforward.
- DomPDF base64 embedding: **HIGH** — verified in source at v3.1.5; no regressions.
- State-machine call-site: **HIGH** — Project model already exposes `canTransitionTo()`.
- AVIXA keyword map coverage: **MEDIUM** — depends on real equipment-name strings (see A1).
- creagia/laravel-sign-pad integration at the JS layer (SignaturePad instance access for resize): **MEDIUM** — plan 01 spike required (see A2 / Pitfall 2).
- Signature round-trip size / PDF memory: **MEDIUM** — no measurement yet (see A4).
- Snagging PDF empty-state behaviour: **LOW** — user confirmation required (see A3).

**Research date:** 2026-04-21
**Valid until:** 2026-05-21 (30 days for stable ecosystem; re-verify creagia/laravel-sign-pad version before Plan 01 execute)
