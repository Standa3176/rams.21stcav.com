<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Project Drawings — {{ $project->ref ?? $project->name }}</title>
    {{--
        Phase 20 Plan 01 — bound PDF cover sheet (DRAW-21).

        Two pages of content (Browsershot may auto-paginate the register if it
        overflows):
          1. Cover heading: project ref + name + client + drawing count + gen date
          2. Drawing register table

        Renders via PdfRenderService::fromBlade('pdf.drawings.bound-cover', ...)
        which inherits the centralised Browsershot construction (chrome path,
        no-sandbox, --disable-dev-shm-usage). A4 portrait by default
        (PdfRenderService::format('A4') + emulateMedia('print') with no
        @page landscape override here).

        @font-face declaration for Arial / Liberation Sans is left to Plan 20-02
        (CRIT-04 mitigation lands there). For this plan, the font-family chain
        below works on dev (Windows has Arial) and on AlmaLinux production
        (Liberation Sans is installed via the chrome-headless-shell deploy
        runbook).
    --}}
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm;
        }
        body {
            font-family: Arial, Helvetica, 'Liberation Sans', 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
            color: #111;
            font-size: 10.5pt;
        }
        .cover {
            text-align: center;
            margin: 0 0 24mm 0;
            border-bottom: 1px solid #1f2937;
            padding-bottom: 8mm;
        }
        .cover__brand {
            font-size: 12pt;
            color: #007B8A;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
            margin-bottom: 6mm;
        }
        .cover__ref {
            font-size: 22pt;
            font-weight: 700;
            margin-bottom: 4mm;
        }
        .cover__name {
            font-size: 14pt;
            font-weight: 600;
            margin-bottom: 2mm;
        }
        .cover__client {
            font-size: 11pt;
            color: #4b5563;
            margin-bottom: 8mm;
        }
        .cover__meta {
            font-size: 10pt;
            color: #4b5563;
        }
        .cover__meta strong {
            color: #111;
        }
        .register__heading {
            font-size: 13pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0 0 4mm 0;
            color: #111;
        }
        .banner-failed {
            background: #fef2f2;
            border: 1px solid #ef4444;
            color: #991b1b;
            padding: 8px 12px;
            margin-bottom: 6mm;
            border-radius: 4px;
            font-size: 10pt;
            font-weight: 600;
        }
        table.register {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
        }
        table.register th, table.register td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            text-align: left;
            vertical-align: top;
        }
        table.register thead th {
            background: #1f2937;
            color: #fff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 8.5pt;
            letter-spacing: 0.04em;
        }
        table.register tr.failed-row td {
            background: #fef2f2;
            color: #991b1b;
        }
        table.register td.col-sheet {
            font-weight: 600;
            white-space: nowrap;
            width: 16%;
        }
        table.register td.col-revision,
        table.register td.col-status,
        table.register td.col-date,
        table.register td.col-kind {
            white-space: nowrap;
            width: 11%;
        }
        .empty-register {
            color: #6b7280;
            font-style: italic;
            padding: 12mm 0;
            text-align: center;
        }
        .footer-note {
            margin-top: 8mm;
            font-size: 8pt;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="cover">
        <div class="cover__brand">{{ config('rams.company_name', '21st Century AV Ltd') }}</div>
        <div class="cover__ref">{{ $project->ref ?? $project->quote_reference ?? '—' }}</div>
        <div class="cover__name">{{ $project->name }}</div>
        @if (! empty($project->client_name))
            <div class="cover__client">{{ $project->client_name }}</div>
        @endif
        <div class="cover__meta">
            <strong>Drawings:</strong> {{ count($register) }}
            &nbsp;·&nbsp;
            <strong>Generated:</strong> {{ $generated_at->format('j M Y H:i') }} (UK time)
            @if (! empty($failed_drawings))
                &nbsp;·&nbsp;
                <strong style="color:#991b1b;">{{ count($failed_drawings) }} render failure(s)</strong>
            @endif
        </div>
    </div>

    <h2 class="register__heading">Drawing Register</h2>

    @if (! empty($failed_drawings))
        <div class="banner-failed">
            {{ count($failed_drawings) }} drawing(s) failed to render — see register rows highlighted below.
            The bound PDF still includes every successful drawing; regenerate the failed ones from the project page.
        </div>
    @endif

    @if (empty($register))
        <p class="empty-register">No drawings in this project yet — create a schematic or rack from the project drawings page.</p>
    @else
        <table class="register">
            <thead>
                <tr>
                    <th class="col-sheet">Sheet</th>
                    <th>Title</th>
                    <th class="col-kind">Kind</th>
                    <th class="col-revision">Rev</th>
                    <th class="col-status">Status</th>
                    <th class="col-date">Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($register as $row)
                    @php
                        $isFailed = str_starts_with((string) $row['title'], '[render failed]');
                    @endphp
                    <tr class="{{ $isFailed ? 'failed-row' : '' }}">
                        <td class="col-sheet">{{ $row['sheet_number'] }}</td>
                        <td>{{ $row['title'] }}</td>
                        <td class="col-kind">{{ ucfirst((string) $row['kind']) }}</td>
                        <td class="col-revision">{{ $row['revision'] }}</td>
                        <td class="col-status">{{ ucfirst((string) $row['status']) }}</td>
                        <td class="col-date">{{ $row['date'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer-note">
        Bound PDF generated by {{ config('rams.company_name', '21st Century AV Ltd') }}
        — RAMS Platform v1.3 — {{ $generated_at->format('Y-m-d') }}
    </p>
</body>
</html>
