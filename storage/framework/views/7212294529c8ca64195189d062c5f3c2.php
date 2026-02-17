<?php $__env->startSection('nexus-content'); ?>
    <div class="flex justify-end mb-6">
        <div class="text-right">
            <form method="POST" action="<?php echo e(route('logout')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">Logout</button>
            </form>
            <div class="text-xs text-gray-500 mt-1"><?php echo e(auth()->user()->name ?? 'User'); ?></div>
            <a href="/make-me-admin" class="text-xs text-indigo-600 hover:text-indigo-800 block mt-1">Grant Admin Rights</a>
        </div>
    </div>

    
    <div class="nexus-kpi-grid mb-6">
        <?php if (isset($component)) { $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.nexus-kpi-card','data' => ['title' => 'Active Deals','value' => $activeDeals,'trend' => $dealsTrend,'trendUp' => $dealsTrend >= 0,'iconBg' => 'bg-indigo-100 text-indigo-600']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('nexus-kpi-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Active Deals','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($activeDeals),'trend' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dealsTrend),'trend-up' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dealsTrend >= 0),'icon-bg' => 'bg-indigo-100 text-indigo-600']); ?>
             <?php $__env->slot('icon', null, []); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                </svg>
             <?php $__env->endSlot(); ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $attributes = $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $component = $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>

        <?php if (isset($component)) { $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.nexus-kpi-card','data' => ['title' => 'Active Listings','value' => $activeListings,'trend' => 0,'trendUp' => true,'iconBg' => 'bg-emerald-100 text-emerald-600']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('nexus-kpi-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Active Listings','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($activeListings),'trend' => 0,'trend-up' => true,'icon-bg' => 'bg-emerald-100 text-emerald-600']); ?>
             <?php $__env->slot('icon', null, []); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
             <?php $__env->endSlot(); ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $attributes = $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $component = $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>

        <?php if (isset($component)) { $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.nexus-kpi-card','data' => ['title' => 'Revenue','value' => 'R ' . number_format($revenue, 0, '.', ','),'trend' => $revenueTrend,'trendUp' => $revenueTrend >= 0,'iconBg' => 'bg-amber-100 text-amber-600']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('nexus-kpi-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Revenue','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('R ' . number_format($revenue, 0, '.', ',')),'trend' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($revenueTrend),'trend-up' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($revenueTrend >= 0),'icon-bg' => 'bg-amber-100 text-amber-600']); ?>
             <?php $__env->slot('icon', null, []); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
             <?php $__env->endSlot(); ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $attributes = $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $component = $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>

        <?php if (isset($component)) { $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.nexus-kpi-card','data' => ['title' => 'Pending Deals','value' => $pendingDeals,'trend' => 0,'trendUp' => false,'iconBg' => 'bg-red-100 text-red-600']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('nexus-kpi-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Pending Deals','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingDeals),'trend' => 0,'trend-up' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'icon-bg' => 'bg-red-100 text-red-600']); ?>
             <?php $__env->slot('icon', null, []); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
             <?php $__env->endSlot(); ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $attributes = $__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__attributesOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af)): ?>
<?php $component = $__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af; ?>
<?php unset($__componentOriginalcebc3fa82c387e68e5e1cf4e6d1130af); ?>
<?php endif; ?>
    </div>

    
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        
        <div class="xl:col-span-2 nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">Transaction Volume</h3>
                <span class="text-xs text-gray-500">Last 6 months</span>
            </div>
            <div class="nexus-panel-body">
                <div class="nexus-chart-container"
                     x-data
                     x-init="NexusCharts.transactionVolume('txVolumeChart', <?php echo \Illuminate\Support\Js::from($chartData->keys()->toArray())->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($chartData->values()->toArray())->toHtml() ?>)">
                    <canvas id="txVolumeChart"></canvas>
                </div>
            </div>
        </div>

        
        <div class="nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">Approval Queue</h3>
                <a href="<?php echo e(route('admin.deals')); ?>" class="text-xs text-indigo-600 font-medium hover:underline">View all</a>
            </div>
            <div class="nexus-panel-body">
                <?php $__empty_1 = true; $__currentLoopData = $approvalQueue; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $deal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="nexus-queue-item">
                        <div>
                            <div class="nexus-queue-label"><?php echo e($deal->deal_no ?: ('Deal #' . $deal->id)); ?></div>
                            <div class="nexus-queue-sub"><?php echo e(Str::limit($deal->property_address, 30)); ?></div>
                        </div>
                        <?php
                            $badgeClass = match($deal->commission_status) {
                                'Granted' => 'nexus-badge-green',
                                'Pending' => 'nexus-badge-yellow',
                                'Declined' => 'nexus-badge-red',
                                'Registered' => 'nexus-badge-blue',
                                default => 'nexus-badge-yellow',
                            };
                        ?>
                        <span class="nexus-badge <?php echo e($badgeClass); ?>"><?php echo e($deal->commission_status); ?></span>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-sm text-gray-400 py-4 text-center">No items in queue</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    
    <div class="nexus-panel">
        <div class="nexus-panel-header">
            <h3 class="nexus-panel-title">Recent Activity</h3>
        </div>
        <div class="nexus-panel-body">
            <?php $__empty_1 = true; $__currentLoopData = $recentActivity; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="nexus-activity-item">
                    <div class="nexus-activity-dot"></div>
                    <div>
                        <div class="nexus-activity-text">
                            <strong><?php echo e($log->actor?->name ?? 'System'); ?></strong>
                            <?php echo e($log->event_type); ?>

                            <?php if($log->deal): ?>
                                on deal <strong><?php echo e($log->deal->deal_no ?: ('#' . $log->deal_id)); ?></strong>
                            <?php endif; ?>
                            <?php if($log->message): ?>
                                &mdash; <?php echo e(Str::limit($log->message, 60)); ?>

                            <?php endif; ?>
                        </div>
                        <div class="nexus-activity-time"><?php echo e($log->created_at?->diffForHumans() ?? ''); ?></div>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-gray-400 py-4 text-center">No recent activity</p>
            <?php endif; ?>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.nexus', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/nexus/dashboard.blade.php ENDPATH**/ ?>