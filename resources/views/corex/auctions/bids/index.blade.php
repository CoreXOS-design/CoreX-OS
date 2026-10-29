@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Bids</h1>
        <p class="text-xs" style="color:var(--text-muted);">Every bid on every property in your auctions. Bidder details are for staff only.</p>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.bids.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search property, lot no., bidder no./name, amount…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">Valid &amp; retracted</option>
                <option value="valid" @selected($filters['status'] === 'valid')>Valid</option>
                <option value="retracted" @selected($filters['status'] === 'retracted')>Retracted</option>
            </select>
            <select name="source" onchange="this.form.submit()" class="list-header-filter" title="Source">
                <option value="">Any source</option>
                @foreach(\App\Models\AuctionBid::SOURCES as $k => $l)<option value="{{ $k }}" @selected($filters['source'] === $k)>{{ $l }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Placed from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['placed_at' => 'Sort: Time placed', 'amount' => 'Sort: Amount', 'lot' => 'Sort: Lot', 'status' => 'Sort: Status'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / highest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / lowest first</option>
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.bids.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($bids->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'auctionId', 'lotId', 'registrationId', 'status', 'source', 'dateFrom', 'dateTo'])->filter()->isNotEmpty())
                No bids match these filters. <a href="{{ route('corex.auctions.bids.index') }}" class="underline">Clear them</a>
            @else
                No bids yet. They appear here the moment a bidder places one.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Placed', 'Auction', 'Lot', 'Property', 'Bidder', 'Amount', 'Source', 'Status', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($bids as $b)
                <tr style="border-top:1px solid var(--border);vertical-align:top;{{ $b->status === 'retracted' ? 'opacity:.6;' : '' }}" data-bid="{{ $b->id }}">
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $b->placed_at->format('d M Y H:i:s') }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $b->auction?->reference }}</td>
                    <td class="px-4 py-2.5 font-semibold"><a href="{{ route('corex.auctions.lots.edit', $b->lot_id) }}" class="underline">{{ $b->lot?->lot_number }}</a></td>
                    <td class="px-4 py-2.5 text-xs">{{ $b->lot?->property?->buildDisplayAddress() ?: $b->lot?->displayTitle() }}</td>
                    <td class="px-4 py-2.5">
                        @if($b->registration)<a href="{{ route('corex.auctions.bidders.show', $b->registration_id) }}" class="underline">#{{ $b->registration->bidder_number ?? '—' }} {{ $b->registration->displayName() }}</a>@endif
                    </td>
                    <td class="px-4 py-2.5 font-semibold whitespace-nowrap">R {{ number_format((float) $b->amount, 0, '.', ' ') }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ \App\Models\AuctionBid::SOURCES[$b->source] ?? $b->source }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ ucfirst($b->status) }}@if($b->retract_reason)<div style="color:var(--text-muted);">{{ $b->retract_reason }}</div>@endif</td>
                    <td class="px-4 py-2.5">
                        @if($b->status === 'valid' && ! ($b->lot?->isConcluded()))@permission('auctions.bids.retract')
                        <form method="POST" action="{{ route('corex.auctions.bids.retract', $b->id) }}" class="flex items-center gap-1" onsubmit="return confirm('Retract this bid?');">@csrf
                            <input name="reason" placeholder="Reason" required class="px-2 py-1 text-xs rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);width:110px;"><button class="text-xs underline" style="color:#ef4444;">Retract</button></form>
                        @endpermission @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $bids->links() }}</div>
    @endif
</div>
@endsection
