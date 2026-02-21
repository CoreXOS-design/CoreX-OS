<?php $__env->startSection('nexus-content'); ?>

<?php
    $statusClasses = match($presentation->status) {
        'presented' => 'bg-blue-100 text-blue-700',
        'locked'    => 'bg-green-100 text-green-700',
        default     => 'bg-gray-100 text-gray-600',
    };
    $lastSummary = $latestSnapshot ? $latestSnapshot->getOutputSummaryArray() : null;
?>


<div class="mb-6 flex items-start justify-between">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <h1 class="text-2xl font-bold text-gray-800"><?php echo e($presentation->title); ?></h1>
            <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo e($statusClasses); ?>">
                <?php echo e(ucfirst($presentation->status)); ?>

            </span>
        </div>
        <p class="text-sm text-gray-600"><?php echo e($presentation->property_address ?? 'No address set'); ?></p>

        
        <?php
            $propDetails = array_filter([
                $presentation->suburb,
                $presentation->property_type ? ucfirst($presentation->property_type) : null,
                $presentation->bedrooms ? $presentation->bedrooms . ' bed' : null,
                $presentation->floor_area_m2 ? $presentation->floor_area_m2 . ' m²' : null,
            ]);
        ?>
        <?php if(!empty($propDetails)): ?>
            <p class="text-xs text-gray-500 mt-0.5"><?php echo e(implode(' · ', $propDetails)); ?></p>
        <?php endif; ?>

        <?php if($presentation->seller_name): ?>
            <p class="text-xs text-gray-400 mt-0.5">Seller: <?php echo e($presentation->seller_name); ?></p>
        <?php endif; ?>
        <p class="text-xs text-gray-400 mt-0.5">Created <?php echo e($presentation->created_at->format('Y-m-d')); ?></p>
    </div>
    <a href="<?php echo e(route('presentations.index')); ?>"
       class="text-xs text-indigo-600 hover:underline mt-1">← All Presentations</a>
</div>

<?php if(session('success')): ?>
    <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">
        <?php echo e(session('success')); ?>

    </div>
<?php endif; ?>


<div class="flex flex-wrap gap-3 mb-6">
    <?php if($readiness['can_compile']): ?>
        <a href="<?php echo e(route('presentations.analysis', $presentation)); ?>"
           class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
            <?php echo e($latestSnapshot ? 'Re-run Analysis' : 'Run Analysis'); ?>

        </a>
    <?php else: ?>
        <span class="px-4 py-2 bg-gray-400 text-white text-sm font-medium rounded cursor-not-allowed"
              title="Complete the required evidence items below before running analysis">
            <?php echo e($latestSnapshot ? 'Re-run Analysis' : 'Run Analysis'); ?>

        </span>
    <?php endif; ?>
    <?php if($latestSnapshot): ?>
        <a href="<?php echo e(route('presentations.snapshots.show', [$presentation, $latestSnapshot])); ?>"
           class="px-4 py-2 border border-gray-300 text-gray-600 text-sm font-medium rounded hover:bg-gray-50">
            Latest Snapshot →
        </a>
    <?php endif; ?>
    <?php if(config('features.presentation_brain_ui_v1')): ?>
        <?php if($latestSnapshot): ?>
            <a href="<?php echo e(route('presentations.brain', $presentation)); ?>"
               class="px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded hover:bg-purple-700">
                Brain Simulation
            </a>
        <?php else: ?>
            <span class="px-4 py-2 bg-gray-400 text-white text-sm font-medium rounded cursor-not-allowed"
                  title="Run analysis and save a snapshot first">
                Brain Simulation
            </span>
        <?php endif; ?>
    <?php endif; ?>
    <?php if(config('features.presentation_blueprint')): ?>
        <form method="POST" action="<?php echo e(route('presentations.compile', $presentation)); ?>" class="inline">
            <?php echo csrf_field(); ?>
            <button type="submit"
                    class="px-4 py-2 <?php echo e($readiness['can_compile'] ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-400 cursor-not-allowed'); ?> text-white text-sm font-medium rounded"
                    <?php echo e($readiness['can_compile'] ? '' : 'disabled title="Missing required evidence — see checklist below"'); ?>>
                Compile Pack
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if(session('error')): ?>
    <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded text-sm">
        <?php echo e(session('error')); ?>

    </div>
<?php endif; ?>


