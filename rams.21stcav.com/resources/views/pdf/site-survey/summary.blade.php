<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Site Survey — {{ $survey->project_name }}</title>
@include('pdf.site-survey._styles')
</head><body>
@php
    use App\Support\SurveyPdfHelpers as H;
    $dateStr = $survey->survey_date ? $survey->survey_date->format('d/m/Y') : '—';
@endphp

<div class="footer">21st Century AV Ltd — Site Survey | {{ $survey->project_name }} | Generated {{ now()->format('d/m/Y') }}</div>
<h1>Site Survey Report</h1>
<p class="meta">21st Century AV Ltd</p>

<h2>Project Details</h2>
<table>
    <tr><td class="label">Project Name</td><td>{{ $survey->project_name }}</td></tr>
    <tr><td class="label">Project Ref</td><td>{{ $survey->project_ref ?? '—' }}</td></tr>
    <tr><td class="label">Client</td><td>{{ $survey->client_name ?? '—' }}</td></tr>
    <tr><td class="label">Site Address</td><td>{{ $survey->site_address ?? '—' }}</td></tr>
    <tr><td class="label">Surveyor</td><td>{{ $survey->surveyor_name ?? '—' }}</td></tr>
    <tr><td class="label">Survey Date</td><td>{{ $dateStr }}</td></tr>
</table>

@if($survey->general_notes)
    <h2>General Notes</h2>
    <p>{!! nl2br(e($survey->general_notes)) !!}</p>
@endif

@foreach($survey->rooms as $room)
    @php
        $title = 'Room: ' . H::balanceParens((string) $room->room_name)
               . ($room->floor ? ' (Floor: ' . $room->floor . ')' : '');
    @endphp

    <h2>{{ $title }}</h2>
    <table>
        <tr><td class="label">Room Ref</td><td>{{ $room->room_ref ?? '—' }}</td></tr>
        <tr><td class="label">Dimensions (W × D × H)</td><td>{{ $room->room_width_m ? $room->room_width_m . 'm' : '—' }} × {{ $room->room_depth_m ? $room->room_depth_m . 'm' : '—' }} × {{ $room->room_height_m ? $room->room_height_m . 'm' : '—' }}</td></tr>
        <tr><td class="label">Ceiling Type</td><td>{{ $room->ceiling_type ?? '—' }}</td></tr>
        <tr><td class="label">Ceiling Height</td><td>{{ $room->ceiling_height_m ? $room->ceiling_height_m . ' m' : '—' }}</td></tr>
        <tr><td class="label">Wall Material</td><td>{{ $room->wall_material ?? '—' }}</td></tr>
        <tr><td class="label">Floor Type</td><td>{{ $room->floor_type ?? '—' }}</td></tr>
        <tr><td class="label">Power Available</td><td>{!! H::yn((bool) $room->has_power) !!}</td></tr>
        <tr><td class="label">Power Outlets</td><td>{{ (int) $room->power_outlet_count }}</td></tr>
        <tr><td class="label">Additional Power Required</td><td>{!! H::yn((bool) $room->requires_additional_power) !!}</td></tr>
        <tr><td class="label">Network Available</td><td>{!! H::yn((bool) $room->has_network) !!}</td></tr>
        <tr><td class="label">Network Ports</td><td>{{ (int) $room->network_port_count }}</td></tr>
        <tr><td class="label">Existing Cabling</td><td>{{ $room->existing_cabling ?? '—' }}</td></tr>
        <tr><td class="label">AV Requirements</td><td>{!! nl2br(e($room->av_requirements ?? '—')) !!}</td></tr>
        <tr><td class="label">Existing AV Equipment</td><td>{!! nl2br(e($room->av_equipment_list ?? '—')) !!}</td></tr>
        <tr><td class="label">Access / Hazard Notes</td><td>{!! nl2br(e($room->access_notes ?? '—')) !!}</td></tr>
        <tr><td class="label">Other Notes</td><td>{!! nl2br(e($room->notes ?? '—')) !!}</td></tr>
    </table>

    @if($room->photos->isNotEmpty())
        <h3>Photos ({{ $room->photos->count() }})</h3>
        <table><tr>
            @foreach($room->photos as $photo)
                @php($photoPath = \Illuminate\Support\Facades\Storage::disk('local')->path('survey-photos/' . $photo->filename))
                @if(file_exists($photoPath))
                    @php($b64 = base64_encode(file_get_contents($photoPath)))
                    <td style="width:33%;text-align:center;border:none;">
                        <img src="data:{{ $photo->mime_type }};base64,{{ $b64 }}" style="max-width:100%;max-height:120pt;"/>
                        @if($photo->caption)<br><small>{{ $photo->caption }}</small>@endif
                    </td>
                @endif
            @endforeach
        </tr></table>
    @endif
@endforeach

</body></html>
