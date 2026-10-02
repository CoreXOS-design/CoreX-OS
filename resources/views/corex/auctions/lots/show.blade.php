@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col p-6 gap-6 max-w-3xl">
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

    @if($lot->status === 'sold')
    @permission('auctions.edit')
    <div>
        @if($lot->deal_id)
        <a href="{{ route('deals-dr2.edit', $lot->deal_id) }}" class="corex-btn-outline text-sm">View Deal</a>
        @else
        <form method="POST" action="{{ route('corex.auctions.lots.open-deal', $lot) }}">
            @csrf
            <button type="submit" class="corex-btn-primary text-sm">Open Deal</button>
        </form>
        <p class="text-xs text-gray-400 mt-1">Usually opens automatically on the fall of the hammer — use this if it didn't (e.g. the property has no linked seller yet).</p>
        @endif
    </div>
    @endpermission
    @endif

    @permission('auctions.edit')
    <div class="flex flex-wrap gap-2">
        @if($advertisingOnly && in_array($lot->status, ['catalogued', 'open_for_bids', 'under_the_hammer'], true))
        <form method="POST" action="{{ route('corex.auctions.lots.record-result', $lot) }}" class="flex flex-wrap gap-2 items-end border rounded p-3 w-full">
            @csrf
            <div><label class="block text-xs text-gray-500">Result of the sale</label>
                <select name="outcome" class="corex-input" onchange="this.form.hammer_price.disabled = this.value !== 'sold'">
                    <option value="sold">Sold</option><option value="passed_in">Passed in</option><option value="withdrawn">Withdrawn</option>
                </select></div>
            <div><label class="block text-xs text-gray-500">Sold price (R)</label><input type="number" name="hammer_price" step="0.01" class="corex-input"></div>
            <div><label class="block text-xs text-gray-500">Note (optional)</label><input type="text" name="reason" class="corex-input"></div>
            <button type="submit" class="corex-btn-primary">Record result</button>
            <p class="text-xs text-gray-400 w-full">The sale was run elsewhere — record what happened and the public advert will show it.</p>
        </form>
        @endif
        @if(! $advertisingOnly && $lot->status === 'catalogued')
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

    @if($lot->status === 'passed_in' && $topUnderBidders->isNotEmpty())
    <div class="rounded border border-amber-200 bg-amber-50 p-4">
        <h2 class="font-medium mb-2">Top Under-Bidders — §12.3</h2>
        <p class="text-sm text-gray-600 mb-2">Every one of these bid but did not win — the strongest, most qualified leads this passed-in lot produced. Negotiate from the top down.</p>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th>Paddle</th><th>Bidder</th><th>Highest Bid</th><th></th></tr></thead>
            <tbody>
                @foreach($topUnderBidders as $bid)
                <tr class="border-t">
                    <td class="py-1">{{ $bid->bidder?->paddle_number }}</td>
                    <td>{{ $bid->bidder?->contact?->full_name }}</td>
                    <td>R {{ number_format($bid->amount, 0) }}</td>
                    <td>
                        @if($bid->bidder?->contact)
                        <a href="{{ route('corex.contacts.show', $bid->bidder->contact) }}" class="corex-btn-outline text-xs">Open Contact</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div>
        <h2 class="font-medium mb-2">Viewings — §5.6</h2>
        <p class="text-sm text-gray-500 mb-2">Scheduled viewing windows before the sale. Published viewings appear on the calendar and, once live, the public lot page.</p>
        <table class="w-full text-sm border-collapse mb-3">
            <thead><tr class="text-left text-gray-500 border-b"><th class="py-1">Starts</th><th>Ends</th><th>Type</th><th>Notes</th><th>Agent</th><th></th></tr></thead>
            <tbody>
                @forelse($lot->viewings as $viewing)
                <tr class="border-b">
                    <td class="py-1">{{ $viewing->starts_at->format('d M Y H:i') }}</td>
                    <td>{{ $viewing->ends_at->format('H:i') }}</td>
                    <td>{{ $viewing->is_by_appointment ? 'By appointment' : 'Open' }}</td>
                    <td>{{ $viewing->notes ?? '—' }}</td>
                    <td>{{ $viewing->agent?->name ?? '—' }}</td>
                    <td>
                        @permission('auctions.edit')
                        <form method="POST" action="{{ route('corex.auctions.lots.viewings.destroy', [$lot, $viewing]) }}" onsubmit="return confirm('Remove this viewing?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 underline">Remove</button>
                        </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-2 text-gray-500">No viewings scheduled yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @permission('auctions.edit')
        <form method="POST" action="{{ route('corex.auctions.lots.viewings.store', $lot) }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <div>
                <label class="block text-xs text-gray-500">Starts *</label>
                <input type="datetime-local" name="starts_at" required class="corex-input">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Ends *</label>
                <input type="datetime-local" name="ends_at" required class="corex-input">
            </div>
            <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_by_appointment" value="1"> By appointment</label>
            <div>
                <label class="block text-xs text-gray-500">Notes</label>
                <input type="text" name="notes" class="corex-input" placeholder="Optional">
            </div>
            <button type="submit" class="corex-btn-outline text-sm">Add Viewing</button>
        </form>
        @endpermission
    </div>

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
