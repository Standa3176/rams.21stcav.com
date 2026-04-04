@props([
    'title',
    'value'    => '—',
    'subtitle' => null,
    'href'     => null,
    'color'    => '#178A95',
])

@php
    $tag   = $href ? 'a' : 'div';
    $attrs = $href ? "href=\"{$href}\"" : '';
@endphp

<{{ $tag }} {{ $attrs }} class="dash-stat-card {{ $href ? 'dash-stat-card--link' : '' }}">
    <div class="dash-stat-card__body">
        <div class="dash-stat-card__label">{{ $title }}</div>
        <div class="dash-stat-card__value" style="color:{{ $color }}">{{ $value }}</div>
        @if($subtitle)
        <div class="dash-stat-card__sub">{{ $subtitle }}</div>
        @endif
    </div>

    @if($slot->isNotEmpty())
    <div class="dash-stat-card__icon" style="background:{{ $color }}18; color:{{ $color }}">
        {{ $slot }}
    </div>
    @endif
</{{ $tag }}>

<style>
.dash-stat-card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    padding: 1.4rem 1.5rem;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    text-decoration: none;
    color: inherit;
    transition: box-shadow .15s ease, border-color .15s ease;
}
.dash-stat-card--link:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
    border-color: #C8E9EC;
    text-decoration: none;
}
.dash-stat-card__body   { display: flex; flex-direction: column; gap: .3rem; }
.dash-stat-card__label  { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #6B7280; }
.dash-stat-card__value  { font-size: 2.25rem; font-weight: 700; letter-spacing: -.04em; line-height: 1; }
.dash-stat-card__sub    { font-size: .75rem; color: #9CA3AF; margin-top: .1rem; }
.dash-stat-card__icon   { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
</style>
