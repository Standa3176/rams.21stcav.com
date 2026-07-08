@extends('layouts.app')

@section('title', 'O&M Manuals')

@section('content')

{{-- Tier-one polish (2026-07-08) — was custom inline <h1> + hex-colour
     table chrome. Now uses .page-header + .page-title from the shared
     shell so hierarchy matches the rest of the app. Table styling
     migrated to .data-table which already inherits the token palette. --}}

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">O&amp;M Manuals</h1>
        <div class="page-subtitle">Operations &amp; Maintenance manuals generated from approved projects.</div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('om-manuals.create') }}" class="btn btn-teal btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New O&amp;M Manual
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@if ($manuals->isEmpty())
    <x-empty-state
        heading="No O&M manuals yet"
        body="Generate an O&M manual from an approved project to get started.">
        <x-slot name="action">
            <a href="{{ route('om-manuals.create') }}" class="btn btn-teal btn-sm">Create O&amp;M Manual</a>
        </x-slot>
    </x-empty-state>
@else
    <div class="card" style="padding: 0; overflow: hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($manuals as $manual)
                    <tr>
                        <td>
                            <div style="color: var(--ink-900); font-weight: 600; letter-spacing: -0.005em;">
                                {{ $manual->project_name ?? '—' }}
                            </div>
                        </td>
                        <td style="color: var(--text-muted);">{{ $manual->client_name ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $manual->statusBadgeClass() }}">{{ $manual->statusLabel() }}</span>
                        </td>
                        <td style="color: var(--text-muted); font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums;">
                            {{ $manual->created_at->diffForHumans() }}
                        </td>
                        <td style="text-align: right;">
                            {{-- Tier-1 audit fix (retained) — every non-deleted row surfaces
                                 the state-appropriate primary action + a ⋯ menu with Edit,
                                 Retry (failed rows only) and Delete. Consistent with RAMS list. --}}
                            <div style="display: inline-flex; align-items: center; gap: 4px;">
                                @if (in_array($manual->status, [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL]))
                                    <a href="{{ route('om-manuals.download', $manual) }}" class="btn btn-teal btn-sm">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                                        </svg>
                                        Download
                                    </a>
                                @elseif ($manual->status === \App\Models\OmManual::STATUS_EXTRACTED)
                                    <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-teal btn-sm">Review</a>
                                @elseif ($manual->status === \App\Models\OmManual::STATUS_FAILED)
                                    <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}"
                                          data-confirm="Retry O&M generation? The failed record stays until the new build finishes."
                                          data-confirm-label="Retry"
                                          style="margin: 0; display: inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-outline btn-sm" title="Retry generation">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                            </svg>
                                            Retry
                                        </button>
                                    </form>
                                @endif

                                <x-row-actions-menu label="More O&M actions">
                                    <a href="{{ route('om-manuals.edit', $manual) }}" class="row-actions-item">
                                        <span class="row-actions-item__icon" aria-hidden="true">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        </span>
                                        <span>Edit O&amp;M</span>
                                    </a>
                                    @if ($manual->project_id)
                                        <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="row-actions-item">
                                            <span class="row-actions-item__icon" aria-hidden="true">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                            </span>
                                            <span>Asset register</span>
                                        </a>
                                    @endif
                                    <a href="{{ route('documents.revisions.view', ['type' => 'om', 'id' => $manual->id]) }}" class="row-actions-item">
                                        <span class="row-actions-item__icon" aria-hidden="true">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="10"/></svg>
                                        </span>
                                        <span>Revision history</span>
                                    </a>
                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual->id) }}"
                                          data-confirm="Delete this O&M Manual? Admins can restore it later."
                                          data-confirm-label="Delete"
                                          data-confirm-danger="1" style="margin: 0;">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="row-actions-item row-actions-item--danger">
                                            <span class="row-actions-item__icon" aria-hidden="true">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/></svg>
                                            </span>
                                            <span>Delete manual</span>
                                        </button>
                                    </form>
                                </x-row-actions-menu>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 12px 20px; border-top: 1px solid var(--rule);">
            {{ $manuals->links() }}
        </div>
    </div>
@endif

@endsection
