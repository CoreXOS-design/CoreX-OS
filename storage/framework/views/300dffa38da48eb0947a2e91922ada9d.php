<?php $__env->startSection('nexus-content'); ?>

<?php
    $summary = $snapshot->getOutputSummaryArray();
    $inputs  = $snapshot->getInputsArray();
?>


<div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Snapshot #<?php echo e($snapshot->id); ?></h2>
            <div class="text-sm text-white/60">
                Presentation #<?php echo e($presentation->id); ?>

                &nbsp;·&nbsp; Saved <?php echo e($snapshot->created_at->format('Y-m-d H:i')); ?>

                <?php if($snapshot->market_analytics_run_id): ?>
                    &nbsp;·&nbsp; MA run #<?php echo e($snapshot->market_analytics_run_id); ?>

                <?php endif; ?>
                <?php if($snapshot->sale_probability_run_id): ?>
                    &nbsp;·&nbsp; SP run #<?php echo e($snapshot->sale_probability_run_id); ?>

                <?php endif; ?>
            </div>
        </div>
        <a href="<?php echo e(route('presentations.index')); ?>"
           class="nexus-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; Back to Presentations
        </a>
    </div>
</div>

<?php if(session('success')): ?>
    <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">
        <?php echo e(session('success')); ?>

    </div>
<?php endif; ?>


<?php if(!empty($inputs)): ?>
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header">Inputs (locked)</h2>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3 lg:grid-cols-4">
        <?php $__currentLoopData = [
            'suburb'        => 'Suburb',
            'type'          => 'Property Type',
            'period_months' => 'Period',
            'price'         => 'Asking Price',
            'size_m2'       => 'Floor Area (m²)',
            'bedrooms'      => 'Bedrooms',
            'branch_id'     => 'Branch ID',
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php if(isset($inputs[$key]) && $inputs[$key] !== null && $inputs[$key] !== ''): ?>
            <div>
                <dt class="text-xs text-gray-400 mb-0.5"><?php echo e($label); ?></dt>
                <dd class="font-semibold text-gray-800">
                    <?php if($key === 'type'): ?>
                        <?php echo e(ucfirst($inputs[$key])); ?>

                    <?php elseif($key === 'period_months'): ?>
                        <?php echo e($inputs[$key]); ?> months
                    <?php elseif($key === 'price'): ?>
                        R<?php echo e(number_format($inputs[$key], 0)); ?>

                    <?php elseif($key === 'size_m2'): ?>
                        <?php echo e($inputs[$key]); ?> m²
                    <?php else: ?>
                        <?php echo e($inputs[$key]); ?>

                    <?php endif; ?>
                </dd>
            </div>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </dl>
</div>
<?php endif; ?>


<div class="bg-gradient-to-br from-[#0b2a4a] to-[#061a30] rounded-xl shadow-lg p-6 mb-6 text-white">
    <p class="text-sky-200 text-xs font-semibold uppercase tracking-widest mb-1">Snapshot — Seller Summary</p>
    <h2 class="text-xl font-bold mb-5">Sale Probability at Your Price</h2>

    <?php if(!empty($summary['skip_reason'])): ?>
        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-sm text-sky-100">
            <strong class="text-white">Insufficient data:</strong>
            <?php echo e($summary['skip_reason']); ?>

        </div>
    <?php else: ?>
        <div class="grid grid-cols-3 gap-3 mb-5">
            <?php $__currentLoopData = [
                ['label' => 'Sold in 30 days', 'key' => 'p30'],
                ['label' => 'Sold in 60 days', 'key' => 'p60'],
                ['label' => 'Sold in 90 days', 'key' => 'p90'],
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $chip): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="bg-white/10 border border-white/20 rounded-lg p-4 text-center">
                <p class="text-sky-200 text-xs mb-1 font-medium"><?php echo e($chip['label']); ?></p>
                <p class="text-3xl font-bold">
                    <?php if(isset($summary[$chip['key']]) && $summary[$chip['key']] !== null): ?>
                        <?php echo e(number_format($summary[$chip['key']] * 100, 0)); ?><span class="text-xl">%</span>
                    <?php else: ?>
                        <span class="text-base font-normal text-sky-300 italic">—</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>

        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 flex items-center justify-between">
            <span class="text-sky-200 text-sm">Estimated time to sell</span>
            <?php if(isset($summary['expected_days']) && $summary['expected_days'] !== null): ?>
                <span class="text-white font-bold text-lg"><?php echo e($summary['expected_days']); ?> days</span>
            <?php else: ?>
                <span class="text-sky-300 text-sm italic">Insufficient data</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if($maRun && $spRun): ?>
    <p class="mt-4 text-sky-300 text-xs text-right font-mono">
        MA <?php echo e($maRun->model_version); ?> · SP <?php echo e($spRun->model_version); ?> · run #<?php echo e($spRun->id); ?>

    </p>
    <?php endif; ?>
</div>


<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header" style="margin-bottom:0.25rem;">Market Evidence (locked)</h2>
    <p class="text-xs text-gray-400 mb-4">Values frozen at snapshot time.</p>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm md:grid-cols-3">
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Months of Inventory</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['months_of_inventory']) && $summary['months_of_inventory'] !== null): ?>
                    <?php echo e(number_format($summary['months_of_inventory'], 1)); ?> mo
                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Demand / Supply Ratio</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['demand_supply_ratio']) && $summary['demand_supply_ratio'] !== null): ?>
                    <?php echo e(number_format($summary['demand_supply_ratio'], 2)); ?>×
                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Price/m² vs Market</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['price_per_sqm_deviation_pct']) && $summary['price_per_sqm_deviation_pct'] !== null): ?>
                    <?php echo e(number_format($summary['price_per_sqm_deviation_pct'], 1)); ?>%
                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM Median (p50)</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['dom_p50']) && $summary['dom_p50'] !== null): ?>
                    <?php echo e($summary['dom_p50']); ?> days
                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM p75</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['dom_p75']) && $summary['dom_p75'] !== null): ?>
                    <?php echo e($summary['dom_p75']); ?> days
                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Elasticity (days/%)</dt>
            <dd class="font-semibold text-gray-800">
                <?php if(isset($summary['elasticity_days_per_pct']) && $summary['elasticity_days_per_pct'] !== null): ?>
                    <?php echo e(number_format($summary['elasticity_days_per_pct'], 2)); ?>

                <?php else: ?>
                    <span class="text-gray-300">—</span>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
