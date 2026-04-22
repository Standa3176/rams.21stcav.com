<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Commissioning Snagging Report — {{ $project->name }}</title>
<style>
@page { size: A4; margin: 18mm 14mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #222; }
h1 { font-size: 18pt; color: #007B8A; margin: 0 0 4pt 0; }
h2.sec-heading { font-size: 13pt; color: #007B8A; border-bottom: 1pt solid #007B8A; padding-bottom: 2pt; margin-top: 14pt; }
table.std-table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
table.std-table td, table.std-table th { padding: 3pt 5pt; border: 0.4pt solid #ccc; font-size: 9pt; }
table.std-table th { background: #f4f4f4; text-align: left; }
.status-pass { color: #1d7c3c; font-weight: bold; }
.status-fail { color: #b02a37; font-weight: bold; }
.status-na { color: #666; }
.status-pending { color: #b68600; }
.signature-box { margin-top: 10pt; padding: 8pt; border: 0.4pt solid #999; text-align: center; }
.signature-box img { max-width: 300px; max-height: 150px; }
.certification-text { font-size: 9pt; color: #333; margin-top: 8pt; padding: 6pt; background: #f9f9f9; border-left: 2pt solid #007B8A; }
.thumb { max-width: 150px; max-height: 150px; border: 0.4pt solid #ddd; }
.evidence-missing { color: #b68600; font-style: italic; font-size: 9pt; }
.empty-state { padding: 12pt; border: 0.5pt dashed #999; text-align: center; color: #666; font-size: 10pt; }
.lbl { background: #fafafa; width: 30%; }
</style>
</head>
<body>

<h1>Commissioning Snagging Report</h1>
<p>
    <strong>Project:</strong> {{ $project->name }}<br>
    <strong>Client:</strong> {{ $project->client_name }}<br>
    <strong>Site:</strong> {{ $project->site_address }}<br>
    <strong>Programme #{{ $programme->id }}</strong> · Generated {{ now()->setTimezone('Europe/London')->format('d F Y H:i') }}
</p>

@if ($items->count() === 0)
    <section>
        <h2 class="sec-heading">Commissioning Items</h2>
        <p class="empty-state">
            No commissioning items applicable for this programme — no equipment matched an AVIXA commissioning category.
        </p>
    </section>
@else
    @foreach ($rooms as $roomName => $roomItems)
        <section>
            <h2 class="sec-heading">{{ $roomName }}</h2>
            <table class="std-table">
                <thead>
                    <tr>
                        <th style="width:35%">Equipment</th>
                        <th style="width:20%">Category</th>
                        <th style="width:10%">Status</th>
                        <th style="width:35%">Notes</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($roomItems as $item)
                    <tr>
                        <td>{{ $item->equipment_name }}</td>
                        <td>{{ $categoryLabels[$item->category] ?? $item->category }}</td>
                        <td class="status-{{ $item->status }}">{{ strtoupper($item->status) }}</td>
                        <td>{{ $item->notes }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endforeach

    @if ($fails->count() > 0)
        <section>
            <h2 class="sec-heading">To Be Resolved ({{ $fails->count() }})</h2>
            <table class="std-table">
                <thead>
                    <tr>
                        <th style="width:18%">Room</th>
                        <th style="width:24%">Equipment</th>
                        <th style="width:14%">Category</th>
                        <th style="width:28%">Reason</th>
                        <th style="width:16%">Evidence</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($fails as $item)
                    <tr>
                        <td>{{ $item->room_name }}</td>
                        <td>{{ $item->equipment_name }}</td>
                        <td>{{ $categoryLabels[$item->category] ?? $item->category }}</td>
                        <td>{{ $item->notes }}</td>
                        <td>
                            {{-- B-04 + D-14 — embed evidence photo as data:image/jpeg;base64 URI.
                                 DomPDF 3.1.5 renders data: URIs natively (RESEARCH §Pitfall 3).
                                 The CommissioningItem::resolvedEvidencePhotoBase64() helper reads
                                 storage/app/private/{evidence_photo_path} via Storage::disk('local'),
                                 returns null if the path is empty OR the file is missing. Missing
                                 renders the placeholder copy so PDF generation never crashes. --}}
                            @php $evidenceBase64 = $item->resolvedEvidencePhotoBase64(); @endphp
                            @if ($evidenceBase64 !== null)
                                <img src="data:image/jpeg;base64,{{ $evidenceBase64 }}"
                                     class="thumb"
                                     alt="Evidence photo for {{ $item->equipment_name }}">
                            @else
                                <span class="evidence-missing">(photo missing)</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endif

@if (! empty($signoff))
    <section>
        <h2 class="sec-heading">Client Sign-off</h2>
        <table class="std-table">
            <tr><td class="lbl">Client name</td><td>{{ $signoff->client_name }}</td></tr>
            <tr><td class="lbl">Role</td><td>{{ $signoff->client_role }}</td></tr>
            <tr><td class="lbl">Company</td><td>{{ $signoff->client_company }}</td></tr>
            <tr><td class="lbl">Signed at</td>
                <td>{{ $signoff->signed_at->setTimezone('Europe/London')->format('d F Y H:i T') }}</td></tr>
        </table>

        <p class="certification-text">{{ $signoff->certification_text }}</p>

        {{-- DomPDF 3.1.5 renders data: URIs natively. signature_png_base64 is
             whitespace-sanitised at storage time (see CommissioningService::sanitiseBase64). --}}
        <div class="signature-box">
            <img src="data:image/png;base64,{{ $signoff->signature_png_base64 }}"
                 alt="Client signature">
        </div>
    </section>
@endif

</body>
</html>
