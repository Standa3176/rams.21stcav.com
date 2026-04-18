{{--
    <x-actions.secondary-button href="{{ route(...) }}">View</x-actions.secondary-button>
    <x-actions.secondary-button type="submit">Search</x-actions.secondary-button>

    Props:
      href    — renders as <a> when set, otherwise <button>
      type    — button type (default "button")
      size    — "sm" | "md" (default "sm" for header/toolbar use)
      variant — "outline" | "ghost" (default "outline")
--}}

@props([
    'href'    => null,
    'type'    => 'button',
    'size'    => 'sm',
    'variant' => 'outline',
])

@php
    $sizeClass    = $size === 'sm' ? 'btn-sm' : '';
    $variantClass = $variant === 'ghost' ? 'btn-ghost' : 'btn-outline';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "btn {$variantClass} {$sizeClass}"]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "btn {$variantClass} {$sizeClass}"]) }}>
        {{ $slot }}
    </button>
@endif
