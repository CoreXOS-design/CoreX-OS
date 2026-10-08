@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §11 (Landing 2) — saved column
    mappings, CRUD floor (BUILD_STANDARD §1a-§1d): search (name), sort
    (name, default), archive/restore. No Alpine.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Saved column mappings</h1>
        <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary text-xs">Back to take-on import</a>
    </div>

    @if (session('status'))
        <div class="ds-alert-success">{{ session('status') }}</div>
    @endif

    <div class="corex-card p-4">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
            <input type="hidden" name="archived" value="{{ $showArchived ? 1 : 0 }}">
            <div>
                <label class="block text-xs text-muted">Search by name</label>
                <input type="text" name="q" value="{{ request('q') }}" class="prop-input text-xs">
            </div>
            <button type="submit" class="corex-btn-secondary text-xs">Filter</button>
            <a href="{{ route('corex.rentals.take-on-import.mappings.index', ['archived' => $showArchived ? 0 : 1]) }}" class="corex-btn-secondary text-xs">
                {{ $showArchived ? 'Back to active mappings' : 'View archived mappings' }}
            </a>
        </form>

        @if ($mappings->isEmpty())
            <div class="ds-empty-state p-6 text-center text-sm text-muted">
                @if ($showArchived)
                    No archived mappings.
                @elseif (request('q'))
                    No mappings match this search.
                @else
                    No saved column mappings yet — save one from the "Map your columns" screen when you upload a non-template file.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
            <table class="ds-table w-full text-xs">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Fields mapped</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mappings as $mapping)
                        <tr>
                            <td>{{ $mapping->name }}</td>
                            <td>{{ count($mapping->mapping_json ?? []) }}</td>
                            <td>{{ $mapping->created_at->format('Y-m-d') }}</td>
                            <td class="space-x-2">
                                @if ($showArchived)
                                    <form method="POST" action="{{ route('corex.rentals.take-on-import.mappings.restore', $mapping->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-secondary text-xs">Restore</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('corex.rentals.take-on-import.mappings.archive', $mapping) }}" class="inline" data-confirm="Archive this mapping?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        <button type="submit" class="corex-btn-danger-outline text-xs">Archive</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-4">{{ $mappings->links() }}</div>
        @endif
    </div>
</div>
@endsection
