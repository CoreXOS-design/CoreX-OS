{{-- AT-447 — public, read-only agency timeline. Receives a pre-rendered whitelisted payload only (no models). --}}
@extends('layouts.public')
@section('title', $agencyName . ' — onboarding timeline')
@push('head')
<style>
    .container-public { max-width: 760px; }
    .tl-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.25rem 1.5rem; }
    .tl-step { display:flex; gap:.9rem; padding:.9rem 0; border-top:1px solid #f1f5f9; }
    .tl-step:first-child { border-top:0; }
    .tl-dot { flex:0 0 auto; width:1.5rem; height:1.5rem; border-radius:9999px; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; margin-top:.1rem; }
    .tl-body h3, .tl-body h4 { margin:.5rem 0 .25rem; font-weight:700; } .tl-body p { margin:.35rem 0; } .tl-body ul { margin:.35rem 0 .35rem 1.2rem; list-style:disc; }
</style>
@endpush
@section('public-content')
@php
    $tone = ['done' => ['#16a34a', 'Done'], 'next' => ['#0ea5e9', 'Next up'], 'upcoming' => ['#94a3b8', 'Upcoming'], 'overdue' => ['#dc2626', 'Overdue'], 'skipped' => ['#d97706', 'Skipped']];
@endphp
<div class="space-y-4">
    <div class="tl-card text-center">
        @if($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $agencyName }}" style="max-height:56px; margin:0 auto .75rem;">@endif
        <div class="text-xs uppercase tracking-wider" style="color:#6b7280;">Getting {{ $agencyName }} live on CoreX</div>
        @if($isLive)
            <h1 class="text-xl font-bold mt-1" style="color:#16a34a;">You are live on CoreX 🎉</h1>
        @elseif($expected)
            <h1 class="text-xl font-bold mt-1" style="color:#0b2a4a;">Go live: {{ $expected->format('j F Y') }}</h1>
            @if($slip)<p class="text-sm mt-1" style="color:#dc2626;">Moved from {{ $planned->format('j F') }} — {{ $slip }} day{{ $slip === 1 ? '' : 's' }} of steps are overdue.</p>@endif
        @endif
        <div class="mt-3 h-2 rounded-full overflow-hidden" style="background:#e5e7eb;"><div style="width:{{ $percent }}%; height:100%; background:#00b4d8;"></div></div>
        <div class="text-xs mt-1" style="color:#6b7280;">{{ $percent }}% complete</div>
    </div>

    @foreach($blocks as $b)
        <div class="tl-card"><h2 class="text-base font-bold mb-1" style="color:#0b2a4a;">{{ $b['title'] }}</h2><div class="tl-body text-sm" style="color:#374151;">{!! $b['html'] !!}</div></div>
    @endforeach

    @if($rows->isNotEmpty())
    <div class="tl-card">
        <h2 class="text-base font-bold mb-2" style="color:#0b2a4a;">The plan</h2>
        @foreach($rows as $r)
            @php [$c, $label] = $tone[$r['state']] ?? $tone['upcoming']; @endphp
            <div class="tl-step">
                <div class="tl-dot" style="background:{{ $c }};">{{ $r['state'] === 'done' ? '✓' : ($r['state'] === 'overdue' ? '!' : '') }}</div>
                <div class="tl-body flex-1" style="min-width:0;">
                    <div class="text-sm font-semibold" style="color:#111827;">{{ $r['title'] }}</div>
                    <div class="text-xs mt-0.5" style="color:{{ $c }}; font-weight:600;">
                        {{ $label }}@if($r['state'] === 'done' && $r['done_on']) · {{ $r['done_on']->format('j M') }}@elseif($r['due']) · due {{ $r['due']->format('D j M Y') }}@endif
                        @if($r['state'] === 'overdue' && $r['late']) · {{ $r['late'] }} day{{ $r['late'] === 1 ? '' : 's' }} late @endif
                    </div>
                    @if($r['html'])<div class="text-sm" style="color:#6b7280;">{!! $r['html'] !!}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
    @endif

    <p class="text-center text-xs" style="color:#9ca3af;">Updated {{ $today->format('j F Y') }} · CoreX OS</p>
</div>
@endsection
