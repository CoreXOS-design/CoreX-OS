<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Please confirm</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 min-h-screen">
<main class="max-w-md mx-auto p-4 pt-10">
    <div class="bg-white rounded-xl shadow p-6 space-y-5">
        @if($state === 'open')
            <h1 class="text-lg font-bold text-slate-900">Please confirm</h1>
            <p class="text-base text-slate-800">{{ $line }}</p>
            <p class="text-sm text-slate-500">We will not release your title deed to them unless you approve.</p>
            <form method="POST" action="{{ route('title-deeds.consent.respond', $token) }}" class="grid grid-cols-2 gap-3">
                @csrf
                <button type="submit" name="decision" value="approve" class="rounded-lg bg-emerald-700 text-white font-semibold py-3 text-base">Approve</button>
                <button type="submit" name="decision" value="decline" class="rounded-lg bg-white border border-slate-300 text-slate-800 font-semibold py-3 text-base">Decline</button>
            </form>
        @else
            <h1 class="text-lg font-bold text-slate-900">
                @if($state === 'approved') Approved @elseif($state === 'declined') Declined @else Thank you @endif
            </h1>
            <p class="text-base text-slate-800">{{ $message }}</p>
        @endif
    </div>
</main>
</body>
</html>
