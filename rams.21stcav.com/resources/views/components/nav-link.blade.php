{{--
    Nav-link — tier-one indigo underline on active (PLAN 260708-b7i).
    Used by Breeze's top-nav (auth pages). Main app uses .snav-link
    from navigation.blade.php.
--}}
@props(['active'])

@php
    $classes = ($active ?? false)
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-600 text-sm font-semibold leading-5 text-ink focus:outline-none focus:border-brand-700 transition duration-150 ease-in-out'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-muted hover:text-ink hover:border-hairline-strong focus:outline-none focus:text-ink focus:border-hairline-strong transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
