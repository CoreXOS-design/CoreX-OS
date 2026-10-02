@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Auction Diary</h1>
        @permission('auctions.create')
        <a href="{{ route('corex.auctions.create') }}" class="corex-btn-primary">+ New Auction</a>
        @endpermission
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <div>
            <label class="prop-label">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Reference, title, venue, auctioneer" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">Status</label>
            <select name="status[]" multiple class="list-header-filter min-w-[10rem]" style="height:auto;">
                @foreach($statusOptions as $s)
                    <option value="{{ $s }}" @selected(in_array($s, $filters['status']))>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">Bidding Mode</label>
            <select name="bidding_mode" class="list-header-filter">
                <option value="">Any</option>
                @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['biddingMode'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">From</label>
            <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">To</label>
            <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">Sort</label>
            <select name="sort" class="list-header-filter">
                @foreach(['starts_at' => 'Auction date', 'reference' => 'Reference', 'title' => 'Title', 'status' => 'Status', 'created_at' => 'Created'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" class="list-header-filter">
                <option value="asc" @selected($filters['dir'] === 'asc')>Asc</option>
                <option value="desc" @selected($filters['dir'] === 'desc')>Desc</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
        <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="corex-btn-outline">Clear</a>
    </form>

    @if($auctions->isEmpty())
        <div class="text-center py-16 text-muted">
            @if($filters['search'] !== '' || !empty($filters['status']))
                No auctions match these filters. <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="underline">Clear</a>
            @else
                No auctions yet — create your first auction.
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reference</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Title</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Date</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Venue</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Bidding</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Lots</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($auctions as $auction)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $auction->reference }}</td>
                    <td class="px-4 py-2.5">{{ $auction->title }}</td>
                    <td class="px-4 py-2.5">{{ $auction->starts_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $auction->venue_name ?? ($auction->bidding_mode === 'online' ? 'Online' : '—') }}</td>
                    <td class="px-4 py-2.5">{{ ucfirst(str_replace('_', ' ', $auction->bidding_mode)) }}</td>
                    <td class="px-4 py-2.5">{{ $auction->lots->count() }}</td>
                    <td class="px-4 py-2.5">{{ ucwords(str_replace('_', ' ', $auction->status)) }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.show', $auction) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        <div>{{ $auctions->links() }}</div>
    @endif
</div>
@endsection
