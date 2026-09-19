{{--
    Phase 44 Plan 02 — admin-only labour resources index.

    This view is intentionally EXCLUDED from Plan 44-04's client-facing
    privacy-boundary scan. It sits behind the real `admin` route-group
    middleware (EnsureUserIsAdmin), not a client-facing or tokenised route,
    so the Contact column below deliberately shows email/phone — D-04
    ("A client must never be given an engineer's phone or email. Name
    only.") explicitly permits contact details "visible to the PM and
    admin only." Do not mistake these columns for a privacy bug.
--}}
@extends('layouts.app')

@section('title', 'Labour Resources')

@push('styles')
<style>
/* Follows the same visual language as admin/users/index.blade.php's .au-table. */
.lr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.lr-table th {
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
.lr-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--rule);
    vertical-align: middle;
    color: var(--body);
}
.lr-table tbody tr:last-child td { border-bottom: none; }
.lr-table tbody tr:hover td { background: color-mix(in oklab, var(--teal-100) 22%, transparent); }
.lr-table tr.inactive td { opacity: .55; background: var(--surface-soft); }

.lr-badge {
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
.lr-badge::before {
    content: "";
    width: 5px; height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.lr-badge-role     { background: var(--surface-soft); color: var(--text-muted); border-color: var(--border); }
.lr-badge-active   { background: var(--success-light); color: var(--success);
                     border-color: color-mix(in oklab, var(--success) 30%, transparent); }
.lr-badge-inactive { background: var(--danger-light);  color: #991B1B;
                     border-color: color-mix(in oklab, var(--danger) 30%, transparent); }

.lr-roles { display: flex; gap: 4px; flex-wrap: wrap; }
.lr-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.lr-contact { font-size: 12px; color: var(--text-muted); line-height: 1.4; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Labour Resources</h1>
        <div class="page-subtitle">Add, edit or deactivate engineers, programmers and other labour resources.</div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.labour-resources.create') }}" class="btn btn-teal btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Resource
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
    <table class="lr-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Roles</th>
                <th>Contact</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($resources as $r)
                <tr class="{{ ! $r->is_active ? 'inactive' : '' }}">
                    <td>{{ $r->name }}</td>

                    <td>
                        <div class="lr-roles">
                            @foreach ($r->roles ?? [] as $role)
                                <span class="lr-badge lr-badge-role">{{ ucfirst($role) }}</span>
                            @endforeach
                        </div>
                    </td>

                    <td>
                        <div class="lr-contact">
                            <div>{{ $r->email ?: '—' }}</div>
                            <div>{{ $r->phone ?: '—' }}</div>
                        </div>
                    </td>

                    <td>
                        <span class="lr-badge {{ $r->is_active ? 'lr-badge-active' : 'lr-badge-inactive' }}">
                            {{ $r->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>

                    <td>
                        <div class="lr-actions">
                            <a href="{{ route('admin.labour-resources.edit', $r) }}" class="btn btn-outline btn-sm">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                                </svg>
                                Edit
                            </a>

                            <form method="POST"
                                  action="{{ route('admin.labour-resources.toggle-active', $r) }}"
                                  data-confirm="{{ $r->is_active ? 'Deactivate' : 'Reactivate' }} {{ $r->name }}?"
                                  data-confirm-label="{{ $r->is_active ? 'Deactivate' : 'Reactivate' }}"
                                  @if($r->is_active) data-confirm-danger="1" @endif
                                  style="margin:0;">
                                @csrf
                                <button type="submit"
                                        class="btn btn-sm {{ $r->is_active ? 'btn-danger-outline' : 'btn-outline' }}">
                                    @if ($r->is_active)
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
                                        </svg>
                                        Deactivate
                                    @else
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <polygon points="5 3 19 12 5 21 5 3"/>
                                        </svg>
                                        Reactivate
                                    @endif
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">
                        No labour resources found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($resources->hasPages())
    <div style="margin-top:16px;">
        {{ $resources->links() }}
    </div>
@endif
@endsection
