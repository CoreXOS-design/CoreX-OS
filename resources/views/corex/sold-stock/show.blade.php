@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-5xl mx-auto space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $sale->address ?: $sale->title }}</h1>
                <p class="text-sm text-white/60">Sold {{ optional($sale->sale_date)->format('j F Y') ?? '—' }}@if($sale->sale_price !== null) for R {{ number_format((float) $sale->sale_price, 0, '.', ',') }}@endif — a frozen record of the sale, read-only.</p>
            </div>
            <a href="{{ route('corex.sold-stock.index') }}" class="px-3 py-1.5 rounded-md text-xs font-semibold" style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);">Back to Sold stock</a>
        </div>
    </div>

    @if ($sale->trashed())
        <div class="rounded-md px-4 py-3 text-sm" style="background:rgba(245,158,11,0.12);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);">This sale is archived and is not on the website Sold feed.</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
            <div class="text-xs" style="color:var(--text-muted);">Asking price</div>
            <div class="text-lg font-semibold" style="color:var(--text-primary);">{{ $sale->asking_price !== null ? 'R ' . number_format((float) $sale->asking_price, 0, '.', ',') : '—' }}</div>
        </div>
        <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
            <div class="text-xs" style="color:var(--text-muted);">Sold price</div>
            <div class="text-lg font-semibold" style="color:var(--text-primary);">{{ $sale->sale_price !== null ? 'R ' . number_format((float) $sale->sale_price, 0, '.', ',') : '—' }}</div>
        </div>
        <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
            <div class="text-xs" style="color:var(--text-muted);">Days on market</div>
            <div class="text-lg font-semibold" style="color:var(--text-primary);">{{ $sale->days_on_market ?? '—' }}</div>
        </div>
    </div>

    <div class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);">
        <h2 class="font-semibold" style="color:var(--text-primary);">{{ $sale->title }}</h2>
        <div class="text-sm" style="color:var(--text-secondary);">
            {{ $sale->suburb }}{{ $sale->city ? ', ' . $sale->city : '' }} · {{ $sale->property_type }}
            @if($sale->beds !== null) · {{ $sale->beds }} bed @endif @if($sale->baths !== null) · {{ $sale->baths }} bath @endif @if($sale->size_m2) · {{ $sale->size_m2 }} m² @endif
        </div>
        <div class="text-sm" style="color:var(--text-secondary);">Agent: {{ $sale->agent->name ?? '—' }}@if($sale->secondAgent) and {{ $sale->secondAgent->name }}@endif</div>
        @if (! empty($sale->description))
            <p class="text-sm whitespace-pre-line" style="color:var(--text-secondary);">{{ $sale->description }}</p>
        @endif
        @php $features = collect((array) ($sale->features ?? []))->map(fn ($v, $k) => is_string($v) ? $v : (is_string($k) ? $k : null))->filter()->values(); @endphp
        @if ($features->isNotEmpty())
            <div class="flex flex-wrap gap-2">@foreach ($features as $f)<span class="text-xs px-2 py-1 rounded" style="background:var(--surface-2);color:var(--text-secondary);">{{ $f }}</span>@endforeach</div>
        @endif
    </div>

    @php $photos = collect((array) ($sale->photos ?? []))->map(fn ($p) => str_starts_with($p, 'http') ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url(preg_replace('#^/?storage/#', '', ltrim($p, '/')))); @endphp
    @if ($photos->isNotEmpty())
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($photos as $url)<img src="{{ $url }}" alt="" loading="lazy" class="w-full h-32 object-cover rounded-md" style="border:1px solid var(--border);">@endforeach
        </div>
    @endif

    @if ($earlier->isNotEmpty())
        <div class="rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="font-semibold mb-2" style="color:var(--text-primary);">Earlier sales of this property</h2>
            @foreach ($earlier as $e)
                <div class="text-sm py-1" style="color:var(--text-secondary);">
                    <a href="{{ route('corex.sold-stock.show', $e->id) }}">Sale {{ $e->cycle_no }} — {{ optional($e->sale_date)->format('j M Y') ?? '—' }}@if($e->sale_price !== null), R {{ number_format((float) $e->sale_price, 0, '.', ',') }}@endif</a>
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex items-center gap-3">
        @if ($sale->property)
            <a href="{{ route('corex.properties.show', $sale->property_id) }}" class="text-sm" style="color:var(--brand-button,#0ea5e9);">Open the property</a>
        @endif
        @permission('properties.archive')
            @if ($sale->trashed())
                <form method="POST" action="{{ route('corex.sold-stock.restore', $sale->id) }}">@csrf<button class="text-sm" style="color:var(--brand-button,#0ea5e9);">Restore</button></form>
            @else
                <form method="POST" action="{{ route('corex.sold-stock.archive', $sale->id) }}" data-confirm="Archive this sale? It will leave the website Sold feed. You can restore it later.">@csrf<button class="text-sm" style="color:#ef4444;">Archive</button></form>
            @endif
        @endpermission
    </div>
</div>
@endsection
