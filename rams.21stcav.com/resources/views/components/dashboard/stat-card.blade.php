@props([
    'title',
    'value'    => '—',
    'subtitle' => null,
    'href'     => null,
    'color'    => null,  {{-- Jetbuilt-clean default = single accent. Rare
                             per-tile overrides still accepted via the prop. --}}
])

@php
    /*
     * Colour resolution — a legacy call passes a per-tile hex; we honour
     * that but the Jetbuilt-clean default is to use the CSS accent
     * variable so every KPI tile in the row reads as a set instead of
     * four competing colours.
     */
    $iconStyle = $color
        ? "background: color-mix(in oklab, {$color} 12%, transparent); color: {$color};"
        : "background: var(--accent-50); color: var(--accent-700);";

    /*
     * Re-audit UI-01 / UX-01 fix — the old `$attrs = "href=\"{$href}\""`
     * pattern printed via `{{ $attrs }}` HTML-escaped the quotes, so every
     * KPI tile rendered `href="&quot;http…&quot;"` and 404'd on click.
     * Split into two explicit branches so the anchor emits a real
     * attribute and the div branch stays plain.
     */
@endphp

@if ($href)
    <a href="{{ $href }}" class="dash-stat-card dash-stat-card--link">
@else
    <div class="dash-stat-card">
@endif
    <div class="dash-stat-card__body">
        <div class="dash-stat-card__label">{{ $title }}</div>
        <div class="dash-stat-card__value tabular">{{ $value }}</div>
        @if($subtitle)
            <div class="dash-stat-card__sub">{{ $subtitle }}</div>
        @endif
    </div>

    @if($slot->isNotEmpty())
        <div class="dash-stat-card__icon" style="{{ $iconStyle }}">
            {{ $slot }}
        </div>
    @endif
@if ($href)
    </a>
@else
    </div>
@endif

@once
<style>
/*
 * Dashboard stat card — Jetbuilt clean (2026-07-09).
 *
 * Single accent for every tile. Value stays in ink so the four stats
 * read as a rank-ordered set; the accent-tinted icon chip does the
 * semantic work.
 */
.dash-stat-card {
    background: var(--surface);
    border: 1px solid var(--ink-200);
    border-radius: var(--radius-lg);
    box-shadow: none;
    padding: 18px 20px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    transition: border-color var(--transition);
}
.dash-stat-card--link:hover {
    border-color: var(--ink-300);
    text-decoration: none;
}
.dash-stat-card__body  { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.dash-stat-card__label {
    font-size: var(--fs-small);
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
    color: var(--ink-500);
}
.dash-stat-card__value {
    font-size: 30px;
    font-weight: 600;
    letter-spacing: -.025em;
    line-height: 1.1;
    color: var(--ink-900);
    font-variant-numeric: tabular-nums;
}
.dash-stat-card__sub {
    font-size: var(--fs-small);
    color: var(--ink-500);
    margin-top: 2px;
}
.dash-stat-card__icon {
    width: 36px; height: 36px;
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.dash-stat-card__icon svg { width: 16px; height: 16px; }
</style>
@endonce
