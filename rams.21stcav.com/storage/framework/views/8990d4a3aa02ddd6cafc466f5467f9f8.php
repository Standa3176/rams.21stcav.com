<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'status',              
    'label'  => null,      
    'color'  => null,      
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'status',              
    'label'  => null,      
    'color'  => null,      
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    /* Map project status keys → brand colors.
       Falls back to the supplied $color prop, then a neutral grey. */
    $colorMap = [
        'quote_imported' => '#6B7280',   // grey
        'survey_pending' => '#D97706',   // amber
        'engineering'    => '#2563EB',   // blue
        'installing'     => '#7C3AED',   // purple
        'commissioning'  => '#0891B2',   // cyan
        'handover'       => '#178A95',   // brand teal
        'completed'      => '#16A34A',   // green
        'archived'       => '#9CA3AF',   // light grey

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
?>

<span class="dash-status-badge"
      style="
          background: <?php echo e($resolvedColor); ?>18;
          color: <?php echo e($resolvedColor); ?>;
          border: 1px solid <?php echo e($resolvedColor); ?>40;
      ">
    <span class="dash-status-badge__dot" style="background:<?php echo e($resolvedColor); ?>"></span>
    <?php echo e($displayLabel); ?>

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
<?php /**PATH /home/stcav/rams.21stcav.com/resources/views/components/dashboard/status-badge.blade.php ENDPATH**/ ?>