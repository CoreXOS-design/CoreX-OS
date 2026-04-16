<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link expired — CoreX OS</title>
    @vite(['resources/css/app.css', 'resources/css/corex.css'])
</head>
<body class="antialiased" style="background:#f8fafc;">
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full rounded-md bg-white border border-slate-200 p-8 text-center shadow-sm">
        <h1 class="text-xl font-bold mb-2">This onboarding link is no longer active</h1>
        <p class="text-sm text-slate-600 mb-4">
            The link has expired or been revoked by the administrator. Please contact Home Finders Coastal for a fresh link.
        </p>
        <div class="text-xs text-slate-400">
            Powered by CoreX OS · © {{ date('Y') }} Home Finders Coastal
        </div>
    </div>
</div>
</body>
</html>
