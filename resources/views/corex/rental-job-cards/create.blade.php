@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §14 (AT-442) — new internal job card. --}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <h1 class="text-lg font-semibold">New Job Card</h1>

    @if ($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-job-cards.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if($faultReport)
            <input type="hidden" name="fault_report_id" value="{{ $faultReport->id }}">
            <p class="text-xs" style="color: var(--text-muted);">Raised from fault report #{{ $faultReport->id }}: {{ $faultReport->title }}</p>
        @endif

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

        <div>
            <label class="text-xs font-medium">Title</label>
            <input type="text" name="title" required maxlength="191" value="{{ old('title', $faultReport?->title) }}" placeholder="e.g. Geyser burst — upstairs bathroom" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>

        <div>
            <label class="text-xs font-medium">Description</label>
            <textarea name="description" rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $faultReport?->description) }}</textarea>
        </div>

        <div>
            <label class="text-xs font-medium">Access notes</label>
            <textarea name="access_notes" rows="2" placeholder="Gate code, dog on site, tenant works from home, etc." class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Create Job Card</button>
        </div>
    </form>
</div>
@endsection
