@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

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

    {{-- Tier-one KPI row (PLAN 260708-b7i). Each icon tile carries the
         semantic accent — the raw number stays in ink so the four stats
         read as a rank-ordered set, not four competing colours. --}}
    <x-dashboard.stat-card
        title="Active Projects"
        :value="$statActiveProjects"
        subtitle="Across all stages"
        href="{{ route('projects.index') }}"
        color="#4F46E5">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="RAMS Generated"
        :value="$statRams"
        subtitle="Total documents"
        href="{{ route('rams.index') }}"
        color="#0284C7">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="Quote Imports"
        :value="$statImports"
        subtitle="Packages created"
        href="{{ route('quote-import.create') }}"
        color="#059669">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="16 16 12 12 8 16"/>
            <line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
        </svg>
    </x-dashboard.stat-card>

</div>

{{-- ── Status filter strip + project health grid (Alpine-wrapped) ──────── --}}
<div x-data="{
    filter: '',
    init() {
        const hash = window.location.hash.replace('#', '');
        if (hash) this.filter = hash;
        this.$watch('filter', v => { window.location.hash = v || ' '; });
    }
}" class="dash-filter-section">

    {{-- ── Status summary strip ───────────────────────────────────────── --}}
    <div class="dash-status-strip">
        <button class="dash-chip"
                @click="filter = ''"
                :class="{ 'dash-chip--active': filter === '' }">
            All
            <span class="dash-chip__count">{{ $projects->count() }}</span>
        </button>
        @foreach(\App\Models\Project::STATUS_LABELS as $key => $label)
            @if($key !== 'archived')
            <button class="dash-chip"
                @click="filter = (filter === '{{ $key }}') ? '' : '{{ $key }}'"
                :class="{ 'dash-chip--active': filter === '{{ $key }}' }"
                style="--chip-colour: {{ \App\Models\Project::STATUS_COLOURS[$key] ?? '#6B7280' }}">
                {{ $label }}
                <span class="dash-chip__count">{{ $statusCounts[$key] ?? 0 }}</span>
            </button>
            @endif
        @endforeach
    </div>

    {{-- ── Project health grid ─────────────────────────────────────────── --}}
    @if($projects->isEmpty())
        <x-dashboard.empty-state
            title="No active projects"
            message="Create or import a project to get started."
            href="{{ route('projects.create') }}"
            action="Create Project"/>
    @else
    <div class="dash-health-grid">
        <div class="dash-health-grid__header">
            <span>Project</span>
            <span>Stage</span>
            <span>Health</span>
            <span>Programme</span>
            <span>Updated</span>
            <span></span>
        </div>

        {{-- Tier-1 audit fix — dashboard was rendering the entire projects
             inventory (14 rows), duplicating /projects. Cap the visible
             count at 5 with a "View all" link so the dashboard curates
             instead of mirrors. Full list still available via the sidebar
             Projects link or the "View all →" pill below. --}}
        @php
            $projectPreviewLimit = 5;
            $projectPreview      = $projects->take($projectPreviewLimit);
            $projectOverflow     = max(0, $projects->count() - $projectPreviewLimit);
        @endphp
        @foreach($projectPreview as $project)
        @php
            $health    = $healthMap[$project->id];
            $programme = null;
            $pct       = null;
            if (in_array($project->status, [\App\Models\Project::STATUS_INSTALLING, \App\Models\Project::STATUS_COMMISSIONING])) {
                $programme = $project->activeInstallProgramme;
                if ($programme) {
                    $total  = $programme->tasks->count();
                    $done   = $programme->tasks->where('status', \App\Models\InstallTask::STATUS_COMPLETE)->count();
                    $pct    = $total > 0 ? round($done / $total * 100) : 0;
                }
            }
        @endphp
        <div class="dash-health-row"
             x-show="filter === '' || filter === '{{ $project->status }}'">
            <div class="dash-health-row__name">
                <a href="{{ route('projects.show', $project) }}" class="dash-health-row__link">
                    {{ $project->name }}
                </a>
                @if($project->client_name)
                <div class="dash-health-row__client">{{ $project->client_name }}</div>
                @endif
            </div>
            <div>
                <x-dashboard.status-badge :status="$project->status"/>
            </div>
            <div>
                <x-dashboard.health-badge :health="$health"/>
            </div>
            <div class="dash-health-row__programme">
                @if($pct !== null)
                <div class="dash-prog">
                    <div class="dash-prog__bar">
                        <div class="dash-prog__fill" style="width:{{ $pct }}%"></div>
                    </div>
                    <span class="dash-prog__pct">{{ $pct }}%</span>
                </div>
                @else
                <span class="dash-health-row__none">—</span>
                @endif
            </div>
            <div class="dash-health-row__updated">{{ $project->updated_at->diffForHumans() }}</div>
            <div>
                <a href="{{ route('projects.show', $project) }}" class="btn btn-ghost btn-sm">View</a>
            </div>
        </div>
        @endforeach

        @if($projectOverflow > 0)
            <div style="padding: 12px 20px; text-align: center; border-top: 1px solid var(--border); background: var(--surface-soft);">
                <a href="{{ route('projects.index') }}" style="color: var(--teal-700); font-size: 13px; font-weight: 600; text-decoration: none;">
                    View all {{ $projects->count() }} projects →
                </a>
                <span style="color: var(--text-muted); font-size: 12px; margin-left: 8px;">
                    (showing {{ $projectPreviewLimit }} most recent · {{ $projectOverflow }} more)
                </span>
            </div>
        @endif
    </div>
    @endif

