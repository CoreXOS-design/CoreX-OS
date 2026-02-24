<?php if (isset($component)) { $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54 = $attributes; } ?>
<?php $component = App\View\Components\AppLayout::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('app-layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\AppLayout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>

 <?php $__env->slot('header', null, []); ?> 
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Worksheet &mdash; <?php echo e($user->name); ?></h2>
                <div class="text-sm text-white/60">Income &rarr; Sales &rarr; Stock</div>
            </div>
            <div class="text-sm text-white/80 font-medium"><?php echo e($w->period ?? now()->format('Y-m')); ?></div>
        </div>
    </div>
 <?php $__env->endSlot(); ?>

<div class="max-w-6xl mx-auto px-4 py-6 space-y-6">

    <?php if(session('status')): ?>
        <div class="mb-4 p-3 rounded-lg bg-green-100 text-green-800 font-medium text-sm">
            <?php echo e(session('status')); ?>

        </div>
    <?php endif; ?>

    <?php
        $w = $latest;
        $calc = $w ? \App\Http\Controllers\WorksheetController::calculate($w) : null;

        // Admin-controlled defaults from user record
        $agentCut = ($user->agent_cut_percent === null || $user->agent_cut_percent === '') ? 50 : (float)$user->agent_cut_percent;
        $payeMethod = $user->paye_method ?? 'percentage';
        $payeValue = ($user->paye_value === null || $user->paye_value === '') ? 0 : (float)$user->paye_value;

        $payeDisplay = $payeMethod === 'fixed'
            ? 'Fixed: R ' . number_format($payeValue, 2)
            : 'Percentage: ' . number_format($payeValue, 2) . '%';
    ?>

    <?php
        $latestNet = 0;
        if (!empty($latest)) {
            $latestNet = (float)$latest->personal_net_target + (float)$latest->business_net_target + (float)$latest->want_net_target;
        }
    ?>

    
    
    
    <?php if(auth()->user()->can_capture_rentals || in_array(auth()->user()->role, ['admin','branch_manager'])): ?>
    <?php
        $rentalsActive = (int)($calc['rentals_active_count'] ?? 0);
        $rentalsAssist = (int)($calc['rentals_assist_count'] ?? 0);
        $rentalsCommExcl = (float)($calc['rentals_commission_excl_total'] ?? 0);
    ?>

    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        <div class="flex items-center justify-between mb-3">
            <h3 class="ds-section-header" style="margin-bottom:0;">Rentals (This Period)</h3>
            <span class="ds-badge ds-badge-default">Ex VAT</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <div class="ds-label">Active Rentals</div>
                <div class="ds-value text-lg"><?php echo e($rentalsActive); ?></div>
            </div>
            <div>
                <div class="ds-label">Rental Assist</div>
                <div class="ds-value text-lg"><?php echo e($rentalsAssist); ?></div>
            </div>
            <div>
                <div class="ds-label">Commission (Excl VAT)</div>
                <div class="ds-value text-lg">R <?php echo e(number_format($rentalsCommExcl, 2)); ?></div>
            </div>
        </div>

        <div class="text-xs text-gray-500 mt-3">
            Display-only: rentals are not yet integrated into budgets.
        </div>
    </div>
    <?php endif; ?>

    
    
    
    <?php if(!empty($companyRequirement)): ?>
    <div class="ds-status-card <?php echo e((data_get($companyRequirement, 'shortfall', 0) > 0) ? 'ds-status-declined' : 'ds-status-granted'); ?>">
        <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Company Requirement</h3>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div>
                <div class="ds-label">Branch Budget</div>
                <div class="ds-value">R <?php echo e(number_format($companyRequirement['branch_budget'], 2)); ?></div>
                <div class="text-xs text-gray-500">Ex VAT</div>
            </div>
            <div>
                <div class="ds-label">Agents in Branch</div>
                <div class="ds-value"><?php echo e($companyRequirement['agents']); ?></div>
            </div>
            <div>
                <div class="ds-label">Required per Agent</div>
                <div class="ds-value">R <?php echo e(number_format($companyRequirement['required_per_agent'], 2)); ?></div>
                <div class="text-xs text-gray-500">Ex VAT</div>
            </div>
            <div>
                <div class="ds-label">Company Earns from You</div>
                <div class="ds-value">R <?php echo e(number_format($companyRequirement['current_company_income'], 2)); ?></div>
                <div class="text-xs text-gray-500">Ex VAT &bull; if you hit your current budget</div>
            </div>
            <div>
                <div class="ds-label">Shortfall</div>
                <div class="ds-value <?php echo e($companyRequirement['shortfall'] > 0 ? 'text-red-600' : 'text-green-600'); ?>">
                    R <?php echo e(number_format($companyRequirement['shortfall'], 2)); ?>

                </div>
                <div class="text-xs text-gray-500">Ex VAT</div>
            </div>
        </div>

        <?php if($companyRequirement['shortfall'] > 0): ?>
            <div class="mt-3 text-sm text-red-600 font-medium">
                Your targets do not currently meet the branch/company requirement.
            </div>
        <?php else: ?>
            <div class="mt-3 text-sm text-green-600 font-medium">
                Your targets meet or exceed the company requirement.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    
    
    <?php if(!empty($companyRequirement)): ?>
    <form method="POST" action="<?php echo e(route('worksheet.store')); ?>">
        <?php echo csrf_field(); ?>

        
        <div class="ds-status-card mb-6" style="border-left-color: var(--ds-navy);">
            <h3 class="ds-section-header" style="margin-bottom:0.5rem;">Planning Inputs</h3>
            <p class="text-sm text-gray-500 mb-4">Fill in your numbers for the month. Save. Your required sales and stock levels will calculate automatically.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="ds-label block mb-1">Period (YYYY-MM)</label>
                    <input type="month" name="period" value="<?php echo e(old('period', $w->period ?? now()->format('Y-m'))); ?>" class="w-full border rounded-lg p-2 text-sm" />
                    <?php $__errorArgs = ['period'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm mt-1"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <div>
                    <label class="ds-label block mb-1">Current Active Listings</label>
                    <?php $lockedListings = isset($activeListings) ? (int)$activeListings : (int)($w->current_listings ?? 0); ?>
                    <input type="hidden" name="current_listings" value="<?php echo e($lockedListings); ?>" />
                    <input type="number" value="<?php echo e($lockedListings); ?>"
                           class="w-full border rounded-lg p-2 text-sm bg-gray-100 text-gray-700 cursor-not-allowed"
                           readonly disabled />
                    <div class="text-xs text-gray-500 mt-1">Locked from imported stock (Propcon).</div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Correctly Priced Stock (%)</label>
                    <?php if(isset($cmaCount) && (int)$cmaCount > 0): ?>
                        <input type="hidden" name="correctly_priced_percent" value="<?php echo e((float)$cmaCorrectlyPricedPercent); ?>" />
                        <input type="number" step="0.01" value="<?php echo e((float)$cmaCorrectlyPricedPercent); ?>"
                               class="w-full border rounded-lg p-2 text-sm bg-gray-100 text-gray-700 cursor-not-allowed"
                               readonly disabled />
                        <div class="text-xs text-gray-500 mt-1">Calculated from listings with CMA captured.</div>
                    <?php else: ?>
                        <input type="number" step="0.01" name="correctly_priced_percent" value="<?php echo e(old('correctly_priced_percent', $w->correctly_priced_percent ?? 40)); ?>"
                               class="w-full border rounded-lg p-2 text-sm" />
                        <?php $__errorArgs = ['correctly_priced_percent'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm mt-1"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    <?php endif; ?>

                    <?php echo $__env->make('worksheet._cma_pricing_info', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                </div>
            </div>
        </div>

        
        <div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Net Monthly Targets</h3>

            <?php if(!empty($companyRequirement)): ?>
                <?php
                    $canApplyBranchDefault = (!empty($companyRequirement) && $latestNet <= 0.0 && ($companyRequirement['required_per_agent'] ?? 0) > 0);
                    $canAlignToCompany = (!empty($companyRequirement) && ($companyRequirement['shortfall'] ?? 0) > 1);
                ?>

                <div class="bg-gray-50 border rounded-lg p-4 mb-4">
                    <div class="text-sm font-semibold mb-2 text-gray-700">Budget / Company Requirement Actions</div>

                    <div class="flex flex-col md:flex-row gap-3">
                        <button type="submit"
                                formaction="<?php echo e(route('worksheet.applyBranchDefault')); ?>"
                                formmethod="POST"
                                class="px-4 py-2 rounded-lg text-sm font-semibold text-white <?php echo e($canApplyBranchDefault ? 'bg-[#0b2a4a] hover:bg-[#143d66]' : 'bg-gray-400 opacity-50 cursor-not-allowed'); ?>"
                                <?php echo e($canApplyBranchDefault ? '' : 'disabled'); ?>>
                            Set my budget to the required minimum
                        </button>

                        <button type="submit"
                                formaction="<?php echo e(route('worksheet.align')); ?>"
                                formmethod="POST"
                                class="px-4 py-2 rounded-lg text-sm font-semibold text-white <?php echo e($canAlignToCompany ? 'bg-[#c41e3a] hover:bg-[#a01830]' : 'bg-gray-400 opacity-50 cursor-not-allowed'); ?>"
                                <?php echo e($canAlignToCompany ? '' : 'disabled'); ?>>
                            Scale my targets to meet company requirement
                        </button>
                    </div>

                    <div class="text-xs text-gray-600 mt-2 space-y-0.5">
                        <div><strong>Set my budget</strong> is only available when your Net Monthly Targets are still zero (no budget captured yet).</div>
                        <div><strong>Scale my targets</strong> is available when you have a remaining shortfall against the company requirement.</div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="ds-label block mb-1">Personal (Take-home)</label>
                    <input type="number" step="0.01" name="personal_net_target" value="<?php echo e(old('personal_net_target', $w->personal_net_target ?? 0)); ?>"
                           class="w-full border rounded-lg p-2 text-sm" />
                    <?php $__errorArgs = ['personal_net_target'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm mt-1"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div>
                    <label class="ds-label block mb-1">Business (Fuel/Marketing/etc.)</label>
                    <input type="number" step="0.01" name="business_net_target" value="<?php echo e(old('business_net_target', $w->business_net_target ?? 0)); ?>"
                           class="w-full border rounded-lg p-2 text-sm" />
                    <?php $__errorArgs = ['business_net_target'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm mt-1"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div>
                    <label class="ds-label block mb-1">Want (Savings/Holiday/Buffer)</label>
                    <input type="number" step="0.01" name="want_net_target" value="<?php echo e(old('want_net_target', $w->want_net_target ?? 0)); ?>"
                           class="w-full border rounded-lg p-2 text-sm" />
                    <?php $__errorArgs = ['want_net_target'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm mt-1"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
            </div>
        </div>

        
        
        
        <div class="ds-status-card mb-6" style="border-left-color: var(--ds-green);">
            <h3 class="ds-section-header" style="margin-bottom:0.25rem;">Deal Register Summary (What's Happening)</h3>
            <div class="ds-section-sub mb-4">
                This section reports your captured Deal Register performance for the selected period (sales, commission, stages, and pipeline).
            </div>

            <?php
                // All-time block added by dealRegisterStats()
                $all = $dealStats['all_time'] ?? [];
                $pipeAll = $dealStats['pipeline_not_paid_all_time_counts'] ?? ($all['pipeline_not_paid_counts'] ?? []);

                // Tiny helpers (Blade-only)
                $fmtR = fn($v) => 'R ' . number_format((float)($v ?? 0), 2);
                $fmtPct = fn($v) => number_format((float)($v ?? 0), 2) . '%';
                $stageLine = function(array $arr, string $k) use ($fmtR) {
                    return $fmtR(($arr[$k] ?? 0));
                };
            ?>

            
            <div class="grid grid-cols-3 gap-3 mb-3">
                <div></div>
                <div class="text-xs font-bold uppercase tracking-wide text-white rounded-t-lg px-3 py-2" style="background:#0b2a4a;">Plan Inputs (BM / Worksheet)</div>
                <div class="text-xs font-bold uppercase tracking-wide text-white rounded-t-lg px-3 py-2" style="background:#059669;">Deal Register (Actuals)</div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3 border-b border-gray-200">
                <div class="ds-label self-center">Avg Sale Price</div>

                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="ds-value">R <?php echo e(number_format((float)($w->avg_sale_price_admin ?? $w->avg_sale_price ?? 1060000), 2)); ?></div>
                    <div class="text-xs text-gray-500 mt-1">Set by Branch Manager (per agent, per month).</div>
                    <input type="hidden" name="avg_sale_price" value="<?php echo e(old('avg_sale_price', $w->avg_sale_price ?? 1060000)); ?>" />
                    <?php $__errorArgs = ['avg_sale_price'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <div class="space-y-2">
                    <div class="rounded-lg p-3" style="background: rgba(5, 150, 105, 0.06);">
                        <div class="ds-value">R <?php echo e(number_format((float)($dealStats['avg_sale_price_inc_vat'] ?? 0), 2)); ?></div>
                        <div class="text-xs text-gray-500 mt-1"><b>Ex VAT:</b> R <?php echo e(number_format((float)($dealStats['avg_sale_price_ex_vat'] ?? 0), 2)); ?></div>
                        <div class="text-xs text-gray-500 mt-1">
                            From Deal Register (<?php echo e((int)(($dealStats['counts']['total'] ?? 0))); ?> deals in <?php echo e($dealStats['period'] ?? ''); ?>).
                        </div>
                    </div>

                    <div class="rounded-lg p-3" style="background: rgba(5, 150, 105, 0.06);">
                        <div class="text-xs text-gray-600 mb-1"><b>All-time</b></div>
                        <div class="ds-value"><?php echo e($fmtR($all['avg_sale_price_inc_vat'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-500 mt-1"><b>Ex VAT:</b> <?php echo e($fmtR($all['avg_sale_price_ex_vat'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-500 mt-1">
                            From Deal Register (<?php echo e((int)($all['counts']['total'] ?? 0)); ?> deals all time).
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3 border-b border-gray-200">
                <div class="ds-label self-center">Commission % (Excl VAT)</div>

                <div class="bg-gray-50 rounded-lg p-3">
                    <input type="number" step="0.01" name="commission_percent"
                           value="<?php echo e(old('commission_percent', $w->commission_percent ?? 7.5)); ?>"
                           class="w-full border rounded-lg p-2 text-sm" />
                    <?php $__errorArgs = ['commission_percent'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="text-red-600 text-sm"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    <div class="text-xs text-gray-500 mt-1">Planning % (default 7.5%).</div>
                </div>

                <div class="space-y-2">
                    <div class="rounded-lg p-3" style="background: rgba(5, 150, 105, 0.06);">
                        <div class="ds-value"><?php echo e(number_format((float)($dealStats['effective_commission_percent_ex_vat'] ?? 0), 2)); ?>%</div>
                        <div class="text-xs text-gray-500 mt-1">
                            Actual commission / sales value (based on captured totals).
                        </div>
                        <div class="text-xs text-gray-700 mt-2">
                            Lost vs 7.5%: <b>R <?php echo e(number_format((float)($dealStats['lost_commission_ex_vat_vs_7_5'] ?? 0), 2)); ?></b>
                        </div>
                    </div>

                    <div class="rounded-lg p-3" style="background: rgba(5, 150, 105, 0.06);">
                        <div class="text-xs text-gray-600 mb-1"><b>All-time</b></div>
                        <div class="ds-value"><?php echo e($fmtPct($all['effective_commission_percent_ex_vat'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-500 mt-1">
                            Actual commission / sales value (all captured totals).
                        </div>
                        <?php if(array_key_exists('lost_commission_ex_vat_vs_7_5', $all)): ?>
                            <div class="text-xs text-gray-700 mt-2">
                                Lost vs 7.5%: <b><?php echo e($fmtR($all['lost_commission_ex_vat_vs_7_5'] ?? 0)); ?></b>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3 border-b border-gray-200">
                <div class="ds-label self-center">Deals (count)</div>
                <div class="bg-gray-50 rounded-lg p-3 text-gray-400 text-sm">&mdash;</div>

                <div class="text-sm">
                    <div><b>Period total:</b> <?php echo e((int)($dealStats['counts']['total'] ?? 0)); ?></div>
                    <div class="text-xs text-gray-600 mt-1">
                        Pending: <?php echo e((int)($dealStats['counts']['pending'] ?? 0)); ?> |
                        Granted: <?php echo e((int)($dealStats['counts']['granted'] ?? 0)); ?> |
                        Registered: <?php echo e((int)($dealStats['counts']['registered'] ?? 0)); ?> |
                        Declined: <?php echo e((int)($dealStats['counts']['declined'] ?? 0)); ?>

                    </div>

                    <div class="mt-2">
                        <div class="text-xs text-gray-600 mb-1"><b>All-time</b></div>
                        <div><b>Total:</b> <?php echo e((int)($all['counts']['total'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-600 mt-1">
                            Pending: <?php echo e((int)($all['counts']['pending'] ?? 0)); ?> |
                            Granted: <?php echo e((int)($all['counts']['granted'] ?? 0)); ?> |
                            Registered: <?php echo e((int)($all['counts']['registered'] ?? 0)); ?> |
                            Declined: <?php echo e((int)($all['counts']['declined'] ?? 0)); ?>

                        </div>
                    </div>
                </div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3 border-b border-gray-200">
                <div class="ds-label self-center">Sales Value</div>
                <div class="bg-gray-50 rounded-lg p-3 text-gray-400 text-sm">&mdash;</div>

                <div class="text-sm">
                    <div><b>Period (Sale Price):</b> R <?php echo e(number_format((float)($dealStats['sales_value_inc_vat'] ?? 0), 2)); ?></div>
                    <div class="text-xs text-gray-600 mt-1">
                        Pending: R <?php echo e(number_format((float)($dealStats['stage_sales_inc_vat']['pending'] ?? 0), 2)); ?> |
                        Granted: R <?php echo e(number_format((float)($dealStats['stage_sales_inc_vat']['granted'] ?? 0), 2)); ?> |
                        Registered: R <?php echo e(number_format((float)($dealStats['stage_sales_inc_vat']['registered'] ?? 0), 2)); ?> |
                        Declined: R <?php echo e(number_format((float)($dealStats['stage_sales_inc_vat']['declined'] ?? 0), 2)); ?>

                    </div>

                    <div class="mt-2">
                        <div class="text-xs text-gray-600 mb-1"><b>All-time</b></div>
                        <div><b>Sale Price:</b> <?php echo e($fmtR($all['sales_value_inc_vat'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-600 mt-1">
                            Pending: <?php echo e($fmtR(($all['stage_sales_inc_vat']['pending'] ?? 0))); ?> |
                            Granted: <?php echo e($fmtR(($all['stage_sales_inc_vat']['granted'] ?? 0))); ?> |
                            Registered: <?php echo e($fmtR(($all['stage_sales_inc_vat']['registered'] ?? 0))); ?> |
                            Declined: <?php echo e($fmtR(($all['stage_sales_inc_vat']['declined'] ?? 0))); ?>

                        </div>
                    </div>
                </div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3 border-b border-gray-200">
                <div class="ds-label self-center">Total Commission</div>
                <div class="bg-gray-50 rounded-lg p-3 text-gray-400 text-sm">&mdash;</div>

                <div class="text-sm">
                    <div><b>Period Incl VAT:</b> R <?php echo e(number_format((float)($dealStats['total_commission_inc_vat'] ?? 0), 2)); ?></div>
                    <div class="text-xs text-gray-600 mt-1"><b>Period Ex VAT:</b> R <?php echo e(number_format((float)($dealStats['total_commission_ex_vat'] ?? 0), 2)); ?></div>

                    <div class="mt-2">
                        <div class="text-xs text-gray-600 mb-1"><b>All-time</b></div>
                        <div><b>Incl VAT:</b> <?php echo e($fmtR($all['total_commission_inc_vat'] ?? 0)); ?></div>
                        <div class="text-xs text-gray-600 mt-1"><b>Ex VAT:</b> <?php echo e($fmtR($all['total_commission_ex_vat'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>

            
            <div class="grid grid-cols-3 gap-3 items-start py-3">
                <div>
                    <div class="ds-label">Pipeline (Net)</div>
                    <div class="text-xs text-gray-500">Always ALL-TIME &bull; only NOT PAID deals</div>
                </div>

                <div class="bg-gray-50 rounded-lg p-3 text-gray-400 text-sm">&mdash;</div>

                <div class="text-sm">
                    <?php
                        $pipe = $dealStats['pipeline_not_paid_all_time'] ?? [];
                        $pipeMoney = $pipe['agent_net_ex_vat_by_stage'] ?? [];
                        $pipeCounts = $pipe['counts'] ?? [];
                    ?>

                    <div class="text-xs text-gray-600 mb-1"><b>Outstanding (ex VAT)</b></div>

                    <div class="ds-value">
                        Total: <b><?php echo e($fmtR($pipe['agent_net_ex_vat_total'] ?? 0)); ?></b>
                    </div>

                    <div class="mt-2 text-xs text-gray-700 space-y-1">
                        <div>
                            <b>Pending:</b> <?php echo e($fmtR($pipeMoney['pending'] ?? 0)); ?>

                            <span class="text-gray-400">(<?php echo e((int)($pipeCounts['pending'] ?? 0)); ?> deals)</span>
                        </div>
                        <div>
                            <b>Granted:</b> <?php echo e($fmtR($pipeMoney['granted'] ?? 0)); ?>

                            <span class="text-gray-400">(<?php echo e((int)($pipeCounts['granted'] ?? 0)); ?> deals)</span>
                        </div>
                        <div>
                            <b>Registered:</b> <?php echo e($fmtR($pipeMoney['registered'] ?? 0)); ?>

                            <span class="text-gray-400">(<?php echo e((int)($pipeCounts['registered'] ?? 0)); ?> deals)</span>
                        </div>
                    </div>

                    <?php if(($pipeCounts['total'] ?? 0) > 0): ?>
                    <div class="text-xs text-gray-600 mt-2">
                        Includes <b><?php echo e((int)($pipeCounts['total'] ?? 0)); ?></b> not-paid deals (all-time).
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3 text-xs text-gray-600 border-t pt-3">
                Stock model used: <b>1 sale per <?php echo e((float)\App\Models\PerformanceSetting::get('listings_per_sale', 5)); ?> correctly priced listings</b>
            </div>
        </div>

        
        <div class="ds-status-card mb-6" style="border-left-color: var(--ds-label);">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Admin Controls</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="ds-label block mb-1">PAYE (Admin)</label>
                    <div class="bg-gray-50 border rounded-lg p-3 text-sm text-gray-800">
                        <?php echo e($payeDisplay); ?>

                        <div class="text-xs text-gray-500 mt-1">This value is set by admin on the User record.</div>
                    </div>
                </div>
                <div>
                    <label class="ds-label block mb-1">Agent Cut % (Admin)</label>
                    <div class="bg-gray-50 border rounded-lg p-3 text-sm text-gray-800">
                        <?php echo e(number_format($agentCut, 2)); ?>%
                        <div class="text-xs text-gray-500 mt-1">This value is set by admin on the User record.</div>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="flex flex-col md:flex-row items-start md:items-center gap-4 mb-6">
            <button id="saveWorksheetBtn" class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-lg font-bold text-lg shadow-lg border-2 border-green-800">
                SAVE WORKSHEET
            </button>

            <div class="text-sm text-gray-700 bg-yellow-50 border border-yellow-300 px-3 py-2 rounded-lg">
                After changing any numbers, click <b>SAVE WORKSHEET</b> to update your results.
            </div>
        </div>
    </form>
    <?php endif; ?>

    
    
    
    <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
        <h3 class="ds-section-header" style="margin-bottom:0.25rem;">Target Requirements (Plan vs Market Reality)</h3>
        <div class="ds-section-sub mb-4">
            This section recalculates what you need to do (sales/listings/gap) based on (1) your plan inputs vs (2) market reality from Deal Register averages.
        </div>

        <?php if(!isset($w) || !$w): ?>
            <p class="text-gray-600">No worksheet saved yet.</p>
        <?php else: ?>
            <?php
                $planned = $calc;
                $actual = \App\Http\Controllers\WorksheetController::calculateWithOverrides(
                    $w,
                    (float)($dealStats['avg_sale_price_inc_vat'] ?? 0),
                    (float)($dealStats['effective_commission_percent_ex_vat'] ?? 0),
                    true // commission percent is already ex-VAT from dealRegisterStats
                );

                // -------------------------------------------------------
                // Budget-driven targets (with company floor)
                // -------------------------------------------------------
                $req = (float)($companyRequirement['required_per_agent'] ?? 0);
                $listingsPerSale = (float) \App\Models\PerformanceSetting::get('listings_per_sale', 5);

                $cp = (isset($cmaCorrectlyPricedPercent) && $cmaCorrectlyPricedPercent !== null)
                    ? (float)$cmaCorrectlyPricedPercent
                    : (float)($w->correctly_priced_percent ?? 0);
                $cp = max(0.01, $cp);
                $cpFactor = ($cp / 100.0);
                $currentListings = (int)($w->current_listings ?? 0);

                $plannedNetNeed = (float)($planned['net_need'] ?? 0);
                $actualNetNeed  = (float)($actual['net_need'] ?? 0);

                $plannedAgentNetPerSale = (float)($planned['agent_net_per_sale'] ?? 0);
                $actualAgentNetPerSale  = (float)($actual['agent_net_per_sale'] ?? 0);

                $plannedCompanyIncomePerSale = (float)($planned['company_income_per_sale'] ?? 0);
                $actualCompanyIncomePerSale  = (float)($actual['company_income_per_sale'] ?? 0);

                // Company-floor
                $payePercent = (float)($w->paye_percent ?? 0);
                $payeFactor = 1.0 - ($payePercent / 100.0);

                $agentSplitPercent = (float)($w->agent_split_percent ?? 0);
                $agentShareFactor = ($agentSplitPercent / 100.0);
                $companyShareFactor = 1.0 - $agentShareFactor;

                $commissionPoolFloorEx = ($companyShareFactor > 0) ? ($req / $companyShareFactor) : 0;
                $agentGrossFloorEx = $commissionPoolFloorEx * $agentShareFactor;
                $netFloor = $agentGrossFloorEx * $payeFactor;

                $plannedNetFloor = $netFloor;
                $actualNetFloor  = $netFloor;

                $plannedBudgetUsed = max($plannedNetNeed, $plannedNetFloor);
                $actualBudgetUsed  = max($actualNetNeed,  $actualNetFloor);

                $plannedSalesNeeded = ($plannedAgentNetPerSale > 0) ? ($plannedBudgetUsed / $plannedAgentNetPerSale) : 0;
                $actualSalesNeeded  = ($actualAgentNetPerSale  > 0) ? ($actualBudgetUsed  / $actualAgentNetPerSale)  : 0;

                $plannedListingsNeeded = ($cpFactor > 0) ? (($plannedSalesNeeded * $listingsPerSale) / $cpFactor) : 0;
                $actualListingsNeeded  = ($cpFactor > 0) ? (($actualSalesNeeded  * $listingsPerSale) / $cpFactor) : 0;

                $plannedGap = $plannedListingsNeeded - $currentListings;
                $actualGap  = $actualListingsNeeded  - $currentListings;

                // Deltas (Market - Plan)
                $deltaBudgetUsed   = $actualBudgetUsed - $plannedBudgetUsed;
                $deltaSalesNeeded  = $actualSalesNeeded - $plannedSalesNeeded;
                $deltaListingsNeed = $actualListingsNeeded - $plannedListingsNeeded;
                $deltaGap          = $actualGap - $plannedGap;

                // -------------------------------------------------------
                // Reverse-calc: Net budget -> Gross agent -> Total commission -> Sales value
                // -------------------------------------------------------
                $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
                $vatRate = $vatRatePercent / 100.0;
                $vatDiv = 1.0 + $vatRate;

                $payePercent = (float)($w->paye_percent ?? 0);
                $payeFactor = 1.0 - ($payePercent / 100.0);

                $agentSplitPercent = (float)($w->agent_split_percent ?? 0);
                $agentShareFactor = ($agentSplitPercent / 100.0);

                $plannedAgentGrossNeeded = ($payeFactor > 0) ? ($plannedBudgetUsed / $payeFactor) : 0;
                $actualAgentGrossNeeded  = ($payeFactor > 0) ? ($actualBudgetUsed  / $payeFactor) : 0;

                $plannedTotalCommissionNeededEx = ($agentShareFactor > 0) ? ($plannedAgentGrossNeeded / $agentShareFactor) : 0;
                $actualTotalCommissionNeededEx  = ($agentShareFactor > 0) ? ($actualAgentGrossNeeded  / $agentShareFactor) : 0;

                $planCommissionPercent = (float)($w->commission_percent ?? 0);
                $marketCommissionPercentEx = (float)($dealStats['effective_commission_percent_ex_vat'] ?? 0);

                $plannedSalesValueNeededEx = ($planCommissionPercent > 0) ? ($plannedTotalCommissionNeededEx / ($planCommissionPercent / 100.0)) : 0;
                $actualSalesValueNeededEx  = ($marketCommissionPercentEx > 0) ? ($actualTotalCommissionNeededEx / ($marketCommissionPercentEx / 100.0)) : 0;

                $plannedSalesValueNeededInc = $plannedSalesValueNeededEx * $vatDiv;
                $actualSalesValueNeededInc  = $actualSalesValueNeededEx  * $vatDiv;
            ?>

            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-1">
                <div class="text-xs font-bold uppercase tracking-wide text-white rounded-t-lg px-3 py-2" style="background:#0b2a4a;">Plan (Worksheet Inputs)</div>
                <div class="text-xs font-bold uppercase tracking-wide text-white rounded-t-lg px-3 py-2" style="background:#00b4d8;">Market-Based (Deal Register Averages)</div>
                <div class="text-xs font-bold uppercase tracking-wide text-gray-700 rounded-t-lg px-3 py-2 bg-amber-100">Difference (Market - Plan)</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                
                <div class="border rounded-b-lg p-4 bg-white" style="border-top: 3px solid #0b2a4a;">
                    <div class="space-y-2 text-sm">
                        <div><span class="ds-label">Period:</span> <span class="ds-value"><?php echo e($w->period); ?></span></div>

                        <div class="mt-2"><span class="ds-label">Nett Take-home at Company Budget:</span></div>
                        <div class="ds-value-lg">R <?php echo e(number_format($plannedBudgetUsed, 2)); ?></div>

                        <div class="text-xs <?php echo e(($plannedBudgetUsed > $plannedNetNeed) ? 'text-red-600' : 'text-gray-500'); ?>">
                            <?php if($plannedBudgetUsed > $plannedNetNeed): ?>
                                Budget lifted to meet company requirement.
                            <?php else: ?>
                                Uses your budget (above company requirement).
                            <?php endif; ?>
                        </div>
                        <div class="mt-2"><span class="ds-label">Gross Commission Needed (Ex VAT):</span> <span class="ds-value">R <?php echo e(number_format($plannedTotalCommissionNeededEx, 2)); ?></span></div>
                        <div><span class="ds-label">Sales Value Needed:</span> <span class="ds-value">R <?php echo e(number_format($plannedSalesValueNeededInc, 2)); ?></span> <span class="text-xs text-gray-500">(Inc VAT)</span></div>
                        <div class="text-xs text-gray-500">Ex VAT: R <?php echo e(number_format($plannedSalesValueNeededEx, 2)); ?> &bull; Comm% used: <?php echo e(number_format($planCommissionPercent, 2)); ?>%</div>

                        <div class="text-xs text-gray-500">
                            Uses your budget unless the company-floor requires more.
                        </div>

                        <div class="border-t pt-2 mt-2">
                            <div><span class="ds-label">Sales Needed / Month:</span> <span class="ds-value"><?php echo e(number_format($plannedSalesNeeded, 2)); ?></span></div>
                            <div><span class="ds-label">Total Listings Needed:</span> <span class="ds-value"><?php echo e(number_format($plannedListingsNeeded, 2)); ?></span></div>
                            <div><span class="ds-label">Gap (Needed - Current):</span> <span class="ds-value <?php echo e($plannedGap > 0 ? 'text-red-600' : 'text-green-600'); ?>"><?php echo e(number_format($plannedGap, 2)); ?></span></div>
                        </div>
                    </div>
                </div>

                
                <div class="border rounded-b-lg p-4 bg-white" style="border-top: 3px solid #00b4d8;">
                    <div class="space-y-2 text-sm">
                        <div><span class="ds-label">Period:</span> <span class="ds-value"><?php echo e($dealStats['period'] ?? $w->period); ?></span></div>

                        <div class="mt-2"><span class="ds-label">Nett Take-home at Company Budget:</span></div>
                        <div class="ds-value-lg">R <?php echo e(number_format($actualBudgetUsed, 2)); ?></div>

                        <div class="text-xs <?php echo e(($actualBudgetUsed > $actualNetNeed) ? 'text-red-600' : 'text-gray-500'); ?>">
                            <?php if($actualBudgetUsed > $actualNetNeed): ?>
                                Budget lifted to meet company requirement.
                            <?php else: ?>
                                Uses your budget (above company requirement).
                            <?php endif; ?>
                        </div>
                        <div class="mt-2"><span class="ds-label">Gross Commission Needed (Ex VAT):</span> <span class="ds-value">R <?php echo e(number_format($actualTotalCommissionNeededEx, 2)); ?></span></div>
                        <div><span class="ds-label">Sales Value Needed:</span> <span class="ds-value">R <?php echo e(number_format($actualSalesValueNeededInc, 2)); ?></span> <span class="text-xs text-gray-500">(Inc VAT)</span></div>
                        <div class="text-xs text-gray-500">Ex VAT: R <?php echo e(number_format($actualSalesValueNeededEx, 2)); ?> &bull; Comm% used: <?php echo e(number_format($marketCommissionPercentEx, 2)); ?>%</div>

                        <div class="text-xs text-gray-500">
                            Same budget logic, but per-sale performance comes from Deal Register averages.
                        </div>

                        <div class="border-t pt-2 mt-2">
                            <div><span class="ds-label">Sales Needed / Month:</span> <span class="ds-value"><?php echo e(number_format($actualSalesNeeded, 2)); ?></span></div>
                            <div><span class="ds-label">Total Listings Needed:</span> <span class="ds-value"><?php echo e(number_format($actualListingsNeeded, 2)); ?></span></div>
                            <div><span class="ds-label">Gap (Needed - Current):</span> <span class="ds-value <?php echo e($actualGap > 0 ? 'text-red-600' : 'text-green-600'); ?>"><?php echo e(number_format($actualGap, 2)); ?></span></div>
                        </div>
                    </div>

                    <div class="mt-3 text-xs text-gray-700 bg-blue-50 border rounded-lg p-2">
                        Uses: Avg Price = R <?php echo e(number_format((float)($dealStats['avg_sale_price_inc_vat'] ?? 0), 2)); ?>

                        <div class="text-xs text-gray-600 mt-1"><b>Ex VAT:</b> R <?php echo e(number_format((float)($dealStats['avg_sale_price_ex_vat'] ?? 0), 2)); ?></div>,
                        Comm % = <?php echo e(number_format((float)($dealStats['effective_commission_percent_ex_vat'] ?? 0), 2)); ?>%
                    </div>
                </div>

                
                <?php
                    $delta_comm_per_sale = (float)($actual['commission_per_sale'] ?? 0) - (float)($planned['commission_per_sale'] ?? 0);
                    $delta_net_per_sale = (float)($actual['agent_net_per_sale'] ?? 0) - (float)($planned['agent_net_per_sale'] ?? 0);
                    $delta_net_need = (float)($actualNetNeed ?? 0) - (float)($plannedNetNeed ?? 0);
                ?>

                <div class="border rounded-b-lg p-4 bg-amber-50" style="border-top: 3px solid #f59e0b;">
                    <div class="space-y-2 text-sm">
                        <div><span class="ds-label">Period:</span> <span class="ds-value"><?php echo e($dealStats['period'] ?? $w->period); ?></span></div>
                        <div><span class="ds-label">Nett Take-home at Company Budget:</span> <span class="ds-value">R <?php echo e(number_format($deltaBudgetUsed, 2)); ?></span></div>
                        <div class="mt-2"><span class="ds-label">Commission / Sale (Ex VAT):</span> <span class="ds-value">R <?php echo e(number_format($delta_comm_per_sale, 2)); ?></span></div>
                        <div><span class="ds-label">Your Net / Sale (Ex VAT):</span> <span class="ds-value">R <?php echo e(number_format($delta_net_per_sale, 2)); ?></span></div>

                        <div class="border-t pt-2 mt-2">
                            <div><span class="ds-label">Sales Needed / Month:</span> <span class="ds-value"><?php echo e(number_format($deltaSalesNeeded, 2)); ?></span></div>
                            <div><span class="ds-label">Total Listings Needed:</span> <span class="ds-value"><?php echo e(number_format($deltaListingsNeed, 2)); ?></span></div>
                            <div><span class="ds-label">Gap (Needed - Current):</span> <span class="ds-value"><?php echo e(number_format($deltaGap, 2)); ?></span></div>
                        </div>
                    </div>

                    <div class="mt-2 text-xs text-gray-700">
                        Negative = Market requires <b>less</b>. Positive = Market requires <b>more</b>.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    
    
    
    <div class="ds-status-card" style="border-left-color: var(--ds-navy);">
        <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Your Saved Months</h3>

        <?php if($worksheets->isEmpty()): ?>
            <p class="text-gray-600">No saved records yet.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <th class="text-left p-2">Period</th>
                            <th class="text-left p-2">Net Need</th>
                            <th class="text-left p-2">Current Listings</th>
                            <th class="text-left p-2">Correctly Priced %</th>
                            <th class="text-left p-2">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $worksheets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php $c = \App\Http\Controllers\WorksheetController::calculate($row); ?>
                            <tr>
                                <td class="p-2 ds-value"><?php echo e($row->period); ?></td>
                                <td class="p-2 ds-value">R <?php echo e(number_format($c['net_need'], 2)); ?></td>
                                <td class="p-2"><?php echo e($row->current_listings); ?></td>
                                <td class="p-2"><?php echo e(number_format($row->correctly_priced_percent, 2)); ?>%</td>
                                <td class="p-2 text-gray-500"><?php echo e($row->updated_at->format('Y-m-d H:i')); ?></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $attributes = $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $component = $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/worksheet/index.blade.php ENDPATH**/ ?>