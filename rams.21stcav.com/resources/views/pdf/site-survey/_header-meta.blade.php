{{-- Top-of-form metadata: project + site + surveyor + access + PPE + rooms.
     When $survey is provided, known values pre-populate; otherwise everything
     renders as a blank-line slot. --}}
@php
    use App\Support\SurveyPdfHelpers as H;

    $dateStr      = $survey?->survey_date?->format('d/m/Y');
    $projectName  = $survey?->project_name;
    $projectRef   = $survey?->project_ref;
    $client       = $survey?->client_name;
    $siteAddress  = $survey?->site_address;
    $surveyor     = $survey?->surveyor_name;
    $contactName  = $survey?->site_contact_name;
    $contactPhone = $survey?->site_contact_phone;
    $siteContact  = trim(($contactName ?? '') . ($contactPhone ? ' (' . $contactPhone . ')' : ''));
@endphp

<h2>Project &amp; Site</h2>
<table>
    <tr><td class="label">Project Name</td><td>{!! H::blank($projectName) !!}</td></tr>
    <tr><td class="label">Project Ref</td><td>{!! H::blank($projectRef) !!}</td></tr>
    <tr><td class="label">Client</td><td>{!! H::blank($client) !!}</td></tr>
    <tr><td class="label">Site Address</td><td>{!! H::blank($siteAddress) !!}</td></tr>
    <tr><td class="label">Surveyor</td><td>{!! H::blank($surveyor) !!}</td></tr>
    <tr><td class="label">Survey Date</td><td>{!! H::blank($dateStr) !!}</td></tr>
    <tr><td class="label">Site Contact</td><td>{!! H::blank($siteContact) !!}</td></tr>
</table>

<h2>Site Access &amp; Safety</h2>
<table>
    <tr>
        <td class="label">On-site arrival time</td><td>{!! H::BLANK_LINE !!}</td>
        <td class="label">Expected leave time</td><td>{!! H::BLANK_LINE !!}</td>
    </tr>
    <tr><td class="label">Key holder / escort</td><td colspan="3">{!! H::BLANK_LINE !!}</td></tr>
    <tr><td class="label">Parking arrangement</td><td colspan="3">{!! H::BLANK_LINE !!}</td></tr>
    <tr><td class="label">Emergency contact</td><td colspan="3">{!! H::BLANK_LINE !!}</td></tr>
    <tr>
        <td class="label">PPE required</td>
        <td colspan="3">
            &#9744; Hi-vis &nbsp; &#9744; Hard hat &nbsp; &#9744; Safety boots &nbsp; &#9744; Safety glasses &nbsp; &#9744; Sign-in at reception &nbsp; &#9744; Other: {!! H::BLANK_LINE !!}
        </td>
    </tr>
    <tr>
        <td class="label">Rooms expected</td><td>{!! H::BLANK_LINE !!} of {!! H::BLANK_LINE !!}</td>
        <td class="label">Weather / conditions</td><td>{!! H::BLANK_LINE !!}</td>
    </tr>
</table>
