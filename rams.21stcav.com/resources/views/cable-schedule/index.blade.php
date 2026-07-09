@extends('layouts.app')

@section('title', 'Cable Schedules')

@section('content')

{{-- Tier-one polish (2026-07-08). Was hardcoded inline hex (#D4EDDA,
     #155724, #666, #888, #9CA3AF, #999, #fff8f8) and emoji glyphs on
     every action button. Now uses shared .page-header + .page-subtitle +
     .data-table + tier-one badge pill treatment, with SVG icons on
     action buttons. --}}

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">{{ $showDeleted ? 'Cable Schedules — Deleted' : 'Cable Schedules' }}</h1>
        <div class="page-subtitle">
            @if ($showDeleted)
                Soft-deleted cable schedules. Admins can restore or permanently remove.
            @else
                XLSX cable routing sheets generated from a QuoteWerks PDF.
            @endif
        </div>
    </div>
    <div class="page-header-actions">
        @if ($isAdmin)
            @if ($showDeleted)
                <a href="{{ route('cable-schedules.index') }}" class="btn btn-outline btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    Live Records
                </a>
            @else
                <a href="{{ route('cable-schedules.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                    </svg>
                    View Deleted
                </a>
            @endif
        @endif
        @if (! $showDeleted)
            <a href="{{ route('cable-schedules.create') }}" class="btn btn-teal btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                New from Quote PDF
            </a>
        @endif
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="card" style="padding:0;overflow:hidden;">

    @if ($showDeleted)

        @if ($schedules->isEmpty())
            <div class="empty-state"><h3>No deleted cable schedules</h3></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Cables</th>
                        <th>Deleted</th>
                        <th style="text-align:right;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedules as $s)
                    <tr style="opacity:.55;background:color-mix(in oklab, var(--danger-light) 40%, var(--card));">
                        <td>
                            <div style="color:var(--ink-900);font-weight:600;letter-spacing:-0.005em;">{{ $s->project_name }}</div>
                            @if ($s->project_ref)
                                <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono);margin-top:1px;">{{ $s->project_ref }}</div>
                            @endif
                        </td>
                        <td style="color:var(--body);">{{ $s->client_name ?? '—' }}</td>
                        <td style="font-variant-numeric:tabular-nums;color:var(--body);">{{ $s->items_count }}</td>
                        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;font-variant-numeric:tabular-nums;">
                            {{ $s->deleted_at->format('d M Y H:i') }}
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex;gap:6px;">
                                <form method="POST" action="{{ route('cable-schedules.restore', $s->id) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 12a9 9 0 1 0 9-9M3 3v9h9"/>
                                        </svg>
                                        Restore
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('cable-schedules.force-destroy', $s->id) }}" style="margin:0;"
                                      data-confirm="Permanently delete this cable schedule? This CANNOT be undone."
                                      data-confirm-label="Delete Forever"
                                      data-confirm-danger="1">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                                        </svg>
                                        Delete forever
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($schedules->hasPages())
                <div class="pagination-wrap" style="padding:12px 20px;border-top:1px solid var(--rule);">{{ $schedules->links() }}</div>
            @endif
        @endif

    @elseif ($schedules->isEmpty())
        {{-- Re-audit UX-02 — was `heading=` / `body=`, but <x-empty-state>
             expects `title=` / `description=`. Users only saw the default
             "Nothing here yet" without the intended copy. --}}
        <x-empty-state
            title="No cable schedules yet"
            description="Upload a QuoteWerks PDF and let the AI generate a cable schedule for you."
            :href="route('cable-schedules.create')"
            action="+ New from Quote PDF" />
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Cables</th>
                    <th>Source File</th>
                    <th>Created</th>
                    <th style="text-align:right;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schedules as $s)
                <tr>
                    <td>
                        <div style="color:var(--ink-900);font-weight:600;letter-spacing:-0.005em;">{{ $s->project_name }}</div>
                        @if ($s->project_ref)
                            <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono);margin-top:1px;">{{ $s->project_ref }}</div>
                        @endif
                    </td>
                    <td style="color:var(--body);">{{ $s->client_name ?? '—' }}</td>
                    <td>
                        @if ($s->status === 'final')
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--success-light);color:var(--success);border:1px solid color-mix(in oklab, var(--success) 30%, transparent);">
                                <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Final
                            </span>
                        @else
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--surface-soft);color:var(--text-muted);border:1px solid var(--border);">
                                <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Draft
                            </span>
                        @endif
                    </td>
                    <td style="font-variant-numeric:tabular-nums;color:var(--body);">{{ $s->items_count }}</td>
                    <td style="font-size:12px;color:var(--text-muted);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:var(--font-mono);">
                        {{ $s->source_filename ?? '—' }}
                    </td>
                    <td style="white-space:nowrap;">
                        <div style="color:var(--body);font-variant-numeric:tabular-nums;">{{ $s->created_at->format('d M Y') }}</div>
                        <div style="color:var(--text-muted);font-size:11px;font-variant-numeric:tabular-nums;">{{ $s->created_at->format('H:i') }}</div>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:6px;">
                            <a href="{{ route('cable-schedules.edit', $s) }}" class="btn btn-outline btn-sm">Edit</a>
                            <form method="POST" action="{{ route('cable-schedules.destroy', $s->id) }}"
                                  data-confirm="Delete this cable schedule? Admins can restore it later."
                                  data-confirm-label="Delete"
                                  data-confirm-danger="1" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger-outline btn-sm" aria-label="Delete cable schedule">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if ($schedules->hasPages())
            <div class="pagination-wrap" style="padding:12px 20px;border-top:1px solid var(--rule);">{{ $schedules->links() }}</div>
        @endif
    @endif

</div>

@endsection
