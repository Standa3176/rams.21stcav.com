@extends('layouts.app')

@section('title', $project->name)

@section('content')

@php
    $colour     = $project->status_colour;
    $lifecycle  = \App\Models\Project::LIFECYCLE;
    $currentIdx = array_search($project->status, $lifecycle);
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $project->name }}</h1>
        <p style="color:#666; font-size:.875rem; margin-top:.25rem;">
            {{ $project->client_name }} &nbsp;·&nbsp; {{ $project->site_address }}
            @if($project->ref) &nbsp;·&nbsp; Ref: <strong>{{ $project->ref }}</strong> @endif
        </p>
    </div>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm">↑ Upload Quote</a>
        <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline btn-sm">Edit</a>
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
<div style="display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start;">

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
                <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm" style="font-size:.78rem;">
                    ↑ Upload New Quote
                </a>
            </div>

            @if ($project->projectQuotes->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No quotes uploaded yet.
                    <a href="{{ route('rams.upload.create') }}" style="color:var(--teal);">Upload a quote PDF</a> to get started.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th style="width:50px;">Ver.</th>
                            <th>Original File</th>
                            <th>Quote Ref</th>
                            <th>Client</th>
                            <th style="white-space:nowrap;">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->projectQuotes->sortByDesc('version_number') as $pq)
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

        {{-- ── RAMS Documents ──────────────────────────────────────────────── --}}
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    RAMS Documents
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->ramsDocuments->count() }}
                    </span>
                </h2>
                <div style="display:flex; gap:.5rem;">
                    <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm" style="font-size:.78rem;">↑ Upload Quote</a>
                    <a href="{{ route('rams.create') }}" class="btn btn-teal btn-sm" style="font-size:.78rem;">+ New RAMS</a>
                </div>
            </div>

            @if ($project->ramsDocuments->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No RAMS documents yet.
                    <a href="{{ route('rams.upload.create') }}" style="color:var(--teal);">Upload a quote PDF</a>
                    or <a href="{{ route('rams.create') }}" style="color:var(--teal);">create a RAMS manually</a>.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Project / Ref</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project->ramsDocuments->sortByDesc('created_at') as $rams)
                            @php
                                $status      = $rams->status;
                                $sup         = $rams->isSuperseded();
                                $isPipeline  = $rams->isPipelineStatus();
                            @endphp
                            <tr style="{{ $sup ? 'opacity:.45;' : '' }}">
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
                                    <div style="display:flex; gap:.35rem; flex-wrap:wrap; {{ $sup ? 'pointer-events:none;' : '' }}">
                                        {{-- Review button — shown when awaiting review --}}
                                        @if ($status === \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)
                                            <a href="{{ route('rams.quote-review.show', $rams) }}"
                                               class="btn btn-teal btn-sm" style="font-size:.75rem;">
                                                ✎ Review
                                            </a>
                                        @elseif (in_array($status, [
                                            \App\Models\RamsDocument::STATUS_UPLOADED,
                                            \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                            \App\Models\RamsDocument::STATUS_GENERATING,
                                        ], true))
                                            <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>
                                        @elseif ($rams->filename && in_array($status, [
                                            \App\Models\RamsDocument::STATUS_COMPLETED,
                                            \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                            \App\Models\RamsDocument::STATUS_DRAFT,
                                            \App\Models\RamsDocument::STATUS_APPROVED,
                                        ], true))
                                            <a href="{{ route('rams.download', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                            <a href="{{ route('rams.download-pdf', $rams) }}"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                        @elseif ($status === \App\Models\RamsDocument::STATUS_FAILED)
                                            <span style="font-size:.78rem; color:#991b1b;">⚠ Failed</span>
                                        @endif
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
                {{-- project_id pre-selects this project in the O&M create form --}}
                <a href="{{ route('om-manuals.create', ['project_id' => $project->id]) }}"
                   class="btn btn-teal btn-sm" style="font-size:.78rem;">
                    + New O&amp;M
                </a>
            </div>

            @if ($project->omManuals->isEmpty())
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No O&amp;M manuals yet.
                    <a href="{{ route('om-manuals.create', ['project_id' => $project->id]) }}"
                       style="color:var(--teal);">Create an O&amp;M manual</a> for this project.
                </p>
            @else
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Manual</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:160px;">Actions</th>
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
                                <span class="badge badge-grey" style="font-size:.75rem;">
                                    {{ $manual->statusLabel() }}
                                </span>
                            </td>
                            <td style="color:#888; white-space:nowrap;">
                                {{ $manual->created_at->format('d M Y') }}<br>
                                <small>{{ $manual->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div style="display:flex; gap:.35rem; flex-wrap:wrap;">
                                    @if ($manual->isGenerated())
                                        <a href="{{ route('om-manuals.download', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                        <a href="{{ route('om-manuals.download-pdf', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                    @elseif ($manual->status === 'extracted')
                                        <a href="{{ route('om-manuals.edit', $manual) }}"
                                           class="btn btn-teal btn-sm" style="font-size:.75rem;">✎ Review</a>
                                    @else
                                        <a href="{{ route('om-manuals.edit', $manual) }}"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Site Surveys ────────────────────────────────────────────────── --}}
        @if ($project->siteSurveys->isNotEmpty())
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Site Surveys
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        {{ $project->siteSurveys->count() }}
                    </span>
                </h2>
            </div>
            <table class="data-table" style="font-size:.84rem;">
                <thead>
                    <tr>
                        <th>Survey</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($project->siteSurveys->sortByDesc('created_at') as $survey)
                    <tr>
                        <td>{{ $survey->name ?? 'Site Survey #' . $survey->id }}</td>
                        <td><span class="badge badge-grey">{{ ucfirst($survey->status ?? 'draft') }}</span></td>
                        <td style="color:#888; white-space:nowrap;">{{ $survey->created_at->format('d M Y') }}</td>
                        <td>
                            <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

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
                <dt style="color:#888; font-weight:600;">Ref</dt>
                <dd>{{ $project->ref ?? '—' }}</dd>
                <dt style="color:#888; font-weight:600;">Client</dt>
                <dd>{{ $project->client_name }}</dd>
                <dt style="color:#888; font-weight:600;">Site</dt>
                <dd>{{ $project->site_address }}</dd>
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
                <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm" style="text-align:center;">
                    ↑ Upload Quote PDF
                </a>
                <a href="{{ route('om-manuals.create', ['project_id' => $project->id]) }}"
                   class="btn btn-outline btn-sm" style="text-align:center;">
                    + New O&amp;M Manual
                </a>
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

@endsection
