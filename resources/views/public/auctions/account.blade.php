@extends('public.auctions._layout')
@section('title', 'My account')
@section('content')
<div class="narrow" style="max-width:640px;">
    <div class="panel">
        <h1 style="margin-top:0;">My account</h1>
        <p class="muted small">Signed in as <strong>{{ $buyer->email }}</strong></p>
        <form method="POST" action="{{ route('public.auctions.account.update', $agency->slug) }}" class="stack">
            @csrf @method('PUT')
            <label for="name">Full name</label>
            <input id="name" type="text" name="name" value="{{ old('name', trim($contact->first_name . ' ' . $contact->last_name)) }}" required maxlength="190">
            <label for="phone">Phone</label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone', $contact->phone) }}" maxlength="40">
            <p style="margin-top:16px;"><button class="btn" type="submit">Save</button></p>
        </form>
        <h2>My registrations</h2>
        @forelse($registrations as $r)
            <p class="small" style="margin:.3rem 0;"><a href="{{ route('public.auctions.auction', [$agency->slug, $r->auction_id]) }}">{{ $r->auction?->title }}</a> —
                <strong>{{ $r->status === 'approved' ? 'Approved (bidder #' . $r->bidder_number . ')' : ucfirst($r->status) }}</strong>@if($r->status === 'declined' && $r->declined_reason) · {{ $r->declined_reason }}@endif</p>
        @empty
            <p class="muted small">You have not registered for an auction yet.</p>
        @endforelse

        @if($invoices->isNotEmpty())
        <h2>My invoices</h2>
        @foreach($invoices as $inv)
            <p class="small" style="margin:.3rem 0;" data-my-invoice>{{ $inv->number }} — Lot {{ $inv->lot?->lot_number }} — <strong>R {{ number_format((float) $inv->total_amount, 2, '.', ' ') }}</strong> —
                {{ $inv->status === 'paid' ? 'Paid' : 'Owing R ' . number_format($inv->outstanding(), 2, '.', ' ') }} ·
                <a href="{{ route('public.auctions.account.invoice', [$agency->slug, $inv->id]) }}" target="_blank">PDF</a></p>
        @endforeach
        @endif

        @if($won->isNotEmpty())
        <h2>Lots I have won</h2>
        @foreach($won as $w)
            <p class="small" style="margin:.3rem 0;"><a href="{{ route('public.auctions.lot', [$agency->slug, $w->auction_id, $w->id]) }}">Lot {{ $w->lot_number }} — {{ $w->displayTitle() }}</a> —
                <strong>You won at R {{ number_format((float) $w->hammer_price, 0, '.', ' ') }}</strong>@if($w->status === 'sold_subject_to_confirmation') · waiting for the seller to confirm @endif</p>
        @endforeach
        @endif

        <h2>Watching</h2>
        @forelse($watching as $w)
            <p class="small" style="margin:.3rem 0;"><a href="{{ route('public.auctions.lot', [$agency->slug, $w->lot->auction_id, $w->lot_id]) }}">Lot {{ $w->lot->lot_number }} — {{ $w->lot->displayTitle() }}</a></p>
        @empty
            <p class="muted small">Lots you watch appear here.</p>
        @endforelse

        <p class="small" style="margin-top:18px;"><a href="{{ route('public.auctions.home', $agency->slug) }}">&larr; Back to the auctions</a></p>
    </div>
</div>
@endsection
