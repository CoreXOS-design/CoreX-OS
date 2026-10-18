@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §5.2 — dry-run preview. Nothing has
    been written to properties/contacts/leases yet. Deliberately no Alpine
    on this page — the "select all" behaviour below is plain vanilla JS.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Review take-on import — {{ $run->source_filename }}</h1>
        <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary text-xs">Back to batches</a>
    </div>

    @if (session('status'))
        <div class="ds-alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="ds-alert-danger">{{ session('error') }}</div>
    @endif

    <div class="corex-card p-4 text-xs flex gap-6">
        <div><strong>{{ $run->counts_json['total'] ?? 0 }}</strong> rows</div>
        <div><strong>{{ $run->counts_json['will_create_property'] ?? 0 }}</strong> new properties</div>
        <div><strong>{{ $run->counts_json['will_match_property'] ?? 0 }}</strong> matched properties</div>
        <div><strong>{{ $run->counts_json['complete'] ?? 0 }}</strong> complete leases</div>
        <div><strong>{{ $run->counts_json['draft'] ?? 0 }}</strong> draft (needs completing)</div>
        <div><strong>{{ $run->counts_json['errors'] ?? 0 }}</strong> rows with errors</div>
    </div>

    <p class="text-xs text-muted">
        Nothing below has been created yet. Tick the rows you want to import and press "Import selected",
        or import one row at a time. Rows with errors cannot be imported until fixed in the spreadsheet and
        re-uploaded as a new batch. Importing never emails or messages a landlord or tenant.
    </p>

    {{-- A standalone bulk form — row checkboxes point at it via the HTML5
         form="" attribute instead of nesting inside it, since each row ALSO
         carries its own single-row Import/Exclude forms and HTML forms must
         never nest. --}}
    <form method="POST" action="{{ route('corex.rentals.take-on-import.confirm-bulk', $run) }}" id="rtoi-bulk-form">
        @csrf
    </form>

    <div class="corex-card p-4">
            <div class="flex items-center justify-between mb-3">
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" id="rtoi-select-all" onclick="document.querySelectorAll('.rtoi-row-check').forEach(cb => { if (!cb.disabled) cb.checked = this.checked; })">
                    Select all without errors
                </label>
                <button type="submit" form="rtoi-bulk-form" class="corex-btn-primary text-xs">Import selected</button>
            </div>
            <div class="overflow-x-auto">
            <table class="ds-table w-full text-xs">
                <thead>
                    <tr>
                        <th></th>
                        <th>Row</th>
                        <th>Property</th>
                        <th>Landlord</th>
                        <th>Tenant(s)</th>
                        <th>Rent</th>
                        <th>Status</th>
                        <th>Errors / warnings</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $payload = $row->payload_json ?? []; @endphp
                        <tr>
                            <td>
                                <input type="checkbox" name="ids[]" value="{{ $row->id }}" class="rtoi-row-check" form="rtoi-bulk-form"
                                    @checked($row->status === 'pending')
                                    @disabled(in_array($row->status, ['confirmed', 'excluded']) || $row->hasBlockingErrors())>
                            </td>
                            <td>{{ $row->row_number }}</td>
                            <td>{{ $row->property_match_label }}</td>
                            <td>{{ $payload['landlord1_name'] ?? '—' }}</td>
                            <td>{{ collect([$payload['tenant1_name'] ?? null, $payload['tenant2_name'] ?? null, $payload['tenant3_name'] ?? null, $payload['tenant4_name'] ?? null])->filter()->implode(', ') ?: '—' }}</td>
                            <td>{{ isset($payload['monthly_rental_amount']) ? number_format((float) $payload['monthly_rental_amount'], 2) : '—' }}</td>
                            <td>
                                <span class="ds-badge {{ $row->lease_completeness === 'draft' ? 'ds-badge-muted' : 'ds-badge-success' }}">{{ ucfirst($row->lease_completeness ?? 'pending') }}</span>
                                @if ($row->status === 'confirmed')
                                    <span class="ds-badge ds-badge-success">Imported</span>
                                @elseif ($row->status === 'excluded')
                                    <span class="ds-badge ds-badge-muted">Excluded</span>
                                @endif
                            </td>
                            <td>
                                @foreach ($row->errors_json ?? [] as $e)
                                    <div class="text-red-600">{{ $e }}</div>
                                @endforeach
                                @foreach ($row->warnings_json ?? [] as $w)
                                    <div class="text-amber-600">{{ $w }}</div>
                                @endforeach
                            </td>
                            <td class="space-x-2">
                                @if (!in_array($row->status, ['confirmed', 'excluded']))
                                    <form method="POST" action="{{ route('corex.rentals.take-on-import.rows.confirm', $row) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-secondary text-xs" @disabled($row->hasBlockingErrors())>Import</button>
                                    </form>
                                    <form method="POST" action="{{ route('corex.rentals.take-on-import.rows.exclude', $row) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-danger-outline text-xs">Exclude</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-4">{{ $rows->links() }}</div>
    </div>
</div>
@endsection
