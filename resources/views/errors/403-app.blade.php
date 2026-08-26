{{--
    Authenticated in-app 403 — same reasoning as errors/404-app.blade.php.
    Selected in PHP by the render() callback before this view is resolved,
    so an unconditional @extends here is safe.
--}}
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
