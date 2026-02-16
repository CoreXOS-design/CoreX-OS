<?php if(($cmaCount ?? 0) > 0): ?>
    <?php
        $cp = (int)($cmaCorrectlyPricedPercent ?? 0);
        $op = (int)($cmaOverpricedPercent ?? 0);
    ?>

    <div class="mt-2 text-xs text-gray-600 bg-gray-50 border rounded p-2">
        CMA Coverage: <?php echo e($cmaCount); ?> listings |
        Overpriced: <?php echo e($op); ?>% |
        Correctly Priced: <?php echo e($cp); ?>%
    </div>
<?php endif; ?>
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/worksheet/_cma_pricing_info.blade.php ENDPATH**/ ?>