@extends('layouts.app')

@section('title', 'Worksheets')

@section('content')

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Worksheets</h1>
        <div class="page-subtitle">Client-facing sign-off worksheets generated from a project's install scope.</div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if($worksheets->isEmpty())

    <x-dashboard.empty-state
        heading="No worksheets yet"
        body="Worksheets are generated from a project. Open a project and click Generate Worksheet."
    />

@else

    <div class="card" style="padding:0;overflow:hidden;">
        <x-dashboard.table-wrapper>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($worksheets as $w)
                        <tr>
                            <td>
                                @if($w->project)
                                    <a href="{{ route('projects.show', $w->project) }}" style="color:var(--teal);text-decoration:none;">
                                        {{ $w->project->name }}
                                    </a>
                                    @if($w->project_ref)
                                        <br><small style="color:var(--text-faint);">{{ $w->project_ref }}</small>
                                    @endif
                                @else
                                    <span style="color:var(--text-muted);">{{ $w->project_name }}</span>
                                    @if($w->project_ref)
                                        <br><small style="color:var(--text-faint);">{{ $w->project_ref }}</small>
                                    @endif
                                @endif
                                {{-- Stale-data pill (260602-o2a) — renders only when stale --}}
                                <div style="margin-top:.25rem;">
                                    @include('worksheets._stale-banner', ['worksheet' => $w, 'variant' => 'pill'])
                                </div>
                            </td>
                            <td>{{ $w->client_name ?? '—' }}</td>
                            <td>
                                <x-status-badge :status="$w->status" />
                            </td>
                            <td style="color:var(--text-faint);font-size:.875rem;">
                                {{ $w->updated_at->diffForHumans() }}
                            </td>
                            <td class="actions">
                                <a href="{{ route('worksheets.show', $w) }}" class="btn-outline btn-sm">View</a>
                                @if(in_array($w->status, ['draft', 'final']))
                                    <a href="{{ route('worksheets.download', $w) }}"
                                       class="btn-teal btn-sm"
                                       target="_blank"
                                       aria-label="Download Worksheet DOCX">↓ Download</a>
                                @endif
                                @if(in_array($w->status, ['draft', 'final', 'failed']))
                                    <form method="POST"
                                          action="{{ route('worksheets.retry-generation', $w) }}"
                                          data-confirm="Regenerate this worksheet? The current DOCX will be replaced."
                                          data-confirm-label="Regenerate"
                                          style="display:inline;">
                                        @csrf
                                        <button type="submit"
                                                class="btn-outline btn-sm"
                                                aria-label="Regenerate Worksheet DOCX"
                                                title="Regenerate">↻</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-dashboard.table-wrapper>
    </div>

    <div style="margin-top:1.25rem;">
        {{ $worksheets->links() }}
    </div>

@endif

@endsection
