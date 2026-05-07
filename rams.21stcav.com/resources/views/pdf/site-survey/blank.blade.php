<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Site Survey Form (Blank)</title>
@include('pdf.site-survey._styles')
</head><body>

{{-- Running footer now supplied to Browsershot by SurveyPdfService. --}}
<h1>Site Survey Form</h1>
<p class="meta">21st Century AV Ltd — Complete one form per site visit. Return to office for processing.</p>

@include('pdf.site-survey._header-meta', ['survey' => null])

<h2>General Notes</h2>
<div class="field-box">Write general site observations here…</div>

@for($i = 1; $i <= 4; $i++)
    <h2>Room / Area {{ $i }}</h2>
    @include('pdf.site-survey._blank-room-body')
    @if($i < 4)
        <hr style="border: none; border-top: 0.5pt dashed #ccc; margin: 8pt 0;">
    @endif
@endfor

<div class="page-break"></div>
@include('pdf.site-survey._signoff')

</body></html>
