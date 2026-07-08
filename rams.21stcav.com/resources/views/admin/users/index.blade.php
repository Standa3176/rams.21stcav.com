@extends('layouts.app')

@section('title', 'User Management')

@push('styles')
<style>
/*
 * Users admin — tier-one polish (2026-07-08, PLAN 260708-b7i follow-up).
 * Removed the local .page-header / .card / .btn / .alert overrides;
 * the layout already ships tier-one versions of every one via the
 * shared token system. The screen now inherits the standard treatment
 * used by every other index (RAMS, O&M, Site Surveys, Worksheets).
 *
 * Screen-scoped keeps: user initials avatar (indigo gradient) + role /
 * status badges + a tighter data table with hairline dividers.
 */

.au-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.au-table th {
    background: var(--surface-soft);
    text-align: left;
    padding: 10px 16px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.au-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--rule);
    vertical-align: middle;
    color: var(--body);
}
.au-table tbody tr:last-child td { border-bottom: none; }
.au-table tbody tr:hover td { background: color-mix(in oklab, var(--teal-100) 22%, transparent); }
.au-table tr.suspended td { opacity: .55; background: var(--surface-soft); }

/* Role + status pills — soft rounded pill with hairline + coloured dot */
.au-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
    border: 1px solid transparent;
    letter-spacing: -0.005em;
}
.au-badge::before {
    content: "";
    width: 5px; height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.au-badge-admin     { background: var(--teal-100);      color: var(--teal-700);
                      border-color: color-mix(in oklab, var(--teal-700) 25%, transparent); }
.au-badge-user      { background: var(--surface-soft);  color: var(--text-muted);
                      border-color: var(--border); }
.au-badge-active    { background: var(--success-light); color: var(--success);
                      border-color: color-mix(in oklab, var(--success) 30%, transparent); }
.au-badge-suspended { background: var(--danger-light);  color: #991B1B;
                      border-color: color-mix(in oklab, var(--danger) 30%, transparent); }

.au-actions { display: flex; gap: 6px; flex-wrap: wrap; }

.au-user-cell { display: flex; align-items: center; gap: 10px; }
.au-user-initials {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--teal-500), var(--teal-700));
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.12), inset 0 1px 0 rgba(255,255,255,0.14);
}
.au-user-meta  { line-height: 1.35; min-width: 0; }
.au-user-name  { color: var(--ink-900); font-weight: 600; letter-spacing: -0.005em; }
.au-user-email { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

.au-joined { color: var(--text-muted); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">User Management</h1>
        <div class="page-subtitle">Add, suspend or promote workspace members.</div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.users.create') }}" class="btn btn-teal btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add User
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="card" style="padding: 0; overflow: hidden;">
    <table class="au-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $u)
                @php $isSelf = $u->id === auth()->id(); @endphp
                <tr class="{{ ! $u->is_active ? 'suspended' : '' }}">

                    {{-- Name + email --}}
                    <td>
                        <div class="au-user-cell">
                            <span class="au-user-initials">
                                {{ strtoupper(substr($u->name, 0, 1)) }}{{ strtoupper(substr(strstr($u->name, ' '), 1, 1)) }}
                            </span>
                            <div class="au-user-meta">
                                <div class="au-user-name">{{ $u->name }} @if($isSelf) <span style="font-size:11px;color:var(--text-muted);font-weight:400;">(you)</span> @endif</div>
                                <div class="au-user-email">{{ $u->email }}</div>
                            </div>
                        </div>
                    </td>

                    {{-- Role --}}
                    <td>
                        <span class="au-badge {{ $u->role === 'admin' ? 'au-badge-admin' : 'au-badge-user' }}">
                            {{ ucfirst($u->role) }}
                        </span>
                    </td>

                    {{-- Status --}}
                    <td>
                        <span class="au-badge {{ $u->is_active ? 'au-badge-active' : 'au-badge-suspended' }}">
                            {{ $u->is_active ? 'Active' : 'Suspended' }}
                        </span>
                    </td>

                    {{-- Joined --}}
                    <td><span class="au-joined">{{ $u->created_at->format('d M Y') }}</span></td>

                    {{-- Actions --}}
                    <td>
                        <div class="au-actions">
                            {{-- Edit --}}
                            <a href="{{ route('admin.users.edit', $u) }}" class="btn btn-outline btn-sm">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                                </svg>
                                Edit
                            </a>

                            {{-- Suspend / Reactivate (cannot self-suspend) --}}
                            @if (! $isSelf)
                                <form method="POST"
                                      action="{{ route('admin.users.toggle-active', $u) }}"
                                      data-confirm="{{ $u->is_active ? 'Suspend' : 'Reactivate' }} {{ $u->name }}?"
                                      data-confirm-label="{{ $u->is_active ? 'Suspend' : 'Reactivate' }}"
                                      @if($u->is_active) data-confirm-danger="1" @endif
                                      style="margin:0;">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm {{ $u->is_active ? 'btn-danger-outline' : 'btn-outline' }}">
                                        @if ($u->is_active)
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
                                            </svg>
                                            Suspend
                                        @else
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <polygon points="5 3 19 12 5 21 5 3"/>
                                            </svg>
                                            Reactivate
                                        @endif
                                    </button>
                                </form>

                                {{-- Delete --}}
                                <form method="POST"
                                      action="{{ route('admin.users.destroy', $u) }}"
                                      data-confirm="Permanently delete {{ $u->name }}? This cannot be undone."
                                      data-confirm-label="Delete"
                                      data-confirm-danger="1"
                                      style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                                        </svg>
                                        Delete
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">
                        No users found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($users->hasPages())
    <div style="margin-top:16px;">
        {{ $users->links() }}
    </div>
@endif
@endsection
