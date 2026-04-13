@extends('layouts.app')

@section('title', 'Review Install Programme — ' . $programme->project->name)

@section('content')

{{-- Breadcrumb --}}
<nav style="font-size:.875rem;margin-bottom:1rem;">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    &rsaquo;
    <a href="{{ route('projects.show', $programme->project) }}" style="color:var(--teal);text-decoration:none;">{{ $programme->project->name }}</a>
    &rsaquo;
    <span style="color:var(--text-muted);">Review Install Programme</span>
</nav>

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Review Install Programme — {{ $programme->project->name }}</h1>
        <p class="page-subtitle" style="color:var(--text-muted);margin-top:.25rem;font-size:.875rem;">
            {{ $programme->project->client_name }}
            @if($programme->project->site_address) · {{ $programme->project->site_address }} @endif
        </p>
    </div>
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        <a href="{{ route('projects.show', $programme->project_id) }}" class="btn-outline btn-sm">← Back to Project</a>
    </div>
</div>

{{-- Status bar --}}
<div class="card card-sm" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;padding:.85rem 1.25rem;">
    <div style="display:flex;align-items:center;gap:.75rem;">
        <span class="badge {{ $programme->statusBadgeClass() }}">{{ $programme->statusLabel() }}</span>
        <span style="font-size:.875rem;color:var(--text-muted);">
            Generated {{ $programme->generated_at?->diffForHumans() ?? 'just now' }}
        </span>
    </div>
    @if($programme->isDraft() && $programme->tasks->count() > 0)
        <form method="POST" action="{{ route('install-programmes.activate', $programme) }}">
            @csrf
            <button type="submit" class="btn btn-teal"
                    onclick="return confirm('Activate this install programme? This will make it visible to engineers.')">
                Activate Programme
            </button>
        </form>
    @endif
</div>

{{-- Alert --}}
@if($programme->tasks->count() > 0)
    <div class="alert alert-info" style="margin-bottom:1.25rem;padding:.85rem 1.1rem;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;font-size:.875rem;color:#1D4ED8;">
        Review the generated tasks below. Delete any that don't apply, then activate the programme to make it visible to engineers.
    </div>
@else
    <div class="alert alert-warning" style="margin-bottom:1.25rem;padding:.85rem 1.1rem;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;font-size:.875rem;color:#92400E;">
        No tasks were generated. Check that the project has reviewed equipment data.
    </div>
@endif

{{-- Flash messages --}}
@if(session('success'))
    <div style="background:#D1FAE5;border:1px solid #6EE7B7;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.875rem;color:#065F46;">
        {{ session('success') }}
    </div>
@endif

{{-- Tasks grouped by room --}}
@php
    $tasksByRoom = $programme->tasks->groupBy('room_name');
@endphp

@if($tasksByRoom->isEmpty())
    <div class="card card-sm" style="padding:2rem;text-align:center;color:var(--text-muted);">
        No tasks to display.
    </div>
@else
    @foreach($tasksByRoom as $roomName => $tasks)
        <div class="card card-sm" style="margin-bottom:1.25rem;overflow:hidden;">
            <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:#F9FAFB;">
                <h2 style="margin:0;font-size:.9rem;font-weight:700;color:#0B3C45;">
                    {{ $roomName ?: 'Unknown Room' }}
                    <span style="font-weight:400;font-size:.8rem;color:var(--text-muted);margin-left:.5rem;">
                        {{ $tasks->count() }} {{ Str::plural('task', $tasks->count()) }}
                    </span>
                </h2>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.84rem;">
                    <thead>
                        <tr style="background:#F3F6F7;">
                            <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Title</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Equipment</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Category</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Status</th>
                            <th style="padding:.5rem .75rem;text-align:right;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tasks as $task)
                            <tr style="border-bottom:1px solid #f5f5f5;">
                                <td style="padding:.45rem .75rem;color:#374151;vertical-align:middle;">
                                    {{ $task->title }}
                                </td>
                                <td style="padding:.45rem .75rem;color:#374151;vertical-align:middle;">
                                    {{ $task->equipment_name }}
                                </td>
                                <td style="padding:.45rem .75rem;color:var(--text-muted);vertical-align:middle;font-size:.8rem;">
                                    {{ ucfirst($task->equipment_category) }}
                                </td>
                                <td style="padding:.45rem .75rem;vertical-align:middle;">
                                    <span style="font-size:.75rem;font-weight:600;color:#6B7280;">{{ $task->statusLabel() }}</span>
                                </td>
                                <td style="padding:.45rem .75rem;text-align:right;vertical-align:middle;">
                                    @if($programme->isDraft())
                                        <form method="POST" action="{{ route('install-tasks.destroy', $task) }}"
                                              onsubmit="return confirm('Remove this task?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="btn btn-outline btn-sm"
                                                    style="font-size:.75rem;color:#DC2626;border-color:#FECACA;">
                                                Remove
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endif

{{-- Bottom activate button (duplicate for long pages) --}}
@if($programme->isDraft() && $programme->tasks->count() > 0)
    <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;">
        <form method="POST" action="{{ route('install-programmes.activate', $programme) }}">
            @csrf
            <button type="submit" class="btn btn-teal"
                    onclick="return confirm('Activate this install programme? This will make it visible to engineers.')">
                Activate Programme
            </button>
        </form>
    </div>
@endif

@endsection
