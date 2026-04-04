@extends('layouts.app')

@section('title', $user ? 'Edit User' : 'Add User')

@push('styles')
<style>
.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; gap:1rem; flex-wrap:wrap; }
.page-header h1 { font-size:1.375rem; font-weight:700; color:var(--text); letter-spacing:-.02em; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-sm); padding:1.75rem; max-width:540px; }
.form-row { margin-bottom:1.25rem; }
label { display:block; font-size:.8125rem; font-weight:600; color:var(--text); margin-bottom:.35rem; }
label span { font-weight:400; color:var(--text-muted); }
input[type=text],input[type=email],input[type=password],select {
    width:100%; padding:.5rem .75rem; border:1px solid var(--border); border-radius:6px;
    font-size:.875rem; color:var(--text); background:var(--surface);
    transition:border-color 120ms,box-shadow 120ms;
}
input:focus,select:focus { outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(23,138,149,.12); }
.error-msg { font-size:.78rem; color:#dc2626; margin-top:.3rem; }
.hint { font-size:.78rem; color:var(--text-muted); margin-top:.3rem; }
.btn { display:inline-flex; align-items:center; gap:.3rem; font-size:.875rem; font-weight:500; padding:.45rem 1.1rem; border-radius:6px; border:1px solid transparent; cursor:pointer; text-decoration:none; transition:background 120ms,color 120ms,border-color 120ms; }
.btn-primary { background:var(--teal); color:#fff; border-color:var(--teal); }
.btn-primary:hover { background:var(--teal-hover); text-decoration:none; color:#fff; }
.btn-outline { background:transparent; color:var(--text-muted); border-color:var(--border); }
.btn-outline:hover { background:var(--teal-light); color:var(--teal); border-color:var(--teal-mid); text-decoration:none; }
.btn-row { display:flex; gap:.75rem; align-items:center; margin-top:1.75rem; }
.alert { padding:.75rem 1rem; border-radius:var(--radius-sm); margin-bottom:1rem; font-size:.875rem; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fca5a5; }
</style>
@endpush

@section('content')
<div class="page-header">
    <h1>{{ $user ? 'Edit User' : 'Add User' }}</h1>
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline">← Back to Users</a>
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

<div class="card">
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
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
