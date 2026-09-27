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
        a { color: #2E7BFF; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Layout ───────────────────────────────────────────────────── */
        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 .875rem 2rem;
        }

        /* ── Header ───────────────────────────────────────────────────── */
        .ws-header {
            background: #0F172A;
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
            font-size: .85rem; font-weight: 700; color: #0F172A;
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
            color: #0F172A;
            margin-bottom: .85rem;
            padding-bottom: .55rem;
            border-bottom: 1px solid #F0F0F0;
        }
        .room-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0F172A;
            margin-bottom: .75rem;
        }

        /* ── Section headings inside a room ───────────────────────────── */
        .section-hdr {
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #2E7BFF;
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
            border-color: #2E7BFF;
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
            accent-color: #2E7BFF;
            flex: 0 0 auto;
        }

        /* ── Signature pad ────────────────────────────────────────────── */
        .sig-pad-wrap {
            border: 2px dashed #2E7BFF;
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
        .btn-teal     { background: #2E7BFF; color: #fff; border-color: #2E7BFF; }
        .btn-teal:hover:not([disabled]) { background: #157B85; border-color: #157B85; }
        .btn-teal[disabled] { opacity: .55; cursor: not-allowed; }
        .btn-outline  { background: transparent; color: #2E7BFF; border-color: #2E7BFF; }
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
            color: #0F172A;
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
            color: #2E7BFF; font-size: .85rem; flex-shrink: 0;
            transition: transform 200ms ease;
        }
        details[open] > .room-summary .room-chevron { transform: rotate(90deg); }
        .room-summary-name { flex: 1; font-weight: 700; color: #0F172A; font-size: 1rem; }
        .photo-count-pill {
            background: #2E7BFF; color: #fff;
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
        .room-drawer.teal.teal--accent { border-left-width: 4px; border-left-color: #2E7BFF; }

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
        .room-drawer.teal  summary { background: rgba(46,123,255,.06); color: #0F172A; }
        .room-drawer.gold  summary { background: rgba(251,191,36,.08); color: #92400E; }
        .room-drawer.amber summary { background: rgba(245,158,11,.08); color: #92400E; }
        .room-drawer.grey  summary { background: rgba(107,114,128,.06); color: #374151; }
        .room-drawer summary:hover { filter: brightness(.97); }
        .room-drawer summary .chev {
            font-size: 1.1rem; transition: transform 200ms ease;
            color: #2E7BFF;
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
            text-transform: uppercase; color: #2E7BFF; margin-bottom: .55rem;
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
                {{-- 46.4-04 (D-07) — this line used to promise the opposite: that
                     engineers could carry on updating notes and photos after
                     sign-off. That is now false on the SERVER (every capture
                     endpoint returns 422), so it could not be left saying so on a
                     page the CLIENT reads. Corrected, not deleted. --}}
                <div class="signed-banner__keep">
                    This worksheet remains accessible to read — it is now a completed record and can no longer be changed.
                </div>
            </div>
        @endif

        @php
            $rooms = $worksheet->generated_data['rooms'] ?? [];

            // ── 46.4-04 (D-07) — THE CAPTURE LOCK, COMPUTED ONCE ──────────────
            // The user: *"client cannot chage anything as they are signing to
            // confirm work is complete."* One page, one URL (D-01), so the app
            // tells engineer from client by STATE, not identity.
            //
            // ONE variable, read at every branch below. NOT `isSigned()` at ten
            // call sites, and NOT re-derived from $latestSignoff — the controller
            // passes that in for DISPLAY and the two must never drift apart. The
            // server enforces the same predicate through the same class
            // (App\Support\Worksheets\WorksheetCaptureLock), so the page and the
            // endpoint cannot disagree about what "locked" means.
            //
            // ⚠️ HIDING A CONTROL IS THE COURTESY, NOT THE SECURITY. The token is
            // the only credential; the refusal that matters is the 422 in
            // PublicWorksheetController. Never treat this flag as a permission.
            $captureLocked = \App\Support\Worksheets\WorksheetCaptureLock::isLocked($worksheet);

            // ── 46.4-05 (D-06 / D-08) — ADDITIONAL KIT, IN ONE QUERY ──────────
            // The rows are fetched ONCE for the whole worksheet and partitioned
            // by trimmed lower-cased room_name, matching the join convention
            // already used above for WorksheetPhoto and DeviceLabelPhoto. A
            // per-room query would be an N+1 on a page an engineer opens on site
            // signal, which is the one place this app must not be slow.
            //
            // `Worksheet::additionalKit()` returns MARKED ROWS TOO, on purpose
            // (D-08: nothing is ever hard deleted). The three states are branched
            // on plan 01's isMarked() / isAmended() / isOpen() helpers below —
            // never re-derived from marked_for_deletion_at or count($amendments),
            // so the server's refusals and this page cannot drift apart.
            $kitByRoom = [];
            foreach ($worksheet->additionalKit()->get() as $kitRow) {
                $kitKey = strtolower(trim((string) $kitRow->room_name));
                $kitByRoom[$kitKey][] = $kitRow;
            }

            // ── D-02 — THE ENGINEER IS PICKED, NEVER TYPED ─────────────────────
            // A plain array of ['id','name'] and nothing else. By construction it
            // cannot reach an email or a phone (LR-04): the resolver selects two
            // columns and returns arrays, not models, so there is no model left
            // downstream to lazily reach back through. THERE IS NO FREE-TEXT
            // ENGINEER INPUT ON THIS PAGE — if there were, a null would stop
            // meaning "nobody was allocated" and start being a spelling variant.
            $allocatedEngineers = \App\Support\Worksheets\AllocatedEngineers::forWorksheet($worksheet);
            $engineerNamesById  = [];
            foreach ($allocatedEngineers as $kitEngineer) {
                $engineerNamesById[(int) $kitEngineer['id']] = (string) $kitEngineer['name'];
            }
            // Read from the controller's own constant rather than re-typed here —
            // the endpoint returns this exact string in its JSON, the page renders
            // it server-side, and the two must be the same sentence.
            $unassignedEngineerLabel = \App\Http\Controllers\PublicWorksheetController::UNASSIGNED_ENGINEER;

            // ── D-46-05-01 — sign-off gate defaults, declared UNCONDITIONALLY ──
            // The real values are computed in the @else arm of `@if(empty($rooms))`
            // below (~:773), but BOTH are read AFTER that @endif in the Client
            // Sign-Off card (~:1359, ~:1441). A worksheet with no rooms therefore
            // reached those reads with the variables undefined → 500 on a live
            // engineer link. Three states get here: `generated_data` still NULL
            // while BuildWorksheetJob is queued, that job throwing on zero-room /
            // no-substantive-content (NULL forever), and WorksheetEditAdapter's
            // remove_room emptying `rooms[]` (no last-room guard).
            //
            // FALSE is deliberate, not merely the falsy default. Zero rooms means
            // zero unreviewed rooms, so `! empty($unreviewedRooms)` over the empty
            // set is false — the defaults simply agree with the expression they
            // stand in for. Blocking instead would render a warning naming NO
            // rooms, on a page with no room drawers to clear it, and this gate is
            // cosmetic anyway (the sign POST is accepted regardless). If an empty
            // worksheet must not be signable, that belongs in
            // PublicWorksheetController::sign, not in a display flag.
            //
            // Keep these ABOVE the branch. Moving them inside it restores the 500.
            $signOffBlocked  = false;
            $unreviewedRooms = [];

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
            $carryForward = [];
            if ($worksheet->project_id && class_exists(\App\Models\SiteSurvey::class)) {
                $survey = \App\Models\SiteSurvey::with(['rooms', 'rooms.photos'])
                    ->where('project_id', $worksheet->project_id)
                    ->latest('id')
                    ->first();
                if ($survey) {
                    // ── Site-level carry-forward (Phase 46, D-01) — widened from the
                    //    seven inline keys of 260504-gho to the ten fields D-01 names.
                    //    The four added are the safety ones a surveyor records and an
                    //    installing engineer was never shown: access_constraints,
                    //    site_risks, h_and_s_notes, general_notes.
                    //
                    //    Derivation lives in App\Support\Visits\SurveyCarryForward so
                    //    the labels and the comms-room status map have ONE definition.
                    //    $survey is passed in rather than re-resolved, so the page
                    //    still makes exactly one survey query (T-46-03-05) — and it is
                    //    still a READ of the survey record on every request, never a
                    //    copy (D-01). `office_review_notes` is excluded by name there.
                    $carryForward = \App\Support\Visits\SurveyCarryForward::forSurvey($survey);

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
            // $commsRoomLabels moved to App\Support\Visits\SurveyCarryForward::COMMS_ROOM_LABELS
            // (Phase 46, Plan 46-03) — moved rather than copied, because two maps
            // would disagree the first time a status is added.

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

        {{-- ── 46.4-04 (D-07) — THE READ-ONLY BANNER ────────────────────────────
             One neutral sentence, worded for BOTH readers on this single-URL page:
             the engineer who wonders where the capture buttons went, and the client
             looking at their own signature. Server-rendered @if only: no Alpine
             directive (Alpine is never loaded on this page) and no JS toggling.
             ⚠️ This comment deliberately names no directive — plan 02 pins their
             occurrence COUNT, and a comment mentioning one fails that guard. --}}
        @if($captureLocked)
            <div style="margin-bottom:1rem;padding:.7rem .9rem;border-radius:8px;background:#F1F5F9;border:1px solid #CBD5E1;color:#334155;font-size:.85rem;line-height:1.45;">
                ✓ Signed on <strong>{{ $latestSignoff->signed_at->format('d M Y') }}</strong> — this worksheet is a
                completed record and can no longer be changed. Photos, labels and kit lists stay visible to read.
            </div>
        @endif

        @if($captureLocked)
            {{-- 46.4-04 — was `@if($latestSignoff)`. Same state, but now read through
                 the ONE predicate the server enforces, so the page and the endpoints
                 cannot drift. --}}
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

            {{-- ── OFFICE SEND-BACK BANNER (Phase 46, Plan 46-05) ─────────────
                 A CLIENT signs this page, so `reason` is DELIBERATELY null:
                 `send_back_reason` is the office's internal wording about its
                 own engineer (threat T-46-05-02). The client learns the visit
                 is not finished; they do not read the office's opinion of the
                 return. DO NOT "fix" this by passing $sendBack['reason'].
                 This is a separate, distinct element from the Site Logistics
                 carry-forward drawer below (Plan 46-03) — do not merge them. --}}
            @include('partials._office-sendback-banner', [
                'reopened' => $sendBack['reopened'] ?? false,
                'at'       => $sendBack['at'] ?? null,
                'reason'   => null,
                'rooms'    => $sendBack['rooms'] ?? [],
            ])

            {{-- ── ENGINEER REFERENCE FILES (quick task 260601-r4c) ────────────
                 Project-level uploaded artifacts (site plans, CAD drawings,
                 cable schedules, method statements). Drawer is hidden when
                 the project has no reference files. --}}
            @include('partials._engineer-reference-drawer', [
                'files'          => optional($worksheet->project)->referenceFiles?->sortByDesc('uploaded_at') ?? collect(),
                'serveRouteName' => 'public-worksheet.files.serve',
                'token'          => $token,
            ])

            {{-- ── SITE LOGISTICS — project-level drawer (260504-gho; widened by
                 Phase 46 Plan 46-03 to D-01's ten fields) ──────────────────────
                 Engineers arriving on site need parking / access / constraints /
                 delivery routes / comms-room access / depot distance ONCE per
                 visit, NOT per room — and they need the surveyor's site risks,
                 H&S notes and general notes, which until Phase 46 never left the
                 survey record.

                 ONE DRAWER, DELIBERATELY. The user rejected an earlier, busier
                 design with the words "I want to make it look simple and less
                 scary", and this page is read on a phone, on site. Four more
                 fields do not earn a second drawer or a second tap.

                 Every value below is engineer free text on an unauthenticated,
                 client-signed page: it renders through an ESCAPED echo, and an
                 unescaped raw echo is forbidden here (T-46-03-02). A test pins
                 the raw-echo count in this file at one — the pre-existing
                 $skipRestoreAttr literal — so a new one fails red.

                 Defensive: $carryForward === [] when the survey is missing or
                 every carried column is NULL — the drawer renders nothing at
                 all for legacy projects. No empty drawer, no "not recorded"
                 placeholder: tapping a drawer to find nothing is worse than no
                 drawer. --}}
            @if(! empty($carryForward))
                <details class="room-drawer teal" style="margin-bottom:1rem;">
                    <summary>
                        <span>📋 Site Logistics — Arrival Info</span>
                        <span class="chev">▾</span>
                    </summary>
                    <div class="room-drawer-body">
                        @foreach($carryForward as $cf)
                            <div style="margin-bottom:.65rem;">
                                <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">{{ $cf['label'] }}</div>
                                <div style="font-size:.88rem;color:#374151;white-space:pre-wrap;">{{ $cf['value'] }}</div>
                            </div>
                        @endforeach
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

                    // ── 46.4-02 (D-03) — one pass, two trays ──────────────────
                    // Partition the room's photos by bucket here rather than
                    // querying twice: $worksheet->photos is already eager-loaded
                    // and a second query per room would be an N+1 on a page an
                    // engineer opens on site signal.
                    //
                    // $photoCount above stays WHOLE-ROOM on purpose. It feeds the
                    // room-summary pill and the Mark Room Complete soft gate, both
                    // of which have meant "photos exist for this room" since
                    // 260504-iy4, and neither changes meaning today.
                    $roomPhotosByBucket = $roomPhotos
                        ->groupBy(fn ($p) => (string) ($p->bucket ?: \App\Models\WorksheetPhoto::BUCKET_COMPLETION));
                    $startPhotos      = ($roomPhotosByBucket[\App\Models\WorksheetPhoto::BUCKET_START] ?? collect())->values();
                    $completionPhotos = ($roomPhotosByBucket[\App\Models\WorksheetPhoto::BUCKET_COMPLETION] ?? collect())->values();

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

                    // 46.4-05 — this room's additional-kit rows, out of the ONE
                    // query partitioned in the page-level @php block above.
                    $kitRows = $kitByRoom[$roomKey] ?? [];
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
                        // lightbox cycler. ONE ARRAY PER TRAY (46.4-02): prev/next walks
                        // the tray the engineer actually tapped, so paging out of the
                        // Start tray and into completion shots cannot happen.
                        $lbSet = fn ($set) => $set->map(fn ($p) => [
                            'url'     => route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]),
                            'caption' => $p->caption ?? '',
                        ])->all();

                        // ── 46.4-02 (D-03) — the two trays, in capture order ──────
                        // Order is Start then Completion because that is the order the
                        // work happens in.
                        //
                        // ⚠️ THE COMPLETION TRAY'S TITLE IS LOAD-BEARING AND MUST NOT
                        // CHANGE. Plan 46.4-01's migration backfilled every pre-existing
                        // photo to `completion` and justified that ruling by quoting this
                        // exact wording — `📷 Photos of completed work`. Renaming it would
                        // retroactively make a written decision look arbitrary.
                        // See database/migrations/2026_09_26_100000_add_bucket_to_worksheet_photos_table.php
                        //
                        // START PHOTOS GATE NOTHING. 46.4-CONTEXT.md leaves this to
                        // discretion but rules that if they gate anything, the gate is
                        // VISUAL ONLY — RAMS's existing gates deliberately never block the
                        // server so an engineer on flaky signal is never stranded
                        // (PublicWorksheetController::markRoomComplete's docblock). A
                        // start-photo gate would be a NEW way to strand one, so there is
                        // none: the Mark Room Complete gate below still reads the
                        // whole-room $photoCount exactly as it did before this plan.
                        $photoTrays = [
                            [
                                'bucket' => \App\Models\WorksheetPhoto::BUCKET_START,
                                'title'  => '📸 Before you start',
                                'photos' => $startPhotos,
                                'warn'   => false,
                                'hint'   => 'Optional — a record of how the room looked before works began.',
                            ],
                            [
                                'bucket' => \App\Models\WorksheetPhoto::BUCKET_COMPLETION,
                                'title'  => '📷 Photos of completed work',
                                'photos' => $completionPhotos,
                                'warn'   => true,
                                'hint'   => null,
                            ],
                        ];
                    @endphp
                    @foreach($photoTrays as $tray)
                        @php $trayLb = $lbSet($tray['photos']); @endphp
                        <div class="photo-tray" data-photo-tray data-room-key="{{ $roomKey }}" data-bucket="{{ $tray['bucket'] }}" style="margin-top:0;padding-top:0;border-top:0;margin-bottom:{{ $loop->last ? '1rem' : '.85rem' }};padding-bottom:.85rem;border-bottom:1px dashed #E5E7EB;">
                            <div class="photo-tray-title">{{ $tray['title'] }} (<span data-photo-count>{{ $tray['photos']->count() }}</span>)</div>
                            @if($tray['hint'])
                                <div class="muted" style="font-size:.78rem;margin:-.15rem 0 .5rem;">{{ $tray['hint'] }}</div>
                            @endif
                            <div class="photo-thumbs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
                                @foreach($tray['photos'] as $p)
                                    <div style="width:72px;flex-shrink:0;">
                                        <div style="position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;background:#F3F4F6;">
                                            <a href="{{ route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]) }}"
                                               target="_blank"
                                               onclick="event.preventDefault(); openPhotoLightbox(@js($trayLb), {{ $loop->index }});">
                                                <img src="{{ route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]) }}"
                                                     alt="{{ $p->caption ?? '' }}"
                                                     loading="lazy"
                                                     style="width:100%;height:100%;object-fit:cover;">
                                            </a>
                                            {{-- 46.4-04 (D-07) — photo deletes are named in D-07's
                                                 enumeration. The thumbnail itself still renders. --}}
                                            @unless($captureLocked)
                                            <button type="button"
                                                    data-capture-control
                                                    onclick="deleteWorksheetPhoto({{ $p->id }}, '{{ $token }}', this)"
                                                    title="Remove"
                                                    style="position:absolute;top:2px;right:2px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-size:.7rem;line-height:1;cursor:pointer;">✕</button>
                                            @endunless
                                        </div>
                                        @if(($p->caption ?? '') !== '')
                                            {{-- Engineer free text on a page the CLIENT SIGNS: escaped echo
                                                 only, never a raw echo (T-46.4-02-02). --}}
                                            <div class="photo-thumb-caption" title="{{ $p->caption }}" style="font-size:.66rem;line-height:1.25;color:#4B5563;margin-top:.2rem;word-break:break-word;">{{ $p->caption }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            {{-- 46.4-04 (D-07) — the whole capture control set for this tray:
                                 the label field, the capture button and its hidden file input.
                                 Gone once signed; the thumbnails above stay. 46.4-05's kit
                                 drawer trigger and per-row Correct / Mark controls read this
                                 SAME $captureLocked variable — there is no second flag, and
                                 a new capture control must not introduce one. --}}
                            @unless($captureLocked)
                            {{-- D-04 — the label rides with the capture. maxlength mirrors the
                                 server's max:200 so the field cannot promise what the endpoint
                                 will refuse. --}}
                            <input type="text"
                                   data-photo-caption
                                   data-capture-control
                                   maxlength="200"
                                   placeholder="Label (optional) — e.g. rack before works"
                                   style="display:block;width:100%;max-width:340px;margin-bottom:.45rem;padding:.45rem .6rem;border:1px solid #D1D5DB;border-radius:8px;font-size:.85rem;min-height:40px;">
                            <label class="btn btn-outline btn-sm" data-capture-control style="display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;">
                                {{ $tray['bucket'] === \App\Models\WorksheetPhoto::BUCKET_START ? '📸 Add start photo' : '📷 Add photo' }}
                                <input type="file" accept="image/*" data-capture-control style="display:none;"
                                       onchange="uploadWorksheetPhoto(this, '{{ $token }}', '{{ addslashes($room['name'] ?? '') }}', '{{ $tray['bucket'] }}')">
                            </label>
                            @endunless
                            {{-- 46.4-04 — "capture one before requesting sign-off" is advice that
                                 only makes sense while capture is still possible. --}}
                            @if(! $captureLocked && $tray['warn'] && $tray['photos']->count() === 0)
                                <div class="photo-warn" style="margin-top:.55rem;">
                                    ⚠ No photos captured yet — capture at least one before requesting sign-off.
                                </div>
                            @endif
                        </div>
                    @endforeach

                    {{-- ════════════════════════════════════════════════════════════════
                         46.4-05 (D-06 / D-08 / D-02 / D-10) — ADDITIONAL KIT, PER ROOM

                         The thing this phase is named for. Until now the only place
                         extra kit could land was the sign-off comments textarea or a
                         phone call to the office; these are real rows the office
                         already has a screen for (plan 03).

                         THREE STATES, WORDED THE SAME WAY THE OFFICE SEES THEM, so an
                         engineer on the phone and an admin at a desk describe the same
                         row identically: open, Amended, and Marked for deletion with
                         its reason. A row can be BOTH amended and marked — two
                         independent checks, not a chain.

                         ⚠️ EVERY ECHO IS ESCAPED, INCLUDING THE REASON. part_description
                         and deletion_reason are engineer free text on a document the
                         CLIENT SIGNS. There is exactly one raw echo in this file (the
                         pre-existing $skipRestoreAttr literal) and a test pins that
                         count at one, so a second one fails red.

                         D-10: qty and part description. NO UNIT FIELD — put to the user
                         and declined ("3.no."). Not even a placeholder.
                         Claude's-discretion ruling the same day: NO PHOTO on a kit row
                         either ("dont need kit pics, on serial cpature") — no file
                         input, no thumbnail, no upload endpoint.
                    ════════════════════════════════════════════════════════════════ --}}
                    <div style="margin-bottom:1rem;padding-bottom:.85rem;border-bottom:1px dashed #E5E7EB;">
                        {{-- Hidden while the room has no rows: the trigger below IS the
                             affordance, and an empty titled list is just noise. The JS
                             reveals it when the first row is grafted in. --}}
                        <div class="photo-tray-title" data-kit-title data-room-key="{{ $roomKey }}"
                             style="display:{{ empty($kitRows) ? 'none' : 'block' }};">
                            🧰 Additional kit used (<span data-kit-count>{{ count($kitRows) }}</span>)
                        </div>
                        {{-- Always rendered, even when empty, so a grafted row has a home
                             without the page reloading. Empty, it displays nothing. --}}
                        <ul data-kit-list data-room-key="{{ $roomKey }}" style="list-style:none;margin:0;padding:0;">
                            @foreach($kitRows as $kitRow)
                                @php
                                    // The name comes from AllocatedEngineers' two-key
                                    // arrays. An id whose resource is gone, or one no
                                    // longer on the visit, falls back to the same
                                    // sentence as a null — never to a guessed name.
                                    $kitRowEngineer = $kitRow->labour_resource_id !== null
                                        ? ($engineerNamesById[(int) $kitRow->labour_resource_id] ?? $unassignedEngineerLabel)
                                        : $unassignedEngineerLabel;
                                @endphp
                                <li data-kit-row="{{ $kitRow->id }}"
                                    style="padding:.5rem 0;border-bottom:1px dotted #F1F5F9;font-size:.86rem;line-height:1.45;">
                                    <div style="word-break:break-word;">
                                        <strong>{{ $kitRow->qty }} ×</strong> {{ $kitRow->part_description }}
                                        <span class="muted">— {{ $kitRowEngineer }}</span>
                                    </div>
                                    @if($kitRow->isAmended())
                                        <span data-kit-chip="amended" style="display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#E0F2FE;color:#075985;font-weight:700;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Amended</span>
                                    @endif
                                    @if($kitRow->isMarked())
                                        <span data-kit-chip="marked" style="display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#FEE2E2;color:#991B1B;font-weight:700;font-size:.68rem;">Marked for deletion — {{ $kitRow->deletion_reason ?: 'No reason recorded' }}</span>
                                    @endif
                                    {{-- Controls only on an OPEN row, and only while capture
                                         is possible. A marked or reconciled row shows its
                                         state and NO buttons: the server refuses either way,
                                         but an affordance that always fails is a bug report
                                         waiting to happen. --}}
                                    @if($kitRow->isOpen() && ! $captureLocked)
                                        <div data-kit-row-controls style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.4rem;">
                                            <button type="button"
                                                    data-capture-control
                                                    data-kit-correct
                                                    data-row="{{ $kitRow->id }}"
                                                    data-room="{{ $room['name'] ?? '' }}"
                                                    data-qty="{{ $kitRow->qty }}"
                                                    data-desc="{{ $kitRow->part_description }}"
                                                    data-engineer="{{ $kitRow->labour_resource_id }}"
                                                    class="btn btn-outline btn-sm"
                                                    style="min-height:44px;padding:.5rem .8rem;font-size:.82rem;">✏️ Correct</button>
                                            <button type="button"
                                                    data-capture-control
                                                    data-kit-mark
                                                    data-row="{{ $kitRow->id }}"
                                                    class="btn btn-outline btn-sm"
                                                    style="min-height:44px;padding:.5rem .8rem;font-size:.82rem;">🗑 Mark for deletion</button>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        {{-- The per-room trigger. ONE page-level drawer serves every
                             room (SCC's PMV shape) — the room travels on the button. --}}
                        @unless($captureLocked)
                            <button type="button"
                                    data-capture-control
                                    data-kit-trigger
                                    data-room="{{ $room['name'] ?? '' }}"
                                    data-room-key="{{ $roomKey }}"
                                    class="btn btn-outline btn-sm"
                                    style="margin-top:.6rem;min-height:44px;padding:.6rem 1rem;font-size:.86rem;">+ Add additional kit</button>
                            <div class="muted" style="font-size:.78rem;margin-top:.3rem;">
                                Tap to open — add one or several before closing.
                            </div>
                        @endunless
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
                                    <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.4rem;">Survey photos</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Mounting heights</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Working at height — methods</div>
                                        <div style="font-size:.88rem;color:#374151;">{{ implode(', ', $wahLabels) }}</div>
                                    </div>
                                @endif

                                {{-- Cable routes --}}
                                @php $cableRoutes = (array) ($ef['cable_routes'] ?? []); @endphp
                                @if(! empty($cableRoutes))
                                    <div style="margin-bottom:.65rem;">
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Cable routes planned</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Wall construction &amp; prep</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Brackets required</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Table info</div>
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
                                        <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2E7BFF;margin-bottom:.3rem;">Floor box info</div>
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
                                                {{-- 46.4-04 (D-07) — serial-label capture writes a
                                                     DeviceLabelPhoto and mints/updates a Device row in
                                                     the asset register, so it is capture, and it goes. --}}
                                                @if ($isHardware && ! $captureLocked)
                                                    <label class="btn btn-outline btn-sm label-cap-btn"
                                                           data-capture-control
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
                                                                {{-- 46.4-04 (D-07) — "Edit / Confirm" POSTs to
                                                                     confirmLabelPhoto, which writes the serial /
                                                                     MAC / model onto the Device row. The READING
                                                                     above stays visible; only the write goes. --}}
                                                                @unless($lp->confirmed || $captureLocked)
                                                                    <button type="button"
                                                                            data-capture-control
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

            {{-- ════════════════════════════════════════════════════════════════════
                 46.4-05 — ONE PAGE-LEVEL DRAWER, REUSED BY EVERY ROOM AND BOTH MODES

                 Shape copied from SCC's PMV add-device drawer
                 (service-contractor-creator/resources/views/pmv/show.blade.php
                 :1329-1332 trigger, :1530-1553 the single shared drawer). SHAPE only —
                 that is a different application and nothing is imported from it.

                 ⚠️ VANILLA ON PURPOSE. This page never loads Alpine, so RAMS's own
                 repeater components would silently no-op here. It is also why the
                 styling is inline: the page has no bundler, and the three pinned
                 files (layouts/app.blade.php, resources/css/app.css,
                 tailwind.config.js) must stay byte-identical.

                 `data-mode` switches the SAME drawer between add and correct, so a
                 pre-filled correction and a fresh add cannot drift into two forms
                 that validate differently.
            ════════════════════════════════════════════════════════════════════ --}}
            @unless($captureLocked)
                <div id="kit-drawer-backdrop" data-capture-control hidden
                     style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:80;"></div>

                <aside id="kit-drawer"
                       data-drawer
                       data-capture-control
                       data-mode="add"
                       data-token="{{ $token }}"
                       role="dialog"
                       aria-modal="true"
                       aria-labelledby="kit-drawer-heading"
                       hidden
                       style="position:fixed;left:0;right:0;bottom:0;z-index:81;background:#fff;border-radius:14px 14px 0 0;box-shadow:0 -6px 24px rgba(15,23,42,.18);padding:1rem 1.1rem 1.5rem;max-height:88vh;overflow-y:auto;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.6rem;margin-bottom:.2rem;">
                        <div>
                            <div id="kit-drawer-heading" data-kit-heading style="font-weight:700;font-size:1rem;">Add additional kit</div>
                            <div class="muted" data-kit-room-label style="font-size:.8rem;margin-top:.15rem;"></div>
                        </div>
                        <button type="button" data-capture-control data-kit-close aria-label="Close"
                                style="border:0;background:transparent;font-size:1.5rem;line-height:1;min-width:44px;min-height:44px;cursor:pointer;color:#475569;">&times;</button>
                    </div>

                    {{-- ENGINEER — A SELECT, AND ONLY EVER A SELECT (D-02).
                         There is no free-text engineer input on this page and there
                         never will be one. The options come from the visit's own
                         allocation via AllocatedEngineers, which carries a name and an
                         id and nothing else (LR-04). --}}
                    <label class="form-label" for="kit-engineer" style="display:block;margin-top:.7rem;font-size:.82rem;font-weight:600;">Engineer</label>
                    <select id="kit-engineer" data-capture-control
                            style="display:block;width:100%;min-height:44px;padding:.5rem .6rem;border:1px solid #D1D5DB;border-radius:8px;font-size:.9rem;background:#fff;">
                        @if(empty($allocatedEngineers))
                            {{-- D-02's fallback, on screen. The row is still saved with a
                                 NULL engineer and the office matches it up — which is why
                                 the column is nullable and why THE FORM STAYS
                                 SUBMITTABLE in this state. --}}
                            <option value="" selected disabled>{{ $unassignedEngineerLabel }}</option>
                        @else
                            <option value="">{{ $unassignedEngineerLabel }}</option>
                            @foreach($allocatedEngineers as $kitEngineerOption)
                                <option value="{{ $kitEngineerOption['id'] }}">{{ $kitEngineerOption['name'] }}</option>
                            @endforeach
                        @endif
                    </select>
                    @if(empty($allocatedEngineers))
                        <div class="muted" style="font-size:.76rem;margin-top:.25rem;">
                            No engineer is allocated to this visit yet. Add the item anyway —
                            it saves without a name and the office will match it up.
                        </div>
                    @endif

                    <label class="form-label" for="kit-qty" style="display:block;margin-top:.7rem;font-size:.82rem;font-weight:600;">Qty</label>
                    <input type="number" id="kit-qty" data-capture-control
                           min="1" max="999" value="1" step="1" inputmode="numeric" required
                           style="display:block;width:100%;max-width:140px;min-height:44px;padding:.5rem .6rem;border:1px solid #D1D5DB;border-radius:8px;font-size:.95rem;">

                    {{-- maxlength mirrors the endpoint's max:500 so the field cannot
                         promise what the server will refuse. D-10: there is no unit
                         box next to this one, deliberately. --}}
                    <label class="form-label" for="kit-desc" style="display:block;margin-top:.7rem;font-size:.82rem;font-weight:600;">Part description</label>
                    <input type="text" id="kit-desc" data-capture-control maxlength="500" required
                           placeholder="e.g. Trunking, 50x50 white"
                           style="display:block;width:100%;min-height:44px;padding:.5rem .6rem;border:1px solid #D1D5DB;border-radius:8px;font-size:.9rem;">

                    <button type="button" id="kit-submit" data-capture-control class="btn btn-teal"
                            style="margin-top:.9rem;width:100%;min-height:48px;font-size:.95rem;">Add</button>
                    <div id="kit-status" data-kit-status class="muted" style="font-size:.8rem;margin-top:.5rem;min-height:1.1em;"></div>
                    <div class="muted" style="font-size:.76rem;margin-top:.2rem;">
                        Each item saves as you tap Add — the drawer stays open so you can add the next one.
                    </div>
                </aside>

                {{-- ── THE MARK PROMPT (D-08) ──────────────────────────────────────
                     NOT a confirm() box, and that is the whole point: a confirm box
                     cannot collect a reason, and here THE TEXT IS THE PAYLOAD. The
                     office reads this sentence and decides what to do with the line,
                     so the submit stays disabled until there are at least three
                     non-whitespace characters — the same floor the server enforces. --}}
                <aside id="kit-mark-dialog"
                       data-drawer
                       data-capture-control
                       data-token="{{ $token }}"
                       role="dialog"
                       aria-modal="true"
                       aria-labelledby="kit-mark-heading"
                       hidden
                       style="position:fixed;left:0;right:0;bottom:0;z-index:82;background:#fff;border-radius:14px 14px 0 0;box-shadow:0 -6px 24px rgba(15,23,42,.18);padding:1rem 1.1rem 1.5rem;max-height:88vh;overflow-y:auto;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.6rem;">
                        <div id="kit-mark-heading" style="font-weight:700;font-size:1rem;">Mark this item for deletion</div>
                        <button type="button" data-capture-control data-kit-mark-close aria-label="Close"
                                style="border:0;background:transparent;font-size:1.5rem;line-height:1;min-width:44px;min-height:44px;cursor:pointer;color:#475569;">&times;</button>
                    </div>
                    <div class="muted" style="font-size:.8rem;margin-top:.3rem;">
                        The line stays on the record, flagged with your reason. The office reads the
                        reason and decides what to do with it — so say what happened.
                    </div>
                    <label class="form-label" for="kit-mark-reason" style="display:block;margin-top:.7rem;font-size:.82rem;font-weight:600;">Reason <span class="req">*</span></label>
                    <textarea id="kit-mark-reason" data-capture-control rows="3" maxlength="500" required
                              placeholder="e.g. Ordered twice — only one length fitted."
                              style="display:block;width:100%;padding:.5rem .6rem;border:1px solid #D1D5DB;border-radius:8px;font-size:.9rem;"></textarea>
                    <button type="button" id="kit-mark-submit" data-capture-control class="btn btn-teal" disabled
                            style="margin-top:.9rem;width:100%;min-height:48px;font-size:.95rem;">Mark for deletion</button>
                    <div id="kit-mark-status" class="muted" style="font-size:.8rem;margin-top:.5rem;min-height:1.1em;"></div>
                </aside>
            @endunless
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

        @if($captureLocked)
            {{-- 46.4-04 — pairs with the fieldset opened above; both read $captureLocked. --}}
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
            let lastWidth = 0;

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
                ctx.strokeStyle = '#0F172A';
                lastWidth = rect.width;
            }

            // 260919-chb — write the current bitmap into the hidden input at the
            // end of every completed stroke, so no intervening reset (this file's
            // resize handler or any future one) can silently empty the field the
            // submit handler relies on. No-op on a blank canvas.
            function captureSignature() {
                if (! dirty) return;
                document.getElementById('signature_image').value = canvas.toDataURL('image/png');
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
                if (drawing) {
                    evt.preventDefault();
                    captureSignature();
                }
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

                // 260919-enb — "the job can be done offline, the job cannot be
                // closed offline". Offline: refuse outright, touch nothing in
                // OfflineQueue. Online: best-effort flush of any queued photos
                // (bounded by a timeout so a hung fetch can never block the
                // sign-off itself), then submit regardless of flush outcome.
                if (! navigator.onLine) {
                    alert('Signing off needs an internet connection. Please move somewhere with signal and try again — your photos and notes are already saved and will not be lost.');
                    return false;
                }

                if (submit) submit.disabled = true;

                const finishSubmit = function () {
                    HTMLFormElement.prototype.submit.call(form);
                };

                // Re-check the real queue depth after the race settles rather
                // than trusting drain()'s returned counts — a concurrent
                // drain() (e.g. the 'online' auto-drain firing at the same
                // moment) short-circuits with {successCount:0,failureCount:0,
                // skipped:true} while the real work happens elsewhere, so the
                // toast must reflect OfflineQueue.count(), not drain()'s result.
                const warnIfOutstandingThenSubmit = function () {
                    (window.OfflineQueue ? window.OfflineQueue.count() : Promise.resolve(0))
                        .then(function (remaining) {
                            if (remaining > 0 && window.__wsShowToast) {
                                window.__wsShowToast(remaining + ' item(s) still uploading — continuing with sign-off', 'warning', 5000);
                            }
                        })
                        .catch(function () { /* best-effort only */ })
                        .then(finishSubmit);
                };

                try {
                    if (! window.OfflineQueue) {
                        finishSubmit();
                        return false;
                    }
                    window.OfflineQueue.count().then(function (pending) {
                        if (pending <= 0) {
                            finishSubmit();
                            return;
                        }
                        const drainWithTimeout = Promise.race([
                            window.OfflineQueue.drain({}),
                            new Promise(function (resolve) {
                                setTimeout(function () { resolve({ timedOut: true }); }, 8000);
                            }),
                        ]);
                        drainWithTimeout.then(warnIfOutstandingThenSubmit).catch(warnIfOutstandingThenSubmit);
                    }).catch(function () {
                        finishSubmit();
                    });
                } catch (e) {
                    finishSubmit();
                }

                return false;
            };

            // Defer initial resize so layout has settled.
            requestAnimationFrame(resizeCanvas);
            // 260919-chb — `resize` fires on mobile for viewport-height-only
            // changes (on-screen keyboard opening/closing, iOS Safari URL-bar
            // collapse) far more often than for a genuine width change. Only the
            // canvas's rendered CSS width actually invalidates the backing store,
            // so gate on measured width to stop those height-only events from
            // wiping a drawn signature (the Worksheet 22 / 21CQ30674-03-OPS bug).
            window.addEventListener('resize', () => {
                const newWidth = canvas.getBoundingClientRect().width;
                const widthChanged = Math.abs(newWidth - lastWidth) > 1;
                if (! widthChanged) return;

                let snapshot = null;
                if (dirty) {
                    try {
                        snapshot = canvas.toDataURL('image/png');
                    } catch (e) {
                        snapshot = null;
                    }
                }

                resizeCanvas();

                if (snapshot) {
                    const img = new Image();
                    img.onload = function () {
                        const rect = canvas.getBoundingClientRect();
                        ctx.drawImage(img, 0, 0, rect.width, rect.height);
                        captureSignature();
                    };
                    img.src = snapshot;
                    // Signature survived the resize — do not reset dirty/drawn state.
                } else {
                    dirty = false;
                    window.__signoffSignatureDrawn = false;
                    window.refreshSignoffSubmitState();
                    document.getElementById('signature_image').value = '';
                }
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
        // ── 46.4-02 (D-04) — the label that rides with a capture ────────────
        // The capture control and its label field live in the same
        // [data-photo-tray], one tray per bucket per room, so "this tray's
        // label" is a closest() away. Returns the element (not the string) so
        // callers can clear it after a successful capture — otherwise the next
        // photo silently inherits the last one's label.
        window.__wsPhotoCaptionInput = function (input) {
            const tray = input && input.closest ? input.closest('[data-photo-tray]') : null;
            return tray ? tray.querySelector('[data-photo-caption]') : null;
        };

        window.uploadWorksheetPhoto = async function uploadWorksheetPhoto(input, token, roomName, bucket) {
            const file = input.files && input.files[0];
            if (!file) return;
            const capEl   = window.__wsPhotoCaptionInput(input);
            const caption = capEl ? String(capEl.value || '').trim() : '';
            const fd = new FormData();
            fd.append('photo', file);
            // room_name travels in the body, not the URL path, so names with
            // '/', '?', '#' (e.g. "Comms Room (Next to Breakout/Townhall Area)")
            // don't 404 against nginx/Apache's encoded-slash rejection.
            fd.append('room_name', roomName);
            // Bucket defaults client-side too, so a call site that forgets the
            // 4th argument behaves like every pre-46.4 caller did.
            fd.append('bucket', bucket || 'completion');
            if (caption) fd.append('caption', caption);
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
                // Clear the label so the NEXT photo does not inherit it.
                if (capEl) { try { capEl.value = ''; } catch (e) {} }
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

        // ── Photo lightbox (46.4-02) ────────────────────────────────────────
        // openPhotoLightbox() has been CALLED from two places on this page
        // since 260508 and was never DEFINED here — and the thumbnail's
        // onclick runs event.preventDefault() first, so tapping a photo has
        // been doing nothing at all. This phase puts photos at the centre of
        // the page, so it is fixed here.
        //
        // ⚠️ DELIBERATELY NOT the components/photo-lightbox.blade.php
        // component. That component exists, but it is Alpine-based and Alpine
        // is NEVER loaded on this standalone page — including it would swap
        // one dead feature for another, exactly as the sign-off form's own
        // dead Alpine directives already demonstrate. It also styles itself
        // with .photo-lightbox classes this page does not carry, and app.css
        // is byte-pinned so they cannot be added.
        //
        // (This comment names no Alpine directive and no component tag on
        // purpose: EngineerLinkPhotoTrayGuardTest counts those literals in
        // this file and a comment would read as an occurrence.)
        //
        // Signature matches the two existing call sites EXACTLY —
        // ([{url, caption}], startIndex) — so neither call site changes.
        // The lightbox is handed url + caption ONLY; it must never be given a
        // photo model, and never `captured_by` (T-46.4-02-04).
        (function () {
            let items = [];
            let index = 0;
            let dlg   = null;

            function render() {
                const item = items[index] || {};
                const img  = dlg.querySelector('[data-lb-img]');
                img.src = item.url || '';
                // textContent / property assignment, never innerHTML — the
                // caption is engineer free text (T-46.4-02-02).
                img.alt = item.caption || '';
                dlg.querySelector('[data-lb-caption]').textContent = item.caption || '';
                dlg.querySelector('[data-lb-idx]').textContent = items.length > 1 ? (index + 1) + ' / ' + items.length : '';
                dlg.querySelectorAll('[data-lb-step]').forEach(function (b) {
                    b.style.display = items.length > 1 ? 'inline-block' : 'none';
                });
            }

            function step(delta) {
                if (!items.length) return;
                index = (index + delta + items.length) % items.length;
                render();
            }

            function build() {
                const d = document.createElement('dialog');
                d.id = 'ws-photo-lightbox';
                d.style.cssText = 'max-width:96vw;max-height:94vh;border:0;border-radius:12px;padding:.75rem;background:#111827;color:#F9FAFB;';
                d.innerHTML =
                      '<img data-lb-img alt="" style="display:block;max-width:90vw;max-height:74vh;margin:0 auto;border-radius:8px;">'
                    + '<div data-lb-caption style="margin-top:.5rem;font-size:.85rem;text-align:center;word-break:break-word;"></div>'
                    + '<div style="margin-top:.6rem;display:flex;align-items:center;justify-content:center;gap:.75rem;">'
                    +   '<button type="button" data-lb-step data-lb-prev style="min-height:40px;min-width:48px;border:0;border-radius:8px;background:#374151;color:#fff;font-size:1.1rem;cursor:pointer;">‹</button>'
                    +   '<span data-lb-idx style="font-size:.8rem;opacity:.8;"></span>'
                    +   '<button type="button" data-lb-step data-lb-next style="min-height:40px;min-width:48px;border:0;border-radius:8px;background:#374151;color:#fff;font-size:1.1rem;cursor:pointer;">›</button>'
                    +   '<button type="button" data-lb-close style="min-height:40px;padding:0 .9rem;border:0;border-radius:8px;background:#2E7BFF;color:#fff;font-size:.85rem;cursor:pointer;">Close</button>'
                    + '</div>';
                document.body.appendChild(d);
                d.querySelector('[data-lb-prev]').addEventListener('click', function () { step(-1); });
                d.querySelector('[data-lb-next]').addEventListener('click', function () { step(1); });
                d.querySelector('[data-lb-close]').addEventListener('click', function () { d.close(); });
                // Backdrop tap closes; Esc is the <dialog>'s own behaviour.
                d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
                d.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
                    if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
                });
                return d;
            }

            window.openPhotoLightbox = function (photos, startIndex) {
                items = (Array.isArray(photos) ? photos : []).filter(function (p) { return p && p.url; });
                if (!items.length) return;
                index = Math.min(Math.max(parseInt(startIndex, 10) || 0, 0), items.length - 1);
                dlg = dlg || document.getElementById('ws-photo-lightbox') || build();
                render();
                if (typeof dlg.showModal === 'function') {
                    if (!dlg.open) dlg.showModal();
                } else {
                    // Very old browser with no <dialog> support — fall back to
                    // the plain link behaviour the thumbnail's href already has.
                    window.open(items[index].url, '_blank');
                }
            };
        })();

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

        function openLabelReview({ photoId, token, photoUrl, extracted, queued, onOverlayRemoved }) {
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
            overlay.querySelector('#lblCancel').onclick = () => {
                overlay.remove();
                onOverlayRemoved && onOverlayRemoved();
                if (queued) {
                    window.__lcQueueShift && window.__lcQueueShift();
                    window.__lcMaybePrompt && window.__lcMaybePrompt();
                }
            };
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
                    if (queued) {
                        overlay.remove();
                        onOverlayRemoved && onOverlayRemoved();
                        window.__lcQueueShift && window.__lcQueueShift();
                        if (window.__lcQueueRead && window.__lcQueueRead().length > 0) {
                            window.__lcMaybePrompt && window.__lcMaybePrompt();
                        } else {
                            window.location.reload();
                        }
                    } else {
                        close();
                    }
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

                            // ⚠️ 46.4-06 — THIS GUARD IS WHAT STOPS AN ENGINEER'S
                            // WORK BEING LOST. A 'kit' row has NO BLOB at all
                            // (D-10 — kit rows are text and numbers only), and
                            // appending an undefined blob THROWS, which would
                            // stamp the row with the unreadable-blob error below
                            // — forever, on the one record that is the
                            // engineer's ONLY copy of that work.
                            // The guard MUST stay ABOVE the append — a source
                            // POSITION assertion in OfflineQueueKitKindGuardTest
                            // pins the ordering, so reordering these two lines
                            // goes red rather than silent.
                            // Positive test on the one NON-binary kind, so a
                            // future kind that DOES carry bytes keeps working
                            // without an edit here.
                            const isBinary = row.kind !== 'kit';
                            if (isBinary) {
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
                            }
                            fd.append('room_name', row.room || '');
                            const fields = row.fields || {};
                            Object.keys(fields).forEach(function (k) {
                                fd.append(k, fields[k]);
                            });

                            // A TABLE, not a nested ternary — the next kind gets
                            // its own line and nothing else moves.
                            const path = row.kind === 'label' ? '/label-photo'
                                       : row.kind === 'kit'   ? '/additional-kit'
                                       :                        '/photos';
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
                    return OfflineQueue.drain({ onSuccess: window.__lcHandleLabelUploadSuccess }).then(function (result) {
                        if (!result) return null;
                        if (result.successCount >= 1) {
                            showToast('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            showToast('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                        window.__lcMaybePrompt && window.__lcMaybePrompt();
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
            window.uploadWorksheetPhoto = async function (input, token, roomName, bucket) {
                const file = input && input.files && input.files[0];
                if (!file) return;

                // 46.4-02 — bucket (D-03) + label (D-04). Both travel the SAME
                // two roads as room_name already does: the FormData on the
                // online path, and the queue row's free-form `fields` bag on
                // the offline path. `fields` is already spread into the drain
                // FormData key by key, so this costs ZERO queue schema change —
                // no DB_VERSION bump, no keyPath change, no index change, and
                // therefore no onupgradeneeded (which only ever creates and
                // would strand every pending photo).
                const __bucket = bucket || 'completion';
                const __capEl  = window.__wsPhotoCaptionInput ? window.__wsPhotoCaptionInput(input) : null;
                const __cap    = __capEl ? String(__capEl.value || '').trim() : '';

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
                    fd.append('bucket', __bucket);
                    if (__cap) fd.append('caption', __cap);
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
                        // Clear the label so the next photo does not inherit it.
                        if (__capEl) { try { __capEl.value = ''; } catch (e) {} }
                        // SAME UX as original — reload so thumbnail + count update.
                        window.location.reload();
                        return;
                    } catch (e) {
                        // Network throw mid-fetch — fall through to enqueue below.
                        console.warn('uploadWorksheetPhoto fetch threw, queuing:', e);
                    }
                }

                // OFFLINE (or online + network throw): enqueue.
                // ⚠ `kind` STAYS 'completed'. A photo is still a photo; the
                // bucket is DATA, not a kind. drain() routes on kind, and a
                // third photo kind would need a third URL arm for the same URL.
                try {
                    await window.OfflineQueue.enqueue({
                        token: token,
                        kind:  'completed',
                        room:  roomName,
                        blob:  uploadFile,
                        mime:  'image/jpeg',
                        fields: __cap ? { bucket: __bucket, caption: __cap } : { bucket: __bucket },
                    });
                    __toast("📥 Saved offline — will upload when you're online", 'info');
                } catch (e) {
                    __toast('Could not save offline. Please try again when online.', 'error');
                }
                if (__capEl) { try { __capEl.value = ''; } catch (e) {} }
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
                    window.OfflineQueue.drain({ onSuccess: window.__lcHandleLabelUploadSuccess }).then(function (result) {
                        if (!result) return;
                        if (result.successCount >= 1) {
                            (window.__wsShowToast || function(){})('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            (window.__wsShowToast || function(){})('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                        window.__lcMaybePrompt && window.__lcMaybePrompt();
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
                    window.OfflineQueue.drain({ onSuccess: window.__lcHandleLabelUploadSuccess }).then(function (result) {
                        if (!result) return;
                        if (result.successCount >= 1) {
                            (window.__wsShowToast || function(){})('✅ Uploaded ' + result.successCount + ' pending photo(s)', 'success');
                        }
                        if (result.hitMaxRetry >= 1) {
                            (window.__wsShowToast || function(){})('⚠ ' + result.hitMaxRetry + ' upload(s) failed after retries — tap the pending chip to review', 'warning', 6000);
                        }
                        window.__lcMaybePrompt && window.__lcMaybePrompt();
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

    <script>
        // ── 260919-f8e — confirm queued serial labels on reconnect ──
        // Bridges OfflineQueue.drain()'s onSuccess callback to the existing
        // AI-extraction confirm/edit modal (openLabelReview) so a label photo
        // that finishes uploading after a reconnect (auto-drain / Retry all /
        // per-item retry) is automatically surfaced for confirmation instead
        // of relying solely on the engineer finding the amber "Review" badge
        // in a collapsed Kit List drawer. Durable across an unrelated page
        // reload via sessionStorage only — no IndexedDB schema change.
        (function () {
            'use strict';

            function _lcQueueKey() {
                return 'wsPendingLabelConfirms_' + {{ (int) $worksheet->id }};
            }

            function _lcQueueRead() {
                try {
                    var raw = sessionStorage.getItem(_lcQueueKey());
                    if (!raw) return [];
                    var arr = JSON.parse(raw);
                    return Array.isArray(arr) ? arr : [];
                } catch (e) {
                    return [];
                }
            }

            function _lcQueueWrite(arr) {
                try {
                    sessionStorage.setItem(_lcQueueKey(), JSON.stringify(arr));
                } catch (e) { /* sessionStorage disabled — silently skip */ }
            }

            function _lcQueuePush(entry) {
                var arr = _lcQueueRead();
                var exists = arr.some(function (e) { return e.photoId === entry.photoId; });
                if (exists) return;
                arr.push(entry);
                _lcQueueWrite(arr);
            }

            function _lcQueueShift() {
                var arr = _lcQueueRead();
                arr.shift();
                _lcQueueWrite(arr);
            }

            let _lcModalOpen = false;

            function _lcMaybePrompt() {
                if (_lcModalOpen) return;
                var arr = _lcQueueRead();
                if (!arr.length) return;
                var entry = arr[0];
                _lcModalOpen = true;
                openLabelReview({
                    photoId: entry.photoId,
                    token: entry.token,
                    photoUrl: entry.photoUrl,
                    extracted: entry.extracted || {},
                    queued: true,
                    onOverlayRemoved: function () { _lcModalOpen = false; },
                });
            }

            // Exposed so openLabelReview (declared in an earlier, separate
            // <script> tag's top-level scope) can advance the queue directly
            // on Cancel/Confirm without reaching into this IIFE's closure.
            window.__lcQueueRead = _lcQueueRead;
            window.__lcQueueShift = _lcQueueShift;

            window.__lcHandleLabelUploadSuccess = function (row, json) {
                if (row && row.kind === 'label' && json && json.id) {
                    _lcQueuePush({
                        photoId: json.id,
                        token: row.token,
                        photoUrl: json.photo_url,
                        extracted: json.ai_extracted || {},
                    });
                }
            };

            window.__lcMaybePrompt = function () {
                var before = _lcQueueRead().length;
                if (before && window.__wsShowToast && !_lcModalOpen) {
                    window.__wsShowToast('Label photo(s) uploaded — confirm the serial reading (' + before + ')', 'info', 6000);
                }
                _lcMaybePrompt();
            };

            function _lcInit() {
                window.__lcMaybePrompt();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', _lcInit);
            } else {
                _lcInit();
            }
        })();
    </script>

    {{-- ══════════════════════════════════════════════════════════════════════
         46.4-05 — THE ADDITIONAL-KIT DRAWER (vanilla, no framework)

         Alpine is never loaded on this page, so every behaviour here is hand
         rolled in the page's existing style. Nothing is imported and nothing
         is installed.

         THREE RULES THIS BLOCK EXISTS TO HOLD:

         1. ADD DOES NOT RELOAD. Each tap POSTs, grafts the returned row into the
            room's list, resets qty to 1, clears the description, KEEPS the
            engineer and KEEPS THE DRAWER OPEN — an engineer standing in a room
            with six items should tap six times, not reload six pages.

         2. ON A FAILURE THE FORM IS NOT CLEARED. The server's own message goes
            in the status line and what the engineer typed stays where it is.
            Losing a typed part number to a dropped packet is the fastest way to
            make somebody go back to ringing the office.

         3. ⚠️ EVERY GRAFTED STRING GOES THROUGH _esc. part_description and
            deletion_reason are engineer free text on a document the CLIENT
            SIGNS, so a JS-built row is exactly as much of an XSS surface as a
            Blade echo. _esc is a local copy of the queue panel's helper
            (defined inside its own IIFE and not exported) and escapes the same
            five characters — kept identical deliberately.

         OFFLINE (the ruling lives in plan 46.4-06, next to the queue): ADD works
         offline because plan 06 queues it. CORRECT and MARK do NOT — they need
         the row's current server-side state to diff against, and a queued
         correction could drain onto a row the office had already reconciled.
         So they are shown VISIBLY DISABLED with the words "Needs a connection"
         and they say so on tap. What is NOT acceptable is a control that looks
         like it worked and silently loses the change.
    ══════════════════════════════════════════════════════════════════════ --}}
    <script>
        (function () {
            'use strict';

            const drawer = document.getElementById('kit-drawer');
            const markDlg = document.getElementById('kit-mark-dialog');

            // Absent when the worksheet is signed (capture is closed) or when the
            // worksheet has no rooms at all. Both are normal, neither is an error.
            if (!drawer || !markDlg) return;

            const backdrop   = document.getElementById('kit-drawer-backdrop');
            const heading    = drawer.querySelector('[data-kit-heading]');
            const roomLabel  = drawer.querySelector('[data-kit-room-label]');
            const engineerEl = document.getElementById('kit-engineer');
            const qtyEl      = document.getElementById('kit-qty');
            const descEl     = document.getElementById('kit-desc');
            const submitEl   = document.getElementById('kit-submit');
            const statusEl   = document.getElementById('kit-status');

            const reasonEl     = document.getElementById('kit-mark-reason');
            const markSubmitEl = document.getElementById('kit-mark-submit');
            const markStatusEl = document.getElementById('kit-mark-status');

            const token = drawer.dataset.token || '';

            let activeRoom    = '';
            let activeRoomKey = '';
            let activeRowId   = null;
            let markRowId     = null;
            // The IndexedDB key of a row being corrected ON THE DEVICE.
            // Never a server id — a queued row does not have one yet.
            let queuedEditId  = null;

            // Identical to the queue panel's helper. That one lives inside its own
            // IIFE and is not exported; copying five replaces is better than
            // reaching into another closure or making a global out of it.
            function _esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function _headers() {
                return {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                };
            }

            function _toast(message, tone) {
                if (window.__wsShowToast) {
                    window.__wsShowToast(message, tone || 'info', 4000);
                }
            }

            // ══════════════════════════════════════════════════════════════
            //  46.4-06 — WHAT QUEUES OFFLINE, AND WHAT DOES NOT. THE REASONING
            //  ITSELF, AT THE CODE — not a pointer to a plan file, because the
            //  plan file will not be open when somebody next wonders.
            //
            //  ADD queues. A kit row has no blob at all, so it is unambiguously a
            //  non-binary record and the drain branch is a clean split.
            //  CORRECT and MARK on a row that is ALREADY ON THE SERVER require a
            //  connection, and render visibly disabled reading "Needs a
            //  connection". A row still sitting in the QUEUE can be corrected or
            //  discarded on the device, because that is not a server operation at
            //  all. Four reasons:
            //
            //  1. A QUEUED ADD HAS NO SERVER ID. A queued "modify row 41" that
            //     arrives before the add which creates row 41 is a second,
            //     order-dependent protocol layered on a store whose schema is
            //     frozen at version 1 — client-side temporary ids, a drain-time
            //     rewrite, and a merge rule for the case where the add succeeded
            //     and the modify then 422'd. That is exactly the class of change
            //     that STRANDS WORK, and stranding work is the failure the whole
            //     offline queue exists to prevent.
            //
            //  2. THIS PAGE ALREADY HAS THIS IDIOM AND ENGINEERS HAVE MET IT.
            //     Sign-off refuses offline by explicit design. Room-complete and
            //     survey-reviewed are plain POST+redirect with no offline path at
            //     all. Only photos queue today. Requiring a connection to CHANGE
            //     something is consistent; it is not a new tax.
            //
            //  3. CORRECTING A QUEUED ROW NEEDS NO SERVER. The row has not left
            //     the phone. Editing or discarding it is an IndexedDB write, and
            //     discarding it is NOT a "mark for deletion with a reason" in
            //     D-08's sense — nothing reached the office, so there is nothing
            //     to explain. The confirm text says exactly that.
            //
            //  4. THE ENGINEER IS ALWAYS TOLD WHICH CASE THEY ARE IN. A server
            //     row's controls read "Needs a connection" while offline; a queued
            //     row's read Edit and Discard and genuinely work; and a queued row
            //     that has since drained loses both and says so.
            //     ⚠️ AN AFFORDANCE THAT APPEARS TO WORK OFFLINE AND SILENTLY
            //     LOSES THE CHANGE IS THE ONE UNACCEPTABLE OUTCOME. Neither half
            //     of this ruling permits one.
            //
            //  THE COST, STATED RATHER THAN HIDDEN: an engineer who typed the
            //  wrong quantity offline, and has already regained signal long enough
            //  for the row to drain, must wait for signal to correct it. That is a
            //  real limitation. The alternative is a queued-modify protocol, which
            //  is its own phase, not a bolt-on here.
            // ══════════════════════════════════════════════════════════════

            // The label of whichever engineer the picker currently shows — used
            // for an optimistically grafted row, where there is no server response
            // to read engineer_name out of. When nobody is allocated this is
            // D-02's fallback sentence, which is what the option already says.
            function _engineerLabel() {
                if (!engineerEl) return '';
                const opt = engineerEl.options[engineerEl.selectedIndex];
                return opt ? opt.text : '';
            }

            // A row that is still ON THE DEVICE. Its controls are Edit / Discard,
            // never Correct / Mark for deletion — see reason 3 above.
            function queuedRowInnerHtml(queueId, fields, engineerName) {
                const btn = ' class="btn btn-outline btn-sm" style="min-height:44px;padding:.5rem .8rem;font-size:.82rem;"';
                return ''
                    + '<div style="word-break:break-word;">'
                    +   '<strong>' + _esc(fields.qty) + ' &times;</strong> ' + _esc(fields.part_description)
                    +   ' <span class="muted">&mdash; ' + _esc(engineerName) + '</span>'
                    + '</div>'
                    + '<span data-kit-chip="queued" style="display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#FEF3C7;color:#92400E;font-weight:700;font-size:.68rem;">'
                    +   'Queued on this device &mdash; uploads by itself when you are online'
                    + '</span>'
                    + '<div data-kit-row-controls style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.4rem;">'
                    +   '<button type="button" data-capture-control data-kit-edit'
                    +     ' data-queued="' + _esc(queueId) + '"'
                    +     ' data-room="' + _esc(activeRoom) + '"'
                    +     ' data-qty="' + _esc(fields.qty) + '"'
                    +     ' data-desc="' + _esc(fields.part_description) + '"'
                    +     ' data-engineer="' + _esc(fields.labour_resource_id) + '"'
                    +     btn + '>&#9999;&#65039; Edit</button>'
                    +   '<button type="button" data-capture-control data-kit-discard'
                    +     ' data-queued="' + _esc(queueId) + '"'
                    +     btn + '>&#10005; Discard</button>'
                    + '</div>';
            }

            // ADD, with no signal. The row is saved on the device and drains by
            // itself; nothing the engineer typed is lost, and the toast is the
            // same sentence the photo queue already uses.
            async function enqueueKitRow(qty, desc, engineerRaw) {
                if (!window.OfflineQueue) {
                    statusEl.textContent = 'No connection, and this browser cannot save offline. Nothing was lost — try again when you have signal.';
                    return;
                }
                let queueId = null;
                try {
                    queueId = await window.OfflineQueue.enqueue({
                        token: token,
                        kind:  'kit',
                        room:  activeRoom,
                        // NO BLOB. That is the whole point of the third kind.
                        blob:  undefined,
                        mime:  null,
                        // The free-form fields bag — spread into the FormData at
                        // drain exactly as the photo queue's bucket and caption
                        // already are. NO SCHEMA CHANGE.
                        fields: {
                            labour_resource_id: engineerRaw,
                            qty: qty,
                            part_description: desc,
                        },
                    });
                } catch (e) {
                    queueId = null;
                }
                if (queueId == null) {
                    statusEl.textContent = 'Could not save this item on the device. Nothing was lost — try again.';
                    return;
                }

                const fields = { labour_resource_id: engineerRaw, qty: qty, part_description: desc };
                const list = listFor(activeRoomKey);
                if (list) {
                    const li = document.createElement('li');
                    li.setAttribute('data-kit-row', 'queued-' + queueId);
                    li.setAttribute('data-kit-queued-row', String(queueId));
                    li.setAttribute('style', 'padding:.5rem 0;border-bottom:1px dotted #F1F5F9;font-size:.86rem;line-height:1.45;');
                    li.innerHTML = queuedRowInnerHtml(queueId, fields, _engineerLabel());
                    list.appendChild(li);
                    wireQueuedControls(li);
                    bumpCount(activeRoomKey, 1);
                }

                qtyEl.value = '1';
                descEl.value = '';
                descEl.focus();
                statusEl.textContent = 'Saved on this device. Add another — they all upload when you are back online.';
                _toast("📥 Saved offline — will upload when you're online", 'info');
            }

            // ── Open / close ──────────────────────────────────────────────────

            function openDrawer(mode, room, roomKey) {
                activeRoom    = room || '';
                activeRoomKey = roomKey || '';
                drawer.dataset.mode = mode;
                heading.textContent = mode === 'modify' ? 'Correct this item'
                    : mode === 'queued' ? 'Correct this queued item'
                    :                     'Add additional kit';
                submitEl.textContent = mode === 'modify' ? 'Save correction'
                    : mode === 'queued' ? 'Save on this device'
                    :                     'Add';
                roomLabel.textContent = activeRoom;
                statusEl.textContent = '';
                if (backdrop) backdrop.hidden = false;
                drawer.hidden = false;
                descEl.focus();
            }

            function closeDrawer() {
                drawer.hidden = true;
                if (backdrop) backdrop.hidden = true;
                activeRowId = null;
                queuedEditId = null;
                drawer.dataset.mode = 'add';
            }

            function closeMark() {
                markDlg.hidden = true;
                if (backdrop) backdrop.hidden = true;
                markRowId = null;
                reasonEl.value = '';
                markSubmitEl.disabled = true;
                markStatusEl.textContent = '';
            }

            // ── Row markup — the SAME shape the Blade renders above ───────────

            function controlsHtml(row) {
                return ''
                    + '<div data-kit-row-controls style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.4rem;">'
                    +   '<button type="button" data-capture-control data-kit-correct'
                    +     ' data-row="' + _esc(row.id) + '"'
                    +     ' data-room="' + _esc(activeRoom) + '"'
                    +     ' data-qty="' + _esc(row.qty) + '"'
                    +     ' data-desc="' + _esc(row.part_description) + '"'
                    +     ' data-engineer="' + _esc(row.labour_resource_id == null ? '' : row.labour_resource_id) + '"'
                    +     ' class="btn btn-outline btn-sm" style="min-height:44px;padding:.5rem .8rem;font-size:.82rem;">&#9999;&#65039; Correct</button>'
                    +   '<button type="button" data-capture-control data-kit-mark'
                    +     ' data-row="' + _esc(row.id) + '"'
                    +     ' class="btn btn-outline btn-sm" style="min-height:44px;padding:.5rem .8rem;font-size:.82rem;">&#128465; Mark for deletion</button>'
                    + '</div>';
            }

            function rowInnerHtml(row, opts) {
                const flags = opts || {};
                let html = ''
                    + '<div style="word-break:break-word;">'
                    +   '<strong>' + _esc(row.qty) + ' &times;</strong> ' + _esc(row.part_description)
                    +   ' <span class="muted">&mdash; ' + _esc(row.engineer_name) + '</span>'
                    + '</div>';

                if (flags.amended) {
                    html += '<span data-kit-chip="amended" style="display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#E0F2FE;color:#075985;font-weight:700;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Amended</span>';
                }
                if (flags.marked) {
                    html += '<span data-kit-chip="marked" style="display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#FEE2E2;color:#991B1B;font-weight:700;font-size:.68rem;">Marked for deletion &mdash; '
                        + _esc(flags.deletion_reason || 'No reason recorded') + '</span>';
                }
                // A marked row shows state and NO controls — matching the Blade.
                if (!flags.marked) {
                    html += controlsHtml(row);
                }
                return html;
            }

            function listFor(roomKey) {
                return document.querySelector('[data-kit-list][data-room-key="' + roomKey + '"]');
            }

            function bumpCount(roomKey, delta) {
                const title = document.querySelector('[data-kit-title][data-room-key="' + roomKey + '"]');
                if (!title) return;
                const span = title.querySelector('[data-kit-count]');
                const next = Math.max(0, parseInt(span ? span.textContent : '0', 10) + delta);
                if (span) span.textContent = String(next);
                title.style.display = next > 0 ? 'block' : 'none';
            }

            // ── Triggers ──────────────────────────────────────────────────────

            document.querySelectorAll('[data-kit-trigger]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activeRowId = null;
                    qtyEl.value = '1';
                    descEl.value = '';
                    openDrawer('add', btn.dataset.room, btn.dataset.roomKey);
                });
            });

            function wireCorrect(btn) {
                btn.addEventListener('click', function () {
                    if (!isOnline()) { _toast('Correcting an item needs a connection.', 'warning'); return; }
                    const li = btn.closest('[data-kit-row]');
                    const list = li ? li.closest('[data-kit-list]') : null;
                    activeRowId = btn.dataset.row;
                    qtyEl.value = btn.dataset.qty || '1';
                    descEl.value = btn.dataset.desc || '';
                    engineerEl.value = btn.dataset.engineer || '';
                    openDrawer('modify', btn.dataset.room, list ? list.dataset.roomKey : '');
                });
            }

            function wireMark(btn) {
                btn.addEventListener('click', function () {
                    if (!isOnline()) { _toast('Marking an item for deletion needs a connection.', 'warning'); return; }
                    markRowId = btn.dataset.row;
                    reasonEl.value = '';
                    markSubmitEl.disabled = true;
                    markStatusEl.textContent = '';
                    if (backdrop) backdrop.hidden = false;
                    markDlg.hidden = false;
                    reasonEl.focus();
                });
            }

            function wireRowControls(scope) {
                (scope || document).querySelectorAll('[data-kit-correct]').forEach(function (b) {
                    if (b.dataset.wired) return;
                    b.dataset.wired = '1';
                    wireCorrect(b);
                });
                (scope || document).querySelectorAll('[data-kit-mark]').forEach(function (b) {
                    if (b.dataset.wired) return;
                    b.dataset.wired = '1';
                    wireMark(b);
                });
                // A row grafted in must carry the same offline wording as one the
                // server rendered — otherwise the state depends on when the row
                // arrived, which is exactly the bug the online/offline listeners
                // exist to avoid.
                applyOnlineState();
            }

            // ── A row still ON THE DEVICE: Edit / Discard ───────────────
            // Deliberately NOT Correct / Mark for deletion. Nothing has reached
            // the office, so there is no amendment to append and no reason to
            // record. See the four-reason ruling at the head of this block.
            function wireQueuedControls(scope) {
                (scope || document).querySelectorAll('[data-kit-edit]').forEach(function (b) {
                    if (b.dataset.wired) return;
                    b.dataset.wired = '1';
                    b.addEventListener('click', function () {
                        activeRowId  = null;
                        queuedEditId = parseInt(b.dataset.queued, 10);
                        qtyEl.value  = b.dataset.qty || '1';
                        descEl.value = b.dataset.desc || '';
                        if (engineerEl) engineerEl.value = b.dataset.engineer || '';
                        const li   = b.closest('[data-kit-row]');
                        const list = li ? li.closest('[data-kit-list]') : null;
                        openDrawer('queued', b.dataset.room, list ? list.dataset.roomKey : '');
                    });
                });
                (scope || document).querySelectorAll('[data-kit-discard]').forEach(function (b) {
                    if (b.dataset.wired) return;
                    b.dataset.wired = '1';
                    b.addEventListener('click', function () {
                        // NO REASON IS ASKED FOR, and that is the point: this row
                        // has not reached the office, so there is nobody to
                        // explain it to. It is not a D-08 mark-for-deletion.
                        if (!window.confirm('Discard this item?\n\nIt is still on this device and has NOT reached the office, so there is nothing to explain \u2014 it simply will not be sent.')) return;
                        const qid  = parseInt(b.dataset.queued, 10);
                        const li   = b.closest('[data-kit-row]');
                        const list = li ? li.closest('[data-kit-list]') : null;
                        const key  = list ? list.dataset.roomKey : '';
                        window.OfflineQueue.remove(qid).then(function () {
                            if (li) li.remove();
                            if (key) bumpCount(key, -1);
                        });
                    });
                });
            }

            // When a queued row DRAINS, its device-local controls quietly stop
            // working — update() refuses a row that is no longer in the store.
            // Leaving a dead Edit button on screen would be exactly the silent
            // failure this plan exists to prevent, so swap the chip and drop the
            // controls. The real row, with Correct / Mark for deletion, arrives
            // on the next render from the database.
            function refreshQueuedRows() {
                if (!window.OfflineQueue) return;
                window.OfflineQueue.list().then(function (rows) {
                    const live = {};
                    rows.forEach(function (r) { live[r.id] = true; });
                    document.querySelectorAll('[data-kit-queued-row]').forEach(function (li) {
                        const qid = parseInt(li.getAttribute('data-kit-queued-row'), 10);
                        if (live[qid]) return;
                        const controls = li.querySelector('[data-kit-row-controls]');
                        if (controls) controls.remove();
                        const chip = li.querySelector('[data-kit-chip="queued"]');
                        if (chip) chip.textContent = 'Uploaded \u2014 reload the page to correct this item';
                    });
                });
            }

            drawer.querySelectorAll('[data-kit-close]').forEach(function (b) {
                b.addEventListener('click', closeDrawer);
            });
            markDlg.querySelectorAll('[data-kit-mark-close]').forEach(function (b) {
                b.addEventListener('click', closeMark);
            });
            if (backdrop) {
                backdrop.addEventListener('click', function () { closeDrawer(); closeMark(); });
            }

            // ── Add / correct submit ──────────────────────────────────────────

            submitEl.addEventListener('click', async function () {
                const isModify = drawer.dataset.mode === 'modify';
                // A row still on the device. Not a server operation at all.
                const isQueuedEdit = drawer.dataset.mode === 'queued';
                const qty  = parseInt(qtyEl.value, 10);
                const desc = (descEl.value || '').trim();

                if (!(qty >= 1 && qty <= 999)) { statusEl.textContent = 'Enter a quantity between 1 and 999.'; return; }
                if (desc === '') { statusEl.textContent = 'Enter a part description.'; return; }

                const engineerRaw = engineerEl ? engineerEl.value : '';

                // 46.4-06 — A QUEUED ROW IS CORRECTED ON THE DEVICE. No network,
                // no server id, no amendment trail: nothing has reached the
                // office yet, so there is nothing to amend.
                if (isQueuedEdit) {
                    let ok = false;
                    try {
                        ok = await window.OfflineQueue.update(queuedEditId, {
                            fields: { labour_resource_id: engineerRaw, qty: qty, part_description: desc },
                        });
                    } catch (e) {
                        ok = false;
                    }
                    if (!ok) {
                        // It drained (or started uploading) while the drawer was
                        // open. SAY SO rather than pretend the edit landed.
                        statusEl.textContent = 'That item has already uploaded \u2014 reload the page to correct it.';
                        return;
                    }
                    const qli = document.querySelector('[data-kit-queued-row="' + queuedEditId + '"]');
                    if (qli) {
                        qli.innerHTML = queuedRowInnerHtml(
                            queuedEditId,
                            { labour_resource_id: engineerRaw, qty: qty, part_description: desc },
                            _engineerLabel()
                        );
                        wireQueuedControls(qli);
                    }
                    closeDrawer();
                    _toast('Corrected on this device. It still uploads by itself.', 'info');
                    return;
                }

                // 46.4-06 — ADD WORKS OFFLINE (D-06, "make queue"). Correct and
                // mark do not; see the ruling above. The row goes to IndexedDB
                // with no blob and drains itself when signal returns.
                if (!isModify && !isOnline()) {
                    await enqueueKitRow(qty, desc, engineerRaw);
                    return;
                }

                const payload = {
                    qty: qty,
                    part_description: desc,
                    labour_resource_id: engineerRaw === '' ? null : parseInt(engineerRaw, 10),
                };
                let url = '/worksheet/' + encodeURIComponent(token) + '/additional-kit';
                if (isModify) {
                    url += '/' + encodeURIComponent(activeRowId);
                } else {
                    payload.room_name = activeRoom;
                }

                submitEl.disabled = true;
                statusEl.textContent = isModify ? 'Saving correction…' : 'Adding…';

                try {
                    const resp = await fetch(url, { method: 'POST', headers: _headers(), body: JSON.stringify(payload) });
                    const data = await resp.json().catch(function () { return {}; });

                    if (!resp.ok) {
                        // The server's own sentence, verbatim — including the
                        // sign-off lock's message when a worksheet was signed
                        // while this drawer was open. THE FORM IS NOT CLEARED.
                        statusEl.textContent = data.message || 'That did not save. Try again.';
                        return;
                    }

                    if (isModify) {
                        const li = document.querySelector('[data-kit-row="' + activeRowId + '"]');
                        if (li) {
                            li.innerHTML = rowInnerHtml({
                                id: data.id,
                                qty: data.qty,
                                part_description: data.part_description,
                                engineer_name: data.engineer_name,
                                labour_resource_id: payload.labour_resource_id,
                            }, { amended: true });
                            wireRowControls(li);
                        }
                        statusEl.textContent = 'Correction saved.';
                        closeDrawer();
                        return;
                    }

                    const list = listFor(activeRoomKey);
                    if (list) {
                        const li = document.createElement('li');
                        li.setAttribute('data-kit-row', data.id);
                        li.setAttribute('style', 'padding:.5rem 0;border-bottom:1px dotted #F1F5F9;font-size:.86rem;line-height:1.45;');
                        li.innerHTML = rowInnerHtml({
                            id: data.id,
                            qty: data.qty,
                            part_description: data.part_description,
                            engineer_name: data.engineer_name,
                            labour_resource_id: payload.labour_resource_id,
                        }, {});
                        list.appendChild(li);
                        wireRowControls(li);
                        bumpCount(activeRoomKey, 1);
                    }

                    // Reset for the NEXT item: keep the engineer, keep the drawer.
                    qtyEl.value = '1';
                    descEl.value = '';
                    descEl.focus();
                    statusEl.textContent = 'Added. Add another, or close when you are done.';
                } catch (e) {
                    // The POST threw mid-flight — signal dropped between the tap
                    // and the response. Same shape uploadWorksheetPhoto already
                    // falls back on: queue it rather than lose what was typed.
                    // MODIFY is NOT queued; see the ruling above.
                    if (!isModify) {
                        await enqueueKitRow(qty, desc, engineerRaw);
                        return;
                    }
                    statusEl.textContent = 'That did not save — check your signal and try again.';
                } finally {
                    submitEl.disabled = false;
                }
            });

            // ── Mark submit ───────────────────────────────────────────────────

            reasonEl.addEventListener('input', function () {
                // Three non-whitespace characters — the same floor the server
                // enforces, so the button never promises a 422.
                markSubmitEl.disabled = (reasonEl.value || '').trim().length < 3;
            });

            markSubmitEl.addEventListener('click', async function () {
                const reason = (reasonEl.value || '').trim();
                if (reason.length < 3) { return; }

                markSubmitEl.disabled = true;
                markStatusEl.textContent = 'Marking…';

                const url = '/worksheet/' + encodeURIComponent(token)
                    + '/additional-kit/' + encodeURIComponent(markRowId) + '/mark-deleted';

                try {
                    const resp = await fetch(url, {
                        method: 'POST', headers: _headers(),
                        body: JSON.stringify({ deletion_reason: reason }),
                    });
                    const data = await resp.json().catch(function () { return {}; });

                    if (!resp.ok) {
                        markStatusEl.textContent = data.message || 'That did not save. Try again.';
                        markSubmitEl.disabled = false;
                        return;
                    }

                    const li = document.querySelector('[data-kit-row="' + markRowId + '"]');
                    if (li) {
                        const controls = li.querySelector('[data-kit-row-controls]');
                        if (controls) controls.remove();
                        const chip = document.createElement('span');
                        chip.setAttribute('data-kit-chip', 'marked');
                        chip.setAttribute('style', 'display:inline-block;margin-top:.25rem;padding:1px 8px;border-radius:9999px;background:#FEE2E2;color:#991B1B;font-weight:700;font-size:.68rem;');
                        chip.innerHTML = 'Marked for deletion &mdash; ' + _esc(data.deletion_reason || reason);
                        li.appendChild(chip);
                    }
                    closeMark();
                    _toast('Marked for deletion. The line stays on the record with your reason.', 'info');
                } catch (e) {
                    markStatusEl.textContent = 'That did not save — check your signal and try again.';
                    markSubmitEl.disabled = false;
                }
            });

            // ── Online / offline state, bound to REALITY not page-load time ───

            function isOnline() {
                return navigator.onLine !== false;
            }

            function applyOnlineState() {
                const online = isOnline();
                document.querySelectorAll('[data-kit-correct],[data-kit-mark]').forEach(function (b) {
                    if (!b.dataset.labelWas) b.dataset.labelWas = b.innerHTML;
                    // aria-disabled, NOT the disabled attribute: a natively
                    // disabled button fires no click, and then the engineer taps
                    // a dead control and learns nothing. This one still explains
                    // itself.
                    b.setAttribute('aria-disabled', online ? 'false' : 'true');
                    b.style.opacity = online ? '' : '.55';
                    b.style.textDecoration = '';
                    if (online) {
                        b.innerHTML = b.dataset.labelWas;
                        b.removeAttribute('title');
                    } else {
                        b.innerHTML = 'Needs a connection';
                        b.setAttribute('title', 'Correcting or marking an item needs a connection. Adding still works offline.');
                    }
                });
            }

            window.addEventListener('online', applyOnlineState);
            window.addEventListener('offline', applyOnlineState);

            function _kitInit() {
                wireRowControls(document);
                wireQueuedControls(document);
                applyOnlineState();
                if (window.OfflineQueue && window.OfflineQueue.subscribe) {
                    window.OfflineQueue.subscribe(refreshQueuedRows);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', _kitInit);
            } else {
                _kitInit();
            }
        })();
    </script>

</body>
</html>
