{{--
    Text input — Jetbuilt-clean (2026-07-09). Hairline border, 15px
    body-size, electric-blue focus.
--}}
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge([
    'class' => 'block w-full border-hairline bg-card text-ink placeholder:text-subtle rounded-md text-sm py-2 px-3 focus:border-brand-600 focus:ring-brand-600/25 focus:ring-2 focus:ring-offset-0 disabled:bg-canvas-soft disabled:cursor-not-allowed transition-colors duration-150',
]) }}>
