@extends('layouts.app')

@section('title', 'Cable Schedules')

@section('content')

<div class="page-header">
    <h1 class="page-title">{{ $showDeleted ? 'Cable Schedules — Deleted' : 'Cable Schedules' }}</h1>
    <div style="display:flex;gap:.5rem;align-items:center;">
        @if ($isAdmin)
            @if ($showDeleted)
                <a href="{{ route('cable-schedules.index') }}" class="btn btn-outline btn-sm">← Live Records</a>
            @else
                <a href="{{ route('cable-schedules.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">🗑 View Deleted</a>
            @endif
        @endif
        @if (! $showDeleted)
            <a href="{{ route('cable-schedules.create') }}" class="btn btn-teal">+ New from Quote PDF</a>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedules as $s)
                    <tr style="opacity:.6;background:#fff8f8;">
                        <td>
                            <strong>{{ $s->project_name }}</strong>
                            @if ($s->project_ref)<br><small style="color:#666;">{{ $s->project_ref }}</small>@endif
                        </td>
                        <td>{{ $s->client_name ?? '—' }}</td>
                        <td>{{ $s->items_count }}</td>
                        <td style="font-size:.8rem;color:#9CA3AF;white-space:nowrap;">{{ $s->deleted_at->format('d M Y H:i') }}</td>
                        <td>
                            <div class="actions">
                                <form method="POST" action="{{ route('cable-schedules.restore', $s->id) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">↩ Restore</button>
                                </form>
                                <form method="POST" action="{{ route('cable-schedules.force-destroy', $s->id) }}" style="margin:0;"
                                      onsubmit="return confirm('Permanently delete this cable schedule?\n\nThis CANNOT be undone.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">✕ Delete Forever</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($schedules->hasPages())
                <div class="pagination-wrap" style="padding:1rem;">{{ $schedules->links() }}</div>
            @endif
        @endif

    @elseif ($schedules->isEmpty())
        <div class="empty-state">
            <h3>No cable schedules yet</h3>
            <p>Upload a QuoteWerks PDF and let the AI generate a cable schedule for you.</p>
            <a href="{{ route('cable-schedules.create') }}" class="btn btn-teal">+ New from Quote PDF</a>
        </div>
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schedules as $s)
                <tr>
                    <td>
                        <strong>{{ $s->project_name }}</strong>
                        @if ($s->project_ref)
                            <br><small style="color:#666;">{{ $s->project_ref }}</small>
                        @endif
                    </td>
                    <td>{{ $s->client_name ?? '—' }}</td>
                    <td>
                        @if ($s->status === 'final')
                            <span class="badge" style="background:#D4EDDA;color:#155724;">Final</span>
                        @else
                            <span class="badge badge-grey">Draft</span>
                        @endif
                    </td>
                    <td>{{ $s->items_count }}</td>
                    <td style="font-size:.8rem;color:#888;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        {{ $s->source_filename ?? '—' }}
                    </td>
                    <td style="white-space:nowrap;">
                        {{ $s->created_at->format('d M Y') }}<br>
                        <small style="color:#999;">{{ $s->created_at->format('H:i') }}</small>
                    </td>
                    <td>
                        <div class="actions">
                            <a href="{{ route('cable-schedules.edit', $s) }}" class="btn btn-outline btn-sm">Edit</a>
                            <form method="POST" action="{{ route('cable-schedules.destroy', $s->id) }}"
                                  onsubmit="return confirm('Delete this cable schedule? Admins can restore it later.');" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger-outline btn-sm">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if ($schedules->hasPages())
            <div class="pagination-wrap" style="padding:1rem;">{{ $schedules->links() }}</div>
        @endif
    @endif

</div>

@endsection
