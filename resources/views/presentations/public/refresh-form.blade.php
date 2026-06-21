{{--
    Phase 7 — public refresh-request form.

    Reachable from:
      - the stale-data banner on /p/{token}
      - the full-page expired-with-refresh gate
      - a direct visit to /p/{token}/refresh

    Validation lives on the controller; this form only POSTs the values.
    The honeypot (company_name) catches bot submissions silently.
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
    <title>Request a refreshed presentation</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:560px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:32px 28px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        h1 { font-size:1.4rem; margin:0 0 6px 0; }
        .lead { color:#475569; line-height:1.5; margin:0 0 24px 0; font-size:0.95rem; }
        label { display:block; font-size:0.72rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        input, textarea { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.95rem; font-family:inherit; margin-bottom:14px; background:#fff; color:#0f172a; }
        input:focus, textarea:focus { outline:2px solid #00b594; outline-offset:1px; border-color:#00b594; }
        textarea { min-height:100px; resize:vertical; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width: 540px) { .row { grid-template-columns:1fr; } }
        button { padding:11px 20px; background:#00b594; color:#fff; border:0; border-radius:5px; font-weight:600; cursor:pointer; font-size:0.95rem; }
        button:hover { background:#009579; }
        .hp { position:absolute; left:-9999px; top:-9999px; }
        .agent-contact { margin-top:20px; padding-top:20px; border-top:1px solid #e2e8f0; font-size:0.85rem; color:#475569; }
        .agent-contact strong { color:#0f172a; }
        .errors { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 12px; border-radius:5px; font-size:0.85rem; margin-bottom:16px; }
        .errors ul { margin:4px 0 0 0; padding-left:20px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Request an updated presentation</h1>
    <p class="lead">Let your agent know you'd like a refreshed version. They'll review the latest market data and send you a new link when it's ready.</p>

    @if ($errors->any())
        <div class="errors">
            <strong>Please fix the following:</strong>
            <ul>
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('presentation.public.refresh-submit', $link->token) }}" autocomplete="on">
        @csrf

        {{-- Honeypot — leave empty. Bots will fill it; humans won't see it. --}}
        <div class="hp">
            <label for="company_name">Company</label>
            <input type="text" id="company_name" name="company_name" tabindex="-1" autocomplete="off">
        </div>

        <label for="requester_name">Your name</label>
        <input type="text" id="requester_name" name="requester_name" required maxlength="120"
               value="{{ old('requester_name', $prefill['requester_name'] ?? '') }}"
               autocomplete="name">

        <div class="row">
            <div>
                <label for="requester_email">Email</label>
                <input type="email" id="requester_email" name="requester_email" maxlength="160"
                       value="{{ old('requester_email', $prefill['requester_email'] ?? '') }}"
                       autocomplete="email">
            </div>
            <div>
                <label for="requester_phone">Phone</label>
                <input type="tel" id="requester_phone" name="requester_phone" maxlength="40"
                       value="{{ old('requester_phone', $prefill['requester_phone'] ?? '') }}"
                       autocomplete="tel">
            </div>
        </div>

        <label for="message">Message (optional)</label>
        <textarea id="message" name="message" maxlength="2000"
                  placeholder="Anything you'd like the agent to focus on in the update?">{{ old('message') }}</textarea>

        <button type="submit">Send request</button>
    </form>

    @if($agentName || $agentEmail || $agentPhone)
        <div class="agent-contact">
            <strong>{{ $agentName ?: 'Your agent' }}</strong>
            @if($agentPhone)<div>{{ $agentPhone }}</div>@endif
            @if($agentEmail)<div><a href="mailto:{{ $agentEmail }}" style="color:#00b594;">{{ $agentEmail }}</a></div>@endif
        </div>
    @endif
</div>
</body>
</html>
