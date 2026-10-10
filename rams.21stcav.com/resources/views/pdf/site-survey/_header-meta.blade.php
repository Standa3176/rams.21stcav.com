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

    // Site Logistics (engineer-feedback fields from quick task 260503-rgg).
    // Pre-populate when set; render blank-line slot when null so the engineer
    // can fill on paper and transcribe back into the digital wizard 1:1.
    // Comms room access is deliberately absent here (46.8 D-02 overrides 46.5
    // D-03's "keep it everywhere" ruling) — the column stays on the model,
    // this view just never reads it.
    $parkingNotes      = $survey?->parking_restraints ?? null;
    $distanceMi        = $survey?->distance_from_base_miles;
    $distanceNotes     = $survey?->distance_from_base_notes;
    $siteAccessNotes   = $survey?->site_access_notes;
    $deliveryRoutes    = $survey?->delivery_routes;
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
    <tr><td class="label">Parking arrangement</td><td colspan="3">{!! H::blank($parkingNotes) !!}</td></tr>
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

<h2>Site Logistics</h2>
<table>
    {{-- Comms room access row removed by 46.8 D-02 (overrides 46.5 D-03's
         "keep it everywhere" ruling for this field specifically) — the survey's
         own Word document/PDF never prints comms room, for any survey. The
         stored column is untouched; see 46.8-CONTEXT.md D-02/D-03. --}}
    <tr>
        <td class="label">Distance from base</td>
        <td>{!! H::blank($distanceMi) !!} miles</td>
        <td class="label">Travel notes</td><td>{!! H::blank($distanceNotes) !!}</td>
    </tr>
    <tr>
        <td class="label">Site access notes</td>
        <td colspan="3">{!! H::blank($siteAccessNotes) !!} <span style="color:#888;font-size:7.5pt;">(loading bay / lift size / security pass / sign-in process)</span></td>
    </tr>
    <tr>
        <td class="label">Delivery routes</td>
        <td colspan="3">{!! H::blank($deliveryRoutes) !!} <span style="color:#888;font-size:7.5pt;">(where deliveries can drop, hours, contact)</span></td>
    </tr>
</table>
