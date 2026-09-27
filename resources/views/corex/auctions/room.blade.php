@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-4 gap-4">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('corex.auctions.show', $auction) }}" class="text-sm text-gray-500 underline">&larr; {{ $auction->title }}</a>
            <h1 class="text-xl font-semibold">Sale Room</h1>
        </div>
        <div class="flex gap-1 text-xs">
            @foreach($lots as $l)
                <a href="{{ route('corex.auctions.room.show', [$auction, 'lot' => $l->id]) }}"
                   class="px-2 py-1 rounded border {{ $lot && $lot->id === $l->id ? 'bg-blue-600 text-white' : 'bg-gray-50' }}">
                    Lot {{ $l->lot_number }}
                </a>
            @endforeach
        </div>
    </div>

    @if($errors->any())<div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">{{ $errors->first() }}</div>@endif

    @if(!$lot)
        <div class="text-center py-16 text-gray-500">No lot is ready for the floor — catalogue and publish a lot first.</div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 flex-1">
            {{-- Current lot, large --}}
            <div class="lg:col-span-2 rounded border p-6 flex flex-col gap-4">
                <div>
                    <div class="text-xs text-gray-500">Lot {{ $lot->lot_number }} — {{ ucwords(str_replace('_', ' ', $lot->status)) }}</div>
                    <div class="text-2xl font-semibold">{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</div>
                </div>

                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-xs text-gray-500">Current Bid</div>
                        <div class="text-3xl font-bold">{{ $currentHighBid ? 'R '.number_format($currentHighBid->amount, 0) : '—' }}</div>
                        @if($currentHighBid)<div class="text-xs text-gray-500">Paddle {{ $currentHighBid->bidder?->paddle_number }} — {{ $currentHighBid->bidder?->contact?->full_name }}</div>@endif
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Next Increment</div>
                        <div class="text-3xl font-bold">{{ $suggestedNextBid !== null ? 'R '.number_format($suggestedNextBid, 0) : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Bidders</div>
                        <div class="text-3xl font-bold">{{ $bidderCount }}</div>
                    </div>
                </div>

                @if($canSeeReserve)
                <div class="text-sm text-gray-500">Reserve: {{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : 'No reserve' }}</div>
                @endif

                {{-- Lot status controls --}}
                <div class="flex gap-2">
                    @if($lot->status === 'catalogued')
                    <form method="POST" action="{{ route('corex.auctions.room.open', [$auction, $lot]) }}">@csrf<button class="corex-btn-primary">Open for Bids</button></form>
                    @endif
                    @if($lot->status === 'open_for_bids')
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.start', [$auction, $lot]) }}">@csrf<button class="corex-btn-primary">Start Hammer</button></form>
                    @endif
                </div>

                {{-- One-keystroke bid entry --}}
                @if(in_array($lot->status, ['open_for_bids', 'under_the_hammer']))
                <form method="POST" action="{{ route('corex.auctions.room.bid', [$auction, $lot]) }}" class="flex gap-2 items-end" autocomplete="off">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500">Paddle #</label>
                        <input type="text" name="paddle_number" autofocus class="corex-input text-lg" style="width:8rem">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">Amount (blank = next increment)</label>
                        <input type="number" name="amount" step="0.01" class="corex-input" placeholder="{{ $suggestedNextBid !== null ? 'R '.number_format($suggestedNextBid, 0) : 'Type a starting amount' }}">
                    </div>
                    <button type="submit" class="corex-btn-primary">Bid</button>
                </form>

                <div class="flex gap-2">
                    @if($canRetract && $currentHighBid)
                    <form method="POST" action="{{ route('corex.auctions.room.bid.retract', [$auction, $lot]) }}" onsubmit="return confirm('Retract the last bid?')" class="flex gap-2 items-end">
                        @csrf
                        <input type="text" name="reason" placeholder="Reason" class="corex-input text-xs">
                        <button type="submit" class="corex-btn-outline text-xs">Retract Last Bid</button>
                    </form>
                    @endif
                </div>
                @endif

                {{-- Fall of the hammer — deliberate, confirmed action (§11.1) --}}
                @if($lot->status === 'under_the_hammer')
                <div class="border-t pt-4 flex gap-2">
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.knock-down', [$auction, $lot]) }}"
                          onsubmit="return confirm('Going once… going twice… SOLD to paddle {{ $currentHighBid->bidder->paddle_number ?? '?' }} for R {{ number_format($currentHighBid->amount ?? 0, 0) }}?\n\nThis cannot be undone from here.')">
                        @csrf
                        <button type="submit" class="corex-btn-primary" @if(!$currentHighBid) disabled @endif>Fall of the Hammer — SOLD</button>
                    </form>
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.pass-in', [$auction, $lot]) }}" onsubmit="return confirm('No bid reached the reserve — pass this lot in?')" class="flex gap-2 items-end">
                        @csrf
                        <input type="text" name="reason" placeholder="Reason (optional)" class="corex-input text-xs">
                        <button type="submit" class="corex-btn-outline">Pass In</button>
                    </form>
                </div>
                @endif
            </div>

            {{-- Bid history --}}
            <div class="rounded border p-4">
                <h2 class="font-medium mb-2 text-sm">Bid History</h2>
                <div class="flex flex-col gap-1 text-sm max-h-96 overflow-y-auto">
                    @forelse($bidHistory as $bid)
                    <div class="flex justify-between border-b py-1 {{ $bid->isRetracted() ? 'opacity-40 line-through' : '' }}">
                        <span>#{{ $bid->bidder?->paddle_number }} ({{ $bid->channel }})</span>
                        <span>R {{ number_format($bid->amount, 0) }}</span>
                    </div>
                    @empty
                    <p class="text-gray-500">No bids yet.</p>
                    @endforelse
                </div>

                <h2 class="font-medium mt-4 mb-2 text-sm">Approved Bidders</h2>
                <div class="text-sm flex flex-col gap-1">
                    @foreach($approvedBidders as $ab)
                    <div>#{{ $ab->paddle_number }} — {{ $ab->contact?->full_name }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
