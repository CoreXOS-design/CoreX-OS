<?php $__env->startSection('nexus-content'); ?>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Presentations</h1>
        <p class="text-sm text-gray-500 mt-1">Upload & Extraction Framework — Scaffold</p>
    </div>

    <?php if(session('success')): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-700 mb-3">Upload Document (Scaffold — Presentation ID 1)</h2>
        <p class="text-xs text-gray-400 mb-4">
            Stores file → extracts text → detects fields. No AI. No PDF output yet.
        </p>

        <form method="POST"
              action="<?php echo e(route('presentations.upload', 1)); ?>"
              enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="flex items-center gap-4">
                <input type="file"
                       name="document"
                       accept=".pdf,.doc,.docx,.txt"
                       class="text-sm text-gray-600 border border-gray-300 rounded px-3 py-2 w-full">
                <button type="submit"
                        class="shrink-0 px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">
                    Upload
                </button>
            </div>
            <?php $__errorArgs = ['document'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="mt-1 text-xs text-red-600"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </form>
    </div>

    
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-base font-semibold text-gray-700 mb-3">Uploaded Documents</h2>

        <?php if($uploads->isEmpty()): ?>
            <p class="text-sm text-gray-400">No uploads yet.</p>
        <?php else: ?>
            <table class="w-full text-sm text-left text-gray-600">
                <thead>
                    <tr class="border-b">
                        <th class="pb-2 pr-4">File</th>
                        <th class="pb-2 pr-4">Type</th>
                        <th class="pb-2 pr-4">Extraction</th>
                        <th class="pb-2">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $uploads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $upload): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="border-b last:border-0">
                            <td class="py-2 pr-4"><?php echo e($upload->original_filename); ?></td>
                            <td class="py-2 pr-4 text-gray-400 text-xs"><?php echo e($upload->type); ?></td>
                            <td class="py-2 pr-4">
                                <?php if($upload->extraction_status === 'ok'): ?>
                                    <span class="text-green-600 font-medium">OK</span>
                                <?php elseif($upload->extraction_status === 'failed'): ?>
                                    <span class="text-red-500">Failed</span>
                                <?php else: ?>
                                    <span class="text-yellow-500">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-gray-400 text-xs"><?php echo e($upload->created_at->format('Y-m-d H:i')); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/presentations/index.blade.php ENDPATH**/ ?>