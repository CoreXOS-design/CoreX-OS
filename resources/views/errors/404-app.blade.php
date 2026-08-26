{{--
    Authenticated in-app 404 — rendered by the explicit render() callback in
    bootstrap/app.php for a logged-in user, never for a guest. @extends is
    safe HERE because this file is only ever selected when auth()->check()
    is already known to be true (the choice is made in PHP before this view
    is even resolved) — no conditional @extends inside the file itself,
    which is what caused the leak this file replaces (see errors/404.blade.php).
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="flex flex-col items-center justify-center py-20 text-center">
    <div class="text-6xl font-bold mb-2" style="color:var(--text-muted); opacity:0.3;">404</div>
    <h1 class="text-lg font-semibold mb-2" style="color:var(--text-primary);">Page not found</h1>
    <p class="text-sm mb-6" style="color:var(--text-muted);">The page you're looking for doesn't exist or has been moved.</p>
    <div class="flex gap-3">
        <a href="{{ url()->previous() }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">Go Back</a>
        <a href="{{ route('corex.dashboard') }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--brand-button); color:#fff;">Dashboard</a>
    </div>
</div>
@endsection
