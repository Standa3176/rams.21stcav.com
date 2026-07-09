@extends('layouts.app')

@section('title', $showDeleted ? 'Deleted Projects' : 'Projects')

@section('content')
<x-app-shell>

    {{-- ── Page header ───────────────────────────────────────────────────────── --}}
    <x-page-header :title="$showDeleted ? 'Deleted Projects' : 'Projects'"
                   breadcrumb="Operations Platform">
        <x-slot name="actions">
            @if ($isAdmin)
                @if ($showDeleted)
                    <x-actions.secondary-button :href="route('projects.index')">
                        ← Live Projects
                    </x-actions.secondary-button>
                @else
                    <x-actions.secondary-button :href="route('projects.index', ['show_deleted' => 1])">
                        View Deleted
                    </x-actions.secondary-button>
                @endif
            @endif
            @if (! $showDeleted)
                <x-actions.primary-button :href="route('quote-import.create')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                         style="margin-right:.25rem; vertical-align:middle;">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    New Project
                </x-actions.primary-button>
            @endif
        </x-slot>
    </x-page-header>

    {{-- ── KPI stat cards ─────────────────────────────────────────────────────── --}}
    @if (! $showDeleted)
    <div class="proj-stat-row" role="region" aria-label="Project counts">
        <div class="proj-stat-card">
            <div class="proj-stat-card__value">{{ $statusCounts->sum() }}</div>
            <div class="proj-stat-card__label">Total</div>
        </div>
        @foreach (\App\Models\Project::STATUS_LABELS as $key => $label)
            @php $count = $statusCounts->get($key, 0); @endphp
            @if ($count > 0)
            <a href="{{ route('projects.index', ['status' => $key]) }}"
               class="proj-stat-card proj-stat-card--link {{ $status === $key ? 'proj-stat-card--active' : '' }}"
               aria-current="{{ $status === $key ? 'true' : 'false' }}">
                <div class="proj-stat-card__value">{{ $count }}</div>
                <div class="proj-stat-card__label">{{ $label }}</div>
            </a>
            @endif
        @endforeach
    </div>
    @endif

    {{-- ── Filters + search bar ──────────────────────────────────────────────── --}}
    @if (! $showDeleted)
    <div class="proj-filter-bar">

        {{-- Status filter tabs --}}
        <div class="proj-filter-tabs">
            <a href="{{ route('projects.index') }}"
               class="proj-filter-tab {{ ! $status ? 'active' : '' }}">
                All
                <span class="proj-filter-tab__count">{{ $statusCounts->sum() }}</span>
            </a>
            @foreach (\App\Models\Project::STATUS_LABELS as $key => $label)
                @php $count = $statusCounts->get($key, 0); @endphp
                @if ($count > 0)
                <a href="{{ route('projects.index', ['status' => $key]) }}"
                   class="proj-filter-tab {{ $status === $key ? 'active' : '' }}">
                    {{ $label }}
                    <span class="proj-filter-tab__count">{{ $count }}</span>
                </a>
                @endif
            @endforeach
        </div>

        {{-- Search + client filter --}}
        <form method="GET" action="{{ route('projects.index') }}" class="proj-search-form">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <select name="client"
                    aria-label="Filter by client"
                    class="form-control proj-select"
                    data-optional
                    onchange="this.form.submit()">
                <option value="">All clients</option>
                @foreach ($clients as $name)
                    <option value="{{ $name }}" {{ $client === $name ? 'selected' : '' }}>{{ $name }}</option>
                @endforeach
            </select>
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
            <x-actions.secondary-button type="submit">Search</x-actions.secondary-button>
            @if ($search || $client)
                <x-actions.secondary-button
                    :href="route('projects.index', $status ? ['status' => $status] : [])"
                    variant="ghost">
                    Clear
                </x-actions.secondary-button>
            @endif
        </form>

    </div>
    @endif

    {{-- ── Deleted projects view ─────────────────────────────────────────────── --}}
    @if ($showDeleted)

        @if ($projects->isEmpty())
            <x-empty-state title="No deleted projects" description="There are no deleted projects to show."/>
        @else
            <x-section-card :flush="true">
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
                        <tr class="proj-row--deleted">
                            <td><strong>{{ $project->name }}</strong></td>
                            <td class="proj-cell--muted">{{ $project->client_name }}</td>
                            <td class="proj-cell--muted">{{ $project->ref ?? '—' }}</td>
                            <td class="proj-cell--faint proj-cell--nowrap">{{ $project->deleted_at->format('d M Y H:i') }}</td>
                            <td>
                                <div class="actions">
                                    <form method="POST" action="{{ route('projects.restore', $project->id) }}" class="form-bare">
                                        @csrf
                                        <x-actions.secondary-button type="submit">↩ Restore</x-actions.secondary-button>
                                    </form>
                                    <form method="POST" action="{{ route('projects.force-destroy', $project->id) }}" class="form-bare"
                                          data-confirm="Permanently delete project &quot;{{ $project->name }}&quot;? This CANNOT be undone."
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

                @if ($projects->hasPages())
                    <x-slot name="footer">
                        <div class="pagination-wrap" style="margin:0; justify-content:flex-end;">
                            {{ $projects->links() }}
                        </div>
                    </x-slot>
                @endif
            </x-section-card>
        @endif

    {{-- ── Empty state ───────────────────────────────────────────────────────── --}}
    @elseif ($projects->isEmpty())

        <x-empty-state
            title="No projects found"
            :description="$search ? 'Try adjusting your search or filters.' : 'Import a quote to create your first project.'"
            :href="route('quote-import.create')"
            action="Import Quote">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
        </x-empty-state>

    {{-- ── Projects table ────────────────────────────────────────────────────── --}}
    @else

        <x-section-card :flush="true">
            <table class="data-table proj-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th style="width:130px;">Status</th>
                        <th style="width:110px;">Ref</th>
                        <th style="width:110px;">Updated</th>
                        <th style="width:100px;"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($projects as $project)
                    @php
                        $addr     = Str::limit($project->site_address ?? '', 55);
                        $showAddr = $addr !== '' && stripos($project->name, substr($addr, 0, 20)) === false;
                    @endphp
                    <tr class="{{ $project->isArchived() ? 'proj-row--archived' : '' }}">

                        {{-- Project name + client + site ──────────────────── --}}
                        <td class="proj-td--project">
                            <a href="{{ route('projects.show', $project) }}" class="proj-name-link">
                                {{ Str::limit($project->name, 70) }}
                            </a>
                            <div class="proj-cell--meta">
                                <span>{{ $project->client_name }}</span>
                                @if ($showAddr)
                                    <span class="proj-cell--meta-sep" aria-hidden="true">·</span>
                                    <span>{{ $addr }}</span>
                                @endif
                            </div>
                        </td>

                        {{-- Status ───────────────────────────────────────── --}}
                        <td class="proj-td--status">
                            <x-status-badge :status="$project->status"/>
                        </td>

                        {{-- Ref ──────────────────────────────────────────── --}}
                        <td class="proj-cell--faint">{{ $project->ref ?? '—' }}</td>

                        {{-- Updated ──────────────────────────────────────── --}}
                        <td class="proj-cell--faint proj-cell--nowrap">{{ $project->updated_at->diffForHumans() }}</td>

                        {{-- Actions ──────────────────────────────────────── --}}
                        <td class="proj-td--actions">
                            <div class="actions actions--end">
                                <x-actions.secondary-button :href="route('projects.show', $project)">
                                    View
                                </x-actions.secondary-button>
                                <form method="POST" action="{{ route('projects.destroy', $project->id) }}" class="form-bare"
                                      data-confirm="Delete project &quot;{{ $project->name }}&quot;? Admins can restore it from the deleted projects view."
                                      data-confirm-label="Delete"
                                      data-confirm-danger="1">
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
        </x-section-card>

    @endif

