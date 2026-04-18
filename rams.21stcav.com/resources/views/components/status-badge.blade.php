{{--
    <x-status-badge :status="$project->status" />
    <x-status-badge status="approved" label="Approved" />

    Props:
      status — status key string, e.g. "quote_imported"
      label  — override display text (optional)
      pulse  — bool, animate dot (optional, default auto for in-progress statuses)
--}}

@props([
    'status',
    'label' => null,
    'pulse' => null,
])

@php
    /* Map status keys → sb--* colour class */
    $classMap = [
        // Project lifecycle
        'quote_imported'          => 'sb--grey',
        'survey_pending'          => 'sb--amber',
        'engineering'             => 'sb--blue',
        'installing'              => 'sb--purple',
        'commissioning'           => 'sb--cyan',
        'handover'                => 'sb--teal',
        'completed'               => 'sb--green',
        'archived'                => 'sb--grey',

        // RAMS / document statuses
        'uploaded'                => 'sb--amber',
        'awaiting_review'         => 'sb--amber',
        'approved'                => 'sb--green',
        'approved_for_generation' => 'sb--green',
        'generating'              => 'sb--amber',
        'rendering'               => 'sb--amber',
        'processing'              => 'sb--amber',
        'complete'                => 'sb--green',
        'for_review'              => 'sb--green',
        'failed'                  => 'sb--red',
        'superseded'              => 'sb--grey',

        // Generic aliases
        'active'                  => 'sb--green',
        'draft'                   => 'sb--grey',
        'pending'                 => 'sb--amber',
        'cancelled'               => 'sb--red',
    ];

    /* Statuses that should pulse by default */
    $pulseStatuses = ['generating', 'rendering', 'processing', 'survey_pending', 'engineering', 'installing', 'commissioning'];

    $colourClass  = $classMap[strtolower($status)] ?? 'sb--grey';
    $shouldPulse  = $pulse ?? in_array(strtolower($status), $pulseStatuses);

    $displayLabel = $label
        ?? \App\Models\Project::STATUS_LABELS[strtolower($status)]
        ?? ucwords(str_replace('_', ' ', $status));
@endphp

<span class="status-badge {{ $colourClass }} {{ $shouldPulse ? 'sb--pulse' : '' }}"
      {{ $attributes }}>
    <span class="status-badge__dot" aria-hidden="true"></span>
    {{ $displayLabel }}
</span>
