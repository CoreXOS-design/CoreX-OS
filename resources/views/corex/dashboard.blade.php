@extends('layouts.corex')

@section('corex-content')
@php
    $pct = $monthlyTarget > 0 ? min(100, round(($mtdPoints / $monthlyTarget) * 100)) : 0;
    $monthLabel = \Carbon\Carbon::createFromFormat('Y-m', $period)->format('F Y');
@endphp

<div class="space-y-6">

    {{-- Welcome header --}}
    <div>
        <h1 class="text-2xl font-bold" style="color:var(--brand-default,#0b2a4a);">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="text-sm text-gray-500 mt-1">{{ $monthLabel }}</p>
    </div>

    {{-- Points card --}}
    <div class="corex-panel max-w-md">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">My Daily Activity Points</h3>
            <span class="text-xs text-gray-400">MTD</span>
        </div>
        <div class="corex-panel-body space-y-4">

            {{-- Big number --}}
            <div class="flex items-end gap-3">
                <span class="text-5xl font-bold" style="color:var(--brand-default,#0b2a4a);">
                    {{ number_format($mtdPoints) }}
                </span>
                @if($monthlyTarget > 0)
                    <span class="text-lg text-gray-400 mb-1">/ {{ number_format($monthlyTarget) }} target</span>
                @endif
            </div>

            {{-- Progress bar (only if target set) --}}
            @if($monthlyTarget > 0)
                <div>
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Progress</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full transition-all"
                             style="width:{{ $pct }}%; background:var(--brand-icon,#0ea5e9);"></div>
                    </div>
                </div>
            @else
                <p class="text-xs text-gray-400">No points target set for this month.</p>
            @endif

            {{-- Quick link --}}
            <div class="pt-2">
                <a href="{{ route('agent.daily') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-opacity hover:opacity-90"
                   style="background:var(--brand-default,#0b2a4a);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Capture Today's Activity
                </a>
            </div>
        </div>
    </div>

</div>
@endsection