<div class="mb-6 bg-white rounded-xl shadow p-5">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-700">Pack Readiness</h2>
        <span class="text-xs font-medium <?php echo e($readiness['completed_percent'] >= 100 ? 'text-green-600' : ($readiness['completed_percent'] >= 57 ? 'text-amber-600' : 'text-red-500')); ?>">
            <?php echo e($readiness['completed_percent']); ?>% complete
        </span>
    </div>

    
    <div class="w-full bg-gray-100 rounded-full h-1.5 mb-4">
        <div class="h-1.5 rounded-full <?php echo e($readiness['can_compile'] ? 'bg-green-500' : 'bg-amber-400'); ?>"
             style="width: <?php echo e($readiness['completed_percent']); ?>%"></div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        
        <div>
            <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wide">Required</p>
            <ul class="space-y-1.5">
                <?php $__currentLoopData = $readiness['required_items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex items-start gap-2 text-xs">
                        <span class="<?php echo e($item['satisfied'] ? 'text-green-500' : 'text-red-400'); ?> mt-0.5 shrink-0">
                            <?php echo e($item['satisfied'] ? '✓' : '✗'); ?>

                        </span>
                        <span class="<?php echo e($item['satisfied'] ? 'text-gray-600' : 'text-gray-700 font-medium'); ?>">
                            <?php echo e($item['label']); ?>

                        </span>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>

        
        <div>
            <p class="text-xs font-medium text-gray-500 mb-2 uppercase tracking-wide">Optional</p>
            <ul class="space-y-1.5">
                <?php $__currentLoopData = $readiness['optional_items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex items-start gap-2 text-xs">
                        <span class="<?php echo e($item['satisfied'] ? 'text-green-500' : 'text-gray-300'); ?> mt-0.5 shrink-0">
                            <?php echo e($item['satisfied'] ? '✓' : '○'); ?>

                        </span>
                        <span class="text-gray-500"><?php echo e($item['label']); ?></span>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    </div>

    <?php if($readiness['can_compile']): ?>
        <p class="mt-3 text-xs text-green-600 font-medium">All required items present — ready to compile.</p>
    <?php else: ?>
        <p class="mt-3 text-xs text-red-500">
            Missing: <?php echo e(implode(', ', array_column($readiness['missing_required'], 'label'))); ?>

        </p>
    <?php endif; ?>
</div>


<?php if($powerPanel): ?>
<div class="mb-6 bg-white rounded-xl shadow p-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-gray-700">Power Panel</h2>
        <span class="text-xs text-gray-400">Snapshot <?php echo e($powerPanel['snapshot_at']->format('Y-m-d H:i')); ?></span>
    </div>

    
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 mb-4">
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">P30</p>
            <p class="text-lg font-bold <?php echo e(($powerPanel['p30'] ?? 0) >= 0.5 ? 'text-green-600' : 'text-gray-800'); ?>">
                <?php if($powerPanel['p30'] !== null): ?>
                    <?php echo e(number_format($powerPanel['p30'] * 100, 0)); ?>%
                <?php else: ?>
                    <span class="text-gray-300">--</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">P60</p>
            <p class="text-lg font-bold <?php echo e(($powerPanel['p60'] ?? 0) >= 0.5 ? 'text-green-600' : 'text-gray-800'); ?>">
                <?php if($powerPanel['p60'] !== null): ?>
                    <?php echo e(number_format($powerPanel['p60'] * 100, 0)); ?>%
                <?php else: ?>
                    <span class="text-gray-300">--</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">P90</p>
            <p class="text-lg font-bold <?php echo e(($powerPanel['p90'] ?? 0) >= 0.65 ? 'text-green-600' : 'text-gray-800'); ?>">
                <?php if($powerPanel['p90'] !== null): ?>
                    <?php echo e(number_format($powerPanel['p90'] * 100, 0)); ?>%
                <?php else: ?>
                    <span class="text-gray-300">--</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">Exp. Days</p>
            <p class="text-lg font-bold text-gray-800">
                <?php if($powerPanel['expected_days'] !== null): ?>
                    <?php echo e($powerPanel['expected_days']); ?>

                <?php else: ?>
                    <span class="text-gray-300">--</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">Confidence</p>
            <?php if($powerPanel['confidence']): ?>
                <?php
                    $confScore = $powerPanel['confidence']['confidence_score'] ?? 0;
                    $confGrade = $powerPanel['confidence']['confidence_grade'] ?? '-';
                    $confColor = match($confGrade) {
                        'A' => 'text-green-600',
                        'B' => 'text-blue-600',
                        'C' => 'text-amber-600',
                        default => 'text-red-500',
                    };
                ?>
                <p class="text-lg font-bold <?php echo e($confColor); ?>"><?php echo e($confScore); ?> <span class="text-xs">(<?php echo e($confGrade); ?>)</span></p>
            <?php else: ?>
                <p class="text-lg font-bold text-gray-300">--</p>
            <?php endif; ?>
        </div>
        
        <div class="text-center">
            <p class="text-xs text-gray-400 mb-1">PPI</p>
            <?php if($powerPanel['ppi']): ?>
                <?php
                    $ppiScore = $powerPanel['ppi']['ppi_score'] ?? 0;
                    $ppiLabel = $powerPanel['ppi']['ppi_label'] ?? '-';
                    $ppiColor = match($ppiLabel) {
                        'Strong' => 'text-green-600',
                        'Balanced' => 'text-amber-600',
                        default => 'text-red-500',
                    };
                ?>
                <p class="text-lg font-bold <?php echo e($ppiColor); ?>"><?php echo e($ppiScore); ?> <span class="text-xs">(<?php echo e($ppiLabel); ?>)</span></p>
            <?php else: ?>
                <p class="text-lg font-bold text-gray-300">--</p>
            <?php endif; ?>
        </div>
    </div>

    
    <?php
        $compStock = $powerPanel['competitive_stock'] ?? null;
        $holdingCost = $powerPanel['holding_cost'] ?? null;
    ?>
    <?php if($compStock || $holdingCost): ?>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4 pt-3 border-t border-gray-100">
        <?php if($compStock): ?>
            <div>
                <p class="text-xs text-gray-400">Active Stock</p>
                <p class="text-sm font-semibold text-gray-700"><?php echo e($compStock['total_active_stock'] ?? '--'); ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Below Subject</p>
                <p class="text-sm font-semibold text-gray-700"><?php echo e($compStock['below_subject_count'] ?? '--'); ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Above Subject</p>
                <p class="text-sm font-semibold text-gray-700"><?php echo e($compStock['above_subject_count'] ?? '--'); ?></p>
            </div>
        <?php endif; ?>
        <?php if($holdingCost): ?>
            <div>
                <p class="text-xs text-gray-400">Monthly Hold Cost</p>
                <p class="text-sm font-semibold text-gray-700">R<?php echo e(number_format($holdingCost['monthly_total'] ?? 0, 0)); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    <?php if($powerPanel['explainability']): ?>
        <?php $explain = $powerPanel['explainability']; ?>
        <div class="pt-3 border-t border-gray-100">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                
                <?php if(!empty($explain['key_drivers'])): ?>
                    <div>
                        <p class="text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Key Drivers</p>
                        <ul class="space-y-1">
                            <?php $__currentLoopData = $explain['key_drivers']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $driver): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li class="text-xs text-gray-600 flex items-start gap-1.5">
                                    <span class="text-green-500 mt-0.5 shrink-0">+</span>
                                    <?php echo e($driver); ?>

                                </li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if(!empty($explain['risk_factors'])): ?>
                    <div>
                        <p class="text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Risk Factors</p>
                        <ul class="space-y-1">
                            <?php $__currentLoopData = $explain['risk_factors']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $risk): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li class="text-xs text-gray-600 flex items-start gap-1.5">
                                    <span class="text-red-400 mt-0.5 shrink-0">!</span>
                                    <?php echo e($risk); ?>

                                </li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if(!empty($explain['position_summary'])): ?>
                <p class="mt-2 text-xs text-gray-500 italic"><?php echo e($explain['position_summary']); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    
    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Last Analysis</h2>
        <?php if($lastSummary): ?>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-400 text-xs">60-day sale probability</dt>
                    <dd class="font-semibold text-gray-800">
                        <?php if(isset($lastSummary['p60']) && $lastSummary['p60'] !== null): ?>
                            <?php echo e(number_format($lastSummary['p60'] * 100, 0)); ?>%
                        <?php else: ?>
                            <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-400 text-xs">Expected Days to Sell</dt>
                    <dd class="font-semibold text-gray-800">
                        <?php if(isset($lastSummary['expected_days']) && $lastSummary['expected_days'] !== null): ?>
                            <?php echo e($lastSummary['expected_days']); ?> days
                        <?php else: ?>
                            <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-400 text-xs">Months of Inventory</dt>
                    <dd class="font-semibold text-gray-800">
                        <?php if(isset($lastSummary['months_of_inventory']) && $lastSummary['months_of_inventory'] !== null): ?>
                            <?php echo e(number_format($lastSummary['months_of_inventory'], 1)); ?> mo
                        <?php else: ?>
                            <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-gray-400">
                Snapshot saved <?php echo e($latestSnapshot->created_at->format('Y-m-d H:i')); ?>

            </p>
        <?php else: ?>
            <p class="text-sm text-gray-400 italic">No analysis run yet.</p>
            <?php if($readiness['can_compile']): ?>
                <a href="<?php echo e(route('presentations.analysis', $presentation)); ?>"
                   class="mt-3 inline-block text-xs text-indigo-600 hover:underline">
                    Run first analysis →
                </a>
            <?php else: ?>
                <p class="mt-2 text-xs text-gray-400">Complete the required evidence items above to unlock analysis.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    
    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Snapshots</h2>
        <p class="text-2xl font-bold text-gray-800 mb-1"><?php echo e($snapshotCount); ?></p>
        <p class="text-xs text-gray-400">
            <?php echo e($snapshotCount === 1 ? 'snapshot saved' : 'snapshots saved'); ?>

        </p>
        <?php if($latestSnapshot): ?>
            <a href="<?php echo e(route('presentations.snapshots.show', [$presentation, $latestSnapshot])); ?>"
               class="mt-3 inline-block text-xs text-indigo-600 hover:underline">
                View latest →
            </a>
        <?php endif; ?>
    </div>

