@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §6 — "on rentals on a property we have a
    work order button," Johan's own words. §15 (AT-447) — ALSO reached from
    an inspection's Follow-up block via $followUp: 'single' prefills one
    record (one marked item, or several combined); 'batch' is several
    marked items NOT combined — one work order (or job card) per item,
    sharing only the fields a human must still decide below.
--}}

@section('corex-content')
<div class="w-full space-y-5" x-data="rentalWorkOrderCreate({{ Js::from([
    'propertyId' => $property?->id ?? '',
    'propertyLabel' => $property?->buildDisplayAddress() ?? '',
]) }})">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New Work Order</h1>
        <p class="text-xs" style="color: var(--text-muted);">Log a repair or maintenance job on a rental property and say who will do it.</p>
    </div>

    @if($followUp && ($followUp['mode'] ?? null) === 'none')
        <div class="text-sm px-4 py-3 rounded-md max-w-3xl" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--ds-amber);">
            Nothing to create — every selected item already has a work order.
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-work-orders.store') }}" class="space-y-5 rounded-md p-6 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-wonew-form">
        @csrf

        @if ($errors->any())
            <div class="text-sm px-4 py-3 rounded-md" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- AT-442 req #1 — "who does the work" is the FIRST choice on every work order. --}}
        <div data-tour="rmnt-wonew-who">
            <label class="prop-label">Who does the work?</label>
            <div class="flex flex-wrap gap-3 mt-1 text-sm">
                <label class="flex items-center gap-2 rounded-md px-4 cursor-pointer" style="height:2.25rem; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"><input type="radio" name="assignment_type" value="outside_supplier" {{ $presetAssignmentType !== 'internal' ? 'checked' : '' }}> Outside supplier</label>
                <label class="flex items-center gap-2 rounded-md px-4 cursor-pointer" style="height:2.25rem; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"><input type="radio" name="assignment_type" value="internal" {{ $presetAssignmentType === 'internal' ? 'checked' : '' }}> Our maintenance team</label>
            </div>
        </div>

        <div data-tour="rmnt-wonew-property">
            <label class="prop-label">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1 font-medium" style="color: var(--text-primary);">{{ $property->buildDisplayAddress() }}</div>
            @else
                {{-- AT-442 fix #2 — searchable, scoped property picker (same
                     pattern as RentalApplicationController::searchProperties())
                     instead of a capped, unsorted-by-address 500-row <select>
                     that silently dropped the rest of a 570-property book. --}}
                <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                       placeholder="Search properties by address…" required
                       class="prop-input mt-1">
                <input type="hidden" name="property_id" x-model="selectedPropertyId" required>
                <div class="mt-1 rounded-md max-h-72 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                    <template x-for="p in propertyResults" :key="p.id">
                        <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-sm" style="color: var(--text-primary);">
                            <span x-text="p.label"></span>
                        </button>
                    </template>
                </div>
                <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="selectedPropertyLabel" x-text="'Selected: ' + selectedPropertyLabel"></p>
            @endif
        </div>

        @if($lease)
            <input type="hidden" name="lease_id" value="{{ $lease->id }}">
            <p class="text-xs" style="color: var(--text-muted);">Tenancy: {{ $lease->tenantNames() }}.</p>
        @elseif($property)
            <div>
                <label class="prop-label">Tenancy (optional)</label>
                <select name="lease_id" class="prop-input">
                    <option value="">— No tenancy (vacancy period) —</option>
                    @foreach(\App\Models\Lease::where('property_id', $property->id)->orderByDesc('start_date')->limit(50)->get() as $l)
                        <option value="{{ $l->id }}">{{ $l->tenantNames() }} ({{ $l->start_date?->format('Y-m-d') }}&ndash;{{ $l->end_date?->format('Y-m-d') ?? 'ongoing' }})</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if($followUp && ($followUp['mode'] ?? null) === 'batch')
            {{-- §15 (AT-447) batch mode — several marked items, "combine"
                 left unticked: one work order per item, title/description
                 already derived from each item's own room/condition/note,
                 nothing left to type per item. --}}
            <input type="hidden" name="rental_inspection_id" value="{{ $followUp['rental_inspection_id'] }}">
            <input type="hidden" name="reported_by_type" value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_INSPECTION }}">
            <div>
                <label class="prop-label">{{ count($followUp['items']) }} items — each becomes its own work order</label>
                <ul class="text-sm mt-1 space-y-1" style="color: var(--text-primary);">
                    @foreach($followUp['items'] as $i => $item)
                        <li>{{ $item['title'] }}</li>
                        <input type="hidden" name="batch_items[{{ $i }}][observation_id]" value="{{ $item['observation_id'] }}">
                        <input type="hidden" name="batch_items[{{ $i }}][rental_inspection_item_id]" value="{{ $item['rental_inspection_item_id'] }}">
                        <input type="hidden" name="batch_items[{{ $i }}][title]" value="{{ $item['title'] }}">
                        <input type="hidden" name="batch_items[{{ $i }}][description]" value="{{ $item['description'] }}">
                    @endforeach
                </ul>
            </div>
        @else
            @if($followUp && ($followUp['mode'] ?? null) === 'single')
                {{-- §15 (AT-447) single/combined mode — this item (or merged
                     set) was marked during an inspection; the bridge FKs
                     this spec always had but never wired up (rental-work-
                     orders.md §3.1). --}}
                <input type="hidden" name="rental_inspection_item_id" value="{{ $followUp['rental_inspection_item_id'] }}">
                <input type="hidden" name="reported_inspection_observation_id" value="{{ $followUp['reported_inspection_observation_id'] }}">
                <input type="hidden" name="reported_by_type" value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_INSPECTION }}">
                <p class="text-xs" style="color: var(--text-muted);">Reported via: this inspection's Follow-up block.</p>
            @else
                <div data-tour="rmnt-wonew-by">
                    <label class="prop-label">Reported by</label>
                    <select name="reported_by_type" required class="prop-input">
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED }}">Owner instructed</option>
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_AGENT_NOTICED }}">Agent noticed it directly</option>
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_TENANT }}">Tenant (bypassing a fault report)</option>
                    </select>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">A tenant-reported fault is normally logged as a Fault Report first — use this only when the agent judges it trivial enough to skip that.</p>
                </div>
            @endif

            <div data-tour="rmnt-wonew-title">
                <label class="prop-label">Title</label>
                <input type="text" name="title" value="{{ old('title', $followUp['title'] ?? '') }}" required maxlength="191" placeholder="e.g. Geyser burst — upstairs bathroom" class="prop-input">
            </div>

            <div data-tour="rmnt-wonew-desc">
                <label class="prop-label">Description</label>
                <textarea name="description" required rows="4" class="prop-input">{{ old('description', $followUp['description'] ?? '') }}</textarea>
            </div>
        @endif

        {{-- section 28.7: no Trade type here - it was never used to choose the contractor (the contractor's trades come from the fault's type and the supplier's ticked service types) --}}

        <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--border);">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-sm">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm" data-tour="rmnt-wonew-save">Log Work Order</button>
        </div>
    </form>
</div>

<script>
function rentalWorkOrderCreate(old) {
    old = old || {};
    return {
        propertyQuery: old.propertyLabel || '', propertyResults: [],
        selectedPropertyId: old.propertyId || '', selectedPropertyLabel: old.propertyLabel || '',
        async searchProperties() {
            if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
            const res = await fetch('{{ route('corex.rental-work-orders.search-properties') }}?q=' + encodeURIComponent(this.propertyQuery));
            this.propertyResults = await res.json();
        },
        selectProperty(p) {
            this.selectedPropertyId = p.id;
            this.selectedPropertyLabel = p.label;
            this.propertyResults = [];
            this.propertyQuery = p.label;
        },
    };
}
</script>
@endsection
