@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-6">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">{{ $auction->title }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">{{ $auction->reference }} · {{ ucwords(str_replace('_', ' ', $auction->status)) }} · {{ $auction->starts_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="flex gap-2">
            @if($auction->isCataloguePublished())
            <a href="{{ route('public.auctions.show', $auction->id) }}" target="_blank" class="corex-btn-outline">View public page</a>
            @endif
            @if(! $advertisingOnly)
            @permission('auctions.bidders.view')
            <a href="{{ route('corex.auctions.bidders.index', $auction) }}" class="corex-btn-outline">Bidder Register</a>
            @endpermission
            @permission('auctions.room.operate')
            <a href="{{ route('corex.auctions.room.show', $auction) }}" class="corex-btn-outline">Sale Room</a>
            @endpermission
            @endif
            @permission('auctions.edit')
            <a href="{{ route('corex.auctions.edit', $auction) }}" class="corex-btn-outline">Edit</a>
            @endpermission
            @if($canPublish && $auction->lots->where('status', 'draft')->isNotEmpty())
            <form method="POST" action="{{ route('corex.auctions.publish', $auction) }}" onsubmit="return confirm('Publish the catalogue? Every draft lot goes On Auction and becomes publicly marketable.')">
                @csrf
                <button type="submit" class="corex-btn-primary">Publish Catalogue</button>
            </form>
            @endif
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-crimson,#dc2626) 12%,transparent);color:var(--ds-crimson,#dc2626);">{{ $errors->first() }}
        @if(session('publish_blockers'))
        <ul class="list-disc ml-5 mt-1">@foreach(session('publish_blockers') as $b)<li>{{ $b }}</li>@endforeach</ul>
        @endif
    </div>@endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
        <div><span class="text-muted">Bidding mode</span><br>{{ ucfirst(str_replace('_', ' ', $auction->bidding_mode)) }}</div>
        <div><span class="text-muted">Auctioneer</span><br>{{ $auction->isInternal() ? $auction->auctioneerUser?->name : ($auction->auctioneer_company ?? $auction->auctioneerContact?->name) }}</div>
        <div><span class="text-muted">Venue</span><br>{{ $auction->venue_name ?? '—' }}</div>
        <div><span class="text-muted">Branch</span><br>{{ $auction->branch?->name ?? '—' }}</div>
    </div>

    <div>
        <h2 class="font-medium mb-2">Lots</h2>
        @if($auction->lots->isEmpty())
            <p class="text-muted text-sm">No lots yet — attach a property below.</p>
        @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Lot #</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    @if($canSeeReserve)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reserve</th>@endif
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Guide</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($auction->lots as $lot)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $lot->lot_number }}</td>
                    <td class="px-4 py-2.5">{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</td>
                    @if($canSeeReserve)<td class="px-4 py-2.5">{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</td>@endif
                    <td class="px-4 py-2.5">
                        @if($lot->guide_price_min || $lot->guide_price_max)
                            R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }}
                        @else — @endif
                    </td>
                    <td class="px-4 py-2.5">{{ $statusLabels[$lot->status] ?? $lot->status }}</td>
                    <td class="px-4 py-2.5"><div class="flex gap-2 items-center">
                        <a href="{{ route('corex.auctions.lots.show', $lot) }}" class="corex-btn-outline text-xs">Open</a>
                        @if($lot->status === 'draft')
                        @permission('auctions.edit')
                        <form method="POST" action="{{ route('corex.auctions.lots.remove', [$auction, $lot]) }}" onsubmit="return confirm('Remove this lot?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="corex-btn-outline text-xs">Remove</button>
                        </form>
                        @endpermission
                        @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        @endif
    </div>

    @permission('auctions.create')
    <div class="max-w-lg rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);">
        <h2 class="font-medium mb-2">Attach a Property</h2>
        <form method="POST" action="{{ route('corex.auctions.lots.add', $auction) }}" class="grid grid-cols-2 gap-3 text-sm">
            @csrf
            <div class="col-span-2">
                <label class="prop-label">Property ID *</label>
                <input type="number" name="property_id" required class="prop-input w-full">
                <p class="text-xs text-muted mt-1">Find the property's ID from <a href="{{ route('corex.properties.index') }}" target="_blank" class="underline">Properties</a>.</p>
            </div>
            @if($canSeeReserve)
            <div>
                <label class="prop-label">Reserve Price</label>
                <input type="number" name="reserve_price" step="0.01" class="prop-input w-full">
            </div>
            @endif
            <div>
                <label class="prop-label">Opening Bid</label>
                <input type="number" name="opening_bid" step="0.01" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Guide Price Min</label>
                <input type="number" name="guide_price_min" step="0.01" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Guide Price Max</label>
                <input type="number" name="guide_price_max" step="0.01" class="prop-input w-full">
            </div>
            <div class="col-span-2">
                <button type="submit" class="corex-btn-primary">Add Lot</button>
            </div>
        </form>
    </div>
    @endpermission
</div>
@endsection