</x-app-shell>

@push('styles')
<style>
/* ── projects/index Jetbuilt-clean (2026-07-09) ──────────────────────────
   Retunes stat row + filter tabs + search input to the accent-only
   language shipped in Phases A–E. Class names retained so the deep
   Blade markup below doesn't need to move. */

/* ── Stat cards ─────────────────────────────────────────────────────── */
.proj-stat-row {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
}
.proj-stat-row::-webkit-scrollbar { display: none; }

.proj-stat-card {
    background: var(--surface);
    border: 1px solid var(--ink-200);
    border-radius: var(--radius-lg);
    padding: 14px 20px;
    min-width: 110px;
    flex-shrink: 0;
    text-align: center;
    box-shadow: none;
}
.proj-stat-card--link {
    text-decoration: none;
    cursor: pointer;
    transition: border-color var(--transition), background var(--transition);
}
.proj-stat-card--link:hover {
    border-color: var(--ink-300);
    background: var(--surface-soft);
    text-decoration: none;
    box-shadow: none;
}
.proj-stat-card--active {
    border-color: var(--accent-600);
    background: var(--accent-50);
}
.proj-stat-card__value {
    font-size: 22px;
    font-weight: 600;
    color: var(--ink-900);
    line-height: 1.15;
    letter-spacing: -.02em;
    font-variant-numeric: tabular-nums;
}
.proj-stat-card--active .proj-stat-card__value { color: var(--accent-700); }
.proj-stat-card__label {
    font-size: var(--fs-small);
    font-weight: 500;
    color: var(--ink-500);
    text-transform: none;
    letter-spacing: 0;
    margin-top: 4px;
    white-space: nowrap;
}

