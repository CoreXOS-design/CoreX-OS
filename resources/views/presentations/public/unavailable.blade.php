{{--
    Phase 4 — public unavailable view (link not_found / revoked / expired).

    Friendly copy + agent contact details when the link was found but
    expired/revoked (the 'not_found' case can't expose contact details
    because there's no link to look them up on).
--}}
@php
    $copy = match($reason) {
        'revoked' => 'This share link has been revoked by the agent. They may have sent you an updated link.',
        'expired' => 'This share link has expired. Reach out to the agent for a refreshed version.',
        default   => 'This share link is no longer available. It may have expired, been revoked, or never existed.',
    };
    $heading = match($reason) {
        'revoked' => 'Link revoked',
        'expired' => 'Link expired',
        default   => 'Link unavailable',
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:480px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:36px 28px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        h1 { font-size:1.25rem; margin:0 0 8px 0; }
        p  { color:#475569; line-height:1.5; margin:6px 0; font-size:0.9375rem; }
        .agent-contact { margin-top:18px; padding-top:18px; border-top:1px solid #e2e8f0; font-size:0.875rem; color:#475569; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $heading }}</h1>
    <p>{{ $copy }}</p>
    @if($agent && ($agent->email || $agent->phone))
        <div class="agent-contact">
            Contact your agent
            @if($agent->name)<strong style="color:#0f172a;display:block;margin-top:4px;">{{ $agent->name }}</strong>@endif
            @if($agent->phone)<div>{{ $agent->phone }}</div>@endif
            @if($agent->email)<div><a href="mailto:{{ $agent->email }}" style="color:#00b594;">{{ $agent->email }}</a></div>@endif
        </div>
    @endif
</div>
@include('public.partials.privacy-footer')
</body>
</html>
