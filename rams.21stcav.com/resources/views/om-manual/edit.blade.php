@extends('layouts.app')

@section('title', 'Review O&M Equipment — ' . ($manual->project_name ?? 'O&M Manual'))

@section('content')
<div class="container" style="max-width:900px; margin:0 auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem;">
        <h1 style="font-size:1.4rem; font-weight:700; margin:0;">
            Review O&amp;M Equipment
            @if ($manual->project_name)
                <span style="font-weight:400; color:#888;">— {{ $manual->project_name }}</span>
            @endif
        </h1>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            @if ($manual->project_id)
                <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="btn btn-teal btn-sm">📋 Manage Asset Data</a>
            @endif
            <a href="{{ route('documents.revisions.view', ['type' => 'om', 'id' => $manual->id]) }}" class="btn btn-outline btn-sm">↻ History</a>
            <x-document-edit-drawer
                type="om"
                :id="$manual->id"
                label="O&M Manual"
                :visible="in_array($manual->status, [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])" />
            <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">← Back</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success" style="margin-bottom:1rem;">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" style="margin-bottom:1rem;">{{ session('error') }}</div>
    @endif

    <div class="card" style="padding:1.5rem; margin-bottom:1.25rem;">
        <div style="display:flex; gap:2rem; margin-bottom:1rem; font-size:.9rem;">
            <div><strong>Status:</strong> <span class="badge {{ $manual->statusBadgeClass() }}">{{ $manual->statusLabel() }}</span></div>
            <div><strong>Client:</strong> {{ $manual->client_name ?? '—' }}</div>
            <div><strong>Site:</strong> {{ $manual->site_address ?? '—' }}</div>
            @if ($manual->project_ref)
                <div><strong>Ref:</strong> {{ $manual->project_ref }}</div>
            @endif
        </div>
    </div>

    {{-- Edit extracted data --}}
    <div class="card" style="padding:1.5rem; margin-bottom:1.25rem;">
        <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Equipment List (JSON)</h2>
        <form method="POST" action="{{ route('om-manuals.update', $manual) }}">
            @csrf
            @method('PATCH')
            <div style="margin-bottom:1rem;">
                <textarea id="extracted_json" name="extracted_json" rows="20"
                          class="form-input" style="width:100%; font-family:monospace; font-size:.82rem;"
                >{{ json_encode($manual->extracted_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                @error('extracted_json')
                    <p style="color:#c0392b; font-size:.85rem; margin-top:.25rem;">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn btn-teal">Save Changes</button>
        </form>
    </div>

    {{-- Generate O&M --}}
    @if ($manual->status !== \App\Models\OmManual::STATUS_GENERATING)
        <div class="card" style="padding:1.25rem;">
            <h2 style="font-size:1rem; font-weight:700; margin-bottom:.75rem;">Generate O&amp;M Manual</h2>
            <form method="POST" action="{{ route('om-manuals.generate', $manual) }}">
                @csrf
                <button type="submit" class="btn btn-teal">Generate Document</button>
            </form>
        </div>
    @else
        <div class="card" style="padding:1.25rem;">
            <p style="color:#888; font-style:italic; margin:0;">Generation in progress — please wait…</p>
        </div>
    @endif
</div>
@endsection
