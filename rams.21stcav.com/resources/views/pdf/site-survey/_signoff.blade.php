{{-- Sign-off block — engineer/client signatures plus the "before you leave"
     checklist so the surveyor catches gaps on-site rather than after travel. --}}
@php
    use App\Support\SurveyPdfHelpers as H;
    $line = H::BLANK_LINE;
@endphp

<h2>Before you leave — checklist</h2>
<table>
    <tr><td style="font-size:8.5pt;">
        &#9744; All rooms surveyed &nbsp; &#9744; Every measurement captured &nbsp; &#9744; Quote kit matches site (or deviations noted) &nbsp; &#9744; Photos captured and labelled &nbsp; &#9744; Access/hazard notes complete &nbsp; &#9744; Client walk-through done &nbsp; &#9744; Permits/PTWs returned
    </td></tr>
</table>

<h2>Deviations from planned scope</h2>
<div class="field-box">Record any scope changes, missing items, or variations discovered on-site…</div>

<h2>Next actions agreed with client</h2>
<div class="field-box">Who is doing what by when…</div>

<h2>Sign-off</h2>
<table>
    <tr>
        <td class="label">Survey start time</td><td>{!! $line !!}</td>
        <td class="label">Survey end time</td><td>{!! $line !!}</td>
    </tr>
    <tr><td class="label">Accompanying personnel</td><td colspan="3">{!! $line !!}</td></tr>
    <tr><td class="label">Engineer name</td><td colspan="3">{!! $line !!}</td></tr>
    <tr><td class="label">Engineer signature</td><td colspan="3" style="height:40pt;"></td></tr>
    <tr><td class="label">Client name</td><td colspan="3">{!! $line !!}</td></tr>
    <tr><td class="label">Client signature</td><td colspan="3" style="height:40pt;"></td></tr>
    <tr>
        <td class="label">Date</td><td>{!! $line !!}</td>
        <td class="label">Form version</td><td>FSF-2.0 {{ now()->format('Y-m-d') }}</td>
    </tr>
</table>
