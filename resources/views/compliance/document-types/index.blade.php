{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Types</h1>
                <p class="text-sm text-white/60">Configure which compliance documents your agency maintains. Each type can have its own expiry and renewal rules.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('compliance.document-types.create') }}" class="corex-btn-primary inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Add Type
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- Filter tabs --}}
    <div class="flex gap-1 flex-wrap" style="border-bottom: 1px solid var(--border);">
        @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label)
            <a href="{{ route('compliance.document-types.index', ['filter' => $key]) }}"
               class="px-3 py-1.5 text-xs font-semibold transition"
               @if($filter === $key)
                   style="border-bottom: 2px solid var(--brand-icon); color: var(--brand-icon);"
               @else
                   style="color: var(--text-secondary); border-bottom: 2px solid transparent;"
               @endif>
                {{ $label }} <span class="ml-1 text-[0.6875rem] opacity-60">{{ number_format($counts[$key]) }}</span>
            </a>
        @endforeach
    </div>

    @if($types->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                No document types {{ $filter === 'active' ? 'configured yet' : 'found' }}
            </h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Define your first document type to start tracking agency compliance.</p>
            <a href="{{ route('compliance.document-types.create') }}" class="corex-btn-primary">Add Type</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Slug</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Renewal Reminder</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Required</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($types as $type)
                        <tr class="transition-colors {{ !$type->is_active ? 'opacity-60' : '' }}" style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $type->name }}</td>
                            <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $type->slug }}</td>
                            <td class="px-4 py-3 text-center text-xs">
                                @if($type->has_expiry)
                                    <span style="color: var(--ds-green);">Tracked</span>
                                @else
                                    <span style="color: var(--text-muted);">None</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-xs" style="color: var(--text-secondary);">
                                @if($type->renewal_days)
                                    {{ number_format($type->renewal_days) }} days before
                                @else
                                    <span style="color: var(--text-muted);">No auto-reminder</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($type->required)
                                    <span class="ds-badge ds-badge-success">Required</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Optional</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($type->is_active)
                                    <span class="ds-badge ds-badge-success">Active</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Archived</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('compliance.document-types.edit', $type) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                    @if($type->is_active)
                                        <form method="POST" action="{{ route('compliance.document-types.archive', $type) }}" class="inline" onsubmit="return confirm('Archive this document type?')">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--text-secondary); background: none; border: none; cursor: pointer; padding: 0;">Archive</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('compliance.document-types.restore', $type) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon); background: none; border: none; cursor: pointer; padding: 0;">Restore</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