</div>{{-- /x-data --}}

{{-- ── Recent RAMS panel (retained, now full-width) ────────────────────── --}}
<div class="dash-panels dash-panels--single">

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
                        <a href="{{ route('rams.review', $rams) }}" style="font-weight:600; color:var(--ink-900); text-decoration:none;">
                            {{ $rams->title ?? 'RAMS #'.$rams->id }}
                        </a>
                    </td>
                    <td style="font-size:12px; color:var(--text-muted);">
                        {{ $rams->project?->name ?? '—' }}
                    </td>
                    <td style="font-size:12px; color:var(--text-muted); white-space:nowrap; font-variant-numeric: tabular-nums;">
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
        <div class="dash-quick-link__icon dql-i-brand">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">Generate RAMS</div>
            <div class="dash-quick-link__sub">Create risk assessment</div>
        </div>
    </a>
    <a href="{{ route('site-surveys.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon dql-i-violet">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">New Site Survey</div>
            <div class="dash-quick-link__sub">Start a site assessment</div>
        </div>
    </a>
    <a href="{{ route('cable-schedules.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon dql-i-success">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">Cable Schedule</div>
            <div class="dash-quick-link__sub">Create from PDF</div>
        </div>
    </a>
    <a href="{{ route('om-manuals.create') }}" class="dash-quick-link">
        <div class="dash-quick-link__icon dql-i-warning">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        </div>
        <div>
            <div class="dash-quick-link__title">O&amp;M Manual</div>
            <div class="dash-quick-link__sub">Generate operations guide</div>
        </div>
    </a>
</div>

<style>
/*
 * Dashboard styles — tier-one (PLAN 260708-b7i, 2026-07-08).
 * All hex values replaced with token variables so the palette can shift
 * from one place (layouts/app.blade.php :root) and every dashboard
 * element follows.
 */

/* Stat grid */
.dash-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

/* Two-panel layout */
.dash-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
    align-items: start;
}
.dash-panels--single { grid-template-columns: 1fr; }

