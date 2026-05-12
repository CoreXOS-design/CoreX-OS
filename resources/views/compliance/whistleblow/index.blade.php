@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-4">
    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-lg font-bold" style="color:var(--text-primary);">Compliance Reporting</h1>
        @permission('compliance.whistleblow.create')
        <a href="{{ route('compliance.whistleblow.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-semibold text-white no-underline" style="background:var(--brand-default);">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            File New Report
        </a>
        @endpermission
    </div>

    @if(session('success'))
    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green) 10%, transparent); color:var(--ds-green);">
        {{ session('success') }}
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <select name="status" onchange="this.form.submit()" class="rounded-md text-sm px-3 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All Statuses</option>
            @foreach(['draft','pending_approval','changes_requested','rejected','approved','sent','acknowledged_by_ppra','closed'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
            @endforeach
        </select>
        <select name="tier" onchange="this.form.submit()" class="rounded-md text-sm px-3 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All Tiers</option>
            <option value="tier_1" {{ request('tier') === 'tier_1' ? 'selected' : '' }}>Tier 1</option>
            <option value="tier_2" {{ request('tier') === 'tier_2' ? 'selected' : '' }}>Tier 2</option>
            <option value="tier_3" {{ request('tier') === 'tier_3' ? 'selected' : '' }}>Tier 3</option>
        </select>
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-raised); border-bottom:1px solid var(--border);">
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Reference</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Tier</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Subject Agency</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Reporter</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Days</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($complaints as $c)
                @php
                    $tierBadges = ['tier_1' => 'ds-badge-warning', 'tier_2' => 'ds-badge-info', 'tier_3' => 'ds-badge-danger'];
                    $statusBadges = [
                        'draft' => 'ds-badge-muted', 'pending_approval' => 'ds-badge-warning',
                        'changes_requested' => 'ds-badge-info', 'rejected' => 'ds-badge-danger',
                        'approved' => 'ds-badge-success', 'sent' => 'ds-badge-success',
                        'acknowledged_by_ppra' => 'ds-badge-brand', 'closed' => 'ds-badge-muted',
                    ];
                    $days = $c->updated_at->diffInDays(now());
                @endphp
                <tr class="border-t" style="border-color:var(--border);">
                    <td class="px-4 py-2.5 font-mono text-xs font-bold" style="color:var(--text-primary);">HFC-WB-{{ $c->id }}</td>
                    <td class="px-4 py-2.5"><span class="ds-badge {{ $tierBadges[$c->tier] ?? '' }}">{{ str_replace('tier_', 'T', $c->tier) }}</span></td>
                    <td class="px-4 py-2.5" style="color:var(--text-primary);">{{ Str::limit($c->subjects_summary, 30) }}</td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">{{ Str::limit($c->property_address, 30) }}</td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">{{ $c->reporter?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5"><span class="ds-badge {{ $statusBadges[$c->status] ?? '' }}">{{ str_replace('_', ' ', $c->status) }}</span></td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-muted);">{{ $days }}d</td>
                    <td class="px-4 py-2.5 text-right">
                        <a href="{{ route('compliance.whistleblow.show', $c) }}" class="text-xs font-semibold no-underline" style="color:var(--brand-default);">
                            {{ $c->status === 'pending_approval' ? 'Review' : 'View' }}
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No complaints found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $complaints->withQueryString()->links() }}
</div>
@endsection
