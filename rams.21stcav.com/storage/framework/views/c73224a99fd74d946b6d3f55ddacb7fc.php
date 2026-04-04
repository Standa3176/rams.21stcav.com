<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title',
    'breadcrumb' => null,
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
    'title',
    'breadcrumb' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="dash-page-header">
    <div class="dash-page-header__left">
        <?php if($breadcrumb): ?>
        <div class="dash-page-header__breadcrumb"><?php echo e($breadcrumb); ?></div>
        <?php endif; ?>
        <h1 class="dash-page-header__title"><?php echo e($title); ?></h1>
    </div>

    <?php if(isset($actions) && $actions->isNotEmpty()): ?>
    <div class="dash-page-header__actions">
        <?php echo e($actions); ?>

    </div>
    <?php endif; ?>
</div>

<style>
.dash-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 1.75rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.dash-page-header__left         { display: flex; flex-direction: column; gap: .2rem; }
.dash-page-header__breadcrumb   { font-size: .75rem; color: #9CA3AF; font-weight: 500; letter-spacing: .02em; }
.dash-page-header__title        { font-size: 1.375rem; font-weight: 700; color: #1F2937; letter-spacing: -.02em; line-height: 1.2; }
.dash-page-header__actions      { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
</style>
<?php /**PATH /home/stcav/rams.21stcav.com/resources/views/components/dashboard/page-header.blade.php ENDPATH**/ ?>