@extends('layouts.app')

@section('title', $project->name)

@section('content')

@php
    $isAdmin            = auth()->user()?->isAdmin();
    $lifecycle          = \App\Models\Project::LIFECYCLE;
    $currentIdx         = array_search($project->status, $lifecycle);
    $primaryPackage     = $project->latestPackage ?: $project->packages()->latest()->first();
    $headerAwaitingRams = $project->ramsDocuments
                              ->where('status', \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)
                              ->sortByDesc('id')
                              ->first();
@endphp

<x-app-shell>

{{-- ── Page Header ──────────────────────────────────────────────────────────── --}}
<x-page-header
    :title="$project->name"
    :subtitle="$project->client_name . ($project->site_address ? ' · ' . $project->site_address : '')"
    :status="$project->status"
    :breadcrumb="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name],
    ]">
    <x-slot name="actions">
        @if ($headerAwaitingRams)
            <x-actions.primary-button :href="route('rams.quote-review.show', $headerAwaitingRams)">
                ✎ Review &amp; Generate
            </x-actions.primary-button>
        @elseif ($primaryPackage)
            <x-actions.primary-button :href="route('project-packages.review.show', $primaryPackage)">
                ✎ Edit Project Data
            </x-actions.primary-button>
        @else
            <x-actions.primary-button :href="route('quote-import.create', ['project_id' => $project->id])">
                ↑ Upload Quote
            </x-actions.primary-button>
        @endif
    </x-slot>
</x-page-header>

{{-- ── Project Summary ──────────────────────────────────────────────────────── --}}
<x-summary-card title="Project Overview">
    <div class="kv-grid">
        <div>
            <div class="kv-item__label">Client</div>
            <div class="kv-item__value">{{ $project->client_name }}</div>
        </div>
        <div>
            <div class="kv-item__label">Site</div>
            <div class="kv-item__value">{{ $project->site_address ?? '—' }}</div>
        </div>
        <div>
            <div class="kv-item__label">Reference</div>
            <div class="kv-item__value">{{ $project->ref ?? '—' }}</div>
        </div>
        <div>
            <div class="kv-item__label">Status</div>
            <div class="kv-item__value"><x-status-badge :status="$project->status" /></div>
        </div>
        <div>
            <div class="kv-item__label">Last Updated</div>
            <div class="kv-item__value">{{ $project->updated_at->diffForHumans() }}</div>
        </div>
    </div>
</x-summary-card>

{{-- ── Actual Hours widget (Phase 15 D-13) ─────────────────────────────────── --}}
@if (! empty($canSeeActualHours) && $actualHours !== null)
    @include('projects._actual-hours-widget')
@endif

{{-- ── Lifecycle progress bar ───────────────────────────────────────────────── --}}
<x-section-card>
    <div class="lifecycle-bar">
        @foreach ($lifecycle as $i => $step)
            @php
                $stepLabel  = \App\Models\Project::STATUS_LABELS[$step];
                $stepColour = \App\Models\Project::STATUS_COLOURS[$step];
                $isActive   = $step === $project->status;
                $isPast     = $i < $currentIdx;
            @endphp
            <div class="lifecycle-step">
                {{-- Dynamic state colours require inline styles; values are PHP-computed --}}
                <div style="
                    padding:.3rem .75rem;
                    border-radius:3px;
                    font-size:.75rem;
                    font-weight:{{ $isActive ? '700' : '500' }};
                    background:{{ $isActive ? $stepColour : ($isPast ? $stepColour.'22' : '#f4f6f8') }};
                    color:{{ $isActive ? '#fff' : ($isPast ? $stepColour : '#aaa') }};
                    border:1px solid {{ $isActive ? $stepColour : ($isPast ? $stepColour.'44' : '#ddd') }};
                    white-space:nowrap;">{{ $stepLabel }}</div>
                @if (! $loop->last)
                    <div class="lifecycle-step__connector"></div>
                @endif
            </div>
        @endforeach
    </div>
</x-section-card>

