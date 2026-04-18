{{--
    <x-summary-card title="Project Overview">
        <div class="kv-grid"> ... </div>
    </x-summary-card>

    Props:
      title — optional section heading
--}}

@props([
    'title' => null,
])

<div class="section-block" style="margin-bottom:1.25rem;">
    @if ($title || (isset($actions) && $actions->isNotEmpty()))
    <div class="section-card__header">
        @if ($title)
        <h2 class="section-card__title">{{ $title }}</h2>
        @endif
        @if (isset($actions) && $actions->isNotEmpty())
        <div class="section-card__actions">{{ $actions }}</div>
        @endif
    </div>
    @endif
    {{ $slot }}
</div>
