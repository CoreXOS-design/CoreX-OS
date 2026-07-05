{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Listings per Agent</h1>
                <p class="text-sm text-white/60">Read-only overview from imported listing stock.</p>
            </div>
        </div>
    </div>

    {{-- KPI Stats --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card
            title="Active listings"
            :value="number_format((int)($totals->listing_count ?? 0))" />
        <x-corex-kpi-card
            title="Total asking value"
            :value="'R ' . number_format(((int)($totals->total_value_cents ?? 0))/100, 0)" />
        <x-corex-kpi-card
            title="Agents with stock"
            :value="number_format(count($rows ?? []))" />
    </div>

    {{-- Filters --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select name="status"
                        class="w-full rounded-md text-sm px-3 py-2"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach(['active'=>'Active (contains active/for sale)','all'=>'All'] as $k=>$label)
                        <option value="{{ $k }}" @selected(($status ?? 'active') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Source</label>
                <input type="text" name="source" value="{{ $source ?? 'propcon' }}"
                       class="w-full rounded-md text-sm px-3 py-2 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route('admin.listings.agents') }}" class="corex-btn-outline">Reset</a>
            </div>
        </form>
    </div>

    {{-- Agent Breakdown Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Agent breakdown</div>
            <div class="text-xs" style="color: var(--text-muted);">Click an agent to drill in</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Asking value</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Mandates</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Top types</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $r['name'] }}</div>
                                <div class="text-xs" style="color: var(--text-muted);">{{ $r['email'] }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">{{ number_format($r['listing_count']) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">R {{ number_format($r['total_value_cents']/100, 0) }}</td>
                            <td class="px-4 py-3">
                                @php $m = $r['mandates'] ?? []; @endphp
                                @if(count($m))
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($m as $k => $c)
                                            <span class="agent-chip inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs whitespace-nowrap"
                                                  style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary);">
                                                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((int) $c) }}</span>
                                                <span>{{ $k === '(none)' ? 'No mandate' : $k }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php $t = $r['top_types'] ?? []; @endphp
                                @if(count($t))
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($t as $x)
                                            <span class="agent-chip inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs whitespace-nowrap"
                                                  style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary);">
                                                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((int) $x['c']) }}</span>
                                                <span>{{ $x['type'] === '(none)' ? 'No type' : $x['type'] }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="text-xs font-semibold"
                                   style="color: var(--brand-icon);"
                                   href="{{ route('admin.listings.agents.show', ['user' => $r['user_id'], 'status' => $status ?? 'active', 'source' => $source ?? 'propcon']) }}">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center" style="color: var(--text-muted);">
                                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                    </svg>
                                </div>
                                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No listing stock found</h3>
                                <p class="text-sm" style="color: var(--text-muted);">Try clearing filters or importing fresh stock.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

@push('head')
<style>
    .agent-chip { transition: background-color 150ms ease, border-color 150ms ease; }
    .agent-chip:hover { background: var(--surface) !important; border-color: var(--border-hover) !important; }
    .ds-table tbody tr { transition: background-color 150ms ease; }
    .ds-table tbody tr:hover { background: var(--surface-2); }
</style>
@endpush
@endsection
