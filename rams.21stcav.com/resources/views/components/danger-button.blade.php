{{--
    Danger button — red-600 solid, matches secondary weight (PLAN 260708-b7i).
--}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-tight shadow-sm hover:bg-red-700 active:bg-red-800 focus:outline-none focus-visible:ring focus-visible:ring-red-500/30 focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-150',
]) }}>
    {{ $slot }}
</button>
