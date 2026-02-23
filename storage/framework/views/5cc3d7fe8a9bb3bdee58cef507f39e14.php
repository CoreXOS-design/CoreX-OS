
<?php if(isset($analysisData) && ($analysisData['data_counts']['fields'] > 0 || $analysisData['data_counts']['sold_comps'] > 0)): ?>

<?php
    $subject  = $analysisData['subject_property'];
    $suburb   = $analysisData['suburb_overview'];
    $comps    = $analysisData['comparable_sales'];
    $cma      = $analysisData['cma_valuation'];
    $active   = $analysisData['active_competition'];
    $stock    = $analysisData['stock_absorption'] ?? [];
    $holding  = $analysisData['holding_cost'];
    $insights = $analysisData['key_insights'];
    $counts   = $analysisData['data_counts'];
?>

<div class="mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-gray-800">Extracted Data Review</h2>
        <span class="text-xs text-gray-400">
            <?php echo e($counts['fields']); ?> fields &middot;
            <?php echo e($counts['sold_comps']); ?> comps &middot;
            <?php echo e($counts['active_listings']); ?> active
        </span>
    </div>

    
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">1. Subject Property</h3>
        <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3 lg:grid-cols-4">
            <div>
                <span class="text-xs text-gray-400">Address</span>
                <p class="font-medium text-gray-800"><?php echo e($subject['address'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Suburb</span>
                <p class="font-medium text-gray-800"><?php echo e($subject['suburb'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Erf Number</span>
                <p class="font-medium text-gray-800"><?php echo e($subject['erf'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Extent</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['extent_m2']): ?>
                        <?php echo e(number_format($subject['extent_m2'])); ?> m&sup2;
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">GPS</span>
                <p class="font-medium text-gray-800 text-xs"><?php echo e($subject['gps'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Property Type</span>
                <p class="font-medium text-gray-800"><?php echo e(ucfirst($subject['property_type'] ?? '—')); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Bedrooms</span>
                <p class="font-medium text-gray-800"><?php echo e($subject['bedrooms'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Purchase Date</span>
                <p class="font-medium text-gray-800"><?php echo e($subject['purchase_date'] ?? '—'); ?></p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Purchase Price</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['purchase_price']): ?>
                        R <?php echo e(number_format($subject['purchase_price'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Indexed Value</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['indexed_value']): ?>
                        R <?php echo e(number_format($subject['indexed_value'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">CAGR</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['cagr']): ?>
                        <?php echo e(number_format($subject['cagr'], 2)); ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Municipal Valuation</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['municipal_value']): ?>
                        R <?php echo e(number_format($subject['municipal_value'])); ?>

                        <?php if($subject['municipal_year']): ?>
                            <span class="text-gray-400 text-xs">(<?php echo e($subject['municipal_year']); ?>)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Asking Price</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['asking_price']): ?>
                        R <?php echo e(number_format($subject['asking_price'])); ?>

                    <?php else: ?>
                        <span class="text-amber-500 italic">Not set — enter in form above</span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Monthly Holding Cost</span>
                <p class="font-medium text-gray-800">
                    <?php if($subject['monthly_holding_total'] > 0): ?>
                        R <?php echo e(number_format($subject['monthly_holding_total'])); ?>

                    <?php else: ?>
                        <span class="text-gray-400">R 0</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    
    <?php if($suburb['latest_year']): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">2. Suburb Market Overview</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Year</span>
                <p class="text-lg font-bold text-gray-800"><?php echo e($suburb['latest_year']); ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Sales Count</span>
                <p class="text-lg font-bold text-gray-800"><?php echo e($suburb['sales_count'] ?? '—'); ?></p>
            </div>
            <div class="bg-indigo-50 rounded-lg p-3 text-center">
                <span class="text-xs text-indigo-400 block">Median Price</span>
                <p class="text-lg font-bold text-indigo-700">
                    <?php if($suburb['median_price']): ?>
                        R <?php echo e(number_format($suburb['median_price'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Low Range</span>
                <p class="text-sm font-semibold text-gray-700">
                    <?php if($suburb['low_range']): ?>
                        R <?php echo e(number_format($suburb['low_range'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">High Range</span>
                <p class="text-sm font-semibold text-gray-700">
                    <?php if($suburb['high_range']): ?>
                        R <?php echo e(number_format($suburb['high_range'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Maximum</span>
                <p class="text-sm font-semibold text-gray-700">
                    <?php if($suburb['max_price']): ?>
                        R <?php echo e(number_format($suburb['max_price'])); ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <?php if(!empty($stock['total_active_stock']) && !empty($stock['months_of_supply'])): ?>
    <?php
        $absColor = match($stock['absorption_color'] ?? '') {
            'green'  => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800'],
            'amber'  => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-700',   'badge' => 'bg-amber-100 text-amber-800'],
            'orange' => ['bg' => 'bg-orange-50',   'border' => 'border-orange-200',  'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-800'],
            'red'    => ['bg' => 'bg-red-50',      'border' => 'border-red-200',     'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-800'],
            default  => ['bg' => 'bg-gray-50',     'border' => 'border-gray-200',    'text' => 'text-gray-700',    'badge' => 'bg-gray-100 text-gray-800'],
        };
    ?>
    <div class="<?php echo e($absColor['bg']); ?> <?php echo e($absColor['border']); ?> border rounded-xl p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold <?php echo e($absColor['text']); ?> uppercase tracking-wide">Stock Absorption Rate</h3>
            <span class="text-xs px-2.5 py-1 rounded-full font-semibold <?php echo e($absColor['badge']); ?>"><?php echo e($stock['absorption_label']); ?></span>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Active Listings</span>
                <p class="text-xl font-bold <?php echo e($absColor['text']); ?>"><?php echo e($stock['total_active_stock']); ?></p>
                <?php if($stock['stock_source'] === 'portal_search'): ?>
                    <span class="text-xs text-gray-400">from P24 search</span>
                <?php endif; ?>
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Sales / Year</span>
                <p class="text-xl font-bold text-gray-800"><?php echo e($stock['annual_sales']); ?></p>
                <span class="text-xs text-gray-400"><?php echo e(number_format($stock['monthly_sales'], 1)); ?> / month</span>
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Months of Supply</span>
                <p class="text-xl font-bold <?php echo e($absColor['text']); ?>"><?php echo e(number_format($stock['months_of_supply'], 1)); ?></p>
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Years of Supply</span>
                <p class="text-xl font-bold <?php echo e($absColor['text']); ?>"><?php echo e(number_format($stock['years_of_supply'], 1)); ?></p>
            </div>
        </div>
        <?php if($stock['search_total_count'] && $stock['listings_with_price'] < $stock['search_total_count']): ?>
        <p class="text-xs <?php echo e($absColor['text']); ?> mt-3 opacity-75">
            Price data available for <?php echo e($stock['listings_with_price']); ?> of <?php echo e($stock['search_total_count']); ?> listings &mdash; actual competition may be higher.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    <?php if($comps['vicinity']['count'] > 0 || $comps['cma_comps']['count'] > 0 || $comps['street_sales']['count'] > 0): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">3. Comparable Sales</h3>

        <?php
            $compSections = [
                ['label' => 'Vicinity Sales',  'data' => $comps['vicinity']],
                ['label' => 'CMA Comps',       'data' => $comps['cma_comps']],
                ['label' => 'Street Sales',    'data' => $comps['street_sales']],
            ];
            $firstOpen = true;
        ?>

        <?php $__currentLoopData = $compSections; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $section): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php if($section['data']['count'] > 0): ?>
            <details class="mb-3 border border-gray-200 rounded-lg" <?php echo e($firstOpen ? 'open' : ''); ?>>
                <?php $firstOpen = false; ?>
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 select-none flex items-center justify-between">
                    <span><?php echo e($section['label']); ?></span>
                    <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700 font-medium"><?php echo e($section['data']['count']); ?></span>
                </summary>
                <div class="px-4 pb-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-400 border-b">
                                <th class="pb-2 pr-3 font-medium">Address</th>
                                <th class="pb-2 pr-3 font-medium text-right">Dist (m)</th>
                                <th class="pb-2 pr-3 font-medium text-right">Erf m&sup2;</th>
                                <th class="pb-2 pr-3 font-medium">Sale Date</th>
                                <th class="pb-2 pr-3 font-medium text-right">Sale Price</th>
                                <th class="pb-2 font-medium text-right">R/m&sup2;</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php $__currentLoopData = $section['data']['rows']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 pr-3 text-gray-800 text-xs"><?php echo e($row['address'] ?? '—'); ?></td>
                                <td class="py-2 pr-3 text-right text-gray-600"><?php echo e($row['distance_m'] ?? '—'); ?></td>
                                <td class="py-2 pr-3 text-right text-gray-600"><?php echo e($row['extent_m2'] ? number_format($row['extent_m2']) : '—'); ?></td>
                                <td class="py-2 pr-3 text-gray-600"><?php echo e($row['sale_date'] ?? '—'); ?></td>
                                <td class="py-2 pr-3 text-right font-medium text-gray-800">
                                    <?php if($row['sale_price']): ?>
                                        R <?php echo e(number_format($row['sale_price'])); ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 text-right text-gray-600">
                                    <?php if($row['price_per_m2']): ?>
                                        R <?php echo e(number_format($row['price_per_m2'])); ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 font-semibold text-xs">
                                <td class="pt-2 pr-3 text-gray-500" colspan="4">
                                    Avg (<?php echo e($section['data']['count']); ?> sales)
                                </td>
                                <td class="pt-2 pr-3 text-right text-gray-800">
                                    <?php if($section['data']['avg_price']): ?>
                                        R <?php echo e(number_format($section['data']['avg_price'])); ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="pt-2 text-right text-gray-800">
                                    <?php if($section['data']['avg_price_per_m2']): ?>
                                        R <?php echo e(number_format($section['data']['avg_price_per_m2'])); ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    <?php endif; ?>

    
    <?php if($cma['cma_middle'] || $cma['vicinity_middle']): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">4. CMA Valuation</h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            
            <?php if($cma['cma_middle']): ?>
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">CMA Report Range <span class="text-indigo-400">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    <?php $__currentLoopData = ['lower' => $cma['cma_lower'], 'middle' => $cma['cma_middle'], 'upper' => $cma['cma_upper']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $range => $val): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $isSel = ($cma['selected_range'] ?? 'middle') === $range; ?>
                    <div class="cma-tile text-center flex-1 rounded-lg p-3 cursor-pointer transition-all
                        <?php echo e($isSel ? 'bg-indigo-50 ring-1 ring-indigo-200' : 'bg-gray-50 hover:bg-gray-100'); ?>"
                        data-range="<?php echo e($range); ?>" data-value="<?php echo e($val); ?>">
                        <span class="text-xs block <?php echo e($isSel ? 'text-indigo-400' : 'text-gray-400'); ?>"><?php echo e(ucfirst($range)); ?></span>
                        <p class="<?php echo e($isSel ? 'font-bold text-indigo-700 text-lg' : 'font-semibold text-gray-700'); ?>">R <?php echo e(number_format($val)); ?></p>
                    </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>
            <?php endif; ?>

            
            <?php if($cma['vicinity_middle']): ?>
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Vicinity Sales Range <span class="text-indigo-400">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    <?php $vicSel = $presentation->vicinity_selected_range ?? 'middle'; ?>
                    <?php $__currentLoopData = ['lower' => $cma['vicinity_lower'], 'middle' => $cma['vicinity_middle'], 'upper' => $cma['vicinity_upper']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $range => $val): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $isSel = $vicSel === $range; ?>
                    <div class="vicinity-tile text-center flex-1 rounded-lg p-3 cursor-pointer transition-all
                        <?php echo e($isSel ? 'bg-indigo-50 ring-1 ring-indigo-200' : 'bg-gray-50 hover:bg-gray-100'); ?>"
                        data-range="<?php echo e($range); ?>" data-value="<?php echo e($val); ?>">
                        <span class="text-xs block <?php echo e($isSel ? 'text-indigo-400' : 'text-gray-400'); ?>"><?php echo e(ucfirst($range)); ?></span>
                        <p class="<?php echo e($isSel ? 'font-bold text-indigo-700 text-lg' : 'font-semibold text-gray-700'); ?>">R <?php echo e(number_format($val)); ?></p>
                    </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
                <?php if($cma['vicinity_ppm2']): ?>
                <p class="text-xs text-gray-400 mt-2 text-right">Avg R/m&sup2;: <span class="font-medium text-gray-600">R <?php echo e(number_format($cma['vicinity_ppm2'])); ?></span></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        
        <?php if($cma['asking_price'] && $cma['selected_value']): ?>
        <div id="asking-vs-cma" class="mt-4 p-4 rounded-lg border <?php echo e($cma['is_overpriced'] ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'); ?>"
             data-asking="<?php echo e($cma['asking_price']); ?>"
             data-cma-lower="<?php echo e($cma['cma_lower']); ?>"
             data-cma-middle="<?php echo e($cma['cma_middle']); ?>"
             data-cma-upper="<?php echo e($cma['cma_upper']); ?>">
            <div class="flex items-center justify-between">
                <div>
                    <p id="asking-cma-label" class="text-xs font-medium <?php echo e($cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600'); ?>">
                        Asking Price vs CMA <?php echo e(ucfirst($cma['selected_range'] ?? 'middle')); ?>

                    </p>
                    <p id="asking-cma-values" class="text-sm text-gray-700 mt-1">
                        R <?php echo e(number_format($cma['asking_price'])); ?> vs R <?php echo e(number_format($cma['selected_value'])); ?>

                    </p>
                </div>
                <div class="text-right">
                    <p id="asking-cma-pct" class="text-2xl font-bold <?php echo e($cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600'); ?>">
                        <?php if($cma['asking_vs_cma_pct'] > 0): ?>+<?php endif; ?><?php echo e($cma['asking_vs_cma_pct']); ?>%
                    </p>
                    <?php if($cma['is_overpriced']): ?>
                        <p id="asking-cma-note" class="text-xs text-red-500 font-medium">Above CMA valuation</p>
                    <?php else: ?>
                        <p id="asking-cma-note" class="text-xs text-emerald-500 font-medium hidden"></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    <?php if(($active['total_count'] ?? $active['count']) > 0): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">5. Active Market Competition</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="active-listings-table">
                <thead>
                    <tr class="text-left text-xs text-gray-400 border-b">
                        <th class="pb-2 pr-2 font-medium text-center" style="width:32px">
                            <input type="checkbox" id="active-check-all" checked title="Include/exclude all">
                        </th>
                        <th class="pb-2 pr-3 font-medium">Address</th>
                        <th class="pb-2 pr-3 font-medium">Type</th>
                        <th class="pb-2 pr-3 font-medium text-center">Beds</th>
                        <th class="pb-2 pr-3 font-medium text-center">Baths</th>
                        <th class="pb-2 pr-3 font-medium text-right">Erf m&sup2;</th>
                        <th class="pb-2 pr-3 font-medium">List Date</th>
                        <th class="pb-2 pr-3 font-medium text-right">List Price</th>
                        <th class="pb-2 font-medium text-right">DOM</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php $__currentLoopData = $active['rows']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr class="hover:bg-gray-50 active-listing-row <?php echo e(!empty($row['is_excluded']) ? 'opacity-50' : ''); ?>"
                        data-row-index="<?php echo e($row['row_index'] ?? $loop->index); ?>"
                        data-price="<?php echo e($row['list_price'] ?? 0); ?>">
                        <td class="py-2 pr-2 text-center">
                            <input type="checkbox" class="active-listing-check"
                                   data-row-index="<?php echo e($row['row_index'] ?? $loop->index); ?>"
                                   <?php echo e(empty($row['is_excluded']) ? 'checked' : ''); ?>>
                        </td>
                        <td class="py-2 pr-3 text-gray-800 text-xs max-w-[200px] truncate <?php echo e(!empty($row['is_excluded']) ? 'line-through' : ''); ?>">
                            <?php if(!empty($row['url'])): ?>
                                <a href="<?php echo e($row['url']); ?>" target="_blank" class="text-indigo-600 hover:underline" title="<?php echo e($row['address'] ?? ''); ?>"><?php echo e($row['address'] ?? '—'); ?></a>
                            <?php else: ?>
                                <?php echo e($row['address'] ?? '—'); ?>

                            <?php endif; ?>
                        </td>
                        <td class="py-2 pr-3 text-gray-600 text-xs"><?php echo e($row['property_type'] ?? '—'); ?></td>
                        <td class="py-2 pr-3 text-center text-gray-600"><?php echo e($row['beds'] ?? '—'); ?></td>
                        <td class="py-2 pr-3 text-center text-gray-600"><?php echo e($row['baths'] ?? '—'); ?></td>
                        <td class="py-2 pr-3 text-right text-gray-600"><?php echo e($row['extent_m2'] ? number_format($row['extent_m2']) : '—'); ?></td>
                        <td class="py-2 pr-3 text-gray-600"><?php echo e($row['list_date'] ?? '—'); ?></td>
                        <td class="py-2 pr-3 text-right font-medium text-gray-800">
                            <?php if($row['list_price']): ?>
                                R <?php echo e(number_format($row['list_price'])); ?>

                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="py-2 text-right text-gray-600"><?php echo e($row['days_on_market'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 font-semibold text-xs" id="active-summary">
                        <td class="pt-2" colspan="2"></td>
                        <td class="pt-2 text-gray-500" colspan="5">
                            <span id="active-count"><?php echo e($active['count']); ?></span> active
                            <?php echo e($active['count'] === 1 ? 'listing' : 'listings'); ?>

                            <?php if(($active['total_count'] ?? $active['count']) > $active['count']): ?>
                                <span class="text-gray-400">(<?php echo e(($active['total_count'] ?? $active['count']) - $active['count']); ?> excluded)</span>
                            <?php endif; ?>
                        </td>
                        <td class="pt-2 pr-3 text-right text-gray-800">
                            <span id="active-avg-price">
                            <?php if($active['avg_asking_price']): ?>
                                R <?php echo e(number_format($active['avg_asking_price'])); ?>

                            <?php endif; ?>
                            </span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    
    <?php if($holding['monthly_total'] > 0): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">6. Holding Cost Impact</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Monthly Breakdown</p>
                <div class="space-y-1">
                    <?php $__currentLoopData = $holding['breakdown']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $amount): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if($amount > 0): ?>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600"><?php echo e($label); ?></span>
                            <span class="font-medium text-gray-800">R <?php echo e(number_format($amount)); ?></span>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    <div class="flex justify-between text-sm pt-2 border-t border-gray-200 font-bold">
                        <span class="text-gray-700">Monthly Total</span>
                        <span class="text-gray-900">R <?php echo e(number_format($holding['monthly_total'])); ?></span>
                    </div>
                </div>
            </div>

            
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Cumulative Projections</p>
                <div class="space-y-2">
                    <div class="flex justify-between items-center bg-amber-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-amber-700">3 months</span>
                        <span class="font-bold text-amber-800">R <?php echo e(number_format($holding['projected_3m'])); ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-orange-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-orange-700">6 months</span>
                        <span class="font-bold text-orange-800">R <?php echo e(number_format($holding['projected_6m'])); ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-red-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-red-700">12 months</span>
                        <span class="font-bold text-red-800">R <?php echo e(number_format($holding['projected_12m'])); ?></span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-red-600 font-medium text-center">
                    Every month at current asking price costs R <?php echo e(number_format($holding['monthly_total'])); ?>

                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <div class="bg-white rounded-xl shadow p-6 mb-4" id="key-insights-container">
        <h3 class="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wide">7. Key Insights</h3>

        <?php if(!$insights['asking_price_set']): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-sm text-amber-700">
                    Enter an asking price in the analysis form above to see price position comparisons.
                </p>
            </div>
        <?php elseif(count($insights['comparisons']) === 0): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-sm text-gray-500">
                    No benchmark data available for comparison yet. Upload CMA or suburb reports.
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-3" id="key-insights-list">
                <?php $__currentLoopData = $insights['comparisons']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $comp): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $statusColors = match($comp['status']) {
                            'danger'  => 'bg-red-50 border-red-200 text-red-700',
                            'warning' => 'bg-amber-50 border-amber-200 text-amber-700',
                            default   => 'bg-emerald-50 border-emerald-200 text-emerald-700',
                        };
                        $pctColors = match($comp['status']) {
                            'danger'  => 'text-red-600',
                            'warning' => 'text-amber-600',
                            default   => 'text-emerald-600',
                        };
                    ?>
                    <div class="insight-card flex items-center justify-between p-4 rounded-lg border <?php echo e($statusColors); ?>"
                         data-label="<?php echo e($comp['label']); ?>"
                         data-benchmark="<?php echo e($comp['benchmark']); ?>"
                         data-asking="<?php echo e($comp['asking']); ?>"
                         data-pct="<?php echo e($comp['pct_difference']); ?>">
                        <div>
                            <p class="insight-label text-xs font-medium opacity-75"><?php echo e($comp['label']); ?></p>
                            <p class="insight-values text-sm mt-1">
                                R <?php echo e(number_format($comp['asking'])); ?> vs R <?php echo e(number_format($comp['benchmark'])); ?>

                            </p>
                        </div>
                        <p class="insight-pct text-xl font-bold <?php echo e($pctColors); ?>">
                            <?php if($comp['pct_difference'] > 0): ?>+<?php endif; ?><?php echo e($comp['pct_difference']); ?>%
                        </p>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php endif; ?>
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/presentations/partials/analysis-data-review.blade.php ENDPATH**/ ?>