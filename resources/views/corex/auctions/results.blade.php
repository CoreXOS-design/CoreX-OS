@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Auction Results</h1>
        @permission('auctions.results.export')
        <a href="{{ route('corex.auctions.results.export', $filters) }}" class="corex-btn-outline">Export CSV</a>
        @endpermission
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <div>
            <label class="prop-label">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Lot #, address, suburb, buyer, paddle" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">Status</label>
            <select name="lot_status" class="list-header-filter">
                <option value="">Any</option>
                @foreach(['sold','sold_subject_to_confirmation','passed_in','withdrawn'] as $s)
                    <option value="{{ $s }}" @selected($filters['lotStatus'] === $s)>{{ $statusLabels[$s] ?? $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">Reserve met</label>
            <select name="reserve_met" class="list-header-filter">
                <option value="">Any</option>
                <option value="1" @selected($filters['reserveMet'] === '1')>Yes</option>
                <option value="0" @selected($filters['reserveMet'] === '0')>No</option>
            </select>
        </div>
        <div>
            <label class="prop-label">Auction from</label>
            <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">To</label>
            <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">Sort</label>
            <select name="sort" class="list-header-filter">
                @foreach(['auction_date' => 'Auction date', 'lot_number' => 'Lot #', 'hammer_price' => 'Hammer price', 'status' => 'Status'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" class="list-header-filter">
                <option value="desc" @selected($filters['dir'] === 'desc')>Desc</option>
                <option value="asc" @selected($filters['dir'] === 'asc')>Asc</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
    </form>

    @if($lots->isEmpty())
        <div class="text-center py-16 text-muted">No results yet — results appear here as lots are knocked down.</div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Auction</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Lot</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Hammer Price</th>
                    @if($canSeeReserve)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reserve</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reserve Met</th>@endif
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Buyer</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($lots as $lot)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $lot->auction?->reference }}</td>
                    <td class="px-4 py-2.5">{{ $lot->lot_number }}</td>
                    <td class="px-4 py-2.5">{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</td>
                    <td class="px-4 py-2.5">{{ $statusLabels[$lot->status] ?? $lot->status }}</td>
                    <td class="px-4 py-2.5">{{ $lot->hammer_price ? 'R '.number_format($lot->hammer_price, 0) : '—' }}</td>
                    @if($canSeeReserve)
                    <td class="px-4 py-2.5">{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</td>
                    <td class="px-4 py-2.5">{{ is_null($lot->reserve_met) ? '—' : ($lot->reserve_met ? 'Yes' : 'No') }}</td>
                    @endif
                    <td class="px-4 py-2.5">{{ $lot->winningBidder?->contact?->full_name ?? '—' }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.lots.show', $lot) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        <div>{{ $lots->links() }}</div>
    @endif
</div>
@endsection
