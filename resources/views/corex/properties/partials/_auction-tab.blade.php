@php
    /** @var \App\Models\Property $property */
    $auctionUser = auth()->user();
    $lot = $property->currentAuctionLot();
    $lot?->loadMissing('auction');
    $canManageLots = $auctionUser->hasPermission('auctions.lots.manage');
    $canSeeReserve = $auctionUser->hasPermission('auctions.reserve.view');
    $canEditReserve = $auctionUser->hasPermission('auctions.reserve.edit');
    $pickable = \App\Models\Auction::visibleTo($auctionUser)
        ->whereNotIn('status', [\App\Models\Auction::STATUS_COMPLETED, \App\Models\Auction::STATUS_CANCELLED])
        ->orderByDesc('starts_at')->get(['id', 'reference', 'title', 'starts_at', 'ends_at', 'is_single_property']);
    $dt = fn ($d) => $d ? $d->format('Y-m-d\TH:i') : '';
    $opens = old('opens_at', $dt($lot?->windowOpensAt()));
    $closes = old('closes_at', $dt($lot?->windowClosesAt()));
@endphp
<div class="space-y-5" data-auction-tab>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Auction</h3>
            <p class="text-xs" style="color:var(--text-muted);">When bidding opens and closes for this property, and what it starts at.</p>
        </div>
        @if($lot)
        <div class="text-right text-xs" style="color:var(--text-muted);">
            <div><span class="font-semibold" style="color:var(--text-primary);">Lot {{ $lot->lot_number }}</span> in {{ $lot->auction->reference }} — {{ $lot->auction->title }}</div>
            <div>Lot status: <strong>{{ $lot->statusLabel() }}</strong> · Auction: <strong>{{ $lot->auction->statusLabel() }}</strong></div>
            @permission('auctions.view')
            <a href="{{ route('corex.auctions.edit', $lot->auction_id) }}" class="underline">Open the auction</a>
            @endpermission
        </div>
        @endif
    </div>

    @if($errors->any() && request()->routeIs('corex.properties.show'))
        <div class="rounded-md px-3 py-2 text-xs" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>
    @endif

    @if($lot && $lot->hasBids())
        <div class="rounded-md px-3 py-2 text-xs" style="background:color-mix(in srgb,#f59e0b 14%,transparent);color:#b45309;">This lot has bids, so its bidding window, opening bid and reserve are locked. Retract the bids or withdraw the lot to change them.</div>
    @endif

    @if($canManageLots)
    <form method="POST" action="{{ route('corex.properties.auction.update', $property) }}" class="space-y-4" data-auction-form>
        @csrf @method('PUT')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="prop-label">Auction</label>
                <select name="auction_id" class="prop-select" @disabled($lot && $lot->status !== 'draft')>
                    <option value="">— Create a one-property auction for this property —</option>
                    @foreach($pickable as $a)
                    <option value="{{ $a->id }}" @selected((int) old('auction_id', $lot?->auction_id) === $a->id)>{{ $a->reference }} — {{ $a->title }}@if($a->starts_at) ({{ $a->starts_at->format('d M Y') }})@endif</option>
                    @endforeach
                </select>
                @if($lot && $lot->status !== 'draft')
                <input type="hidden" name="auction_id" value="{{ $lot->auction_id }}">
                <p class="mt-1 text-[11px]" style="color:var(--text-muted);">The lot is published, so it stays in this auction. Withdraw it to move the property.</p>
                @endif
            </div>
            <div>
                <label class="prop-label">Bidding opens</label>
                <input type="datetime-local" name="opens_at" value="{{ $opens }}" class="prop-input">
            </div>
            <div>
                <label class="prop-label">Bidding closes</label>
                <input type="datetime-local" name="closes_at" value="{{ $closes }}" class="prop-input">
            </div>
            <div>
                <label class="prop-label">Opening bid (R)</label>
                <input type="number" step="1" min="0" name="opening_bid" value="{{ old('opening_bid', $lot?->opening_bid !== null ? (float) $lot->opening_bid : '') }}" class="prop-input">
            </div>
            <div>
                <label class="prop-label">Reserve price (R) <span class="text-[11px]" style="color:var(--text-muted);">— staff only, never shown to buyers</span></label>
                @if($canSeeReserve)
                <input type="number" step="1" min="0" name="reserve_price" value="{{ old('reserve_price', $lot?->reserve_price !== null ? (float) $lot->reserve_price : '') }}" class="prop-input" @readonly(! $canEditReserve)>
                @else
                <input type="text" value="Hidden — you do not have permission to see reserve prices" class="prop-input" disabled>
                @endif
            </div>
            <div>
                <label class="prop-label">Guide price from (R) <span class="text-[11px]" style="color:var(--text-muted);">optional</span></label>
                <input type="number" step="1" min="0" name="guide_price_min" value="{{ old('guide_price_min', $lot?->guide_price_min !== null ? (float) $lot->guide_price_min : '') }}" class="prop-input">
            </div>
            <div>
                <label class="prop-label">Guide price to (R)</label>
                <input type="number" step="1" min="0" name="guide_price_max" value="{{ old('guide_price_max', $lot?->guide_price_max !== null ? (float) $lot->guide_price_max : '') }}" class="prop-input">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="prop-action-btn" style="background:var(--brand-button,#0ea5e9);color:#fff;">{{ $lot ? 'Save auction details' : 'Add to auction' }}</button>
            @if(! $lot)
            <span class="text-[11px]" style="color:var(--text-muted);">Choose an auction, or leave it blank and set the dates to create a one-property auction.</span>
            @endif
        </div>
    </form>

    @if($lot && ! $lot->isConcluded())
    <form method="POST" action="{{ route('corex.properties.auction.withdraw', $property) }}" onsubmit="return confirm('Withdraw this property from the auction?');">
        @csrf @method('DELETE')
        <button type="submit" class="prop-action-btn prop-action-btn-neutral">Withdraw from auction</button>
    </form>
    @elseif(! $lot)
    <form method="POST" action="{{ route('corex.properties.auction.unmark', $property) }}" onsubmit="return confirm('Take this property off auction?');">
        @csrf @method('DELETE')
        <button type="submit" class="prop-action-btn prop-action-btn-neutral">This is not an auction property</button>
    </form>
    @endif
    @else
    <p class="text-xs" style="color:var(--text-muted);">You can see this property's auction but you do not have permission to change it.</p>
    @endif

    @if($lot && auth()->user()->hasPermission('auctions.bids.view'))
    @php $recentBids = $lot->bids()->with('registration.contact')->orderByDesc('id')->limit(10)->get(); @endphp
    <div data-auction-bids>
        <h4 class="text-xs font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Bids ({{ $lot->bids()->valid()->count() }})</h4>
        @forelse($recentBids as $b)
        <div class="flex items-center gap-3 text-xs py-1" style="border-top:1px solid var(--border);{{ $b->status === 'retracted' ? 'opacity:.55;' : '' }}">
            <span style="width:110px;color:var(--text-muted);">{{ $b->placed_at->format('d M H:i:s') }}</span>
            <span class="flex-1">#{{ $b->registration?->bidder_number ?? '—' }} {{ $b->registration?->displayName() }}</span>
            <strong>R {{ number_format((float) $b->amount, 0, '.', ' ') }}</strong>
            <span style="color:var(--text-muted);">{{ \App\Models\AuctionBid::SOURCES[$b->source] ?? $b->source }}{{ $b->status === 'retracted' ? ' · retracted' : '' }}</span>
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">No bids yet.</p>
        @endforelse
        @if($lot->bids()->count() > 10)<a href="{{ route('corex.auctions.bids.index', ['lot_id' => $lot->id]) }}" class="text-xs underline">See all bids</a>@endif
    </div>
    @endif
</div>