</div>


<?php
    $sensitivityDrops = [
        ['label' => 'Drop R50,000',  'key' => 'sensitivity_drop_50k'],
        ['label' => 'Drop R100,000', 'key' => 'sensitivity_drop_100k'],
        ['label' => 'Drop R150,000', 'key' => 'sensitivity_drop_150k'],
    ];
    $hasAnySensitivity = collect($sensitivityDrops)->contains(fn($d) => !empty($summary[$d['key']]));
?>
<?php if($hasAnySensitivity): ?>
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header" style="margin-bottom:0.25rem;">Price Sensitivity (locked)</h2>
    <p class="text-xs text-gray-400 mb-4">Quick scenario cards frozen at snapshot time.</p>
    <div class="grid grid-cols-3 gap-3">
        <?php $__currentLoopData = $sensitivityDrops; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $drop): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $row    = $summary[$drop['key']] ?? null;
                $baseP60 = $summary['p60'] ?? null;
            ?>
            <div class="border border-gray-200 rounded-lg p-4">
                <p class="text-xs text-gray-500 font-medium mb-2"><?php echo e($drop['label']); ?></p>
                <?php if($row && $row['p60'] !== null): ?>
                    <p class="text-2xl font-bold text-gray-800 mb-1">
                        <?php echo e(number_format($row['p60'] * 100, 0)); ?><span class="text-base">%</span>
                        <span class="text-sm text-gray-400 font-normal ml-1">p60</span>
                    </p>
                    <?php if($baseP60 !== null): ?>
                        <?php $delta = ($row['p60'] - $baseP60) * 100; ?>
                        <p class="text-xs <?php echo e($delta >= 0 ? 'text-green-600' : 'text-red-500'); ?> font-medium">
                            <?php if($delta >= 0): ?>+<?php endif; ?><?php echo e(number_format($delta, 1)); ?> pp vs base
                        </p>
                    <?php endif; ?>
                    <?php if(isset($row['expected_days']) && $row['expected_days'] !== null && isset($summary['expected_days']) && $summary['expected_days'] !== null): ?>
                        <?php $daysDelta = $row['expected_days'] - $summary['expected_days']; ?>
                        <p class="text-xs <?php echo e($daysDelta <= 0 ? 'text-green-600' : 'text-red-500'); ?> mt-0.5">
                            <?php if($daysDelta < 0): ?>
                                <?php echo e($daysDelta); ?> days faster
                            <?php elseif($daysDelta > 0): ?>
                                +<?php echo e($daysDelta); ?> days slower
                            <?php else: ?>
                                No change in days
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic">—</p>
                <?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>
<?php endif; ?>


<div class="mb-2">
    <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-widest">Audit Mode</h2>
</div>

<?php if($maRun): ?>
<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Market Analytics breakdown (MA run #<?php echo e($maRun->id); ?> · <?php echo e($maRun->model_version); ?>)
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap"><?php echo e(json_encode($maRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
</details>
<?php endif; ?>

<?php if($spRun): ?>
<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Sale Probability breakdown (SP run #<?php echo e($spRun->id); ?> · <?php echo e($spRun->model_version); ?>)
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap"><?php echo e(json_encode($spRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
</details>
<?php endif; ?>

<details class="mb-8">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Snapshot output_summary_json (raw)
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap"><?php echo e(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
</details>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/presentations/snapshot.blade.php ENDPATH**/ ?>