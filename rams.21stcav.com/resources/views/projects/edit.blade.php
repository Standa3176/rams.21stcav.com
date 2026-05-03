@extends('layouts.app')

@section('title', 'Edit — ' . $project->name)

@section('content')

<div class="page-header">
    <h1 class="page-title">Edit Project</h1>
    <a href="{{ route('projects.show', $project) }}" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-section" style="max-width:720px;">
    <div class="form-section__header">
        <h2 class="section-heading">Project Details</h2>
    </div>
    <div class="form-section__body">
    <form method="POST" action="{{ route('projects.update', $project) }}">
        @csrf @method('PUT')

        <div class="form-grid-2">
            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="name">Project Name <span class="req">*</span></label>
                <input id="name" name="name" type="text"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $project->name) }}" required placeholder=" ">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="ref">Project Ref</label>
                <input id="ref" name="ref" type="text" class="form-control"
                       value="{{ old('ref', $project->ref) }}" placeholder=" ">
            </div>

            <div class="form-group">
                <label class="form-label" for="client_name">Client <span class="req">*</span></label>
                <input id="client_name" name="client_name" type="text"
                       class="form-control @error('client_name') is-invalid @enderror"
                       value="{{ old('client_name', $project->client_name) }}" required placeholder=" ">
                @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="site_address">Site Address <span class="req">*</span></label>
                <input id="site_address" name="site_address" type="text"
                       class="form-control @error('site_address') is-invalid @enderror"
                       value="{{ old('site_address', $project->site_address) }}" required placeholder=" ">
                @error('site_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="works_description">Works Description</label>
                <textarea id="works_description" name="works_description"
                          class="form-control" rows="3" placeholder=" ">{{ old('works_description', $project->works_description) }}</textarea>
            </div>

            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes"
                          class="form-control" rows="2" data-optional>{{ old('notes', $project->notes) }}</textarea>
            </div>
        </div>

        <div style="display:flex; gap:.75rem; margin-top:.5rem;">
            <button type="submit" class="btn btn-teal">Save Changes</button>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    </div>
</div>

@endsection
