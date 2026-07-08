{{--
    Text input — tier-one hairline border with indigo focus ring
    (PLAN 260708-b7i, 2026-07-08).
--}}
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge([
    'class' => 'block w-full border-hairline-strong bg-card text-ink placeholder:text-subtle rounded-md text-sm focus:border-brand-600 focus:ring-brand-600/25 focus:ring-2 focus:ring-offset-0 disabled:bg-canvas-soft disabled:cursor-not-allowed transition-colors duration-150',
]) }}>
