@extends('layouts.app')

@section('title', 'User Management')

@push('styles')
<style>
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}
.page-header h1 {
    font-size: 1.375rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.02em;
}
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
table { width: 100%; border-collapse: collapse; font-size: .875rem; }
th {
    background: #f9fafb;
    text-align: left;
    padding: .6rem 1rem;
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
td { padding: .7rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr.suspended td { opacity: .55; background: #fafafa; }

.badge {
    display: inline-block;
    font-size: .72rem;
    font-weight: 600;
    padding: .18rem .5rem;
    border-radius: 4px;
    white-space: nowrap;
}
.badge-admin    { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
.badge-user     { background: #f0f0f0; color: #555; }
.badge-active   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.badge-suspended{ background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

.actions { display: flex; gap: .4rem; flex-wrap: wrap; }

.btn { display: inline-flex; align-items: center; gap: .3rem; font-size: .8125rem; font-weight: 500; padding: .3rem .75rem; border-radius: 6px; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: background 120ms, color 120ms, border-color 120ms; }
.btn-primary   { background: var(--teal); color: #fff; border-color: var(--teal); }
.btn-primary:hover { background: var(--teal-hover); color: #fff; text-decoration: none; }
.btn-outline   { background: transparent; color: var(--text-muted); border-color: var(--border); }
.btn-outline:hover { background: var(--teal-light); color: var(--teal); border-color: var(--teal-mid); text-decoration: none; }
.btn-warning   { background: #fffbeb; color: #92400e; border-color: #f59e0b; }
.btn-warning:hover { background: #fef3c7; text-decoration: none; }
.btn-danger    { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }
.btn-danger:hover  { background: #fee2e2; text-decoration: none; }
.btn-sm        { font-size: .75rem; padding: .2rem .55rem; }

.alert { padding: .75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; font-size: .875rem; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

.user-initials {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--teal);
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    flex-shrink: 0;
    margin-right: .5rem;
}
.user-cell { display: flex; align-items: center; }
.user-meta  { line-height: 1.3; }
.user-email { font-size: .78rem; color: var(--text-muted); }
</style>
@endpush

@section('content')
<div class="page-header">
    <h1>User Management</h1>
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
        + Add User
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $u)
                @php $isSelf = $u->id === auth()->id(); @endphp
                <tr class="{{ ! $u->is_active ? 'suspended' : '' }}">

                    {{-- Name + email --}}
                    <td>
                        <div class="user-cell">
                            <span class="user-initials">
                                {{ strtoupper(substr($u->name, 0, 1)) }}{{ strtoupper(substr(strstr($u->name, ' '), 1, 1)) }}
                            </span>
                            <div class="user-meta">
                                <div>{{ $u->name }} @if($isSelf) <span style="font-size:.72rem;color:var(--text-muted)">(you)</span> @endif</div>
                                <div class="user-email">{{ $u->email }}</div>
                            </div>
                        </div>
                    </td>

                    {{-- Role --}}
                    <td>
                        <span class="badge {{ $u->role === 'admin' ? 'badge-admin' : 'badge-user' }}">
                            {{ ucfirst($u->role) }}
                        </span>
                    </td>

                    {{-- Status --}}
                    <td>
                        <span class="badge {{ $u->is_active ? 'badge-active' : 'badge-suspended' }}">
                            {{ $u->is_active ? 'Active' : 'Suspended' }}
                        </span>
                    </td>

                    {{-- Joined --}}
                    <td style="white-space:nowrap;color:var(--text-muted);font-size:.8rem;">
                        {{ $u->created_at->format('d M Y') }}
                    </td>

                    {{-- Actions --}}
                    <td>
                        <div class="actions">
                            {{-- Edit --}}
                            <a href="{{ route('admin.users.edit', $u) }}" class="btn btn-outline btn-sm">
                                ✎ Edit
                            </a>

                            {{-- Suspend / Reactivate (cannot self-suspend) --}}
                            @if (! $isSelf)
                                <form method="POST"
                                      action="{{ route('admin.users.toggle-active', $u) }}"
                                      style="margin:0;">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm {{ $u->is_active ? 'btn-warning' : 'btn-outline' }}"
                                            onclick="return confirm('{{ $u->is_active ? 'Suspend' : 'Reactivate' }} {{ addslashes($u->name) }}?')">
                                        {{ $u->is_active ? '⏸ Suspend' : '▶ Reactivate' }}
                                    </button>
                                </form>

                                {{-- Delete --}}
                                <form method="POST"
                                      action="{{ route('admin.users.destroy', $u) }}"
                                      style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Permanently delete {{ addslashes($u->name) }}? This cannot be undone.')">
                                        ✕ Delete
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">
                        No users found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($users->hasPages())
    <div style="margin-top:1rem;">
        {{ $users->links() }}
    </div>
@endif
@endsection
