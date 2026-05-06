<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Mini O&amp;M Manual — {{ $project['name'] ?: 'Project' }}</title>
{{--
    260506-qa9 Mini O&M — Tier 1 client-facing visual chrome.
    Matches resources/views/pdf/om-manual.blade.php (commit 3c9d179
    feat(om-tier1)) so a client receiving both PDFs reads them as one
    family of documents (D-LOCK-4): same Poppins body, Verdana headings,
    #01889F teal + #D4AF37 gold accent, .cover-accent-bar, .section-title
    chrome, .cover-table, .data-table conventions.

    Render path: MiniOmController::generate -> PdfRenderService::fromBlade
    -> Browsershot (Chromium headless) on live. Browsershot reads images
    via file:// URIs so every <img src> in this file is prefixed with
    "file://" against absolute paths the service supplies.

    Browsershot puppeteer is NOT provisioned on Windows dev — local UAT
    falls back to direct `view('pdf.mini-om', $data)->render()` HTML
    inspection. Print-layout verification happens on live UAT.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* ── Base — 21CAV brand: Poppins body, Verdana headings ───────────── */
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
    @page { size: A4 portrait; }
    .page-wrap { margin: 0 18mm; }

    /* ── Cover page (mirrors om-manual.blade.php cover) ───────────────── */
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
    .cover-hero {
        width: 100%;
        max-height: 320pt;
        object-fit: cover;
        border-radius: 4pt;
        margin-bottom: 20pt;
    }
    .cover-hero-fallback {
        width: 100%;
        height: 220pt;
        background: linear-gradient(to right, #01889F, #D4AF37);
        border-radius: 4pt;
        margin-bottom: 20pt;
        display: table;
        text-align: center;
    }
    .cover-hero-fallback span {
        display: table-cell;
        vertical-align: middle;
        color: #FFFFFF;
        font-family: Verdana, "DejaVu Sans", Arial, sans-serif;
        font-style: italic;
        font-size: 12pt;
        letter-spacing: 0.5pt;
    }

    /* ── Cover info table ─────────────────────────────────────────────── */
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
        width: 32%;
    }
    .cover-table .val {
        color: #1A1A2E;
        background-color: #FFFFFF;
    }

    /* ── Section heading (matches om-manual .section-title) ───────────── */
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
        font-size: 16pt;
        font-weight: 700;
        color: #01889F;
        margin: 0 0 4pt;
        page-break-after: avoid;
    }
    .room-scope {
        color: #555;
        font-size: 9.5pt;
        line-height: 1.45;
        margin-bottom: 10pt;
    }

    /* ── Pills ────────────────────────────────────────────────────────── */
    .pill {
        display: inline-block;
        padding: 2pt 8pt;
        border-radius: 10pt;
        font-size: 8pt;
        font-weight: 700;
        letter-spacing: 0.3pt;
    }
    .pill-green  { background: #E8F5E9; color: #2E7D32; border: 0.5pt solid #A5D6A7; }
    .pill-amber  { background: #FFF3CD; color: #7A4D00; border: 0.5pt solid #F0CB6E; }

    /* ── Asset tables ─────────────────────────────────────────────────── */
    .asset-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
        margin: 6pt 0 10pt;
        border: 0.5pt solid #CCCCCC;
    }
    .asset-table th {
        background: #F4FBFB;
        color: #01889F;
        padding: 4pt 7pt;
        text-align: left;
        font-size: 8pt;
        font-weight: 700;
        border-bottom: 0.5pt solid #01889F;
        letter-spacing: 0.3pt;
    }
    .asset-table td {
        padding: 4pt 7pt;
        border-bottom: 0.5pt solid #E0E0E0;
        vertical-align: top;
    }
    .asset-table tr:nth-child(even) td { background: #FAFAFA; }
    .asset-empty {
        color: #888;
        font-style: italic;
        font-size: 9pt;
        margin: 4pt 0 10pt;
    }

    /* ── Photo blocks (D-LOCK-3 + D-LOCK-6) ───────────────────────────── */
    .photo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6pt;
        margin: 6pt 0;
    }
    .photo-grid img {
        width: 100%;
        max-height: 180pt;
        object-fit: cover;
        border-radius: 3pt;
        border: 0.5pt solid #DDD;
    }
    .photo-strip {
        display: block;
        margin: 4pt 0 8pt;
        font-size: 0; /* removes gap between inline-block thumbs */
    }
    .photo-strip img {
        display: inline-block;
        width: 80pt;
        height: 60pt;
        object-fit: cover;
        margin: 0 4pt 4pt 0;
        opacity: 0.85;
        border-radius: 2pt;
        border: 0.5pt solid #DDD;
    }
    .photo-placeholder {
        border: 1pt dashed #CCCCCC;
        padding: 24pt;
        text-align: center;
        color: #888;
        font-style: italic;
        font-size: 9.5pt;
        margin: 6pt 0 10pt;
        border-radius: 3pt;
    }

    /* ── Sign-off line ────────────────────────────────────────────────── */
    .signoff-line {
        font-size: 9pt;
        color: #1A1A2E;
        background: #F4FBFB;
        border-left: 3pt solid #01889F;
        padding: 6pt 10pt;
        margin: 8pt 0 0;
    }

    /* ── Register table (asset register page) ─────────────────────────── */
    .register-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
        margin: 6pt 0 14pt;
        border: 0.5pt solid #CCCCCC;
    }
    .register-table th {
        background: #01889F;
        color: #FFFFFF;
        padding: 5pt 7pt;
        text-align: left;
        font-size: 8pt;
        font-weight: 700;
        border: 0.5pt solid #01889F;
        letter-spacing: 0.3pt;
    }
    .register-table td {
        padding: 4pt 7pt;
        border-bottom: 0.5pt solid #E0E0E0;
        vertical-align: top;
    }
    .register-table tr:nth-child(even) td { background: #FAFAFA; }

    /* ── Support block ────────────────────────────────────────────────── */
    .support-block {
        margin-bottom: 14pt;
    }
    .support-block h3 {
        font-size: 11pt;
        font-weight: 700;
        color: #01889F;
        margin-bottom: 4pt;
        border-bottom: 0.5pt solid #01889F;
        padding-bottom: 2pt;
    }
    .support-block p {
        font-size: 9.5pt;
        line-height: 1.5;
        color: #1A1A2E;
    }

    /* ── Per-room page break ──────────────────────────────────────────── */
    .room-page  { page-break-before: always; }
    .pb         { page-break-before: always; }
    .avoid-break{ page-break-inside: avoid; }
</style>
</head>
<body>

@php
    // Locked decisions surfaced as helper closures for clarity in the template.
    $em = function ($v) { return ($v === null || $v === '') ? '—' : $v; };
@endphp

{{-- ============================================================
     1. COVER PAGE
     ============================================================ --}}
<div class="cover">
    <div class="cover-company-name">{{ $company['name'] ?: '21st Century AV Ltd' }}</div>
    <div class="cover-tagline">Mini Operation &amp; Maintenance Manual</div>
    <div class="cover-doc-title">{{ $project['name'] ?: 'Project' }}</div>
    <div class="cover-doc-subtitle">{{ $project['ref'] ?: '—' }}</div>
    <div class="cover-accent-bar"></div>

    @if (! empty($cover['hero_photo_abs_path']))
        <img class="cover-hero" src="file://{{ $cover['hero_photo_abs_path'] }}" alt="">
    @else
        <div class="cover-hero-fallback"><span>Photos to be captured during install</span></div>
    @endif

    <table class="cover-table">
        <tr><td class="lbl">Client</td>            <td class="val">{{ $em($project['client']) }}</td></tr>
        <tr><td class="lbl">Site Address</td>      <td class="val">{{ $em($project['site']) }}</td></tr>
        <tr><td class="lbl">Project Reference</td> <td class="val">{{ $em($project['ref']) }}</td></tr>
        <tr><td class="lbl">Lead Engineer</td>     <td class="val">{{ $em($project['lead_engineer']) }}</td></tr>
        <tr><td class="lbl">Install Started</td>   <td class="val">{{ $project['install_started'] ? $project['install_started']->format('j F Y') : '—' }}</td></tr>
        <tr><td class="lbl">Handover Date</td>     <td class="val">{{ $project['handover_date'] ? $project['handover_date']->format('j F Y') : '—' }}</td></tr>
        <tr><td class="lbl">Generated</td>         <td class="val">{{ $project['generated_at']->format('j F Y') }}</td></tr>
    </table>
</div>

{{-- ============================================================
     2. PROJECT SUMMARY
     ============================================================ --}}
<div class="page-wrap">
    <div class="section-title">Works Overview</div>
    @if (trim($project['works_description']) !== '')
        <p style="font-size: 10pt; line-height: 1.5; margin-bottom: 8pt;">
            {!! nl2br(e($project['works_description'])) !!}
        </p>
    @else
        <p class="asset-empty">Works overview not recorded.</p>
    @endif

    @if ($project['is_signed'])
        <p style="margin-top: 6pt;"><span class="pill pill-green">Worksheet signed</span></p>
    @else
        <p style="margin-top: 6pt;"><span class="pill pill-amber">Pending sign-off</span></p>
    @endif
</div>

{{-- ============================================================
     3. PER-ROOM PAGES (D-LOCK-3 — all rooms, no skipping)
     ============================================================ --}}
@forelse ($rooms as $room)
    @php
        $hasAfter  = ! empty($room['photos']['after']);
        $hasBefore = ! empty($room['photos']['before']);
        $afterCapped = array_slice($room['photos']['after']  ?? [], 0, 6);
        $beforeCapped = array_slice($room['photos']['before'] ?? [], 0, 6);
    @endphp

    <div class="room-page page-wrap">
        <h2 class="room-title">{{ $room['name'] }}</h2>

        @if (trim($room['scope_sentence']) !== '')
            <p class="room-scope">{!! nl2br(e($room['scope_sentence'])) !!}</p>
        @endif

        {{-- Asset list inline (D-LOCK-2 — confirmed first) --}}
        @if (! empty($room['assets']['confirmed']))
            <div class="subsection-title">Confirmed Equipment</div>
            <table class="asset-table">
                <thead>
                    <tr>
                        <th>Manufacturer</th><th>Model</th><th>Part</th><th>Serial</th><th>MAC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($room['assets']['confirmed'] as $a)
                        <tr>
                            <td>{{ $em($a['manufacturer']) }}</td>
                            <td>{{ $em($a['model']) }}</td>
                            <td>{{ $em($a['part_number']) }}</td>
                            <td>{{ $em($a['serial']) }}</td>
                            <td>{{ $em($a['mac']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (! empty($room['assets']['quoted']))
            <div class="subsection-title">Also installed (quoted)</div>
            <table class="asset-table">
                <thead>
                    <tr>
                        <th>Manufacturer</th><th>Model</th><th>Part</th><th>Description</th><th style="text-align: right;">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($room['assets']['quoted'] as $q)
                        <tr>
                            <td>{{ $em($q['manufacturer']) }}</td>
                            <td>{{ $em($q['model']) }}</td>
                            <td>{{ $em($q['part_number']) }}</td>
                            <td>{{ $em($q['description']) }}</td>
                            <td style="text-align: right;">{{ $q['qty'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (empty($room['assets']['confirmed']) && empty($room['assets']['quoted']))
            <p class="asset-empty">Asset list pending.</p>
        @endif

        {{-- Photo block (D-LOCK-3 placeholder + D-LOCK-6 graceful pair) --}}
        @if ($hasAfter)
            <div class="subsection-title">Installed</div>
            <div class="photo-grid">
                @foreach ($afterCapped as $photo)
                    <img src="file://{{ $photo['abs_path'] }}" alt="">
                @endforeach
            </div>

            @if ($hasBefore)
                <div class="subsection-title">Before</div>
                <div class="photo-strip">
                    @foreach ($beforeCapped as $photo)
                        <img src="file://{{ $photo['abs_path'] }}" alt="">
                    @endforeach
                </div>
            @endif
        @elseif ($hasBefore)
            {{-- D-LOCK-6 graceful: only Before photos exist -- render at After-style size --}}
            <div class="subsection-title">Site (pre-install)</div>
            <div class="photo-grid">
                @foreach ($beforeCapped as $photo)
                    <img src="file://{{ $photo['abs_path'] }}" alt="">
                @endforeach
            </div>
        @else
            <div class="photo-placeholder">Photos to be captured during install</div>
        @endif

        {{-- Sign-off line --}}
        @if ($room['signoff']['date'] !== null)
            <p class="signoff-line">
                Installed by <strong>{{ $em($room['signoff']['engineer']) }}</strong>
                &middot; Accepted by <strong>{{ $em($room['signoff']['client']) }}</strong>
                &middot; {{ $room['signoff']['date']->format('j F Y') }}
            </p>
        @else
            <p style="margin-top: 8pt;"><span class="pill pill-amber">Pending sign-off</span></p>
        @endif
    </div>
@empty
    {{-- Empty rooms collection -- D-LOCK-3 still renders cover + register + support --}}
@endforelse

{{-- ============================================================
     4. ASSET REGISTER (single page summary)
     ============================================================ --}}
<div class="pb page-wrap">
    <div class="section-title">Asset Register</div>

    @if (empty($asset_register['confirmed']) && empty($asset_register['also_installed']))
        <p class="asset-empty">No equipment recorded.</p>
    @else
        @if (! empty($asset_register['confirmed']))
            <div class="subsection-title">Confirmed</div>
            <table class="register-table">
                <thead>
                    <tr><th>Room</th><th>Manufacturer</th><th>Model</th><th>Part</th><th>Serial</th><th>MAC</th></tr>
                </thead>
                <tbody>
                    @foreach ($asset_register['confirmed'] as $a)
                        <tr>
                            <td>{{ $em($a['room']) }}</td>
                            <td>{{ $em($a['manufacturer']) }}</td>
                            <td>{{ $em($a['model']) }}</td>
                            <td>{{ $em($a['part_number']) }}</td>
                            <td>{{ $em($a['serial']) }}</td>
                            <td>{{ $em($a['mac']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (! empty($asset_register['also_installed']))
            <div class="subsection-title">Also installed</div>
            <table class="register-table">
                <thead>
                    <tr><th>Room</th><th>Manufacturer</th><th>Model</th><th>Part</th><th>Description</th><th style="text-align: right;">Qty</th></tr>
                </thead>
                <tbody>
                    @foreach ($asset_register['also_installed'] as $a)
                        <tr>
                            <td>{{ $em($a['room']) }}</td>
                            <td>{{ $em($a['manufacturer']) }}</td>
                            <td>{{ $em($a['model']) }}</td>
                            <td>{{ $em($a['part_number']) }}</td>
                            <td>{{ $em($a['description']) }}</td>
                            <td style="text-align: right;">{{ $a['qty'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>

{{-- ============================================================
     5. SUPPORT & WARRANTY
     ============================================================ --}}
<div class="pb page-wrap">
    <div class="section-title">Support &amp; Warranty</div>

    <div class="support-block">
        <h3>How to reach us</h3>
        <p>
            <strong>{{ $company['name'] ?: '21st Century AV Ltd' }}</strong><br>
            Phone: {{ $em($support['support_phone'] ?: $company['phone']) }}<br>
            Email: {{ $em($support['support_email'] ?: $company['email']) }}<br>
            Web: {{ $em($company['website']) }}
            @if (trim($company['address']) !== '')
                <br>{{ $company['address'] }}
            @endif
        </p>
    </div>

    <div class="support-block">
        <h3>Warranty</h3>
        <p>
            @if (trim($support['warranty_terms']) !== '')
                {!! nl2br(e($support['warranty_terms'])) !!}
            @else
                Warranty terms not yet configured.
            @endif
        </p>
    </div>

    <div class="support-block">
        <h3>How to raise a service ticket</h3>
        <p>
            @if (trim($support['service_ticket_instructions']) !== '')
                {!! nl2br(e($support['service_ticket_instructions'])) !!}
            @else
                Service ticket instructions not yet configured.
            @endif
        </p>
    </div>

    <p style="text-align: center; color: #888; font-size: 8pt; margin-top: 30pt;">
        {{ $company['name'] ?: '21st Century AV Ltd' }}
        &middot; Mini O&amp;M generated {{ $project['generated_at']->format('j F Y') }}
    </p>
</div>

</body>
</html>
