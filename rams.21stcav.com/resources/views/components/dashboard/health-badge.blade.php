@props(['health'])

@php
    /* Maps ProjectHealth DTO status values → pill colours.
       Reuses the .dash-status-badge and .dash-status-badge__dot classes
       defined in components/dashboard/status-badge.blade.php — we only
       override the colour inline so we never duplicate the style block. */
    $colourMap = [
        'green' => '#16A34A',
        'amber' => '#D97706',
        'red'   => '#DC2626',
    ];
    $labelMap = [
        'green' => 'Healthy',
        'amber' => 'Warning',
        'red'   => 'Blocked',
    ];
    $colour = $colourMap[$health->status] ?? '#6B7280';
    $label  = $labelMap[$health->status]  ?? ucfirst($health->status);
@endphp

<span class="dash-status-badge"
      title="{{ $health->reason }}"
      style="background:{{ $colour }}18; color:{{ $colour }}; border:1px solid {{ $colour }}40;">
    <span class="dash-status-badge__dot" style="background:{{ $colour }}"></span>
    {{ $label }}
    @if($health->overdue)
        <span style="margin-left:.2rem; opacity:.8;" title="Stage overdue > 14 days">&#9679;</span>
    @endif
</span>
