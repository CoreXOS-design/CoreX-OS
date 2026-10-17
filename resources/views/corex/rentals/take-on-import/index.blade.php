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

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental take-on import</h1>
        <p class="text-xs" style="color: var(--text-muted);">Bring an existing rental book into CoreX: upload it, check each row, then confirm. Nothing is created or sent until you confirm.</p>
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

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="archived" value="{{ $showArchived ? 1 : 0 }}">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Filename or uploader" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
                <select name="status" class="list-header-filter text-sm {{ filled(request('status')) ? 'is-active' : '' }}">
                    <option value="">All statuses</option>
                    @foreach (['parsing', 'mapping_pending', 'pending_confirm', 'importing', 'completed', 'failed', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
            </div>
            <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
            <a href="{{ route('corex.rentals.take-on-import.index', ['archived' => $showArchived ? 0 : 1]) }}" class="corex-btn-outline text-sm" style="height:2.25rem;">
                {{ $showArchived ? 'Back to active batches' : 'View archived batches' }}
            </a>
        <div class="flex-1"></div>
        <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
              style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
            {{ number_format($runs->total()) }} {{ \Illuminate\Support\Str::plural('batch', $runs->total()) }}
        </span>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if ($runs->isEmpty())
            <div class="ds-empty-state px-6 py-12 text-center text-sm text-muted">
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
            @if ($runs->hasPages())
            <div class="px-5 py-4" style="border-top:1px solid var(--border);">{{ $runs->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
