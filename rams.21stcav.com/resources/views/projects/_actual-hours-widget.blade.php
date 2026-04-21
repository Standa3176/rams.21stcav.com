{{--
    Actual Hours widget (Phase 15 D-13/D-14/D-15/D-16).

    Renders total hours + 4-category horizontal bar breakdown.

    Required view vars:
      $actualHours        array|null  [
        'total_minutes' => int,
        'per_category'  => ['installation' => int, 'commissioning' => int, 'testing' => int, 'other' => int],
      ]

    Visibility is enforced in the parent view via `@if ($canSeeActualHours && $actualHours !== null)`.
    This partial itself does NOT re-check — it trusts the include gate.
--}}
@php
    $total  = (int) ($actualHours['total_minutes'] ?? 0);
    $perCat = $actualHours['per_category'] ?? [
        'installation' => 0, 'commissioning' => 0, 'testing' => 0, 'other' => 0,
    ];
    $fmt = static function (int $m): string {
        $h   = intdiv($m, 60);
        $rem = $m % 60;
        return $h . 'h ' . $rem . 'm';
    };
    $categories = [
        'installation'  => ['label' => 'Installation',  'colour' => '#178A95'],
        'commissioning' => ['label' => 'Commissioning', 'colour' => '#21A8B5'],
        'testing'       => ['label' => 'Testing',       'colour' => '#4FB8C2'],
        'other'         => ['label' => 'Other',         'colour' => '#9CA3AF'],
    ];
@endphp

<x-section-card title="Actual Hours">
    @if ($total === 0)
        <div class="actual-hours-empty">
            <p class="actual-hours-empty__text">No time tracked yet.</p>
            <p class="actual-hours-empty__hint">Engineers clock in from the mobile field page.</p>
        </div>
    @else
        <div class="actual-hours-total">
            <span class="actual-hours-total__value">{{ $fmt($total) }}</span>
            <span class="actual-hours-total__label">total</span>
        </div>

        <ul class="actual-hours-breakdown">
            @foreach ($categories as $key => $meta)
                @php
                    $mins = (int) ($perCat[$key] ?? 0);
                    // Proportion based on total = 100% baseline. If a category has 0 min,
                    // render a 2px placeholder sliver so the visual rhythm is preserved.
                    $pct = $total > 0 ? max(2, (int) round($mins / $total * 100)) : 2;
                @endphp
                <li class="actual-hours-row">
                    <div class="actual-hours-row__label">
                        {{ $meta['label'] }}
                        <span class="actual-hours-row__value">({{ $fmt($mins) }})</span>
                    </div>
                    <div class="actual-hours-bar-track" aria-hidden="true">
                        <div class="actual-hours-bar"
                             style="width: {{ $mins > 0 ? $pct : 2 }}%; background: {{ $meta['colour'] }};"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-section-card>

@push('styles')
<style>
/* ── Actual Hours widget (Phase 15) ─────────────────────────────────────── */
.actual-hours-total {
    display: flex;
    align-items: baseline;
    gap: .5rem;
    margin-bottom: 1rem;
}
.actual-hours-total__value {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--teal, #178A95);
    line-height: 1;
}
.actual-hours-total__label {
    font-size: .75rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: .06em;
}
.actual-hours-breakdown {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: .6rem;
}
.actual-hours-row {
    display: flex;
    flex-direction: column;
    gap: .25rem;
}
.actual-hours-row__label {
    font-size: .8125rem;
    font-weight: 600;
    color: var(--text, #111827);
    display: flex;
    justify-content: space-between;
}
.actual-hours-row__value {
    font-weight: 400;
    color: var(--text-muted, #6b7280);
    font-size: .78rem;
}
.actual-hours-bar-track {
    height: 8px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
}
.actual-hours-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 300ms ease-out;
}
.actual-hours-empty {
    padding: .5rem 0;
}
.actual-hours-empty__text {
    font-size: .9rem;
    color: var(--text, #111827);
    font-weight: 500;
    margin: 0 0 .25rem;
}
.actual-hours-empty__hint {
    font-size: .8rem;
    color: var(--text-muted, #6b7280);
    margin: 0;
}
</style>
@endpush
