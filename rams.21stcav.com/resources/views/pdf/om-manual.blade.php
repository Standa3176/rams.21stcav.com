<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>O&amp;M Manual — {{ $manual->project_name }}</title>
<style>
    /* ── Base ─────────────────────────────────────────────────────────── */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 9pt;
        color: #1a1a1a;
        line-height: 1.4;
    }

    /* ── Page ─────────────────────────────────────────────────────────── */
    @page {
        margin: 18mm 15mm 22mm 15mm;
        size: A4 portrait;
    }
    @page cover {
        margin: 0;
    }

    /* ── Cover page ───────────────────────────────────────────────────── */
    .cover {
        background: #007B8A;
        color: #fff;
        width: 100%;
        min-height: 297mm;
        padding: 60px 50px;
        page-break-after: always;
    }
    .cover h1 { font-size: 22pt; font-weight: 700; margin-bottom: 8px; }
    .cover h2 { font-size: 14pt; font-weight: 400; opacity: .85; margin-bottom: 40px; }
    .cover-detail { font-size: 10pt; margin-bottom: 6px; opacity: .9; }
    .cover-detail strong { opacity: 1; }
    .cover-logo {
        font-size: 13pt;
        font-weight: 700;
        letter-spacing: .04em;
        opacity: .7;
        margin-bottom: 10px;
    }
    .cover-date { margin-top: 30px; font-size: 9pt; opacity: .7; }

    /* ── Section heading ──────────────────────────────────────────────── */
    .section-title {
        font-size: 11pt;
        font-weight: 700;
        color: #fff;
        background: #007B8A;
        padding: 6px 10px;
        border-radius: 3px;
        margin: 18px 0 8px;
        page-break-after: avoid;
    }
    .subsection-title {
        font-size: 9.5pt;
        font-weight: 700;
        color: #007B8A;
        border-bottom: 1.5px solid #007B8A;
        padding-bottom: 2px;
        margin: 10px 0 5px;
        page-break-after: avoid;
    }
    .room-title {
        font-size: 10.5pt;
        font-weight: 700;
        color: #005f6b;
        background: #e0f4f6;
        padding: 5px 10px;
        border-left: 4px solid #007B8A;
        margin: 14px 0 7px;
        page-break-after: avoid;
    }

    /* ── Info table ───────────────────────────────────────────────────── */
    .info-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .info-table td {
        padding: 4px 8px;
        border: 1px solid #ddd;
        vertical-align: top;
    }
    .info-table .lbl {
        background: #f4f6f8;
        font-weight: 600;
        width: 26%;
        font-size: 8.5pt;
        color: #333;
    }

    /* ── Steps list ───────────────────────────────────────────────────── */
    .steps-list { padding-left: 16px; margin: 4px 0 6px; }
    .steps-list li { margin-bottom: 3px; line-height: 1.35; }

    /* ── Note boxes ───────────────────────────────────────────────────── */
    .note {
        padding: 5px 8px;
        border-radius: 3px;
        font-size: 8pt;
        margin: 4px 0;
        border-left: 3px solid transparent;
    }
    .note-info    { background: #e0f4f6; border-color: #007B8A; color: #0c4a52; }
    .note-warning { background: #fff3cd; border-color: #e6a817; color: #856404; }

    /* ── Data tables ──────────────────────────────────────────────────── */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8pt;
        margin-bottom: 12px;
        page-break-inside: auto;
    }
    .data-table th {
        background: #007B8A;
        color: #fff;
        padding: 5px 7px;
        text-align: left;
        font-size: 7.5pt;
        font-weight: 600;
    }
    .data-table td {
        padding: 4px 7px;
        border-bottom: 1px solid #e8e8e8;
        vertical-align: top;
    }
    .data-table tr:nth-child(even) td { background: #f9feff; }
    .data-table tr { page-break-inside: avoid; }

    /* ── Frequency badge ──────────────────────────────────────────────── */
    .freq-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 8px;
        font-size: 7pt;
        font-weight: 700;
        white-space: nowrap;
    }
    .freq-weekly    { background: #e0f4f6; color: #007B8A; }
    .freq-monthly   { background: #e8f5e9; color: #2e7d32; }
    .freq-quarterly { background: #fff3cd; color: #856404; }
    .freq-annual    { background: #fde8e8; color: #7b1c1c; }

    /* ── Footer ───────────────────────────────────────────────────────── */
    .footer {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        border-top: 1px solid #ccc;
        font-size: 7pt;
        color: #888;
        padding: 4px 0;
    }
    .footer-left  { float: left;  }
    .footer-right { float: right; }

    /* ── Bullet list ──────────────────────────────────────────────────── */
    .blist { padding-left: 14px; margin: 3px 0 6px; }
    .blist li { margin-bottom: 2px; }

    /* ── Page break util ──────────────────────────────────────────────── */
    .pb { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }
</style>
</head>
<body>

@php
    $company  = config('rams.company_name', '');
    $project  = $manual;   // shorthand — use model columns directly
    $ops      = $data['operation_sections']   ?? [];
    $maint    = $data['maintenance_schedule'] ?? [];
    $faults   = $data['fault_finding']        ?? [];
    $netDevs  = $data['network_devices']      ?? [];
    $netSec   = $data['network_security_notes'] ?? [];
    $mfr      = $data['manufacturer_support'] ?? [];
    $warranty = $data['warranty_summary']     ?? [];
    $rooms    = $data['rooms_summary']        ?? [];

    $freqClass = function(string $f): string {
        $f = strtolower($f);
        if (str_contains($f, 'week'))    return 'freq-weekly';
        if (str_contains($f, 'month'))   return 'freq-monthly';
        if (str_contains($f, 'quarter')) return 'freq-quarterly';
        return 'freq-annual';
    };
@endphp

{{-- Fixed footer appears on every page except the cover --}}
<div class="footer">
    <span class="footer-left">{{ $company }} — O&amp;M Manual: {{ $project->project_name }}</span>
    <span class="footer-right">{{ now()->format('d M Y') }}</span>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- COVER PAGE                                                             --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="cover">
    <div class="cover-logo">{{ strtoupper($company) }}</div>
    <h1>Operation &amp; Maintenance Manual</h1>
    <h2>AV Systems</h2>

    <div class="cover-detail"><strong>Project:</strong> {{ $project->project_name }}</div>
    @if ($project->project_ref)
    <div class="cover-detail"><strong>Reference:</strong> {{ $project->project_ref }}</div>
    @endif
    <div class="cover-detail"><strong>Client:</strong> {{ $project->client_name }}</div>
    <div class="cover-detail"><strong>Site:</strong> {{ $project->site_address }}</div>
    <div class="cover-date">Document Date: {{ now()->format('F Y') }}</div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 1. PROJECT SCOPE                                                       --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title">1. Project Scope &amp; Installed Systems</div>

<table class="info-table" style="margin-bottom:10px;">
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

@if (! empty($rooms))
<div class="subsection-title">Installed Equipment by Room</div>
@foreach ($rooms as $room)
    <div class="room-title" style="margin-top:6px;">
        {{ $room['name'] ?? 'Room' }}
        @if (! empty($room['drawing_ref']))
            <span style="font-size:8pt;font-weight:400;opacity:.75;margin-left:6px;">Drg: {{ $room['drawing_ref'] }}</span>
        @endif
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:8%;text-align:center;">Qty</th>
                <th style="width:46%;">Description</th>
                <th style="width:28%;">Model</th>
                <th style="width:18%;">Part No.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($room['equipment'] ?? [] as $eq)
            <tr>
                <td style="text-align:center;">{{ $eq['qty'] ?? 1 }}</td>
                <td>{{ $eq['description'] ?? '—' }}</td>
                <td>{{ $eq['model'] ?? '—' }}</td>
                <td>{{ $eq['part_no'] ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="height:8px;"></div>
@endforeach
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 2. SYSTEM OPERATION                                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($ops))
<div class="section-title pb">2. System Operation — User Guides</div>

@foreach ($ops as $op)
<div class="room-title">
    {{ $op['room_name'] ?? 'Room' }}
    @if (! empty($op['drawing_ref']))
        <span style="font-size:8pt;font-weight:400;opacity:.75;margin-left:6px;">Drg: {{ $op['drawing_ref'] }}</span>
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

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 3. ROUTINE MAINTENANCE                                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($maint))
<div class="section-title pb">3. Routine Maintenance Schedule</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:13%;">Frequency</th>
            <th style="width:28%;">Item</th>
            <th style="width:59%;">Task Description</th>
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
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 4. FAULT FINDING                                                       --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($faults))
<div class="section-title pb">4. Fault Finding Guide</div>
@foreach ($faults as $f)
<div class="avoid-break" style="margin-bottom:10px;">
    <div style="font-weight:700;font-size:9pt;color:#007B8A;margin-bottom:2px;">
        {{ $f['symptom'] ?? '' }}
    </div>
    <div style="font-size:8.5pt;color:#555;margin-bottom:3px;">
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
{{-- 5. NETWORK & IP CONFIGURATION                                         --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($netDevs) || ! empty($netSec))
<div class="section-title pb">5. Network &amp; IP Configuration</div>

@if (! empty($netDevs))
<div class="subsection-title">Network-Connected Devices</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:20%;">Room</th>
            <th style="width:30%;">Device</th>
            <th style="width:50%;">Network Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($netDevs as $nd)
        <tr>
            <td>{{ $nd['room'] ?? '—' }}@if(! empty($nd['drawing_ref'])) <br><span style="font-size:7pt;color:#999;">{{ $nd['drawing_ref'] }}</span>@endif</td>
            <td>{{ $nd['device'] ?? '—' }}</td>
            <td>{{ $nd['network_notes'] ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

@if (! empty($netSec))
<div class="subsection-title">Network Security Recommendations</div>
<ul class="blist">
    @foreach ($netSec as $note)
        <li>{{ $note }}</li>
    @endforeach
</ul>
@endif
@endif

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- 6. MANUFACTURER SUPPORT & WARRANTY                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@if (! empty($mfr) || ! empty($warranty))
<div class="section-title pb">6. Manufacturer Support &amp; Warranty</div>

@if (! empty($mfr))
@foreach ($mfr as $m)
<div class="avoid-break" style="margin-bottom:10px;">
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
{{-- 7. DOCUMENT CONTROL                                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
<div class="section-title pb">7. Document Control</div>
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
        <td style="height:30px;"></td>
        <td class="lbl">Approved by</td>
        <td style="height:30px;"></td>
    </tr>
</table>

<div style="margin-top:20px;font-size:7.5pt;color:#888;border-top:1px solid #ddd;padding-top:8px;">
    This document is confidential and intended solely for the use of the client named above.
    © {{ now()->format('Y') }} {{ $company }}. All rights reserved.
</div>

</body>
</html>
