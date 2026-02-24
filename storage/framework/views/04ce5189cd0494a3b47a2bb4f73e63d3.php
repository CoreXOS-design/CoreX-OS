<?php $__env->startSection('content'); ?>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Document Types</h2>
            <div class="text-sm text-white/60">Manage categories for document templates.</div>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?php echo e(route('docuperfect.settings.namedFields')); ?>" class="text-sm text-white/70 hover:text-white">Named Fields</a>
            <a href="<?php echo e(route('docuperfect.dashboard')); ?>" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    <?php if(session('status')): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            <?php echo e(session('status')); ?>

        </div>
    <?php endif; ?>

    <?php if(session('error')): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            <?php echo e(session('error')); ?>

        </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            <?php echo e($errors->first()); ?>

        </div>
    <?php endif; ?>

    
    <div class="ds-status-card p-4">
        <h3 class="ds-section-header mb-3">Add document type</h3>

        <form method="POST" action="<?php echo e(route('docuperfect.settings.types.store')); ?>" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <?php echo csrf_field(); ?>

            <div class="md:col-span-7">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input name="name" required
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. Mandates">
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                <input name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="0">
            </div>

            <div class="md:col-span-2">
                <button class="w-full nexus-btn-primary text-sm">Add</button>
            </div>
        </form>
    </div>

    
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Current types</div>
            <div class="text-xs text-slate-500 dark:text-slate-400"><?php echo e(count($types)); ?> total</div>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            <?php $__empty_1 = true; $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="p-4">
                    <form method="POST" action="<?php echo e(route('docuperfect.settings.types.update', $type->id)); ?>" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        <?php echo csrf_field(); ?>
                        <?php echo method_field('PUT'); ?>

                        <div class="md:col-span-6">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                            <input name="name" value="<?php echo e($type->name); ?>" required
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="<?php echo e((int)$type->sort_order); ?>"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-1">
                            <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-sm font-semibold">
                                Save
                            </button>
                        </div>

                        <div class="md:col-span-2 flex items-end gap-2">
                            <span class="text-xs text-slate-400"><?php echo e($type->templates()->count()); ?> template<?php echo e($type->templates()->count() !== 1 ? 's' : ''); ?></span>
                        </div>
                    </form>

                    <form method="POST" action="<?php echo e(route('docuperfect.settings.types.destroy', $type->id)); ?>"
                          onsubmit="return confirm('Delete this document type? This cannot be undone.');"
                          class="mt-2">
                        <?php echo csrf_field(); ?>
                        <?php echo method_field('DELETE'); ?>
                        <button class="text-xs font-semibold text-red-600 hover:text-red-700" <?php echo e($type->templates()->count() > 0 ? 'disabled title=Cannot delete — templates assigned' : ''); ?>>
                            Delete
                        </button>
                    </form>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="p-6 text-sm text-slate-500 dark:text-slate-400">
                    No document types found.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/docuperfect/settings/types.blade.php ENDPATH**/ ?>