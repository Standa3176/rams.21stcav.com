@extends('layouts.app')
@section('title', $rule->exists ? 'Edit Cable Rule' : 'New Cable Rule')

@section('content')

<x-edit-action-bar
    :form-id="'rule-form'"
    :cancel-url="route('admin.device-cable-rules.index')"
    :save-label="$rule->exists ? 'Save Rule' : 'Create Rule'">
    <x-slot name="title">
        {{ $rule->exists ? 'Edit Rule #'.$rule->id : 'New Cable Rule' }}
    </x-slot>
</x-edit-action-bar>

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
      id="rule-form"
      action="{{ $rule->exists ? route('admin.device-cable-rules.update', $rule) : route('admin.device-cable-rules.store') }}">
    @csrf
    @if ($rule->exists) @method('PUT') @endif

    @include('admin.device-cable-rules._form', ['rule' => $rule])

    <div style="display:flex;gap:.75rem;align-items:center;">
        <button type="submit" class="btn btn-teal">
            {{ $rule->exists ? 'Save Rule' : 'Create Rule' }}
        </button>
        <a href="{{ route('admin.device-cable-rules.index') }}" class="btn btn-outline">Cancel</a>
    </div>
</form>

@endsection
