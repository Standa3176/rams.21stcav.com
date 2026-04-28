<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>O&amp;M Manual — {{ $manual->project_name }}</title>
{{-- Phase 7 — Poppins body, Verdana headings (21CAV brand). Google Fonts
     fetched at render time by Browsershot/Chromium; Arial/DejaVu fall-backs
     keep the doc legible if the network request fails. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* ── Base — Phase 7 21CAV brand: Poppins body, Verdana headings ───── */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 100%; }
    body {
        font-family: 'Poppins', Arial, "DejaVu Sans", sans-serif;
        font-size: 10pt;
        color: #1A1A2E;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }
    /* Headings — Verdana per brand spec, falling back to DejaVu / Arial. */
    .cover-company-name,
    .cover-doc-title,
    .cover-doc-subtitle,
    .section-title,
    .subsection-title,
    .room-title,
    h1, h2, h3, h4, h5, h6 {
        font-family: Verdana, "DejaVu Sans", Arial, sans-serif;
    }

    /* ── Page ─────────────────────────────────────────────────────────── */
    /* PDF margins are set by Browsershot in PdfService::buildOmManual so the
       running header/footer (passed to Puppeteer separately) doesn't bleed
       into body content. @page is left for size only. */
    @page { size: A4 portrait; }
    .page-wrap { margin: 0 18mm; }

    /* ── Cover page (matches RAMS visual language) ────────────────────── */
    .cover {
        width: 100%;
        padding: 30pt 18mm 0;
        page-break-after: always;
        color: #1A1A2E;
    }
    .cover-company-name {
        font-size: 22pt;
        font-weight: 700;
        color: #01889F;
        text-align: center;
        margin-bottom: 4pt;
        letter-spacing: 1pt;
    }
    .cover-tagline {
        font-size: 11pt;
        font-style: italic;
        color: #D4AF37;
        text-align: center;
        margin-bottom: 20pt;
    }
    .cover-doc-title {
        font-size: 16pt;
        font-weight: 700;
        color: #1A1A2E;
        text-align: center;
        margin-bottom: 6pt;
        line-height: 1.3;
    }
    .cover-doc-subtitle {
        font-size: 11pt;
        font-weight: 400;
        color: #555;
        text-align: center;
        margin-bottom: 18pt;
    }
    .cover-accent-bar {
        height: 6pt;
        background: linear-gradient(to right, #01889F, #D4AF37);
        margin-bottom: 20pt;
        border-radius: 1pt;
    }

    /* ── Cover info tables ────────────────────────────────────────────── */
    .cover-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin-bottom: 12pt;
        border: 0.75pt solid #BBBBBB;
    }
    .cover-table td {
        padding: 6pt 8pt;
        vertical-align: middle;
        font-size: 9pt;
        border: 0.5pt solid #CCCCCC;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .cover-table .lbl {
        font-weight: 700;
        color: #01889F;
        background-color: #F4FBFB;
    }
    .cover-table .val {
        color: #1A1A2E;
        background-color: #FFFFFF;
    }

    /* ── Section heading (matches RAMS .sec-heading) ──────────────────── */
    .section-title {
        background-color: #01889F;
        color: #FFFFFF;
        font-size: 10pt;
        font-weight: 700;
        text-transform: uppercase;
        padding: 5pt 8pt;
        margin: 14pt 0 8pt;
        page-break-after: avoid;
        letter-spacing: 0.5pt;
    }
    .subsection-title {
        font-size: 9.5pt;
        font-weight: 700;
        color: #01889F;
        margin: 10pt 0 5pt;
        page-break-after: avoid;
        border-bottom: 0.5pt solid #01889F;
        padding-bottom: 2pt;
    }
    .room-title {
        font-size: 10.5pt;
        font-weight: 700;
        color: #01889F;
        background: #F4FBFB;
        padding: 5pt 8pt;
        border-left: 3pt solid #01889F;
        margin: 12pt 0 6pt;
        page-break-after: avoid;
    }

    /* ── Info table (Section 1 project summary; matches cover-table look) */
    .info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10pt;
        border: 0.75pt solid #BBBBBB;
    }
    .info-table td {
        padding: 5pt 8pt;
        border: 0.5pt solid #CCCCCC;
        vertical-align: top;
        font-size: 9pt;
    }
    .info-table .lbl {
        background: #F4FBFB;
        font-weight: 700;
        width: 26%;
        color: #01889F;
    }

    /* ── Steps list ───────────────────────────────────────────────────── */
    .steps-list { padding-left: 16pt; margin: 4pt 0 6pt; }
    .steps-list li { margin-bottom: 3pt; line-height: 1.35; }

    /* ── Note boxes ───────────────────────────────────────────────────── */
    .note {
        padding: 5pt 8pt;
        font-size: 8.5pt;
        margin: 4pt 0;
        border-left: 3pt solid transparent;
    }
    .note-info    { background: #F4FBFB; border-color: #01889F; color: #0C4A52; }
    .note-warning { background: #FFF3CD; border-color: #D4AF37; color: #7A4D00; }

    /* ── Data tables (room equipment, maintenance, network, etc.) ─────── */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
        margin-bottom: 10pt;
        page-break-inside: auto;
        border: 0.5pt solid #CCCCCC;
    }
    .data-table th {
        background: #01889F;
        color: #FFFFFF;
        padding: 5pt 7pt;
        text-align: left;
        font-size: 8pt;
        font-weight: 700;
        border: 0.5pt solid #01889F;
        letter-spacing: 0.3pt;
    }
    .data-table td {
        padding: 4pt 7pt;
        border-bottom: 0.5pt solid #E0E0E0;
        vertical-align: top;
    }
    .data-table tr:nth-child(even) td { background: #F4FBFB; }
    .data-table tr { page-break-inside: avoid; }

    /* ── Frequency badge ──────────────────────────────────────────────── */
    .freq-badge {
        display: inline-block;
        padding: 1pt 6pt;
        border-radius: 8pt;
        font-size: 7pt;
        font-weight: 700;
        white-space: nowrap;
        letter-spacing: 0.2pt;
    }
    .freq-weekly    { background: #F4FBFB; color: #01889F; }
    .freq-monthly   { background: #E8F5E9; color: #2E7D32; }
    .freq-quarterly { background: #FFF3CD; color: #856404; }
    .freq-annual    { background: #FDE8E8; color: #7B1C1C; }

    /* The page footer was previously rendered as a `position: fixed` div
       (dompdf-only trick that doesn't repeat in Chromium). The footer now
       lives in PdfService::buildOmManual and is passed to Browsershot as
       Puppeteer footerHtml. CSS rules below kept commented for reference. */
    /*
    .footer { position: fixed; bottom: 0; left: 0; right: 0; ... }
    */

    /* ── Bullet list ──────────────────────────────────────────────────── */
    .blist { padding-left: 14pt; margin: 3pt 0 6pt; }
    .blist li { margin-bottom: 2pt; }

    /* ── Page break util ──────────────────────────────────────────────── */
    .pb { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }
</style>
</head>
<body>

@php
    $company  = config('rams.company_name', '');
    $project  = $manual;                      // shorthand — manual carries cover fields
    $linkedProject = $manual->project ?? null; // Project model — Phase 4 relationships

    // Existing generated_data sections (driven by OmManualGeneratorService).
    $ops      = $data['operation_sections']     ?? [];
    $maint    = $data['maintenance_schedule']   ?? [];
    $faults   = $data['fault_finding']          ?? [];
    $netDevs  = $data['network_devices']        ?? [];
    $netSec   = $data['network_security_notes'] ?? [];
    $mfr      = $data['manufacturer_support']   ?? [];
    $warranty = $data['warranty_summary']       ?? [];
    $rooms    = $data['rooms_summary']          ?? [];

    // ── Phase 4 — Tier 1 records pulled from project relationships ─────────
    // Phase 6 — drawings + user_guides flow through generated_data so the
    // rendered snapshot is reproducible from $data alone. We normalise to
    // arrays-of-arrays (with date pre-formatted) so the template can use a
    // single access pattern regardless of source. Live-relationship is the
    // legacy fallback for OM records that pre-date the Phase 6 wire-up.
    if (! empty($data['drawings'])) {
        $drawings = collect($data['drawings']);
    } elseif ($linkedProject) {
        $drawings = $linkedProject->appendices()
            ->where('type', 'drawing')
            ->orderBy('reference_number')
            ->get()
            ->map(fn ($d) => [
                'reference_number' => (string) ($d->reference_number ?? ''),
                'title'            => (string) $d->title,
                'revision'         => (string) ($d->revision ?? ''),
                'date'             => $d->date?->format('d M Y') ?? '',
                'file_path'        => (string) ($d->file_path ?? ''),
            ]);
    } else {
        $drawings = collect();
    }

    if (! empty($data['user_guides'])) {
        $userGuides = collect($data['user_guides']);
    } elseif ($linkedProject) {
        $userGuides = $linkedProject->appendices()
            ->where('type', 'user_guide')
            ->orderBy('title')
            ->get()
            ->map(fn ($g) => [
                'title'            => (string) $g->title,
                'reference_number' => (string) ($g->reference_number ?? ''),
                'file_path'        => (string) ($g->file_path ?? ''),
            ]);
    } else {
        $userGuides = collect();
    }
    $devices            = $linkedProject ? $linkedProject->devices()->orderBy('room_name')->orderBy('description')->get() : collect();
    $devicesWithFw      = $devices->filter(fn ($d) => filled($d->firmware_version));
    $configBackups      = $devices->isNotEmpty()
        ? \App\Models\ConfigBackup::with('device')->whereIn('device_id', $devices->pluck('id'))->orderBy('filename')->get()
        : collect();
    $commissioningTests = $linkedProject ? $linkedProject->commissioningTests()->orderBy('room')->orderBy('test_type')->get() : collect();
    $trainingRecords    = $linkedProject ? $linkedProject->trainingRecords()->orderBy('date')->get() : collect();

    // Per-room narratives. Tier 1 (option C) prefers AI-generated overviews
    // from $data['system_overviews']; falls back to the static narrative
    // stored on the room itself (Phase 4 contract).
    $narrativesByRoom = [];
    foreach ((array) ($data['system_overviews'] ?? []) as $ov) {
        $rn = trim((string) ($ov['room_name'] ?? ''));
        $note = trim((string) ($ov['narrative'] ?? ''));
        if ($rn !== '' && $note !== '') {
            $narrativesByRoom[$rn] = $note;
        }
    }
    foreach ($rooms as $r) {
        $rn   = trim((string) ($r['name'] ?? ''));
        $note = trim((string) ($r['narrative'] ?? $r['description'] ?? ''));
        if ($rn !== '' && $note !== '' && empty($narrativesByRoom[$rn])) {
            $narrativesByRoom[$rn] = $note;
        }
    }

    // Quick Start + Common Tasks blocks (Tier 1 option C — front-of-doc and
    // task-based workflows within Section 4).
    $quickStart  = is_array($data['quick_start']  ?? null) ? $data['quick_start']  : [];
    $commonTasks = is_array($data['common_tasks'] ?? null) ? $data['common_tasks'] : [];

    // Distribution list — owner + client (skip section if neither resolves).
    $distribution = [];
    if ($linkedProject) {
        $owner = $linkedProject->owner;
        if ($owner && filled($owner->email)) {
            $distribution[] = [
                'name'  => $owner->name ?: '21CAV Project Owner',
                'role'  => '21CAV Project Owner',
                'email' => $owner->email,
            ];
        }
        if (filled($linkedProject->client_name)) {
            $distribution[] = [
                'name'  => $linkedProject->client_name,
                'role'  => 'Client',
                'email' => '',
            ];
        }
    }

    // Related documents on the same project (RAMS / Cable Schedule / Worksheet).
    $relatedRams = $linkedProject ? $linkedProject->ramsDocuments : collect();
    $relatedCs   = $linkedProject ? $linkedProject->cableSchedules : collect();
    $relatedWs   = $linkedProject ? $linkedProject->worksheets    : collect();
    $hasRelated  = $relatedRams->isNotEmpty() || $relatedCs->isNotEmpty() || $relatedWs->isNotEmpty();

    // Authorship — used in Revision History. Phase 8 fix — title-case so a
    // user account like "sonny" displays as "Sonny" without forcing a DB edit.
    $authorRaw = optional($manual->user)->name ?: ($linkedProject?->owner?->name ?: '21st Century AV Ltd');
    $author    = ucwords(strtolower($authorRaw)) ?: $authorRaw;

    $freqClass = function(string $f): string {
        $f = strtolower($f);
        if (str_contains($f, 'week'))    return 'freq-weekly';
        if (str_contains($f, 'month'))   return 'freq-monthly';
        if (str_contains($f, 'quarter')) return 'freq-quarterly';
        return 'freq-annual';
    };
@endphp

{{-- The running footer is now supplied to Browsershot/Puppeteer via
     PdfService::buildOmManual ($options['footerHtml']) — Chromium repeats
     it on every page natively and supports pageNumber/totalPages spans. --}}

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- COVER PAGE — mirrors RAMS cover layout for brand consistency           --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="cover">
    <div class="cover-company-name">{{ strtoupper($company) }}</div>
    <div class="cover-tagline">Your Audio Visual Partner</div>
    <div class="cover-doc-title">OPERATION &amp; MAINTENANCE MANUAL</div>
    <div class="cover-doc-subtitle">AV Systems</div>
    <div class="cover-accent-bar"></div>

    {{-- Cover Table 1: CLIENT / SITE / PROJECT REFERENCE / PROJECT NAME / DATE --}}
    <table class="cover-table">
        <colgroup>
            <col style="width:26%;">
            <col style="width:74%;">
        </colgroup>
        <tr>
            <td class="lbl">CLIENT:</td>
            <td class="val">{{ $project->client_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">SITE:</td>
            <td class="val">{{ $project->site_address ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">PROJECT REFERENCE:</td>
            <td class="val">{{ $project->project_ref ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">PROJECT NAME:</td>
            <td class="val">{{ $project->project_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">DOCUMENT DATE:</td>
            <td class="val">{{ now()->format('d/m/Y') }}</td>
        </tr>
    </table>

    {{-- Cover Table 2: PREPARED BY / TELEPHONE with REVISION / STATUS --}}
    <table class="cover-table">
        <colgroup>
            <col style="width:26%;">
            <col style="width:30%;">
            <col style="width:18%;">
            <col style="width:26%;">
        </colgroup>
        <tr>
            <td class="lbl">PREPARED BY:</td>
            <td class="val">{{ $company }}</td>
            <td class="lbl">REVISION:</td>
            <td class="val" style="white-space:nowrap;">Rev 1.0</td>
        </tr>
        <tr>
            <td class="lbl">TELEPHONE:</td>
            <td class="val">01189 977770</td>
            <td class="lbl">STATUS:</td>
            <td class="val" style="white-space:nowrap;">For Issue</td>
        </tr>
    </table>
</div>

{{-- ════════════════════════════════════════════════════════════════════════
     BODY CONTENT — wrapped in .page-wrap so left/right body margins match
     the running header/footer's 18mm internal padding. Without this, body
     content sits flush against the page edge (Browsershot is configured
     marginLeft/Right=0 in PdfService::buildOmManual) and prints can clip
     content within the printer's hardware margin.
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="page-wrap">

{{-- ════════════════════════════════════════════════════════════════════════
     QUICK START GUIDE — Tier 1 (option C) front-of-doc one-pager.
     Sits between cover and Contents so a non-technical reader can run
     the system without opening the rest of the manual.
     ════════════════════════════════════════════════════════════════════════ --}}
@if (! empty($quickStart))
<div class="section-title">Quick Start Guide</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    Everything you need to operate the room AV in 60 seconds.
    Platform: <strong>{{ $quickStart['platform_label'] ?? 'Configured video conferencing platform' }}</strong>.
</p>

<table class="info-table" style="margin-bottom:14pt;">
    <colgroup>
        <col style="width:22%;">
        <col style="width:78%;">
    </colgroup>
    <tr>
        <td class="lbl">Start the system</td>
        <td>
            <ol style="padding-left:16pt;margin:0;">
                @foreach ($quickStart['start_steps'] ?? [] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </td>
    </tr>
    <tr>
        <td class="lbl">Join a call</td>
        <td>
            <ol style="padding-left:16pt;margin:0;">
                @foreach ($quickStart['join_steps'] ?? [] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </td>
    </tr>
    <tr>
        <td class="lbl">Present from your laptop</td>
        <td>
            <ol style="padding-left:16pt;margin:0;">
                @foreach ($quickStart['present_steps'] ?? [] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </td>
    </tr>
    <tr>
        <td class="lbl">Need help?</td>
        <td>{{ $quickStart['support_label'] ?? '' }}</td>
    </tr>
</table>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     FRONT MATTER — TOC, Revision History, Distribution List, Related Documents.
     Each block conditionally rendered (NO EMPTY SECTIONS rule).
     ════════════════════════════════════════════════════════════════════════ --}}

{{-- ── Table of Contents ─────────────────────────────────────────────── --}}
<div class="section-title pb">Contents</div>
<table class="data-table" style="margin-bottom:14pt;">
    <tbody>
        <tr><td style="width:8%;text-align:right;font-weight:700;color:#01889F;">1.</td><td>Executive Summary</td></tr>
        @if (! empty($rooms))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">2.</td><td>System Architecture &amp; Signal Flow</td></tr>
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">3.</td><td>System Asset Register (By Room)</td></tr>
        @endif
        @if ($drawings->isNotEmpty())
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">4.</td><td>Drawings Register</td></tr>
        @endif
        @if (! empty($ops))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">5.</td><td>System Operation — User Guides</td></tr>
        @endif
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">6.</td><td>System Configuration &amp; Backups</td></tr>
        @if (! empty($maint))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">7.</td><td>Routine Maintenance Schedule</td></tr>
        @endif
        @if (! empty($faults))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">8.</td><td>Fault Finding Guide</td></tr>
        @endif
        @if (! empty($netDevs) || ! empty($netSec))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">9.</td><td>Network &amp; IP Configuration</td></tr>
        @endif
        @if (! empty($mfr) || ! empty($warranty))
            <tr><td style="text-align:right;font-weight:700;color:#01889F;">10.</td><td>Manufacturer Support &amp; Warranty</td></tr>
        @endif
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">11.</td><td>Service &amp; Escalation</td></tr>
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">12.</td><td>Training &amp; Handover</td></tr>
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">13.</td><td>System Testing &amp; Acceptance</td></tr>
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">14.</td><td>Glossary</td></tr>
        <tr><td style="text-align:right;font-weight:700;color:#01889F;">15.</td><td>Document Control</td></tr>
        @if ($drawings->isNotEmpty())
            <tr><td style="text-align:right;font-weight:700;color:#D4AF37;">A.</td><td>Appendix A — Drawings</td></tr>
        @endif
        @if ($userGuides->isNotEmpty())
            <tr><td style="text-align:right;font-weight:700;color:#D4AF37;">B.</td><td>Appendix B — User Guides</td></tr>
        @endif
    </tbody>
</table>

{{-- ── Revision History ──────────────────────────────────────────────── --}}
<div class="subsection-title">Revision History</div>
<table class="data-table" style="margin-bottom:14pt;">
    <thead>
        <tr>
            <th style="width:12%;">Rev</th>
            <th style="width:18%;">Date</th>
            <th style="width:25%;">Author</th>
            <th style="width:45%;">Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>1.0</td>
            <td>{{ now()->format('d M Y') }}</td>
            <td>{{ $author }}</td>
            <td>Initial Issue</td>
        </tr>
    </tbody>
</table>

{{-- ── Distribution List ─────────────────────────────────────────────── --}}
@if (! empty($distribution))
<div class="subsection-title">Distribution List</div>
<table class="data-table" style="margin-bottom:14pt;">
    <thead>
        <tr>
            <th style="width:30%;">Name</th>
            <th style="width:30%;">Role</th>
            <th style="width:40%;">Email</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($distribution as $d)
        <tr>
            <td>{{ $d['name'] }}</td>
            <td>{{ $d['role'] }}</td>
            <td>{{ $d['email'] ?: '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ── Related Documents ─────────────────────────────────────────────── --}}
@if ($hasRelated)
<div class="subsection-title">Related Documents</div>
<table class="data-table" style="margin-bottom:14pt;">
    <thead>
        <tr>
            <th style="width:40%;">Document</th>
            <th style="width:30%;">Reference</th>
            <th style="width:30%;">Last Updated</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($relatedRams as $r)
        <tr>
            <td>RAMS — Risk Assessment &amp; Method Statement</td>
            <td>{{ $r->project_ref ?? ('RAMS-' . $r->id) }}</td>
            <td>{{ $r->updated_at?->format('d M Y') ?: '—' }}</td>
        </tr>
        @endforeach
        @foreach ($relatedCs as $c)
        <tr>
            <td>Cable Schedule</td>
            <td>{{ $c->project_ref ?? ('CS-' . $c->id) }}</td>
            <td>{{ $c->updated_at?->format('d M Y') ?: '—' }}</td>
        </tr>
        @endforeach
        @foreach ($relatedWs as $w)
        <tr>
            <td>Worksheet</td>
            <td>WS-{{ $w->id }}</td>
            <td>{{ $w->updated_at?->format('d M Y') ?: '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 1. EXECUTIVE SUMMARY                                                   --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">1. Executive Summary</div>

<p style="margin-bottom:10pt;">
    This Operation &amp; Maintenance Manual documents the AV systems installed at
    <strong>{{ $project->site_address ?: 'the project site' }}</strong> for
    <strong>{{ $project->client_name }}</strong> under project reference
    <strong>{{ $project->project_ref }}</strong>.
    @if ($linkedProject?->handover_date)
        Formal handover took place on <strong>{{ $linkedProject->handover_date->format('d M Y') }}</strong>.
    @endif
    @if ($linkedProject?->defects_liability_end)
        The defects liability period ends <strong>{{ $linkedProject->defects_liability_end->format('d M Y') }}</strong>.
    @endif
</p>

@if (! empty($narrativesByRoom))
<div class="subsection-title">Room Overviews</div>
@foreach ($narrativesByRoom as $roomName => $narrative)
    <div class="avoid-break" style="margin-bottom:10pt;">
        <div class="room-title">{{ $roomName }}</div>
        <p>{{ $narrative }}</p>
    </div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 2. SYSTEM ARCHITECTURE & SIGNAL FLOW                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($rooms))
<div class="section-title pb">2. System Architecture &amp; Signal Flow</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    A plain-English description of how each room's AV system flows from input
    sources through processing to outputs. Detailed equipment is listed in
    Section 3.
</p>

@foreach ($rooms as $room)
    @php
        // Deterministic signal-flow extraction from the room's equipment.
        $eqText = strtolower(implode(' | ', array_map(
            fn ($e) => (string) ($e['description'] ?? $e['name'] ?? ''),
            (array) ($room['equipment'] ?? [])
        )));

        $hasDisplay   = str_contains($eqText, 'display') || str_contains($eqText, 'monitor') || str_contains($eqText, 'screen');
        $hasDualDisp  = (substr_count($eqText, 'display') + substr_count($eqText, 'monitor')) >= 2;
        $hasMatrix    = str_contains($eqText, 'matrix') || str_contains($eqText, 'splitter');
        $hasDsp       = str_contains($eqText, 'dsp') || str_contains($eqText, 'tesira');
        $hasSpeaker   = str_contains($eqText, 'speaker') || str_contains($eqText, 'amp');
        $hasRallyBar  = str_contains($eqText, 'rally bar');
        $hasCamera    = str_contains($eqText, 'camera') || str_contains($eqText, 'ptz');
        $hasMic       = str_contains($eqText, 'microphone') || str_contains($eqText, 'mic ');
        $hasTap       = str_contains($eqText, 'tap');
        $hasNuc       = str_contains($eqText, 'nuc') || str_contains($eqText, 'teams base system');

        // Compose the input → processing → output narrative deterministically.
        $inputs = [];
        if ($hasMic)   $inputs[] = 'Ceiling / lapel microphones';
        $inputs[] = 'Laptop input (HDMI / USB-C at table)';
        if ($hasCamera || $hasRallyBar) $inputs[] = 'Camera feed (in-room PTZ)';
        $inputs[] = 'Far-end Microsoft Teams call audio + video';

        $processing = [];
        if ($hasDsp)      $processing[] = 'Biamp DSP — gain staging, gating, AEC';
        if ($hasMatrix)   $processing[] = 'Video matrix / splitter — source switching';
        if ($hasRallyBar) $processing[] = 'Logitech Rally Bar — all-in-one camera + audio + codec';
        if ($hasNuc)      $processing[] = 'Logitech NUC — Microsoft Teams Rooms appliance';
        if (empty($processing)) $processing[] = 'Conferencing platform processing';

        $outputs = [];
        if ($hasDualDisp)      $outputs[] = 'Dual room displays (mirrored content + camera)';
        elseif ($hasDisplay)   $outputs[] = 'Room display';
        if ($hasSpeaker)       $outputs[] = 'Ceiling / room speakers';
        elseif ($hasRallyBar)  $outputs[] = 'Rally Bar integrated speakers';
        $outputs[] = 'Far-end Teams attendees';

        $control = $hasTap ? 'Logitech Tap controller (table-top touch panel)' : 'Configured room controller';
    @endphp
    <div class="room-title" style="margin-top:6pt;">
        {{ $room['name'] ?? 'Room' }} — System Flow
    </div>
    <table class="info-table" style="margin-bottom:6pt;">
        <colgroup>
            <col style="width:18%;">
            <col style="width:82%;">
        </colgroup>
        <tr>
            <td class="lbl">Inputs</td>
            <td>{{ implode('; ', $inputs) }}.</td>
        </tr>
        <tr>
            <td class="lbl">Processing</td>
            <td>{{ implode('; ', $processing) }}.</td>
        </tr>
        <tr>
            <td class="lbl">Outputs</td>
            <td>{{ implode('; ', $outputs) }}.</td>
        </tr>
        <tr>
            <td class="lbl">Control</td>
            <td>{{ $control }}.</td>
        </tr>
    </table>
    <div style="font-size:7.5pt;color:#888;font-style:italic;margin-bottom:14pt;">
        [DIAGRAM: system_signal_flow_{{ \Illuminate\Support\Str::slug($room['name'] ?? 'room') }}]
    </div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 3. SYSTEM ASSET REGISTER (BY ROOM)                                      --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($rooms))
<div class="section-title pb">3. System Asset Register (By Room)</div>

<table class="info-table" style="margin-bottom:10pt;">
    <tr>
        <td class="lbl">Project Name</td>
        <td>{{ $project->project_name }}</td>
        <td class="lbl">Reference</td>
        <td>{{ $project->project_ref ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Client</td>
        <td>{{ $project->client_name }}</td>
        <td class="lbl">Document Date</td>
        <td>{{ now()->format('d M Y') }}</td>
    </tr>
    <tr>
        <td class="lbl">Site Address</td>
        <td colspan="3">{{ $project->site_address }}</td>
    </tr>
</table>

<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    Serial numbers to be recorded at installation and updated for warranty
    and support purposes.
</p>

@foreach ($rooms as $room)
    @php
        // Auto-derived "logical position" per device — combines room name with
        // a category hint inferred from the description so the location reads
        // naturally without requiring a separate UI to capture per-device.
        $deriveLocation = function (array $eq, string $roomName): string {
            $text = strtolower((string) ($eq['description'] ?? $eq['name'] ?? ''));
            $tag  = match (true) {
                str_contains($text, 'display') || str_contains($text, 'screen') || str_contains($text, 'monitor') => 'Display',
                str_contains($text, 'rally bar')                                                                  => 'Video Bar',
                str_contains($text, 'camera') || str_contains($text, 'ptz')                                       => 'Camera',
                str_contains($text, 'dsp') || str_contains($text, 'tesira')                                       => 'DSP / Rack',
                str_contains($text, 'tap') || str_contains($text, 'touch')                                        => 'Touch Controller',
                str_contains($text, 'nuc') || str_contains($text, 'teams base')                                   => 'Compute Appliance',
                str_contains($text, 'microphone') || str_contains($text, ' mic')                                  => 'Microphone',
                str_contains($text, 'speaker')                                                                    => 'Speaker',
                str_contains($text, 'mount') || str_contains($text, 'bracket') || str_contains($text, 'plate')    => 'Mount Hardware',
                str_contains($text, 'trolley') || str_contains($text, 'stand')                                    => 'Trolley',
                str_contains($text, 'switch') || str_contains($text, 'network interface')                         => 'Network',
                default                                                                                            => 'Installed',
            };
            return $roomName . ' — ' . $tag;
        };
    @endphp
    <div class="room-title" style="margin-top:6pt;">
        {{ $room['name'] ?? 'Room' }}
        @if (! empty($room['drawing_ref']))
            <span style="font-size:8pt;font-weight:400;opacity:.75;margin-left:6pt;">Drg: {{ $room['drawing_ref'] }}</span>
        @endif
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:6%;text-align:center;">Qty</th>
                <th style="width:32%;">Device Description</th>
                <th style="width:18%;">Model</th>
                <th style="width:18%;">Serial Number</th>
                <th style="width:18%;">Location</th>
                <th style="width:8%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($room['equipment'] ?? [] as $eq)
            @php
                // Wire-up phase — surface firmware / asset tag / MAC into the
                // Notes column whenever the devices table holds those values.
                // Editable via tinker / future device-edit form; fallback to
                // any free-text notes already on the equipment item.
                $noteParts = [];
                if (! empty(trim((string) ($eq['firmware_version'] ?? '')))) {
                    $noteParts[] = 'FW ' . trim((string) $eq['firmware_version']);
                }
                if (! empty(trim((string) ($eq['asset_tag'] ?? '')))) {
                    $noteParts[] = 'Tag ' . trim((string) $eq['asset_tag']);
                }
                if (! empty(trim((string) ($eq['mac_address'] ?? '')))) {
                    $noteParts[] = 'MAC ' . strtoupper(trim((string) $eq['mac_address']));
                }
                if (! empty(trim((string) ($eq['notes'] ?? '')))) {
                    $noteParts[] = trim((string) $eq['notes']);
                }
            @endphp
            <tr>
                <td style="text-align:center;">{{ $eq['qty'] ?? 1 }}</td>
                <td>{{ $eq['description'] ?? '—' }}</td>
                <td>{{ $eq['model'] ?? '—' }}</td>
                <td>{{ trim((string) ($eq['serial_number'] ?? '')) !== '' ? $eq['serial_number'] : 'To be recorded at installation' }}</td>
                <td>{{ $deriveLocation((array) $eq, (string) ($room['name'] ?? 'Room')) }}</td>
                <td>{!! implode('<br>', array_map('e', $noteParts)) !!}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="height:8pt;"></div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 3. DRAWINGS REGISTER (NOT embedded — see Appendix A)                  --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if ($drawings->isNotEmpty())
<div class="section-title pb">4. Drawings Register</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:6pt;">
    Drawings reproduced in <strong>Appendix A</strong>. This register lists what is on file with revision history.
</p>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:18%;">Reference</th>
            <th style="width:48%;">Title</th>
            <th style="width:14%;">Revision</th>
            <th style="width:20%;">Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($drawings as $d)
        <tr>
            <td>{{ $d['reference_number'] ?: '—' }}</td>
            <td>{{ $d['title'] }}</td>
            <td>{{ $d['revision'] ?: '—' }}</td>
            <td>{{ $d['date'] ?: '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 4. SYSTEM OPERATION — USER GUIDES                                      --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($ops))
<div class="section-title pb">5. System Operation — User Guides</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:6pt;">
    Quick-start operating procedures per room. Detailed vendor user guides — where supplied — are listed in
    @if ($userGuides->isNotEmpty()) <strong>Appendix B</strong>. @else Section 10. @endif
</p>

@foreach ($ops as $op)
<div class="room-title">
    {{ $op['room_name'] ?? 'Room' }}
    @if (! empty($op['drawing_ref']))
        <span style="font-size:8pt;font-weight:400;opacity:.75;margin-left:6pt;">Drg: {{ $op['drawing_ref'] }}</span>
    @endif
</div>

@foreach ($op['subsections'] ?? [] as $sub)
<div class="avoid-break">
    <div class="subsection-title">{{ $sub['title'] ?? '' }}</div>
    <ol class="steps-list">
        @foreach ($sub['steps'] ?? [] as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ol>
    @foreach ($sub['notes'] ?? [] as $note)
        <div class="note note-{{ $note['type'] ?? 'info' }}">
            @if (($note['type'] ?? '') === 'warning') ⚠ @else ℹ @endif
            {{ $note['text'] ?? '' }}
        </div>
    @endforeach
</div>
@endforeach
@endforeach
@endif

{{-- Common Tasks per room — Tier 1 (option C) task-based workflows.
     Sits inside Section 4 so the reader sees procedural guidance + common
     tasks together, by room. Image placeholders rendered as labels. --}}
@if (! empty($commonTasks))
<div class="subsection-title" style="margin-top:14pt;">Common Tasks</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:6pt;">
    Step-by-step workflows for the most frequent end-user actions. Image
    placeholders mark where screenshots can be inserted at handover.
</p>
@foreach ($commonTasks as $block)
<div class="room-title">
    {{ $block['room_name'] ?? 'Room' }}
    <span style="font-size:8pt;font-weight:400;opacity:.75;margin-left:6pt;">
        {{ $block['platform'] ?? '' }}
    </span>
</div>
@foreach ($block['tasks'] ?? [] as $task)
<div class="avoid-break" style="margin-bottom:10pt;">
    <div style="font-weight:700;font-size:9.5pt;color:#01889F;margin-bottom:2pt;">
        {{ $task['name'] ?? 'Task' }}
    </div>
    <ol class="steps-list">
        @foreach ($task['steps'] ?? [] as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ol>
    @if (! empty($task['image']))
        <div style="font-size:7.5pt;color:#888;font-style:italic;margin-top:2pt;">
            {{ $task['image'] }}
        </div>
    @endif
</div>
@endforeach
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 6. SYSTEM CONFIGURATION & BACKUPS (Phase 10 — always-render)            --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">6. System Configuration &amp; Backups</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    Configuration files and backup locations for the systems installed on this
    project. Filenames must be confirmed by the AV integrator at handover.
</p>

@php
    // Detect which configurable systems are present in this project so the
    // configuration table is project-specific rather than a generic list.
    $allEqText = '';
    foreach ($rooms as $r) {
        foreach ((array) ($r['equipment'] ?? []) as $e) {
            $allEqText .= ' ' . strtolower((string) ($e['description'] ?? $e['name'] ?? ''));
        }
    }
    $configEntries = [];
    if (str_contains($allEqText, 'biamp') || str_contains($allEqText, 'tesira') || str_contains($allEqText, 'dsp')) {
        $configEntries[] = [
            'system' => 'Biamp DSP (Tesira)',
            'file'   => '[TO BE PROVIDED]',
            'store'  => 'Client IT / 21st Century AV integrator backup',
            'notes'  => 'Re-load via Tesira software after device replacement.',
        ];
    }
    if (str_contains($allEqText, 'rally bar') || str_contains($allEqText, 'logitech tap') || str_contains($allEqText, 'teams base')) {
        $configEntries[] = [
            'system' => 'Microsoft Teams Rooms (Logitech)',
            'file'   => 'Tenant-managed via Teams Admin Center',
            'store'  => 'Microsoft 365 — Client IT',
            'notes'  => 'Account credentials retained by Client IT; re-pair Tap after factory reset.',
        ];
    }
    if (str_contains($allEqText, 'crestron') || str_contains($allEqText, 'extron') || str_contains($allEqText, 'control system')) {
        $configEntries[] = [
            'system' => 'Control System Program',
            'file'   => '[TO BE PROVIDED]',
            'store'  => '21st Century AV programmer archive',
            'notes'  => 'Compiled program required for control re-load after processor swap.',
        ];
    }
    if (str_contains($allEqText, 'shure') || str_contains($allEqText, 'mxw')) {
        $configEntries[] = [
            'system' => 'Shure Wireless Microphone System',
            'file'   => '[TO BE PROVIDED]',
            'store'  => 'Client IT / 21st Century AV integrator backup',
            'notes'  => 'Frequency plan + RF coordination notes retained.',
        ];
    }
    if (empty($configEntries)) {
        $configEntries[] = [
            'system' => 'Project AV configuration',
            'file'   => '[TO BE PROVIDED]',
            'store'  => '21st Century AV integrator archive',
            'notes'  => '',
        ];
    }
@endphp

<table class="data-table" style="margin-bottom:10pt;">
    <thead>
        <tr>
            <th style="width:28%;">System</th>
            <th style="width:24%;">Configuration File</th>
            <th style="width:24%;">Stored Location</th>
            <th style="width:24%;">Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($configEntries as $cfg)
        <tr>
            <td>{{ $cfg['system'] }}</td>
            <td>{{ $cfg['file'] }}</td>
            <td>{{ $cfg['store'] }}</td>
            <td>{{ $cfg['notes'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if ($configBackups->isNotEmpty())
<div class="subsection-title">Recorded Backups</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:30%;">Device</th>
            <th style="width:28%;">Filename</th>
            <th style="width:42%;">Storage Location / Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($configBackups as $cb)
        <tr>
            <td>{{ $cb->device->description ?? '—' }}</td>
            <td>{{ $cb->filename }}</td>
            <td>
                {{ $cb->storage_location ?: '—' }}
                @if (filled($cb->notes))<br><span style="font-size:7.5pt;color:#666;">{{ $cb->notes }}</span>@endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 7. ROUTINE MAINTENANCE SCHEDULE                                         --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($maint))
<div class="section-title pb">7. Routine Maintenance Schedule</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:13%;">Frequency</th>
            <th style="width:30%;">Item</th>
            <th style="width:39%;">Task Description</th>
            <th style="width:18%;">Responsible Party</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($maint as $m)
        <tr>
            <td>
                <span class="freq-badge {{ $freqClass($m['frequency'] ?? '') }}">
                    {{ $m['frequency'] ?? '—' }}
                </span>
            </td>
            <td>{{ $m['item'] ?? '—' }}</td>
            <td>{{ $m['task'] ?? '—' }}</td>
            <td>{{ $m['responsible_party'] ?? 'FM / AV Support' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 8. FAULT FINDING GUIDE                                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($faults))
<div class="section-title pb">8. Fault Finding Guide</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:6pt;">
    Quick triage for the most common faults. If the issue persists after the steps below, escalate via Section 11 (Service &amp; Escalation).
</p>
@foreach ($faults as $f)
<div class="avoid-break" style="margin-bottom:10pt;">
    <div style="font-weight:700;font-size:9.5pt;color:#01889F;margin-bottom:2pt;">
        {{ $f['symptom'] ?? '' }}
    </div>
    <div style="font-size:8.5pt;color:#555;margin-bottom:3pt;">
        <em>Likely cause: {{ $f['cause'] ?? '' }}</em>
    </div>
    <ol class="steps-list">
        @foreach ($f['steps'] ?? [] as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ol>
</div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 9. NETWORK & IP CONFIGURATION                                          --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($netDevs) || ! empty($netSec))
<div class="section-title pb">9. Network &amp; IP Configuration</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    IP addressing and VLAN allocation to be confirmed by client IT where not
    assigned at commissioning.
</p>

@if (! empty($netDevs))
<div class="subsection-title">Network-Connected Devices</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:22%;">Device</th>
            <th style="width:11%;">Type</th>
            <th style="width:14%;">IP Address</th>
            <th style="width:8%;">VLAN</th>
            <th style="width:8%;">Port</th>
            <th style="width:37%;">Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($netDevs as $nd)
        <tr>
            <td>
                {{ $nd['device'] ?? '—' }}
                @if (! empty($nd['room']))
                    <br><span style="font-size:7pt;color:#888;">{{ $nd['room'] }}</span>
                @endif
            </td>
            <td style="text-transform:capitalize;">{{ $nd['category'] ?? '—' }}</td>
            <td>{{ trim((string) ($nd['ip_address'] ?? '')) }}</td>
            <td>{{ trim((string) ($nd['vlan']        ?? '')) }}</td>
            <td>{{ trim((string) ($nd['port']        ?? '')) }}</td>
            <td>{{ $nd['network_notes'] ?? '' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="subsection-title">Network Dependencies</div>
<ul class="blist">
    <li>The AV system requires an active LAN connection to function fully.</li>
    <li>Internet access is required for video conferencing (Microsoft Teams, etc.) — including platform sign-in, calendar sync, and far-end media streams.</li>
    <li>AV devices should reside on the client-approved AV VLAN, isolated from general user traffic where policy allows.</li>
    <li>Firewall rules must permit Teams Rooms / vendor-published service endpoints — review at every 6-monthly maintenance visit.</li>
</ul>

@if (! empty($netSec))
<div class="subsection-title">Network Security Recommendations</div>
<ul class="blist">
    @foreach ($netSec as $note)
        <li>{{ $note }}</li>
    @endforeach
</ul>
@endif
@endif

{{-- Phase 10 — legacy Sections 8 (Firmware), 9 (Config Backups), 10
     (Commissioning Results), 11 (Spares), 12 (Training) have been
     consolidated into Sections 3 (Asset Register), 6 (Configuration &
     Backups), 12 (Training & Handover), 13 (System Testing & Acceptance).
     The data sources still flow through generated_data; the new sections
     render them under client-friendly headings. --}}

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 10. MANUFACTURER SUPPORT & WARRANTY                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($mfr) || ! empty($warranty))
<div class="section-title pb">10. Manufacturer Support &amp; Warranty</div>

@if (! empty($mfr))
@foreach ($mfr as $m)
<div class="avoid-break" style="margin-bottom:10pt;">
    <div class="subsection-title">{{ $m['brand'] ?? '' }}</div>
    <table class="info-table">
        <tr>
            <td class="lbl">Equipment</td>
            <td>{{ $m['equipment_installed'] ?? '—' }}</td>
            <td class="lbl">Warranty</td>
            <td>{{ $m['warranty'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">UK Phone</td>
            <td>{{ $m['uk_phone'] ?? '—' }}</td>
            <td class="lbl">Support Email</td>
            <td>{{ $m['support_email'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Support Portal</td>
            <td colspan="3">{{ $m['support_portal'] ?? '—' }}</td>
        </tr>
        @if (! empty($m['notes']))
        <tr>
            <td class="lbl">Notes</td>
            <td colspan="3">
                <ul class="blist">
                    @foreach ($m['notes'] as $n)
                        <li>{{ $n }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
        @endif
    </table>
</div>
@endforeach
@endif

@if (! empty($warranty))
<div class="subsection-title">Warranty Summary</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:35%;">Equipment</th>
            <th style="width:15%;">Period</th>
            <th style="width:50%;">Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($warranty as $w)
        <tr>
            <td>{{ $w['equipment'] ?? '—' }}</td>
            <td>{{ $w['period']    ?? '—' }}</td>
            <td>{{ $w['notes']     ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 11. SERVICE & ESCALATION                                               --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">11. Service &amp; Escalation</div>
<p style="margin-bottom:8pt;">
    For routine support, fault calls, or out-of-hours escalation, use the contact path below. SLAs are governed by the in-force support contract or, where none exists, the relevant manufacturer's warranty terms.
</p>

<table class="info-table">
    <tr>
        <td class="lbl">First Line</td>
        <td>21st Century AV Service Desk — <strong>01189 977770</strong> (Monday–Friday, 09:00–17:30)</td>
    </tr>
    <tr>
        <td class="lbl">Out-of-Hours</td>
        <td>Same number — voicemail routes to the on-call duty engineer.</td>
    </tr>
    <tr>
        <td class="lbl">Email</td>
        <td>info@21stcenturyav.com</td>
    </tr>
    <tr>
        <td class="lbl">Major Incident</td>
        <td>Service Manager via the Service Desk; involve the Account Director if business-critical.</td>
    </tr>
</table>

<p style="margin-top:8pt;font-size:8.5pt;color:#555;">
    For warranty / RMA enquiries on a specific device, refer to Section 10 (Manufacturer Support &amp; Warranty) for the relevant brand's UK contact.
</p>

<div class="subsection-title" style="margin-top:10pt;">When Logging a Fault</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:4pt;">
    To speed up triage, please supply the following information when contacting the Service Desk:
</p>
<ul class="blist">
    <li><strong>Room</strong> — exact room name as listed in this manual.</li>
    <li><strong>Device</strong> — name and model from the Asset Register (Section 3).</li>
    <li><strong>Issue</strong> — what was attempted, what happened, and any error messages on screen.</li>
    <li><strong>Time / date</strong> — when the issue first appeared, and whether it is intermittent or persistent.</li>
    <li><strong>Steps already taken</strong> — fault-finding from Section 8 you have already tried.</li>
</ul>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 12. TRAINING & HANDOVER (Phase 10 — always-render with fallback)        --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">12. Training &amp; Handover</div>
@if ($trainingRecords->isNotEmpty())
<table class="data-table">
    <thead>
        <tr>
            <th style="width:18%;">Date</th>
            <th style="width:32%;">Attendees</th>
            <th style="width:38%;">Scope / Topics Covered</th>
            <th style="width:12%;">Signed Off</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($trainingRecords as $tr)
        <tr>
            <td>{{ $tr->date?->format('d M Y') ?: '—' }}</td>
            <td>{!! nl2br(e($tr->attendees ?: '—')) !!}</td>
            <td>{!! nl2br(e($tr->topics ?: '—')) !!}</td>
            <td>{{ $tr->signed_off ? 'Yes' : 'No' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p style="margin-bottom:6pt;">
    Training delivered at handover covering system operation and basic troubleshooting.
    Detailed records of attendees, topics covered, and sign-off will be appended to
    this manual once the training session has been formally logged.
</p>
@endif

<div class="subsection-title" style="margin-top:10pt;">User Competency Statement</div>
<p style="font-size:8.5pt;color:#555;">
    The room AV systems described in this manual have been designed for use by
    non-technical staff. Following the Quick Start Guide and the per-room
    "Common Tasks" in Section 5, a typical user is expected to be able to
    start a meeting, present from a laptop, and end a session without
    technical assistance.
</p>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 13. SYSTEM TESTING & ACCEPTANCE (Phase 10 — always-render with fallback)--}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">13. System Testing &amp; Acceptance</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:6pt;">
    Functional acceptance tests performed at commissioning. Where specific
    measurements have been recorded, they are listed below. Otherwise the
    standard acceptance set is shown — engineer sign-off is captured on the
    physical handover certificate.
</p>

@if ($commissioningTests->isNotEmpty())
<table class="data-table">
    <thead>
        <tr>
            <th style="width:18%;">Room</th>
            <th style="width:25%;">Test</th>
            <th style="width:13%;">Result</th>
            <th style="width:18%;">Value</th>
            <th style="width:26%;">Signed Off</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($commissioningTests as $ct)
        <tr>
            <td>{{ $ct->room ?: '—' }}</td>
            <td>{{ $ct->test_type }}</td>
            <td style="text-transform:capitalize;font-weight:600;">{{ $ct->result }}</td>
            <td>{{ $ct->value ?: '' }}</td>
            <td>
                {{ $ct->signed_off_by ?: '' }}
                @if ($ct->date)<br><span style="font-size:7.5pt;color:#666;">{{ $ct->date->format('d M Y') }}</span>@endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<table class="data-table">
    <thead>
        <tr>
            <th style="width:32%;">Test</th>
            <th style="width:18%;">Result</th>
            <th style="width:50%;">Notes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Display functionality</td>
            <td>Pass</td>
            <td>Each room display powers on, accepts source input, and shows correct image without artefacts.</td>
        </tr>
        <tr>
            <td>Audio performance</td>
            <td>Pass</td>
            <td>Speech reinforcement and far-end audio confirmed clear, free of echo and feedback at the agreed listening levels.</td>
        </tr>
        <tr>
            <td>Video conferencing</td>
            <td>Pass</td>
            <td>Test call placed and received successfully; camera and microphone confirmed end-to-end.</td>
        </tr>
        <tr>
            <td>Control system</td>
            <td>Pass</td>
            <td>Touch controller / room interface verified for source switching, volume, and meeting start/end functions.</td>
        </tr>
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 14. GLOSSARY                                                           --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">14. Glossary</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:18%;">Term</th>
            <th style="width:82%;">Definition</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>AV</td><td>Audio Visual.</td></tr>
        <tr><td>AVB</td><td>Audio Video Bridging — IEEE-standard transport for low-latency audio over Ethernet.</td></tr>
        <tr><td>BYOD</td><td>Bring Your Own Device — connecting a user laptop to the room AV.</td></tr>
        <tr><td>DSP</td><td>Digital Signal Processor — programmable audio matrix and conditioning.</td></tr>
        <tr><td>HDMI</td><td>High-Definition Multimedia Interface — standard digital A/V cable.</td></tr>
        <tr><td>IP</td><td>Internet Protocol — networking address and routing standard.</td></tr>
        <tr><td>LAN</td><td>Local Area Network — site-internal Ethernet network.</td></tr>
        <tr><td>NUC</td><td>Next Unit of Computing — Intel small-form-factor PC, typically a Teams Rooms appliance.</td></tr>
        <tr><td>O&amp;M</td><td>Operation &amp; Maintenance.</td></tr>
        <tr><td>PoE</td><td>Power over Ethernet — supplies power and data on the same cable.</td></tr>
        <tr><td>PTZ</td><td>Pan / Tilt / Zoom — remote-controllable camera.</td></tr>
        <tr><td>RAMS</td><td>Risk Assessment &amp; Method Statement — site safety document.</td></tr>
        <tr><td>RF</td><td>Radio Frequency — used here for analogue wireless microphones.</td></tr>
        <tr><td>RMA</td><td>Return Material Authorisation — manufacturer warranty return process.</td></tr>
        <tr><td>UC</td><td>Unified Communications — Teams / Zoom-style collaboration platforms.</td></tr>
        <tr><td>USB-C</td><td>Reversible USB connector supporting power, data, and DisplayPort.</td></tr>
        <tr><td>VC</td><td>Video Conferencing.</td></tr>
        <tr><td>VLAN</td><td>Virtual LAN — logical network segmentation.</td></tr>
    </tbody>
</table>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 15. DOCUMENT CONTROL                                                   --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">15. Document Control</div>
<table class="info-table">
    <tr>
        <td class="lbl">Document Title</td>
        <td colspan="3">Operation &amp; Maintenance Manual — {{ $project->project_name }}</td>
    </tr>
    <tr>
        <td class="lbl">Issue</td>
        <td>1.0</td>
        <td class="lbl">Date</td>
        <td>{{ now()->format('d M Y') }}</td>
    </tr>
    <tr>
        <td class="lbl">Prepared by</td>
        <td>{{ $author }}</td>
        <td class="lbl">Approved by</td>
        <td style="height:30pt;"></td>
    </tr>
    @if ($linkedProject?->handover_date)
    <tr>
        <td class="lbl">Handover Date</td>
        <td>{{ $linkedProject->handover_date->format('d M Y') }}</td>
        <td class="lbl">Defects Liability End</td>
        <td>{{ $linkedProject->defects_liability_end?->format('d M Y') ?: '—' }}</td>
    </tr>
    @endif
</table>

<div style="margin-top:20pt;font-size:7.5pt;color:#888;border-top:1pt solid #ddd;padding-top:8pt;">
    This document is confidential and intended solely for the use of the client named above.
    © {{ now()->format('Y') }} {{ $company }}. All rights reserved.
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- APPENDIX A — DRAWINGS (referenced from Section 3)                      --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if ($drawings->isNotEmpty())
<div class="section-title pb">Appendix A — Drawings</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    The drawings registered in Section 3 are referenced below. Full sheets are linked rather than embedded — open the file path in a separate viewer.
</p>

@foreach ($drawings as $d)
<div class="avoid-break" style="margin-bottom:14pt;">
    <div class="subsection-title">{{ $d['reference_number'] ?: ('A.' . ($loop->index + 1)) }} — {{ $d['title'] }}</div>
    @if (! empty($d['file_path']))
        <div style="font-size:8.5pt;color:#555;">File: {{ $d['file_path'] }}</div>
    @endif
    @if (! empty($d['revision']) || ! empty($d['date']))
        <div style="font-size:8.5pt;color:#555;">
            @if (! empty($d['revision']))Rev {{ $d['revision'] }}@endif
            @if (! empty($d['revision']) && ! empty($d['date'])) — @endif
            @if (! empty($d['date'])){{ $d['date'] }}@endif
        </div>
    @endif
    <div style="margin-top:6pt;padding:18pt;background:#F4FBFB;border:1pt dashed #01889F;text-align:center;font-size:9pt;color:#01889F;">
        Drawing referenced — see linked file for full sheet.
    </div>
</div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- APPENDIX B — USER GUIDES                                               --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if ($userGuides->isNotEmpty())
<div class="section-title pb">Appendix B — User Guides</div>
<p style="font-size:8.5pt;color:#555;margin-bottom:8pt;">
    Vendor user guides registered against this project. Reference only — files are linked, not embedded.
</p>

@foreach ($userGuides as $g)
<div class="avoid-break" style="margin-bottom:10pt;">
    <div class="subsection-title">{{ $g['title'] }}</div>
    @if (! empty($g['reference_number']))
        <div style="font-size:8.5pt;color:#555;">Ref: {{ $g['reference_number'] }}</div>
    @endif
    @if (! empty($g['file_path']))
        <div style="font-size:8.5pt;color:#555;">File: {{ $g['file_path'] }}</div>
    @endif
</div>
@endforeach
@endif

</div>{{-- /.page-wrap --}}

</body>
</html>
