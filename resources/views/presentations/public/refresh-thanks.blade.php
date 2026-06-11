{{--
    Phase 7 — refresh-request confirmation.

    Renders three states:
      - success         (default): "thanks, we've sent it"
      - rate_limited    (429):     "you've already asked recently"
--}}
@php
    $rateLimited = $rate_limited ?? false;
    $rateMsg     = $rate_limit_msg ?? null;
    $agentName   = ($agent ?? $link->creator)?->name ?? 'The agent';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $rateLimited ? 'Already sent' : 'Request sent' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:520px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:40px 32px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .icon { width:56px; height:56px; margin:0 auto 16px; border-radius:50%;
            display:flex; align-items:center; justify-content:center; font-size:28px; }
        .icon.ok { background:rgba(0,181,148,0.12); color:#00b594; }
        .icon.warn { background:rgba(245,158,11,0.12); color:#d97706; }
        h1 { font-size:1.35rem; margin:0 0 10px 0; }
        h1.ok   { color:#00b594; }
        h1.warn { color:#d97706; }
        p  { color:#475569; line-height:1.55; margin:6px 0; font-size:0.95rem; }
        .meta { margin-top:18px; padding-top:18px; border-top:1px solid #e2e8f0; font-size:0.85rem; color:#64748b; }
    </style>
</head>
<body>
<div class="card">
    @if($rateLimited)
        <div class="icon warn">!</div>
        <h1 class="warn">You've already asked recently</h1>
        <p>{{ $rateMsg ?: 'A refresh request has already been logged. Please give the agent a moment to respond.' }}</p>
        <p class="meta">{{ $agentName }} has been notified and will be in touch.</p>
    @else
        <div class="icon ok">&check;</div>
        <h1 class="ok">Thanks — your request has been sent</h1>
        <p>{{ $agentName }} will be in touch shortly with a refreshed presentation.</p>
        <p class="meta">You can close this window. We'll keep this share link active until the new one is ready.</p>
    @endif
</div>
@include('public.partials.privacy-footer')
</body>
</html>
