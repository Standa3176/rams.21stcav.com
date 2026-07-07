@extends('layouts.app')

@section('title', 'Asset Register — ' . ($manual->project_name ?? 'O&M Manual'))

@section('content')
<x-edit-action-bar :form-id="'om-manual-devices-form'" :cancel-url="route('om-manuals.edit', $manual)">
    <x-slot:title>Edit Devices — {{ $manual->project_name ?? $manual->title ?? 'Untitled' }}</x-slot:title>
</x-edit-action-bar>

<div class="container" style="max-width:1400px; margin:0 auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem;">
        <h1 style="font-size:1.4rem; font-weight:700; margin:0;">
            Asset Register
            @if ($manual->project_name)
                <span style="font-weight:400; color:#888;">— {{ $manual->project_name }}</span>
            @endif
        </h1>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-outline btn-sm">← Back to O&amp;M</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success" style="margin-bottom:1rem;">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" style="margin-bottom:1rem;">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" style="margin-bottom:1rem;">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card" style="padding:1.25rem; margin-bottom:1.25rem;">
        <p style="margin:0;font-size:.9rem;color:#555;">
            Enter the per-device data captured at installation: serial number, IP / VLAN / switch port, firmware level, and asset tag.
            Fields can be left blank where unknown — only populated values appear in the rendered O&amp;M (Section&nbsp;3 Asset Register and Section&nbsp;9 Network &amp; IP).
            Use <strong>Save &amp; Regenerate</strong> to push the updated values straight into a new PDF.
        </p>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         Bulk CSV workflow — download a pre-populated template, complete it in
         Excel / Sheets / Numbers, upload it back. Rows are matched by
         `device_id` (already in the template) so users can't accidentally
         create duplicates or overwrite the wrong device.
         ══════════════════════════════════════════════════════════════════════ --}}
    @if ($manual->project_id && $devices->isNotEmpty())
        <div class="card" style="padding:1.1rem 1.25rem; margin-bottom:1.25rem; background: linear-gradient(180deg, #FBF8EF 0%, #FFFFFF 100%); border: 1px solid #E4DDCB;">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.7rem;">
                <h2 style="font-size:1rem;font-weight:700;margin:0;color:#0F3E36;">📄 Bulk import from CSV</h2>
                <span style="font-size:.75rem;color:var(--text-muted);">
                    {{ $devices->count() }} device {{ Str::plural('row', $devices->count()) }} available
                </span>
            </div>
            <p style="margin:0 0 .9rem;font-size:.85rem;color:#4A4F4C;line-height:1.5;">
                Download the pre-populated CSV, fill in serial numbers / IPs / VLANs / etc. in your spreadsheet,
                then upload it back. Rows are matched by internal <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;">device_id</span>
                — leave that column alone and any unknown IDs are silently skipped.
            </p>

            <div style="display:flex;gap:1.5rem;align-items:flex-end;flex-wrap:wrap;">
                {{-- Template download --}}
                <div>
                    <div style="font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:.35rem;">
                        Step 1 — download template
                    </div>
                    <a href="{{ route('om-manuals.devices.csv-template', $manual) }}" class="btn btn-outline btn-sm">
                        ↓ Download CSV template
                    </a>
                    <div style="font-size:.7rem;color:var(--text-muted);margin-top:.3rem;">
                        Includes every device on this project · pre-filled reference columns.
                    </div>
                </div>

                {{-- Upload form --}}
                <form method="POST" action="{{ route('om-manuals.devices.import-csv', $manual) }}"
                      enctype="multipart/form-data"
                      style="display:flex;flex-direction:column;gap:.3rem;">
                    @csrf
                    <div style="font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:.05rem;">
                        Step 2 — upload completed CSV
                    </div>
                    <div style="display:flex;gap:.5rem;align-items:center;">
                        <input type="file" name="asset_csv" accept=".csv,.txt" required
                               style="font-size:.82rem;">
                        <button type="submit" class="btn btn-teal btn-sm">↑ Import</button>
                    </div>
                    <div style="font-size:.7rem;color:var(--text-muted);">
                        Excel &gt; File &gt; Save as &gt; CSV UTF-8 also works. Max 2 MB.
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($devices->isEmpty())
        <div class="card" style="padding:1.5rem; text-align:center; color:#888;">
            No device rows yet for this project. Generate the O&amp;M Manual once
            (project page → Generate) — that auto-seeds one device row per
            equipment line. Then come back here to populate the data.
        </div>
    @else
        <form method="POST" action="{{ route('om-manuals.update-devices', $manual) }}" id="om-manual-devices-form">
            @csrf
            @method('PUT')

            @foreach ($devices->groupBy('room_name') as $roomName => $roomDevices)
                <div class="card" style="padding:1.25rem; margin-bottom:1.25rem;">
                    <h2 style="font-size:1rem; font-weight:700; margin:0 0 .75rem; color:#01889F;">
                        {{ $roomName ?: 'Unassigned room' }}
                        <span style="font-weight:400; color:#888; font-size:.85rem; margin-left:.5rem;">
                            {{ $roomDevices->count() }} {{ \Illuminate\Support\Str::plural('device', $roomDevices->count()) }}
                        </span>
                    </h2>

                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:.82rem;">
                            <thead>
                                <tr style="background:#F4FBFB; color:#01889F; text-align:left;">
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; min-width:240px;">Device</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:140px;">Serial Number</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:130px;">IP Address</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:70px;">VLAN</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:90px;">Port</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:120px;">Firmware</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:130px;">Asset Tag</th>
                                    <th style="padding:6px 8px; border-bottom:1px solid #DDD; width:160px;">MAC Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roomDevices as $d)
                                    @php
                                        $labelPhotos = class_exists(\App\Models\DeviceLabelPhoto::class)
                                            ? \App\Models\DeviceLabelPhoto::where('device_id', $d->id)
                                                ->orderByDesc('captured_at')
                                                ->get()
                                            : collect();
                                        $labelCount = $labelPhotos->count();
                                        $confirmedCount = $labelPhotos->where('confirmed', true)->count();
                                        $maxThumbs = 3;
                                    @endphp
                                    <tr style="border-bottom:1px solid #EEE;">
                                        <td style="padding:8px; vertical-align:top;">
                                            <div style="font-weight:600;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                                                <span>{{ $d->description }}</span>
                                                @if ($confirmedCount > 0)
                                                    <span title="{{ $confirmedCount }} confirmed label photo{{ $confirmedCount > 1 ? 's' : '' }} from worksheet"
                                                          style="display:inline-flex;align-items:center;gap:.25rem;padding:1px 6px;border-radius:9999px;background:#DCFCE7;color:#166534;font-weight:600;font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;">
                                                        ✓ Worksheet
                                                    </span>
                                                @elseif ($labelCount > 0)
                                                    <span title="{{ $labelCount }} unconfirmed label photo{{ $labelCount > 1 ? 's' : '' }}"
                                                          style="display:inline-flex;align-items:center;gap:.25rem;padding:1px 6px;border-radius:9999px;background:#FEF3C7;color:#92400E;font-weight:600;font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;">
                                                        📷 {{ $labelCount }} pending
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($labelCount > 0)
                                                <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.4rem;">
                                                    @foreach ($labelPhotos->take($maxThumbs) as $lp)
                                                        @php $ai = $lp->ai_extracted ?? []; @endphp
                                                        <a href="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}"
                                                           target="_blank" rel="noopener"
                                                           title="Captured {{ optional($lp->captured_at)->format('d M Y H:i') ?? '—' }}{{ $lp->confirmed ? ' • confirmed' : ' • pending review' }}{{ ! empty($ai['serial_number']) ? ' • S/N '.$ai['serial_number'] : '' }}"
                                                           style="display:inline-block;width:36px;height:36px;border-radius:4px;overflow:hidden;border:1px solid {{ $lp->confirmed ? '#86EFAC' : '#FCD34D' }};box-shadow:0 1px 2px rgba(0,0,0,.05);">
                                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}"
                                                                 alt="" loading="lazy"
                                                                 style="width:100%;height:100%;object-fit:cover;display:block;">
                                                        </a>
                                                    @endforeach
                                                    @if ($labelCount > $maxThumbs)
                                                        <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:4px;border:1px dashed #CBD5E1;font-size:.7rem;color:#64748B;font-weight:600;"
                                                              title="{{ $labelCount - $maxThumbs }} more photo{{ ($labelCount - $maxThumbs) > 1 ? 's' : '' }}">
                                                            +{{ $labelCount - $maxThumbs }}
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif
                                            <div style="font-size:.75rem; color:#888;">
                                                {{ $d->model ?? '—' }}
                                                @if ($d->qty && $d->qty > 1)
                                                    &middot; qty {{ $d->qty }}
                                                @endif
                                            </div>
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][serial_number]"
                                                value="{{ old('devices.' . $d->id . '.serial_number', $d->serial_number) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][ip_address]"
                                                value="{{ old('devices.' . $d->id . '.ip_address', $d->ip_address) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][vlan]"
                                                value="{{ old('devices.' . $d->id . '.vlan', $d->vlan) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][port]"
                                                value="{{ old('devices.' . $d->id . '.port', $d->port) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][firmware_version]"
                                                value="{{ old('devices.' . $d->id . '.firmware_version', $d->firmware_version) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][asset_tag]"
                                                value="{{ old('devices.' . $d->id . '.asset_tag', $d->asset_tag) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                        <td style="padding:6px 4px;">
                                            <input type="text" data-optional
                                                name="devices[{{ $d->id }}][mac_address]"
                                                value="{{ old('devices.' . $d->id . '.mac_address', $d->mac_address) }}"
                                                class="form-input" style="width:100%; font-size:.82rem; padding:4px 6px;"
                                                placeholder="—">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            <div class="card" style="padding:1.25rem; display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="btn btn-teal">Save</button>
                <button type="submit" name="regenerate" value="1" class="btn btn-teal">Save &amp; Regenerate O&amp;M</button>
                <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-outline">Cancel</a>
                <span style="font-size:.85rem; color:#888; margin-left:auto;">
                    {{ $devices->count() }} device {{ \Illuminate\Support\Str::plural('row', $devices->count()) }} on this project
                </span>
            </div>
        </form>
    @endif
</div>
@endsection
