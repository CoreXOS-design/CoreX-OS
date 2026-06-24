{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" data-tour="cc-my-performance-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My Performance</h1>
                <p class="text-sm text-white/60">{{ $user->name }} · Last {{ number_format($days) }} days</p>
            </div>
            <div class="flex items-center gap-2" data-tour="cc-my-performance-range">
                @include('layouts.partials.tour-header-launcher')
                @foreach([7 => '7d', 30 => '30d', 90 => '90d', 365 => 'Year'] as $d => $label)
                    <a href="{{ route('command-center.reporting.agent', ['days' => $d]) }}"
                       class="text-xs px-2.5 py-1 rounded-md no-underline {{ $days == $d ? 'text-white font-semibold' : 'text-white/60' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button, #0ea5e9);' : 'background: rgba(255,255,255,0.08);' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Activity Metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-tour="cc-my-performance-activity">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">{{ number_format($metrics['events_completed']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Events Completed</div>
            @if($metrics['events_prior'] > 0)
                @php $change = (int) round(($metrics['events_completed'] - $metrics['events_prior']) / $metrics['events_prior'] * 100); @endphp
                <div class="text-[0.6875rem] mt-1 font-semibold" style="color: {{ $change >= 0 ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};">{{ $change >= 0 ? '+' : '' }}{{ $change }}%</div>
            @endif
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">{{ number_format($metrics['viewings']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Viewings</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">{{ number_format($metrics['presentations']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Presentations</div>
        </div>
        @php $feedbackOnTrack = $metrics['feedback_rate'] >= 70; @endphp
        <div class="rounded-md p-4 text-center" data-tour="cc-my-performance-feedback" style="background: var(--surface); border: 1px solid {{ $feedbackOnTrack ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: {{ $feedbackOnTrack ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};">{{ number_format($metrics['feedback_rate'], 0) }}%</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Feedback Rate</div>
        </div>
    </div>

    {{-- Pipeline Metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-tour="cc-my-performance-pipeline">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">{{ number_format($metrics['active_buyers']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: {{ $metrics['high_risk_buyers'] > 3 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-primary)' }};">{{ number_format($metrics['high_risk_buyers']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">High-Risk Buyers</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-amber, #f59e0b);">{{ number_format($metrics['lost_deals']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Lost Deals</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-amber, #f59e0b);">R&nbsp;{{ number_format($metrics['lost_value']) }}</div>
            <div class="text-[0.6875rem] uppercase font-semibold tracking-wider mt-1" style="color: var(--text-muted);">Lost Value</div>
        </div>
    </div>

    {{-- Funnel --}}
    @include("command-center.reporting._funnel")

    {{-- Insights --}}
    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Insights</h2>
        <div class="space-y-2">
            @foreach($insights as $insight)
                <div class="flex items-start gap-2 text-sm" style="color: var(--text-secondary);">
                    <span class="w-1.5 h-1.5 rounded-full mt-2 flex-shrink-0" style="background: var(--brand-icon, #0ea5e9);"></span>
                    <span>{{ $insight }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
