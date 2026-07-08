@extends('layouts.app')

@section('title', 'Document History — ' . ucfirst($document_type) . ' #' . $document_id)

@section('content')

{{-- Audit D-06 (2026-07-08) — was 10-line local stylesheet with hardcoded
     #0B3C45 / #6B7280 / #E0E7FF / #D1FAE5 / #FEF3C7 hex values. Reused
     shared .page-header + .card + .data-table + .badge chrome — every
     colour flows through tokens now. --}}

<style>
    .docrev-src {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px; font-weight: 500;
        letter-spacing: -0.005em;
        border: 1px solid transparent;
    }
    .docrev-src::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
    .docrev-src--base    { background: var(--brand-50);   color: var(--brand-700);   border-color: var(--brand-100); }
    .docrev-src--ai_chat { background: var(--success-light); color: var(--success);
                           border-color: color-mix(in oklab, var(--success) 30%, transparent); }
    .docrev-src--manual  { background: var(--warning-light); color: #92400E;
                           border-color: color-mix(in oklab, var(--warning) 30%, transparent); }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Document History</h1>
        <div class="page-subtitle">
            <span style="font-family:var(--font-mono);">{{ strtoupper($document_type) }} #{{ $document_id }}</span>
            · {{ $revisions->count() }} revision{{ $revisions->count() === 1 ? '' : 's' }}
        </div>
    </div>
</div>

@if($revisions->isEmpty())
    <x-empty-state
        heading="No revisions yet"
        body="The first revision is captured the moment a chat-edit thread is opened on this document." />
@else
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:72px;">#</th>
                    <th>Source</th>
                    <th>Summary</th>
                    <th>Artifact</th>
                    <th style="width:140px;">Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($revisions as $r)
                    <tr>
                        <td style="color:var(--muted);font-variant-numeric:tabular-nums;">{{ $r->id }}</td>
                        <td><span class="docrev-src docrev-src--{{ $r->source }}">{{ strtoupper(str_replace('_', ' ', $r->source)) }}</span></td>
                        <td style="color:var(--body);">{{ $r->change_summary ?? '—' }}</td>
                        <td style="font-family:var(--font-mono);font-size:11px;color:var(--text-muted);">{{ $r->artifact_filename ?? '—' }}</td>
                        <td style="color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums;">{{ $r->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
