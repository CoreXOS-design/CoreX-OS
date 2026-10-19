@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §11 (Landing 2) — saved column
    mappings, CRUD floor (BUILD_STANDARD §1a-§1d): search (name), sort
    (name, default), archive/restore. No Alpine.
--}}

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Saved column mappings</h1>
                <p class="text-xs" style="color: var(--text-muted);">The column layouts you saved when importing files from another system, so the next upload maps itself.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-outline text-xs">Back to take-on import</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="ds-alert-success">{{ session('status') }}</div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="archived" value="{{ $showArchived ? 1 : 0 }}">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Search by name</label>
                <input type="text" name="q" value="{{ request('q') }}" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
            </div>
            <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
            <a href="{{ route('corex.rentals.take-on-import.mappings.index', ['archived' => $showArchived ? 0 : 1]) }}" class="corex-btn-outline text-sm" style="height:2.25rem;">
                {{ $showArchived ? 'Back to active mappings' : 'View archived mappings' }}
            </a>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($mappings->total()) }} {{ \Illuminate\Support\Str::plural('mapping', $mappings->total()) }}
        </span>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if ($mappings->isEmpty())
            <div class="ds-empty-state px-6 py-12 text-center text-sm text-muted">
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
            @if ($mappings->hasPages())
            <div class="px-5 py-4" style="border-top:1px solid var(--border);">{{ $mappings->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
