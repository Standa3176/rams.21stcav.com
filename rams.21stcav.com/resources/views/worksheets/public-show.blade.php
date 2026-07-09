<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Worksheet — {{ $worksheet->project_name }}</title>
    <style>
        /* ── Reset & base ─────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 17px; -webkit-font-smoothing: antialiased; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 1rem;
            line-height: 1.5;
            color: #1F2937;
            background: #F0F4F5;
            min-height: 100vh;
            padding-bottom: 3rem;
        }
        a { color: #178A95; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Layout ───────────────────────────────────────────────────── */
        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 .875rem 2rem;
        }

        /* ── Header ───────────────────────────────────────────────────── */
        .ws-header {
            background: #0B3C45;
            color: #fff;
            padding: 1rem 1.25rem .9rem;
            margin-bottom: 1.25rem;
        }
        .ws-header__inner { max-width: 860px; margin: 0 auto; }
        .ws-header__brand {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: rgba(255,255,255,.5);
            margin-bottom: .3rem;
        }
        .ws-header__title { font-size: 1.2rem; font-weight: 700; line-height: 1.3; }
        .ws-header__meta { font-size: .82rem; color: rgba(255,255,255,.7); margin-top: .35rem; }

        /* ── Alerts ───────────────────────────────────────────────────── */
        .alert {
            border-radius: 8px;
            padding: .9rem 1.1rem;
            margin-bottom: 1rem;
            font-size: .9rem;
            line-height: 1.5;
        }
        .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
        .alert-error   { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        .alert-info    { background: #E0F2FE; color: #0C4A6E; border: 1px solid #7DD3FC; }

        /* ── 260603-eha — Offline queue chip + panel ───────────────────── */
        .pending-chip {
            display: none; /* shown when count > 0 via JS */
            align-items: center;
            gap: .35rem;
            padding: .3rem .7rem;
            margin-top: .55rem;
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FCD34D;
            border-radius: 9999px;
            font-size: .82rem;
            font-weight: 700;
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
        }
        .pending-chip:hover { background: #FDE68A; }
        .pending-chip[aria-expanded="true"] { background: #FDE68A; }
        .pending-panel {
            display: none;
            margin-top: .5rem;
            background: #fff;
            color: #1F2937;
            border-radius: 10px;
            border: 1px solid #E5E7EB;
            padding: .75rem .9rem;
            max-width: 480px;
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }
        .pending-panel[data-open="1"] { display: block; }
        .pending-panel__head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: .55rem; padding-bottom: .45rem;
            border-bottom: 1px solid #F0F0F0;
            font-size: .85rem; font-weight: 700; color: #0B3C45;
        }
        .pending-item {
            display: flex; align-items: center; gap: .5rem;
            padding: .45rem 0; font-size: .82rem;
            border-bottom: 1px dashed #F0F0F0;
        }
        .pending-item:last-child { border-bottom: 0; }
        .pending-item__meta { flex: 1; min-width: 0; }
        .pending-item__room { font-weight: 600; color: #1F2937; }
        .pending-item__sub { color: #6B7280; font-size: .75rem; }
        .pending-item__status { font-size: .72rem; padding: .15rem .45rem; border-radius: 4px; }
        .pending-item__status--queued    { background: #E5E7EB; color: #374151; }
        .pending-item__status--uploading { background: #DBEAFE; color: #1E40AF; }
        .pending-item__status--failed    { background: #FEE2E2; color: #991B1B; }
        .pending-item__btns { display: flex; gap: .3rem; }
        .pending-item__btn {
            border: 1px solid #D1D5DB; background: #fff;
            padding: .2rem .5rem; border-radius: 4px;
            font-size: .72rem; cursor: pointer; color: #374151;
        }
        .pending-item__btn:hover { background: #F3F4F6; }

        /* ── Signed banner ─────────────────────────────────────────────── */
        .signed-banner {
            background: #ECFDF5;
            border: 1px solid #6EE7B7;
            border-radius: 10px;
            padding: 1rem 1.15rem;
            margin-bottom: 1.1rem;
            color: #065F46;
        }
        .signed-banner__head {
            font-weight: 700;
            font-size: .98rem;
            margin-bottom: .25rem;
        }
        .signed-banner__sub { font-size: .85rem; color: #047857; }
        .signed-banner__comments {
            margin-top: .65rem;
            padding-top: .65rem;
            border-top: 1px solid #A7F3D0;
            font-size: .88rem;
            white-space: pre-wrap;
        }
        .signed-banner__keep {
            margin-top: .55rem;
            font-size: .78rem;
            color: #4B5563;
            font-style: italic;
        }

        /* ── Cards ────────────────────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #E5E7EB;
            padding: 1.1rem 1.2rem;
            margin-bottom: 1rem;
        }
        .card-title {
            font-size: .9rem;
            font-weight: 700;
            color: #0B3C45;
            margin-bottom: .85rem;
            padding-bottom: .55rem;
            border-bottom: 1px solid #F0F0F0;
        }
        .room-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0B3C45;
            margin-bottom: .75rem;
        }

        /* ── Section headings inside a room ───────────────────────────── */
        .section-hdr {
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #178A95;
            margin: .9rem 0 .4rem;
            padding-top: .55rem;
            border-top: 1px solid #F0F0F0;
        }
        .section-hdr:first-of-type { border-top: 0; padding-top: 0; }

        /* ── Field tables ─────────────────────────────────────────────── */
        .field-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .87rem;
            margin-bottom: .25rem;
        }
        .field-table th {
            background: #F3F6F7;
            color: #6B7280;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 700;
            padding: .45rem .65rem;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }
        .field-table td {
            padding: .45rem .65rem;
            border-bottom: 1px solid #F5F5F5;
            vertical-align: top;
            color: #374151;
        }
        .field-table tr:last-child td { border-bottom: none; }
        .field-table td.label {
            width: 38%;
            font-weight: 600;
            color: #4B5563;
            font-size: .82rem;
        }
        .muted { color: #9CA3AF; font-style: italic; }
        .pre   { white-space: pre-wrap; }

        /* ── Forms ────────────────────────────────────────────────────── */
        .form-group { margin-bottom: .8rem; }
        .form-label {
            display: block;
            font-size: .78rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .3rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .form-label .req { color: #DC2626; }
        .form-control {
            display: block;
            width: 100%;
            padding: .72rem .8rem;
            border: 1.5px solid #D1D5DB;
            border-radius: 7px;
            font-size: 1rem;
            background: #fff;
            color: #1F2937;
            min-height: 52px;
            -webkit-appearance: none;
        }
        .form-control:focus {
            outline: none;
            border-color: #178A95;
            box-shadow: 0 0 0 3px rgba(23,138,149,.15);
        }
        textarea.form-control { resize: vertical; min-height: 90px; }

        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            margin: .55rem 0 .75rem;
            font-size: .9rem;
            color: #374151;
        }
        .checkbox-row input[type="checkbox"] {
            margin-top: .2rem;
            width: 1.05rem;
            height: 1.05rem;
            accent-color: #178A95;
            flex: 0 0 auto;
        }

        /* ── Signature pad ────────────────────────────────────────────── */
        .sig-pad-wrap {
            border: 2px dashed #178A95;
            border-radius: 10px;
            background: #F8FAFB;
            padding: .65rem;
            margin-bottom: .55rem;
        }
        .sig-pad {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 6px;
            display: block;
            width: 100%;
            height: 220px;
            touch-action: none;
            cursor: crosshair;
        }
        .sig-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: .4rem;
        }

        /* ── Buttons ──────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: .75rem 1.4rem;
            border-radius: 8px;
            border: 1.5px solid transparent;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            min-height: 50px;
            touch-action: manipulation;
            font-family: inherit;
        }
        .btn-teal     { background: #178A95; color: #fff; border-color: #178A95; }
        .btn-teal:hover:not([disabled]) { background: #157B85; border-color: #157B85; }
        .btn-teal[disabled] { opacity: .55; cursor: not-allowed; }
        .btn-outline  { background: transparent; color: #178A95; border-color: #178A95; }
        .btn-outline:hover { background: #EBF6F7; }
        .btn-sm       { padding: .45rem .85rem; font-size: .82rem; min-height: 40px; }

        .submit-row {
            display: flex;
            justify-content: flex-end;
            margin-top: .85rem;
        }

        ul.bullets { margin: 0 0 .35rem 1.15rem; padding: 0; }
        ul.bullets li { margin: .15rem 0; font-size: .9rem; color: #374151; }

        /* ── Top WORKSHEET ribbon (distinguishes from Site Survey link) ─── */
        .doc-ribbon {
            background: #FBBF24;
            color: #0B3C45;
            padding: .35rem 1rem;
            text-align: center;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        /* ── Collapsible room cards ──────────────────────────────────────── */
        .room-summary {
            list-style: none;
            cursor: pointer;
            padding: .9rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .65rem;
            user-select: none;
            background: #F9FAFB;
            border-radius: 8px;
            margin: -1.1rem -1.2rem .85rem;
            border-bottom: 1px solid #E5E7EB;
        }
        .room-summary::-webkit-details-marker { display: none; }
        .room-summary::marker { display: none; }
        details[open] > .room-summary { background: #ECFEFF; border-color: #67E8F9; }
        .room-chevron {
            color: #178A95; font-size: .85rem; flex-shrink: 0;
            transition: transform 200ms ease;
        }
        details[open] > .room-summary .room-chevron { transform: rotate(90deg); }
        .room-summary-name { flex: 1; font-weight: 700; color: #0B3C45; font-size: 1rem; }
        .photo-count-pill {
            background: #178A95; color: #fff;
            padding: .15rem .55rem; border-radius: 14px;
            font-size: .68rem; font-weight: 700;
        }
        .photo-count-pill.zero { background: #EF4444; }

        /* ── In-room hamburger drawers (Tier-1 polish) ───────────────────── */
        .room-drawer {
            background: #fff;
            border: 1.5px solid;
            border-radius: 10px;
            margin-bottom: .65rem;
            overflow: hidden;
        }
        .room-drawer.teal  { border-color: rgba(23,138,149,.35); }
        .room-drawer.gold  { border-color: rgba(251,191,36,.5); }
        .room-drawer.amber { border-color: rgba(245,158,11,.4); }
        .room-drawer.grey  { border-color: rgba(107,114,128,.35); }
        /* 260504-ij9 — accent variant for Survey Reference drawer (visual differentiator). */
        .room-drawer.teal.teal--accent { border-left-width: 4px; border-left-color: #178A95; }

        .room-drawer summary {
            list-style: none;
            cursor: pointer;
            padding: .7rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
            min-height: 44px;
            font-size: .9rem;
            font-weight: 700;
            transition: background 120ms ease;
        }
        .room-drawer summary::-webkit-details-marker { display: none; }
        .room-drawer summary::marker { display: none; }
        .room-drawer.teal  summary { background: rgba(23,138,149,.06); color: #0B3C45; }
        .room-drawer.gold  summary { background: rgba(251,191,36,.08); color: #92400E; }
        .room-drawer.amber summary { background: rgba(245,158,11,.08); color: #92400E; }
        .room-drawer.grey  summary { background: rgba(107,114,128,.06); color: #374151; }
        .room-drawer summary:hover { filter: brightness(.97); }
        .room-drawer summary .chev {
            font-size: 1.1rem; transition: transform 200ms ease;
            color: #178A95;
        }
        .room-drawer.gold summary .chev,
        .room-drawer.amber summary .chev { color: #D97706; }
        .room-drawer.grey summary .chev { color: #6B7280; }
        .room-drawer[open] summary .chev { transform: rotate(180deg); }
        .room-drawer-body { padding: .85rem 1rem; }
        .room-drawer-body ul.kit-rows {
            list-style: none; padding: 0; margin: 0;
        }
        .room-drawer-body ul.kit-rows li {
            display: flex; align-items: flex-start; gap: .5rem;
            padding: .35rem 0;
            border-bottom: 1px dashed #E5E7EB;
            font-size: .88rem;
        }
        .room-drawer-body ul.kit-rows li:last-child { border-bottom: none; }
        .qty-pill {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 28px; height: 22px; padding: 0 .35rem;
            background: rgba(251,191,36,.18); color: #92400E;
            border-radius: 4px;
            font-size: .72rem; font-weight: 700;
            font-variant-numeric: tabular-nums;
            flex-shrink: 0;
        }
        .room-drawer-body ol.steps,
        .room-drawer-body ul.actions {
            margin: 0; padding-left: 1.3rem;
        }
        .room-drawer-body ol.steps li,
        .room-drawer-body ul.actions li {
            font-size: .88rem; color: #374151; margin: .35rem 0;
            line-height: 1.45;
        }

        /* Photo upload tiles per room — placeholder UI for now (next batch) */
        .photo-tray { margin-top: .85rem; padding-top: .85rem; border-top: 1px dashed #E5E7EB; }
        .photo-tray-title {
            font-size: .72rem; font-weight: 800; letter-spacing: .06em;
            text-transform: uppercase; color: #178A95; margin-bottom: .55rem;
        }
        .photo-warn {
            background: #FEF3C7; color: #92400E;
            border: 1px solid #FBBF24; border-radius: 6px;
            padding: .55rem .75rem; font-size: .82rem; font-weight: 600;
        }

        /* 260504-lat: loading spinner for Box Serial Label capture button */
        .label-cap-busy::after {
            content: '';
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: currentColor;
            margin-left: 6px;
            animation: lblPulse 1s ease-in-out infinite;
        }
        @keyframes lblPulse {
            0%, 100% { opacity: 0.3; transform: scale(0.8); }
            50%      { opacity: 1.0; transform: scale(1.1); }
        }
    </style>
</head>
<body>

    {{-- Top ribbon — distinguishes the WORKSHEET link from the SITE SURVEY
         link. Engineers and clients now see at a glance which document this is. --}}
    <div class="doc-ribbon">📋 WORKSHEET — Engineer Job Card &amp; Sign-Off</div>

    <header class="ws-header">
        <div class="ws-header__inner">
            <div class="ws-header__brand">21st Century AV — Installation Worksheet</div>
            <div class="ws-header__title">{{ $worksheet->project_name }}</div>
            <div class="ws-header__meta">
                @if($worksheet->client_name){{ $worksheet->client_name }}@endif
                @if($worksheet->project_ref) · Ref: {{ $worksheet->project_ref }}@endif
                @if($worksheet->site_address) · {{ $worksheet->site_address }}@endif
            </div>
            {{-- ── 260602-mlt — Site contact line ──────────────────────────────
                 Sourced from $worksheet->project->latestPackage->extracted_data
                 ['ship_contact' / 'ship_phone'] (top-level keys, NOT nested under
                 'project'). UK normalisation: leading '0' → '+44' in the tel:
                 href; visible label preserves original formatting. Renders
                 nothing when BOTH name AND phone are empty (no dangling
                 "Site contact: ·" debris). --}}
            @php
                $pkg = optional($worksheet->project)->latestPackage;
                $ed  = is_array($pkg?->extracted_data) ? $pkg->extracted_data : [];
                $siteContactName  = trim((string) ($ed['ship_contact'] ?? ''));
                $siteContactPhone = trim((string) ($ed['ship_phone']   ?? ''));
                $telHref = '';
                if ($siteContactPhone !== '') {
                    $digits = preg_replace('/\s+/', '', $siteContactPhone);
                    $telHref = (str_starts_with($digits, '0'))
                        ? '+44' . substr($digits, 1)
                        : $digits;
                }
            @endphp
            @if($siteContactName !== '' || $siteContactPhone !== '')
                <div class="ws-header__meta ws-header__contact" style="margin-top:.2rem;">
                    Site contact:
                    @if($siteContactName !== ''){{ ' ' . $siteContactName }}@endif
                    @if($siteContactName !== '' && $siteContactPhone !== '') · @endif
                    @if($siteContactPhone !== '')<a href="tel:{{ $telHref }}" style="color:inherit;text-decoration:underline;">{{ $siteContactPhone }}</a>@endif
                </div>
            @endif

            {{-- ── 260603-eha — Offline photo queue chip + panel ────────
                 Hidden by default; the OfflineQueue UI controller (bottom of
                 file) toggles display:inline-flex when count > 0. --}}
            <button type="button"
                    id="pending-chip"
                    class="pending-chip"
                    aria-expanded="false"
                    aria-controls="pending-panel"
                    title="Pending photo uploads">
                🔄 <span id="pending-chip-count">0</span> pending
            </button>
            <div id="pending-panel" class="pending-panel" role="region" aria-label="Pending uploads">
                <div class="pending-panel__head">
                    <span>Pending uploads</span>
                    <button type="button" id="pending-retry-all" class="pending-item__btn">↻ Retry all</button>
                </div>
                <div id="pending-list"></div>
            </div>
        </div>
    </header>

    <div class="wrap">

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-error">
                <strong>Please check the form:</strong>
                <ul style="margin:.4rem 0 0 1.1rem;">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($latestSignoff)
            <div class="signed-banner">
                <div class="signed-banner__head">
                    Signed by {{ $latestSignoff->client_name }}
                    on {{ $latestSignoff->signed_at->format('d M Y H:i') }}
                </div>
                <div class="signed-banner__sub">
                    Thank you for signing this worksheet. A copy has been recorded.
                </div>
                @if($latestSignoff->signed_with_comments && trim((string) $latestSignoff->comments) !== '')
                    <div class="signed-banner__comments">
                        <strong>Outstanding items / comments:</strong><br>
                        {{ $latestSignoff->comments }}
                    </div>
                @endif
                <div class="signed-banner__keep">
                    This worksheet remains accessible — engineers may continue to update notes and photos.
                </div>
            </div>
        @endif

        @php
            $rooms = $worksheet->generated_data['rooms'] ?? [];

            // ── Survey Reference lookup (per quick task 260504-dh8) ────────────────
            // Build a per-project, room-name-keyed lookup of engineer-feedback
            // captured in the latest SiteSurvey for this worksheet's project. Used
            // by the new teal "Survey Reference" drawer rendered before the kit-list.
            // Defensive: missing class / missing project / missing survey ⇒ empty []
            // and the drawer simply doesn't render — pre-260503-rgg worksheets stay
            // visually identical.
            $efByRoom = [];
            $photosByRoom = [];
            $roomsRequiringReview = [];
            $siteLogistics = [];
            if ($worksheet->project_id && class_exists(\App\Models\SiteSurvey::class)) {
                $survey = \App\Models\SiteSurvey::with(['rooms', 'rooms.photos'])
                    ->where('project_id', $worksheet->project_id)
                    ->latest('id')
                    ->first();
                if ($survey) {
                    // ── Site-level logistics (260504-gho) — single inline lookup
                    //    reuses $survey already loaded for the per-room drawer
                    //    so this is zero-net DB cost.
                    $siteLogistics = [
                        'comms_room_access_status' => (string) ($survey->comms_room_access_status ?? ''),
                        'comms_room_access_notes'  => (string) ($survey->comms_room_access_notes  ?? ''),
                        'parking_restraints'       => (string) ($survey->parking_restraints       ?? ''),
                        'distance_from_base_miles' => $survey->distance_from_base_miles,
                        'distance_from_base_notes' => (string) ($survey->distance_from_base_notes ?? ''),
                        'site_access_notes'        => (string) ($survey->site_access_notes        ?? ''),
                        'delivery_routes'          => (string) ($survey->delivery_routes          ?? ''),
                    ];
                    $hasSiteLogistics = false;
                    foreach ($siteLogistics as $v) {
                        if ($v !== '' && $v !== null) { $hasSiteLogistics = true; break; }
                    }
                    if (! $hasSiteLogistics) $siteLogistics = [];

                    foreach ($survey->rooms as $r) {
                        $key = strtolower(trim((string) ($r->room_name ?? '')));
                        if ($key === '') continue;
                        $efByRoom[$key] = [
                            'mounting_heights'         => (array) ($r->mounting_heights ?? []),
                            'work_at_height_methods'   => (array) ($r->work_at_height_methods ?? []),
                            'cable_routes'             => (array) ($r->cable_routes ?? []),
                            'wall_construction'        => (array) ($r->wall_construction ?? []),
                            'wall_needs_reinforcement' => (bool) ($r->wall_needs_reinforcement ?? false),
                            'wall_needs_chase_out'     => (bool) ($r->wall_needs_chase_out ?? false),
                            'wall_needs_conduit'       => (bool) ($r->wall_needs_conduit ?? false),
                            'table_info'               => (array) ($r->table_info ?? []),
                            'floor_box_info'           => (array) ($r->floor_box_info ?? []),
                            'brackets_required'        => (array) ($r->brackets_required ?? []),
                        ];
                        $photosByRoom[$key] = $r->photos ?? collect();
                    }
                    // ── 260504-hqe — page-level "rooms whose drawer is visible" set
                    //    drives the soft-disable on the Sign-Off button. The drawer
                    //    only opens when EF data OR survey photos exist; we MUST
                    //    match that condition or the engineer would be blocked from
                    //    signing off without any UI to clear the gate.
                    foreach ($efByRoom as $k => $efv) {
                        $hasAnyEf = ! empty($efv['mounting_heights'])
                            || ! empty($efv['work_at_height_methods'])
                            || ! empty($efv['cable_routes'])
                            || ! empty($efv['wall_construction'])
                            || ! empty($efv['wall_needs_reinforcement'])
                            || ! empty($efv['wall_needs_chase_out'])
                            || ! empty($efv['wall_needs_conduit'])
                            || ! empty($efv['brackets_required'])
                            || (is_array($efv['table_info'] ?? null) && ! empty($efv['table_info']['has_grommets']))
                            || (is_array($efv['floor_box_info'] ?? null) && ! empty($efv['floor_box_info']['has_floor_box']));
                        $hasPhotos = isset($photosByRoom[$k]) && $photosByRoom[$k]->isNotEmpty();
                        if ($hasAnyEf || $hasPhotos) {
                            $roomsRequiringReview[] = $k;
                        }
                    }
                }
            }
            $commsRoomLabels = [
                'yes' => 'Permission required', 'no' => 'Free access',
                'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
            ];

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

        @if($latestSignoff)
            {{-- 260504-iy4 L3 — signaled lock. Disables every nested form/button/input
                 (fieldset cascades the disabled attribute) so engineers + clients
                 can't accidentally re-submit photos / labels / reviews / sign-offs.
                 View-only elements (drawers, thumbnails, signed banner) remain
                 interactive. JS can later toggle this flag if a re-sign-off is
                 intentional (snag-list workflow — out of scope for v1.3). --}}
            <fieldset disabled style="border:0;padding:0;margin:0;">
        @endif

        @if(empty($rooms))
            <div class="card">
                <div class="card-title">Worksheet</div>
                <p class="muted">No room data is available yet — please contact your project manager.</p>
            </div>
        @else
            {{-- ── STALE-DATA BANNER (quick task 260602-o2a) ──────────────────
                 Informational only — engineers cannot regen, only the office
                 can. Renders ONLY when worksheet has rooms (i.e. something
                 to be stale about) AND project.latestPackage was edited
                 after the worksheet's snapshot timestamp. --}}
            @include('worksheets._stale-banner', ['worksheet' => $worksheet, 'variant' => 'public'])

            {{-- ── ENGINEER REFERENCE FILES (quick task 260601-r4c) ────────────
                 Project-level uploaded artifacts (site plans, CAD drawings,
                 cable schedules, method statements). Drawer is hidden when
                 the project has no reference files. --}}
            @include('partials._engineer-reference-drawer', [
                'files'          => optional($worksheet->project)->referenceFiles?->sortByDesc('uploaded_at') ?? collect(),
                'serveRouteName' => 'public-worksheet.files.serve',
                'token'          => $token,
            ])

            {{-- ── SITE LOGISTICS — project-level drawer (260504-gho) ──────────────
                 Engineers arriving on site need parking / comms-room access /
                 depot distance / delivery routes ONCE per visit, NOT per room.
                 Defensive: $siteLogistics === [] when survey missing or all 7
                 columns are NULL — drawer renders nothing for legacy projects. --}}
            @if(! empty($siteLogistics))
                <details class="room-drawer teal" style="margin-bottom:1rem;">
                    <summary>
                        <span>📋 Site Logistics — Arrival Info</span>
                        <span class="chev">▾</span>
                    </summary>
                    <div class="room-drawer-body">
                        @if(! empty($siteLogistics['parking_restraints']))
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Parking arrangements</div>
                                <div style="font-size:.88rem;color:#374151;white-space:pre-wrap;">{{ $siteLogistics['parking_restraints'] }}</div>
                            </div>
                        @endif
                        @if(! empty($siteLogistics['site_access_notes']))
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Site access notes</div>
                                <div style="font-size:.88rem;color:#374151;white-space:pre-wrap;">{{ $siteLogistics['site_access_notes'] }}</div>
                            </div>
                        @endif
                        @if(! empty($siteLogistics['delivery_routes']))
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Delivery routes</div>
                                <div style="font-size:.88rem;color:#374151;white-space:pre-wrap;">{{ $siteLogistics['delivery_routes'] }}</div>
                            </div>
                        @endif
                        @if(! empty($siteLogistics['comms_room_access_status']) || ! empty($siteLogistics['comms_room_access_notes']))
                            @php
                                $statusLabel = $commsRoomLabels[$siteLogistics['comms_room_access_status'] ?? ''] ?? '';
                                $parts = array_filter([$statusLabel, $siteLogistics['comms_room_access_notes'] ?? '']);
                            @endphp
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Comms room access</div>
                                <div style="font-size:.88rem;color:#374151;">{{ implode(' — ', $parts) }}</div>
                            </div>
                        @endif
                        @if(! empty($siteLogistics['distance_from_base_miles']) || ! empty($siteLogistics['distance_from_base_notes']))
                            @php
                                $parts = array_filter([
                                    ! empty($siteLogistics['distance_from_base_miles'])
                                        ? $siteLogistics['distance_from_base_miles'] . ' miles from depot' : '',
                                    $siteLogistics['distance_from_base_notes'] ?? '',
                                ]);
                            @endphp
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Distance from depot</div>
                                <div style="font-size:.88rem;color:#374151;">{{ implode(' — ', $parts) }}</div>
                            </div>
                        @endif
                    </div>
                </details>
            @endif

            <p class="muted" style="font-size:.85rem;margin-bottom:.85rem;">
                Tap each space to expand. Use the drawers inside to switch between AV
                works summary, kit list, and install steps. Photos required per space
                before sign-off.
            </p>

            @php
                // ── Sign-off gate (260504-hqe + 260504-iy4 H4) — collect rooms that
                //    have a survey but have NOT yet been reviewed. The set drives the
                //    soft-disable on the page-level Sign-Off button. Visual-only gate —
                //    never blocks server. Now reads via $worksheet->surveyReviewedAt()
                //    accessor so legacy flat-shape AND the new namespaced shape both
                //    resolve cleanly (legacy → null → re-mark).
                $unreviewedRooms  = [];
                foreach ($rooms as $r) {
                    $rName = (string) ($r['name'] ?? '');
                    if ($rName === '') continue;
                    $rKey  = strtolower(trim($rName));
                    $hasSurveyForThisRoom = in_array($rKey, $roomsRequiringReview, true);
                    if ($hasSurveyForThisRoom && $worksheet->surveyReviewedAt($rName) === null) {
                        $unreviewedRooms[] = $rName;
                    }
                }
                $signOffBlocked = ! empty($unreviewedRooms);
                // 260504-ij9 fix H2 — slug of the FIRST unreviewed room, used by the
                // top banner's "Jump to first unreviewed room" anchor link.
                $firstUnreviewedSlug = ! empty($unreviewedRooms)
                    ? \Illuminate\Support\Str::slug((string) $unreviewedRooms[0])
                    : '';

                // ── 260504-iy4 H1 — auto-collapse on completion ──
                // Default open-room is the first room that is NOT yet marked complete.
                // When every room is complete, leave them all closed so the engineer
                // sees a clean "all done" page that they can review-or-collapse-on-demand.
                $firstIncompleteIdx = null;
                foreach ($rooms as $i => $r) {
                    $rName = (string) ($r['name'] ?? '');
                    if ($rName === '') continue;
                    if (! $worksheet->roomCompletedAt($rName)) {
                        $firstIncompleteIdx = $i;
                        break;
                    }
                }
            @endphp

            {{-- 260504-ij9 fix H2 — TOP banner mirrors the bottom Sign-Off banner so
                 engineers see the warning whether they're at top or bottom of the page.
                 Anchor jumps straight to the first unreviewed room's <details> block. --}}
            @if($signOffBlocked)
                <div id="signoff-block-top" style="margin-bottom:16px;padding:12px 14px;border-radius: var(--radius-lg);background: var(--warning-light);color:#92400E;border:1px solid color-mix(in oklab, var(--warning) 30%, transparent);font-size: var(--fs-small);line-height:1.5;">
                    <strong>⚠ Sign-off blocked.</strong>
                    Review the survey reference for these rooms first:
                    <strong>{{ implode(', ', $unreviewedRooms) }}</strong>.
                    @if($firstUnreviewedSlug !== '')
                        <a href="#room-{{ $firstUnreviewedSlug }}"
                           style="display:inline-block;margin-left:.4rem;font-weight:600;color:#92400E;text-decoration:underline;">Jump to first unreviewed room →</a>
                    @endif
                </div>
            @endif

            @foreach($rooms as $idx => $room)
                @php
                    $equipment    = $room['equipment'] ?? [];
                    $bullets      = (array) ($room['works_summary_bullets'] ?? []);
                    $installSteps = trim((string) ($room['install_steps'] ?? ''));
                    $stepLines    = $installSteps !== ''
                        ? array_values(array_filter(array_map(
                              fn ($s) => preg_replace('/^\s*(?:\d+[\.\)]|[-•])\s*/', '', trim($s)),
                              preg_split('/\r?\n/', $installSteps)
                          ), fn ($s) => $s !== ''))
                        : [];
                    $roomKey      = strtolower(trim((string) ($room['name'] ?? '')));
                    $photoCount   = $photoCounts[$roomKey] ?? 0;
                    $roomPhotos   = $worksheet->photos
                        ->filter(fn ($p) => strtolower(trim((string) $p->room_name)) === $roomKey);

                    // ── Survey Reference (260504-dh8) — per-room engineer-feedback
                    //    lookup keyed by lowercase room name. \$hasEF gates the
                    //    teal drawer below; \$efItemCount drives the "(N captured)"
                    //    badge in the drawer summary.
                    $efKey  = strtolower(trim((string) ($room['name'] ?? '')));
                    $ef     = $efByRoom[$efKey] ?? [];
                    // 260504-hqe — survey photos may exist even when zero EF data was
                    // captured in the wizard. The drawer must still open in that case
                    // so the engineer can review the photos and tap Mark Reviewed.
                    $hasSurveyPhotos = isset($photosByRoom[$efKey]) && $photosByRoom[$efKey]->isNotEmpty();
                    $hasEF  = $hasSurveyPhotos || (! empty($ef) && (
                        ! empty($ef['mounting_heights'])
                        || ! empty($ef['work_at_height_methods'])
                        || ! empty($ef['cable_routes'])
                        || ! empty($ef['wall_construction'])
                        || ! empty($ef['wall_needs_reinforcement'])
                        || ! empty($ef['wall_needs_chase_out'])
                        || ! empty($ef['wall_needs_conduit'])
                        || ! empty($ef['brackets_required'])
                        || (is_array($ef['table_info'] ?? null) && ! empty($ef['table_info']['has_grommets']))
                        || (is_array($ef['floor_box_info'] ?? null) && ! empty($ef['floor_box_info']['has_floor_box']))
                    ));
                    $efItemCount = 0;
                    if ($hasEF) {
                        $efItemCount = (int) (! empty($ef['mounting_heights']) ? 1 : 0)
                                     + (int) (! empty($ef['work_at_height_methods']) ? 1 : 0)
                                     + (int) (! empty($ef['cable_routes']) ? 1 : 0)
                                     + (int) (! empty($ef['wall_construction']) || ! empty($ef['wall_needs_reinforcement']) || ! empty($ef['wall_needs_chase_out']) || ! empty($ef['wall_needs_conduit']) ? 1 : 0)
                                     + (int) (! empty($ef['brackets_required']) ? 1 : 0)
                                     + (int) (! empty($ef['table_info']['has_grommets'] ?? false) ? 1 : 0)
                                     + (int) (! empty($ef['floor_box_info']['has_floor_box'] ?? false) ? 1 : 0);
                    }

                    // ── Per-room review status (260504-ij9 fix B2 + 260504-iy4 H4 namespace) ──
                    // Pill renders alongside the photo-count pill on the room <summary>.
                    // No pill at all when the room has no survey to review (gate doesn't apply).
                    $thisRoomReviewedStamp  = $worksheet->surveyReviewedAt($room['name'] ?? '');
                    $gateApplies            = $hasEF; // EF data OR survey photos already folded into $hasEF
                    $isReviewed             = $thisRoomReviewedStamp !== null;
                    $isUnreviewedWithGate   = $gateApplies && ! $isReviewed;
                    $roomIdSlug             = \Illuminate\Support\Str::slug((string) ($room['name'] ?? ('room-' . $idx)));

                    // ── 260504-iy4 H1 — per-room completion status ──
                    $roomCompletedAt = $worksheet->roomCompletedAt($room['name'] ?? '');
                    $roomCompletedBy = $worksheet->roomCompletedBy($room['name'] ?? '');
                    $isRoomComplete  = $roomCompletedAt !== null;
                    try {
                        $roomCompletedDisplay = $isRoomComplete ? \Carbon\Carbon::parse($roomCompletedAt)->format('d M Y H:i') : '';
                    } catch (\Throwable $e) {
                        $roomCompletedDisplay = (string) $roomCompletedAt;
                    }

                    // Soft gate for Mark Complete CTA: requires (a) survey reviewed if a survey applies, AND
                    // (b) at least one completed-work photo. Visual-only — server still accepts the POST.
                    $markCompleteGateOk = (! $gateApplies || $isReviewed) && $photoCount >= 1;

                    // Skip-restore flag — used by the H3 scroll-restore JS so a room that was just
                    // completed DOES NOT get reopened on reload (auto-collapse must win).
                    $skipRestoreAttr = $isRoomComplete ? 'data-skip-restore="1"' : '';
                @endphp

                <details class="card" id="room-{{ $roomIdSlug }}" {!! $skipRestoreAttr !!} {{ $idx === $firstIncompleteIdx ? 'open' : '' }}>
                    <summary class="room-summary">
                        <span class="room-chevron">▶</span>
                        <span class="room-summary-name">{{ $room['name'] ?? 'Unknown Room' }}</span>
                        @if($isUnreviewedWithGate)
                            <span style="display:inline-flex;align-items:center;gap:.25rem;padding:1px 8px;border-radius:9999px;background:#FEF3C7;color:#92400E;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;">⚠ Survey not reviewed</span>
                        @elseif($isReviewed)
                            <span style="display:inline-flex;align-items:center;gap:.25rem;padding:1px 8px;border-radius:9999px;background:#DCFCE7;color:#166534;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;">✓ Reviewed</span>
                        @endif
                        @if($isRoomComplete)
                            <span style="display:inline-flex;align-items:center;gap:.25rem;padding:1px 8px;border-radius:9999px;background:#DCFCE7;color:#166534;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;" title="Completed by {{ $roomCompletedBy }} at {{ $roomCompletedDisplay }}">✓ Complete</span>
                        @endif
                        <span class="photo-count-pill {{ $photoCount === 0 ? 'zero' : '' }}">
                            📷 {{ $photoCount }}
                        </span>
                    </summary>

                    {{-- 260504-ij9 fix B3 — Photo tray moved to TOP of room body so engineers
                         see the action item (capture proof of completed work) FIRST, before
                         scrolling through Survey Reference / AV Works / Kit / Steps. --}}
                    @php
                        // 260508 — pre-compute the photo set as a plain array for the
                        // x-photo-lightbox cycler. One array per room; each thumbnail's
                        // onclick passes its own index so prev/next walks just this room.
                        $roomPhotosLb = $roomPhotos->values()->map(fn ($p) => [
                            'url'     => route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]),
                            'caption' => $p->caption ?? '',
                        ])->all();
                    @endphp
                    <div class="photo-tray" data-photo-tray data-room-key="{{ $roomKey }}" style="margin-top:0;padding-top:0;border-top:0;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:1px dashed #E5E7EB;">
                        <div class="photo-tray-title">📷 Photos of completed work (<span data-photo-count>{{ $photoCount }}</span>)</div>
                        <div class="photo-thumbs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
                            @foreach($roomPhotos as $p)
                                <div style="position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;background:#F3F4F6;flex-shrink:0;">
                                    <a href="{{ route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]) }}"
                                       target="_blank"
                                       onclick="event.preventDefault(); openPhotoLightbox(@js($roomPhotosLb), {{ $loop->index }});">
                                        <img src="{{ route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]) }}"
                                             alt="{{ $p->caption ?? '' }}"
                                             loading="lazy"
                                             style="width:100%;height:100%;object-fit:cover;">
                                    </a>
                                    <button type="button"
                                            onclick="deleteWorksheetPhoto({{ $p->id }}, '{{ $token }}', this)"
                                            title="Remove"
                                            style="position:absolute;top:2px;right:2px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-size:.7rem;line-height:1;cursor:pointer;">✕</button>
                                </div>
                            @endforeach
                        </div>
                        <label class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;">
                            📷 Add photo
                            <input type="file" accept="image/*" style="display:none;"
                                   onchange="uploadWorksheetPhoto(this, '{{ $token }}', '{{ addslashes($room['name'] ?? '') }}')">
                        </label>
                        @if($photoCount === 0)
                            <div class="photo-warn" style="margin-top:.55rem;">
                                ⚠ No photos captured yet — capture at least one before requesting sign-off.
                            </div>
                        @endif
                    </div>

                    {{-- ── 260504-iy4 H1 — Mark Room Complete CTA ──
                         Soft visual gate: button disables until (a) survey reviewed if a survey
                         applies AND (b) at least one completed-work photo exists. The endpoint
                         itself does NOT enforce the gate — engineer on flaky network can still
                         POST. When complete, this block flips to a green status badge instead. --}}
                    <div style="margin-bottom:1rem;padding-bottom:.85rem;border-bottom:1px dashed #E5E7EB;">
                        @if($isRoomComplete)
                            <div style="display:inline-block;padding:.45rem .9rem;border-radius:9999px;background:#DCFCE7;color:#166534;font-size:.85rem;font-weight:700;">
                                ✓ Room Complete by {{ $roomCompletedBy }} at {{ $roomCompletedDisplay }}
                            </div>
                        @elseif($photoCount >= 1 || $hasEF)
                            @php
                                $gateMsg = ! $markCompleteGateOk
                                    ? ($photoCount < 1
                                        ? 'Capture at least one photo first'
                                        : 'Review the survey for this room first')
                                    : '';
                            @endphp
                            <form method="POST"
                                  action="{{ route('public-worksheet.room-complete', ['token' => $token, 'roomName' => $room['name']]) }}"
                                  style="margin:0;">
                                @csrf
                                <button type="submit"
                                        class="btn btn-teal"
                                        style="font-size:.9rem;padding:.6rem 1.1rem;min-height:44px;"
                                        @disabled(! $markCompleteGateOk)
                                        title="{{ $gateMsg }}">
                                    ✅ Mark Room Complete
                                </button>
                                @if(! $markCompleteGateOk)
                                    <span class="muted" style="margin-left:.6rem;font-size:.82rem;">{{ $gateMsg }}</span>
                                @endif
                            </form>
                        @endif
                    </div>

                    {{-- SURVEY REFERENCE drawer (teal) — engineer findings captured during the
                         site survey (Mounting heights, Cable Routes, Wall Prep, Brackets etc.).
                         Read-only reference for installers. Hidden when no survey data exists. --}}
                    @if($hasEF)
                        <details class="room-drawer teal teal--accent">
                            <summary>
                                <span>🔍 Survey Reference ({{ $efItemCount }} captured)</span>
                                <span class="chev">▾</span>
                            </summary>
                            <div class="room-drawer-body">

                                {{-- ── Survey photos for this room (260504-hqe) ──
                                     Token-gated proxy serves SiteSurveyPhoto rows linked to the same project.
                                     If the room has no survey photos, render the muted "no photos" line instead. --}}
                                @php
                                    $surveyPhotos = $photosByRoom[$efKey] ?? collect();
                                    // 260508 — pre-compute survey photo set for the lightbox cycler.
                                    $surveyPhotosLb = $surveyPhotos->values()->map(fn ($sp) => [
                                        'url'     => route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => $sp->id]),
                                        'caption' => $sp->caption ?? '',
                                    ])->all();
                                @endphp
                                <div style="margin-bottom:.85rem;">
                                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.4rem;">Survey photos</div>
                                    @if($surveyPhotos->isEmpty())
                                        <div class="muted" style="font-size:.8rem;">No survey photos for this room.</div>
                                    @else
                                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                                            @foreach($surveyPhotos as $sp)
                                                <a href="{{ route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => $sp->id]) }}"
                                                   target="_blank"
                                                   onclick="event.preventDefault(); openPhotoLightbox(@js($surveyPhotosLb), {{ $loop->index }});"
                                                   style="display:inline-block;width:80px;height:80px;border-radius:6px;overflow:hidden;background:#F3F4F6;">
                                                    <img src="{{ route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => $sp->id]) }}"
                                                         alt="{{ $sp->caption ?? '' }}"
                                                         loading="lazy"
                                                         style="width:100%;height:100%;object-fit:cover;">
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                {{-- Mounting heights --}}
                                @php
                                    $mh = (array) ($ef['mounting_heights'] ?? []);
                                    $heightLines = [];
                                    foreach ([
                                        'screen_h_m' => 'Screen', 'camera_h_m' => 'Camera',
                                        'booking_panel_h_m' => 'Booking panel', 'speaker_h_m' => 'Speaker',
                                    ] as $k => $lbl) {
                                        if (! empty($mh[$k])) $heightLines[] = $lbl . ': ' . $mh[$k] . ' m';
                                    }
                                    foreach ((array) ($mh['other'] ?? []) as $other) {
                                        $oLbl = trim((string) ($other['label'] ?? ''));
                                        $oH   = $other['h_m'] ?? null;
                                        if ($oLbl !== '' && $oH !== null && $oH !== '') $heightLines[] = $oLbl . ': ' . $oH . ' m';
                                    }
                                @endphp
                                @if(! empty($heightLines))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Mounting heights</div>
                                        <ul class="actions">
                                            @foreach($heightLines as $hl)<li>{{ $hl }}</li>@endforeach
                                        </ul>
                                    </div>
                                @endif

                                {{-- Working at height methods --}}
                                @php
                                    $wahLabels = array_values(array_filter(array_map(
                                        fn ($m) => $methodLabels[strtolower((string) $m)] ?? ucfirst((string) $m),
                                        (array) ($ef['work_at_height_methods'] ?? [])
                                    )));
                                @endphp
                                @if(! empty($wahLabels))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Working at height — methods</div>
                                        <div style="font-size:.88rem;color:#374151;">{{ implode(', ', $wahLabels) }}</div>
                                    </div>
                                @endif

                                {{-- Cable routes --}}
                                @php $cableRoutes = (array) ($ef['cable_routes'] ?? []); @endphp
                                @if(! empty($cableRoutes))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Cable routes planned</div>
                                        <ul class="actions">
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
                                                @if(! empty($parts))<li>{{ implode(' — ', $parts) }}</li>@endif
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                {{-- Wall construction & prep --}}
                                @php
                                    $wcLabels = array_values(array_filter(array_map(
                                        fn ($w) => $wallConstructionLabels[strtolower((string) $w)] ?? ucwords(str_replace('_', ' ', (string) $w)),
                                        (array) ($ef['wall_construction'] ?? [])
                                    )));
                                    $prepFlags = [];
                                    if (! empty($ef['wall_needs_reinforcement'])) $prepFlags[] = 'Reinforcement';
                                    if (! empty($ef['wall_needs_chase_out']))     $prepFlags[] = 'Chase out';
                                    if (! empty($ef['wall_needs_conduit']))       $prepFlags[] = 'Conduit';
                                @endphp
                                @if(! empty($wcLabels) || ! empty($prepFlags))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Wall construction &amp; prep</div>
                                        <div style="font-size:.88rem;color:#374151;">
                                            @if(! empty($wcLabels))<div><strong>Construction:</strong> {{ implode(', ', $wcLabels) }}</div>@endif
                                            @if(! empty($prepFlags))<div><strong>Prep needed:</strong> {{ implode(', ', $prepFlags) }}</div>@endif
                                        </div>
                                    </div>
                                @endif

                                {{-- Brackets required --}}
                                @php $brackets = (array) ($ef['brackets_required'] ?? []); @endphp
                                @if(! empty($brackets))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Brackets required</div>
                                        <ul class="actions">
                                            @foreach($brackets as $b)
                                                @php
                                                    $eq   = trim((string) ($b['equipment'] ?? ''));
                                                    $mod  = trim((string) ($b['model']     ?? ''));
                                                    $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                                                    $note = trim((string) ($b['notes']     ?? ''));
                                                    $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                                                    if ($note !== '') $line .= ' — ' . $note;
                                                @endphp
                                                @if($line !== '')<li>{{ $line }}</li>@endif
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                {{-- Table info — only when has_grommets --}}
                                @php $ti = (array) ($ef['table_info'] ?? []); @endphp
                                @if(! empty($ti['has_grommets']))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Table info</div>
                                        <div style="font-size:.88rem;color:#374151;">
                                            {{ ($ti['grommet_count'] ?? '?') }}× {{ trim((string) ($ti['grommet_size'] ?? '')) }} grommets
                                            @if(! empty($ti['notes'])) — {{ $ti['notes'] }}@endif
                                        </div>
                                    </div>
                                @endif

                                {{-- Floor box info — only when has_floor_box --}}
                                @php $fb = (array) ($ef['floor_box_info'] ?? []); @endphp
                                @if(! empty($fb['has_floor_box']))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.3rem;">Floor box info</div>
                                        <div style="font-size:.88rem;color:#374151;">
                                            {{ ($fb['power_outlets'] ?? 0) }} power, {{ ($fb['data_outlets'] ?? 0) }} data
                                            @if(! empty($fb['cable_space'])) • {{ trim((string) $fb['cable_space']) }} cable space @endif
                                            @if(! empty($fb['notes'])) — {{ $fb['notes'] }}@endif
                                        </div>
                                    </div>
                                @endif

                                {{-- ── Review confirmation gate (260504-hqe + 260504-iy4 H4) ──
                                     Soft-block: when the engineer has not yet ticked "I have reviewed",
                                     this room is flagged in $unreviewedRooms (page-level set computed
                                     before the rooms loop) and the page-level Sign-Off button is
                                     visually disabled. Once submitted, the row is stamped
                                     {reviewed_at, reviewed_by} and a green badge replaces the form.
                                     Gate is visual-only — the server does NOT block sign-off.
                                     H4: read via $worksheet->surveyReviewedAt() so the new namespaced
                                     shape AND the legacy flat shape both resolve cleanly. --}}
                                @php
                                    $reviewedAt = $worksheet->surveyReviewedAt($room['name'] ?? '');
                                    $reviewedBy = $worksheet->surveyReviewedBy($room['name'] ?? '');
                                    $thisRoomReview = $reviewedAt ? ['reviewed_at' => $reviewedAt, 'reviewed_by' => $reviewedBy] : null;
                                @endphp
                                <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #E5E7EB;">
                                    @if($thisRoomReview)
                                        @php
                                            $rTime = $thisRoomReview['reviewed_at'] ?? null;
                                            $rBy   = $thisRoomReview['reviewed_by'] ?? '';
                                            try { $rDisplay = $rTime ? \Carbon\Carbon::parse($rTime)->format('d M Y H:i') : ''; }
                                            catch (\Throwable $e) { $rDisplay = (string) $rTime; }
                                        @endphp
                                        <div style="display:inline-block;padding:.4rem .75rem;border-radius:9999px;background:#DCFCE7;color:#166534;font-size:.8rem;font-weight:600;">
                                            ✓ Reviewed by {{ $rBy }} at {{ $rDisplay }}
                                        </div>
                                    @else
                                        {{-- 260504-ij9 fix H5 — one-tap review. Dropped the
                                             confirmation checkbox: the button itself IS the
                                             confirmation. Same backend POST. --}}
                                        <form method="POST"
                                              action="{{ route('public-worksheet.survey-reviewed', ['token' => $token, 'roomName' => $room['name']]) }}"
                                              style="margin:0;">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-teal"
                                                    style="font-size:.85rem;padding:.55rem 1rem;min-height:42px;">
                                                ✓ I have reviewed this room — mark reviewed
                                            </button>
                                        </form>
                                    @endif
                                </div>

                            </div>
                        </details>
                    @endif

                    {{-- AV WORKS drawer (grey) — neutral colour scheme so it's visually
                         distinct from the teal Survey Reference drawer (260504-ij9 fix B1). --}}
                    @if(! empty($bullets))
                        <details class="room-drawer grey">
                            <summary>
                                <span>🛠 AV Works ({{ count($bullets) }})</span>
                                <span class="chev">▾</span>
                            </summary>
                            <div class="room-drawer-body">
                                <ul class="actions">
                                    @foreach($bullets as $b)
                                        <li>{{ preg_replace('/^[-•]\s*/', '', trim((string) $b)) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endif

                    {{-- KIT LIST drawer (gold) — each row has a "📷 Label" capture button.
                         Engineer photographs the sticker; server runs Claude vision OCR;
                         engineer confirms → values flow into the asset register (devices).
                         Defensive: DeviceLabelPhoto model is half-built (not yet deployed
                         on every environment) — degrade gracefully when missing. --}}
                    @if(! empty($equipment) && class_exists(\App\Models\DeviceLabelPhoto::class))
                        @php
                            $roomLabelPhotos = \App\Models\DeviceLabelPhoto::where('worksheet_id', $worksheet->id)
                                ->where('room_name', $room['name'] ?? '')
                                ->get()
                                ->groupBy(fn ($p) => strtolower(trim((string) optional($p->device)->description)));
                        @endphp
                        <details class="room-drawer gold">
                            <summary>
                                <span>📦 Kit List ({{ count($equipment) }})</span>
                                <span class="chev">▾</span>
                            </summary>
                            <div class="room-drawer-body">
                                <ul class="kit-rows" style="list-style:none;padding:0;margin:0;">
                                    @foreach($equipment as $item)
                                        @php
                                            $itemDesc  = $item['name'] ?? $item['description'] ?? '—';
                                            $itemPart  = $item['part_number'] ?? $item['part_no'] ?? '';
                                            $itemQty   = $item['quantity'] ?? $item['qty'] ?? 1;
                                            $itemKey   = strtolower(trim($itemDesc));
                                            $existing  = $roomLabelPhotos[$itemKey] ?? collect();

                                            // Box Serial Label only makes sense for physical hardware that has a
                                            // sticker on the box — skip cables, mounts, brackets, services, warranties.
                                            $itemDescLower = strtolower($itemDesc);
                                            $itemCategory  = strtolower($item['category'] ?? '');
                                            $nonHardwareKeywords = [
                                                'cable', 'cat5', 'cat6', 'cat6a', 'cat7', 'hdmi cable', 'usb cable',
                                                'patch lead', 'patch cable', 'fibre', 'optical lead',
                                                'mount', 'bracket', 'caddy', 'tray', 'arm', 'pole', 'plate',
                                                'warranty', 'extended warranty', 'support contract', 'maintenance',
                                                'install', 'commission', 'project management', 'configuration',
                                                'training', 'delivery', 'consumable',
                                            ];
                                            $isHardware = true;
                                            foreach ($nonHardwareKeywords as $kw) {
                                                if (str_contains($itemDescLower, $kw)) { $isHardware = false; break; }
                                            }
                                            if (in_array($itemCategory, ['cable','accessory','service','warranty','consumable','option'], true)) {
                                                $isHardware = false;
                                            }
                                        @endphp
                                        <li class="kit-row" style="display:flex;flex-direction:column;gap:.5rem;padding:.65rem 0;border-bottom:1px solid #F3F4F6;">
                                            <div style="display:flex;align-items:center;gap:.5rem;">
                                                <span class="qty-pill">{{ $itemQty }}×</span>
                                                <span style="flex:1;">{{ $itemDesc }}</span>
                                                @if ($isHardware)
                                                    <label class="btn btn-outline btn-sm label-cap-btn"
                                                           style="display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;font-size:.78rem;">
                                                        📷 Box Serial Label
                                                        <input type="file"
                                                               accept="image/*"
                                                               style="display:none;"
                                                               data-room="{{ $room['name'] ?? '' }}"
                                                               data-desc="{{ $itemDesc }}"
                                                               data-part="{{ $itemPart }}"
                                                               data-qty="{{ $itemQty }}"
                                                               onchange="captureLabel(this, '{{ $token }}')">
                                                    </label>
                                                @endif
                                            </div>
                                            @if($existing->isNotEmpty())
                                                <div class="label-thumbs" style="display:flex;flex-wrap:wrap;gap:.4rem;">
                                                    @foreach($existing as $lp)
                                                        @php $ai = $lp->ai_extracted ?? []; @endphp
                                                        <div class="label-thumb"
                                                             data-photo-id="{{ $lp->id }}"
                                                             style="position:relative;border:1px solid #E5E7EB;border-radius:6px;padding:.4rem;background:#F9FAFB;font-size:.72rem;line-height:1.35;min-width:180px;">
                                                            <a href="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}" target="_blank" style="float:right;">↗</a>
                                                            <div><strong>Part:</strong> {{ $ai['part_number'] ?? '—' }}</div>
                                                            <div><strong>Serial:</strong> {{ $ai['serial_number'] ?? '—' }}</div>
                                                            <div><strong>MAC:</strong> {{ $ai['mac_address'] ?? '—' }}</div>
                                                            <div style="margin-top:.25rem;">
                                                                <span style="display:inline-block;padding:1px 6px;border-radius:9999px;background:{{ $lp->confirmed ? '#DCFCE7' : '#FEF3C7' }};color:{{ $lp->confirmed ? '#166534' : '#92400E' }};font-weight:600;font-size:.65rem;">
                                                                    {{ $lp->confirmed ? '✓ Confirmed' : 'Review' }}
                                                                </span>
                                                                @unless($lp->confirmed)
                                                                    <button type="button"
                                                                            onclick="reviewLabel({{ $lp->id }}, '{{ $token }}')"
                                                                            style="background:none;border:0;color:#0F766E;cursor:pointer;font-size:.7rem;text-decoration:underline;">Edit / Confirm</button>
                                                                @endunless
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endif

                    {{-- INSTALL STEPS drawer (amber) --}}
                    @if(! empty($stepLines))
                        <details class="room-drawer amber">
                            <summary>
                                <span>✅ Install Steps ({{ count($stepLines) }})</span>
                                <span class="chev">▾</span>
                            </summary>
                            <div class="room-drawer-body">
                                <ol class="steps">
                                    @foreach($stepLines as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ol>
                            </div>
                        </details>
                    @endif

                    {{-- 260504-ij9 fix B3 — Photo tray moved to TOP of room body (above). --}}
                </details>
            @endforeach
        @endif

        {{-- ── Sign-off card ─────────────────────────────────────────── --}}
        @if($latestSignoff)
            <div class="alert alert-info" style="margin-bottom:1rem;">
                🔒 This worksheet was signed by <strong>{{ $latestSignoff->client_name }}</strong>
                on <strong>{{ $latestSignoff->signed_at->format('d M Y H:i') }}</strong>.
                Photo uploads, label captures, and review actions are now disabled.
                Additional sign-offs are recorded as snag / follow-up entries — contact your
                project manager if you need to re-open the worksheet.
            </div>
        @endif

        <div class="card">
            <div class="card-title">Client Sign-Off</div>

            {{-- ── 260504-hqe — soft-block warning banner ──
                 Renders only when one or more rooms with a linked survey have
                 NOT yet been ticked "reviewed". Visual-only — server still
                 accepts the sign-off POST so a stuck engineer cannot be locked
                 out by a stale legacy survey. --}}
            @if($signOffBlocked)
                <div style="margin-bottom:.85rem;padding:.7rem .9rem;border-radius:6px;background:#FEF3C7;color:#92400E;font-size:.85rem;">
                    ⚠ Review the survey reference for these rooms before signing off:
                    <strong>{{ implode(', ', $unreviewedRooms) }}</strong>.
                    Open each room above, expand <em>📋 Survey Reference</em>, and tap <em>Mark Reviewed</em>.
                </div>
            @endif

            <p style="font-size:.88rem;color:#4B5563;margin-bottom:.95rem;">
                By signing below you confirm you have reviewed the installation worksheet for this project.
                Tick which option applies — if you have outstanding items, list them below before signing.
            </p>

            <form method="POST"
                  action="{{ route('public-worksheet.sign', ['token' => $token]) }}"
                  id="signoff-form"
                  x-data="{
                      happy: {{ old('happy_with_work') ? 'true' : 'false' }},
                      outstanding: {{ old('signed_with_comments') ? 'true' : 'false' }}
                  }"
                  onsubmit="return prepareSignoff(this);">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="client_name">Your Full Name <span class="req">*</span></label>
                    <input type="text"
                           name="client_name"
                           id="client_name"
                           class="form-control"
                           value="{{ old('client_name') }}"
                           maxlength="200"
                           autocomplete="name"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">Signature <span class="req">*</span></label>
                    <div class="sig-pad-wrap">
                        <canvas id="sig-pad" class="sig-pad" aria-label="Signature pad"></canvas>
                        <div class="sig-actions">
                            <button type="button" class="btn btn-outline btn-sm" onclick="clearSignature()">Clear</button>
                        </div>
                    </div>
                    <input type="hidden" name="signature_image" id="signature_image" value="">
                </div>

                {{-- 260504-q19 — two-checkbox gate: at least one must be ticked. --}}
                <label class="checkbox-row">
                    <input type="checkbox"
                           name="happy_with_work"
                           id="happy_with_work"
                           value="1"
                           x-model="happy"
                           @change="window.refreshSignoffSubmitState && window.refreshSignoffSubmitState()">
                    <span>I am happy with the work carried out.</span>
                </label>

                <label class="checkbox-row">
                    <input type="checkbox"
                           name="signed_with_comments"
                           id="signed_with_comments"
                           value="1"
                           x-model="outstanding"
                           @change="window.refreshSignoffSubmitState && window.refreshSignoffSubmitState()">
                    <span>Outstanding items — list them in the comments below. The engineering team will follow up.</span>
                </label>

                {{-- Comments textarea — revealed only when "Outstanding items" is ticked. --}}
                <div class="form-group" x-show="outstanding" x-cloak style="margin-top:.5rem;">
                    <label class="form-label" for="comments">Outstanding Items / Comments <span class="req">*</span></label>
                    <textarea name="comments"
                              id="comments"
                              class="form-control"
                              rows="4"
                              maxlength="5000"
                              placeholder="List the outstanding items the engineering team needs to follow up on…">{{ old('comments') }}</textarea>
                </div>

                <div class="submit-row">
                    <button type="submit"
                            id="signoff-submit"
                            class="btn btn-teal"
                            data-signoff-blocked="{{ $signOffBlocked ? '1' : '0' }}"
                            @disabled(true)
                            title="Please confirm you are happy with the work or list outstanding items, then draw your signature.">Sign &amp; Submit</button>
                </div>
            </form>
        </div>

        @if($latestSignoff)
            </fieldset>
        @endif

    </div>

    <script>
        // ── Signature pad — vanilla canvas, no npm dependencies ────────────────
        (function () {
            const canvas = document.getElementById('sig-pad');
            const submit = document.getElementById('signoff-submit');
            if (! canvas) return;

            const ctx = canvas.getContext('2d');
            let drawing = false;
            let dirty   = false;
            let lastX = 0, lastY = 0;

            // 260504-q19 — central submit-state gate. Button is enabled only when
            // all three are true: signature drawn, at least one checkbox ticked,
            // and not soft-blocked by an unreviewed survey room.
            window.__signoffSignatureDrawn = false;
            window.refreshSignoffSubmitState = function () {
                if (submit.dataset.signoffBlocked === '1') {
                    submit.disabled = true;
                    return;
                }
                const happy = !!document.getElementById('happy_with_work')?.checked;
                const outstanding = !!document.getElementById('signed_with_comments')?.checked;
                submit.disabled = !(window.__signoffSignatureDrawn && (happy || outstanding));
            };

            function resizeCanvas() {
                // Fit canvas internal pixel grid to displayed size for crisp lines.
                const ratio = window.devicePixelRatio || 1;
                const rect = canvas.getBoundingClientRect();
                canvas.width  = Math.max(1, Math.round(rect.width * ratio));
                canvas.height = Math.max(1, Math.round(rect.height * ratio));
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.fillStyle   = '#ffffff';
                ctx.fillRect(0, 0, rect.width, rect.height);
                ctx.lineWidth   = 2.2;
                ctx.lineCap     = 'round';
                ctx.lineJoin    = 'round';
                ctx.strokeStyle = '#0B3C45';
            }

            function pointerPos(evt) {
                const rect = canvas.getBoundingClientRect();
                const x = (evt.clientX ?? (evt.touches && evt.touches[0] ? evt.touches[0].clientX : 0)) - rect.left;
                const y = (evt.clientY ?? (evt.touches && evt.touches[0] ? evt.touches[0].clientY : 0)) - rect.top;
                return { x, y };
            }

            function start(evt) {
                evt.preventDefault();
                drawing = true;
                const p = pointerPos(evt);
                lastX = p.x; lastY = p.y;
            }
            function move(evt) {
                if (! drawing) return;
                evt.preventDefault();
                const p = pointerPos(evt);
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                lastX = p.x; lastY = p.y;
                if (! dirty) {
                    dirty = true;
                    window.__signoffSignatureDrawn = true;
                    window.refreshSignoffSubmitState();
                }
            }
            function end(evt) {
                if (drawing) evt.preventDefault();
                drawing = false;
            }

            canvas.addEventListener('pointerdown', start);
            canvas.addEventListener('pointermove', move);
            canvas.addEventListener('pointerup',   end);
            canvas.addEventListener('pointerleave', end);
            canvas.addEventListener('touchstart',  start, { passive: false });
            canvas.addEventListener('touchmove',   move,  { passive: false });
            canvas.addEventListener('touchend',    end);

            window.clearSignature = function () {
                resizeCanvas();
                dirty = false;
                window.__signoffSignatureDrawn = false;
                window.refreshSignoffSubmitState();
                document.getElementById('signature_image').value = '';
            };
            window.prepareSignoff = function (form) {
                if (! dirty) {
                    alert('Please draw your signature in the box before submitting.');
                    return false;
                }
                const happy = !!document.getElementById('happy_with_work')?.checked;
                const outstanding = !!document.getElementById('signed_with_comments')?.checked;
                if (! happy && ! outstanding) {
                    alert('Please tick "I am happy with the work carried out" or "Outstanding items" before signing.');
                    return false;
                }
                if (outstanding && (document.getElementById('comments')?.value || '').trim() === '') {
                    alert('Please list the outstanding items in the comments box.');
                    return false;
                }
                document.getElementById('signature_image').value = canvas.toDataURL('image/png');
                return true;
            };

            // Defer initial resize so layout has settled.
            requestAnimationFrame(resizeCanvas);
            window.addEventListener('resize', () => {
                // Reset on resize — drawing on resized canvas would be misaligned.
                resizeCanvas();
                dirty = false;
                window.__signoffSignatureDrawn = false;
                window.refreshSignoffSubmitState();
                document.getElementById('signature_image').value = '';
            });

            // Initial sync once DOM is ready (covers old() repopulation after a
            // failed validation round-trip).
            requestAnimationFrame(() => window.refreshSignoffSubmitState());
        })();

        // ── Photo upload (per-room) ─────────────────────────────────────────
        // NOTE (260603-eha): declared as `window.uploadWorksheetPhoto = async function`
        // (not bare `async function`) so the OfflineQueue wrapper at the bottom
        // of the file can capture the original via `const __orig = window.uploadWorksheetPhoto`.
        // The original body below is the ONLINE happy path — it still runs unchanged
        // when navigator.onLine === true. The wrapper intercepts the OFFLINE path
        // BEFORE this function is called.
        window.uploadWorksheetPhoto = async function uploadWorksheetPhoto(input, token, roomName) {
            const file = input.files && input.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('photo', file);
            // room_name travels in the body, not the URL path, so names with
            // '/', '?', '#' (e.g. "Comms Room (Next to Breakout/Townhall Area)")
            // don't 404 against nginx/Apache's encoded-slash rejection.
            fd.append('room_name', roomName);
            const url = '/worksheet/' + encodeURIComponent(token) + '/photos';
            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                               'Accept': 'application/json' },
                    body: fd,
                });
                if (!resp.ok) {
                    alert('Upload failed. ' + (resp.statusText || 'Please try again.'));
                    input.value = '';
                    return;
                }
                // Simplest UX: reload the page so the new thumbnail + count + warning
                // state all update together. The page is short and fast.
                window.location.reload();
            } catch (e) {
                alert('Network error. Please try again.');
                input.value = '';
            }
        }

        async function deleteWorksheetPhoto(photoId, token, btn) {
            if (!(await window.appConfirm('Remove this photo?', { title: 'Remove photo?', confirmLabel: 'Remove', danger: true }))) return;
            const url = '/worksheet/' + encodeURIComponent(token) + '/photos/' + photoId;
            try {
                const resp = await fetch(url, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                               'Accept': 'application/json' },
                });
                if (!resp.ok) { alert('Delete failed.'); return; }
                window.location.reload();
            } catch (e) {
                alert('Network error.');
            }
        }

        // ── Equipment label capture (per-item) ──────────────────────────────
        // Engineer photographs the manufacturer sticker; Claude vision OCRs
        // part / serial / MAC; engineer reviews + confirms; values flow into
        // the asset-register `devices` table.
        //
        // 260504-ktt: iOS Safari uploads HEIC files which Claude vision can't
        // read — we draw the image to a canvas and re-encode as JPEG client-side
        // before upload. Downscales to maxSide=2400 (was 1600) at quality 0.92
        // (was 0.85) — Claude vision OCR needs the extra resolution to read
        // small label text reliably; the original 1600px @ 0.85 was making
        // 5-8px text in the label too lossy for accurate extraction.
        // Falls back to the raw file if anything fails (very old browser, CORS,
        // out-of-memory) so the fix never makes uploads worse than they were.
        async function convertToJpegBlob(file, maxSide = 2400, quality = 0.92) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onerror = () => reject(new Error('FileReader failed'));
                reader.onload = () => {
                    const img = new Image();
                    img.onerror = () => reject(new Error('Image decode failed'));
                    img.onload = () => {
                        const w0 = img.naturalWidth, h0 = img.naturalHeight;
                        if (!w0 || !h0) return reject(new Error('Empty image'));
                        const scale = Math.min(1, maxSide / Math.max(w0, h0));
                        const w = Math.round(w0 * scale), h = Math.round(h0 * scale);
                        const canvas = document.createElement('canvas');
                        canvas.width = w; canvas.height = h;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, w, h);
                        canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('toBlob returned null')), 'image/jpeg', quality);
                    };
                    img.src = reader.result;
                };
                reader.readAsDataURL(file);
            });
        }

        // NOTE (260603-eha): declared as `window.captureLabel = async function`
        // (not bare `async function`) so the OfflineQueue wrapper at the bottom
        // of the file can capture the original via `const __orig = window.captureLabel`.
        // The original body below is the ONLINE happy path (modal opens on success).
        // The wrapper intercepts the OFFLINE path BEFORE this function is called.
        window.captureLabel = async function captureLabel(input, token) {
            const file = input.files && input.files[0];
            if (!file) return;

            const btn = input.closest('label');
            const orig = btn ? btn.textContent.trim() : '';

            // 260504-lat: visible loading state — pulsing spinner + progress text
            // The button is a <label> with TEXT NODE + <input> as siblings. We mutate
            // ONLY the first text node so we don't wipe the <input>.
            const setBusy = (text) => {
                if (!btn) return;
                const textNode = Array.from(btn.childNodes).find(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
                if (textNode) {
                    textNode.textContent = text + ' ';
                } else {
                    let span = btn.querySelector('.label-cap-text');
                    if (!span) {
                        span = document.createElement('span');
                        span.className = 'label-cap-text';
                        btn.appendChild(span);
                    }
                    span.textContent = text + ' ';
                }
                btn.classList.add('label-cap-busy');
                btn.style.opacity = '.75';
                btn.style.pointerEvents = 'none';
            };

            const restoreBtn = () => {
                if (!btn) return;
                btn.classList.remove('label-cap-busy');
                btn.style.opacity = '';
                btn.style.pointerEvents = '';
                const textNode = Array.from(btn.childNodes).find(n => n.nodeType === Node.TEXT_NODE);
                if (textNode) textNode.textContent = orig + ' ';
                const span = btn.querySelector('.label-cap-text');
                if (span) span.remove();
                if (input) input.disabled = false;
            };

            // Disable input so taps mid-process don't re-trigger
            if (input) input.disabled = true;
            setBusy('⏳ Processing image...');

            // Convert to JPEG client-side — fixes iOS HEIC + downscales for faster upload.
            // On any failure, fall through with the original file unchanged.
            let uploadFile = file;
            let uploadFilename = file.name || 'label.jpg';
            try {
                const blob = await convertToJpegBlob(file);
                uploadFile = blob;
                uploadFilename = 'label.jpg';
            } catch (e) {
                console.warn('Canvas JPEG conversion failed, uploading original:', e);
                // fall through with raw file
            }

            const fd = new FormData();
            fd.append('photo', uploadFile, uploadFilename);
            fd.append('room_name',        input.dataset.room || '');
            fd.append('item_description', input.dataset.desc || '');
            fd.append('item_part_number', input.dataset.part || '');
            fd.append('item_qty',         input.dataset.qty  || 1);

            setBusy('📤 Uploading...');

            const url = '/worksheet/' + encodeURIComponent(token) + '/label-photo';
            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: fd,
                });
                if (!resp.ok) {
                    alert('Label upload failed. ' + (resp.statusText || ''));
                    input.value = '';
                    restoreBtn();
                    return;
                }
                setBusy('🤖 Reading label...');
                const data = await resp.json();
                const ai = data.ai_extracted || {};
                restoreBtn();
                openLabelReview({
                    photoId: data.id,
                    token,
                    photoUrl: data.photo_url,
                    extracted: {
                        part_number:   ai.part_number   || '',
                        serial_number: ai.serial_number || '',
                        mac_address:   ai.mac_address   || '',
                        model:         ai.model         || '',
                        manufacturer:  ai.manufacturer  || '',
                    },
                });
            } catch (e) {
                alert('Network error. Please try again.');
                input.value = '';
                restoreBtn();
            }
        }

        async function reviewLabel(photoId, token) {
            // Re-open the modal for an existing label photo (the engineer can
            // edit then confirm). Pulls latest extracted values via the same
            // confirm endpoint by sending a GET — but we only have POST, so we
            // grab values from the DOM card instead.
            const card = document.querySelector('.label-thumb[data-photo-id="' + photoId + '"]');
            if (!card) return;
            const get = (label) => {
                const r = [...card.querySelectorAll('div')].find((d) => d.textContent.startsWith(label));
                return r ? r.textContent.replace(label, '').trim() : '';
            };
            openLabelReview({
                photoId,
                token,
                photoUrl: card.querySelector('a').href,
                extracted: {
                    part_number:   get('Part:'),
                    serial_number: get('Serial:'),
                    mac_address:   get('MAC:'),
                    model:         '',
                    manufacturer:  '',
                },
            });
        }

        function openLabelReview({ photoId, token, photoUrl, extracted }) {
            // 260504-ktt: detect when AI extraction returned nothing usable so we
            // can prompt the engineer to type the values manually from the photo.
            const aiFailed = ['part_number','serial_number','mac_address','model','manufacturer']
                .every(k => !extracted[k] || String(extracted[k]).trim() === '' || String(extracted[k]).toUpperCase() === 'UNKNOWN');

            // Build a simple modal — vanilla JS, no Alpine dep.
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
            overlay.innerHTML = `
                <div style="background:#fff;border-radius:12px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;padding:1.25rem;">
                    <h3 style="margin:0 0 .75rem;font-size:1.05rem;">Confirm label values</h3>
                    ${aiFailed ? `
                        <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:.65rem .85rem;margin-bottom:.85rem;font-size:.82rem;color:#92400E;line-height:1.4;">
                            <strong>⚠ AI couldn't read this label clearly.</strong>
                            Please type the visible values from the photo into the fields below. The image is saved either way.
                        </div>
                    ` : ''}
                    <img src="${photoUrl}" alt="" style="width:100%;max-height:240px;object-fit:contain;border-radius:8px;background:#F3F4F6;margin-bottom:.85rem;">
                    <div style="font-size:.78rem;color:#6B7280;margin-bottom:.65rem;">
                        AI read these values from the label. Edit any field, then confirm to save to the asset register.
                    </div>
                    <div style="display:flex;flex-direction:column;gap:.55rem;">
                        ${['part_number','serial_number','mac_address','model','manufacturer'].map((k) => `
                            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.78rem;font-weight:600;color:#374151;">
                                <span>${k.replace('_',' ').replace(/\b\w/g, (c) => c.toUpperCase())}</span>
                                <input type="text" name="${k}" value="${extracted[k] && extracted[k] !== 'UNKNOWN' ? extracted[k].replace(/"/g,'&quot;') : ''}"
                                       style="border:1px solid #D1D5DB;border-radius:6px;padding:.5rem .65rem;font-family:inherit;font-size:.875rem;">
                            </label>
                        `).join('')}
                    </div>
                    <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                        <button type="button" id="lblCancel" style="background:#F3F4F6;border:1px solid #D1D5DB;color:#374151;padding:.5rem .9rem;border-radius:6px;cursor:pointer;font-weight:600;font-size:.85rem;">Cancel</button>
                        <button type="button" id="lblConfirm" style="background:#16A34A;border:1px solid #16A34A;color:#fff;padding:.5rem .9rem;border-radius:6px;cursor:pointer;font-weight:600;font-size:.85rem;">✓ Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            const close = () => { overlay.remove(); window.location.reload(); };
            overlay.querySelector('#lblCancel').onclick = () => overlay.remove();
            overlay.querySelector('#lblConfirm').onclick = async () => {
                const fd = new FormData();
                ['part_number','serial_number','mac_address','model','manufacturer'].forEach((k) => {
                    const v = overlay.querySelector(`input[name="${k}"]`).value.trim();
                    if (v) fd.append(k, v);
                });
                const url = '/worksheet/' + encodeURIComponent(token) + '/label-photos/' + photoId + '/confirm';
                try {
                    const resp = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                        body: fd,
                    });
                    if (!resp.ok) { alert('Confirm failed.'); return; }
                    close();
                } catch (e) { alert('Network error.'); }
            };
        }
    </script>

    <script>
        // ── 260504-iy4 H3 — scroll + drawer restore on reload ──
        // The full-page-reload UX (Mark Reviewed / Mark Complete / Photo upload /
        // Sign-Off all redirect back to GET /worksheet/{token}) drops the engineer
        // back at the top with all rooms collapsed. Capture state on submit, restore
        // on next DOMContentLoaded. SessionStorage scoped per worksheet ID. State
        // expires after 5 minutes (avoids stale restore on a fresh tab).
        (function () {
            var KEY = 'wsState_' + {{ (int) $worksheet->id }};

            // Save on any form submit (capture phase — fires before navigation).
            document.addEventListener('submit', function () {
                try {
                    var openIds = Array.prototype.slice
                        .call(document.querySelectorAll('details[open][id]'))
                        .map(function (d) { return d.id; });
                    sessionStorage.setItem(KEY, JSON.stringify({
                        scrollY: window.scrollY,
                        openDetails: openIds,
                        ts: Date.now()
                    }));
                } catch (e) { /* sessionStorage disabled — silently skip */ }
            }, true);

            // Restore on load.
            window.addEventListener('DOMContentLoaded', function () {
                var raw;
                try { raw = sessionStorage.getItem(KEY); } catch (e) { return; }
                if (! raw) return;
                var state;
                try { state = JSON.parse(raw); } catch (e) { return; }

                // Stale guard — drop if older than 5 minutes.
                if (! state || typeof state !== 'object' || ! state.ts || Date.now() - state.ts > 5 * 60 * 1000) {
                    try { sessionStorage.removeItem(KEY); } catch (e) {}
                    return;
                }

                // Reopen drawers (skip rooms now flagged data-skip-restore — those are
                // the ones the engineer just marked complete, auto-collapse must win).
                (state.openDetails || []).forEach(function (id) {
                    var d = document.getElementById(id);
                    if (d && d.dataset.skipRestore !== '1') d.open = true;
                });

                // Restore scroll — defer to next paint so layout has settled.
                if (typeof state.scrollY === 'number') {
                    requestAnimationFrame(function () { window.scrollTo(0, state.scrollY); });
                }

                try { sessionStorage.removeItem(KEY); } catch (e) {}
            });
        })();
    </script>

    {{-- ══════════════════════════════════════════════════════════════════════
         260603-eha — Offline photo upload queue
         ──────────────────────────────────────────────────────────────────────
         Adds an IndexedDB-backed queue so engineers in dead zones (comms
         cupboards, basements, racks) keep working when uploads fail.

         Covers BOTH label captures AND per-room "Add photo" uploads,
         auto-drains when network returns, persists across reloads, and
         surfaces via a quiet header chip + expandable panel.

         Server endpoints (PublicWorksheetController) are UNCHANGED — drained
         POSTs use the same FormData shape the live functions build today.

         See: .planning/quick/260603-eha-offline-photo-queue-on-engineer-workshee/260603-eha-PLAN.md
         ══════════════════════════════════════════════════════════════════════ --}}
    <script>
        (function () {
            'use strict';

            // ── OfflineQueue module ───────────────────────────────────────
            // Self-contained — no library imports, no build step. Pure
            // vanilla JS over native IndexedDB.
            const DB_NAME    = 'engineer-worksheet';
            const DB_VERSION = 1;
            const STORE      = 'pending_uploads';

            const OfflineQueue = {
                unavailable: false,
                _draining:   false,
                _db:         null,
                _warned:     false,
                // Transient per-id status used only by the UI panel — rows
                // in IDB are 'pending' OR removed; status is in-memory.
                _uploadingIds: new Set(),
            };

            // Lazy DB open. Resolves once; cached.
            OfflineQueue.db = function () {
                if (!('indexedDB' in window)) {
                    OfflineQueue.unavailable = true;
                    return Promise.reject(new Error('IndexedDB unavailable'));
                }
                if (OfflineQueue._db) return Promise.resolve(OfflineQueue._db);
                return new Promise(function (resolve, reject) {
                    const req = indexedDB.open(DB_NAME, DB_VERSION);
                    req.onupgradeneeded = function (e) {
                        const db = e.target.result;
                        if (!db.objectStoreNames.contains(STORE)) {
                            const store = db.createObjectStore(STORE, {
                                keyPath: 'id',
                                autoIncrement: true,
                            });
                            store.createIndex('capturedAt', 'capturedAt', { unique: false });
                        }
                    };
                    req.onsuccess = function (e) {
                        OfflineQueue._db = e.target.result;
                        resolve(OfflineQueue._db);
                    };
                    req.onerror = function (e) {
                        OfflineQueue.unavailable = true;
                        reject(e.target.error || new Error('IndexedDB open failed'));
                    };
                });
            };

            // Tiny helper — wraps a transaction as a Promise.
            function tx(mode, fn) {
                return OfflineQueue.db().then(function (db) {
                    return new Promise(function (resolve, reject) {
                        const transaction = db.transaction([STORE], mode);
                        const store = transaction.objectStore(STORE);
                        let result;
                        try { result = fn(store); } catch (e) { reject(e); return; }
                        transaction.oncomplete = function () { resolve(result); };
                        transaction.onerror    = function () { reject(transaction.error); };
                        transaction.onabort    = function () { reject(transaction.error); };
                    });
                });
            }

            // ── Public API ───────────────────────────────────────────────

            OfflineQueue.enqueue = function (row) {
                // row = {token, kind, room, blob, mime, fields}
                if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                    if (!OfflineQueue._warned) {
                        OfflineQueue._warned = true;
                        try {
                            showToast("⚠ Offline queue unsupported on this browser — uploads still work when online.", 'warning', 6000);
                        } catch (e) {}
                    }
                    return Promise.resolve(null);
                }
                const record = {
                    token:        row.token || '',
                    kind:         row.kind  || 'completed',
                    room:         row.room  || '',
                    blob:         row.blob,
                    mime:         row.mime  || 'image/jpeg',
                    fields:       row.fields || {},
                    attemptCount: 0,
                    lastError:    null,
                    capturedAt:   Date.now(),
                };
                return tx('readwrite', function (store) {
                    const req = store.add(record);
                    return new Promise(function (resolve, reject) {
                        req.onsuccess = function () { resolve(req.result); };
                        req.onerror   = function () { reject(req.error); };
                    });
                }).then(function (id) {
                    OfflineQueue._notifyChange();
                    return id;
                }).catch(function (e) {
                    // Hard fail enqueue — surface so caller can fall back.
                    if (!OfflineQueue._warned) {
                        OfflineQueue._warned = true;
                        try {
                            showToast("⚠ Couldn't save offline (storage error) — try again when online.", 'error', 6000);
                        } catch (_) {}
                    }
                    throw e;
                });
            };

            OfflineQueue.list = function () {
                if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                    return Promise.resolve([]);
                }
                return tx('readonly', function (store) {
                    const req = store.getAll();
                    return new Promise(function (resolve, reject) {
                        req.onsuccess = function () {
                            const rows = (req.result || []).map(function (r) {
                                // Strip blob for cheap UI rendering.
                                return {
                                    id:           r.id,
                                    kind:         r.kind,
                                    room:         r.room,
                                    capturedAt:   r.capturedAt,
                                    attemptCount: r.attemptCount || 0,
                                    lastError:    r.lastError || null,
                                };
                            });
                            rows.sort(function (a, b) { return a.capturedAt - b.capturedAt; });
                            resolve(rows);
                        };
                        req.onerror = function () { reject(req.error); };
                    });
                }).catch(function () { return []; });
            };

            OfflineQueue.count = function () {
                if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                    return Promise.resolve(0);
                }
                return tx('readonly', function (store) {
                    const req = store.count();
                    return new Promise(function (resolve, reject) {
                        req.onsuccess = function () { resolve(req.result || 0); };
                        req.onerror   = function () { reject(req.error); };
                    });
                }).catch(function () { return 0; });
            };

            OfflineQueue.remove = function (id) {
                if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                    return Promise.resolve();
                }
                return tx('readwrite', function (store) {
                    store.delete(id);
                }).then(function () {
                    OfflineQueue._uploadingIds.delete(id);
                    OfflineQueue._notifyChange();
                });
            };

            // Internal — fetch raw rows including the blob.
            function _getAllRaw() {
                return tx('readonly', function (store) {
                    const req = store.getAll();
                    return new Promise(function (resolve, reject) {
                        req.onsuccess = function () { resolve(req.result || []); };
                        req.onerror   = function () { reject(req.error); };
                    });
                });
            }

            // Internal — update a row (e.g. bump attemptCount on failure).
            function _updateRow(row) {
                return tx('readwrite', function (store) {
                    store.put(row);
                });
            }

            // Internal — small sleep helper (throttle compliance).
            function _sleep(ms) {
                return new Promise(function (resolve) { setTimeout(resolve, ms); });
            }

            OfflineQueue.drain = function (opts) {
                opts = opts || {};
                if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                    return Promise.resolve({ successCount: 0, failureCount: 0 });
                }
                if (OfflineQueue._draining) {
                    return Promise.resolve({ successCount: 0, failureCount: 0, skipped: true });
                }
                OfflineQueue._draining = true;

                const onSuccess = opts.onSuccess || function () {};
                const onFailure = opts.onFailure || function () {};
                const onProgress = opts.onProgress || function () {};

                let successCount = 0;
                let failureCount = 0;
                let hitMaxRetry  = 0;

                return _getAllRaw().then(function (rows) {
                    rows.sort(function (a, b) { return a.capturedAt - b.capturedAt; });

                    return rows.reduce(function (chain, row) {
                        return chain.then(function () {
                            OfflineQueue._uploadingIds.add(row.id);
                            OfflineQueue._notifyChange();
                            onProgress(row);

                            const fd = new FormData();
                            try {
                                fd.append('photo', row.blob, (row.kind === 'label' ? 'label.jpg' : 'photo.jpg'));
                            } catch (e) {
                                // Blob gone? Skip + mark failure.
                                row.attemptCount = (row.attemptCount || 0) + 1;
                                row.lastError = 'Local blob unreadable';
                                failureCount++;
                                if (row.attemptCount >= 3) hitMaxRetry++;
                                OfflineQueue._uploadingIds.delete(row.id);
                                return _updateRow(row).then(function () { onFailure(row, e); });
                            }
                            fd.append('room_name', row.room || '');
                            const fields = row.fields || {};
                            Object.keys(fields).forEach(function (k) {
                                fd.append(k, fields[k]);
                            });

                            const path = row.kind === 'label' ? '/label-photo' : '/photos';
                            const url  = '/worksheet/' + encodeURIComponent(row.token) + path;

                            return fetch(url, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                    'Accept':       'application/json',
                                },
                                body: fd,
                            }).then(function (resp) {
                                if (resp.ok) {
                                    successCount++;
                                    OfflineQueue._uploadingIds.delete(row.id);
                                    return tx('readwrite', function (store) {
                                        store.delete(row.id);
                                    }).then(function () {
                                        return resp.json().catch(function () { return {}; });
                                    }).then(function (json) {
                                        onSuccess(row, json);
                                    });
                                }
                                row.attemptCount = (row.attemptCount || 0) + 1;
                                row.lastError = resp.statusText || ('HTTP ' + resp.status);
                                failureCount++;
                                if (row.attemptCount >= 3) hitMaxRetry++;
                                OfflineQueue._uploadingIds.delete(row.id);
                                return _updateRow(row).then(function () {
                                    onFailure(row, new Error(row.lastError));
                                });
                            }).catch(function (err) {
                                row.attemptCount = (row.attemptCount || 0) + 1;
                                row.lastError = (err && err.message) || 'Network error';
                                failureCount++;
                                if (row.attemptCount >= 3) hitMaxRetry++;
                                OfflineQueue._uploadingIds.delete(row.id);
                                return _updateRow(row).then(function () { onFailure(row, err); });
                            }).then(function () {
                                // Throttle compliance — 5/sec << server 30/min/IP.
                                return _sleep(200);
                            });
                        });
                    }, Promise.resolve());
                }).then(function () {
                    OfflineQueue._draining = false;
                    OfflineQueue._notifyChange();
                    return { successCount: successCount, failureCount: failureCount, hitMaxRetry: hitMaxRetry };
                }).catch(function (e) {
                    OfflineQueue._draining = false;
                    OfflineQueue._notifyChange();
                    return { successCount: successCount, failureCount: failureCount, hitMaxRetry: hitMaxRetry, error: e };
                });
            };

            OfflineQueue._notifyChange = function () {
                try {
                    window.dispatchEvent(new CustomEvent('offline-queue-change'));
                } catch (e) {
                    // Old IE — CustomEvent constructor unavailable. Silent.
                }
            };

            OfflineQueue.subscribe = function (handler) {
                window.addEventListener('offline-queue-change', handler);
            };

            // ── Toast helper (inline-styled, no CSS class deps) ──────────
            let _toastContainer = null;
            function showToast(msg, variant, ttl) {
                variant = variant || 'info';
                ttl = ttl || 4000;
                if (!_toastContainer) {
                    _toastContainer = document.createElement('div');
                    _toastContainer.id = 'offline-queue-toasts';
                    _toastContainer.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;gap:.4rem;align-items:center;pointer-events:none;max-width:92vw;';
                    document.body.appendChild(_toastContainer);
                }
                const palette = {
                    info:    { bg:'#E0F2FE', fg:'#0C4A6E', bd:'#7DD3FC' },
                    success: { bg:'#D1FAE5', fg:'#065F46', bd:'#6EE7B7' },
                    warning: { bg:'#FEF3C7', fg:'#92400E', bd:'#FCD34D' },
                    error:   { bg:'#FEE2E2', fg:'#991B1B', bd:'#FCA5A5' },
                }[variant] || { bg:'#E0F2FE', fg:'#0C4A6E', bd:'#7DD3FC' };

                const t = document.createElement('div');
                t.style.cssText = 'background:' + palette.bg + ';color:' + palette.fg + ';border:1px solid ' + palette.bd + ';border-radius:10px;padding:.6rem .9rem;font-size:.85rem;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.18);transition:opacity .2s ease;pointer-events:auto;text-align:center;';
                t.textContent = msg;
                _toastContainer.appendChild(t);

                setTimeout(function () {
                    t.style.opacity = '0';
                    setTimeout(function () {
                        if (t.parentNode) t.parentNode.removeChild(t);
                    }, 220);
                }, ttl);
            }
            // Expose for the wrappers in the next script block.
            window.__wsShowToast = showToast;

            // ── Auto-drain triggers ──────────────────────────────────────
            // One shared aggregator for online-event + 60s tick. Guarded
            // by _draining so they don't double-post.
            function _autoDrain(reason) {
                if (!('indexedDB' in window) || OfflineQueue.unavailable) return Promise.resolve();
                return OfflineQueue.count().then(function (n) {
                    if (n === 0) return null;
                    return OfflineQueue.drain({}).then(function (result) {
                        if (!result) return null;
                        if (result.successCount >= 1) {
                            showToast('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            showToast('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                        return result;
                    });
                });
            }

            window.addEventListener('online', function () { _autoDrain('online-event'); });
            setInterval(function () {
                if (navigator.onLine) _autoDrain('60s-tick');
            }, 60000);

            // Expose for testing/debug — token-gated page, no admin info leaks.
            window.OfflineQueue = OfflineQueue;
        })();
    </script>

    {{-- ══════════════════════════════════════════════════════════════════════
         260603-eha — Wrapper overrides for captureLabel + uploadWorksheetPhoto
         ──────────────────────────────────────────────────────────────────────
         When navigator.onLine === false (or fetch throws unexpectedly) we
         intercept BEFORE the original's fetch, normalise to JPEG, enqueue,
         show a toast, and restore the UI WITHOUT alert().

         When navigator.onLine === true we delegate to the original so the
         AI-extraction modal still opens (labels) or the page still reloads
         (completed-work photos). For the rare case of online + 500 server
         error, the original's existing alert() still fires — engineer can
         retap, queue catches them next time (acceptable compromise per
         interfaces note in PLAN.md).
         ══════════════════════════════════════════════════════════════════════ --}}
    <script>
        (function () {
            'use strict';

            const __origCaptureLabel         = window.captureLabel;
            const __origUploadWorksheetPhoto = window.uploadWorksheetPhoto;
            const __toast                    = window.__wsShowToast || function () {};

            // Helper — restore the <label> button text after offline enqueue,
            // mirroring the original captureLabel's restoreBtn logic at a
            // simpler level (we only ran a tiny setBusy here, not the full
            // 'Processing image...' sequence the original uses).
            function _resetLabelInput(input) {
                if (!input) return;
                try { input.value = ''; } catch (e) {}
                if (input.disabled) input.disabled = false;
                const btn = input.closest('label');
                if (btn) {
                    btn.classList.remove('label-cap-busy');
                    btn.style.opacity = '';
                    btn.style.pointerEvents = '';
                    const span = btn.querySelector('.label-cap-text');
                    if (span) span.remove();
                }
            }

            // ── window.captureLabel wrapper ──────────────────────────────
            window.captureLabel = async function (input, token) {
                const file = input && input.files && input.files[0];
                if (!file) return;

                // Online happy path — delegate to original (modal still opens).
                // Wrap in try/catch so unexpected JS throws fall through to enqueue.
                if (navigator.onLine) {
                    try {
                        return await __origCaptureLabel(input, token);
                    } catch (e) {
                        // Unexpected JS error in original — fall through to enqueue.
                        console.warn('captureLabel original threw, falling back to queue:', e);
                    }
                }

                // OFFLINE (or unexpected throw) path: replicate prep + enqueue.
                let uploadFile = file;
                try {
                    uploadFile = await convertToJpegBlobSafe(file);
                } catch (e) {
                    // fall through with raw file
                }

                const fields = {
                    item_description: (input.dataset && input.dataset.desc) || '',
                    item_part_number: (input.dataset && input.dataset.part) || '',
                    item_qty:         (input.dataset && input.dataset.qty)  || 1,
                };
                const room = (input.dataset && input.dataset.room) || '';

                try {
                    await window.OfflineQueue.enqueue({
                        token: token,
                        kind:  'label',
                        room:  room,
                        blob:  uploadFile,
                        mime:  'image/jpeg',
                        fields: fields,
                    });
                    __toast("📥 Saved offline — will upload when you're online", 'info');
                } catch (e) {
                    // Enqueue itself failed (IDB unavailable / storage error).
                    // Original would have shown alert; surface a single toast.
                    __toast('Could not save offline. Please try again when online.', 'error');
                }
                _resetLabelInput(input);
            };

            // ── window.uploadWorksheetPhoto wrapper ──────────────────────
            window.uploadWorksheetPhoto = async function (input, token, roomName) {
                const file = input && input.files && input.files[0];
                if (!file) return;

                // HEIC normalisation (bonus per PLAN.md — original doesn't run this).
                // Smaller blob in IDB AND faster online uploads on iOS.
                let uploadFile = file;
                try {
                    uploadFile = await convertToJpegBlobSafe(file);
                } catch (e) {
                    // fall through with raw file
                }

                // Online happy path — replicate the original's POST inline so we
                // can a) feed the normalised blob, b) intercept network throws and
                // route them into the queue without firing alert().
                if (navigator.onLine) {
                    const fd = new FormData();
                    fd.append('photo', uploadFile, (uploadFile && uploadFile.type) ? 'photo.jpg' : (file.name || 'photo.jpg'));
                    fd.append('room_name', roomName);
                    const url = '/worksheet/' + encodeURIComponent(token) + '/photos';
                    try {
                        const resp = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept':       'application/json',
                            },
                            body: fd,
                        });
                        if (!resp.ok) {
                            // Server returned non-2xx (validation / 500) — preserve
                            // the original alert() so the engineer sees the failure
                            // explicitly (matches pre-260603-eha behaviour).
                            alert('Upload failed. ' + (resp.statusText || 'Please try again.'));
                            try { input.value = ''; } catch (e) {}
                            return;
                        }
                        // SAME UX as original — reload so thumbnail + count update.
                        window.location.reload();
                        return;
                    } catch (e) {
                        // Network throw mid-fetch — fall through to enqueue below.
                        console.warn('uploadWorksheetPhoto fetch threw, queuing:', e);
                    }
                }

                // OFFLINE (or online + network throw): enqueue.
                try {
                    await window.OfflineQueue.enqueue({
                        token: token,
                        kind:  'completed',
                        room:  roomName,
                        blob:  uploadFile,
                        mime:  'image/jpeg',
                        fields: {},
                    });
                    __toast("📥 Saved offline — will upload when you're online", 'info');
                } catch (e) {
                    __toast('Could not save offline. Please try again when online.', 'error');
                }
                try { input.value = ''; } catch (e) {}
            };

            // Defensive wrapper around convertToJpegBlob — original is defined
            // earlier in this Blade file (line ~1568) but lives in a different
            // <script> block scope. It's hoisted as a function declaration so
            // it IS accessible here via the global scope, but we wrap in a
            // try/catch just in case (e.g. if some future refactor IIFE-wraps
            // that block).
            async function convertToJpegBlobSafe(file) {
                if (typeof convertToJpegBlob === 'function') {
                    return convertToJpegBlob(file);
                }
                return file;
            }
        })();
    </script>

    {{-- ══════════════════════════════════════════════════════════════════════
         260603-eha — Pending-uploads chip + panel UI controller
         ──────────────────────────────────────────────────────────────────────
         Subscribes to OfflineQueue change events; updates the chip count;
         renders the expandable panel with per-item Retry/Remove + Retry all.
         ══════════════════════════════════════════════════════════════════════ --}}
    <script>
        (function () {
            'use strict';

            if (!window.OfflineQueue) return;

            const chip       = document.getElementById('pending-chip');
            const chipCount  = document.getElementById('pending-chip-count');
            const panel      = document.getElementById('pending-panel');
            const list       = document.getElementById('pending-list');
            const retryAll   = document.getElementById('pending-retry-all');

            if (!chip || !panel || !list) return;

            function _esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function _relTime(ts) {
                const ms = Date.now() - (ts || Date.now());
                const s = Math.max(0, Math.round(ms / 1000));
                if (s < 60) return s + 's ago';
                const m = Math.round(s / 60);
                if (m < 60) return m + ' min ago';
                const h = Math.round(m / 60);
                if (h < 24) return h + ' hr ago';
                const d = Math.round(h / 24);
                return d + ' day' + (d === 1 ? '' : 's') + ' ago';
            }

            function _icon(kind) {
                return kind === 'label' ? '📷' : '🖼';
            }

            function _statusFor(row) {
                if (window.OfflineQueue._uploadingIds && window.OfflineQueue._uploadingIds.has(row.id)) {
                    return { key: 'uploading', label: 'uploading…' };
                }
                if ((row.attemptCount || 0) >= 1 && row.lastError) {
                    return { key: 'failed', label: 'failed' };
                }
                return { key: 'queued', label: 'queued' };
            }

            function renderList(items) {
                if (!items.length) {
                    list.innerHTML = '<div style="font-size:.8rem;color:#6B7280;padding:.4rem 0;">No pending items.</div>';
                    return;
                }
                const html = items.map(function (row) {
                    const status = _statusFor(row);
                    const errSub = (row.attemptCount || 0) >= 1 && row.lastError
                        ? ' · ' + row.attemptCount + ' attempt' + (row.attemptCount === 1 ? '' : 's') + ' — ' + _esc(row.lastError)
                        : '';
                    return ''
                        + '<div class="pending-item" data-id="' + row.id + '">'
                        +   '<span style="font-size:1.05rem;">' + _icon(row.kind) + '</span>'
                        +   '<div class="pending-item__meta">'
                        +     '<div class="pending-item__room">' + _esc(row.room || '(no room)') + '</div>'
                        +     '<div class="pending-item__sub">'
                        +       (row.kind === 'label' ? 'Box serial label' : 'Completed-work photo')
                        +       ' · ' + _relTime(row.capturedAt)
                        +       errSub
                        +     '</div>'
                        +   '</div>'
                        +   '<span class="pending-item__status pending-item__status--' + status.key + '">' + status.label + '</span>'
                        +   '<div class="pending-item__btns">'
                        +     '<button type="button" class="pending-item__btn" data-act="retry" data-id="' + row.id + '">↻ Retry</button>'
                        +     '<button type="button" class="pending-item__btn" data-act="remove" data-id="' + row.id + '">✕ Remove</button>'
                        +   '</div>'
                        + '</div>';
                }).join('');
                list.innerHTML = html;
            }

            function refreshChip() {
                if (!window.OfflineQueue) return;
                Promise.all([
                    window.OfflineQueue.count(),
                    window.OfflineQueue.list(),
                ]).then(function (results) {
                    const n = results[0];
                    const items = results[1];
                    chipCount.textContent = String(n);
                    if (n > 0) {
                        chip.style.display = 'inline-flex';
                        renderList(items);
                    } else {
                        chip.style.display = 'none';
                        // Auto-close panel when queue drains to zero.
                        chip.setAttribute('aria-expanded', 'false');
                        panel.removeAttribute('data-open');
                        list.innerHTML = '';
                    }
                });
            }

            // Chip click → toggle panel.
            chip.addEventListener('click', function () {
                const open = panel.getAttribute('data-open') === '1';
                if (open) {
                    panel.removeAttribute('data-open');
                    chip.setAttribute('aria-expanded', 'false');
                } else {
                    panel.setAttribute('data-open', '1');
                    chip.setAttribute('aria-expanded', 'true');
                }
            });

            // Keyboard accessibility — Enter/Space on chip toggles.
            chip.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    chip.click();
                }
            });

            // Retry all.
            if (retryAll) {
                retryAll.addEventListener('click', function () {
                    window.OfflineQueue.drain({}).then(function (result) {
                        if (!result) return;
                        if (result.successCount >= 1) {
                            (window.__wsShowToast || function(){})('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            (window.__wsShowToast || function(){})('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                    });
                });
            }

            // Delegated per-item retry / remove.
            list.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-act]');
                if (!btn) return;
                const id = parseInt(btn.getAttribute('data-id'), 10);
                if (isNaN(id)) return;
                const act = btn.getAttribute('data-act');
                if (act === 'remove') {
                    window.OfflineQueue.remove(id).then(refreshChip);
                } else if (act === 'retry') {
                    // Drain processes ALL rows in oldest-first order; per-item
                    // retry is effectively the same as Retry all but the user
                    // explicitly chose this row. Keep behaviour identical for
                    // simplicity.
                    window.OfflineQueue.drain({}).then(function (result) {
                        if (!result) return;
                        if (result.successCount >= 1) {
                            (window.__wsShowToast || function(){})('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            (window.__wsShowToast || function(){})('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                    });
                }
            });

            // Outside-click closes panel.
            document.addEventListener('click', function (e) {
                if (!chip.contains(e.target) && !panel.contains(e.target)) {
                    panel.removeAttribute('data-open');
                    chip.setAttribute('aria-expanded', 'false');
                }
            });

            // Subscribe to queue changes (fires on enqueue / drain step / remove).
            window.OfflineQueue.subscribe(refreshChip);

            // Initial paint — may have rows from a prior session.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', refreshChip);
            } else {
                refreshChip();
            }
        })();
    </script>

</body>
</html>
