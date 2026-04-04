@extends('layouts.app')

@section('title', 'Site Surveys')

@section('content')

<div class="page-header">
    <h1 class="page-title">Site Surveys</h1>
    <a href="{{ route('site-surveys.create') }}" class="btn btn-teal">+ New Survey</a>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="card" style="padding:0;overflow:hidden;">
    @if ($surveys->isEmpty())
        <div class="empty-state">
            <h3>No site surveys yet</h3>
            <p>Capture room-by-room AV requirements on site using the survey form.</p>
            <a href="{{ route('site-surveys.create') }}" class="btn btn-teal">+ New Survey</a>
        </div>
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
                        <div class="actions">
                            <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">View</a>
                            <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">Edit</a>
                            <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
                                  onsubmit="return confirm('Delete this survey?');" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger-outline btn-sm">✕</button>
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
</div>

@endsection
