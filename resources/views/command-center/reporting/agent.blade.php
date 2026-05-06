@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white">My Performance</h1>
                <p class="text-sm text-white/60">{{ $user->name }} · Last {{ $days }} days</p>
            </div>
            <div class="flex items-center gap-2">
                @foreach([7 => '7d', 30 => '30d', 90 => '90d', 365 => 'Year'] as $d => $label)
                    <a href="{{ route('command-center.reporting.agent', ['days' => $d]) }}"
                       class="text-xs px-2 py-1 rounded no-underline {{ $days == $d ? 'text-white' : 'text-white/50' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button);' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Activity Metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $metrics['events_completed'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Events Completed</div>
            @if($metrics['events_prior'] > 0)
                @php $change = round(($metrics['events_completed'] - $metrics['events_prior']) / $metrics['events_prior'] * 100); @endphp
                <div class="text-[10px] mt-1" style="color: {{ $change >= 0 ? '#10b981' : '#ef4444' }};">{{ $change >= 0 ? '+' : '' }}{{ $change }}%</div>
            @endif
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $metrics['viewings'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Viewings</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $metrics['presentations'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Presentations</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid {{ $metrics['feedback_rate'] >= 70 ? '#10b981' : '#f59e0b' }};">
            <div class="text-2xl font-bold" style="color: {{ $metrics['feedback_rate'] >= 70 ? '#10b981' : '#f59e0b' }};">{{ $metrics['feedback_rate'] }}%</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Feedback Rate</div>
        </div>
    </div>

    {{-- Pipeline Metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $metrics['active_buyers'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: {{ $metrics['high_risk_buyers'] > 3 ? '#ef4444' : 'var(--text-primary)' }};">{{ $metrics['high_risk_buyers'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">High-Risk Buyers</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: #ef4444;">{{ $metrics['lost_deals'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost Deals</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-lg font-bold" style="color: #ef4444;">R {{ number_format($metrics['lost_value']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost Value</div>
        </div>
    </div>

    {{-- Insights --}}
    @include("command-center.reporting._funnel")

    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Insights</h2>
        <div class="space-y-2">
            @foreach($insights as $insight)
                <div class="flex items-start gap-2 text-xs" style="color: var(--text-secondary);">
                    <span class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background: #00d4aa;"></span>
                    <span>{{ $insight }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
