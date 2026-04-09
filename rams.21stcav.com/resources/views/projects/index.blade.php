@extends('layouts.app')

@section('title', 'Projects')

@section('content')

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<x-dashboard.page-header title="{{ $showDeleted ? 'Projects — Deleted' : 'Projects' }}" breadcrumb="Operations Platform">
    <x-slot name="actions">
        @if ($isAdmin)
            @if ($showDeleted)
                <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Live Projects</a>
            @else
                <a href="{{ route('projects.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">🗑 View Deleted</a>
            @endif
        @endif
        @if (! $showDeleted)
        <a href="{{ route('quote-import.create') }}" class="btn btn-teal btn-sm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            + New Project
        </a>
        @endif
    </x-slot>
</x-dashboard.page-header>

{{-- ── Flash messages ──────────────────────────────────────────────────── --}}
@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- ── Filters + search bar ────────────────────────────────────────────── --}}
<div class="proj-filter-bar">

    {{-- Status filter tabs --}}
    <div class="proj-filter-tabs">
        <a href="{{ route('projects.index') }}"
           class="proj-filter-tab {{ !$status ? 'active' : '' }}">
            All
            <span class="proj-filter-tab__count">{{ $statusCounts->sum() }}</span>
        </a>
        @foreach(\App\Models\Project::STATUS_LABELS as $key => $label)
            @php $count = $statusCounts->get($key, 0); @endphp
            @if($count > 0)
            <a href="{{ route('projects.index', ['status' => $key]) }}"
               class="proj-filter-tab {{ $status === $key ? 'active' : '' }}">
                {{ $label }}
                <span class="proj-filter-tab__count">{{ $count }}</span>
            </a>
            @endif
        @endforeach
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('projects.index') }}" class="proj-search-form">
        @if($status)
            <input type="hidden" name="status" value="{{ $status }}">
        @endif
        <div class="proj-search-input-wrap">
            <svg class="proj-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text"
                   name="search"
                   value="{{ $search }}"
                   placeholder="Search name, client or ref…"
                   class="proj-search-input">
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        @if($search)
            <a href="{{ route('projects.index', $status ? ['status' => $status] : []) }}"
               class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </form>

</div>

{{-- ── Projects table ──────────────────────────────────────────────────── --}}
@if ($showDeleted)

    {{-- ── Deleted projects view ─────────────────────────────────────────── --}}
    @if ($projects->isEmpty())
        <div class="alert alert-info">No deleted projects found.</div>
    @else
    <x-dashboard.table-wrapper>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Ref</th>
                    <th>Deleted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($projects as $project)
                <tr style="opacity:.6;background:#fff8f8;">
                    <td><strong>{{ $project->name }}</strong></td>
                    <td>{{ $project->client_name }}</td>
                    <td>{{ $project->ref ?? '—' }}</td>
                    <td style="font-size:.8rem;color:#9CA3AF;white-space:nowrap;">{{ $project->deleted_at->format('d M Y H:i') }}</td>
                    <td>
                        <div class="actions">
                            <form method="POST" action="{{ route('projects.restore', $project->id) }}" style="margin:0;">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm">↩ Restore</button>
                            </form>
                            <form method="POST" action="{{ route('projects.force-destroy', $project->id) }}" style="margin:0;"
                                  onsubmit="return confirm('Permanently delete project &quot;{{ addslashes($project->name) }}&quot;?\n\nThis CANNOT be undone.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">✕ Delete Forever</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($projects->hasPages())
        <x-slot name="footer">
            <div class="pagination-wrap" style="margin:0;justify-content:flex-end;">{{ $projects->links() }}</div>
        </x-slot>
        @endif
    </x-dashboard.table-wrapper>
    @endif

@elseif ($projects->isEmpty())

    <x-dashboard.empty-state
        title="No projects found"
        :message="$search ? 'Try adjusting your search or filters.' : 'Create your first project to get started.'"
        href="{{ route('projects.create') }}"
        action="Create Project">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
    </x-dashboard.empty-state>

