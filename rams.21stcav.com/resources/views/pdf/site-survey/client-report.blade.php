<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Survey — {{ $survey->project_name }}</title>
    {{--
        Tier 1 client-facing site survey report. Quick task 260508-v7g.

        Distinct from pdf/site-survey/summary.blade.php (engineer-internal,
        technical jargon, all checklist artifacts). This Blade produces the
        polished client-facing artifact matching the Mini O&M visual
        language (260506-qa9) — same teal #1B7A7A headings, brand orange
        #C07000 accents, .cover-accent-bar cover-page chrome (D-LOCK-3).

        Variables:
          $survey — eager-loaded with rooms.photos, variations.photo, project
    --}}
    <style>
        :root {
            --teal: #1B7A7A;
            --orange: #C07000;
            --grey: #555;
            --grey-light: #f6f6f6;
            --grey-border: #ddd;
        }

        @page { size: A4; margin: 18mm 14mm; }

        body {
            font-family: 'Figtree', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #222;
            line-height: 1.4;
            margin: 0;
        }

        h1 {
            color: var(--teal);
            font-size: 22pt;
            margin: 0 0 4pt;
            font-weight: 700;
        }
        h2 {
            color: var(--teal);
            font-size: 14pt;
            border-bottom: 1.5pt solid var(--teal);
            padding-bottom: 3pt;
            margin: 18pt 0 8pt;
            font-weight: 700;
        }
        h3 {
            color: var(--orange);
            font-size: 11pt;
            margin: 12pt 0 6pt;
            font-weight: 700;
        }

        p { margin: 0 0 6pt; }

        .cover-accent-bar {
            height: 6pt;
            background: var(--teal);
            margin: 6pt 0 18pt;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8pt;
        }
        .meta-table td {
            padding: 4pt 6pt;
            border-bottom: 0.5pt solid var(--grey-border);
            vertical-align: top;
        }
        .meta-table td:first-child {
            font-weight: 600;
            color: var(--grey);
            width: 30%;
        }

        .photo-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6pt;
            margin: 6pt 0 8pt;
        }
        .photo-grid .photo-cell {
            width: 32%;
            box-sizing: border-box;
        }
        .photo-grid img {
            width: 100%;
            height: auto;
            border: 0.5pt solid #ccc;
            display: block;
        }
        .photo-caption {
            font-size: 8pt;
            color: var(--grey);
            margin-top: 2pt;
        }

        .room-block {
            page-break-inside: avoid;
            margin-bottom: 16pt;
            padding-bottom: 8pt;
            border-bottom: 0.5pt solid var(--grey-border);
        }

        /* Office-note callout — orange accent left-bar to mirror brand chrome */
        .office-note-callout {
            background: #FFFBEB;
            border-left: 3pt solid var(--orange);
            padding: 6pt 10pt;
            margin: 8pt 0;
        }
        .office-note-callout strong {
            color: var(--orange);
            display: block;
            margin-bottom: 2pt;
        }

        .variations-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-top: 6pt;
        }
        .variations-table th {
            background: var(--grey-light);
            text-align: left;
            padding: 4pt 6pt;
            border-bottom: 1pt solid #ccc;
            color: var(--grey);
            font-weight: 700;
        }
        .variations-table td {
            padding: 4pt 6pt;
            border-bottom: 0.5pt solid #eee;
            vertical-align: top;
        }

        /* Status pill colour scheme — matches the edit-page table chrome */
        .status-pill {
            display: inline-block;
            padding: 1pt 6pt;
            border-radius: 8pt;
            font-size: 8pt;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .03em;
        }
        .status-proposed { background: #FEF3C7; color: #92400E; }
        .status-quoted   { background: #DBEAFE; color: #1E40AF; }
        .status-approved { background: #D1FAE5; color: #065F46; }
        .status-rejected { background: #FEE2E2; color: #991B1B; }

        .footer-note {
            font-size: 9pt;
            color: var(--grey);
            margin-top: 8pt;
            font-style: italic;
        }
    </style>
</head>
<body>

{{-- ═══════════════════════════════════════════════════════════════════════
     COVER — project meta + survey-level office review note (if any)
     ═══════════════════════════════════════════════════════════════════════ --}}
<h1>Site Survey Report</h1>
<div class="cover-accent-bar"></div>

<table class="meta-table">
    <tr><td>Project</td><td>{{ $survey->project_name }}</td></tr>
    @if (filled($survey->project_ref))
        <tr><td>Reference</td><td>{{ $survey->project_ref }}</td></tr>
    @endif
    @if (filled($survey->client_name))
        <tr><td>Client</td><td>{{ $survey->client_name }}</td></tr>
    @endif
    @if (filled($survey->site_address))
        <tr><td>Site address</td><td>{{ $survey->site_address }}</td></tr>
    @endif
    @if ($survey->survey_date)
        <tr><td>Survey date</td><td>{{ $survey->survey_date->format('d M Y') }}</td></tr>
    @endif
    @if (filled($survey->surveyor_name))
        <tr><td>Surveyor</td><td>{{ $survey->surveyor_name }}</td></tr>
    @endif
    <tr><td>Report generated</td><td>{{ now()->format('d M Y H:i') }}</td></tr>
</table>

@if (filled($survey->office_review_notes))
    <div class="office-note-callout">
        <strong>Office review summary</strong>
        {!! nl2br(e($survey->office_review_notes)) !!}
    </div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     PER-ROOM BLOCKS — overview + photos + office notes + per-room variations
     ═══════════════════════════════════════════════════════════════════════ --}}
@if ($survey->rooms->isNotEmpty())
    <h2>Rooms</h2>
    @foreach ($survey->rooms as $room)
        <div class="room-block">
            <h3>{{ $room->room_name }}{{ $room->room_ref ? ' (' . $room->room_ref . ')' : '' }}</h3>

            @if (filled($room->floor) || filled($room->space_type))
                <p style="font-size: 9pt; color: var(--grey); margin-top: -4pt;">
                    @if (filled($room->floor)){{ $room->floor }}@endif
                    @if (filled($room->floor) && filled($room->space_type)) &mdash; @endif
                    @if (filled($room->space_type)){{ str_replace('_', ' ', ucfirst($room->space_type)) }}@endif
                </p>
            @endif

            {{-- AV requirements / overview --}}
            @if (filled($room->av_requirements))
                <p><strong>AV requirements:</strong></p>
                <p>{!! nl2br(e($room->av_requirements)) !!}</p>
            @endif

            {{-- Office notes for this room (D-LOCK-2 per-room field) --}}
            @if (filled($room->office_notes))
                <div class="office-note-callout">
                    <strong>Office notes</strong>
                    {!! nl2br(e($room->office_notes)) !!}
                </div>
            @endif

            {{-- Photos — base64-inlined as data URIs.
                 Browsershot 5.x rejects any HTML containing `file://` as a
                 security measure (HtmlIsNotAllowedToContainFile), so we
                 must embed image bytes directly. is_file() guard prevents
                 broken-image markers when a photo file is missing on disk. --}}
            @if ($room->photos->isNotEmpty())
                <p><strong>Site photos:</strong></p>
                <div class="photo-grid">
                    @foreach ($room->photos as $photo)
                        @php
                            $abs = \Illuminate\Support\Facades\Storage::disk('local')->path($photo->storagePath());
                            // Resize-then-embed via PdfImageEmbedder. Phone photos
                            // are 4–12MB at full resolution; resized to 1600px JPEG@80
                            // they're typically 200–400KB. Critical for keeping the
                            // PDF under sane size and the render under timeout.
                            $dataUri = \App\Support\PdfImageEmbedder::dataUri($abs);
                        @endphp
                        @if ($dataUri !== '')
                            <div class="photo-cell">
                                <img src="{{ $dataUri }}" alt="{{ $photo->caption ?? $photo->original_name }}">
                                @if (filled($photo->caption))
                                    <div class="photo-caption">{{ $photo->caption }}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Per-room variations (filtered by room_name) --}}
            @php
                $roomVariations = $survey->variations->filter(fn ($v) => (string) $v->room_name === (string) $room->room_name);
            @endphp
            @if ($roomVariations->isNotEmpty())
                <p><strong>Variations for this room:</strong></p>
                <table class="variations-table">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Type</th>
                            <th>Description</th>
                            <th style="width: 8%; text-align: right;">Qty</th>
                            <th style="width: 14%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roomVariations as $var)
                            <tr>
                                <td>{{ str_replace('_', ' ', ucfirst($var->type)) }}</td>
                                <td>{{ $var->description }}</td>
                                <td style="text-align: right;">{{ $var->qty }}</td>
                                <td><span class="status-pill status-{{ $var->status }}">{{ $var->status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     SURVEY-WIDE VARIATIONS — those without a specific room_name
     ═══════════════════════════════════════════════════════════════════════ --}}
@php
    $surveyWideVariations = $survey->variations->filter(fn ($v) => empty($v->room_name));
@endphp
@if ($survey->variations->isNotEmpty())
    <h2>Variations Summary</h2>

    @if ($surveyWideVariations->isNotEmpty())
        <h3>Survey-wide</h3>
        <table class="variations-table">
            <thead>
                <tr>
                    <th style="width: 22%;">Type</th>
                    <th>Description</th>
                    <th style="width: 8%; text-align: right;">Qty</th>
                    <th style="width: 14%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($surveyWideVariations as $var)
                    <tr>
                        <td>{{ str_replace('_', ' ', ucfirst($var->type)) }}</td>
                        <td>{{ $var->description }}</td>
                        <td style="text-align: right;">{{ $var->qty }}</td>
                        <td><span class="status-pill status-{{ $var->status }}">{{ $var->status }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer-note">
        Variations are scope changes captured during or after the survey. Status reflects the
        current commercial conversation — please contact your project manager for the latest position.
    </p>
@endif

</body>
</html>
