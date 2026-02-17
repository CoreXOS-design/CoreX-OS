<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title', 'value', 'trend' => 0, 'trendUp' => true, 'iconBg' => 'bg-indigo-100 text-indigo-600']));

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

foreach (array_filter((['title', 'value', 'trend' => 0, 'trendUp' => true, 'iconBg' => 'bg-indigo-100 text-indigo-600']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="nexus-kpi-card">
    <div class="flex items-start justify-between">
        <div>
            <p class="nexus-kpi-title"><?php echo e($title); ?></p>
            <p class="nexus-kpi-value"><?php echo e($value); ?></p>
            <div class="nexus-kpi-trend <?php echo e($trendUp ? 'up' : 'down'); ?>">
                <?php if($trendUp): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                    </svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181" />
                    </svg>
                <?php endif; ?>
                <span><?php echo e(abs($trend)); ?>%</span>
                <span class="nexus-kpi-subtitle">from last month</span>
            </div>
        </div>
        <?php if(isset($icon)): ?>
            <div class="nexus-kpi-icon <?php echo e($iconBg); ?>">
                <?php echo e($icon); ?>

            </div>
        <?php endif; ?>
    </div>
</div>
<?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/components/nexus-kpi-card.blade.php ENDPATH**/ ?>