{{-- ── Main grid ────────────────────────────────────────────────────────────── --}}
<div class="proj-show-grid">

    {{-- ────────────────────────────────────────────────────────────────────── --}}
    {{-- LEFT COLUMN                                                             --}}
    {{-- ────────────────────────────────────────────────────────────────────── --}}
    <div>

        {{-- ── Lifecycle Action ─────────────────────────────────────────────── --}}
        @if (! $project->isArchived())
        <x-section-card title="Lifecycle Action">
            <div class="actions">
                @if ($nextStatus)
                    @php $nextLabel = \App\Models\Project::STATUS_LABELS[$nextStatus]; @endphp
                    <form method="POST" action="{{ route('projects.transition', $project) }}" class="form-bare">
                        @csrf
                        <input type="hidden" name="to_status" value="{{ $nextStatus }}">
                        <button type="submit" class="btn btn-teal"
                                onclick="return confirm('Advance project to {{ $nextLabel }}?')">
                            Advance → {{ $nextLabel }}
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('projects.archive', $project) }}" class="form-bare">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm"
                            onclick="return confirm('Archive this project?')">
                        Archive
                    </button>
                </form>
            </div>
        </x-section-card>
        @endif

        {{-- ── Reopen Project ────────────────────────────────────────────────── --}}
        @if ($project->canReopen())
        <x-workflow.blocking-banner title="Project can be Reopened" severity="info">
            This project has been completed or archived. Provide a reason to reopen it and resume work.
            <div class="blocking-banner__cta">
                <form method="POST" action="{{ route('projects.reopen', $project) }}" class="form-bare">
                    @csrf
                    <div class="reopen-form">
                        <div class="form-group" style="margin-bottom:0; flex:1; min-width:200px;">
                            <label class="form-label" for="reopen_reason">
                                Reason for Reopening <span class="req">*</span>
                            </label>
                            <input id="reopen_reason" name="reopen_reason" type="text"
                                   class="form-control"
                                   placeholder="e.g. Customer requested additional works" required>
                        </div>
                        <div class="reopen-form__btn">
                            <button type="submit" class="btn btn-outline btn-sm">Reopen</button>
                        </div>
                    </div>
                </form>
            </div>
        </x-workflow.blocking-banner>
        @endif

        {{-- ── Quote History ──────────────────────────────────────────────────── --}}
        @php
            $quoteRamsMap = $project->ramsDocuments
                ->filter(fn ($r) => ! empty($r->form_data['original_filename'] ?? null))
                ->groupBy(fn ($r) => $r->form_data['original_filename'])
                ->map(fn ($g) => $g->sortByDesc('id')->first());
            $headerPackage = $project->latestPackage
                ?: $project->packages()->latest()->first();
        @endphp

        <x-section-card title="Quote History ({{ $project->projectQuotes->count() }})" :flush="true">
            <x-slot name="actions">
                @if ($headerPackage)
                    <x-actions.secondary-button :href="route('project-packages.review.show', $headerPackage)">
                        ✎ Edit Project Data
                    </x-actions.secondary-button>
                @endif
                <x-actions.secondary-button :href="route('quote-import.create', ['project_id' => $project->id])">
                    ↑ Upload New Quote
                </x-actions.secondary-button>
            </x-slot>

            @if ($project->projectQuotes->isEmpty())
                <x-empty-state title="No quotes uploaded yet"
                    description="Upload a quote PDF to link it to this project."
                    :href="route('quote-import.create', ['project_id' => $project->id])"
                    action="Upload Quote"/>
            @else
                <table class="data-table data-table--sm">
                    <thead>
                        <tr>
                            <th style="width:50px;">Ver.</th>
                            <th>Original File</th>
                            <th>Quote Ref</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th class="proj-cell--nowrap">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->projectQuotes->sortByDesc('version_number') as $pq)
                        @php $linkedRams = $quoteRamsMap[$pq->original_filename] ?? null; @endphp
                        <tr>
                            <td class="tbl-ver-cell">v{{ $pq->version_number }}</td>
                            <td>
                                <span title="{{ $pq->original_filename }}" class="tbl-cell--mono">
                                    {{ \Illuminate\Support\Str::limit($pq->original_filename, 45) }}
                                </span>
                            </td>
                            <td>{{ $pq->quote_reference ?? '—' }}</td>
                            <td class="proj-cell--muted">{{ $pq->client_name ?? '—' }}</td>
                            <td>
                                @if ($linkedRams)
                                    <x-status-badge :status="$linkedRams->status" />
                                @else
                                    <span class="proj-cell--faint">—</span>
                                @endif
                            </td>
                            <td class="proj-cell--faint proj-cell--nowrap">
                                {{ $pq->created_at->format('d M Y') }}<br>
                                <small>{{ $pq->created_at->format('H:i') }}</small>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-section-card>

        {{-- ── Tab Strip: Overview / Project Data ─────────────────────────────── --}}
        <div x-data="{ activeTab: 'overview' }">

            <div class="proj-tabs">
                <button @click="activeTab='overview'"
                        class="proj-tab"
                        :class="{ 'proj-tab--active': activeTab==='overview' }"
                        role="tab" :aria-selected="activeTab==='overview'">
                    Overview
                </button>
                <button @click="activeTab='data'"
                        class="proj-tab"
                        :class="{ 'proj-tab--active': activeTab==='data' }"
                        role="tab" :aria-selected="activeTab==='data'">
                    Project Data
                </button>
            </div>

            {{-- Overview tab: Linked Records ──────────────────────────────── --}}
            <div x-show="activeTab==='overview'" role="tabpanel">

                <x-section-card title="Linked Records" :flush="true">
                    @foreach ($linkedRecords as $entry)
                    <div class="linked-records__group">

                        {{-- Section type header --}}
                        <div class="lr-section-hdr">
                            <span class="badge {{ $entry['badge_class'] }} lr-section-hdr__badge">{{ $entry['type'] }}</span>
                        </div>

                        {{-- Unified table — renders for both populated and empty states --}}
                        <table class="data-table data-table--sm lr-table">
                            <thead>
                                <tr>
                                    <th>Reference / Name</th>
                                    <th style="width:130px;">Status</th>
                                    <th style="width:110px;" class="proj-cell--nowrap">Date</th>
                                    <th style="width:200px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            @if ($entry['records']->isNotEmpty())
                                @foreach ($entry['records'] as $record)
                                <tr>
                                    <td>
                                        {{ \Illuminate\Support\Str::limit($record->project_name ?? $record->name ?? ('Record #' . $record->id), 55) }}
                                    </td>
                                    <td>
                                        @if (isset($record->status))
                                            <x-status-badge :status="$record->status" />
                                        @else
                                            <span class="proj-cell--faint">—</span>
                                        @endif
                                    </td>
                                    <td class="proj-cell--faint proj-cell--nowrap">
                                        {{ $record->updated_at->diffForHumans() }}
                                    </td>
                                    <td class="proj-cell--nowrap lr-actions">
                                        {{-- Regen: POST form --}}
                                        @if (! empty($entry['regenerate_route_name']) && ($record->status ?? '') !== \App\Models\RamsDocument::STATUS_SUPERSEDED)
                                            @php
                                                // Some regen routes expect the project (e.g. survey supersede),
                                                // others expect the record itself. Use regenerate_route_param if set.
                                                $regenParam = $entry['regenerate_route_param'] ?? $record;
                                            @endphp
                                            <form method="POST" action="{{ route($entry['regenerate_route_name'], $regenParam) }}"
                                                  class="form-bare form-bare--inline">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm lr-btn">↻ Regen</button>
                                            </form>
                                        @endif
                                        {{-- View --}}
                                        @if (! empty($entry['route_name']))
                                            <a href="{{ route($entry['route_name'], $record) }}"
                                               class="btn btn-outline btn-sm lr-btn">View</a>
                                        @endif
                                        {{-- Copy Link (Survey only) --}}
                                        @if (! empty($entry['copy_link']) && ! empty($record->access_token))
                                            <button type="button" class="btn btn-teal btn-sm lr-btn"
                                                    onclick="copyEngineerLink('{{ $record->publicUrl() }}', this)"
                                                    title="{{ $record->publicUrl() }}">⎘ Copy Link</button>
                                        @endif
                                        {{-- Download --}}
                                        @php
                                            $hasDownloadFile = ! empty($record->filename) || ! empty($record->source_filename);
                                            $isGenerating = ($record->status ?? '') === \App\Models\RamsDocument::STATUS_GENERATING;
                                            $downloadLabel = '↓ DOCX';
                                            if (! empty($record->source_filename) && empty($record->filename)) {
                                                $ext = strtoupper(pathinfo($record->source_filename, PATHINFO_EXTENSION));
                                                $downloadLabel = '↓ ' . ($ext ?: 'File');
                                            }
                                        @endphp
                                        @if (! empty($entry['download_route_name']) && $hasDownloadFile && ! $isGenerating)
                                            <a href="{{ route($entry['download_route_name'], $record) }}"
                                               class="btn btn-teal btn-sm lr-btn"
                                               target="_blank">{{ $downloadLabel }}</a>
                                        @endif
                                        {{-- Download PDF --}}
                                        @if (! empty($entry['download_pdf_route_name']) && in_array($record->status ?? '', ['completed', 'for_review', 'approved']))
                                            <a href="{{ route($entry['download_pdf_route_name'], $record) }}"
                                               class="btn btn-outline btn-sm lr-btn"
                                               target="_blank">↓ PDF</a>
                                        @endif
                                        {{-- Delete --}}
                                        @if (! empty($entry['delete_route_name']))
                                            <form method="POST" action="{{ route($entry['delete_route_name'], $record) }}"
                                                  class="form-bare form-bare--inline"
                                                  onsubmit="return confirm('Delete this {{ $entry['type'] }}?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-danger-outline btn-sm lr-btn">✕</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                {{-- Empty state — columns aligned with header --}}
                                @php
                                    $latestRecord       = $entry['records']->first();
                                    $generatingStatuses = ['pending', 'generating'];
                                    $doneStatuses       = ['draft', 'final'];
                                @endphp
                                <tr class="lr-empty-row">
                                    <td class="proj-cell--faint lr-empty-ref">Not yet generated</td>
                                    <td><span class="proj-cell--faint">—</span></td>
                                    <td><span class="proj-cell--faint">—</span></td>
                                    <td class="lr-actions">
                                        @if (! empty($entry['generate_route']))
                                            @if (! $latestRecord || $latestRecord->status === 'failed')
                                                <form method="POST" action="{{ $entry['generate_route'] }}" class="form-bare form-bare--inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-teal btn-sm lr-btn"
                                                            aria-label="Generate {{ $entry['type'] }}">
                                                        {{ $entry['generate_label'] }}
                                                    </button>
                                                </form>
                                            @elseif (in_array($latestRecord->status, $generatingStatuses))
                                                @if (! empty($entry['status_route_name']))
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
                                                    <button type="button" class="btn btn-outline btn-sm lr-btn"
                                                            disabled aria-disabled="true">
                                                        <svg class="spin-icon" viewBox="0 0 24 24" fill="none"
                                                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                            <path d="M21 12a9 9 0 11-6.219-8.56"/>
                                                        </svg>
                                                        Generating…
                                                    </button>
                                                </div>
                                                @else
                                                <button type="button" class="btn btn-outline btn-sm lr-btn" disabled>Generating…</button>
                                                @endif
                                            @elseif (in_array($latestRecord->status, $doneStatuses))
                                                @if (! empty($entry['download_route_name']))
                                                    <a href="{{ route($entry['download_route_name'], $latestRecord) }}"
                                                       class="btn btn-teal btn-sm lr-btn" target="_blank">↓ Download</a>
                                                @endif
                                                @if (! empty($entry['route_name']))
                                                    <a href="{{ route($entry['route_name'], $latestRecord) }}"
                                                       class="btn btn-outline btn-sm lr-btn">View</a>
                                                @endif
                                            @endif
                                        @elseif (! empty($entry['empty_action_route']) && ! empty($entry['empty_action_label']))
                                            <a href="{{ $entry['empty_action_route'] }}" class="btn btn-outline btn-sm lr-btn">
                                                {{ $entry['empty_action_label'] }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            </tbody>
                        </table>

                    </div>
                    @endforeach
                </x-section-card>

            </div>{{-- end overview tab panel --}}

            {{-- Project Data tab ────────────────────────────────────────────── --}}
            <div x-show="activeTab==='data'" role="tabpanel">

                <x-section-card title="Canonical Dataset">
                    <p class="data-source-note">
                        Read-only view of the merged project data.
                        @if (! empty($canonicalData['meta']))
                            Source: <strong>{{ $canonicalData['meta']['data_source'] ?? 'unknown' }}</strong>.
                            Overall confidence: {{ number_format(($canonicalData['meta']['confidence'] ?? 0) * 100, 0) }}%.
                        @endif
                    </p>

                    @if (! empty($canonicalData['equipment']))
                        <h3 class="data-subheading">Equipment ({{ count($canonicalData['equipment']) }})</h3>
                        <table class="data-table data-table--sm" style="margin-bottom:1.25rem;">
                            <thead>
                                <tr><th>Name</th><th>Qty</th><th>Area</th><th>Source</th><th>Confidence</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($canonicalData['equipment'] as $item)
                                <tr>
                                    <td>{{ $item['name'] ?? '—' }}</td>
                                    <td>{{ $item['quantity'] ?? '—' }}</td>
                                    <td>{{ $item['area'] ?? '—' }}</td>
                                    <td title="Source: {{ ucfirst(str_replace('_', ' ', $item['data_source'] ?? '')) }}">{{ $item['data_source'] ?? '—' }}</td>
                                    <td @if (($item['confidence'] ?? 1) < 0.7) class="conf-low" @endif>
                                        {{ number_format(($item['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="proj-cell--muted data-table--sm">No equipment data available.</p>
                    @endif

                    @if (! empty($canonicalData['rooms']))
                        <h3 class="data-subheading">Rooms ({{ count($canonicalData['rooms']) }})</h3>
                        <table class="data-table data-table--sm" style="margin-bottom:1.25rem;">
                            <thead>
                                <tr><th>Name</th><th>Source</th><th>Confidence</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($canonicalData['rooms'] as $room)
                                <tr>
                                    <td>{{ $room['name'] ?? '—' }}</td>
                                    <td title="Source: {{ ucfirst(str_replace('_', ' ', $room['data_source'] ?? '')) }}">{{ $room['data_source'] ?? '—' }}</td>
                                    <td @if (($room['confidence'] ?? 1) < 0.7) class="conf-low" @endif>
                                        {{ number_format(($room['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    <p class="data-conf-note">Low confidence threshold: &lt;70%. Fields highlighted in red need review.</p>
                </x-section-card>

            </div>{{-- end project data tab panel --}}

        </div>{{-- end tab wrapper --}}

        {{-- ── RAMS Documents ─────────────────────────────────────────────────── --}}
        @php
            $latestPackage      = $project->latestPackage ?: $project->packages()->latest()->first();
            $latestAwaitingRams = $project->ramsDocuments
                ->where('status', \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)
                ->sortByDesc('id')->first();
            $generatingRams     = $project->ramsDocuments
                ->whereIn('status', [
                    \App\Models\RamsDocument::STATUS_GENERATING,
                    \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                ])->first();
            $hasCompletedRams   = $project->ramsDocuments
                ->whereIn('status', [
                    \App\Models\RamsDocument::STATUS_COMPLETED,
                    \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                    \App\Models\RamsDocument::STATUS_DRAFT,
                ])->isNotEmpty();
        @endphp

        <x-section-card title="RAMS Documents ({{ $project->ramsDocuments->count() }})" :flush="true">
            <x-slot name="actions">
                @if ($latestAwaitingRams)
                    <x-actions.secondary-button :href="route('rams.quote-review.show', $latestAwaitingRams)">
                        ✎ Review &amp; Generate
                    </x-actions.secondary-button>
                @elseif ($generatingRams)
                    <span class="section-state-note">Processing…</span>
                @elseif ($hasCompletedRams)
                    <form method="POST" action="{{ route('rams.from-project', $project) }}" class="form-bare"
                          onsubmit="return confirm('Generate a new RAMS document from the current project data?');">
                        @csrf
                        <x-actions.secondary-button type="submit">+ New Version</x-actions.secondary-button>
                    </form>
                @elseif ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <form method="POST" action="{{ route('rams.from-project', $project) }}" class="form-bare">
                        @csrf
                        <x-actions.secondary-button type="submit">+ Create RAMS</x-actions.secondary-button>
                    </form>
                @elseif ($latestPackage)
                    <x-actions.secondary-button :href="route('project-packages.review.show', $latestPackage)">
                        ✎ Edit Project Data
                    </x-actions.secondary-button>
                @else
                    <x-actions.secondary-button :href="route('rams.create', ['project_id' => $project->id])">
                        + Create RAMS
                    </x-actions.secondary-button>
                @endif
            </x-slot>

            @if ($project->ramsDocuments->isEmpty())
                @if ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <x-empty-state title="No RAMS documents yet"
                        description="Project data has been reviewed and is ready for RAMS generation."/>
                @elseif ($latestPackage)
                    <x-empty-state title="No RAMS documents yet"
                        description="Review quote data to enable RAMS generation."
                        :href="route('project-packages.review.show', $latestPackage)"
                        action="Review Quote Data"/>
                @else
                    <x-empty-state title="No RAMS documents yet"
                        description="Create a RAMS manually using the project details."
                        :href="route('rams.create', ['project_id' => $project->id])"
                        action="Create RAMS"/>
                @endif
            @else
                @php
                    $ramsSorted = $project->ramsDocuments->sortByDesc('created_at')->values();
                    $versionMap = $project->ramsDocuments
                        ->sortBy('created_at')
                        ->values()
                        ->mapWithKeys(fn ($doc, $i) => [$doc->id => $i + 1]);
                @endphp
                <table class="data-table data-table--sm">
                    <thead>
                        <tr>
                            <th style="width:70px;">Ver.</th>
                            <th>Project / Ref</th>
                            <th>Status</th>
                            <th class="proj-cell--nowrap">Created</th>
                            <th style="min-width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ramsSorted as $rams)
                            @php
                                $status     = $rams->status;
                                $sup        = $rams->isSuperseded();
                                $isPipeline = $rams->isPipelineStatus();
                            @endphp
                            <tr class="{{ $sup ? 'proj-row--superseded' : '' }}">
                                <td class="tbl-ver-cell">v{{ $versionMap[$rams->id] ?? '—' }}</td>
                                <td>
                                    <strong>{{ $rams->project_name ?: '—' }}</strong>
                                    @if ($rams->project_ref)
                                        <br><small class="proj-cell--faint">{{ $rams->project_ref }}</small>
                                    @endif
                                    @if ($sup)
                                        <br><small class="tbl-cell--superseded">Superseded</small>
                                    @endif
                                </td>
                                <td>
                                    <x-status-badge :status="$status" />
                                </td>
                                <td class="proj-cell--faint proj-cell--nowrap">
                                    {{ $rams->created_at->format('d M Y') }}<br>
                                    <small>{{ $rams->created_at->format('H:i') }}</small>
                                </td>
                                <td>
                                    <div class="actions {{ $sup ? 'actions--disabled' : '' }}">
                                        @if ($status === \App\Models\RamsDocument::STATUS_APPROVED)
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="form-bare">
                                                @csrf
                                                <button type="submit" class="btn btn-teal btn-sm">▶ Generate</button>
                                            </form>

                                        @elseif (in_array($status, [
                                            \App\Models\RamsDocument::STATUS_UPLOADED,
                                            \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                            \App\Models\RamsDocument::STATUS_GENERATING,
                                        ], true))
                                            <span class="section-state-note">Processing…</span>

                                        @elseif ($status === \App\Models\RamsDocument::STATUS_COMPLETED && $rams->filename)
                                            <a href="{{ route('rams.download', $rams) }}" class="btn btn-outline btn-sm">↓ .docx</a>
                                            <a href="{{ route('rams.download-pdf', $rams) }}"
                                               class="btn btn-outline btn-sm"
                                               onclick="triggerFileDownload(this.href); return false;">↓ PDF</a>
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="form-bare">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>

                                        @elseif ($status === \App\Models\RamsDocument::STATUS_FAILED)
                                            <span class="tbl-cell--error">⚠ Failed</span>
                                            @if (! empty($rams->reviewed_data))
                                                <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="form-bare">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm">↺ Retry</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('rams.retry-extraction', $rams) }}" class="form-bare">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm">↺ Retry</button>
                                                </form>
                                            @endif

                                        @elseif ($rams->filename && in_array($status, [
                                            \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                            \App\Models\RamsDocument::STATUS_DRAFT,
                                        ], true))
                                            <a href="{{ route('rams.download', $rams) }}" class="btn btn-outline btn-sm">↓ .docx</a>
                                            <a href="{{ route('rams.download-pdf', $rams) }}"
                                               class="btn btn-outline btn-sm"
                                               onclick="triggerFileDownload(this.href); return false;">↓ PDF</a>
                                            <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="form-bare">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>
                                        @endif

                                        {{-- Delete (soft) --}}
                                        <form method="POST"
                                              action="{{ route('rams.destroy', $rams) }}"
                                              class="form-bare"
                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-section-card>

        {{-- ── O&M Manuals ─────────────────────────────────────────────────────── --}}
        @php
            $latestPackage  = $project->latestPackage ?: $project->packages()->latest()->first();
            $generatingOm   = $project->omManuals->where('status', \App\Models\OmManual::STATUS_GENERATING)->first();
            $hasCompletedOm = $project->omManuals
                ->whereIn('status', [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])
                ->isNotEmpty();
        @endphp

        <x-section-card title="O&M Manuals ({{ $project->omManuals->count() }})" :flush="true">
            <x-slot name="actions">
                <x-actions.secondary-button :href="route('om-manuals.create', ['project_id' => $project->id])">
                    + New O&M
                </x-actions.secondary-button>
                @if ($generatingOm)
                    <span class="section-state-note">Processing…</span>
                @elseif ($hasCompletedOm)
                    <x-actions.secondary-button :href="route('quote-import.create', ['project_id' => $project->id])">
                        + New Version
                    </x-actions.secondary-button>
                @elseif ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" class="form-bare">
                        @csrf
                        <x-actions.secondary-button type="submit">+ Create O&M</x-actions.secondary-button>
                    </form>
                @elseif ($latestPackage)
                    <x-actions.secondary-button :href="route('project-packages.review.show', $latestPackage)">
                        Review Quote Data
                    </x-actions.secondary-button>
                @endif
            </x-slot>

            @if ($project->omManuals->isEmpty())
                @if ($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <x-empty-state title="No O&M manuals yet"
                        description="Project data is reviewed and ready for O&M generation."/>
                @elseif ($latestPackage)
                    <x-empty-state title="No O&M manuals yet"
                        description="Review quote data to enable O&M generation."
                        :href="route('project-packages.review.show', $latestPackage)"
                        action="Review Quote Data"/>
                @else
                    <x-empty-state title="No O&M manuals yet"
                        description="Upload a quote in Quote History to enable O&M generation."/>
                @endif
            @else
                <table class="data-table data-table--sm">
                    <thead>
                        <tr>
                            <th>Manual</th>
                            <th>Status</th>
                            <th class="proj-cell--nowrap">Created</th>
                            <th style="min-width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->omManuals->sortByDesc('created_at') as $manual)
                        <tr>
                            <td>
                                <strong>{{ $manual->project_name ?? 'O&M Manual #' . $manual->id }}</strong>
                                @if ($manual->project_ref)
                                    <br><small class="proj-cell--faint">{{ $manual->project_ref }}</small>
                                @endif
                            </td>
                            <td>
                                <x-status-badge :status="$manual->status" :label="$manual->statusLabel()" />
                            </td>
                            <td class="proj-cell--faint proj-cell--nowrap">
                                {{ $manual->created_at->format('d M Y') }}<br>
                                <small>{{ $manual->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div class="actions">
                                    @if ($manual->status === \App\Models\OmManual::STATUS_GENERATING)
                                        <span class="section-state-note">Processing…</span>

                                    @elseif ($manual->status === \App\Models\OmManual::STATUS_FAILED)
                                        <span class="tbl-cell--error" title="{{ $manual->error_message }}">⚠ Failed</span>
                                        @if (! empty($manual->extracted_data))
                                            <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" class="form-bare">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm">↺ Retry</button>
                                            </form>
                                        @endif

                                    @elseif ($manual->isGenerated())
                                        <a href="{{ route('om-manuals.download', $manual) }}" class="btn btn-outline btn-sm">↓ .docx</a>
                                        <a href="{{ route('om-manuals.download-pdf', $manual) }}" class="btn btn-outline btn-sm">↓ PDF</a>
                                        <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" class="form-bare">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-sm"
                                                    onclick="return confirm('Rebuild this O&M manual from the existing data?');">↺ Regen</button>
                                        </form>

                                    @elseif ($manual->status === \App\Models\OmManual::STATUS_EXTRACTED)
                                        <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-teal btn-sm">✎ Review</a>

                                    @else
                                        <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-outline btn-sm">View</a>
                                    @endif

                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual) }}"
                                          class="form-bare"
                                          onsubmit="return confirm('Delete this O&M manual?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-section-card>

        {{-- ── Site Surveys ─────────────────────────────────────────────────────── --}}
        @php $latestSurvey = $project->siteSurveys->sortByDesc('created_at')->first(); @endphp

        <x-section-card title="Site Surveys ({{ $project->siteSurveys->count() }})" :flush="true">
            <x-slot name="actions">
                <x-actions.secondary-button :href="route('site-surveys.from-project', $project)">
                    + Create Survey
                </x-actions.secondary-button>
            </x-slot>

            @if ($project->siteSurveys->isEmpty())
                <x-empty-state title="No site surveys yet"
                    description="Create a survey to share a pre-filled form with your on-site engineer."
                    :href="route('site-surveys.from-project', $project)"
                    action="Create Survey"/>
            @else
                <table class="data-table data-table--sm">
                    <thead>
                        <tr>
                            <th>Survey</th>
                            <th>Status</th>
                            <th class="proj-cell--nowrap">Created</th>
                            <th style="min-width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->siteSurveys->sortByDesc('created_at') as $survey)
                        <tr>
                            <td>
                                <strong>{{ 'Site Survey #' . $survey->id }}</strong>
                                @if ($survey->surveyor_name)
                                    <br><small class="proj-cell--faint">By: {{ $survey->surveyor_name }}</small>
                                @endif
                                @if ($survey->survey_date)
                                    <br><small class="proj-cell--faint">{{ $survey->survey_date->format('d M Y') }}</small>
                                @endif
                            </td>
                            <td>
                                <x-status-badge
                                    :status="$survey->status ?? 'draft'"
                                    :label="ucfirst($survey->status ?? 'draft') . ($survey->isSubmitted() ? ' · Submitted' : '')" />
                            </td>
                            <td class="proj-cell--faint proj-cell--nowrap">
                                {{ $survey->created_at->format('d M Y') }}<br>
                                <small>{{ $survey->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div class="actions">
                                    @if ($survey->access_token && ! $survey->isTokenExpired())
                                        <button type="button"
                                                class="btn btn-outline btn-sm"
                                                onclick="copyEngineerLink('{{ $survey->publicUrl() }}', this)"
                                                title="{{ $survey->publicUrl() }}">
                                            🔗 Copy Link
                                        </button>
                                    @endif
                                    <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">👁 View</a>
                                    @if (! $survey->isCompleted())
                                        <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">✎ Edit</a>
                                        <form method="POST" action="{{ route('site-surveys.complete', $survey) }}"
                                              class="form-bare"
                                              onsubmit="return confirm('Mark this survey as completed?');">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-sm">✓ Complete</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
                                          class="form-bare"
                                          onsubmit="return confirm('Delete this survey?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-section-card>

        {{-- Copy-to-clipboard for engineer links --}}
        <script>
        function copyEngineerLink(url, btn) {
            const orig = btn.textContent;
            const showSuccess = () => {
                btn.textContent = '✓ Copied!';
                btn.style.background = '#059669';
                setTimeout(() => { btn.textContent = orig; btn.style.background = ''; }, 2500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(showSuccess).catch(() => {
                    fallbackCopyText(url); showSuccess();
                });
            } else {
                fallbackCopyText(url); showSuccess();
            }
        }
        function fallbackCopyText(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch(e) {}
            document.body.removeChild(ta);
        }
        </script>

        {{-- Cable Schedules are shown in the Linked Records table above --}}

        {{-- ── Activity Log ──────────────────────────────────────────────────────── --}}
        <x-section-card title="Activity Log">
            @if ($project->activityLog->isEmpty())
                <p class="proj-cell--muted">No activity recorded yet.</p>
            @else
                <ul class="activity-list">
                    @foreach ($project->activityLog->take(20) as $entry)
                    <li class="activity-item">
                        <span class="activity-item__date">{{ $entry->created_at->format('d M Y H:i') }}</span>
                        <span class="activity-item__desc">{{ $entry->description }}</span>
                    </li>
                    @endforeach
                </ul>
            @endif
        </x-section-card>

    </div>{{-- /left column --}}

    {{-- ────────────────────────────────────────────────────────────────────── --}}
    {{-- RIGHT COLUMN                                                            --}}
    {{-- ────────────────────────────────────────────────────────────────────── --}}
    <div>

        {{-- Project Details --}}
        <x-section-card title="Project Details">
            <x-slot name="actions">
                <x-actions.secondary-button :href="route('projects.edit', $project)">
                    Edit
                </x-actions.secondary-button>
            </x-slot>

            <dl class="details-list">
                <dt>Status</dt>
                <dd><x-status-badge :status="$project->status" /></dd>
                <dt>Client</dt>
                <dd>{{ $project->client_name }}</dd>
                <dt>Site</dt>
                <dd>{{ $project->site_address ?? '—' }}</dd>
                <dt>Quote ref</dt>
                <dd>{{ $project->quote_reference ?? $project->ref ?? '—' }}</dd>
                @if ($project->works_description)
                <dt>Scope</dt>
                <dd>{{ $project->works_description }}</dd>
                @endif
                @if ($project->notes)
                <dt>Notes</dt>
                <dd class="proj-cell--muted">{{ $project->notes }}</dd>
                @endif
                <dt>Created</dt>
                <dd>{{ $project->created_at->format('d M Y') }}</dd>
                <dt>Updated</dt>
                <dd class="proj-cell--faint">{{ $project->updated_at->diffForHumans() }}</dd>
                @if ($project->reopened_at)
                <dt>Reopened</dt>
                <dd>
                    {{ $project->reopened_at->format('d M Y') }}<br>
                    <span class="proj-cell--muted details-list__reopen-reason">{{ $project->reopen_reason }}</span>
                </dd>
                @endif
            </dl>
        </x-section-card>

        {{-- Document counts --}}
        <x-section-card title="Documents">
            <div class="doc-counts-grid">
                <div class="doc-count-item">
                    <div class="doc-count-item__label">Quotes</div>
                    <div class="doc-count-item__value">{{ $project->projectQuotes->count() }}</div>
                </div>
                <div class="doc-count-item">
                    <div class="doc-count-item__label">RAMS</div>
                    <div class="doc-count-item__value">{{ $project->ramsDocuments->count() }}</div>
                </div>
                <div class="doc-count-item">
                    <div class="doc-count-item__label">O&M</div>
                    <div class="doc-count-item__value">{{ $project->omManuals->count() }}</div>
                </div>
                <div class="doc-count-item">
                    <div class="doc-count-item__label">Surveys</div>
                    <div class="doc-count-item__value">{{ $project->siteSurveys->count() }}</div>
                </div>
            </div>
        </x-section-card>

        {{-- Quick Actions --}}
        @php $latestPackage = $project->latestPackage ?: $project->packages()->latest()->first(); @endphp
        @if ($latestPackage)
        <x-section-card title="Quick Actions">
            <div class="quick-actions">
                @if ($latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                    <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" class="form-bare">
                        @csrf
                        <x-actions.secondary-button type="submit" class="btn-full">
                            + Create O&M Manual
                        </x-actions.secondary-button>
                    </form>
                @else
                    <x-actions.secondary-button :href="route('project-packages.review.show', $latestPackage)" class="btn-full">
                        Review Quote Data
                    </x-actions.secondary-button>
                @endif
            </div>
        </x-section-card>
        @endif

        {{-- Danger zone: only shown when archived --}}
        @if ($project->isArchived())
        <div class="section-block danger-zone">
            <h2 class="section-card__title danger-zone__title">Danger Zone</h2>
            <p class="proj-cell--muted danger-zone__desc">
                Permanently delete this project and all associated data. This cannot be undone.
            </p>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="form-bare">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Permanently delete project &quot;{{ addslashes($project->name) }}&quot;? This cannot be undone.')">
                    Delete Project
                </button>
            </form>
        </div>
        @endif

    </div>{{-- /right column --}}

</div>{{-- /proj-show-grid --}}

</x-app-shell>

@push('styles')
<style>
/* ── Grid layout ─────────────────────────────────────────────────────────── */
.proj-show-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.25rem;
    align-items: start;
}

/* ── Lifecycle bar ───────────────────────────────────────────────────────── */
.lifecycle-bar {
    display: flex;
    align-items: center;
    overflow-x: auto;
    padding-bottom: .25rem;
}
.lifecycle-step             { display: flex; align-items: center; flex-shrink: 0; }
.lifecycle-step__connector  { width: 18px; height: 1px; background: #ddd; flex-shrink: 0; }

/* ── Tab strip ───────────────────────────────────────────────────────────── */
.proj-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
    margin-bottom: 1rem;
}
.proj-tab {
    padding: .75rem 1rem;
    border: none;
    background: none;
    cursor: pointer;
    font-size: .9375rem;
    font-weight: 500;
    color: var(--text-muted);
    border-bottom: 2px solid transparent;
    transition: color var(--transition), border-color var(--transition);
}
.proj-tab--active { border-bottom-color: var(--teal); color: var(--teal); font-weight: 600; }
.proj-tab:hover   { color: var(--teal); }

/* ── Linked records ──────────────────────────────────────────────────────── */
.linked-records__group              { border-bottom: 2px solid var(--border); }
.linked-records__group:last-child   { border-bottom: none; }

/* Section type header bar — brand gold */
.lr-section-hdr {
    display: flex;
    align-items: center;
    padding: .45rem 1.25rem;
    background: #C9922A;
    border-bottom: 1px solid #A87620;
}
.lr-section-hdr__badge {
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: .2rem .6rem;
    background: transparent;
    border: none;
    color: #1A1A1A;
    box-shadow: none;
}

/* Table inside each group — no top border (header bar is the divider) */
.lr-table thead th {
    background: #fff;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-muted);
    padding: .45rem 1rem;
    border-bottom: 1px solid var(--border);
}
.lr-table tbody td { padding: .7rem 1rem; }

/* Empty row */
.lr-empty-row td   { color: var(--text-muted); }
.lr-empty-ref      { font-style: italic; font-size: .84rem; }

/* Action buttons — uniform min-width so they line up */
.lr-actions        { white-space: nowrap; }
.lr-btn            { min-width: 72px; text-align: center; }

/* ── Table helpers ───────────────────────────────────────────────────────── */
.data-table--sm         { font-size: .84rem; }
.tbl-ver-cell           { text-align: center; font-weight: 700; color: var(--teal); }
.tbl-cell--mono         { font-family: monospace; font-size: .78rem; }
.tbl-cell--superseded   { color: var(--danger); font-size: .72rem; }
.tbl-cell--error        { font-size: .78rem; color: #991b1b; }
.proj-cell--muted       { color: var(--text-muted); }
.proj-cell--faint       { color: var(--text-faint); font-size: .85rem; }
.proj-cell--nowrap      { white-space: nowrap; }
.proj-row--superseded   { opacity: .45; }

/* ── Form bare (removes browser default form margin) ────────────────────── */
.form-bare              { margin: 0; }
.form-bare--inline      { display: inline-block; }

/* ── Actions ─────────────────────────────────────────────────────────────── */
.actions--disabled      { pointer-events: none; }

/* ── Section state note (Processing…, info text) ─────────────────────────── */
.section-state-note     { font-size: .78rem; color: var(--text-muted); font-style: italic; }

/* ── Spinner ─────────────────────────────────────────────────────────────── */
.spin-icon {
    display: inline-block;
    width: 14px; height: 14px;
    vertical-align: middle;
    margin-right: .25rem;
    animation: spin 1s linear infinite;
}
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* ── Canonical data ──────────────────────────────────────────────────────── */
.data-source-note  { font-size: .8125rem; color: var(--text-muted); margin-bottom: 1rem; }
.data-subheading   { font-size: .8125rem; font-weight: 600; color: var(--text); margin: 1rem 0 .5rem; }
.data-conf-note    { font-size: .75rem; color: var(--text-faint); margin-top: .75rem; }
.conf-low          { color: var(--danger); font-weight: 600; }

/* ── Reopen form ─────────────────────────────────────────────────────────── */
.reopen-form {
    display: flex;
    gap: .5rem;
    align-items: flex-end;
    flex-wrap: wrap;
}
.reopen-form__btn { flex-shrink: 0; }

/* ── Project details list ────────────────────────────────────────────────── */
.details-list {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .4rem .75rem;
    font-size: .85rem;
}
.details-list dt                    { color: var(--text-muted); font-weight: 600; }
.details-list dd                    { color: var(--text); }
.details-list__reopen-reason        { font-size: .8rem; }

/* ── Document count grid ─────────────────────────────────────────────────── */
.doc-counts-grid    { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.doc-count-item {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .65rem .75rem;
    text-align: center;
}
.doc-count-item__label { font-size: .72rem; font-weight: 700; color: var(--teal); text-transform: uppercase; letter-spacing: .04em; }
.doc-count-item__value { font-size: 1.3rem; font-weight: 700; color: var(--text); }

/* ── Quick actions ───────────────────────────────────────────────────────── */
.quick-actions { display: flex; flex-direction: column; gap: .5rem; }

/* ── Danger zone ─────────────────────────────────────────────────────────── */
.danger-zone            { border-left: 3px solid var(--danger); }
.danger-zone__title     { color: var(--danger); margin-bottom: .5rem; }
.danger-zone__desc      { font-size: .8rem; margin-bottom: .75rem; }

/* ── Activity log ────────────────────────────────────────────────────────── */
.activity-list          { list-style: none; padding: 0; margin: 0; }
.activity-item {
    display: flex;
    gap: .75rem;
    padding: .55rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: .84rem;
}
.activity-item:last-child   { border-bottom: none; }
.activity-item__date        { color: var(--text-muted); white-space: nowrap; padding-top: 1px; min-width: 110px; }
.activity-item__desc        { color: var(--text); }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .proj-show-grid { grid-template-columns: 1fr; }
}
</style>
@endpush

@endsection
