{{--
    Phase 7 — full-page "this share link has expired" gate with an inline
    refresh-request form. Replaces the flat 'expired' branch in unavailable.blade.

    The form posts to the same refresh-submit endpoint as the banner-triggered
    form; we just don't preserve the link contents (the seller can't see them
    once expired) — only the agent contact + the request CTA.
--}}
@php
    $agentName  = $agent?->name ?? null;
    $agentEmail = $agent?->outward_email ?? null; // AT-79 outward override
    $agentPhone = $agent?->phone ?? null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link expired — request a refresh</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:560px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:36px 32px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .badge { display:inline-block; padding:4px 10px; background:#fef3c7; color:#92400e;
            border-radius:99px; font-size:0.72rem; font-weight:600; letter-spacing:.04em; text-transform:uppercase; margin-bottom:14px; }
        h1 { font-size:1.5rem; margin:0 0 8px 0; }
        .lead { color:#475569; line-height:1.55; margin:0 0 24px 0; font-size:0.95rem; }
        label { display:block; font-size:0.72rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        input, textarea { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.95rem; font-family:inherit; margin-bottom:14px; background:#fff; color:#0f172a; }
        input:focus, textarea:focus { outline:2px solid #00b594; outline-offset:1px; border-color:#00b594; }
        textarea { min-height:90px; resize:vertical; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width: 540px) { .row { grid-template-columns:1fr; } }
        button { padding:11px 20px; background:#00b594; color:#fff; border:0; border-radius:5px; font-weight:600; cursor:pointer; font-size:0.95rem; }
        button:hover { background:#009579; }
        .hp { position:absolute; left:-9999px; top:-9999px; }
        .agent-contact { margin-top:22px; padding-top:22px; border-top:1px solid #e2e8f0; font-size:0.875rem; color:#475569; }
        .agent-contact strong { color:#0f172a; }
    </style>
</head>
<body>
<div class="card">
    <span class="badge">Link expired</span>
    <h1>This share link has expired</h1>
    <p class="lead">No problem — let your agent know you'd like an updated presentation and they'll send a fresh link with the latest market data.</p>

    <form method="POST" action="{{ route('presentation.public.refresh-submit', $link->token) }}" autocomplete="on">
        @csrf

        <div class="hp">
            <label for="company_name">Company</label>
            <input type="text" id="company_name" name="company_name" tabindex="-1" autocomplete="off">
        </div>

        <label for="requester_name">Your name</label>
        <input type="text" id="requester_name" name="requester_name" required maxlength="120"
               value="{{ old('requester_name') }}" autocomplete="name">

        <div class="row">
            <div>
                <label for="requester_email">Email</label>
                <input type="email" id="requester_email" name="requester_email" maxlength="160"
                       value="{{ old('requester_email') }}" autocomplete="email">
            </div>
            <div>
                <label for="requester_phone">Phone</label>
                <input type="tel" id="requester_phone" name="requester_phone" maxlength="40"
                       value="{{ old('requester_phone') }}" autocomplete="tel">
            </div>
        </div>

        <label for="message">Message (optional)</label>
        <textarea id="message" name="message" maxlength="2000"
                  placeholder="Anything specific you'd like the updated report to cover?">{{ old('message') }}</textarea>

        <button type="submit">Request refreshed presentation</button>
    </form>

    @if($agentName || $agentEmail || $agentPhone)
        <div class="agent-contact">
            Or reach out directly:
            <div style="margin-top:6px;"><strong>{{ $agentName ?: 'Your agent' }}</strong></div>
            @if($agentPhone)<div>{{ $agentPhone }}</div>@endif
            @if($agentEmail)<div><a href="mailto:{{ $agentEmail }}" style="color:#00b594;">{{ $agentEmail }}</a></div>@endif
        </div>
    @endif
</div>
</body>
</html>
