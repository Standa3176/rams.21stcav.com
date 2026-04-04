@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

@php
    /* Safe stat queries — default to 0 if model/column doesn't exist yet. */
    try {
        $statActiveProjects = \App\Models\Project::whereNotIn('status', ['archived'])->count();
        $statAllProjects    = \App\Models\Project::count();
    } catch (\Throwable $e) { $statActiveProjects = 0; $statAllProjects = 0; }

    try {
        $statRams = \App\Models\RamsDocument::count();
    } catch (\Throwable $e) { $statRams = 0; }

    try {
        $statSurveys = \App\Models\SiteSurvey::count();
    } catch (\Throwable $e) { $statSurveys = 0; }

    try {
        $statImports = \App\Models\ProjectPackage::count();
    } catch (\Throwable $e) { $statImports = 0; }

    try {
        $recentProjects = \App\Models\Project::orderByDesc('updated_at')->limit(6)->get();
    } catch (\Throwable $e) { $recentProjects = collect(); }

    try {
        $recentRams = \App\Models\RamsDocument::orderByDesc('created_at')->limit(6)->get();
    } catch (\Throwable $e) { $recentRams = collect(); }
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<x-dashboard.page-header title="Dashboard" breadcrumb="21st Century AV Operations">
    <x-slot name="actions">
        <a href="{{ route('projects.create') }}" class="btn btn-teal btn-sm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Project
        </a>
        <a href="{{ route('quote-import.create') }}" class="btn btn-outline btn-sm">
            Import Quote
        </a>
    </x-slot>
</x-dashboard.page-header>

{{-- ── Stat cards grid ─────────────────────────────────────────────────── --}}
<div class="dash-stats-grid">

    <x-dashboard.stat-card
        title="Active Projects"
        :value="$statActiveProjects"
        subtitle="Across all stages"
        href="{{ route('projects.index') }}"
        color="#178A95">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="RAMS Generated"
        :value="$statRams"
        subtitle="Total documents"
        href="{{ route('rams.index') }}"
        color="#2563EB">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="Site Surveys"
        :value="$statSurveys"
        subtitle="Total surveys"
        href="{{ route('site-surveys.index') }}"
        color="#7C3AED">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="Quote Imports"
        :value="$statImports"
        subtitle="Packages created"
        href="{{ route('quote-import.create') }}"
        color="#D97706">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="16 16 12 12 8 16"/>
            <line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
        </svg>
    </x-dashboard.stat-card>

</div>

{{-- ── Two-panel row ───────────────────────────────────────────────────── --}}
<div class="dash-panels">

    {{-- Recent Projects ──────────────────────────────────────────────── --}}
    <x-dashboard.table-wrapper title="Recent Projects">
        <x-slot name="header">
            <a href="{{ route('projects.index') }}" class="btn btn-ghost btn-sm">View all</a>
        </x-slot>

        @if($recentProjects->isEmpty())
            <x-dashboard.empty-state
                title="No projects yet"
                message="Create your first project to get started."
                href="{{ route('projects.create') }}"
                action="Create Project"/>
        @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            @foreach($recentProjects as $project)
                <tr>
                    <td>
                        <a href="{{ route('projects.show', $project) }}" style="font-weight:600;">
                            {{ $project->name }}
                        </a>
                        @if($project->client_name)
                        <div style="font-size:.78rem; color:#9CA3AF; margin-top:1px;">{{ $project->client_name }}</div>
                        @endif
                    </td>
                    <td>
                        <x-dashboard.status-badge :status="$project->status"/>
                    </td>
                    <td style="font-size:.8rem; color:#9CA3AF; white-space:nowrap;">
                        {{ $project->updated_at->diffForHumans() }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </x-dashboard.table-wrapper>

    {{-- Recent RAMS ──────────────────────────────────────────────────── --}}
    <x-dashboard.table-wrapper title="Recent RAMS Documents">
        <x-slot name="header">
            <a href="{{ route('rams.index') }}" class="btn btn-ghost btn-sm">View all</a>
        </x-slot>

        @if($recentRams->isEmpty())
            <x-dashboard.empty-state
                title="No RAMS documents yet"
                message="Generate a RAMS document from the RAMS section."
                href="{{ route('rams.index') }}"
                action="Go to RAMS"/>
        @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Project</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            @foreach($recentRams as $rams)
                <tr>
                    <td>
                        <a href="{{ route('rams.review', $rams) }}" style="font-weight:600;">
                            {{ $rams->title ?? 'RAMS #'.$rams->id }}
                        </a>
                    </td>
                    <td style="font-size:.85rem; color:#6B7280;">
                        {{ $rams->project?->name ?? '—' }}
                    </td>
                    <td style="font-size:.8rem; color:#9CA3AF; white-space:nowrap;">
                        {{ $rams->created_at->diffForHumans() }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </x-dashboard.table-wrapper>

</div>

{{-- ── Quick links strip ───────────────────────────────────────────────── --}}
<div class="dash-quick-links">
    <a href="{{ route('rams.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon" style="background:#EFF6FF; color:#2563EB;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">Generate RAMS</div>
            <div class="dash-quick-link__sub">Create risk assessment</div>
        </div>
    </a>
    <a href="{{ route('site-surveys.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon" style="background:#F5F3FF; color:#7C3AED;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">New Site Survey</div>
            <div class="dash-quick-link__sub">Start a site assessment</div>
        </div>
    </a>
    <a href="{{ route('cable-schedules.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon" style="background:#ECFDF5; color:#059669;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">Cable Schedule</div>
            <div class="dash-quick-link__sub">Create from PDF</div>
        </div>
    </a>
    <a href="{{ route('om-manuals.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon" style="background:#FFF7ED; color:#EA580C;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">O&amp;M Manual</div>
            <div class="dash-quick-link__sub">Generate operations guide</div>
        </div>
    </a>
</div>

<style>
/* Stat grid */
.dash-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
}

/* Two-panel layout */
.dash-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    margin-bottom: 1.75rem;
    align-items: start;
}

/* Quick links */
.dash-quick-links {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}
.dash-quick-link {
    display: flex;
    align-items: center;
    gap: .875rem;
    padding: 1rem 1.25rem;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    text-decoration: none;
    color: inherit;
    transition: box-shadow .15s ease, border-color .15s ease;
}
.dash-quick-link:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); border-color: #C8E9EC; text-decoration: none; }
.dash-quick-link__icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.dash-quick-link__title { font-size: .875rem; font-weight: 600; color: #1F2937; }
.dash-quick-link__sub   { font-size: .75rem; color: #9CA3AF; margin-top: .1rem; }

/* Responsive */
@media (max-width: 1100px) {
    .dash-stats-grid  { grid-template-columns: repeat(2, 1fr); }
    .dash-quick-links { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .dash-panels     { grid-template-columns: 1fr; }
}
@media (max-width: 540px) {
    .dash-stats-grid  { grid-template-columns: 1fr; }
    .dash-quick-links { grid-template-columns: 1fr; }
}
</style>

@endsection
