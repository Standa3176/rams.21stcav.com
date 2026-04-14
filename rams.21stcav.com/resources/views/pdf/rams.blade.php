<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RAMS &mdash; {{ $rams->project_name }}</title>
<style>
/* === Base reset & page geometry === */
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 100%; }
body {
    font-family: Arial, "DejaVu Sans", sans-serif;
    font-size: 10pt;
    color: #333333;
    line-height: 1.4;
    margin: 0;
    padding: 26mm 0 18mm 0; /* space for fixed header/footer */
}
@page { size: A4 portrait; margin: 0; }

.page-wrap { margin: 0 18mm; }

/* === Running page header === */
.page-header {
    position: fixed;
    top: 0; left: 0; right: 0;
    padding: 5mm 18mm 2mm 18mm;
    border-bottom: 0.75pt solid #00788A;
    font-size: 8.5pt;
}
.page-header table { width: 100%; border-collapse: collapse; }
.page-header td { border: 0; padding: 0; vertical-align: bottom; }
.ph-left  { text-align: left;  font-weight: 700; color: #00788A; }
.ph-right { text-align: right; color: #666666; white-space: nowrap; }

/* === Running footer === */
.page-footer {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    padding: 2mm 18mm 5mm 18mm;
    border-top: 0.75pt solid #00788A;
    font-size: 8pt;
    color: #666666;
}
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td { border: 0; padding: 0; vertical-align: top; }
.pf-left  { text-align: left; }
.pf-right { text-align: right; white-space: nowrap; }
.page-number::before { content: "Page " counter(page); }

/* === Cover === */
.cover-company {
    font-size: 18pt;
    font-weight: 700;
    color: #00788A;
    margin: 24pt 0 4pt;
}
.cover-title {
    font-size: 14pt;
    font-weight: 700;
    color: #333333;
    border-bottom: 1pt solid #00788A;
    padding-bottom: 4pt;
    margin-bottom: 14pt;
}

/* === Section headings === */
.sec-heading {
    font-size: 11pt;
    font-weight: 700;
    color: #00788A;
    text-transform: uppercase;
    border-bottom: 0.75pt solid #00788A;
    padding-bottom: 2pt;
    margin: 16pt 0 8pt;
    page-break-after: avoid;
}
.sec-subheading {
    font-size: 10pt;
    font-weight: 700;
    color: #00788A;
    margin: 10pt 0 4pt;
    page-break-after: avoid;
}

/* === Standard tables === */
table { border-collapse: collapse; width: 100%; }
.std-table { margin-bottom: 10pt; }
.std-table td, .std-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: top;
    font-size: 9pt;
}
.std-table th {
    background-color: #00788A;
    color: #ffffff;
    font-weight: 700;
    font-size: 9pt;
    text-align: center;
}
.std-table .lbl {
    background-color: #00788A;
    color: #ffffff;
    font-weight: 700;
    width: 28%;
    font-size: 9pt;
}
.std-table tr:nth-child(even) td { background-color: #F0FBFC; }

/* === Cover info tables (teal label column) === */
.cover-table { margin-bottom: 8pt; }
.cover-table td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: middle;
    font-size: 9pt;
}
.cover-table .lbl {
    background-color: #00788A;
    color: #ffffff;
    font-weight: 700;
    width: 35%;
}
.cover-table .val { background-color: #ffffff; }
.cover-table tr:nth-child(even) .val { background-color: #F0FBFC; }

/* === Equipment schedule table === */
.equip-table { margin-bottom: 10pt; font-size: 8.5pt; }
.equip-table td, .equip-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 3pt 5pt;
    vertical-align: top;
}
.equip-table th {
    background-color: #00788A;
    color: #ffffff;
    font-weight: 700;
    text-align: center;
}
.equip-table .sub-header td {
    background-color: #333333;
    color: #ffffff;
    font-weight: 700;
    font-size: 8.5pt;
}
.equip-table tr:nth-child(even) td { background-color: #F0FBFC; }

/* === Risk matrix 5x5 table === */
.risk-matrix { margin-bottom: 10pt; font-size: 8pt; }
.risk-matrix td, .risk-matrix th {
    border: 0.5pt solid #CCCCCC;
    padding: 3pt 5pt;
    text-align: center;
    vertical-align: middle;
}
.risk-matrix th { background-color: #00788A; color: #fff; font-weight: 700; }

/* === Risk key table === */
.risk-key { margin-bottom: 10pt; }
.risk-key td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: middle;
    font-size: 9pt;
}
.risk-key .band { font-weight: 700; text-align: center; width: 20%; }
.bg-green  { background-color: #D4EDDA; }
.bg-amber  { background-color: #FFF3CD; }
.bg-orange { background-color: #FFD0A0; }
.bg-red    { background-color: #FFDEDE; }

/* === Hazard register table === */
.haz-table { margin-bottom: 12pt; font-size: 8.5pt; }
.haz-table th {
    background-color: #00788A;
    color: #fff;
    font-weight: 700;
    padding: 4pt 5pt;
    text-align: center;
    border: 0.5pt solid #005f6b;
}
.haz-table td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 5pt;
    vertical-align: top;
}
.haz-table tr:nth-child(even) td { background-color: #f9feff; }
.haz-table tr { page-break-inside: avoid; }
.haz-table thead { display: table-header-group; }
.score-cell {
    text-align: center;
    font-weight: 700;
    font-size: 8pt;
    padding: 3pt 4pt;
    white-space: nowrap;
}

/* === Lists === */
p { margin: 3pt 0; }
.blist {
    list-style: disc outside;
    padding-left: 12mm;
    margin: 3pt 0 6pt;
}
.blist li { margin-bottom: 3pt; line-height: 1.5; font-size: 9pt; }
.nlist {
    list-style: decimal outside;
    padding-left: 12mm;
    margin: 3pt 0 6pt;
}
.nlist li { margin-bottom: 3pt; line-height: 1.5; font-size: 9pt; }

/* === Method statement phases === */
.ms-phase {
    color: #00788A;
    font-weight: 700;
    font-size: 10pt;
    margin: 10pt 0 4pt;
    page-break-after: avoid;
}

/* === Sign-off table === */
.signoff-table { margin-bottom: 10pt; }
.signoff-table td, .signoff-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: top;
    font-size: 9pt;
    width: 50%;
}
.signoff-table th {
    background-color: #00788A;
    color: #ffffff;
    font-weight: 700;
    text-align: center;
}
.signoff-table .sig-row td { height: 30pt; }

/* === Utility === */
.note-text { font-style: italic; color: #666666; margin-bottom: 8pt; font-size: 9pt; }
.page-break { page-break-before: always; }
.body-para { font-size: 9pt; color: #333333; margin-bottom: 8pt; text-align: justify; line-height: 1.5; }
</style>
</head>
<body>

@php
    $project   = $data['project']             ?? [];
    $hazards   = $data['hazards']             ?? [];
    $team      = $data['team']                ?? [];
    $ms        = $data['method_statement']    ?? [];
    $quote     = $data['quote']               ?? [];
    $formData  = $rams->form_data             ?? [];
    $scopeItems      = $data['scope_items']            ?? [];
    $scopeOfWorks    = $data['scope_of_works']         ?? '';
    $tools           = $data['tools_and_equipment']    ?? [];
    $clientResp      = $data['client_responsibilities'] ?? [];

    // Type guards — AI may occasionally return strings instead of arrays
    $hazards     = is_array($hazards)    ? $hazards    : [];
    $team        = is_array($team)       ? $team       : [];
    $ms          = is_array($ms)         ? $ms         : [];
    $quote       = is_array($quote)      ? $quote      : [];
    $scopeItems  = is_array($scopeItems) ? $scopeItems : [];
    $tools       = is_array($tools)      ? $tools      : [];
    $clientResp  = is_array($clientResp) ? $clientResp : [];

    // Config
    $company  = config('rams.company_name',    '21st Century AV Ltd');
    $compShort= config('rams.company_short',   '21CAV');
    $address  = config('rams.company_address', 'Thames Court, 2 Richfield Avenue, Reading, Berkshire');
    $phone    = config('rams.company_phone',   '01189 977770');
    $website  = config('rams.company_website', 'www.21stcenturyav.com');
    $email    = config('rams.company_email',   'info@21stcenturyav.com');

    // Project fields
    $ref           = $project['ref']               ?? $rams->project_ref   ?? '';
    $client        = $project['client']            ?? $rams->client_name   ?? '';
    $siteAddress   = $project['site_address']      ?? $rams->site_address  ?? '';
    $roomsText     = $project['rooms_text']        ?? '';
    $docDate       = $project['date']              ?? now()->format('F Y');
    $docAuthor     = $project['doc_author'] ?: ($project['project_manager'] ?? '');
    $clientContact = trim(($project['client_contact_name'] ?? '') . (($project['client_contact_email'] ?? '') !== '' ? ' | ' . $project['client_contact_email'] : ''));
    $revision      = $project['revision']          ?? 'Rev 1.0';
    $docStatus     = $project['document_status']   ?? 'For Issue';
    $workingHours  = $project['working_hours']     ?? ($formData['working_hours'] ?? 'Monday–Friday, 09:00–17:30');
    $siteContact   = $project['site_contact']      ?? ($formData['site_contact'] ?? '');

    // Scope items
    $hasDecomm = ! empty($scopeItems['decommission'] ?? []);
    $hasRetain = ! empty($scopeItems['retained']     ?? []);
    $hasNew    = ! empty($scopeItems['new_install']  ?? []);
    $hasScope  = $hasDecomm || $hasRetain || $hasNew;

    // Risk helpers
    $riskBgColour = function(int $score): string {
        return match(true) {
            $score >= 15 => '#FFDEDE',
            $score >= 10 => '#FFD0A0',
            $score >= 5  => '#FFF3CD',
            default      => '#D4EDDA',
        };
    };
    $riskBadge = function(int $score): string {
        return match(true) {
            $score >= 10 => 'HIGH',
            $score >= 5  => 'MED',
            default      => 'LOW',
        };
    };

    // Default tools fallback
    $defaultTools = [
        'Insulated hand tools',
        'Power drill and drill bits',
        'Cable fish rods',
        'Cat6 test equipment',
        'Class 1 stepladder',
        'PPE (safety footwear, dust masks FFP2, safety glasses)',
        'Carry cases and equipment trolley',
        'Laptop for configuration and device testing',
        'Dust sheets and cable covers',
    ];
@endphp

{{-- ═══ Running page header ══════════════════════════════════════════════════ --}}
<div class="page-header">
    <table>
        <tr>
            <td class="ph-left">{{ $company }}  |  RAMS  |  {{ $ref }}{{ $client ? ' — ' . $client : '' }}</td>
            <td class="ph-right">Ref: {{ $ref }}</td>
        </tr>
    </table>
</div>

{{-- ═══ Running page footer ══════════════════════════════════════════════════ --}}
<div class="page-footer">
    <table>
        <tr>
            <td class="pf-left">{{ $company }}  |  {{ $address }}  |  {{ $phone }}</td>
            <td class="pf-right"><span class="page-number"></span></td>
        </tr>
    </table>
</div>

<div class="page-wrap">

{{-- ═══════════════════════════════════════════════════════════════════════════
     COVER PAGE
     ══════════════════════════════════════════════════════════════════════════ --}}

<div class="cover-company">{{ $company }}</div>
<div class="cover-title">RISK ASSESSMENT &amp; METHOD STATEMENT</div>

{{-- Cover Table 1: CLIENT | SITE | PROJECT REFERENCE | ROOMS | DATE --}}
<table class="cover-table">
    <tr>
        <td class="lbl">CLIENT</td>
        <td class="val">{{ $client ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">SITE ADDRESS</td>
        <td class="val">{{ $siteAddress ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">PROJECT REFERENCE</td>
        <td class="val">{{ $ref ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">ROOMS</td>
        <td class="val">{{ $roomsText ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">DATE</td>
        <td class="val">{{ $docDate }}</td>
    </tr>
</table>

{{-- Cover Table 2: PREPARED BY | TELEPHONE | CLIENT CONTACT | REVISION | STATUS --}}
<table class="cover-table" style="margin-top: 6pt;">
    <tr>
        <td class="lbl">PREPARED BY</td>
        <td class="val">{{ $docAuthor ?: $company }}</td>
    </tr>
    <tr>
        <td class="lbl">TELEPHONE</td>
        <td class="val">{{ $phone }}</td>
    </tr>
    <tr>
        <td class="lbl">CLIENT CONTACT</td>
        <td class="val">{{ $clientContact ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">REVISION</td>
        <td class="val">{{ $revision }}</td>
    </tr>
    <tr>
        <td class="lbl">STATUS</td>
        <td class="val">{{ $docStatus }}</td>
    </tr>
</table>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 1 — DOCUMENT CONTROL
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">1. Document Control</div>
<table class="std-table">
    <thead>
        <tr>
            <th style="width:12%;">Rev</th>
            <th style="width:18%;">Date</th>
            <th style="width:22%;">Author</th>
            <th>Description</th>
            <th style="width:14%;">Status</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $revision }}</td>
            <td>{{ $docDate }}</td>
            <td>{{ $docAuthor }}</td>
            <td>Initial Issue</td>
            <td>{{ $docStatus }}</td>
        </tr>
        {{-- Three blank rows for future revisions --}}
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
    </tbody>
</table>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 2 — COMPANY INFORMATION
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">2. Company Information</div>
<table class="std-table">
    <tbody>
        <tr>
            <td class="lbl">Company</td>
            <td>{{ $company }}</td>
            <td class="lbl">Project Reference</td>
            <td>{{ $ref ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td>
            <td>{{ $address }}</td>
            <td class="lbl">Telephone</td>
            <td>{{ $phone }}</td>
        </tr>
        <tr>
            <td class="lbl">Website</td>
            <td>{{ $website }}</td>
            <td class="lbl">Email</td>
            <td>{{ $email }}</td>
        </tr>
        <tr>
            <td class="lbl">Prepared by</td>
            <td>{{ $docAuthor ?: '—' }}</td>
            <td class="lbl">Direct</td>
            <td>{{ $phone }}</td>
        </tr>
    </tbody>
</table>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 3 — HEALTH & SAFETY POLICY STATEMENT
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">3. Health &amp; Safety Policy Statement</div>

<p class="body-para">
    21st Century AV Ltd is committed to ensuring the health, safety and welfare of all its employees,
    subcontractors, clients and members of the public who may be affected by our activities. We comply
    fully with the Health and Safety at Work etc. Act 1974 and all relevant statutory provisions,
    including the Management of Health and Safety at Work Regulations 1999, the Provision and Use of
    Work Equipment Regulations 1998 (PUWER), the Manual Handling Operations Regulations 1992, and the
    Electricity at Work Regulations 1989.
</p>

<p class="body-para">
    All engineers operating on behalf of 21st Century AV Ltd are briefed on site-specific risks prior to
    commencement of works and are required to adhere to this Risk Assessment and Method Statement at all
    times. Engineers will not commence work until they are satisfied that it is safe to do so. Any near
    misses, accidents, or unsafe conditions must be reported to the site manager and to the 21st Century
    AV operations team immediately.
</p>

<p class="body-para">
    This document must be read, understood, and complied with by all persons carrying out the works
    described herein. It should be retained on site for the duration of the works.
</p>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 4 — SCOPE OF WORKS
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">4. Scope of Works</div>

{{-- Header block --}}
<table class="std-table" style="margin-bottom: 10pt;">
    <tbody>
        <tr>
            <td class="lbl" style="width: 25%;">Client</td>
            <td>{{ $client ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Site</td>
            <td>{{ $siteAddress ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Rooms</td>
            <td>{{ $roomsText ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Working Hours</td>
            <td>{{ $workingHours }}</td>
        </tr>
    </tbody>
</table>

@if($scopeOfWorks)
<p class="body-para">{{ $scopeOfWorks }}</p>
@endif

{{-- Equipment schedule table --}}
<table class="equip-table">
    <thead>
        <tr>
            <th style="width: 24%; text-align: left;">Activity</th>
            <th style="text-align: left;">Item</th>
            <th style="width: 10%;">Qty / Room</th>
            <th style="width: 18%; text-align: left;">Notes</th>
        </tr>
    </thead>
    <tbody>
    @if($hasScope)
        {{-- DECOMMISSION & HANDBACK --}}
        @if($hasDecomm)
        <tr class="sub-header">
            <td colspan="4" style="background-color: #333333; color: #ffffff; font-weight: 700; padding: 3pt 5pt;">DECOMMISSION &amp; HANDBACK</td>
        </tr>
        @foreach($scopeItems['decommission'] as $item)
        <tr>
            <td>Decommission</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td style="text-align: center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ $item['notes'] ?? '' }}</td>
        </tr>
        @endforeach
        @endif

        {{-- EXISTING — RETAINED --}}
        @if($hasRetain)
        <tr>
            <td colspan="4" style="background-color: #333333; color: #ffffff; font-weight: 700; padding: 3pt 5pt;">EXISTING &mdash; RETAINED</td>
        </tr>
        @foreach($scopeItems['retained'] as $item)
        <tr>
            <td>Retained</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td style="text-align: center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ $item['notes'] ?? '' }}</td>
        </tr>
        @endforeach
        @endif

        {{-- NEW INSTALLATION --}}
        @if($hasNew)
        <tr>
            <td colspan="4" style="background-color: #333333; color: #ffffff; font-weight: 700; padding: 3pt 5pt;">NEW INSTALLATION</td>
        </tr>
        @foreach($scopeItems['new_install'] as $item)
        <tr>
            <td>New Installation</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td style="text-align: center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ $item['notes'] ?? '' }}</td>
        </tr>
        @endforeach
        @endif

    @elseif(! empty($quote['line_items']))
        {{-- Fallback: quote line items as NEW INSTALLATION --}}
        <tr>
            <td colspan="4" style="background-color: #333333; color: #ffffff; font-weight: 700; padding: 3pt 5pt;">NEW INSTALLATION</td>
        </tr>
        @foreach($quote['line_items'] as $item)
        <tr>
            <td>New Installation</td>
            <td>{{ $item['description'] ?? '' }}</td>
            <td style="text-align: center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ $item['room'] ?? '' }}</td>
        </tr>
        @endforeach

    @else
        <tr>
            <td colspan="4" style="font-style: italic; color: #666;">No equipment items listed.</td>
        </tr>
    @endif
    </tbody>
</table>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 5 — RISK ASSESSMENT
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">5. Risk Assessment</div>

<p class="body-para">
    The risk scoring matrix below is used throughout this assessment.
    Likelihood (L) &times; Severity (S) = Risk Score (R). L/S scale 1&ndash;5.
</p>

{{-- Risk key --}}
<table class="risk-key" style="margin-bottom: 10pt;">
    <tbody>
        <tr>
            <td class="band bg-green">1&ndash;4<br><strong>LOW</strong></td>
            <td style="font-size: 8.5pt;">Risk is acceptable. Proceed with standard precautions.</td>
        </tr>
        <tr>
            <td class="band bg-amber">5&ndash;9<br><strong>MEDIUM</strong></td>
            <td style="font-size: 8.5pt;">Risk requires attention. Implement additional controls before proceeding.</td>
        </tr>
        <tr>
            <td class="band bg-orange">10&ndash;14<br><strong>HIGH</strong></td>
            <td style="font-size: 8.5pt;">Significant risk. Management review required before proceeding.</td>
        </tr>
        <tr>
            <td class="band bg-red">15&ndash;25<br><strong>CRITICAL</strong></td>
            <td style="font-size: 8.5pt;">Unacceptable risk. Work must not proceed until risk is reduced.</td>
        </tr>
    </tbody>
</table>

{{-- Hazard register --}}
@if(! empty($hazards))
<table class="haz-table">
    <thead>
        <tr>
            <th style="width: 5%;">Ref</th>
            <th style="width: 18%;">Hazard</th>
            <th style="width: 15%;">Persons at Risk</th>
            <th style="width: 5%;">Initial Risk<br>L&times;S=R</th>
            <th style="width: 28%;">Control Measures</th>
            <th style="width: 5%;">Residual Risk<br>L&times;S=R</th>
        </tr>
    </thead>
    <tbody>
    @foreach($hazards as $h)
        @php
            $preL      = (int)($h['pre_likelihood']  ?? 1);
            $preS      = (int)($h['pre_severity']    ?? 1);
            $postL     = (int)($h['post_likelihood'] ?? 1);
            $postS     = (int)($h['post_severity']   ?? 1);
            $preScore  = $preL  * $preS;
            $postScore = $postL * $postS;
            $refLabel  = 'RA' . str_pad($loop->index + 1, 2, '0', STR_PAD_LEFT);
        @endphp
        <tr>
            <td class="score-cell">{{ $refLabel }}</td>
            <td><strong>{{ $h['hazard'] ?? '' }}</strong></td>
            <td>
                <ul class="blist" style="padding-left: 8mm;">
                @foreach((array)($h['persons_at_risk'] ?? []) as $p)
                    @if(is_string($p) && $p !== '')
                    <li>{{ $p }}</li>
                    @endif
                @endforeach
                </ul>
            </td>
            <td class="score-cell" style="background-color: {{ $riskBgColour($preScore) }};">
                {{ $preL }}&times;{{ $preS }}={{ $preScore }}<br>
                <span style="font-size: 7pt;">{{ $riskBadge($preScore) }}</span>
            </td>
            <td>
                <ul class="blist" style="padding-left: 8mm;">
                @foreach((array)($h['controls'] ?? []) as $c)
                    @if(is_string($c))
                    <li>{{ $c }}</li>
                    @endif
                @endforeach
                </ul>
            </td>
            <td class="score-cell" style="background-color: {{ $riskBgColour($postScore) }};">
                {{ $postL }}&times;{{ $postS }}={{ $postScore }}<br>
                <span style="font-size: 7pt;">{{ $riskBadge($postScore) }}</span>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
<p style="font-size: 7.5pt; color: #555; margin-bottom: 8pt;">
    L = Likelihood &nbsp;|&nbsp; S = Severity &nbsp;|&nbsp; Risk = L &times; S &nbsp;|&nbsp;
    Ratings based on controls being in place as described.
</p>
@else
<p class="note-text">No hazards identified.</p>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 6 — METHOD STATEMENT
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">6. Method Statement</div>

{{-- 6.1 Team Requirements & Competencies --}}
<div class="sec-subheading">6.1 Team Requirements &amp; Competencies</div>
<table class="std-table">
    <thead>
        <tr>
            <th style="width: 30%;">Role</th>
            <th style="width: 8%;">Qty</th>
            <th>Requirements</th>
        </tr>
    </thead>
    <tbody>
    @if(! empty($team))
        @php
            $reqMap = [
                'lead av engineer'  => 'CSCS Card, IPAF (if applicable), relevant AV experience, ECS Card',
                'lead engineer'     => 'CSCS Card, IPAF (if applicable), relevant AV experience, ECS Card',
                'av engineer'       => 'CSCS Card, AV installation experience',
                'engineer'          => 'CSCS Card, AV installation experience',
                'project manager'   => 'SMSTS or equivalent, CSCS Card',
            ];
            // Aggregate by role
            $roleGroups = [];
            foreach ($team as $member) {
                $role = (string)($member['role'] ?? 'Engineer');
                $roleGroups[$role] = ($roleGroups[$role] ?? 0) + 1;
            }
        @endphp
        @foreach($roleGroups as $role => $qty)
        <tr>
            <td>{{ $role }}</td>
            <td style="text-align: center;">{{ $qty }}</td>
            <td>{{ $reqMap[strtolower($role)] ?? 'CSCS Card, AV installation experience' }}</td>
        </tr>
        @endforeach
    @else
        <tr>
            <td>Lead AV Engineer</td>
            <td style="text-align: center;">1</td>
            <td>CSCS Card, IPAF (if applicable), relevant AV experience, ECS Card</td>
        </tr>
    @endif
    </tbody>
</table>

{{-- 6.2 Tools & Equipment --}}
<div class="sec-subheading">6.2 Tools &amp; Equipment</div>
<ul class="blist">
@php $toolsList = ! empty($tools) ? $tools : $defaultTools; @endphp
@foreach($toolsList as $tool)
    <li>{{ $tool }}</li>
@endforeach
</ul>

{{-- 6.3 Pre-Installation Requirements (Client Responsibilities) --}}
<div class="sec-subheading">6.3 Pre-Installation Requirements (Client Responsibilities)</div>
@if(! empty($clientResp))
<ul class="blist">
    @foreach($clientResp as $item)
    <li>{{ $item }}</li>
    @endforeach
</ul>
@else
<p class="note-text">Client responsibilities to be confirmed prior to works.</p>
@endif

{{-- 6.4 Method of Works --}}
<div class="sec-subheading">6.4 Method of Works</div>
@php $phases = is_array($ms) ? ($ms['phases'] ?? []) : []; @endphp
@if(! empty($phases))
    @foreach($phases as $i => $phase)
        @php
            $rawTitle   = trim((string)($phase['title'] ?? ''));
            $cleanTitle = preg_replace('/^\d+[\.\-\–\—\s]+/', '', $rawTitle);
            $stepTitle  = 'Step ' . ($i + 1) . ' — ' . $cleanTitle;
        @endphp
        <div class="ms-phase">{{ $stepTitle }}</div>
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

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 7 — EMERGENCY PROCEDURES
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">7. Emergency Procedures</div>

{{-- 7.1 Emergency Contact Numbers --}}
<div class="sec-subheading">7.1 Emergency Contact Numbers</div>
<table class="std-table" style="width: 60%;">
    <thead>
        <tr>
            <th>Contact</th>
            <th>Number</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Emergency Services (Fire, Police, Ambulance)</td>
            <td><strong>999</strong></td>
        </tr>
        <tr>
            <td>Non-Emergency Police</td>
            <td>101</td>
        </tr>
        <tr>
            <td>Site Contact</td>
            <td>{{ $siteContact ?: '—' }}</td>
        </tr>
        <tr>
            <td>{{ $compShort }} Operations</td>
            <td>{{ $phone }}</td>
        </tr>
    </tbody>
</table>

{{-- 7.2 In the Event of an Accident or Injury --}}
<div class="sec-subheading">7.2 In the Event of an Accident or Injury</div>
<ul class="blist">
    <li>Stop all work immediately.</li>
    <li>Call 999 if the injury is life-threatening or the person is unconscious.</li>
    <li>Administer first aid if qualified to do so; do not move a person with a suspected spinal injury.</li>
    <li>Contact the 21st Century AV operations team immediately: {{ $phone }}.</li>
    <li>Preserve the scene for investigation. Record all details: time, nature of incident, persons involved, witnesses.</li>
    <li>Complete a 21st Century AV incident report form and submit to operations within 24 hours.</li>
    <li>Report to the client site manager and comply with site reporting procedures.</li>
    <li>RIDDOR reportable incidents must be reported by the Responsible Person within the required timescales.</li>
</ul>

{{-- 7.3 In the Event of a Fire --}}
<div class="sec-subheading">7.3 In the Event of a Fire</div>
<ul class="blist">
    <li>Raise the alarm immediately using the nearest fire alarm call point.</li>
    <li>Evacuate the building by the nearest fire exit. Do not use lifts.</li>
    <li>Proceed to the designated assembly point as directed by the site fire warden.</li>
    <li>Do not re-enter the building until instructed to do so by the fire warden or emergency services.</li>
    <li>Inform the site manager that {{ $compShort }} engineers are on-site and present at the assembly point.</li>
</ul>

{{-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 8 — DOCUMENT SIGN-OFF
     ══════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">8. Document Sign-Off</div>

<table class="signoff-table">
    <thead>
        <tr>
            <th>{{ $company }}</th>
            <th>Client Acceptance</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Name:</strong> {{ $docAuthor ?: '—' }}</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td><strong>Position:</strong> Project Manager</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td><strong>Date:</strong> {{ $docDate }}</td>
            <td>&nbsp;</td>
        </tr>
        <tr class="sig-row">
            <td><strong>Signature:</strong></td>
            <td>&nbsp;</td>
        </tr>
    </tbody>
</table>

</div>{{-- /.page-wrap --}}
</body>
</html>
