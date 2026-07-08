@extends('layouts.app')
@section('title', 'Solution Types')

@section('content')

{{-- Tier-one polish (2026-07-08). Was custom inline hex (#0B3C45,
     #D1FAE5, #F3F4F6, #6B7280) + inline style="…" on every table cell.
     Now uses the shared .data-table + tokens. --}}

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Solution Types</h1>
        <div class="page-subtitle">
            Define room/space solution types. Assigned per space in Project Data. Drives site survey checklists and method statement generation.
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.solution-types.create') }}" class="btn btn-teal btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Solution Type
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card" style="padding:0;overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:60px;">Order</th>
                <th>Name</th>
                <th>Description</th>
                <th>Checklist</th>
                <th>Status</th>
                <th style="text-align:right;"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($types as $type)
            <tr>
                <td style="color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums;">{{ $type->sort_order }}</td>
                <td>
                    <div style="color:var(--ink-900);font-weight:600;letter-spacing:-0.005em;">{{ $type->name }}</div>
                    <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono);margin-top:1px;">{{ $type->slug }}</div>
                </td>
                <td style="color:var(--body);max-width:280px;">
                    {{ Str::limit($type->description, 80) }}
                </td>
                <td style="color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums;">
                    {{ count($type->checklistLines()) }} items
                </td>
                <td>
                    @if($type->is_active)
                        <span class="badge badge-success" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--success-light);color:var(--success);border:1px solid color-mix(in oklab, var(--success) 30%, transparent);">
                            <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Active
                        </span>
                    @else
                        <span class="badge badge-muted" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--surface-soft);color:var(--text-muted);border:1px solid var(--border);">
                            <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Inactive
                        </span>
                    @endif
                </td>
                <td style="text-align:right;">
                    <div style="display:flex;gap:6px;justify-content:flex-end;">
                        <a href="{{ route('admin.solution-types.edit', $type) }}" class="btn btn-outline btn-sm">Edit</a>
                        <form method="POST" action="{{ route('admin.solution-types.destroy', $type) }}"
                              data-confirm="Delete {{ $type->name }}?"
                              data-confirm-label="Delete"
                              data-confirm-danger="1" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">
                    No solution types yet.
                    <a href="{{ route('admin.solution-types.create') }}" style="color:var(--teal-700);font-weight:600;">Create one →</a>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
