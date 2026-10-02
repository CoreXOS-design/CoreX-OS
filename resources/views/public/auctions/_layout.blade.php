<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ $agency->name }}</title>
    <meta property="og:title" content="@yield('title') — {{ $agency->name }}">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
<header class="px-6 py-4 bg-slate-900 text-white">
    <div class="max-w-5xl mx-auto flex items-center gap-3">
        @if($agency->logo_path)<img src="{{ asset('storage/'.$agency->logo_path) }}" alt="{{ $agency->name }}" class="h-12 w-auto">@endif
        <span class="font-semibold">{{ $agency->name }}</span>
    </div>
</header>
<main class="max-w-5xl mx-auto p-4 sm:p-6">
    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-3 py-2 text-sm mb-4">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded bg-red-50 text-red-800 px-3 py-2 text-sm mb-4">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
<footer class="px-6 py-6 text-center text-xs text-slate-400">Powered by CoreX OS</footer>
</body>
</html>
