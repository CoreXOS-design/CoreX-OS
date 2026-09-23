{{--
    Johan, 2026-09-23, approved — the same page whether the token never
    existed, has expired, or was revoked: never distinguishing "wrong" from
    "expired" to an unauthenticated caller (RentalInspectionPublicController::
    show()'s own docblock). $reason only ever separates the rate-limit case,
    which is a genuinely different situation the reader should be told about.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($reason ?? null) === 'rate_limited' ? 'One Moment' : 'Link Unavailable' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            @if(($reason ?? null) === 'rate_limited')
                <h1 class="text-xl font-bold text-slate-800 mb-2">One moment</h1>
                <p class="text-sm text-slate-500">This page is receiving a lot of requests right now, so it's paused for a moment. Please wait a minute and reload.</p>
            @else
                <h1 class="text-xl font-bold text-slate-800 mb-2">This link isn't available</h1>
                <p class="text-sm text-slate-500">It may have expired, or a newer link has replaced it. Please contact your agent for a current link.</p>
            @endif
        </div>
    </div>
</body>
</html>
