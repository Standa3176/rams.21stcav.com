---
quick_id: 260504-gho
mode: quick
type: plan
phase: quick-260504-gho
plan: 01
wave: 1
depends_on: []
autonomous: true
tags: [worksheet, rams, docx, blade, additive, output-documents]
requirements:
  - "REGEN-WORKSHEET — show-page Regenerate button (and index-row icon if actions cell exists) wiring to existing worksheets.retry-generation route"
  - "WS-SITE-LOGISTICS — Worksheet DOCX cover header AND public engineer view show the 7 site-logistics fields from the project's latest SiteSurvey"
  - "RAMS-DOCX-MIRROR — RAMS DOCX gains the Site Logistics section + per-room Engineer Survey Findings blocks already shipped to PDF (260503-tfb)"
files_modified:
  - resources/views/worksheets/show.blade.php
  - resources/views/worksheets/index.blade.php
  - app/Services/WorksheetDocxService.php
  - resources/views/worksheets/public-show.blade.php
  - app/Services/DocxBuilderService.php
must_haves:
  truths:
    - "User can click Regenerate Worksheet on the worksheet show page and the BuildWorksheetJob is dispatched, status flips to GENERATING, page returns to show with success flash."
    - "User can click the same regen action from the worksheets index actions cell (if the row has an actions cell) and observe the same outcome."
    - "Engineer downloading the Worksheet DOCX sees a 'Site Logistics' block on the cover page below the Client/Site/Reference/Date table when the project has site-logistics data; the block is COMPLETELY ABSENT for projects with no/empty site logistics (legacy regression-safe)."
    - "Engineer opening the public worksheet (/worksheets/{token}) sees a teal-bordered '📋 Site Logistics' details drawer at project level (above the per-room cards) showing the 5 fields when populated; drawer is ABSENT when no data."
    - "Engineer downloading the RAMS DOCX sees a Site Logistics & Access section in Section 4 (matches PDF) AND per-room 'Engineer Survey Findings — {room name}' blocks (matches PDF) when data exists; rooms with empty engineer_feedback render byte-identical to pre-change DOCX."
  artifacts:
    - path: "resources/views/worksheets/show.blade.php"
      provides: "Regenerate Worksheet POST form alongside the Download button (action-area lines 105-125 and footer lines 365-384)"
    - path: "resources/views/worksheets/index.blade.php"
      provides: "Per-row regen icon button in the existing actions cell at line 63 (only when status is in [draft, final] — failed worksheets already use the existing retry path)"
    - path: "app/Services/WorksheetDocxService.php"
      provides: "buildCoverHeader expanded with optional Site Logistics block (loads SiteSurvey via existing loadEngineerFeedbackByRoom-style helper, but at site level)"
    - path: "resources/views/worksheets/public-show.blade.php"
      provides: "Page-level <details class='room-drawer teal'> Site Logistics drawer between top header and rooms loop"
    - path: "app/Services/DocxBuilderService.php"
      provides: "New private buildSiteLogistics() called from buildScopeOfWorks (after summary header table, before equipment schedule) + new private buildEngineerFindingsByRoom() called from build() between buildScopeOfWorks and buildRiskAssessment"
  key_links:
    - from: "resources/views/worksheets/show.blade.php"
      to: "POST /worksheets/{worksheet}/retry-generation"
      via: "<form method='POST' action='{{ route(\"worksheets.retry-generation\", $worksheet) }}'>"
      pattern: "worksheets\\.retry-generation"
    - from: "app/Services/WorksheetDocxService.php"
      to: "App\\Models\\SiteSurvey"
      via: "Reuse the existing loadEngineerFeedbackByRoom pattern at line 798 — SiteSurvey::with('rooms')->where('project_id', ...)->latest('id')->first(), but expose site-level columns too"
      pattern: "SiteSurvey::.*latest\\('id'\\)"
    - from: "resources/views/worksheets/public-show.blade.php"
      to: "App\\Models\\SiteSurvey"
      via: "Extend the existing inline @php block at line 442 — already loads $survey via the same lookup; just additionally read the 7 site-level columns into $siteLogistics"
      pattern: "class_exists\\(\\\\App\\\\Models\\\\SiteSurvey::class\\)"
    - from: "app/Services/DocxBuilderService.php"
      to: "$data['site_logistics'] (already populated by RamsDataBuilderService since 260503-tfb)"
      via: "Read $data['site_logistics'] inside new buildSiteLogistics() — same shape as resources/views/pdf/rams.blade.php lines 714-734"
      pattern: "\\$data\\['site_logistics'\\]"
    - from: "app/Services/DocxBuilderService.php"
      to: "$data['rooms'][n]['engineer_feedback'] (already populated by ProjectContextBuilder since 260503-tfb)"
      via: "Read $data['rooms'] inside new buildEngineerFindingsByRoom() — same shape as resources/views/pdf/rams.blade.php lines 781-1033"
      pattern: "engineer_feedback"
---

<objective>
Three bundled output-document improvements that close the v1.3.x quick-task backlog from 260503-tfb (RAMS PDF only — DOCX deferred) and 260504-dh8 (worksheet drawer at room level — site-level deferred):

1. **Regen worksheet button** — surface the already-existing `worksheets.retry-generation` route on the show page (and index actions cell). Engineers currently have to delete + recreate worksheets when the project data changes.
2. **Site logistics on worksheet** — engineers arriving on site need parking, comms-room access, depot distance and delivery routes ONCE per visit, on both the printed DOCX (cover header) and the tablet view (public-show drawer).
3. **RAMS DOCX parity with RAMS PDF** — the Site Logistics block and per-room Engineer Survey Findings blocks already render in the PDF (since 260503-tfb) but the DOCX still produces the pre-260503 output. This closes that explicit follow-up noted in the 260503-tfb summary line 214.

Purpose: complete the engineer-feedback rendering loop. After this task, every output document (RAMS PDF + RAMS DOCX + Worksheet DOCX + public worksheet view) surfaces the 17 fields captured in the engineer's site survey.

