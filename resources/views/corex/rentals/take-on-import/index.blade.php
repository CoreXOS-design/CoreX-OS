@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §7 — batch history list. CRUD/list-screen
    floor (BUILD_STANDARD §1a-§1d): search (source filename, uploading user),
    sort (default: newest first), filter (status, date range), pagination,
    real empty state, OWN/BRANCH/AGENCY scoping (RentalTakeOnImportRun::scopeVisibleTo()).
    Deliberately no Alpine on this page — plain forms/links only.
--}}

@php
    $sortLink = fn ($col) => route('corex.rentals.take-on-import.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => (request('sort', 'created_at') === $col && request('direction', 'desc') === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => request('sort', 'created_at') === $col ? (request('direction', 'desc') === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'completed' => 'ds-badge-success',
        'failed' => 'ds-badge-danger',
        'cancelled' => 'ds-badge-muted',
        'pending_confirm' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental take-on import</h1>
    </div>

    @if (session('status'))
        <div class="ds-alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="ds-alert-danger">{{ $errors->first() }}</div>
    @endif

    @unless ($showArchived)
    <div class="corex-card p-4 space-y-3">
        <h2 class="text-sm font-semibold">Upload a take-on book</h2>
        <p class="text-xs text-muted">
            Download the template, fill in one row per tenancy, then upload it here.
            Nothing is created until you review and confirm each row on the next screen.
            Importing never emails or messages a landlord or tenant.
        </p>
        <div class="flex items-center gap-3">
            <a href="{{ route('corex.rentals.take-on-import.template') }}" class="corex-btn-secondary text-xs">Download template</a>
            <a href="{{ route('corex.rentals.take-on-import.mappings.index') }}" class="corex-btn-secondary text-xs">Saved column mappings</a>
        </div>
        <form method="POST" action="{{ route('corex.rentals.take-on-import.upload') }}" enctype="multipart/form-data" class="flex items-center gap-3">
            @csrf
            <input type="file" name="file" accept=".xlsx,.csv" required class="text-xs">
            <button type="submit" class="corex-btn-primary text-xs">Upload &amp; preview</button>
        </form>
        <p class="text-xs text-muted">
            Uploading a file from another CRM? Upload it directly — if its columns don't match our
            template exactly, you'll be asked to map them on the next screen (and can save that mapping
            for next time).
        </p>
    </div>
    @endunless

    <div class="corex-card p-4">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
            <input type="hidden" name="archived" value="{{ $showArchived ? 1 : 0 }}">
            <div>
                <label class="block text-xs text-muted">Search</label>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Filename or uploader" class="prop-input text-xs">
            </div>
            <div>
                <label class="block text-xs text-muted">Status</label>
                <select name="status" class="prop-select text-xs">
                    <option value="">All</option>
                    @foreach (['parsing', 'mapping_pending', 'pending_confirm', 'importing', 'completed', 'failed', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-muted">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="prop-input text-xs">
            </div>
            <div>
                <label class="block text-xs text-muted">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="prop-input text-xs">
            </div>
            <button type="submit" class="corex-btn-secondary text-xs">Filter</button>
            <a href="{{ route('corex.rentals.take-on-import.index', ['archived' => $showArchived ? 0 : 1]) }}" class="corex-btn-secondary text-xs">
                {{ $showArchived ? 'Back to active batches' : 'View archived batches' }}
            </a>
        </form>

        @if ($runs->isEmpty())
            <div class="ds-empty-state p-6 text-center text-sm text-muted">
                @if ($showArchived)
                    No archived take-on import batches.
                @elseif (request('q') || request('status') || request('date_from') || request('date_to'))
                    No batches match this filter.
                @else
                    No take-on imports yet — download the template above to get started.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
            <table class="ds-table w-full text-xs">
                <thead>
                    <tr>
                        <th><a href="{{ $sortLink('created_at') }}">Uploaded{{ $sortIndicator('created_at') }}</a></th>
                        <th>File</th>
                        <th>Uploaded by</th>
                        <th><a href="{{ $sortLink('status') }}">Status{{ $sortIndicator('status') }}</a></th>
                        <th><a href="{{ $sortLink('rows') }}">Rows{{ $sortIndicator('rows') }}</a></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr>
                            <td>{{ $run->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $run->source_filename }}</td>
                            <td>{{ $run->user?->name }}</td>
                            <td><span class="ds-badge {{ $statusBadgeClass($run->status) }}">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</span></td>
                            <td>{{ $run->counts_json['total'] ?? $run->rows()->count() }}</td>
                            <td class="space-x-2">
                                @if ($showArchived)
                                    @permission('rentals_take_on_import.manage')
                                    <form method="POST" action="{{ route('corex.rentals.take-on-import.restore', $run->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-secondary text-xs">Restore</button>
                                    </form>
                                    @endpermission
                                @else
                                    @if ($run->status === 'mapping_pending')
                                        <a href="{{ route('corex.rentals.take-on-import.map-columns', $run) }}" class="corex-btn-secondary text-xs">Map columns</a>
                                    @elseif ($run->status === 'pending_confirm')
                                        <a href="{{ route('corex.rentals.take-on-import.preview', $run) }}" class="corex-btn-secondary text-xs">Review</a>
                                    @else
                                        <a href="{{ route('corex.rentals.take-on-import.show', $run) }}" class="corex-btn-secondary text-xs">View</a>
                                    @endif
                                    @permission('rentals_take_on_import.manage')
                                    @if ($run->status === 'completed')
                                        <form method="POST" action="{{ route('corex.rentals.take-on-import.archive', $run) }}" class="inline" data-confirm="Archive this batch? Anything it created that has not been edited since will be archived." data-confirm-danger data-confirm-label="Archive">
                                            @csrf
                                            <button type="submit" class="corex-btn-danger-outline text-xs">Archive</button>
                                        </form>
                                    @elseif (!in_array($run->status, ['completed', 'cancelled']))
                                        <form method="POST" action="{{ route('corex.rentals.take-on-import.cancel', $run) }}" class="inline" data-confirm="Cancel this import batch? Nothing confirmed so far will be undone." data-confirm-danger data-confirm-label="Cancel">
                                            @csrf
                                            <button type="submit" class="corex-btn-danger-outline text-xs">Cancel</button>
                                        </form>
                                    @endif
                                    @endpermission
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </div>
</div>
@endsection
