@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §7 — read-only batch detail: what this
    run actually did, per row. No Alpine.
--}}

@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-4">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3" data-tour="rops-tos-intro">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Take-on import — {{ $run->source_filename }}</h1>
        <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            <a href="{{ route('corex.rentals.take-on-import.issues', $run) }}" class="corex-btn-outline text-xs" data-tour="rops-tos-issues">Download issue report</a>
            <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-outline text-xs">Back to batches</a>
        </div>
    </div>
    </div>



    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif

    <div class="rounded-md p-4 text-xs flex flex-wrap gap-6" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);" data-tour="rops-tos-summary">
        <div>Status: <span class="ds-badge ds-badge-info">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</span></div>
        <div>Uploaded by {{ $run->user?->name }} on {{ $run->created_at->format('Y-m-d H:i') }}</div>
        @if ($run->completed_at)
            <div>Completed {{ $run->completed_at->format('Y-m-d H:i') }}</div>
        @endif
    </div>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rops-tos-rows">
        <div class="overflow-x-auto">
        <table class="ds-table w-full text-xs">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Property</th>
                    <th>Lease</th>
                    <th>Status</th>
                    <th>Issues</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->row_number }}</td>
                        <td>
                            @if ($row->targetProperty)
                                <a href="{{ route('corex.properties.show', $row->target_property_id) }}">{{ $row->targetProperty->buildDisplayAddress() ?? '#' . $row->target_property_id }}</a>
                            @else
                                {{ $row->property_match_label }}
                            @endif
                        </td>
                        <td>
                            @if ($row->targetLease)
                                @feature('rental-leases')<a href="{{ route('corex.leases.show', $row->target_lease_id) }}">Lease #{{ $row->target_lease_id }}</a>@else Lease #{{ $row->target_lease_id }} @endfeature
                            @else
                                —
                            @endif
                        </td>
                        <td><span class="ds-badge">{{ ucfirst($row->status) }}</span></td>
                        <td>
                            @foreach ($row->errors_json ?? [] as $e)
                                <div style="color: var(--ds-red, #dc2626);">{{ $e }}</div>
                            @endforeach
                            @foreach ($row->warnings_json ?? [] as $w)
                                <div style="color: var(--ds-amber, #f59e0b);">{{ $w }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </div>

    @permission('rentals_take_on_import.manage')
    @if ($run->status === 'completed')
        <form method="POST" action="{{ route('corex.rentals.take-on-import.archive', $run) }}" data-tour="rops-tos-archive" data-confirm="Archive this batch? Anything it created that has not been edited since will be archived." data-confirm-danger data-confirm-label="Archive">
            @csrf
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive this batch</button>
        </form>
    @endif
    @endpermission
</div>
@endsection
