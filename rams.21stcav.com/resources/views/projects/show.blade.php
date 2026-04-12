@extends('layouts.app')

@section('title', $project->name)

@section('content')

@php
    $colour     = $project->status_colour;
    $lifecycle  = \App\Models\Project::LIFECYCLE;
    $currentIdx = array_search($project->status, $lifecycle);
    $isAdmin    = auth()->user()?->isAdmin();
@endphp

<nav class="breadcrumb" aria-label="breadcrumb">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    <span style="margin:0 .5rem;color:var(--text-faint);">›</span>
    <span style="color:var(--text-muted);">{{ $project->name }}</span>
</nav>

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $project->name }}</h1>
        <p style="color:#666; font-size:.875rem; margin-top:.25rem;">
            {{ $project->client_name }} &nbsp;·&nbsp; {{ $project->site_address }}
            @if($project->ref) &nbsp;·&nbsp; Ref: <strong>{{ $project->ref }}</strong> @endif
        </p>
    </div>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Projects</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- ── Lifecycle progress bar ─────────────────────────────────────────────── --}}
<div class="card card-sm" style="margin-bottom:1.25rem; overflow:hidden; padding:1.25rem 1.5rem;">
    <div style="display:flex; align-items:center; gap:0; overflow-x:auto; padding-bottom:.25rem;">
        @foreach($lifecycle as $i => $step)
            @php
                $stepLabel  = \App\Models\Project::STATUS_LABELS[$step];
                $stepColour = \App\Models\Project::STATUS_COLOURS[$step];
                $isActive   = $step === $project->status;
                $isPast     = $i < $currentIdx;
                $isFuture   = $i > $currentIdx;
            @endphp
            <div style="display:flex; align-items:center; flex-shrink:0;">
                <div style="
                    padding:.3rem .75rem;
                    border-radius:3px;
                    font-size:.75rem;
                    font-weight:{{ $isActive ? '700' : '500' }};
                    background:{{ $isActive ? $stepColour : ($isPast ? $stepColour.'22' : '#f4f6f8') }};
                    color:{{ $isActive ? '#fff' : ($isPast ? $stepColour : '#aaa') }};
                    border:1px solid {{ $isActive ? $stepColour : ($isPast ? $stepColour.'44' : '#ddd') }};
                    white-space:nowrap;
                ">{{ $stepLabel }}</div>
                @if(!$loop->last)
                    <div style="width:18px; height:1px; background:#ddd; flex-shrink:0;"></div>
                @endif
            </div>
        @endforeach
    </div>
</div>

