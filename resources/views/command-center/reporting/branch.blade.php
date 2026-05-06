@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white">Branch Performance</h1>
                <p class="text-sm text-white/60">Last {{ $days }} days</p>
            </div>
            <div class="flex items-center gap-2">
                @if($branches->isNotEmpty())
                    <select onchange="window.location.href=this.value" class="text-xs rounded px-2 py-1" style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                        @foreach($branches as $b)
                            <option value="{{ route('command-center.reporting.branch', ['branch_id' => $b->id, 'days' => $days]) }}" {{ $b->id == $branchId ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                @endif
                @foreach([7 => '7d', 30 => '30d', 90 => '90d'] as $d => $label)
                    <a href="{{ route('command-center.reporting.branch', ['days' => $d, 'branch_id' => $branchId]) }}"
                       class="text-xs px-2 py-1 rounded no-underline {{ $days == $d ? 'text-white' : 'text-white/50' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button);' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Branch At a Glance --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['active_agents'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Agents</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['active_buyers'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['active_listings'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Listings</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['events_completed'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Events</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid #ef4444;">
            <div class="text-xl font-bold" style="color: #ef4444;">{{ $metrics['lost_count'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-lg font-bold" style="color: #ef4444;">R {{ number_format($metrics['lost_value']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost Value</div>
        </div>
    </div>

    {{-- Agent Leaderboard --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Agent Leaderboard</h2>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Agent</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Events</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Feedback %</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Buyers</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Lost</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leaderboard as $agent)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-2 text-xs font-medium" style="color: var(--text-primary);">{{ $agent->name }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $agent->events_completed }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: {{ $agent->feedback_rate >= 70 ? '#10b981' : ($agent->feedback_rate >= 50 ? '#f59e0b' : '#ef4444') }};">{{ $agent->feedback_rate }}%</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $agent->active_buyers }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: {{ $agent->lost_deals > 0 ? '#ef4444' : 'var(--text-muted)' }};">{{ $agent->lost_deals }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Insights --}}
    @include("command-center.reporting._funnel")

    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Branch Insights</h2>
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
