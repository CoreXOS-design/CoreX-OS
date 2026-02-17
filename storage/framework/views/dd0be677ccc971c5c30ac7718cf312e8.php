<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home Finders Coastal</title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

    <!-- Top right auth links -->
    <div class="w-full flex justify-end gap-6 p-6 text-sm font-semibold">
        <?php if(auth()->guard()->check()): ?>
            <a href="<?php echo e(url('/dashboard')); ?>" class="text-gray-700 hover:underline">Dashboard</a>
        <?php else: ?>
            <a href="<?php echo e(route('login')); ?>" class="text-gray-700 hover:underline">Log in</a>
            <?php if(Route::has('register')): ?>
                <a href="<?php echo e(route('register')); ?>" class="text-gray-700 hover:underline">Register</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Center logo -->
    <div class="flex-grow flex items-center justify-center">
        <img src="<?php echo e(asset('images/logo.png')); ?>"
             alt="Home Finders Coastal"
             class="w-auto h-28 md:h-36">
    </div>

</body>
</html>
<?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/welcome.blade.php ENDPATH**/ ?>