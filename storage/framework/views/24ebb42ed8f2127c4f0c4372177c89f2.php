<?php $__env->startSection('content'); ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">My Listing Stock</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300">Read-only view from imported Propcon stock. Filters affect totals.</p>

            <?php if(!empty($context)): ?>
    <div class="mt-4 rounded-2xl bg-slate-900 dark:bg-slate-800 text-white px-6 py-4">
        <div class="flex items-center justify-between gap-6">
            <div class="min-w-0">
                <div class="text-xs uppercase tracking-wider text-slate-200">
                    <?php echo e(strtoupper((string)($context['filter'] ?? 'view'))); ?>

                </div>
                <div class="text-2xl font-semibold leading-tight truncate">
                    <?php echo e($context['title'] ?? 'Listings'); ?>

                </div>
                <div class="text-sm text-slate-200 mt-1">
                    <?php echo e($context['note'] ?? ''); ?>

                </div>
            </div>
            <div class="text-right shrink-0">
                <div class="text-xs uppercase tracking-wider text-slate-200">Count</div>
                <div class="text-3xl font-bold leading-tight">
                    <?php echo e((int)($context['count'] ?? 0)); ?>

                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

        </div>
        <div class="text-right">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active listings</div>
            <div class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100"><?php echo e(number_format((int)($summary->listing_count ?? 0))); ?></div>
            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Total value: R <?php echo e(number_format(((int)($summary->total_price_cents ?? 0))/100, 0)); ?>

            </div>
        </div>
    </div>

    
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-3 space-y-3">
        <form method="get" class="flex flex-wrap items-end gap-2">
            <div class="min-w-[220px]">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Status</label>
                <select name="status" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm">
                    <option value="active" <?php echo e($statusFilter==='active' ? 'selected' : ''); ?>>Active (contains active/for sale)</option>
                    <option value="all" <?php echo e($statusFilter==='all' ? 'selected' : ''); ?>>All</option>
                    <option value="sold" <?php echo e($statusFilter==='sold' ? 'selected' : ''); ?>>Contains: sold</option>
                    <option value="withdrawn" <?php echo e($statusFilter==='withdrawn' ? 'selected' : ''); ?>>Contains: withdrawn</option>
                    <option value="expired" <?php echo e($statusFilter==='expired' ? 'selected' : ''); ?>>Contains: expired</option>
                    <option value="under offer" <?php echo e($statusFilter==='under offer' ? 'selected' : ''); ?>>Contains: under offer</option>
                </select>
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Mandate contains</label>
                <input type="text" name="mandate" value="<?php echo e($mandate); ?>"
                       placeholder="e.g. open / sole"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm" />
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Type contains</label>
                <input type="text" name="type" value="<?php echo e($type); ?>"
                       placeholder="e.g. apartment"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm" />
            </div>

            <div class="flex gap-2">
                <button class="px-3 py-1 rounded-lg text-sm bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                    Apply
                </button>
                <a href="<?php echo e(route('agent.listings')); ?>" class="px-3 py-1 rounded-lg text-sm border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-900">
                    Reset
                </a>
            </div>
        </form>

        <div class="flex flex-wrap items-start gap-3">
            <div class="flex items-center gap-2">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Mandate</div>
                <div class="flex flex-wrap gap-2">
                    <?php $__empty_1 = true; $__currentLoopData = $byMandate; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <a href="<?php echo e(route('agent.listings', array_merge(request()->except('page'), ['mandate' => $m->label]))); ?>"
                           class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full text-xs border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/40 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-900">
                            <span class="font-semibold"><?php echo e($m->c); ?></span>
                            <span><?php echo e($m->label); ?></span>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <span class="text-xs text-slate-500 dark:text-slate-400">No mandate data</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="px-1 text-slate-400 dark:text-slate-500 font-semibold select-none">|</div>

            <div class="flex items-center gap-2">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Type</div>
                <div class="flex flex-wrap gap-2">
                    <?php $__empty_1 = true; $__currentLoopData = $byType; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <a href="<?php echo e(route('agent.listings', array_merge(request()->except('page'), ['type' => $t->label]))); ?>"
                           class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full text-xs border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/40 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-900">
                            <span class="font-semibold"><?php echo e($t->c); ?></span>
                            <span><?php echo e($t->label); ?></span>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <span class="text-xs text-slate-500 dark:text-slate-400">No type data</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-3 py-2 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Listings</div>
            <div class="text-xs text-slate-500 dark:text-slate-400"><?php echo e($listings->total()); ?> total (page <?php echo e($listings->currentPage()); ?> of <?php echo e($listings->lastPage()); ?>)</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
