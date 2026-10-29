@extends('layouts.corex')

@section('corex-content')
@php $money = fn ($v) => $v === null ? '—' : 'R ' . number_format((float) $v, 0, '.', ' '); @endphp
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Results</h1>
            <p class="text-xs" style="color:var(--text-muted);">How each lot ended: the price, the winner and how many bids it took.</p>
        </div>
        @permission('auctions.results.export')
        <a href="{{ route('corex.auctions.results.export', request()->query()) }}" class="corex-btn-outline text-xs px-3 py-2" data-results-export>Export to CSV</a>
        @endpermission
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif

    <div class="grid grid-cols-2 md:grid-cols-7 gap-3 text-sm" data-results-stats>
        @foreach([['Lots sold', $stats['sold']], ['Awaiting seller', $stats['subject']], ['Passed in', $stats['passed_in']], ['Turnover', $money($stats['turnover'])], ['Highest price', $money($stats['highest'] ?: null)], ['Bids per lot', $stats['bids_per_lot']], ['Commission earned (ex VAT)', $money($stats['commission'])]] as [$label, $value])
        <div class="rounded-md px-4 py-3" style="background:var(--surface);border:1px solid var(--border);"><div class="text-xs" style="color:var(--text-muted);">{{ $label }}</div><div class="font-bold" style="color:var(--text-primary);">{{ $value }}</div></div>
        @endforeach
    </div>

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.results') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search property, lot no., winner…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <select name="outcome" onchange="this.form.submit()" class="list-header-filter" title="Outcome">
                <option value="">All outcomes</option>
                @foreach($outcomes as $k => $l)<option value="{{ $k }}" @selected($filters['outcome'] === $k)>{{ $l }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Result from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['result_at' => 'Sort: Result date', 'hammer_price' => 'Sort: Price', 'lot' => 'Sort: Lot', 'outcome' => 'Sort: Outcome'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / highest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / lowest first</option>
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.results') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($lots->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'auctionId', 'outcome', 'dateFrom', 'dateTo'])->filter()->isNotEmpty())
                No results match these filters. <a href="{{ route('corex.auctions.results') }}" class="underline">Clear them</a>
            @else
                No lot has finished yet. Results appear here as soon as a lot is sold, passed in or withdrawn.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(array_filter(['Result', 'Auction', 'Lot', 'Property', 'Outcome', 'Price', $canReserve ? 'Reserve' : null, 'Winner', 'Bids', '']) as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($lots as $lot)
                @php $w = $winners[$lot->id] ?? null; @endphp
                <tr style="border-top:1px solid var(--border);" data-result="{{ $lot->id }}">
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $lot->result_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $lot->auction?->reference }}</td>
                    <td class="px-4 py-2.5 font-semibold">{{ $lot->lot_number }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $lot->property?->buildDisplayAddress() ?: $lot->displayTitle() }}</td>
                    <td class="px-4 py-2.5">{{ $outcomes[$lot->status] ?? $lot->status }}</td>
                    <td class="px-4 py-2.5 font-semibold whitespace-nowrap">{{ $money($lot->hammer_price) }}</td>
                    @if($canReserve)<td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $money($lot->reserve_price) }}@if($lot->reserve_price !== null && $lot->hammer_price !== null)<div style="color:{{ (float) $lot->hammer_price >= (float) $lot->reserve_price ? '#10b981' : '#b45309' }};">{{ (float) $lot->hammer_price >= (float) $lot->reserve_price ? 'Met' : 'Not met' }}</div>@endif</td>@endif
                    <td class="px-4 py-2.5 text-xs">@if($w)#{{ $w->bidder_number }} {{ $w->displayName() }}@else—@endif</td>
                    <td class="px-4 py-2.5">{{ $bidCounts[$lot->id] ?? 0 }}</td>
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
