@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('compliance.screenings.index') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; Screenings</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Overdue Screenings</h1>
                <p class="text-xs" style="color: var(--text-muted);">Staff whose employee screening is overdue or has never been done.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Staff Member</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Role</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Risk Tier</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Due On</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overdueUsers as $u)
                    <tr>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $u->role }}</td>
                        <td class="px-4 py-3">
                            @php $tier = $u->risk_tier ?? 'medium'; @endphp
                            <span class="ds-badge {{ $tier === 'high' ? 'ds-badge-danger' : ($tier === 'medium' ? 'ds-badge-warning' : 'ds-badge-info') }}">{{ ucfirst($tier) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="ds-badge ds-badge-danger">{{ str_replace('_', ' ', ucfirst($u->screening_status ?? 'never_screened')) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--ds-crimson);">{{ $u->screening_due_on ? \Carbon\Carbon::parse($u->screening_due_on)->format('d M Y') : 'Never' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.screenings.create', $u) }}" class="text-xs font-semibold px-2 py-1" style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent); color: var(--brand-icon); border-radius: 6px; text-decoration: none;">Start Screening</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No overdue screenings. All staff are up to date.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($overdueUsers->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $overdueUsers->links() }}</div>
        @endif
    </div>
</div>
@endsection
