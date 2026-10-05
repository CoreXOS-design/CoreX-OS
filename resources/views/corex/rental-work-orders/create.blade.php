@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §6 — "on rentals on a property we have a
    work order button," Johan's own words. §15 (AT-447) — ALSO reached from
    an inspection's Follow-up block via $followUp: 'single' prefills one
    record (one marked item, or several combined); 'batch' is several
    marked items NOT combined — one work order (or job card) per item,
    sharing only the fields a human must still decide below.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <h1 class="text-lg font-semibold">New Work Order</h1>

    @if($followUp && ($followUp['mode'] ?? null) === 'none')
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber);">
            Nothing to create — every selected item already has a work order.
        </div>
    @endif

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
                <label class="flex items-center gap-1"><input type="radio" name="assignment_type" value="outside_supplier" {{ $presetAssignmentType !== 'internal' ? 'checked' : '' }}> Outside supplier</label>
                <label class="flex items-center gap-1"><input type="radio" name="assignment_type" value="internal" {{ $presetAssignmentType === 'internal' ? 'checked' : '' }}> Our maintenance team</label>
            </div>
        </div>

        <div>
            <label class="text-xs font-medium">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
            @else
                <select name="property_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Select a property…</option>
                    @foreach(\App\Models\Property::where('listing_type', 'rental')->orderBy('title')->limit(500)->get() as $p)
                        <option value="{{ $p->id }}">{{ $p->buildDisplayAddress() }}</option>
                    @endforeach
                </select>
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

        @if($followUp && ($followUp['mode'] ?? null) === 'batch')
            {{-- §15 (AT-447) batch mode — several marked items, "combine"
                 left unticked: one work order per item, title/description
                 already derived from each item's own room/condition/note,
                 nothing left to type per item. --}}
            <input type="hidden" name="rental_inspection_id" value="{{ $followUp['rental_inspection_id'] }}">
            <input type="hidden" name="reported_by_type" value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_INSPECTION }}">
            <div>
                <label class="text-xs font-medium">{{ count($followUp['items']) }} items — each becomes its own work order</label>
                <ul class="text-sm mt-1 space-y-1">
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
                <div>
                    <label class="text-xs font-medium">Reported by</label>
                    <select name="reported_by_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED }}">Owner instructed</option>
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_AGENT_NOTICED }}">Agent noticed it directly</option>
                        <option value="{{ \App\Models\RentalWorkOrder::REPORTED_BY_TENANT }}">Tenant (bypassing a fault report)</option>
                    </select>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">A tenant-reported fault is normally logged as a Fault Report first — use this only when the agent judges it trivial enough to skip that.</p>
                </div>
            @endif

            <div>
                <label class="text-xs font-medium">Title</label>
                <input type="text" name="title" value="{{ old('title', $followUp['title'] ?? '') }}" required maxlength="191" placeholder="e.g. Geyser burst — upstairs bathroom" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>

            <div>
                <label class="text-xs font-medium">Description</label>
                <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $followUp['description'] ?? '') }}</textarea>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Trade type</label>
            <select name="trade_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">— Not yet known —</option>
                @foreach(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get() as $type)
                    <option value="{{ $type->code }}">{{ $type->label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Log Work Order</button>
        </div>
    </form>
</div>
@endsection
