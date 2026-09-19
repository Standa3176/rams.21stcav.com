@extends('layouts.app')

@section('title', $resource ? 'Edit Labour Resource' : 'Add Labour Resource')

@push('styles')
<style>
/* Follows admin/users/form.blade.php's form-row convention. */
.form-row { margin-bottom: 18px; }
.form-row label { display: block; font-size: 12px; font-weight: 600; color: var(--body); margin-bottom: 5px; letter-spacing: -0.005em; }
.form-row label span { font-weight: 400; color: var(--text-muted); }
.form-row input[type=text],
.form-row input[type=email],
.form-row select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    color: var(--ink-900);
    background: var(--surface);
    transition: border-color 120ms, box-shadow 120ms;
}
.form-row input:focus,
.form-row select:focus {
    outline: none;
    border-color: var(--teal-500);
    box-shadow: var(--shadow-focus);
}
.form-row .error-msg { font-size: 12px; color: var(--danger); margin-top: 4px; }
.form-row .hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.lr-role-options { display: flex; gap: 16px; flex-wrap: wrap; }
.lr-role-option { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--body); font-weight: 400; }
.btn-row { display: flex; gap: 8px; align-items: center; margin-top: 20px; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">{{ $resource ? 'Edit Labour Resource' : 'Add Labour Resource' }}</h1>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.labour-resources.index') }}" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Back to Labour Resources
        </a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card" style="max-width:560px;padding:24px 28px;">
    <form method="POST"
          action="{{ $resource ? route('admin.labour-resources.update', $resource) : route('admin.labour-resources.store') }}">
        @csrf
        @if ($resource) @method('PUT') @endif

        {{-- Name --}}
        <div class="form-row">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name"
                   value="{{ old('name', $resource?->name) }}"
                   required autocomplete="name" maxlength="150">
            @error('name') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Email --}}
        <div class="form-row">
            <label for="email">Email Address <span>(optional)</span></label>
            <input type="email" id="email" name="email"
                   value="{{ old('email', $resource?->email) }}"
                   autocomplete="email" maxlength="254">
            @error('email') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Phone --}}
        <div class="form-row">
            <label for="phone">Phone <span>(optional)</span></label>
            <input type="text" id="phone" name="phone"
                   value="{{ old('phone', $resource?->phone) }}"
                   autocomplete="tel" maxlength="30">
            @error('phone') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Roles --}}
        <div class="form-row">
            <label for="roles">Roles</label>
            <div class="lr-role-options">
                @php $selectedRoles = old('roles', $resource?->roles ?? []); @endphp
                @foreach (\App\Models\LabourResource::ROLES as $role)
                    <label class="lr-role-option">
                        <input type="checkbox" name="roles[]" value="{{ $role }}"
                               @checked(in_array($role, $selectedRoles, true))>
                        {{ ucfirst($role) }}
                    </label>
                @endforeach
            </div>
            <p class="hint">Select at least one role.</p>
            @error('roles') <p class="error-msg">{{ $message }}</p> @enderror
            @error('roles.*') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Linked login --}}
        <div class="form-row">
            <label for="user_id">Linked login <span>(optional)</span></label>
            <select id="user_id" name="user_id">
                <option value="">— none —</option>
                @foreach (\App\Models\User::orderBy('name')->get() as $u)
                    <option value="{{ $u->id }}" {{ (string) old('user_id', $resource?->user_id) === (string) $u->id ? 'selected' : '' }}>
                        {{ $u->name }}
                    </option>
                @endforeach
            </select>
            <p class="hint">Only relevant when this person also has a staff login account.</p>
            @error('user_id') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">
                {{ $resource ? 'Save Changes' : 'Create Resource' }}
            </button>
            <a href="{{ route('admin.labour-resources.index') }}" class="btn btn-outline btn-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
