{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Branch Performance</h1>
                <p class="text-sm text-white/60">Last {{ $days }} days</p>
            </div>
            <div class="flex items-center gap-2">
                @if($branches->isNotEmpty())
                    <select onchange="window.location.href=this.value" class="text-xs rounded-md px-2 py-1" style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                        @foreach($branches as $b)
                            <option value="{{ route('command-center.reporting.branch', ['branch_id' => $b->id, 'days' => $days]) }}" {{ $b->id == $branchId ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                @endif
                @foreach([7 => '7d', 30 => '30d', 90 => '90d'] as $d => $label)
                    <a href="{{ route('command-center.reporting.branch', ['days' => $d, 'branch_id' => $branchId]) }}"
                       class="text-xs px-2 py-1 rounded-md no-underline {{ $days == $d ? 'text-white' : 'text-white/50' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button, #0ea5e9);' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Branch At a Glance --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['active_agents']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Agents</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['active_buyers']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['active_listings']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Listings</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['events_completed']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Events</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--ds-amber, #f59e0b);">{{ number_format($metrics['lost_count']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--ds-amber, #f59e0b);">R {{ number_format($metrics['lost_value']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost Value</div>
        </div>
    </div>

    {{-- Agent Leaderboard --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Agent Leaderboard</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Events</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Feedback %</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Buyers</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($leaderboard as $agent)
                        @php
                            $feedbackColor = $agent->feedback_rate >= 70
                                ? 'var(--ds-green, #059669)'
                                : ($agent->feedback_rate >= 50 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-muted)');
                            $lostColor = $agent->lost_deals > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-muted)';
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 text-xs font-medium" style="color: var(--text-primary);">{{ $agent->name }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($agent->events_completed) }}</td>
                            <td class="px-4 py-3 text-xs text-center font-semibold" style="color: {{ $feedbackColor }};">{{ number_format($agent->feedback_rate, 1) }}%</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($agent->active_buyers) }}</td>
                            <td class="px-4 py-3 text-xs text-center font-semibold" style="color: {{ $lostColor }};">{{ number_format($agent->lost_deals) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No agent activity in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Conversion Funnel --}}
    @include("command-center.reporting._funnel")

    {{-- Insights --}}
    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Branch Insights</h2>
        <div class="space-y-2">
            @foreach($insights as $insight)
                <div class="flex items-start gap-2 text-xs" style="color: var(--text-secondary);">
                    <span class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background: var(--brand-icon, #0ea5e9);"></span>
                    <span>{{ $insight }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
