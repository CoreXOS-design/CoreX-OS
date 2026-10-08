@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §7 — read-only batch detail: what this
    run actually did, per row. No Alpine.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Take-on import — {{ $run->source_filename }}</h1>
        <div class="space-x-2">
            <a href="{{ route('corex.rentals.take-on-import.issues', $run) }}" class="corex-btn-secondary text-xs">Download issue report</a>
            <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary text-xs">Back to batches</a>
        </div>
    </div>

    @if (session('status'))
        <div class="ds-alert-success">{{ session('status') }}</div>
    @endif

    <div class="corex-card p-4 text-xs flex gap-6">
        <div>Status: <span class="ds-badge ds-badge-info">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</span></div>
        <div>Uploaded by {{ $run->user?->name }} on {{ $run->created_at->format('Y-m-d H:i') }}</div>
        @if ($run->completed_at)
            <div>Completed {{ $run->completed_at->format('Y-m-d H:i') }}</div>
        @endif
    </div>

    <div class="corex-card p-4">
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
                                <div class="text-red-600">{{ $e }}</div>
                            @endforeach
                            @foreach ($row->warnings_json ?? [] as $w)
                                <div class="text-amber-600">{{ $w }}</div>
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
        <form method="POST" action="{{ route('corex.rentals.take-on-import.archive', $run) }}" data-confirm="Archive this batch? Anything it created that has not been edited since will be archived." data-confirm-danger data-confirm-label="Archive">
            @csrf
            <button type="submit" class="corex-btn-danger-outline text-xs">Archive this batch</button>
        </form>
    @endif
    @endpermission
</div>
@endsection