@else

    <x-dashboard.table-wrapper>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Ref</th>
                    <th>Updated</th>
                    <th style="width:140px;"></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($projects as $project)
                <tr style="{{ $project->isArchived() ? 'opacity:.5;' : '' }}">
                    <td>
                        <a href="{{ route('projects.show', $project) }}"
                           style="font-weight:600;color:var(--teal);text-decoration:none;">
                            {{ Str::limit($project->name, 70) }}
                        </a>
                        @php
                            $addr = Str::limit($project->site_address ?? '', 60);
                            // Don't repeat the address if the project name already contains it
                            $showAddr = $addr !== '' && stripos($project->name, substr($addr, 0, 20)) === false;
                        @endphp
                        @if ($showAddr)
                            <div style="font-size:.78rem; color:#9CA3AF; margin-top:1px;">
                                {{ $addr }}
                            </div>
                        @endif
                    </td>
                    <td style="color:#6B7280;">{{ $project->client_name }}</td>
                    <td>
                        <x-dashboard.status-badge :status="$project->status"/>
                    </td>
                    <td style="font-size:.85rem; color:#9CA3AF;">{{ $project->ref ?? '—' }}</td>
                    <td style="font-size:.8rem; color:#9CA3AF; white-space:nowrap;">
                        {{ $project->updated_at->diffForHumans() }}
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;flex-direction:row;gap:.4rem;align-items:center;justify-content:flex-end;flex-wrap:nowrap;">
                            <a href="{{ route('projects.show', $project) }}" class="btn btn-outline btn-sm">View</a>
                            <form method="POST" action="{{ route('projects.destroy', $project->id) }}" style="margin:0;"
                                  onsubmit="return confirm('Delete project &quot;{{ addslashes($project->name) }}&quot;?\n\nAdmins can restore it from the deleted projects view.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete project">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($projects->hasPages())
        <x-slot name="footer">
            <div class="pagination-wrap" style="margin:0; justify-content:flex-end;">
                {{ $projects->links() }}
            </div>
        </x-slot>
        @endif

    </x-dashboard.table-wrapper>

@endif

<style>
/* ── Filter bar ────────────────────────────────────────────────── */
.proj-filter-bar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.25rem;
}

/* Status tabs */
.proj-filter-tabs {
    display: flex;
    gap: .25rem;
    flex-wrap: wrap;
    align-items: center;
}
.proj-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .85rem;
    border-radius: 9999px;
    font-size: .8125rem;
    font-weight: 500;
    color: #6B7280;
    border: 1px solid #E5E7EB;
    background: #fff;
    text-decoration: none;
    transition: background .12s, border-color .12s, color .12s;
    white-space: nowrap;
}
.proj-filter-tab:hover {
    background: var(--teal-light);
    border-color: var(--teal-mid);
    color: var(--teal);
    text-decoration: none;
}
.proj-filter-tab.active {
    background: var(--teal);
    border-color: var(--teal);
    color: #fff;
}
.proj-filter-tab.active .proj-filter-tab__count { opacity: .75; }
.proj-filter-tab__count {
    font-size: .7rem;
    font-weight: 700;
    opacity: .65;
}

/* Search form */
.proj-search-form         { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.proj-search-input-wrap   { position: relative; }
.proj-search-icon         { position: absolute; left: .65rem; top: 50%; transform: translateY(-50%); color: #9CA3AF; pointer-events: none; }
.proj-search-input {
    padding: .4rem .75rem .4rem 2.1rem;
    border: 1px solid #D1D5DB;
    border-radius: 8px;
    font-size: .875rem;
    font-family: inherit;
    color: #1F2937;
    background: #fff;
    width: 260px;
    transition: border-color .15s, box-shadow .15s;
}
.proj-search-input:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(23,138,149,.15);
}

@media (max-width: 640px) {
    .proj-filter-bar      { flex-direction: column; }
    .proj-search-input    { width: 100%; }
    .proj-search-form     { width: 100%; }
}
</style>

@endsection
