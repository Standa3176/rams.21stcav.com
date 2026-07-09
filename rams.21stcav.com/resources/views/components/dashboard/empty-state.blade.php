@props([
    'title'    => 'Nothing here yet',
    'message'  => null,
    'href'     => null,
    'action'   => null,
    'icon'     => null,   {{-- optional SVG string --}}
])

<div class="dash-empty-state">
    <div class="dash-empty-state__icon">
        @if($icon)
            {!! $icon !!}
        @elseif($slot->isNotEmpty())
            {{ $slot }}
        @else
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="3" width="20" height="14" rx="2"/>
                <path d="M8 21h8M12 17v4"/>
            </svg>
        @endif
    </div>

    <div class="dash-empty-state__title">{{ $title }}</div>

    @if($message)
        <div class="dash-empty-state__message">{{ $message }}</div>
    @endif

    @if($href && $action)
        <a href="{{ $href }}" class="btn btn-primary btn-sm" style="margin-top:20px;">
            {{ $action }}
        </a>
    @endif
</div>

<style>
/*
 * Dashboard empty state — Jetbuilt-clean (2026-07-09).
 * Was tier-one warm-teal chip on a grey ground. Retunes to the
 * accent-tinted chip + ink typography so it speaks the same language
 * as the KPI icon chips and quick-link tiles.
 */
.dash-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 56px 24px;
    text-align: center;
    color: var(--ink-500);
}
.dash-empty-state__icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-sm);
    background: var(--accent-50);
    color: var(--accent-700);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.dash-empty-state__title   { font-size: var(--fs-body); font-weight: 600; color: var(--ink-900); margin-bottom: 6px; letter-spacing: -0.005em; }
.dash-empty-state__message { font-size: var(--fs-small); color: var(--ink-500); max-width: 340px; line-height: 1.5; }
</style>
