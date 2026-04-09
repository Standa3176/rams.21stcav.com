<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RAMS — {{ $rams->project_name }}</title>
<style>
/* === DOCX-matched spec (sourced from RAMS_21CQ30124-01-OPS_MODTedworthHouse.docx) === */

* { margin:0; padding:0; box-sizing:border-box; }
html, body { width: 100%; }   /* prevent Dompdf full-bleed body */
body {
    font-family: Arial, "DejaVu Sans", sans-serif;
    font-size: 11pt;
    color: #333333;
    line-height: 1.35;
    margin: 0;
    padding: 26mm 0 18mm 0; /* top/bottom only (space for header/footer) */
}
@page { size: A4 portrait; margin: 0; }

/* content wrapper — rely on @page margins for top/bottom spacing */
.page-wrap { margin: 0 17.6mm; }

/* ── Running page header (fixed, drawn in top margin) ── */
.page-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    padding: 6mm 17.6mm 2mm 17.6mm;
    border-bottom: 0.75pt solid #00788A;
    font-size: 9pt;
}
.page-header-table { width: 100%; border-collapse: collapse; }
.page-header-table td { border: 0; padding: 0; vertical-align: bottom; }
.ph-left  { text-align: left;  font-weight: 700; color: #00788A; }
.ph-right { text-align: right; color: #666666; white-space: nowrap; }

/* ── Cover ── */
.cover-title {
    font-size: 26pt;          /* Word title = 26pt (sz=52) */
    font-weight: 700;
    color: #00788A;
    margin: 20pt 0 2pt;
}
.cover-subtitle {
    font-size: 16pt;          /* Word subtitle = 16pt (sz=32) */
    color: #1A1A1A;
    border-bottom: 1pt solid #C9A84C;
    padding-bottom: 4pt;
    margin-bottom: 14pt;
}

/* ── Section headings ── */
.sec-heading {
    font-size: 14pt;          /* Word Heading 1 = 14pt (sz=28) */
    font-weight: 700;
    color: #00788A;
    border-bottom: 0.75pt solid #00788A;
    padding-bottom: 2pt;
    margin: 16pt 0 8pt;
    page-break-after: avoid;
}
.sec-subheading {
    font-size: 12pt;          /* Word Heading 2 = 12pt (sz=24) */
    font-weight: 700;
    color: #1A1A1A;
    margin: 10pt 0 4pt;
    page-break-after: avoid;
}

/* ── Tables — borders #AAAAAA, 0.5pt (sz=4 twips in DOCX) ── */
table { border-collapse: collapse; width: 100%; }
.std-table { margin-bottom: 10pt; page-break-inside: avoid; }
.std-table td, .std-table th {
    border: 0.5pt solid #AAAAAA;
    padding: 4pt 6pt;         /* Word cell margin: 80/120 twips */
    vertical-align: top;
}
.std-table td { font-size: 10pt; }
/* Equipment schedule — smaller font to compress long quote lists */
.equip-table { margin-bottom: 10pt; font-size: 8.5pt; }
.equip-table td, .equip-table th {
    border: 0.5pt solid #AAAAAA;
    padding: 3pt 5pt;
    vertical-align: top;
}
.equip-table th {
    background: #00788A;
    color: #ffffff;
    font-weight: 700;
}
.std-table th {
    background: #00788A;
    color: #ffffff;
    font-weight: 700;
    font-size: 10pt;
}
.std-table .lbl {
    background: #E8F4F6;
    font-weight: 700;
    color: #333;
    width: 28%;               /* Word label col = 2800/9906 twips */
}
.std-table tr:nth-child(even) td:not(.lbl) { background: #F0FBFC; }

/* ── Risk rating key ── */
.risk-key-table { margin-bottom: 10pt; }
.risk-key-table td {
    border: 0.5pt solid #AAAAAA;
    padding: 4pt 6pt;
    vertical-align: middle;
}
.risk-key-table .band { font-weight: 700; text-align: center; width: 18%; }
.bg-green  { background: #D4EDDA; }
.bg-amber  { background: #FFF3CD; }
.bg-orange { background: #FFD0A0; }
.bg-red    { background: #FFDEDE; }

/* ── Hazard table ── */
.haz-table { margin-bottom: 12pt; font-size: 9pt; page-break-inside: auto; }
.haz-table th {
    background: #00788A;
    color: #fff;
    padding: 4pt 5pt;
    text-align: center;
    font-size: 8.5pt;
    font-weight: 700;
    border: 0.5pt solid #005f6b;
}
.haz-table .th-group { background: #004f5a; }
.haz-table td {
    border: 0.5pt solid #AAAAAA;
    padding: 4pt 5pt;
    vertical-align: top;
}
.haz-table tr:nth-child(even) td { background: #f9feff; }
.haz-table tr { page-break-inside: avoid; }
.haz-table thead { display: table-header-group; }
.haz-table tfoot { display: table-footer-group; }
.score-cell { text-align: center; font-weight: 700; font-size: 8.5pt; padding: 3pt 4pt; }

/* ── Lists — Word bullet: left 720 twips (12.7mm), hanging 360 (6.35mm) ── */
p { margin: 3pt 0; }
.blist {
    list-style: disc outside;
    padding-left: 12.7mm;
    margin: 3pt 0 6pt;
}
.blist li {
    margin-bottom: 3pt;
    line-height: 1.5;
}

/* ── Method statement ── */
.ms-phase {
    color: #00788A;
    font-weight: 700;
    font-size: 11pt;
    margin: 10pt 0 4pt;
    page-break-after: avoid;
}
.ms-step { margin: 0 0 3pt; }

/* ── Footer (fixed, drawn in bottom margin) ── */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 2mm 17.6mm 6mm 17.6mm;
    border-top: 0.75pt solid #00788A;
    font-size: 8pt;
    color: #666666;
}
.footer-table { width: 100%; border-collapse: collapse; }
.footer-table td { border: 0; padding: 0; vertical-align: top; }
.footer-left  { text-align: left; }
.footer-right { text-align: right; white-space: nowrap; }
.page-number:before { content: "Page " counter(page); }

/* ── Note / italic text ── */
.note-text { font-style: italic; color: #666666; margin-bottom: 8pt; font-size: 10pt; }
</style>
</head>
<body>

@php
    $project       = $data['project']          ?? [];
    $hazards       = $data['hazards']          ?? [];
    $ppe           = $data['ppe']              ?? [];
    $persons       = $data['persons_at_risk']  ?? [];
    $team          = $data['team']             ?? [];
    $ms            = $data['method_statement'] ?? [];
    $quote         = $data['quote']            ?? [];
    $formData      = $rams->form_data          ?? [];
    $siteLogistics = $data['site_logistics']   ?? [];
    $scopeOfWorks  = $data['scope_of_works']   ?? '';
    $company  = config('rams.company_name',    '21st Century AV Ltd');
    $address  = config('rams.company_address', 'Thames Court, 2 Richfield Ave, Reading, RG1 8EQ');
    $tel      = config('rams.company_tel',     '0118 977 771');
    $email    = config('rams.company_email',   'operations@21stcenturyav.com');

    // Hard guards: prevent "Uninitialized string offset" if AI returns a string
    $hazards = is_array($hazards) ? $hazards : [];
    $ppe     = is_array($ppe)     ? $ppe     : [];
    $persons = is_array($persons) ? $persons : [];
    $team    = is_array($team)    ? $team    : [];
    $ms      = is_array($ms)      ? $ms      : [];
    $quote   = is_array($quote)   ? $quote   : [];

    $opsContact = trim(implode('  |  ', array_filter([$email, $tel])));

    $riskCellBg = fn(int $s): string => match(true) {
        $s >= 15 => 'bg-red',
        $s >= 8  => 'bg-orange',
        $s >= 4  => 'bg-amber',
        default  => 'bg-green',
    };

    $riskCellColor = fn(int $s): string => match(true) {
        $s >= 15 => '#FFDEDE',
        $s >= 8  => '#FFD0A0',
        $s >= 4  => '#FFF3CD',
        default  => '#D4EDDA',
    };

    $projectName = $project['name'] ?? $rams->project_name ?? 'AV Installation';
    $projectRef  = $project['ref']  ?? $rams->project_ref  ?? '';

    // PPE lookup: item → [required_when, standard]
    $ppeDetails = [
        'Safety Footwear (toe-cap)'    => ['All times on site',                'EN ISO 20345'],
        'Safety Boots (steel toe cap)' => ['All times on site',                'EN ISO 20345'],
        'Safety Gloves'                => ['Manual handling / sharp edges',     'EN 388'],
        'Latex / Nitrile Gloves'       => ['Cable handling, sharp edges',       'EN 374'],
        'Safety Glasses'               => ['Drilling / cutting',                'EN 166'],
        'Hard Hat'                     => ['Overhead works & exclusion zone',   'EN 397'],
        'Dust Mask (FFP2)'             => ['Dust-generating activities',         'EN 149'],
        'Hi-Vis Vest'                  => ['If required by site',               'EN ISO 20471'],
        'Hi-Visibility Vest'           => ['During delivery/unloading operations', 'EN ISO 20471'],
        'Ear Defenders'                => ['Noisy power tool use',              'EN 352'],
        'Hearing Protection'           => ['Sustained power tool use',          'EN 352'],
    ];
@endphp

{{-- Running page header --}}
<div class="page-header">
    <table class="page-header-table">
        <tr>
            <td class="ph-left">{{ $company }}  |  RAMS  |  {{ $projectName }}</td>
            <td class="ph-right">Ref: {{ $projectRef }}</td>
        </tr>
    </table>
</div>

{{-- Footer --}}
<div class="footer">
    <table class="footer-table">
        <tr>
            <td class="footer-left">{{ $company }}  |  {{ $address }}  |  {{ $tel }}</td>
            <td class="footer-right"><span class="page-number"></span></td>
        </tr>
    </table>
</div>

<div class="page-wrap">

{{-- ═══ COVER ═══════════════════════════════════════════════════════════════ --}}
<div class="cover-title">RISK ASSESSMENT &amp; METHOD STATEMENT</div>
<div class="cover-subtitle">
    Audio Visual Installation  &ndash;  {{ $projectName }}
</div>

<table class="std-table">
    <tr><td class="lbl">Project Reference</td><td>{{ $projectRef ?: '—' }}</td></tr>
    <tr><td class="lbl">Client</td>           <td>{{ $project['client']       ?? $rams->client_name   ?? '—' }}</td></tr>
    <tr><td class="lbl">Site Address</td>     <td>{{ $project['site_address'] ?? $rams->site_address  ?? '—' }}</td></tr>
    <tr><td class="lbl">Site Contact</td>     <td>{{ $formData['site_contact'] ?? ($formData['client_contact'] ?? '') }}</td></tr>
    <tr><td class="lbl">Prepared by</td>      <td>{{ $company }}</td></tr>
    <tr><td class="lbl">Operations Contact</td><td>{{ $opsContact }}</td></tr>
    <tr><td class="lbl">Date of Issue</td>    <td>{{ $rams->created_at->format('F Y') }}</td></tr>
    <tr><td class="lbl">Document Version</td> <td>v1.0</td></tr>
    <tr><td class="lbl">Review Date</td>      <td>Prior to each site visit / phase commencement</td></tr>
</table>

<div class="sec-subheading">Operations Info</div>
<table class="std-table">
    <tr><td class="lbl">Project Manager</td><td>{{ $formData['project_manager'] ?? ($project['project_manager'] ?? '') }}</td></tr>
    <tr><td class="lbl">Lead Engineer</td><td>{{ $formData['lead_engineer'] ?? ($project['lead_engineer'] ?? '') }}</td></tr>
    <tr><td class="lbl">Additional Engineer(s)</td><td>{{ $formData['additional_engineers'] ?? ($project['additional_engineers'] ?? '') }}</td></tr>
    <tr><td class="lbl">Programmer</td><td>{{ $formData['programmer'] ?? ($project['programmer'] ?? '') }}</td></tr>
</table>

{{-- ═══ 1. SCOPE OF WORKS ═══════════════════════════════════════════════════ --}}
<div class="sec-heading">1. Scope of Works</div>
<p style="margin-bottom:8px;">
    {{ $scopeOfWorks ?: ($project['works_description'] ?? $formData['works_description'] ?? 'AV installation works as per quotation.') }}
</p>
<p class="note-text">
    Works will be carried out by {{ $company }} qualified AV Engineers during standard working hours:
    Monday–Friday, 09:00–17:30, unless otherwise agreed in writing with the client.
</p>

@if(! empty($quote) && ! empty($quote['line_items']))
    <div class="sec-subheading">Quoted Equipment Schedule</div>
    @if(! empty($quote['hardware_by_room']))
        @foreach($quote['hardware_by_room'] as $group)
            <div style="font-weight:700; color:#333; margin:6pt 0 2pt;">
                {{ $group['room'] ?? 'General' }}
            </div>
            <table class="equip-table">
                <tr>
                    <th style="width:10%;">Qty</th>
                    <th>Hardware Item</th>
                </tr>
                @foreach(($group['items'] ?? []) as $item)
                <tr>
                    <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
                    <td>{{ $item['description'] ?? '' }}</td>
                </tr>
                @endforeach
            </table>
        @endforeach
    @else
        <table class="equip-table">
            <tr>
                <th style="width:10%;">Qty</th>
                <th>Hardware Item</th>
            </tr>
            @foreach($quote['line_items'] as $item)
            <tr>
                <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
                <td>{{ $item['description'] ?? '' }}</td>
            </tr>
            @endforeach
        </table>
    @endif
@endif

{{-- ═══ 2. COMPANY & KEY PERSONNEL ════════════════════════════════════════ --}}
<div class="sec-heading">2. Company &amp; Key Personnel</div>
<table class="std-table">
    <tr><td class="lbl">Principal Contractor</td><td>{{ $company }}</td></tr>
    <tr><td class="lbl">Registered Address</td>  <td>{{ $address }}</td></tr>
    <tr><td class="lbl">Company Reg No.</td>      <td>03700669</td></tr>
    <tr><td class="lbl">H&amp;S Accreditation</td><td>{{ config('rams.hs_accreditation', 'SafeContractor accredited') }}</td></tr>
    @php
        $tc           = collect($team);
        $leadEngineer = ($tc->firstWhere('role', 'Lead Engineer') ?? $tc->firstWhere('role', 'Engineer') ?? [])['name'] ?? 'To be confirmed prior to works';
        $supervisor   = ($tc->firstWhere('role', 'Supervisor') ?? $tc->firstWhere('role', 'Project Manager') ?? [])['name'] ?? 'To be confirmed prior to works';
    @endphp
    <tr><td class="lbl">Lead Engineer</td>        <td>{{ $leadEngineer }}</td></tr>
    <tr><td class="lbl">Supervisor</td>           <td>{{ $supervisor }}</td></tr>
    <tr><td class="lbl">Emergency Contact</td>    <td>{{ $tel }}  |  {{ $email }}</td></tr>
</table>
<p style="font-size:8pt; color:#555; margin-bottom:8px;">
    All operatives hold relevant CSCS/ECS cards, manufacturer certifications, and have completed
    induction training covering manual handling, working at height, and electrical awareness.
</p>

@if(! empty($team))
    <div class="sec-subheading">Engineering Team</div>
    <table class="std-table">
        <tr><th style="width:35%;">Role</th><th style="width:35%;">Name</th><th style="width:30%;">Mobile</th></tr>
        @foreach($team as $member)
        <tr>
            <td>{{ $member['role']   ?? '' }}</td>
            <td>{{ $member['name']   ?? '' }}</td>
            <td>{{ $member['mobile'] ?? '' }}</td>
        </tr>
        @endforeach
    </table>
@endif

@php
    $slContact    = trim(implode('  |  ', array_filter([$siteLogistics['contact_name'] ?? '', $siteLogistics['contact_phone'] ?? '', $siteLogistics['contact_email'] ?? ''])));
    $slParking    = $siteLogistics['parking'] ?? '';
    $slParkingNote= $siteLogistics['parking_notes'] ?? '';
    $slFloor      = $siteLogistics['install_floor'] ?? '';
    $slDelivery   = $siteLogistics['delivery_area'] ?? '';
    $slAccessType = $siteLogistics['access_type'] ?? '';
    $slAccessNote = $siteLogistics['access_notes'] ?? '';
    $slRestrict   = $siteLogistics['restrictions'] ?? '';
    $slCommission = $siteLogistics['commissioning_notes'] ?? '';
    $hasLogistics = $slContact || $slParking || $slFloor || $slDelivery || $slAccessType || $slRestrict;
@endphp
@if($hasLogistics)
<div class="sec-subheading">Site Logistics</div>
<table class="std-table">
    @if($slContact)
    <tr><td class="lbl" style="width:30%;">Site Contact</td><td>{{ $slContact }}</td></tr>
    @endif
    @if($slParking !== '')
    <tr><td class="lbl">Parking Available</td><td>{{ $slParking === 'yes' ? 'Yes' : 'No' }}@if($slParkingNote) &mdash; {{ $slParkingNote }}@endif</td></tr>
    @endif
    @if($slFloor)
    <tr><td class="lbl">Installation Floor</td><td>{{ $slFloor }}</td></tr>
    @endif
    @if($slDelivery)
    <tr><td class="lbl">Delivery &amp; Staging Area</td><td>{{ $slDelivery }}</td></tr>
    @endif
    @if($slAccessType)
    <tr><td class="lbl">Site Access</td>
        <td>
            @php
                $accessLabels = [
                    'no_special'  => 'No special access requirements',
                    'induction'   => 'Site induction required before commencing work',
                    'reception'   => 'Report to reception on arrival',
                    'security'    => 'Report to security on arrival',
                    'other'       => 'Other (see notes)',
                ];
            @endphp
            {{ $accessLabels[$slAccessType] ?? $slAccessType }}
            @if($slAccessNote) &mdash; {{ $slAccessNote }}@endif
        </td>
    </tr>
    @endif
    @if($slRestrict)
    <tr><td class="lbl">Site Restrictions</td><td>{{ $slRestrict }}</td></tr>
    @endif
    @if($slCommission)
    <tr><td class="lbl">Commissioning Notes</td><td>{{ $slCommission }}</td></tr>
    @endif
</table>
@endif

{{-- ═══ 3. LEGISLATION ════════════════════════════════════════════════════ --}}
<div class="sec-heading">3. Relevant Legislation &amp; Standards</div>
<ul class="blist">
    <li>Health &amp; Safety at Work etc. Act 1974</li>
    <li>Management of Health &amp; Safety at Work Regulations 1999</li>
    <li>Manual Handling Operations Regulations 1992 (amended 2002)</li>
    <li>Provision and Use of Work Equipment Regulations (PUWER) 1998</li>
    <li>Electricity at Work Regulations 1989</li>
    <li>Control of Noise at Work Regulations 2005</li>
    <li>The Work at Height Regulations 2005</li>
    <li>CDM Regulations 2015 (where applicable)</li>
    <li>Environmental Protection Act 1990 (waste disposal)</li>
    <li>The Regulatory Reform (Fire Safety) Order 2005</li>
    <li>Personal Protective Equipment at Work Regulations 2022</li>
    <li>Reporting of Injuries, Diseases and Dangerous Occurrences Regulations (RIDDOR) 2013</li>
</ul>
<p style="font-size:8pt; color:#555; margin-bottom:8px;">
    This RAMS reflects the requirements of the above and all other applicable legislation at the time of issue.
</p>

{{-- ═══ 4. RISK ASSESSMENT ════════════════════════════════════════════════ --}}
<div class="sec-heading" style="page-break-before: always;">4. Risk Assessment</div>
<p style="font-size:8.5pt; margin-bottom:6px;">
    Risk Rating = Likelihood (L) &times; Severity (S). &nbsp; Key: L/S scale 1–5.
</p>

<div class="sec-subheading">RISK RATING KEY</div>
<table class="risk-key-table" style="margin-bottom:12px;">
    <tr>
        <td class="band bg-green">1–3<br><strong>Low</strong></td>
        <td style="font-size:8.5pt;">Risk is acceptable. Proceed with standard precautions.</td>
    </tr>
    <tr>
        <td class="band bg-amber">4–6<br><strong>Medium</strong></td>
        <td style="font-size:8.5pt;">Risk requires attention. Implement additional controls before proceeding.</td>
    </tr>
    <tr>
        <td class="band bg-orange">8–12<br><strong>High</strong></td>
        <td style="font-size:8.5pt;">Significant risk. Management review required before proceeding.</td>
    </tr>
    <tr>
        <td class="band bg-red">15–25<br><strong>Critical</strong></td>
        <td style="font-size:8.5pt;">Unacceptable risk. Work must not proceed until risk is reduced.</td>
    </tr>
</table>

<div class="sec-subheading">Hazard Register</div>
@if(! empty($hazards))
@foreach($hazards as $h)
@php
    $preScore  = ($h['pre_likelihood']  ?? 1) * ($h['pre_severity']  ?? 1);
    $postScore = ($h['post_likelihood'] ?? 1) * ($h['post_severity'] ?? 1);
@endphp
<table class="haz-table" style="margin-bottom:10pt; page-break-inside:avoid;">
    <thead>
        <tr>
            <th rowspan="2" style="width:20%;">Hazard</th>
            <th rowspan="2" style="width:12%;">Who Affected</th>
            <th colspan="3" class="th-group" style="width:18%;">Initial Rating</th>
            <th rowspan="2" style="width:30%;">Control Measures</th>
            <th colspan="3" class="th-group" style="width:18%;">Residual Rating</th>
        </tr>
        <tr>
            <th style="width:6%;">L</th>
            <th style="width:6%;">S</th>
            <th style="width:6%;">Risk</th>
            <th style="width:6%;">L</th>
            <th style="width:6%;">S</th>
            <th style="width:6%;">Residual</th>
        </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>{{ $h['hazard'] ?? '' }}</strong></td>
        <td>
            <ul class="blist" style="padding-left:10px;">
                @foreach((array)($h['persons_at_risk'] ?? []) as $p)
                    @if(is_string($p) && $p !== '')
                    <li>{{ $p }}</li>
                    @endif
                @endforeach
            </ul>
        </td>
        <td class="score-cell">{{ $h['pre_likelihood'] ?? '' }}</td>
        <td class="score-cell">{{ $h['pre_severity']   ?? '' }}</td>
        <td class="score-cell" style="background: {{ $riskCellColor($preScore) }};">{{ $preScore }}</td>
        <td>
            <ul class="blist" style="padding-left:10px;">
                @foreach((array)($h['controls'] ?? []) as $c)
                    @if(is_string($c))
                    <li>{{ $c }}</li>
                    @endif
                @endforeach
            </ul>
        </td>
        <td class="score-cell">{{ $h['post_likelihood'] ?? '' }}</td>
        <td class="score-cell">{{ $h['post_severity']   ?? '' }}</td>
        <td class="score-cell" style="background: {{ $riskCellColor($postScore) }};">{{ $postScore }}</td>
    </tr>
    </tbody>
</table>
@endforeach
<p style="font-size:7.5pt; color:#555; margin-bottom:8px;">
    L = Likelihood &nbsp;|&nbsp; S = Severity &nbsp;|&nbsp; Risk = L &times; S &nbsp;|&nbsp;
    Ratings based on controls being in place as described.
</p>
@else
<p class="note-text">No hazards identified.</p>
@endif

{{-- ═══ 5. METHOD STATEMENT ════════════════════════════════════════════════ --}}
<div class="sec-heading" style="page-break-before: always;">5. Method Statement</div>
@php $phases = is_array($ms) ? ($ms['phases'] ?? []) : []; @endphp
@if(! empty($phases))
    @foreach($phases as $i => $phase)
        @php $phaseTitle = preg_replace('/^\d+[\.\)]\s*/', '', $phase['title'] ?? 'Phase ' . ($i + 1)); @endphp
        <div class="ms-phase">5.{{ $i + 1 }}&nbsp;&nbsp; {{ $phaseTitle }}</div>
        <ul class="blist">
            @foreach((array)($phase['steps'] ?? []) as $step)
                @if(is_string($step))
                <li>{{ $step }}</li>
                @endif
            @endforeach
        </ul>
    @endforeach
@else
    <p class="note-text">Method statement not available.</p>
@endif

{{-- ═══ 6. PPE ═════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">6. Personal Protective Equipment</div>
@if(! empty($ppe))
<table class="std-table">
    <tr>
        <th style="width:32%;">PPE Item</th>
        <th style="width:40%;">Required When</th>
        <th>Standard</th>
    </tr>
    @foreach($ppe as $item)
        @php
            $details = $ppeDetails[$item] ?? null;
            $req     = is_array($details) ? ($details[0] ?? 'As required by risk assessment') : 'As required by risk assessment';
            $std     = is_array($details) ? ($details[1] ?? '—') : '—';
        @endphp
        <tr>
            <td>{{ $item }}</td>
            <td>{{ $req }}</td>
            <td>{{ $std }}</td>
        </tr>
    @endforeach
</table>
@else
<p class="note-text">PPE requirements to be confirmed by the project manager.</p>
@endif

{{-- ═══ 7. EMERGENCY PROCEDURES ═══════════════════════════════════════════ --}}
<div class="sec-heading">7. Emergency Procedures</div>

<div class="sec-subheading">7.1 First Aid</div>
<ul class="blist">
    <li>At least one operative per team will hold a current First Aid at Work or Emergency First Aid at Work certificate.</li>
    <li>A first aid kit will be available on the work floor and in the engineer's vehicle.</li>
    <li>In the event of injury requiring hospital treatment, call 999 immediately.</li>
    <li>All injuries and near-misses must be recorded in the site accident book.</li>
</ul>

<div class="sec-subheading">7.2 Fire</div>
<ul class="blist">
    <li>On discovering a fire, raise the alarm using the nearest call point.</li>
    <li>Evacuate via the nearest emergency exit; do not use lifts.</li>
    <li>Assemble at the site-designated assembly point (confirm at site induction).</li>
    <li>Do not re-enter the building until instructed by the Fire Officer.</li>
</ul>

<div class="sec-subheading">7.3 Incident Reporting</div>
<ul class="blist">
    <li>All accidents, near-misses, and dangerous occurrences must be reported to the site supervisor immediately.</li>
    <li>{{ $company }}'s designated H&amp;S contact must be notified on {{ $tel }}.</li>
    <li>Complete a {{ $company }} Incident Report form within 24 hours.</li>
    <li>RIDDOR-reportable incidents must be reported to the HSE.</li>
</ul>

{{-- ═══ 8. SIGN-OFF ════════════════════════════════════════════════════════ --}}
<div class="sec-heading">8. RAMS Acknowledgement &amp; Sign-Off</div>
<p class="note-text">
    All operatives must read, understand, and sign this RAMS before commencing works on site.
    This document must be available on site at all times during installation.
</p>
<table class="std-table">
    <tr>
        <th style="width:33%;">Name (Print)</th>
        <th style="width:22%;">Role</th>
        <th style="width:28%;">Signature</th>
        <th style="width:17%;">Date</th>
    </tr>
    @for($i = 0; $i < 5; $i++)
    <tr>
        <td style="height:26px;"></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    @endfor
</table>
<p style="font-size:8pt; color:#555; margin-top:6px;">
    By signing above, each operative confirms they have read and understood the contents of this
    Risk Assessment &amp; Method Statement and will comply with all stated control measures.
</p>

</div>{{-- /.page-wrap --}}
</body>
</html>