Output: 5 modified files, ~3 commits, zero new schema/routes/controllers.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/STATE.md
@.planning/quick/260503-tfb-wire-site-survey-engineer-feedback-field/260503-tfb-SUMMARY.md
@.planning/quick/260504-dh8-survey-worksheet-output-usability-site-c/260504-dh8-SUMMARY.md
@resources/views/pdf/rams.blade.php
@resources/views/worksheets/show.blade.php
@resources/views/worksheets/index.blade.php
@resources/views/worksheets/public-show.blade.php
@app/Services/WorksheetDocxService.php
@app/Services/DocxBuilderService.php
@app/Http/Controllers/WorksheetController.php
@routes/web.php

<interfaces>
<!-- Key contracts the executor will lean on. Extracted from the codebase. -->
<!-- Use these directly — no exploration needed. -->

From routes/web.php:381 (already wired — DO NOT add a new route):
```
Route::post('worksheets/{worksheet}/retry-generation',
    [WorksheetController::class, 'retryGeneration'])
    ->name('worksheets.retry-generation');
```

From app/Http/Controllers/WorksheetController.php:199-223 (already implemented):
```php
public function retryGeneration(Worksheet $worksheet): RedirectResponse
{
    abort_if(... !owner && !admin, 403);
    if ($worksheet->status === Worksheet::STATUS_GENERATING) {
        return back()->with('error', 'This worksheet is already being generated. Please wait.');
    }
    $worksheet->update(['status' => Worksheet::STATUS_GENERATING]);
    app(WorkerMonitorService::class)->ensureRunning();
    BuildWorksheetJob::dispatch($worksheet->id);
    return back()->with('success', 'Worksheet regeneration queued. ...');
}
```

From app/Services/WorksheetDocxService.php:798-827 (REUSE this pattern for site-level lookup):
```php
private function loadEngineerFeedbackByRoom(Worksheet $worksheet): array
{
    if (! $worksheet->project_id) return [];
    $survey = SiteSurvey::with('rooms')
        ->where('project_id', $worksheet->project_id)
        ->latest('id')
        ->first();
    if ($survey === null) return [];
    // ... reads per-room cols
}
```
The new helper for site-level data (`loadSiteLogistics(Worksheet $worksheet): array`)
follows the same shape but reads 7 columns directly from $survey (NOT $survey->rooms):
- `comms_room_access_status` (enum: yes|no|outsourced|unknown|null)
- `comms_room_access_notes` (text|null)
- `parking_restraints` (text|null)
- `distance_from_base_miles` (decimal|null)
- `distance_from_base_notes` (text|null)
- `site_access_notes` (text|null)
- `delivery_routes` (text|null)

From resources/views/pdf/rams.blade.php:714-734 (Site Logistics PDF rendering — copy into DOCX):
```blade
@php
    $siteLog    = $data['site_logistics'] ?? [];
    $hasSiteLog = is_array($siteLog) && (
        ! empty($siteLog['comms_room_access_status']) ||
        ! empty($siteLog['comms_room_access_notes']) ||
        ! empty($siteLog['parking_restraints']) ||
        ! empty($siteLog['distance_from_base_miles']) ||
        ! empty($siteLog['distance_from_base_notes']) ||
        ! empty($siteLog['site_access_notes']) ||
        ! empty($siteLog['delivery_routes'])
    );
    $commsLabels = [
        'yes' => 'Permission required', 'no' => 'Free access',
        'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
    ];
@endphp
```

From resources/views/pdf/rams.blade.php:781-1033 (per-room Engineer Survey Findings —
copy into a new DOCX builder method as PhpWord paragraphs/tables. The 7 sub-blocks
have INDEPENDENT @if guards — preserve that defensiveness in the DOCX port).

From app/Services/DocxBuilderService.php:74-108 — build() method order:
```php
$this->buildCoverPage(...);              // 1
$this->buildDocumentControl(...);        // 2
$this->buildCompanyInformation(...);     // 3
$this->buildHealthSafetyPolicy(...);     // 4
$this->buildCdmSection(...);             // 5
$this->buildScopeOfWorks(...);           // 6  ← Site Logistics rendered INSIDE this (after summary header table)
$this->buildRiskAssessment(...);         // 7
$this->buildMethodStatement(...);        // 8
$this->buildEmergencyProcedures(...);    // 9
$this->buildDocumentSignOff(...);        // 10
```
**Engineer Findings per room** — buildScopeOfWorks does NOT have a per-room loop
(it builds a single Equipment Schedule table). buildMethodStatement also doesn't.
Decision: add a NEW dedicated section method `buildEngineerFindingsByRoom()` called
between buildScopeOfWorks and buildRiskAssessment in the build() sequence. Defensive:
when no rooms have engineer_feedback, the entire section (including its page-break
+ heading) is suppressed.

From app/Services/WorksheetDocxService.php:34-40 — Brand colours (REUSE these):
```php
private const TEAL  = '178A95';
private const WHITE = 'FFFFFF';
private const DARK  = '0B3C45';
private const GREY  = 'F3F6F7';
private const MID   = 'E5E7EB';
```

From app/Services/DocxBuilderService.php:42-50 — Brand colours (REUSE these):
```php
private const TEAL = '007B8A';
private const DARK_GREY = '333333';
private const ROW_ALT = 'F0FBFC';
private const WHITE = 'FFFFFF';
```

From resources/views/worksheets/public-show.blade.php:442-492 — existing $efByRoom
@php block + label maps. The new Site Logistics drawer EXTENDS this same @php block
to read the 7 site-level columns from `$survey` directly (single SiteSurvey query;
zero new DB queries — the survey is already loaded for the per-room drawer).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Wire Regenerate Worksheet button on show + index views</name>
  <files>resources/views/worksheets/show.blade.php, resources/views/worksheets/index.blade.php</files>
  <action>
**resources/views/worksheets/show.blade.php** — Add a Regenerate button to the action area at the top of the page (between lines 107-125, alongside the existing Download/Back/History buttons) AND to the footer action row (lines 365-384, near the second Download button). The button is rendered ONLY when `$worksheet->status` is in `['draft', 'final', 'failed']` (mirrors the existing Download button visibility rule but adds 'failed' so engineers can retry after a job exception).

Pattern (use VERBATIM in both spots):
```blade
@if(in_array($worksheet->status, ['draft', 'final', 'failed']))
    <form method="POST"
          action="{{ route('worksheets.retry-generation', $worksheet) }}"
          onsubmit="return confirm('Regenerate this worksheet? The current DOCX will be replaced.');"
          style="display:inline;">
        @csrf
        <button type="submit"
                class="btn-outline btn-sm"
                aria-label="Regenerate Worksheet DOCX">
            ↻ Regenerate
        </button>
    </form>
@endif
```

