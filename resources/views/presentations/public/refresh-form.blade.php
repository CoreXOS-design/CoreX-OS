{{-- Phase 4 — refresh-request form (Phase 7 adds the agent-side handler). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request a refreshed presentation</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:'Figtree',system-ui,sans-serif; background:#f4f6fb; color:#0f172a; padding:20px; }
        .card { max-width:520px; width:100%; background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:32px 28px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        h1 { font-size:1.25rem; margin:0 0 6px 0; }
        p  { color:#475569; line-height:1.5; margin:0 0 18px 0; font-size:0.9375rem; }
        label { display:block; font-size:0.75rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        input, textarea { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.875rem; font-family:inherit; margin-bottom:12px; }
        textarea { min-height:90px; resize:vertical; }
        button { padding:10px 16px; background:#00b594; color:#fff; border:0; border-radius:4px; font-weight:600; cursor:pointer; }
    </style>
</head>
<body>
<div class="card">
    <h1>Request an updated presentation</h1>
    <p>Let the agent know you'd like a refreshed version of this presentation. They'll send a new link when ready.</p>
    <form method="POST" action="{{ route('presentation.public.refresh-submit', $link->token) }}">
        @csrf
        <label for="requester_name">Your name</label>
        <input type="text" id="requester_name" name="requester_name" required maxlength="200">

        <label for="message">Message (optional)</label>
        <textarea id="message" name="message" maxlength="2000" placeholder="Anything you'd like the agent to focus on?"></textarea>

        <button type="submit">Send request</button>
    </form>
</div>
</body>
</html>
