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
