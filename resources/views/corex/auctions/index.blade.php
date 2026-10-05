@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0" data-tour="au-diary-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Auction Diary</h1>
                <p class="text-xs" style="color:var(--text-muted);">Plan, advertise and track your auctions.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                @permission('auctions.create')
                <a href="{{ route('corex.auctions.create') }}" class="corex-btn-primary" data-tour="au-new-btn">+ New Auction</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>
    @endif

    <style>
        .auction-filter-date { color-scheme: light; }
        html.dark .auction-filter-date { color-scheme: dark; }
    </style>
    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);" data-tour="au-filters">
        <form method="GET" action="{{ route('corex.auctions.index') }}" class="flex flex-wrap items-center gap-3">
            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs" data-tour="au-search">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search reference, title, venue, auctioneer…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
            </div>

            <select name="status[]" onchange="this.form.submit()" class="list-header-filter" title="Status" data-tour="au-status">
                <option value="">All statuses</option>
                @foreach($statusOptions as $s)
                    <option value="{{ $s }}" @selected(in_array($s, $filters['status']))>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>

            <select name="bidding_mode" onchange="this.form.submit()" class="list-header-filter" title="Bidding mode" data-tour="au-bidding-filter">
                <option value="">All bidding modes</option>
                @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['biddingMode'] === $val)>{{ $label }}</option>
                @endforeach
            </select>

            <div class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);" data-tour="au-dates">
                <span>From</span>
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter auction-filter-date" title="Auction date from">
                <span>To</span>
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter auction-filter-date" title="Auction date to">
            </div>

            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by" data-tour="au-sort">
                @foreach(['starts_at' => 'Sort: Auction date', 'reference' => 'Sort: Reference', 'title' => 'Sort: Title', 'status' => 'Sort: Status', 'created_at' => 'Sort: Created'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Sort direction">
                <option value="asc" @selected($filters['dir'] === 'asc')>Oldest / A-Z first</option>
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / Z-A first</option>
            </select>

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="text-xs underline" style="color:var(--text-muted);" data-tour="au-clear">Clear</a>
        </form>
    </div>

    @if($auctions->isEmpty())
        <div class="text-center py-16 text-muted">
            @if($filters['search'] !== '' || !empty($filters['status']))
                No auctions match these filters. <a href="{{ route('corex.auctions.index', ['clear' => 1]) }}" class="underline">Clear</a>
            @else
                No auctions yet — create your first auction.
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);" data-tour="au-list">
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
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.show', $auction) }}" class="corex-btn-outline text-xs" data-tour="au-open">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        <div>{{ $auctions->links() }}</div>
    @endif
</div>
@endsection
