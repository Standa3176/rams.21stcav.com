{{--
    Secondary button — white with hairline border (PLAN 260708-b7i).
--}}
<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-card border border-hairline-strong rounded-md font-semibold text-sm text-body tracking-tight shadow-sm hover:bg-canvas-soft hover:border-brand-500 hover:text-ink focus:outline-none focus-visible:shadow-inset-focus disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-150',
]) }}>
    {{ $slot }}
</button>
