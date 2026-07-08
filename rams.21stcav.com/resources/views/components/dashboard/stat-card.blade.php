@props([
    'title',
    'value'    => '—',
    'subtitle' => null,
    'href'     => null,
    'color'    => '#4F46E5',  {{-- Tier-one default = indigo brand-600 --}}
])

@php
    $tag   = $href ? 'a' : 'div';
    $attrs = $href ? "href=\"{$href}\"" : '';
@endphp

<{{ $tag }} {{ $attrs }} class="dash-stat-card {{ $href ? 'dash-stat-card--link' : '' }}">
    <div class="dash-stat-card__body">
        <div class="dash-stat-card__label">{{ $title }}</div>
        <div class="dash-stat-card__value tabular">{{ $value }}</div>
        @if($subtitle)
        <div class="dash-stat-card__sub">{{ $subtitle }}</div>
        @endif
    </div>

    @if($slot->isNotEmpty())
    <div class="dash-stat-card__icon" style="background:{{ $color }}14; color:{{ $color }}">
        {{ $slot }}
    </div>
    @endif
</{{ $tag }}>

@once
<style>
/*
 * Dashboard stat card — tier-one (PLAN 260708-b7i, 2026-07-08).
 *
 * Value renders in ink (not the accent hex) — the coloured icon tile
 * carries the semantic meaning, the number stays in a single voice.
 * That matches Linear / Attio / Vercel — the accent lives in an icon
 * tile, a badge, or a trend line, never in the raw stat value itself.
 */
.dash-stat-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #E2E8F0);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    padding: 16px 18px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    transition: box-shadow 150ms ease, border-color 150ms ease, transform 150ms ease;
}
.dash-stat-card--link:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--border-strong, #CBD5E1);
    text-decoration: none;
}
.dash-stat-card__body   { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.dash-stat-card__label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted, #64748B);
}
.dash-stat-card__value {
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -.025em;
    line-height: 1;
    color: var(--ink-900, #0B1220);
    font-variant-numeric: tabular-nums;
}
.dash-stat-card__sub {
    font-size: 11px;
    color: var(--text-muted, #64748B);
    margin-top: 2px;
}
.dash-stat-card__icon {
    width: 40px; height: 40px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.dash-stat-card__icon svg { width: 18px; height: 18px; }
</style>
@endonce
