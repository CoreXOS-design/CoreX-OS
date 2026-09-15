{{--
    Johan, QA1, independent testing (cc5) — RA-01: "a DRAFT application —
    created but the agent has never clicked Send — is already fully
    fillable at its public token URL, with nothing telling the applicant
    it was never sent." The token is generated at creation now (an earlier
    round's fix, so a link exists to share immediately even before Send),
    which is exactly what made this reachable: a real, live token for an
    application nobody was ever told to fill in.

    Decision: show this same "not ready" page rather than a bare 404/expired
    message treated identically to a truly dead link. A 404 would be less
    honest — the link IS real and WILL work the moment the agent sends it —
    and reuses the one existing "can't fill this in right now" page rather
    than inventing a second one, so there's a single consistent unavailable
    experience with a reason-specific message.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ match($reason) { 'not_sent' => 'Not Ready Yet', 'rate_limited' => 'One Moment', default => 'Link Expired' } }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            @if($reason === 'not_sent')
                <h1 class="text-xl font-bold text-slate-800 mb-2">This application isn't ready yet</h1>
                <p class="text-sm text-slate-500">Your agent hasn't sent this application to you yet. Please wait for them to send it, or contact them if you believe this is a mistake.</p>
            @elseif($reason === 'rate_limited')
                {{-- AT-392 round 2, 2026-09-13 — the re-keyed rental-application-show
                     limiter's trip page. Same standard as every other rate-limit
                     message this feature carries: plain words, says what happened,
                     says what to do — never "Too many attempts". --}}
                <h1 class="text-xl font-bold text-slate-800 mb-2">One moment</h1>
                <p class="text-sm text-slate-500">This page is receiving a lot of requests right now, so it's paused for a moment. Your application is safe — please wait a minute and reload.</p>
            @else
                <h1 class="text-xl font-bold text-slate-800 mb-2">This link has expired</h1>
                <p class="text-sm text-slate-500">Please contact your agent for a new link.</p>
            @endif
        </div>
    </div>
</body>
</html>
