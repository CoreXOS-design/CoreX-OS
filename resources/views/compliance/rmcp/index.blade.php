{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="comp-rmcp-intro">
                <h1 class="text-xl font-bold text-white leading-tight">RMCP Versions</h1>
                <p class="text-sm text-white/60">Risk Management & Compliance Programme — version history and approvals.</p>
            </div>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('compliance.fica.index') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);">Back</a>
                @permission('edit_rmcp')
                <a href="{{ route('compliance.rmcp.variables') }}" data-tour="comp-rmcp-variables" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);">Variables</a>
                <a href="{{ route('compliance.rmcp.create') }}" data-tour="comp-rmcp-new" class="corex-btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Version
                </a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- Filter / search bar --}}
    <div class="rounded-md p-4" data-tour="comp-rmcp-search" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" class="flex flex-col sm:flex-row sm:items-center gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search versions..."
                   class="w-full sm:max-w-xs rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <button type="submit" class="corex-btn-outline">Search</button>
            @if(request('search'))
                <a href="{{ route('compliance.rmcp.index') }}" class="text-sm font-medium" style="color: var(--brand-icon);">Clear</a>
            @endif
            <div class="sm:ml-auto text-xs" style="color: var(--text-muted);">
                Showing {{ number_format($versions->count()) }} of {{ number_format($versions->total()) }} versions
            </div>
        </form>
    </div>

    {{-- Versions table --}}
    <div class="rounded-md overflow-hidden" data-tour="comp-rmcp-versions" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <x-sort-header field="version_number" :current-sort="$sort" :current-direction="$direction" label="Version" />
                        <x-sort-header field="status" :current-sort="$sort" :current-direction="$direction" label="Status" />
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Approved By</th>
                        <x-sort-header field="approved_at" :current-sort="$sort" :current-direction="$direction" label="Approved" />
                        <x-sort-header field="effective_from" :current-sort="$sort" :current-direction="$direction" label="Effective From" />
                        <x-sort-header field="next_review_due" :current-sort="$sort" :current-direction="$direction" label="Next Review" />
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($versions as $v)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">v{{ $v->version_number }}</td>
                            <td class="px-4 py-3">
                                @if($v->status === 'active')
                                    <span class="ds-badge ds-badge-success">Active</span>
                                @elseif($v->status === 'draft')
                                    <span class="ds-badge ds-badge-warning">Draft</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Superseded</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->approver?->name ?? '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->approved_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->effective_from?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $v->next_review_due?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('compliance.rmcp.show', $v) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                                    @if($v->canBeEdited())
                                        @permission('edit_rmcp')
                                        <a href="{{ route('compliance.rmcp.edit', $v) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                        @endpermission
                                        @permission('approve_rmcp')
                                        <a href="{{ route('compliance.rmcp.approve.form', $v) }}" class="text-xs font-semibold" style="color: var(--ds-amber);">Approve</a>
                                        @endpermission
                                    @endif
                                    <a href="{{ route('compliance.rmcp.pdf', $v) }}" class="text-xs font-semibold" style="color: var(--text-muted);" target="_blank">PDF</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-12 h-12 rounded-full flex items-center justify-center"
                                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No RMCP versions yet</h3>
                                        <p class="text-sm" style="color: var(--text-muted);">Create your first version to get started.</p>
                                    </div>
                                    @permission('edit_rmcp')
                                    <a href="{{ route('compliance.rmcp.create') }}" class="corex-btn-primary mt-2">New Version</a>
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($versions->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $versions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
