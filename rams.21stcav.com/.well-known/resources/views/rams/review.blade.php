@extends('layouts.app')

@section('title', 'Review RAMS — ' . $rams->project_name)

@section('content')

@php
    $project = $rams->generated_data['project'] ?? [];
    $hazards = $rams->generated_data['hazards'] ?? [];
    $ms      = $rams->generated_data['method_statement'] ?? null;
    $ppe     = $rams->generated_data['ppe'] ?? [];
    $persons = $rams->generated_data['persons_at_risk'] ?? [];
@endphp

    <div class="page-header">
        <h1 class="page-title">Review &amp; Download RAMS</h1>
        <a href="{{ route('rams.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="alert alert-info">
        Review the AI-generated details below. You can edit project fields before downloading.
        The hazard register and method statement are shown read-only — edit the Word document after download if needed.
    </div>

    {{-- ── Edit & Download form ─────────────────────────────────────────────── --}}
    <div class="card">
        <h2 class="section-heading">Project Details</h2>
        <form method="POST" action="{{ route('rams.update-and-download', $rams) }}">
            @csrf
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="project_name">Project Name <span class="req">*</span></label>
                    <input id="project_name" name="project_name" type="text"
                           class="form-control @error('project_name') is-invalid @enderror"
                           value="{{ old('project_name', $project['name'] ?? $rams->project_name) }}" required>
                    @error('project_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label" for="project_ref">Project Ref</label>
                    <input id="project_ref" name="project_ref" type="text"
                           class="form-control"
                           value="{{ old('project_ref', $project['ref'] ?? $rams->project_ref) }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="client_name">Client <span class="req">*</span></label>
                    <input id="client_name" name="client_name" type="text"
                           class="form-control @error('client_name') is-invalid @enderror"
                           value="{{ old('client_name', $project['client'] ?? $rams->client_name) }}" required>
                    @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label" for="document_status">Document Status</label>
                    <select id="document_status" name="document_status" class="form-control">
                        @foreach(['For Construction', 'For Review', 'For Approval', 'For Information'] as $docStatus)
                            <option value="{{ $docStatus }}"
                                {{ ($project['document_status'] ?? 'For Construction') === $docStatus ? 'selected' : '' }}>
                                {{ $docStatus }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="site_address">Site Address <span class="req">*</span></label>
                    <input id="site_address" name="site_address" type="text"
                           class="form-control @error('site_address') is-invalid @enderror"
                           value="{{ old('site_address', $project['site_address'] ?? $rams->site_address) }}" required>
                    @error('site_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="subtitle">Subtitle <small style="font-weight:400;color:#666;">(one-liner shown on cover, e.g. "Site | Client | AV Installation")</small></label>
                    <input id="subtitle" name="subtitle" type="text"
                           class="form-control"
                           value="{{ old('subtitle', $project['subtitle'] ?? '') }}">
                </div>
            </div>

            <div style="display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem;">
                <button type="submit" class="btn btn-teal">
                    ↓ Save &amp; Download .docx
                </button>
                <a href="{{ route('rams.download-pdf', $rams) }}" class="btn btn-outline">
                    ↓ Download PDF
                </a>
                <a href="{{ route('rams.download', $rams) }}" class="btn btn-outline">
                    ↓ Download current .docx
                </a>
            </div>
        </form>
    </div>

    {{-- ── AI Summary (read-only) ───────────────────────────────────────────── --}}
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem;">

        {{-- Hazard summary --}}
        <div class="card card-sm">
            <h2 class="section-heading">Hazard Register <span style="font-weight:400; font-size:.85rem; color:#666;">({{ count($hazards) }} hazards)</span></h2>
            @if (empty($hazards))
                <p style="color:#666; font-size:.875rem;">No hazards generated.</p>
            @else
                <table class="data-table" style="font-size:.8rem;">
                    <thead><tr><th>Hazard</th><th style="width:55px;">Pre</th><th style="width:55px;">Post</th></tr></thead>
                    <tbody>
                    @foreach ($hazards as $h)
                        @php
                            $pre  = (int)($h['pre_likelihood']  ?? 1) * (int)($h['pre_severity']  ?? 1);
                            $post = (int)($h['post_likelihood'] ?? 1) * (int)($h['post_severity'] ?? 1);
                        @endphp
                        <tr>
                            <td>{{ $h['hazard'] ?? '—' }}</td>
                            <td style="text-align:center;">
                                <span class="badge" style="background:{{ $pre <= 6 ? '#D4EDDA' : ($pre <= 9 ? '#FFF3CD' : ($pre <= 14 ? '#FFD0A0' : '#FFDEDE')) }}; color:#333;">
                                    {{ $pre }}
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge" style="background:{{ $post <= 6 ? '#D4EDDA' : ($post <= 9 ? '#FFF3CD' : ($post <= 14 ? '#FFD0A0' : '#FFDEDE')) }}; color:#333;">
                                    {{ $post }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Method statement summary + PPE + Persons --}}
        <div>
            @if ($ms)
            <div class="card card-sm" style="margin-bottom:1.25rem;">
                <h2 class="section-heading">Method Statement</h2>
                <p style="font-size:.875rem; color:#444; margin-bottom:.75rem;">{{ $ms['introduction'] ?? '' }}</p>
                @if (!empty($ms['phases']))
                    <strong style="font-size:.8rem; color:#666;">Phases:</strong>
                    <ul style="margin:.35rem 0 0 1.1rem; font-size:.85rem;">
                        @foreach ($ms['phases'] as $phase)
                            <li>{{ $phase['name'] ?? '' }} <span style="color:#888;">({{ count($phase['procedures'] ?? []) }} steps)</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @endif

            <div class="card card-sm">
                <h2 class="section-heading" style="font-size:.9rem;">PPE &amp; Persons at Risk</h2>
                @if (!empty($ppe))
                    <p style="font-size:.8rem; color:#444; margin-bottom:.5rem;"><strong>PPE:</strong> {{ implode(', ', $ppe) }}</p>
                @endif
                @if (!empty($persons))
                    <p style="font-size:.8rem; color:#444;"><strong>Persons at risk:</strong> {{ implode(', ', $persons) }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Regenerate ────────────────────────────────────────────────────────── --}}
    <div class="card card-sm">
        <h2 class="section-heading" style="font-size:.9rem;">↺ Regenerate</h2>
        <p style="font-size:.875rem; color:#666; margin-bottom:.75rem;">Re-run the AI to produce a fresh RAMS document using the same source data.</p>
        <form method="POST" action="{{ route('rams.regenerate', $rams) }}">
            @csrf
            <button type="submit" class="btn btn-outline btn-sm"
                    onclick="return confirm('Regenerate this RAMS document? The current version will be replaced.')">
                ↺ Regenerate
            </button>
        </form>
    </div>

    {{-- ── Email form ───────────────────────────────────────────────────────── --}}
    <div class="card card-sm">
        <h2 class="section-heading" style="font-size:.9rem;">Email this RAMS</h2>
        <form method="POST" action="{{ route('rams.email', $rams) }}" style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap;">
            @csrf
            <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                <label class="form-label" for="recipient_email">Recipient Email</label>
                <input id="recipient_email" name="recipient_email" type="email"
                       class="form-control" placeholder="client@example.com">
            </div>
            <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
                <label class="form-label" for="recipient_name">Recipient Name <small style="font-weight:400;">(optional)</small></label>
                <input id="recipient_name" name="recipient_name" type="text"
                       class="form-control" placeholder="John Smith">
            </div>
            <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:.05rem;">Send</button>
        </form>
    </div>

@endsection