In the page-header area (line 107-125), insert this form RIGHT AFTER the existing `<a href="{{ route('worksheets.download', $worksheet) }}" ...>↓ Download</a>` block (around line 113) and BEFORE the "Back to Project" link.

In the footer action row (line 365-384), insert it inside the existing left `<div>` (line 366-375) RIGHT AFTER the "Download DOCX" link.

**resources/views/worksheets/index.blade.php** — The index DOES have an actions cell at line 63-71. Add the regen button INSIDE that cell, after the Download link (line 65-70):
```blade
@if(in_array($w->status, ['draft', 'final', 'failed']))
    <form method="POST"
          action="{{ route('worksheets.retry-generation', $w) }}"
          onsubmit="return confirm('Regenerate this worksheet? The current DOCX will be replaced.');"
          style="display:inline;">
        @csrf
        <button type="submit"
                class="btn-outline btn-sm"
                aria-label="Regenerate Worksheet DOCX"
                title="Regenerate">↻</button>
    </form>
@endif
```
Use just the ↻ icon (no text label) on the index — actions cell is space-constrained and the title attribute provides the tooltip. Failed worksheets already render a Generate button via this same route in some flows (verify by re-reading lines 63-71 — if a separate "Retry" button exists for status='failed', skip it and only render for draft/final to avoid duplication).

**Defensive notes:**
- Both forms use the existing `worksheets.retry-generation` route name (verified at routes/web.php:381). DO NOT add a new route.
- Use `btn-outline btn-sm` so it's visually subordinate to the teal Download button (regen is recovery, not the primary action).
- The confirm() prompt prevents accidental clicks — engineers regenerating worksheets in batch may otherwise wipe state mid-edit.
- After running php -l smoke check on both files, view:cache rebuild via `php artisan view:clear`.

**OUT OF SCOPE** for this task:
- No JS for async toast — the controller's `back()->with('success', ...)` already drives the existing alert-success banner at the top of show.blade.php and index.blade.php.
- No new CSS classes — reuse `btn-outline` / `btn-sm`.
  </action>
  <verify>
    <automated>php -l resources/views/worksheets/show.blade.php && php -l resources/views/worksheets/index.blade.php && php artisan view:clear &amp;&amp; php artisan view:cache</automated>
    Manual smoke (post-commit): hit `/worksheets/{id}` for a draft worksheet, click ↻ Regenerate, confirm, verify status flips to "Generating…" and BuildWorksheetJob is queued (check `php artisan queue:work --once` output OR jobs table). Hit `/worksheets` index, confirm a ↻ button appears in the actions cell next to ↓ Download.
  </verify>
  <done>
    - show.blade.php has TWO regen forms (header action area + footer card) — both posting to `worksheets.retry-generation`.
    - index.blade.php has ONE regen icon button per actions cell.
    - `git diff --stat app/ routes/ database/ config/` returns EMPTY for this commit.
    - `php artisan view:cache` succeeds.
    - Confirm dialog text matches verbatim (single source-of-truth UX wording).
  </done>
</task>

<task type="auto">
  <name>Task 2: Surface site logistics on Worksheet DOCX cover + public engineer view</name>
  <files>app/Services/WorksheetDocxService.php, resources/views/worksheets/public-show.blade.php</files>
  <action>
**app/Services/WorksheetDocxService.php** — Add a new private helper `loadSiteLogistics(Worksheet $worksheet): array` that mirrors the existing `loadEngineerFeedbackByRoom()` pattern at line 798 but reads SITE-level columns from the SiteSurvey (NOT the per-room SiteSurveyRoom rows).

Insert IMMEDIATELY AFTER `loadEngineerFeedbackByRoom()` (around line 828):
```php
/**
 * Load site-level logistics columns from the project's latest SiteSurvey.
 *
 * Returns [] when the worksheet has no project_id, the project has no
 * SiteSurvey, OR every column is null/empty — caller treats [] as
 * "no Site Logistics block on the cover page".
 *
 * @return array<string, mixed>
 */
private function loadSiteLogistics(Worksheet $worksheet): array
{
    if (! $worksheet->project_id) return [];

    $survey = SiteSurvey::where('project_id', $worksheet->project_id)
        ->latest('id')
        ->first();

    if ($survey === null) return [];

    $out = [
        'comms_room_access_status' => (string) ($survey->comms_room_access_status ?? ''),
        'comms_room_access_notes'  => (string) ($survey->comms_room_access_notes  ?? ''),
        'parking_restraints'       => (string) ($survey->parking_restraints       ?? ''),
        'distance_from_base_miles' => $survey->distance_from_base_miles, // numeric or null
        'distance_from_base_notes' => (string) ($survey->distance_from_base_notes ?? ''),
        'site_access_notes'        => (string) ($survey->site_access_notes        ?? ''),
        'delivery_routes'          => (string) ($survey->delivery_routes          ?? ''),
    ];

    // Strict empty test — all 7 keys empty ⇒ return [] so caller no-ops.
    $hasAny = false;
    foreach ($out as $v) {
        if ($v !== '' && $v !== null) { $hasAny = true; break; }
    }
    return $hasAny ? $out : [];
}
```

In `build()` at line 59 (immediately AFTER the existing `$efByRoom = $this->loadEngineerFeedbackByRoom(...)` line), add:
```php
$siteLogistics = $this->loadSiteLogistics($worksheet);
```

