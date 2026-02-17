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
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">User Management</h1>
                <p class="text-sm text-slate-600 dark:text-slate-300">Manage roles, branch assignment, designation (prints), and default commission/PAYE settings.</p>

                <?php if(session('status')): ?>
                    <div class="mt-4 rounded-2xl bg-emerald-50 text-emerald-800 border border-emerald-200 px-4 py-3">
                        <?php echo e(session('status')); ?>

                    </div>
                <?php endif; ?>

                <?php if($errors->any()): ?>
                    <div class="mt-4 rounded-2xl bg-red-50 text-red-800 border border-red-200 px-4 py-3">
                        <ul class="list-disc pl-5 text-sm space-y-1">
                            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li><?php echo e($e); ?></li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <?php
                $uCol = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
                $totalUsers = is_countable($uCol) ? count($uCol) : 0;
                $activeUsers = method_exists($uCol, 'where') ? $uCol->where('is_active', true)->count() : 0;
            ?>

            <div class="text-right">
                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Users</div>
                <div class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100"><?php echo e(number_format((int)$totalUsers)); ?></div>
                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Active: <?php echo e(number_format((int)$activeUsers)); ?>

                </div>
            </div>
        </div>

        <div class="space-y-3">
            <?php $__currentLoopData = ($users instanceof \Illuminate\Pagination\AbstractPaginator ? $users : $users); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
                    <div class="px-4 py-3 bg-slate-900 dark:bg-slate-800 text-white flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-white truncate"><?php echo e($user->name); ?></div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-white/15 bg-white/10 text-white">
                                    <?php echo e(str_replace('_', ' ', (string)$user->role)); ?>

                                </span>

                                <?php if($user->is_active): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-emerald-300/40 bg-emerald-400/15 text-emerald-100">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-red-300/40 bg-red-400/15 text-red-100">
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-1 text-sm text-slate-200 truncate"><?php echo e($user->email); ?></div>

                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-white/15 bg-white/10">
                                    <span class="font-semibold text-white">Branch:</span>
                                    <span><?php echo e(optional(($branches ?? collect())->firstWhere('id', $user->branch_id))->name ?? '(none)'); ?></span>
                                </span>

                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-white/15 bg-white/10">
                                    <span class="font-semibold text-white">Designation:</span>
                                    <span><?php echo e($user->designation ?: '(blank)'); ?></span>
                                </span>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-2">
                            <form method="POST" action="<?php echo e(route('admin.users.toggle', $user)); ?>">
                                <?php echo csrf_field(); ?>
                                <button class="px-3 py-1.5 rounded-lg text-sm bg-white text-slate-900 hover:bg-slate-100">
                                    Toggle Active
                                </button>
                            </form>

                            <form method="POST" action="<?php echo e(route('admin.users.delete', $user)); ?>" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                <?php echo csrf_field(); ?>
                                <button class="px-3 py-1.5 rounded-lg text-sm border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/40 dark:text-red-300 dark:hover:bg-red-900/20">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="p-4">
                        <form method="POST" action="<?php echo e(route('admin.users.role.update', $user)); ?>" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            <?php echo csrf_field(); ?>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Role</label>
                                <select name="role" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="agent" <?php echo e($user->role==='agent'?'selected':''); ?>>agent</option>
                                    <option value="branch_manager" <?php echo e($user->role==='branch_manager'?'selected':''); ?>>branch_manager</option>
                                    <option value="admin" <?php echo e($user->role==='admin'?'selected':''); ?>>admin</option>
                                </select>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Branch</label>
                                <select name="branch_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="">(no branch)</option>
                                    <?php $__currentLoopData = ($branches ?? []); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($b->id); ?>" <?php echo e((string)$user->branch_id === (string)$b->id ? 'selected' : ''); ?>><?php echo e($b->name); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </select>
                            </div>

                            <div class="md:col-span-5">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Designation (prints on certificates)</label>
                                <select name="designation" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <?php $des = old('designation', $user->designation ?? ''); ?>
                                    
                                    <option value="" <?php echo e($des==='' ? 'selected' : ''); ?>>(blank)</option>
                                    <?php $__currentLoopData = ($designations ?? []); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($d->name); ?>" <?php echo e($des===$d->name ? 'selected' : ''); ?>><?php echo e($d->name); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                                </select>
                                <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Used on Commission Summary + Current Market Analysis prints.</div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="<?php echo e(old('agent_cut_percent', $user->agent_cut_percent ?? 50)); ?>"
                                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">PAYE Method</label>
                                <?php $pm = old('paye_method', $user->paye_method ?? 'percentage'); ?>
                                <select name="paye_method" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="percentage" <?php echo e($pm==='percentage'?'selected':''); ?>>percentage</option>
                                    <option value="fixed" <?php echo e($pm==='fixed'?'selected':''); ?>>fixed</option>
                                </select>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="<?php echo e(old('paye_value', $user->paye_value ?? 0)); ?>"
                                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-2 flex items-center gap-2 pt-6">
                                <input type="hidden" name="sliding_enabled" value="0" />
                                <input type="checkbox" name="sliding_enabled" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                       <?php echo e(old('sliding_enabled', (int)($user->sliding_enabled ?? 0)) ? 'checked' : ''); ?> />
                                <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Sliding</span>
                            </div>


                            <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                <input type="hidden" name="can_capture_rentals" value="0" />
                                <input type="checkbox" name="can_capture_rentals" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                       <?php echo e(old('can_capture_rentals', (int)($user->can_capture_rentals ?? 0)) ? 'checked' : ''); ?> />
                                <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Can Capture Rentals</span>
                            </div>


                              <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                  <input type="hidden" name="counts_for_branch_split" value="0" />
                                  <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                         <?php echo e(old('counts_for_branch_split', (int)($user->counts_for_branch_split ?? 1)) ? 'checked' : ''); ?> />
                                  <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Counts for Branch Split</span>
                              </div>

                            <div class="md:col-span-12 flex items-center justify-between gap-3 pt-2">
                                <div class="text-xs text-slate-500 dark:text-slate-400">One save updates role/branch/designation + defaults.</div>
                                <button type="submit" class="px-3 py-2 rounded-lg text-sm bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                                    Save User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>

        <?php if($users instanceof \Illuminate\Pagination\AbstractPaginator): ?>
            <div class="pt-2">
                <?php echo e($users->links()); ?>

            </div>
        <?php endif; ?>

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
<?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/admin/users/index.blade.php ENDPATH**/ ?>