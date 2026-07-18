{{--
    <x-section-card title="...">
        <x-slot name="actions"> ... </x-slot>
        <x-slot name="footer"> ... </x-slot>
        ... body content ...
    </x-section-card>

    Props:
      title — optional heading with teal underline
      flush — bool, removes body padding and enables horizontal scroll (use for tables)
--}}

@props([
    'title' => null,
    'flush' => false,
])

<div @class(['section-block', 'section-block--flush' => $flush])>

    @if ($title || (isset($actions) && $actions->isNotEmpty()))
    <div @class(['section-card__header', 'section-card__header--flush' => $flush])>
        @if ($title)
        <h2 class="section-card__title">{{ $title }}</h2>
        @endif
        @if (isset($actions) && $actions->isNotEmpty())
        <div class="section-card__actions">
            {{ $actions }}
        </div>
        @endif
    </div>
    @endif

    <div @class(['section-card__body', 'section-card__body--flush' => $flush])>
        {{ $slot }}
    </div>

    @if (isset($footer) && $footer->isNotEmpty())
    <div class="section-card__footer">
        {{ $footer }}
    </div>
    @endif

</div>
