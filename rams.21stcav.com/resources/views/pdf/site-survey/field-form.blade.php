<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Field Survey Form — {{ $survey->project_name }}</title>
@include('pdf.site-survey._styles')
</head><body>
@php
    use App\Support\SurveyPdfHelpers as H;
    $package = $survey->project?->latestPackage;
@endphp

{{-- Running footer now supplied to Browsershot by SurveyPdfService::buildFieldFormContents. --}}
<h1>Field Survey Form</h1>
<p class="meta">Complete by hand on-site. Return to office for processing into the digital survey.</p>

@include('pdf.site-survey._header-meta', ['survey' => $survey])

@php
    $worksDescription = $package->works_description ?? null;
@endphp
@if(is_string($worksDescription) && trim($worksDescription) !== '')
    <h2>Planned AV Works — Project Overview</h2>
    <p style="font-size:8.5pt;">{!! nl2br(e($worksDescription)) !!}</p>
@endif

{{-- Per-room manual-fill sections --}}
@if($survey->rooms->isEmpty())
    <h2>Rooms</h2><p class="meta">No rooms pre-populated. Use the blank section below.</p>
    <h2>Room / Area 1</h2>
    @include('pdf.site-survey._blank-room-body')
@else
    @foreach($survey->rooms as $room)
        @php
            $title = 'Room: ' . H::balanceParens((string) ($room->room_name ?: 'Unnamed'))
                   . ($room->floor ? ' (Floor: ' . $room->floor . ')' : '');

            $roomName       = (string) ($room->room_name ?? '');
            $avRequirements = trim((string) ($room->av_requirements ?? ''));
            $avEquipment    = trim((string) ($room->av_equipment_list ?? ''));
            // Strip leading duplicate of room name from narrative (data-quality fix).
            $avRequirements = trim(H::stripLeadingDuplicate($avRequirements, $roomName));
        @endphp

        <h2>{{ $title }}</h2>

        @if($avRequirements !== '' || $avEquipment !== '')
            <table>
                @if($avRequirements !== '')
                    <tr>
                        <td class="label">
                            Planned AV Works<br>
                            <span style="font-weight:normal;font-size:7.5pt;color:#888;">Tick each item as confirmed on-site</span>
                        </td>
                        <td>{!! H::narrativeAsTickList($avRequirements) !!}</td>
                    </tr>
                @endif
                @if($avEquipment !== '')
                    <tr>
                        <td class="label">Quote Kit</td>
                        <td>{!! nl2br(e($avEquipment)) !!}</td>
                    </tr>
                @endif
            </table>
        @endif

        @include('pdf.site-survey._blank-room-body')
    @endforeach
@endif

<div class="page-break"></div>
@include('pdf.site-survey._signoff')

</body></html>
