@extends('layouts.app')

@section('title', 'Site Surveys')

@section('content')

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">{{ $showDeleted ? 'Site Surveys — Deleted' : 'Site Surveys' }}</h1>
        <div class="page-subtitle">
            @if ($showDeleted)
                Soft-deleted survey records. Admins can restore.
            @else
                Room-by-room site assessments captured via the engineer link.
            @endif
        </div>
    </div>
    <div class="page-header-actions">
        @if ($isAdmin)
            @if ($showDeleted)
                <a href="{{ route('site-surveys.index') }}" class="btn btn-outline btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    Live Records
                </a>
            @else
                <a href="{{ route('site-surveys.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                    </svg>
                    View Deleted
                </a>
            @endif
        @endif
        @if (! $showDeleted)
            <a href="{{ route('site-surveys.create') }}" class="btn btn-teal btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                New Survey
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

        @if ($surveys->isEmpty())
            <div class="empty-state"><h3>No deleted surveys</h3></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Surveyor</th>
                        <th>Deleted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($surveys as $survey)
                    <tr style="opacity:.6;background:#fff8f8;">
                        <td>
                            <strong>{{ $survey->project_name }}</strong>
                            @if ($survey->project_ref)<br><small style="color:#666;">{{ $survey->project_ref }}</small>@endif
                        </td>
                        <td>{{ $survey->client_name ?? '—' }}</td>
                        <td>{{ $survey->surveyor_name ?? '—' }}</td>
                        <td style="font-size:.8rem;color:#9CA3AF;white-space:nowrap;">{{ $survey->deleted_at->format('d M Y H:i') }}</td>
                        <td>
                            <div class="actions">
                                <form method="POST" action="{{ route('site-surveys.restore', $survey->id) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">↩ Restore</button>
                                </form>
                                <form method="POST" action="{{ route('site-surveys.force-destroy', $survey->id) }}" style="margin:0;"
                                      data-confirm="Permanently delete this survey? This CANNOT be undone."
                                      data-confirm-label="Delete Forever"
                                      data-confirm-danger="1">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">✕ Delete Forever</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($surveys->hasPages())
                <div class="pagination-wrap" style="padding:1rem;">{{ $surveys->links() }}</div>
            @endif
        @endif

    @elseif ($surveys->isEmpty())
        {{-- Re-audit UX-07 — bespoke .empty-state div drifted from every
             other list. Now uses the shared component so heading weight,
             spacing, and CTA sizing match cable-schedules, rams,
             projects/show, worksheets. --}}
        <x-empty-state
            title="No site surveys yet"
            description="Capture room-by-room AV requirements on site using the survey form."
            :href="route('site-surveys.create')"
            action="+ New Survey" />
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Surveyor</th>
                    <th>Survey Date</th>
                    <th>Rooms</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($surveys as $survey)
                <tr>
                    <td>
                        <strong>{{ $survey->project_name }}</strong>
                        @if ($survey->project_ref)
                            <br><small style="color:#666;">{{ $survey->project_ref }}</small>
                        @endif
                    </td>
                    <td>{{ $survey->client_name ?? '—' }}</td>
                    <td>{{ $survey->surveyor_name ?? '—' }}</td>
                    <td>{{ $survey->survey_date ? $survey->survey_date->format('d M Y') : '—' }}</td>
                    <td>{{ $survey->rooms_count }}</td>
                    <td>
                        @if($survey->status === 'completed')
                            <span style="background:#d4edda;color:#155724;padding:.15rem .5rem;border-radius:3px;font-size:.75rem;font-weight:600;">Completed</span>
                        @else
                            <span style="background:#fff3cd;color:#856404;padding:.15rem .5rem;border-radius:3px;font-size:.75rem;">Draft</span>
                        @endif
                    </td>
                    <td style="white-space:nowrap;">
                        {{ $survey->created_at->format('d M Y') }}<br>
                        <small style="color:#999;">{{ $survey->created_at->format('H:i') }}</small>
                    </td>
                    <td>
                        {{-- Tier-1 audit fix — v1 stacked View / Edit / ✕ per row,
                             putting Delete as prominent as View. Delete moves
                             into the overflow menu; View stays primary. --}}
                        <div class="actions" style="display:flex;align-items:center;gap:.3rem;">
                            <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">View</a>
                            <x-row-actions-menu label="More survey actions">
                                <a href="{{ route('site-surveys.edit', $survey) }}" class="row-actions-item">
                                    <span class="row-actions-item__icon" aria-hidden="true">✎</span>
                                    <span>Edit</span>
                                </a>
                                <form method="POST" action="{{ route('site-surveys.destroy', $survey->id) }}"
                                      data-confirm="Delete this survey? Admins can restore it later."
                                      data-confirm-label="Delete"
                                      data-confirm-danger="1" style="margin:0;">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="row-actions-item row-actions-item--danger">
                                        <span class="row-actions-item__icon" aria-hidden="true">✕</span>
                                        <span>Delete survey</span>
                                    </button>
                                </form>
                            </x-row-actions-menu>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if ($surveys->hasPages())
            <div class="pagination-wrap" style="padding:1rem;">{{ $surveys->links() }}</div>
        @endif
    @endif

</div>

@endsection