<tr>
                        <th class="text-left px-3 py-2">Status</th>
                        <th class="text-left px-3 py-2">Mandate</th>
                        <th class="text-left px-3 py-2">Type</th>
                        <th class="text-right px-3 py-2">DOM</th>
                        <th class="text-right px-3 py-2">Since edit</th>
                        <th class="text-left px-3 py-2">Expiry</th>
                        <th class="text-right px-3 py-2">Price</th>
                        <th class="text-right px-3 py-2">CMA (R)</th>
                        <th class="text-right px-3 py-2">Ref</th>
                    </tr>
                </thead>
                
<tbody class="divide-y divide-slate-200 dark:divide-slate-800">

<?php $__empty_1 = true; $__currentLoopData = $listings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

<tr class="bg-slate-50/40 dark:bg-slate-900/20">
<td colspan="9" class="px-3 py-2">
<div class="font-semibold text-slate-900 dark:text-slate-100">
<?php echo e(trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], ' ', (string)($l->property ?? '')))) ?: ($l->region ?: '(no address)')); ?>

</div>
</td>
</tr>

<tr>

<td class="px-3 py-2 text-slate-700 dark:text-slate-200"><?php echo e($l->status); ?></td>

<td class="px-3 py-2 text-slate-700 dark:text-slate-200"><?php echo e($l->mandate); ?></td>

<td class="px-3 py-2 text-slate-700 dark:text-slate-200"><?php echo e($l->type); ?></td>

<td class="px-3 py-2 text-right font-semibold text-slate-900 dark:text-slate-100">
<?php echo e($l->days_on_market !== null ? (int)$l->days_on_market : "—"); ?>

</td>

<td class="px-3 py-2 text-right font-semibold text-slate-900 dark:text-slate-100">
<?php echo e($l->days_since_edit !== null ? (int)$l->days_since_edit : "—"); ?>

</td>

<td class="px-3 py-2 text-xs">
<?php if($l->expires_at): ?>
<div class="font-medium"><?php echo e($l->expires_on); ?></div>
<?php $dte = $l->days_to_expiry; ?>
<?php if(!is_null($dte)): ?>
<?php if($dte < 0): ?>
<div class="text-slate-500">expired <?php echo e(abs((int)$dte)); ?>d ago</div>
<?php else: ?>
<div class="text-slate-500">in <?php echo e((int)$dte); ?>d</div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
—
<?php endif; ?>
</td>

<td class="px-3 py-2 text-right font-semibold">
<?php if($l->price_cents !== null): ?>
R <?php echo e(number_format($l->price_cents/100, 0)); ?>

<?php else: ?>
—
<?php endif; ?>
</td>

<td class="px-3 py-2 text-right">

<form method="POST" action="<?php echo e(route('agent.listings.cma', $l)); ?>" class="flex items-center justify-end gap-2">

<?php echo csrf_field(); ?>

<input name="cma_value"
value="<?php echo e($l->cma_price_cents !== null ? number_format($l->cma_price_cents/100, 0, '.', '') : ''); ?>"
placeholder="e.g. 1250000"
class="w-28 text-right rounded-lg border border-slate-300 dark:border-slate-700 px-2 py-1 text-xs" />

<button class="px-2 py-1 rounded-lg bg-slate-900 text-white text-xs">
Save
</button>

</form>

<?php if($l->cma_updated_at): ?>
<div class="text-[10px] text-slate-500 mt-1">
updated <?php echo e(is_string($l->cma_updated_at) ? substr($l->cma_updated_at,0,10) : $l->cma_updated_at->format('Y-m-d')); ?>

</div>
<?php endif; ?>

</td>

<td class="px-3 py-2 text-right text-xs text-slate-500">
<?php echo e($l->external_ref ?? $l->external_id ?? '—'); ?>

</td>

</tr>

<tr class="h-3"><td colspan="9"></td></tr>

<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

<tr>
<td colspan="9" class="px-4 py-6 text-center text-slate-500">
No listings found.
</td>
</tr>

<?php endif; ?>

</tbody>

            </table>
        </div>

        <div class="px-3 py-2 border-t border-slate-200 dark:border-slate-800">
            <?php echo e($listings->links()); ?>

        </div>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/agent/listings/index.blade.php ENDPATH**/ ?>