Modify `buildCoverHeader()` signature to accept `array $siteLogistics = []` as a new 4th parameter (backwards-compat default), and update the build() call site at line 63 to pass it. Inside `buildCoverHeader()`, AFTER the existing meta-table foreach (line 133, BEFORE the `$section->addTextBreak(1); $section->addLine(...)` separator at line 135-136), add a defensive Site Logistics block:
```php
if (! empty($siteLogistics)) {
    $section->addTextBreak(1);
    $section->addText('SITE LOGISTICS — FROM SITE SURVEY',
        ['bold' => true, 'size' => 10, 'color' => self::TEAL, 'allCaps' => true],
        ['spaceAfter' => 60]);

    $commsLabels = [
        'yes' => 'Permission required', 'no' => 'Free access',
        'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
    ];

    $logisticsTable = $section->addTable([
        'borderSize' => 0, 'borderColor' => self::MID,
        'cellMarginLeft' => 100, 'cellMarginRight' => 100,
    ]);

    $rows = [];
    if (! empty($siteLogistics['parking_restraints'])) {
        $rows[] = ['Parking arrangements', $siteLogistics['parking_restraints']];
    }
    if (! empty($siteLogistics['site_access_notes'])) {
        $rows[] = ['Site access notes', $siteLogistics['site_access_notes']];
    }
    if (! empty($siteLogistics['delivery_routes'])) {
        $rows[] = ['Delivery routes', $siteLogistics['delivery_routes']];
    }
    if (! empty($siteLogistics['comms_room_access_status']) || ! empty($siteLogistics['comms_room_access_notes'])) {
        $statusLabel = $commsLabels[$siteLogistics['comms_room_access_status'] ?? ''] ?? '';
        $parts = array_filter([$statusLabel, $siteLogistics['comms_room_access_notes'] ?? '']);
        $rows[] = ['Comms room access', implode(' — ', $parts)];
    }
    if (! empty($siteLogistics['distance_from_base_miles']) || ! empty($siteLogistics['distance_from_base_notes'])) {
        $parts = array_filter([
            ! empty($siteLogistics['distance_from_base_miles'])
                ? $siteLogistics['distance_from_base_miles'] . ' miles from depot' : '',
            $siteLogistics['distance_from_base_notes'] ?? '',
        ]);
        $rows[] = ['Distance from depot', implode(' — ', $parts)];
    }

    foreach ($rows as [$label, $value]) {
        $row = $logisticsTable->addRow();
        $row->addCell(2000)->addText($label, ['bold' => true, 'size' => 10, 'color' => self::TEAL]);
        $row->addCell(7000)->addText($this->t((string) $value), ['size' => 10, 'color' => self::DARK]);
    }
}
```

**resources/views/worksheets/public-show.blade.php** — The existing @php block at line 442 ALREADY loads `$survey` (line 454-457) and iterates `$survey->rooms` for the per-room drawer. EXTEND the same block to ALSO read the 7 site-level columns from `$survey` directly (no second query):

After line 457 (`$survey = SiteSurvey::with('rooms')->where(...)->first();`), and BEFORE the existing `if ($survey)` block at line 458, add:
```blade
$siteLogistics = [];
if ($survey) {
    $siteLogistics = [
        'comms_room_access_status' => (string) ($survey->comms_room_access_status ?? ''),
        'comms_room_access_notes'  => (string) ($survey->comms_room_access_notes  ?? ''),
        'parking_restraints'       => (string) ($survey->parking_restraints       ?? ''),
        'distance_from_base_miles' => $survey->distance_from_base_miles,
        'distance_from_base_notes' => (string) ($survey->distance_from_base_notes ?? ''),
        'site_access_notes'        => (string) ($survey->site_access_notes        ?? ''),
        'delivery_routes'          => (string) ($survey->delivery_routes          ?? ''),
    ];
    $hasSiteLogistics = false;
    foreach ($siteLogistics as $v) {
        if ($v !== '' && $v !== null) { $hasSiteLogistics = true; break; }
    }
    if (! $hasSiteLogistics) $siteLogistics = [];
}
$commsRoomLabels = [
    'yes' => 'Permission required', 'no' => 'Free access',
    'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
];
```

Insert the new project-level Site Logistics drawer BETWEEN the existing `@if(empty($rooms))` block and the rooms `@foreach` (around line 494-499). It must render BEFORE the rooms loop (drawer is project-scoped, not per-room). Use the existing `.room-drawer.teal` class from quick task 260504-dh8 — DO NOT add new CSS classes:

```blade
@if(! empty($siteLogistics))
<details class="room-drawer teal" style="margin-bottom:1rem;">
    <summary class="room-drawer-summary">
        📋 Site Logistics — Arrival Info
    </summary>
    <div class="room-drawer-body">
        @if(! empty($siteLogistics['parking_restraints']))
            <div class="room-subsection">
                <div class="room-subsection-eyebrow">Parking arrangements</div>
                <div>{{ $siteLogistics['parking_restraints'] }}</div>
            </div>
        @endif
        @if(! empty($siteLogistics['site_access_notes']))
            <div class="room-subsection">
                <div class="room-subsection-eyebrow">Site access notes</div>
                <div>{{ $siteLogistics['site_access_notes'] }}</div>
            </div>
        @endif
        @if(! empty($siteLogistics['delivery_routes']))
            <div class="room-subsection">
                <div class="room-subsection-eyebrow">Delivery routes</div>
                <div>{{ $siteLogistics['delivery_routes'] }}</div>
            </div>
        @endif
        @if(! empty($siteLogistics['comms_room_access_status']) || ! empty($siteLogistics['comms_room_access_notes']))
            @php
                $statusLabel = $commsRoomLabels[$siteLogistics['comms_room_access_status'] ?? ''] ?? '';
                $parts = array_filter([$statusLabel, $siteLogistics['comms_room_access_notes'] ?? '']);
            @endphp
            <div class="room-subsection">
                <div class="room-subsection-eyebrow">Comms room access</div>
                <div>{{ implode(' — ', $parts) }}</div>
            </div>
        @endif
        @if(! empty($siteLogistics['distance_from_base_miles']) || ! empty($siteLogistics['distance_from_base_notes']))
            @php
                $parts = array_filter([
                    ! empty($siteLogistics['distance_from_base_miles'])
                        ? $siteLogistics['distance_from_base_miles'] . ' miles from depot' : '',
                    $siteLogistics['distance_from_base_notes'] ?? '',
                ]);
            @endphp
            <div class="room-subsection">
                <div class="room-subsection-eyebrow">Distance from depot</div>
                <div>{{ implode(' — ', $parts) }}</div>
            </div>
        @endif
    </div>
</details>
@endif
```

If the `.room-subsection` / `.room-subsection-eyebrow` classes don't already exist on this page (they may be inline-styled in 260504-dh8 instead — re-read the existing `<details class="room-drawer teal">` Survey Reference drawer in this same file to confirm the convention), use the SAME inline-style approach as the existing Survey Reference drawer rather than introducing new classes.

