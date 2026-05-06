@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="My Screening Records" :back-route="route('agent.portal')" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="text-sm mb-4" style="color:#64748b;">
            These are your employee screening records as required by the Financial Intelligence Centre Act. Screenings are conducted by the compliance officer.
        </div>

        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <table class="w-full text-sm" style="">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Type</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Status</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Result</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Initiated</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Completed</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Next Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($screenings as $s)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 text-xs">{{ \App\Models\Compliance\EmployeeScreening::$typeLabels[$s->screening_type] ?? $s->screening_type }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="border-radius:6px; background:{{ $s->status === 'completed' ? 'color-mix(in srgb, var(--brand-icon) 15%, transparent)' : ($s->status === 'flagged' ? 'rgba(239,68,68,0.15)' : 'rgba(234,179,8,0.15)') }}; color:{{ $s->status === 'completed' ? 'var(--brand-icon)' : ($s->status === 'flagged' ? 'var(--ds-crimson)' : 'var(--ds-amber)') }};">{{ ucfirst($s->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s->overall_result ? ucfirst(str_replace('_', ' ', $s->overall_result)) : '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s->initiated_on->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s->completed_on?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s->next_due_on?->format('d M Y') ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center" style="color:#94a3b8;">No screening records.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($screenings->hasPages())
        <div class="mt-4">{{ $screenings->links() }}</div>
        @endif
    </div>
</div>
@endsection
