<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RAMS &mdash; {{ $rams->project_name }}</title>
<style>
/* ── Base ─────────────────────────────────────────────────────────────────── */
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 100%; }
body {
    font-family: Arial, "DejaVu Sans", sans-serif;
    font-size: 10pt;
    color: #1A1A2E;
    line-height: 1.4;
    margin: 0;
    padding: 0;
}
/* Body margin space for the running header/footer is set by Browsershot's
   PDF margins (see PdfService::buildRams), not via CSS @page — Chromium's
   running header/footer is supplied as a separate HTML doc to Puppeteer. */
@page { size: A4 portrait; }
.page-wrap { margin: 0 18mm; }

/* ── Cover ───────────────────────────────────────────────────────────────── */
.cover-company-name {
    font-size: 22pt;
    font-weight: 700;
    color: #1B7A7A;
    text-align: center;
    margin-bottom: 4pt;
    letter-spacing: 1pt;
}
.cover-tagline {
    font-size: 11pt;
    font-style: italic;
    color: #C07000;
    text-align: center;
    margin-bottom: 20pt;
}
.cover-doc-title {
    font-size: 16pt;
    font-weight: 700;
    color: #1A1A2E;
    text-align: center;
    margin-bottom: 24pt;
    line-height: 1.3;
}
.cover-accent-bar {
    height: 6pt;
    background: linear-gradient(to right, #1B7A7A, #C07000);
    margin-bottom: 20pt;
    border-radius: 1pt;
}

/* ── Cover info tables ───────────────────────────────────────────────────── */
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
    color: #1B7A7A;
    background-color: #F4FBFB;
}
.cover-table .val {
    color: #1A1A2E;
    background-color: #FFFFFF;
}

/* ── Section headings (teal background bar) ──────────────────────────────── */
.sec-heading {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-size: 10pt;
    font-weight: 700;
    text-transform: uppercase;
    padding: 5pt 8pt;
    margin: 14pt 0 8pt;
    page-break-after: avoid;
    letter-spacing: 0.5pt;
}
.sec-subheading {
    font-size: 9.5pt;
    font-weight: 700;
    color: #1B7A7A;
    margin: 10pt 0 5pt;
    page-break-after: avoid;
    border-bottom: 0.5pt solid #1B7A7A;
    padding-bottom: 2pt;
}