**Defensive notes:**
- Both code paths share the SAME defensive contract: if every column is empty/null, render NOTHING (no heading, no table, no drawer).
- The DOCX coverage adds a section between meta-table and the cover separator line — preserves the existing Client/Site/Reference/Date layout.
- The public view drawer is `<details>` (closed by default) — engineers can expand on arrival without cluttering the room cards.
- Existing pre-260503-rgg surveys (where the 7 columns are NULL) produce ZERO rendered output in either surface. Tests below verify this regression-safety.
  </action>
  <verify>
    <automated>php -l app/Services/WorksheetDocxService.php && php -l resources/views/worksheets/public-show.blade.php && php artisan view:clear &amp;&amp; php artisan view:cache</automated>
    Tinker render smoke (post-edit): `php artisan tinker --execute="$w = \App\Models\Worksheet::latest('id')->first(); $svc = app(\App\Services\WorksheetDocxService::class); $svc->build($w->generated_data ?? ['rooms' => [], 'project' => []], $w); echo 'OK';"` — should not throw. For public-show.blade.php: `view('worksheets.public-show', ['worksheet' => $w, 'latestSignoff' => null])->render()` should render without exception (existing render path verified working in 260504-dh8 task).
  </verify>
  <done>
    - WorksheetDocxService has a new `loadSiteLogistics()` private method.
    - `build()` calls it once and passes the result into `buildCoverHeader()` via a new optional 4th parameter.
    - `buildCoverHeader()` renders the SITE LOGISTICS heading + table ONLY when the array is non-empty.
    - public-show.blade.php @php block extended to populate `$siteLogistics`; new project-level `<details class="room-drawer teal">` drawer renders between the rooms-empty check and the rooms foreach, ONLY when `$siteLogistics` is non-empty.
    - Regression smoke: a worksheet whose project has NULL in all 7 columns produces a DOCX whose cover header shows ZERO new rows AND a public-show page that renders ZERO new drawers (verifiable via grep of rendered HTML / DOCX XML for "SITE LOGISTICS" — must be ABSENT).
    - `git diff --stat app/Models/ app/Http/Controllers/ routes/ database/ config/` returns EMPTY for this commit.
  </done>
</task>

<task type="auto">
  <name>Task 3: Mirror Site Logistics + per-room Engineer Findings into RAMS DOCX</name>
  <files>app/Services/DocxBuilderService.php</files>
  <action>
Add TWO new private methods to `app/Services/DocxBuilderService.php` and call them from `build()`. Both methods mirror the rendering already shipped to `resources/views/pdf/rams.blade.php` lines 714-734 (Site Logistics) and 781-1033 (per-room Engineer Survey Findings) since 260503-tfb.

**1. New method `buildSiteLogistics(PhpWord $phpWord, array $data): void`** — DO NOT add a new section. Render INSIDE `buildScopeOfWorks()` (modify it in place) AFTER the summary header table at line 358 (`$section->addTextBreak(1);`) and BEFORE the equipment-schedule comment block at line 360. Render is fully defensive — when `$data['site_logistics']` is empty/missing, this block adds NOTHING (zero ripple on existing DOCX byte output).

Implementation (insert immediately after line 358 in buildScopeOfWorks):
```php
// ── Site Logistics & Access (mirrors PDF — quick task 260503-tfb closure) ─
$siteLog = $data['site_logistics'] ?? [];
$hasSiteLog = is_array($siteLog) && (
    ! empty($siteLog['comms_room_access_status']) ||
    ! empty($siteLog['comms_room_access_notes']) ||
    ! empty($siteLog['parking_restraints']) ||
    ! empty($siteLog['distance_from_base_miles']) ||
    ! empty($siteLog['distance_from_base_notes']) ||
    ! empty($siteLog['site_access_notes']) ||
    ! empty($siteLog['delivery_routes'])
);
if ($hasSiteLog) {
    $section->addText(
        'Site Logistics & Access (from site survey)',
        $this->font(10, bold: true, colour: self::TEAL),
        ['spaceBefore' => 80, 'spaceAfter' => 60],
    );

    $commsLabels = [
        'yes' => 'Permission required', 'no' => 'Free access',
        'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
    ];

    $logTable = $section->addTable($this->tableStyle());
    $rowsLog = [];
    if (! empty($siteLog['parking_restraints'])) {
        $rowsLog[] = ['Parking arrangements', $siteLog['parking_restraints']];
    }
    if (! empty($siteLog['site_access_notes'])) {
        $rowsLog[] = ['Site access notes', $siteLog['site_access_notes']];
    }
    if (! empty($siteLog['delivery_routes'])) {
        $rowsLog[] = ['Delivery routes', $siteLog['delivery_routes']];
    }
    if (! empty($siteLog['comms_room_access_status']) || ! empty($siteLog['comms_room_access_notes'])) {
        $statusLabel = $commsLabels[$siteLog['comms_room_access_status'] ?? ''] ?? '';
        $parts = array_filter([$statusLabel, $siteLog['comms_room_access_notes'] ?? '']);
        $rowsLog[] = ['Comms room access', implode(' — ', $parts)];
    }
    if (! empty($siteLog['distance_from_base_miles']) || ! empty($siteLog['distance_from_base_notes'])) {
        $parts = array_filter([
            ! empty($siteLog['distance_from_base_miles'])
                ? $siteLog['distance_from_base_miles'] . ' miles from depot' : '',
            $siteLog['distance_from_base_notes'] ?? '',
        ]);
        $rowsLog[] = ['Distance from depot', implode(' — ', $parts)];
    }

    $altCellLog  = ['bgColor' => self::ROW_ALT];
    $whiteCellLog = ['bgColor' => self::WHITE];
    foreach ($rowsLog as $i => [$label, $value]) {
        $bg = ($i % 2 === 0) ? $altCellLog : $whiteCellLog;
        $row = $logTable->addRow(380);
        $row->addCell(3000, $bg)->addText($label, $this->font(9, bold: true));
        $row->addCell(6866, $bg)->addText($this->t((string) $value), $this->font(9));
    }

    $section->addTextBreak(1);
}
```

**2. New method `buildEngineerFindingsByRoom(PhpWord $phpWord, array $data): void`** — Add a NEW section between `buildScopeOfWorks` and `buildRiskAssessment` in the build() sequence. Render is fully defensive — when no rooms have any populated engineer_feedback fields, the section is suppressed entirely (no page-break, no heading).

