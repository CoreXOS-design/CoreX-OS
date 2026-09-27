@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Auction Diary</h1>
        @permission('auctions.create')
        <a href="{{ route('corex.auctions.create') }}" class="corex-btn-primary">+ New Auction</a>
        @endpermission
    </div>

    @if(session('status'))
        <div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Reference, title, venue, auctioneer" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Status</label>
            <select name="status[]" multiple class="corex-input min-w-[10rem]">
                @foreach($statusOptions as $s)
                    <option value="{{ $s }}" @selected(in_array($s, $filters['status']))>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Bidding Mode</label>
            <select name="bidding_mode" class="corex-input">
                <option value="">Any</option>
                @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['biddingMode'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">From</label>
            <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">To</label>
            <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Sort</label>
            <select name="sort" class="corex-input">
                @foreach(['starts_at' => 'Auction date', 'reference' => 'Reference', 'title' => 'Title', 'status' => 'Status', 'created_at' => 'Created'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" class="corex-input">
                <option value="asc" @selected($filters['dir'] === 'asc')>Asc</option>
                <option value="desc" @selected($filters['dir'] === 'desc')>Desc</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
        <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="corex-btn-outline">Clear</a>
    </form>

    @if($auctions->isEmpty())
        <div class="text-center py-16 text-gray-500">
            @if($filters['search'] !== '' || !empty($filters['status']))
                No auctions match these filters. <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="underline">Clear</a>
            @else
                No auctions yet — create your first auction.
            @endif
        </div>
    @else
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">Reference</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Venue</th>
                    <th>Bidding</th>
                    <th>Lots</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($auctions as $auction)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $auction->reference }}</td>
                    <td>{{ $auction->title }}</td>
                    <td>{{ $auction->starts_at?->format('d M Y H:i') }}</td>
                    <td>{{ $auction->venue_name ?? ($auction->bidding_mode === 'online' ? 'Online' : '—') }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $auction->bidding_mode)) }}</td>
                    <td>{{ $auction->lots->count() }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $auction->status)) }}</td>
                    <td><a href="{{ route('corex.auctions.show', $auction) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div>{{ $auctions->links() }}</div>
    @endif
</div>
@endsection
