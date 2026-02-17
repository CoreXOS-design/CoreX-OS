<?php $__env->startSection('nexus-content'); ?>
<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
        <p class="text-sm text-gray-500 mt-1">System configuration and preferences.</p>
    </div>

    <?php if(session('success')): ?>
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <div class="nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">General</h3>
            </div>
            <div class="nexus-panel-body space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Application Name</label>
                    <input type="text" value="<?php echo e(config('app.name')); ?>" disabled
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-sm text-gray-500 cursor-not-allowed">
                    <p class="text-xs text-gray-400 mt-1">Configured in environment settings.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                    <span class="nexus-badge <?php echo e(config('app.env') === 'production' ? 'nexus-badge-red' : 'nexus-badge-green'); ?>">
                        <?php echo e(config('app.env')); ?>

                    </span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Debug Mode</label>
                    <span class="nexus-badge <?php echo e(config('app.debug') ? 'nexus-badge-yellow' : 'nexus-badge-green'); ?>">
                        <?php echo e(config('app.debug') ? 'Enabled' : 'Disabled'); ?>

                    </span>
                </div>
            </div>
        </div>

        
        <div class="nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">Quick Links</h3>
            </div>
            <div class="nexus-panel-body">
                <div class="space-y-2">
                    <a href="<?php echo e(route('nexus.role-manager')); ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors group">
                        <div class="w-9 h-9 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-indigo-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 group-hover:text-indigo-600">Role &amp; Permissions Manager</div>
                            <div class="text-xs text-gray-500">Manage role-based access and user roles</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 ml-auto">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                    <a href="<?php echo e(route('admin.users')); ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors group">
                        <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 group-hover:text-emerald-600">User Management</div>
                            <div class="text-xs text-gray-500">Activate, deactivate, or remove users</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 ml-auto">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                    <a href="<?php echo e(route('admin.designations.index')); ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors group">
                        <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-amber-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 group-hover:text-amber-600">Designations</div>
                            <div class="text-xs text-gray-500">Manage user designation types</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 ml-auto">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                    <a href="<?php echo e(route('admin.branch-assignments')); ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors group">
                        <div class="w-9 h-9 rounded-lg bg-sky-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-sky-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 group-hover:text-sky-600">Branch Assignments</div>
                            <div class="text-xs text-gray-500">Manage branches and user assignments</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 ml-auto">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        
        <div class="nexus-panel lg:col-span-2">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">System Information</h3>
            </div>
            <div class="nexus-panel-body">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">Laravel Version</div>
                        <div class="text-sm font-semibold text-gray-900"><?php echo e(app()->version()); ?></div>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">PHP Version</div>
                        <div class="text-sm font-semibold text-gray-900"><?php echo e(PHP_VERSION); ?></div>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">Database</div>
                        <div class="text-sm font-semibold text-gray-900"><?php echo e(config('database.default')); ?></div>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">Total Users</div>
                        <div class="text-sm font-semibold text-gray-900"><?php echo e(\App\Models\User::count()); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/nexus/settings.blade.php ENDPATH**/ ?>