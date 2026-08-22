@extends('layouts.app')

@section('title', 'Review Extracted Quote')

@section('content')

<div class="page-header">
    <h1 class="page-title">Review Extracted Quote</h1>
    <a href="{{ route('quote-import.create') }}" class="btn btn-outline btn-sm">← Import another</a>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@php
    $data      = $package->extracted_data ?? [];
    $lineItems = $data['line_items']     ?? [];
    $rooms     = $data['room_summaries'] ?? [];
    $equipment = $package->equipment_list ?? [];
    $cables    = $package->cable_list     ?? [];
    $hazards   = $data['hazards']        ?? [];
    $ppe       = $data['ppe']            ?? [];
    $persons   = $data['persons_at_risk'] ?? [];
@endphp

{{-- Audit D-10 (2026-07-08) — 1fr/320px grid had no @media breakpoint so
     the sidebar collapsed over the content on mobile. Added responsive
     stack + retuned section heading from hardcoded #007B8A / #666 hex
     to tokens. --}}
<style>
    .qi-review-grid { display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .qi-review-h2   { font-size: 15px; font-weight: 700; margin-bottom: 16px; color: var(--ink-900); letter-spacing: -0.015em; }
    .qi-review-h2 small { font-size: 12px; font-weight: 400; color: var(--muted); margin-left: 8px; letter-spacing: 0; }
    @media (max-width: 900px) {
        .qi-review-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="qi-review-grid">

    {{-- ── LEFT: Confirm form ──────────────────────────────────────────────── --}}
    <div>
        <div class="card">
            <h2 class="qi-review-h2">
                Project Details
                <small>(edit before confirming)</small>
            </h2>

            <form method="POST" action="{{ route('quote-import.deliverables-step', $package) }}" id="confirmForm">
                @csrf

                <div class="form-grid-2">
                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="name">Project Name <span class="req">*</span></label>
                        <input id="name" name="name" type="text"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $data['project_name'] ?? $package->project?->name ?? '') }}"
                               required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ref">Quote / Project Ref</label>
                        <input id="ref" name="ref" type="text"
                               class="form-control"
                               value="{{ old('ref', $data['qw_number'] ?? $package->project?->ref ?? '') }}">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="client_name">Client <span class="req">*</span></label>
                        <input id="client_name" name="client_name" type="text"
                               class="form-control @error('client_name') is-invalid @enderror"
                               value="{{ old('client_name', $data['client_name'] ?? $package->project?->client_name ?? '') }}"
                               required>
                        @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="site_address">Site Address <span class="req">*</span></label>
                        <input id="site_address" name="site_address" type="text"
                               class="form-control @error('site_address') is-invalid @enderror"
                               value="{{ old('site_address', $data['site_address'] ?? $package->project?->site_address ?? '') }}"
                               required>
                        @error('site_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="works_description">Works Description</label>
                        <textarea id="works_description" name="works_description"
                                  class="form-control" rows="3">{{ old('works_description', $data['works_description'] ?? $package->project?->works_description ?? '') }}</textarea>
                    </div>

                    {{-- Project assignment --}}
                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="project_id">Link to Project</label>
                        <select name="project_id" id="project_id" class="form-control">
                            @if ($package->project_id === null)
                                <option value="" selected>— Create new project —</option>
                            @else
                                <option value="">— Create new project —</option>
                            @endif
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}"
                                    {{ $package->project_id == $p->id ? 'selected' : '' }}>
                                    {{ $p->name }}
                                    @if($p->ref) ({{ $p->ref }}) @endif
                                    — {{ $p->client_name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-help">Link this package to an existing project, or leave blank to create one now.</p>
                    </div>
                </div>

                <div style="display:flex; gap:.75rem; margin-top:.5rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-teal">Continue</button>
                    <a href="{{ route('quote-import.create') }}" class="btn btn-outline">Discard</a>

                    {{-- Re-extract --}}
                    <form method="POST" action="{{ route('quote-import.reextract', $package) }}"
                          style="margin:0;"
                          data-confirm="Re-run AI extraction? This will create a new revision."
                          data-confirm-label="Re-extract">
                        @csrf
                        <button type="submit" class="btn btn-outline" style="color:#888;">
                            ↺ Re-extract
                        </button>
                    </form>
                </div>
            </form>
        </div>

        {{-- ── Line Items ──────────────────────────────────────────────────── --}}
        @if (count($lineItems) > 0)
        <div class="card" style="margin-top:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:.75rem 1.25rem; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-size:.9rem; font-weight:600; color:#1a1a1a;">
                    Line Items
                    <span style="font-weight:400; color:#666; font-size:.8rem; margin-left:.4rem;">({{ count($lineItems) }})</span>
                </h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table" style="font-size:.82rem;">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th style="text-align:right;">Qty</th>
                            <th style="text-align:right;">Unit</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($lineItems as $item)
                        <tr>
                            <td style="color:#888; white-space:nowrap;">{{ $item['part_number'] ?? $item['part_no'] ?? $item['sku'] ?? '' ?: '—' }}</td>
                            <td>{{ $item['description'] ?? '' }}</td>
                            <td style="text-align:right;">{{ $item['qty'] ?? 1 }}</td>
                            <td style="text-align:right;">
                                @if(($item['unit_price'] ?? 0) > 0)
                                    £{{ number_format($item['unit_price'], 2) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:right;">
                                @if(($item['total_price'] ?? 0) > 0)
                                    £{{ number_format($item['total_price'], 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- ── Equipment List ───────────────────────────────────────────────── --}}
        @if (count($equipment) > 0)
        <div class="card" style="margin-top:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:.75rem 1.25rem; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-size:.9rem; font-weight:600;">
                    Equipment List
                    <span style="font-weight:400; color:#666; font-size:.8rem; margin-left:.4rem;">({{ count($equipment) }} items)</span>
                </h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table" style="font-size:.82rem;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Manufacturer</th>
                            <th>Model</th>
                            <th style="text-align:right;">Qty</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($equipment as $item)
                        <tr>
                            <td>
                                <span style="
                                    display:inline-block;
                                    padding:.15rem .45rem;
                                    border-radius:4px;
                                    font-size:.75rem;
                                    background:#e8f8f9;
                                    color:#007B8A;
                                    white-space:nowrap;
                                ">{{ $item['category'] ?? 'Other' }}</span>
                            </td>
                            <td>{{ $item['manufacturer'] ?? '' }}</td>
                            <td style="font-weight:500;">{{ $item['model'] ?? '' }}</td>
                            <td style="text-align:right;">{{ $item['qty'] ?? 1 }}</td>
                            <td style="color:#666;">{{ $item['location'] ?? '' ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    {{-- ── RIGHT: Summary sidebar ───────────────────────────────────────────── --}}
    <div>
        {{-- Meta --}}
        <div class="card card-sm" style="font-size:.85rem;">
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td style="color:#888; padding:.3rem 0; width:40%;">Filename</td>
                    <td style="font-weight:500; padding:.3rem 0; word-break:break-all;">{{ $package->quote_filename }}</td>
                </tr>
                <tr>
                    <td style="color:#888; padding:.3rem 0;">Revision</td>
                    <td style="padding:.3rem 0;">{{ $package->revision }}</td>
                </tr>
                <tr>
                    <td style="color:#888; padding:.3rem 0;">Status</td>
                    <td style="padding:.3rem 0;">
                        <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--warning-light);color:#92400E;border:1px solid color-mix(in oklab, var(--warning) 30%, transparent);">
                            <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Extracted
                        </span>
                    </td>
                </tr>
                @if(!empty($data['project_type']))
                <tr>
                    <td style="color:#888; padding:.3rem 0;">Type</td>
                    <td style="padding:.3rem 0;">{{ $data['project_type'] }}</td>
                </tr>
                @endif
                <tr>
                    <td style="color:#888; padding:.3rem 0;">Imported</td>
                    <td style="padding:.3rem 0;">{{ $package->created_at->diffForHumans() }}</td>
                </tr>
            </table>
        </div>

        {{-- Rooms --}}
        @if (count($rooms) > 0)
        <div class="card card-sm" style="margin-top:1rem;">
            <h3 style="font-size:.85rem; font-weight:600; color:#007B8A; margin-bottom:.75rem;">Rooms / Areas</h3>
            @foreach ($rooms as $room)
            <div style="margin-bottom:.75rem; padding-bottom:.75rem; border-bottom:1px solid #f0f0f0;">
                <div style="font-weight:600; font-size:.82rem;">{{ $room['room'] ?? '' }}</div>
                <div style="font-size:.78rem; color:#555; margin-top:.2rem; line-height:1.4;">{{ $room['summary'] ?? '' }}</div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Cable Hints --}}
        @if (count($cables) > 0)
        <div class="card card-sm" style="margin-top:1rem;">
            <h3 style="font-size:.85rem; font-weight:600; color:#007B8A; margin-bottom:.75rem;">
                Cable Hints
                <span style="font-weight:400; color:#888; font-size:.75rem;">({{ count($cables) }})</span>
            </h3>
            @foreach ($cables as $cable)
            <div style="font-size:.78rem; margin-bottom:.5rem; padding-bottom:.5rem; border-bottom:1px solid #f0f0f0;">
                <span style="font-weight:500;">{{ $cable['from_location'] ?? '?' }}</span>
                <span style="color:#888; margin:0 .35rem;">→</span>
                <span style="font-weight:500;">{{ $cable['to_location'] ?? '?' }}</span>
                <span style="
                    display:inline-block;
                    margin-left:.35rem;
                    padding:.1rem .35rem;
                    background:#f0f0f0;
                    border-radius:4px;
                    font-size:.72rem;
                    color:#444;
                ">{{ $cable['cable_type'] ?? '' }}</span>
                @if(!empty($cable['notes']))
                    <div style="color:#888; margin-top:.2rem;">{{ $cable['notes'] }}</div>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        {{-- H&S Seeds --}}
        @if (count($hazards) > 0 || count($ppe) > 0)
        <div class="card card-sm" style="margin-top:1rem;">
            <h3 style="font-size:.85rem; font-weight:600; color:#007B8A; margin-bottom:.75rem;">H&S Seeds</h3>

            @if (count($hazards) > 0)
            <div style="margin-bottom:.75rem;">
                <div style="font-size:.78rem; font-weight:600; color:#555; margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.04em;">
                    Hazards ({{ count($hazards) }})
                </div>
                <ul style="font-size:.78rem; color:#333; padding-left:1.2rem; line-height:1.6;">
                    @foreach ($hazards as $hazard)
                        <li>{{ $hazard }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if (count($ppe) > 0)
            <div style="margin-bottom:.75rem;">
                <div style="font-size:.78rem; font-weight:600; color:#555; margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.04em;">
                    PPE
                </div>
                <ul style="font-size:.78rem; color:#333; padding-left:1.2rem; line-height:1.6;">
                    @foreach ($ppe as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if (count($persons) > 0)
            <div>
                <div style="font-size:.78rem; font-weight:600; color:#555; margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.04em;">
                    Persons at Risk
                </div>
                <div style="font-size:.78rem; color:#333; line-height:1.6;">
                    {{ implode(', ', $persons) }}
                </div>
            </div>
            @endif
        </div>
        @endif
    </div>

</div>

@endsection
