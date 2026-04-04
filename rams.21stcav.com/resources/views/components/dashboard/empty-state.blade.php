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
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
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
    <a href="{{ $href }}" class="btn btn-teal btn-sm" style="margin-top:1.25rem;">
        {{ $action }}
    </a>
    @endif
</div>

<style>
.dash-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4rem 1.5rem;
    text-align: center;
    color: #6B7280;
}
.dash-empty-state__icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: #EBF6F7;
    color: #178A95;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.1rem;
}
.dash-empty-state__title   { font-size: .9375rem; font-weight: 600; color: #1F2937; margin-bottom: .4rem; }
.dash-empty-state__message { font-size: .875rem; color: #6B7280; max-width: 340px; line-height: 1.5; }
</style>
