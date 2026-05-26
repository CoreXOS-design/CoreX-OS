@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Employee Screenings" :back-route="route('compliance.screening.dashboard.index')" back-label="Dashboard" :flush="true">
        <x-slot:actions>
            <a href="{{ route('compliance.screenings.overdue') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280);">Overdue</a>
            <a href="{{ route('compliance.screenings.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Screening
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <form method="GET" class="flex items-center gap-3 mb-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name..." class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px; max-width:200px;">
            <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                <option value="">All statuses</option>
                <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="flagged" {{ request('status') === 'flagged' ? 'selected' : '' }}>Flagged</option>
            </select>
            <select name="risk_tier" onchange="this.form.submit()" class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                <option value="">All risk tiers</option>
                <option value="high" {{ request('risk_tier') === 'high' ? 'selected' : '' }}>High</option>
                <option value="medium" {{ request('risk_tier') === 'medium' ? 'selected' : '' }}>Medium</option>
                <option value="low" {{ request('risk_tier') === 'low' ? 'selected' : '' }}>Low</option>
            </select>
            @if(request('search') || request('status') || request('risk_tier'))
            <a href="{{ route('compliance.screenings.index') }}" class="text-xs" style="color:#6b7280;">Clear</a>
            @endif
        </form>

        <div class="text-xs mb-2" style="color:#64748b;">Showing {{ $screenings->count() }} of {{ $screenings->total() }}</div>

        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <table class="w-full text-sm" style="">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Staff Member</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Risk Tier</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Type</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Status</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Completed</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Next Due</th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($screenings as $s)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary, #1f2937);">{{ $s->user->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="border-radius:6px; background:{{ $s->risk_tier === 'high' ? 'rgba(239,68,68,0.15)' : ($s->risk_tier === 'medium' ? 'rgba(234,179,8,0.15)' : 'color-mix(in srgb, var(--brand-icon) 15%, transparent)') }}; color:{{ $s->risk_tier === 'high' ? 'var(--ds-crimson)' : ($s->risk_tier === 'medium' ? 'var(--ds-amber)' : 'var(--brand-icon)') }};">{{ ucfirst($s->risk_tier) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ \App\Models\Compliance\EmployeeScreening::$typeLabels[$s->screening_type] ?? $s->screening_type }}</td>
                        <td class="px-4 py-3">
                            @if($s->status === 'completed')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 15%, transparent); color:var(--brand-icon); border-radius:6px;">Completed</span>
                            @elseif($s->status === 'flagged')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(239,68,68,0.15); color:var(--ds-crimson); border-radius:6px;">Flagged</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(234,179,8,0.15); color:var(--ds-amber); border-radius:6px;">{{ $s->completionPercent() }}%</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s->completed_on?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:{{ $s->next_due_on && $s->next_due_on->isPast() ? 'var(--ds-crimson)' : '#64748b' }};">{{ $s->next_due_on?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.screenings.show', $s) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center" style="color:#94a3b8;">No screenings found. Start one from the Screening Dashboard.</td>
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
