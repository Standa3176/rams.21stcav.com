{{--
    <x-empty-state title="No projects found" description="Import a quote to get started."
                   href="{{ route('quote-import.create') }}" action="Import Quote">
        <svg .../>
    </x-empty-state>

    Props:
      title       — heading text
      description — body text (optional)
      href        — CTA link (optional; rendered only when action also set)
      action      — CTA label (optional; requires href)
--}}

@props([
    'title'       => 'Nothing here yet',
    'description' => null,
    'href'        => null,
    'action'      => null,
])

<div class="empty-state-v2">
    <div class="empty-state-v2__icon">
        @if ($slot->isNotEmpty())
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

    <p class="empty-state-v2__title">{{ $title }}</p>

    @if ($description)
        <p class="empty-state-v2__desc">{{ $description }}</p>
    @endif

    @if ($href && $action)
        <a href="{{ $href }}" class="btn btn-teal btn-sm">{{ $action }}</a>
    @endif
</div>
