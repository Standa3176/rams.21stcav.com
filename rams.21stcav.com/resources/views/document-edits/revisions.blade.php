@extends('layouts.app')

@section('title', 'Document History — ' . ucfirst($document_type) . ' #' . $document_id)

@section('content')

<style>
.docrev-page { max-width: 960px; margin: 1.25rem auto; padding: 0 1rem; }
.docrev-header { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:1.25rem; }
.docrev-title { font-size:1.35rem; font-weight:700; color:#0B3C45; margin:0; }
.docrev-sub   { color:#6B7280; font-size:.85rem; margin-top:.1rem; }
.docrev-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #E5E7EB; border-radius:8px; overflow:hidden; }
.docrev-table th, .docrev-table td { padding:.6rem .8rem; border-bottom:1px solid #F0F1F3; font-size:.88rem; text-align:left; }
.docrev-table th { background:#F9FAFB; color:#4B5563; font-weight:600; letter-spacing:.02em; font-size:.78rem; text-transform:uppercase; }
.docrev-table tr:last-child td { border-bottom:none; }
.docrev-src   { display:inline-block; font-size:.72rem; font-weight:700; padding:.15rem .55rem; border-radius:999px; }
.docrev-src--base    { background:#E0E7FF; color:#3730A3; }
.docrev-src--ai_chat { background:#D1FAE5; color:#065F46; }
.docrev-src--manual  { background:#FEF3C7; color:#92400E; }
.docrev-empty { background:#F9FAFB; border:1px dashed #D1D5DB; border-radius:8px; padding:1.5rem; text-align:center; color:#6B7280; font-size:.9rem; }
</style>

<div class="docrev-page">
    <div class="docrev-header">
        <div>
            <h1 class="docrev-title">Document History</h1>
            <div class="docrev-sub">{{ strtoupper($document_type) }} #{{ $document_id }} · {{ $revisions->count() }} revision{{ $revisions->count() === 1 ? '' : 's' }}</div>
        </div>
    </div>

    @if($revisions->isEmpty())
        <div class="docrev-empty">
            No revisions yet. The first revision is captured the moment a chat-edit thread is opened on this document.
        </div>
    @else
    <table class="docrev-table">
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
                <td>{{ $r->id }}</td>
                <td><span class="docrev-src docrev-src--{{ $r->source }}">{{ strtoupper($r->source) }}</span></td>
                <td>{{ $r->change_summary ?? '—' }}</td>
                <td style="font-family:monospace;font-size:.78rem;color:#4B5563;">{{ $r->artifact_filename ?? '—' }}</td>
                <td>{{ $r->created_at?->format('d M Y H:i') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>

@endsection
