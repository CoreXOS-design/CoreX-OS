@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §6 — "on rentals on a property we have a work order button," Johan's own words. --}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4" x-data="rentalWorkOrderCreate({{ Js::from([
    'propertyId' => $property?->id ?? '',
    'propertyLabel' => $property?->buildDisplayAddress() ?? '',
]) }})">
    <h1 class="text-lg font-semibold">New Work Order</h1>

    <form method="POST" action="{{ route('corex.rental-work-orders.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- AT-442 req #1 — "who does the work" is the FIRST choice on every work order. --}}
        <div>
            <label class="text-xs font-medium">Who does the work?</label>
            <div class="flex gap-4 mt-1 text-sm">
                <label class="flex items-center gap-1"><input type="radio" name="assignment_type" value="outside_supplier" checked> Outside supplier</label>
                <label class="flex items-center gap-1"><input type="radio" name="assignment_type" value="internal"> Our maintenance team</label>
            </div>
        </div>

        <div>
            <label class="text-xs font-medium">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
            @else
                {{-- AT-442 fix #2 — searchable, scoped property picker (same
                     pattern as RentalApplicationController::searchProperties())
                     instead of a capped, unsorted-by-address 500-row <select>
                     that silently dropped the rest of a 570-property book. --}}
                <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                       placeholder="Search properties by address…" required
                       class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <input type="hidden" name="property_id" x-model="selectedPropertyId" required>
                <div class="mt-1 rounded-md max-h-72 overflow-y-auto" style="border: 1px solid var(--border);" x-show="propertyResults.length">
                    <template x-for="p in propertyResults" :key="p.id">
                        <button type="button" @click="selectProperty(p)" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
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
                <label class="text-xs font-medium">Tenancy (optional)</label>
                <select name="lease_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">— No tenancy (vacancy period) —</option>
                    @foreach(\App\Models\Lease::where('property_id', $property->id)->orderByDesc('start_date')->limit(50)->get() as $l)
                        <option value="{{ $l->id }}">{{ $l->tenantNames() }} ({{ $l->start_date?->format('Y-m-d') }}&ndash;{{ $l->end_date?->format('Y-m-d') ?? 'ongoing' }})</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Reported by</label>
            <select name="reported_by_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED }}">Owner instructed</option>
                <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_AGENT_NOTICED }}">Agent noticed it directly</option>
                <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_TENANT }}">Tenant (bypassing a fault report)</option>
            </select>
            <p class="text-xs mt-1" style="color: var(--text-muted);">A tenant-reported fault is normally logged as a Fault Report first — use this only when the agent judges it trivial enough to skip that.</p>
        </div>

        <div>
            <label class="text-xs font-medium">Trade type</label>
            <select name="trade_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">— Not yet known —</option>
                @foreach(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get() as $type)
                    <option value="{{ $type->code }}">{{ $type->label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="text-xs font-medium">Title</label>
            <input type="text" name="title" required maxlength="191" placeholder="e.g. Geyser burst — upstairs bathroom" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>

        <div>
            <label class="text-xs font-medium">Description</label>
            <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Log Work Order</button>
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