/* Quick links */
.dash-quick-links {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.dash-quick-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--surface, #fff);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    text-decoration: none;
    color: inherit;
    transition: box-shadow 150ms ease, border-color 150ms ease;
}
.dash-quick-link:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--border-strong);
    text-decoration: none;
}
.dash-quick-link__icon {
    width: 38px; height: 38px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
/* Icon accent tints — sourced from the semantic palette. Same swatches
   as the KPI card icons above so a user's eye connects "cable = success
   green" between the quick-link and the stat card. */
.dql-i-brand   { background: var(--teal-100); color: var(--teal-700); }
.dql-i-violet  { background: #F5F3FF;         color: #7C3AED; }
.dql-i-success { background: var(--success-light); color: var(--success); }
.dql-i-warning { background: var(--warning-light); color: var(--warning); }
.dash-quick-link__title { font-size: 13px; font-weight: 600; color: var(--ink-900); letter-spacing: -0.005em; }
.dash-quick-link__sub   { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* ── Status filter strip ─────────────────────────────────────────────── */
.dash-filter-section { margin-bottom: 24px; }

.dash-status-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
    align-items: center;
}
.dash-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    background: var(--surface);
    color: var(--ink-700);
    cursor: pointer;
    font-family: inherit;
    transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}
.dash-chip:hover {
    border-color: var(--chip-colour, var(--teal-700));
    color: var(--chip-colour, var(--teal-700));
    background: color-mix(in oklab, var(--chip-colour, var(--teal-700)) 8%, var(--surface));
}
.dash-chip--active {
    background: var(--chip-colour, var(--teal-700));
    border-color: var(--chip-colour, var(--teal-700));
    color: #fff;
    font-weight: 600;
}
.dash-chip__count {
    background: rgba(0,0,0,.10);
    border-radius: 999px;
    padding: 1px 6px;
    font-size: 10px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.dash-chip--active .dash-chip__count { background: rgba(255,255,255,.20); }

/* ── Health grid ─────────────────────────────────────────────────────── */
.dash-health-grid {
    background: var(--surface, #fff);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    overflow: hidden;
}
.dash-health-grid__header {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.25fr 1fr auto;
    gap: 16px;
    padding: 10px 20px;
    background: var(--surface-soft, #F8FAFC);
    border-bottom: 1px solid var(--border);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
}
.dash-health-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.25fr 1fr auto;
    gap: 16px;
    align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid var(--rule);
    transition: background .12s ease;
}
.dash-health-row:last-child { border-bottom: none; }
.dash-health-row:hover { background: color-mix(in oklab, var(--teal-100) 22%, transparent); }

.dash-health-row__link {
    font-weight: 600; color: var(--ink-900);
    text-decoration: none; letter-spacing: -0.005em;
    font-size: 13px;
}
.dash-health-row__link:hover { color: var(--teal-700); text-decoration: underline; }
.dash-health-row__client { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
.dash-health-row__updated { font-size: 12px; color: var(--text-muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
.dash-health-row__none { color: var(--text-faint); font-size: 13px; }

/* ── Progress bar widget ─────────────────────────────────────────────── */
.dash-prog {
    display: flex;
    align-items: center;
    gap: 8px;
}
.dash-prog__bar {
    flex: 1;
    height: 5px;
    background: var(--ink-100);
    border-radius: 999px;
    overflow: hidden;
    min-width: 48px;
}
.dash-prog__fill {
    height: 100%;
    background: linear-gradient(90deg, var(--teal-500), var(--teal-700));
    border-radius: 999px;
    box-shadow: 0 0 6px 0 color-mix(in oklab, var(--teal-500) 40%, transparent);
    transition: width .3s ease;
}
.dash-prog__pct {
    font-size: 11px;
    font-weight: 600;
    color: var(--ink-700);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

/* Responsive */
@media (max-width: 1100px) {
    .dash-stats-grid  { grid-template-columns: repeat(2, 1fr); }
    .dash-quick-links { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    .dash-health-grid__header { display: none; }
    .dash-health-row {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto auto;
        gap: .4rem;
    }
    .dash-health-row__programme, .dash-health-row__updated { font-size: .72rem; }
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
