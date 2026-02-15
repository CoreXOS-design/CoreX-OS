<?php $__env->startSection('nexus-content'); ?>
<div x-data="{ activeTab: 'permissions' }">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Role &amp; Permissions Manager</h1>
            <p class="text-sm text-gray-500 mt-1">Manage role-based permissions for each section of the system.</p>
        </div>
    </div>

    <?php if(session('success')): ?>
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    
    <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 w-fit">
        <button @click="activeTab = 'permissions'"
                :class="activeTab === 'permissions'
                    ? 'bg-white text-gray-900 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Permissions Matrix
        </button>
        <button @click="activeTab = 'users'"
                :class="activeTab === 'users'
                    ? 'bg-white text-gray-900 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            User Roles
        </button>
    </div>

    
    <div x-show="activeTab === 'permissions'" x-cloak>
        <form method="POST" action="<?php echo e(route('nexus.role-manager.save')); ?>">
            <?php echo csrf_field(); ?>
            <div class="nexus-panel">
                <div class="nexus-panel-header">
                    <h3 class="nexus-panel-title">Section Permissions</h3>
                    <button type="submit" class="nexus-btn-primary" style="padding: 8px 20px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Save Changes
                    </button>
                </div>
                <div class="nexus-panel-body p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50">
                                    <th class="text-left py-3 px-4 font-semibold text-gray-700 sticky left-0 bg-gray-50 z-10 min-w-[260px]">
                                        Permission
                                    </th>
                                    <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <th class="text-center py-3 px-3 font-semibold text-gray-700 min-w-[120px]">
                                            <div class="flex flex-col items-center gap-1">
                                                <?php
                                                    $roleBadge = match($role) {
                                                        'admin' => 'nexus-badge-blue',
                                                        'branch_manager' => 'nexus-badge-green',
                                                        default => 'nexus-badge-yellow',
                                                    };
                                                ?>
                                                <span class="nexus-badge <?php echo e($roleBadge); ?>">
                                                    <?php echo e(str_replace('_', ' ', $role)); ?>

                                                </span>
                                            </div>
                                        </th>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $lastSection = ''; ?>
                                <?php $__currentLoopData = $permissions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $perm): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php if($perm->section !== $lastSection): ?>
                                        <?php $lastSection = $perm->section; ?>
                                        <tr class="bg-gray-50/80">
                                            <td colspan="<?php echo e(count($roles) + 1); ?>" class="py-2 px-4">
                                                <span class="text-xs font-bold text-indigo-600 uppercase tracking-wider">
                                                    <?php echo e(str_replace('-', ' ', $perm->section)); ?>

                                                </span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/50 transition-colors">
                                        <td class="py-2.5 px-4 font-medium text-gray-800 sticky left-0 bg-white z-10">
                                            <?php echo e($perm->label); ?>

                                        </td>
                                        <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <td class="py-2.5 px-3 text-center">
                                                <label class="inline-flex items-center justify-center cursor-pointer">
                                                    <input type="hidden"
                                                           name="permissions[<?php echo e($perm->key); ?>][<?php echo e($role); ?>]"
                                                           value="0">
                                                    <input type="checkbox"
                                                           name="permissions[<?php echo e($perm->key); ?>][<?php echo e($role); ?>]"
                                                           value="1"
                                                           <?php echo e(isset($granted[$perm->key][$role]) ? 'checked' : ''); ?>

                                                           class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                                </label>
                                            </td>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                                <?php if($permissions->isEmpty()): ?>
                                    <tr>
                                        <td colspan="<?php echo e(count($roles) + 1); ?>" class="py-12 text-center text-gray-400">
                                            No permissions defined yet. Run the seeder to populate default permissions.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>

    
    <div x-show="activeTab === 'users'" x-cloak>
        <div class="nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">User Roles</h3>
            </div>
            <div class="nexus-panel-body p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50">
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Name</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Email</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700">Current Role</th>
                                <?php if(auth()->user()->isEffectiveAdmin()): ?>
                                    <th class="text-left py-3 px-4 font-semibold text-gray-700">Change Role</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50/50 transition-colors">
                                    <td class="py-2.5 px-4 font-medium text-gray-900"><?php echo e($u->name); ?></td>
                                    <td class="py-2.5 px-4 text-gray-600"><?php echo e($u->email); ?></td>
                                    <td class="py-2.5 px-4">
                                        <?php
                                            $roleBadge = match($u->role) {
                                                'admin' => 'nexus-badge-blue',
                                                'branch_manager' => 'nexus-badge-green',
                                                default => 'nexus-badge-yellow',
                                            };
                                        ?>
                                        <span class="nexus-badge <?php echo e($roleBadge); ?>"><?php echo e(str_replace('_', ' ', $u->role)); ?></span>
                                    </td>
                                    <?php if(auth()->user()->isEffectiveAdmin()): ?>
                                        <td class="py-2.5 px-4">
                                            <form method="POST" action="<?php echo e(route('nexus.role-manager.user-role')); ?>" class="flex items-center gap-2">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="user_id" value="<?php echo e($u->id); ?>">
                                                <select name="role" class="text-xs border-gray-300 rounded-md py-1.5 px-2 bg-white" style="max-width:160px">
                                                    <option value="agent" <?php echo e($u->role === 'agent' ? 'selected' : ''); ?>>Agent</option>
                                                    <option value="branch_manager" <?php echo e($u->role === 'branch_manager' ? 'selected' : ''); ?>>Branch Manager</option>
                                                    <option value="admin" <?php echo e($u->role === 'admin' ? 'selected' : ''); ?>>Admin</option>
                                                </select>
                                                <button type="submit" class="nexus-btn-primary" style="padding:6px 14px;font-size:12px;min-height:auto;min-width:auto">Save</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\resources\views/nexus/role-manager.blade.php ENDPATH**/ ?>