{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Compliance Reporting</h1>
                <p class="text-sm text-white/60">File and track whistleblower reports to the PPRA.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('compliance.whistleblow.create')
                <a href="{{ route('compliance.whistleblow.create') }}" class="corex-btn-primary inline-flex items-center gap-2 text-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    File New Report
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm font-medium"
         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
        {{ session('success') }}
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <select name="status" onchange="this.form.submit()" class="list-header-filter">
            <option value="">All Statuses</option>
            @foreach(['draft','pending_approval','changes_requested','rejected','approved','sent','acknowledged_by_ppra','closed'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
            @endforeach
        </select>
        <select name="tier" onchange="this.form.submit()" class="list-header-filter">
            <option value="">All Tiers</option>
            <option value="tier_1" {{ request('tier') === 'tier_1' ? 'selected' : '' }}>Tier 1</option>
            <option value="tier_2" {{ request('tier') === 'tier_2' ? 'selected' : '' }}>Tier 2</option>
            <option value="tier_3" {{ request('tier') === 'tier_3' ? 'selected' : '' }}>Tier 3</option>
        </select>
        @if(request('status') || request('tier'))
        <a href="{{ route('compliance.whistleblow.index') }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">Clear</a>
        @endif
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Reference</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Tier</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Subject Agency</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Reporter</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color: var(--text-muted);">Days</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($complaints as $c)
                    @php
                        $tierBadges = ['tier_1' => 'ds-badge-warning', 'tier_2' => 'ds-badge-info', 'tier_3' => 'ds-badge-danger'];
                        $statusBadges = [
                            'draft' => 'ds-badge-default', 'pending_approval' => 'ds-badge-warning',
                            'changes_requested' => 'ds-badge-info', 'rejected' => 'ds-badge-danger',
                            'approved' => 'ds-badge-success', 'sent' => 'ds-badge-success',
                            'acknowledged_by_ppra' => 'ds-badge-info', 'closed' => 'ds-badge-default',
                        ];
                        $days = (int) $c->updated_at->diffInDays(now());
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs font-bold" style="color: var(--text-primary);">HFC-WB-{{ $c->id }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $tierBadges[$c->tier] ?? 'ds-badge-default' }}">Tier {{ str_replace('tier_', '', $c->tier) }}</span></td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ Str::limit($c->subjects_summary, 30) }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ Str::limit($c->property_address, 30) }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $c->reporter?->name ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $statusBadges[$c->status] ?? 'ds-badge-default' }}">{{ str_replace('_', ' ', $c->status) }}</span></td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ number_format($days) }}d</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.whistleblow.show', $c) }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">
                                {{ $c->status === 'pending_approval' ? 'Review' : 'View' }}
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No reports filed yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($complaints->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $complaints->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
