@extends('layouts.app')

@section('title', 'New Project')

@section('content')

<div class="page-header">
    <h1 class="page-title">New Project</h1>
    <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Back to Projects</a>
</div>

<div class="card" style="max-width:720px;">
    <form method="POST" action="{{ route('projects.store') }}">
        @csrf

        <div class="form-grid-2">
            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="name">Project Name <span class="req">*</span></label>
                <input id="name" name="name" type="text"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}" required autofocus>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="client_name">Client <span class="req">*</span></label>
                <input id="client_name" name="client_name" type="text"
                       class="form-control @error('client_name') is-invalid @enderror"
                       value="{{ old('client_name') }}" required>
                @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="site_address">Site Address <span class="req">*</span></label>
                <input id="site_address" name="site_address" type="text"
                       class="form-control @error('site_address') is-invalid @enderror"
                       value="{{ old('site_address') }}" required>
                @error('site_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="quote_reference">Quote Reference <span class="req">*</span></label>
                <input id="quote_reference" name="quote_reference" type="text"
                       class="form-control @error('quote_reference') is-invalid @enderror"
                       value="{{ old('quote_reference') }}"
                       placeholder="e.g. ABC123-01"
                       required>
                @error('quote_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <p class="form-help">Leave blank to assign later. Version suffix (-01, -02) is tracked automatically when quotes are uploaded.</p>
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="works_description">Works Description</label>
                <textarea id="works_description" name="works_description"
                          class="form-control" rows="3"
                          placeholder="Brief description of the AV installation scope…">{{ old('works_description') }}</textarea>
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes"
                          class="form-control" rows="2"
                          placeholder="Internal notes (not shown in documents)…">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div style="display:flex; gap:.75rem; margin-top:.5rem;">
            <button type="submit" class="btn btn-teal">Create Project</button>
            <a href="{{ route('projects.index') }}" class="btn btn-outline">Back to Projects</a>
        </div>
    </form>
</div>

@endsection
