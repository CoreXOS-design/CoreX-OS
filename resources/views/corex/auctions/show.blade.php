@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ $auction->title }}</h1>
            <p class="text-sm text-gray-500">{{ $auction->reference }} · {{ ucwords(str_replace('_', ' ', $auction->status)) }} · {{ $auction->starts_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="flex gap-2">
            @permission('auctions.bidders.view')
            <a href="{{ route('corex.auctions.bidders.index', $auction) }}" class="corex-btn-outline">Bidder Register</a>
            @endpermission
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

    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div><span class="text-gray-500">Bidding mode</span><br>{{ ucfirst(str_replace('_', ' ', $auction->bidding_mode)) }}</div>
        <div><span class="text-gray-500">Auctioneer</span><br>{{ $auction->isInternal() ? $auction->auctioneerUser?->name : ($auction->auctioneer_company ?? $auction->auctioneerContact?->name) }}</div>
        <div><span class="text-gray-500">Venue</span><br>{{ $auction->venue_name ?? '—' }}</div>
        <div><span class="text-gray-500">Branch</span><br>{{ $auction->branch?->name ?? '—' }}</div>
    </div>

    <div>
        <h2 class="font-medium mb-2">Lots</h2>
        @if($auction->lots->isEmpty())
            <p class="text-gray-500 text-sm">No lots yet — attach a property below.</p>
        @else
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">Lot #</th>
                    <th>Property</th>
                    @if($canSeeReserve)<th>Reserve</th>@endif
                    <th>Guide</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($auction->lots as $lot)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $lot->lot_number }}</td>
                    <td>{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</td>
                    @if($canSeeReserve)<td>{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</td>@endif
                    <td>
                        @if($lot->guide_price_min || $lot->guide_price_max)
                            R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }}
                        @else — @endif
                    </td>
                    <td>{{ $statusLabels[$lot->status] ?? $lot->status }}</td>
                    <td class="flex gap-2">
                        <a href="{{ route('corex.auctions.lots.show', $lot) }}" class="corex-btn-outline text-xs">Open</a>
                        @if($lot->status === 'draft')
                        @permission('auctions.edit')
                        <form method="POST" action="{{ route('corex.auctions.lots.remove', [$auction, $lot]) }}" onsubmit="return confirm('Remove this lot?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="corex-btn-outline text-xs">Remove</button>
                        </form>
                        @endpermission
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    @permission('auctions.create')
    <div class="max-w-lg">
        <h2 class="font-medium mb-2">Attach a Property</h2>
        <form method="POST" action="{{ route('corex.auctions.lots.add', $auction) }}" class="grid grid-cols-2 gap-3 text-sm">
            @csrf
            <div class="col-span-2">
                <label class="block text-xs text-gray-500">Property ID *</label>
                <input type="number" name="property_id" required class="corex-input w-full">
                <p class="text-xs text-gray-400 mt-1">Find the property's ID from <a href="{{ route('corex.properties.index') }}" target="_blank" class="underline">Properties</a>.</p>
            </div>
            @if($canSeeReserve)
            <div>
                <label class="block text-xs text-gray-500">Reserve Price</label>
                <input type="number" name="reserve_price" step="0.01" class="corex-input w-full">
            </div>
            @endif
            <div>
                <label class="block text-xs text-gray-500">Opening Bid</label>
                <input type="number" name="opening_bid" step="0.01" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Guide Price Min</label>
                <input type="number" name="guide_price_min" step="0.01" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Guide Price Max</label>
                <input type="number" name="guide_price_max" step="0.01" class="corex-input w-full">
            </div>
            <div class="col-span-2">
                <button type="submit" class="corex-btn-primary">Add Lot</button>
            </div>
        </form>
    </div>
    @endpermission
</div>
@endsection
