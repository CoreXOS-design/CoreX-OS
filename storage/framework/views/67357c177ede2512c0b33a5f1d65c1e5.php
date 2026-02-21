<?php $__env->startSection('nexus-content'); ?>

<div class="mb-6 flex items-center justify-between">
    <h1 class="text-xl font-bold text-gray-800"><?php echo e($pageTitle); ?></h1>
    <a href="<?php echo e(route('presentations.index')); ?>"
       class="text-xs text-indigo-600 hover:underline">← All Presentations</a>
</div>


<form method="GET" class="mb-4 flex flex-wrap gap-2 items-end">
    <?php if($isAdmin): ?>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Branch</label>
            <select name="branch_id" class="border border-gray-300 rounded px-2 py-1.5 text-xs">
                <option value="">All branches</option>
                <?php $__currentLoopData = $branches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $branch): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($branch->id); ?>"
                        <?php echo e(($filters['branch_id'] ?? '') == $branch->id ? 'selected' : ''); ?>>
                        <?php echo e($branch->name); ?>

                    </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">User ID</label>
            <input type="number" name="user_id" value="<?php echo e($filters['user_id'] ?? ''); ?>"
                   placeholder="Any"
                   class="border border-gray-300 rounded px-2 py-1.5 text-xs w-24">
        </div>
    <?php endif; ?>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Presentation ID</label>
        <input type="number" name="presentation_id" value="<?php echo e($filters['presentation_id'] ?? ''); ?>"
               placeholder="Any"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Period (YYYY-MM)</label>
        <input type="text" name="period" value="<?php echo e($filters['period'] ?? ''); ?>"
               placeholder="e.g. 2026-02"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <button type="submit"
            class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded hover:bg-indigo-700">
        Filter
    </button>

    <?php if(array_filter($filters)): ?>
        <a href="<?php echo e(request()->url()); ?>"
           class="px-3 py-1.5 border border-gray-300 text-gray-500 text-xs rounded hover:bg-gray-50">
            Clear
        </a>
    <?php endif; ?>
</form>


<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compiled</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Presentation</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compiled by</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Blueprint</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Links</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php $__empty_1 = true; $__currentLoopData = $versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                        <?php echo e($version->compiled_at?->format('Y-m-d H:i') ?? '—'); ?>

                    </td>
                    <td class="px-4 py-3 text-xs text-gray-800">
                        <?php echo e($version->presentation?->title ?? '#' . $version->presentation_id); ?>

                        <span class="text-gray-400 ml-1">(<?php echo e($version->presentation?->suburb ?? ''); ?>)</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        <?php echo e($version->compiledBy?->name ?? 'User #' . $version->compiled_by); ?>

                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        <?php echo e($version->blueprint_version ?? '—'); ?>

                    </td>
                    <td class="px-4 py-3 text-right text-xs">
                        <?php if($version->presentation): ?>
                            <a href="<?php echo e(route('presentations.show', $version->presentation_id)); ?>"
                               class="text-indigo-600 hover:underline mr-3">
                                Presentation →
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400 italic">
                        No compiled versions found.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if($versions->hasPages()): ?>
    <div class="mt-4">
        <?php echo e($versions->links()); ?>

    </div>
<?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/presentations/versions/index.blade.php ENDPATH**/ ?>