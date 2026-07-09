{{--
    Primary button — Jetbuilt-clean (2026-07-09). 6px radius, tight 13px
    type, no shadow, electric-blue accent. Used by Breeze auth pages
    (login / register / password reset). The main app uses the
    `.btn .btn-primary` class from app.blade.php.
--}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-brand-600 border border-transparent rounded-md font-medium text-sm text-white tracking-tight hover:bg-brand-700 active:bg-brand-800 focus:outline-none focus-visible:shadow-inset-focus disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-150',
]) }}>
    {{ $slot }}
</button>
