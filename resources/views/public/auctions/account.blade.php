@extends('public.auctions._layout')
@section('title', 'My account')
@section('content')
<div class="narrow">
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
        <p class="small" style="margin-top:18px;"><a href="{{ route('public.auctions.home', $agency->slug) }}">&larr; Back to the auctions</a></p>
    </div>
</div>
@endsection
