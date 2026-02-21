@extends('layouts.nexus')

@section('nexus-content')

<div class="mb-6 flex items-center justify-between">
    <h1 class="text-xl font-bold text-gray-800">{{ $pageTitle }}</h1>
    <a href="{{ route('presentations.index') }}"
       class="text-xs text-indigo-600 hover:underline">← All Presentations</a>
</div>

{{-- FILTERS --}}
<form method="GET" class="mb-4 flex flex-wrap gap-2 items-end">
    @if($isAdmin)
        <div>
            <label class="block text-xs text-gray-500 mb-1">Branch</label>
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
            <label class="block text-xs text-gray-500 mb-1">User ID</label>
            <input type="number" name="user_id" value="{{ $filters['user_id'] ?? '' }}"
                   placeholder="Any"
                   class="border border-gray-300 rounded px-2 py-1.5 text-xs w-24">
        </div>
    @endif

    <div>
        <label class="block text-xs text-gray-500 mb-1">Presentation ID</label>
        <input type="number" name="presentation_id" value="{{ $filters['presentation_id'] ?? '' }}"
               placeholder="Any"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Period (YYYY-MM)</label>
        <input type="text" name="period" value="{{ $filters['period'] ?? '' }}"
               placeholder="e.g. 2026-02"
               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-28">
    </div>

    <button type="submit"
            class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded hover:bg-indigo-700">
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
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full text-sm">
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
                               class="text-indigo-600 hover:underline mr-3">
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
