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
     <?php $__env->slot('header', null, []); ?> Branch Assignments <?php $__env->endSlot(); ?>

    <div class="space-y-6">

        <?php if(session('success')): ?>
            <div class="p-3 bg-emerald-100 text-emerald-900 rounded-lg border border-emerald-200">
                <?php echo e(session('success')); ?>

            </div>
        <?php endif; ?>

        <?php if($errors->any()): ?>
            <div class="p-3 bg-red-100 text-red-800 rounded-lg border border-red-200">
                <?php echo e($errors->first()); ?>

            </div>
        <?php endif; ?>

        
        <div class="p-4 border rounded-lg space-y-4 bg-white">
            <h3 class="font-bold text-slate-900">Add Branch</h3>

            <form method="POST" action="<?php echo e(route('admin.branches.store')); ?>" class="flex flex-wrap gap-3 items-end">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="text-xs font-semibold text-slate-600">Name</label>
                    <input class="border rounded px-3 py-2" name="name" required>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-600">Code</label>
                    <input class="border rounded px-3 py-2" name="code" required>
                </div>
                <button type="submit" class="px-4 py-2 rounded bg-slate-900 text-white font-semibold">Add Branch</button>
            </form>

            <div class="pt-4 space-y-2">
                <h4 class="font-semibold text-slate-800">Existing Branches</h4>

                <?php $__currentLoopData = $branches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $branch): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="flex items-center justify-between gap-4 border-b pb-2">
                        <div class="font-medium text-slate-900">
                            <?php echo e($branch->name); ?> <span class="text-slate-500">(<?php echo e($branch->code); ?>)</span>
                        </div>

                        <form method="POST" action="<?php echo e(route('admin.branches.delete', $branch)); ?>"
                              onsubmit="return confirm('Delete this branch? This cannot be undone.');">
                            <?php echo csrf_field(); ?>
                            <button class="text-red-600 text-sm font-semibold">Delete</button>
                        </form>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

        
        <div class="p-4 border rounded-lg bg-white space-y-4">
            <div>
                <h3 class="font-bold text-slate-900">Branch Settings</h3>
                <div class="text-sm text-slate-600">
                    These settings are stored per-branch (key/value). Later we can add more keys (logos, banking, templates, etc).
                </div>
            </div>

            <div class="space-y-4">
                <?php $__currentLoopData = $branches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $branch): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $bs = $branchSettingsByBranch[$branch->id] ?? [];
                    ?>

                    <form method="POST" action="<?php echo e(route('admin.branch-settings.update', $branch)); ?>"
                          class="border rounded-xl p-4 space-y-3 bg-slate-50">
                        <?php echo csrf_field(); ?>

                        <div class="flex items-center justify-between gap-4">
                            <div class="font-semibold text-slate-900">
                                <?php echo e($branch->name); ?> <span class="text-slate-500">(<?php echo e($branch->code); ?>)</span>
                            </div>
                            <button type="submit" class="px-4 py-2 rounded bg-slate-900 text-white text-sm font-semibold">
                                Save
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Company Name</label>
                                <input class="border rounded px-3 py-2 w-full"
                                       name="company_name"
                                       value="<?php echo e($bs['company_name'] ?? ''); ?>"
                                       placeholder="e.g. Home Finders Coastal">
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-slate-600">FFC</label>
                                <input class="border rounded px-3 py-2 w-full"
                                       name="company_ffc"
                                       value="<?php echo e($bs['company_ffc'] ?? ''); ?>"
                                       placeholder="e.g. 2023116041">
                            </div>

                            <div class="md:col-span-2">
                                <label class="text-xs font-semibold text-slate-600">Address</label>
                                <input class="border rounded px-3 py-2 w-full"
                                       name="company_address"
                                       value="<?php echo e($bs['company_address'] ?? ''); ?>"
                                       placeholder="e.g. The Emporium Shop 5, Shelly Beach, Margate">
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-slate-600">Telephone</label>
                                <input class="border rounded px-3 py-2 w-full"
                                       name="company_tel"
                                       value="<?php echo e($bs['company_tel'] ?? ''); ?>"
                                       placeholder="e.g. (039) 315 0857">
                            </div>

                            <div class="text-xs text-slate-500 flex items-end">
                                Saved values will be used later for branch-level printing & templates.
                            </div>
                        </div>
                    </form>
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
<?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\resources\views/admin/branch-assignments/index.blade.php ENDPATH**/ ?>