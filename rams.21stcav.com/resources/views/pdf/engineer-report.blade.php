<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Engineer Report — {{ $worksheet->project_name }}</title>
    {{--
        Engineer Report PDF (quick task 260602-rcd).

        Print-optimised version of the on-site engineer activity surfaced
        on /worksheets/{worksheet}. Driven by the same EngineerActivityService
        context as the View page — single source of truth.

        Visual chrome mirrors pdf/site-survey/client-report.blade.php for
        per-room layout (room-block + photo-grid) and pdf/mini-om.blade.php
        for the 21CAV teal + gold cover accent.

        Browsershot 5.x rejects `file://` URIs, so all photos MUST be inlined
        as base64 data URIs via App\Support\PdfImageEmbedder::dataUri().
    --}}
    <style>
        :root {
            --teal: #1B7A7A;
            --gold: #C07000;
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
            color: var(--gold);
            font-size: 11pt;
            margin: 12pt 0 6pt;
            font-weight: 700;
        }
        h4 {
            color: var(--teal);
            font-size: 9.5pt;
            margin: 8pt 0 4pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        p { margin: 0 0 6pt; }

        .cover-accent-bar {
            height: 6pt;
            background: linear-gradient(to right, var(--teal), var(--gold));
            margin: 6pt 0 18pt;
            border-radius: 1pt;
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

        /* Outstanding-items callout — gold left bar so it stands out on page 1. */
        .outstanding-callout {
            background: #FFFBEB;
            border-left: 3pt solid var(--gold);
            padding: 8pt 12pt;
            margin: 10pt 0 14pt;
        }
        .outstanding-callout strong {
            color: var(--gold);
            display: block;
            margin-bottom: 4pt;
            font-size: 10.5pt;
        }
        .outstanding-callout ul {
            margin: 0;
            padding-left: 14pt;
        }
        .outstanding-callout li {
            margin-bottom: 2pt;
            font-size: 9.5pt;
        }

        .summary-row {
            display: flex;
            gap: 8pt;
            margin: 6pt 0 12pt;
            flex-wrap: wrap;
        }
        .summary-pill {
            background: var(--grey-light);
            border: 0.5pt solid var(--grey-border);
            border-radius: 12pt;
            padding: 3pt 10pt;
            font-size: 9pt;
            color: var(--grey);
        }
        .summary-pill strong {
            color: var(--teal);
        }

        .room-block {
            page-break-inside: avoid;
            margin-bottom: 16pt;
            padding-bottom: 8pt;
            border-bottom: 0.5pt solid var(--grey-border);
        }
        .room-block:last-child {
            border-bottom: none;
        }

        .room-meta {
            font-size: 9pt;
            color: var(--grey);
            margin: -4pt 0 8pt;
        }
        .room-meta .pill {
            display: inline-block;
            padding: 1pt 8pt;
            border-radius: 10pt;
            background: #E8F5E9;
            color: #2E7D32;
            border: 0.5pt solid #A5D6A7;
            font-weight: 600;
            font-size: 8pt;
            margin-right: 4pt;
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

        .label-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-top: 4pt;
        }
        .label-table th {
            background: var(--grey-light);
            text-align: left;
            padding: 3pt 6pt;
            border-bottom: 1pt solid #ccc;
            color: var(--grey);
            font-weight: 700;
        }
        .label-table td {
            padding: 3pt 6pt;
            border-bottom: 0.5pt solid #eee;
            vertical-align: top;
        }

        .signoff-block {
            page-break-inside: avoid;
            margin: 8pt 0 14pt;
            padding: 8pt 10pt;
            background: #F4FBFB;
            border-left: 3pt solid var(--teal);
        }
        .signoff-block .so-meta {
            font-size: 9pt;
            color: var(--grey);
            margin-bottom: 4pt;
        }
        .signoff-block .so-comments {
            font-size: 9pt;
            color: #1A1A2E;
            margin-top: 4pt;
            white-space: pre-wrap;
        }
        .signoff-block img.so-sig {
            display: block;
            max-width: 200pt;
            max-height: 60pt;
            margin-top: 4pt;
            border: 0.5pt solid var(--grey-border);
            background: #FFFFFF;
        }

        .footer-note {
            font-size: 9pt;
            color: var(--grey);
            margin-top: 12pt;
            font-style: italic;
        }

        .empty-state {
            color: var(--grey);
            font-style: italic;
            font-size: 9.5pt;
        }
    </style>
</head>
<body>

{{-- ═══════════════════════════════════════════════════════════════════════
     COVER — project meta + summary pills + outstanding-items callout
     ═══════════════════════════════════════════════════════════════════════ --}}
<h1>Engineer Report</h1>
<div class="cover-accent-bar"></div>

<table class="meta-table">
    <tr><td>Project</td><td>{{ $worksheet->project_name }}</td></tr>
    @if(filled($worksheet->project_ref))
        <tr><td>Reference</td><td>{{ $worksheet->project_ref }}</td></tr>
    @endif
    @if(filled($worksheet->client_name))
        <tr><td>Client</td><td>{{ $worksheet->client_name }}</td></tr>
    @endif
    @if(filled($worksheet->site_address))
        <tr><td>Site address</td><td>{{ $worksheet->site_address }}</td></tr>
    @endif
    <tr><td>Worksheet status</td><td>{{ $worksheet->statusLabel() }}</td></tr>
    <tr><td>Report generated</td><td>{{ $generatedAt->format('d M Y H:i') }}</td></tr>
</table>

<div class="summary-row">
    <span class="summary-pill"><strong>{{ $context['summary']['photo_count'] }}</strong> completed-work photos</span>
    <span class="summary-pill"><strong>{{ $context['summary']['label_count'] }}</strong> equipment labels</span>
    <span class="summary-pill"><strong>{{ $context['summary']['signoff_count'] }}</strong> sign-offs</span>
    <span class="summary-pill"><strong>{{ count($context['outstanding_items']) }}</strong> outstanding items</span>
</div>

@if(! empty($context['outstanding_items']))
    <div class="outstanding-callout">
        <strong>Outstanding items ({{ count($context['outstanding_items']) }})</strong>
        <ul>
            @foreach($context['outstanding_items'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     PER-ROOM BLOCKS — completed-work photos + equipment-label captures
     ═══════════════════════════════════════════════════════════════════════ --}}
@if(! empty($context['rooms']))
    <h2>Rooms</h2>
    @foreach($context['rooms'] as $room)
        @php
            $completedPhotos = $room['completed_photos'] ?? collect();
            $labelPhotos     = $room['label_photos'] ?? collect();
            $hasAnyRoomContent = $completedPhotos->isNotEmpty()
                || $labelPhotos->isNotEmpty()
                || ! empty($room['survey_reviewed_at'])
                || ! empty($room['room_completed_at']);
        @endphp

        @if($hasAnyRoomContent)
            <div class="room-block">
                <h3>{{ $room['name'] }}</h3>

                @if(! empty($room['survey_reviewed_at']) || ! empty($room['room_completed_at']))
                    <p class="room-meta">
                        @if(! empty($room['survey_reviewed_at']))
                            <span class="pill">Survey reviewed</span>
                            {{ \Illuminate\Support\Carbon::parse($room['survey_reviewed_at'])->format('d M Y H:i') }}
                        @endif
                        @if(! empty($room['room_completed_at']))
                            &nbsp;&nbsp;<span class="pill">Room complete</span>
                            {{ \Illuminate\Support\Carbon::parse($room['room_completed_at'])->format('d M Y H:i') }}
                        @endif
                    </p>
                @endif

                {{-- Completed-work photos ------------------------------------- --}}
                @if($completedPhotos->isNotEmpty())
                    <h4>Completed-work photos ({{ $completedPhotos->count() }})</h4>
                    <div class="photo-grid">
                        @foreach($completedPhotos as $photo)
                            @php
                                // WorksheetPhoto::absolutePath() resolves to the local
                                // disk path under storage/app/. PdfImageEmbedder resizes
                                // and base64-inlines so Browsershot doesn't trip on
                                // file:// (HtmlIsNotAllowedToContainFile).
                                $abs     = $photo->absolutePath();
                                $dataUri = \App\Support\PdfImageEmbedder::dataUri($abs);
                            @endphp
                            @if($dataUri !== '')
                                <div class="photo-cell">
                                    <img src="{{ $dataUri }}" alt="{{ $photo->caption ?: $photo->original_name }}">
                                    @if(filled($photo->caption))
                                        <div class="photo-caption">{{ $photo->caption }}</div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Equipment-label photos ------------------------------------ --}}
                @if($labelPhotos->isNotEmpty())
                    <h4>Equipment labels captured ({{ $labelPhotos->count() }})</h4>
                    <div class="photo-grid">
                        @foreach($labelPhotos as $lp)
                            @php
                                $abs     = \Illuminate\Support\Facades\Storage::disk('local')->path($lp->photo_path);
                                $dataUri = \App\Support\PdfImageEmbedder::dataUri($abs);
                                $ai      = $lp->ai_extracted ?? [];
                                $caption = $lp->device?->description
                                    ?: ($ai['part_number'] ?? 'Equipment label');
                            @endphp
                            @if($dataUri !== '')
                                <div class="photo-cell">
                                    <img src="{{ $dataUri }}" alt="{{ $caption }}">
                                    <div class="photo-caption">{{ $caption }}</div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <table class="label-table">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>Part</th>
                                <th>Serial</th>
                                <th>MAC</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($labelPhotos as $lp)
                                @php $ai = $lp->ai_extracted ?? []; @endphp
                                <tr>
                                    <td>{{ $lp->device?->description ?? '—' }}</td>
                                    <td>{{ $lp->device?->part_no ?? ($ai['part_number'] ?? '—') }}</td>
                                    <td>{{ $lp->device?->serial_number ?? ($ai['serial_number'] ?? '—') }}</td>
                                    <td>{{ $lp->device?->mac_address ?? ($ai['mac_address'] ?? '—') }}</td>
                                    <td>{{ $lp->confirmed ? '✓ Confirmed' : 'Awaiting review' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endforeach
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     SIGN-OFFS — append-only log, newest first
     ═══════════════════════════════════════════════════════════════════════ --}}
@if($context['signoffs']->isNotEmpty())
    <h2>Client Sign-Offs ({{ $context['signoffs']->count() }})</h2>

    @foreach($context['signoffs'] as $signoff)
        <div class="signoff-block">
            <div class="so-meta">
                <strong>{{ $signoff->client_name }}</strong>
                — {{ $signoff->signed_at->format('d M Y H:i') }}
                @if($signoff->signed_with_comments)
                    &nbsp;·&nbsp; <em style="color: var(--gold);">Signed with comments</em>
                @endif
            </div>
            @if(filled($signoff->comments))
                <div class="so-comments">{{ $signoff->comments }}</div>
            @endif
            @if(filled($signoff->signature_png_base64))
                <img class="so-sig" src="{{ $signoff->signature_data_uri }}" alt="Signature">
            @endif
        </div>
    @endforeach
@endif

<p class="footer-note">
    Engineer Report generated from on-site activity captured against worksheet #{{ $worksheet->id }}
    — content mirrors the live View page at the moment of render.
</p>

</body>
</html>
