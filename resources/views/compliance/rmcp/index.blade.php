@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="RMCP Versions" :back-route="route('compliance.fica.index')" back-label="Compliance" :flush="true">
        <x-slot:actions>
            @permission('edit_rmcp')
            <a href="{{ route('compliance.rmcp.variables') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-secondary, #6b7280);">
                Variables
            </a>
            <a href="{{ route('compliance.rmcp.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="background:#00d4aa; color:#0f172a; border-radius:3px;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Version
            </a>
            @endpermission
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Search --}}
        <form method="GET" class="mb-4 flex items-center gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search versions..."
                   class="w-full max-w-xs px-3 py-2 text-sm border rounded" style="border-color:var(--border, #e5e7eb); border-radius:3px; font-family:'Plus Jakarta Sans',sans-serif;">
            @if(request('search'))
            <a href="{{ route('compliance.rmcp.index') }}" class="text-sm" style="color:#6b7280;">Clear</a>
            @endif
        </form>

        <div class="text-xs mb-2" style="color:#64748b;">Showing {{ $versions->count() }} of {{ $versions->total() }} versions</div>

        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <x-sort-header field="version_number" :current-sort="$sort" :current-direction="$direction" label="Version" />
                        <x-sort-header field="status" :current-sort="$sort" :current-direction="$direction" label="Status" />
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Approved By</th>
                        <x-sort-header field="approved_at" :current-sort="$sort" :current-direction="$direction" label="Approved" />
                        <x-sort-header field="effective_from" :current-sort="$sort" :current-direction="$direction" label="Effective From" />
                        <x-sort-header field="next_review_due" :current-sort="$sort" :current-direction="$direction" label="Next Review" />
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($versions as $v)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold">v{{ $v->version_number }}</td>
                        <td class="px-4 py-3">
                            @if($v->status === 'active')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(0,212,170,0.15); color:#00d4aa; border-radius:3px;">Active</span>
                            @elseif($v->status === 'draft')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(234,179,8,0.15); color:#eab308; border-radius:3px;">Draft</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:3px;">Superseded</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $v->approver?->name ?? '-' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $v->approved_at?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $v->effective_from?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $v->next_review_due?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('compliance.rmcp.show', $v) }}" class="text-xs font-semibold px-2 py-1" style="color:#00d4aa;">View</a>
                                @if($v->canBeEdited())
                                @permission('edit_rmcp')
                                <a href="{{ route('compliance.rmcp.edit', $v) }}" class="text-xs font-semibold px-2 py-1" style="color:#3b82f6;">Edit</a>
                                @endpermission
                                @permission('approve_rmcp')
                                <a href="{{ route('compliance.rmcp.approve.form', $v) }}" class="text-xs font-semibold px-2 py-1" style="color:#f59e0b;">Approve</a>
                                @endpermission
                                @endif
                                <a href="{{ route('compliance.rmcp.pdf', $v) }}" class="text-xs font-semibold px-2 py-1" style="color:#6b7280;" target="_blank">PDF</a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center" style="color:#94a3b8;">No RMCP versions found. Create your first version to get started.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($versions->hasPages())
        <div class="mt-4">{{ $versions->links() }}</div>
        @endif
    </div>
</div>
@endsection
