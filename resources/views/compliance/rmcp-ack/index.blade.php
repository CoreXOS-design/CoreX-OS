@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="My RMCP Acknowledgements" :back-route="route('agent.portal')" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Version</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Status</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Started</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Completed</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Valid Until</th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($acks as $a)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold">v{{ $a->version->version_number }}</td>
                        <td class="px-4 py-3">
                            @if($a->status === 'completed' && $a->isValid())
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(0,212,170,0.15); color:#00d4aa; border-radius:3px;">Valid</span>
                            @elseif($a->status === 'completed')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(239,68,68,0.15); color:#ef4444; border-radius:3px;">Expired</span>
                            @elseif($a->status === 'in_progress')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(234,179,8,0.15); color:#eab308; border-radius:3px;">In Progress</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:3px;">{{ ucfirst($a->status) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color:#64748b;">{{ $a->started_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3" style="color:#64748b;">{{ $a->completed_at?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3" style="color:#64748b;">{{ $a->valid_until?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($a->isComplete())
                            <a href="{{ route('rmcp.ack.receipt', $a) }}" class="text-xs font-semibold" style="color:#00d4aa;">Receipt</a>
                            @elseif($a->status === 'in_progress')
                            <a href="{{ route('rmcp.ack.step', 1) }}" class="text-xs font-semibold" style="color:#eab308;">Continue</a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center" style="color:#94a3b8;">No acknowledgements yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($acks->hasPages())
        <div class="mt-4">{{ $acks->links() }}</div>
        @endif
    </div>
</div>
@endsection