Modify `build()` at line 91-92, insert NEW call:
```php
$this->buildScopeOfWorks($phpWord, $data, $formData);
$this->buildEngineerFindingsByRoom($phpWord, $data);   // ← NEW
$this->buildRiskAssessment($phpWord, $data);
```

Add the new method (place it AFTER `buildScopeOfWorks` end-brace at line 472, BEFORE `buildRiskAssessment` at line 478):

```php
// =========================================================================
// SECTION 4.5 — Engineer Survey Findings (per room)
//
// Mirrors resources/views/pdf/rams.blade.php lines 781-1033 from quick task
// 260503-tfb. Reads $data['rooms'][n]['engineer_feedback'] populated by
// ProjectContextBuilder. Whole section is suppressed when no rooms have
// any populated engineer_feedback fields — pre-260503 RAMS DOCX byte
// output is regression-safe.
// =========================================================================

private function buildEngineerFindingsByRoom(PhpWord $phpWord, array $data): void
{
    $rooms = (array) ($data['rooms'] ?? []);
    if (empty($rooms)) return;

    // Pre-flight: any room with non-empty engineer_feedback?
    $anyEf = false;
    foreach ($rooms as $room) {
        $ef = (array) ($room['engineer_feedback'] ?? []);
        if (! empty($ef) && (
            ! empty($ef['mounting_heights']) ||
            ! empty($ef['work_at_height_methods']) ||
            ! empty($ef['cable_routes']) ||
            ! empty($ef['wall_construction']) ||
            ! empty($ef['wall_needs_reinforcement']) ||
            ! empty($ef['wall_needs_chase_out']) ||
            ! empty($ef['wall_needs_conduit']) ||
            ! empty($ef['brackets_required']) ||
            ! empty($ef['table_info']) ||
            ! empty($ef['floor_box_info'])
        )) {
            $anyEf = true; break;
        }
    }
    if (! $anyEf) return;

    $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
    $this->attachFooter($section);
    $this->sectionHeading($section, 'Engineer Survey Findings');

    $methodLabels = [
        'ladder' => 'Ladder', 'podium' => 'Podium steps', 'tower' => 'Access tower',
        'mewp' => 'MEWP', 'scaffold' => 'Scaffold', 'na' => 'Not required',
    ];
    $wallConstructionLabels = [
        'ply_lined' => 'Ply-lined', 'solid' => 'Solid wall', 'plasterboard' => 'Plasterboard',
        'masonry' => 'Masonry / brick', 'metal_stud' => 'Metal stud', 'concrete' => 'Concrete',
    ];
    $cableCategoryLabels = [
        'ceiling_speakers' => 'Ceiling speakers', 'desk_cables' => 'Desk cables',
        'mic_cables' => 'Microphone cables', 'booking_panel_cables' => 'Booking panel cables',
        'screen_cables' => 'Screen / display cables', 'rack_to_room' => 'Rack to room',
        'other' => 'Other',
    ];

    $vf = $this->font(9);
    $bf = $this->font(9, bold: true);

    foreach ($rooms as $room) {
        $ef = (array) ($room['engineer_feedback'] ?? []);
        $hasEF = ! empty($ef) && (
            ! empty($ef['mounting_heights']) ||
            ! empty($ef['work_at_height_methods']) ||
            ! empty($ef['cable_routes']) ||
            ! empty($ef['wall_construction']) ||
            ! empty($ef['wall_needs_reinforcement']) ||
            ! empty($ef['wall_needs_chase_out']) ||
            ! empty($ef['wall_needs_conduit']) ||
            ! empty($ef['brackets_required']) ||
            ! empty($ef['table_info']) ||
            ! empty($ef['floor_box_info'])
        );
        if (! $hasEF) continue;

        $roomName = (string) ($room['name'] ?? 'Room');
        $section->addText(
            'Engineer Survey Findings — ' . $this->t($roomName),
            $this->font(10, bold: true, colour: self::TEAL),
            ['spaceBefore' => 100, 'spaceAfter' => 60],
        );

        // ── Mounting heights ─────────────────────────────────────────────
        $mh = (array) ($ef['mounting_heights'] ?? []);
        $heightRows = [];
        foreach ([
            'screen_h_m' => 'Screen', 'camera_h_m' => 'Camera',
            'booking_panel_h_m' => 'Booking panel', 'speaker_h_m' => 'Speaker',
        ] as $k => $lbl) {
            if (! empty($mh[$k])) $heightRows[] = $lbl . ': ' . $mh[$k] . ' m';
        }
        foreach ((array) ($mh['other'] ?? []) as $other) {
            $oLbl = trim((string) ($other['label'] ?? ''));
            $oH = $other['h_m'] ?? null;
            if ($oLbl !== '' && $oH !== null && $oH !== '') {
                $heightRows[] = $oLbl . ': ' . $oH . ' m';
            }
        }
        if (! empty($heightRows)) {
            $section->addText('Installation heights: ', $bf, ['spaceBefore' => 40]);
            $section->addText($this->t(implode(' • ', $heightRows)), $vf, ['spaceAfter' => 40]);
        }

        // ── Working at height methods ────────────────────────────────────
        $wahLabels = array_values(array_filter(array_map(
            fn ($m) => $methodLabels[strtolower((string) $m)] ?? ucfirst((string) $m),
            (array) ($ef['work_at_height_methods'] ?? [])
        )));
        if (! empty($wahLabels)) {
            $section->addText('Working at height — methods on site: ', $bf, ['spaceBefore' => 40]);
            $section->addText($this->t(implode(', ', $wahLabels)), $vf, ['spaceAfter' => 40]);
        }

        // ── Cable routes ─────────────────────────────────────────────────
        $cableRoutes = (array) ($ef['cable_routes'] ?? []);
        if (! empty($cableRoutes)) {
            $section->addText('Cable routes planned:', $bf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
            foreach ($cableRoutes as $cr) {
                $catKey = (string) ($cr['category'] ?? '');
                $cat = $cableCategoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey));
                $len = ! empty($cr['length_m']) ? ($cr['length_m'] . ' m') : '';
                $from = trim((string) ($cr['from'] ?? ''));
                $to = trim((string) ($cr['to'] ?? ''));
                $route = ($from && $to) ? ($from . ' → ' . $to) : ($from ?: $to);
                $note = trim((string) ($cr['notes'] ?? ''));
                $parts = array_filter([$cat, $route, $len, $note]);
                if (! empty($parts)) {
                    $section->addText('•  ' . $this->t(implode(' — ', $parts)), $vf, ['spaceBefore' => 20, 'spaceAfter' => 20]);
                }
            }
        }

        // ── Wall construction & prep ─────────────────────────────────────
        $wcLabels = array_values(array_filter(array_map(
            fn ($w) => $wallConstructionLabels[strtolower((string) $w)] ?? ucwords(str_replace('_', ' ', (string) $w)),
            (array) ($ef['wall_construction'] ?? [])
        )));
        $prepFlags = [];
        if (! empty($ef['wall_needs_reinforcement'])) $prepFlags[] = 'Reinforcement required';
        if (! empty($ef['wall_needs_chase_out']))     $prepFlags[] = 'Chase-out required';
        if (! empty($ef['wall_needs_conduit']))       $prepFlags[] = 'Conduit installation required';
        if (! empty($wcLabels) || ! empty($prepFlags)) {
            $section->addText('Wall construction: ', $bf, ['spaceBefore' => 40]);
            $section->addText(! empty($wcLabels) ? $this->t(implode(', ', $wcLabels)) : '—', $vf);
            if (! empty($prepFlags)) {
                $section->addText('Prep needed: ', $bf, ['spaceBefore' => 20]);
                $section->addText($this->t(implode(', ', $prepFlags)), $vf, ['spaceAfter' => 40]);
            }
        }

        // ── Brackets ─────────────────────────────────────────────────────
        $brackets = (array) ($ef['brackets_required'] ?? []);
        if (! empty($brackets)) {
            $section->addText('Brackets to source:', $bf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
            foreach ($brackets as $b) {
                $eq = trim((string) ($b['equipment'] ?? ''));
                $mod = trim((string) ($b['model'] ?? ''));
                $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                $note = trim((string) ($b['notes'] ?? ''));
                $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                if ($note !== '') $line .= ' — ' . $note;
                if ($line !== '') {
                    $section->addText('•  ' . $this->t($line), $vf, ['spaceBefore' => 20, 'spaceAfter' => 20]);
                }
            }
        }

        // ── Table info ───────────────────────────────────────────────────
        $ti = (array) ($ef['table_info'] ?? []);
        if (! empty($ti) && (! empty($ti['has_grommets']) || ! empty($ti['notes']))) {
            $tParts = [];
            if (! empty($ti['has_grommets'])) {
                $tParts[] = ($ti['grommet_count'] ?? '?') . '× ' . trim((string) ($ti['grommet_size'] ?? '')) . ' grommets';
            }
            if (! empty($ti['notes'])) $tParts[] = $ti['notes'];
            $section->addText('Table: ', $bf, ['spaceBefore' => 40]);
            $section->addText($this->t(implode(' — ', array_filter($tParts))), $vf, ['spaceAfter' => 40]);
        }

        // ── Floor box info ───────────────────────────────────────────────
        $fb = (array) ($ef['floor_box_info'] ?? []);
        if (! empty($fb) && (! empty($fb['has_floor_box']) || ! empty($fb['notes']))) {
            $fParts = [];
            if (! empty($fb['has_floor_box'])) {
                $fParts[] = ($fb['power_outlets'] ?? 0) . ' power, ' . ($fb['data_outlets'] ?? 0) . ' data';
                if (! empty($fb['cable_space'])) $fParts[] = trim((string) $fb['cable_space']) . ' cable space';
            }
            if (! empty($fb['notes'])) $fParts[] = $fb['notes'];
            $section->addText('Floor box: ', $bf, ['spaceBefore' => 40]);
            $section->addText($this->t(implode(' — ', array_filter($fParts))), $vf, ['spaceAfter' => 40]);
        }
    }
}
```

