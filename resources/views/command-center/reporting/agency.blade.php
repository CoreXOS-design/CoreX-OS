{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency Performance</h1>
                <p class="text-sm text-white/60">Leadership view · Last {{ $days }} days</p>
            </div>
            <div class="flex items-center gap-2">
                @foreach([30 => '30 days', 90 => '90 days', 365 => 'Year'] as $d => $label)
                    <a href="{{ route('command-center.reporting.agency', ['days' => $d]) }}"
                       class="text-xs font-semibold px-2.5 py-1 rounded-md no-underline whitespace-nowrap {{ $days == $d ? 'text-white' : 'text-white/60 hover:text-white' }}"
                       style="{{ $days == $d ? 'background: var(--brand-button, #0ea5e9);' : 'background: rgba(255,255,255,0.08);' }} transition: all 150ms ease;">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Agency At a Glance --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3">
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['total_agents']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agents</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['total_buyers']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active Buyers</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['total_listings']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Listings</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ number_format($metrics['events_completed']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Events</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ isset($metrics['avg_dom']) ? number_format($metrics['avg_dom']) : '—' }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Avg DOM</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xl font-bold" style="color: var(--ds-crimson, #c41e3a);">{{ number_format($metrics['lost_count']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lost</div>
        </div>
        <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-lg font-bold" style="color: var(--ds-crimson, #c41e3a);">R {{ number_format($metrics['lost_value']) }}</div>
            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lost Value</div>
        </div>
    </div>

    {{-- Branch Comparison --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Branch Comparison</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agents</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Buyers</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Listings</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Events</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lost</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lost Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($branchComparison as $branch)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 text-xs font-medium" style="color: var(--text-primary);">
                                <a href="{{ route('command-center.reporting.branch', ['branch_id' => $branch->id, 'days' => $days]) }}" class="no-underline" style="color: var(--brand-icon);">{{ $branch->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($branch->active_agents) }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($branch->active_buyers) }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($branch->active_listings) }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--text-secondary);">{{ number_format($branch->events_completed) }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: {{ $branch->lost_count > 0 ? 'var(--ds-crimson, #c41e3a)' : 'var(--text-muted)' }};">{{ number_format($branch->lost_count) }}</td>
                            <td class="px-4 py-3 text-xs text-center" style="color: var(--ds-crimson, #c41e3a);">R {{ number_format($branch->lost_value) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No branch data for the selected period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Insights --}}
    @if(!empty($insights))
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Strategic Insights</h2>
        <div class="space-y-2">
            @foreach($insights as $insight)
                <div class="flex items-start gap-2 text-xs" style="color: var(--text-secondary);">
                    <span class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background: var(--ds-green, #059669);"></span>
                    <span>{{ $insight }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
