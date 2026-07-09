@props(['active'])

@php
/* Re-audit D-04 — the Breeze default indigo palette shipped on the
   mobile responsive nav link (border-indigo-400 / text-indigo-700 /
   bg-indigo-50 / focus:border-indigo-700). Every phone-width user saw
   indigo where desktop shows accent-blue. Remapped to accent-* so the
   two shells speak one language. */
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-accent-600 text-start text-base font-medium text-accent-700 bg-accent-50 focus:outline-none focus:text-accent-700 focus:bg-accent-100 focus:border-accent-700 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
