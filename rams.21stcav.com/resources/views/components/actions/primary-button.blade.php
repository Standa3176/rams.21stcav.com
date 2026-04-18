{{--
    <x-actions.primary-button href="{{ route(...) }}">+ New Project</x-actions.primary-button>
    <x-actions.primary-button type="submit">Save</x-actions.primary-button>

    Props:
      href — renders as <a> when set, otherwise <button>
      type — button type (default "button")
      size — "sm" | "md" (default "sm" for header/toolbar use)
--}}

@props([
    'href' => null,
    'type' => 'button',
    'size' => 'sm',
])

@php $sizeClass = $size === 'sm' ? 'btn-sm' : ''; @endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "btn btn-teal {$sizeClass}"]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "btn btn-teal {$sizeClass}"]) }}>
        {{ $slot }}
    </button>
@endif