**Defensive notes:**
- The pre-flight `$anyEf` check at the top of `buildEngineerFindingsByRoom()` is the regression-safety guarantee. When NO rooms have engineer_feedback, the function returns BEFORE adding a new section to the document — pre-260503 RAMS DOCX outputs are byte-identical.
- The Site Logistics block inside `buildScopeOfWorks` is gated by the same `$hasSiteLog` flag — same guarantee.
- All cable category / WAH method / wall-construction labels match the verbatim labels in the PDF Blade (line 794-821) — visual consistency between PDF and DOCX.
- All 7 sub-blocks within the per-room Engineer Survey Findings section have INDEPENDENT `! empty(...)` guards (matches PDF lines 925, 936, 942, 974, 986, 1012, 1022).
- Re-uses ONLY existing PhpWord helpers: `$this->font()`, `$this->t()`, `$this->portraitStyle()`, `$this->attachFooter()`, `$this->sectionHeading()`, `$this->tableStyle()`, `self::TEAL`, `self::ROW_ALT`, `self::WHITE`. Zero new helpers, zero new constants.
- DOCX section break uses `'breakType' => 'nextPage'` consistent with all other sections in this file.

**Files audited explicitly NOT touched** by this task — controllers, models, routes, migrations, RamsBuilderService, RamsDataBuilderService, PdfRenderService, all RAMS tests. Pure presentation-layer change.
  </action>
  <verify>
    <automated>php -l app/Services/DocxBuilderService.php && php artisan view:clear &amp;&amp; php artisan view:cache</automated>
    Tinker render smoke (post-edit):
    ```
    php artisan tinker --execute="
      $rams = \App\Models\RamsDocument::whereNotNull('generated_data')->latest('id')->first();
      if (! $rams) { echo 'NO RAMS'; return; }
      $svc = app(\App\Services\DocxBuilderService::class);
      $path = $svc->build($rams->generated_data, $rams);
      $size = filesize($path);
      $zip = new \ZipArchive(); $zip->open($path);
      $xml = $zip->getFromName('word/document.xml'); $zip->close();
      echo 'BYTES=' . $size . PHP_EOL;
      echo 'HAS_SITE_LOG=' . (str_contains($xml, 'Site Logistics') ? 'Y' : 'N') . PHP_EOL;
      echo 'HAS_EF=' . (str_contains($xml, 'Engineer Survey Findings') ? 'Y' : 'N') . PHP_EOL;
    "
    ```
    Run TWICE — once for a project with site_logistics + engineer_feedback populated (expect Y/Y), once for a project with NULL data (expect N/N — regression-safe).
  </verify>
  <done>
    - DocxBuilderService has TWO new methods: `buildSiteLogistics()` rendering inside buildScopeOfWorks (defensive — no-op when empty), and `buildEngineerFindingsByRoom()` as a new section called from build() (defensive — no-op when no rooms have feedback).
    - The build() method order is: ...buildScopeOfWorks → buildEngineerFindingsByRoom (NEW) → buildRiskAssessment...
    - Both new blocks gated by `$hasSiteLog` / `$anyEf` pre-flight checks.
    - RAMS regenerated for a project with synthetic site_logistics + engineer_feedback shows BOTH new blocks in the DOCX (verifiable via grep word/document.xml for "Site Logistics" + "Engineer Survey Findings").
    - RAMS regenerated for a project with NULL data shows NEITHER new block — output byte size differs only by zero-or-very-small overhead (new method present in code but adds nothing to document).
    - `git diff --stat app/Models/ app/Http/Controllers/ routes/ database/ config/ resources/views/pdf/` returns EMPTY for this commit (resources/views/pdf/ explicitly out — PDF was already shipped in 260503-tfb).
  </done>
