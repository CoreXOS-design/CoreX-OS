@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="re-commercial-evals-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Commercial Market Evaluations</h1>
                <p class="text-sm text-white/60">Evaluate commercial, industrial, hospitality &amp; agricultural properties</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('commercial-evaluations.create') }}" class="corex-btn-primary" data-tour="re-commercial-evals-new">
                    + New Evaluation
                </a>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);" data-tour="re-commercial-evals-filter">
        <div class="text-sm" style="color: var(--text-secondary);">
            Showing <span style="color: var(--text-primary); font-weight: 600;">{{ number_format($evaluations->count()) }}</span>
            {{ $showArchived ? 'archived' : 'active' }} {{ Str::plural('evaluation', $evaluations->count()) }}
        </div>
        <form method="GET" action="{{ route('commercial-evaluations.index') }}" class="sm:ml-auto">
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
            </select>
        </form>
    </div>

    {{-- Content --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);" data-tour="re-commercial-evals-list">
        @if($evaluations->isEmpty())
            <div class="py-12 px-6 text-center">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                    {{ $showArchived ? 'No archived evaluations' : 'No commercial evaluations yet' }}
                </h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">
                    {{ $showArchived ? 'Archived evaluations will appear here.' : 'Create your first evaluation to start tracking commercial property valuations.' }}
                </p>
                @unless($showArchived)
                    <a href="{{ route('commercial-evaluations.create') }}" class="corex-btn-primary">
                        Create Your First Evaluation
                    </a>
                @endunless
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="ds-table min-w-full text-sm">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Town</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Asking Price</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Recommended Range</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($evaluations as $eval)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3">
                                <a href="{{ route('commercial-evaluations.show', $eval) }}" class="font-medium" style="color: var(--brand-icon);">
                                    {{ $eval->property_name }}
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ \App\Models\CommercialEvaluation::propertyTypeBadgeColor($eval->property_type) }}">
                                    {{ \App\Models\CommercialEvaluation::propertyTypeLabel($eval->property_type) }}
                                </span>
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $eval->town ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($showArchived)
                                    <span class="ds-badge ds-badge-warning">Archived</span>
                                @else
                                    <span class="ds-badge {{ \App\Models\CommercialEvaluation::statusBadgeColor($eval->status) }}">
                                        {{ ucfirst($eval->status) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs" style="color: var(--text-secondary);">{{ $eval->asking_price_display }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" style="color: var(--text-secondary);">{{ $eval->recommended_range_display }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $eval->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($showArchived)
                                    <form method="POST" action="{{ route('commercial-evaluations.restore', $eval->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-primary text-xs px-2.5 py-1">Restore</button>
                                    </form>
                                @else
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('commercial-evaluations.show', $eval) }}" class="corex-btn-outline text-xs px-2.5 py-1">View</a>
                                        <a href="{{ route('commercial-evaluations.edit', $eval) }}" class="corex-btn-outline text-xs px-2.5 py-1">Edit</a>
                                        <form method="POST" action="{{ route('commercial-evaluations.destroy', $eval) }}" class="inline" onsubmit="return confirm('Delete this evaluation?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="corex-btn-outline text-xs px-2.5 py-1" style="color: var(--ds-crimson, #c41e3a); border-color: color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);">Delete</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($evaluations->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $evaluations->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
