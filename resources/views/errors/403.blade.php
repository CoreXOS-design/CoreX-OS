@auth
@extends('layouts.corex')

@section('corex-content')
<div class="flex flex-col items-center justify-center py-20 text-center">
    <div class="text-6xl font-bold mb-2" style="color:var(--text-muted); opacity:0.3;">403</div>
    <h1 class="text-lg font-semibold mb-2" style="color:var(--text-primary);">Access denied</h1>
    <p class="text-sm mb-6" style="color:var(--text-muted);">You don't have permission to access this page.</p>
    <div class="flex gap-3">
        <a href="{{ url()->previous() }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">Go Back</a>
        <a href="{{ route('corex.dashboard') }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--brand-button); color:#fff;">Dashboard</a>
    </div>
</div>
@endsection
@else
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — Access Denied</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .box { text-align: center; }
        .code { font-size: 4rem; font-weight: 700; color: #334155; }
        h1 { font-size: 1.25rem; margin: 0.5rem 0; }
        p { font-size: 0.875rem; color: #64748b; margin-bottom: 1.5rem; }
        a { color: #0ea5e9; text-decoration: none; font-size: 0.875rem; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <div class="code">403</div>
        <h1>Access denied</h1>
        <p>You don't have permission to access this page.</p>
        <a href="/">Return to home</a>
    </div>
</body>
</html>
@endauth
