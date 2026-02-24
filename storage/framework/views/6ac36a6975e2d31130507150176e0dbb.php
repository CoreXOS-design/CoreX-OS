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
     <?php $__env->slot('header', null, []); ?> 
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">
                        Filing Register &mdash; <?php echo e($branchName); ?>

                    </h2>
                    <div class="text-sm text-white/60">Searchable index of physically filed mandates</div>
                </div>
            </div>
        </div>
     <?php $__env->endSlot(); ?>

    <div class="space-y-6">

        
        <?php if(session('success')): ?>
        <div class="ds-status-card" style="border-left-color: var(--ds-green);">
            <div class="text-sm text-green-700 font-semibold"><?php echo e(session('success')); ?></div>
        </div>
        <?php endif; ?>

        
        <div class="ds-status-card">
            <form method="GET" action="<?php echo e(route('filing-register.index')); ?>" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="ds-label block mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo e(request('search')); ?>" placeholder="Address, reference, seller, seq..." class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-1 focus:ring-cyan-500 focus:border-cyan-500">
                </div>
                <div>
                    <label class="ds-label block mb-1">Type</label>
                    <select name="document_type" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="All" <?php echo e(request('document_type') === 'All' ? 'selected' : ''); ?>>All</option>
                        <option value="OA" <?php echo e(request('document_type') === 'OA' ? 'selected' : ''); ?>>OA</option>
                        <option value="EA" <?php echo e(request('document_type') === 'EA' ? 'selected' : ''); ?>>EA</option>
                        <option value="Other" <?php echo e(request('document_type') === 'Other' ? 'selected' : ''); ?>>Other</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Status</label>
                    <select name="status" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="All" <?php echo e(request('status') === 'All' ? 'selected' : ''); ?>>All</option>
                        <option value="Active" <?php echo e(request('status') === 'Active' ? 'selected' : ''); ?>>Active</option>
                        <option value="Expiring" <?php echo e(request('status') === 'Expiring' ? 'selected' : ''); ?>>Expiring Soon</option>
                        <option value="Expired" <?php echo e(request('status') === 'Expired' ? 'selected' : ''); ?>>Expired</option>
                    </select>
                </div>
                <?php if($isAdmin): ?>
                <div>
                    <label class="ds-label block mb-1">Branch</label>
                    <select name="branch_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Branches</option>
                        <?php $__currentLoopData = $branches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $branch): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($branch->id); ?>" <?php echo e(request('branch_id') == $branch->id ? 'selected' : ''); ?>><?php echo e($branch->name); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="ds-label block mb-1">Agent</label>
                    <select name="agent_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Agents</option>
                        <?php $__currentLoopData = $agents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($ag->id); ?>" <?php echo e(request('agent_id') == $ag->id ? 'selected' : ''); ?>><?php echo e($ag->name); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="nexus-btn-primary px-4 py-2 rounded-lg text-sm">Filter</button>
                </div>
                <?php if(request()->hasAny(['search','document_type','status','branch_id','agent_id'])): ?>
                <div>
                    <a href="<?php echo e(route('filing-register.index')); ?>" class="text-sm text-gray-500 hover:text-gray-700 underline">Clear</a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">Total Filed</div>
                <div class="ds-value-lg"><?php echo e($totalCount); ?></div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-green);">
                <div class="ds-label">Active</div>
                <div class="ds-value-lg" style="color: var(--ds-green);"><?php echo e($activeCount); ?></div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
                <div class="ds-label">Expiring (30 days)</div>
                <div class="ds-value-lg" style="color: var(--ds-amber);"><?php echo e($expiringCount); ?></div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-crimson);">
                <div class="ds-label">Expired</div>
                <div class="ds-value-lg" style="color: var(--ds-crimson);"><?php echo e($expiredCount); ?></div>
            </div>
        </div>

        
        <?php if($isAdmin): ?>
        <div class="ds-status-card" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2 text-sm font-semibold" style="color: var(--ds-navy);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Filing
                <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>

            <form method="POST" action="<?php echo e(route('filing-register.store')); ?>" x-show="open" x-cloak x-transition class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="ds-label block mb-1">Branch *</label>
                    <select name="branch_id" required tabindex="1" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <?php $__currentLoopData = $branches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $branch): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($branch->id); ?>"><?php echo e($branch->name); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Agent *</label>
                    <select name="agent_id" required tabindex="2" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">Select Agent</option>
                        <?php $__currentLoopData = $agents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($ag->id); ?>"><?php echo e($ag->name); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Type *</label>
                    <select name="document_type" required tabindex="3" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="OA">OA (Open Authority)</option>
                        <option value="EA">EA (Exclusive Authority)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">File Reference *</label>
                    <input type="text" name="file_reference" required tabindex="4" placeholder="e.g. File 3" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Sequence Number *</label>
                    <input type="text" name="sequence_number" required tabindex="5" placeholder="e.g. 0042" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Property Address *</label>
                    <input type="text" name="property_address" required tabindex="6" placeholder="e.g. 21 Dee Road, Uvongo" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Seller Name</label>
                    <input type="text" name="seller_name" tabindex="7" placeholder="Optional" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" tabindex="8" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Notes</label>
                    <input type="text" name="notes" tabindex="9" placeholder="Optional" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div class="md:col-span-3">
                    <button type="submit" tabindex="10" class="nexus-btn-primary px-6 py-2 rounded-lg text-sm font-semibold">Save Filing</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        
        <div class="ds-status-card" style="padding:0; overflow:hidden;">
            <div class="table-scroll">
                <table class="w-full text-sm ds-table table-sticky">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left">Ref</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Property Address</th>
                            <th class="px-4 py-3 text-left">Seller</th>
                            <th class="px-4 py-3 text-left">Agent</th>
                            <th class="px-4 py-3 text-left">Expiry</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <?php if($isAdmin): ?>
                            <th class="px-4 py-3 text-right">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__empty_1 = true; $__currentLoopData = $filings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $filing): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr x-data="{ editing: false }">
                            
                            <template x-if="!editing">
                                <td class="px-4 py-3 font-mono text-xs whitespace-nowrap"><?php echo e($filing->full_reference); ?></td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">
                                    <?php if($filing->document_type === 'OA'): ?>
                                        <span class="ds-badge ds-badge-info">OA</span>
                                    <?php elseif($filing->document_type === 'EA'): ?>
                                        <span class="ds-badge" style="background: var(--ds-cyan); color: #fff;">EA</span>
                                    <?php else: ?>
                                        <span class="ds-badge ds-badge-default">Other</span>
                                    <?php endif; ?>
                                </td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3"><?php echo e($filing->property_address); ?></td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3 text-gray-600"><?php echo e($filing->seller_name ?? '—'); ?></td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3"><?php echo e($filing->agent->name ?? '—'); ?></td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?php echo e($filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : '—'); ?>

                                </td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">
                                    <?php if($filing->status === 'active'): ?>
                                        <span class="ds-badge ds-badge-success">Active</span>
                                    <?php elseif($filing->status === 'expiring'): ?>
                                        <span class="ds-badge ds-badge-warning">Expires in <?php echo e((int) now()->diffInDays($filing->expiry_date)); ?>d</span>
                                    <?php else: ?>
                                        <span class="ds-badge ds-badge-danger">Expired <?php echo e((int) $filing->expiry_date->diffInDays(now())); ?>d ago</span>
                                    <?php endif; ?>
                                </td>
                            </template>
                            <?php if($isAdmin): ?>
                            <template x-if="!editing">
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button @click="editing = true" class="text-xs text-blue-600 hover:underline mr-2">Edit</button>
                                    <form method="POST" action="<?php echo e(route('filing-register.destroy', $filing->id)); ?>" class="inline" onsubmit="return confirm('Delete this filing entry?')">
                                        <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                        <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </template>
                            <?php endif; ?>

                            
                            <?php if($isAdmin): ?>
                            <template x-if="editing">
                                <td colspan="<?php echo e($isAdmin ? 8 : 7); ?>" class="px-4 py-3">
                                    <form method="POST" action="<?php echo e(route('filing-register.update', $filing->id)); ?>" class="flex flex-wrap items-end gap-3">
                                        <?php echo csrf_field(); ?> <?php echo method_field('PUT'); ?>
                                        <input type="hidden" name="branch_id" value="<?php echo e($filing->branch_id); ?>">
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Agent</label>
                                            <select name="agent_id" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                                <?php $__currentLoopData = $agents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <option value="<?php echo e($ag->id); ?>" <?php echo e($filing->agent_id == $ag->id ? 'selected' : ''); ?>><?php echo e($ag->name); ?></option>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Type</label>
                                            <select name="document_type" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                                <option value="OA" <?php echo e($filing->document_type === 'OA' ? 'selected' : ''); ?>>OA</option>
                                                <option value="EA" <?php echo e($filing->document_type === 'EA' ? 'selected' : ''); ?>>EA</option>
                                                <option value="Other" <?php echo e($filing->document_type === 'Other' ? 'selected' : ''); ?>>Other</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">File Ref</label>
                                            <input type="text" name="file_reference" value="<?php echo e($filing->file_reference); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs w-20">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Seq #</label>
                                            <input type="text" name="sequence_number" value="<?php echo e($filing->sequence_number); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs w-16">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Address</label>
                                            <input type="text" name="property_address" value="<?php echo e($filing->property_address); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs w-40">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Seller</label>
                                            <input type="text" name="seller_name" value="<?php echo e($filing->seller_name); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs w-28">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Expiry</label>
                                            <input type="date" name="expiry_date" value="<?php echo e($filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : ''); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Notes</label>
                                            <input type="text" name="notes" value="<?php echo e($filing->notes); ?>" class="px-2 py-1 border border-gray-200 rounded text-xs w-28">
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit" class="nexus-btn-primary px-3 py-1 rounded text-xs">Save</button>
                                            <button type="button" @click="editing = false" class="px-3 py-1 rounded text-xs bg-gray-200 hover:bg-gray-300">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </template>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="<?php echo e($isAdmin ? 8 : 7); ?>" class="px-4 py-8 text-center text-gray-400">
                                No filing entries found. <?php echo e($isAdmin ? 'Click "+ New Filing" to add one.' : ''); ?>

                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/filing-register/index.blade.php ENDPATH**/ ?>