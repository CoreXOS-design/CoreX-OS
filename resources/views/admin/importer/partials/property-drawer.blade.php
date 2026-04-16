@php
    $m = $row->mapped_json ?? [];
    $p = $row->payload_json ?? [];
    $images = (array) ($row->image_urls_json ?? []);
    $errs = (array) ($row->errors_json ?? []);
@endphp

<h3 class="text-lg font-semibold mb-3">Listing #{{ $row->external_id }}</h3>
<div class="text-xs text-muted mb-4">
    Run #{{ $row->run_id }} · Status: {{ $row->status }} · Action: {{ $row->action }}
</div>

@if (!empty($errs))
    <div class="rounded-md bg-red-500/10 border border-red-500/30 text-red-300 px-3 py-2 text-xs mb-3">
        <div class="font-semibold mb-1">Errors</div>
        @foreach ($errs as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif

<div class="grid grid-cols-2 gap-3 text-sm mb-4">
    <div><span class="text-muted text-xs block">Type</span> {{ $m['listing_type'] ?? '—' }}</div>
    <div><span class="text-muted text-xs block">Property Type</span> {{ $m['property_type'] ?? '—' }}</div>
    <div><span class="text-muted text-xs block">Price</span>
        @if (!empty($m['price'])) R {{ number_format((float)$m['price'], 0, '.', ',') }}
        @elseif (!empty($m['rental_amount'])) R {{ number_format((float)$m['rental_amount'], 0, '.', ',') }} /m
        @else — @endif
    </div>
    <div><span class="text-muted text-xs block">Address</span> {{ $m['address'] ?? '—' }}</div>
    <div><span class="text-muted text-xs block">Beds / Baths / Garages</span> {{ $m['beds'] ?? 0 }} / {{ $m['baths'] ?? 0 }} / {{ $m['garages'] ?? 0 }}</div>
    <div><span class="text-muted text-xs block">Erf m² / Floor m²</span> {{ $m['erf_size_m2'] ?? '—' }} / {{ $m['size_m2'] ?? '—' }}</div>
    <div><span class="text-muted text-xs block">Resolved Agent</span>
        {{ $row->resolvedAgent?->name ?? '— unresolved —' }}
    </div>
    <div><span class="text-muted text-xs block">SourceReference</span> {{ $m['source_reference'] ?? '—' }}</div>
</div>

<div class="mb-4">
    <div class="text-muted text-xs mb-1">Description</div>
    <div class="text-sm whitespace-pre-wrap bg-surface-2 rounded-md p-3 max-h-40 overflow-y-auto">{{ $m['description'] ?? '' }}</div>
</div>

<div class="mb-4">
    <div class="text-muted text-xs mb-2">Images ({{ count($images) }})</div>
    <div class="grid grid-cols-3 gap-2">
        @foreach ($images as $url)
            <img src="{{ $url }}" loading="lazy" class="rounded-md w-full h-24 object-cover">
        @endforeach
    </div>
</div>

<details class="text-xs text-muted">
    <summary class="cursor-pointer">Raw CSV payload</summary>
    <pre class="bg-surface-2 rounded-md p-2 mt-2 overflow-x-auto">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</details>