{{-- ── Main grid ──────────────────────────────────────────────────────────── --}}
<div class="proj-show-grid" style="display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start;">

    {{-- Left column --}}
    <div>

        {{-- ── Lifecycle action ──────────────────────────────────────────────── --}}
        @if (!$project->isArchived())
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">
                Lifecycle Action
            </h2>

            <div style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-start;">
                @if ($nextStatus)
                    @php $nextLabel = \App\Models\Project::STATUS_LABELS[$nextStatus]; @endphp
                    <form method="POST" action="{{ route('projects.transition', $project) }}">
                        @csrf
                        <input type="hidden" name="to_status" value="{{ $nextStatus }}">
                        <button type="submit" class="btn btn-teal"
                                onclick="return confirm('Advance project to {{ $nextLabel }}?')">
                            Advance → {{ $nextLabel }}
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('projects.archive', $project) }}"
                      style="display:flex; gap:.5rem; align-items:center;">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm"
                            onclick="return confirm('Archive this project?')">
                        Archive
                    </button>
                </form>
            </div>
        </div>
        @endif

        @if ($project->canReopen())
        <div class="card card-sm" style="border-left:3px solid #fd7e14; margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem;">Reopen Project</h2>
            <form method="POST" action="{{ route('projects.reopen', $project) }}"
                  style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; margin-top:.75rem;">
                @csrf
                <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                    <label class="form-label" for="reopen_reason">Reason for Reopening <span class="req">*</span></label>
                    <input id="reopen_reason" name="reopen_reason" type="text"
                           class="form-control" placeholder="e.g. Customer requested additional works" required>
                </div>
                <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;">
                    Reopen
                </button>
            </form>
        </div>
        @endif

        {{-- ── Quote History ───────────────────────────────────────────────── --}}
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Quote History
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->projectQuotes->count() }}
                    </span>
                </h2>
                @php
                    $latestRams = $project->ramsDocuments->sortByDesc('id')->first();
                    $quoteRamsMap = $project->ramsDocuments
                        ->filter(fn ($r) => ! empty($r->form_data['original_filename'] ?? null))
                        ->groupBy(fn ($r) => $r->form_data['original_filename'])
                        ->map(fn ($g) => $g->sortByDesc('id')->first());
                    // Use the package already eager-loaded on the project where possible.
                    $headerPackage = $project->latestPackage
                        ?: $project->packages()->latest()->first();
                @endphp
                <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                    {{-- Primary CTA: Edit Project Data if a package exists, otherwise Upload --}}
                    @if ($headerPackage)
                        <a href="{{ route('project-packages.review.show', $headerPackage) }}"
                           class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            ✎ Edit Project Data
                        </a>
                        <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
                           class="btn btn-outline btn-sm" style="font-size:.78rem;">
                            ↑ Upload New Quote
                        </a>
                    @else
                        <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
                           class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            ↑ Upload New Quote
                        </a>
                    @endif
                </div>
            </div>

            @if ($project->projectQuotes->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No quotes uploaded yet.
                    <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}" style="color:var(--teal);">Upload a quote PDF</a> to get started.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th style="width:50px;">Ver.</th>
                            <th>Original File</th>
                            <th>Quote Ref</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->projectQuotes->sortByDesc('version_number') as $pq)
                        @php
                            $linkedRams = $quoteRamsMap[$pq->original_filename] ?? null;
                        @endphp
                        <tr>
                            <td style="text-align:center; font-weight:700; color:#007B8A;">
                                v{{ $pq->version_number }}
                            </td>
                            <td>
                                <span title="{{ $pq->original_filename }}" style="font-family:monospace; font-size:.78rem;">
                                    {{ \Illuminate\Support\Str::limit($pq->original_filename, 45) }}
                                </span>
                            </td>
                            <td>{{ $pq->quote_reference ?? '—' }}</td>
                            <td style="color:#666;">{{ $pq->client_name ?? '—' }}</td>
                            <td style="white-space:nowrap;">
                                @if ($linkedRams)
                                    <span class="badge {{ $linkedRams->statusBadgeClass() }}">
                                        {{ $linkedRams->statusLabel() }}
                                    </span>
                                @else
                                    <span style="color:#888;">—</span>
                                @endif
                            </td>
                            <td style="white-space:nowrap; color:#888;">
                                {{ $pq->created_at->format('d M Y') }}<br>
                                <small>{{ $pq->created_at->format('H:i') }}</small>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Tab Strip: Overview / Project Data (D-25) ─────────────────── --}}
        <div x-data="{ activeTab: 'overview' }">

            {{-- Tab buttons --}}
            <div style="display:flex; border-bottom:1px solid var(--border); margin-bottom:1rem;">
                <button @click="activeTab='overview'"
                        style="padding:.75rem 1rem; border:none; background:none; cursor:pointer; font-size:.9375rem; font-weight:600; border-bottom:2px solid transparent;"
                        :style="activeTab==='overview' ? 'border-bottom-color:var(--teal); color:var(--teal)' : 'color:var(--text-muted)'"
                        role="tab" :aria-selected="activeTab==='overview'">
                    Overview
                </button>
                <button @click="activeTab='data'"
                        style="padding:.75rem 1rem; border:none; background:none; cursor:pointer; font-size:.9375rem; border-bottom:2px solid transparent;"
                        :style="activeTab==='data' ? 'border-bottom-color:var(--teal); color:var(--teal)' : 'color:var(--text-muted)'"
                        role="tab" :aria-selected="activeTab==='data'">
                    Project Data
                </button>
            </div>

            {{-- Overview tab: Linked Records --}}
            <div x-show="activeTab==='overview'" role="tabpanel">

        {{-- ── Linked Records ──────────────────────────────────────────────── --}}
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">Linked Records</h2>
            </div>

            @foreach($linkedRecords as $entry)
                <div style="border-bottom:1px solid var(--border);">
                    @if($entry['records']->isNotEmpty())
                        <x-dashboard.table-wrapper>
                            <table class="data-table" style="font-size:.84rem;">
                                <thead>
                                    <tr>
                                        <th style="width:120px;">Type</th>
                                        <th>Reference / Name</th>
                                        <th>Status</th>
                                        <th style="white-space:nowrap;">Date</th>
                                        <th style="width:80px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($entry['records'] as $record)
                                    <tr>
                                        <td>
                                            <span class="badge {{ $entry['badge_class'] }}">{{ $entry['type'] }}</span>
                                        </td>
                                        <td>
                                            {{ \Illuminate\Support\Str::limit($record->project_name ?? $record->name ?? ('Record #' . $record->id), 50) }}
                                        </td>
                                        <td>
                                            @if(isset($record->status))
                                                <x-dashboard.status-badge :status="$record->status"/>
                                            @else
                                                <span style="color:var(--text-faint);">—</span>
                                            @endif
                                        </td>
                                        <td style="color:var(--text-faint); white-space:nowrap; font-size:.8rem;">
                                            {{ $record->updated_at->diffForHumans() }}
                                        </td>
                                        <td>
                                            @if($entry['route_name'])
                                                <a href="{{ route($entry['route_name'], $record) }}"
                                                   class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                                            @endif
                                            @if(!empty($entry['download_route_name']) && $record->filename)
                                                <a href="{{ route($entry['download_route_name'], $record) }}"
                                                   class="btn btn-teal btn-sm" style="font-size:.75rem; margin-left:.25rem;"
                                                   target="_blank" aria-label="Download {{ $entry['type'] }}">↓ Download</a>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-dashboard.table-wrapper>
                    @else
                        {{-- Empty state row --}}
                        <div style="padding:1rem 1.25rem; display:flex; align-items:center; gap:.75rem;">
                            <span class="badge {{ $entry['badge_class'] }}">{{ $entry['type'] }}</span>
                            <span style="color:#888; font-size:.875rem; flex:1;">No records yet.</span>

                            @if(!empty($entry['generate_route']))
                                @php
                                    $latestRecord = $entry['records']->first();
                                    $generatingStatuses = ['pending', 'generating'];
                                    $doneStatuses = ['draft', 'final'];
                                @endphp

                                @if(!$latestRecord || $latestRecord->status === 'failed')
                                    {{-- State 1: Generate button --}}
                                    <form method="POST" action="{{ $entry['generate_route'] }}">
                                        @csrf
                                        <button type="submit" class="btn btn-teal btn-sm"
                                                aria-label="Generate {{ $entry['type'] }}">
                                            {{ $entry['generate_label'] }}
                                        </button>
                                    </form>

                                @elseif(in_array($latestRecord->status, $generatingStatuses))
                                    {{-- State 2: Spinner with Alpine.js polling --}}
                                    @if(!empty($entry['status_route_name']))
                                    <div x-data="{
                                            pollInterval: null,
                                            startPolling() {
                                                this.pollInterval = setInterval(() => {
                                                    fetch('{{ route($entry['status_route_name'], $latestRecord) }}')
                                                        .then(r => r.json())
                                                        .then(data => {
                                                            if (['draft','final','failed'].includes(data.status)) {
                                                                clearInterval(this.pollInterval);
                                                                window.location.reload();
                                                            }
                                                        })
                                                        .catch(() => clearInterval(this.pollInterval));
                                                }, 4000);
                                            }
                                        }"
                                        x-init="startPolling()">
                                        <button type="button" class="btn btn-outline btn-sm"
                                                disabled aria-disabled="true"
                                                aria-label="Generating {{ $entry['type'] }}, please wait">
                                            <svg style="display:inline-block;width:14px;height:14px;vertical-align:middle;margin-right:.25rem;animation:spin 1s linear infinite;"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 12a9 9 0 11-6.219-8.56"/>
                                            </svg>
                                            Generating…
                                        </button>
                                    </div>
                                    @else
                                    {{--
                                        Static fallback — only reached if a generate-capable type
                                        has no status_route_name. After Task 1, all three types
                                        (Worksheet, O&M, Cable Schedule) have status_route_name set,
                                        so this branch is a defensive fallback only.
                                    --}}
                                    <button type="button" class="btn btn-outline btn-sm" disabled aria-disabled="true">
                                        Generating…
                                    </button>
                                    @endif

                                @elseif(in_array($latestRecord->status, $doneStatuses))
                                    {{-- State 3: Download + View --}}
                                    @if(!empty($entry['download_route_name']))
                                        <a href="{{ route($entry['download_route_name'], $latestRecord) }}"
                                           class="btn btn-teal btn-sm"
                                           target="_blank"
                                           aria-label="Download {{ $entry['type'] }} DOCX">↓ Download</a>
                                    @endif
                                    @if(!empty($entry['route_name']))
                                        <a href="{{ route($entry['route_name'], $latestRecord) }}"
                                           class="btn btn-outline btn-sm">View</a>
                                    @endif
                                @endif

                            @elseif(!empty($entry['empty_action_route']) && !empty($entry['empty_action_label']))
                                {{--
                                    Legacy GET-link fallback for types without generate_route.
                                    RAMS uses empty_action_route → links to rams.upload.create (GET).
                                    Survey uses empty_action_route → links to site-surveys.from-project (GET).
                                    These types use existing page links, not POST generation forms.
                                    Worksheet, O&M, and Cable Schedule all have generate_route set
                                    after Task 1, so they never reach this branch.
                                --}}
                                <a href="{{ $entry['empty_action_route'] }}" class="btn btn-outline btn-sm">
                                    {{ $entry['empty_action_label'] }}
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

            </div>{{-- end Overview tab panel --}}

            {{-- Project Data tab: read-only canonical dataset (D-25) --}}
            <div x-show="activeTab==='data'" role="tabpanel">
                <div class="card card-sm" style="margin-bottom:1.25rem; padding:1.25rem;">
                    <h2 class="section-heading" style="font-size:.9rem; margin:0 0 .75rem;">Canonical Dataset</h2>
                    <p style="font-size:.8125rem; color:var(--text-muted); margin-bottom:1rem;">
                        Read-only view of the merged project data.
                        @if(!empty($canonicalData['meta']))
                            Source: <strong>{{ $canonicalData['meta']['data_source'] ?? 'unknown' }}</strong>.
                            Overall confidence: {{ number_format(($canonicalData['meta']['confidence'] ?? 0) * 100, 0) }}%.
                        @endif
                    </p>

                    {{-- Equipment --}}
                    @if(!empty($canonicalData['equipment']))
                        <h3 style="font-size:.8125rem; font-weight:600; margin:1rem 0 .5rem;">Equipment ({{ count($canonicalData['equipment']) }})</h3>
                        <table class="data-table" style="font-size:.8125rem; margin-bottom:1.25rem;">
                            <thead><tr><th>Name</th><th>Qty</th><th>Area</th><th>Source</th><th>Confidence</th></tr></thead>
                            <tbody>
                            @foreach($canonicalData['equipment'] as $item)
                                <tr>
                                    <td>{{ $item['name'] ?? '—' }}</td>
                                    <td>{{ $item['quantity'] ?? '—' }}</td>
                                    <td>{{ $item['area'] ?? '—' }}</td>
                                    <td title="Source: {{ ucfirst(str_replace('_', ' ', $item['data_source'] ?? '')) }}">{{ $item['data_source'] ?? '—' }}</td>
                                    <td @if(($item['confidence'] ?? 1) < 0.7) style="color:var(--danger); font-weight:600;" @endif>
                                        {{ number_format(($item['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @else
                        <p style="color:var(--text-muted); font-size:.8125rem;">No equipment data available.</p>
                    @endif

                    {{-- Rooms --}}
                    @if(!empty($canonicalData['rooms']))
                        <h3 style="font-size:.8125rem; font-weight:600; margin:1rem 0 .5rem;">Rooms ({{ count($canonicalData['rooms']) }})</h3>
                        <table class="data-table" style="font-size:.8125rem; margin-bottom:1.25rem;">
                            <thead><tr><th>Name</th><th>Source</th><th>Confidence</th></tr></thead>
                            <tbody>
                            @foreach($canonicalData['rooms'] as $room)
                                <tr>
                                    <td>{{ $room['name'] ?? '—' }}</td>
                                    <td title="Source: {{ ucfirst(str_replace('_', ' ', $room['data_source'] ?? '')) }}">{{ $room['data_source'] ?? '—' }}</td>
                                    <td @if(($room['confidence'] ?? 1) < 0.7) style="color:var(--danger); font-weight:600;" @endif>
                                        {{ number_format(($room['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    <p style="font-size:.75rem; color:var(--text-faint);">
                        Low confidence threshold: &lt;70%. Fields highlighted in red need review.
                    </p>
                </div>
            </div>{{-- end Project Data tab panel --}}

        </div>{{-- end x-data tab wrapper --}}

        {{-- ── RAMS Documents ──────────────────────────────────────────────── --}}
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    RAMS Documents
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->ramsDocuments->count() }}
                    </span>
                </h2>
                @php
                    $latestPackage      = $project->latestPackage ?: $project->packages()->latest()->first();
                    $latestAwaitingRams = $project->ramsDocuments->where('status', \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)->sortByDesc('id')->first();
                    $generatingRams     = $project->ramsDocuments->whereIn('status', [\App\Models\RamsDocument::STATUS_GENERATING, \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION])->first();
                    $hasCompletedRams   = $project->ramsDocuments->whereIn('status', [\App\Models\RamsDocument::STATUS_COMPLETED, \App\Models\RamsDocument::STATUS_FOR_REVIEW, \App\Models\RamsDocument::STATUS_DRAFT])->isNotEmpty();
                @endphp
                <div style="display:flex; gap:.5rem; align-items:center;">
                    @if ($latestAwaitingRams)
                        {{-- New PDF upload flow: quote data extracted, needs review before generating --}}
                        <a href="{{ route('rams.quote-review.show', $latestAwaitingRams) }}"
                           class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            ✎ Review &amp; Generate
                        </a>
                    @elseif ($generatingRams)
                        {{-- A RAMS is currently being built — suppress the create button --}}
                        <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>
                    @elseif ($hasCompletedRams)
                        {{-- Completed RAMS exists — generate a new version from the reviewed project data --}}
                        <form method="POST" action="{{ route('rams.from-project', $project) }}" style="margin:0;"
                              onsubmit="return confirm('Generate a new RAMS document from the current project data?');">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.78rem;">
                                + New Version
                            </button>
                        </form>
                    @elseif ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                        {{-- Classic package-based flow: reviewed data ready to generate directly --}}
                        <form method="POST" action="{{ route('rams.from-project', $project) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="btn btn-teal btn-sm" style="font-size:.78rem;">
                                + Create RAMS
                            </button>
                        </form>
                    @elseif ($latestPackage)
                        <a href="{{ route('project-packages.review.show', $latestPackage) }}"
                           class="btn btn-teal btn-sm" style="font-size:.78rem;">✎ Edit Project Data</a>
                    @else
                        <span style="font-size:.78rem; color:#888;">Upload quote in Quote History</span>
                    @endif
                </div>
            </div>

            @if ($project->ramsDocuments->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No RAMS documents yet.
                    @if ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                        <form method="POST" action="{{ route('rams.from-project', $project) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="link-button" style="color:var(--teal); background:none; border:0; padding:0; font-size:inherit; cursor:pointer;">
                                Create RAMS
                            </button>
                        </form>
                        from the reviewed project data.
                    @elseif ($latestPackage)
                        <a href="{{ route('project-packages.review.show', $latestPackage) }}" style="color:var(--teal);">Review quote data</a> to enable RAMS generation.
                    @else
                        Upload a quote in Quote History to enable RAMS generation.
                    @endif
                </p>
            @else
                @php
                    $ramsSorted = $project->ramsDocuments->sortByDesc('created_at')->values();
                    $versionMap = $project->ramsDocuments
                        ->sortBy('created_at')
                        ->values()
                        ->mapWithKeys(fn($doc, $i) => [$doc->id => $i + 1]);
                @endphp
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th style="width:70px;">Ver.</th>
                            <th>Project / Ref</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ramsSorted as $rams)
                            @php
                                $status      = $rams->status;
                                $sup         = $rams->isSuperseded();
                                $isPipeline  = $rams->isPipelineStatus();
                            @endphp
                            <tr style="{{ $sup ? 'opacity:.45;' : '' }}">
                                <td style="text-align:center; font-weight:700; color:#007B8A;">
                                    v{{ $versionMap[$rams->id] ?? '—' }}
                                </td>
                                <td>
                                    <strong>{{ $rams->project_name ?: '—' }}</strong>
                                    @if ($rams->project_ref)
                                        <br><small style="color:#888; font-size:.75rem;">{{ $rams->project_ref }}</small>
                                    @endif
                                    @if ($sup)
                                        <br><small style="color:#c0392b; font-size:.72rem;">Superseded</small>
                                    @endif
                                </td>
                                <td>
                                    @if ($sup)
                                        <span class="badge badge-grey">Superseded</span>
                                    @elseif ($isPipeline)
                                        <span class="badge {{ $rams->statusBadgeClass() }}">{{ $rams->statusLabel() }}</span>
                                    @else
                                        <span class="badge badge-grey">{{ $rams->statusLabel() }}</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap; color:#888;">
                                    {{ $rams->created_at->format('d M Y') }}<br>
                                    <small>{{ $rams->created_at->format('H:i') }}</small>
                                </td>
                                <td>
                                    <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; {{ $sup ? 'pointer-events:none;' : '' }}">

                                        @if ($status === \App\Models\RamsDocument::STATUS_APPROVED)
                                            {{-- Step 2: approved — ready to generate --}}
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-teal btn-sm" style="font-size:.75rem;">▶ Generate</button>
                                            </form>

                                        @elseif (in_array($status, [
                                            \App\Models\RamsDocument::STATUS_UPLOADED,
                                            \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                            \App\Models\RamsDocument::STATUS_GENERATING,
                                        ], true))
                                            {{-- In progress --}}
                                            <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>

                                        @elseif ($status === \App\Models\RamsDocument::STATUS_COMPLETED && $rams->filename)
                                            {{-- Step 3: done — download + option to re-edit --}}
                                            <a href="{{ route('rams.download', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                            <a href="{{ route('rams.download-pdf', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>
                                        @elseif ($status === \App\Models\RamsDocument::STATUS_FAILED)
                                            {{-- Failed — show retry based on what data is available --}}
                                            <span style="font-size:.78rem; color:#991b1b; margin-right:.25rem;">⚠ Failed</span>
                                            @if (!empty($rams->reviewed_data))
                                                <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('rams.retry-extraction', $rams) }}" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                                </form>
                                            @endif

                                        @elseif ($rams->filename && in_array($status, [
                                            \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                            \App\Models\RamsDocument::STATUS_DRAFT,
                                        ], true))
                                            {{-- Legacy statuses with an existing file --}}
                                            <a href="{{ route('rams.download', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                            <a href="{{ route('rams.download-pdf', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>
                                        @endif

                                        {{-- Delete (soft delete) --}}
                                        <form method="POST"
                                              action="{{ route('rams.destroy', $rams) }}"
                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');"
                                              style="margin:0;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── O&M Manuals ─────────────────────────────────────────────────── --}}
        {{-- Always rendered so the "+ New O&M" button is always reachable --}}
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    O&amp;M Manuals
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->omManuals->count() }}
                    </span>
                </h2>
                @php
                    $latestPackage       = $project->latestPackage ?: $project->packages()->latest()->first();
                    $generatingOm        = $project->omManuals->where('status', \App\Models\OmManual::STATUS_GENERATING)->first();
                    $hasCompletedOm      = $project->omManuals->whereIn('status', [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])->isNotEmpty();
                @endphp
                {{-- New O&M link — always present so tests and users can reach the upload form --}}
                <a href="{{ route('om-manuals.create', ['project_id' => $project->id]) }}"
                   class="btn btn-outline btn-sm" style="font-size:.78rem; margin-right:.5rem;">
                    + New O&amp;M
                </a>
                @if ($generatingOm)
                    {{-- An O&M is currently being built — prevent duplicate submissions --}}
                    <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>
                @elseif ($hasCompletedOm)
                    {{-- O&M already exists — new version requires a fresh quote upload --}}
                    <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
                       class="btn btn-outline btn-sm" style="font-size:.78rem;">
                        + New Version
                    </a>
                @elseif ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            + Create O&amp;M
                        </button>
                    </form>
                @elseif ($latestPackage)
                    <a href="{{ route('project-packages.review.show', $latestPackage) }}"
                       class="btn btn-outline btn-sm" style="font-size:.78rem;">
                        Review Quote Data
                    </a>
                @else
                    <span style="font-size:.78rem; color:#888;">Upload quote in Quote History</span>
                @endif
            </div>

            @if ($project->omManuals->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No O&amp;M manuals yet.
                    @if ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                        <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="link-button" style="color:var(--teal); background:none; border:0; padding:0; font-size:inherit; cursor:pointer;">
                                Create an O&amp;M manual
                            </button>
                        </form>
                    @elseif ($latestPackage)
                        <a href="{{ route('project-packages.review.show', $latestPackage) }}"
                           style="color:var(--teal);">Review quote data</a> to enable O&amp;M generation.
                    @else
                        Upload a quote in Quote History to enable O&amp;M generation.
                    @endif
                    for this project.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Manual</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->omManuals->sortByDesc('created_at') as $manual)
                        <tr>
                            <td>
                                <strong>{{ $manual->project_name ?? 'O&M Manual #' . $manual->id }}</strong>
                                @if ($manual->project_ref)
                                    <br><small style="color:#888; font-size:.75rem;">{{ $manual->project_ref }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $manual->statusBadgeClass() }}" style="font-size:.75rem;">
                                    {{ $manual->statusLabel() }}
                                </span>
                            </td>
                            <td style="color:#888; white-space:nowrap;">
                                {{ $manual->created_at->format('d M Y') }}<br>
                                <small>{{ $manual->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center;">

                                    @if ($manual->status === \App\Models\OmManual::STATUS_GENERATING)
                                        {{-- Job is running — show progress indicator --}}
                                        <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>

                                    @elseif ($manual->status === \App\Models\OmManual::STATUS_FAILED)
                                        {{-- Generation failed — show error and retry --}}
                                        <span style="font-size:.78rem; color:#991b1b; margin-right:.25rem;" title="{{ $manual->error_message }}">⚠ Failed</span>
                                        @if (! empty($manual->extracted_data))
                                            <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                            </form>
                                        @endif

                                    @elseif ($manual->isGenerated())
                                        {{-- Pass 2 complete: download + regen --}}
                                        <a href="{{ route('om-manuals.download', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                        <a href="{{ route('om-manuals.download-pdf', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                        <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" style="margin:0;">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                    onclick="return confirm('Rebuild this O&amp;M manual from the existing data?');">↺ Regen</button>
                                        </form>

                                    @elseif ($manual->status === \App\Models\OmManual::STATUS_EXTRACTED)
                                        {{-- Pass 1 complete: user can review extracted data --}}
                                        <a href="{{ route('om-manuals.edit', $manual) }}"
                                           class="btn btn-teal btn-sm" style="font-size:.75rem;">✎ Review</a>

                                    @else
                                        <a href="{{ route('om-manuals.edit', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                                    @endif

                                    {{-- Delete --}}
                                    <form method="POST"
                                          action="{{ route('om-manuals.destroy', $manual) }}"
                                          onsubmit="return confirm('Delete this O&amp;M manual?');"
                                          style="margin:0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Site Surveys ────────────────────────────────────────────────── --}}
        @php
            $latestSurvey = $project->siteSurveys->sortByDesc('created_at')->first();
        @endphp
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Site Surveys
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->siteSurveys->count() }}
                    </span>
                </h2>
                <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                    {{-- Always show "Create Survey" — multiple surveys per project are allowed --}}
                    <a href="{{ route('site-surveys.from-project', $project) }}"
                       class="btn btn-teal btn-sm" style="font-size:.78rem;">
                        + Create Survey
                    </a>
                </div>
            </div>

            @if ($project->siteSurveys->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No site surveys yet.
                    <a href="{{ route('site-surveys.from-project', $project) }}"
                       style="color:var(--teal);">Create a survey</a>
                    to share a pre-filled form with your on-site engineer.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Survey</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->siteSurveys->sortByDesc('created_at') as $survey)
                        @php
                            $badgeStyle = match($survey->status) {
                                'completed' => 'background:#D1FAE5; color:#065F46; border-color:#6EE7B7;',
                                default     => 'background:#FEF3C7; color:#92400E; border-color:#FCD34D;',
                            };
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ 'Site Survey #' . $survey->id }}</strong>
                                @if ($survey->surveyor_name)
                                    <br><small style="color:#888; font-size:.75rem;">By: {{ $survey->surveyor_name }}</small>
                                @endif
                                @if ($survey->survey_date)
                                    <br><small style="color:#888; font-size:.75rem;">{{ $survey->survey_date->format('d M Y') }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge" style="{{ $badgeStyle }} padding:.15rem .45rem; border-radius:10px; border:1px solid; font-size:.75rem;">
                                    {{ ucfirst($survey->status ?? 'draft') }}
                                    @if ($survey->isSubmitted()) · Submitted @endif
                                </span>
                            </td>
                            <td style="color:#888; white-space:nowrap;">
                                {{ $survey->created_at->format('d M Y') }}<br>
                                <small>{{ $survey->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center;">

                                    {{-- Engineer link — copy to clipboard --}}
                                    @if ($survey->access_token && !$survey->isTokenExpired())
                                        <button type="button"
                                                class="btn btn-outline btn-sm"
                                                style="font-size:.75rem;"
                                                onclick="copyEngineerLink('{{ $survey->publicUrl() }}', this)"
                                                title="{{ $survey->publicUrl() }}">
                                            🔗 Copy Link
                                        </button>
                                    @endif

                                    {{-- View / Edit (authenticated) --}}
                                    <a href="{{ route('site-surveys.show', $survey) }}"
                                       class="btn btn-outline btn-sm" style="font-size:.75rem;">👁 View</a>
                                    @if (!$survey->isCompleted())
                                        <a href="{{ route('site-surveys.edit', $survey) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">✎ Edit</a>
                                    @endif

                                    {{-- Mark complete (if still draft) --}}
                                    @if (!$survey->isCompleted())
                                        <form method="POST"
                                              action="{{ route('site-surveys.complete', $survey) }}"
                                              style="margin:0;"
                                              onsubmit="return confirm('Mark this survey as completed?');">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-sm"
                                                    style="font-size:.75rem; color:#065F46; border-color:#6EE7B7;">
                                                ✓ Complete
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Delete --}}
                                    <form method="POST"
                                          action="{{ route('site-surveys.destroy', $survey) }}"
                                          onsubmit="return confirm('Delete this survey?');"
                                          style="margin:0;">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm"
                                                title="Delete" style="font-size:.75rem;">✕</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Copy-to-clipboard script for engineer links --}}
        <script>
        function copyEngineerLink(url, btn) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    var orig = btn.textContent;
                    btn.textContent = '✓ Copied!';
                    setTimeout(function() { btn.textContent = orig; }, 2000);
                });
            } else {
                window.prompt('Copy this engineer link:', url);
            }
        }
        </script>

        {{-- ── Cable Schedules ─────────────────────────────────────────────── --}}
        @if ($project->cableSchedules->isNotEmpty())
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Cable Schedules
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->cableSchedules->count() }}
                    </span>
                </h2>
            </div>
            <table class="data-table" style="font-size:.84rem;">
                <thead>
                    <tr>
                        <th>Schedule</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($project->cableSchedules->sortByDesc('created_at') as $cs)
                    <tr>
                        <td>{{ $cs->name ?? 'Cable Schedule #' . $cs->id }}</td>
                        <td style="color:#888; white-space:nowrap;">{{ $cs->created_at->format('d M Y') }}</td>
                        <td>
                            <a href="{{ route('cable-schedules.edit', $cs) }}" class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- ── Activity log ───────────────────────────────────────────────────── --}}
        <div class="card card-sm">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Activity Log</h2>
            @if ($project->activityLog->isEmpty())
                <p style="color:#888; font-size:.875rem;">No activity recorded yet.</p>
            @else
                <ul style="list-style:none; padding:0; margin:0;">
                    @foreach ($project->activityLog->take(20) as $entry)
                    <li style="display:flex; gap:.75rem; padding:.55rem 0; border-bottom:1px solid #f0f0f0; font-size:.84rem;">
                        <span style="color:#888; white-space:nowrap; padding-top:1px; min-width:110px;">
                            {{ $entry->created_at->format('d M Y H:i') }}
                        </span>
                        <span style="color:#333;">{{ $entry->description }}</span>
                    </li>
                    @endforeach
                </ul>
            @endif
        </div>

    </div>{{-- /left column --}}

    {{-- Right column — project details --}}
    <div>
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Project Details</h2>
            <dl style="display:grid; grid-template-columns:auto 1fr; gap:.4rem .75rem; font-size:.85rem;">
                <dt style="color:#888; font-weight:600;">Status</dt>
                <dd>
                    <span class="badge"
                          style="background:{{ $colour }}22; color:{{ $colour }};
                                 border:1px solid {{ $colour }}44;
                                 padding:.15rem .5rem; border-radius:10px; font-size:.78rem;">
                        {{ $project->status_label }}
                    </span>
                </dd>
                <dt style="color:#888; font-weight:600;">Client</dt>
                <dd>{{ $project->client_name }}</dd>
                <dt style="color:#888; font-weight:600;">Site</dt>
                <dd>{{ $project->site_address }}</dd>
                <dt style="color:#888; font-weight:600;">Quote ref</dt>
                <dd>{{ $project->quote_reference ?? $project->ref ?? '—' }}</dd>
                @if ($project->works_description)
                <dt style="color:#888; font-weight:600;">Scope</dt>
                <dd>{{ $project->works_description }}</dd>
                @endif
                @if ($project->notes)
                <dt style="color:#888; font-weight:600;">Notes</dt>
                <dd style="color:#666;">{{ $project->notes }}</dd>
                @endif
                <dt style="color:#888; font-weight:600;">Created</dt>
                <dd>{{ $project->created_at->format('d M Y') }}</dd>
                <dt style="color:#888; font-weight:600;">Updated</dt>
                <dd style="color:var(--text-faint);">{{ $project->updated_at->diffForHumans() }}</dd>
                @if ($project->reopened_at)
                <dt style="color:#888; font-weight:600;">Reopened</dt>
                <dd>{{ $project->reopened_at->format('d M Y') }}<br>
                    <span style="color:#666; font-size:.8rem;">{{ $project->reopen_reason }}</span></dd>
                @endif
            </dl>

            <div style="margin-top:1rem; padding-top:.75rem; border-top:1px solid #f0f0f0;">
                <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline btn-sm">Edit Details</a>
            </div>
        </div>

        {{-- Document counts summary --}}
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Documents</h2>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem;">
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">Quotes</div>
                    <div style="font-size:1.3rem; font-weight:700;">{{ $project->projectQuotes->count() }}</div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">RAMS</div>
                    <div style="font-size:1.3rem; font-weight:700;">{{ $project->ramsDocuments->count() }}</div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">O&amp;M</div>
                    <div style="font-size:1.3rem; font-weight:700;">{{ $project->omManuals->count() }}</div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">Surveys</div>
                    <div style="font-size:1.3rem; font-weight:700;">{{ $project->siteSurveys->count() }}</div>
                </div>
            </div>
        </div>

        {{-- Quick actions --}}
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Quick Actions</h2>
            <div style="display:flex; flex-direction:column; gap:.5rem;">
                @php $latestPackage = $project->latestPackage ?: $project->packages()->latest()->first(); @endphp
                @if ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-sm" style="text-align:center;">
                            + Create O&amp;M Manual
                        </button>
                    </form>
                @elseif ($latestPackage)
                    <a href="{{ route('project-packages.review.show', $latestPackage) }}"
                       class="btn btn-outline btn-sm" style="text-align:center;">
                        Review Quote Data
                    </a>
                @endif
            </div>
        </div>

        {{-- Delete --}}
        @if ($project->isArchived())
        <div class="card card-sm" style="border-left:3px solid #c0392b;">
            <p style="font-size:.8rem; color:#666; margin-bottom:.5rem;">
                Permanently delete this project and all associated data.
            </p>
            <form method="POST" action="{{ route('projects.destroy', $project) }}">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Permanently delete project &quot;{{ $project->name }}&quot;? This cannot be undone.')">
                    Delete Project
                </button>
            </form>
        </div>
        @endif
    </div>

</div>{{-- /grid --}}

<style>
@media (max-width: 900px) {
    .proj-show-grid { grid-template-columns: 1fr; }
}
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

@endsection
