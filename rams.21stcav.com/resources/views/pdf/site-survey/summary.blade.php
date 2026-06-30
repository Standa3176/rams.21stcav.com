<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Site Survey — {{ $survey->project_name }}</title>
@include('pdf.site-survey._styles')
@php
    // ── Unified engineer/client survey report (quick task 260517-su1).
    //    $internal = true  → engineer-internal mode (Site Conditions table,
    //                         Pre-Install Checks, Engineer Findings, all the
    //                         technical artefacts).
    //    $internal = false → polished client-facing mode (cover chrome, AV
    //                         requirements + per-room photos + variations
    //                         only). No engineer-only sections rendered.
    //    Default = true preserves back-compat for any caller invoking the
    //    blade without the flag (the legacy buildSummary() pathway).
    $internal = $internal ?? true;
@endphp
{{-- Client-mode cover chrome overlay — kept here so the engineer mode's
     base _styles.blade.php is untouched and we don't fork CSS. Mirrors the
     Mini O&M Tier-1 visual language (260506-qa9): teal #1B7A7A headings,
     orange #C07000 accents, .cover-accent-bar cover-page band. --}}
@unless ($internal)
<style>
    .cover-accent-bar { height: 6pt; background: #1B7A7A; margin: 6pt 0 14pt; }
    .office-note-callout {
        background: #FFFBEB;
        border-left: 3pt solid #C07000;
        padding: 6pt 10pt;
        margin: 8pt 0;
    }
    .office-note-callout strong { color: #C07000; display: block; margin-bottom: 2pt; }
    .variations-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 6pt; }
    .variations-table th { background: #f6f6f6; text-align: left; padding: 4pt 6pt; border-bottom: 1pt solid #ccc; color: #555; font-weight: 700; }
    .variations-table td { padding: 4pt 6pt; border-bottom: 0.5pt solid #eee; vertical-align: top; }
    .status-pill { display: inline-block; padding: 1pt 6pt; border-radius: 8pt; font-size: 8pt; text-transform: uppercase; font-weight: 700; letter-spacing: .03em; }
    .status-proposed { background: #FEF3C7; color: #92400E; }
    .status-quoted   { background: #DBEAFE; color: #1E40AF; }
    .status-approved { background: #D1FAE5; color: #065F46; }
    .status-rejected { background: #FEE2E2; color: #991B1B; }
</style>
@endunless
</head><body>
@php
    use App\Support\SurveyPdfHelpers as H;
    $dateStr = $survey->survey_date ? $survey->survey_date->format('d/m/Y') : '—';
@endphp

{{-- Running footer now supplied to Browsershot by SurveyPdfService::buildSummary. --}}
<h1>Site Survey Report</h1>
@unless ($internal)
    <div class="cover-accent-bar"></div>
@else
    <p class="meta">21st Century AV Ltd</p>
@endunless

<h2>Project Details</h2>
<table>
    <tr><td class="label">Project Name</td><td>{{ $survey->project_name }}</td></tr>
    <tr><td class="label">Project Ref</td><td>{{ $survey->project_ref ?? '—' }}</td></tr>
    <tr><td class="label">Client</td><td>{{ $survey->client_name ?? '—' }}</td></tr>
    <tr><td class="label">Site Address</td><td>{{ $survey->site_address ?? '—' }}</td></tr>
    <tr><td class="label">Surveyor</td><td>{{ $survey->surveyor_name ?? '—' }}</td></tr>
    <tr><td class="label">Survey Date</td><td>{{ $dateStr }}</td></tr>
</table>

{{-- Survey-level office review summary — client-only, rendered once at the
     top above the per-room blocks. Mirrors the client-report.blade.php
     cover-page callout that used to live there (260508-v7g D-LOCK-2). --}}
@if (! $internal && filled($survey->office_review_notes))
    <div class="office-note-callout">
        <strong>Office review summary</strong>
        {!! nl2br(e($survey->office_review_notes)) !!}
    </div>
@endif

@if($survey->general_notes)
    <h2>General Notes</h2>
    <p>{!! nl2br(e($survey->general_notes)) !!}</p>
@endif

@foreach($survey->rooms as $room)
    @php
        $title = 'Room: ' . H::balanceParens((string) $room->room_name)
               . ($room->floor ? ' (Floor: ' . $room->floor . ')' : '');

        // ── AV Requirements narrative — strip leading duplicate of room name
        //    (mirrors field-form.blade.php pattern at line 39 — data quality fix)
        //    Then strip the SurveyService-appended "Standard checks for this
        //    solution type:" checklist tail — those bullets are a surveyor
        //    hint and belong on field-form.blade.php (which retains them),
        //    not on the post-survey summary where they render as a list of
        //    unanswered questions to the client/reviewer.
        $avReq = trim((string) ($room->av_requirements ?? ''));
        $avReq = $avReq !== '' ? trim(H::stripLeadingDuplicate($avReq, (string) ($room->room_name ?? ''))) : '';
        $avReq = $avReq !== '' ? H::stripStandardChecksTail($avReq) : '';
        $avEq  = trim((string) ($room->av_equipment_list ?? ''));

        // ── Engineer Findings guard + label maps (mirrors rams.blade.php
        //    lines 884-898; copies the same data-shape from $efByRoom into
        //    direct attribute access — Eloquent casts auto-decode arrays)
        $hasEF = ! empty($room->mounting_heights)
              || ! empty($room->work_at_height_methods)
              || ! empty($room->cable_routes)
              || ! empty($room->wall_construction)
              || ! empty($room->wall_needs_reinforcement)
              || ! empty($room->wall_needs_chase_out)
              || ! empty($room->wall_needs_conduit)
              || ! empty($room->brackets_required)
              || ! empty($room->table_info)
              || ! empty($room->floor_box_info);

        $methodLabels = [
            'ladder' => 'Ladder', 'podium' => 'Podium steps', 'tower' => 'Access tower',
            'mewp' => 'MEWP', 'scaffold' => 'Scaffold', 'na' => 'Not required',
        ];
        $wallConstructionLabels = [
            'ply_lined' => 'Ply-lined', 'solid' => 'Solid wall', 'plasterboard' => 'Plasterboard',
            'masonry' => 'Masonry / brick', 'metal_stud' => 'Metal stud', 'concrete' => 'Concrete',
        ];
        $cableCategoryLabels = [
            'ceiling_speakers' => 'Ceiling speakers', 'desk_cables' => 'Desk cables',
            'mic_cables' => 'Microphone cables', 'booking_panel_cables' => 'Booking panel cables',
            'screen_cables' => 'Screen / display cables', 'rack_to_room' => 'Rack to room',
            'other' => 'Other',
        ];
    @endphp

    <h2>{{ $title }}</h2>

    {{-- ─────────────────────────────────────────────────────────────────
         Group 1 — Site Conditions  [INTERNAL ONLY]
         Engineer-facing dimensions/services/access table. Suppressed in
         client mode to keep the report focused on planned scope + photos.
         ───────────────────────────────────────────────────────────────── --}}
    @if ($internal)
        <h3>Site Conditions</h3>
        <table>
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
            <tr><td class="label">Access / Hazard Notes</td><td>{!! nl2br(e($room->access_notes ?? '—')) !!}</td></tr>
            <tr><td class="label">Other Notes</td><td>{!! nl2br(e($room->notes ?? '—')) !!}</td></tr>
        </table>
    @endif

    {{-- ─────────────────────────────────────────────────────────────────
         Group 2 — AV Requirements (tick list + retained kit)
         Rendered in BOTH modes. Existing AV Equipment kept client-safe —
         the client benefits from knowing what existing gear is on site.
         ───────────────────────────────────────────────────────────────── --}}
    @if($avReq !== '' || $avEq !== '')
        <h3>AV Requirements</h3>
        <table>
            @if($avReq !== '')
                <tr>
                    <td class="label">Planned AV Works</td>
                    <td>{!! H::narrativeAsTickList($avReq, $internal) !!}</td>
                </tr>
            @endif
            @if($avEq !== '')
                <tr>
                    <td class="label">Existing AV Equipment</td>
                    <td>{!! nl2br(e($avEq)) !!}</td>
                </tr>
            @endif
        </table>
    @endif

    {{-- Per-room office notes — client-only, rendered as a callout. Mirrors
         the office_review_notes block at the top but scoped to one room. --}}
    @if (! $internal && filled($room->office_notes))
        <div class="office-note-callout">
            <strong>Office notes</strong>
            {!! nl2br(e($room->office_notes)) !!}
        </div>
    @endif

    {{-- ─────────────────────────────────────────────────────────────────
         Group 2.5 — Pre-install Checks  [INTERNAL ONLY]
         AI-generated SiteSurveyRoomQuestion rows including engineer free-
         text answers ("Pm to work this out", "wall not built yet" etc.).
         Strictly engineer-side — never surfaced to clients.
         ───────────────────────────────────────────────────────────────── --}}
    @if ($internal && $room->questions->isNotEmpty())
        @php
            $answerLabels = ['yes' => 'Yes', 'no' => 'No', 'other' => 'Other'];
        @endphp
        <h3>Pre-install Checks</h3>
        <table>
            @foreach($room->questions as $q)
                @php
                    $answerKey   = strtolower((string) ($q->answer ?? ''));
                    $answerLabel = $answerLabels[$answerKey] ?? '—';
                    $other       = trim((string) ($q->other_text ?? ''));
                @endphp
                <tr>
                    <td class="label">{{ $q->question }}</td>
                    <td>
                        {{ $answerLabel }}@if($answerKey === 'other' && $other !== '') — {{ $other }}@endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ─────────────────────────────────────────────────────────────────
         Group 3 — Engineer Findings  [INTERNAL ONLY]
         The 7 sub-sections from 260503-rgg: mounting heights, working-at-
         height methods, cable routes, wall construction & prep, brackets,
         table grommets, floor-box info. Each sub-section keeps its own @if
         so empty rooms render no empty stubs. Heading itself only appears
         when at least one sub-key is populated.
         ───────────────────────────────────────────────────────────────── --}}
    @if ($internal && $hasEF)
        <h3>Engineer Findings</h3>

        {{-- Mounting heights --}}
        @php
            $mh         = (array) ($room->mounting_heights ?? []);
            $heightRows = [];
            foreach ([
                'screen_h_m'         => 'Screen',
                'camera_h_m'         => 'Camera',
                'booking_panel_h_m'  => 'Booking panel',
                'speaker_h_m'        => 'Speaker',
            ] as $k => $lbl) {
                if (! empty($mh[$k])) {
                    $heightRows[] = $lbl . ': ' . $mh[$k] . ' m';
                }
            }
            foreach ((array) ($mh['other'] ?? []) as $other) {
                $oLbl = trim((string) ($other['label'] ?? ''));
                $oH   = $other['h_m'] ?? null;
                if ($oLbl !== '' && $oH !== null && $oH !== '') {
                    $heightRows[] = $oLbl . ': ' . $oH . ' m';
                }
            }
        @endphp
        @if(! empty($heightRows))
            <p style="margin:4pt 0;"><strong>Installation heights:</strong> {{ implode(' • ', $heightRows) }}</p>
        @endif

        {{-- Working at height methods --}}
        @php
            $wahLabels = array_values(array_filter(array_map(
                fn ($m) => $methodLabels[strtolower((string) $m)] ?? ucfirst((string) $m),
                (array) ($room->work_at_height_methods ?? [])
            )));
        @endphp
        @if(! empty($wahLabels))
            <p style="margin:4pt 0;"><strong>Working at height — methods on site:</strong> {{ implode(', ', $wahLabels) }}</p>
        @endif

        {{-- Cable routes --}}
        @php $cableRoutes = (array) ($room->cable_routes ?? []); @endphp
        @if(! empty($cableRoutes))
            <p style="margin:4pt 0 2pt;"><strong>Cable routes planned:</strong></p>
            <ul class="tick-list">
                @foreach($cableRoutes as $cr)
                    @php
                        $catKey = (string) ($cr['category'] ?? '');
                        $cat    = $cableCategoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey));
                        $len    = ! empty($cr['length_m']) ? ($cr['length_m'] . ' m') : '';
                        $from   = trim((string) ($cr['from'] ?? ''));
                        $to     = trim((string) ($cr['to']   ?? ''));
                        $route  = ($from && $to) ? ($from . ' → ' . $to) : ($from ?: $to);
                        $note   = trim((string) ($cr['notes'] ?? ''));
                        $parts  = array_filter([$cat, $route, $len, $note]);
                    @endphp
                    @if(! empty($parts))
                        <li>{{ implode(' — ', $parts) }}</li>
                    @endif
                @endforeach
            </ul>
        @endif

        {{-- Wall construction & prep --}}
        @php
            $wcLabels = array_values(array_filter(array_map(
                fn ($w) => $wallConstructionLabels[strtolower((string) $w)] ?? ucwords(str_replace('_', ' ', (string) $w)),
                (array) ($room->wall_construction ?? [])
            )));
            $prepFlags = [];
            if (! empty($room->wall_needs_reinforcement)) $prepFlags[] = 'Reinforcement required';
            if (! empty($room->wall_needs_chase_out))     $prepFlags[] = 'Chase-out required';
            if (! empty($room->wall_needs_conduit))       $prepFlags[] = 'Conduit installation required';
        @endphp
        @if(! empty($wcLabels) || ! empty($prepFlags))
            <p style="margin:4pt 0;">
                <strong>Wall construction:</strong>
                {{ ! empty($wcLabels) ? implode(', ', $wcLabels) : '—' }}
                @if(! empty($prepFlags))
                    <br><strong>Prep needed:</strong> {{ implode(', ', $prepFlags) }}
                @endif
            </p>
        @endif

        {{-- Brackets to source --}}
        @php $brackets = (array) ($room->brackets_required ?? []); @endphp
        @if(! empty($brackets))
            <p style="margin:4pt 0 2pt;"><strong>Brackets to source:</strong></p>
            <ul class="tick-list">
                @foreach($brackets as $b)
                    @php
                        $eq   = trim((string) ($b['equipment'] ?? ''));
                        $mod  = trim((string) ($b['model']     ?? ''));
                        $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                        $note = trim((string) ($b['notes']     ?? ''));
                        $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                        if ($note !== '') $line .= ' — ' . $note;
                    @endphp
                    @if($line !== '')
                        <li>{{ $line }}</li>
                    @endif
                @endforeach
            </ul>
        @endif

        {{-- Table & floor box info (compact, single line each if present) --}}
        @php
            $ti       = (array) ($room->table_info ?? []);
            $hasTable = ! empty($ti) && (! empty($ti['has_grommets']) || ! empty($ti['notes']));
            $fb       = (array) ($room->floor_box_info ?? []);
            $hasFb    = ! empty($fb) && (! empty($fb['has_floor_box']) || ! empty($fb['notes']));
        @endphp
        @if($hasTable)
            @php
                $tParts = [];
                if (! empty($ti['has_grommets'])) {
                    $tParts[] = ($ti['grommet_count'] ?? '?') . '× ' . trim((string) ($ti['grommet_size'] ?? '')) . ' grommets';
                }
                if (! empty($ti['notes'])) $tParts[] = $ti['notes'];
            @endphp
            <p style="margin:4pt 0;"><strong>Table:</strong> {{ implode(' — ', array_filter($tParts)) }}</p>
        @endif
        @if($hasFb)
            @php
                $fParts = [];
                if (! empty($fb['has_floor_box'])) {
                    $fParts[] = ($fb['power_outlets'] ?? 0) . ' power, ' . ($fb['data_outlets'] ?? 0) . ' data';
                    if (! empty($fb['cable_space'])) $fParts[] = trim((string) $fb['cable_space']) . ' cable space';
                }
                if (! empty($fb['notes'])) $fParts[] = $fb['notes'];
            @endphp
            <p style="margin:4pt 0;"><strong>Floor box:</strong> {{ implode(' — ', array_filter($fParts)) }}</p>
        @endif
    @endif

    {{-- Photos block — rendered in BOTH modes.
         Uses PdfImageEmbedder::dataUri() (resize-then-embed) so phone
         photos at 4–12MB are downscaled to ~200–400KB JPEG@80, keeping the
         PDF small and the Browsershot render under timeout. Also uses
         $photo->storagePath() to support both legacy flat paths and the
         current project-scoped layout (matches client-report.blade.php
         behaviour from before the merge — 260508-v7g). --}}
    @if($room->photos->isNotEmpty())
        <h3>Photos ({{ $room->photos->count() }})</h3>
        <table><tr>
            @foreach($room->photos as $photo)
                @php
                    $absPath = \Illuminate\Support\Facades\Storage::disk('local')->path($photo->storagePath());
                    $dataUri = \App\Support\PdfImageEmbedder::dataUri($absPath);
                @endphp
                @if($dataUri !== '')
                    <td style="width:33%;text-align:center;border:none;">
                        <img src="{{ $dataUri }}" style="max-width:100%;max-height:120pt;"/>
                        @if($photo->caption)<br><small>{{ $photo->caption }}</small>@endif
                    </td>
                @endif
            @endforeach
        </tr></table>
    @endif

    {{-- Per-room variations — client-only. Engineers see variations via
         the variations editor surface; on the engineer PDF they'd just be
         duplicated noise. Filtered by room_name (variations.room_name is
         a plain string column, not an FK). --}}
    @unless ($internal)
        @php
            $roomVariations = $survey->variations->filter(
                fn ($v) => (string) $v->room_name === (string) $room->room_name
            );
        @endphp
        @if ($roomVariations->isNotEmpty())
            <p style="margin:6pt 0 2pt;"><strong>Variations for this room:</strong></p>
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
    @endunless
@endforeach

{{-- Survey-wide variations (those without a per-room scope) — client-only.
     Mirrors the trailing block on the deleted client-report.blade.php. --}}
@unless ($internal)
    @php
        $surveyWideVariations = $survey->variations->filter(fn ($v) => empty($v->room_name));
    @endphp
    @if ($surveyWideVariations->isNotEmpty())
        <h2>Variations Summary</h2>
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
        <p style="font-size: 9pt; color: #555; margin-top: 8pt; font-style: italic;">
            Variations are scope changes captured during or after the survey. Status reflects the
            current commercial conversation — please contact your project manager for the latest position.
        </p>
    @endif
@endunless

</body></html>
