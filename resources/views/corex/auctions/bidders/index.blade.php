@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-4">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('corex.auctions.show', $auction) }}" class="text-sm text-gray-500 underline">&larr; {{ $auction->title }}</a>
            <h1 class="text-xl font-semibold">Bidder Register</h1>
        </div>
        @permission('auctions.bidders.approve')
        <a href="{{ route('corex.auctions.bidders.create', $auction) }}" class="corex-btn-primary">+ Register a Bidder</a>
        @endpermission
    </div>

    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>@endif

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Name, email, mobile, ID, paddle" class="corex-input">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Status</label>
            <select name="status" class="corex-input">
                <option value="">Any</option>
                @foreach(\App\Models\AuctionBidder::STATUSES as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Deposit received</label>
            <select name="deposit_received" class="corex-input">
                <option value="">Any</option>
                <option value="1" @selected($filters['depositReceived'] === '1')>Yes</option>
                <option value="0" @selected($filters['depositReceived'] === '0')>No</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Rules signed</label>
            <select name="rules_signed" class="corex-input">
                <option value="">Any</option>
                <option value="1" @selected($filters['rulesSigned'] === '1')>Yes</option>
                <option value="0" @selected($filters['rulesSigned'] === '0')>No</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
    </form>

    @if($bidders->isEmpty())
        <div class="text-center py-16 text-gray-500">
            No bidders registered yet.
            @permission('auctions.bidders.approve')
            <a href="{{ route('corex.auctions.bidders.create', $auction) }}" class="underline">Register at the door</a>
            @endpermission
        </div>
    @else
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">Paddle</th>
                    <th>Bidder</th>
                    @if($canSeeContactDetails)<th>Contact</th>@endif
                    <th>Status</th>
                    @if($canSeeFica)<th>FICA</th>@endif
                    <th>Deposit</th>
                    <th>Rules</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($bidders as $bidder)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $bidder->paddle_number ?? '—' }}</td>
                    <td>{{ $bidder->contact?->full_name }}</td>
                    @if($canSeeContactDetails)<td>{{ $bidder->contact?->email }} {{ $bidder->contact?->phone }}</td>@endif
                    <td>{{ ucwords(str_replace('_', ' ', $bidder->status)) }}</td>
                    @if($canSeeFica)<td>{{ $bidder->fica_verified_at ? 'Verified' : 'Pending' }}</td>@endif
                    <td>{{ $bidder->deposit_received_at ? 'Received' : ($bidder->deposit_required ? 'Outstanding' : 'N/A') }}</td>
                    <td>{{ $bidder->rules_signed_at ? 'Signed' : 'Pending' }}</td>
                    <td><a href="{{ route('corex.auctions.bidders.show', $bidder) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div>{{ $bidders->links() }}</div>
    @endif
</div>
@endsection
