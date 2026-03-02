@extends('layouts.corex')

@section('corex-content')

<div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">{{ $pageTitle }}</h2>
            <div class="text-sm text-white/60">Compiled presentation packs</div>
        </div>
        <a href="{{ route('presentations.index') }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; All Presentations
        </a>
    </div>
</div>

{{-- FILTERS --}}
<form method="GET" class="mb-4 flex flex-wrap gap-2 items-end">
    @if($isAdmin)
        <div>
            <label class="ds-label block mb-1">Branch</label>
            <select name="branch_id" class="border border-gray-300 rounded px-2 py-1.5 text-xs">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}"
                        {{ ($filters['branch_id'] ?? '') == $branch->id ? 'selected' : '' }}>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ds-label block mb-1">User ID</label>
            <input type="number" name="user_id" value="{{ $filters['user_id'] ?? '' }}"
                   placeholder="Any"
                   class="border border-gray-300 rounded px-2 py-1.5 text-xs w-24">
        </div>
    @endif

    <div>
        <label class="ds-label block mb-1">Presentation ID</label>
        <input type="number" name="presentation_id" value="{{ $filters['presentation_id'] ?? '' }}"
               placeholder="Any"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <div>
        <label class="ds-label block mb-1">Period (YYYY-MM)</label>
        <input type="text" name="period" value="{{ $filters['period'] ?? '' }}"
               placeholder="e.g. 2026-02"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <button type="submit" class="corex-btn-primary" style="padding:0.375rem 0.75rem; font-size:0.75rem;">
        Filter
    </button>

    @if(array_filter($filters))
        <a href="{{ request()->url() }}"
           class="px-3 py-1.5 border border-gray-300 text-gray-500 text-xs rounded hover:bg-gray-50">
            Clear
        </a>
    @endif
</form>

{{-- TABLE --}}
<div class="ds-status-card overflow-hidden" style="border-left-color: var(--ds-cyan); padding:0;">
    <table class="ds-table w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compiled</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Presentation</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compiled by</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Blueprint</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Links</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($versions as $version)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                        {{ $version->compiled_at?->format('Y-m-d H:i') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-800">
                        {{ $version->presentation?->title ?? '#' . $version->presentation_id }}
                        <span class="text-gray-400 ml-1">({{ $version->presentation?->suburb ?? '' }})</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        {{ $version->compiledBy?->name ?? 'User #' . $version->compiled_by }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $version->blueprint_version ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-xs">
                        @if($version->presentation)
                            <a href="{{ route('presentations.show', $version->presentation_id) }}"
                               class="text-[#00b4d8] hover:underline mr-3">
                                Presentation →
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400 italic">
                        No compiled versions found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($versions->hasPages())
    <div class="mt-4">
        {{ $versions->links() }}
    </div>
@endif

@endsection
