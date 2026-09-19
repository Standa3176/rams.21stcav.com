{{--
    Lifecycle row — the drawn / sent / issued discs.

    The discs reuse the Done and Waiting SHAPES from the state vocabulary so
    the page keeps one vocabulary throughout: a filled teal disc means the
    system knows it happened, a hollow disc means it has not. The tick glyph
    is aria-hidden and the state is carried by the row's accessible name and
    by the value text beside it, so no meaning rests on colour or on a glyph.
--}}
@props(['name', 'value' => '', 'done' => false])

<div {{ $attributes->merge(['class' => 'cav-life']) }}>
    <span class="cav-life__disc{{ $done ? ' cav-life__disc--on' : '' }}"
          role="img"
          aria-label="{{ $done ? 'Complete' : 'Not started' }}">
        @if ($done)
            <span aria-hidden="true">&check;</span>
        @endif
    </span>

    <span class="cav-life__name">{{ $name }}</span>

    <span class="cav-life__value">{{ $value }}</span>
</div>
