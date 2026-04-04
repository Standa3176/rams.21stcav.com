@extends('layouts.app')

@section('title', 'O&M Manuals')

@push('styles')
<style>
/* Status badges ─────────────────────────────────────────────────────── */
.status-badge {
    display: inline-block;
    padding: .2rem .55rem;
    border-radius: 3px;
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.status-extracted { background: #fff3cd; color: #856404; }
.status-draft     { background: #e0f4f6; color: #007B8A; }
.status-final     { background: #d4edda; color: #155724; }

/* Step indicator ────────────────────────────────────────────────────── */
.step-pill {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .75rem;
    color: #666;
    padding: .15rem .5rem;
    border-radius: 10px;
    border: 1px solid #ddd;
    background: #fafafa;
}
.step-pill.done { color: #155724; border-color: #28a745; background: #d4edda; }
</style>
@endpush

@section('content')

    {{-- Page header --}}
    <div class="page-header">
        <h1 class="page-title">O&amp;M Manuals</h1>
        <a href="{{ route('om-manuals.create') }}" class="btn btn-teal">
            + New O&amp;M Manual
        </a>
    </div>

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    @if ($isAdmin)
        <div class="alert alert-info"><strong>Admin view</strong> — showing all users' O&amp;M manuals.</div>
    @endif

    {{-- Table --}}
    <div class="card" style="padding:0;overflow:hidden;">
        @if ($manuals->isEmpty())
            <div class="empty-state">
                <h3>No O&amp;M Manuals yet</h3>
                <p>Upload a QuoteWerks PDF to generate your first Operation &amp; Maintenance Manual.</p>
                <a href="{{ route('om-manuals.create') }}" class="btn btn-teal">+ New O&amp;M Manual</a>
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        @if ($isAdmin) <th>Owner</th> @endif
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Created</th>
                        <th style="min-width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($manuals as $manual)
                    <tr>
                        <td>
                            <strong>{{ $manual->project_name }}</strong>
                            @if ($manual->project_ref)
                                <br><small style="color:#666;">{{ $manual->project_ref }}</small>
                            @endif
                        </td>
                        <td>{{ $manual->client_name ?? '—' }}</td>
                        @if ($isAdmin)
                            <td>
                                {{ $manual->user?->name ?? '—' }}<br>
                                <small style="color:#999;font-size:.75rem;">{{ $manual->user?->email ?? '' }}</small>
                            </td>
                        @endif
                        <td>
                            <span class="status-badge status-{{ $manual->status }}">
                                {{ $manual->statusLabel() }}
                            </span>
                        </td>

                        {{-- Progress steps --}}
                        <td>
                            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                                <span class="step-pill done">✓ Extracted</span>
                                <span class="step-pill {{ $manual->generated_data ? 'done' : '' }}">
                                    {{ $manual->generated_data ? '✓' : '○' }} Generated
                                </span>
                                <span class="step-pill {{ $manual->filename ? 'done' : '' }}">
                                    {{ $manual->filename ? '✓' : '○' }} .docx
                                </span>
                            </div>
                        </td>

                        <td style="white-space:nowrap;">
                            {{ $manual->created_at->format('d M Y') }}<br>
                            <small style="color:#999;">{{ $manual->created_at->format('H:i') }}</small>
                        </td>

                        {{-- Actions --}}
                        <td>
                            <div class="actions" style="flex-wrap:wrap;gap:.3rem;">

                                {{-- Review/Edit extracted data --}}
                                @if (! $manual->generated_data)
                                    <a href="{{ route('om-manuals.edit', $manual) }}"
                                       class="btn btn-outline btn-sm" title="Review extracted equipment">
                                        ✎ Review
                                    </a>
                                @endif

                                {{-- Generate (Pass 2) --}}
                                @if (! $manual->generated_data || ! $manual->filename)
                                    <form method="POST"
                                          action="{{ route('om-manuals.generate', $manual) }}"
                                          onsubmit="return confirm('Generate the O&M Manual? This may take up to 2 minutes.');"
                                          style="margin:0;">
                                        @csrf
                                        <button type="submit" class="btn btn-teal btn-sm" title="Run AI generation">
                                            ⚡ Generate
                                        </button>
                                    </form>
                                @endif

                                {{-- Download .docx --}}
                                @if ($manual->filename)
                                    <a href="{{ route('om-manuals.download', $manual) }}"
                                       class="btn btn-outline btn-sm" title="Download Word document">
                                        ↓ .docx
                                    </a>
                                @endif

                                {{-- Download PDF --}}
                                @if ($manual->generated_data)
                                    <a href="{{ route('om-manuals.download-pdf', $manual) }}"
                                       class="btn btn-outline btn-sm" title="Download PDF">
                                        ↓ PDF
                                    </a>
                                @endif

                                {{-- Delete --}}
                                <form method="POST"
                                      action="{{ route('om-manuals.destroy', $manual) }}"
                                      onsubmit="return confirm('Delete this O&M Manual? This cannot be undone.');"
                                      style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">
                                        ✕
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($manuals->hasPages())
                <div class="pagination-wrap" style="padding:1rem;">
                    {{ $manuals->links() }}
                </div>
            @endif
        @endif
    </div>

@endsection
