<?php $__env->startSection('content'); ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Daily Activity Summary (Branch)</h1>
            <div class="text-sm text-slate-600">
                <?php echo e($branchName ?? ('Branch #' . (int)$branchId)); ?> · <?php echo e($start->toFormattedDateString()); ?> → <?php echo e($end->toFormattedDateString()); ?>

            </div>
        </div>

        <form method="GET" action="<?php echo e(route('bm.daily.summary')); ?>" class="flex flex-wrap items-center gap-2">
            <select name="range" class="rounded-xl border-slate-200 text-sm">
                <option value="7d"  <?php echo e($range==='7d' ? 'selected' : ''); ?>>Last 7 days</option>
                <option value="month" <?php echo e($range==='month' ? 'selected' : ''); ?>>This month</option>
                <option value="3m"  <?php echo e($range==='3m' ? 'selected' : ''); ?>>Last 3 months</option>
                <option value="6m"  <?php echo e($range==='6m' ? 'selected' : ''); ?>>Last 6 months</option>
                <option value="12m" <?php echo e($range==='12m' ? 'selected' : ''); ?>>Last 12 months</option>
            </select>

            <?php if($range === 'month'): ?>
                <input type="text" name="month" value="<?php echo e($month ?? ''); ?>" placeholder="YYYY-MM"
                       class="w-28 rounded-xl border-slate-200 text-sm" />
            <?php endif; ?>

            <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold">Apply</button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Total Count</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900"><?php echo e((int)$grandCount); ?></div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Total Points</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900"><?php echo e(number_format((float)$grandPoints, 0)); ?></div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Activities Tracked</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900"><?php echo e(count($items)); ?></div>
        </div>
    </div>

    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b bg-slate-50/60">
            <div class="font-semibold text-slate-900">By Activity</div>
            <div class="text-xs text-slate-500">Click the activity name or count to drill down to agents.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-white">
                    <tr class="border-b text-slate-600">
                        <th class="text-left p-3">Activity</th>
                        <th class="text-right p-3">Count</th>
                        <th class="text-right p-3">Points</th>
                        <th class="text-right p-3">% (Points)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $it): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="border-b hover:bg-slate-50/70">
                            <td class="p-3 font-medium text-slate-900">
                                <a class="hover:underline"
                                   href="<?php echo e(route('bm.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month]))); ?>">
                                    <?php echo e($it['name']); ?>

                                </a>
                            </td>
                            <td class="p-3 text-right">
                                <a class="inline-flex items-center rounded-lg bg-slate-900/5 px-2 py-1 font-semibold text-slate-900 hover:bg-slate-900/10 hover:underline"
                                   href="<?php echo e(route('bm.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month]))); ?>">
                                    <?php echo e((int)$it['count']); ?>

                                </a>
                            </td>
                            <td class="p-3 text-right"><?php echo e(number_format((float)$it['points'], 0)); ?></td>
                            <td class="p-3 text-right"><?php echo e(number_format((float)$it['pct_points'], 1)); ?>%</td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/bm/daily-summary/index.blade.php ENDPATH**/ ?>