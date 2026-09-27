@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-6 max-w-3xl">
    <div>
        <a href="{{ route('corex.auctions.show', $lot->auction) }}" class="text-sm text-gray-500 underline">&larr; {{ $lot->auction->title }}</a>
        <h1 class="text-xl font-semibold">Lot {{ $lot->lot_number }} — {{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</h1>
        <p class="text-sm text-gray-500">Status: {{ $statusLabels[$lot->status] ?? $lot->status }}</p>
    </div>

    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
        @if($canSeeReserve)<div><span class="text-gray-500">Reserve</span><br>{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</div>@endif
        <div><span class="text-gray-500">Guide</span><br>
            @if($lot->guide_price_min || $lot->guide_price_max)
                R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }}
            @else — @endif
        </div>
        <div><span class="text-gray-500">Hammer Price</span><br>{{ $lot->hammer_price ? 'R '.number_format($lot->hammer_price, 0) : '—' }}</div>
        @if($lot->hammer_at)<div><span class="text-gray-500">Hammer At</span><br>{{ $lot->hammer_at->format('d M Y H:i') }}</div>@endif
        @if(! is_null($lot->reserve_met))<div><span class="text-gray-500">Reserve Met</span><br>{{ $lot->reserve_met ? 'Yes' : 'No' }}</div>@endif
        @if($lot->confirmation_deadline)<div><span class="text-gray-500">Confirmation Due</span><br>{{ $lot->confirmation_deadline->format('d M Y H:i') }}</div>@endif
    </div>

    @permission('auctions.edit')
    <div class="flex flex-wrap gap-2">
        @if($lot->status === 'catalogued')
        <form method="POST" action="{{ route('corex.auctions.lots.open', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Open for Bids</button></form>
        @endif
        @if($lot->status === 'open_for_bids')
        @permission('auctions.hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.hammer.start', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Start Hammer</button></form>
        @endpermission
        @endif
        @if($lot->status === 'under_the_hammer')
        @permission('auctions.hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.hammer.record', $lot) }}" class="flex gap-2 items-end">
            @csrf
            <div>
                <label class="block text-xs text-gray-500">Hammer Price *</label>
                <input type="number" name="hammer_price" step="0.01" required class="corex-input">
            </div>
            <button type="submit" class="corex-btn-primary">Record Hammer</button>
        </form>
        @endpermission
        @endif
        @if($lot->status === 'sold_subject_to_confirmation')
        <form method="POST" action="{{ route('corex.auctions.lots.confirm', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Seller Confirms Sale</button></form>
        <form method="POST" action="{{ route('corex.auctions.lots.decline', $lot) }}" class="flex gap-2 items-end">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="corex-input">
            <button type="submit" class="corex-btn-outline">Seller Declines</button>
        </form>
        @endif
        @if(! in_array($lot->status, ['sold', 'passed_in', 'withdrawn']))
        <form method="POST" action="{{ route('corex.auctions.lots.withdraw', $lot) }}" class="flex gap-2 items-end" onsubmit="return confirm('Withdraw this lot?')">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="corex-input">
            <button type="submit" class="corex-btn-outline">Withdraw</button>
        </form>
        @endif
        @if($lot->status === 'under_the_hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.passed-in', $lot) }}" class="flex gap-2 items-end" onsubmit="return confirm('Mark this lot passed in — no bid reached the reserve?')">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="corex-input">
            <button type="submit" class="corex-btn-outline">Passed In</button>
        </form>
        @endif
    </div>
    @endpermission

    <div>
        <h2 class="font-medium mb-2">History</h2>
        <table class="w-full text-sm border-collapse">
            <thead><tr class="text-left text-gray-500 border-b"><th class="py-2">When</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
                @foreach($lot->statusHistory as $row)
                <tr class="border-b">
                    <td class="py-2">{{ $row->created_at?->format('d M Y H:i') }}</td>
                    <td>{{ $row->from_status ?? '—' }}</td>
                    <td>{{ $row->to_status }}</td>
                    <td>{{ $row->changedBy?->name ?? 'System' }}</td>
                    <td>{{ $row->reason ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
