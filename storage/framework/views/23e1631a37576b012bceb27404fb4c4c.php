<?php $__env->startSection('nexus-content'); ?>
    
    <?php if(isset($header)): ?>
        <div class="mb-4 rounded-xl bg-[#0b2a4a] px-6 py-4">
            <div class="hfc-onblue-strong">
                <?php echo e($header); ?>

            </div>
        </div>
    <?php endif; ?>

    
    <div class="agency-tracker-shell">
        <aside x-data="{ collapsed: false }"
               :class="collapsed ? 'w-20' : 'w-72'"
               class="agency-tracker-sidebar hidden lg:block shrink-0 transition-all duration-200">
            <?php echo $__env->make('layouts.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        </aside>

        <main class="agency-tracker-content">
            <div class="hfc-card p-4 sm:p-6">
                <?php if (! empty(trim($__env->yieldContent('content')))): ?>
                    <?php echo $__env->yieldContent('content'); ?>
                <?php else: ?>
                    <?php echo e($slot ?? ''); ?>

                <?php endif; ?>
            </div>
        </main>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\resources\views/layouts/app.blade.php ENDPATH**/ ?>