/* ── Filter bar ─────────────────────────────────────────────────────── */
.proj-filter-bar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.proj-filter-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.proj-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: var(--fs-small);
    font-weight: 500;
    color: var(--ink-700);
    border: 1px solid var(--ink-200);
    background: var(--surface);
    text-decoration: none;
    transition: background var(--transition), border-color var(--transition), color var(--transition);
    white-space: nowrap;
}
.proj-filter-tab:hover {
    background: var(--surface-soft);
    border-color: var(--ink-300);
    color: var(--ink-900);
    text-decoration: none;
}
.proj-filter-tab.active {
    background: var(--accent-600);
    border-color: var(--accent-600);
    color: #fff;
    font-weight: 600;
}
.proj-filter-tab__count {
    font-size: 11px;
    font-weight: 600;
    background: color-mix(in oklab, currentColor 12%, transparent);
    border-radius: 999px;
    padding: 1px 7px;
    font-variant-numeric: tabular-nums;
}
.proj-filter-tab.active .proj-filter-tab__count { background: rgba(255,255,255,.20); }

/* ── Search form ────────────────────────────────────────────────────── */
.proj-search-form       { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.proj-select            { width: 200px; font-size: var(--fs-small); }
.proj-search-input-wrap { position: relative; }
.proj-search-icon       { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--ink-400); pointer-events: none; }
.proj-search-input {
    padding: 7px 12px 7px 32px;
    border: 1px solid var(--ink-200);
    border-radius: var(--radius-sm);
    font-size: var(--fs-small);
    font-family: inherit;
    color: var(--ink-900);
    background: var(--surface);
    width: 260px;
    transition: border-color var(--transition), box-shadow var(--transition);
}
.proj-search-input:focus {
    outline: none;
    border-color: var(--accent-600);
    box-shadow: var(--shadow-focus);
}

/* ── Enhanced table ─────────────────────────────────────────────────── */
.proj-table tbody tr td { padding: 14px 16px; vertical-align: middle; }
.proj-table thead tr th { padding: 12px 16px; }

.proj-td--project   { max-width: 0; }
.proj-name-link {
    display: block;
    font-size: var(--fs-body);
    font-weight: 600;
    color: var(--ink-900);
    text-decoration: none;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -0.005em;
}
.proj-name-link:hover { color: var(--accent-700); text-decoration: none; }

.proj-cell--meta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    font-size: var(--fs-small);
    color: var(--ink-500);
    margin-top: 3px;
    line-height: 1.4;
}
.proj-cell--meta-sep { opacity: .4; }

/* Status cell — vertically centred */
.proj-td--status    { vertical-align: middle; }

/* Actions cell */
.proj-td--actions   { text-align: right; }
.actions--end       { justify-content: flex-end; }

/* Inline form reset */
.form-bare          { margin: 0; }

/* Table row helpers */
.proj-row--deleted  { opacity: .6; background: #fff8f8; }
.proj-row--archived { opacity: .5; }
.proj-cell--muted   { color: var(--text-muted); }
.proj-cell--faint   { font-size: .84rem; color: var(--text-faint); }
.proj-cell--nowrap  { white-space: nowrap; }

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .proj-filter-bar   { flex-direction: column; }
    .proj-search-input { width: 100%; }
    .proj-search-form  { width: 100%; }
    .proj-select       { width: 100%; }
    .proj-stat-card__value { font-size: 1.25rem; }
}
</style>
@endpush

@endsection
