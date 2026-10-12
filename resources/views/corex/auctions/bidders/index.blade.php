@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <div>
            <a href="{{ route('corex.auctions.show', $auction) }}" class="text-sm text-muted underline">&larr; {{ $auction->title }}</a>
            <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Bidder Register</h1>
        </div>
        @permission('auctions.bidders.approve')
        <a href="{{ route('corex.auctions.bidders.create', $auction) }}" class="corex-btn-primary">+ Register a Bidder</a>
        @endpermission
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>@endif

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <div>
            <label class="prop-label">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Name, email, mobile, ID, paddle" class="list-header-filter">
        </div>
        <div>
            <label class="prop-label">Status</label>
            <select name="status" class="list-header-filter">
                <option value="">Any</option>
                @foreach(\App\Models\AuctionBidder::STATUSES as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">Deposit received</label>
            <select name="deposit_received" class="list-header-filter">
                <option value="">Any</option>
                <option value="1" @selected($filters['depositReceived'] === '1')>Yes</option>
                <option value="0" @selected($filters['depositReceived'] === '0')>No</option>
            </select>
        </div>
        <div>
            <label class="prop-label">Rules signed</label>
            <select name="rules_signed" class="list-header-filter">
                <option value="">Any</option>
                <option value="1" @selected($filters['rulesSigned'] === '1')>Yes</option>
                <option value="0" @selected($filters['rulesSigned'] === '0')>No</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline">Apply</button>
    </form>

    @if($bidders->isEmpty())
        <div class="text-center py-16 text-muted">
            No bidders registered yet.
            @permission('auctions.bidders.approve')
            <a href="{{ route('corex.auctions.bidders.create', $auction) }}" class="underline">Register at the door</a>
            @endpermission
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Paddle</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Bidder</th>
                    @if($canSeeContactDetails)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Contact</th>@endif
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    @if($canSeeFica)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">FICA</th>@endif
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Deposit</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Rules</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($bidders as $bidder)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $bidder->paddle_number ?? '—' }}</td>
                    <td class="px-4 py-2.5">{{ $bidder->contact?->full_name }}</td>
                    @if($canSeeContactDetails)<td class="px-4 py-2.5">{{ $bidder->contact?->email }} {{ $bidder->contact?->phone }}</td>@endif
                    <td class="px-4 py-2.5">{{ ucwords(str_replace('_', ' ', $bidder->status)) }}</td>
                    @if($canSeeFica)<td class="px-4 py-2.5">{{ $bidder->fica_verified_at ? 'Verified' : 'Pending' }}</td>@endif
                    <td class="px-4 py-2.5">{{ $bidder->deposit_received_at ? 'Received' : ($bidder->deposit_required ? 'Outstanding' : 'N/A') }}</td>
                    <td class="px-4 py-2.5">{{ $bidder->rules_signed_at ? 'Signed' : 'Pending' }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.bidders.show', $bidder) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        <div>{{ $bidders->links() }}</div>
    @endif
</div>
@endsection
