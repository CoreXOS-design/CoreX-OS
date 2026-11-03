{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold v3 pitch path (22.10): the plain answer after "No owner found". Chrome-less inside the pop-up (embed mode), a normal page otherwise. --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold')

@section('corex-content')
<div class="mx-auto max-w-xl py-10 px-4">
    <div class="rounded-md px-5 py-4 text-sm font-medium"
         style="background: color-mix(in srgb, var({{ $ok ? '--ds-green' : '--ds-crimson' }}) 10%, transparent); border: 1px solid color-mix(in srgb, var({{ $ok ? '--ds-green' : '--ds-crimson' }}) 30%, transparent); color: var(--text-primary);"
         role="status">
        {{ $message }}
    </div>
    <p class="mt-4 text-sm" style="color: var(--text-muted);">
        <a href="{{ $back }}" class="corex-btn-secondary">Back to Road to Sold</a>
    </p>
</div>
@endsection
