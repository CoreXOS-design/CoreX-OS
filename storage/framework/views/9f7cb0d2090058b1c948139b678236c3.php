<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

        <title><?php echo e(config('app.name', 'Laravel')); ?></title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts & Styles -->
        <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/css/nexus.css', 'resources/js/app.js']); ?>
        <link rel="stylesheet" href="/css/paye-fix.css">
    </head>
    <body class="font-sans antialiased">
        
        <div x-data="{ sidebarOpen: false }" class="flex h-screen overflow-hidden bg-gray-100">

            
            <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/50 z-40 lg:hidden" x-cloak></div>

            
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-50 w-60 transform transition-transform duration-200 ease-in-out lg:relative lg:translate-x-0 lg:flex-shrink-0">
                <?php echo $__env->make('layouts.nexus-sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </aside>

            
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                
                <div class="flex items-center lg:hidden px-4 py-2 bg-white border-b border-gray-200">
                    <button @click="sidebarOpen = true" type="button" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold text-gray-900">nexus <span class="text-indigo-600">os</span></span>
                </div>

                <?php echo $__env->make('layouts.nexus-header', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

                
                <main class="flex-1 overflow-y-auto bg-gray-100 p-4 lg:p-6">
                    <?php if (! empty(trim($__env->yieldContent('nexus-content')))): ?>
                        <?php echo $__env->yieldContent('nexus-content'); ?>
                    <?php else: ?>
                        <?php echo e($slot ?? ''); ?>

                    <?php endif; ?>
                </main>
            </div>
        </div>

        
        <?php if(auth()->guard()->check()): ?>
            <?php echo $__env->make('layouts.partials.ellie-widget', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?>
    </body>
</html>
<?php /**PATH C:\Users\johan\OneDrive\Documents\GitHub\hfc-dash\resources\views/layouts/nexus.blade.php ENDPATH**/ ?>