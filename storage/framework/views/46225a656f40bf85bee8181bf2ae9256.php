<?php $__env->startSection('content'); ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <?php if(!empty($packInstance)): ?>
            <h2 class="text-xl font-bold text-white leading-tight">Document Pack</h2>
            <div class="text-sm text-white/60">Documents created from this pack. Fill named fields once — they populate across all documents.</div>
            <?php else: ?>
            <h2 class="text-xl font-bold text-white leading-tight">My Documents</h2>
            <div class="text-sm text-white/60">All your filled documents. Create new ones from the <a href="<?php echo e(route('docuperfect.dashboard')); ?>" class="text-white/80 underline">Dashboard</a>.</div>
            <?php endif; ?>
        </div>
        <?php if(!empty($packInstance)): ?>
        <a href="<?php echo e(route('docuperfect.documents.index')); ?>" class="text-sm text-white/70 hover:text-white">Show All</a>
        <?php endif; ?>
    </div>

    <?php if(session('status')): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            <?php echo e(session('status')); ?>

        </div>
    <?php endif; ?>

    <?php if($documents->isEmpty()): ?>
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No documents yet. <a href="<?php echo e(route('docuperfect.dashboard')); ?>" class="ds-link">Create one from a template</a>.</div>
        </div>
    <?php else: ?>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Template</th>
                        <th class="text-left px-4 py-3">Last Edited</th>
                        <?php if($user->isAdmin() || $user->isBranchManager()): ?>
                        <th class="text-left px-4 py-3">Agent</th>
                        <?php endif; ?>
                        <?php if($user->isAdmin()): ?>
                        <th class="text-left px-4 py-3">Branch</th>
                        <?php endif; ?>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900"><?php echo e($doc->name); ?></td>
                        <td class="px-4 py-3 text-slate-600"><?php echo e($doc->template->name ?? '—'); ?></td>
                        <td class="px-4 py-3 text-slate-500"><?php echo e($doc->updated_at->format('d M Y H:i')); ?></td>
                        <?php if($user->isAdmin() || $user->isBranchManager()): ?>
                        <td class="px-4 py-3 text-slate-600"><?php echo e($doc->owner->name ?? '—'); ?></td>
                        <?php endif; ?>
                        <?php if($user->isAdmin()): ?>
                        <td class="px-4 py-3 text-slate-600"><?php echo e($doc->branch->name ?? '—'); ?></td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-right space-x-2">
                            <a href="<?php echo e(route('docuperfect.documents.edit', $doc->id)); ?>" class="ds-link text-sm">Edit</a>
                            <form method="POST" action="<?php echo e(route('docuperfect.documents.archive', $doc->id)); ?>" class="inline" onsubmit="return confirm('Archive this document?');">
                                <?php echo csrf_field(); ?>
                                <button class="text-sm text-slate-400 hover:text-amber-600">Archive</button>
                            </form>
                            <form method="POST" action="<?php echo e(route('docuperfect.documents.destroy', $doc->id)); ?>" class="inline" onsubmit="return confirm('Permanently delete this document?');">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('DELETE'); ?>
                                <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/docuperfect/documents/index.blade.php ENDPATH**/ ?>