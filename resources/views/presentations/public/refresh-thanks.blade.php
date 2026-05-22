{{-- Phase 4 — refresh request confirmation. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request sent</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:480px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:36px 28px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        h1 { font-size:1.25rem; margin:0 0 8px 0; color:#00b594; }
        p  { color:#475569; line-height:1.5; margin:6px 0; font-size:0.9375rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Thanks — your request has been sent</h1>
    <p>{{ $link->creator?->name ?? 'The agent' }} will be in touch shortly with a refreshed presentation.</p>
</div>
</body>
</html>