</div>


<div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">

    
    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Property Links</h2>

        <?php if($links->isEmpty()): ?>
            <p class="text-xs text-gray-400 italic mb-3">No links added yet.</p>
        <?php else: ?>
            <?php
                $linkTypeLabels = [
                    'property24'         => 'Property24',
                    'lightstone'         => 'Lightstone',
                    'active_listing'     => 'Active Listing',
                    'competitor_listing'  => 'Competitor',
                    'market_article'     => 'Article',
                    'other'              => 'Other',
                ];
            ?>
            <ul class="space-y-3 mb-4">
                <?php $__currentLoopData = $links; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $link): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="border border-gray-100 rounded-lg p-2 text-xs">
                        
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex items-center gap-1 flex-wrap">
                                <?php
                                    $linkColor = in_array($link->type, ['active_listing', 'competitor_listing'])
                                        ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500';
                                ?>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?php echo e($linkColor); ?>">
                                    <?php echo e($linkTypeLabels[$link->type] ?? ucfirst($link->type)); ?>

                                </span>

                                
                                <?php
                                    $lExtStatus = $link->extraction_status ?? 'pending';
                                    $lExtBadge = match($lExtStatus) {
                                        'ok'     => 'bg-green-100 text-green-700',
                                        'failed' => 'bg-red-100 text-red-600',
                                        default  => 'bg-yellow-100 text-yellow-700',
                                    };
                                    $lExtLabel = match($lExtStatus) {
                                        'ok'     => 'Extracted',
                                        'failed' => 'Failed',
                                        default  => 'Pending',
                                    };
                                ?>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?php echo e($lExtBadge); ?>">
                                    <?php echo e($lExtLabel); ?>

                                </span>

                                <?php if (! (config('features.portal_extension_capture_v1') && $link->type === 'property24')): ?>
                                    <form method="POST"
                                          action="<?php echo e(route('presentations.links.re-extract', [$presentation, $link])); ?>"
                                          class="inline">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                                class="inline-block px-1 py-0.5 text-xs text-indigo-500 hover:text-indigo-700"
                                                title="Re-run extraction">&#x27F3;</button>
                                    </form>
                                <?php endif; ?>

                                <?php if($link->isOverridden()): ?>
                                    <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">
                                        Override
                                    </span>
                                <?php endif; ?>

                                <a href="<?php echo e($link->url); ?>" target="_blank" rel="noopener noreferrer"
                                   class="text-indigo-600 hover:underline break-all">
                                    <?php echo e(\Illuminate\Support\Str::limit($link->url, 50)); ?>

                                </a>
                                <?php if($link->notes): ?>
                                    <span class="text-gray-400"> — <?php echo e($link->notes); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <form method="POST"
                                      action="<?php echo e(route('presentations.links.update-type', [$presentation, $link])); ?>"
                                      class="flex items-center gap-1">
                                    <?php echo csrf_field(); ?>
                                    <?php echo method_field('PATCH'); ?>
                                    <select name="type" class="border border-gray-200 rounded px-1 py-0.5 text-xs">
                                        <?php $__currentLoopData = $linkTypeLabels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $val => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <option value="<?php echo e($val); ?>" <?php echo e($link->type === $val ? 'selected' : ''); ?>><?php echo e($label); ?></option>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </select>
                                    <button type="submit"
                                            class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Save</button>
                                </form>
                                <form method="POST"
                                      action="<?php echo e(route('presentations.links.destroy', [$presentation, $link])); ?>">
                                    <?php echo csrf_field(); ?>
                                    <?php echo method_field('DELETE'); ?>
                                    <button type="submit"
                                            class="text-red-400 hover:text-red-600 text-xs"
                                            onclick="return confirm('Remove this link?')">✕</button>
                                </form>
                            </div>
                        </div>

                        
                        <?php $lVerified = $link->getVerifiedData(); ?>
                        <?php if($lVerified && ($lVerified['link_subtype'] ?? '') === 'search_results'): ?>
                            
                            <?php
                                $lParts = [];
                                if (!empty($lVerified['results_count'])) $lParts[] = 'Results: ' . $lVerified['results_count'];
                                if (!empty($lVerified['price_min']) && !empty($lVerified['price_max'])) {
                                    $lParts[] = 'Range: R' . number_format($lVerified['price_min'], 0) . ' – R' . number_format($lVerified['price_max'], 0);
                                }
                                if (!empty($lVerified['price_median'])) $lParts[] = 'Median: R' . number_format($lVerified['price_median'], 0);
                            ?>
                            <?php if(!empty($lParts)): ?>
                                <div class="mt-1.5 text-xs text-gray-600 bg-purple-50 rounded px-2 py-1">
                                    <?php echo e(implode(' | ', $lParts)); ?>

                                </div>
                            <?php endif; ?>
                        <?php elseif($lVerified && !empty($lVerified['asking_price'])): ?>
                            
                            <?php
                                $lParts = [];
                                $lParts[] = 'R' . number_format($lVerified['asking_price'], 0);
                                if (!empty($lVerified['beds'])) $lParts[] = $lVerified['beds'] . ' bed';
                                if (!empty($lVerified['baths'])) $lParts[] = $lVerified['baths'] . ' bath';
                                if (!empty($lVerified['floor_area_m2'])) $lParts[] = $lVerified['floor_area_m2'] . 'm²';
                                if (!empty($lVerified['suburb'])) $lParts[] = $lVerified['suburb'];
                            ?>
                            <div class="mt-1.5 text-xs text-gray-600 bg-green-50 rounded px-2 py-1">
                                <?php echo e(implode(' | ', $lParts)); ?>

                            </div>
                        <?php elseif($lVerified): ?>
                            
                            <?php
                                $lSkipKeys = ['extractor_version', 'link_type', 'url', 'source_domain', 'source_site', 'link_subtype', 'snapshot_id', 'extraction_method', 'snapshot_error', 'top_listings', 'blocked_reason', 'timed_out', 'http_status', 'content_bytes'];
                            ?>
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                <?php $__currentLoopData = $lVerified; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $lKey => $lVal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php if(!in_array($lKey, $lSkipKeys) && $lVal !== null && $lVal !== '' && !is_array($lVal)): ?>
                                        <span>
                                            <span class="text-gray-400"><?php echo e(str_replace('_', ' ', $lKey)); ?>:</span>
                                            <?php if(is_numeric($lVal) && $lVal >= 10000): ?>
                                                R<?php echo e(number_format($lVal, 0)); ?>

                                            <?php else: ?>
                                                <?php echo e($lVal); ?>

                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        <?php endif; ?>
                        <?php if($lExtStatus === 'failed'): ?>
                            <?php if(config('features.portal_extension_capture_v1') && $link->type === 'property24'): ?>
                                
                                <div class="mt-1.5 bg-blue-50 border border-blue-200 rounded px-2 py-1.5 text-xs text-blue-700 flex items-center justify-between gap-2">
                                    <div class="flex-1">
                                        <span class="font-semibold">Capture via Browser Extension</span> — open the portal and use the capture extension
                                    </div>
                                    <a href="<?php echo e($link->url); ?>" target="_blank" rel="noopener noreferrer"
                                       class="px-2 py-0.5 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 font-medium shrink-0">
                                        Open Portal
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php
                                    $lBlockedReason = $lVerified['blocked_reason'] ?? null;
                                    $lHttpStatus    = $lVerified['http_status'] ?? null;
                                    $lTimedOut      = $lVerified['timed_out'] ?? false;
                                    $lErrorMsg      = $link->extraction_error ?? 'check link type';

                                    // Determine error category for styling
                                    $lIsBlocked = $lBlockedReason || ($lHttpStatus && $lHttpStatus >= 400);
                                    $lIsTimeout = $lTimedOut;
                                ?>
                                <div class="mt-1.5 <?php echo e($lIsBlocked ? 'bg-red-50 border-red-300' : ($lIsTimeout ? 'bg-orange-50 border-orange-300' : 'bg-red-50 border-red-200')); ?> border rounded px-2 py-1.5 text-xs <?php echo e($lIsBlocked ? 'text-red-800' : ($lIsTimeout ? 'text-orange-700' : 'text-red-700')); ?> flex items-center justify-between gap-2">
                                    <div class="flex-1">
                                        <?php if(str_starts_with($lBlockedReason ?? '', 'headless_service_')): ?>
                                            <span class="font-semibold">Portal fetch engine offline</span> — start the headless service and retry
                                        <?php elseif($lIsBlocked): ?>
                                            <span class="font-semibold">Blocked</span> — <?php echo e($lBlockedReason ?? $lErrorMsg); ?>

                                            <?php if($lHttpStatus): ?>
                                                <span class="text-red-500">(HTTP <?php echo e($lHttpStatus); ?>)</span>
                                            <?php endif; ?>
                                        <?php elseif($lIsTimeout): ?>
                                            <span class="font-semibold">Timed out</span> — connection to site failed
                                        <?php else: ?>
                                            No data extracted — <?php echo e($lErrorMsg); ?>

                                        <?php endif; ?>
                                    </div>
                                    <form method="POST"
                                          action="<?php echo e(route('presentations.links.re-extract', [$presentation, $link])); ?>"
                                          class="shrink-0">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                                class="px-2 py-0.5 bg-red-600 text-white text-xs rounded hover:bg-red-700 font-medium">
                                            Retry
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        
                        <?php if($link->isOverridden()): ?>
                            <p class="mt-1 text-xs text-orange-500">
                                Overridden <?php echo e($link->override_at ? $link->override_at->format('Y-m-d H:i') : ''); ?>

                                <?php if($link->override_by_user_id): ?>
                                    by user #<?php echo e($link->override_by_user_id); ?>

                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        
                        <details class="mt-1.5">
                            <summary class="text-xs text-indigo-500 cursor-pointer hover:underline">
                                <?php echo e($link->isOverridden() ? 'Edit override' : 'View details / Override'); ?>

                            </summary>
                            <div class="mt-2 space-y-2">
                                
                                <?php if($link->extracted_json): ?>
                                    <div class="bg-gray-50 rounded p-2 text-xs font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                        <pre><?php echo e(json_encode($link->extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                    </div>
                                <?php endif; ?>

                                
                                <form method="POST"
                                      action="<?php echo e(route('presentations.links.override', [$presentation, $link])); ?>"
                                      class="border border-orange-200 rounded p-2 bg-orange-50">
                                    <?php echo csrf_field(); ?>
                                    <?php echo method_field('PATCH'); ?>
                                    <p class="text-xs font-medium text-orange-700 mb-1.5">Override values</p>
                                    <?php
                                        $lOverride = $link->override_json ?? $link->extracted_json ?? [];
                                        $lOverrideFields = array_filter($lOverride, fn($k) => !in_array($k, ['extractor_version', 'link_type', 'url', 'source_domain']), ARRAY_FILTER_USE_KEY);
                                    ?>
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <?php if(in_array($link->type, ['property24', 'active_listing', 'competitor_listing'])): ?>
                                            <input type="number" name="override_data[asking_price]" placeholder="Asking price (R)"
                                                   value="<?php echo e($lOverride['asking_price'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            <input type="text" name="override_data[suburb]" placeholder="Suburb"
                                                   value="<?php echo e($lOverride['suburb'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            <input type="number" name="override_data[beds]" placeholder="Beds"
                                                   value="<?php echo e($lOverride['beds'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            <input type="number" name="override_data[baths]" placeholder="Baths"
                                                   value="<?php echo e($lOverride['baths'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            <input type="number" name="override_data[floor_area_m2]" placeholder="Floor m²"
                                                   value="<?php echo e($lOverride['floor_area_m2'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            <input type="number" name="override_data[erf_m2]" placeholder="Erf m²"
                                                   value="<?php echo e($lOverride['erf_m2'] ?? ''); ?>"
                                                   class="border border-gray-200 rounded px-2 py-1 text-xs">
                                        <?php elseif($link->type === 'market_article'): ?>
                                            <input type="text" name="override_data[headline]" placeholder="Headline"
                                                   value="<?php echo e($lOverride['headline'] ?? ''); ?>"
                                                   class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                        <?php else: ?>
                                            <input type="text" name="override_data[notes]" placeholder="Notes"
                                                   value="<?php echo e($lOverride['notes'] ?? ''); ?>"
                                                   class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-2 mt-1.5">
                                        <button type="submit"
                                                class="px-2 py-1 bg-orange-600 text-white text-xs rounded hover:bg-orange-700">
                                            Save Override
                                        </button>
                                    </div>
                                </form>
                                <?php if($link->isOverridden()): ?>
                                    <form method="POST"
                                          action="<?php echo e(route('presentations.links.override.clear', [$presentation, $link])); ?>"
                                          class="mt-1">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('DELETE'); ?>
                                        <button type="submit"
                                                class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                onclick="return confirm('Clear this override?')">
                                            Clear Override
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('presentations.links.store', $presentation)); ?>" class="space-y-2">
            <?php echo csrf_field(); ?>
            <div class="flex gap-2">
                <select name="type" id="link-type" class="border border-gray-300 rounded px-2 py-1.5 text-xs">
                    <option value="property24">Property24</option>
                    <option value="lightstone">Lightstone</option>
                    <option value="active_listing">Active Listing</option>
                    <option value="competitor_listing">Competitor Listing</option>
                    <option value="market_article">Market Article</option>
                    <option value="other">Other</option>
                </select>
                <input type="url" name="url" id="link-url" placeholder="https://..." required
                       class="flex-1 border border-gray-300 rounded px-2 py-1.5 text-xs min-w-0">
                <a href="#" id="open-link-btn" target="_blank" rel="noopener noreferrer"
                   class="px-2 py-1.5 border border-gray-300 text-xs rounded text-gray-500 hover:bg-gray-50 shrink-0"
                   title="Open link in new tab">↗</a>
            </div>
            <div class="flex gap-2">
                <input type="text" name="notes" placeholder="Notes (optional)"
                       class="flex-1 border border-gray-300 rounded px-2 py-1.5 text-xs">
                <button type="submit"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded hover:bg-indigo-700 shrink-0">
                    Add Link
                </button>
            </div>

            
            <div id="p24-fields" class="grid grid-cols-2 gap-2 pt-1 border-t border-gray-100">
                <p class="col-span-2 text-xs text-gray-400">
                    Optional: paste key fields from the listing to prefill presentation inputs.
                </p>
                <input type="number" name="asking_price_inc" placeholder="Asking price (R)"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <input type="text" name="suburb" placeholder="Suburb"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <input type="number" name="beds" placeholder="Beds" min="0" max="20"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <input type="number" name="baths" placeholder="Baths" min="0" max="20"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <input type="number" name="floor_area_m2" placeholder="Floor area (m²)"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <input type="number" name="erf_m2" placeholder="Erf / land (m²)"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                <select name="property_type" class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                    <option value="">Property type (optional)</option>
                    <option value="house">House</option>
                    <option value="unit">Unit / Apartment</option>
                    <option value="land">Land / Vacant Erf</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <?php $__errorArgs = ['url'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-xs text-red-600"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </form>
        <script>
        (function () {
            var typeEl = document.getElementById('link-type');
            var urlEl  = document.getElementById('link-url');
            var openBtn = document.getElementById('open-link-btn');
            var p24    = document.getElementById('p24-fields');

            function toggleP24() {
                p24.style.display = typeEl.value === 'property24' ? '' : 'none';
            }
            typeEl.addEventListener('change', toggleP24);
            toggleP24();

            urlEl.addEventListener('input', function () {
                openBtn.href = urlEl.value || '#';
            });
        })();
        </script>
    </div>

    
    <?php if(config('features.portal_extension_capture_v1')): ?>
    <div class="bg-white rounded-xl shadow p-5 col-span-2">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-gray-700">Portal Captures</h2>
            <div class="flex gap-2">
                <a href="https://www.property24.com" target="_blank" rel="noopener noreferrer"
                   class="px-2 py-1 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700">
                    Property24
                </a>
                <a href="https://www.privateproperty.co.za" target="_blank" rel="noopener noreferrer"
                   class="px-2 py-1 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700">
                    PrivateProperty
                </a>
                <button type="button" id="refresh-captures-btn"
                        class="px-2 py-1 bg-gray-100 text-gray-700 text-xs font-medium rounded hover:bg-gray-200 border border-gray-300">
                    Refresh
                </button>
            </div>
        </div>

        <div id="captures-container">
            <p class="text-xs text-gray-400 italic">Loading captures...</p>
        </div>

        <script>
        (function () {
            var container = document.getElementById('captures-container');
            var refreshBtn = document.getElementById('refresh-captures-btn');
            var presentationId = <?php echo e($presentation->id); ?>;
            var listUrl = '<?php echo e(route("presentations.portal-captures.index", $presentation)); ?>';

            function loadCaptures() {
                container.innerHTML = '<p class="text-xs text-gray-400 italic">Loading...</p>';
                fetch(listUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var html = '';

                    if (data.attached && data.attached.length > 0) {
                        html += '<p class="text-xs font-semibold text-gray-500 mb-1">Attached</p>';
                        html += buildTable(data.attached, false);
                    }

                    if (data.unattached && data.unattached.length > 0) {
                        html += '<p class="text-xs font-semibold text-gray-500 mt-3 mb-1">Unattached (your recent captures)</p>';
                        html += buildTable(data.unattached, true);
                    }

                    if (!html) {
                        html = '<p class="text-xs text-gray-400 italic">No captures yet. Open a portal site and use the capture extension.</p>';
                    }

                    container.innerHTML = html;
                })
                .catch(function () {
                    container.innerHTML = '<p class="text-xs text-red-500">Failed to load captures.</p>';
                });
            }

            function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            function buildTable(items, showAttach) {
                var t = '<table class="w-full text-xs border-collapse">';
                t += '<thead><tr class="text-left text-gray-400 border-b">';
                t += '<th class="py-1 pr-2">Site</th><th class="py-1 pr-2">Type</th><th class="py-1 pr-2">URL</th><th class="py-1 pr-2">Status</th><th class="py-1 pr-2">Captured</th>';
                t += '<th class="py-1">Action</th>';
                t += '</tr></thead><tbody>';

                items.forEach(function (c) {
                    var shortUrl = (c.source_url || '').length > 45 ? c.source_url.substring(0, 45) + '...' : c.source_url;
                    var capturedAt = c.captured_at ? c.captured_at.substring(0, 16).replace('T', ' ') : '';
                    var statusBadge = c.parse_status === 'parsed'
                        ? '<span class="px-1 py-0.5 rounded bg-green-50 text-green-700">parsed</span>'
                        : '<span class="px-1 py-0.5 rounded bg-yellow-50 text-yellow-700">' + esc(c.parse_status || 'unknown') + '</span>';
                    t += '<tr class="border-b border-gray-50">';
                    t += '<td class="py-1.5 pr-2 text-gray-600">' + esc(c.source_site || '') + '</td>';
                    t += '<td class="py-1.5 pr-2"><span class="px-1 py-0.5 rounded bg-blue-50 text-blue-700">' + esc(c.page_type) + '</span></td>';
                    t += '<td class="py-1.5 pr-2"><a href="' + esc(c.source_url) + '" target="_blank" class="text-indigo-600 hover:underline">' + esc(shortUrl) + '</a></td>';
                    t += '<td class="py-1.5 pr-2">' + statusBadge + '</td>';
                    t += '<td class="py-1.5 pr-2 text-gray-500">' + capturedAt + '</td>';
                    if (showAttach) {
                        t += '<td class="py-1.5"><button class="px-2 py-0.5 bg-green-600 text-white rounded hover:bg-green-700 text-xs" onclick="attachCapture(' + c.id + ')">Attach</button></td>';
                    } else {
                        t += '<td class="py-1.5 text-gray-500">' + (c.html_bytes ? Number(c.html_bytes).toLocaleString() + 'b' : '-') + '</td>';
                    }
                    t += '</tr>';
                });

                t += '</tbody></table>';
                return t;
            }

            window.attachCapture = function (captureId) {
                var attachUrl = '/presentations/' + presentationId + '/portal-captures/' + captureId + '/attach';
                fetch(attachUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) loadCaptures();
                    else alert('Failed to attach capture');
                })
                .catch(function () { alert('Error attaching capture'); });
            };

            refreshBtn.addEventListener('click', loadCaptures);
            loadCaptures();
        })();
        </script>
    </div>
    <?php endif; ?>

    
    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Documents</h2>

        <?php
            $docTypeLabels = [
                'suburb_stats'   => 'Suburb Stats',
                'vicinity_sales' => 'Vicinity Sales',
                'cma'            => 'CMA',
                'market_article' => 'Market Article',
                'other'          => 'Other',
            ];
        ?>

        <?php if($presentation->uploads->isEmpty()): ?>
            <p class="text-xs text-gray-400 italic mb-3">No documents uploaded yet.</p>
        <?php else: ?>
            <ul class="space-y-3 mb-4 text-xs text-gray-600">
                <?php $__currentLoopData = $presentation->uploads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $upload): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="border border-gray-100 rounded-lg p-2">
                        
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0 flex-wrap">
                                <span class="text-gray-400 shrink-0">📄</span>
                                <span class="truncate"><?php echo e($upload->original_filename ?? basename($upload->file_path)); ?></span>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium
                                    <?php echo e(isset($docTypeLabels[$upload->type]) && $upload->type !== 'other' ? 'bg-indigo-50 text-indigo-600' : 'bg-gray-100 text-gray-500'); ?>">
                                    <?php echo e($docTypeLabels[$upload->type] ?? $upload->type); ?>

                                </span>

                                
                                <?php
                                    $uExtStatus = $upload->extraction_status ?? 'pending';
                                    $uExtBadge = match($uExtStatus) {
                                        'ok'     => 'bg-green-100 text-green-700',
                                        'failed' => 'bg-red-100 text-red-600',
                                        default  => 'bg-yellow-100 text-yellow-700',
                                    };
                                    $uExtLabel = match($uExtStatus) {
                                        'ok'     => 'Extracted',
                                        'failed' => 'Failed',
                                        default  => 'Pending',
                                    };
                                ?>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?php echo e($uExtBadge); ?>">
                                    <?php echo e($uExtLabel); ?>

                                </span>

                                <form method="POST"
                                      action="<?php echo e(route('presentations.uploads.re-extract', [$presentation, $upload])); ?>"
                                      class="inline">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit"
                                            class="inline-block px-1 py-0.5 text-xs text-indigo-500 hover:text-indigo-700"
                                            title="Re-run extraction">&#x27F3;</button>
                                </form>

                                <?php if($upload->isOverridden()): ?>
                                    <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">
                                        Override
                                    </span>
                                <?php endif; ?>
                            </div>
                            <form method="POST"
                                  action="<?php echo e(route('presentations.uploads.update-type', [$presentation, $upload])); ?>"
                                  class="flex items-center gap-1 shrink-0">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('PATCH'); ?>
                                <select name="type" class="border border-gray-200 rounded px-1 py-0.5 text-xs">
                                    <?php $__currentLoopData = $docTypeLabels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $val => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($val); ?>" <?php echo e($upload->type === $val ? 'selected' : ''); ?>><?php echo e($label); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </select>
                                <button type="submit"
                                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Save</button>
                            </form>
                        </div>

                        
                        <?php
                            $uVerified  = $upload->getVerifiedData();
                            $uAgg       = $uVerified['aggregates'] ?? [];
                            $uCounts    = $uVerified['parsed_counts'] ?? [];
                        ?>
                        <?php if($uVerified && ($upload->type === 'suburb_stats') && !empty($uAgg)): ?>
                            
                            <?php
                                $uParts = [];
                                if (!empty($uAgg['active_listings_count'])) $uParts[] = 'Active: ' . $uAgg['active_listings_count'];
                                if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                                if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                                if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                                if (!empty($uAgg['months_of_inventory'])) $uParts[] = 'MOI: ' . $uAgg['months_of_inventory'];
                                if (!empty($uCounts['active_listings'])) $uParts[] = 'Rows: ' . $uCounts['active_listings'];
                            ?>
                            <div class="mt-1.5 text-xs text-gray-600 bg-blue-50 rounded px-2 py-1">
                                <?php echo e(implode(' | ', $uParts)); ?>

                            </div>
                        <?php elseif($uVerified && ($upload->type === 'vicinity_sales') && !empty($uAgg)): ?>
                            
                            <?php
                                $uParts = [];
                                if (!empty($uAgg['sold_count'])) $uParts[] = 'Sold: ' . $uAgg['sold_count'];
                                if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                                if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                                if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                                if (!empty($uAgg['price_range_low']) && !empty($uAgg['price_range_high'])) {
                                    $uParts[] = 'Range: R' . number_format($uAgg['price_range_low'], 0) . '–R' . number_format($uAgg['price_range_high'], 0);
                                }
                                if (!empty($uCounts['sold_comps'])) $uParts[] = 'Rows: ' . $uCounts['sold_comps'];
                            ?>
                            <div class="mt-1.5 text-xs text-gray-600 bg-blue-50 rounded px-2 py-1">
                                <?php echo e(implode(' | ', $uParts)); ?>

                            </div>
                        <?php elseif($uVerified && ($upload->type === 'cma') && !empty($uVerified['suggested_band'])): ?>
                            
                            <?php
                                $band = $uVerified['suggested_band'];
                            ?>
                            <div class="mt-1.5 text-xs text-gray-600 bg-blue-50 rounded px-2 py-1">
                                Band: R<?php echo e(number_format($band['low'], 0)); ?> – R<?php echo e(number_format($band['high'], 0)); ?>

                                <?php if(!empty($uVerified['notes'])): ?>
                                    <?php $__currentLoopData = $uVerified['notes']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $note): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        | <?php echo e(str_replace('suggested_value:', 'Suggested: R', $note)); ?>

                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif($uVerified && !empty($uCounts)): ?>
                            
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                <?php $__currentLoopData = $uCounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pcKey => $pcVal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <span>
                                        <span class="text-gray-400"><?php echo e(str_replace('_', ' ', $pcKey)); ?>:</span>
                                        <?php echo e($pcVal); ?>

                                    </span>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        <?php endif; ?>
                        <?php if($uExtStatus === 'failed'): ?>
                            <div class="mt-1.5 bg-red-50 border border-red-200 rounded px-2 py-1.5 text-xs text-red-700">
                                No data extracted — <?php echo e($upload->extraction_error ?? 'check PDF format'); ?>

                            </div>
                        <?php endif; ?>

                        
                        <?php if($upload->isOverridden()): ?>
                            <p class="mt-1 text-xs text-orange-500">
                                Overridden <?php echo e($upload->override_at ? $upload->override_at->format('Y-m-d H:i') : ''); ?>

                                <?php if($upload->override_by_user_id): ?>
                                    by user #<?php echo e($upload->override_by_user_id); ?>

                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        
                            <details class="mt-1.5">
                                <summary class="text-xs text-indigo-500 cursor-pointer hover:underline">
                                    <?php echo e($upload->isOverridden() ? 'Edit override' : 'View details / Override'); ?>

                                </summary>
                                <div class="mt-2 space-y-2">
                                    
                                    <?php if($upload->extraction_json): ?>
                                        <div class="bg-gray-50 rounded p-2 text-xs font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                            <pre><?php echo e(json_encode($upload->extraction_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                        </div>
                                    <?php endif; ?>

                                    
                                    <form method="POST"
                                          action="<?php echo e(route('presentations.uploads.override', [$presentation, $upload])); ?>"
                                          class="border border-orange-200 rounded p-2 bg-orange-50">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('PATCH'); ?>
                                        <p class="text-xs font-medium text-orange-700 mb-1.5">Override values</p>
                                        <?php
                                            $uOverrideSource = $upload->override_json ?? [];
                                            // Prefill from aggregates if available and no override set
                                            $uAggPrefill = $uVerified['aggregates'] ?? [];
                                            $uOverride = !empty($uOverrideSource) ? $uOverrideSource : $uAggPrefill;
                                            // Build fields based on doc type
                                            $uFieldDefs = match($upload->type) {
                                                'suburb_stats' => [
                                                    'active_listings_count' => 'Active listings',
                                                    'median_price' => 'Median price',
                                                    'average_price' => 'Average price',
                                                    'dom_p50' => 'DOM p50',
                                                    'months_of_inventory' => 'Months of inventory',
                                                ],
                                                'vicinity_sales' => [
                                                    'sold_count' => 'Sold count',
                                                    'median_price' => 'Median price',
                                                    'average_price' => 'Average price',
                                                    'dom_p50' => 'DOM p50',
                                                ],
                                                'cma' => [
                                                    'suggested_price_low' => 'Price low',
                                                    'suggested_price_high' => 'Price high',
                                                    'comps_count' => 'Comps count',
                                                ],
                                                default => [
                                                    'notes' => 'Notes',
                                                ],
                                            };
                                        ?>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            <?php $__currentLoopData = $uFieldDefs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $fKey => $fLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <div>
                                                    <label class="block text-xs text-gray-400"><?php echo e($fLabel); ?></label>
                                                    <input type="text" name="override_data[<?php echo e($fKey); ?>]"
                                                           placeholder="<?php echo e($fLabel); ?>"
                                                           value="<?php echo e($uOverride[$fKey] ?? ''); ?>"
                                                           class="w-full border border-gray-200 rounded px-2 py-1 text-xs">
                                                </div>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                        </div>
                                        <div class="flex gap-2 mt-1.5">
                                            <button type="submit"
                                                    class="px-2 py-1 bg-orange-600 text-white text-xs rounded hover:bg-orange-700">
                                                Save Override
                                            </button>
                                        </div>
                                    </form>
                                    <?php if($upload->isOverridden()): ?>
                                        <form method="POST"
                                              action="<?php echo e(route('presentations.uploads.override.clear', [$presentation, $upload])); ?>"
                                              class="mt-1">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit"
                                                    class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                    onclick="return confirm('Clear this override?')">
                                                Clear Override
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('presentations.upload', $presentation)); ?>"
              enctype="multipart/form-data" class="space-y-2">
            <?php echo csrf_field(); ?>
            <div class="flex gap-2 items-center">
                <select name="doc_type" class="border border-gray-300 rounded px-2 py-1.5 text-xs" required>
                    <option value="" disabled selected>Document type...</option>
                    <?php $__currentLoopData = $docTypeLabels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $val => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($val); ?>"><?php echo e($label); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <input type="file" name="documents[]" multiple
                       class="flex-1 text-xs text-gray-600 border border-gray-300 rounded px-2 py-1.5" required>
                <button type="submit"
                        class="px-3 py-1.5 bg-gray-600 text-white text-xs font-medium rounded hover:bg-gray-700 shrink-0">
                    Upload
                </button>
            </div>
            <?php $__errorArgs = ['doc_type'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="mt-1 text-xs text-red-600"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            <?php $__errorArgs = ['documents'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="mt-1 text-xs text-red-600"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            <?php $__errorArgs = ['documents.*'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="mt-1 text-xs text-red-600"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </form>
    </div>

</div>


<div class="mt-6">
    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Holding Cost Inputs (monthly, ZAR)</h2>

        <form method="POST" action="<?php echo e(route('presentations.holding-cost.update', $presentation)); ?>" class="space-y-3">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Bond payment</label>
                    <input type="number" name="monthly_bond" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_bond ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Rates</label>
                    <input type="number" name="monthly_rates" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_rates ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Levies</label>
                    <input type="number" name="monthly_levies" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_levies ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Insurance</label>
                    <input type="number" name="monthly_insurance" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_insurance ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Utilities</label>
                    <input type="number" name="monthly_utilities" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_utilities ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Opportunity cost</label>
                    <input type="number" name="monthly_opportunity_cost" min="0" step="0.01"
                           value="<?php echo e($presentation->monthly_opportunity_cost ?? ''); ?>"
                           placeholder="0"
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs">
                </div>
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded hover:bg-indigo-700">
                    Save Holding Cost
                </button>
                <?php
                    $hcTotal = collect([
                        $presentation->monthly_bond,
                        $presentation->monthly_rates,
                        $presentation->monthly_levies,
                        $presentation->monthly_insurance,
                        $presentation->monthly_utilities,
                        $presentation->monthly_opportunity_cost,
                    ])->sum();
                ?>
                <?php if($hcTotal > 0): ?>
                    <span class="text-xs text-gray-500">
                        Monthly total: R<?php echo e(number_format($hcTotal, 0)); ?>

                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/presentations/show.blade.php ENDPATH**/ ?>