{{--
    .ai/specs/rental-work-orders.md §14.28 — the crew's per-job page. No login:
    the token in the URL is the credential. Phone-first; the body is the shared
    _job-body partial (also used by the crew page, §14.29).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $job['title'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; background:#f4f6fb; color:#141821; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-text-size-adjust: 100%; }
        header.top { background: {{ $job['agency']['color'] }}; color:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        header.top img { height:34px; width:auto; max-width:120px; object-fit:contain; background:#fff; border-radius:6px; padding:3px; }
        header.top .name { font-size:15px; font-weight:700; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 14px 14px 40px; }
        .alert-success { background:#eafaf0; border:1px solid #1f8a4c; color:#1f8a4c; padding:12px; border-radius:12px; margin-bottom:14px; font-size:15px; font-weight:600; }
        .alert-error { background:#fdecea; border:1px solid #c0392b; color:#c0392b; padding:12px; border-radius:12px; margin-bottom:14px; font-size:15px; }
    </style>
</head>
<body>
    <header class="top">
        @if($job['agency']['logo_url'])<img src="{{ $job['agency']['logo_url'] }}" alt="">@endif
        <span class="name">{{ $job['agency']['name'] }}</span>
    </header>
    <div class="wrap">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @include('rentals.crew-link._job-body', ['job' => $job, 'actions' => $actions])
    </div>
@include('partials.corex-confirm')
</body>
</html>
