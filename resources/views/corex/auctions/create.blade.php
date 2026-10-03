@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New Auction</h1>
        <p class="text-xs" style="color: var(--text-muted);">Set up the auction, then add lots and publish the advert.</p>
    </div>
    @include('corex.auctions._form')
</div>
@endsection
