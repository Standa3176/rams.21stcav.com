@extends('layouts.app')
@section('title', $type->exists ? 'Edit Solution Type' : 'New Solution Type')

@section('content')

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $type->exists ? 'Edit: ' . $type->name : 'New Solution Type' }}</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.25rem;">
            Survey checklist and install method are used by AI when generating room summaries and method statements.
        </p>
    </div>
    <a href="{{ route('admin.solution-types.index') }}" class="btn btn-outline btn-sm">← Back</a>
</div>

@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please fix the following:</strong>
        <ul style="margin:.5rem 0 0 1.25rem;padding:0;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST"
      action="{{ $type->exists ? route('admin.solution-types.update', $type) : route('admin.solution-types.store') }}">
    @csrf
    @if($type->exists) @method('PUT') @endif

    {{-- ── Basic info ───────────────────────────────────────────────────── --}}
    <div class="section-block" style="margin-bottom:1.25rem;">
        <h2 class="section-heading">Basic Info</h2>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="name" class="form-control"
                       value="{{ old('name', $type->name) }}"
                       placeholder="e.g. Video Conferencing"
                       maxlength="120" required>
            </div>
            <div class="form-group">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control"
                       value="{{ old('sort_order', $type->sort_order ?? 0) }}"
                       min="0" max="999" style="width:100px;">
            </div>
            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control"
                       value="{{ old('description', $type->description) }}"
                       placeholder="Short description shown in dropdowns"
                       maxlength="500">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;">
                    <input type="checkbox" name="is_active" value="1"
                           {{ old('is_active', $type->is_active ?? true) ? 'checked' : '' }}>
                    Active (appears in dropdowns)
                </label>
            </div>
        </div>
    </div>

    {{-- ── Survey checklist ─────────────────────────────────────────────── --}}
    <div class="section-block" style="margin-bottom:1.25rem;">
        <h2 class="section-heading">Site Survey Checklist</h2>
        <p style="font-size:.825rem;color:var(--text-muted);margin-bottom:.75rem;">
            One item per line. These are shown to the engineer on the site survey form and used by AI to generate the AV Requirements pre-fill for each room.
        </p>
        <textarea name="survey_checklist"
                  class="form-control"
                  rows="16"
                  placeholder="Room dimensions (W × D × H)&#10;Wall construction at display position&#10;Existing AV equipment&#10;...">{{ old('survey_checklist', $type->survey_checklist) }}</textarea>
    </div>

    {{-- ── Install method ───────────────────────────────────────────────── --}}
    <div class="section-block" style="margin-bottom:1.25rem;">
        <h2 class="section-heading">Typical Install Method</h2>
        <p style="font-size:.825rem;color:var(--text-muted);margin-bottom:.75rem;">
            One step per line. Used by AI when generating the Method Statement for this room type. Use numbered steps (1. First fix…).
        </p>
        <textarea name="install_method"
                  class="form-control"
                  rows="16"
                  placeholder="1. First fix — install cable containment and pull cables&#10;2. Mount display bracket&#10;3. ...">{{ old('install_method', $type->install_method) }}</textarea>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center;">
        <button type="submit" class="btn btn-teal">
            {{ $type->exists ? 'Save Changes' : 'Create Solution Type' }}
        </button>
        <a href="{{ route('admin.solution-types.index') }}" class="btn btn-outline">Cancel</a>
    </div>

</form>

@endsection
