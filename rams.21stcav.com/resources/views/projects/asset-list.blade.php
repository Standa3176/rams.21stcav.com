@extends('layouts.app')

@section('title', 'Asset List — ' . $project->name)

{{-- Phase 46.4 Plan 03 Task 1 — D-05's asset register.

     NO NEW STYLESHEET, NO app.css EDIT, NO cockpit.css EDIT. Every class here
     (.page-header, .page-title, .card, .card-sm, .data-table, .btn-outline,
     .btn-sm, .badge) is already defined in layouts/app.blade.php, and the rest
     is inline — the same idiom worksheets/show.blade.php uses. That is what
     keeps this page shippable without an `npm run build`.

     The register NEVER renders device_label_photos.captured_by. It is not even
     selected — see ProjectAssetListController::assetRows(). --}}

@section('content')

<div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.75rem;">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    <span style="margin:0 .35rem;">›</span>
    <a href="{{ route('projects.show', $project) }}" style="color:var(--teal);text-decoration:none;">{{ $project->name }}</a>
    <span style="margin:0 .35rem;">›</span>
    <span>Asset List</span>
</div>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Asset List — {{ $project->name }}</h1>
        <div class="page-subtitle">
            Every unit captured on site: serial, MAC or asset tag read off the equipment label.
            @if($project->ref) · Ref: {{ $project->ref }} @endif
        </div>
    </div>
    <div class="page-header-actions">
        @if($rows->isNotEmpty())
            <a href="{{ route('projects.asset-list.export', $project) }}" class="btn-outline btn-sm">↓ Export CSV</a>
        @endif
        <a href="{{ route('projects.show', $project) }}" class="btn-outline btn-sm">← Back to Project</a>
    </div>
</div>

@if($rows->isEmpty())
    {{-- A NAMED empty state, never a table with no rows — the office must be
         able to tell "nobody has been yet" from "this screen is broken". --}}
    <div class="card card-sm" style="color:var(--text-muted);font-size:.875rem;text-align:center;padding:2rem;">
        No assets captured on site yet. Serials, MAC addresses and asset tags appear here once an
        engineer photographs an equipment label on the worksheet link and the reading is confirmed.
    </div>
@else
    <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.6rem;">
        {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('asset', $rows->count()) }} captured
        across {{ $byRoom->count() }} {{ \Illuminate\Support\Str::plural('room', $byRoom->count()) }}.
    </div>

    @foreach($byRoom as $roomName => $devices)
        <div class="card card-sm" style="margin-bottom:1rem;padding:0;overflow:hidden;">
            <div style="padding:.7rem 1rem;background:var(--surface-soft);border-bottom:1px solid var(--border);
                        font-size:.8rem;font-weight:700;color:var(--text);">
                {{ $roomName }}
                <span style="font-weight:500;color:var(--text-muted);margin-left:.4rem;">
                    ({{ $devices->count() }})
                </span>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table" style="font-size:.82rem;margin:0;">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Make / Model</th>
                            <th>Part No</th>
                            <th>Qty</th>
                            <th>Serial</th>
                            <th>MAC</th>
                            <th>Asset Tag</th>
                            <th>Label</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($devices as $device)
                            <tr>
                                <td style="font-weight:600;color:var(--text);">{{ $device->description }}</td>
                                <td>
                                    @if($device->manufacturer || $device->model)
                                        {{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) }}
                                    @else
                                        <span style="color:var(--text-faint);">—</span>
                                    @endif
                                </td>
                                <td>{{ $device->part_no ?: '—' }}</td>
                                <td>{{ $device->qty }}</td>
                                <td style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $device->serial_number ?: '—' }}</td>
                                <td style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $device->mac_address ?: '—' }}</td>
                                <td>{{ $device->asset_tag ?: '—' }}</td>
                                <td>
                                    @if($device->confirmed_label_photo_count > 0)
                                        <span style="display:inline-block;padding:1px 6px;border-radius:9999px;background:#DCFCE7;color:#166534;font-weight:600;font-size:.7rem;">
                                            ✓ Photographed
                                        </span>
                                    @else
                                        <span style="color:var(--text-faint);font-size:.75rem;">Keyed in</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endif

@endsection
