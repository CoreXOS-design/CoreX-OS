@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-6 max-w-2xl">
    <div>
        <a href="{{ route('corex.auctions.bidders.index', $bidder->auction) }}" class="text-sm text-gray-500 underline">&larr; Bidder Register</a>
        <h1 class="text-xl font-semibold">{{ $bidder->contact?->full_name }}</h1>
        <p class="text-sm text-gray-500">Paddle {{ $bidder->paddle_number ?? '—' }} · {{ ucwords(str_replace('_', ' ', $bidder->status)) }} · {{ $bidder->auction->title }}</p>
    </div>

    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">{{ $errors->first() }}</div>@endif

    <div>
        <h2 class="font-medium mb-2">Paddle gate — §10.2</h2>
        @if(empty($unmetGates))
            <p class="text-green-700 text-sm">Every gate satisfied — this bidder may hold a paddle.</p>
        @else
            <ul class="text-sm text-amber-700 list-disc pl-5">
                @foreach($unmetGates as $gate)
                    <li>{{ $gate }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
            <span class="text-gray-500">FICA</span><br>
            {{ $bidder->fica_verified_at ? 'Verified '.$bidder->fica_verified_at->format('d M Y') : 'Pending' }}
            @if($canVerifyFica && !$bidder->fica_verified_at)
            <form method="POST" action="{{ route('corex.auctions.bidders.verify-fica', $bidder) }}" class="mt-1">
                @csrf<button type="submit" class="corex-btn-outline text-xs">Mark FICA Verified</button>
            </form>
            @endif
        </div>
        <div>
            <span class="text-gray-500">Rules of Auction</span><br>
            {{ $bidder->rules_signed_at ? 'Signed '.$bidder->rules_signed_at->format('d M Y') : 'Pending' }}
            @if($canApprove && !$bidder->rules_signed_at)
            <form method="POST" action="{{ route('corex.auctions.bidders.rules-signed', $bidder) }}" class="mt-1">
                @csrf<button type="submit" class="corex-btn-outline text-xs">Mark Signed</button>
            </form>
            @endif
        </div>
        <div>
            <span class="text-gray-500">Deposit</span><br>
            @if(!$bidder->deposit_required) N/A
            @elseif($bidder->deposit_received_at) Received {{ $bidder->deposit_received_at->format('d M Y') }} ({{ $bidder->deposit_reference }})
            @else Outstanding — R {{ number_format($bidder->deposit_amount ?? 0, 0) }}
            @endif
            @if($canRecordDeposits && $bidder->deposit_required && !$bidder->deposit_received_at)
            <form method="POST" action="{{ route('corex.auctions.bidders.deposit', $bidder) }}" class="flex gap-2 items-end mt-1">
                @csrf
                <input type="text" name="deposit_reference" placeholder="Reference" required class="corex-input text-xs">
                <button type="submit" class="corex-btn-outline text-xs">Record</button>
            </form>
            @endif
            @if($canRecordDeposits && $bidder->deposit_received_at && !$bidder->deposit_refunded_at)
            <form method="POST" action="{{ route('corex.auctions.bidders.deposit.refund', $bidder) }}" class="flex gap-2 items-end mt-1">
                @csrf
                <input type="text" name="deposit_refund_reference" placeholder="Refund reference" required class="corex-input text-xs">
                <button type="submit" class="corex-btn-outline text-xs">Refund</button>
            </form>
            @endif
        </div>
        <div>
            <span class="text-gray-500">Bidding for</span><br>
            {{ ucwords(str_replace('_', ' ', $bidder->bidding_for)) }}
            @if($bidder->entityContact) — {{ $bidder->entityContact->full_name }} @endif
        </div>
    </div>

    @if($canApprove && !in_array($bidder->status, ['approved', 'declined', 'withdrawn']))
    <div class="flex gap-2">
        <form method="POST" action="{{ route('corex.auctions.bidders.approve', $bidder) }}" class="flex gap-2 items-end">
            @csrf
            @if($paddleNumberMode === 'manual')
            <input type="text" name="paddle_number" placeholder="Paddle #" class="corex-input text-xs">
            @endif
            <button type="submit" class="corex-btn-primary">Approve{{ empty($unmetGates) || $unmetGates === ['Not yet approved by staff.'] ? ' & Issue Paddle' : '' }}</button>
        </form>
        <form method="POST" action="{{ route('corex.auctions.bidders.decline', $bidder) }}" class="flex gap-2 items-end" onsubmit="return confirm('Decline this registration?')">
            @csrf
            <input type="text" name="declined_reason" placeholder="Reason (optional)" class="corex-input text-xs">
            <button type="submit" class="corex-btn-outline">Decline</button>
        </form>
    </div>
    @endif

    @if($canApprove && $bidder->status === 'approved')
    <form method="POST" action="{{ route('corex.auctions.bidders.withdraw', $bidder) }}" onsubmit="return confirm('Withdraw this bidder?')">
        @csrf<button type="submit" class="corex-btn-outline">Withdraw Registration</button>
    </form>
    @endif
</div>
@endsection
