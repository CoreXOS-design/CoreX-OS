@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Auction Results</h1>
        @permission('auctions.results.export')
        <a href="{{ route('corex.auctions.results.export', $filters) }}" class="corex-btn-outline">Export CSV</a>
        @endpermission
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Lot #, address, suburb, buyer, paddle" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Status</label>
            <select name="lot_status" class="corex-input">
                <option value="">Any</option>
                @foreach(['sold','sold_subject_to_confirmation','passed_in','withdrawn'] as $s)
                    <option value="{{ $s }}" @selected($filters['lotStatus'] === $s)>{{ $statusLabels[$s] ?? $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Reserve met</label>
            <select name="reserve_met" class="corex-input">
                <option value="">Any</option>
                <option value="1" @selected($filters['reserveMet'] === '1')>Yes</option>
                <option value="0" @selected($filters['reserveMet'] === '0')>No</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Auction from</label>
            <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">To</label>
            <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Sort</label>
            <select name="sort" class="corex-input">
                @foreach(['auction_date' => 'Auction date', 'lot_number' => 'Lot #', 'hammer_price' => 'Hammer price', 'status' => 'Status'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" class="corex-input">
                <option value="desc" @selected($filters['dir'] === 'desc')>Desc</option>
                <option value="asc" @selected($filters['dir'] === 'asc')>Asc</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
    </form>

    @if($lots->isEmpty())
        <div class="text-center py-16 text-gray-500">No results yet — results appear here as lots are knocked down.</div>
    @else
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">Auction</th>
                    <th>Lot</th>
                    <th>Property</th>
                    <th>Status</th>
                    <th>Hammer Price</th>
                    @if($canSeeReserve)<th>Reserve</th><th>Reserve Met</th>@endif
                    <th>Buyer</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($lots as $lot)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $lot->auction?->reference }}</td>
                    <td>{{ $lot->lot_number }}</td>
                    <td>{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</td>
                    <td>{{ $statusLabels[$lot->status] ?? $lot->status }}</td>
                    <td>{{ $lot->hammer_price ? 'R '.number_format($lot->hammer_price, 0) : '—' }}</td>
                    @if($canSeeReserve)
                    <td>{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</td>
                    <td>{{ is_null($lot->reserve_met) ? '—' : ($lot->reserve_met ? 'Yes' : 'No') }}</td>
                    @endif
                    <td>{{ $lot->winningBidder?->contact?->full_name ?? '—' }}</td>
                    <td><a href="{{ route('corex.auctions.lots.show', $lot) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div>{{ $lots->links() }}</div>
    @endif
</div>
@endsection
