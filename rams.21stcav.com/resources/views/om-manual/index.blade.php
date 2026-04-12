@extends('layouts.app')

@section('title', 'O&M Manuals')

@section('content')
<div class="container">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem;">
        <h1 style="font-size:1.4rem; font-weight:700; margin:0;">O&amp;M Manuals</h1>
        <a href="{{ route('om-manuals.create') }}" class="btn btn-teal btn-sm">+ New O&amp;M Manual</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success" style="margin-bottom:1rem;">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" style="margin-bottom:1rem;">{{ session('error') }}</div>
    @endif

    @if ($manuals->isEmpty())
        <p style="color:#888;">No O&amp;M manuals yet. <a href="{{ route('om-manuals.create') }}" style="color:var(--teal);">Create one</a>.</p>
    @else
        <div class="card" style="padding:0; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; font-size:.9rem;">
                <thead>
                    <tr style="background:#f8f8f8; border-bottom:2px solid var(--border);">
                        <th style="padding:.6rem 1rem; text-align:left;">Project</th>
                        <th style="padding:.6rem 1rem; text-align:left;">Client</th>
                        <th style="padding:.6rem 1rem; text-align:left;">Status</th>
                        <th style="padding:.6rem 1rem; text-align:left;">Created</th>
                        <th style="padding:.6rem 1rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($manuals as $manual)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:.6rem 1rem;">
                                {{ $manual->project_name ?? '—' }}
                            </td>
                            <td style="padding:.6rem 1rem;">{{ $manual->client_name ?? '—' }}</td>
                            <td style="padding:.6rem 1rem;">
                                <span class="badge {{ $manual->statusBadgeClass() }}">{{ $manual->statusLabel() }}</span>
                            </td>
                            <td style="padding:.6rem 1rem; color:#888; font-size:.85rem;">
                                {{ $manual->created_at->diffForHumans() }}
                            </td>
                            <td style="padding:.6rem 1rem; text-align:right;">
                                @if (in_array($manual->status, [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL]))
                                    <a href="{{ route('om-manuals.download', $manual) }}" class="btn btn-outline btn-sm">Download</a>
                                @elseif ($manual->status === \App\Models\OmManual::STATUS_EXTRACTED)
                                    <a href="{{ route('om-manuals.edit', $manual) }}" class="btn btn-teal btn-sm">Review</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding:.75rem 1rem;">
                {{ $manuals->links() }}
            </div>
        </div>
    @endif
</div>
@endsection
