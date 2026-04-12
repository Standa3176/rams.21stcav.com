@extends('layouts.app')

@section('title', 'New O&M Manual')

@section('content')
<div class="container" style="max-width:760px; margin:0 auto;">
    <h1 style="font-size:1.4rem; font-weight:700; margin-bottom:1.5rem;">New O&amp;M Manual</h1>

    @if (session('error'))
        <div class="alert alert-error" style="margin-bottom:1rem;">{{ session('error') }}</div>
    @endif

    <div class="card" style="padding:1.5rem;">
        <form method="POST" action="{{ route('om-manuals.store') }}" enctype="multipart/form-data">
            @csrf

            {{-- Project selection --}}
            <div style="margin-bottom:1.25rem;">
                <label for="project_id" style="display:block; font-weight:600; margin-bottom:.4rem;">
                    Project <span style="color:#888; font-weight:400;">(optional)</span>
                </label>
                <select id="project_id" name="project_id" class="form-input" style="width:100%;">
                    <option value="">— No project —</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}"
                            {{ (string) $selectedProjectId === (string) $project->id ? 'selected' : '' }}>
                            {{ $project->name }} ({{ $project->ref }})
                        </option>
                    @endforeach
                </select>
                @error('project_id')
                    <p style="color:#c0392b; font-size:.85rem; margin-top:.25rem;">{{ $message }}</p>
                @enderror
            </div>

            {{-- Quote PDF upload --}}
            <div style="margin-bottom:1.25rem;">
                <label for="quote_pdf" style="display:block; font-weight:600; margin-bottom:.4rem;">
                    Quote PDF <span style="color:#c0392b;">*</span>
                </label>
                <input id="quote_pdf" name="quote_pdf" type="file" accept="application/pdf"
                       class="form-input" style="width:100%;" required>
                @error('quote_pdf')
                    <p style="color:#c0392b; font-size:.85rem; margin-top:.25rem;">{{ $message }}</p>
                @enderror
            </div>

            {{-- AI provider --}}
            <div style="margin-bottom:1.5rem;">
                <label for="ai_provider" style="display:block; font-weight:600; margin-bottom:.4rem;">AI Provider</label>
                <select id="ai_provider" name="ai_provider" class="form-input" style="width:100%;">
                    <option value="claude" {{ $defaultProvider === 'claude' ? 'selected' : '' }}>Claude (Anthropic)</option>
                    <option value="openai" {{ $defaultProvider === 'openai' ? 'selected' : '' }}>GPT-4o (OpenAI)</option>
                </select>
            </div>

            <div style="display:flex; gap:.75rem;">
                <button type="submit" class="btn btn-teal">Extract Equipment</button>
                <a href="{{ route('om-manuals.index') }}" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
