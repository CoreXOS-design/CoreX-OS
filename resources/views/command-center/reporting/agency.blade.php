@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white">Agency Performance</h1>
                <p class="text-sm text-white/60">Leadership view · Last {{ $days }} days</p>
            </div>
            <div class="flex items-center gap-2">
                @foreach([30 => '30d', 90 => '90d', 365 => 'Year'] as $d => $label)
                    <a href="{{ route('command-center.reporting.agency', ['days' => $d]) }}"
                       class="text-xs px-2 py-1 rounded no-underline {{ $days == $d ? 'text-white' : 'text-white/50' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button);' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Agency At a Glance --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3">
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['total_agents'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Agents</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['total_buyers'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['total_listings'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Listings</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['events_completed'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Events</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $metrics['avg_dom'] ?? '—' }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Avg DOM</div>
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

    {{-- Branch Comparison --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Branch Comparison</h2>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Branch</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Agents</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Buyers</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Listings</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Events</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Lost</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Lost Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($branchComparison as $branch)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-2 text-xs font-medium" style="color: var(--text-primary);">
                            <a href="{{ route('command-center.reporting.branch', ['branch_id' => $branch->id, 'days' => $days]) }}" class="no-underline" style="color: var(--brand-icon);">{{ $branch->name }}</a>
                        </td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $branch->active_agents }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $branch->active_buyers }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $branch->active_listings }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: var(--text-secondary);">{{ $branch->events_completed }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: {{ $branch->lost_count > 0 ? '#ef4444' : 'var(--text-muted)' }};">{{ $branch->lost_count }}</td>
                        <td class="px-4 py-2 text-xs text-center" style="color: #ef4444;">R {{ number_format($branch->lost_value) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Insights --}}
    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Strategic Insights</h2>
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
