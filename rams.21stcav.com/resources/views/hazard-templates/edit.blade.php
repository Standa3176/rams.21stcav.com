@extends('layouts.app')

@section('title', 'Edit Hazard Template')

@section('content')

<div class="page-header">
    <h1 class="page-title">Edit Hazard Template</h1>
    <a href="{{ route('hazard-templates.index') }}" class="btn btn-outline btn-sm">← Library</a>
</div>

@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="card" style="max-width:720px;">

    <form method="POST" action="{{ route('hazard-templates.update', $template) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label class="form-label" for="name">Hazard Name <span class="req">*</span></label>
            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                   value="{{ old('name', $template->name) }}" required maxlength="150">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="2"
                      maxlength="500">{{ old('description', $template->description) }}</textarea>
        </div>

        {{-- Risk scores --}}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:1rem;">
            <div>
                <label class="form-label">Pre-controls Likelihood</label>
                <select name="pre_likelihood" class="form-control">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}" {{ old('pre_likelihood', $template->pre_likelihood) == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="form-label">Pre-controls Severity</label>
                <select name="pre_severity" class="form-control">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}" {{ old('pre_severity', $template->pre_severity) == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="form-label">Post-controls Likelihood</label>
                <select name="post_likelihood" class="form-control">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}" {{ old('post_likelihood', $template->post_likelihood) == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="form-label">Post-controls Severity</label>
                <select name="post_severity" class="form-control">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}" {{ old('post_severity', $template->post_severity) == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
            </div>
        </div>

        {{-- Controls --}}
        <div class="form-group">
            <label class="form-label">Control Measures</label>
            <div id="controls-list" style="display:flex; flex-direction:column; gap:.4rem; margin-bottom:.5rem;">
                @foreach (old('controls', $template->controls ?? []) as $ctrl)
                    <div style="display:flex; gap:.4rem; align-items:center;">
                        <input type="text" name="controls[]" class="form-control"
                               value="{{ $ctrl }}" maxlength="500">
                        <button type="button" onclick="this.closest('div').remove()"
                                style="border:none;background:none;color:#c0392b;font-size:1.2rem;cursor:pointer;line-height:1;">✕</button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="addControl()">+ Add Control</button>
        </div>

        @if ($isAdmin)
        <div class="form-group">
            <label class="check-item" style="display:inline-flex; gap:.5rem; align-items:center;">
                <input type="checkbox" name="is_global" value="1"
                       {{ old('is_global', $template->is_global) ? 'checked' : '' }}>
                <span>Global template (visible to all users)</span>
            </label>
        </div>
        @endif

        <div style="display:flex; gap:.75rem; margin-top:1rem;">
            <button type="submit" class="btn btn-teal">Save Changes</button>
            <a href="{{ route('hazard-templates.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
function addControl() {
    const list = document.getElementById('controls-list');
    const row  = document.createElement('div');
    row.style.cssText = 'display:flex;gap:.4rem;align-items:center;';
    row.innerHTML = `
        <input type="text" name="controls[]" class="form-control" maxlength="500" placeholder="Control measure…">
        <button type="button" onclick="this.closest('div').remove()"
                style="border:none;background:none;color:#c0392b;font-size:1.2rem;cursor:pointer;line-height:1;">✕</button>
    `;
    list.appendChild(row);
    row.querySelector('input').focus();
}
</script>

@endsection
