{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-readiness.md --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $verdict = $result['verdict'];
    $verdictText = [
        'not_ready' => 'Not ready — something must be fixed first',
        'ready_with_warnings' => 'Ready, with things worth a look',
        'ready' => 'Ready',
    ][$verdict];
    $verdictColour = ['not_ready' => 'var(--ds-crimson)', 'ready_with_warnings' => 'var(--ds-amber, #d97706)', 'ready' => 'var(--ds-green)'][$verdict];
    $statusLabel = ['fail' => 'Fix this', 'warn' => 'Look at this', 'info' => 'For your information', 'pass' => 'All good'];
    $statusColour = ['fail' => 'var(--ds-crimson)', 'warn' => 'var(--ds-amber, #d97706)', 'info' => 'var(--text-muted)', 'pass' => 'var(--ds-green)'];
@endphp
<div class="w-full space-y-5" data-readiness-report data-verdict="{{ $verdict }}">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Readiness check: {{ $template->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Is this document ready to be used for a real signing? This page only looks — it changes nothing.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-btn-outline text-xs px-3 py-1.5">Back to templates</a>
                @if(\Illuminate\Support\Facades\Route::has('docuperfect.templates.demo'))
                    <a href="{{ route('docuperfect.templates.demo', $template->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Demo view</a>
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $verdictColour }};">
        <div class="text-sm font-bold" style="color: {{ $verdictColour }};" data-verdict-text>{{ $verdictText }}</div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            {{ $result['counts']['fail'] }} to fix · {{ $result['counts']['warn'] }} to look at · {{ $result['counts']['pass'] }} fine · {{ $result['counts']['info'] }} for information
        </div>
    </div>

    <div class="space-y-3">
        @foreach($checks as $c)
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $statusColour[$c['status']] }};" data-check="{{ $c['id'] }}" data-status="{{ $c['status'] }}">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $c['title'] }}</div>
                    <span class="text-[0.6875rem] font-semibold whitespace-nowrap" style="color: {{ $statusColour[$c['status']] }};">{{ $statusLabel[$c['status']] }}</span>
                </div>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $c['detail'] }}</p>
                @if($c['items'] !== [])
                    <ul class="list-disc pl-5 mt-2 text-xs space-y-0.5" style="color: var(--text-secondary);">
                        @foreach($c['items'] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                @endif
                @if($c['action'] !== '' && in_array($c['status'], ['fail', 'warn'], true))
                    <p class="text-xs mt-2 font-semibold" style="color: var(--text-primary);">What to do: <span class="font-normal">{{ $c['action'] }}</span></p>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
