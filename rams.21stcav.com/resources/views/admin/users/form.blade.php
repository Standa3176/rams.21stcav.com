@extends('layouts.app')

@section('title', $user ? 'Edit User' : 'Add User')

@push('styles')
<style>
/*
 * User form — tier-one polish (2026-07-08).
 * Was redeclaring .page-header, .card, .btn, .alert, .form-* locally with
 * legacy warm teal focus rings (rgba(23,138,149,.12)) and static hex.
 * Every one of those is provided by the shared layout now, so the
 * override block is deleted. Screen-scoped keeps: form-row spacing +
 * hint text, both of which map to what the form-group + form-help
 * convention provides in the shared layout.
 */
.form-row { margin-bottom: 18px; }
.form-row label { display: block; font-size: 12px; font-weight: 600; color: var(--body); margin-bottom: 5px; letter-spacing: -0.005em; }
.form-row label span { font-weight: 400; color: var(--text-muted); }
.form-row input[type=text],
.form-row input[type=email],
.form-row input[type=password],
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
.btn-row { display: flex; gap: 8px; align-items: center; margin-top: 20px; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">{{ $user ? 'Edit User' : 'Add User' }}</h1>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.users.index') }}" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Back to Users
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
          action="{{ $user ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if ($user) @method('PUT') @endif

        {{-- Name --}}
        <div class="form-row">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name"
                   value="{{ old('name', $user?->name) }}"
                   required autocomplete="name" maxlength="100">
            @error('name') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Email --}}
        <div class="form-row">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email"
                   value="{{ old('email', $user?->email) }}"
                   required autocomplete="email" maxlength="254">
            @error('email') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Role --}}
        <div class="form-row">
            <label for="role">Role</label>
            <select id="role" name="role" required>
                <option value="user"  {{ old('role', $user?->role) === 'user'  ? 'selected' : '' }}>User</option>
                <option value="admin" {{ old('role', $user?->role) === 'admin' ? 'selected' : '' }}>Admin</option>
            </select>
            <p class="hint">Admins can manage users, view all documents, and change AI settings.</p>
            @error('role') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        {{-- Password --}}
        <div class="form-row">
            <label for="password">
                Password
                @if ($user) <span>(leave blank to keep current password)</span> @endif
            </label>
            <input type="password" id="password" name="password"
                   autocomplete="new-password"
                   {{ $user ? '' : 'required' }}>
            <p class="hint">Minimum 8 characters including letters and numbers.</p>
            @error('password') <p class="error-msg">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">
                {{ $user ? 'Save Changes' : 'Create User' }}
            </button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline btn-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
