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

    <?php if(isset($paidNotSettledDeals) && $paidNotSettledDeals->count() > 0 && auth()->user()?->isEffectiveAdmin()): ?>
        <div x-data="{ openPaidExceptions: false }" class="mb-4">
            <div class="rounded-xl border border-red-500 bg-red-50 px-4 py-3 text-sm text-red-900 flex items-center justify-between gap-3">
                <div class="font-semibold">
                    ⚠ <?php echo e($paidNotSettledDeals->count()); ?> deal<?php echo e($paidNotSettledDeals->count() === 1 ? '' : 's'); ?> marked Paid but Settlement not marked Paid
                </div>
                <button type="button"
                        @click="openPaidExceptions = true"
                        class="rounded-lg bg-red-200/70 px-3 py-1.5 text-xs font-semibold hover:bg-red-200">
                    View exceptions
                </button>
            </div>

            <div x-show="openPaidExceptions" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="openPaidExceptions = false"></div>

                <div class="relative w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-lg font-extrabold text-gray-900">Paid but not settled</div>
                        <button type="button" @click="openPaidExceptions = false" class="text-gray-500 hover:text-gray-800">✕</button>
                    </div>

                    <div class="text-sm text-gray-600 mb-4">
                        These deals are marked <b>Paid</b> on the Deal Register, but settlement has not been marked paid yet.
                        Open each settlement and complete the agent payout workflow.
                    </div>

                    <div class="max-h-[60vh] overflow-auto rounded-xl border border-gray-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left">Deal No</th>
                                    <th class="px-3 py-2 text-left">Property</th>
                                    <th class="px-3 py-2 text-left">Period</th>
                                    <th class="px-3 py-2 text-left">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php $__currentLoopData = $paidNotSettledDeals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 font-semibold text-gray-900"><?php echo e($d->deal_no ?? ('#'.$d->id)); ?></td>
                                        <td class="px-3 py-2 text-gray-700"><?php echo e($d->property_address ?? '—'); ?></td>
                                        <td class="px-3 py-2 text-gray-700"><?php echo e($d->period ?? '—'); ?></td>
                                        <td class="px-3 py-2">
                                            <a href="<?php echo e(route('admin.deals.settle', $d)); ?>" class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800">
                                                Open settlement
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="button" @click="openPaidExceptions = false" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm hover:bg-gray-50">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

     <?php $__env->slot('header', null, []); ?> 
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xl font-semibold text-gray-900">Deal Register</div>
                <div class="text-sm text-gray-500">Operational view for tracking deal status, settlement, and audit log.</div>
            </div>

            <a href="<?php echo e(route('admin.deals.create')); ?>"
               class="inline-flex items-center gap-2 rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300">
                <span class="text-base leading-none">+</span>
                <span>Add Deal</span>
            </a>
        </div>
     <?php $__env->endSlot(); ?>

    <div class="space-y-6">

        <?php if($errors->any()): ?>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?php echo e($errors->first()); ?>

            </div>
        <?php endif; ?>

        
        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
            
            <div class="bg-slate-900 px-5 py-4 text-white">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold tracking-wide uppercase text-white/80">Deals overview</div>
                        <div class="text-lg font-extrabold leading-tight">No sideways scrolling • Everything visible</div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/20">
                            <?php echo e($deals->count()); ?> deals
                        </span>
                    </div>
                </div>
            </div>

            
            <div class="bg-gray-50 p-4 space-y-4">
                <?php $__currentLoopData = $deals->sortByDesc('deal_no'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $deal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $b = $branches->firstWhere('id', $deal->branch_id);
                        $acceptedMap = ['P'=>'Pending','D'=>'Declined','G'=>'Granted','R'=>'Registered'];
                        $asVal = (string)($deal->accepted_status ?? '');
                        $csVal = (string)($deal->commission_status ?? '');
                    ?>

                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        
                        <div class="px-5 py-4 bg-slate-900 text-white">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex items-start gap-3">
                                        <div class="h-10 w-10 rounded-xl bg-white/10 ring-1 ring-white/15 flex items-center justify-center text-xs font-extrabold shrink-0">
                                            <?php echo e(\Illuminate\Support\Str::of($deal->deal_no)->after('D-') ?: '#'); ?>

                                        </div>

                                        <div class="min-w-0">
                                            <div class="text-lg font-extrabold leading-tight">
                                                <?php echo e($deal->deal_no); ?>

                                            </div>

                                            <div class="mt-0.5 text-base font-extrabold text-white/95 break-words">
                                                <?php echo e($deal->property_address ?: '—'); ?>

                                            </div>

                                            <div class="mt-1 text-xs text-white/70">
                                                <?php echo e($deal->seller_name ?: '—'); ?> → <?php echo e($deal->buyer_name ?: '—'); ?>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                
                                <div class="flex flex-wrap items-center justify-start gap-2 lg:justify-end">
                                    <a href="<?php echo e(route('admin.deals.log', $deal)); ?>"
                                       class="inline-flex items-center justify-center rounded-xl bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100">
                                        Log
                                    </a>

                                    <a href="<?php echo e(route('admin.deals.edit', $deal)); ?>"
                                       class="inline-flex items-center justify-center rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                                        Edit
                                    </a>

                                    
                                    <?php if(auth()->user()->isEffectiveAdmin()): ?>
                                        <a href="<?php echo e(route('admin.deals.settle', $deal)); ?>"
                                           class="inline-flex items-center justify-center rounded-xl bg-emerald-500/90 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">
                                            Pay
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        
                        <div class="px-5 py-5">
                            
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div class="rounded-xl border bg-white px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Branch & Period</div>
                                    <div class="mt-1 text-sm text-gray-800">
                                        <div class="font-semibold text-gray-900"><?php echo e($b?->name ?? '—'); ?></div>
                                        <div class="text-xs text-gray-500">Period: <span class="text-gray-800 font-medium"><?php echo e($deal->period ?: '—'); ?></span></div>
                                    </div>
                                </div>

                                <div class="rounded-xl border bg-white px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Selling price & Attorney</div>
                                    <div class="mt-1 text-sm text-gray-800">
                                        <div class="font-semibold text-gray-900">R <?php echo e(number_format((float)$deal->property_value, 0)); ?></div>
                                        <div class="text-xs text-gray-500">Attorney: <span class="text-gray-800 font-medium"><?php echo e($deal->attorney_name ?: '—'); ?></span></div>
                                    </div>
                                </div>

                                <div class="rounded-xl border bg-white px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Our total (Ex VAT)</div>
                                    <div class="mt-1 text-sm text-gray-800">
                                        <div class="text-lg font-extrabold text-gray-900">R <?php echo e(number_format((float)$deal->totalOurCommission(), 0)); ?></div>
                                        <div class="text-xs text-gray-500">Company + agents (before PAYE/deductions)</div>
                                    </div>
                                </div>
                            </div>

                            
                            <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">
                                        Status: <?php echo e($acceptedMap[$asVal] ?? ($asVal ?: '—')); ?>

                                    </span>
                                    <span class="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">
                                        Commission: <?php echo e($csVal ?: '—'); ?>

                                    </span>
                                </div>

                                <form method="POST" action="<?php echo e(route('admin.deals.quickUpdate', $deal)); ?>" class="flex flex-wrap items-center gap-2">
                                    <?php echo csrf_field(); ?>

                                    <select name="accepted_status" class="h-8 rounded-xl border-gray-200 text-xs">
                                        <option value="">—</option>
                                        <option value="P" <?php echo e($asVal === 'P' ? 'selected' : ''); ?>>Pending</option>
                                        <option value="G" <?php echo e($asVal === 'G' ? 'selected' : ''); ?>>Granted</option>
                                        <option value="R" <?php echo e($asVal === 'R' ? 'selected' : ''); ?>>Registered</option>
                                        <option value="D" <?php echo e($asVal === 'D' ? 'selected' : ''); ?>>Declined</option>
                                    </select>

                                    <select name="commission_status" class="h-8 rounded-xl border-gray-200 text-xs">
                                        <option value="">—</option>
                                        <option value="Not Paid" <?php echo e($csVal === 'Not Paid' ? 'selected' : ''); ?>>Not Paid</option>
                                        <option value="Paid" <?php echo e($csVal === 'Paid' ? 'selected' : ''); ?>>Paid</option>
                                        <option value="Loss" <?php echo e($csVal === 'Loss' ? 'selected' : ''); ?>>Loss</option>
                                    </select>

                                    <button type="submit"
                                            class="inline-flex h-8 items-center justify-center rounded-xl bg-gray-900 px-3 text-xs font-semibold text-white shadow-sm hover:bg-gray-800">
                                        Save
                                    </button>
                                </form>
                            </div>

                            
                            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                <div class="rounded-xl bg-white border px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Registration</div>
                                    <div class="mt-1 text-sm text-gray-800">
                                        <span class="font-medium text-gray-900"><?php echo e($deal->registration_date ?: '—'); ?></span>
                                    </div>
                                </div>

                                <div class="rounded-xl bg-white border px-4 py-3 lg:col-span-2">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Gross comm per agent before agency share</div>
                                    <div class="mt-2 text-sm text-gray-800">
                                        <?php $__currentLoopData = $deal->allocations(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $userId => $amount): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <span class="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-1 ring-1 ring-gray-200 mr-1 mb-1">
                                                <?php echo e($userId == 0 ? 'Company (Unallocated)' : ($agents->firstWhere('id', $userId)->name ?? 'Unknown')); ?>

                                                <span class="ml-1 font-semibold text-gray-900">R <?php echo e(number_format($amount, 0)); ?></span>
                                            </span>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </div>
                                </div>

                                <div class="rounded-xl bg-white border px-4 py-3 lg:col-span-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Reference</div>
                                    <div class="mt-1 text-sm text-gray-800">
                                        <span class="text-gray-600">File:</span> <span class="font-medium text-gray-900"><?php echo e($deal->file_no ?: '—'); ?></span>
                                        <span class="mx-2 text-gray-300">|</span>
                                        <span class="text-gray-600">Deal date:</span> <span class="font-medium text-gray-900"><?php echo e($deal->deal_date ?: '—'); ?></span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
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
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/admin/deals/index.blade.php ENDPATH**/ ?>