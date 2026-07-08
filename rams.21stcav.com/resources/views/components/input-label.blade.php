{{-- Input label — tier-one body-colour semi-bold (PLAN 260708-b7i). --}}
@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-body tracking-tight']) }}>
    {{ $value ?? $slot }}
</label>
