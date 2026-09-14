{{--
    Return gate, AT-392 round 4, 2026-09-13 — Johan: "initial open is not
    gated but if the applicant submits... after initial submission we can
    gate on ID." Deliberately a SHORT, single-purpose page — no internal
    scroll container by design (a real Windows Chrome affordability-panel
    bug this session found showed a classic scrollbar can sit on top of a
    control's right edge; the fix here is not needing to scroll at all).

    Two methods, one screen: 'id_number' (default, a speed bump — the
    applicant's own ID number, already on the application) or 'email_otp'
    (agency-configurable stronger option, via CoreX's existing OtpService).
    $lockedOut renders the "contact your agent" way-forward Johan required
    — never a bare refusal, never a technical error, and the SAME plain
    sentence whether the ID was close or not (no oracle).

    Conductor, 2026-09-13 — this WAS a raw HTML comment (`<!-- -->`),
    which ships to every visitor's page source verbatim, unlike a Blade
    comment. It named the default gate method and called it out
    explicitly as "not authentication" — accurate, but not something an
    unauthenticated visitor needs handed to them in view-source. Same
    class of lesson as tonight's contact-property finding: check what
    actually reaches the browser, not just what the rendered page shows.
    No behaviour change — Blade comments are stripped server-side.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify It's You</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-center">
            @if($lockedOut ?? false)
                <h1 class="text-xl font-bold text-slate-800 mb-2">We can't verify you right now</h1>
                <p class="text-sm text-slate-500 mb-4">
                    Please contact
                    @if($agentName ?? null)
                        <strong>{{ $agentName }}</strong>
                    @else
                        your agent
                    @endif
                    to continue.
                </p>
                @if(($agentEmail ?? null) || ($agentPhone ?? null))
                    <p class="text-sm text-slate-600">
                        @if($agentEmail ?? null)
                            <a href="mailto:{{ $agentEmail }}" class="text-blue-700 hover:underline">{{ $agentEmail }}</a>
                        @endif
                        @if(($agentEmail ?? null) && ($agentPhone ?? null))
                            &middot;
                        @endif
                        @if($agentPhone ?? null)
                            <a href="tel:{{ $agentPhone }}" class="text-blue-700 hover:underline">{{ $agentPhone }}</a>
                        @endif
                    </p>
                @endif
            @else
                <h1 class="text-xl font-bold text-slate-800 mb-2">Verify it's you</h1>

                @if($errors->has('gate'))
                    <p class="text-sm text-red-600 mb-3">{{ $errors->first('gate') }}</p>
                @endif
                @if(session('gate_status'))
                    <p class="text-sm text-emerald-600 mb-3">{{ session('gate_status') }}</p>
                @endif

                @if(($method ?? 'id_number') === 'email_otp')
                    <p class="text-sm text-slate-500 mb-4">
                        We've sent a 6-digit code to
                        @if($maskedEmail ?? null)
                            <strong>{{ $maskedEmail }}</strong>.
                        @else
                            your email.
                        @endif
                    </p>
                    <form method="POST" action="{{ route($verifyRouteName ?? 'rental-applications.public.verify-gate', $token) }}">
                        @csrf
                        <input type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code"
                               maxlength="6" placeholder="6-digit code"
                               class="w-full text-center text-lg tracking-widest rounded-lg border border-slate-300 px-3 py-3 mb-3"
                               autofocus>
                        <button type="submit" class="w-full rounded-lg text-white font-semibold py-3 text-sm"
                                style="background: var(--brand-default, #0b2a4a);">Continue</button>
                    </form>
                    <form method="POST" action="{{ route($resendRouteName ?? 'rental-applications.public.gate.resend-otp', $token) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="text-xs text-slate-500 hover:text-slate-700 underline">Resend code</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500 mb-4">Enter your ID number to continue.</p>
                    <form method="POST" action="{{ route($verifyRouteName ?? 'rental-applications.public.verify-gate', $token) }}">
                        @csrf
                        <input type="text" name="id_number" inputmode="numeric" placeholder="ID number"
                               class="w-full text-center text-lg rounded-lg border border-slate-300 px-3 py-3 mb-3"
                               autofocus>
                        <button type="submit" class="w-full rounded-lg text-white font-semibold py-3 text-sm"
                                style="background: var(--brand-default, #0b2a4a);">Continue</button>
                    </form>
                @endif

                <p class="text-xs text-slate-400 mt-4">
                    Trouble accessing your application?
                    @if($agentName ?? null)
                        Contact {{ $agentName }}
                        @if($agentEmail ?? null)
                            at <a href="mailto:{{ $agentEmail }}" class="text-blue-700 hover:underline">{{ $agentEmail }}</a>
                        @endif
                    @else
                        Contact your agent
                    @endif
                    .
                </p>
            @endif
        </div>
    </div>
</body>
</html>
