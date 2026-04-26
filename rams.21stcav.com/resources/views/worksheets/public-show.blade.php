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
        .room-drawer summary:hover { filter: brightness(.97); }
        .room-drawer summary .chev {
            font-size: 1.1rem; transition: transform 200ms ease;
            color: #178A95;
        }
        .room-drawer.gold summary .chev,
        .room-drawer.amber summary .chev { color: #D97706; }
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
        @endphp

        @if(empty($rooms))
            <div class="card">
                <div class="card-title">Worksheet</div>
                <p class="muted">No room data is available yet — please contact your project manager.</p>
            </div>
        @else
            <p class="muted" style="font-size:.85rem;margin-bottom:.85rem;">
                Tap each space to expand. Use the drawers inside to switch between AV
                works summary, kit list, and install steps. Photos required per space
                before sign-off.
            </p>
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
                @endphp

                <details class="card" {{ $idx === 0 ? 'open' : '' }}>
                    <summary class="room-summary">
                        <span class="room-chevron">▶</span>
                        <span class="room-summary-name">{{ $room['name'] ?? 'Unknown Room' }}</span>
                        <span class="photo-count-pill {{ $photoCount === 0 ? 'zero' : '' }}">
                            📷 {{ $photoCount }}
                        </span>
                    </summary>

                    {{-- AV WORKS drawer (teal) --}}
                    @if(! empty($bullets))
                        <details class="room-drawer teal">
                            <summary>
                                <span>📋 AV Works ({{ count($bullets) }})</span>
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

                    {{-- KIT LIST drawer (gold) --}}
                    @if(! empty($equipment))
                        <details class="room-drawer gold">
                            <summary>
                                <span>📦 Kit List ({{ count($equipment) }})</span>
                                <span class="chev">▾</span>
                            </summary>
                            <div class="room-drawer-body">
                                <ul class="kit-rows">
                                    @foreach($equipment as $item)
                                        <li>
                                            <span class="qty-pill">{{ $item['quantity'] ?? $item['qty'] ?? 1 }}×</span>
                                            <span style="flex:1;">{{ $item['name'] ?? $item['description'] ?? '—' }}</span>
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

                    {{-- Photo tray — engineers must capture at least one photo per
                         space before requesting client sign-off. --}}
                    <div class="photo-tray" data-photo-tray data-room-key="{{ $roomKey }}">
                        <div class="photo-tray-title">📷 Photos for this space (<span data-photo-count>{{ $photoCount }}</span>)</div>
                        <div class="photo-thumbs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
                            @foreach($roomPhotos as $p)
                                <div style="position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;background:#F3F4F6;flex-shrink:0;">
                                    <a href="{{ route('public-worksheet.photos.serve', ['token' => $token, 'photo' => $p->id]) }}"
                                       target="_blank">
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
                            <input type="file" accept="image/*" capture="environment" style="display:none;"
                                   onchange="uploadWorksheetPhoto(this, '{{ $token }}', '{{ addslashes($room['name'] ?? '') }}')">
                        </label>
                        @if($photoCount === 0)
                            <div class="photo-warn" style="margin-top:.55rem;">
                                ⚠ No photos captured yet — capture at least one before requesting sign-off.
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach
        @endif

        {{-- ── Sign-off card ─────────────────────────────────────────── --}}
        <div class="card">
            <div class="card-title">Client Sign-Off</div>
            <p style="font-size:.88rem;color:#4B5563;margin-bottom:.95rem;">
                By signing below you confirm you have reviewed the installation worksheet for this project.
                If you have outstanding items, tick the box below and list them in the comments — your sign-off
                is still recorded but the engineering team will be notified to follow up.
            </p>

            <form method="POST"
                  action="{{ route('public-worksheet.sign', ['token' => $token]) }}"
                  id="signoff-form"
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

                <div class="form-group">
                    <label class="form-label" for="comments">Outstanding Items / Comments</label>
                    <textarea name="comments"
                              id="comments"
                              class="form-control"
                              rows="4"
                              maxlength="5000"
                              placeholder="Optional — leave blank if everything is complete">{{ old('comments') }}</textarea>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox"
                           name="signed_with_comments"
                           value="1"
                           {{ old('signed_with_comments') ? 'checked' : '' }}>
                    <span>I am signing with the outstanding items / comments above. The engineering team will follow up.</span>
                </label>

                <div class="submit-row">
                    <button type="submit" id="signoff-submit" class="btn btn-teal" disabled>Sign &amp; Submit</button>
                </div>
            </form>
        </div>

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
                    submit.disabled = false;
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
                submit.disabled = true;
                document.getElementById('signature_image').value = '';
            };
            window.prepareSignoff = function (form) {
                if (! dirty) {
                    alert('Please draw your signature in the box before submitting.');
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
                submit.disabled = true;
                document.getElementById('signature_image').value = '';
            });
        })();

        // ── Photo upload (per-room) ─────────────────────────────────────────
        async function uploadWorksheetPhoto(input, token, roomName) {
            const file = input.files && input.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('photo', file);
            const url = '/worksheet/' + encodeURIComponent(token)
                      + '/rooms/'    + encodeURIComponent(roomName)
                      + '/photos';
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
            if (!confirm('Remove this photo?')) return;
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
    </script>

</body>
</html>
