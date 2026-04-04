<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title'     => null,
    'emptyText' => 'No records found.',
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
    'title'     => null,
    'emptyText' => 'No records found.',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="dash-table-wrapper">

    <?php if($title || (isset($header) && $header->isNotEmpty())): ?>
    <div class="dash-table-wrapper__header">
        <?php if($title): ?>
        <div class="dash-table-wrapper__title"><?php echo e($title); ?></div>
        <?php endif; ?>
        <?php if(isset($header) && $header->isNotEmpty()): ?>
        <div class="dash-table-wrapper__header-actions"><?php echo e($header); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dash-table-wrapper__body">
        <?php echo e($slot); ?>

    </div>

    <?php if(isset($footer) && $footer->isNotEmpty()): ?>
    <div class="dash-table-wrapper__footer">
        <?php echo e($footer); ?>

    </div>
    <?php endif; ?>

</div>

<style>
.dash-table-wrapper {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.dash-table-wrapper__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #E5E7EB;
    gap: 1rem;
    flex-wrap: wrap;
}
.dash-table-wrapper__title          { font-size: .9375rem; font-weight: 600; color: #1F2937; }
.dash-table-wrapper__header-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.dash-table-wrapper__body           { overflow-x: auto; }
.dash-table-wrapper__footer {
    padding: .85rem 1.25rem;
    border-top: 1px solid #E5E7EB;
    background: #FAFAFA;
}
</style>
<?php /**PATH /home/stcav/rams.21stcav.com/resources/views/components/dashboard/table-wrapper.blade.php ENDPATH**/ ?>