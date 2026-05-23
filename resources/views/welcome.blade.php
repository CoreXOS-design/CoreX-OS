<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home Finders Coastal</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=3">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=3">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=3">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

    <!-- Top right auth links -->
    <div class="w-full flex justify-end gap-6 p-6 text-sm font-semibold">
        @auth
            <a href="{{ url('/dashboard') }}" class="text-gray-700 hover:underline">Dashboard</a>
        @else
            <a href="{{ route('login') }}" class="text-gray-700 hover:underline">Log in</a>
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="text-gray-700 hover:underline">Register</a>
            @endif
        @endauth
    </div>

    <!-- Center logo -->
    <div class="flex-grow flex items-center justify-center">
        <img src="{{ asset('images/logo.png') }}"
             alt="Home Finders Coastal"
             class="w-auto h-28 md:h-36">
    </div>

</body>
</html>
