@php
    $m = $row->mapped_json ?? [];
    $p = $row->payload_json ?? [];
    $images = (array) ($row->image_urls_json ?? []);
    $errs = (array) ($row->errors_json ?? []);
@endphp

<h3 class="text-sm font-bold mb-3" style="color: var(--text-primary);">Listing #{{ $row->external_id }}</h3>
<div class="text-xs mb-4" style="color: var(--text-muted);">
    Run #{{ $row->run_id }} · Status: {{ $row->status }} · Action: {{ $row->action }}
</div>

@if (!empty($errs))
    <div class="rounded-md px-3 py-2 text-xs mb-3"
         style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                color: var(--text-primary);">
        <div class="font-semibold mb-1" style="color: var(--ds-crimson);">Errors</div>
        @foreach ($errs as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif

<div class="grid grid-cols-2 gap-3 text-sm mb-4" style="color: var(--text-primary);">
    <div><span class="text-xs block" style="color: var(--text-muted);">Type</span> {{ $m['listing_type'] ?? '—' }}</div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Property Type</span> {{ $m['property_type'] ?? '—' }}</div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Price</span>
        <span class="tabular-nums">
        @if (!empty($m['price'])) R {{ number_format((float)$m['price'], 0, '.', ',') }}
        @elseif (!empty($m['rental_amount'])) R {{ number_format((float)$m['rental_amount'], 0, '.', ',') }} /m
        @else — @endif
        </span>
    </div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Address</span> {{ $m['address'] ?? '—' }}</div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Beds / Baths / Garages</span> <span class="tabular-nums">{{ $m['beds'] ?? 0 }} / {{ $m['baths'] ?? 0 }} / {{ $m['garages'] ?? 0 }}</span></div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Erf m² / Floor m²</span> <span class="tabular-nums">{{ $m['erf_size_m2'] ?? '—' }} / {{ $m['size_m2'] ?? '—' }}</span></div>
    <div><span class="text-xs block" style="color: var(--text-muted);">Resolved Agent</span>
        {{ $row->resolvedAgent?->name ?? '— unresolved —' }}
    </div>
    <div><span class="text-xs block" style="color: var(--text-muted);">SourceReference</span> {{ $m['source_reference'] ?? '—' }}</div>
</div>

<div class="mb-4">
    <div class="text-xs mb-1" style="color: var(--text-muted);">Description</div>
    <div class="text-sm whitespace-pre-wrap rounded-md p-3 max-h-40 overflow-y-auto"
         style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">{{ $m['description'] ?? '' }}</div>
</div>

<div class="mb-4">
    <div class="text-xs mb-2" style="color: var(--text-muted);">Images ({{ count($images) }})</div>
    <div class="grid grid-cols-3 gap-2">
        @foreach ($images as $url)
            <img src="{{ $url }}" loading="lazy" class="rounded-md w-full h-24 object-cover" style="border: 1px solid var(--border);">
        @endforeach
    </div>
</div>

<details class="text-xs" style="color: var(--text-muted);">
    <summary class="cursor-pointer">Raw CSV payload</summary>
    <pre class="rounded-md p-2 mt-2 overflow-x-auto"
         style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</details>