</task>

</tasks>

<verification>
**File footprint audit (post-final-commit):**
```
git diff --stat HEAD~3 HEAD -- \
    resources/views/worksheets/show.blade.php \
    resources/views/worksheets/index.blade.php \
    app/Services/WorksheetDocxService.php \
    resources/views/worksheets/public-show.blade.php \
    app/Services/DocxBuilderService.php
```
Expected: exactly 5 files, all in the above set.

**Forbidden-paths audit (post-final-commit):**
```
git diff --stat HEAD~3 HEAD -- \
    app/Models/ app/Http/Controllers/ routes/ database/ config/ \
    resources/views/pdf/rams.blade.php \
    app/Services/RamsBuilderService.php \
    app/Services/RamsDataBuilderService.php \
    app/Services/ProjectContext/
```
Expected: EMPTY (zero changes — no controller/route/model/migration/config/RAMS-pipeline edits).

**Render smoke tests:**
- Worksheet DOCX with synthetic site logistics → cover header includes "SITE LOGISTICS — FROM SITE SURVEY" + 5 rows.
- Worksheet DOCX without site logistics → cover header IDENTICAL to pre-change output.
- public-show.blade.php with synthetic site logistics → "📋 Site Logistics — Arrival Info" drawer at project level.
- public-show.blade.php without site logistics → ZERO new drawers (regression-safe — verified the same way in 260504-dh8).
- RAMS DOCX with synthetic data → Site Logistics block in section 4 + per-room Engineer Survey Findings section between sections 4 and 5.
- RAMS DOCX without data → ZERO new content (zero new section break, zero new headings).

**Manual UAT (post-upload):**
1. Open `/worksheets/{id}` for a worksheet with `status=draft` — verify ↻ Regenerate button appears alongside ↓ Download.
2. Click ↻ — confirm prompt appears, accept — verify status flips to "Generating…" and success flash banner appears.
3. Open `/worksheets/` index — verify ↻ icon button appears in actions cell of each draft/final row.
4. Pick a project with engineer-feedback site columns populated. Regenerate worksheet. Download DOCX. Verify cover header has SITE LOGISTICS section showing the populated rows.
5. Open `/worksheets/{token}` (the public engineer URL) — verify "📋 Site Logistics — Arrival Info" drawer appears between header and rooms; expand it and verify all 5 fields render.
6. Pick a project with engineer-feedback room data populated. Regenerate RAMS. Download DOCX. Verify a new "Engineer Survey Findings" section appears between Section 4 (Scope of Works) and Section 5 (Risk Assessment), with per-room subheadings + 7 sub-blocks each.
7. Pick a project with all NULL engineer-feedback. Regenerate worksheet AND RAMS. Verify NO new content appears in either DOCX (regression-safe).
</verification>

<success_criteria>
- [ ] 3 commits on `feat/worksheet-classifier-universal` branch (one per task; commit messages: `feat(quick-260504-gho-01): regen worksheet button on show + index`, `feat(quick-260504-gho-02): site logistics on worksheet docx + public view`, `feat(quick-260504-gho-03): mirror engineer feedback into rams docx`)
- [ ] Exactly 5 files modified — none outside the listed set
- [ ] Forbidden-paths diff returns EMPTY (zero controllers/routes/models/migrations/config touched, RAMS PDF blade also untouched — already shipped)
- [ ] `php -l` clean on all 5 files
- [ ] `php artisan view:clear && php artisan view:cache` succeeds
- [ ] Render smoke tests confirm: populated data ⇒ new content visible; empty data ⇒ ZERO new content (regression-safe on all 4 surfaces — Worksheet DOCX cover, public-show drawer, RAMS DOCX Site Logistics, RAMS DOCX Engineer Findings)
- [ ] No new CSS classes added (reuses `.room-drawer.teal` from 260504-dh8 + existing PhpWord helpers from DocxBuilderService / WorksheetDocxService)
- [ ] No new routes — `worksheets.retry-generation` already exists (web.php:381)
- [ ] No tests added (consistent with prior quick-task convention — 260503-tfb / 260504-dh8 also skipped tests for pure presentation changes; render smoke tests via tinker provide sufficient evidence)
</success_criteria>

<output>
After completion, create `.planning/quick/260504-gho-output-document-improvements-regen-works/260504-gho-SUMMARY.md` with:
- frontmatter (quick_id, mode, type=summary, status, completed_at, duration_minutes, commits[], files_modified[], file_count, line_delta, deviations[])
- "What changed" section per file with `+N / -N` line deltas
- File footprint audit + forbidden-paths audit (verbatim git diff outputs)
- Render smoke test results (populated + empty cases for each of the 4 surfaces)
- Files-to-upload list (5 files) + commands-to-run-on-live (`php artisan view:clear`)
- Deviations from plan (or "None")
- Self-Check section confirming files exist and commits found in git log

Plus list the live-upload files in the chat reply for the local-edit-then-upload workflow.
</output>
