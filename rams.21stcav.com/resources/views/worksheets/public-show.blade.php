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
    </style>
</head>
<body>

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
            @foreach($rooms as $room)
                @php
                    $isSurveyed   = $room['is_surveyed'] ?? false;
                    $equipment    = $room['equipment'] ?? [];
                    $installSteps = trim((string) ($room['install_steps'] ?? ''));
                    $cableRoute   = trim((string) ($room['cable_route_desc'] ?? ''));
                @endphp

                <div class="card">
                    <div class="room-name">{{ $room['name'] ?? 'Unknown Room' }}</div>

                    {{-- Equipment --}}
                    <div class="section-hdr">Equipment</div>
                    @if(empty($equipment))
                        <p class="muted" style="font-size:.88rem;">No equipment listed for this room.</p>
                    @else
                        <table class="field-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th style="width:18%;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($equipment as $item)
                                    <tr>
                                        <td>{{ $item['name'] ?? $item['description'] ?? '—' }}</td>
                                        <td>{{ $item['quantity'] ?? 1 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    {{-- Install Steps --}}
                    <div class="section-hdr">Install Steps</div>
                    @if($installSteps !== '')
                        <div class="pre" style="font-size:.9rem;color:#374151;">{{ $installSteps }}</div>
                    @else
                        <p class="muted" style="font-size:.88rem;">Steps will be confirmed on-site.</p>
                    @endif

                    {{-- Cable Routes --}}
                    <div class="section-hdr">Cable Routes</div>
                    @if($cableRoute !== '')
                        <p style="font-size:.9rem;color:#374151;">{{ $cableRoute }}</p>
                    @else
                        <p class="muted" style="font-size:.88rem;">Not surveyed</p>
                    @endif

                    {{-- Power & Network --}}
                    <div class="section-hdr">Power &amp; Network</div>
                    <table class="field-table">
                        <tbody>
                            <tr>
                                <td class="label">Power outlets</td>
                                <td>
                                    @if(isset($room['power_outlet_count']) && $room['power_outlet_count'] !== null)
                                        {{ $room['power_outlet_count'] }}
                                    @else
                                        <span class="muted">Not surveyed</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Additional power required</td>
                                <td>
                                    @if(isset($room['requires_additional_power']) && $room['requires_additional_power'] !== null)
                                        {{ $room['requires_additional_power'] ? 'Yes' : 'No' }}
                                    @else
                                        <span class="muted">Not surveyed</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Network ports</td>
                                <td>
                                    @if(isset($room['network_port_count']) && $room['network_port_count'] !== null)
                                        {{ $room['network_port_count'] }}
                                    @else
                                        <span class="muted">Not surveyed</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Existing cabling</td>
                                <td>
                                    @if(isset($room['existing_cabling']) && $room['existing_cabling'] !== null && $room['existing_cabling'] !== '')
                                        {{ $room['existing_cabling'] }}
                                    @else
                                        <span class="muted">Not surveyed</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
    </script>

</body>
</html>
