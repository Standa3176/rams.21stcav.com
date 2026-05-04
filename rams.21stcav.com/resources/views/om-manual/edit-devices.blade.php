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
