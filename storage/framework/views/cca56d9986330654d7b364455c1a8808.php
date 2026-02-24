<?php $__env->startSection('content'); ?>

<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Rentals Register</h1>

<!-- RENTAL SUMMARY -->
<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:16px;">
    <div style="font-weight:600;margin-bottom:8px;">Register Totals (All Assigned Rentals)</div>
    <div style="font-size:12px;color:#6b7280;margin:-4px 0 10px 0;">Not period-based. For worksheet-matching figures, use <strong>Rentals (This Period)</strong> above.</div>

    <div style="display:flex;gap:24px;margin-bottom:8px;">
        <div>Total Rentals: <strong><?php echo e($summary->total_count ?? 0); ?></strong></div>
        <div>Total Commission (Excl VAT): <strong>R <?php echo e(number_format($summary->total_comm ?? 0, 2)); ?></strong></div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:16px;">
        <?php $__currentLoopData = $summary_per_agent; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;background:white;">
                <div style="font-weight:500;"><?php echo e(data_get($a, 'name')); ?></div>
                <div style="font-size:12px;color:#555;">
                    <?php echo e(data_get($a, 'rental_count', 0)); ?> rentals —
                    R <?php echo e(number_format((float) data_get($a, 'total_comm', 0), 2)); ?>

                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>



        <a href="<?php echo e(route('rentals.create')); ?>"
           class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            + New Rental
        </a>
    </div>

    <div class="bg-white shadow rounded-lg overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-gray-100">
                <tr>
                    <th class="text-left px-4 py-2">Address</th>
                    <th class="text-left px-4 py-2">Lease Start</th>
                    <th class="text-left px-4 py-2">Lease End</th>
                    <th class="text-center px-4 py-2">M2M</th>
                    <th class="text-center px-4 py-2">Active</th>
                    <th class="text-right px-4 py-2">Commission (excl)</th>
                    <th class="text-center px-4 py-2">Assist</th>
                    <th class="text-left px-4 py-2">Agents</th>
                    <th class="text-right px-4 py-2">Edit</th>
                </tr>
            </thead>

            <tbody>

                <?php $__empty_1 = true; $__currentLoopData = $rentals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rental): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                <tr class="border-t">

                    <td class="px-4 py-2">
                        <?php echo e($rental->lease_address); ?>

                    </td>

                    <td class="px-4 py-2">
                        <?php echo e(optional($rental->lease_start_date)->format('Y-m-d')); ?>

                    </td>

                    <td class="px-4 py-2">
                        <?php echo e(optional($rental->lease_end_date)->format('Y-m-d')); ?>

                    </td>

                    <td class="px-4 py-2 text-center">
                        <?php if($rental->is_month_to_month): ?> ✓ <?php endif; ?>
                    </td>

                    <td class="px-4 py-2 text-center">
                        <?php if($rental->is_active): ?> ✓ <?php endif; ?>
                    </td>

                    <td class="px-4 py-2 text-right">
                        <?php echo e(number_format(optional($rental->currentAmountVersion)->commission_excl ?? 0, 2)); ?>

                    </td>

                    <td class="px-4 py-2 text-center">
                        <?php if($rental->is_rental_assist): ?> ✓ <?php endif; ?>
                    </td>

                    <td class="px-4 py-2">

                        <?php $__currentLoopData = $rental->agents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $agent): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div><?php echo e($agent->name); ?></div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                    </td>

                    <td class="px-4 py-2 text-right">

                        <a href="<?php echo e(route('rentals.edit', $rental->id)); ?>"
                           class="text-blue-600 hover:underline">
                            Edit
                        </a>

                    </td>

                </tr>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

                <tr>
                    <td colspan="9" class="px-4 py-6 text-center text-gray-500">
                        No rentals found
                    </td>
                </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/rentals/index.blade.php ENDPATH**/ ?>