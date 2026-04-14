@props([
    'status',              {{-- status key string, e.g. "quote_imported"   --}}
    'label'  => null,      {{-- override display text                       --}}
    'color'  => null,      {{-- override hex color, e.g. "#28a745"          --}}
])

@php
    /* Map project status keys → brand colors.
       Falls back to the supplied $color prop, then a neutral grey. */
    $colorMap = [
        // Project lifecycle statuses
        'quote_imported' => '#6B7280',   // grey
        'survey_pending' => '#D97706',   // amber
        'engineering'    => '#2563EB',   // blue
        'installing'     => '#7C3AED',   // purple
        'commissioning'  => '#0891B2',   // cyan
        'handover'       => '#178A95',   // brand teal
        'completed'      => '#16A34A',   // green
        'archived'       => '#9CA3AF',   // light grey

        // RAMS / document statuses — traffic light
        'failed'                  => '#DC2626',   // red
        'generating'              => '#D97706',   // amber
        'rendering'               => '#D97706',   // amber
        'processing'              => '#D97706',   // amber
        'uploaded'                => '#D97706',   // amber
        'awaiting_review'         => '#D97706',   // amber
        'approved'                => '#16A34A',   // green
        'approved_for_generation' => '#16A34A',   // green
        'complete'                => '#16A34A',   // green
        'for_review'              => '#16A34A',   // green
        'superseded'              => '#9CA3AF',   // light grey

        // generic aliases
        'active'         => '#16A34A',
        'draft'          => '#6B7280',
        'pending'        => '#D97706',
        'cancelled'      => '#DC2626',
    ];

    $resolvedColor = $color
        ?? $colorMap[strtolower($status)] ?? '#6B7280';

    $displayLabel = $label
        ?? \App\Models\Project::STATUS_LABELS[strtolower($status)]
        ?? ucwords(str_replace('_', ' ', $status));
@endphp

<span class="dash-status-badge"
      style="
          background: {{ $resolvedColor }}18;
          color: {{ $resolvedColor }};
          border: 1px solid {{ $resolvedColor }}40;
      ">
    <span class="dash-status-badge__dot" style="background:{{ $resolvedColor }}"></span>
    {{ $displayLabel }}
</span>

<style>
.dash-status-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .25rem .65rem;
    border-radius: 9999px;
    font-size: .6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
}
.dash-status-badge__dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    flex-shrink: 0;
}
</style>
