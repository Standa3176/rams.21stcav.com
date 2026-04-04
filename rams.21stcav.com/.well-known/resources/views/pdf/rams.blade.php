<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RAMS — {{ $rams->project_name }}</title>
<style>
/* ── Reset ─────────────────────────────────────────────────────────────── */
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9pt;
    color: #333333;
    line-height: 1.45;
}

/* ── Page ───────────────────────────────────────────────────────────────── */
@page { margin:18mm 15mm 20mm 15mm; size:A4 portrait; }
.page-break { page-break-before: always; }

/* ── Cover / title block ────────────────────────────────────────────────── */
.cover-company {
    font-size: 22pt;
    font-weight: 700;
    color: #007B8A;
    margin-bottom: 4px;
}
.cover-title {
    font-size: 16pt;
    font-weight: 700;
    color: #333333;
    border-bottom: 2px solid #007B8A;
    padding-bottom: 5px;
    margin-bottom: 4px;
}
.cover-subtitle {
    font-size: 10pt;
    color: #666666;
    margin-bottom: 16px;
}

/* ── Section headings ───────────────────────────────────────────────────── */
.sec-heading {
    font-size: 11pt;
    font-weight: 700;
    color: #007B8A;
    border-bottom: 2px solid #007B8A;
    padding-bottom: 3px;
    margin: 14px 0 7px;
}
.sec-subheading {
    font-size: 9.5pt;
    font-weight: 700;
    color: #007B8A;
    margin: 10px 0 5px;
}

