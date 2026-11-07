@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Lots</h1>
                <p class="text-xs" style="color:var(--text-muted);">Every property offered at auction, across all your auctions.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('corex.auctions.index') }}" class="corex-btn-outline text-xs px-3 py-2">All auctions</a>
                @permission('auctions.lots.manage')
                <a href="{{ route('corex.auctions.lots.create') }}" class="corex-btn-primary" data-lot-new>+ New Lot</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.lots.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search lot no., title, reference, address, agent…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Lot status">
                <option value="">All lot statuses</option>
                @foreach(\App\Models\AuctionLot::STATUSES as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
            <select name="agent_id" onchange="this.form.submit()" class="list-header-filter" title="Listing agent">
                <option value="">All agents</option>
                @foreach($agents as $u)<option value="{{ $u->id }}" @selected($filters['agentId'] === (string) $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Auction from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['auction' => 'Sort: Auction date', 'lot_number' => 'Sort: Lot number', 'title' => 'Sort: Title', 'status' => 'Sort: Status', 'opens_at' => 'Sort: Bidding opens', 'closes_at' => 'Sort: Bidding closes', 'opening_bid' => 'Sort: Opening bid'] as $v => $l)
                    <option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Descending</option><option value="asc" @selected($filters['dir'] === 'asc')>Ascending</option>
            </select>
            <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-muted);"><input type="checkbox" name="archived" value="1" onchange="this.form.submit()" @checked($filters['archived'])> Archived</label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.lots.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($lots->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'auctionId', 'status', 'agentId', 'dateFrom', 'dateTo'])->filter()->isNotEmpty() || $filters['archived'])
                No lots match these filters. <a href="{{ route('corex.auctions.lots.index') }}" class="underline">Clear them</a>
            @else
                No lots yet. @permission('auctions.lots.manage')<a href="{{ route('corex.auctions.lots.create') }}" class="underline">Add the first lot.</a>@endpermission
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Auction', 'Lot', 'Property', 'Agent', 'Bidding window', 'Opening bid', 'Status', ''] as $h)
                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($lots as $lot)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $lot->auction->reference }}<div class="text-[11px]" style="color:var(--text-muted);">{{ $lot->auction->starts_at?->format('d M Y') }}</div></td>
                    <td class="px-4 py-2.5 font-semibold">{{ $lot->lot_number }}</td>
                    <td class="px-4 py-2.5">{{ $lot->displayTitle() }}<div class="text-[11px]" style="color:var(--text-muted);">{{ $lot->property?->buildDisplayAddress() }}</div></td>
                    <td class="px-4 py-2.5">{{ $lot->property?->agent?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $lot->windowOpensAt()?->format('d M H:i') ?? '—' }} → {{ $lot->windowClosesAt()?->format('d M H:i') ?? '—' }}</td>
                    <td class="px-4 py-2.5">{{ $lot->opening_bid !== null ? 'R ' . number_format((float) $lot->opening_bid, 0, '.', ' ') : '—' }}</td>
                    <td class="px-4 py-2.5">{{ $lot->trashed() ? 'Archived' : $lot->statusLabel() }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.lots.edit', $lot->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $lots->links() }}</div>
    @endif
</div>
@endsection
