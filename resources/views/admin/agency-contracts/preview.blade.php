{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — template preview with sample data (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('admin.agency-contracts.templates.edit', $template->id) }}" class="text-xs underline" style="color:var(--text-muted);">← Back to template</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Preview — {{ $template->name }}</h1>
        <p class="text-xs" style="color: var(--text-muted);">Shown with sample agency details. The real send uses the chosen agency's details.</p>
    </div>
    @if($error)
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">This template cannot be sent yet: {{ $error }}</div>
    @endif
    <div class="rounded-md p-6" style="background:#fff; border:1px solid var(--border); color:#111827;"><div class="text-sm leading-relaxed">{!! $html !!}</div></div>
</div>
@endsection
