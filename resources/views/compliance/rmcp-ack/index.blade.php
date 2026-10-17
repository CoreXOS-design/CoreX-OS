@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('agent.portal') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; My Portal</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">My RMCP Acknowledgements</h1>
                <p class="text-xs" style="color: var(--text-muted);">Your acknowledgements of each published RMCP version.</p>
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
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Version</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Started</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Completed</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Valid Until</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($acks as $a)
                    <tr>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">v{{ $a->version->version_number }}</td>
                        <td class="px-4 py-3">
                            @if($a->status === 'completed' && $a->isValid())
                            <span class="ds-badge ds-badge-success">Valid</span>
                            @elseif($a->status === 'completed')
                            <span class="ds-badge ds-badge-danger">Expired</span>
                            @elseif($a->status === 'in_progress')
                            <span class="ds-badge ds-badge-warning">In Progress</span>
                            @else
                            <span class="ds-badge ds-badge-default">{{ ucfirst($a->status) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $a->started_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $a->completed_at?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $a->valid_until?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($a->isComplete())
                            <a href="{{ route('rmcp.ack.receipt', $a) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Receipt</a>
                            @elseif($a->status === 'in_progress')
                            <a href="{{ route('rmcp.ack.step', 1) }}" class="text-xs font-semibold" style="color: var(--ds-amber);">Continue</a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No acknowledgements yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($acks->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $acks->links() }}</div>
        @endif
    </div>
</div>
@endsection
