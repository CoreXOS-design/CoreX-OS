{{--
    Contractor Secure Link — AT-445, .ai/specs/rental-portal-access.md §4
    No login. The token in this URL IS the access control. Mobile-first.
--}}
@php
    $jobCard = $workOrder->jobCard ?? null;
    $tasks = $jobCard ? $jobCard->tasks()->orderBy('sort_order')->get() : collect();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Job — {{ $workOrder->title }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --bg:#f4f6fb; --surface:#fff; --border:rgba(0,0,0,.08); --text:#141821; --muted:#5a6472; --brand:#0b2a4a; --accent:#00b4d8; --ok:#1f8a4c; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family:'Figtree', system-ui, sans-serif; }
        .wrap { max-width: 480px; margin: 0 auto; padding: 16px 16px 48px; }
        header.top { background:var(--brand); color:#fff; padding:18px 16px; }
        header.top h1 { font-size:16px; margin:0; font-weight:700; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:14px; }
        h2 { font-size:15px; margin:0 0 10px; }
        label { display:block; font-size:13px; font-weight:600; margin:10px 0 4px; }
        input, textarea { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:15px; font-family:inherit; }
        .btn { display:inline-block; width:100%; text-align:center; padding:12px; border-radius:10px; border:none; font-weight:700; font-size:15px; cursor:pointer; margin-top:10px; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-ok { background:var(--ok); color:#fff; }
        .muted { color:var(--muted); font-size:13px; }
        ul.tasks { padding-left:18px; margin:0; }
        .alert-success { background:#eafaf0; border:1px solid var(--ok); color:var(--ok); padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .alert-error { background:#fdecea; border:1px solid #c0392b; color:#c0392b; padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:14px; }
    </style>
</head>
<body>
    <header class="top"><h1>Job #{{ $workOrder->id }}</h1></header>
    <div class="wrap">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <div class="card">
            <h2>{{ $workOrder->title }}</h2>
            <p class="muted">{{ $workOrder->property?->buildDisplayAddress() }}</p>
            <p>{{ $workOrder->description }}</p>
            @if ($jobCard?->access_notes)
                <p class="muted"><strong>Access notes:</strong> {{ $jobCard->access_notes }}</p>
            @endif
        </div>

        @if ($tasks->count())
            <div class="card">
                <h2>Tasks</h2>
                <ul class="tasks">
                    @foreach ($tasks as $task)
                        <li>{{ $task->description }} @if ($task->is_done) ✓ @endif</li>
                    @endforeach
                </ul>
            </div>
        @endif


        <div class="card">
            <h2>Upload a quote</h2>
            <form method="POST" action="{{ route('rentals.secure-link.quote', $token) }}" enctype="multipart/form-data">
                @csrf
                <label>Amount (R)</label>
                <input type="number" step="0.01" min="0" name="amount" required>
                <label>Quote date</label>
                <input type="date" name="quote_date" required value="{{ now()->toDateString() }}">
                <label>Details (optional)</label>
                <textarea name="detail_text" rows="2"></textarea>
                <label>Quote document (optional)</label>
                <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif">
                <button class="btn btn-primary" type="submit">Submit quote</button>
            </form>
        </div>

        <div class="card">
            <h2>Upload after photos</h2>
            <form method="POST" action="{{ route('rentals.secure-link.photo', $token) }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="photo" accept="image/*" capture="environment" required>
                <button class="btn btn-primary" type="submit">Upload photo</button>
            </form>
        </div>

        <div class="card">
            <h2>Mark job done</h2>
            <form method="POST" action="{{ route('rentals.secure-link.mark-done', $token) }}">
                @csrf
                <button class="btn btn-ok" type="submit">Mark done</button>
            </form>
        </div>
    </div>
</body>
</html>
