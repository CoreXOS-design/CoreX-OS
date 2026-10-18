{{--
    .ai/specs/rental-work-orders.md §17.10.3 — the SAME response whether the token never existed, expired, was revoked,
    or the job is archived. Never distinguishing which, to an unauthenticated caller. The one exception is the plain,
    harmless "response period has ended" sentence for a link that is genuinely live but past its window.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Link unavailable</title>
    <style>
        body { margin:0; background:#f4f6fb; color:#141821; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px; box-sizing:border-box; }
        .card { background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:24px; max-width:360px; text-align:center; }
        h1 { font-size:18px; margin:0 0 8px; }
        p { color:#5a6472; font-size:15px; margin:0; line-height:1.45; }
    </style>
</head>
<body>
    <div class="card">
        @if(!empty($message))
            <h1>{{ $message }}</h1>
            <p>If something is still wrong with the work, please report it as a new fault.</p>
        @else
            <h1>This link is no longer available</h1>
            <p>It may have expired, or the job may be closed. Contact the agency if you need help.</p>
        @endif
    </div>
</body>
</html>
