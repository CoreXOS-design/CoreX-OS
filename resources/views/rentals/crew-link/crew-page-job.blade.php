{{--
    .ai/specs/rental-work-orders.md §14.29 — one card opened FROM the crew page. The
    body is Build 1's shared per-job partial (CrewJobService::payload() +
    _job-body), authorised by the crew token; this wrapper only adds the page
    chrome and a way back to the crew's list.
--}}
@php
    $brand = $job['agency']['color'] ?? '#0b2a4a';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $job['title'] }}</title>
    <style>
        :root { --bg:#f4f6fb; --brand:{{ $brand }}; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-font-smoothing:antialiased; }
        header.top { background:var(--brand); color:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        header.top a { color:#fff; text-decoration:none; font-weight:800; font-size:15px; min-height:44px; display:flex; align-items:center; }
        header.top .crew { font-size:13px; opacity:.85; margin-left:auto; text-align:right; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 16px 14px 56px; }
        .alert-success { background:#eafaf0; border:1px solid #1f8a4c; color:#1f8a4c; padding:11px 13px; border-radius:12px; margin-bottom:14px; font-size:14px; font-weight:600; }
        .alert-error { background:#fdecea; border:1px solid #c0392b; color:#c0392b; padding:11px 13px; border-radius:12px; margin-bottom:14px; font-size:14px; font-weight:600; }
    </style>
</head>
<body>
    <header class="top">
        <a href="{{ route('rentals.crew-page.show', $token) }}" data-back-to-crew-page>&larr; All jobs</a>
        <div class="crew">{{ $crew?->name }}</div>
    </header>
    <div class="wrap" data-crew-page-job>
        @if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert-error">{{ $errors->first() }}</div>@endif

        @include('rentals.crew-link._job-body', ['job' => $job, 'actions' => $actions])
    </div>
@include('partials.corex-confirm')
</body>
</html>
