@extends('layouts.app')

@section('title', 'Hazard Library')

@section('content')

<div class="page-header">
    <h1 class="page-title">Hazard Library</h1>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div style="display:grid; grid-template-columns:1fr 1.65fr; gap:1.5rem; align-items:start;">

    {{-- ── Left: Add template form ──────────────────────────────────────── --}}
    <div class="card">
        <h2 class="section-heading">Add New Template</h2>

        <form method="POST" action="{{ route('hazard-templates.store') }}" id="tpl-form">
            @csrf

            <div class="form-group">
                <label class="form-label" for="tpl-name">Name <span class="req">*</span></label>
                <input type="text" id="tpl-name" name="name"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}" required maxlength="150"
                       placeholder="e.g. Working at Height">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="tpl-desc">Description</label>
                <textarea id="tpl-desc" name="description" class="form-control" rows="2"
                          maxlength="500"
                          placeholder="Brief description of this hazard...">{{ old('description') }}</textarea>
            </div>

            {{-- Risk scores --}}
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:1rem;">
                <div>
                    <label class="form-label">Pre Likelihood</label>
                    <select name="pre_likelihood" class="form-control">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('pre_likelihood', 3) == $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="form-label">Pre Severity</label>
                    <select name="pre_severity" class="form-control">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('pre_severity', 3) == $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="form-label">Post Likelihood</label>
                    <select name="post_likelihood" class="form-control">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('post_likelihood', 1) == $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="form-label">Post Severity</label>
                    <select name="post_severity" class="form-control">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('post_severity', 2) == $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            {{-- Control measures --}}
            <div class="form-group">
                <label class="form-label">Control Measures</label>
                <div id="controls-list" style="display:flex; flex-direction:column; gap:.4rem; margin-bottom:.5rem;">
                    @foreach (old('controls', []) as $ctrl)
                        <div class="ctrl-row" style="display:flex; gap:.4rem; align-items:center;">
                            <input type="text" name="controls[]" class="form-control"
                                   value="{{ $ctrl }}" maxlength="500">
                            <button type="button" onclick="this.closest('.ctrl-row').remove()"
                                    class="btn-remove-ctrl">&#10005;</button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="addControl()">
                    + Add Control
                </button>
            </div>

            @if ($isAdmin)
            <div class="form-group">
                <label style="display:inline-flex; gap:.5rem; align-items:center; cursor:pointer;">
                    <input type="checkbox" name="is_global" value="1"
                           {{ old('is_global') ? 'checked' : '' }}>
                    <span>Global template (visible to all users)</span>
                </label>
            </div>
            @endif

            <button type="submit" class="btn btn-teal btn-full" style="margin-top:.75rem;">
                Save Template
            </button>
        </form>
    </div>

    {{-- ── Right: Existing templates ─────────────────────────────────────── --}}
    <div>

        {{-- Global templates --}}
        <div class="card" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:.75rem 1.25rem; border-bottom:1px solid #e5e7eb;">
                <h2 style="font-size:.95rem; font-weight:600; margin:0;">
                    Global Templates
                    <span style="font-size:.8rem; font-weight:400; color:#666;">({{ $globalTemplates->count() }})</span>
                </h2>
            </div>

            @if ($globalTemplates->isEmpty())
                <div style="padding:1.5rem; color:#888; font-size:.875rem;">
                    No global templates yet.
                    @if ($isAdmin)
                        Run <code>php artisan db:seed --class=HazardTemplateSeeder</code> to seed the standard library.
                    @endif
                </div>
            @else
                @include('hazard-templates._table', [
                    'templates' => $globalTemplates,
                    'canEdit'   => $isAdmin,
                ])
            @endif
        </div>

        {{-- Personal templates --}}
        <div class="card" style="padding:0; overflow:hidden;">
            <div style="padding:.75rem 1.25rem; border-bottom:1px solid #e5e7eb;">
                <h2 style="font-size:.95rem; font-weight:600; margin:0;">
                    My Templates
                    <span style="font-size:.8rem; font-weight:400; color:#666;">({{ $myTemplates->count() }})</span>
                </h2>
            </div>

            @if ($myTemplates->isEmpty())
                <div style="padding:1.5rem; color:#888; font-size:.875rem;">
                    You haven't saved any personal templates yet.
                </div>
            @else
                @include('hazard-templates._table', [
                    'templates' => $myTemplates,
                    'canEdit'   => true,
                ])
            @endif
        </div>
    </div>

</div>

{{-- ── Shared styles ──────────────────────────────────────────────────────── --}}
<style>
.btn-remove-ctrl {
    border: none; background: none; color: #c0392b;
    font-size: 1.1rem; cursor: pointer; line-height: 1; padding: 0 .25rem; flex-shrink: 0;
}
.hazard-ctrl-panel { display:none; margin-top:.6rem; }
.hazard-ctrl-panel ol { margin:0; padding-left:1.4rem; font-size:.76rem; color:#444; line-height:1.7; }
.risk-badge {
    display:inline-block; padding:.18rem .4rem;
    border-radius:4px; font-weight:600; font-size:.78rem; white-space:nowrap;
}
</style>

{{-- ── Shared JS ─────────────────────────────────────────────────────────── --}}
<script>
function addControl() {
    var list  = document.getElementById('controls-list');
    var row   = document.createElement('div');
    row.className = 'ctrl-row';
    row.style.cssText = 'display:flex;gap:.4rem;align-items:center;';

    var input = document.createElement('input');
    input.type = 'text'; input.name = 'controls[]';
    input.className = 'form-control'; input.maxLength = 500;
    input.placeholder = 'Control measure...';

    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn-remove-ctrl';
    btn.innerHTML = '&#10005;';
    btn.onclick = function () { row.remove(); };

    row.appendChild(input);
    row.appendChild(btn);
    list.appendChild(row);
    input.focus();
}

function toggleControls(id) {
    var el = document.getElementById('ctrl-' + id);
    if (el) {
        el.style.display = (el.style.display === '' || el.style.display === 'none') ? 'block' : 'none';
    }
}
</script>

@endsection
