{{--
    <x-workflow.blocking-banner title="Action not available" severity="warning">
        Explain why the action is blocked.
        <div class="blocking-banner__cta"> ... optional CTA ... </div>
    </x-workflow.blocking-banner>

    Props:
      title    — banner heading
      severity — 'warning' | 'error' | 'info'  (default: 'warning')
--}}

@props([
    'title'    => 'Action not available',
    'severity' => 'warning',
])

<div class="blocking-banner blocking-banner--{{ $severity }}" role="alert">
    <div class="blocking-banner__icon" aria-hidden="true">
        @if ($severity === 'error')
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        @elseif ($severity === 'info')
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
        @else
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
        @endif
    </div>
    <div class="blocking-banner__body">
        <div class="blocking-banner__title">{{ $title }}</div>
        <div class="blocking-banner__desc">{{ $slot }}</div>
    </div>
</div>
