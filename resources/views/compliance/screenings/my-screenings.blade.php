{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="My Screening Records" :back-route="route('agent.portal')" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="text-sm mb-4" style="color:var(--text-secondary, #64748b);">
            These are your employee screening records as required by the Financial Intelligence Centre Act. Screenings are conducted by the compliance officer.
        </div>

        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Result</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Initiated</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Completed</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Next Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($screenings as $s)
                    @php
                        $screeningBadge = $s->status === 'completed' ? 'ds-badge-success' : ($s->status === 'flagged' ? 'ds-badge-danger' : 'ds-badge-warning');
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ \App\Models\Compliance\EmployeeScreening::$typeLabels[$s->screening_type] ?? $s->screening_type }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $screeningBadge }}">{{ ucfirst($s->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->overall_result ? ucfirst(str_replace('_', ' ', $s->overall_result)) : '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->initiated_on->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->completed_on?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->next_due_on?->format('d M Y') ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No screening records.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @if($screenings->hasPages())
        <div class="mt-4">{{ $screenings->links() }}</div>
        @endif
    </div>
</div>
@endsection
