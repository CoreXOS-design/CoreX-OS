@extends('layouts.corex')

@section('corex-content')

<div class="rounded-md px-6 py-5 corex-page-banner mb-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $pageTitle }}</h1>
            <p class="text-xs" style="color: var(--text-muted);">Compiled presentation packs</p>
        </div>
        <a href="{{ route('presentations.index') }}" class="corex-btn-outline text-xs">
            &larr; All Presentations
        </a>
    </div>
</div>

{{-- FILTERS --}}
<form method="GET" class="props-filter-panel mb-4 flex flex-wrap gap-3 items-end rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
    @if($isAdmin)
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
            <select class="list-header-filter {{ (!empty($filters['branch_id'])) ? 'is-active' : '' }}" name="branch_id">
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
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">User ID</label>
            <input class="list-header-filter {{ (!empty($filters['user_id'])) ? 'is-active' : '' }}" type="number" name="user_id" style="width:6rem;" value="{{ $filters['user_id'] ?? '' }}"
                   placeholder="Any"
                  >
        </div>
    @endif

    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Presentation ID</label>
        <input class="list-header-filter {{ (!empty($filters['presentation_id'])) ? 'is-active' : '' }}" type="number" name="presentation_id" style="width:7rem;" value="{{ $filters['presentation_id'] ?? '' }}"
               placeholder="Any"
              >
    </div>

    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Period (YYYY-MM)</label>
        <input class="list-header-filter {{ (!empty($filters['period'])) ? 'is-active' : '' }}" type="text" name="period" style="width:7rem;" value="{{ $filters['period'] ?? '' }}"
               placeholder="e.g. 2026-02"
              >
    </div>

    <button type="submit" class="corex-btn-primary" style="padding:0.375rem 0.75rem; font-size:0.75rem;">
        Filter
    </button>

    @if(array_filter($filters))
        <a href="{{ request()->url() }}"
           class="corex-btn-outline text-xs px-3 py-1.5">
            Clear
        </a>
    @endif
</form>

{{-- TABLE --}}
<div class="ds-status-card overflow-hidden" style="border-left-color: var(--ds-cyan); padding:0;">
    <table class="ds-table w-full text-sm">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Compiled</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Presentation</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Compiled by</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Blueprint</th>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Links</th>
            </tr>
        </thead>
        <tbody class="divide-y" style="border-color: var(--border);">
            @forelse($versions as $version)
                <tr class="transition-all duration-300" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                    <td class="px-4 py-3 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                        {{ $version->compiled_at?->format('Y-m-d H:i') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-primary);">
                        {{ $version->presentation?->title ?? '#' . $version->presentation_id }}
                        <span class="ml-1" style="color: var(--text-muted);">({{ $version->presentation?->suburb ?? '' }})</span>
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                        {{ $version->compiledBy?->name ?? 'User #' . $version->compiled_by }}
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                        {{ $version->blueprint_version ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-xs">
                        @if($version->presentation)
                            <a href="{{ route('presentations.show', $version->presentation_id) }}"
                               class="hover:underline mr-3" style="color: var(--brand-icon, #0ea5e9);">
                                Presentation →
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm italic" style="color: var(--text-muted);">
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