/* ── Standard bordered table ────────────────────────────────────────────── */
table { border-collapse: collapse; width: 100%; }
.std-table { margin-bottom: 12px; font-size: 8.5pt; }
.std-table td, .std-table th {
    border: 1px solid #cccccc;
    padding: 4px 8px;
    vertical-align: top;
}
.std-table th {
    background: #007B8A;
    color: #ffffff;
    font-weight: 700;
    font-size: 8pt;
}
.std-table .lbl {
    background: #F0FBFC;
    font-weight: 600;
    color: #333;
    width: 30%;
}
.std-table tr:nth-child(even) td:not(.lbl) { background: #F0FBFC; }
.std-table .th-span { background:#007B8A; color:#fff; font-weight:700; text-align:center; }

/* ── Risk rating matrix ─────────────────────────────────────────────────── */
.risk-table { margin-bottom: 12px; font-size: 8pt; }
.risk-table td, .risk-table th {
    border: 1px solid #cccccc;
    padding: 4px 6px;
    vertical-align: middle;
}
.risk-table th { background:#007B8A; color:#fff; font-weight:700; text-align:center; }
.bg-green  { background: #D4EDDA; }
.bg-amber  { background: #FFF3CD; }
.bg-orange { background: #FFD0A0; }
.bg-red    { background: #FFDEDE; }

/* ── Hazard table ───────────────────────────────────────────────────────── */
.haz-table { margin-bottom: 14px; font-size: 7.5pt; page-break-inside:auto; }
.haz-table th {
    background: #007B8A;
    color: #fff;
    padding: 5px 5px;
    text-align: left;
    font-size: 7pt;
    font-weight: 700;
}
.haz-table td {
    border: 1px solid #e0e0e0;
    padding: 4px 5px;
    vertical-align: top;
}
.haz-table tr:nth-child(even) td { background: #f9feff; }
.haz-table tr { page-break-inside: avoid; }
.haz-table .th-group { background:#004f5a; color:#fff; font-weight:700; text-align:center; font-size:7pt; }
.score-cell {
    text-align: center;
    font-weight: 700;
    font-size: 7pt;
    padding: 3px 4px;
}

/* ── Risk score inline badge ────────────────────────────────────────────── */
.risk-badge {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 2px;
    font-size: 7pt;
    font-weight: 700;
    white-space: nowrap;
}
.r-green  { background:#D4EDDA; color:#155724; }
.r-amber  { background:#FFF3CD; color:#856404; }
.r-orange { background:#FFD0A0; color:#7d3c00; }
.r-red    { background:#FFDEDE; color:#7b1c1c; }

/* ── Chip row ───────────────────────────────────────────────────────────── */
.chip-row td {
    border: 1px solid #cccccc;
    padding: 5px 8px;
    text-align: center;
    font-size: 8pt;
    background: #F0FBFC;
}
.chip-row th { background:#007B8A; color:#fff; font-weight:700; text-align:center; font-size:8pt; }

/* ── Method statement steps ─────────────────────────────────────────────── */
.ms-step {
    color: #007B8A;
    font-weight: 700;
    font-size: 9pt;
    margin-bottom: 5px;
    padding: 4px 6px;
    page-break-inside: avoid;
}
.ms-step-num { margin-right: 6px; }

/* ── Bullet list ────────────────────────────────────────────────────────── */
.blist { padding-left: 14px; margin: 2px 0; }
.blist li { margin-bottom: 2px; line-height: 1.35; }

/* ── Sign-off table ─────────────────────────────────────────────────────── */
.signoff-row td {
    border: 1px solid #cccccc;
    height: 26px;
    padding: 3px 8px;
    font-size: 8.5pt;
}
.signoff-row .lbl { background:#F0FBFC; font-weight:600; width:22%; }

/* ── Footer ─────────────────────────────────────────────────────────────── */
.footer {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    border-top: 1px solid #ccc;
    font-size: 7pt;
    color: #888;
    padding: 3px 0;
}
.footer-left  { float: left; }
.footer-right { float: right; }
</style>
</head>
<body>

@php
    $project  = $data['project']          ?? [];
    $hazards  = $data['hazards']          ?? [];
    $ppe      = $data['ppe']              ?? [];
    $persons  = $data['persons_at_risk']  ?? [];
    $regs     = $data['regulations']      ?? [];
    $team     = $data['team']             ?? [];
    $ms       = $data['method_statement'] ?? [];
    $quote    = $data['quote']            ?? [];
    $formData = $rams->form_data          ?? [];
    $company  = config('rams.company_name', '21st Century AV Ltd');

    $riskBg = fn(int $s): string => match(true) {
        $s >= 15 => 'r-red',
        $s >= 10 => 'r-orange',
        $s >= 7  => 'r-amber',
        default  => 'r-green',
    };
    $riskCellBg = fn(int $s): string => match(true) {
        $s >= 15 => 'bg-red',
        $s >= 10 => 'bg-orange',
        $s >= 7  => 'bg-amber',
        default  => 'bg-green',
    };
@endphp

{{-- Fixed footer every page --}}
<div class="footer">
    <span class="footer-left">{{ $company }} &mdash; RAMS: {{ $project['name'] ?? $rams->project_name }} &mdash; Ref: {{ $project['ref'] ?? $rams->project_ref }}</span>
    <span class="footer-right">{{ now()->format('d M Y') }}</span>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     PAGE 1 — COVER + PROJECT DETAILS + SETUP TABLES
═══════════════════════════════════════════════════════════════════════ --}}

{{-- Cover title block --}}
<div class="cover-company">{{ $company }}</div>
<div class="cover-title">RISK ASSESSMENT &amp; METHOD STATEMENT</div>
<div class="cover-subtitle">
    {{ $project['name'] ?? $rams->project_name }}
    @if(! empty($project['client'] ?? $rams->client_name))
         &nbsp;|&nbsp; {{ $project['client'] ?? $rams->client_name }}
    @endif
</div>

{{-- Section 1: Project Details --}}
<div class="sec-heading">Project Details</div>
<table class="std-table">
    <tr><td class="lbl">Project Reference</td><td>{{ $project['ref']          ?? $rams->project_ref          ?? '—' }}</td></tr>
    <tr><td class="lbl">Project Name</td>     <td>{{ $project['name']         ?? $rams->project_name         ?? '—' }}</td></tr>
    <tr><td class="lbl">Client</td>           <td>{{ $project['client']       ?? $rams->client_name          ?? '—' }}</td></tr>
    <tr><td class="lbl">Site Address</td>     <td>{{ $project['site_address'] ?? $rams->site_address         ?? '—' }}</td></tr>
    <tr><td class="lbl">Contractor</td>       <td>{{ $company }}</td></tr>
    <tr><td class="lbl">Works Description</td><td>{{ $project['works_description'] ?? $formData['works_description'] ?? '—' }}</td></tr>
    <tr><td class="lbl">Start Date</td>       <td>{{ $formData['start_date']        ?? 'TBC' }}</td></tr>
    <tr><td class="lbl">Expected Duration</td><td>{{ $formData['expected_duration'] ?? 'TBC' }}</td></tr>
    <tr><td class="lbl">Document Status</td>  <td>For Review</td></tr>
    <tr><td class="lbl">Date</td>             <td>{{ $rams->created_at->format('F Y') }}</td></tr>
</table>

{{-- Section 2: Document Authorisation --}}
<div class="sec-heading">Document Authorisation</div>
<table class="std-table">
    <tr>
        <th style="width:22%;">Role</th>
        <th style="width:22%;">Name</th>
        <th style="width:18%;">Title</th>
        <th style="width:22%;">Signature</th>
        <th style="width:16%;">Date</th>
    </tr>
    @foreach(['Document Author', 'Authorised By', 'Authorised By (Client)'] as $role)
    <tr>
        <td>{{ $role }}</td>
        <td style="height:22px;">{{ $role === 'Document Author' ? ($formData['doc_author'] ?? '') : '' }}</td>
        <td></td><td></td><td></td>
    </tr>
    @endforeach
</table>

{{-- Section 3: Emergency Contacts --}}
<div class="sec-heading">Emergency Contacts</div>
<table class="std-table">
    <tr>
        <th style="width:28%;">Contact</th>
        <th style="width:24%;">Tel</th>
        <th style="width:24%;">Mobile</th>
        <th style="width:24%;">Role</th>
    </tr>
    <tr>
        <td style="height:22px;">{{ $formData['emergency_contact'] ?? '' }}</td>
        <td>{{ $formData['emergency_tel'] ?? '' }}</td>
        <td></td><td></td>
    </tr>
    <tr><td style="height:22px;"></td><td></td><td></td><td></td></tr>
</table>

{{-- Section 4: Engineering Team --}}
@if(! empty($team))
<div class="sec-heading">Engineering Team</div>
<table class="std-table">
    <tr>
        <th style="width:30%;">Role</th>
        <th style="width:40%;">Name</th>
        <th style="width:30%;">Mobile</th>
    </tr>
    @foreach($team as $member)
    <tr>
        <td>{{ $member['role']   ?? '' }}</td>
        <td>{{ $member['name']   ?? '' }}</td>
        <td>{{ $member['mobile'] ?? '' }}</td>
    </tr>
    @endforeach
</table>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     PAGE 2 — LEGISLATION + RISK RATING + PPE + PERSONS
═══════════════════════════════════════════════════════════════════════ --}}
<div class="page-break"></div>

{{-- Section 5: Applicable UK Legislation --}}
<div class="sec-heading">Applicable UK Legislation &amp; Regulations</div>
<table class="std-table">
@php
    $legislation = [
        ['Health &amp; Safety at Work Act 1974',        'Management of H&amp;S at Work Regs 1999'],
        ['Manual Handling Operations Regulations 1992', 'Work at Height Regulations 2005'],
        ['PUWER 1998',                                  'COSHH 2002'],
        ['Control of Noise at Work Regulations 2005',   'PPE at Work Regulations 2022'],
        ['CDM Regulations 2015',                        'Electricity at Work Regulations 1989'],
        ['Control of Asbestos Regulations 2012',        'RIDDOR 2013'],
    ];
@endphp
    @foreach($legislation as $i => $pair)
    <tr>
        <td style="width:50%; {{ $i % 2 === 0 ? '' : 'background:#F0FBFC;' }}">{!! $pair[0] !!}</td>
        <td style="width:50%; {{ $i % 2 === 0 ? '' : 'background:#F0FBFC;' }}">{!! $pair[1] !!}</td>
    </tr>
    @endforeach
</table>

{{-- Section 6: Risk Rating System --}}
<div class="sec-heading">Risk Rating System</div>
<table class="risk-table">
    <tr>
        <th style="width:20%;">Likelihood</th>
        <th style="width:8%;">Score</th>
        <th style="width:20%;">Severity</th>
        <th style="width:8%;">Score</th>
        <th style="width:44%;">Risk = Likelihood &times; Severity</th>
    </tr>
    <tr>
        <td>Highly Unlikely</td><td class="score-cell">1</td>
        <td>Trivial</td><td class="score-cell">1</td>
        <td class="bg-green">No Action Required &nbsp;(1)</td>
    </tr>
    <tr>
        <td>Unlikely</td><td class="score-cell">2</td>
        <td>Minor Injury</td><td class="score-cell">2</td>
        <td class="bg-green">Low Priority &nbsp;(2–6)</td>
    </tr>
    <tr>
        <td>Possible</td><td class="score-cell">3</td>
        <td>Over 3-Day Injury</td><td class="score-cell">3</td>
        <td class="bg-amber">Medium Priority &nbsp;(7–9)</td>
    </tr>
    <tr>
        <td>Probable</td><td class="score-cell">4</td>
        <td>Major Injury</td><td class="score-cell">4</td>
        <td class="bg-orange">High Priority &nbsp;(10–14)</td>
    </tr>
    <tr>
        <td>Certain</td><td class="score-cell">5</td>
        <td>Incapacity or Death</td><td class="score-cell">5</td>
        <td class="bg-red">Urgent Action Required &nbsp;(&ge;15)</td>
    </tr>
</table>

{{-- Section 7: PPE Required --}}
@if(! empty($ppe))
<div class="sec-heading">PPE Required for this Project</div>
<table class="std-table">
    <tr>
        @foreach($ppe as $item)
        <td style="text-align:center; background:#F0FBFC; width:{{ round(100/count($ppe), 1) }}%;">{{ $item }}</td>
        @endforeach
    </tr>
</table>
@endif

{{-- Section 8: Persons at Risk --}}
@if(! empty($persons))
<div class="sec-heading">Persons at Risk</div>
<table class="std-table">
    <tr>
        @foreach($persons as $p)
        <td style="text-align:center; background:#F0FBFC; width:{{ round(100/count($persons), 1) }}%;">&#10003; &nbsp;{{ $p }}</td>
        @endforeach
    </tr>
</table>
@endif

{{-- Quoted Works Summary (quote-upload RAMS only) --}}
@if(! empty($quote))
<div class="sec-heading">Quoted Works Summary{{ ! empty($quote['qw_number']) ? ' — ' . $quote['qw_number'] : '' }}</div>
@if(! empty($quote['line_items']))
<table class="std-table">
    <tr>
        <th style="width:20%;">SKU / Product Code</th>
        <th style="width:8%;">Qty</th>
        <th>Description</th>
    </tr>
    @foreach($quote['line_items'] as $i => $item)
    <tr>
        <td>{{ $item['sku']         ?? '' }}</td>
        <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
        <td>{{ $item['description'] ?? '' }}</td>
    </tr>
    @endforeach
</table>
@endif
@if(! empty($quote['room_summaries']))
<table class="std-table">
    <tr><th style="width:30%;">Room / Area</th><th>AV Solution Summary</th></tr>
    @foreach($quote['room_summaries'] as $entry)
    <tr>
        <td>{{ $entry['room']    ?? '' }}</td>
        <td>{{ $entry['summary'] ?? '' }}</td>
    </tr>
    @endforeach
</table>
@endif
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     PAGE 3+ — HAZARD REGISTER
═══════════════════════════════════════════════════════════════════════ --}}
@if(! empty($hazards))
<div class="page-break"></div>

<div class="sec-heading">Hazard Register &amp; Control Measures</div>
<p style="font-size:8pt; color:#666; margin-bottom:6px;">
    L = Likelihood &nbsp;|&nbsp; S = Severity &nbsp;|&nbsp; Risk = L &times; S &nbsp;|&nbsp; Scores shown before and after controls applied
</p>

<table class="haz-table">
    <thead>
        <tr>
            <th rowspan="2" style="width:14%;">Hazard</th>
            <th rowspan="2" style="width:18%;">Consequences</th>
            <th colspan="3" class="th-group" style="width:12%;">Pre-Control Risk</th>
            <th rowspan="2" style="width:34%;">Control Measures</th>
            <th colspan="3" class="th-group" style="width:12%;">Post-Control Risk</th>
        </tr>
        <tr>
            <th style="width:4%; text-align:center;">L</th>
            <th style="width:4%; text-align:center;">S</th>
            <th style="width:4%; text-align:center;">Risk</th>
            <th style="width:4%; text-align:center;">L</th>
            <th style="width:4%; text-align:center;">S</th>
            <th style="width:4%; text-align:center;">Risk</th>
        </tr>
    </thead>
    <tbody>
    @foreach($hazards as $h)
    @php
        $preScore  = ($h['pre_likelihood']  ?? 1) * ($h['pre_severity']  ?? 1);
        $postScore = ($h['post_likelihood'] ?? 1) * ($h['post_severity'] ?? 1);
    @endphp
    <tr>
        <td><strong>{{ $h['hazard'] ?? '' }}</strong></td>
        <td>
            <ul class="blist">
                @foreach($h['consequences'] ?? [] as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        </td>
        <td class="score-cell">{{ $h['pre_likelihood'] ?? '' }}</td>
        <td class="score-cell">{{ $h['pre_severity']   ?? '' }}</td>
        <td class="score-cell {{ $riskCellBg($preScore) }}">{{ $preScore }}</td>
        <td>
            <ul class="blist">
                @foreach($h['controls'] ?? [] as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        </td>
        <td class="score-cell">{{ $h['post_likelihood'] ?? '' }}</td>
        <td class="score-cell">{{ $h['post_severity']   ?? '' }}</td>
        <td class="score-cell {{ $riskCellBg($postScore) }}">{{ $postScore }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     METHOD STATEMENT
═══════════════════════════════════════════════════════════════════════ --}}
@if(! empty($ms))
<div class="page-break"></div>

<div class="sec-heading">Method Statement</div>
<p style="font-size:8.5pt; color:#666; margin-bottom:10px;">
    The following describes the planned sequence of works for this project, covering all phases from mobilisation through to completion and site reinstatement.
</p>

<div class="sec-subheading">General Site Procedures &amp; Sequence of Works</div>

@foreach($ms as $i => $step)
<div class="ms-step">
    <span class="ms-step-num">{{ $i + 1 }}.</span>{{ preg_replace('/^\d+\.\s*/', '', $step) }}
</div>
@endforeach
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     APPLICABLE REGULATIONS (AI-generated list)
═══════════════════════════════════════════════════════════════════════ --}}
@if(! empty($regs))
<div class="sec-heading" style="margin-top:12px;">Additional Applicable Regulations</div>
<ul class="blist" style="margin-bottom:12px;">
    @foreach($regs as $r)
        <li>{{ $r }}</li>
    @endforeach
</ul>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     OPERATIVE SIGN-OFF + DOCUMENT CONTROL
═══════════════════════════════════════════════════════════════════════ --}}
<div class="page-break"></div>

<div class="sec-heading">Operative Sign-Off Sheet</div>
<p style="font-size:8.5pt; color:#555; margin-bottom:8px; font-style:italic;">
    I have read and understood this Risk Assessment and Method Statement and agree to comply with its requirements.
</p>
<table class="std-table">
    <tr>
        <th style="width:34%;">Print Name</th>
        <th style="width:34%;">Signature</th>
        <th style="width:32%;">Date</th>
    </tr>
    @for($i = 0; $i < 6; $i++)
    <tr>
        <td style="height:24px;"></td>
        <td></td>
        <td></td>
    </tr>
    @endfor
</table>

<div class="sec-heading" style="margin-top:20px;">Document Control</div>
<table class="std-table">
    <tr>
        <th style="width:8%;">Rev</th>
        <th style="width:18%;">Date</th>
        <th style="width:26%;">Prepared By</th>
        <th style="width:22%;">Checked By</th>
        <th>Description</th>
    </tr>
    <tr>
        <td>01</td>
        <td>{{ $rams->created_at->format('d/m/Y') }}</td>
        <td>{{ $company }}</td>
        <td>—</td>
        <td>Initial Issue</td>
    </tr>
</table>

</body>
</html>
