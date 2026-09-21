@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §8 — the move-out review. Read-time only:
    RentalInventoryComparisonService computes this fresh on every load,
    nothing here is stored. A PROPOSAL an agent reviews — no currency, no
    deposit figure anywhere on this page.
--}}

@section('content')
<div class="p-6 max-w-5xl mx-auto space-y-4" x-data="rentalInventoryComparison({{ $inventory->id }})">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">Move-out comparison — {{ $inventory->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Against the inventory recorded at move-in. Nothing on this page is a deposit figure — it is what was found, for an agent to review.</p>
        </div>
        <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="corex-btn-outline text-xs">Back to inventory</a>
    </div>

    <div x-show="lifecycleError" x-cloak class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" x-text="lifecycleError"></div>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2">Room</th>
                    <th class="text-left px-4 py-2">Item</th>
                    <th class="text-left px-4 py-2">Qty at move-in</th>
                    <th class="text-left px-4 py-2">Move-out finding</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="comparison-row-{{ $row['line_id'] }}">
                    <td class="px-4 py-2" style="color: var(--text-muted);">{{ $row['room_label'] }}</td>
                    <td class="px-4 py-2">{{ $row['description'] }}</td>
                    <td class="px-4 py-2">{{ $row['quantity_at_move_in'] }}</td>
                    <td class="px-4 py-2">
                        @if($row['outstanding'])
                            <span class="text-xs" style="color: var(--text-muted);">Not yet checked</span>
                        @else
                            <div class="text-xs">
                                <span class="ds-badge {{ $row['disposition_key'] === 'present' ? 'ds-badge-success' : ($row['disposition_key'] === 'missing' ? 'ds-badge-danger' : 'ds-badge-info') }}">{{ $row['disposition_label'] }}</span>
                                @if($row['quantity_found'] !== null)
                                    <span style="color: var(--text-secondary);"> — {{ $row['quantity_found'] }} found{{ $row['quantity_delta'] > 0 ? ' (' . $row['quantity_delta'] . ' short)' : '' }}</span>
                                @endif
                                @if($row['notes'])
                                    <div style="color: var(--text-muted);">{{ $row['notes'] }}</div>
                                @endif
                                <div style="color: var(--text-muted);">{{ $row['recorded_by'] }} — {{ $row['recorded_at'] }}</div>
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        @permission('rental_inventories.create')
                        <button type="button" @click="openFor({{ $row['line_id'] }})" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2);">
                            {{ $row['outstanding'] ? 'Record' : 'Correct' }}
                        </button>
                        @endpermission
                    </td>
                </tr>
                <tr x-show="activeLine === {{ $row['line_id'] }}" x-cloak style="border-bottom: 1px solid var(--border); background:var(--surface-2);">
                    <td colspan="5" class="px-4 py-3">
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                            <select x-model="field({{ $row['line_id'] }}).disposition_key" class="prop-input">
                                <option value="">Select…</option>
                                @foreach($dispositionPresets as $preset)
                                    <option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="0" x-model.number="field({{ $row['line_id'] }}).quantity_found" placeholder="Qty found (leave blank if not applicable)" class="prop-input sm:col-span-1">
                            <input type="text" x-model="field({{ $row['line_id'] }}).notes" placeholder="Note" class="prop-input sm:col-span-1">
                            <div class="flex gap-2">
                                <button type="button" @click="activeLine = null" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface);">Cancel</button>
                                <button type="button" @click="save({{ $row['line_id'] }})" :disabled="!field({{ $row['line_id'] }}).disposition_key" class="text-xs px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save</button>
                            </div>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
function rentalInventoryComparison(inventoryId) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        activeLine: null,
        forms: {},
        lifecycleError: '',
        field(lineId) { return this.forms[lineId] || (this.forms[lineId] = { disposition_key: '', quantity_found: null, notes: '' }); },
        openFor(lineId) { this.activeLine = this.activeLine === lineId ? null : lineId; },
        async save(lineId) {
            const form = this.field(lineId);
            this.lifecycleError = '';
            try {
                const res = await fetch(`${this.baseUrl}/lines/${lineId}/dispositions`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        disposition_key: form.disposition_key,
                        quantity_found: form.quantity_found === '' ? null : form.quantity_found,
                        notes: form.notes || null,
                    }),
                });
                if (!res.ok) { const j = await res.json().catch(() => ({})); throw new Error(j.message || `Request failed (${res.status}).`); }
                window.location.reload();
            } catch (e) { this.lifecycleError = e.message; }
        },
    };
}
</script>
@endpush
