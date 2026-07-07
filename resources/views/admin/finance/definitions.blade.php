{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Finance Definitions</h1>
                <p class="text-sm text-white/60">All formula definitions registered in the Finance Engine.</p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.finance.audit.index') }}" class="corex-btn-outline text-sm">Audit History</a>
                <form method="POST" action="{{ route('admin.finance.recalculate') }}" class="flex items-center gap-2"
                      id="recalcForm">
                    @csrf
                    <input type="hidden" name="mode" id="recalcMode" value="single">
                    <select name="period"
                            class="rounded-md border-0 text-white text-sm px-3 py-1.5 transition-colors duration-150 [&>option]:text-slate-900"
                            style="background: rgba(255,255,255,0.1);">
                        @foreach($availablePeriods as $p)
                            <option value="{{ $p }}" {{ $p === now()->format('Y-m') ? 'selected' : '' }}>
                                {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit"
                            onclick="document.getElementById('recalcMode').value='single'"
                            class="corex-btn-primary text-sm whitespace-nowrap">
                        Recalculate Period
                    </button>
                    <button type="submit"
                            onclick="if(!confirm('This will recalculate ALL periods with deals. This may take a while. Continue?')){event.preventDefault();return;}document.getElementById('recalcMode').value='all'"
                            class="corex-btn-outline text-sm whitespace-nowrap"
                            style="border-color: color-mix(in srgb, var(--ds-amber) 50%, transparent); color: var(--ds-amber);">
                        Recalculate ALL
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- KPI tiles --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total definitions" :value="number_format($definitions->count())" />
        <x-corex-kpi-card title="Active definitions" :value="number_format($definitions->where('status', 'active')->count())" />
        <x-corex-kpi-card title="Computed values stored" :value="number_format($computedCount)" />
    </div>

    {{-- Definitions Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Definitions ({{ number_format($definitions->count()) }})</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2, var(--surface));">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Key</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Entity Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Value Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Version</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($definitions as $def)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-primary);">{{ $def->key }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $def->entity_type ? ucfirst(str_replace('_', ' ', $def->entity_type)) : '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $def->value_type ? ucfirst(str_replace('_', ' ', $def->value_type)) : '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">v{{ $def->version }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $def->status === 'active' ? 'ds-badge-success' : 'ds-badge-default' }}" style="white-space: nowrap;">
                                    {{ $def->status ?: '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs max-w-xs truncate" style="color: var(--text-muted);">{{ $def->notes ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No definitions registered yet. Run a recalculation to auto-create them.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