/* ── Standard tables ─────────────────────────────────────────────────────── */
table { border-collapse: collapse; width: 100%; }
.std-table { margin-bottom: 10pt; font-size: 9pt; }
.std-table td, .std-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: top;
}
.std-table th {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-weight: 700;
    font-size: 9pt;
    text-align: center;
}
.std-table .lbl {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-weight: 700;
    width: 26%;
}
.std-table tr:nth-child(even) td { background-color: #F4FBFB; }

/* ── Equipment scope table ───────────────────────────────────────────────── */
.equip-table { margin-bottom: 10pt; font-size: 8.5pt; }
.equip-table td, .equip-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 3pt 5pt;
    vertical-align: top;
}
.equip-table th {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-weight: 700;
    text-align: center;
}
.equip-table .group-header td {
    background-color: #2C2C3E;
    color: #FFFFFF;
    font-weight: 700;
    font-size: 8.5pt;
    padding: 3pt 6pt;
}
.equip-table tr:nth-child(even) td { background-color: #F4FBFB; }

/* ── Risk matrix ─────────────────────────────────────────────────────────── */
.risk-matrix { margin-bottom: 8pt; font-size: 7.5pt; border-collapse: collapse; }
.risk-matrix td, .risk-matrix th {
    border: 0.5pt solid #CCCCCC;
    padding: 3pt 4pt;
    text-align: center;
    vertical-align: middle;
    width: 14%;
}
.risk-matrix .row-hdr {
    background-color: #1B7A7A;
    color: #fff;
    font-weight: 700;
    text-align: left;
    width: 24%;
}
.risk-matrix .col-hdr {
    background-color: #1B7A7A;
    color: #fff;
    font-weight: 700;
}
.rm-low    { background-color: #D4EDDA; }
.rm-med    { background-color: #FFF3CD; }
.rm-high   { background-color: #F8D7DA; }

/* ── Risk key legend ─────────────────────────────────────────────────────── */
.risk-key-row { border-collapse: collapse; margin-bottom: 10pt; width: 100%; }
.risk-key-row td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 8pt;
    font-size: 8.5pt;
    vertical-align: middle;
}
.rk-band {
    font-weight: 700;
    text-align: center;
    width: 14%;
    white-space: nowrap;
}

/* ── Hazard register ─────────────────────────────────────────────────────── */
.haz-table { margin-bottom: 12pt; font-size: 8.5pt; }
.haz-table th {
    background-color: #1B7A7A;
    color: #fff;
    font-weight: 700;
    padding: 4pt 5pt;
    text-align: center;
    border: 0.5pt solid #145E5E;
}
.haz-table td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 5pt;
    vertical-align: top;
}
.haz-table tr:nth-child(even) td { background-color: #F4FBFB; }
.haz-table tr { page-break-inside: avoid; }
.haz-table thead { display: table-header-group; }
.score-cell {
    text-align: center;
    font-weight: 700;
    font-size: 7.5pt;
    padding: 3pt 4pt;
    white-space: nowrap;
}

/* ── Lists ───────────────────────────────────────────────────────────────── */
p { margin: 3pt 0; }
.blist {
    list-style: disc outside;
    padding-left: 10mm;
    margin: 3pt 0 6pt;
}
.blist li { margin-bottom: 3pt; line-height: 1.5; font-size: 9pt; }

/* ── Method statement step headers ──────────────────────────────────────── */
.ms-step-header {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-weight: 700;
    font-size: 9pt;
    padding: 4pt 8pt;
    margin: 10pt 0 4pt;
    page-break-after: avoid;
}

/* ── Emergency contacts table ────────────────────────────────────────────── */
.emerg-table { border-collapse: collapse; width: 100%; margin-bottom: 8pt; font-size: 9pt; }
.emerg-table td {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 6pt;
    vertical-align: middle;
}
.emerg-table .e-lbl {
    font-weight: 700;
    background-color: #F4FBFB;
    width: 22%;
}
.emerg-table .e-val { width: 28%; }

/* ── Sign-off table ──────────────────────────────────────────────────────── */
.signoff-table { margin-bottom: 10pt; font-size: 9pt; }
.signoff-table td, .signoff-table th {
    border: 0.5pt solid #CCCCCC;
    padding: 4pt 8pt;
    vertical-align: top;
}
.signoff-table th {
    background-color: #1B7A7A;
    color: #FFFFFF;
    font-weight: 700;
    text-align: center;
    width: 33%;
}
.signoff-table .row-lbl {
    background-color: #F4FBFB;
    font-weight: 700;
    width: 15%;
}
.signoff-table .sig-row td { height: 32pt; }

/* ── Utility ─────────────────────────────────────────────────────────────── */
.body-para { font-size: 9pt; color: #1A1A2E; margin-bottom: 8pt; text-align: justify; line-height: 1.5; }
.note-text { font-style: italic; color: #666; margin-bottom: 8pt; font-size: 9pt; }
.page-break { page-break-before: always; }
.kv-block { margin-bottom: 8pt; font-size: 9pt; }
.kv-block p { margin-bottom: 3pt; }
.kv-block strong { color: #1A1A2E; }
.cover-footer-bar {
    margin-top: 32pt;
    border-top: 0.75pt solid #CCCCCC;
    padding-top: 5pt;
    font-size: 8pt;
    color: #555;
    text-align: center;
}
</style>
</head>
<body>

@php
    $project         = $data['project']                ?? [];
    $hazards         = $data['hazards']                ?? [];
    $team            = $data['team']                   ?? [];
    $ms              = $data['method_statement']        ?? [];
    $quote           = $data['quote']                  ?? [];
    $formData        = $rams->form_data                ?? [];
    $scopeItems      = $data['scope_items']            ?? [];
    $scopeOfWorks    = $data['scope_of_works']         ?? '';
    $tools           = $data['tools_and_equipment']    ?? [];
    $clientResp      = $data['client_responsibilities'] ?? [];

    // Type guards
    $hazards     = is_array($hazards)    ? $hazards    : [];
    $team        = is_array($team)       ? $team       : [];
    $ms          = is_array($ms)         ? $ms         : [];
    $quote       = is_array($quote)      ? $quote      : [];
    $scopeItems  = is_array($scopeItems) ? $scopeItems : [];
    $tools       = is_array($tools)      ? $tools      : [];
    $clientResp  = is_array($clientResp) ? $clientResp : [];

    // 260712-twi Task 2 — defensive render-time fallback for tier-1 AV
    // baseline hazards. Task 1 already folds baseline hazards into
    // generated_data['hazards'] at build time; this belt-and-braces path
    // catches any legacy generated_data records persisted BEFORE Task 1
    // shipped. Engineer values already loaded above still win — this only
    // fires when hazards is empty AND the kill-switch is on.
    if (empty($hazards) && config('rams_tier1.enabled', true)) {
        $hazards = (array) config('rams_tier1.baseline_hazards', []);
    }

    // Company config
    $company  = config('rams.company_name',    '21st Century AV Ltd');
    $compShort= config('rams.company_short',   '21CAV');
    $address  = config('rams.company_address', 'Thames Court, 2 Richfield Avenue, Reading, Berkshire');
    $phone    = config('rams.company_phone',   '01189 977770');
    $website  = config('rams.company_website', 'www.21stcenturyav.com');
    $email    = config('rams.company_email',   'info@21stcenturyav.com');

    // Project fields
    $ref           = ($project['ref']          ?? '') ?: ($rams->project_ref  ?? '');
    $client        = ($project['client']       ?? '') ?: ($rams->client_name  ?? '');
    $siteAddress   = ($project['site_address'] ?? '') ?: ($rams->site_address ?? '');
    $roomsText     = $project['rooms_text']        ?? '';
    // Room overviews needed early so they can seed $roomsList below
    $roomOverviews = $rams->reviewed_data['room_overviews'] ?? [];
    // Rooms — priority: reviewed_data['rooms'] → room_overviews names → rooms_text blob
    $roomsList = [];
    $reviewedRooms = $rams->reviewed_data['rooms'] ?? [];
    if (! empty($reviewedRooms) && is_array($reviewedRooms)) {
        foreach ($reviewedRooms as $r) {
            $name = $r['name'] ?? ($r['room_name'] ?? '');
            if ($name) $roomsList[] = $name;
        }
    }
    if (empty($roomsList) && ! empty($roomOverviews) && is_array($roomOverviews)) {
        foreach ($roomOverviews as $ro) {
            // 'room' is the canonical key in reviewed_data room_overviews
            $rn = $ro['room'] ?? ($ro['room_name'] ?? ($ro['name'] ?? ''));
            // Exclude non-physical-space entries (licencing, cabling, services, etc.)
            if ($rn && ! preg_match('/\b(licen[cs]|cabling|cables?|wiring|network|software|service|warranty|support|delivery|carriage)\b/i', $rn)) {
                $roomsList[] = $rn;
            }
        }
    }
    if (empty($roomsList) && $roomsText) {
        $roomsList = array_filter(array_map('trim', preg_split('/[,\n]+/', $roomsText)));
    }
    // Use actual document creation date (dd/mm/yyyy) — not the "April 2026" placeholder.
    $docDate = $rams->created_at
        ? $rams->created_at->format('d/m/Y')
        : now()->format('d/m/Y');
    $docAuthor     = ($project['doc_author'] ?? '') ?: ($project['project_manager'] ?? '');
    $pmPhone       = ($project['project_manager_phone'] ?? '') ?: $phone;
    $clientContactName  = $project['client_contact_name']  ?? '';
    $clientContactEmail = $project['client_contact_email'] ?? '';
    $clientContactPhone = $project['client_contact_phone'] ?? '';
    $clientContact      = trim($clientContactName . ($clientContactEmail ? ' | ' . $clientContactEmail : ''));
    $revision      = $project['revision']        ?? 'Rev 1.0';
    $docStatus     = $project['document_status'] ?? 'For Issue';
    $workingHours  = ($project['working_hours'] ?? '') ?: ($formData['working_hours'] ?? 'Monday–Friday, 09:00–17:30');
    $siteContact   = ($project['site_contact']  ?? '') ?: ($formData['site_contact']  ?? '');
    // Personnel
    $leadEngineer      = $project['lead_engineer']        ?? '';
    $additionalEngs    = $project['additional_engineers'] ?? '';
    $programmer        = $project['programmer']           ?? '';
    // Project dates from programme — formatted as dd/mm/yyyy for UK documents
    $plannedStart = $project['planned_start_date'] ?? '';
    $plannedEnd   = $project['planned_end_date']   ?? '';
    $formatDate = function(string $d): string {
        if (! $d) return '';
        try { return \Carbon\Carbon::parse($d)->format('d/m/Y'); } catch (\Throwable $e) { return $d; }
    };
    $plannedStart = $formatDate($plannedStart);
    $plannedEnd   = $formatDate($plannedEnd);
    // Time fields
    $plannedStartTime = $project['planned_start_time'] ?? ($rams->reviewed_data['programme']['planned_start_time'] ?? '');
    $plannedEndTime   = $project['planned_end_time']   ?? ($rams->reviewed_data['programme']['planned_end_time']   ?? '');
    // Waste removal
    $wasteParty  = $rams->reviewed_data['programme']['waste_removal_party'] ?? '';
    $wasteNotes  = $rams->reviewed_data['programme']['waste_removal_notes'] ?? '';
    $wasteLabels = ['client' => 'Client', '21cav' => '21st Century AV Ltd', 'other' => 'Other'];
    $wasteLabel  = $wasteLabels[$wasteParty] ?? '';
    // Permits — explicit form entries first, then auto-derive from scope so the
    // standalone section never contradicts the Method Statement (which calls
    // out permits for ceiling penetrations, electrical isolations, etc.)
    $permitsRd      = $rams->reviewed_data['permits_required'] ?? [];
    $requiredPermits = array_values(array_filter(is_array($permitsRd) ? $permitsRd : [], fn ($p) => ! empty($p['required'])));
    if (empty($requiredPermits)) {
        $scopeBlob = strtolower(implode(' ', [
            (string) ($rams->reviewed_data['scope_of_works']    ?? ''),
            (string) ($rams->reviewed_data['works_overview']    ?? ''),
            (string) ($rams->reviewed_data['method_statement_notes'] ?? ''),
        ]));
        $derived = [];
        if (preg_match('/(ceiling void|ceiling tile|above ceiling|plenum|cable tray|containment|riser|in-ceiling)/', $scopeBlob)) {
            $derived[] = ['type' => 'Permit to Work — Ceiling Voids',
                          'notes' => 'Required before accessing ceiling voids, risers, or restricted areas. Confirm asbestos register sign-off.'];
        }
        if (preg_match('/(mains|electrical|circuit|isolat|consumer unit|distribution board|rack power)/', $scopeBlob)) {
            $derived[] = ['type' => 'Electrical Isolation Permit',
                          'notes' => 'Required before any work on or near mains-voltage circuits. Lock-off and tag out for the duration of works.'];
        }
        if (preg_match('/(solder|heat shrink|hot work)/', $scopeBlob)) {
            $derived[] = ['type' => 'Hot Works Permit',
                          'notes' => 'Required if soldering or heat-shrink operations are performed on site.'];
        }
        if (preg_match('/(fire.?rated|fire stop|firebreak|compartment)/', $scopeBlob)) {
            $derived[] = ['type' => 'Fixings into Fire-Rated Structures',
                          'notes' => 'Client / building management approval required before any penetration of a fire-rated wall or partition.'];
        }
        $requiredPermits = $derived;
    }
    // Material handling
    $matHandling = $rams->reviewed_data['material_handling'] ?? [];
    $mhItems     = is_array($matHandling['large_items'] ?? null) ? $matHandling['large_items'] : [];
    $mhNotes     = $matHandling['handling_notes'] ?? '';
    // CDM
    $cdmRows = $rams->reviewed_data['cdm'] ?? [];
    $cdmRows = is_array($cdmRows) ? $cdmRows : [];
    // Welfare
    $welfareNotes = $rams->reviewed_data['programme']['welfare_notes'] ?? '';
    // Note: $roomOverviews already assigned earlier in the $roomsList block above

    // New sections from reviewed_data
    $scopeTraceability  = $rams->reviewed_data['scope_traceability']              ?? [];
    $clientRespExp      = $rams->reviewed_data['client_responsibilities_expanded'] ?? [];
    $exclusionsList     = $rams->reviewed_data['exclusions']                       ?? [];
    $decommData         = $rams->reviewed_data['decommissioning']                  ?? [];
    $commCriteria       = $rams->reviewed_data['commissioning_criteria']           ?? [];

    $scopeTraceability  = is_array($scopeTraceability)  ? $scopeTraceability  : [];
    $exclusionsList     = is_array($exclusionsList)      ? $exclusionsList     : [];
    $commCriteria       = is_array($commCriteria)        ? $commCriteria       : [];

    // Scope items
    $hasDecomm = ! empty($scopeItems['decommission'] ?? []);
    $hasRetain = ! empty($scopeItems['retained']     ?? []);
    $hasNew    = ! empty($scopeItems['new_install']  ?? []);
    $hasScope  = $hasDecomm || $hasRetain || $hasNew;

    // Decommissioning enabled when flag set OR scope has decommission items
    $decommEnabled = ! empty($decommData['enabled']) || $hasDecomm;

    // Risk helpers — LOW ≤4, MED 5–9, HIGH ≥10 (matching reference)
    $riskBg = function(int $score): string {
        if ($score >= 10) return '#F8D7DA';  // HIGH red
        if ($score >= 5)  return '#FFF3CD';  // MED amber
        return '#D4EDDA';                     // LOW green
    };
    $riskLabel = function(int $score): string {
        if ($score >= 10) return 'HIGH';
        if ($score >= 5)  return 'MED';
        return 'LOW';
    };
    // Matrix cell colour helper
    $matCell = function(int $l, int $s) use ($riskBg): string {
        return $riskBg($l * $s);
    };

    // Default tools fallback
    $defaultTools = [
        'Insulated hand tools (screwdrivers, pliers, wire strippers)',
        'Power drill with masonry and wood bits',
        'Cable fish rods and draw tape',
        'Cat6 patch leads and network tester',
        'Class 1 stepladder (EN131 rated)',
        'Multimeter and cable tester',
        'HDMI test equipment / signal generator',
        'Label maker and cable labels',
        'PPE: safety footwear, dust masks (FFP2), safety glasses',
        'Carry cases and equipment trolley',
        'Laptop for DSP/AV configuration and device testing',
        'Dust sheets and cable ramps/covers',
    ];
@endphp

{{-- Running header + footer used to be inline `position: fixed` divs that
     dompdf rendered on every page. Chromium only paints fixed elements once
     (page 1) and breaks page numbering, so we now pass the running header
     and footer HTML to Browsershot via PdfRenderService options — Chromium
     repeats them on every page natively, with `<span class="pageNumber">` /
     `<span class="totalPages">` placeholders filled in by Puppeteer. --}}

<div class="page-wrap">

{{-- ════════════════════════════════════════════════════════════════════════
     COVER PAGE
     ════════════════════════════════════════════════════════════════════════ --}}

<div class="cover-company-name">{{ strtoupper($company) }}</div>
<div class="cover-tagline">Your Audio Visual Partner</div>
<div class="cover-doc-title">RISK ASSESSMENT &amp;<br>METHOD STATEMENT</div>
<div class="cover-accent-bar"></div>

{{-- Cover Table 1: CLIENT | SITE | PROJECT REFERENCE | ROOMS | DATE | WORKING HOURS --}}
<table class="cover-table">
    <colgroup>
        <col style="width:26%;">
        <col style="width:74%;">
    </colgroup>
    <tr>
        <td class="lbl">CLIENT:</td>
        <td class="val">{{ $client ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">SITE:</td>
        <td class="val">{{ $siteAddress ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">PROJECT REFERENCE:</td>
        <td class="val">{{ $ref ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">ROOMS:</td>
        <td class="val">
            @if(! empty($roomsList))
                @foreach($roomsList as $r){{ $r }}<br>@endforeach
            @else
                &mdash;
            @endif
        </td>
    </tr>
    <tr>
        <td class="lbl">DATE:</td>
        <td class="val">{{ $docDate }}</td>
    </tr>
    {{-- Start / End Date + Time — always rendered so the form-set values
         reach the cover. "—" placeholder shown when data is missing. --}}
    <tr>
        <td class="lbl">START DATE:</td>
        <td class="val">
            {{ $plannedStart ?: '—' }}
            @if($plannedStartTime) &nbsp; {{ $plannedStartTime }}@endif
        </td>
    </tr>
    <tr>
        <td class="lbl">END DATE:</td>
        <td class="val">
            {{ $plannedEnd ?: '—' }}
            @if($plannedEndTime) &nbsp; {{ $plannedEndTime }}@endif
        </td>
    </tr>
    @if($workingHours)
    <tr>
        <td class="lbl">WORKING HOURS:</td>
        <td class="val">{{ $workingHours }}</td>
    </tr>
    @endif
</table>

{{-- Cover Table 2: PREPARED BY / TELEPHONE / CLIENT CONTACT with REVISION / STATUS --}}
<table class="cover-table">
    <colgroup>
        <col style="width:26%;">
        <col style="width:30%;">
        <col style="width:18%;">
        <col style="width:26%;">
    </colgroup>
    <tr>
        <td class="lbl">PREPARED BY:</td>
        <td class="val">{{ $docAuthor ?: $company }}</td>
        <td class="lbl">REVISION:</td>
        <td class="val" style="white-space:nowrap;">{{ $revision }}</td>
    </tr>
    <tr>
        <td class="lbl">TELEPHONE:</td>
        <td class="val">{{ $pmPhone }}</td>
        <td class="lbl">STATUS:</td>
        <td class="val" style="white-space:nowrap;">{{ $docStatus }}</td>
    </tr>
    <tr>
        <td class="lbl">CLIENT CONTACT:</td>
        <td class="val" colspan="3">{{ $clientContact ?: 'TBC at site induction' }}</td>
    </tr>
</table>

{{-- Cover Table 3: PERSONNEL & VEHICLES — always rendered so the form-set
     values reach the cover. "—" placeholder shown when data is missing. --}}
@php
    $coverVehSrc = $project['site_vehicles'] ?? ($data['site_vehicles'] ?? null);
    if (is_string($coverVehSrc)) { $coverVehSrc = preg_split('/\r?\n/', $coverVehSrc) ?: []; }
    $coverVehiclesList = array_values(array_filter(
        array_map('trim', (array) ($coverVehSrc ?? [])),
        fn (string $v) => $v !== ''
    ));
    $coverVehiclesDisplay = ! empty($coverVehiclesList) ? implode(', ', $coverVehiclesList) : '—';
@endphp
<table class="cover-table">
    <colgroup>
        <col style="width:26%;">
        <col style="width:74%;">
    </colgroup>
    <tr>
        <td class="lbl">PROJECT MANAGER:</td>
        <td class="val">{{ $docAuthor ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">LEAD ENGINEER:</td>
        <td class="val">{{ $leadEngineer ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">ENGINEERS:</td>
        <td class="val">{{ $additionalEngs ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">PROGRAMMER:</td>
        <td class="val">{{ $programmer ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">VEHICLE REGS:</td>
        <td class="val">{{ $coverVehiclesDisplay }}</td>
    </tr>
</table>


{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 1 — DOCUMENT CONTROL
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">1. &nbsp;Document Control</div>
<table class="std-table">
    <thead>
        <tr>
            <th style="width:10%;">Rev</th>
            <th style="width:16%;">Date</th>
            <th style="width:22%;">Author</th>
            <th>Description</th>
            <th style="width:14%;">Status</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $revision }}</td>
            <td>{{ $docDate }}</td>
            <td>{{ $docAuthor ?: '—' }}</td>
            <td>Initial Issue</td>
            <td>{{ $docStatus }}</td>
        </tr>
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
    </tbody>
</table>

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 2 — COMPANY INFORMATION
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">2. &nbsp;Company Information</div>
<table class="std-table">
    <tbody>
        <tr>
            <td class="lbl">Company:</td>
            <td>{{ $company }}</td>
            <td class="lbl">Project Reference:</td>
            <td>{{ $ref ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Address:</td>
            <td>{{ $address }}</td>
            <td class="lbl">Telephone:</td>
            <td>{{ $phone }}</td>
        </tr>
        <tr>
            <td class="lbl">Website:</td>
            <td>{{ $website }}</td>
            <td class="lbl">Email:</td>
            <td>{{ $email }}</td>
        </tr>
        <tr>
            <td class="lbl">Prepared by:</td>
            <td>{{ $docAuthor ?: '—' }}</td>
            <td class="lbl">Direct:</td>
            <td>{{ $pmPhone }}</td>
        </tr>
    </tbody>
</table>

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 3 — HEALTH & SAFETY POLICY STATEMENT
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">3. &nbsp;Health &amp; Safety Policy Statement</div>

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

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 4 — SCOPE OF WORKS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">4. &nbsp;Scope of Works</div>

<div class="kv-block">
    <p><strong>Client:</strong> {{ $client ?: '—' }}</p>
    <p><strong>Site:</strong> {{ $siteAddress ?: '—' }}</p>
    <p><strong>Rooms:</strong> {{ ! empty($roomsList) ? implode(', ', $roomsList) : '—' }}</p>
    <p><strong>Working Hours:</strong> {{ $workingHours }}</p>
    @if($wasteLabel || $wasteNotes)
    <p><strong>Waste Removal:</strong> {{ $wasteLabel ?: '' }}{{ $wasteNotes ? ($wasteLabel ? ' — ' : '') . $wasteNotes : '' }}</p>
    @endif
</div>

{{-- ─── Site Logistics & Access (from site survey, quick task 260503-tfb) ─── --}}
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
        'yes'        => 'Permission required',
        'no'         => 'Free access',
        'outsourced' => 'Outsourced facilities team',
        'unknown'    => 'Status unknown',
    ];
@endphp
@if($hasSiteLog)
<div class="sec-subheading" style="margin-top:8pt;">Site Logistics &amp; Access (from site survey)</div>
<table class="std-table">
    <tbody>
        @if(! empty($siteLog['parking_restraints']))
            <tr><td class="lbl" style="width:30%;">Parking arrangements:</td>
                <td>{{ $siteLog['parking_restraints'] }}</td></tr>
        @endif
        @if(! empty($siteLog['site_access_notes']))
            <tr><td class="lbl">Site access notes:</td>
                <td>{{ $siteLog['site_access_notes'] }}</td></tr>
        @endif
        @if(! empty($siteLog['delivery_routes']))
            <tr><td class="lbl">Delivery routes:</td>
                <td>{{ $siteLog['delivery_routes'] }}</td></tr>
        @endif
        @if(! empty($siteLog['comms_room_access_status']) || ! empty($siteLog['comms_room_access_notes']))
            @php
                $commsStatus = $commsLabels[$siteLog['comms_room_access_status'] ?? ''] ?? '';
                $commsParts  = array_filter([$commsStatus, $siteLog['comms_room_access_notes'] ?? '']);
            @endphp
            <tr><td class="lbl">Comms room access:</td>
                <td>{{ implode(' — ', $commsParts) }}</td></tr>
        @endif
        @if(! empty($siteLog['distance_from_base_miles']) || ! empty($siteLog['distance_from_base_notes']))
            @php
                $distParts = array_filter([
                    ! empty($siteLog['distance_from_base_miles']) ? $siteLog['distance_from_base_miles'] . ' miles from depot' : '',
                    $siteLog['distance_from_base_notes'] ?? '',
                ]);
            @endphp
            <tr><td class="lbl">Distance from depot:</td>
                <td>{{ implode(' — ', $distParts) }}</td></tr>
        @endif
    </tbody>
</table>
@endif

{{-- Scope of Works bullets (Tier 1 upgrade) --}}
@if(! empty($data['scope_of_works_bullets']))
<div class="sec-subheading" style="margin-top:8pt;">Works Activities</div>
<ul class="blist">
    @foreach($data['scope_of_works_bullets'] as $bullet)
        <li>{{ $bullet }}</li>
    @endforeach
</ul>
@endif

{{-- ─── Engineer Survey Findings lookup (quick task 260503-tfb) ───
     Build a name-keyed lookup from $data['rooms'] (ProjectContext-derived)
     so the existing $roomOverviews loop (driven by reviewed_data) can
     find the matching engineer_feedback block by room name. --}}
@php
    $efByRoom = [];
    foreach ((array) ($data['rooms'] ?? []) as $ctxRoom) {
        $key = strtolower(trim((string) ($ctxRoom['name'] ?? '')));
        $ef  = (array) ($ctxRoom['engineer_feedback'] ?? []);
        if ($key !== '' && ! empty($ef)) {
            $efByRoom[$key] = $ef;
        }
    }
    $methodLabels = [
        'ladder'   => 'Ladder',
        'podium'   => 'Podium steps',
        'tower'    => 'Access tower',
        'mewp'     => 'MEWP',
        'scaffold' => 'Scaffold',
        'na'       => 'Not required',
    ];
    $wallConstructionLabels = [
        'ply_lined'    => 'Ply-lined',
        'solid'        => 'Solid wall',
        'plasterboard' => 'Plasterboard',
        'masonry'      => 'Masonry / brick',
        'metal_stud'   => 'Metal stud',
        'concrete'     => 'Concrete',
    ];
    // Cable category enum verified against SiteSurveyController validation
    // (rooms.*.cable_routes.*.category): ceiling_speakers, desk_cables,
    // mic_cables, booking_panel_cables, screen_cables, rack_to_room, other.
    $cableCategoryLabels = [
        'ceiling_speakers'      => 'Ceiling speakers',
        'desk_cables'           => 'Desk cables',
        'mic_cables'            => 'Microphone cables',
        'booking_panel_cables'  => 'Booking panel cables',
        'screen_cables'         => 'Screen / display cables',
        'rack_to_room'          => 'Rack to room',
        'other'                 => 'Other',
    ];
@endphp
@if(! empty($roomOverviews) && is_array($roomOverviews))
    {{-- Per-room scope paragraphs from reviewed data.
         Prefers works_summary (install-action bullets — populated by the
         RamsComplianceUpgrade pipeline or by the project-review Convert-to-
         bullets button) and falls back to the raw overview prose only when
         no bullets are available. --}}
    @foreach($roomOverviews as $roomOv)
        @php
            // 'room' is the canonical key in reviewed_data room_overviews
            $rvName    = $roomOv['room'] ?? ($roomOv['room_name'] ?? ($roomOv['name'] ?? ''));
            $rvBullets = trim((string) ($roomOv['works_summary'] ?? ''));
            // Phase 22.1 D-08: per-room fallback chain simplified. `description`
            // and `scope` are no longer canonical room_overviews keys after
            // Plan 22.1-03 trimmed the normaliser to overview / works_summary /
            // solution_type_id. `overview` is the sole prose source.
            $rvDesc    = $roomOv['overview'] ?? '';

            // Parse bullet list when works_summary contains "- " markers.
            $rvBulletLines = [];
            if ($rvBullets !== '' && (str_starts_with($rvBullets, '- ') || str_contains($rvBullets, "\n- "))) {
                foreach (preg_split('/\r?\n/', $rvBullets) ?: [] as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    $rvBulletLines[] = preg_replace('/^[-•]\s*/', '', $ln);
                }
            }

            // Prose fallback — only computed if there are no bullets.
            if (empty($rvBulletLines)) {
                $rvDesc = preg_replace('/\*\*([^*]+)\*\*/', '$1', $rvDesc ?? '');
                $rvDesc = ltrim(trim($rvDesc), ': ');

                $rvDescLines = preg_split('/\r?\n/', trim($rvDesc)) ?: [];
                if (count($rvDescLines) >= 2) {
                    $firstLine     = trim($rvDescLines[0]);
                    $canonFirst    = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $firstLine));
                    $canonRoomName = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $rvName));
                    if ($canonFirst !== '' && $canonFirst === $canonRoomName) {
                        array_shift($rvDescLines);
                        $rvDesc = implode("\n", $rvDescLines);
                    }
                }

                $rvDescParas = array_filter(array_map('trim', preg_split('/\n{2,}/', trim($rvDesc))));
                $rvDesc = reset($rvDescParas) ?: '';
            } else {
                $rvDesc = '';
            }
        @endphp
        @if(! empty($rvBulletLines))
            <p class="body-para" style="margin-bottom: 2pt;"><strong>{{ $rvName }}:</strong></p>
            <ul class="blist" style="margin-top: 0; margin-bottom: 8pt;">
                @foreach($rvBulletLines as $b)
                    <li>{{ $b }}</li>
                @endforeach
            </ul>
        @elseif($rvName || $rvDesc)
            <p class="body-para"><strong>{{ $rvName }}:</strong>{{ $rvName && $rvDesc ? ' ' : '' }}{{ $rvDesc }}</p>
        @endif

        {{-- ─── Engineer Survey Findings (quick task 260503-tfb) ───
             Per-room block sourced from $data['rooms'][n]['engineer_feedback'].
             Every sub-block is independently @if-guarded so a row with all
             new fields NULL renders nothing — the heading itself is also
             guarded so untouched rooms remain visually identical to before. --}}
        @php
            $rvKey = strtolower(trim((string) ($rvName ?? '')));
            $ef    = $efByRoom[$rvKey] ?? [];
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
        @endphp
        @if($hasEF)
            <div class="sec-subheading" style="margin-top:6pt;">Engineer Survey Findings — {{ $rvName }}</div>

            {{-- Mounting heights --}}
            @php
                $mh         = (array) ($ef['mounting_heights'] ?? []);
                $heightRows = [];
                foreach ([
                    'screen_h_m'         => 'Screen',
                    'camera_h_m'         => 'Camera',
                    'booking_panel_h_m'  => 'Booking panel',
                    'speaker_h_m'        => 'Speaker',
                ] as $k => $lbl) {
                    if (! empty($mh[$k])) {
                        $heightRows[] = $lbl . ': ' . $mh[$k] . ' m';
                    }
                }
                foreach ((array) ($mh['other'] ?? []) as $other) {
                    $oLbl = trim((string) ($other['label'] ?? ''));
                    $oH   = $other['h_m'] ?? null;
                    if ($oLbl !== '' && $oH !== null && $oH !== '') {
                        $heightRows[] = $oLbl . ': ' . $oH . ' m';
                    }
                }
            @endphp
            @if(! empty($heightRows))
                <p class="body-para"><strong>Installation heights:</strong> {{ implode(' • ', $heightRows) }}</p>
            @endif

            {{-- Working at height methods --}}
            @php
                $wahLabels = array_values(array_filter(array_map(
                    fn ($m) => $methodLabels[strtolower((string) $m)] ?? ucfirst((string) $m),
                    (array) ($ef['work_at_height_methods'] ?? [])
                )));
            @endphp
            @if(! empty($wahLabels))
                <p class="body-para"><strong>Working at height — methods on site:</strong> {{ implode(', ', $wahLabels) }}</p>
            @endif

            {{-- Cable routes --}}
            @php $cableRoutes = (array) ($ef['cable_routes'] ?? []); @endphp
            @if(! empty($cableRoutes))
                <p class="body-para" style="margin-bottom:2pt;"><strong>Cable routes planned:</strong></p>
                <ul class="blist">
                    @foreach($cableRoutes as $cr)
                        @php
                            $catKey = (string) ($cr['category'] ?? '');
                            $cat    = $cableCategoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey));
                            $len    = ! empty($cr['length_m']) ? ($cr['length_m'] . ' m') : '';
                            $from   = trim((string) ($cr['from'] ?? ''));
                            $to     = trim((string) ($cr['to']   ?? ''));
                            $route  = ($from && $to) ? ($from . ' → ' . $to) : ($from ?: $to);
                            $note   = trim((string) ($cr['notes'] ?? ''));
                            $parts  = array_filter([$cat, $route, $len, $note]);
                        @endphp
                        @if(! empty($parts))
                            <li>{{ implode(' — ', $parts) }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif

            {{-- Wall construction & prep --}}
            @php
                $wcLabels = array_values(array_filter(array_map(
                    fn ($w) => $wallConstructionLabels[strtolower((string) $w)] ?? ucwords(str_replace('_', ' ', (string) $w)),
                    (array) ($ef['wall_construction'] ?? [])
                )));
                $prepFlags = [];
                if (! empty($ef['wall_needs_reinforcement'])) $prepFlags[] = 'Reinforcement required';
                if (! empty($ef['wall_needs_chase_out']))     $prepFlags[] = 'Chase-out required';
                if (! empty($ef['wall_needs_conduit']))       $prepFlags[] = 'Conduit installation required';
            @endphp
            @if(! empty($wcLabels) || ! empty($prepFlags))
                <p class="body-para">
                    <strong>Wall construction:</strong>
                    {{ ! empty($wcLabels) ? implode(', ', $wcLabels) : '—' }}
                    @if(! empty($prepFlags))
                        <br><strong>Prep needed:</strong> {{ implode(', ', $prepFlags) }}
                    @endif
                </p>
            @endif

            {{-- Brackets to source --}}
            @php $brackets = (array) ($ef['brackets_required'] ?? []); @endphp
            @if(! empty($brackets))
                <p class="body-para" style="margin-bottom:2pt;"><strong>Brackets to source:</strong></p>
                <ul class="blist">
                    @foreach($brackets as $b)
                        @php
                            $eq   = trim((string) ($b['equipment'] ?? ''));
                            $mod  = trim((string) ($b['model']     ?? ''));
                            $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                            $note = trim((string) ($b['notes']     ?? ''));
                            $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                            if ($note !== '') $line .= ' — ' . $note;
                        @endphp
                        @if($line !== '')
                            <li>{{ $line }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif

            {{-- Table & floor box info (compact, single line each if present) --}}
            @php
                $ti       = (array) ($ef['table_info'] ?? []);
                $hasTable = ! empty($ti) && (! empty($ti['has_grommets']) || ! empty($ti['notes']));
                $fb       = (array) ($ef['floor_box_info'] ?? []);
                $hasFb    = ! empty($fb) && (! empty($fb['has_floor_box']) || ! empty($fb['notes']));
            @endphp
            @if($hasTable)
                @php
                    $tParts = [];
                    if (! empty($ti['has_grommets'])) {
                        $tParts[] = ($ti['grommet_count'] ?? '?') . '× ' . trim((string) ($ti['grommet_size'] ?? '')) . ' grommets';
                    }
                    if (! empty($ti['notes'])) $tParts[] = $ti['notes'];
                @endphp
                <p class="body-para"><strong>Table:</strong> {{ implode(' — ', array_filter($tParts)) }}</p>
            @endif
            @if($hasFb)
                @php
                    $fParts = [];
                    if (! empty($fb['has_floor_box'])) {
                        $fParts[] = ($fb['power_outlets'] ?? 0) . ' power, ' . ($fb['data_outlets'] ?? 0) . ' data';
                        if (! empty($fb['cable_space'])) $fParts[] = trim((string) $fb['cable_space']) . ' cable space';
                    }
                    if (! empty($fb['notes'])) $fParts[] = $fb['notes'];
                @endphp
                <p class="body-para"><strong>Floor box:</strong> {{ implode(' — ', array_filter($fParts)) }}</p>
            @endif
        @endif
    @endforeach
@elseif($scopeOfWorks)
    {{-- Single scope-of-works block — split on double newlines to form paragraphs --}}
    @php $scopeParas = array_filter(array_map('trim', preg_split('/\n{2,}/', trim($scopeOfWorks)))); @endphp
    @foreach($scopeParas as $para)
        <p class="body-para">{{ $para }}</p>
    @endforeach
@else
<p class="body-para">
    <span style="color:#CC0000; font-style:italic;">Scope of works not generated.</span>
</p>
@endif

{{-- Equipment schedule --}}
<table class="equip-table">
    <thead>
        <tr>
            <th style="width:20%; text-align:left; padding-left:6pt;">Activity</th>
            <th style="text-align:left; padding-left:6pt;">Item</th>
            <th style="width:20%; text-align:left; padding-left:6pt;">Room / Area</th>
            <th style="width:8%;">Qty</th>
            <th style="width:14%; text-align:left; padding-left:6pt;">Notes</th>
        </tr>
    </thead>
    <tbody>
    @if($hasScope)

        @if($hasDecomm)
        <tr class="group-header">
            <td colspan="5">DECOMMISSION &amp; HANDBACK</td>
        </tr>
        @foreach($scopeItems['decommission'] as $item)
        <tr>
            <td>DECOMMISSION &amp; HANDBACK</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td>{{ $item['room'] ?? ($item['notes'] ?? '') }}</td>
            <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ isset($item['room']) ? ($item['notes'] ?? '') : '' }}</td>
        </tr>
        @endforeach
        @endif

        @if($hasRetain)
        <tr class="group-header">
            <td colspan="5">EXISTING &mdash; RETAINED</td>
        </tr>
        @foreach($scopeItems['retained'] as $item)
        <tr>
            <td>EXISTING &mdash; RETAINED</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td>{{ $item['room'] ?? ($item['notes'] ?? '') }}</td>
            <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ isset($item['room']) ? ($item['notes'] ?? '') : '' }}</td>
        </tr>
        @endforeach
        @endif

        @if($hasNew)
        <tr class="group-header">
            <td colspan="5">NEW INSTALLATION</td>
        </tr>
        @foreach($scopeItems['new_install'] as $item)
        <tr>
            <td>NEW INSTALLATION</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td>{{ $item['room'] ?? ($item['notes'] ?? '') }}</td>
            <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
            <td>{{ isset($item['room']) ? ($item['notes'] ?? '') : '' }}</td>
        </tr>
        @endforeach
        @endif

    @elseif(! empty($quote['line_items']))
        <tr class="group-header">
            <td colspan="5">NEW INSTALLATION</td>
        </tr>
        @foreach($quote['line_items'] as $item)
        <tr>
            <td>NEW INSTALLATION</td>
            <td>{{ $item['description'] ?? '' }}</td>
            <td>{{ $item['room'] ?? '' }}</td>
            <td style="text-align:center;">{{ $item['qty'] ?? '' }}</td>
            <td></td>
        </tr>
        @endforeach

    @else
        <tr>
            <td colspan="5" style="font-style:italic; color:#666; padding:6pt;">No equipment items listed.</td>
        </tr>
    @endif
    </tbody>
</table>

{{-- ════════════════════════════════════════════════════════════════════════
     SCOPE TRACEABILITY — only rendered when at least one row has a RAMS
     activity filled in (pre-filled rows with blank activity are hidden).
     ════════════════════════════════════════════════════════════════════════ --}}
@php
    $hasTraceabilityActivity = ! empty($scopeTraceability)
        && collect($scopeTraceability)->contains(fn ($r) => trim((string)($r['rams_activity'] ?? '')) !== '');
@endphp
@if($hasTraceabilityActivity)
<div class="sec-heading">Scope Traceability</div>
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:26%;">Quote Ref / Item Description</th>
            <th style="width:28%;">RAMS Activity</th>
            <th style="width:18%;">Room / Area</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
    @foreach($scopeTraceability as $stRow)
    @php $stRow = is_array($stRow) ? $stRow : []; @endphp
    <tr>
        <td>{{ $stRow['quote_item']    ?? '' }}</td>
        <td>{{ $stRow['rams_activity'] ?? '' }}</td>
        <td>{{ $stRow['room']          ?? '' }}</td>
        <td>{{ $stRow['notes']         ?? '' }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     EXCLUSIONS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">Exclusions</div>
@if(! empty($exclusionsList))
<ul class="blist">
@foreach($exclusionsList as $exItem)
    @if(trim((string)$exItem) !== '')
    <li>{{ $exItem }}</li>
    @endif
@endforeach
</ul>
@else
<ul class="blist">
    <li>No structural works.</li>
    <li>No core drilling unless explicitly scoped.</li>
    <li>No containment beyond surface trunking.</li>
    <li>No decorative making good after cable routes.</li>
    <li>No IT network provision unless scoped.</li>
</ul>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 5 — RISK ASSESSMENT
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">5. &nbsp;Risk Assessment</div>

<p class="body-para">
    The risk scoring matrix below is used throughout this assessment. Likelihood (L) &times; Severity (S) = Risk Score (R).
</p>

{{-- 5×5 Risk Matrix --}}
<table class="risk-matrix" style="margin-bottom: 6pt;">
    <tr>
        <td class="col-hdr" style="text-align:left; padding-left:4pt;"></td>
        <td class="col-hdr">Severity 1<br><small>Minor</small></td>
        <td class="col-hdr">Severity 2<br><small>Moderate</small></td>
        <td class="col-hdr">Severity 3<br><small>Serious</small></td>
        <td class="col-hdr">Severity 4<br><small>Major</small></td>
        <td class="col-hdr">Severity 5<br><small>Fatal</small></td>
    </tr>
    @foreach([1=>'Unlikely', 2=>'Possible', 3=>'Likely', 4=>'Probable', 5=>'Almost Certain'] as $l => $lLabel)
    <tr>
        <td class="row-hdr">Likelihood {{ $l }}<br><small>{{ $lLabel }}</small></td>
        @foreach([1,2,3,4,5] as $s)
        @php $score = $l * $s; @endphp
        <td style="background-color: {{ $matCell($l, $s) }}; font-weight:700;">{{ $score }}</td>
        @endforeach
    </tr>
    @endforeach
</table>

{{-- Risk key legend (3 bands matching reference) --}}
<table class="risk-key-row" style="margin-bottom: 10pt;">
    <tr>
        <td class="rk-band" style="background-color:#D4EDDA;">1&ndash;4<br><strong>LOW</strong></td>
        <td style="font-size:8.5pt;">Acceptable. Monitor and maintain controls.</td>
        <td class="rk-band" style="background-color:#FFF3CD;">5&ndash;9<br><strong>MEDIUM</strong></td>
        <td style="font-size:8.5pt;">Action required to reduce risk.</td>
        <td class="rk-band" style="background-color:#F8D7DA;">10+<br><strong>HIGH</strong></td>
        <td style="font-size:8.5pt;">Stop work. Implement immediate controls.</td>
    </tr>
</table>

{{-- Hazard register --}}
@if(! empty($hazards))
<table class="haz-table">
    <thead>
        <tr>
            <th style="width:5%;">Ref</th>
            <th style="width:19%;">Hazard</th>
            <th style="width:14%;">Persons at Risk</th>
            <th style="width:9%;">Initial Risk<br>L&times;S=R</th>
            <th style="width:30%;">Control Measures</th>
            <th style="width:9%;">Residual Risk<br>L&times;S=R</th>
        </tr>
    </thead>
    <tbody>
    @foreach($hazards as $h)
        @php
            $preL      = max(1, min(5, (int)($h['pre_likelihood']  ?? 1)));
            $preS      = max(1, min(5, (int)($h['pre_severity']    ?? 1)));
            $postL     = max(1, min(5, (int)($h['post_likelihood'] ?? 1)));
            $postS     = max(1, min(5, (int)($h['post_severity']   ?? 1)));
            $preScore  = $preL  * $preS;
            $postScore = $postL * $postS;
            $refLabel  = 'RA' . str_pad($loop->index + 1, 2, '0', STR_PAD_LEFT);
        @endphp
        <tr>
            <td class="score-cell">{{ $refLabel }}</td>
            <td><strong>{{ $h['hazard'] ?? '' }}</strong></td>
            <td>
                <ul class="blist" style="padding-left:6mm;">
                @foreach((array)($h['persons_at_risk'] ?? []) as $par)
                    @if(is_string($par) && trim($par) !== '')
                    <li>{{ $par }}</li>
                    @endif
                @endforeach
                </ul>
            </td>
            <td class="score-cell" style="background-color:{{ $riskBg($preScore) }};">
                {{ $preL }}&times;{{ $preS }}={{ $preScore }}<br>
                <span style="font-size:7pt;">{{ $riskLabel($preScore) }}</span>
            </td>
            <td>
                <ul class="blist" style="padding-left:6mm;">
                @foreach((array)($h['controls'] ?? []) as $c)
                    @if(is_string($c))
                    <li>{{ $c }}</li>
                    @endif
                @endforeach
                </ul>
            </td>
            <td class="score-cell" style="background-color:{{ $riskBg($postScore) }};">
                {{ $postL }}&times;{{ $postS }}={{ $postScore }}<br>
                <span style="font-size:7pt;">{{ $riskLabel($postScore) }}</span>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@else
<p class="note-text">No hazards identified.</p>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 6 — METHOD STATEMENT
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">6. &nbsp;Method Statement</div>

{{-- 6.1 Team Requirements --}}
<div class="sec-subheading">6.1 Team Requirements &amp; Competencies</div>
@php
    // Build a working team list. Prefer the structured $team array (built by
    // RamsBuilderService when reviewed_data has been populated), but fall
    // back to $project fields when it's empty — covers the case where the
    // RAMS was generated before the team-array path existed, or where the
    // engineer typed names into the simple-string fields on the form.
    $teamForRender = is_array($team ?? null) ? $team : [];
    if (empty($teamForRender)) {
        $pmName  = trim((string) ($project['project_manager'] ?? ''));
        $leName  = trim((string) ($project['lead_engineer']   ?? ''));
        $progStr = trim((string) ($project['programmer']      ?? ''));
        $addStr  = trim((string) ($project['additional_engineers'] ?? ''));
        if ($pmName !== '') {
            $teamForRender[] = ['role' => 'Project Manager', 'name' => $pmName];
        }
        if ($leName !== '') {
            $teamForRender[] = ['role' => 'Lead Engineer', 'name' => $leName];
        }
        foreach (preg_split('/[,;]+/', $addStr) ?: [] as $eng) {
            $eng = trim($eng);
            if ($eng !== '') {
                $teamForRender[] = ['role' => 'Engineer', 'name' => $eng];
            }
        }
        if ($progStr !== '') {
            $teamForRender[] = ['role' => 'Programmer', 'name' => $progStr];
        }
    }
@endphp
<table class="std-table">
    <thead>
        <tr>
            <th style="width:34%;">Role</th>
            <th style="width:8%;">Qty</th>
            <th>Requirements</th>
        </tr>
    </thead>
    <tbody>
    @if(! empty($teamForRender))
        @php
            $reqMap = [
                'lead av engineer' => 'Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, Biamp DSP configuration, and AV commissioning. IPAF/PASMA required if working at height.',
                'lead engineer'    => 'Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, and AV commissioning. IPAF/PASMA required if working at height.',
                'av engineer'      => 'Qualified AV installation engineer. CSCS/ECS Card required. Experienced in structured AV cabling, rack builds, and equipment installation.',
                'engineer'         => 'Qualified AV installation engineer. CSCS/ECS Card required. Experienced in structured AV cabling and equipment installation.',
                'project manager'  => 'SMSTS or equivalent. CSCS Card. First Aid at Work certificate. Responsible for site management and client liaison.',
                'programmer'       => 'AV programmer competent in control system configuration and DSP programming. CSCS Card.',
            ];
            // Aggregate by role and collect names so the table reads
            // "Lead Engineer — Simon Pittaway" instead of bare "Lead Engineer".
            $roleGroups = [];
            foreach ($teamForRender as $member) {
                $role = trim((string) ($member['role'] ?? 'Engineer'));
                $name = trim((string) ($member['name'] ?? ''));
                if (! isset($roleGroups[$role])) {
                    $roleGroups[$role] = ['qty' => 0, 'names' => []];
                }
                $roleGroups[$role]['qty']++;
                if ($name !== '') {
                    $roleGroups[$role]['names'][] = $name;
                }
            }
        @endphp
        @foreach($roleGroups as $role => $info)
        @php
            $names = array_values(array_unique($info['names']));
            $label = $names ? $role . ' — ' . implode(', ', $names) : $role;
        @endphp
        <tr>
            <td>{{ $label }}</td>
            <td style="text-align:center;">{{ $info['qty'] }}</td>
            <td>{{ $reqMap[strtolower($role)] ?? 'Qualified AV installation engineer. CSCS Card.' }}</td>
        </tr>
        @endforeach
    @else
        <tr>
            <td>Lead AV Engineer</td>
            <td style="text-align:center;">1</td>
            <td>Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, Biamp DSP configuration, and AV commissioning. IPAF/PASMA required if working at height.</td>
        </tr>
        <tr>
            <td>AV Engineer</td>
            <td style="text-align:center;">1</td>
            <td>Qualified AV installation engineer. CSCS/ECS Card required. Experienced in structured AV cabling, rack builds, and equipment installation.</td>
        </tr>
    @endif
    </tbody>
</table>

{{-- 6.1.1 Site Vehicles & Registrations — only if any provided --}}
@php
    $vehSrc = $project['site_vehicles'] ?? ($data['site_vehicles'] ?? null);
    if (is_string($vehSrc)) { $vehSrc = preg_split('/\r?\n/', $vehSrc) ?: []; }
    $vehiclesList = array_values(array_filter(
        array_map('trim', (array) ($vehSrc ?? [])),
        fn (string $v) => $v !== ''
    ));
@endphp
@if(! empty($vehiclesList))
<div class="sec-subheading" style="margin-top:8pt;">Site Vehicles &amp; Registrations</div>
<table class="std-table">
    <thead>
        <tr>
            <th style="width:34%;">Vehicle Registration</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach($vehiclesList as $entry)
            @php
                $reg = $entry; $note = '';
                if (str_contains($entry, ' - ')) {
                    [$reg, $note] = explode(' - ', $entry, 2);
                }
            @endphp
            <tr>
                <td>{{ trim($reg) }}</td>
                <td>{{ trim($note) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- 6.2 Tools & Equipment --}}
<div class="sec-subheading">6.2 Tools &amp; Equipment</div>
<ul class="blist">
@php $toolsList = ! empty($tools) ? $tools : $defaultTools; @endphp
@foreach($toolsList as $tool)
    <li>{{ $tool }}</li>
@endforeach
</ul>

{{-- 6.3 PPE Matrix (Tier 1 upgrade) --}}
@if(! empty($data['ppe_matrix']))
<div class="sec-subheading">6.3 Personal Protective Equipment (PPE)</div>
<table class="std-table">
    <thead>
        <tr>
            <th style="width:35%;">Task</th>
            <th>PPE Required</th>
        </tr>
    </thead>
    <tbody>
    @foreach($data['ppe_matrix'] as $ppeRow)
        <tr>
            <td>{{ $ppeRow['task'] ?? '' }}</td>
            <td>
                <ul class="blist" style="margin:0; padding-left:14pt;">
                @foreach(($ppeRow['ppe'] ?? []) as $ppeItem)
                    <li>{{ $ppeItem }}</li>
                @endforeach
                </ul>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- 6.4 Access Equipment (Tier 1 upgrade) --}}
@if(! empty($data['access_equipment_detail']))
@php $accessDetail = $data['access_equipment_detail']; @endphp
<div class="sec-subheading">6.4 Access Equipment</div>
@if(! empty($accessDetail['items']))
<ul class="blist">
    @foreach($accessDetail['items'] as $accessItem)
        <li>{{ $accessItem }}</li>
    @endforeach
</ul>
@endif
@if(! empty($accessDetail['requirements']))
<p class="body-para" style="margin-top:4pt;"><strong>Requirements:</strong></p>
<ul class="blist">
    @foreach($accessDetail['requirements'] as $req)
        <li>{{ $req }}</li>
    @endforeach
</ul>
@endif
@endif

{{-- Pre-Installation Requirements (Client Responsibilities) --}}
@php $preInstallNum = ! empty($data['ppe_matrix']) ? '6.5' : '6.3'; @endphp
<div class="sec-subheading">{{ $preInstallNum }} Pre-Installation Requirements (Client Responsibilities)</div>
@if(! empty($clientResp))
<ul class="blist">
    @foreach($clientResp as $item)
    <li>{{ $item }}</li>
    @endforeach
</ul>
@else
<ul class="blist">
    <li>Mains power outlets (standard UK 13A sockets) must be available and live at each equipment location.</li>
    <li>Network / LAN drops (minimum Cat5e, ideally Cat6) must be active at each device location.</li>
    <li>The rooms must be booked out and unavailable for normal use on the installation day(s).</li>
    <li>A site contact must be available throughout the working day to grant access and sign off the completed works.</li>
    <li>Any client IT, security, or facilities requirements must be communicated to the 21CAV Operations team in advance.</li>
</ul>
@endif

{{-- 6.3 expanded: structured client responsibilities --}}
@php
    $crExpLabels = [
        'network_readiness' => 'Network / LAN readiness (active drops at device locations)',
        'licences'          => 'Software licences / subscriptions (Teams Rooms, Zoom, etc.)',
        'access'            => 'Site access and room availability on installation day(s)',
        'power_validation'  => 'Mains power validation (sockets live and tested)',
    ];
    $crExpChecked = array_filter(
        is_array($clientRespExp) ? $clientRespExp : [],
        fn ($v, $k) => is_array($v) && ! empty($v['required']) && $k !== 'additional',
        ARRAY_FILTER_USE_BOTH
    );
    $crExpAdditional = is_array($clientRespExp['additional'] ?? null) ? $clientRespExp['additional'] : [];
@endphp
@if(! empty($crExpChecked) || ! empty($crExpAdditional))
<ul class="blist" style="margin-top:4pt;">
@foreach($crExpChecked as $crk => $crv)
    <li><strong>{{ $crExpLabels[$crk] ?? $crk }}:</strong>{{ ! empty($crv['notes']) ? ' ' . $crv['notes'] : ' Required prior to works commencing.' }}</li>
@endforeach
@foreach($crExpAdditional as $cra)
    @php $cra = is_array($cra) ? $cra : []; @endphp
    @if(! empty($cra['item']))
    <li><strong>{{ $cra['item'] }}:</strong>{{ ! empty($cra['notes']) ? ' ' . $cra['notes'] : '' }}</li>
    @endif
@endforeach
</ul>
@endif

{{-- Method of Works --}}
@php $methodNum = ! empty($data['ppe_matrix']) ? '6.6' : '6.4'; @endphp
<div class="sec-subheading">{{ $methodNum }} Method of Works &mdash; Step by Step</div>
@php $phases = is_array($ms) ? ($ms['phases'] ?? []) : []; @endphp
@if(! empty($phases))
    @foreach($phases as $i => $phase)
        @php
            $rawTitle   = trim((string)($phase['title'] ?? ''));
            $cleanTitle = preg_replace('/^\s*(step\s+\d+[\.\-–—\s]*|phase\s+\d+[\.\-–—\s]*|\d+[\.\-–—\s]+)/i', '', $rawTitle);
            $stepLabel  = 'Step ' . ($i + 1) . ' &nbsp;&nbsp;&nbsp; ' . htmlspecialchars($cleanTitle, ENT_QUOTES);
        @endphp
        <div class="ms-step-header">{!! $stepLabel !!}</div>
        <ul class="blist">
            @foreach((array)($phase['steps'] ?? []) as $step)
                @if(is_string($step) && trim($step) !== '')
                <li>{{ $step }}</li>
                @endif
            @endforeach
        </ul>
        @if(! empty($phase['associated_risks_label']))
        <p style="font-size:7.5pt; color:#666; font-style:italic; margin:2pt 0 8pt 0;">{{ $phase['associated_risks_label'] }}</p>
        @endif
    @endforeach
@else
<p class="note-text">Method statement not available.</p>
@endif

{{-- 6.7 Material Handling --}}
@php
    $mhNum = ! empty($data['ppe_matrix']) ? '6.7' : '6.5';
    $mhDerived = $data['material_handling_derived'] ?? null;
    $mhDerivedItems = is_array($mhDerived['items'] ?? null) ? $mhDerived['items'] : [];
    $mhHasHeavy = ! empty($mhDerived['has_heavy_items']) || ! empty($mhItems);
@endphp
<div class="sec-subheading">{{ $mhNum }} Material Handling</div>
@if(! empty($mhItems))
{{-- User-specified items from reviewed_data --}}
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:40%;">Item Description</th>
            <th style="width:15%;">Weight (approx.)</th>
            <th>Handling Method / Controls</th>
        </tr>
    </thead>
    <tbody>
    @foreach($mhItems as $mhi)
        @if(! empty($mhi['item']))
        <tr>
            <td>{{ $mhi['item'] }}</td>
            <td style="text-align:center;">{{ ($mhi['weight_kg'] ?? '') ? $mhi['weight_kg'] . ' kg' : '—' }}</td>
            <td>{{ $mhi['handling_method'] ?? '—' }}</td>
        </tr>
        @endif
    @endforeach
    </tbody>
</table>
@elseif(! empty($mhDerivedItems))
{{-- Auto-derived from equipment data --}}
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:10%;">Qty</th>
            <th style="width:35%;">Item</th>
            <th>Handling Method / Controls</th>
        </tr>
    </thead>
    <tbody>
    @foreach($mhDerivedItems as $di)
        <tr>
            <td style="text-align:center;">{{ $di['qty'] ?? 1 }}</td>
            <td>{{ $di['item'] ?? '' }}</td>
            <td>{{ $di['handling_method'] ?? 'Assess weight before lifting. Team lift for items over 20 kg.' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
<p class="body-para">{{ $mhDerived['statement'] ?? ($mhHasHeavy ? 'Manual handling controls apply — team lift for items over 20 kg, use mechanical aids where available.' : 'No significant heavy or bulky items identified. Standard manual handling precautions apply.') }}</p>
@if($mhNotes)
<p class="body-para"><strong>Handling Notes:</strong> {{ $mhNotes }}</p>
@endif

{{-- 6.8 Permit & Isolation Requirements --}}
@php $permitNum = ! empty($data['ppe_matrix']) ? '6.8' : '6.6'; @endphp
@if(! empty($data['permit_and_isolation']['rules']))
<div class="sec-subheading">{{ $permitNum }} Permit &amp; Isolation Requirements</div>
<ul class="blist">
    @foreach($data['permit_and_isolation']['rules'] as $rule)
        <li>{{ $rule }}</li>
    @endforeach
</ul>
@endif

{{-- 6.9 Fixings & Installation Control --}}
@php $fixingsNum = ! empty($data['ppe_matrix']) ? '6.9' : '6.7'; @endphp
@if(! empty($data['fixings_control']['rules']))
<div class="sec-subheading">{{ $fixingsNum }} Fixings &amp; Installation Control</div>
<ul class="blist">
    @foreach($data['fixings_control']['rules'] as $rule)
        <li>{{ $rule }}</li>
    @endforeach
</ul>
@endif

{{-- 6.10 Supervision & Quality Assurance --}}
@php $qaNum = ! empty($data['ppe_matrix']) ? '6.10' : '6.8'; @endphp
@if(! empty($data['supervision_and_qa']['responsibilities']))
<div class="sec-subheading">{{ $qaNum }} Supervision &amp; Quality Assurance</div>
<ul class="blist">
    @foreach($data['supervision_and_qa']['responsibilities'] as $item)
        <li>{{ $item }}</li>
    @endforeach
</ul>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     DECOMMISSIONING PROCEDURE
     ════════════════════════════════════════════════════════════════════════ --}}
@if($decommEnabled)
<div class="sec-heading">Decommissioning Procedure</div>
@php
    $decomLabel    = $decommData['labelling_procedure']    ?? '';
    $decomStorage  = $decommData['storage_location']       ?? '';
    $decomDisposal = $decommData['disposal_method']        ?? '';
    $decomSignOff  = ! empty($decommData['client_sign_off_required']);
    $decomStepsPdf = is_array($decommData['steps'] ?? null) ? $decommData['steps'] : [];
@endphp
<div class="kv-block">
    @if($decomLabel)    <p><strong>Labelling Procedure:</strong> {{ $decomLabel }}</p>@endif
    @if($decomStorage)  <p><strong>Storage Location:</strong> {{ $decomStorage }}</p>@endif
    @if($decomDisposal) <p><strong>Disposal Method:</strong> {{ $decomDisposal }}</p>@endif
    <p><strong>Client Sign-Off Required:</strong> {{ $decomSignOff ? 'Yes — client must sign before removal of any equipment' : 'No' }}</p>
</div>
@if(! empty($decomStepsPdf))
<ol style="margin: 0 0 8pt 18pt; font-size:9.5pt; line-height:1.5;">
@foreach($decomStepsPdf as $dStep)
    @if(trim((string)$dStep) !== '')
    <li>{{ $dStep }}</li>
    @endif
@endforeach
</ol>
@endif
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     PERMITS & AUTHORISATIONS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">Permits &amp; Authorisations</div>
@if(! empty($requiredPermits))
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:30%;">Permit Type</th>
            <th>Notes / Requirements</th>
        </tr>
    </thead>
    <tbody>
    @foreach($requiredPermits as $permit)
    <tr>
        <td><strong>{{ $permit['type'] ?? '' }}</strong></td>
        <td>{{ $permit['notes'] ?? 'Permit to be obtained from site/client before works commence.' }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
<p class="note-text">All permits must be obtained and displayed on site before relevant works commence. Engineers must not start permit-controlled activities without a valid, signed permit.</p>
@else
<p class="body-para">No project-specific permits have been pre-identified. Standard site requirements still apply: any permits called out in the Method Statement (e.g. permit-to-work for ceiling penetrations, electrical isolations) must be obtained from the site / client representative before the relevant activity commences.</p>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     CDM 2015 DUTY HOLDERS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">CDM 2015 Duty Holders</div>
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:22%;">Role</th>
            <th style="width:26%;">Organisation</th>
            <th style="width:26%;">Name</th>
            <th>Contact</th>
        </tr>
    </thead>
    <tbody>
    @php
        // Merge cdm_duty_holders from compliance upgrade when cdmRows is empty
        $cdmMerged = $cdmRows;
        if (empty($cdmMerged) && ! empty($data['cdm_duty_holders'])) {
            $cdmDh = $data['cdm_duty_holders'];
            $cdmMerged = [
                ['role' => 'Client',               'organisation' => $cdmDh['client'] ?? '',               'name' => '', 'contact' => ''],
                ['role' => 'Principal Designer',    'organisation' => $cdmDh['principal_designer'] ?? '',   'name' => '', 'contact' => ''],
                ['role' => 'Principal Contractor',  'organisation' => $cdmDh['principal_contractor'] ?? '', 'name' => '', 'contact' => ''],
                ['role' => 'Sub-contractor',        'organisation' => $cdmDh['contractor'] ?? $company,    'name' => $cdmDh['site_supervisor'] ?? '', 'contact' => $phone],
            ];
        }
        $cdmLookup = [];
        foreach ($cdmMerged as $cr) { $cdmLookup[$cr['role'] ?? ''] = $cr; }
        $cdmDisplayRoles = ['Client', 'Principal Designer', 'Principal Contractor', 'Sub-contractor'];
    @endphp
    @foreach($cdmDisplayRoles as $cdmRole)
    @php
        $cr = $cdmLookup[$cdmRole] ?? [];
        $isSubcon = ($cdmRole === 'Sub-contractor');
        $cdmOrg  = $cr['organisation'] ?? ($isSubcon ? $company : '');
        $cdmName = $cr['name']         ?? '';
        $cdmCont = $cr['contact']      ?? ($isSubcon ? $phone : '');
    @endphp
    <tr>
        <td><strong>{{ $cdmRole }}</strong></td>
        <td>{{ $cdmOrg ?: '—' }}</td>
        <td>{{ $cdmName ?: '—' }}</td>
        <td>{{ $cdmCont ?: '—' }}</td>
    </tr>
    @endforeach
    </tbody>
</table>

{{-- ════════════════════════════════════════════════════════════════════════
     COSHH ASSESSMENT
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">COSHH Assessment</div>
<p class="body-para">
    AV installation works on this project involve the use of the following substances or
    processes that may present a health hazard under the Control of Substances Hazardous
    to Health Regulations 2002 (COSHH):
</p>
<ul class="blist">
    <li><strong>Cable conduit adhesives / sealants</strong> — used in limited quantities. Ensure adequate ventilation. Wear nitrile gloves and safety glasses. Avoid skin contact. Store according to manufacturer data sheet.</li>
    <li><strong>Dust generated by drilling / cutting</strong> — use FFP2 dust masks when drilling into plasterboard, masonry or MDF. Use dust extraction where practicable.</li>
    <li><strong>Electrical flux (soldering)</strong> — only where cable terminations require soldering. Ensure ventilation. Avoid inhalation of fumes. Use flux-specific respiratory protection if repeated soldering is required.</li>
    <li><strong>Battery acid (UPS batteries if applicable)</strong> — handle sealed VRLA batteries per manufacturer instructions. Wear chemical-resistant gloves and eye protection.</li>
</ul>
<p class="body-para">
    Engineers must report any unexpected COSHH hazard (e.g. discovery of asbestos-containing
    materials, chemical spills) to the Project Manager and cease work in the affected area
    immediately. No work to recommence until the hazard is assessed and controlled.
</p>
@if(! empty($data['coshh']))
<p class="body-para"><strong>Site-specific COSHH entries:</strong></p>
<ul class="blist">
    @foreach((array)$data['coshh'] as $coshhItem)
    <li>{{ is_string($coshhItem) ? $coshhItem : ($coshhItem['item'] ?? '') }}</li>
    @endforeach
</ul>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     ENVIRONMENTAL MANAGEMENT
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">Environmental Management</div>

<div class="sec-subheading">Waste Disposal</div>
<p class="body-para">
    All waste generated during the works — including packaging materials, cable off-cuts,
    redundant equipment, and general site waste — will be managed in accordance with the
    Environmental Protection Act 1990 and the Waste (England and Wales) Regulations 2011.
</p>
@if($wasteLabel)
<p class="body-para"><strong>Waste removal responsibility:</strong> {{ $wasteLabel }}{{ $wasteNotes ? ' — ' . $wasteNotes : '' }}</p>
@else
<p class="body-para">Waste removal responsibility to be confirmed with client prior to works.</p>
@endif
<ul class="blist">
    <li>No waste to be disposed of via client's trade waste unless agreed in writing.</li>
    <li>Hazardous waste (e.g. batteries, lamps, WEEE) to be disposed of via registered carriers only.</li>
    <li>Site to be left clean and tidy at the end of each working day.</li>
</ul>

<div class="sec-subheading">Noise, Dust &amp; Vibration</div>
<ul class="blist">
    <li>Noisy operations (drilling, chasing) to be carried out during agreed working hours only and with prior notification to the site/client representative.</li>
    <li>Dust suppression measures (dust sheets, vacuuming, wet methods) to be used where practical.</li>
    <li>Hand-arm vibration exposure to be minimised; use of powered tools to comply with the Control of Vibration at Work Regulations 2005. Engineers to use anti-vibration PPE where required.</li>
    <li>Any spill of materials on site (cable lubricants, adhesives) to be contained and cleaned immediately.</li>
</ul>

{{-- ════════════════════════════════════════════════════════════════════════
     WELFARE ARRANGEMENTS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">Welfare Arrangements</div>
<ul class="blist">
    <li><strong>Toilets:</strong> Engineers will use welfare facilities provided or indicated by the site/client representative.{{ $welfareNotes ? '' : ' Location to be confirmed at site induction.' }}</li>
    <li><strong>Washing facilities:</strong> Adequate washing facilities with hot and cold water to be made available on site.</li>
    <li><strong>Rest area:</strong> Engineers will use designated rest areas as directed by the site manager. No eating or drinking in work areas.</li>
    <li><strong>First Aid:</strong> At least one engineer on site will hold a current First Aid at Work or Emergency First Aid at Work certificate. First aid kit carried at all times. Nearest hospital A&amp;E to be identified at site induction.</li>
    <li><strong>Drinking water:</strong> Engineers to carry their own supply; confirm availability of potable water with site contact.</li>
</ul>
@if($welfareNotes)
<p class="body-para"><strong>Site-specific welfare notes:</strong> {{ $welfareNotes }}</p>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 7 — EMERGENCY PROCEDURES
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">7. &nbsp;Emergency Procedures</div>

{{-- 7.0 Site-Specific Emergency Details (260712-twi Task 3) --}}
@php
    $siteEmerg = (array) ($data['site_emergency'] ?? ($rams->reviewed_data['site_emergency'] ?? []));
    $hasSiteEmerg = ! empty(array_filter($siteEmerg, fn ($v) => is_string($v) ? trim($v) !== '' : ! empty($v)));
@endphp
<div class="sec-subheading">7.0 Site-Specific Emergency Details</div>
@if($hasSiteEmerg)
<table class="emerg-table" style="margin-bottom: 10pt;">
    <tr>
        <td class="e-lbl">Nearest A&amp;E Hospital</td>
        <td class="e-val" colspan="3">
            {{ $siteEmerg['nearest_hospital'] ?: 'TBC' }}
            @if(! empty($siteEmerg['hospital_address']))
                <br><span style="font-size:8pt; color:#555;">{{ $siteEmerg['hospital_address'] }}</span>
            @endif
        </td>
    </tr>
    <tr>
        <td class="e-lbl">Fire Assembly Point</td>
        <td class="e-val" colspan="3">{{ $siteEmerg['fire_assembly_point'] ?: 'TBC' }}</td>
    </tr>
    <tr>
        <td class="e-lbl">Fire Warden</td>
        <td class="e-val">{{ $siteEmerg['fire_warden_name'] ?: 'TBC' }}</td>
        <td class="e-lbl">Contact</td>
        <td class="e-val">{{ $siteEmerg['fire_warden_contact'] ?: '—' }}</td>
    </tr>
    <tr>
        <td class="e-lbl">First Aider</td>
        <td class="e-val">{{ $siteEmerg['first_aider_name'] ?: 'TBC' }}</td>
        <td class="e-lbl">Contact</td>
        <td class="e-val">{{ $siteEmerg['first_aider_contact'] ?: '—' }}</td>
    </tr>
    <tr>
        <td class="e-lbl">Nearest Defibrillator</td>
        <td class="e-val" colspan="3">{{ $siteEmerg['defibrillator_location'] ?: 'TBC — confirm at site induction' }}</td>
    </tr>
</table>
@else
<div style="border: 2pt solid #c00; background: #ffecec; padding: 8pt; margin: 6pt 0 12pt 0; color: #900; font-weight: 700; text-align: center;">
    TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE.<br>
    <span style="font-weight: normal; font-size: 8.5pt;">Nearest hospital, fire assembly point, fire warden, first aider, and defibrillator location have not been captured in the review form.</span>
</div>
@endif

{{-- 7.1 Emergency Contact Numbers --}}
<div class="sec-subheading">7.1 Emergency Contact Numbers</div>
<table class="emerg-table" style="margin-bottom: 10pt;">
    <tr>
        <td class="e-lbl">Emergency Services</td>
        <td class="e-val"><strong>999</strong></td>
        <td class="e-lbl">Non-Emergency Police</td>
        <td class="e-val"><strong>101</strong></td>
    </tr>
    <tr>
        <td class="e-lbl">Site Contact</td>
        <td class="e-val">{{ $clientContact ?: ($siteContact ?: 'TBC at site induction') }}</td>
        <td class="e-lbl">{{ $compShort }} Operations</td>
        <td class="e-val">{{ $phone }}</td>
    </tr>
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

{{-- RIDDOR Reporting Matrix (260712-twi Task 3) --}}
<div class="sec-subheading" style="margin-top: 4pt;">RIDDOR Reporting Matrix</div>
<table class="std-table" style="margin-bottom: 10pt;">
    <thead>
        <tr>
            <th style="width: 30%;">Incident Type</th>
            <th style="width: 30%;">Responsible Person</th>
            <th style="width: 22%;">Timescale</th>
            <th>Reporting Route</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Death or specified major injury</td>
            <td>{{ $company }} Ops Manager</td>
            <td><strong>Immediate</strong></td>
            <td>HSE Incident Contact Centre — 0345 300 9923</td>
        </tr>
        <tr>
            <td>Over-7-day incapacity (injury)</td>
            <td>{{ $company }} Ops Manager</td>
            <td>Within 15 days</td>
            <td>HSE online RIDDOR form (F2508)</td>
        </tr>
        <tr>
            <td>Dangerous occurrence (near miss)</td>
            <td>{{ $company }} Ops Manager</td>
            <td>Within 10 days</td>
            <td>HSE online RIDDOR form (F2508)</td>
        </tr>
        <tr>
            <td>Occupational disease</td>
            <td>{{ $company }} Ops Manager</td>
            <td>Within 10 days of diagnosis</td>
            <td>HSE online RIDDOR form (F2508A)</td>
        </tr>
    </tbody>
</table>

{{-- 7.3 In the Event of a Fire --}}
<div class="sec-subheading">7.3 In the Event of a Fire</div>
<ul class="blist">
    <li>Raise the alarm immediately using the nearest fire alarm call point.</li>
    <li>Evacuate the building by the nearest fire exit. Do not use lifts.</li>
    <li>Proceed to the designated assembly point as directed by the site fire warden.</li>
    <li>Do not re-enter the building until instructed to do so by the fire warden or emergency services.</li>
    <li>Inform the site manager that {{ $compShort }} engineers are on-site and present at the assembly point.</li>
</ul>

{{-- ════════════════════════════════════════════════════════════════════════
     COMMISSIONING CRITERIA
     ════════════════════════════════════════════════════════════════════════ --}}
@if(! empty($commCriteria))
<div class="sec-heading page-break">Commissioning Criteria</div>
<p class="body-para">The following criteria must be verified and signed off before the installation is considered complete and handed over to the client.</p>
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr style="background-color:#1B7A7A; color:#ffffff;">
            <th style="width:18%; color:#fff;">System</th>
            <th style="color:#fff;">Criterion</th>
            <th style="width:22%; color:#fff;">Verification Method</th>
            <th style="width:20%; color:#fff;">Pass Condition</th>
            <th style="width:60pt; color:#fff; text-align:center;">Result</th>
        </tr>
    </thead>
    <tbody>
    @foreach($commCriteria as $ccRow)
    @php $ccRow = is_array($ccRow) ? $ccRow : []; @endphp
    <tr>
        <td><strong>{{ $ccRow['system'] ?? '' }}</strong></td>
        <td>{{ $ccRow['criterion'] ?? '' }}</td>
        <td>{{ $ccRow['verification_method'] ?? '' }}</td>
        <td>{{ $ccRow['pass_condition'] ?? '' }}</td>
        <td style="text-align:center; font-size:8.5pt;">Pass &#9744;&nbsp; Fail &#9744;</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SECTION 8 — DOCUMENT SIGN-OFF
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">8. &nbsp;Document Sign-Off</div>

<p class="body-para">
    This Risk Assessment and Method Statement has been prepared by {{ $company }} for the project detailed herein.
    It must be read, understood and agreed to by all personnel carrying out the works. By signing below, the parties
    confirm that the works will be carried out in accordance with this document.
</p>

<table class="signoff-table">
    <thead>
        <tr>
            <th style="width:15%; background-color:#F4FBFB; color:#1A1A2E;">&nbsp;</th>
            <th>{{ $company }}</th>
            <th>Client Acceptance</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="row-lbl">Name</td>
            <td>{{ $docAuthor ?: '—' }}</td>
            <td style="color:#999; font-style:italic;">________________</td>
        </tr>
        <tr>
            <td class="row-lbl">Position</td>
            <td>Project Manager</td>
            <td style="color:#999; font-style:italic;">________________</td>
        </tr>
        <tr>
            <td class="row-lbl">Date</td>
            <td>{{ $docDate }}</td>
            <td style="color:#999; font-style:italic;">____/____/________</td>
        </tr>
        <tr class="sig-row">
            <td class="row-lbl">Signature</td>
            <td style="height:40pt;">&nbsp;</td>
            <td style="height:40pt; color:#999; font-style:italic;">________________</td>
        </tr>
    </tbody>
</table>

<div style="margin-top: 14pt; border-top: 0.5pt solid #CCCCCC; padding-top: 5pt; font-size: 8pt; color: #555; text-align: center;">
    {{ $company }} &nbsp;|&nbsp; {{ $address }} &nbsp;|&nbsp; {{ $phone }} &nbsp;|&nbsp; {{ $email }}
</div>

{{-- ════════════════════════════════════════════════════════════════════════
     APPENDIX A — TOOLBOX TALK RECORD
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading page-break">Appendix A &mdash; Toolbox Talk Record</div>
<p class="body-para">
    Prior to commencement of works, the lead engineer or Project Manager must conduct a
    toolbox talk covering the key risks, controls, and procedures in this RAMS document.
    All attending personnel must sign below to confirm attendance and understanding.
</p>
<p class="body-para" style="margin-bottom:8pt;">
    <strong>Date of toolbox talk:</strong> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <strong>Conducted by:</strong> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <strong>Location:</strong>
</p>
<table class="signoff-table">
    <thead>
        <tr>
            <th style="width:30%;">Name</th>
            <th style="width:25%;">Company</th>
            <th style="width:20%;">Date</th>
            <th>Signature</th>
        </tr>
    </thead>
    <tbody>
    @for($i = 0; $i < 5; $i++)
        <tr class="sig-row">
            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        </tr>
    @endfor
    </tbody>
</table>

</div>{{-- /.page-wrap --}}
</body>
</html>
