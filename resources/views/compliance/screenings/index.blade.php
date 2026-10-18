@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('compliance.screening.dashboard.index') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; Dashboard</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Employee Screenings</h1>
                <p class="text-xs" style="color: var(--text-muted);">FICA screening records for every staff member.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('compliance.screenings.overdue') }}" class="corex-btn-outline text-xs" data-tour="comp-screenings-overdue">Overdue</a>
                <a href="{{ route('compliance.screenings.create') }}" class="corex-btn-primary text-xs inline-flex items-center gap-1.5" data-tour="comp-screenings-new">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Screening
                </a>
            </div>
        </div>
    </div>

    <div class="props-filter-panel rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comp-screenings-filters">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name..." class="list-header-filter {{ request('search') ? 'is-active' : '' }}" style="max-width:200px;">
            <select name="status" onchange="this.form.submit()" class="list-header-filter {{ request('status') ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="flagged" {{ request('status') === 'flagged' ? 'selected' : '' }}>Flagged</option>
            </select>
            <select name="risk_tier" onchange="this.form.submit()" class="list-header-filter {{ request('risk_tier') ? 'is-active' : '' }}">
                <option value="">All risk tiers</option>
                <option value="high" {{ request('risk_tier') === 'high' ? 'selected' : '' }}>High</option>
                <option value="medium" {{ request('risk_tier') === 'medium' ? 'selected' : '' }}>Medium</option>
                <option value="low" {{ request('risk_tier') === 'low' ? 'selected' : '' }}>Low</option>
            </select>
            <button type="submit" class="corex-btn-primary text-xs px-4 py-1.5">Search</button>
            @if(request('search') || request('status') || request('risk_tier'))
            <a href="{{ route('compliance.screenings.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>
            @endif
            <span class="ml-auto text-xs" style="color: var(--text-muted);">Showing {{ $screenings->count() }} of {{ $screenings->total() }}</span>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comp-screenings-table">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Staff Member</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Risk Tier</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Completed</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Next Due</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($screenings as $s)
                    <tr>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $s->user->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $s->risk_tier === 'high' ? 'ds-badge-danger' : ($s->risk_tier === 'medium' ? 'ds-badge-warning' : 'ds-badge-info') }}">{{ ucfirst($s->risk_tier) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ \App\Models\Compliance\EmployeeScreening::$typeLabels[$s->screening_type] ?? $s->screening_type }}</td>
                        <td class="px-4 py-3">
                            @if($s->status === 'completed')
                            <span class="ds-badge ds-badge-success">Completed</span>
                            @elseif($s->status === 'flagged')
                            <span class="ds-badge ds-badge-danger">Flagged</span>
                            @else
                            <span class="ds-badge ds-badge-warning">{{ $s->completionPercent() }}%</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $s->completed_on?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: {{ $s->next_due_on && $s->next_due_on->isPast() ? 'var(--ds-crimson)' : 'var(--text-secondary)' }};">{{ $s->next_due_on?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.screenings.show', $s) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No screenings found. Start one from the Screening Dashboard.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($screenings->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $screenings->links() }}</div>
        @endif
    </div>
</div>
@endsection
